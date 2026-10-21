<?php
declare(strict_types=1);
/**
 * Voice assistant (M2): a spoken turn with the AI advisor, usable from every page (assets/voicedock.js).
 *  - POST /ai/voice            one turn: the transcript goes through the same engine as /ai/chat (ai_turn: gate, budget, wallet, tools, previews, metering),
 *                              answered as an NDJSON stream with live text `delta` events so the browser can start speaking after the first sentence.
 *  - GET  /ai/voice/state      what the panel needs: advisor name, model options, how speech will be done (ElevenLabs or the device), recent voice turns.
 *  - Streaming from the Anthropic API (server-sent events) is parsed by ai_sse_* (pure, tested); the mock transport simulates the same deltas.
 *  - Transcripts are stored in ai_messages with "voice":1 (user text, the advisor's reply and its cards); raw audio is never stored anywhere
 *    (STT audio is read from the request, sent to the provider and dropped; the cached speech files are the advisor's synthesised answer).
 *  - Nothing is applied by voice: previews appear with Apply / Undo buttons exactly as in the chat, the spoken reply only confirms what was prepared.
 */
const VOICE_HINT_MARK = 'SPOKEN_CONVERSATION';

/** Extra system text for a voice turn (not cached with the main prompt). */
function ai_voice_hint(): string {
    return VOICE_HINT_MARK . ": the person is talking to you by voice and your words are read aloud. Answer in one to four short, natural spoken sentences. No markdown, lists, tables, emoji or web addresses. "
        . "Say numbers the way a person would (for example 'about forty-five thousand rupees'). Use the tools for exact figures. Cards and previews appear on screen by themselves: do not read them out, just say what you prepared. "
        . "You can only propose changes: never say something is saved or done until the person has tapped Apply; tell them to tap Apply on the card. If the request is unclear, ask one short question.";
}
/** Is the voice assistant offered to this person at all (the advisor is switched on, or they are an admin)? Gates (budget, plan, key) are checked when they speak. */
function voice_ai_visible(?array $u): bool { return $u && (site_setting('ai_enabled', '0') === '1' || $u['role'] === 'admin'); }

