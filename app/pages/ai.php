<?php
declare(strict_types=1);
/** AI advisor pages: chat (/ai), actions log with undo (/ai/actions), settings (/ai/settings), avatar proxy, and the JSON endpoints used by the chat. */

function ai_avatar_img(array $p, string $cls = 'size-10'): string { return '<img src="' . e(ai_avatar_url($p)) . '" alt="" width="40" height="40" class="ai-avatar ' . e($cls) . '" loading="lazy" decoding="async">'; }

function ai_gate_notice(array $u): string {
    $why = ai_not_ready_reason(); $link = '';
    if ($why === '') { $fa = feature_access($u, 'ai'); if (!$fa['ok']) { $why = $fa['reason']; $link = '<a class="btn btn-dark mt-4" href="' . e(url('/plans')) . '">See plans &amp; wallet</a>'; } }
    elseif ($u['role'] === 'admin') { $link = '<a class="btn btn-dark mt-4" href="' . e(url('/admin/ai')) . '">Set it up in Admin → AI agent</a>'; }
    if ($why === '') { return ''; }
    return '<div class="card mx-auto max-w-xl p-8 text-center"><div class="mx-auto mb-4 grid size-14 place-items-center rounded-3xl bg-soft">' . icon('sparkles', 'size-7') . '</div><h2 class="text-xl font-semibold">AI advisor is not available</h2><p class="mt-2 text-sm text-mute">' . e($why) . '</p>' . $link . '</div>';
}

function page_ai(): void {
    $u = require_login(); $s = snap(); $uid = (int) $u['id']; $profile = ai_profile($uid);
    layout_start('AI advisor', 'ai', $s);
    page_header(($profile['agent_name'] ?: 'AI advisor'), 'Blunt, numbers-first advice on your own data. It can propose changes; nothing happens until you press Apply.',
        btn('Actions &amp; undo', url('/ai/actions'), 'btn btn-sm', 'history') . btn('Settings', url('/ai/settings'), 'btn btn-sm', 'sliders-horizontal'));
    if ($n = ai_gate_notice($u)) { echo $n; layout_end(); return; }
    ai_ensure_started($uid, $profile); $profile = ai_profile($uid);
    $rows = array_reverse(all('SELECT id,role,content FROM {ai_messages} WHERE user_id=? ORDER BY id DESC LIMIT 60', [$uid])); $msgs = [];
    foreach ($rows as $r) { $c = json_decode($r['content'], true) ?: []; $msgs[] = $r['role'] === 'user' ? ['role' => 'user', 'text' => (string) ($c['text'] ?? '')] : ['role' => 'assistant', 'blocks' => $c['blocks'] ?? []]; }
    // previews that were already applied / dismissed elsewhere show their real state
    foreach ($msgs as &$m) { foreach ($m['blocks'] ?? [] as &$b) { if (($b['type'] ?? '') === 'preview') { $b['status'] = (string) (val('SELECT status FROM {ai_actions} WHERE id=? AND user_id=?', [$b['action_id'], $uid]) ?? 'dismissed'); } } unset($b); } unset($m);
    $data = ['messages' => $msgs, 'stage' => $profile['stage'], 'agent' => ['name' => $profile['agent_name'], 'avatar' => ai_avatar_url($profile)], 'endpoints' => ['chat' => url('/ai/chat'), 'act' => url('/ai/act')], 'csrf' => csrf_token(), 'user' => explode(' ', $u['name'])[0],
        'suggestions' => ['How am I doing this month?', 'Where does my money go?', 'Compare old vs new tax regime', 'What would a ₹10 lakh loan at 9% cost me?', 'Which of my goals is behind?', 'Which bills are due?']];
    echo '<section class="card ai-chat" data-ai aria-label="Chat with your advisor"><header class="ai-chat__head">' . ai_avatar_img($profile, 'size-11') . '<div class="min-w-0"><p class="truncate font-semibold" data-ai-name>' . e($profile['agent_name'] ?: 'Your advisor') . '</p><p class="text-xs text-mute" data-ai-sub>'
        . ($profile['stage'] === 'interview' ? 'Getting to know you' : ($profile['stage'] === 'naming' ? 'Waiting for a name' : 'Ready. Ask anything about your money.')) . '</p></div></header>'
        . '<div class="ai-log" data-ai-log role="log" aria-live="polite" aria-relevant="additions"></div>'
        . '<div class="ai-suggest" data-ai-suggest></div>'
        . '<form class="ai-compose" data-ai-form autocomplete="off"><label for="ai-input" class="sr-only">Message</label><textarea id="ai-input" rows="1" maxlength="2000" placeholder="Ask or answer here" data-ai-input></textarea><button class="btn btn-dark ai-send" aria-label="Send">' . icon('send', 'size-4') . '</button></form></section>';
    echo '<script src="' . e(asset('ai.js')) . '" defer></script><script type="application/json" id="ai-data">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script><noscript><p class="mt-4 text-sm text-mute">The advisor needs JavaScript.</p></noscript>';
    echo '<p class="mt-3 text-center text-xs text-mute">General information, not regulated advice. Confirm tax and legal matters with a practising chartered accountant.</p>';
    layout_end();
}

