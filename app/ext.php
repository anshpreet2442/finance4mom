<?php
declare(strict_types=1);
/**
 * Shared plumbing for the optional features: admin-managed settings (secrets encrypted at rest and never sent to the browser),
 * feature access (free / paid plan / pay-as-you-go), the usage wallet and entitlements.
 * Money inside the wallet is stored in micro-rupees (1 rupee = 1,000,000) so token prices below one paisa add up exactly.
 */

// ============================ settings & secrets ============================
function set_setting(string $k, string $v): void { run('INSERT INTO {settings} (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $v]); site_settings_flush(); }
function secret_key(): string { return hash('sha256', (string) (getenv('MFNC_SECRET') ?: (DB_PASS . '|' . DB_NAME . '|' . DB_USER . '|mfnc-secrets')), true); }
function secret_encrypt(string $plain): string {
    if ($plain === '') { return ''; }
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $c === false ? '' : 'v1:' . base64_encode($iv . $tag . $c);
}
function secret_decrypt(string $stored): string {
    if (!str_starts_with($stored, 'v1:')) { return ''; }
    $raw = base64_decode(substr($stored, 3), true); if ($raw === false || strlen($raw) < 29) { return ''; }
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}
/** A secret from an environment variable (wins) or from the encrypted setting. */
function secret_get(string $key, string $env = ''): string {
    if ($env !== '' && (string) getenv($env) !== '') { return (string) getenv($env); }
    return secret_decrypt(site_setting($key));
}
function secret_set(string $key, string $plain): void { set_setting($key, secret_encrypt($plain)); }
/** Shows only the tail of a secret, e.g. "••••••••k3F9". */
function mask_secret(string $s): string { return $s === '' ? '' : str_repeat('•', 8) . (strlen($s) > 8 ? substr($s, -4) : ''); }
/** Saves a posted secret only when the field was filled in (blank keeps the stored value; the browser never receives it). */
function secret_from_post(string $field, string $key): void { $v = trim((string) ($_POST[$field] ?? '')); if ($v !== '') { secret_set($key, $v); } if (!empty($_POST[$field . '_clear'])) { set_setting($key, ''); } }

// ============================ features ============================
const FEATURES = [
    'dock' => ['label' => 'Dock', 'desc' => 'Pin your favourite pages in a dock on desktop.', 'modes' => ['free', 'paid']],
    'ai' => ['label' => 'AI finance advisor', 'desc' => 'A blunt personal finance / CA advisor that reads your data and can act with undo.', 'modes' => ['free', 'paid', 'payg']],
    'email' => ['label' => 'Email alerts', 'desc' => 'Receipts and reminders by email.', 'modes' => ['free', 'paid', 'payg']],
    'sms' => ['label' => 'SMS alerts', 'desc' => 'Payment links and alerts by text message.', 'modes' => ['free', 'paid', 'payg']],
];
function feature_cfg(string $f): array {
    $d = ['dock' => 'free', 'ai' => 'free', 'email' => 'free', 'sms' => 'free'][$f] ?? 'free';
    $mode = site_setting("feat_{$f}_mode", $d); if (!in_array($mode, FEATURES[$f]['modes'] ?? ['free'], true)) { $mode = 'free'; }
    return ['mode' => $mode, 'price' => max(0, (int) site_setting("feat_{$f}_price", '0')), 'days' => max(1, (int) site_setting("feat_{$f}_days", '30')), 'unit_micro' => max(0, (int) site_setting("feat_{$f}_unit", '0'))];
}
function is_exempt_user(?array $u): bool { return $u && $u['role'] === 'admin'; }
function entitlement_until(int $uid, string $f): ?string { $v = val('SELECT valid_until FROM {entitlements} WHERE user_id=? AND feature=?', [$uid, $f]); return $v ? (string) $v : null; }
function grant_feature(int $uid, string $f, int $days, string $source = 'admin'): string {
    $cur = entitlement_until($uid, $f); $from = $cur && $cur >= today() ? $cur : today(); $until = day_after_n($from, $days);
    run('INSERT INTO {entitlements} (user_id,feature,valid_until,source) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE valid_until=VALUES(valid_until), source=VALUES(source)', [$uid, $f, $until, $source]);
    return $until;
}
function revoke_feature(int $uid, string $f): void { run('DELETE FROM {entitlements} WHERE user_id=? AND feature=?', [$uid, $f]); }
/** Server-side gate used by every feature entry point. Returns ['ok' => bool, 'reason' => string, 'mode' => string, 'code' => string]. */
function feature_access(?array $u, string $f): array {
    if (!$u) { return ['ok' => false, 'reason' => 'Sign in first.', 'mode' => 'free', 'code' => 'auth']; }
    $c = feature_cfg($f); $m = $c['mode']; $ok = ['ok' => true, 'reason' => '', 'mode' => $m, 'code' => ''];
    if (is_exempt_user($u) || $m === 'free') { return $ok; }
    if ($m === 'paid') {
        $until = entitlement_until((int) $u['id'], $f);
        return $until && $until >= today() ? $ok : ['ok' => false, 'reason' => FEATURES[$f]['label'] . ' is part of a paid plan. Get access on the Plans page.', 'mode' => $m, 'code' => 'plan'];
    }
    $need = max($c['unit_micro'], 1);
    return wallet_balance((int) $u['id']) >= $need ? $ok : ['ok' => false, 'reason' => 'Your wallet is empty. Top it up on the Plans page to keep using ' . FEATURES[$f]['label'] . '.', 'mode' => $m, 'code' => 'wallet'];
}
function feature_on(?array $u, string $f): bool { return feature_access($u, $f)['ok']; }

// ============================ wallet ============================
function micro_to_inr(int $micro): string { return '₹' . number_format($micro / 1_000_000, abs($micro) < 100_000_000 ? 2 : 0); }
function wallet_balance(int $uid): int { return (int) val('SELECT COALESCE(SUM(amount_micro),0) FROM {wallet_ledger} WHERE user_id=?', [$uid]); }
/** Credits (+) or debits (-). A $ref makes it idempotent: the same ref is applied once. Returns false when it was already applied. */
function wallet_add(int $uid, int $micro, string $kind, string $note = '', ?string $ref = null): bool {
    return run('INSERT IGNORE INTO {wallet_ledger} (user_id,amount_micro,kind,note,ref) VALUES (?,?,?,?,?)', [$uid, $micro, $kind, mb_substr($note, 0, 160), $ref]) > 0;
}
/** Called when a charge is paid (gateway or manual): grants a purchased plan or credits a wallet top-up. Idempotent. */
function apply_charge_effects(array $c): void {
    $uid = (int) $c['user_id'];
    if (!empty($c['feature']) && isset(FEATURES[$c['feature']])) { if (!(int) val('SELECT COUNT(*) FROM {wallet_ledger} WHERE user_id=? AND ref=?', [$uid, 'plan:' . $c['id']])) { grant_feature($uid, $c['feature'], max(1, (int) ($c['feature_days'] ?: 30)), 'purchase'); wallet_add($uid, 0, 'adjust', 'Plan purchased: ' . $c['title'], 'plan:' . $c['id']); } }
    if (!empty($c['topup_micro'])) { wallet_add($uid, (int) $c['topup_micro'], 'topup', 'Top-up', 'charge:' . $c['id']); }
}
/** The user starts a purchase: a normal charge they pay through the existing gateway flow. Returns the charge id. */
function create_self_charge(int $uid, string $title, int $amountPaise, array $extra): int {
    if ($amountPaise <= 0) { throw new UserError('Nothing to pay for.'); }
    $open = one("SELECT id FROM {charges} WHERE user_id=? AND status='pending' AND title=? AND amount=? AND created_by=?", [$uid, $title, $amountPaise, $uid]); if ($open) { return (int) $open['id']; }
    return insert('INSERT INTO {charges} (user_id,kind,title,description,amount,due_date,created_by,feature,feature_days,topup_micro) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$uid, 'one_time', $title, $extra['desc'] ?? null, $amountPaise, day_after_n(today(), 1), $uid, $extra['feature'] ?? null, $extra['days'] ?? null, $extra['topup_micro'] ?? null]);
}

// ============================ hooks used by the installer pages ============================
/** Pages that can be pinned to the dock: key => [label, path, icon]. */
function dock_pages(bool $admin = false): array {
    $p = ['dashboard' => ['Dashboard', '/', 'layout-dashboard'], 'accounts' => ['Accounts', '/accounts', 'landmark'], 'transactions' => ['Transactions', '/transactions', 'arrow-left-right'], 'budget' => ['Budget', '/budget', 'calendar-check'],
        'goals' => ['Goals', '/goals', 'goal'], 'house' => ['House', '/house', 'house'], 'big' => ['Big purchases', '/big-purchases', 'shopping-cart'], 'bills' => ['Bills', '/bills', 'bell-ring'], 'yearly' => ['Yearly', '/yearly', 'calendar-clock'],
        'analysis' => ['Analysis', '/analysis', 'chart-pie'], 'dues' => ['Dues', '/dues', 'wallet'], 'reports' => ['Reports', '/reports', 'chart-line'], 'ai' => ['AI advisor', '/ai', 'sparkles'], 'plans' => ['Plans', '/plans', 'credit-card'], 'settings' => ['Settings', '/settings', 'settings']];
    if ($admin) { $p['admin'] = ['Admin', '/admin', 'shield-check']; }
    return $p;
}

// ============================ dock (desktop) ============================
function dock_pins(int $uid): array { return array_column(all('SELECT page_key FROM {dock_pins} WHERE user_id=? ORDER BY sort, page_key', [$uid]), 'page_key'); }
/** Nav key used by layout_start() => dock page key. */
function dock_key_for(string $active): ?string {
    $m = ['dashboard' => 'dashboard', 'accounts' => 'accounts', 'transactions' => 'transactions', 'budget' => 'budget', 'goals' => 'goals', 'house' => 'house', 'big' => 'big', 'bills' => 'bills', 'yearly' => 'yearly', 'analysis' => 'analysis', 'dues' => 'dues', 'reports' => 'reports', 'ai' => 'ai', 'plans' => 'plans', 'settings' => 'settings', 'admin' => 'admin'];
    return $m[$active] ?? null;
}
/** The dock itself (rendered once in the page shell; hidden by CSS below the desktop breakpoint). */
function dock_html(array $u): string {
    if (!feature_on($u, 'dock')) { return ''; }
    $pages = dock_pages($u['role'] === 'admin'); $pins = array_values(array_filter(dock_pins((int) $u['id']), fn($k) => isset($pages[$k])));
    if (!$pins) { return ''; }
    $badges = $GLOBALS['__badges'] ?? []; $active = dock_key_for((string) ($GLOBALS['__layout_extra']['active'] ?? ''));
    $o = '<nav id="mf-dock" class="mf-dock" aria-label="Dock" data-dock data-base="50" data-magnify="70" data-panel="68" data-gap="10" data-round="0.5" data-unpin-action="' . e(url('/do/dock-unpin')) . '"><div class="mf-dock__panel" role="toolbar" aria-label="Pinned pages">';
    foreach ($pins as $k) { [$label, $path, $ic] = $pages[$k]; $n = (int) ($badges[$k] ?? 0); $on = $k === $active;
        $o .= '<a class="mf-dock__item' . ($on ? ' is-active' : '') . '" href="' . e(url($path)) . '" data-key="' . e($k) . '" data-label="' . e($label) . '" aria-label="' . e($label) . ($n ? ', ' . $n . ' need attention' : '') . '"' . ($on ? ' aria-current="page"' : '') . '>'
            . '<span class="mf-dock__tile">' . icon($ic, 'mf-dock__icon') . ($n ? '<span class="mf-dock__badge" aria-hidden="true">' . min($n, 99) . '</span>' : '') . '</span><span class="mf-dock__label" aria-hidden="true">' . e($label) . '</span><span class="mf-dock__dot" aria-hidden="true"></span></a>'; }
    return $o . '</div><div class="mf-dock__menu" role="menu" hidden><a role="menuitem" data-act="open" href="#">Open</a><button type="button" role="menuitem" data-act="unpin">Unpin</button></div></nav>';
}
/** Pin / unpin toggle for the current page (shown in page headers on desktop). */
function dock_pin_button(): string {
    $u = current_user(); if (!$u || !feature_on($u, 'dock')) { return ''; }
    $k = dock_key_for((string) ($GLOBALS['__layout_extra']['active'] ?? '')); if (!$k || !isset(dock_pages($u['role'] === 'admin')[$k])) { return ''; }
    $pinned = in_array($k, dock_pins((int) $u['id']), true);
    return post_btn(icon($pinned ? 'pin-off' : 'pin', 'size-4') . '<span class="hidden xl:inline">' . ($pinned ? 'Unpin from dock' : 'Pin to dock') . '</span>', $pinned ? 'dock-unpin' : 'dock-pin', ['page' => $k], 'btn btn-sm max-lg:hidden', 'title="' . ($pinned ? 'Remove this page from the dock' : 'Pin this page to the dock') . '" aria-label="' . ($pinned ? 'Unpin from dock' : 'Pin to dock') . '"');
}
/** Settings: every page with a pin / unpin button. */
function dock_manager(array $u): string {
    $pins = dock_pins((int) $u['id']); $o = '<ul class="divide-y divide-line">';
    foreach (dock_pages($u['role'] === 'admin') as $k => [$label, $path, $ic]) { $on = in_array($k, $pins, true);
        $o .= '<li class="flex items-center justify-between gap-3 py-2.5 text-sm"><span class="flex items-center gap-3">' . icon($ic, 'size-4') . e($label) . '</span>' . post_btn($on ? 'Unpin' : 'Pin', $on ? 'dock-unpin' : 'dock-pin', ['page' => $k], $on ? 'btn btn-ghost btn-sm' : 'btn btn-sm') . '</li>'; }
    return $o . '</ul><p class="mt-3 text-xs text-mute">The dock appears on desktop screens. You can also pin the page you are on from its header, and right-click an icon in the dock to unpin it.</p>';
}

// ============================ swipe rows (mobile) & star border ============================
/**
 * One swipeable list row. $start = actions revealed by swiping right, $end = by swiping left (the first action of each is also the full-swipe action).
 * Action: ['type' => 'post', 'label', 'icon', 'tone' => danger|ok|warn|dark|primary, 'action' => 'transaction-delete', 'fields' => [...], 'undo' => 'Transaction deleted'] or ['type' => 'open', 'open' => '#dialog-id', ...] or ['type' => 'link', 'href' => ...].
 */
function swipe_row(string $content, array $start = [], array $end = [], string $label = '', array $opts = []): string {
    $mk = function (array $a): string {
        $cls = 'swipe-act swipe-act--' . ($a['tone'] ?? 'dark'); $inner = icon($a['icon'] ?? 'ellipsis', 'size-5') . '<span>' . e($a['label'] ?? '') . '</span>';
        if (($a['type'] ?? 'post') === 'link') { return '<a class="' . $cls . '" href="' . e($a['href'] ?? '#') . '">' . $inner . '</a>'; }
        if (($a['type'] ?? 'post') === 'attr') { return '<button type="button" class="' . $cls . '" ' . $a['attrs'] . ' data-swipe-open>' . $inner . '</button>'; }
        if (($a['type'] ?? 'post') === 'open') { return '<button type="button" class="' . $cls . '" data-open="' . e($a['open']) . '" data-swipe-open>' . $inner . '</button>'; }
        $h = ''; foreach (($a['fields'] ?? []) as $k => $v) { $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">'; }
        return '<form method="post" action="' . e(url('/do/' . $a['action'])) . '">' . csrf_field() . '<input type="hidden" name="_back" value="' . e(current_url()) . '">' . $h
            . '<button class="' . $cls . '"' . (!empty($a['undo']) ? ' data-swipe-undo="' . e($a['undo']) . '"' : '') . ' aria-label="' . e(($a['label'] ?? '') . ($label !== '' ? ': ' . $label : '')) . '">' . $inner . '</button></form>';
    };
    $o = '<div class="swipe-row' . (!empty($opts['class']) ? ' ' . e($opts['class']) : '') . '" data-swipe role="group" aria-roledescription="swipeable row"' . ($label !== '' ? ' aria-label="' . e($label) . '"' : '') . '>';
    if ($start) { $o .= '<div class="swipe-row__actions swipe-row__actions--start">' . implode('', array_map($mk, $start)) . '</div>'; }
    if ($end) { $o .= '<div class="swipe-row__actions swipe-row__actions--end">' . implode('', array_map($mk, $end)) . '</div>'; }
    return $o . '<div class="swipe-row__content" tabindex="0">' . $content . '</div></div>';
}
/** The StarBorder button/link (see assets/starborder.js for the props). */
function star_border(string $label, string $href = '', array $o = []): string {
    $o += ['color' => '#ffffff', 'duration' => 4, 'trail' => 0.3, 'thickness' => 1, 'radius' => 12, 'glow' => 0.6, 'theme' => 'dark', 'hoverLap' => true, 'clickPulse' => true, 'class' => ''];
    $attrs = ' data-star data-color="' . e($o['color']) . '" data-duration="' . (float) $o['duration'] . '" data-trail="' . (float) $o['trail'] . '" data-thickness="' . (float) $o['thickness'] . '" data-radius="' . (float) $o['radius'] . '" data-glow="' . (float) $o['glow'] . '" data-theme="' . e($o['theme']) . '"' . ($o['hoverLap'] ? ' data-hover-lap' : '') . ($o['clickPulse'] ? ' data-click-pulse' : '');
    return $href !== '' ? '<a class="star-border ' . e($o['class']) . '" href="' . e($href) . '"' . $attrs . '>' . $label . '</a>' : '<button type="button" class="star-border ' . e($o['class']) . '"' . $attrs . '>' . $label . '</button>';
}
