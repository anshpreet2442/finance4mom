<?php
declare(strict_types=1);
/**
 * Voice (H2): ElevenLabs text-to-speech and speech-to-text behind one small wrapper, with an automatic fall back to the browser's own voice.
 *  - The browser decides nothing: voice_decide() says "elevenlabs" or "browser". "browser" is never an error; the page then uses speechSynthesis / SpeechRecognition,
 *    and hides the button when the device has neither.
 *  - Key (encrypted, or env ELEVENLABS_API_KEY), voice id and model live in Admin. MFNC_VOICE_MOCK=1 gives deterministic fake audio/text for tests and the dev server.
 *  - TTS results are cached on disk by hash with a size cap; usage is metered per character / minute (wallet for pay-as-you-go) and logged for the admin dashboard.
 *  - People without the plugin can pay a fee to ask the admin to set ElevenLabs up for them (request queue; refused requests are refunded automatically).
 *  - Helpers for other code (Telegram): voice_tts_bytes() and voice_stt_bytes() return null when ElevenLabs is not usable, so callers can just send text.
 */
const VOICE_HINT = "Using your device's voice.";
const VOICE_MAX_TTS_CHARS = 2500;
const VOICE_MAX_AUDIO_BYTES = 8000000;

// ============================ settings ============================
function voice_mock(): bool { return (string) getenv('MFNC_VOICE_MOCK') === '1'; }
function voice_cfg(): array {
    return ['enabled' => site_setting('voice_enabled', '1') === '1', 'key' => secret_get('el_key', 'ELEVENLABS_API_KEY'), 'voice_id' => preg_replace('/[^A-Za-z0-9_-]/', '', site_setting('el_voice_id', (string) (getenv('ELEVENLABS_VOICE_ID') ?: '21m00Tcm4TlvDq8ikWAM'))),
        'model' => preg_replace('/[^A-Za-z0-9_.-]/', '', site_setting('el_model', 'eleven_multilingual_v2')) ?: 'eleven_multilingual_v2', 'stt_model' => preg_replace('/[^A-Za-z0-9_.-]/', '', site_setting('el_stt_model', 'scribe_v1')) ?: 'scribe_v1',
        'base' => rtrim((string) (getenv('ELEVENLABS_BASE') ?: site_setting('el_base', 'https://api.elevenlabs.io')), '/'), 'cache_mb' => max(5, (int) site_setting('voice_cache_mb', '100')),
        'price_1k' => max(0, (int) site_setting('voice_price_1k', '5000000')), 'cost_1k' => max(0, (int) site_setting('voice_cost_1k', '15000000')), 'stt_price_min' => max(0, (int) site_setting('voice_stt_price_min', '3000000')), 'stt_cost_min' => max(0, (int) site_setting('voice_stt_cost_min', '2500000')),
        'daily_chars' => max(0, (int) site_setting('voice_daily_chars', '20000')), 'daily_stt_sec' => max(0, (int) site_setting('voice_daily_stt_sec', '600')), 'setup_fee' => max(0, (int) site_setting('voice_setup_fee', '49900')), 'setup_days' => max(1, (int) site_setting('voice_setup_days', '30'))];
}
function voice_el_ready(?array $c = null): bool { $c ??= voice_cfg(); return $c['key'] !== '' || voice_mock(); }
/** Which engine will this person get? ['engine' => 'elevenlabs'|'browser', 'why' => code, 'hint' => string]. Never an error: "browser" just means the device voice is used. */
function voice_decide(?array $u, string $kind = 'tts'): array {
    $browser = fn(string $why) => ['engine' => 'browser', 'why' => $why, 'hint' => VOICE_HINT]; if (!$u) { return $browser('auth'); } $c = voice_cfg();
    if (!$c['enabled']) { return $browser('off'); } if (!voice_el_ready($c)) { return $browser('not_configured'); }
    $fa = feature_access($u, 'voice'); if (!$fa['ok']) { return $browser('not_owned'); }
    if (!is_exempt_user($u)) { $d = voice_usage_today((int) $u['id']); if ($kind === 'tts' && $c['daily_chars'] > 0 && $d['chars'] >= $c['daily_chars']) { return $browser('limit'); } if ($kind === 'stt' && $c['daily_stt_sec'] > 0 && $d['seconds'] >= $c['daily_stt_sec']) { return $browser('limit'); }
        if (feature_cfg('voice')['mode'] === 'payg' && wallet_balance((int) $u['id']) < max(1, $kind === 'stt' ? $c['stt_price_min'] / 6 : $c['price_1k'] / 20)) { return $browser('wallet'); } }
    return ['engine' => 'elevenlabs', 'why' => '', 'hint' => ''];
}
/** What the page needs to know to show its buttons (cached by the browser for a few minutes). */
function voice_status(?array $u): array { $t = voice_decide($u, 'tts'); $s = voice_decide($u, 'stt'); return ['tts' => $t['engine'], 'stt' => $s['engine'], 'hint' => $t['engine'] === 'browser' ? VOICE_HINT : '', 'max_chars' => VOICE_MAX_TTS_CHARS]; }