function page_ai_actions(): void {
    $u = require_login(); $s = snap(); $uid = (int) $u['id']; $profile = ai_profile($uid);
    $rows = all("SELECT * FROM {ai_actions} WHERE user_id=? AND status IN ('applied','undone','failed','dismissed') ORDER BY id DESC LIMIT 200", [$uid]);
    layout_start('AI actions', 'ai', $s);
    page_header('AI actions', 'Every change your advisor proposed, what happened, and a one-click undo for anything applied.', btn('Back to chat', url('/ai'), 'btn btn-sm', 'arrow-left'));
    card_flush_open();
    if (!$rows) { echo '<div class="p-6">' . empty_state('No actions yet', 'When the advisor proposes a change and you apply it, it appears here.', '', 'sparkles') . '</div>'; }
    else {
        echo '<ul class="divide-y divide-line">';
        foreach ($rows as $a) {
            $tone = ['applied' => 'pos', 'undone' => 'neutral', 'failed' => 'neg', 'dismissed' => 'neutral'][$a['status']] ?? 'neutral'; $b = json_decode((string) $a['before_json'], true) ?: []; $af = json_decode((string) $a['after_json'], true) ?: [];
            $kv = fn($x) => implode(', ', array_map(fn($k, $v) => str_replace('_', ' ', $k) . ': ' . (is_scalar($v) ? (string) $v : json_encode($v)), array_keys($x), $x));
            echo '<li class="flex flex-wrap items-center justify-between gap-3 px-6 py-4"><div class="min-w-0 flex-1"><p class="font-medium">' . e($a['summary']) . '</p><p class="text-xs text-mute">' . e(ucfirst(str_replace('_', ' ', $a['kind']))) . ' · ' . e($a['created_at']) . ' UTC'
                . ($a['undone_at'] ? ' · undone ' . e($a['undone_at']) : '') . '</p>' . ($b || $af ? '<details class="mt-1 text-xs text-mute"><summary class="cursor-pointer">Before / after</summary><p class="mt-1">Before: ' . e($kv($b) ?: '—') . '</p><p>Recorded: ' . e($kv($af) ?: '—') . '</p></details>' : '')
                . ($a['error'] ? '<p class="mt-1 text-xs text-neg">' . e($a['error']) . '</p>' : '') . '</div><div class="flex items-center gap-2">' . badge(ucfirst($a['status']), $tone)
                . ($a['status'] === 'applied' ? confirm_btn(icon('undo-2', 'size-4') . ' Undo', 'ai-undo', ['id' => $a['id']], 'Undo this change?', $a['summary'] . ' will be reversed exactly.', 'Undo', 'btn btn-sm') : '') . '</div></li>';
        }
        echo '</ul>';
    }
    echo '</div></section>'; layout_end();
}

