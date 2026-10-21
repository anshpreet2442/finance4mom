<?php
declare(strict_types=1);
/**
 * The basic (non-AI) Telegram bot: commands, inline menus, guided "spent 450 on groceries", and one-tap-with-confirmation bill payments.
 * Every money action goes through the same service functions as the web app (create_transaction, pay_occurrence, ...), so the same validation
 * applies, and is written to the audit log. Text the person types is never written to the Telegram log.
 */

// ============================ helpers ============================
function tg_user(int $uid): ?array { return one('SELECT id,email,name,role,status FROM {users} WHERE id=?', [$uid]); }
/** Per-request context for one person (the money formatter reads the preferences from a global). */
function tg_boot(array $u): array { $s = load_snapshot((int) $u['id']); return $s; }
function tg_first_name(array $u): string { return explode(' ', trim((string) $u['name']))[0] ?: 'there'; }
function tg_m(int $minor): string { return tg_h(fmt($minor)); }
function tg_bar(float $pct, int $n = 8): string { $f = (int) round(max(0, min(100, $pct)) / 100 * $n); return str_repeat('▰', $f) . str_repeat('▱', $n - $f); }
function tg_can_write(array $u): bool { return billing_level($u) < 2; }
function tg_pending_new(int $uid, int $chat, string $kind, array $payload): int { return insert('INSERT INTO {telegram_pending} (user_id,chat_id,kind,payload) VALUES (?,?,?,?)', [$uid, $chat, $kind, json_encode($payload, JSON_UNESCAPED_UNICODE)]); }
function tg_pending_get(int $id, int $uid, int $chat, ?string $kind = null): ?array {
    $p = one("SELECT * FROM {telegram_pending} WHERE id=? AND user_id=? AND chat_id=? AND status='open' AND created_at >= ?", [$id, $uid, $chat, gmdate('Y-m-d H:i:s', time() - 3600)]);
    if (!$p || ($kind !== null && $p['kind'] !== $kind)) { return null; } $p['data'] = json_decode((string) $p['payload'], true) ?: []; return $p;
}
function tg_pending_set(int $id, array $payload): void { run('UPDATE {telegram_pending} SET payload=? WHERE id=?', [json_encode($payload, JSON_UNESCAPED_UNICODE), $id]); }
function tg_pending_close(int $id, string $status = 'done'): void { run('UPDATE {telegram_pending} SET status=? WHERE id=?', [$status, $id]); }
/** True when the person owns the Telegram AI plugin (whether the AI service is configured is reported by the gate in tg_ai_message). */
function tg_ai_active(array $u): bool { $fa = feature_access($u, 'telegram_ai'); return $fa['ok'] || $fa['code'] === 'wallet'; }

const TG_MENU_LABELS = ['balance' => '💰 Balance', 'bills' => '🧾 Bills', 'goals' => '🎯 Goals', 'report' => '📊 Report', 'add' => '➕ Add', 'help' => '❓ Help'];
function tg_reply_keyboard(): array {
    $l = array_values(TG_MENU_LABELS);
    return ['keyboard' => [[['text' => $l[0]], ['text' => $l[1]], ['text' => $l[2]]], [['text' => $l[3]], ['text' => $l[4]], ['text' => $l[5]]]], 'resize_keyboard' => true, 'is_persistent' => true, 'input_field_placeholder' => 'Try: spent 450 on groceries'];
}
function tg_menu_markup(int $chat, int $uid): array {
    $b = fn($t, $k) => tg_btn($t, tg_cb($chat, $uid, 'm', $k));
    return tg_kb([[$b('💰 Balance', 'bal'), $b('🧾 Bills', 'bil')], [$b('🎯 Goals', 'gol'), $b('📊 Report', 'rep')], [$b('➕ Add expense', 'add'), $b('❓ Help', 'hlp')]]);
}
function tg_label_to_command(string $text): ?string {
    if (mb_strlen($text) > 20) { return null; } $k = preg_replace('/[^a-z]/', '', strtolower($text)); return isset(TG_MENU_LABELS[$k]) ? $k : null;
}

