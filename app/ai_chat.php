<?php
declare(strict_types=1);
/** The conversation engine: Anthropic Messages API transport (or the MFNC_AI_MOCK fake), the tool loop, the setup interview, naming and avatars. */

// ============================ transport ============================
function ai_http(array $body): array {
    $c = ai_cfg();
    if (ai_mock()) { return ai_mock_call($body); }
    $ch = curl_init($c['base'] . '/v1/messages'); $local = (bool) preg_match('~^http://(127\.0\.0\.1|localhost)~', $c['base']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => !$local, CURLOPT_SSL_VERIFYHOST => $local ? 0 : 2,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $c['key'], 'anthropic-version: 2023-06-01', 'content-type: application/json'], CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)]);
    $raw = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch);
    if ($raw === false) { log_error(new RuntimeException('anthropic: ' . $err)); throw new UserError('Could not reach the AI service. Try again in a minute.'); }
    $r = json_decode((string) $raw, true);
    if (!is_array($r)) { log_error(new RuntimeException("anthropic: HTTP $http non-JSON")); throw new UserError('The AI service sent an unexpected reply. Try again.'); }
    if ($http >= 400 || ($r['type'] ?? '') === 'error') {
        $t = $r['error']['type'] ?? ''; log_error(new RuntimeException("anthropic: HTTP $http $t " . ($r['error']['message'] ?? '')));
        throw new UserError(match (true) { $http === 401 || $http === 403 => 'The AI service rejected the API key. The administrator needs to check it.', $http === 429 || $http === 529 => 'The AI service is busy right now. Try again in a minute.', $http === 404 => 'The selected AI model is not available to this API key. The administrator can pick another model.',
            default => 'The AI service returned an error (' . ($t ?: "HTTP $http") . ').' });
    }
    return $r;
}

