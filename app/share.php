<?php
declare(strict_types=1);
/**
 * Beautiful share links (H4). A person publishes a read-only page with the widgets they choose at /s/<token>.
 *  - tokens are 144 random bits; only sha256(token) is used for lookup (UNIQUE index) and the token itself is stored encrypted so the owner can copy the link again
 *  - the public page never loads the app shell, scripts or cookies other than the PHP session used for the password gate; it is noindex, no-store and rate limited
 *  - data comes from share_data(): a whitelist of aggregates (no email, no account names, no transaction descriptions); "mask amounts" removes every rupee value
 */
const SHARE_MAX_LINKS = 25;
const SHARE_WIDGETS = [
    'summary' => ['This month', 'Income, spending and what was saved this month', 'calendar-days'],
    'balance' => ['Balance', 'Total money, spendable and reserved', 'wallet'],
    'goals' => ['Goals', 'Progress bars for your savings goals', 'goal'],
    'spending' => ['Where money went', 'Spending by category this month', 'chart-pie'],
    'trend' => ['Six-month trend', 'Income, spending and savings over six months', 'chart-line'],
    'bills' => ['Upcoming bills', 'Next bills that are due', 'bell-ring'],
    'budget' => ['Budget', 'How much of this month\'s budget is used', 'gauge'],
];