// ============================ text + cache ============================
/** Plain speakable text: markdown, links and symbols removed, whitespace tidied, length capped at a sentence. */
function voice_clean_text(string $t, int $max = VOICE_MAX_TTS_CHARS): string {
    $t = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/', ' ', $t) ?? $t; $t = preg_replace('/```.*?```/s', ' ', $t) ?? $t; $t = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/', '$1', $t) ?? $t; $t = preg_replace('~https?://\S+~', ' link ', $t) ?? $t; $t = preg_replace('/(\*+|_{2,}|`+)/', '', $t) ?? $t; $t = preg_replace('/[#>|]+/', ' ', $t) ?? $t;
    $t = trim(preg_replace('/\s+/u', ' ', $t) ?? ''); if (mb_strlen($t) > $max) { $cut = mb_substr($t, 0, $max); $p = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '? '), (int) mb_strrpos($cut, '! ')); $t = $p > $max * 0.5 ? mb_substr($cut, 0, $p + 1) : $cut; } return $t;
}
function voice_cache_dir(): string { $d = rtrim((string) (getenv('MFNC_VOICE_CACHE') ?: sys_get_temp_dir()), '/') . '/mfnc-voice'; if (!is_dir($d)) { @mkdir($d, 0700, true); } return $d; }
function voice_hash(string $text, string $format = 'mp3'): string { $c = voice_cfg(); return c_hmac(implode('|', [$c['voice_id'], $c['model'], $format, voice_mock() ? 'mock' : 'live', $text])); /* keyed: another signed-in person cannot probe the cache for a known sentence */ }
function voice_cache_path(string $h, string $format = 'mp3'): string { return voice_cache_dir() . '/' . $h . '.' . ($format === 'opus' ? 'opus' : 'mp3'); }
function voice_cache_put(string $h, string $bytes, string $format = 'mp3'): void { @file_put_contents(voice_cache_path($h, $format), $bytes, LOCK_EX); voice_cache_trim(); }
/** Keeps the cache under its size cap by deleting the least recently used files first (down to 80% of the cap). */
function voice_cache_trim(?int $capBytes = null): int {
    $cap = $capBytes ?? voice_cfg()['cache_mb'] * 1048576; $files = []; $total = 0; foreach (glob(voice_cache_dir() . '/*.{mp3,opus}', GLOB_BRACE) ?: [] as $f) { $s = (int) @filesize($f); $files[$f] = [max((int) @filemtime($f), (int) @fileatime($f)), $s]; $total += $s; }
    if ($total <= $cap) { return 0; } uasort($files, fn($a, $b) => $a[0] <=> $b[0]); $deleted = 0; foreach ($files as $f => [$t, $s]) { if ($total <= $cap * 0.8) { break; } if (@unlink($f)) { $total -= $s; $deleted++; } } return $deleted;
}

// ============================ ElevenLabs API ============================
function voice_mock_mp3(string $text): string { $frame = "\xFF\xFB\x90\x64" . str_repeat("\x00", 413); return "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat($frame, 8 + min(40, intdiv(strlen($text), 40))); }
/** Raw call. Returns [httpCode, body]. Never throws; network trouble is code 0. */
function voice_el_http(string $method, string $url, array $headers, $body, int $timeout = 40): array {
    $ch = curl_init($url); $local = (bool) preg_match('~^http://(127\.0\.0\.1|localhost)~', $url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => !$local, CURLOPT_SSL_VERIFYHOST => $local ? 0 : 2]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); } $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); if ($raw === false) { log_error(new RuntimeException('elevenlabs: ' . curl_error($ch))); return [0, '']; } return [$code, (string) $raw];
}
/** Text to audio bytes, or null (the caller falls back to the browser voice). */
function voice_el_tts(string $text, string $format = 'mp3'): ?string {
    $c = voice_cfg(); if (voice_mock()) { return voice_mock_mp3($text); } if ($c['key'] === '' || $c['voice_id'] === '') { return null; }
    $fmt = $format === 'opus' ? 'opus_48000_64' : 'mp3_44100_64';
    [$code, $body] = voice_el_http('POST', $c['base'] . '/v1/text-to-speech/' . rawurlencode($c['voice_id']) . '?output_format=' . $fmt, ['xi-api-key: ' . $c['key'], 'Content-Type: application/json', 'Accept: audio/mpeg'], json_encode(['text' => $text, 'model_id' => $c['model']], JSON_UNESCAPED_UNICODE));
    if ($code !== 200 || strlen($body) < 100) { log_error(new RuntimeException('elevenlabs tts HTTP ' . $code)); return null; } return $body;
}
/** Audio bytes to text, or null. */
function voice_el_stt(string $audio, string $mime = 'audio/webm'): ?string {
    $c = voice_cfg(); if (voice_mock()) { return 'Mock transcription of ' . strlen($audio) . ' bytes'; } if ($c['key'] === '') { return null; }
    $tmp = tempnam(sys_get_temp_dir(), 'stt'); file_put_contents($tmp, $audio); $ext = str_contains($mime, 'ogg') ? 'ogg' : (str_contains($mime, 'mp4') || str_contains($mime, 'aac') ? 'm4a' : (str_contains($mime, 'wav') ? 'wav' : (str_contains($mime, 'mpeg') ? 'mp3' : 'webm')));
    try { [$code, $body] = voice_el_http('POST', $c['base'] . '/v1/speech-to-text', ['xi-api-key: ' . $c['key']], ['model_id' => $c['stt_model'], 'file' => new CURLFile($tmp, $mime, 'speech.' . $ext)], 60); } finally { @unlink($tmp); }
    $j = json_decode($body, true); if ($code !== 200 || !is_array($j) || !isset($j['text'])) { log_error(new RuntimeException('elevenlabs stt HTTP ' . $code)); return null; } return trim((string) $j['text']);
}