// ============================ entry point: messages ============================
function tg_on_message(array $m): void {
    $chat = (int) ($m['chat']['id'] ?? 0); if (!$chat || ($m['chat']['type'] ?? '') !== 'private') { return; }
    $from = is_array($m['from'] ?? null) ? $m['from'] : []; if (!empty($from['is_bot'])) { return; }
    $text = trim(mb_substr((string) ($m['text'] ?? $m['caption'] ?? ''), 0, 2000)); $photo = !empty($m['photo']) && is_array($m['photo']);
    if ($text === '' && !$photo) { return; }
    if ($lim = tg_rate_hit($chat)) {
        $first = (int) val("SELECT COUNT(*) FROM {telegram_log} WHERE direction='in' AND status='skipped' AND kind='rate' AND chat_id=? AND created_at >= ?", [$chat, gmdate('Y-m-d H:i:s', time() - 60)]) === 0;
        tg_log('in', $chat, null, 'rate', 'skipped', $lim);
        if ($first) { tg_send($chat, $lim === 'min' ? 'Whoa, slow down a little. Try again in a minute.' : 'That is the daily message limit for this chat. Try again tomorrow.'); }
        return;
    }
    $cmd = null; $arg = ''; if (preg_match('~^/([A-Za-z_]+)(?:@\w+)?(?:\s+(.*))?$~su', $text, $mm)) { $cmd = strtolower($mm[1]); $arg = trim((string) ($mm[2] ?? '')); }
    $link = tg_link_by_chat($chat); $uid = $link ? (int) $link['user_id'] : null;
    tg_log('in', $chat, $uid, $cmd !== null ? 'cmd:' . mb_substr($cmd, 0, 20) : ($photo ? 'photo' : 'text'));
    if ($cmd === 'start') { tg_cmd_start($chat, $from, $arg, $link); return; }
    if (!$link) { tg_send($chat, tg_unlinked_text()); return; }
    $u = tg_user((int) $link['user_id']);
    if (!$u || $u['status'] !== 'active') { tg_send($chat, 'This account is not active. Please contact the administrator.'); return; }
    run('UPDATE {telegram_links} SET last_seen_at=UTC_TIMESTAMP() WHERE user_id=?', [$u['id']]);
    if ($cmd === 'stop') { tg_unlink((int) $u['id'], true, 'chat'); return; }
    $fa = feature_access($u, 'telegram');
    if (!$fa['ok']) { tg_send($chat, 'The Telegram bot is part of a paid plan, and yours has not been unlocked yet. Get it here: ' . tg_h(tg_shop_url()), null, [], (int) $u['id']); return; }
    $s = tg_boot($u);
    if ($photo) { tg_on_photo($chat, $u, $link, $m, $text); return; }
    if ($cmd === null && ($lc = tg_label_to_command($text))) { $cmd = $lc; $arg = ''; }
    if ($cmd !== null) { tg_run_command($chat, $u, $link, $s, $cmd, $arg); return; }
    // plain text: a pending guided "add", then the AI (if owned), then the simple parser
    $await = one("SELECT id FROM {telegram_pending} WHERE user_id=? AND chat_id=? AND kind='await_add' AND status='open' AND created_at >= ? ORDER BY id DESC LIMIT 1", [$u['id'], $chat, gmdate('Y-m-d H:i:s', time() - 600)]);
    if ($await) { tg_pending_close((int) $await['id']); if (!tg_start_entry($chat, $u, $s, $text, true)) { $p = tg_pending_new((int) $u['id'], $chat, 'await_add', []); } return; }
    if (tg_ai_active($u) && !tg_looks_like_entry($text)) { tg_ai_message($chat, $u, $link, $text); return; }
    if (tg_start_entry($chat, $u, $s, $text, false)) { return; }
    if (tg_ai_active($u)) { tg_ai_message($chat, $u, $link, $text); return; }
    tg_nudge($chat, $u, $link);
}
function tg_unlinked_text(): string {
    return "Hi! I'm the " . tg_h(APP_NAME) . " bot, but I don't know who you are yet.\n\nTo connect: open " . tg_h(APP_NAME) . " → <b>Settings → Connections → Connect Telegram</b>, then tap <b>Start</b> in the chat that opens.\n" . tg_h(abs_url('/settings'));
}
/** A friendly reply for text the basic bot cannot act on. The upsell for the AI plugin appears at most once a day. */
function tg_nudge(int $chat, array $u, array $link): void {
    $h = "I did not catch that. Try <b>spent 450 on groceries</b>, or use the buttons below.";
    $upsell = '';
    if (!feature_on($u, 'telegram_ai') && ai_ready() && (!$link['upsell_at'] || $link['upsell_at'] < gmdate('Y-m-d H:i:s', time() - 86400))) {
        $upsell = "\n\nWant to just talk to your advisor here? That is the <b>Telegram AI plugin</b>: " . tg_h(tg_shop_url()); run('UPDATE {telegram_links} SET upsell_at=UTC_TIMESTAMP() WHERE user_id=?', [$u['id']]);
    }
    tg_send($chat, $h . $upsell, tg_menu_markup($chat, (int) $u['id']), [], (int) $u['id']);
}

