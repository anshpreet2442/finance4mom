<?php
declare(strict_types=1);
/**
 * Telegram bot core: configuration, Bot API client (curl, no SDK), the public webhook entry point, account linking by one-time deep link,
 * signed callback data, rate limiting and logging.
 *  - The bot token and webhook secret are stored encrypted (or come from TELEGRAM_BOT_TOKEN / TELEGRAM_WEBHOOK_SECRET) and are never shown or logged.
 *  - TELEGRAM_API_BASE overrides https://api.telegram.org (used by tests/telegram_mock.php).
 *  - The basic bot lives in telegram_bot.php, the AI plugin in telegram_ai.php, charts in telegram_charts.php, admin/user actions in telegram_actions.php.
 */

const TG_LINK_TTL_MIN = 15;
const TG_MAX_BODY = 262144;

// ============================ configuration ============================
function tg_cfg(): array {
    $token = secret_get('tg_bot_token', 'TELEGRAM_BOT_TOKEN');
    return ['enabled' => site_setting('tg_enabled', '0') === '1', 'token' => $token, 'username' => ltrim(site_setting('tg_bot_username', (string) getenv('TELEGRAM_BOT_USERNAME')), '@'),
        'secret' => secret_get('tg_webhook_secret', 'TELEGRAM_WEBHOOK_SECRET'), 'base' => rtrim((string) (getenv('TELEGRAM_API_BASE') ?: 'https://api.telegram.org'), '/'),
        'rate_min' => max(1, (int) site_setting('tg_rate_min', '20')), 'rate_day' => max(1, (int) site_setting('tg_rate_day', '400')),
        'notify' => site_setting('tg_notify', '1') === '1', 'open_mode' => site_setting('tg_open_mode', 'url') === 'webapp' ? 'webapp' : 'url',
        'max_reply' => max(120, min(3000, (int) site_setting('tg_max_reply', '600'))), 'memory_turns' => max(2, min(40, (int) site_setting('tg_memory_turns', '10'))),
        'persona' => site_setting('tg_persona', '')];
}
function tg_token_ok(string $t): bool { return preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $t) === 1; }
function tg_username_ok(string $u): bool { return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $u) === 1; }
function tg_configured(?array $c = null): bool { $c ??= tg_cfg(); return tg_token_ok($c['token']) && tg_username_ok($c['username']); }
function tg_ready(?array $c = null): bool { $c ??= tg_cfg(); return $c['enabled'] && tg_configured($c); }
function tg_local_base(string $base): bool { return (bool) preg_match('~^http://(127\.0\.0\.1|localhost)(:|/|$)~', $base); }
function tg_https(string $url): bool { return str_starts_with($url, 'https://'); }
function tg_webhook_url(): string { return (APP_URL !== '' ? rtrim(APP_URL, '/') : abs_url('')) . '/webhooks/telegram'; }
/** The shop (builder-A) when it exists, otherwise the Plans page. */
function tg_shop_url(): string { return abs_url(function_exists('page_plugins') ? '/plugins' : '/plans'); }
function tg_new_secret(): string { return bin2hex(random_bytes(24)); }