/** A scripted stand-in for the Messages API (MFNC_AI_MOCK=1). Deterministic: keywords in the last user message pick a tool path. */
function ai_mock_call(array $body): array {
    $msgs = $body['messages']; $last = end($msgs); $plain = ''; $rounds = 0; $results = [];
    for ($i = count($msgs) - 1; $i >= 0; $i--) { $m = $msgs[$i]; if ($m['role'] === 'user' && is_string($m['content'])) { $plain = $m['content']; break; } if ($m['role'] === 'user' && is_array($m['content'])) { $rounds++; foreach ($m['content'] as $b) { if (($b['type'] ?? '') === 'tool_result') { $results[] = json_decode((string) $b['content'], true); } } } }
    $t = mb_strtolower($plain); $tu = fn($name, $in) => ['type' => 'tool_use', 'id' => 'toolu_' . bin2hex(random_bytes(6)), 'name' => $name, 'input' => $in];
    $out = fn(array $content, string $stop) => ['id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'content' => $content, 'stop_reason' => $stop, 'usage' => ['input_tokens' => (int) ceil(strlen(json_encode($body)) / 4), 'output_tokens' => (int) ceil(strlen(json_encode($content)) / 4) + 20]];
    $text = fn($s) => $out([['type' => 'text', 'text' => $s]], 'end_turn');
    if (str_contains($t, 'summarise_interview')) { // setup interview wrap-up: context bullets + names
        preg_match_all('/^- (\w+): (.+)$/m', $plain, $mm, PREG_SET_ORDER); $ctx = ''; foreach ($mm as $x) { $ctx .= '- ' . ucfirst($x[1]) . ': ' . trim($x[2]) . "\n"; }
        return $text(json_encode(['context' => trim($ctx), 'names' => ['Paisa', 'Dhan', 'Sikka']]));
    }
    if (str_contains($t, 'evade') || str_contains($t, 'hide income')) { return $text('No. I do not help evade tax. Use the lawful route: claim every deduction you qualify for, pick the right regime and invest properly.'); }
    if (str_contains($t, 'mockerror')) { throw new UserError('The AI service is busy right now. Try again in a minute.'); }
    if (str_contains($t, 'emi')) {
        if ($rounds === 0) { return $out([['type' => 'text', 'text' => 'Let me run the numbers.'], $tu('calc_emi', ['principal' => 1000000, 'annual_rate_pct' => 9, 'months' => 120])], 'tool_use'); }
        if ($rounds === 1) { $r = $results[0]; return $out([$tu('present', ['blocks' => [['type' => 'stats', 'items' => [['label' => 'EMI', 'value' => $r['emi'], 'type' => 'money'], ['label' => 'Interest', 'value' => $r['total_interest'], 'type' => 'money', 'tone' => 'bad', 'sub' => round($r['interest_share_pct']) . '% of what you pay']]], ['type' => 'callout', 'tone' => 'warn', 'text' => 'Nearly half of what you repay is interest. Prepay or shorten the tenure.']]])], 'tool_use'); }
        return $text('That loan costs you more than the car. Prepay whatever you can.');
    }
    if (str_contains($t, 'tax')) {
        if ($rounds === 0) { return $out([$tu('calc_tax', ['annual_income' => 1800000, 'old_regime_deductions' => 350000, 'salaried' => true])], 'tool_use'); }
        if ($rounds === 1) { $r = $results[0]; return $out([$tu('present', ['blocks' => [['type' => 'comparison', 'title' => 'Tax on ₹18,00,000', 'labels' => ['New regime', 'Old regime'], 'rows' => [['label' => 'Taxable income', 'values' => [$r['new']['taxable_income'], $r['old']['taxable_income']], 'money' => true], ['label' => 'Total tax', 'values' => [$r['new']['total_tax'], $r['old']['total_tax']], 'money' => true, 'better' => $r['new']['total_tax'] <= $r['old']['total_tax'] ? 0 : 1]], 'verdict' => ($r['better'] === 'new' ? 'New' : 'Old') . ' regime wins by ₹' . number_format($r['saving'])]]])], 'tool_use'); }
        return $text('Pick the cheaper regime and stop agonising. Confirm the year\'s slabs with your CA before filing.');
    }
    if (str_contains($t, 'spend') || str_contains($t, 'category')) {
        if ($rounds === 0) { return $out([$tu('spending_by_category', [])], 'tool_use'); }
        if ($rounds === 1) { $r = $results[0]; $cats = array_slice($r['categories'] ?? [], 0, 6); return $out([$tu('present', ['blocks' => [['type' => 'table', 'title' => 'Where the money went', 'columns' => ['Category', ['label' => 'Amount', 'type' => 'money'], ['label' => 'Share', 'type' => 'pct']], 'rows' => array_map(fn($c) => [$c['category'], $c['amount'], $c['share_pct']], $cats)], ['type' => 'chart', 'kind' => 'donut', 'title' => 'Share by category', 'labels' => array_column($cats, 'category'), 'series' => [['name' => 'Spend', 'values' => array_column($cats, 'amount')]], 'money' => true]]])], 'tool_use'); }
        return $text('Your top category is the problem. Cut it by a fifth and see what happens.');
    }
    if (str_contains($t, 'goal')) {
        if ($rounds === 0) { return $out([$tu('list_goals', [])], 'tool_use'); }
        if ($rounds === 1) { $g = array_slice($results[0]['goals'] ?? [], 0, 5); return $out([$tu('present', ['blocks' => [['type' => 'goal_progress', 'items' => array_map(fn($x) => ['name' => $x['name'], 'saved' => $x['saved'], 'target' => $x['target'], 'note' => $x['behind'] ? 'Behind plan' : 'On track'], $g)]]])], 'tool_use'); }
        return $text('Fund the one that is behind first.');
    }
    if (str_contains($t, 'bill')) {
        if ($rounds === 0) { return $out([$tu('list_bills', [])], 'tool_use'); }
        if ($rounds === 1) { $b = array_slice($results[0]['bills'] ?? [], 0, 6); return $out([$tu('present', ['blocks' => [['type' => 'bill_list', 'items' => array_map(fn($x) => ['name' => $x['bill'], 'due' => $x['due'], 'amount' => $x['outstanding'], 'status' => $x['status']], $b)]]])], 'tool_use'); }
        return $text('Pay the overdue ones today. Late fees are the dumbest money you will ever spend.');
    }
    if (str_contains($t, 'add expense') || str_contains($t, 'record') || str_contains($t, 'add transaction')) {
        if ($rounds === 0) { return $out([$tu('list_accounts', [])], 'tool_use'); }
        if ($rounds === 1) { $a = $results[0]['accounts'][0] ?? null; $cat = $results[0]['expense_categories'][0] ?? null; return $out([$tu('propose_action', ['kind' => 'create_transaction', 'params' => ['type' => 'expense', 'amount' => 499, 'account_id' => $a['id'] ?? 0, 'category_id' => $cat['id'] ?? null, 'description' => 'Mock coffee'], 'reason' => 'You asked me to record it.'])], 'tool_use'); }
        return $text('Previewed. Press Apply if that is right.');
    }
    if (str_contains($t, 'budget')) {
        if ($rounds === 0) { return $out([$tu('list_accounts', [])], 'tool_use'); }
        if ($rounds === 1) { $cat = $results[0]['expense_categories'][0] ?? null; return $out([$tu('propose_action', ['kind' => 'set_budget', 'params' => ['category_id' => $cat['id'] ?? 0, 'amount' => 7500], 'reason' => 'Your spending here is out of hand.'])], 'tool_use'); }
        return $text('Previewed. Apply it and stick to it.');
    }
    return $text('Mock advisor here. Ask about EMI, tax, spending, goals, bills, or tell me to add an expense or set a budget.');
}

// ============================ conversation ============================
function ai_save_message(int $uid, string $role, array $content): int { return insert('INSERT INTO {ai_messages} (user_id,role,content) VALUES (?,?,?)', [$uid, $role, json_encode($content, JSON_UNESCAPED_UNICODE)]); }
function ai_history(int $uid, int $limit = 16): array {
    $rows = array_reverse(all('SELECT role,content FROM {ai_messages} WHERE user_id=? ORDER BY id DESC LIMIT ' . (int) $limit, [$uid])); $out = [];
    foreach ($rows as $r) {
        $c = json_decode($r['content'], true) ?: [];
        if ($r['role'] === 'user') { $txt = (string) ($c['text'] ?? ''); } else { $parts = []; foreach ($c['blocks'] ?? [] as $b) { $parts[] = match ($b['type'] ?? '') { 'text' => $b['text'], 'callout' => $b['text'], 'preview' => '[showed a preview: ' . $b['title'] . ' - ' . $b['status'] . ']', 'name_prompt' => '', default => '[showed a ' . ($b['type'] ?? 'component') . (!empty($b['title']) ? ': ' . $b['title'] : '') . ']' }; } $txt = trim(implode("\n", array_filter($parts))); }
        if ($txt === '') { continue; } if ($out && $out[count($out) - 1]['role'] === $r['role']) { $out[count($out) - 1]['content'] .= "\n" . $txt; } else { $out[] = ['role' => $r['role'], 'content' => $txt]; }
    }
    while ($out && $out[0]['role'] !== 'user') { array_shift($out); }
    return $out;
}
/** One model call, metered. Returns the response. Throws UserError when the money or the service is not there. */
function ai_call(array $u, array $profile, array $messages, bool $tools = true): array {
    $c = ai_cfg(); $body = ['model' => $c['model'], 'max_tokens' => $c['max_tokens'], 'system' => [['type' => 'text', 'text' => ai_system_prompt($u, $profile), 'cache_control' => ['type' => 'ephemeral']]], 'messages' => $messages];
    if ($tools) { $body['tools'] = ai_tools(); }
    $r = ai_http($body); $in = (int) ($r['usage']['input_tokens'] ?? 0) + (int) ($r['usage']['cache_creation_input_tokens'] ?? 0) + (int) ($r['usage']['cache_read_input_tokens'] ?? 0); $out = (int) ($r['usage']['output_tokens'] ?? 0);
    ai_record_usage($u, $c['model'], $in, $out); return $r;
}
/** Handles one user message and streams events through $emit(['type' => status|block|error|done, ...]). */
function ai_turn(array $u, string $text, callable $emit): void {
    $uid = (int) $u['id']; $text = trim(mb_substr($text, 0, 2000)); if ($text === '') { return; }
    $profile = ai_profile($uid);
    if ($g = ai_gate($u, $profile['stage'] === 'ready')) { $emit(['type' => 'error', 'code' => $g['code'], 'message' => $g['message']]); return; } // the setup questions are cheap and are not rate limited
    if ($profile['stage'] === 'interview') { ai_interview_step($u, $profile, $text, $emit); return; }
    if ($profile['stage'] === 'naming') { ai_name_step($u, $profile, $text, $emit); return; }
    ai_save_message($uid, 'user', ['text' => $text]); $hist = ai_history($uid); $snap = null; $ui = []; $saved = []; $c = ai_cfg();
    $messages = $hist ?: [['role' => 'user', 'content' => $text]];
    $push = function (array $b) use (&$saved, $emit) { $saved[] = $b; $emit(['type' => 'block', 'block' => $b]); };
    try {
        for ($step = 0; $step < $c['max_steps']; $step++) {
            $emit(['type' => 'status', 'text' => $step === 0 ? 'Thinking…' : 'Working on it…']);
            $r = ai_call($u, $profile, $messages); $content = $r['content'] ?? []; $uses = [];
            foreach ($content as $b) { if (($b['type'] ?? '') === 'text' && trim((string) $b['text']) !== '') { $push(['type' => 'text', 'text' => mb_substr((string) $b['text'], 0, 6000)]); } elseif (($b['type'] ?? '') === 'tool_use') { $uses[] = $b; } }
            if (($r['stop_reason'] ?? '') !== 'tool_use' || !$uses) { break; }
            $messages[] = ['role' => 'assistant', 'content' => $content]; $results = [];
            foreach ($uses as $tu) {
                $emit(['type' => 'status', 'text' => ai_status_for($tu['name'])]); $ui = [];
                try { $res = ai_run_tool($u, $tu['name'], is_array($tu['input'] ?? null) ? $tu['input'] : [], $ui, $snap); $isErr = false; }
                catch (UserError $e) { $res = ['error' => $e->getMessage()]; $isErr = true; }
                catch (Throwable $e) { log_error($e); $res = ['error' => 'That tool failed.']; $isErr = true; }
                foreach ($ui as $b) { $push($b); }
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $tu['id'], 'content' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'is_error' => $isErr];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
            if ($g = ai_gate($u)) { if ($g['code'] !== 'rate') { $push(['type' => 'callout', 'tone' => 'warn', 'text' => $g['message']]); break; } }
        }
    } catch (UserError $e) { $emit(['type' => 'error', 'code' => 'service', 'message' => $e->getMessage()]); }
    if ($saved) { ai_save_message($uid, 'assistant', ['blocks' => $saved]); }
    $emit(['type' => 'done']);
}
function ai_status_for(string $tool): string { return match (true) { str_starts_with($tool, 'calc_') => 'Calculating…', $tool === 'present' => 'Putting it together…', $tool === 'propose_action' => 'Preparing a preview…', default => 'Reading your data…' }; }

// ============================ setup interview + naming ============================
function ai_greeting_blocks(): array {
    return [['type' => 'text', 'text' => "Right. Before I give you advice, I need to know who I'm talking to. Eight quick questions. Honest answers get you honest advice; polite answers get you generic advice."], ['type' => 'text', 'text' => AI_QUESTIONS[0]['q']]];
}
/** Seeds the first assistant message of a new conversation. */
function ai_ensure_started(int $uid, array $profile): void {
    if ((int) val('SELECT COUNT(*) FROM {ai_messages} WHERE user_id=?', [$uid]) > 0) { return; }
    if ($profile['stage'] === 'interview') { ai_save_message($uid, 'assistant', ['blocks' => ai_greeting_blocks(), 'meta' => 'interview']); }
}
function ai_interview_step(array $u, array $profile, string $answer, callable $emit): void {
    $uid = (int) $u['id']; $qa = $profile['qa_list']; $i = count($qa);
    if ($i >= count(AI_QUESTIONS)) { return; }
    ai_save_message($uid, 'user', ['text' => $answer, 'setup' => 1]); $qa[] = ['key' => AI_QUESTIONS[$i]['key'], 'q' => AI_QUESTIONS[$i]['q'], 'a' => mb_substr($answer, 0, 1500)];
    run('UPDATE {ai_profiles} SET qa=? WHERE user_id=?', [json_encode($qa, JSON_UNESCAPED_UNICODE), $uid]);
    if ($i + 1 < count(AI_QUESTIONS)) {
        $b = ['type' => 'text', 'text' => 'Question ' . ($i + 2) . ' of ' . count(AI_QUESTIONS) . ': ' . AI_QUESTIONS[$i + 1]['q']]; ai_save_message($uid, 'assistant', ['blocks' => [$b]]); $emit(['type' => 'block', 'block' => $b]); $emit(['type' => 'done']); return;
    }
    $emit(['type' => 'status', 'text' => 'Writing up what you told me…']);
    $ctx = ''; foreach ($qa as $x) { $ctx .= '- ' . $x['key'] . ': ' . str_replace("\n", ' ', $x['a']) . "\n"; }
    $context = trim($ctx); $names = ['Paisa', 'Dhan', 'Sikka'];
    try {
        $prompt = "SUMMARISE_INTERVIEW. Below are a person's answers to a money-setup interview. Reply with ONLY compact JSON: {\"context\": \"6-10 short bullet lines (each starting with '- ') capturing facts that matter for advice: household, income, debts, savings, goals, tax position, habits, worry. Use the person's own numbers. No fluff.\", \"names\": [\"three short, warm, memorable first names for a blunt-but-caring money advisor, no brand names\"]}\n\nAnswers:\n" . $ctx;
        $r = ai_call($u, $profile, [['role' => 'user', 'content' => $prompt]], false); $txt = ''; foreach ($r['content'] ?? [] as $b) { if (($b['type'] ?? '') === 'text') { $txt .= $b['text']; } }
        if (preg_match('/\{.*\}/s', $txt, $m) && ($j = json_decode($m[0], true))) { if (!empty($j['context'])) { $context = mb_substr(is_array($j['context']) ? implode("\n", $j['context']) : (string) $j['context'], 0, 3000); } if (!empty($j['names']) && is_array($j['names'])) { $names = array_values(array_filter(array_map(fn($n) => preg_replace('/[^\p{L}\p{N} \-]/u', '', ai_str($n, 24)), $j['names']))) ?: $names; } }
    } catch (UserError $e) { /* keep the plain version of their answers; the interview still completes */ }
    run("UPDATE {ai_profiles} SET context=?, suggested_name=?, stage='naming' WHERE user_id=?", [$context, $names[0], $uid]);
    $blocks = [['type' => 'text', 'text' => "That's enough to start. I've written down what I know about you; you can read and edit it any time in the advisor settings."], ['type' => 'name_prompt', 'suggested' => $names[0], 'alternatives' => array_slice($names, 1, 4), 'text' => "One last thing: I'd like to be called " . $names[0] . '. Keep it, pick another, or type your own name for me.']];
    ai_save_message($uid, 'assistant', ['blocks' => $blocks]); foreach ($blocks as $b) { $emit(['type' => 'block', 'block' => $b]); } $emit(['type' => 'stage', 'stage' => 'naming']); $emit(['type' => 'done']);
}
function ai_clean_name(string $n): string { $n = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N} \-]/u', '', $n)) ?? ''); return mb_substr($n, 0, 24); }
function ai_name_step(array $u, array $profile, string $name, callable $emit): void {
    $uid = (int) $u['id']; $name = ai_clean_name($name === 'yes' || $name === '' ? (string) $profile['suggested_name'] : $name);
    if (mb_strlen($name) < 2) { $emit(['type' => 'error', 'code' => 'name', 'message' => 'A name needs at least 2 letters or digits.']); return; }
    ai_save_message($uid, 'user', ['text' => $name, 'setup' => 1]); run("UPDATE {ai_profiles} SET agent_name=?, stage='ready' WHERE user_id=?", [$name, $uid]);
    $b = ['type' => 'text', 'text' => "I'm $name. Done with the paperwork. Ask me anything about your money, or say \"how am I doing?\" and I'll tell you straight."]; ai_save_message($uid, 'assistant', ['blocks' => [$b], 'meta' => 'named']); $emit(['type' => 'block', 'block' => $b]); $emit(['type' => 'stage', 'stage' => 'ready', 'name' => $name, 'avatar' => ai_avatar_url(['avatar_style' => $profile['avatar_style'], 'agent_name' => $name])]); $emit(['type' => 'done']);
}