function page_ai_settings(): void {
    $u = require_login(); $s = snap(); $uid = (int) $u['id']; $profile = ai_profile($uid);
    layout_start('Advisor settings', 'ai', $s);
    page_header('Advisor settings', 'Name your advisor, change its look, and correct what it knows about you.', btn('Back to chat', url('/ai'), 'btn btn-sm', 'arrow-left'));
    echo '<div class="grid gap-5 lg:grid-cols-2"><div class="space-y-5">';
    card_open('Your advisor'); echo '<div class="mb-4 flex items-center gap-4">' . ai_avatar_img($profile, 'size-16') . '<div><p class="text-lg font-semibold">' . e($profile['agent_name'] ?: 'Not named yet') . '</p><p class="text-xs text-mute">Avatars by DiceBear, drawn fresh from the name.</p></div></div>';
    $styles = ''; foreach (AI_STYLES as $k => $l) { $styles .= '<label class="ai-style"><input type="radio" name="avatar_style" value="' . e($k) . '"' . ($profile['avatar_style'] === $k ? ' checked' : '') . '><span><img src="' . e(url('/ai/avatar', ['style' => $k, 'seed' => $profile['agent_name'] ?: 'advisor'])) . '" alt="" width="48" height="48" loading="lazy"><small>' . e($l) . '</small></span></label>'; }
    echo form_open('ai-profile-save', 'space-y-4') . '<div><label class="label" for="an">Name</label><input id="an" name="agent_name" class="input" maxlength="24" required value="' . e((string) $profile['agent_name']) . '" placeholder="e.g. Paisa"></div><fieldset><legend class="label">Look</legend><div class="ai-styles">' . $styles . '</div></fieldset><div class="flex justify-end"><button class="btn btn-dark">Save</button></div></form>';
    card_close();
    card_open('Danger zone', 'Start over with the setup questions. Your chat history and context are cleared; applied actions and their undo stay.');
    echo '<div class="flex flex-wrap gap-2">' . confirm_btn('Restart setup', 'ai-reset', [], 'Restart the advisor setup?', 'The advisor forgets this conversation and what it knows about you, and asks the setup questions again.', 'Restart', 'btn btn-sm') . '</div>'; card_close();
    echo '</div><div class="space-y-5">';
    card_open('What your advisor knows about you', 'Written from your setup answers. It is added to every conversation, so keep it accurate and short.');
    echo form_open('ai-context-save', 'space-y-3') . '<label class="sr-only" for="ctx">Context</label><textarea id="ctx" name="context" class="input" rows="14" maxlength="3000">' . e((string) $profile['context']) . '</textarea><p class="text-xs text-mute">Stored on this server and sent to the AI service with your questions. Do not put passwords or account numbers here.</p><div class="flex justify-end"><button class="btn btn-dark">Save context</button></div></form>';
    card_close(); echo '</div></div>'; layout_end();
}

/** GET /ai/avatar?style=&seed= : same-origin image so no third-party host is needed in img-src. */
function page_ai_avatar(): void {
    require_login(); $style = get_str('style', 30); $seed = get_str('seed', 40);
    header('Content-Type: image/svg+xml'); header('Cache-Control: private, max-age=86400'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'"); echo ai_avatar_svg($style, $seed);
}

// ============================ JSON endpoints (POST) ============================
function ai_json_guard(): array {
    header('Content-Type: application/x-ndjson; charset=utf-8'); header('X-Accel-Buffering: no'); header('Cache-Control: no-store');
    $u = current_user(); $fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['type' => 'error', 'message' => $msg]) . "\n"; exit; };
    if (!$u) { $fail(401, 'Sign in again.'); }
    start_session(); $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''); if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) { $fail(403, 'Your page expired. Reload and try again.'); }
    if (billing_level($u) >= 2) { $fail(403, 'The app is read-only until your dues are paid.'); }
    $body = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true); if (!is_array($body)) { $fail(400, 'Bad request.'); }
    session_write_close(); // do not hold the session lock while the model thinks
    return [$u, $body];
}
function handle_ai_chat(): never {
    [$u, $body] = ai_json_guard(); set_time_limit(150); ignore_user_abort(true);
    while (ob_get_level() > 0) { ob_end_flush(); }
    $emit = function (array $ev) { echo json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n"; echo str_repeat(' ', 0); flush(); };
    try { ai_turn($u, (string) ($body['message'] ?? ''), $emit); }
    catch (UserError $e) { $emit(['type' => 'error', 'code' => 'error', 'message' => $e->getMessage()]); }
    catch (Throwable $e) { log_error($e); $emit(['type' => 'error', 'code' => 'error', 'message' => 'Something went wrong. Please try again.']); }
    exit;
}
function handle_ai_act(): never {
    [$u, $body] = ai_json_guard(); $uid = (int) $u['id']; $id = (int) ($body['id'] ?? 0); $op = (string) ($body['op'] ?? '');
    try {
        if (!feature_on($u, 'ai')) { throw new UserError(feature_access($u, 'ai')['reason']); }
        $msg = match ($op) { 'apply' => ai_apply($uid, $id), 'dismiss' => (function () use ($uid, $id) { ai_dismiss($uid, $id); return 'Dismissed.'; })(), 'undo' => ai_undo($uid, $id), default => throw new UserError('Unknown request.') };
        echo json_encode(['type' => 'ok', 'message' => $msg, 'status' => (string) val('SELECT status FROM {ai_actions} WHERE id=?', [$id])]) . "\n";
    } catch (UserError $e) { echo json_encode(['type' => 'error', 'message' => $e->getMessage(), 'status' => (string) (val('SELECT status FROM {ai_actions} WHERE id=? AND user_id=?', [$id, $uid]) ?? '')]) . "\n"; }
    catch (Throwable $e) { log_error($e); echo json_encode(['type' => 'error', 'message' => 'Something went wrong.']) . "\n"; }
    exit;
}
