<?php
declare(strict_types=1);
/**
 * Account menu (M1): the profile area of the top bar, the phone hero and the drawer opens a morphing menu (assets/accountmenu.js, a vanilla port of the
 * KokonutUI "Account Menu"). Everything here is server side: who the person is, which entries exist for them, the status (online / away / do not disturb)
 * and what "do not disturb" really suppresses. Without JavaScript the trigger is a plain link to Settings and the drawer still has Sign out.
 *
 * Do not disturb: no toast for background events, no red dot on the bell, and no email / SMS / Telegram reminders except the critical ones (an overdue
 * payment to the app, DND_CRITICAL). The avatar carries a small dot everywhere so the person always knows which state they are in.
 */
const PRESENCE = ['online' => 'Online', 'away' => 'Away', 'busy' => 'Do not disturb'];
/** Events that still reach a person who turned on Do not disturb. */
const DND_CRITICAL = ['charge.overdue', 'security', 'password.reset'];

function presence_get(int $uid): string {
    if (!isset($GLOBALS['__presence'][$uid])) { $v = (string) (val('SELECT presence FROM {preferences} WHERE user_id=?', [$uid]) ?? 'online'); $GLOBALS['__presence'][$uid] = isset(PRESENCE[$v]) ? $v : 'online'; }
    return $GLOBALS['__presence'][$uid];
}
function presence_set(int $uid, string $status): void {
    if (!isset(PRESENCE[$status])) { throw new UserError('Choose online, away or do not disturb.'); }
    run('UPDATE {preferences} SET presence=? WHERE user_id=?', [$status, $uid]); $GLOBALS['__presence'][$uid] = $status;
}
function presence_dnd(int $uid): bool { return presence_get($uid) === 'busy'; }
/** True when Do not disturb holds this alert back. $event is the notification event name (email / SMS / Telegram templates use the same names). */
function dnd_blocks(int $uid, string $event): bool { return !in_array($event, DND_CRITICAL, true) && presence_dnd($uid); }
function do_presence_set(array $u): ?string {
    $st = in_enum('status', array_keys(PRESENCE)); presence_set((int) $u['id'], $st);
    return null;
}

function acct_initials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: []; $o = '';
    foreach (array_slice(array_filter($parts, fn($p) => $p !== ''), 0, 2) as $p) { $o .= mb_strtoupper(mb_substr($p, 0, 1)); }
    return $o !== '' ? $o : '?';
}
/** "Admin", or how many plugins are active, or "Free". */
function acct_plan_label(array $u): string {
    if ($u['role'] === 'admin') { return 'Admin'; }
    $n = (int) val('SELECT COUNT(*) FROM {entitlements} WHERE user_id=? AND valid_until >= ?', [$u['id'], today()]);
    return $n > 0 ? ($n === 1 ? '1 plugin' : $n . ' plugins') : 'Free';
}
/**
 * The entries of the menu for this person: [key, label, href, icon, value, desktop_only]. Only pages that exist and that the person may open
 * (feature gates, role, dues lock) are listed; the item list never contains a dead link.
 */
function acct_menu_items(array $u): array {
    $admin = $u['role'] === 'admin'; $items = [];
    if (billing_level($u) >= 3) { return [['dues', 'Dues & payments', '/dues', 'hand-coins', '', false]]; }   // locked: only the Dues page opens
    $items[] = ['settings', 'Profile & settings', '/settings', 'user', '', false];
    $paid = false; foreach (array_keys(features()) as $f) { if (feature_cfg($f)['mode'] !== 'free') { $paid = true; break; } }
    $bal = wallet_balance((int) $u['id']);
    if ($paid || $bal > 0 || $admin) { $items[] = ['plans', 'Plans & wallet', '/plans', 'credit-card', micro_to_inr($bal), false]; }
    $items[] = ['plugins', 'Plugins', '/plugins', 'puzzle', '', false];
    $items[] = ['import', 'Import statements', '/import', 'upload', '', false];
    if ((site_setting('ai_enabled', '0') === '1' || $admin) && feature_on($u, 'ai')) { $items[] = ['ai-actions', 'AI actions & undo', '/ai/actions', 'history', '', false]; }
    if (feature_on($u, 'share_links')) { $items[] = ['share', 'Share links', '/share', 'share-2', '', false]; }
    if (feature_on($u, 'dock')) { $items[] = ['dock', 'Dock & pinned pages', '/settings#dock', 'layout-grid', 'Desktop', true]; }   // the dock only exists on desktop, so phones do not see this entry
    if ($admin) { $items[] = ['admin', 'Admin', '/admin', 'shield-check', '', false]; }
    return $items;
}
/** Avatar (initials) with the status dot. */
function acct_avatar(array $u, string $cls = 'am-av--md'): string {
    return '<span class="am-av ' . e($cls) . '" aria-hidden="true">' . e(acct_initials((string) $u['name'])) . '<i class="am-dot" data-am-dot></i></span>';
}
/**
 * The trigger and the (hidden) panel. $o: variant = bar (top bar chip, avatar only on phones) | hero (glass avatar on the ember header) | side (drawer footer),
 * align = end | start (which corner of the panel is pinned to the trigger).
 */