// ============================ /start and linking ============================
function tg_cmd_start(int $chat, array $from, string $arg, ?array $link): void {
    $token = preg_match('/^[A-Za-z0-9_-]{20,64}$/', $arg) ? $arg : '';
    if ($token === '') {
        if (!$link) { tg_send($chat, tg_unlinked_text()); return; }
        $u = tg_user((int) $link['user_id']); if (!$u) { return; } tg_send($chat, 'Welcome back, ' . tg_h(tg_first_name($u)) . '. What do you need?', tg_reply_keyboard(), [], (int) $u['id']); return;
    }
    $r = tg_link_consume($token, $chat, $from);
    if (!$r['ok']) {
        tg_log('in', $chat, null, 'link', 'failed', $r['code']);
        tg_send($chat, match ($r['code']) {
            'expired' => 'That connect link has expired (they last ' . TG_LINK_TTL_MIN . ' minutes). Go back to ' . tg_h(APP_NAME) . ' and tap <b>Connect Telegram</b> again.',
            'used' => 'That connect link was already used. If this chat is not connected yet, tap <b>Connect Telegram</b> in the app again for a fresh one.',
            'chat_taken' => 'This Telegram chat is already connected to another ' . tg_h(APP_NAME) . ' account. Send /stop here first, then connect again.',
            'disabled' => 'That account is not active.',
            default => 'That connect link does not look right. Go back to ' . tg_h(APP_NAME) . ' and tap <b>Connect Telegram</b> again.' }); return;
    }
    $uid = (int) $r['user_id']; $u = tg_user($uid); audit('telegram.link', "user#$uid", 'chat'); tg_log('in', $chat, $uid, 'link');
    if ($r['old_chat']) { tg_send((int) $r['old_chat'], 'Your ' . tg_h(APP_NAME) . ' account was connected to a different Telegram chat, so this one is disconnected now.', ['remove_keyboard' => true]); }
    $ai = $u && tg_ai_active($u) ? "\n\nYour AI advisor is here too. Just write to me like you would to a friend." : '';
    tg_send($chat, 'You are connected, ' . tg_h(tg_first_name($u ?? ['name' => ''])) . '! 🎉 I am the ' . tg_h(APP_NAME) . " bot.\n\nTry:\n• <b>balance</b> to see your money\n• <b>bills</b> to see what is due and mark it paid\n• <b>spent 450 on groceries</b> to log an expense\n• /help for everything else\n\nSend /stop any time to disconnect." . $ai, tg_reply_keyboard(), [], $uid);
}

// ============================ commands ============================
function tg_run_command(int $chat, array $u, array $link, array $s, string $cmd, string $arg): void {
    $uid = (int) $u['id'];
    switch ($cmd) {
        case 'balance': case 'bal': [$h, $mk] = tg_view_balance($s, $chat, $uid); tg_send($chat, $h, $mk, [], $uid); return;
        case 'bills': case 'due': [$h, $mk] = tg_view_bills($s, $chat, $uid); tg_send($chat, $h, $mk, [], $uid); return;
        case 'goals': [$h, $mk] = tg_view_goals($s, $chat, $uid, $u); tg_send($chat, $h, $mk, [], $uid); return;
        case 'report': [$h, $mk] = tg_view_report($s, $chat, $uid, $u, $arg === 'last' ? 1 : 0); tg_send($chat, $h, $mk, [], $uid); return;
        case 'add': case 'spent': case 'expense': case 'income':
            if ($arg === '' ) { tg_pending_new($uid, $chat, 'await_add', []); tg_send($chat, "Tell me what happened in one line, like:\n<b>spent 450 on groceries</b>\n<b>received 5000 salary</b>\n<b>paid 1200 electricity yesterday</b>", null, [], $uid); return; }
            if (!tg_start_entry($chat, $u, $s, ($cmd === 'income' ? 'received ' : ($cmd === 'add' ? '' : 'spent ')) . $arg, true)) { tg_pending_new($uid, $chat, 'await_add', []); } return;
        case 'menu': tg_send($chat, 'What do you need?', tg_menu_markup($chat, $uid), [], $uid); return;
        case 'notify': tg_cmd_notify($chat, $u, $link, $arg); return;
        case 'forget': $n = tg_forget($uid); tg_send($chat, $n ? "Done. I have forgotten our chat. Your accounts, bills and what your advisor knows about you are untouched." : "There is nothing to forget yet.", null, [], $uid); return;
        case 'help': case 'commands': tg_send($chat, tg_help_text($u), tg_menu_markup($chat, $uid), [], $uid); return;
    }
    tg_send($chat, "I don't know that command. /help lists what I can do.", tg_menu_markup($chat, $uid), [], $uid);
}
function tg_help_text(array $u): string {
    $ai = tg_ai_active($u) ? "\n\n<b>Your advisor</b>\nJust write to me. Ask anything about your money, send a photo of a receipt, or tell me what you want to change. Nothing changes until you tap Apply. /forget clears our chat memory." : '';
    return "<b>What I can do</b>\n• /balance – your accounts and cash\n• /bills – what is due, with a Mark paid button\n• /add – log an expense or income (or just write <i>spent 450 on groceries</i>)\n• /goals – progress on your goals\n• /report – this month at a glance\n• /notify on|off – reminders here\n• /menu – the button menu\n• /stop – disconnect this chat" . $ai;
}
function tg_cmd_notify(int $chat, array $u, array $link, string $arg): void {
    $uid = (int) $u['id']; $c = tg_cfg();
    if (!$c['notify']) { tg_send($chat, 'Reminders in Telegram are switched off by the administrator.', null, [], $uid); return; }
    $a = strtolower($arg);
    if ($a === 'on' || $a === 'off') { run('UPDATE {telegram_links} SET notify=? WHERE user_id=?', [$a === 'on' ? 1 : 0, $uid]); tg_send($chat, $a === 'on' ? 'Done. I will message you about bills that are due soon.' : 'Okay, no more reminders here.', null, [], $uid); return; }
    tg_send($chat, 'Reminders are <b>' . ((int) $link['notify'] ? 'on' : 'off') . '</b>. Send <b>/notify on</b> or <b>/notify off</b> to change that.', null, [], $uid);
}