// ============================ usage + metering ============================
function voice_usage_today(int $uid): array { $r = one('SELECT COALESCE(SUM(chars),0) c, COALESCE(SUM(seconds),0) s FROM {c_voice_usage} WHERE user_id=? AND provider=\'elevenlabs\' AND created_at >= ?', [$uid, gmdate('Y-m-d 00:00:00')]); return ['chars' => (int) $r['c'], 'seconds' => (int) $r['s']]; }
function voice_usage_record(int $uid, string $kind, int $chars, int $seconds, int $costMicro, string $provider, string $source): void { run('INSERT INTO {c_voice_usage} (user_id,kind,chars,seconds,cost_micro,provider,source) VALUES (?,?,?,?,?,?,?)', [$uid, $kind, $chars, $seconds, $costMicro, $provider, mb_substr($source, 0, 16)]); }
/** Charges a pay-as-you-go person for what they used. Returns the micro-rupees charged (0 for free/paid plans and admins). */
function voice_charge(array $u, int $micro, string $note): int {
    if ($micro <= 0 || is_exempt_user($u) || feature_cfg('voice')['mode'] !== 'payg') { return 0; } wallet_add((int) $u['id'], -$micro, 'usage', $note); return $micro;
}
function voice_tts_price(int $chars): int { return (int) ceil($chars / 1000 * voice_cfg()['price_1k']); }

/**
 * Speak text. Returns ['engine' => 'elevenlabs', 'url', 'hash', 'chars', 'cached'] or ['engine' => 'browser', 'text', 'hint'].
 * Anything that goes wrong (no key, not owned, limit, network, wallet) becomes the browser answer, never an error.
 */
function voice_tts(array $u, string $text, string $source = 'web'): array {
    $clean = voice_clean_text($text); $fallback = fn(string $why = '') => ['engine' => 'browser', 'text' => $clean, 'hint' => VOICE_HINT, 'why' => $why]; if ($clean === '') { return $fallback('empty'); }
    $d = voice_decide($u, 'tts'); if ($d['engine'] !== 'elevenlabs') { return $fallback($d['why']); } $uid = (int) $u['id']; $c = voice_cfg(); $chars = mb_strlen($clean);
    if (!is_exempt_user($u) && feature_cfg('voice')['mode'] === 'payg' && wallet_balance($uid) < voice_tts_price($chars)) { return $fallback('wallet'); }
    $h = voice_hash($clean); $p = voice_cache_path($h); $cached = is_file($p);
    if (!$cached) { $bytes = voice_el_tts($clean); if ($bytes === null) { return $fallback('provider'); } voice_cache_put($h, $bytes); } else { @touch($p); }
    $paid = voice_charge($u, voice_tts_price($chars), 'Voice: ' . $chars . ' characters'); voice_usage_record($uid, 'tts', $chars, 0, $cached ? 0 : (int) ceil($chars / 1000 * $c['cost_1k']), 'elevenlabs', $source);
    return ['engine' => 'elevenlabs', 'url' => url('/voice/audio', ['h' => $h]), 'hash' => $h, 'chars' => $chars, 'cached' => $cached, 'charged_micro' => $paid];
}
/** Speech to text. ['engine' => 'elevenlabs', 'text'] or ['engine' => 'browser'] (use the device's recogniser instead). */
function voice_stt(array $u, string $audio, string $mime = 'audio/webm', int $seconds = 0, string $source = 'web'): array {
    $d = voice_decide($u, 'stt'); if ($d['engine'] !== 'elevenlabs' || $audio === '' || strlen($audio) > VOICE_MAX_AUDIO_BYTES) { return ['engine' => 'browser', 'hint' => VOICE_HINT, 'why' => $d['why'] ?: 'audio']; }
    $seconds = $seconds > 0 ? min($seconds, 300) : max(1, (int) ceil(strlen($audio) / 6000)); $c = voice_cfg(); $text = voice_el_stt($audio, $mime); if ($text === null) { return ['engine' => 'browser', 'hint' => VOICE_HINT, 'why' => 'provider']; }
    voice_charge($u, (int) ceil($seconds / 60 * $c['stt_price_min']), 'Voice typing: ' . $seconds . ' s'); voice_usage_record((int) $u['id'], 'stt', mb_strlen($text), $seconds, (int) ceil($seconds / 60 * $c['stt_cost_min']), 'elevenlabs', $source);
    return ['engine' => 'elevenlabs', 'text' => $text, 'seconds' => $seconds];
}
/** For Telegram and other server code: audio bytes for a text, or null when ElevenLabs is not usable for this person (send text instead). $format: mp3 | opus (Telegram voice notes). */
function voice_tts_bytes(array $u, string $text, string $format = 'mp3'): ?array {
    $r = voice_tts_raw($u, $text, $format, 'telegram'); return $r;
}
function voice_tts_raw(array $u, string $text, string $format, string $source): ?array {
    $clean = voice_clean_text($text, 900); if ($clean === '' || voice_decide($u, 'tts')['engine'] !== 'elevenlabs') { return null; } $chars = mb_strlen($clean); $c = voice_cfg();
    if (!is_exempt_user($u) && feature_cfg('voice')['mode'] === 'payg' && wallet_balance((int) $u['id']) < voice_tts_price($chars)) { return null; }
    $h = voice_hash($clean, $format); $p = voice_cache_path($h, $format); $cached = is_file($p); $bytes = $cached ? (string) file_get_contents($p) : voice_el_tts($clean, $format); if ($bytes === null || $bytes === '') { return null; } if (!$cached) { voice_cache_put($h, $bytes, $format); }
    voice_charge($u, voice_tts_price($chars), 'Voice: ' . $chars . ' characters'); voice_usage_record((int) $u['id'], 'tts', $chars, 0, $cached ? 0 : (int) ceil($chars / 1000 * $c['cost_1k']), 'elevenlabs', $source);
    return ['mime' => $format === 'opus' ? 'audio/ogg' : 'audio/mpeg', 'data' => $bytes, 'chars' => $chars];
}
function voice_stt_bytes(array $u, string $audio, string $mime = 'audio/ogg', int $seconds = 0): ?string { $r = voice_stt($u, $audio, $mime, $seconds, 'telegram'); return $r['engine'] === 'elevenlabs' ? (string) $r['text'] : null; }

