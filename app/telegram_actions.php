<?php
declare(strict_types=1);
/** POST handlers for the Telegram bot (admin and person), reminder messages ("Telegram is a notification channel") and the cron entry point. */

const TG_EVENTS = ['bill.due' => 'A bill is due soon', 'bill.overdue' => 'A bill is overdue', 'charge.new' => 'A new charge was posted', 'charge.overdue' => 'A charge is overdue', 'test' => 'Test message'];
function tg_default_template(string $event): string {
    return match ($event) {
        'bill.due' => '{title}: {amount} is due {due_date}. Tap below to mark it paid once you have paid.',
        'bill.overdue' => '{title}: {amount} was due {due_date} and is still unpaid. Late fees are the dumbest money you will spend, so sort it today.',
        'charge.new' => 'A new charge was posted: {title}, {amount}, due {due_date}. Pay it here: {link}',
        'charge.overdue' => '{title} ({amount}) is overdue since {due_date}. Pay it here: {link}',
        default => 'This is a test message from {app}. If you can read this, Telegram reminders work.',
    };
}
function tg_template(string $event): string { $t = site_setting("tg_tpl_$event", ''); return $t !== '' ? $t : tg_default_template($event); }
function tg_gen_secret_if_missing(): void { if (secret_get('tg_webhook_secret', 'TELEGRAM_WEBHOOK_SECRET') === '') { secret_set('tg_webhook_secret', tg_new_secret()); } }
function tg_health(): array { return json_decode(site_setting('tg_health', ''), true) ?: []; }
function tg_health_save(array $h): void { set_setting('tg_health', json_encode($h + ['at' => gmdate('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE)); }

// ---------------- admin ----------------
function do_admin_telegram_settings(array $u): string {
    $user = ltrim(in_str('tg_bot_username', 40, false, 'Bot username'), '@'); if ($user !== '' && !tg_username_ok($user)) { throw new UserError('A bot username is 5 to 32 letters, numbers or underscores, e.g. MomsFinanceBot.'); }
    $tok = trim((string) ($_POST['tg_bot_token'] ?? '')); if ($tok !== '' && !tg_token_ok($tok)) { throw new UserError('That does not look like a bot token. BotFather gives you something like 123456789:AAH... (digits, a colon, then letters).'); }
    $on = post_bool('tg_enabled'); $have = $tok !== '' || (secret_get('tg_bot_token', 'TELEGRAM_BOT_TOKEN') !== '' && empty($_POST['tg_bot_token_clear']));
    if ($on && (!$have || $user === '')) { throw new UserError('Enter the bot token and the bot username before switching Telegram on.'); }
    secret_from_post('tg_bot_token', 'tg_bot_token'); tg_gen_secret_if_missing();
    foreach (['tg_enabled' => $on ? '1' : '0', 'tg_bot_username' => $user, 'tg_notify' => post_bool('tg_notify') ? '1' : '0', 'tg_open_mode' => in_enum('tg_open_mode', ['url', 'webapp']), 'tg_rate_min' => (string) in_int('tg_rate_min', 1, 120, 20), 'tg_rate_day' => (string) in_int('tg_rate_day', 10, 5000, 400)] as $k => $v) { set_setting($k, $v); }
    audit('admin.telegram.settings', 'site'); return 'Telegram settings saved.' . ($on ? ' Now press Register webhook so Telegram knows where to send messages.' : '');
}
/** getMe + setWebhook (with our secret_token) + the command list. */
function do_admin_telegram_register(array $u): string {
    if (!tg_configured()) { throw new UserError('Save the bot token and username first.'); }
    if (!empty($_POST['rotate'])) { secret_set('tg_webhook_secret', tg_new_secret()); } else { tg_gen_secret_if_missing(); }
    $url = tg_webhook_url(); $c = tg_cfg();
    if (!tg_https($url) && !tg_local_base($c['base'])) { throw new UserError('Telegram only delivers to a public HTTPS address. Set APP_URL (e.g. https://finance.example.com) and try again. Current address: ' . $url); }
    try {
        $me = tg_api('getMe')['result'] ?? []; if (!empty($me['username']) && strcasecmp((string) $me['username'], $c['username']) !== 0) { set_setting('tg_bot_username', (string) $me['username']); }
        tg_api('setWebhook', ['url' => $url, 'secret_token' => tg_cfg()['secret'], 'allowed_updates' => ['message', 'callback_query'], 'max_connections' => 20, 'drop_pending_updates' => false]);
        tg_api('setMyCommands', ['commands' => [['command' => 'balance', 'description' => 'Your accounts and cash'], ['command' => 'bills', 'description' => 'What is due, mark paid'], ['command' => 'add', 'description' => 'Log an expense or income'], ['command' => 'goals', 'description' => 'Goal progress'],
            ['command' => 'report', 'description' => 'This month at a glance'], ['command' => 'menu', 'description' => 'Button menu'], ['command' => 'help', 'description' => 'What I can do'], ['command' => 'forget', 'description' => 'Clear our chat memory'], ['command' => 'stop', 'description' => 'Disconnect this chat']]]);
    } catch (Throwable $e) { tg_health_save(['ok' => false, 'error' => tg_scrub($e->getMessage())]); throw new UserError('Telegram refused: ' . tg_scrub($e->getMessage())); }
    audit('admin.telegram.register', 'site', $url); do_admin_telegram_check($u, true); return 'Webhook registered at ' . $url . ' for @' . tg_cfg()['username'] . '.';
}
function do_admin_telegram_unregister(array $u): string {
    try { tg_api('deleteWebhook', ['drop_pending_updates' => false]); } catch (Throwable $e) { throw new UserError('Telegram refused: ' . tg_scrub($e->getMessage())); }
    tg_health_save(['ok' => true, 'webhook' => '']); audit('admin.telegram.unregister', 'site'); return 'Webhook removed. The bot will not receive messages until you register it again.';
}
/** Asks Telegram who the bot is and what its webhook looks like; stores the answer for the admin page. */
function do_admin_telegram_check(array $u, bool $quiet = false): string {
    if (!tg_configured()) { throw new UserError('Save the bot token and username first.'); }
    try { $me = tg_api('getMe', [], [], 8)['result'] ?? []; $wi = tg_api('getWebhookInfo', [], [], 8)['result'] ?? []; }
    catch (Throwable $e) { tg_health_save(['ok' => false, 'error' => tg_scrub($e->getMessage())]); if ($quiet) { return ''; } throw new UserError('Could not check: ' . tg_scrub($e->getMessage())); }
    $want = tg_webhook_url(); $h = ['ok' => true, 'bot' => (string) ($me['username'] ?? ''), 'bot_name' => (string) ($me['first_name'] ?? ''), 'webhook' => (string) ($wi['url'] ?? ''), 'pending' => (int) ($wi['pending_update_count'] ?? 0),
        'last_error' => (string) ($wi['last_error_message'] ?? ''), 'last_error_at' => !empty($wi['last_error_date']) ? gmdate('Y-m-d H:i:s', (int) $wi['last_error_date']) : '', 'matches' => (string) ($wi['url'] ?? '') === $want];
    tg_health_save($h); if ($quiet) { return ''; }
    return $h['webhook'] === '' ? 'The bot is reachable but no webhook is registered. Press Register webhook.' : ($h['matches'] ? 'All good: the webhook points here' . ($h['last_error'] ? ', but Telegram reported an error: ' . $h['last_error'] : '.') : 'The webhook points somewhere else (' . $h['webhook'] . '). Press Register webhook to fix it.');
}
function do_admin_telegram_test(array $u): string {
    if (!tg_ready()) { throw new UserError('Switch Telegram on and save the settings first.'); }
    $l = tg_link_for((int) $u['id']); if (!$l) { throw new UserError('Connect your own Telegram first (Settings → Connections), then send the test to yourself.'); }
    tg_boot($u); $v = template_vars($u, ['title' => 'Test bill', 'amount' => fmt_inr(125000), 'due_date' => date_label(day_after_n(today(), 3)), 'link' => abs_url('/dues')]);
    if (!tg_send((int) $l['chat_id'], tg_h(render_template(tg_template('test'), $v)), null, [], (int) $u['id'])) { throw new UserError('Telegram did not accept the message. See the log below.'); }
    audit('admin.telegram.test'); return 'Test message sent to your Telegram.';
}
function do_admin_telegram_persona(array $u): string {
    if (!empty($_POST['reset'])) { set_setting('tg_persona', ''); return 'Persona reset to the default.'; }
    $t = trim(str_replace("\r\n", "\n", (string) ($_POST['tg_persona'] ?? ''))); if (mb_strlen($t) > 8000) { throw new UserError('The persona is too long (8,000 characters).'); }
    if ($bad = unknown_params($t, array_keys(TG_PERSONA_VARS))) { throw new UserError('Unknown parameter' . (count($bad) > 1 ? 's' : '') . ': {' . implode('}, {', $bad) . '}. Available: {' . implode('}, {', array_keys(TG_PERSONA_VARS)) . '}.'); }
    set_setting('tg_persona', $t === trim(tg_default_persona()) ? '' : $t); set_setting('tg_max_reply', (string) in_int('tg_max_reply', 120, 3000, 600)); set_setting('tg_memory_turns', (string) in_int('tg_memory_turns', 2, 40, 10));
    audit('admin.telegram.persona', 'site'); return 'Persona saved.';
}
function do_admin_telegram_templates(array $u): string {
    if (!empty($_POST['reset'])) { $ev = in_enum('reset', array_keys(TG_EVENTS)); set_setting("tg_tpl_$ev", ''); return 'Template reset to the default.'; }
    $t = $_POST['tpl'] ?? []; if (!is_array($t)) { throw new UserError('Invalid form.'); } $bad = [];
    foreach (array_keys(TG_EVENTS) as $ev) { foreach (unknown_params((string) ($t[$ev] ?? '')) as $k) { $bad[$k] = 1; } }
    if ($bad) { throw new UserError('Unknown parameter' . (count($bad) > 1 ? 's' : '') . ': {' . implode('}, {', array_keys($bad)) . '}. Available: {' . implode('}, {', array_keys(TEMPLATE_VARS)) . '}.'); }
    foreach (array_keys(TG_EVENTS) as $ev) { if (!isset($t[$ev])) { continue; } $v = trim(mb_substr(str_replace("\r\n", "\n", (string) $t[$ev]), 0, 600)); set_setting("tg_tpl_$ev", $v === tg_default_template($ev) ? '' : $v); }
    audit('admin.telegram.templates', 'site'); return 'Reminder templates saved.';
}
function do_admin_telegram_remind(array $u): string { $n = tg_send_due_reminders(); return $n ? "Sent $n reminder" . ($n === 1 ? '' : 's') . '.' : 'Nothing to send right now (no one opted in has a bill due soon, or they were already reminded).'; }

// ---------------- the person ----------------
function do_telegram_connect(array $u): string {
    if (!tg_ready()) { throw new UserError('Telegram is not set up yet. Ask the administrator.'); }
    $fa = feature_access($u, 'telegram'); if (!$fa['ok']) { throw new UserError($fa['reason']); }
    $t = tg_link_token_create((int) $u['id']); start_session(); $_SESSION['tg_link'] = ['link' => $t['link'], 'expires' => $t['expires']];
    audit('telegram.connect.start', 'user#' . $u['id']); return 'Ready. Tap “Open Telegram”, then press Start in the chat.';
}
function do_telegram_disconnect(array $u): string {
    start_session(); unset($_SESSION['tg_link']); if (!tg_unlink((int) $u['id'], true, 'app')) { return 'Telegram was not connected.'; } return 'Telegram disconnected.';
}
function do_telegram_prefs(array $u): string { run('UPDATE {telegram_links} SET notify=? WHERE user_id=?', [post_bool('notify') ? 1 : 0, $u['id']]); return 'Saved.'; }

// ---------------- notifications ----------------
/** Sends one reminder-style message (plain text from an admin-editable template) to a connected, opted-in person. $dedupe makes it once-only. */
function tg_notify(int $uid, string $event, array $vars, ?string $dedupe = null, ?array $markup = null, bool $force = false): bool {
    try {
        $c = tg_cfg(); if (!tg_ready($c) || !$c['notify']) { return false; }
        $l = tg_link_for($uid); if (!$l || (!(int) $l['notify'] && !$force)) { return false; }
        $u = tg_user($uid); if (!$u || $u['status'] !== 'active' || !feature_on($u, 'telegram')) { return false; }
        if ($dedupe !== null && run('INSERT IGNORE INTO {telegram_sent} (user_id,dedupe_key) VALUES (?,?)', [$uid, mb_substr($dedupe, 0, 80)]) !== 1) { return false; }
        tg_boot($u); $text = render_template(tg_template($event), template_vars($u, $vars));
        $mid = tg_send((int) $l['chat_id'], tg_h($text), $markup, [], $uid); if (!$mid && $dedupe !== null) { run('DELETE FROM {telegram_sent} WHERE user_id=? AND dedupe_key=?', [$uid, mb_substr($dedupe, 0, 80)]); }
        return $mid !== null;
    } catch (Throwable $e) { log_error($e); return false; }
}
/** Bill reminders for everyone who opted in: due within their reminder window (once per bill), and overdue (once per bill). Called by cron or the admin button. */
function tg_send_due_reminders(?int $onlyUid = null): int {
    if (!tg_ready() || !tg_cfg()['notify']) { return 0; } $sent = 0;
    foreach (all('SELECT l.user_id,l.chat_id FROM {telegram_links} l JOIN {users} u ON u.id=l.user_id WHERE l.notify=1 AND u.status=? ' . ($onlyUid ? 'AND l.user_id=' . (int) $onlyUid : ''), ['active']) as $l) {
        $uid = (int) $l['user_id']; $chat = (int) $l['chat_id']; $s = load_snapshot($uid); $win = max(0, (int) ($s['prefs']['reminder_days'] ?? 7)); $n = 0;
        foreach (occurrence_views($s) as $v) {
            if ($v['skipped'] || $v['outstanding'] <= 0 || $n >= 5) { continue; }
            $over = $v['overdue']; if (!$over && $v['days'] > $win) { continue; }
            $vars = ['title' => (string) $v['bill']['name'], 'amount' => fmt_inr((int) $v['outstanding']), 'due_date' => date_label($v['due_date']), 'link' => abs_url('/bills')];
            $mk = tg_kb([[tg_btn('Mark paid', tg_cb($chat, $uid, 'mp', (int) $v['id']))]]);
            if (tg_notify($uid, $over ? 'bill.overdue' : 'bill.due', $vars, 'bill:' . $v['id'] . ($over ? ':over' : ':due'), $mk)) { $sent++; $n++; }
        }
    }
    return $sent;
}