// ============================ views ============================
function tg_view_balance(array $s, int $chat, int $uid): array {
    $acc = active_accounts($s); $total = total_cash($s['accounts'], $s['txs'], $s['today']); $res = reserved_total($s['contribs']);
    $o = "<b>Your money</b>\nTotal cash: <b>" . tg_m($total) . "</b>\n"; foreach ($acc as $a) { $o .= '• ' . tg_h($a['name']) . ': ' . tg_m((int) $a['balance']) . "\n"; }
    if (!$acc) { $o .= "No accounts yet. Add one in the app.\n"; }
    if ($res > 0) { $o .= "\nSet aside for goals and bills: " . tg_m($res) . "\nFree to spend: <b>" . tg_m($total - $res) . '</b>'; }
    return [rtrim($o), tg_kb([[tg_btn('🧾 Bills', tg_cb($chat, $uid, 'm', 'bil')), tg_btn('📊 Report', tg_cb($chat, $uid, 'm', 'rep'))]])];
}
function tg_view_bills(array $s, int $chat, int $uid): array {
    $rows = []; foreach (occurrence_views($s) as $v) { if ($v['skipped'] || $v['outstanding'] <= 0 || $v['days'] > 30) { continue; } $rows[] = $v; }
    usort($rows, fn($a, $b) => $a['due_date'] <=> $b['due_date']); $rows = array_slice($rows, 0, 8);
    if (!$rows) { return ["Nothing is due in the next 30 days. 🙌", tg_kb([[tg_btn('📊 Report', tg_cb($chat, $uid, 'm', 'rep'))]])]; }
    $o = "<b>Due soon</b>\n"; $kb = [];
    foreach ($rows as $v) {
        $when = $v['overdue'] ? '<b>overdue</b> since ' . tg_h(date_short($v['due_date'])) : ($v['days'] === 0 ? '<b>due today</b>' : 'due ' . tg_h(date_short($v['due_date'])) . ' (' . ($v['days'] === 1 ? 'tomorrow' : 'in ' . $v['days'] . ' days') . ')');
        $o .= '• ' . tg_h($v['bill']['name']) . ' · ' . tg_m((int) $v['outstanding']) . ' · ' . $when . "\n";
        $kb[] = [tg_btn('Mark paid: ' . mb_substr((string) $v['bill']['name'], 0, 22), tg_cb($chat, $uid, 'mp', (int) $v['id']))];
    }
    return [rtrim($o), tg_kb($kb)];
}
function tg_view_goals(array $s, int $chat, int $uid, array $u): array {
    $g = goal_views($s); if (!$g) { return ["You have no goals yet. Add one in the app and I will track it here.", null]; }
    $o = "<b>Your goals</b>\n";
    foreach (array_slice($g, 0, 8) as $x) { $o .= "\n" . tg_h($x['name']) . ($x['behind'] ? ' ⚠️' : '') . "\n" . tg_bar((float) $x['percent']) . ' ' . round($x['percent']) . '%  ' . tg_m((int) $x['saved']) . ' of ' . tg_m((int) $x['target_amount']) . "\n"; }
    $kb = []; if (feature_on($u, 'telegram_ai')) { $kb[] = [tg_btn('📈 Goal chart', tg_cb($chat, $uid, 'ch', 'g'))]; }
    return [rtrim($o), tg_kb($kb)];
}
function tg_view_report(array $s, int $chat, int $uid, array $u, int $back = 0): array {
    $m = add_months(month_of($s['today']), -$back); $sum = summarize_month($s['accounts'], $s['txs'], $s['contribs'], $m); $prev = summarize_month($s['accounts'], $s['txs'], $s['contribs'], add_months($m, -1));
    $o = '<b>' . tg_h(month_label($m)) . "</b>\nIncome: " . tg_m((int) $sum['income']) . "\nSpent: " . tg_m((int) $sum['expenses']) . "\nSaved: <b>" . tg_m((int) $sum['net']) . '</b>' . ($sum['rate'] !== null ? ' (' . round($sum['rate']) . '% of income)' : '') . "\n";
    if ($prev['expenses'] > 0) { $d = (int) $sum['expenses'] - (int) $prev['expenses']; $o .= 'Compared with last month you spent ' . tg_m(abs($d)) . ($d > 0 ? ' more' : ' less') . ".\n"; }
    $cats = array_slice(category_breakdown($s, $m), 0, 3); if ($cats) { $o .= "\n<b>Top spending</b>\n"; foreach ($cats as $c) { $o .= '• ' . tg_h($c['name']) . ': ' . tg_m((int) $c['value']) . "\n"; } }
    $kb = [[tg_btn($back ? 'This month' : 'Last month', tg_cb($chat, $uid, 'rp', $back ? 0 : 1))]];
    if (feature_on($u, 'telegram_ai')) { $kb[0][] = tg_btn('📈 Chart', tg_cb($chat, $uid, 'ch', 's')); }
    if ($b = tg_url_btn('Open full report', abs_url('/reports'))) { $kb[] = [$b]; }
    return [rtrim($o), tg_kb($kb)];
}