// ============================ Bot API client ============================
function tg_scrub(string $s, ?string $token = null): string { $token ??= tg_cfg()['token']; return $token !== '' ? str_replace($token, '[token]', $s) : $s; }
/** One Bot API call. $files = ['photo' => ['/path/to.png', 'image/png', 'chart.png']]. Throws RuntimeException with Telegram's words (never the token). */
function tg_api(string $method, array $params = [], array $files = [], int $timeout = 15): array {
    $c = tg_cfg(); if (!tg_token_ok($c['token'])) { throw new RuntimeException('The Telegram bot token is not set.'); }
    $ch = curl_init($c['base'] . '/bot' . $c['token'] . '/' . $method); $local = tg_local_base($c['base']);
    $opts = [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => !$local, CURLOPT_SSL_VERIFYHOST => $local ? 0 : 2];
    if ($files) {
        $f = []; foreach ($params as $k => $v) { $f[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v; }
        foreach ($files as $k => [$path, $mime, $name]) { $f[$k] = new CURLFile($path, $mime, $name); }
        $opts[CURLOPT_POSTFIELDS] = $f;
    } else { $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; $opts[CURLOPT_POSTFIELDS] = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); }
    curl_setopt_array($ch, $opts); $raw = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch);
    if ($raw === false) { throw new RuntimeException(tg_scrub('Could not reach Telegram (' . $err . ').', $c['token'])); }
    $r = json_decode((string) $raw, true);
    if (!is_array($r)) { throw new RuntimeException("Telegram sent an unexpected reply (HTTP $http)."); }
    if (empty($r['ok'])) { throw new RuntimeException(tg_scrub('Telegram: ' . ($r['description'] ?? "HTTP $http") . (isset($r['error_code']) ? ' (' . $r['error_code'] . ')' : ''), $c['token'])); }
    return $r;
}
/** Downloads a file Telegram holds (photos). Returns the bytes, or null. Size capped. */
function tg_download(string $fileId, int $max = 5_000_000, bool $allowPdf = false): ?array {
    $c = tg_cfg(); try { $r = tg_api('getFile', ['file_id' => $fileId])['result'] ?? null; } catch (Throwable) { return null; } if (!is_array($r) || empty($r['file_path']) || (int) ($r['file_size'] ?? 0) > $max) { return null; }
    $ch = curl_init($c['base'] . '/file/bot' . $c['token'] . '/' . $r['file_path']); $local = tg_local_base($c['base']);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => !$local, CURLOPT_SSL_VERIFYHOST => $local ? 0 : 2]);
    $raw = curl_exec($ch); if ($raw === false || (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200 || strlen((string) $raw) > $max) { return null; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer((string) $raw); return in_array($mime, array_merge(['image/jpeg', 'image/png', 'image/webp', 'image/gif'], $allowPdf ? ['application/pdf'] : []), true) ? ['bytes' => (string) $raw, 'mime' => $mime] : null;
}
function tg_log(string $dir, ?int $chat, ?int $uid, string $kind, string $status = 'ok', ?string $detail = null): void {
    try { run('INSERT INTO {telegram_log} (direction,chat_id,user_id,kind,status,detail) VALUES (?,?,?,?,?,?)', [$dir, $chat, $uid, mb_substr($kind, 0, 40), $status, $detail === null ? null : mb_substr(tg_scrub($detail), 0, 300)]); } catch (Throwable) {}
}

// ============================ sending ============================
/** Escapes text for parse_mode=HTML (Telegram understands only &lt; &gt; &amp; &quot;). */
function tg_h($s): string { return htmlspecialchars((string) $s, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8'); }
/** Splits long HTML on line boundaries so every part stays under Telegram's 4096 limit (tags never straddle parts: callers keep <pre> blocks short). */
function tg_split(string $html, int $max = 3800): array {
    if (mb_strlen($html) <= $max) { return [$html]; }
    $parts = []; $cur = '';
    foreach (explode("\n", $html) as $line) {
        while (mb_strlen($line) > $max) { if ($cur !== '') { $parts[] = $cur; $cur = ''; } $parts[] = mb_substr($line, 0, $max); $line = mb_substr($line, $max); }
        if (mb_strlen($cur) + mb_strlen($line) + 1 > $max) { $parts[] = $cur; $cur = $line; } else { $cur .= ($cur === '' ? '' : "\n") . $line; }
    }
    if ($cur !== '') { $parts[] = $cur; } return $parts;
}
/** Sends HTML text (markup goes on the last part). Returns the last message id or null. Never throws. */
function tg_send(int $chat, string $html, ?array $markup = null, array $extra = [], ?int $uid = null): ?int {
    $parts = tg_split($html); $mid = null;
    foreach ($parts as $i => $p) {
        $body = ['chat_id' => $chat, 'text' => $p === '' ? '.' : $p, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true] + $extra;
        if ($markup && $i === count($parts) - 1) { $body['reply_markup'] = $markup; }
        try { $r = tg_api('sendMessage', $body); $mid = (int) ($r['result']['message_id'] ?? 0) ?: null; tg_log('out', $chat, $uid, 'sendMessage'); }
        catch (Throwable $e) { tg_log('out', $chat, $uid, 'sendMessage', 'failed', $e->getMessage()); return null; }
    }
    return $mid;
}
function tg_photo(int $chat, string $png, string $caption = '', ?array $markup = null, ?int $uid = null): bool {
    $tmp = tempnam(sys_get_temp_dir(), 'tgc'); if ($tmp === false) { return false; }
    try {
        file_put_contents($tmp, $png); $p = ['chat_id' => $chat, 'parse_mode' => 'HTML']; if ($caption !== '') { $p['caption'] = mb_substr($caption, 0, 1000); } if ($markup) { $p['reply_markup'] = $markup; }
        tg_api('sendPhoto', $p, ['photo' => [$tmp, 'image/png', 'chart.png']], 25); tg_log('out', $chat, $uid, 'sendPhoto'); return true;
    } catch (Throwable $e) { tg_log('out', $chat, $uid, 'sendPhoto', 'failed', $e->getMessage()); return false; }
    finally { @unlink($tmp); }
}
function tg_typing(int $chat, string $action = 'typing'): void { try { tg_api('sendChatAction', ['chat_id' => $chat, 'action' => $action], [], 5); } catch (Throwable) {} }
function tg_answer(string $cbId, string $text = '', bool $alert = false): void { try { tg_api('answerCallbackQuery', array_filter(['callback_query_id' => $cbId, 'text' => mb_substr($text, 0, 190), 'show_alert' => $alert ?: null]), [], 5); } catch (Throwable) {} }
function tg_clear_buttons(int $chat, ?int $mid): void { if (!$mid) { return; } try { tg_api('editMessageReplyMarkup', ['chat_id' => $chat, 'message_id' => $mid, 'reply_markup' => ['inline_keyboard' => []]], [], 8); } catch (Throwable) {} }
function tg_edit(int $chat, ?int $mid, string $html, ?array $markup = null): void {
    if (!$mid) { tg_send($chat, $html, $markup); return; }
    try { tg_api('editMessageText', ['chat_id' => $chat, 'message_id' => $mid, 'text' => $html, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true, 'reply_markup' => $markup ?: ['inline_keyboard' => []]], [], 8); }
    catch (Throwable) { tg_send($chat, $html, $markup); }
}
function tg_kb(array $rows): array { return ['inline_keyboard' => array_values(array_filter($rows))]; }
function tg_btn(string $text, string $cb): array { return ['text' => mb_substr($text, 0, 40), 'callback_data' => $cb]; }
function tg_url_btn(string $text, string $url): ?array {
    if (!tg_https($url)) { return null; } // Telegram refuses non-HTTPS links in buttons
    return tg_cfg()['open_mode'] === 'webapp' ? ['text' => mb_substr($text, 0, 40), 'web_app' => ['url' => $url]] : ['text' => mb_substr($text, 0, 40), 'url' => $url];
}

// ============================ signed callback data ============================
/** callback_data is "op:arg:arg:sig" (<= 64 bytes). sig = HMAC bound to the chat and the app user, so a button cannot be replayed from another chat or account. */
function tg_sign(int $chat, int $uid, string $data): string { return substr(hash_hmac('sha256', $chat . '|' . $uid . '|' . $data, secret_key() . '|tgcb'), 0, 10); }
function tg_cb(int $chat, int $uid, string $op, int|string ...$args): string {
    $d = $op . ($args ? ':' . implode(':', $args) : ''); $cb = $d . ':' . tg_sign($chat, $uid, $d);
    if (strlen($cb) > 64) { throw new LogicException('callback_data too long'); } return $cb;
}
/** Returns [op, args[]] when the signature matches this chat and user, otherwise null. */
function tg_cb_parse(int $chat, int $uid, string $cb): ?array {
    if (strlen($cb) > 64 || !preg_match('/^([a-z]{1,3})((?::[A-Za-z0-9_-]+)*):([0-9a-f]{10})$/', $cb, $m)) { return null; }
    $d = $m[1] . $m[2]; if (!hash_equals(tg_sign($chat, $uid, $d), $m[3])) { return null; }
    return [$m[1], $m[2] === '' ? [] : explode(':', substr($m[2], 1))];
}

// ============================ linking ============================
function tg_link_by_chat(int $chat): ?array { return one('SELECT * FROM {telegram_links} WHERE chat_id=?', [$chat]); }
function tg_link_for(int $uid): ?array { return one('SELECT * FROM {telegram_links} WHERE user_id=?', [$uid]); }
/** A fresh single-use, 15-minute, unguessable token for this user (older open ones are discarded). Only its hash is stored. */
function tg_link_token_create(int $uid): array {
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='); // 32 chars, 192 bits
    run('DELETE FROM {telegram_tokens} WHERE user_id=? AND used_at IS NULL', [$uid]);
    run('INSERT INTO {telegram_tokens} (token_hash,user_id,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . TG_LINK_TTL_MIN . ' MINUTE))', [hash('sha256', $token), $uid]);
    return ['token' => $token, 'link' => 'https://t.me/' . tg_cfg()['username'] . '?start=' . $token, 'expires' => time() + TG_LINK_TTL_MIN * 60];
}
/** Redeems a token from a chat. Returns ['ok' => bool, 'code' => invalid|used|expired|chat_taken|disabled|ok, 'user_id' => ?int, 'old_chat' => ?int]. */
function tg_link_consume(string $token, int $chat, array $from = []): array {
    $bad = fn(string $code) => ['ok' => false, 'code' => $code, 'user_id' => null, 'old_chat' => null];
    if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $token)) { return $bad('invalid'); }
    try {
        return tx(function () use ($token, $chat, $from, $bad) {
            $t = one('SELECT *, (expires_at < UTC_TIMESTAMP()) AS gone FROM {telegram_tokens} WHERE token_hash=? FOR UPDATE', [hash('sha256', $token)]);
            if (!$t) { return $bad('invalid'); } if ($t['used_at'] !== null) { return $bad('used'); } if ((int) $t['gone'] === 1) { return $bad('expired'); }
            $uid = (int) $t['user_id']; $u = one('SELECT status FROM {users} WHERE id=?', [$uid]); if (!$u || $u['status'] !== 'active') { return $bad('disabled'); }
            $existing = tg_link_by_chat($chat); if ($existing && (int) $existing['user_id'] !== $uid) { return $bad('chat_taken'); } // one user per chat
            if (run('UPDATE {telegram_tokens} SET used_at=UTC_TIMESTAMP() WHERE token_hash=? AND used_at IS NULL', [$t['token_hash']]) !== 1) { return $bad('used'); }
            $mine = tg_link_for($uid); $old = $mine && (int) $mine['chat_id'] !== $chat ? (int) $mine['chat_id'] : null;
            $name = mb_substr(trim((string) ($from['first_name'] ?? '')), 0, 80); $un = mb_substr((string) ($from['username'] ?? ''), 0, 64);
            if ($mine) { run('UPDATE {telegram_links} SET chat_id=?, tg_username=?, first_name=?, linked_at=UTC_TIMESTAMP(), last_seen_at=UTC_TIMESTAMP() WHERE user_id=?', [$chat, $un ?: null, $name ?: null, $uid]); }
            else { run('INSERT INTO {telegram_links} (user_id,chat_id,tg_username,first_name,last_seen_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$uid, $chat, $un ?: null, $name ?: null]); }
            run('DELETE FROM {telegram_tokens} WHERE user_id=? AND used_at IS NULL', [$uid]); run('UPDATE {telegram_pending} SET status=? WHERE user_id=? AND status=?', ['cancelled', $uid, 'open']);
            return ['ok' => true, 'code' => 'ok', 'user_id' => $uid, 'old_chat' => $old];
        });
    } catch (PDOException $e) { log_error($e); return $bad('chat_taken'); } // unique constraints are the last line of defence
}
/** Removes the link (from the app or with /stop). Tells the chat when asked. */
function tg_unlink(int $uid, bool $tellChat = true, string $why = ''): bool {
    $l = tg_link_for($uid); if (!$l) { return false; }
    run('DELETE FROM {telegram_links} WHERE user_id=?', [$uid]); run('DELETE FROM {telegram_tokens} WHERE user_id=?', [$uid]); run("UPDATE {telegram_pending} SET status='cancelled' WHERE user_id=? AND status='open'", [$uid]);
    audit('telegram.unlink', "user#$uid", $why);
    if ($tellChat && tg_ready()) { tg_send((int) $l['chat_id'], "You are disconnected from " . tg_h(APP_NAME) . ($why === 'app' ? ' (you did that in the app)' : '') . ". I will not message you any more.\n\nReconnect any time: Settings → Connections → Connect Telegram.", ['remove_keyboard' => true]); }
    return true;
}

// ============================ rate limiting ============================
/** null when allowed; otherwise 'min' or 'day'. Only accepted messages count, so being limited does not extend the limit. */
function tg_rate_hit(int $chat, string $kind = ''): ?string {
    $c = tg_cfg(); $w = $kind !== '' ? ' AND kind=' . db()->quote($kind) : '';
    $min = (int) val("SELECT COUNT(*) FROM {telegram_log} WHERE direction='in' AND status='ok' AND chat_id=? AND created_at >= ?" . $w, [$chat, gmdate('Y-m-d H:i:s', time() - 60)]);
    if ($min >= ($kind === 'ai' ? max(1, (int) (ai_cfg()['rate_min'])) : $c['rate_min'])) { return 'min'; }
    $day = (int) val("SELECT COUNT(*) FROM {telegram_log} WHERE direction='in' AND status='ok' AND chat_id=? AND created_at >= ?" . $w, [$chat, gmdate('Y-m-d H:i:s', time() - 86400)]);
    return $day >= ($kind === 'ai' ? max(1, (int) (ai_cfg()['rate_day'])) : $c['rate_day']) ? 'day' : null;
}
function tg_prune(): void {
    try {
        run('DELETE FROM {telegram_updates} WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 3 * 86400)]); run('DELETE FROM {telegram_log} WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        run('DELETE FROM {telegram_tokens} WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]); run('DELETE FROM {telegram_pending} WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]); run('DELETE FROM {telegram_sent} WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
    } catch (Throwable) {}
}

// ============================ the webhook ============================
function tg_header(array $headers, string $name): string { foreach ($headers as $k => $v) { if (strcasecmp(str_replace('_', '-', (string) $k), $name) === 0) { return (string) $v; } } return ''; }
/**
 * Validates one delivery without doing any work: [http code, body, update|null]. 403 only for a wrong/missing secret; everything else is a quiet 200
 * (a non-200 makes Telegram retry, and errors must never be described to the caller). Duplicates (same update_id) return null so they are not processed twice.
 */
function tg_webhook_accept(string $raw, array $headers): array {
    if (strlen($raw) > TG_MAX_BODY) { return [413, 'too large', null]; }
    $c = tg_cfg(); $sent = tg_header($headers, 'X-Telegram-Bot-Api-Secret-Token');
    if ($c['secret'] === '' || $sent === '' || !hash_equals($c['secret'], $sent)) { return [403, 'forbidden', null]; }
    $u = json_decode($raw, true); if (!is_array($u) || !isset($u['update_id']) || !is_int($u['update_id']) || $u['update_id'] < 0) { return [200, 'ok', null]; }
    if (!tg_ready($c)) { return [200, 'ok', null]; }
    try { if (run('INSERT IGNORE INTO {telegram_updates} (update_id) VALUES (?)', [$u['update_id']]) !== 1) { return [200, 'ok', null]; } } catch (Throwable $e) { log_error($e); return [200, 'ok', null]; }
    return [200, 'ok', $u];
}
/** Processes one accepted update. Never throws; failures are logged and the person gets a calm one-liner. */
function tg_process_update(array $upd): void {
    $chat = 0;
    try {
        if (mt_rand(1, 200) === 1) { tg_prune(); }
        if (isset($upd['callback_query']) && is_array($upd['callback_query'])) { $chat = (int) ($upd['callback_query']['message']['chat']['id'] ?? 0); tg_on_callback($upd['callback_query']); }
        elseif (isset($upd['message']) && is_array($upd['message'])) { $chat = (int) ($upd['message']['chat']['id'] ?? 0); tg_on_message($upd['message']); }
    } catch (Throwable $e) {
        log_error($e); tg_log('in', $chat ?: null, null, 'error', 'failed', get_class($e));
        if ($chat) { try { tg_send($chat, 'Sorry, something went wrong on my side. Please try again in a moment.'); } catch (Throwable) {} }
    }
}
/** Full request handler used by index.php: answers immediately, then does the work. Returns nothing (exits via the caller). */
function tg_handle_request(string $raw, array $headers): void {
    [$code, $msg, $upd] = tg_webhook_accept($raw, $headers);
    http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); header('Content-Length: ' . strlen($msg)); header('Connection: close'); echo $msg;
    if ($upd === null) { return; }
    ignore_user_abort(true); @set_time_limit(120);
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); } else { while (ob_get_level() > 0) { @ob_end_flush(); } flush(); }
    tg_process_update($upd);
}