function acct_menu_html(array $u, array $o = []): string {
    $o += ['variant' => 'bar', 'align' => 'end', 'cls' => '']; $uid = (int) $u['id']; $st = presence_get($uid); $v = (string) $o['variant'];
    $theme = (string) ($o['theme'] ?? (prefs()['theme'] ?? 'system')); $plan = acct_plan_label($u);
    $av = acct_avatar($u, $v === 'bar' ? 'am-av--sm' : ($v === 'hero' ? 'am-av--hero' : 'am-av--sm'));
    $idTxt = '<span class="am-txt" data-am-txt><span class="am-name" data-am-name>' . e((string) $u['name']) . '</span><span class="am-mail" data-am-email>' . e((string) $u['email']) . '</span></span>';
    $o_ = '<div class="am am--' . e($v) . ' ' . e((string) $o['cls']) . '" data-acctmenu data-align="' . e((string) $o['align']) . '" data-status="' . e($st) . '" data-theme-now="' . e($theme) . '" data-theme-url="' . e(url('/do/theme-quick')) . '" data-status-url="' . e(url('/do/presence-set')) . '">'
        . '<a class="am__trigger' . ($v === 'hero' ? ' m-glass' : '') . '" href="' . e(url('/settings')) . '" aria-haspopup="menu" aria-expanded="false" aria-label="Account menu, ' . e((string) $u['name']) . '"><span class="am-id" data-am-id><span data-am-avwrap>' . $av . '</span>' . ($v === 'hero' ? '' : $idTxt) . '</span>'
        . ($v === 'hero' ? '' : icon('chevrons-up-down', 'size-4 am__chev')) . '</a>'
        . '<div class="am__layer" hidden><div class="am__shadow" aria-hidden="true"><div class="am__outer"></div><div class="am__inner"></div></div>'
        . '<div class="am__clip"><div class="am__pages"><div class="am__page" data-page="main"><div class="am__head"><span class="am-id am-id--stacked" data-am-id><span data-am-avwrap>' . acct_avatar($u, 'am-av--lg') . '</span>' . $idTxt . '</span><span class="am__plan" data-am-fade>' . e($plan) . '</span></div>'
        . '<div class="am__menu" role="menu" tabindex="-1" aria-label="' . e((string) $u['name']) . '" data-am-fade>';
    // status row (radios)
    $o_ .= '<fieldset class="am__row" aria-label="Status"><span class="am__lab">Status</span><span class="am__radios">';
    foreach (['online' => ['Online', 'Online'], 'away' => ['Away', 'Away'], 'busy' => ['DND', 'Do not disturb']] as $k => [$short, $long]) {
        $o_ .= '<button type="button" class="am__radio" role="menuitemradio" tabindex="-1" data-menu-item data-row="status" data-status-set="' . $k . '" aria-checked="' . ($st === $k ? 'true' : 'false') . '" aria-label="' . e($long) . '"><i class="am__sdot am__sdot--' . $k . '"></i>' . e($short) . '</button>';
    }
    $o_ .= '</span></fieldset>';
    // theme row
    $o_ .= '<fieldset class="am__row" aria-label="Theme"><span class="am__lab">Theme</span><span class="am__seg" data-am-seg><i class="am__thumb" aria-hidden="true"></i>';
    foreach (['light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon'], 'system' => ['System', 'monitor']] as $k => [$lab, $ic]) {
        $o_ .= '<button type="button" class="am__tbtn" role="menuitemradio" tabindex="-1" data-menu-item data-row="theme" data-theme-set="' . $k . '" aria-checked="' . ($theme === $k ? 'true' : 'false') . '" aria-label="' . e($lab) . '">' . icon($ic, 'size-3.5') . '</button>';
    }
    $o_ .= '</span></fieldset><hr class="am__sep">';
    foreach (acct_menu_items($u) as $i => [$key, $label, $href, $ic, $value, $desk]) {
        $o_ .= '<a class="am__item' . ($desk ? ' am--desk' : '') . '" role="menuitem" tabindex="-1" data-menu-item data-row="item-' . $i . '" data-key="' . e($key) . '" href="' . e(url($href)) . '"><span class="am__ic">' . icon($ic, 'size-4') . '</span><span class="am__il">' . e($label) . '</span>' . ($value !== '' ? '<span class="am__iv">' . e($value) . '</span>' : '') . '</a>';
    }
    // (no "Switch account": the app has one account per sign-in, so that page is not rendered at all)
    $o_ .= '<hr class="am__sep"><button type="button" class="am__item am__hold" role="menuitem" tabindex="-1" data-menu-item data-row="sign-out" data-hold="1200">'
        . '<span class="am__face">' . icon('log-out', 'size-4') . '<span class="am__il">Sign out</span><span class="am__iv" data-hold-hint>Hold</span></span><span class="sr-only">, press and hold to confirm</span>'
        . '<span class="am__fill" aria-hidden="true"><span class="am__face">' . icon('log-out', 'size-4') . '<span class="am__il">Sign out</span><span class="am__iv" data-hold-hint>Hold</span></span></span></button>'
        . '</div></div></div></div></div>'
        . '<form method="post" action="' . e(url('/do/logout')) . '" data-logout hidden>' . csrf_field() . '</form></div>';
    return $o_;
}