// ============================ "spent 450 on groceries" ============================
const TG_INCOME_WORDS = 'got|received|earned|income|salary|credited|deposit|deposited|refund';
const TG_EXPENSE_WORDS = 'spent|spend|paid|pay|bought|buy|expense|gave|charged';
/** Pure parser. Returns ['type' => income|expense, 'amount' => minor units, 'desc' => string, 'date' => ?YYYY-MM-DD] or null. */
function tg_parse_entry(string $t, ?string $today = null): ?array {
    $today ??= today(); $t = trim(preg_replace('/\s+/u', ' ', $t) ?? ''); if ($t === '' || mb_strlen($t) > 200) { return null; }
    $type = 'expense'; $verb = false;
    if (preg_match('/^(\+)\s*/u', $t)) { $type = 'income'; $verb = true; $t = preg_replace('/^\+\s*/u', '', $t); }
    elseif (preg_match('/^(' . TG_INCOME_WORDS . ')\b\s*/iu', $t, $m)) { $type = 'income'; $verb = true; $t = mb_substr($t, mb_strlen($m[0])); }
    elseif (preg_match('/^(' . TG_EXPENSE_WORDS . ')\b\s*/iu', $t, $m)) { $verb = true; $t = mb_substr($t, mb_strlen($m[0])); }
    $date = null; if (preg_match('/\s+(yesterday|today)\s*$/iu', $t, $dm)) { $date = strtolower($dm[1]) === 'yesterday' ? day_before($today) : $today; $t = trim(mb_substr($t, 0, -mb_strlen($dm[0]))); }
    if (!preg_match('/^(?:rs\.?|inr|₹)?\s*(\d[\d,]*(?:\.\d{1,2})?)(?![\d,]|\.\d|[eE]\d)\s*(k\b)?\s*(?:rs\.?|rupees|inr|₹)?\s*(?:(?:on|for|at|to|from|towards|in)\s+)?(.*)$/iu', $t, $m)) { return null; }
    $numTxt = $m[1]; $rest = trim($m[3]); if (!$verb && $rest === '') { return null; } // a bare number is not an entry
    if ($m[2] !== '') { $numTxt = rtrim(rtrim(number_format((float) str_replace(',', '', $numTxt) * 1000, 2, '.', ''), '0'), '.'); }
    $amount = parse_money($numTxt); if ($amount === null || $amount <= 0 || $amount > 100_000_000_00) { return null; }
    $desc = trim(preg_replace('/^(?:the|a|an|my)\s+/iu', '', $rest) ?? $rest, " .,;"); $desc = mb_substr($desc, 0, 80);
    if (!$verb && (preg_match('/^[\d\W]*$/u', $desc) || mb_strlen($desc) < 2)) { return null; }
    return ['type' => $type, 'amount' => $amount, 'desc' => $desc, 'date' => $date ?? $today];
}
function tg_looks_like_entry(string $t): bool { return tg_parse_entry($t) !== null && preg_match('/^(\+|' . TG_INCOME_WORDS . '|' . TG_EXPENSE_WORDS . '|rs\.?\s*\d|₹\s*\d|\d)/iu', trim($t)) === 1 && mb_strlen($t) <= 80; }
function tg_guess_category(array $s, string $type, string $desc): ?int {
    $d = mb_strtolower($desc); if ($d === '') { return null; } $best = null; $score = 0;
    foreach ($s['categories'] as $c) {
        if (($type === 'income') !== ($c['grp'] === 'income')) { continue; }
        $name = mb_strtolower($c['name']); $sc = 0;
        if (str_contains($d, $name) || str_contains($name, $d)) { $sc = 10 + mb_strlen($name); }
        foreach (preg_split('/[^\p{L}]+/u', $name) ?: [] as $w) { if (mb_strlen($w) >= 4 && (str_contains($d, rtrim($w, 's')) )) { $sc = max($sc, mb_strlen($w)); } }
        if ($sc > $score) { $score = $sc; $best = (int) $c['id']; }
    }
    return $best;
}
function tg_default_account(array $s, string $type): ?array {
    $acc = active_accounts($s); if (!$acc) { return null; }
    foreach ($acc as $a) { if ($a['purpose'] === ($type === 'income' ? 'income' : 'spending')) { return $a; } }
    return $acc[0];
}
/** Parses a line and asks for confirmation. Returns false when the line is not an entry. */
function tg_start_entry(int $chat, array $u, array $s, string $text, bool $explain): bool {
    $uid = (int) $u['id']; $e = tg_parse_entry($text, $s['today']);
    if (!$e) { if ($explain) { tg_send($chat, "I could not read that. Write it like <b>spent 450 on groceries</b> or <b>received 5000 salary</b>.", null, [], $uid); } return false; }
    if (!tg_can_write($u)) { tg_send($chat, 'Your account is read-only until your dues are paid: ' . tg_h(abs_url('/dues')), null, [], $uid); return true; }
    $a = tg_default_account($s, $e['type']); if (!$a) { tg_send($chat, 'You need an account first. Add one in the app, then try again.', null, [], $uid); return true; }
    $cat = tg_guess_category($s, $e['type'], $e['desc']);
    $pid = tg_pending_new($uid, $chat, 'entry', $e + ['account_id' => (int) $a['id'], 'category_id' => $cat]);
    tg_confirm_entry($chat, $u, $s, $pid, null); return true;
}
function tg_confirm_text(array $d, array $s): string {
    $acct = array_column($s['accounts'], 'name', 'id')[$d['account_id']] ?? '?'; $cat = !empty($d['category_id']) ? cat_path($s, (int) $d['category_id']) : null;
    if (($d['kind'] ?? 'entry') === 'pay') { return "<b>Mark this bill paid?</b>\n" . tg_h($d['name']) . ' · ' . tg_m((int) $d['amount']) . "\nFrom " . tg_h($acct) . ' · ' . tg_h(date_short($d['date'])); }
    return '<b>Add this?</b>' . "\n" . ($d['type'] === 'income' ? 'Income ' : 'Expense ') . '<b>' . tg_m((int) $d['amount']) . '</b>' . ($d['desc'] !== '' ? ' · ' . tg_h($d['desc']) : '') . "\n" . ($cat ? 'Category: ' . tg_h($cat) . "\n" : '') . ($d['type'] === 'income' ? 'Into ' : 'From ') . tg_h($acct) . ' · ' . tg_h(date_short($d['date']));
}
function tg_confirm_entry(int $chat, array $u, array $s, int $pid, ?int $mid): void {
    $uid = (int) $u['id']; $p = tg_pending_get($pid, $uid, $chat); if (!$p) { return; } $d = $p['data'] + ['kind' => $p['kind']];
    $kb = tg_kb([[tg_btn('✅ ' . ($p['kind'] === 'pay' ? 'Yes, paid' : 'Add it'), tg_cb($chat, $uid, 'cf', $pid)), tg_btn('Cancel', tg_cb($chat, $uid, 'cx', $pid))], count(active_accounts($s)) > 1 ? [tg_btn('Change account', tg_cb($chat, $uid, 'ac', $pid))] : []]);
    $html = tg_confirm_text($d, $s); if ($mid) { tg_edit($chat, $mid, $html, $kb); } else { tg_send($chat, $html, $kb, [], $uid); }
}