// ============================ HTTP endpoints ============================
function voice_http_status(): void { $u = current_user(); c_json(['ok' => true] + voice_status($u)); }
function voice_http_tts(): void {
    [$u, $b] = c_json_guard(); if (!c_rate_ok('vtts:' . $u['id'], 40, 60)) { c_json(['ok' => true, 'engine' => 'browser', 'text' => voice_clean_text((string) ($b['text'] ?? '')), 'hint' => VOICE_HINT, 'why' => 'rate']); }
    $r = voice_tts($u, (string) ($b['text'] ?? ''), in_array($b['source'] ?? '', ['chat', 'report', 'test', 'web'], true) ? (string) $b['source'] : 'web'); c_json(['ok' => true] + $r);
}
function voice_http_stt(): void {
    $u = current_user(); if (!$u) { c_json(['ok' => false, 'message' => 'Sign in again.'], 401); } start_session(); $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''); if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) { c_json(['ok' => false, 'message' => 'Reload the page.'], 403); } c_session_close();
    if (!c_rate_ok('vstt:' . $u['id'], 20, 60)) { c_json(['ok' => true, 'engine' => 'browser', 'hint' => VOICE_HINT, 'why' => 'rate']); }
    $audio = (string) file_get_contents('php://input', false, null, 0, VOICE_MAX_AUDIO_BYTES + 1); $mime = preg_replace('/[^a-z0-9\/;=+.-]/i', '', (string) ($_SERVER['CONTENT_TYPE'] ?? 'audio/webm')) ?: 'audio/webm'; $mime = explode(';', $mime)[0];
    c_json(['ok' => true] + voice_stt($u, $audio, $mime, (int) ($_SERVER['HTTP_X_AUDIO_SECONDS'] ?? 0)));
}
/** Streams a cached audio file with Range support (Safari needs it). Only signed-in people; the file name is an unguessable hash. */
function voice_http_audio(): void {
    require_login(); $h = (string) ($_GET['h'] ?? ''); if (!preg_match('/^[a-f0-9]{64}$/', $h)) { c_status(404); return; } $f = voice_cache_path($h); $o = false; if (!is_file($f)) { $f = voice_cache_path($h, 'opus'); $o = true; if (!is_file($f)) { c_status(404); echo 'Not found'; return; } }
    $size = (int) filesize($f); $start = 0; $end = $size - 1; c_hdr('Content-Type: ' . ($o ? 'audio/ogg' : 'audio/mpeg')); c_hdr('Accept-Ranges: bytes'); c_hdr('Cache-Control: private, max-age=86400'); c_hdr('X-Content-Type-Options: nosniff');
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) { if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); } else { $start = (int) $m[1]; if ($m[2] !== '') { $end = min($end, (int) $m[2]); } } if ($start > $end || $start >= $size) { c_status(416); c_hdr("Content-Range: bytes */$size"); return; } c_status(206); c_hdr("Content-Range: bytes $start-$end/$size"); }
    c_hdr('Content-Length: ' . ($end - $start + 1)); $fp = fopen($f, 'rb'); fseek($fp, $start); $left = $end - $start + 1; while ($left > 0 && !feof($fp)) { $chunk = fread($fp, min(65536, $left)); echo $chunk; $left -= strlen((string) $chunk); } fclose($fp);
}
/** Read-aloud button for a page region. $target = CSS selector of what to read. */
function voice_read_button(string $target, string $label = 'Read aloud'): string {
    return '<button type="button" class="btn btn-sm" data-voice-read="' . e($target) . '" hidden aria-label="' . e($label) . '">' . icon('volume-2', 'size-4') . '<span>' . e($label) . '</span></button>';
}