// ============================ server-sent events from the Anthropic API ============================
function ai_sse_new(): array { return ['buf' => '', 'msg' => ['id' => null, 'model' => null, 'stop_reason' => null, 'usage' => []], 'blocks' => [], 'json' => [], 'error' => null, 'done' => false, 'deltas' => 0]; }
/** Feeds raw bytes from the stream. Calls $onDelta(string $text) for every piece of visible text. Safe with events split across chunks. */
function ai_sse_feed(array &$st, string $chunk, ?callable $onDelta = null): void {
    $st['buf'] .= str_replace("\r\n", "\n", $chunk);
    while (($p = strpos($st['buf'], "\n\n")) !== false) {
        $raw = substr($st['buf'], 0, $p); $st['buf'] = substr($st['buf'], $p + 2); $data = '';
        foreach (explode("\n", $raw) as $line) { if (str_starts_with($line, 'data:')) { $data .= ltrim(substr($line, 5), ' '); } }
        if ($data === '' || $data === '[DONE]') { continue; } $e = json_decode($data, true); if (!is_array($e)) { continue; }
        ai_sse_event($st, $e, $onDelta);
    }
}
function ai_sse_event(array &$st, array $e, ?callable $onDelta): void {
    $i = (int) ($e['index'] ?? 0);
    switch ($e['type'] ?? '') {
        case 'message_start': $m = $e['message'] ?? []; $st['msg']['id'] = $m['id'] ?? null; $st['msg']['model'] = $m['model'] ?? null; $st['msg']['usage'] = array_merge($st['msg']['usage'], is_array($m['usage'] ?? null) ? $m['usage'] : []); break;
        case 'content_block_start': $st['blocks'][$i] = is_array($e['content_block'] ?? null) ? $e['content_block'] : ['type' => 'text', 'text' => '']; if (($st['blocks'][$i]['type'] ?? '') === 'text' && !isset($st['blocks'][$i]['text'])) { $st['blocks'][$i]['text'] = ''; } break;
        case 'content_block_delta':
            $dl = $e['delta'] ?? []; $b = &$st['blocks'][$i]; if (!is_array($b)) { $b = ['type' => 'text', 'text' => '']; }
            switch ($dl['type'] ?? '') {
                case 'text_delta': $t = (string) ($dl['text'] ?? ''); $b['text'] = ($b['text'] ?? '') . $t; if ($t !== '') { $st['deltas']++; if ($onDelta) { $onDelta($t); } } break;
                case 'input_json_delta': $st['json'][$i] = ($st['json'][$i] ?? '') . (string) ($dl['partial_json'] ?? ''); break;
                case 'thinking_delta': $b['thinking'] = ($b['thinking'] ?? '') . (string) ($dl['thinking'] ?? ''); break;
                case 'signature_delta': $b['signature'] = ($b['signature'] ?? '') . (string) ($dl['signature'] ?? ''); break;
                case 'citations_delta': $b['citations'][] = $dl['citation'] ?? null; break;
            }
            unset($b); break;
        case 'content_block_stop': if (isset($st['json'][$i])) { $j = json_decode($st['json'][$i] === '' ? '{}' : $st['json'][$i], true); $st['blocks'][$i]['input'] = is_array($j) ? $j : []; unset($st['json'][$i]); } break;
        case 'message_delta': $dl = $e['delta'] ?? []; if (isset($dl['stop_reason'])) { $st['msg']['stop_reason'] = $dl['stop_reason']; } if (is_array($e['usage'] ?? null)) { $st['msg']['usage'] = array_merge($st['msg']['usage'], $e['usage']); } break;
        case 'message_stop': $st['done'] = true; break;
        case 'error': $st['error'] = is_array($e['error'] ?? null) ? $e['error'] : ['type' => 'api_error', 'message' => 'The stream failed.']; break;
    }
}
/** The finished message in the same shape as a non-streaming /v1/messages answer. */
function ai_sse_message(array $st): array {
    $blocks = $st['blocks']; ksort($blocks); $out = [];
    foreach ($blocks as $b) { if (($b['type'] ?? '') === 'tool_use' && (!isset($b['input']) || $b['input'] === [])) { $b['input'] = []; } $out[] = $b; }
    return ['id' => $st['msg']['id'] ?? 'msg_stream', 'type' => 'message', 'role' => 'assistant', 'model' => $st['msg']['model'], 'content' => $out, 'stop_reason' => $st['msg']['stop_reason'] ?? 'end_turn', 'usage' => $st['msg']['usage']];
}
/** Splits a mock answer into small word groups, the way a model streams (mock transport only). */
function ai_mock_deltas(string $text): array {
    preg_match_all('/\S+\s*/u', $text, $m); $out = []; $cur = ''; $n = 0;
    foreach ($m[0] as $w) { $cur .= $w; $n++; if ($n >= 3) { $out[] = $cur; $cur = ''; $n = 0; } } if ($cur !== '') { $out[] = $cur; }
    return $out;
}
/** Real streaming call. Returns the finished message; on any HTTP error it falls back to the plain call (which has the full error handling and retries). */
function ai_http_stream(array $body, callable $onDelta): array {
    $c = ai_cfg(); $plain = $body; $body = ai_fix_body($body); $body['stream'] = true;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE); if ($json === false) { throw new UserError('That request could not be prepared. Please try again.'); }
    $ch = curl_init($c['base'] . '/v1/messages'); $local = (bool) preg_match('~^http://(127\.0\.0\.1|localhost)~', $c['base']); $st = ai_sse_new(); $errRaw = ''; $ok = null;
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_TIMEOUT => 150, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => !$local, CURLOPT_SSL_VERIFYHOST => $local ? 0 : 2, CURLOPT_TCP_NODELAY => true,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $c['key'], 'anthropic-version: 2023-06-01', 'content-type: application/json', 'accept: text/event-stream'], CURLOPT_POSTFIELDS => $json,
        CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$st, &$errRaw, &$ok, $onDelta): int {
            if ($ok === null) { $ok = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200; }
            if (!$ok) { $errRaw .= $data; return strlen($data); }
            ai_sse_feed($st, $data, $onDelta); if (function_exists('ai_should_stop_quiet') && ai_should_stop_quiet()) { return 0; } return strlen($data);
        }]);
    curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch);
    if (!$ok || $http !== 200) { if ($errRaw !== '' || $http >= 400) { return ai_http($plain); } log_error(new RuntimeException('anthropic stream: ' . $err)); throw new UserError('Could not reach the AI service. Try again in a minute.'); }
    if ($st['error']) { log_error(new RuntimeException('anthropic stream error: ' . ai_redact((string) ($st['error']['message'] ?? '')))); throw new UserError('The AI service had a problem while answering. Try again.'); }
    if (!$st['blocks']) { throw new UserError('The AI service sent an empty reply. Try again.'); }
    return ai_sse_message($st);
}
/** True when the browser has gone away (barge-in / Stop aborts the request). Quiet: no output, so it is safe inside a curl write callback. */
function ai_should_stop_quiet(): bool { if (isset($GLOBALS['__ai_stop'])) { return (bool) (is_callable($GLOBALS['__ai_stop']) ? $GLOBALS['__ai_stop']() : $GLOBALS['__ai_stop']); } return PHP_SAPI !== 'cli' && connection_aborted() === 1; }

// ============================ routing decisions (tested) ============================
/** How a spoken turn will be transcribed and read aloud for this person, and why. Mirrors voice_decide(); "browser" is never an error. */
function voice_plan(?array $u): array {
    $stt = voice_decide($u, 'stt'); $tts = voice_decide($u, 'tts');
    return ['stt' => $stt['engine'], 'tts' => $tts['engine'], 'stt_why' => $stt['why'], 'tts_why' => $tts['why'], 'hint' => ($stt['engine'] === 'browser' || $tts['engine'] === 'browser') ? VOICE_HINT : ''];
}