// ============================ callbacks ============================
function tg_on_callback(array $q): void {
    $id = (string) ($q['id'] ?? ''); $chat = (int) ($q['message']['chat']['id'] ?? 0); $mid = (int) ($q['message']['message_id'] ?? 0) ?: null; $data = (string) ($q['data'] ?? '');
    if ($id === '') { return; }
    $link = $chat ? tg_link_by_chat($chat) : null;
    if (!$link) { tg_answer($id, 'This chat is not connected. Open the app → Settings → Connect Telegram.', true); return; }
    $uid = (int) $link['user_id'];
    $p = tg_cb_parse($chat, $uid, $data); // bound to this chat and this account
    if (!$p) { tg_log('in', $chat, $uid, 'callback', 'failed', 'bad signature'); tg_answer($id, 'That button is no longer valid. Send /menu for a fresh one.', true); return; }
    if ($lim = tg_rate_hit($chat)) { tg_log('in', $chat, $uid, 'rate', 'skipped', $lim); tg_answer($id, 'Slow down a little.', true); return; }
    [$op, $args] = $p; tg_log('in', $chat, $uid, 'cb:' . $op);
    $u = tg_user($uid); if (!$u || $u['status'] !== 'active') { tg_answer($id, 'This account is not active.', true); return; }
    $fa = feature_access($u, 'telegram'); if (!$fa['ok']) { tg_answer($id, 'The Telegram bot is not unlocked on your plan.', true); return; }
    $s = tg_boot($u);
    try { tg_dispatch_callback($id, $chat, $mid, $u, $link, $s, $op, $args); }
    catch (UserError $e) { tg_answer($id, $e->getMessage(), true); }
}
function tg_dispatch_callback(string $id, int $chat, ?int $mid, array $u, array $link, array $s, string $op, array $args): void {
    $uid = (int) $u['id']; $arg = $args[0] ?? '';
    switch ($op) {
        case 'm':
            tg_answer($id); $map = ['bal' => 'balance', 'bil' => 'bills', 'gol' => 'goals', 'rep' => 'report', 'add' => 'add', 'hlp' => 'help'];
            if (isset($map[$arg])) { tg_run_command($chat, $u, $link, $s, $map[$arg], ''); } return;
        case 'rp': tg_answer($id); [$h, $mk] = tg_view_report($s, $chat, $uid, $u, (int) $arg === 1 ? 1 : 0); tg_edit($chat, $mid, $h, $mk); return;
        case 'mp': tg_answer($id); tg_start_pay($chat, $u, $s, (int) $arg); return;
        case 'ac': {
            $p = tg_pending_get((int) $arg, $uid, $chat) ?? throw new UserError('That one expired. Start again.'); tg_answer($id); $rows = []; $row = [];
            foreach (active_accounts($s) as $a) { $row[] = tg_btn(mb_substr((string) $a['name'], 0, 24), tg_cb($chat, $uid, 'sa', (int) $arg, (int) $a['id'])); if (count($row) === 2) { $rows[] = $row; $row = []; } }
            if ($row) { $rows[] = $row; } $rows[] = [tg_btn('Cancel', tg_cb($chat, $uid, 'cx', (int) $arg))]; tg_edit($chat, $mid, 'Which account?', tg_kb($rows)); return;
        }
        case 'sa': {
            $p = tg_pending_get((int) $arg, $uid, $chat) ?? throw new UserError('That one expired. Start again.'); $acc = (int) ($args[1] ?? 0);
            if (!in_array($acc, array_map('intval', array_column(active_accounts($s), 'id')), true)) { throw new UserError('Unknown account.'); }
            tg_answer($id); tg_pending_set((int) $p['id'], ['account_id' => $acc] + $p['data']); tg_confirm_entry($chat, $u, $s, (int) $p['id'], $mid); return;
        }
        case 'cx': {
            $p = tg_pending_get((int) $arg, $uid, $chat); if ($p) { tg_pending_close((int) $p['id'], 'cancelled'); } tg_answer($id, 'Cancelled.'); tg_edit($chat, $mid, 'Cancelled. Nothing was changed.'); return;
        }
        case 'cf': tg_confirm_pending($id, $chat, $mid, $u, $s, (int) $arg); return;
        case 'ud': tg_undo_pending($id, $chat, $mid, $u, (int) $arg); return;
    }
    // everything below belongs to the AI plugin (telegram_ai.php)
    tg_ai_callback($id, $chat, $mid, $u, $link, $s, $op, $args);
}
function tg_start_pay(int $chat, array $u, array $s, int $occ): void {
    $uid = (int) $u['id']; if (!tg_can_write($u)) { tg_send($chat, 'Your account is read-only until your dues are paid: ' . tg_h(abs_url('/dues')), null, [], $uid); return; }
    $v = null; foreach (occurrence_views($s) as $x) { if ((int) $x['id'] === $occ) { $v = $x; } }
    if (!$v || $v['skipped']) { tg_send($chat, 'I could not find that bill any more.', null, [], $uid); return; }
    if ($v['outstanding'] <= 0) { tg_send($chat, tg_h($v['bill']['name']) . ' is already paid. ✅', null, [], $uid); return; }
    $a = tg_default_account($s, 'expense'); if (!$a) { tg_send($chat, 'You need an account to pay from. Add one in the app.', null, [], $uid); return; }
    $pid = tg_pending_new($uid, $chat, 'pay', ['occ' => $occ, 'name' => (string) $v['bill']['name'], 'amount' => (int) $v['outstanding'], 'account_id' => (int) $a['id'], 'date' => $s['today']]);
    tg_confirm_entry($chat, $u, $s, $pid, null);
}
/** The "yes" tap for an add-expense or mark-paid confirmation. Uses the same services (and validation) as the web forms. */
function tg_confirm_pending(string $id, int $chat, ?int $mid, array $u, array $s, int $pid): void {
    $uid = (int) $u['id']; $p = tg_pending_get($pid, $uid, $chat) ?? throw new UserError('That one expired or was already used.');
    if (!in_array($p['kind'], ['entry', 'pay'], true)) { throw new UserError('Nothing to confirm.'); }
    if (!tg_can_write($u)) { throw new UserError('Your account is read-only until your dues are paid.'); }
    if (run("UPDATE {telegram_pending} SET status='done' WHERE id=? AND status='open'", [$pid]) !== 1) { throw new UserError('Already done.'); } // a double tap cannot post twice
    $d = $p['data'];
    try {
        if ($p['kind'] === 'pay') {
            pay_occurrence($uid, (int) $d['occ'], (int) $d['amount'], $d['date'], (int) $d['account_id'], false);
            $tx = (int) val('SELECT MAX(id) FROM {transactions} WHERE user_id=? AND occurrence_id=?', [$uid, $d['occ']]); $accName = array_column($s['accounts'], 'name', 'id')[$d['account_id']] ?? '';
            notify_email($uid, 'bill.paid', ['title' => $d['name'], 'amount' => fmt_inr((int) $d['amount']), 'date' => date_label($d['date']), 'account' => $accName ? ' from ' . $accName : '', 'link' => abs_url('/bills')]);
            $res = '✅ Marked paid: <b>' . tg_h($d['name']) . '</b> · ' . tg_m((int) $d['amount']);
        } else {
            $tx = create_transaction($uid, ['type' => $d['type'], 'amount' => (int) $d['amount'], 'date' => $d['date'], 'account_id' => (int) $d['account_id'], 'category_id' => $d['category_id'] ?: null, 'description' => $d['desc'] !== '' ? $d['desc'] : 'Added from Telegram']);
            $res = '✅ Added: ' . ($d['type'] === 'income' ? 'income ' : 'expense ') . '<b>' . tg_m((int) $d['amount']) . '</b>' . ($d['desc'] !== '' ? ' · ' . tg_h($d['desc']) : '');
        }
    } catch (UserError $e) { tg_pending_close($pid, 'cancelled'); tg_answer($id, $e->getMessage(), true); tg_edit($chat, $mid, 'That did not go through: ' . tg_h($e->getMessage())); return; }
    tg_pending_set($pid, $d + ['tx' => $tx]); audit('telegram.' . $p['kind'], "tx#$tx", (string) $d['amount']); tg_log('out', $chat, $uid, 'action:' . $p['kind'], 'ok', 'tx#' . $tx);
    tg_answer($id, 'Done.'); tg_edit($chat, $mid, $res, tg_kb([[tg_btn('↩️ Undo', tg_cb($chat, $uid, 'ud', $pid))]]));
}
function tg_undo_pending(string $id, int $chat, ?int $mid, array $u, int $pid): void {
    $uid = (int) $u['id']; $p = one("SELECT * FROM {telegram_pending} WHERE id=? AND user_id=? AND chat_id=? AND status='done' AND kind IN ('entry','pay')", [$pid, $uid, $chat]) ?? throw new UserError('Nothing to undo.');
    $d = json_decode((string) $p['payload'], true) ?: []; $tx = (int) ($d['tx'] ?? 0); if (!$tx || !one('SELECT id FROM {transactions} WHERE id=? AND user_id=?', [$tx, $uid])) { throw new UserError('That entry is already gone.'); }
    if (!tg_can_write($u)) { throw new UserError('Your account is read-only until your dues are paid.'); }
    delete_transaction($uid, $tx); run("UPDATE {telegram_pending} SET status='cancelled' WHERE id=?", [$pid]); audit('telegram.undo', "tx#$tx"); tg_log('out', $chat, $uid, 'action:undo', 'ok', 'tx#' . $tx);
    tg_answer($id, 'Undone.'); tg_edit($chat, $mid, '↩️ Undone. The entry was removed.');
}