// ============================ lifecycle ============================
function share_hash(string $token): string { return hash('sha256', $token); }
function share_url(string $token): string { return abs_url('/s/' . $token); }
function share_find(string $token): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) { return null; }
    $h = share_hash($token); $r = one('SELECT * FROM {c_share_links} WHERE token_hash=?', [$h]);
    return $r && hash_equals((string) $r['token_hash'], $h) ? $r : null;
}
function share_token_of(array $r): string { return secret_decrypt((string) ($r['token_enc'] ?? '')); }
/** active | paused | revoked | expired | limit */
function share_state(array $r): string {
    if ($r['status'] === 'revoked') { return 'revoked'; } if ($r['status'] === 'paused') { return 'paused'; }
    if ($r['expires_at'] !== null && strtotime((string) $r['expires_at'] . ' UTC') <= time()) { return 'expired'; }
    if ($r['max_views'] !== null && (int) $r['views'] >= (int) $r['max_views']) { return 'limit'; }
    return 'active';
}
function share_clean_widgets($w): array { $w = is_array($w) ? $w : (is_string($w) ? explode(',', $w) : []); return array_values(array_intersect(array_keys(SHARE_WIDGETS), array_map('strval', $w))); }
/** Validates the owner's input. Throws UserError with a friendly text. Returns the column values. */
function share_validate(array $in): array {
    $title = trim(preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', (string) ($in['title'] ?? '')) ?? ''); if ($title === '' || mb_strlen($title) > 80) { throw new UserError('Give the link a title of up to 80 characters.'); }
    $msg = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', (string) ($in['message'] ?? '')) ?? ''); if (mb_strlen($msg) > 400) { throw new UserError('The message can be up to 400 characters.'); }
    $widgets = share_clean_widgets($in['widgets'] ?? []); if (!$widgets) { throw new UserError('Choose at least one widget to show.'); }
    $exp = null; $e = (string) ($in['expires'] ?? 'never');
    if (in_array($e, ['1d', '7d', '30d', '90d'], true)) { $exp = gmdate('Y-m-d H:i:s', time() + (int) $e * 86400); }
    elseif ($e === 'date') { $d = (string) ($in['expires_on'] ?? ''); if (!valid_date($d) || $d < today()) { throw new UserError('Pick an expiry date that is today or later.'); } $exp = gmdate('Y-m-d H:i:s', strtotime($d . ' 23:59:59 ' . APP_TIMEZONE)); }
    elseif ($e !== 'never') { throw new UserError('Unknown expiry option.'); }
    $mv = trim((string) ($in['max_views'] ?? '')); $max = null; if ($mv !== '') { if (!ctype_digit($mv) || (int) $mv < 1 || (int) $mv > 100000) { throw new UserError('Maximum views is a whole number from 1 to 100000, or leave it empty.'); } $max = (int) $mv; }
    $style = in_array($in['style'] ?? 'tube', ['tube', 'dots', 'square'], true) ? (string) ($in['style'] ?? 'tube') : 'tube'; $color = qr_color((string) ($in['color'] ?? ''), '#0b3d2e'); if (qr_contrast_white($color) < 3.0) { throw new UserError('That QR colour is too light to scan. Pick a darker colour.'); }
    return ['title' => $title, 'message' => $msg, 'widgets' => implode(',', $widgets), 'mask' => !empty($in['mask']) ? 1 : 0, 'expires_at' => $exp, 'max_views' => $max, 'style' => $style, 'color' => $color];
}
/** Creates a link. Returns ['id' => int, 'token' => string, 'url' => string]. */
function share_create(int $uid, array $in): array {
    $v = share_validate($in); if ((int) val("SELECT COUNT(*) FROM {c_share_links} WHERE user_id=? AND status<>'revoked'", [$uid]) >= SHARE_MAX_LINKS) { throw new UserError('You already have ' . SHARE_MAX_LINKS . ' links. Revoke or delete one first.'); }
    $pw = (string) ($in['password'] ?? ''); if ($pw !== '' && (mb_strlen($pw) < 4 || mb_strlen($pw) > 72)) { throw new UserError('A link password needs 4 to 72 characters.'); }
    $token = c_token(18);
    $id = insert('INSERT INTO {c_share_links} (user_id,token_hash,token_hint,token_enc,title,message,widgets,mask,password_hash,expires_at,max_views,style,color) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$uid, share_hash($token), substr($token, 0, 4), secret_encrypt($token), $v['title'], $v['message'], $v['widgets'], $v['mask'], $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : null, $v['expires_at'], $v['max_views'], $v['style'], $v['color']]);
    return ['id' => $id, 'token' => $token, 'url' => share_url($token)];
}
function share_update(int $uid, int $id, array $in): void {
    $r = share_mine($uid, $id); $v = share_validate($in); $pw = (string) ($in['password'] ?? '');
    if ($pw !== '' && (mb_strlen($pw) < 4 || mb_strlen($pw) > 72)) { throw new UserError('A link password needs 4 to 72 characters.'); }
    $hash = !empty($in['password_clear']) ? null : ($pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : $r['password_hash']);
    run('UPDATE {c_share_links} SET title=?,message=?,widgets=?,mask=?,expires_at=?,max_views=?,style=?,color=?,password_hash=? WHERE id=? AND user_id=?', [$v['title'], $v['message'], $v['widgets'], $v['mask'], $v['expires_at'], $v['max_views'], $v['style'], $v['color'], $hash, $id, $uid]);
}
function share_mine(int $uid, int $id): array { $r = one('SELECT * FROM {c_share_links} WHERE id=? AND user_id=?', [$id, $uid]); if (!$r) { throw new UserError('Link not found.'); } return $r; }
function share_set_status(int $uid, int $id, string $status): void {
    $r = share_mine($uid, $id); if ($r['status'] === 'revoked') { throw new UserError('A revoked link cannot be turned back on. Create a new one.'); }
    run('UPDATE {c_share_links} SET status=? WHERE id=? AND user_id=?', [$status, $id, $uid]);
}
function share_list(int $uid): array { return all('SELECT * FROM {c_share_links} WHERE user_id=? ORDER BY id DESC', [$uid]); }

// ============================ sanitized data ============================
/** The only data the public page ever sees. $mask removes every amount. Names of goals, bills and categories are shown (the owner chose those widgets). */
function share_data(int $uid, array $widgets, bool $mask): array {
    $s = load_snapshot($uid); $month = month_of($s['today']); $d = dashboard_data($s, $month); $sum = $d['sum']; $out = [];
    $amt = fn(int $v) => $mask ? null : $v; $cut = fn($t, int $n = 40) => mb_substr(trim((string) $t), 0, $n);
    foreach (share_clean_widgets($widgets) as $w) {
        switch ($w) {
            case 'summary': $out[$w] = ['month' => month_label($month), 'income' => $amt((int) $sum['income']), 'expenses' => $amt((int) $sum['expenses']), 'saved' => $amt((int) $sum['net']), 'rate' => $sum['rate'] === null ? null : round((float) $sum['rate'])]; break;
            case 'balance': $tot = max(1, (int) $d['cash']); $out[$w] = ['total' => $amt((int) $d['cash']), 'spendable' => $amt((int) $d['spendable']), 'reserved' => $amt((int) $d['reserved']), 'reserved_pct' => $d['cash'] > 0 ? round($d['reserved'] / $tot * 100) : 0]; break;
            case 'goals': $out[$w] = array_map(fn($g) => ['name' => $cut($g['name']), 'percent' => (int) round($g['percent']), 'saved' => $amt((int) $g['saved']), 'target' => $amt((int) $g['target_amount']), 'behind' => (bool) $g['behind'], 'complete' => (bool) $g['complete']], array_slice($d['goals'], 0, 6)); break;
            case 'spending': $items = array_slice($d['breakdown'], 0, 6); $tot = max(1, array_sum(array_map(fn($b) => (int) ($b['amount'] ?? $b['value'] ?? 0), $d['breakdown'])));
                $out[$w] = array_map(fn($b) => ['name' => $cut($b['name'] ?? $b['label'] ?? 'Other'), 'share' => (int) round((int) ($b['amount'] ?? $b['value'] ?? 0) / $tot * 100), 'amount' => $amt((int) ($b['amount'] ?? $b['value'] ?? 0))], $items); break;
            case 'trend': $mx = max(1, ...array_map(fn($m) => max((int) $m['income'], (int) $m['expenses'], 0), $d['series'])); $out[$w] = array_map(fn($m) => ['label' => substr((string) $m['label'], 0, 3), 'income' => $mask ? null : (int) $m['income'], 'expenses' => $mask ? null : (int) $m['expenses'], 'net' => $mask ? null : (int) $m['net'],
                'income_pct' => (int) round((int) $m['income'] / $mx * 100), 'expenses_pct' => (int) round((int) $m['expenses'] / $mx * 100)], $d['series']); break;
            case 'bills': $out[$w] = array_map(fn($o) => ['name' => $cut($o['bill']['name']), 'due' => date_short($o['due_date']), 'overdue' => (bool) $o['overdue'], 'amount' => $amt((int) $o['outstanding'])], array_slice($d['upcoming'], 0, 6)); break;
            case 'budget': $out[$w] = ['has' => (bool) $d['has_budgets'], 'used_pct' => $d['total_budget'] > 0 ? (int) round($d['budget_spent'] / $d['total_budget'] * 100) : 0, 'spent' => $amt((int) $d['budget_spent']), 'total' => $amt((int) $d['total_budget'])]; break;
        }
    }
    return $out;
}

// ============================ public page ============================
function share_css(): string {
    return ':root{--bg:#f4f1ea;--card:#fff;--ink:#16211c;--mute:#5d6b64;--line:#e4dfd3;--accent:#0b6b4d;--soft:#eef3ee;--neg:#b42318;--c1:#0b6b4d;--c2:#d4560f;--c3:#e0a800}@media(prefers-color-scheme:dark){:root{--bg:#0f1512;--card:#18211c;--ink:#eaf1ec;--mute:#9db0a5;--line:#2a372f;--accent:#58d3a0;--soft:#1f2b25;--neg:#ff8b7a;--c1:#58d3a0;--c2:#ff9a5c;--c3:#f3c74d}}'
        . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-text-size-adjust:100%}.wrap{max-width:720px;margin:0 auto;padding:20px 16px 48px}'
        . 'h1{font-size:1.75rem;line-height:1.2;margin:.2rem 0 .4rem;letter-spacing:-.01em}h2{font-size:1.05rem;margin:0 0 .75rem}.sub{color:var(--mute);margin:0 0 1.2rem}.msg{background:var(--soft);border-radius:16px;padding:14px 16px;margin:0 0 16px;white-space:pre-line}'
        . '.card{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:18px;margin:0 0 14px}.row{display:flex;justify-content:space-between;gap:12px;align-items:baseline;padding:6px 0}.num{font-variant-numeric:tabular-nums;font-weight:600}'
        . '.big{font-size:2rem;font-weight:700;letter-spacing:-.02em}.mute{color:var(--mute)}.bar{height:10px;border-radius:99px;background:var(--soft);overflow:hidden}.bar>i{display:block;height:100%;border-radius:99px;background:var(--c1)}.bar.warn>i{background:var(--c2)}'
        . '.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.tile{background:var(--soft);border-radius:16px;padding:12px}.tile b{display:block;font-size:1.15rem;margin-top:2px}.hide{letter-spacing:.2em;color:var(--mute)}'
        . '.foot{text-align:center;color:var(--mute);font-size:.8rem;margin-top:24px}.pill{display:inline-block;font-size:.72rem;border:1px solid var(--line);border-radius:99px;padding:2px 10px;color:var(--mute);margin-left:6px;vertical-align:middle}'
        . 'form.pw{display:grid;gap:10px;margin-top:10px}input[type=password]{font:inherit;padding:12px 14px;border-radius:14px;border:1px solid var(--line);background:var(--card);color:var(--ink);width:100%}button{font:inherit;font-weight:600;padding:12px 16px;border-radius:14px;border:0;background:var(--accent);color:#fff;min-height:44px;cursor:pointer}@media(prefers-color-scheme:dark){button{color:#08140e}}'
        . '.err{color:var(--neg);font-size:.9rem}svg{max-width:100%;height:auto}@media(max-width:420px){.grid3{grid-template-columns:1fr}.big{font-size:1.7rem}}';
}
function share_money(?int $v): string { return $v === null ? '<span class="hide" aria-label="hidden">••••</span>' : e(fmt($v)); }
function share_page_shell(string $title, string $body, array $og = [], int $code = 200): void {
    c_status($code); c_hdr('Content-Type: text/html; charset=utf-8'); c_hdr('Cache-Control: no-store, max-age=0'); c_hdr('X-Robots-Tag: noindex, nofollow, noarchive'); c_hdr('Referrer-Policy: no-referrer'); c_hdr('X-Frame-Options: DENY');
    c_hdr("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    $m = ''; foreach ($og as $k => $v) { $m .= '<meta property="og:' . e($k) . '" content="' . e($v) . '">'; }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="color-scheme" content="light dark"><title>' . e($title) . '</title>'
        . '<meta name="twitter:card" content="summary_large_image">' . $m . '<style>' . share_css() . '</style></head><body><div class="wrap">' . $body . '<p class="foot">Shared with ' . e(APP_NAME) . '. Read only.</p></div></body></html>';
}
function share_notice(string $head, string $text, int $code): void { share_page_shell($head, '<div class="card" style="margin-top:12vh;text-align:center"><h1>' . e($head) . '</h1><p class="sub">' . e($text) . '</p></div>', [], $code); }
function share_bars_svg(array $rows, bool $mask): string {
    $n = count($rows); if (!$n) { return ''; } $w = 640; $h = 190; $gw = $w / $n; $o = '<svg viewBox="0 0 ' . $w . ' ' . ($h + 26) . '" role="img" aria-label="Six-month trend">';
    foreach ($rows as $i => $r) { $x = $i * $gw + $gw * 0.18; $bw = $gw * 0.30; $a = max(2, $r['income_pct'] / 100 * $h); $b = max(2, $r['expenses_pct'] / 100 * $h);
        $o .= '<rect x="' . round($x, 1) . '" y="' . round($h - $a, 1) . '" width="' . round($bw, 1) . '" height="' . round($a, 1) . '" rx="5" fill="var(--c1)"/><rect x="' . round($x + $bw + 4, 1) . '" y="' . round($h - $b, 1) . '" width="' . round($bw, 1) . '" height="' . round($b, 1) . '" rx="5" fill="var(--c2)"/>'
            . '<text x="' . round($i * $gw + $gw / 2, 1) . '" y="' . ($h + 18) . '" text-anchor="middle" font-size="13" fill="var(--mute)">' . e($r['label']) . '</text>'; }
    return $o . '</svg><p class="mute" style="font-size:.85rem;margin:.4rem 0 0"><span style="color:var(--c1)">■</span> Income &nbsp;<span style="color:var(--c2)">■</span> Spending' . ($mask ? ' · bars show proportions only' : '') . '</p>';
}
function share_donut_svg(array $rows): string {
    $cols = ['var(--c1)', 'var(--c2)', 'var(--c3)', '#4f7cac', '#8c6bb1', '#7a8b82']; $tot = max(1, array_sum(array_column($rows, 'share'))); $o = '<svg viewBox="-60 -60 120 120" width="160" height="160" role="img" aria-label="Spending split"><circle r="40" fill="none" stroke="var(--soft)" stroke-width="16"/>'; $off = 0.0; $C = 2 * M_PI * 40;
    foreach ($rows as $i => $r) { $len = $r['share'] / $tot * $C; $o .= '<circle r="40" fill="none" stroke="' . $cols[$i % 6] . '" stroke-width="16" stroke-dasharray="' . round($len, 2) . ' ' . round($C - $len, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90)"/>'; $off += $len; }
    return $o . '</svg>';
}
/** The widgets' HTML (shared by the public page and the owner's preview). */
function share_widgets_html(array $data, bool $mask): string {
    $o = '';
    foreach ($data as $w => $d) {
        $title = SHARE_WIDGETS[$w][0] ?? $w; $o .= '<section class="card" aria-label="' . e($title) . '"><h2>' . e($title) . ($mask ? '<span class="pill">amounts hidden</span>' : '') . '</h2>';
        switch ($w) {
            case 'summary': $o .= '<div class="grid3"><div class="tile"><span class="mute">Income</span><b>' . share_money($d['income']) . '</b></div><div class="tile"><span class="mute">Spent</span><b>' . share_money($d['expenses']) . '</b></div><div class="tile"><span class="mute">Saved</span><b>' . share_money($d['saved']) . '</b></div></div>'
                . '<p class="mute" style="margin:.8rem 0 0">' . e($d['month']) . ($d['rate'] !== null ? ' · saved ' . (int) $d['rate'] . '% of income' : '') . '</p>'; break;
            case 'balance': $o .= '<p class="big" style="margin:0">' . share_money($d['total']) . '</p><div class="row"><span class="mute">Spendable</span><span class="num">' . share_money($d['spendable']) . '</span></div><div class="row"><span class="mute">Reserved for goals</span><span class="num">' . share_money($d['reserved']) . '</span></div>'
                . ($mask ? '<p class="mute" style="margin:.4rem 0 0">' . (int) $d['reserved_pct'] . '% of the money is reserved</p>' : ''); break;
            case 'goals': if (!$d) { $o .= '<p class="mute">No goals yet.</p>'; } foreach ($d as $g) { $o .= '<div style="margin:0 0 14px"><div class="row" style="padding:0 0 6px"><span>' . e($g['name']) . '</span><span class="num">' . (int) $g['percent'] . '%</span></div><div class="bar' . ($g['behind'] ? ' warn' : '') . '" role="progressbar" aria-valuenow="' . (int) $g['percent'] . '" aria-valuemin="0" aria-valuemax="100"><i style="width:' . max(0, min(100, (int) $g['percent'])) . '%"></i></div>'
                . ($g['saved'] !== null ? '<div class="mute" style="font-size:.85rem;margin-top:4px">' . e(fmt($g['saved'])) . ' of ' . e(fmt($g['target'])) . '</div>' : '') . '</div>'; } break;
            case 'spending': if (!$d) { $o .= '<p class="mute">Nothing spent yet this month.</p>'; break; } $o .= '<div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap"><div>' . share_donut_svg($d) . '</div><div style="flex:1;min-width:180px">'; $cols = ['var(--c1)', 'var(--c2)', 'var(--c3)', '#4f7cac', '#8c6bb1', '#7a8b82'];
                foreach ($d as $i => $r) { $o .= '<div class="row"><span><span style="color:' . $cols[$i % 6] . '">●</span> ' . e($r['name']) . '</span><span class="num">' . ($r['amount'] === null ? (int) $r['share'] . '%' : e(fmt($r['amount']))) . '</span></div>'; } $o .= '</div></div>'; break;
            case 'trend': $o .= share_bars_svg($d, $mask); break;
            case 'bills': if (!$d) { $o .= '<p class="mute">Nothing due soon.</p>'; } foreach ($d as $b) { $o .= '<div class="row"><span>' . e($b['name']) . ' <span class="mute">· ' . ($b['overdue'] ? '<span style="color:var(--neg)">overdue</span>' : e($b['due'])) . '</span></span><span class="num">' . share_money($b['amount']) . '</span></div>'; } break;
            case 'budget': if (!$d['has']) { $o .= '<p class="mute">No budget set.</p>'; break; } $o .= '<div class="bar' . ($d['used_pct'] > 100 ? ' warn' : '') . '"><i style="width:' . max(0, min(100, (int) $d['used_pct'])) . '%"></i></div><div class="row"><span class="mute">' . (int) $d['used_pct'] . '% used</span><span class="num">' . ($d['spent'] === null ? '' : e(fmt($d['spent'])) . ' of ' . e(fmt($d['total']))) . '</span></div>'; break;
        }
        $o .= '</section>';
    }
    return $o;
}
function share_public(string $token, string $sub = ''): void {
    $ip = client_ip(); if (!c_rate_ok('share:' . $ip, 90, 60)) { c_hdr('Retry-After: 60'); share_notice('Slow down', 'Too many requests. Please try again in a minute.', 429); return; }
    $r = share_find($token);
    if (!$r) { share_notice('This link is not available', 'It may have been typed wrongly or removed.', 404); return; }
    $st = share_state($r);
    if ($sub === 'og.png') { share_og_png($r); return; }
    if ($sub === 'qr.svg') { c_hdr('Content-Type: image/svg+xml'); c_hdr('Cache-Control: no-store'); echo qr_svg(qr_matrix(share_url($token), 'M'), ['fg' => $r['color'], 'style' => $r['style']]); return; }
    if ($st === 'revoked') { share_notice('This link was turned off', 'The person who shared it has revoked access.', 410); return; }
    if ($st === 'paused') { share_notice('This link is paused', 'The person who shared it has paused access for now. Try again later.', 403); return; }
    if ($st === 'expired') { share_notice('This link has expired', 'Ask the person who shared it to send a new one.', 410); return; }
    if ($st === 'limit') { share_notice('This link has reached its view limit', 'Ask the person who shared it to send a new one.', 410); return; }
    $og = ['type' => 'website', 'title' => $r['title'], 'description' => $r['password_hash'] ? 'A private view shared with you. Enter the password to open it.' : ($r['message'] !== '' ? mb_substr($r['message'], 0, 160) : 'A read-only snapshot shared with you on ' . APP_NAME . '.'), 'site_name' => APP_NAME, 'url' => share_url($token), 'image' => abs_url('/s/' . $token . '/og.png')];
    start_session(); $k = 'share_ok_' . (int) $r['id'];
    if ($r['password_hash']) {
        $ok = !empty($_SESSION[$k]); $err = '';
        if (!$ok && method() === 'POST') {
            if (!c_rate_ok('sharepw:' . $r['id'] . ':' . $ip, 8, 600)) { share_notice('Too many attempts', 'Wait ten minutes and try again.', 429); return; }
            $sent = (string) ($_POST['password'] ?? ''); if (hash_equals((string) ($_SESSION['share_csrf'] ?? ''), (string) ($_POST['t'] ?? '-')) && password_verify($sent, (string) $r['password_hash'])) { $_SESSION[$k] = 1; if (PHP_SAPI !== 'cli') { session_regenerate_id(true); } $ok = true; } else { $err = 'That password is not right.'; }
        }
        if (!$ok) { $_SESSION['share_csrf'] = bin2hex(random_bytes(12)); c_session_close();
            share_page_shell($r['title'], '<div class="card" style="margin-top:10vh"><h1>' . e($r['title']) . '</h1><p class="sub">This view is protected. Enter the password you were given.</p><form class="pw" method="post" action="' . e(url('/s/' . $token)) . '"><input type="hidden" name="t" value="' . e($_SESSION['share_csrf']) . '"><label for="pw" class="mute">Password</label><input id="pw" type="password" name="password" autocomplete="off" required autofocus>'
                . ($err ? '<p class="err" role="alert">' . e($err) . '</p>' : '') . '<button type="submit">Open</button></form></div>', $og, $err ? 401 : 200); return; }
    }
    if (method() === 'POST') { c_status(303); c_hdr('Location: ' . url('/s/' . $token)); if (PHP_SAPI !== 'cli') { exit; } return; } // password accepted: reload as GET
    // count the view (the same visitor session counts once per 10 minutes) and respect the limit atomically
    $seen = (int) ($_SESSION['share_seen_' . $r['id']] ?? 0); $count = $seen < time() - 600;
    if ($count) { if (run('UPDATE {c_share_links} SET views=views+1, last_viewed_at=UTC_TIMESTAMP() WHERE id=? AND status=\'active\' AND (max_views IS NULL OR views < max_views) AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())', [$r['id']]) === 0) { c_session_close(); share_notice('This link is no longer available', 'It has expired or reached its view limit.', 410); return; } $_SESSION['share_seen_' . $r['id']] = time(); }
    c_session_close();
    $data = share_data((int) $r['user_id'], explode(',', (string) $r['widgets']), (bool) $r['mask']);
    $body = '<p class="mute" style="margin:0">' . e(APP_NAME) . '</p><h1>' . e($r['title']) . '</h1>' . ($r['message'] !== '' ? '<div class="msg">' . e($r['message']) . '</div>' : '<p class="sub">Updated ' . e(date_label(today())) . '</p>') . share_widgets_html($data, (bool) $r['mask']);
    share_page_shell($r['title'], $body, $og);
}
/** 1200x630 preview card. Shows only the title and the app name, never any figures. */
function share_og_svg(array $r): string {
    $title = e(mb_substr((string) $r['title'], 0, 48)); $app = e(APP_NAME);
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630"><rect width="1200" height="630" fill="#0b3d2e"/><text x="70" y="120" font-family="system-ui,Segoe UI,Roboto,sans-serif" font-size="30" fill="#58d3a0">' . $app . '</text>'
        . '<text x="70" y="270" font-family="system-ui,Segoe UI,Roboto,sans-serif" font-size="58" font-weight="700" fill="#f4f1ea">' . $title . '</text><text x="70" y="560" font-family="system-ui,Segoe UI,Roboto,sans-serif" font-size="26" fill="#58d3a0">Read-only view shared with you</text></svg>';
}
function share_og_png(array $r): void {
    if (!gd_png_ok(true)) { c_hdr('Content-Type: image/svg+xml; charset=utf-8'); c_hdr('Cache-Control: public, max-age=300'); echo share_og_svg($r); return; }   // no GD / FreeType on this host: an SVG card instead of a fatal error
    $im = imagecreatetruecolor(1200, 630); $bg = imagecolorallocate($im, 11, 61, 46); $fg = imagecolorallocate($im, 244, 241, 234); $ac = imagecolorallocate($im, 88, 211, 160); imagefilledrectangle($im, 0, 0, 1200, 630, $bg);
    $font = __DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf'; $reg = __DIR__ . '/../assets/fonts/DejaVuSans.ttf'; $title = mb_substr((string) $r['title'], 0, 60);
    $m = qr_matrix(share_url(share_token_of($r)) ?: 'x', 'M'); $qr = imagecreatefromstring(qr_png($m, ['fg' => '#0b3d2e', 'bg' => '#f4f1ea', 'size' => 300, 'style' => $r['style'] ?? 'tube'])); if ($qr) { imagecopy($im, $qr, 840, 165, 0, 0, 300, 300); }
    if (is_file($font) && function_exists('imagettftext')) {
        imagettftext($im, 22, 0, 70, 120, $ac, $reg, APP_NAME); $lines = explode("\n", wordwrap($title, 20, "\n", true)); $y = 250; foreach (array_slice($lines, 0, 3) as $ln) { imagettftext($im, 54, 0, 70, $y, $fg, $font, $ln); $y += 76; }
        imagettftext($im, 24, 0, 70, 560, $ac, $reg, 'Read-only view shared with you');
    }
    c_hdr('Content-Type: image/png'); c_hdr('Cache-Control: public, max-age=300'); imagepng($im);
}

// ============================ owner UI ============================
function share_gate(array $u): ?string { $fa = feature_access($u, 'share_links'); return $fa['ok'] ? null : $fa['reason']; }
function share_locked_card(string $reason): void { echo empty_state('Share links are a plugin', e($reason), '<a class="btn btn-primary" href="' . e(url('/plugins') . '#plug-share_links') . '">See plugins</a>', 'share-2'); }
function share_state_badge(array $r): string { return match (share_state($r)) { 'active' => badge('Active', 'pos'), 'paused' => badge('Paused', 'warn'), 'revoked' => badge('Revoked', 'neg'), 'expired' => badge('Expired', 'neutral'), default => badge('View limit reached', 'neutral') }; }
function share_sheet_html(array $r, string $token): string {
    $url = share_url($token); $text = $r['title'] . ' - ' . $url;
    return '<div class="flex flex-wrap items-center gap-2" data-share-sheet data-url="' . e($url) . '" data-title="' . e($r['title']) . '">'
        . '<button type="button" class="btn btn-sm" data-share-copy>' . icon('copy', 'size-4') . 'Copy link</button><button type="button" class="btn btn-sm hidden" data-share-native>' . icon('share-2', 'size-4') . 'Share…</button>'
        . '<a class="btn btn-sm" target="_blank" rel="noopener" href="' . e('https://wa.me/?text=' . rawurlencode($text)) . '">' . icon('message-circle', 'size-4') . 'WhatsApp</a>'
        . '<a class="btn btn-sm" target="_blank" rel="noopener" href="' . e('https://t.me/share/url?url=' . rawurlencode($url) . '&text=' . rawurlencode($r['title'])) . '">' . icon('send', 'size-4') . 'Telegram</a></div>';
}
function page_share(): void {
    $u = require_login(); $s = snap(); layout_start('Share links', 'plugins', $s);
    page_header('Share links', 'Show a read-only page of your choice to friends or family with a private link.', share_gate($u) === null ? '<a class="btn btn-dark btn-sm" href="' . e(url('/share/new')) . '">' . icon('plus', 'size-4') . 'New link</a>' : '');
    if ($g = share_gate($u)) { share_locked_card($g); layout_end(); return; }
    $links = share_list((int) $u['id']); echo '<link rel="stylesheet" href="' . e(asset('c.css')) . '">';
    if (!$links) { echo empty_state('No links yet', 'Create one to share this month, your goals or your trend. You decide what is shown, and you can switch a link off at any time.', '<a class="btn btn-primary" href="' . e(url('/share/new')) . '">Create a link</a>', 'share-2'); }
    echo '<div class="grid gap-4 lg:grid-cols-2">';
    foreach ($links as $r) {
        $token = share_token_of($r); $st = share_state($r); $qr = $token !== '' ? url('/share/qr', ['id' => $r['id'], 'fmt' => 'svg']) : '';
        echo '<article class="card min-w-0 p-5" id="link-' . (int) $r['id'] . '"><div class="flex flex-wrap items-start justify-between gap-2"><div class="min-w-0"><h2 class="truncate text-lg font-semibold tracking-tight">' . e($r['title']) . '</h2><p class="text-xs text-mute">Created ' . e(substr((string) $r['created_at'], 0, 10)) . ' · ' . count(explode(',', (string) $r['widgets'])) . ' widgets' . ($r['mask'] ? ' · amounts hidden' : '') . '</p></div>' . share_state_badge($r) . '</div>';
        echo '<div class="mt-4 flex flex-wrap items-start gap-4">' . ($qr ? '<div class="share-qr shrink-0"><img src="' . e($qr) . '" alt="QR code for ' . e($r['title']) . '" width="132" height="132"></div>' : '')
            . '<div class="min-w-0 flex-1 space-y-1 text-sm"><p class="num"><span class="text-mute">Views</span> <strong>' . (int) $r['views'] . '</strong>' . ($r['max_views'] !== null ? ' <span class="text-mute">of ' . (int) $r['max_views'] . '</span>' : '') . '</p>'
            . '<p class="text-mute">Last viewed ' . ($r['last_viewed_at'] ? e(substr((string) $r['last_viewed_at'], 0, 16)) . ' UTC' : 'never') . '</p>'
            . '<p class="text-mute">' . ($r['expires_at'] ? 'Expires ' . e(substr((string) $r['expires_at'], 0, 10)) : 'Never expires') . ($r['password_hash'] ? ' · password' : '') . '</p>'
            . ($token !== '' && $st === 'active' ? '<p class="share-url truncate font-mono text-xs text-mute" title="' . e(share_url($token)) . '">' . e(share_url($token)) . '</p>' : '') . '</div></div>';
        if ($token !== '') { echo '<div class="mt-4">' . share_sheet_html($r, $token) . '</div>'; }
        echo '<div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">';
        if ($token !== '') { echo '<a class="btn btn-sm btn-ghost" href="' . e(url('/s/' . $token)) . '" target="_blank" rel="noopener">' . icon('eye', 'size-4') . 'Preview</a><a class="btn btn-sm btn-ghost" href="' . e(url('/share/qr', ['id' => $r['id'], 'fmt' => 'svg', 'dl' => 1])) . '" download>' . icon('download', 'size-4') . 'QR SVG</a><a class="btn btn-sm btn-ghost" href="' . e(url('/share/qr', ['id' => $r['id'], 'fmt' => 'png', 'dl' => 1])) . '" download>QR PNG</a>'; }
        if ($r['status'] !== 'revoked') { echo '<a class="btn btn-sm btn-ghost" href="' . e(url('/share/new', ['id' => $r['id']])) . '">Edit</a>' . post_btn($r['status'] === 'paused' ? 'Resume' : 'Pause', $r['status'] === 'paused' ? 'share-resume' : 'share-pause', ['id' => $r['id']], 'btn btn-sm btn-ghost')
            . confirm_btn('Revoke', 'share-revoke', ['id' => $r['id']], 'Revoke this link?', 'Anyone with the link or QR code loses access straight away. This cannot be undone.', 'Revoke'); }
        echo confirm_btn('Delete', 'share-delete', ['id' => $r['id']], 'Delete this link?', 'The link stops working and its history is removed.', 'Delete') . '</div></article>';
    }
    echo '</div><script src="' . e(asset('share.js')) . '" defer></script>'; layout_end();
}
function page_share_new(): void {
    $u = require_login(); $s = snap(); $id = (int) ($_GET['id'] ?? 0); $r = $id ? one('SELECT * FROM {c_share_links} WHERE id=? AND user_id=?', [$id, $u['id']]) : null;
    layout_start($r ? 'Edit share link' : 'New share link', 'plugins', $s); page_header($r ? 'Edit share link' : 'New share link', 'Choose what friends see. They never see your name, email, accounts or individual transactions.', '<a class="btn btn-sm" href="' . e(url('/share')) . '">Back</a>');
    if ($g = share_gate($u)) { share_locked_card($g); layout_end(); return; }
    $sel = $r ? explode(',', (string) $r['widgets']) : ['summary', 'goals']; $exp = $r && $r['expires_at'] ? 'date' : 'never'; $expOn = $r && $r['expires_at'] ? substr((string) $r['expires_at'], 0, 10) : '';
    echo '<link rel="stylesheet" href="' . e(asset('c.css')) . '"><div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">';
    card_open($r ? 'Link settings' : 'Create a link');
    echo form_open($r ? 'share-update' : 'share-create', 'grid grid-cols-2 gap-x-3 gap-y-4') . ($r ? '<input type="hidden" name="id" value="' . (int) $r['id'] . '">' : '')
        . form_fields([['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => $r['title'] ?? 'Our money this month', 'hint' => 'Shown at the top and in link previews.'], ['name' => 'message', 'label' => 'Message (optional)', 'type' => 'textarea', 'value' => $r['message'] ?? '', 'placeholder' => 'A short note for the people you share this with']]);
    echo '<fieldset class="col-span-2"><legend class="label">What to show</legend><div class="grid gap-2 sm:grid-cols-2">';
    foreach (SHARE_WIDGETS as $k => [$l, $d, $ic]) { echo '<label class="flex min-h-11 items-start gap-3 rounded-2xl border border-line p-3 text-sm"><input type="checkbox" name="widgets[]" value="' . e($k) . '" class="mt-1 size-4 accent-[var(--lime-dk)]"' . (in_array($k, $sel, true) ? ' checked' : '') . '><span><span class="flex items-center gap-2 font-medium">' . icon($ic, 'size-4') . e($l) . '</span><span class="block text-xs text-mute">' . e($d) . '</span></span></label>'; }
    echo '</div></fieldset>' . form_fields([['name' => 'mask', 'label' => 'Hide all amounts (show percentages and shapes only)', 'type' => 'checkbox', 'value' => $r ? (bool) $r['mask'] : true],
        ['name' => 'expires', 'label' => 'Expires', 'type' => 'select', 'required' => true, 'half' => true, 'options' => ['never' => 'Never', '1d' => 'In 1 day', '7d' => 'In 7 days', '30d' => 'In 30 days', '90d' => 'In 90 days', 'date' => 'On a date…'], 'value' => $exp],
        ['name' => 'expires_on', 'label' => 'Expiry date', 'type' => 'date', 'half' => true, 'value' => $expOn], ['name' => 'max_views', 'label' => 'Maximum views (optional)', 'type' => 'number', 'min' => 1, 'max' => 100000, 'half' => true, 'value' => $r['max_views'] ?? ''],
        ['name' => 'password', 'label' => $r && $r['password_hash'] ? 'New password (leave empty to keep)' : 'Password (optional)', 'type' => 'password', 'autocomplete' => 'new-password', 'half' => true],
        ['name' => 'style', 'label' => 'QR style', 'type' => 'select', 'required' => true, 'half' => true, 'options' => ['tube' => 'Tube (joined, rounded)', 'dots' => 'Dots', 'square' => 'Classic squares'], 'value' => $r['style'] ?? 'tube'],
        ['name' => 'color', 'label' => 'QR colour', 'type' => 'color', 'half' => true, 'value' => $r['color'] ?? '#0b3d2e', 'hint' => 'Dark colours scan best.']]);
    if ($r && $r['password_hash']) { echo form_fields([['name' => 'password_clear', 'label' => 'Remove the password', 'type' => 'checkbox', 'value' => false]]); }
    echo submit_row($r ? 'Save changes' : 'Create link') . '</form>'; card_close();
    card_open('What people see', 'A preview with your current data'); echo '<p class="text-sm text-mute">After you save, open <strong>Preview</strong> on the link to see the exact public page. Revoke or pause it any time.</p><ul class="mt-3 space-y-1.5 text-sm text-mute"><li>• No login needed, nothing can be changed.</li><li>• Not listed in search engines.</li><li>• Unguessable address (144 random bits).</li></ul>'; card_close();
    echo '</div>'; layout_end();
}
function share_qr_http(): void {
    $u = require_login(); $r = one('SELECT * FROM {c_share_links} WHERE id=? AND user_id=?', [(int) ($_GET['id'] ?? 0), $u['id']]); if (!$r) { render_error(404, 'Not found', 'That link does not exist.'); return; }
    $tok = share_token_of($r); if ($tok === '') { render_error(404, 'Not found', 'That link has no address.'); return; }
    $fmt = ($_GET['fmt'] ?? 'svg') === 'png' && gd_png_ok() ? 'png' : 'svg'; $o = ['fg' => $r['color'], 'style' => $r['style'], 'size' => $fmt === 'png' ? 1024 : 640]; $m = qr_matrix(share_url($tok), 'M');
    c_hdr('Cache-Control: private, no-store'); if (!empty($_GET['dl'])) { c_hdr('Content-Disposition: attachment; filename="share-link-qr.' . $fmt . '"'); }
    if ($fmt === 'png') { c_hdr('Content-Type: image/png'); echo qr_png($m, $o); } else { c_hdr('Content-Type: image/svg+xml'); echo qr_svg($m, $o); }
}

// ============================ POST handlers ============================
function share_input(): array { return ['title' => (string) ($_POST['title'] ?? ''), 'message' => (string) ($_POST['message'] ?? ''), 'widgets' => $_POST['widgets'] ?? [], 'mask' => !empty($_POST['mask']), 'expires' => (string) ($_POST['expires'] ?? 'never'), 'expires_on' => (string) ($_POST['expires_on'] ?? ''),
    'max_views' => (string) ($_POST['max_views'] ?? ''), 'password' => (string) ($_POST['password'] ?? ''), 'password_clear' => !empty($_POST['password_clear']), 'style' => (string) ($_POST['style'] ?? 'tube'), 'color' => (string) ($_POST['color'] ?? '')]; }
function share_require(array $u): void { if ($g = share_gate($u)) { throw new UserError($g); } }
function do_share_create(array $u): string { share_require($u); $r = share_create((int) $u['id'], share_input()); $GLOBALS['__redirect'] = url('/share') . '#link-' . $r['id']; return 'Link created. Copy it or scan the QR code.'; }
function do_share_update(array $u): string { share_require($u); share_update((int) $u['id'], in_id('id'), share_input()); $GLOBALS['__redirect'] = url('/share'); return 'Link updated.'; }
function do_share_pause(array $u): string { share_require($u); share_set_status((int) $u['id'], in_id('id'), 'paused'); return 'Link paused. Nobody can open it until you resume.'; }
function do_share_resume(array $u): string { share_require($u); share_set_status((int) $u['id'], in_id('id'), 'active'); return 'Link is live again.'; }
function do_share_revoke(array $u): string { share_require($u); share_set_status((int) $u['id'], in_id('id'), 'revoked'); return 'Link revoked.'; }
function do_share_delete(array $u): string { $id = in_id('id'); share_mine((int) $u['id'], $id); run('DELETE FROM {c_share_links} WHERE id=? AND user_id=?', [$id, $u['id']]); return 'Link deleted.'; }