// ============================ setup requests ============================
function voice_request_open(int $uid): ?array { return one("SELECT * FROM {c_voice_requests} WHERE user_id=? AND status IN ('new','in_progress') ORDER BY id DESC LIMIT 1", [$uid]); }
function voice_request_last(int $uid): ?array { return one('SELECT * FROM {c_voice_requests} WHERE user_id=? ORDER BY id DESC LIMIT 1', [$uid]); }
/** Pays the fee from the wallet and files a request. One open request per person. Throws ShopShortfall when the wallet is short. */
function voice_request_create(int $uid, string $note = ''): int {
    $fee = voice_cfg()['setup_fee']; $micro = $fee * 10000; $note = mb_substr(trim($note), 0, 500);
    return tx(function () use ($uid, $micro, $note) {
        if (!val('SELECT id FROM {users} WHERE id=? FOR UPDATE', [$uid])) { throw new UserError('Account not found.'); } if (voice_request_open($uid)) { throw new UserError('You already have a request in progress. We will let you know when it is done.'); }
        $bal = wallet_balance($uid); if ($micro > 0 && $bal < $micro) { throw new ShopShortfall('Your wallet has ' . micro_to_inr($bal) . ' and this costs ' . micro_to_inr($micro) . '. Top up first on the Plans page.', $micro - $bal); }
        $id = insert('INSERT INTO {c_voice_requests} (user_id,fee_micro,note,ref) VALUES (?,?,?,?)', [$uid, $micro, $note, 'tmp' . bin2hex(random_bytes(8))]); $ref = 'voicereq:' . $id; run('UPDATE {c_voice_requests} SET ref=? WHERE id=?', [$ref, $id]);
        if ($micro > 0) { wallet_add($uid, -$micro, 'purchase', 'ElevenLabs setup request', $ref); shop_record($uid, 'voice_setup', 'ElevenLabs setup request', $micro, null, 'wallet', $ref, null); } return $id;
    });
}
/** Admin moves a request along. done: grants the Voice plugin for the configured days. refused: refunds the fee to the wallet once. */
function voice_request_set(int $id, string $status, string $adminNote = '', ?int $adminId = null): void {
    if (!in_array($status, ['in_progress', 'done', 'refused'], true)) { throw new UserError('Unknown status.'); }
    tx(function () use ($id, $status, $adminNote) {
        $r = one('SELECT * FROM {c_voice_requests} WHERE id=? FOR UPDATE', [$id]); if (!$r) { throw new UserError('Request not found.'); } if (in_array($r['status'], ['done', 'refused'], true)) { throw new UserError('This request is already closed.'); }
        if ($status === 'done' && !voice_el_ready()) { throw new UserError('Add your ElevenLabs API key under Voice settings first, then mark the request done.'); }
        run('UPDATE {c_voice_requests} SET status=?, admin_note=? WHERE id=?', [$status, mb_substr($adminNote, 0, 500), $id]); $uid = (int) $r['user_id'];
        if ($status === 'done') { grant_feature($uid, 'voice', voice_cfg()['setup_days'], 'request'); }
        if ($status === 'refused' && !(int) $r['refunded'] && (int) $r['fee_micro'] > 0) { wallet_add($uid, (int) $r['fee_micro'], 'adjust', 'Refund: ElevenLabs setup request', 'voicereq-refund:' . $id); run('UPDATE {c_voice_requests} SET refunded=1 WHERE id=?', [$id]); }
    });
    audit('admin.voice.request', (string) $id, $status);
}
function voice_status_badge(string $s): string { return badge(['new' => 'New', 'in_progress' => 'In progress', 'done' => 'Done', 'refused' => 'Refused'][$s] ?? $s, ['new' => 'warn', 'in_progress' => 'dark', 'done' => 'pos', 'refused' => 'neg'][$s] ?? 'neutral'); }
/** Extra block on a plugin card in the shop (called from plugins.php). */
function c_plugin_extra(array $it, array $u): string {
    $open = ['ai_layout' => ['/layout', 'Customize home'], 'share_links' => ['/share', 'Open share links'], 'pdf_export' => ['/reports', 'Open Reports'], 'voice' => ['/ai', 'Try in the AI chat']][$it['key']] ?? null;
    if ($open && in_array($it['state'], ['owned', 'free', 'payg'], true)) { $link = '<div class="mt-3"><a class="btn btn-sm" href="' . e(url($open[0])) . '">' . e($open[1]) . '</a></div>'; if ($it['key'] !== 'voice') { return $link; } if ($it['state'] !== 'free') { return $link; } }
    if ($it['key'] !== 'voice' || !empty($it['admin_included']) || $it['state'] === 'owned') { return ''; } $fee = voice_cfg()['setup_fee']; $last = voice_request_last((int) $u['id']); $o = '<div class="mt-3 border-t border-line pt-3 text-sm">';
    if ($last && in_array($last['status'], ['new', 'in_progress'], true)) { return $o . '<p class="flex flex-wrap items-center gap-2">' . voice_status_badge($last['status']) . '<span class="text-mute">Your ElevenLabs setup request is with the team.</span></p></div>'; }
    if ($last && $last['status'] === 'refused') { $o .= '<p class="mb-2 text-xs text-mute">' . voice_status_badge('refused') . ' Your last request was refused and the fee was refunded.' . ($last['admin_note'] ? ' ' . e($last['admin_note']) : '') . '</p>'; }
    return $o . '<p class="mb-2 text-xs text-mute">No ElevenLabs account? We can set it up for you. ' . ($fee > 0 ? 'One-time fee ' . e(fmt_inr($fee)) . ' from your wallet, refunded if we cannot.' : 'Free.') . '</p>'
        . '<form method="post" action="' . e(url('/do/voice-request')) . '" class="space-y-2">' . csrf_field() . '<input type="hidden" name="_back" value="' . e(url('/plugins')) . '"><label class="label" for="vr-note">Anything we should know? (optional)</label><input class="input" id="vr-note" name="note" maxlength="300" autocomplete="off">'
        . '<button class="btn btn-sm">Request ElevenLabs setup' . ($fee > 0 ? ' · ' . e(fmt_inr($fee)) : '') . '</button></form></div>';
}