// ============================ photos ============================
function tg_on_photo(int $chat, array $u, array $link, array $m, string $caption): void {
    $uid = (int) $u['id'];
    if (!tg_ai_active($u)) {
        $up = !feature_on($u, 'telegram_ai') ? "\n\nReading receipts is part of the <b>Telegram AI plugin</b>: " . tg_h(tg_shop_url()) : '';
        tg_send($chat, "Thanks, but I cannot read photos yet." . $up, null, [], $uid); return;
    }
    $sizes = $m['photo']; usort($sizes, fn($a, $b) => ((int) ($b['file_size'] ?? 0)) <=> ((int) ($a['file_size'] ?? 0))); $pick = null;
    foreach ($sizes as $ph) { if ((int) ($ph['file_size'] ?? 0) <= 4_000_000 && !empty($ph['file_id'])) { $pick = $ph; break; } }
    if (!$pick) { tg_send($chat, 'That photo is too big for me. Send a smaller one.', null, [], $uid); return; }
    tg_ai_message($chat, $u, $link, $caption !== '' ? $caption : 'Here is a receipt. Read it and propose the expense for me to confirm.', (string) $pick['file_id']);
}

// ============================ memory ============================
function tg_forget(int $uid): int { $n = (int) val('SELECT COUNT(*) FROM {telegram_memory} WHERE user_id=?', [$uid]) + (int) val('SELECT COUNT(*) FROM {telegram_summary} WHERE user_id=?', [$uid]); run('DELETE FROM {telegram_memory} WHERE user_id=?', [$uid]); run('DELETE FROM {telegram_summary} WHERE user_id=?', [$uid]); return $n; }
