<?php
declare(strict_types=1);
/** AI advisor pages: chat (/ai), actions log with undo (/ai/actions), settings (/ai/settings), avatar proxy, and the JSON endpoints used by the chat. */

function ai_avatar_img(array $p, string $cls = 'size-10'): string { return '<img src="' . e(ai_avatar_url($p)) . '" alt="" width="40" height="40" class="ai-avatar ' . e($cls) . '" loading="lazy" decoding="async">'; }

function ai_gate_notice(array $u): string {
    $why = ai_not_ready_reason(); $link = '';
    if ($why === '') { $fa = feature_access($u, 'ai'); if (!$fa['ok']) { $why = $fa['reason']; $link = '<a class="btn btn-dark mt-4" href="' . e(url('/plans')) . '">See plans &amp; wallet</a>'; } }
    elseif ($u['role'] === 'admin') { $link = '<a class="btn btn-dark mt-4" href="' . e(url('/admin/ai')) . '">Set it up in Admin → AI agent</a>'; }
    if ($why === '') { return ''; }
    return '<div class="card mx-auto max-w-xl p-8 text-center"><div class="mx-auto mb-4 grid size-14 place-items-center rounded-3xl bg-soft"><span class="pills-mark" data-pills data-size="40"></span></div><h2 class="text-xl font-semibold">AI advisor is not available</h2><p class="mt-2 text-sm text-mute">' . e($why) . '</p>' . $link . '</div>';
}