// ============================ admin ============================
function voice_stats(): array {
    $m = gmdate('Y-m-01 00:00:00'); $tot = one("SELECT COALESCE(SUM(chars),0) chars, COALESCE(SUM(seconds),0) secs, COALESCE(SUM(cost_micro),0) cost, COUNT(*) calls FROM {c_voice_usage} WHERE provider='elevenlabs' AND created_at >= ?", [$m]) ?? [];
    $tts = (int) val("SELECT COALESCE(SUM(chars),0) FROM {c_voice_usage} WHERE provider='elevenlabs' AND kind='tts' AND created_at >= ?", [$m]);
    $days = all("SELECT DATE(created_at) d, SUM(CASE WHEN kind='tts' THEN chars ELSE 0 END) chars, SUM(seconds) secs, SUM(cost_micro) cost FROM {c_voice_usage} WHERE provider='elevenlabs' AND created_at >= ? GROUP BY DATE(created_at) ORDER BY d", [gmdate('Y-m-d 00:00:00', time() - 13 * 86400)]);
    $top = all("SELECT u.name, u.email, SUM(CASE WHEN v.kind='tts' THEN v.chars ELSE 0 END) chars, SUM(v.seconds) secs, SUM(v.cost_micro) cost FROM {c_voice_usage} v JOIN {users} u ON u.id=v.user_id WHERE v.provider='elevenlabs' AND v.created_at >= ? GROUP BY v.user_id ORDER BY cost DESC LIMIT 10", [$m]);
    $browser = (int) val("SELECT COUNT(*) FROM {c_voice_usage} WHERE provider='browser' AND created_at >= ?", [$m]);
    return ['chars' => $tts, 'seconds' => (int) ($tot['secs'] ?? 0), 'cost_micro' => (int) ($tot['cost'] ?? 0), 'calls' => (int) ($tot['calls'] ?? 0), 'days' => $days, 'top' => $top, 'browser' => $browser, 'revenue_micro' => (int) val("SELECT COALESCE(-SUM(amount_micro),0) FROM {wallet_ledger} WHERE kind='usage' AND note LIKE 'Voice%' AND created_at >= ?", [$m]),
        'setup_micro' => (int) val("SELECT COALESCE(SUM(fee_micro),0) FROM {c_voice_requests} WHERE status IN ('new','in_progress','done') AND created_at >= ?", [$m])];
}
function page_admin_voice(): void {
    $u = require_admin(); admin_start('Voice & PDF', 'voice'); $c = voice_cfg(); $st = voice_stats(); $reqs = all('SELECT r.*, u.name, u.email FROM {c_voice_requests} r JOIN {users} u ON u.id=r.user_id ORDER BY FIELD(r.status,\'new\',\'in_progress\',\'done\',\'refused\'), r.id DESC LIMIT 60');
    $ready = voice_el_ready($c); $mode = feature_cfg('voice')['mode']; echo '<link rel="stylesheet" href="' . e(asset('c.css')) . '">';
    echo '<div class="grid gap-5 lg:grid-cols-2">'; card_open('ElevenLabs voice', ($ready ? badge(voice_mock() ? 'Test mode' : 'Key saved', 'pos') : badge('Not configured: people hear their device voice', 'warn')) . ' <span class="text-mute">Plugin mode: ' . e($mode) . ' (change in Plugins &amp; models)</span>');
    echo simple_form('admin-voice-save', [
        ['name' => 'voice_enabled', 'label' => 'Voice features on (read aloud, voice typing, spoken replies)', 'type' => 'checkbox', 'value' => $c['enabled']],
        secret_field('el_key', 'ElevenLabs API key', $c['key'], 'Stored encrypted. Or set ELEVENLABS_API_KEY in the environment.'), ['name' => 'el_key_clear', 'label' => 'Remove the saved key', 'type' => 'checkbox', 'value' => false],
        ['name' => 'el_voice_id', 'label' => 'Default voice ID', 'half' => true, 'value' => $c['voice_id'], 'hint' => 'From your ElevenLabs voice library.'], ['name' => 'el_model', 'label' => 'Speech model', 'half' => true, 'value' => $c['model']],
        ['name' => 'el_stt_model', 'label' => 'Transcription model', 'half' => true, 'value' => $c['stt_model']], ['name' => 'voice_cache_mb', 'label' => 'Audio cache limit (MB)', 'type' => 'number', 'min' => 5, 'max' => 5000, 'half' => true, 'value' => $c['cache_mb']],
        ['name' => 'voice_price_1k', 'label' => 'Price per 1,000 characters (₹, pay as you go)', 'half' => true, 'value' => money_input((int) ($c['price_1k'] / 10000))], ['name' => 'voice_cost_1k', 'label' => 'Your cost per 1,000 characters (₹, for the dashboard)', 'half' => true, 'value' => money_input((int) ($c['cost_1k'] / 10000))],
        ['name' => 'voice_stt_price_min', 'label' => 'Price per minute of voice typing (₹)', 'half' => true, 'value' => money_input((int) ($c['stt_price_min'] / 10000))], ['name' => 'voice_stt_cost_min', 'label' => 'Your cost per minute (₹)', 'half' => true, 'value' => money_input((int) ($c['stt_cost_min'] / 10000))],
        ['name' => 'voice_daily_chars', 'label' => 'Daily limit per person: characters (0 = none)', 'type' => 'number', 'min' => 0, 'max' => 1000000, 'half' => true, 'value' => $c['daily_chars']], ['name' => 'voice_daily_stt_sec', 'label' => 'Daily limit per person: voice typing seconds', 'type' => 'number', 'min' => 0, 'max' => 86400, 'half' => true, 'value' => $c['daily_stt_sec']],
        ['name' => 'voice_setup_fee', 'label' => 'Fee to request an ElevenLabs setup (₹, 0 = free)', 'half' => true, 'value' => money_input($c['setup_fee'])], ['name' => 'voice_setup_days', 'label' => 'Days of Voice granted when a request is done', 'type' => 'number', 'min' => 1, 'max' => 366, 'half' => true, 'value' => $c['setup_days']],
    ], 'Save voice settings'); card_close();
    echo '<div class="space-y-5">'; card_open('Try it', 'Plays a short sentence with the saved settings.'); echo '<div class="flex flex-wrap items-center gap-2"><button type="button" class="btn btn-dark btn-sm" data-voice-test>' . icon('volume-2', 'size-4') . 'Play test sentence</button><span class="text-xs text-mute" data-voice-test-out></span></div>'; card_close();
    card_open('PDF reports engine', 'FPDF is built in. ReportLab is used only when python3 and reportlab are installed on this server.'); $rl = pdf_reportlab_available();
    echo '<p class="mb-3 text-sm">ReportLab on this server: ' . ($rl ? badge('Detected', 'pos') : badge('Not found', 'neutral')) . '</p>' . simple_form('admin-pdf-settings', [['name' => 'pdf_engine', 'label' => 'Engine', 'type' => 'select', 'required' => true, 'options' => ['fpdf' => 'FPDF (default)', 'reportlab' => 'ReportLab (python3)'], 'value' => site_setting('pdf_engine', 'fpdf')]], 'Save engine', 'btn btn-dark'); card_close(); echo '</div></div>';
    echo '<div class="mt-5">'; card_open('Usage this month', 'Only ElevenLabs calls are counted here; device voices cost nothing.');
    echo '<dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4"><div class="rounded-2xl bg-soft p-3"><dt class="text-xs text-mute">Characters spoken</dt><dd class="num text-lg font-semibold">' . number_format($st['chars']) . '</dd></div><div class="rounded-2xl bg-soft p-3"><dt class="text-xs text-mute">Voice typing</dt><dd class="num text-lg font-semibold">' . round($st['seconds'] / 60, 1) . ' min</dd></div>'
        . '<div class="rounded-2xl bg-soft p-3"><dt class="text-xs text-mute">Your estimated cost</dt><dd class="num text-lg font-semibold">' . e(micro_to_inr($st['cost_micro'])) . '</dd></div><div class="rounded-2xl bg-soft p-3"><dt class="text-xs text-mute">Billed to people</dt><dd class="num text-lg font-semibold">' . e(micro_to_inr($st['revenue_micro'])) . '</dd></div></dl>';
    if ($st['days']) { echo '<div class="mt-4 overflow-x-auto"><table class="w-full min-w-[420px] text-sm"><thead><tr class="border-b border-line"><th class="th">Day</th><th class="th text-right">Characters</th><th class="th text-right">Minutes</th><th class="th text-right">Est. cost</th></tr></thead><tbody class="divide-y divide-line">';
        foreach (array_reverse($st['days']) as $d) { echo '<tr><td class="td">' . e((string) $d['d']) . '</td><td class="td num text-right">' . number_format((int) $d['chars']) . '</td><td class="td num text-right">' . round((int) $d['secs'] / 60, 1) . '</td><td class="td num text-right">' . e(micro_to_inr((int) $d['cost'])) . '</td></tr>'; } echo '</tbody></table></div>'; }
    if ($st['top']) { echo '<h3 class="mt-5 text-sm font-semibold">Top users</h3><ul class="mt-2 divide-y divide-line text-sm">'; foreach ($st['top'] as $t) { echo '<li class="flex flex-wrap justify-between gap-2 py-2"><span class="min-w-0 truncate">' . e($t['name']) . ' <span class="text-mute">' . e($t['email']) . '</span></span><span class="num text-mute">' . number_format((int) $t['chars']) . ' chars · ' . round((int) $t['secs'] / 60, 1) . ' min · ' . e(micro_to_inr((int) $t['cost'])) . '</span></li>'; } echo '</ul>'; }
    card_close(); echo '</div>';
    echo '<div class="mt-5">'; card_open('Voice requests', 'People who paid for you to set up ElevenLabs for them. Mark done after adding your key; refusing refunds their wallet.');
    if (!$reqs) { echo '<p class="text-sm text-mute">No requests yet.</p>'; } else { echo '<ul class="divide-y divide-line text-sm">';
        foreach ($reqs as $r) { echo '<li class="py-3"><div class="flex flex-wrap items-start justify-between gap-2"><div class="min-w-0"><p class="font-medium">#' . (int) $r['id'] . ' · ' . e($r['name']) . ' <span class="text-mute">' . e($r['email']) . '</span></p><p class="text-xs text-mute">' . e(substr((string) $r['created_at'], 0, 16)) . ' UTC · fee ' . e(micro_to_inr((int) $r['fee_micro'])) . ($r['refunded'] ? ' · refunded' : '') . '</p>'
            . ($r['note'] ? '<p class="mt-1">' . e($r['note']) . '</p>' : '') . ($r['admin_note'] ? '<p class="mt-1 text-xs text-mute">Note: ' . e($r['admin_note']) . '</p>' : '') . '</div>' . voice_status_badge((string) $r['status']) . '</div>';
            if (in_array($r['status'], ['new', 'in_progress'], true)) { echo '<form method="post" action="' . e(url('/do/admin-voice-request')) . '" class="mt-2 flex flex-wrap items-center gap-2">' . csrf_field() . '<input type="hidden" name="_back" value="' . e(url('/admin/voice')) . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><input class="input !h-10 min-w-0 flex-1" name="admin_note" maxlength="300" placeholder="Note to keep (optional)" aria-label="Note">'
                . ($r['status'] === 'new' ? '<button class="btn btn-sm" name="status" value="in_progress">Start</button>' : '') . '<button class="btn btn-sm btn-dark" name="status" value="done">Done</button><button class="btn btn-sm btn-ghost text-neg" name="status" value="refused">Refuse + refund</button></form>'; } echo '</li>'; } echo '</ul>'; }
    card_close(); echo '</div><script src="' . e(asset('voice.js')) . '" defer></script>'; admin_end();
}
function do_admin_voice_save(array $u): string {
    $money = fn(string $k) => post_micro_val($_POST[$k] ?? '0'); $vid = trim((string) ($_POST['el_voice_id'] ?? '')); if ($vid !== '' && !preg_match('/^[A-Za-z0-9_-]{6,40}$/', $vid)) { throw new UserError('A voice ID is letters and digits, like 21m00Tcm4TlvDq8ikWAM.'); }
    $int = fn(string $k, int $lo, int $hi) => max($lo, min($hi, (int) ($_POST[$k] ?? 0)));
    set_setting('voice_enabled', !empty($_POST['voice_enabled']) ? '1' : '0'); secret_from_post('el_key', 'el_key'); if ($vid !== '') { set_setting('el_voice_id', $vid); } set_setting('el_model', preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($_POST['el_model'] ?? '')) ?: 'eleven_multilingual_v2'); set_setting('el_stt_model', preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($_POST['el_stt_model'] ?? '')) ?: 'scribe_v1');
    set_setting('voice_cache_mb', (string) $int('voice_cache_mb', 5, 5000)); set_setting('voice_price_1k', (string) $money('voice_price_1k')); set_setting('voice_cost_1k', (string) $money('voice_cost_1k')); set_setting('voice_stt_price_min', (string) $money('voice_stt_price_min')); set_setting('voice_stt_cost_min', (string) $money('voice_stt_cost_min'));
    set_setting('voice_daily_chars', (string) $int('voice_daily_chars', 0, 1000000)); set_setting('voice_daily_stt_sec', (string) $int('voice_daily_stt_sec', 0, 86400)); $fee = parse_money(trim((string) ($_POST['voice_setup_fee'] ?? '0')) ?: '0'); if ($fee === null || $fee > 5000000) { throw new UserError('The setup fee is an amount in rupees, up to ₹50,000.'); }
    set_setting('voice_setup_fee', (string) $fee); set_setting('voice_setup_days', (string) $int('voice_setup_days', 1, 366)); audit('admin.voice', 'site'); return 'Voice settings saved.';
}
function do_admin_voice_request(array $u): string { voice_request_set(in_id('id'), in_enum('status', ['in_progress', 'done', 'refused'], 'in_progress'), (string) ($_POST['admin_note'] ?? ''), (int) $u['id']); return 'Request updated.'; }
function do_voice_request(array $u): string {
    try { voice_request_create((int) $u['id'], (string) ($_POST['note'] ?? '')); } catch (ShopShortfall $e) { $GLOBALS['__redirect'] = url('/plans'); throw $e; }
    return 'Request sent. We will set up ElevenLabs for you and tell you here when it is done.';
}
