<?php
// Stand-in for the Telegram Bot API, used only by tests and by local development:
//   php -S 127.0.0.1:8097 tests/telegram_mock.php        then   TELEGRAM_API_BASE=http://127.0.0.1:8097
// It checks what a real Bot API checks (token, HTML entities and tags, callback_data length, https-only buttons, PNG uploads) and records every call.
//   GET  /__calls            all recorded calls (JSON)       POST /__reset         forget them
//   POST /__file?id=ID       body = raw bytes: makes getFile / file download work for that file_id
//   POST /__fail?method=sendMessage&n=1   the next n calls to that method answer 429 (to test failures)
$f = sys_get_temp_dir() . '/mfnc_telegram_mock.json';
$st = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; $st += ['calls' => [], 'mid' => 100, 'files' => [], 'fail' => [], 'webhook' => ['url' => '', 'secret' => '', 'allowed' => []]];
$save = function () use (&$st, $f) { file_put_contents($f, json_encode($st)); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'; $q = []; parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$reply = function (int $code, array $body) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($body); exit; };
$err = fn(int $code, string $d) => $reply($code, ['ok' => false, 'error_code' => $code, 'description' => $d]);
if ($path === '/__calls') { header('Content-Type: application/json'); echo json_encode($st['calls']); exit; }
if ($path === '/__reset') { $st['calls'] = []; $st['fail'] = []; $save(); $reply(200, ['ok' => true]); }
if ($path === '/__file') { $st['files'][$q['id'] ?? 'f'] = base64_encode((string) file_get_contents('php://input')); $save(); $reply(200, ['ok' => true]); }
if ($path === '/__fail') { $st['fail'][$q['method'] ?? ''] = (int) ($q['n'] ?? 1); $save(); $reply(200, ['ok' => true]); }
if (preg_match('~^/file/bot([^/]+)/photos/(.+)$~', $path, $m)) { $b = $st['files'][pathinfo($m[2], PATHINFO_FILENAME)] ?? null; if ($b === null) { http_response_code(404); exit; } header('Content-Type: image/jpeg'); echo base64_decode($b); exit; }
if (!preg_match('~^/bot([^/]+)/([A-Za-z]+)$~', $path, $m)) { $err(404, 'Not Found'); }
[$_, $token, $method] = $m;
if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $token) || (getenv('MOCK_TG_TOKEN') && $token !== getenv('MOCK_TG_TOKEN'))) { $err(401, 'Unauthorized'); }
$ct = $_SERVER['CONTENT_TYPE'] ?? ''; $p = str_contains($ct, 'application/json') ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_POST; $file = null;
foreach ($_FILES as $k => $fi) { $bytes = (string) @file_get_contents($fi['tmp_name']); $file = ['field' => $k, 'name' => $fi['name'], 'size' => strlen($bytes), 'png' => substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n", 'head' => bin2hex(substr($bytes, 0, 8))]; if (strlen($bytes) > 24 && substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") { $file['w'] = unpack('N', substr($bytes, 16, 4))[1]; $file['h'] = unpack('N', substr($bytes, 20, 4))[1]; } }
foreach (['reply_markup'] as $k) { if (isset($p[$k]) && is_string($p[$k])) { $p[$k] = json_decode($p[$k], true) ?? $p[$k]; } }
$st['calls'][] = ['method' => $method, 'params' => $p, 'file' => $file, 'at' => microtime(true)]; $call = count($st['calls']) - 1;
$fail = function (string $d, int $code = 400) use (&$st, $save, $call, $err) { $st['calls'][$call]['error'] = $d; $save(); $err($code, $d); };
if (($st['fail'][$method] ?? 0) > 0) { $st['fail'][$method]--; $fail('Too Many Requests: retry after 1', 429); }
/** HTML as Telegram parses it: only <b i u s a code pre blockquote ...> tags, balanced, and & only as &lt; &gt; &amp; &quot;. */
$html = function (string $s) use ($fail) {
    if (preg_match('/&(?!(lt|gt|amp|quot);)/', $s)) { $fail("Bad Request: can't parse entities: unsupported entity"); }
    $stack = []; $allowed = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'a', 'code', 'pre', 'blockquote', 'tg-spoiler', 'tg-emoji', 'span'];
    if (!preg_match_all('/<(\/?)([A-Za-z-]*)([^>]*)>|</', $s, $mm, PREG_SET_ORDER)) { return; }
    foreach ($mm as $t) { if ($t[0] === '<') { $fail("Bad Request: can't parse entities: unexpected '<'"); } if (!in_array(strtolower($t[2]), $allowed, true)) { $fail("Bad Request: can't parse entities: unsupported start tag \"{$t[2]}\""); }
        if ($t[1] === '/') { if (array_pop($stack) !== strtolower($t[2])) { $fail("Bad Request: can't parse entities: unmatched end tag"); } } else { $stack[] = strtolower($t[2]); } }
    if ($stack) { $fail("Bad Request: can't parse entities: unclosed tag"); }
};
$markup = function ($mk) use ($fail) {
    if ($mk === null) { return; } if (!is_array($mk)) { $fail('Bad Request: reply markup is not valid'); }
    foreach ($mk['inline_keyboard'] ?? [] as $row) { foreach ($row as $b) { if (!isset($b['text']) || $b['text'] === '') { $fail('Bad Request: button text is empty'); } if (isset($b['callback_data']) && strlen($b['callback_data']) > 64) { $fail('Bad Request: BUTTON_DATA_INVALID'); }
        if (isset($b['url']) && !preg_match('~^(https|tg)://~', $b['url'])) { $fail('Bad Request: wrong HTTP URL'); } if (isset($b['web_app']['url']) && !str_starts_with($b['web_app']['url'], 'https://')) { $fail('Bad Request: BUTTON_TYPE_INVALID'); }
        if (!isset($b['callback_data']) && !isset($b['url']) && !isset($b['web_app'])) { $fail('Bad Request: button must have a type'); } } }
};
switch ($method) {
    case 'getMe': $save(); $reply(200, ['ok' => true, 'result' => ['id' => 424242, 'is_bot' => true, 'first_name' => 'Mock Finance', 'username' => 'MockFinanceBot']]);
    case 'setWebhook': if (empty($p['url']) || !preg_match('~^https?://~', $p['url'])) { $fail('Bad Request: bad webhook: URL host is empty'); } if (isset($p['secret_token']) && !preg_match('/^[A-Za-z0-9_-]{1,256}$/', $p['secret_token'])) { $fail('Bad Request: secret token contains unallowed characters'); }
        $st['webhook'] = ['url' => $p['url'], 'secret' => $p['secret_token'] ?? '', 'allowed' => $p['allowed_updates'] ?? []]; $save(); $reply(200, ['ok' => true, 'result' => true, 'description' => 'Webhook was set']);
    case 'deleteWebhook': $st['webhook'] = ['url' => '', 'secret' => '', 'allowed' => []]; $save(); $reply(200, ['ok' => true, 'result' => true]);
    case 'getWebhookInfo': $save(); $reply(200, ['ok' => true, 'result' => ['url' => $st['webhook']['url'], 'has_custom_certificate' => false, 'pending_update_count' => 2, 'last_error_date' => 1760000000, 'last_error_message' => 'Wrong response from the webhook: 500 Internal Server Error', 'allowed_updates' => $st['webhook']['allowed']]]);
    case 'setMyCommands': $save(); $reply(200, ['ok' => true, 'result' => true]);
    case 'sendChatAction': case 'answerCallbackQuery': $save(); $reply(200, ['ok' => true, 'result' => true]);
    case 'sendMessage': case 'editMessageText':
        if (empty($p['chat_id'])) { $fail('Bad Request: chat_id is empty'); } if (!isset($p['text']) || trim((string) $p['text']) === '') { $fail('Bad Request: message text is empty'); } if (mb_strlen((string) $p['text']) > 4096) { $fail('Bad Request: message is too long'); }
        if (($p['parse_mode'] ?? '') === 'HTML') { $html((string) $p['text']); } $markup($p['reply_markup'] ?? null); $st['mid']++; $save(); $reply(200, ['ok' => true, 'result' => ['message_id' => $st['mid'], 'chat' => ['id' => (int) $p['chat_id']], 'text' => $p['text']]]);
    case 'editMessageReplyMarkup': $markup($p['reply_markup'] ?? null); $save(); $reply(200, ['ok' => true, 'result' => true]);
    case 'sendPhoto':
        if (!$file || !$file['png']) { $fail('Bad Request: wrong file identifier/HTTP URL specified'); } if (isset($p['caption'])) { if (mb_strlen((string) $p['caption']) > 1024) { $fail('Bad Request: message caption is too long'); } if (($p['parse_mode'] ?? '') === 'HTML') { $html((string) $p['caption']); } }
        $markup($p['reply_markup'] ?? null); $st['mid']++; $save(); $reply(200, ['ok' => true, 'result' => ['message_id' => $st['mid'], 'chat' => ['id' => (int) ($p['chat_id'] ?? 0)], 'photo' => [['file_id' => 'x']]]]);
    case 'getFile': $id = (string) ($p['file_id'] ?? ''); if (!isset($st['files'][$id])) { $fail('Bad Request: invalid file_id'); } $save(); $reply(200, ['ok' => true, 'result' => ['file_id' => $id, 'file_path' => 'photos/' . $id . '.jpg', 'file_size' => strlen((string) base64_decode($st['files'][$id]))]]);
}
$fail('Not Found', 404);