/** Monthly AI budget as a small ring in the chat header (only when the person has a budget to run out of). */
function ai_budget_ring(array $u): string {
    $uid = (int) $u['id']; $bu = ai_budget($uid); if ($bu <= 0 || is_exempt_user($u)) { return ''; } $sp = ai_month_spend($uid); $pct = ring_pct($sp, $bu);
    return '<span class="ai-budget" title="' . e('AI budget this month: ' . micro_to_inr($sp) . ' of ' . micro_to_inr($bu)) . '">' . ring($pct, ['limit' => true, 'size' => 'xs', 'label' => 'AI budget used this month: ' . micro_to_inr($sp) . ' of ' . micro_to_inr($bu)]) . '</span>';
}
function page_ai(): void {
    $u = require_login(); $s = snap(); $uid = (int) $u['id']; $profile = ai_profile($uid);
    layout_start('AI advisor', 'ai', $s);
    if ($n = ai_gate_notice($u)) {
        page_header(($profile['agent_name'] ?: 'AI advisor'), 'Blunt, numbers-first advice on your own data. It can propose changes; nothing happens until you press Apply.', btn('Actions &amp; undo', url('/ai/actions'), 'btn btn-sm', 'history') . btn('Settings', url('/ai/settings'), 'btn btn-sm', 'sliders-horizontal'), '<span class="pills-mark" data-pills data-size="56" data-label="AI advisor"></span>');
        echo $n; layout_end(); return;
    }
    ai_ensure_started($uid, $profile); $profile = ai_profile($uid);
    $rows = array_reverse(all('SELECT id,role,content,created_at FROM {ai_messages} WHERE user_id=? ORDER BY id DESC LIMIT 60', [$uid])); $msgs = [];
    foreach ($rows as $r) { $c = json_decode($r['content'], true) ?: []; $at = gmdate('c', (int) strtotime($r['created_at'] . ' UTC')); $msgs[] = ($r['role'] === 'user' ? ['role' => 'user', 'text' => (string) ($c['text'] ?? ''), 'files' => array_values((array) ($c['files'] ?? [])), 'at' => $at] : ['role' => 'assistant', 'blocks' => $c['blocks'] ?? [], 'at' => $at]) + (!empty($c['voice']) ? ['voice' => true] : []); }   // voice turns carry a mic badge
    // previews that were already applied / dismissed elsewhere show their real state
    foreach ($msgs as $mi => $m) { foreach ($m['blocks'] ?? [] as $bi => $b) {   // (index based: iterating a `?? []` copy by reference would silently drop the change)
        if (($b['type'] ?? '') === 'doc_estimate') { $msgs[$mi]['blocks'][$bi]['status'] = ai_docs_estimate_open($uid, $b) ? 'open' : 'closed'; }
        if (($b['type'] ?? '') === 'preview') { $msgs[$mi]['blocks'][$bi]['status'] = (string) (val('SELECT status FROM {ai_actions} WHERE id=? AND user_id=?', [$b['action_id'], $uid]) ?? 'dismissed'); } } }
    $data = ['messages' => $msgs, 'stage' => $profile['stage'], 'agent' => ['name' => $profile['agent_name'], 'avatar' => ai_avatar_url($profile)], 'endpoints' => ['chat' => url('/ai/chat'), 'act' => url('/ai/act'), 'upload' => url('/ai/upload'), 'upload_b64' => url('/ai/upload/b64'), 'shop' => url('/plugins')], 'models' => ai_model_options($u), 'defaultModel' => ai_pick_model($u, ''), 'efforts' => array_keys(ai_efforts_cfg()), 'defaultEffort' => 'Medium',
        'commands' => array_values(array_map(fn($c) => ['key' => $c['key'], 'name' => $c['name'], 'description' => $c['description']], ai_commands($u))), 'scopes' => array_keys(AI_SCOPES), 'web' => site_setting('ai_web_search', '0') === '1', 'upload' => ai_upload_limits(), 'csrf' => csrf_token(), 'user' => explode(' ', $u['name'])[0],
        'docs' => ['pdf' => feature_on($u, 'ai_docs'), 'plugin' => url('/plugins') . '#plug-ai_docs'],
        'suggestions' => ['How am I doing this month?', 'Where does my money go?', 'Compare old vs new tax regime', 'What would a ₹10 lakh loan at 9% cost me?', 'Which of my goals is behind?', 'Which bills are due?']];
    echo '<link rel="stylesheet" href="' . e(asset('ai.css')) . '">';
    echo '<section class="card ai-chat ai2" data-ai aria-label="Chat with your advisor"><header class="ai-chat__head"><span class="ai-logo" aria-hidden="true"><span class="pills-mark" data-pills data-size="32"></span></span><div class="ai-chat__who"><p class="ai-chat__name" data-ai-name>' . e($profile['agent_name'] ?: 'Your advisor') . '</p><p class="ai-chat__sub" data-ai-sub>'
        . ($profile['stage'] === 'interview' ? 'Getting to know you' : ($profile['stage'] === 'naming' ? 'Waiting for a name' : 'Ready. Ask anything about your money.')) . '</p></div>'
        . '<div class="ai-chat__tools">' . ai_budget_ring($u) . '<span data-ai-speak-slot></span><a class="ai-iconbtn" href="' . e(url('/ai/actions')) . '" aria-label="Actions and undo" title="Actions and undo">' . icon('history', 'size-5') . '</a><a class="ai-iconbtn" href="' . e(url('/ai/settings')) . '" aria-label="Advisor settings" title="Advisor settings">' . icon('sliders-horizontal', 'size-5') . '</a></div></header>'
        . '<div class="ai-stage"><div class="ai-log" data-ai-log role="log" aria-live="polite" aria-relevant="additions"></div><button type="button" class="ai-down" data-ai-down aria-label="Jump to the latest message">' . icon('arrow-down', 'size-5') . '</button></div>'
        . '<div class="ai-suggest" data-ai-suggest></div>'
        . '<div class="ai-composer"><div data-ai-composer></div><p class="ai-foot">General information, not regulated advice. Confirm tax and legal matters with a practising chartered accountant.</p></div></section>';
    echo '<script src="' . e(asset('ai.js')) . '" defer></script><script type="application/json" id="ai-data">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script><noscript><p class="mt-4 text-sm text-mute">The advisor needs JavaScript.</p></noscript>';
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
                . ($a['error'] ? '<p class="mt-1 text-xs text-neg">' . e($a['error']) . '</p>' : '') . '</div><div class="flex items-center gap-2"><span data-status-mark data-status="' . ['applied' => 'done', 'undone' => 'cancelled', 'failed' => 'failed', 'dismissed' => 'cancelled'][$a['status']] . '" data-size="18" data-strike="0" aria-hidden="true"></span>' . badge(ucfirst($a['status']), $tone)
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
    $o = ['model' => is_string($body['model'] ?? null) ? mb_substr($body['model'], 0, 60) : '', 'effort' => is_string($body['effort'] ?? null) ? mb_substr($body['effort'], 0, 12) : '', 'files' => is_array($body['files'] ?? null) ? array_slice($body['files'], 0, 8) : [],
        'sources' => is_array($body['sources'] ?? null) ? array_slice(array_filter($body['sources'], 'is_string'), 0, 8) : [], 'web' => !empty($body['web']), 'docs_ok' => !empty($body['docs_ok'])];
    try { ai_turn($u, (string) ($body['message'] ?? ''), $emit, $o); }
    catch (UserError $e) { $emit(['type' => 'error', 'code' => 'error', 'message' => $e->getMessage()]); }
    catch (Throwable $e) { log_error($e); $emit(['type' => 'error', 'code' => 'error', 'message' => 'Something went wrong. Please try again.']); }
    exit;
}
function handle_ai_act(): never {
    [$u, $body] = ai_json_guard(); $uid = (int) $u['id']; $id = (int) ($body['id'] ?? 0); $op = (string) ($body['op'] ?? '');
    try {
        if ($op === 'docs_cancel') { $ids = array_map('intval', array_filter((array) ($body['files'] ?? []), 'is_scalar')); ai_upload_discard(ai_load_uploads($uid, array_slice($ids, 0, AI_UPLOAD_PER_MESSAGE))); echo json_encode(['type' => 'ok', 'message' => 'Cancelled. Nothing was read and you were not charged.', 'status' => 'cancelled']) . "\n"; exit; }
        if (!feature_on($u, 'ai')) { throw new UserError(feature_access($u, 'ai')['reason']); }
        $msg = match ($op) { 'apply' => ai_apply($uid, $id), 'dismiss' => (function () use ($uid, $id) { ai_dismiss($uid, $id); return 'Dismissed.'; })(), 'undo' => ai_undo($uid, $id), default => throw new UserError('Unknown request.') };
        echo json_encode(['type' => 'ok', 'message' => $msg, 'status' => (string) val('SELECT status FROM {ai_actions} WHERE id=?', [$id])]) . "\n";
    } catch (UserError $e) { echo json_encode(['type' => 'error', 'message' => $e->getMessage(), 'status' => (string) (val('SELECT status FROM {ai_actions} WHERE id=? AND user_id=?', [$id, $uid]) ?? '')]) . "\n"; }
    catch (Throwable $e) { log_error($e); echo json_encode(['type' => 'error', 'message' => 'Something went wrong.']) . "\n"; }
    exit;
}

/** POST /ai/upload (multipart): one attachment for the chat. Validated type + size; returns {type:'ok', id, name, mime, size}. */
function handle_ai_upload(): never {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); $u = current_user();
    $fail = function (int $code, string $msg) { http_response_code($code); echo json_encode(['type' => 'error', 'message' => $msg]); exit; };
    if (!$u) { $fail(401, 'Sign in again.'); } start_session(); $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''); if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) { $fail(403, 'Your page expired. Reload and try again.'); }
    if (billing_level($u) >= 2) { $fail(403, 'The app is read-only until your dues are paid.'); } session_write_close();
    if (!feature_on($u, 'ai') || ai_not_ready_reason() !== '') { $fail(403, 'The AI advisor is not available to you right now.'); }
    if (empty($_FILES['file']) || !is_array($_FILES['file']) || is_array($_FILES['file']['tmp_name'] ?? null)) { $fail(400, 'No file received.'); }
    try { echo json_encode(['type' => 'ok'] + ai_store_upload($u, $_FILES['file'])); } catch (UserError $e) { $fail(422, $e->getMessage()); } catch (Throwable $e) { log_error($e); $fail(500, 'Could not save the file.'); }
    exit;
}