// ============================ avatars ============================
/** The agent's avatar, from DiceBear (cached on disk, served from this origin). Falls back to a drawn face when DiceBear cannot be reached. */
function ai_avatar_svg(string $style, string $seed): string {
    $style = isset(AI_STYLES[$style]) ? $style : 'bottts-neutral'; $seed = mb_substr(preg_replace('/[^\p{L}\p{N} \-_]/u', '', $seed) ?: 'advisor', 0, 40);
    $dir = rtrim(sys_get_temp_dir(), '/') . '/mfnc-avatars'; $f = $dir . '/' . hash('sha256', $style . '|' . $seed) . '.svg';
    if (is_file($f) && filemtime($f) > time() - 30 * 86400) { return (string) file_get_contents($f); }
    $svg = ''; if (!ai_mock() && !getenv('MFNC_NO_DICEBEAR')) {
        $ch = curl_init('https://api.dicebear.com/9.x/' . $style . '/svg?seed=' . rawurlencode($seed)); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false]);
        $raw = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); if ($raw !== false && $http === 200 && str_contains((string) $raw, '<svg') && strlen((string) $raw) < 200000) { $svg = (string) $raw; }
    }
    if ($svg === '') { return ai_fallback_avatar($seed); } // not cached, so a later request can still get the real one
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); } @file_put_contents($f, $svg); return $svg;
}
function ai_fallback_avatar(string $seed): string {
    $h = crc32($seed); $hue = $h % 360; $hue2 = ($hue + 40) % 360; $i = mb_strtoupper(mb_substr($seed, 0, 1));
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="hsl(' . $hue . ' 75% 62%)"/><stop offset="1" stop-color="hsl(' . $hue2 . ' 70% 52%)"/></linearGradient></defs><rect width="64" height="64" rx="32" fill="url(#g)"/>'
        . '<circle cx="22" cy="28" r="5" fill="#fff"/><circle cx="42" cy="28" r="5" fill="#fff"/><circle cx="23" cy="29" r="2.4" fill="#222"/><circle cx="41" cy="29" r="2.4" fill="#222"/><path d="M22 41c5 6 15 6 20 0" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round"/><text x="32" y="12" text-anchor="middle" font-family="sans-serif" font-size="9" font-weight="700" fill="#fff" opacity=".9">' . htmlspecialchars($i, ENT_QUOTES) . '</text></svg>';
}
function ai_avatar_url(array $profile): string { return url('/ai/avatar', ['style' => $profile['avatar_style'], 'seed' => $profile['agent_name'] ?: 'advisor']); }