// ============================ endpoints ============================
function voice_recent(int $uid, int $limit = 8): array {
    $rows = array_reverse(all("SELECT id,role,content,created_at FROM {ai_messages} WHERE user_id=? AND content LIKE '%\"voice\":1%' ORDER BY id DESC LIMIT " . (int) ($limit * 2), [$uid])); $out = []; $cur = null;
    foreach ($rows as $r) {
        $c = json_decode((string) $r['content'], true) ?: []; $at = gmdate('c', (int) strtotime($r['created_at'] . ' UTC'));
        if ($r['role'] === 'user') { if ($cur) { $out[] = $cur; } $cur = ['id' => (int) $r['id'], 'at' => $at, 'user' => (string) ($c['text'] ?? ''), 'reply' => '', 'cards' => 0]; continue; }
        if (!$cur) { $cur = ['id' => (int) $r['id'], 'at' => $at, 'user' => '', 'reply' => '', 'cards' => 0]; }
        foreach ($c['blocks'] ?? [] as $b) { if (($b['type'] ?? '') === 'text') { $cur['reply'] .= ($cur['reply'] !== '' ? ' ' : '') . (string) $b['text']; } else { $cur['cards']++; } }
        $out[] = $cur; $cur = null;
    }
    if ($cur) { $out[] = $cur; }
    return array_slice($out, -$limit);
}
function voice_http_state(): void {
    $u = current_user(); if (!$u) { c_json(['ok' => false, 'message' => 'Sign in again.'], 401); }
    $uid = (int) $u['id']; $profile = ai_profile($uid); $gate = voice_ai_visible($u) ? ai_gate($u, false) : ['code' => 'off', 'message' => 'The AI advisor is switched off.'];
    c_json(['ok' => true, 'ai' => ['ok' => $gate === null && $profile['stage'] === 'ready', 'code' => $gate['code'] ?? ($profile['stage'] !== 'ready' ? 'setup' : ''), 'message' => $gate['message'] ?? ($profile['stage'] !== 'ready' ? 'Finish the advisor setup in the chat first. Then you can talk to it.' : ''),
        'name' => $profile['agent_name'] ?: 'Your advisor', 'avatar' => ai_avatar_url($profile), 'models' => ai_model_options($u), 'model' => ai_pick_model($u, ''), 'efforts' => array_keys(ai_efforts_cfg()), 'effort' => 'Medium'],
        'voice' => voice_plan($u), 'recent' => voice_recent($uid), 'links' => ['chat' => url('/ai'), 'plans' => url('/plans'), 'plugins' => url('/plugins') . '#plug-voice', 'settings' => url('/ai/settings')], 'debug' => APP_DEBUG, 'user' => explode(' ', (string) $u['name'])[0]]);
}
/** POST /ai/voice: one spoken turn, streamed as NDJSON (status, delta, step, block, error, cancelled, timing, done). */
function handle_ai_voice(): never {
    [$u, $body] = ai_json_guard(); set_time_limit(150); ignore_user_abort(true); $t0 = microtime(true);
    while (ob_get_level() > 0) { ob_end_flush(); }
    $first = null; $deltas = 0; $emit = function (array $ev) use (&$first, &$deltas, $t0) { if ($ev['type'] === 'delta') { $first ??= (int) round((microtime(true) - $t0) * 1000); $deltas++; } echo json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n"; flush(); };
    $stt = in_array($body['stt'] ?? '', ['elevenlabs', 'browser', 'typed'], true) ? (string) $body['stt'] : 'typed';
    $o = ['model' => is_string($body['model'] ?? null) ? mb_substr($body['model'], 0, 60) : '', 'effort' => is_string($body['effort'] ?? null) ? mb_substr($body['effort'], 0, 12) : '', 'voice' => true, 'stream' => true, 'stt' => $stt];
    try { ai_turn($u, (string) ($body['message'] ?? ''), $emit, $o); }
    catch (UserError $e) { $emit(['type' => 'error', 'code' => 'error', 'message' => $e->getMessage()]); }
    catch (Throwable $e) { log_error($e); $emit(['type' => 'error', 'code' => 'error', 'message' => 'Something went wrong. Please try again.']); }
    $total = (int) round((microtime(true) - $t0) * 1000); $emit(['type' => 'timing', 'first_token_ms' => $first, 'total_ms' => $total, 'deltas' => $deltas]);
    if (APP_DEBUG) { error_log('[voice-latency] user=' . (int) $u['id'] . ' stt=' . $stt . ' first_token_ms=' . var_export($first, true) . ' total_ms=' . $total . ' deltas=' . $deltas); }
    exit;
}

/** The phone button and the (empty) panel shell, rendered once per page; assets/voicedock.js fills the panel the first time it opens, so nothing shifts on load. */
function voice_dock_shell(array $u): string {
    return '<button type="button" class="vd-fab lg:hidden" data-voice-open aria-label="Voice assistant" aria-haspopup="dialog" aria-expanded="false" title="Talk to your advisor">' . icon('mic', 'size-6') . '</button>'
        . '<div id="voice-dock" class="vd" hidden data-ai-js="' . e(asset('ai.js')) . '" data-ai-css="' . e(asset('ai.css')) . '"></div>';
}
