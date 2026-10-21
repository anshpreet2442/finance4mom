<?php
declare(strict_types=1);
/**
 * Telegram AI plugin (feature `telegram_ai`): free text goes to the person's own AI agent, which talks like a chartered-accountant friend.
 *  - same tools, same preview -> Apply -> logged -> Undo guarantees as the web advisor (ai_propose / ai_apply / ai_undo); nothing changes without a tap
 *  - the model can attach whitelisted inline buttons with the `buttons` tool; callback data is signed and bound to the chat and the account
 *  - components are drawn for Telegram: cards as escaped HTML, tables as monospace blocks, charts as PNG (GD, sendPhoto)
 *  - metered through ai_record_usage (monthly budget / wallet); memory is the last N turns plus a rolling summary, wiped by /forget, never logged
 */

const TG_PAGES = ['dashboard' => ['/', 'Dashboard'], 'transactions' => ['/transactions', 'Transactions'], 'bills' => ['/bills', 'Bills'], 'goals' => ['/goals', 'Goals'], 'budget' => ['/budget', 'Budget'], 'reports' => ['/reports', 'Reports'], 'analysis' => ['/analysis', 'Analysis'], 'ai' => ['/ai', 'Advisor'], 'plans' => ['/plans', 'Plans'], 'settings' => ['/settings', 'Settings']];
const TG_CHARTS = ['s' => 'spending', 't' => 'trend', 'g' => 'goals', 'b' => 'balance'];

// ============================ persona ============================
function tg_default_persona(): string {
    return <<<'P'
You are {agent_name}, {user_name}'s chartered-accountant friend, chatting with them on Telegram. Today is {today}. Money is in {currency} (Indian rupees, lakh/crore style).

HOW YOU SOUND
- Like a real person texting, not a report. A CA friend who knows their numbers, says it plainly, and is warm underneath the bluntness.
- Short messages. Usually one to four sentences, never more than {max_reply} characters. No bullet lists or headings unless they ask for a list.
- Natural fillers now and then, not in every message: "hmm", "uhh, okay so...", "honestly? I'd skip that", "wait, that's funny, but think about it: what if you take a break, book that flight, and then deal with this?"
- Mirror their language. If they write English, you write English. Hinglish only if they write Hinglish. Same for any other language.
- Verdict first, then the reason, then one next step. A light joke is fine. Nagging and lectures are not.
- If you lack a number, fetch it with a tool or ask one precise question. Never invent balances, transactions, tax rules or returns.

WHAT YOU KNOW
- Personal finance: budgets, emergency funds, debt payoff, EMIs, SIPs, insurance, goals, inflation.
- Indian tax for individuals: old vs new regime, standard deduction, 80C/80D/NPS/HRA, 87A rebate, advance tax, ITR forms, TDS, capital gains basics. Say which financial year you assume and tell them to confirm before filing. For anything complex (notices, big capital gains, business structuring) say they need a practising CA.

WHAT YOU KNOW ABOUT {user_name}
{context}
P;
}
const TG_RAILS = <<<'R'
RULES THAT ALWAYS APPLY (they override everything above)
- You are inside Telegram. Plain text only: no Markdown, no HTML, no pipe tables, no headings. Keep your prose under {max_reply} characters.
- Read real numbers with the data tools before you talk about them. Use the calc_* tools for any arithmetic. Do not invent numbers.
- To show numbers use the `present` tool (stats, table, chart, goal_progress, bill_list, comparison, callout, text). They are drawn for the person as cards, monospace tables and PNG charts. Do not repeat in prose what a component already shows.
- To change anything call `propose_action`. It only shows a preview with Apply and Not now buttons. Nothing changes until the person taps Apply, so never say it is done before they have.
- Offer next steps with the `buttons` tool, at most four with short labels: ask (a follow-up question they can tap), apply / dismiss / undo / preview (an action id you created), open (a page of the app), pay_bill (starts a confirm-before-paying flow), chart (spending, trend, goals or balance). No buttons for small talk.
- Never help evade tax, hide income, fake invoices or documents, or launder money. Refuse in one blunt, friendly sentence and point to the lawful route. Tax planning is fine; evasion is not.
R;
function tg_ai_system(array $u, array $profile, string $summary): string {
    $c = tg_cfg(); $tpl = trim($c['persona']) !== '' ? $c['persona'] : tg_default_persona(); $ctx = trim((string) ($profile['context'] ?? '')) ?: '(Nothing recorded yet. Ask when it matters, one question at a time.)';
    $vars = ['agent_name' => $profile['agent_name'] ?: 'your advisor', 'user_name' => tg_first_name($u), 'today' => today(), 'context' => $ctx, 'currency' => prefs()['currency'] ?? 'INR', 'app' => APP_NAME, 'max_reply' => (string) $c['max_reply']];
    $o = render_template($tpl, $vars); if (!str_contains($tpl, '{context}')) { $o .= "\n\nWHAT YOU KNOW ABOUT " . $vars['user_name'] . "\n" . $ctx; }
    $o .= "\n\n" . render_template(TG_RAILS, $vars);
    if ($summary !== '') { $o .= "\n\nWHAT YOU REMEMBER FROM EARLIER CHATS (summary, may be out of date)\n" . $summary; }
    return $o;
}
const TG_PERSONA_VARS = ['agent_name' => 'The name the person gave their advisor', 'user_name' => "The person's first name", 'today' => "Today's date", 'context' => 'What the person told the advisor during setup', 'currency' => 'Currency code', 'app' => 'The app name', 'max_reply' => 'The maximum reply length from the admin settings'];

// ============================ gate, metering ============================
/** null when allowed, else ['code', 'message' (Telegram HTML)]. Friendly in every case: not owned, not configured, budget, wallet, rate. */
function tg_ai_gate(array $u, int $chat): ?array {
    $fa = feature_access($u, 'telegram_ai');
    if (!$fa['ok']) {
        if ($fa['code'] === 'wallet') { return ['code' => 'wallet', 'message' => "Your wallet is empty, so I cannot think out loud right now. Top it up here and I'm back: " . tg_h(abs_url('/plans'))]; }
        return ['code' => 'plan', 'message' => "Chatting with your advisor here is the <b>Telegram AI plugin</b>. It is not on your account yet. Add it here: " . tg_h(tg_shop_url()) . "\n\nThe basic commands (balance, bills, goals, report, spent 450 on groceries) keep working."];
    }
    if ($why = ai_not_ready_reason()) { return ['code' => 'not_configured', 'message' => "I can't reach my brain at the moment: " . tg_h(preg_replace('/ Ask the administrator.*$/', '', $why)) . " The basic commands still work."]; }
    $uid = (int) $u['id'];
    if (!is_exempt_user($u)) {
        $budget = ai_budget($uid); $spent = ai_month_spend($uid);
        if ($budget > 0 && $spent >= $budget) { return ['code' => 'budget', 'message' => 'You have used this month\'s AI budget (' . tg_h(micro_to_inr($spent)) . ' of ' . tg_h(micro_to_inr($budget)) . "). It resets on the 1st. To keep going now, top up or ask the administrator to raise it: " . tg_h(abs_url('/plans'))]; }
        if ((feature_cfg('telegram_ai')['mode'] === 'payg' || feature_cfg('ai')['mode'] === 'payg') && wallet_balance($uid) <= 0) { return ['code' => 'wallet', 'message' => 'Your wallet is empty. Top it up and we can carry on: ' . tg_h(abs_url('/plans'))]; }
    }
    if (tg_rate_hit($chat, 'ai')) { return ['code' => 'rate', 'message' => 'Easy there, give me a minute to catch up.']; }
    return null;
}
/** ai_record_usage() for the budget/ledger, plus the wallet debit when only the Telegram plugin is pay-as-you-go. */
function tg_record_usage(array $u, string $model, int $in, int $out): int {
    $cost = ai_record_usage($u, $model, $in, $out);
    if (feature_cfg('telegram_ai')['mode'] === 'payg' && feature_cfg('ai')['mode'] !== 'payg' && !is_exempt_user($u) && $cost > 0) { wallet_add((int) $u['id'], -$cost, 'usage', 'Telegram AI: ' . $model . ' ' . $in . '+' . $out . ' tokens'); }
    return $cost;
}
function tg_ai_model(): string { return function_exists('ai_default_model') ? ai_default_model() : ai_cfg()['model']; }
function tg_ai_tools(): array {
    $b = ['name' => 'buttons', 'description' => 'Attach inline buttons under your reply (max 6; 4 is plenty). Each button: label (max 28 chars) and action: ask{text} sends that question back to you when tapped; apply|dismiss|undo|preview{action_id} act on an action you created with propose_action; open{page: ' . implode('|', array_keys(TG_PAGES)) . '} opens a page of the app; pay_bill{occurrence_id} starts a confirm-before-paying flow; chart{chart: spending|trend|goals|balance} sends a PNG chart.',
        'input_schema' => ['type' => 'object', 'properties' => ['buttons' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string'], 'action' => ['type' => 'string', 'enum' => ['ask', 'apply', 'dismiss', 'undo', 'preview', 'open', 'pay_bill', 'chart']],
            'text' => ['type' => 'string'], 'action_id' => ['type' => 'integer'], 'page' => ['type' => 'string', 'enum' => array_keys(TG_PAGES)], 'occurrence_id' => ['type' => 'integer'], 'chart' => ['type' => 'string', 'enum' => array_values(TG_CHARTS)]], 'required' => ['label', 'action']]]], 'required' => ['buttons']]];
    return array_merge(ai_tools(), [$b]);
}
function tg_ai_call(array $u, string $system, array $messages, bool $tools = true): array {
    $c = ai_cfg(); $model = tg_ai_model();
    $body = ['model' => $model, 'max_tokens' => min($c['max_tokens'], 1500), 'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]], 'messages' => $messages];
    if ($tools) { $body['tools'] = tg_ai_tools(); }
    $r = ai_mock() ? tg_mock_call($body) : ai_http($body);
    $in = (int) ($r['usage']['input_tokens'] ?? 0) + (int) ($r['usage']['cache_creation_input_tokens'] ?? 0) + (int) ($r['usage']['cache_read_input_tokens'] ?? 0); $out = (int) ($r['usage']['output_tokens'] ?? 0);
    tg_record_usage($u, $model, $in, $out); return $r;
}

// ============================ buttons the model may ask for ============================
/** Validates every requested button against the whitelist and this person's own data. Returns [rows of Telegram buttons, rejected reasons]. */
function tg_ai_buttons(array $u, int $chat, array $in): array {
    $uid = (int) $u['id']; $out = []; $bad = []; $list = is_array($in['buttons'] ?? null) ? array_slice($in['buttons'], 0, 6) : [];
    foreach ($list as $b) {
        if (!is_array($b)) { continue; } $label = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($b['label'] ?? ''))) ?? ''); $label = mb_substr($label, 0, 28); $act = (string) ($b['action'] ?? '');
        if ($label === '') { $bad[] = 'a button without a label'; continue; }
        switch ($act) {
            case 'ask':
                $t = trim(mb_substr((string) ($b['text'] ?? ''), 0, 200)); if ($t === '') { $bad[] = "$label: ask needs text"; break; }
                $pid = tg_pending_new($uid, $chat, 'ask', ['text' => $t]); $out[] = tg_btn($label, tg_cb($chat, $uid, 'q', $pid)); break;
            case 'apply': case 'dismiss': case 'preview': case 'undo':
                $id = (int) ($b['action_id'] ?? 0); $a = $id > 0 ? one('SELECT id,status FROM {ai_actions} WHERE id=? AND user_id=?', [$id, $uid]) : null;
                if (!$a) { $bad[] = "$label: unknown action id"; break; }
                $need = $act === 'undo' ? 'applied' : 'proposed'; if ($a['status'] !== $need) { $bad[] = "$label: that action is " . $a['status']; break; }
                $out[] = tg_btn($label, tg_cb($chat, $uid, ['apply' => 'ap', 'dismiss' => 'dm', 'preview' => 'pv', 'undo' => 'un'][$act], $id)); break;
            case 'open':
                $pg = TG_PAGES[(string) ($b['page'] ?? '')] ?? null; if (!$pg) { $bad[] = "$label: unknown page"; break; }
                $btn = tg_url_btn($label, abs_url($pg[0])); if ($btn) { $out[] = $btn; } else { $bad[] = "$label: links need an https address"; } break;
            case 'pay_bill':
                $occ = (int) ($b['occurrence_id'] ?? 0); $ok = false; if ($occ > 0) { foreach (occurrence_views(load_snapshot($uid)) as $v) { if ((int) $v['id'] === $occ && $v['outstanding'] > 0 && !$v['skipped']) { $ok = true; } } }
                if (!$ok) { $bad[] = "$label: no such unpaid bill"; break; } $out[] = tg_btn($label, tg_cb($chat, $uid, 'mp', $occ)); break;
            case 'chart':
                $code = array_search((string) ($b['chart'] ?? ''), TG_CHARTS, true); if ($code === false) { $bad[] = "$label: unknown chart"; break; } $out[] = tg_btn($label, tg_cb($chat, $uid, 'ch', (string) $code)); break;
            default: $bad[] = "$label: unknown action";
        }
    }
    $rows = []; $row = []; foreach ($out as $btn) { $long = mb_strlen($btn['text']) > 16; if ($long && $row) { $rows[] = $row; $row = []; } $row[] = $btn; if ($long || count($row) === 2) { $rows[] = $row; $row = []; } } if ($row) { $rows[] = $row; }
    return [$rows, $bad];
}

// ============================ drawing components for Telegram ============================
function tg_clean_prose(string $t, int $max): string {
    $t = preg_replace(['/\*\*|__|`{1,3}/u', '/^\s{0,3}#{1,6}\s*/mu', '/[ \t]+/u', '/\n{3,}/u'], ['', '', ' ', "\n\n"], $t) ?? $t; $t = trim($t);
    if (mb_strlen($t) <= $max) { return $t; }
    $cut = mb_substr($t, 0, $max); $p = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '? '), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, "\n"));
    return rtrim($p > $max * 0.5 ? mb_substr($cut, 0, $p + 1) : $cut) . ($p > $max * 0.5 ? '' : '…');
}
function tg_val_fmt($v, string $type = 'text'): string {
    if ($type === 'money' && is_numeric($v)) { return inr_s((float) $v); } if ($type === 'pct' && is_numeric($v)) { return round((float) $v, 1) . '%'; } if ($type === 'number' && is_numeric($v)) { return (string) round((float) $v, 2); }
    return is_float($v) ? (string) round($v, 2) : (string) $v;
}
function tg_cut(string $s, int $w): string { return mb_strlen($s) > $w ? mb_substr($s, 0, max(1, $w - 1)) . '…' : $s; }
/** Monospace table inside <pre>. Cells are escaped; widths are fitted to a phone screen (about 34 characters). */
function tg_pre_table(array $cols, array $rows, int $maxW = 34): string {
    $n = count($cols); $w = array_map(fn($c) => mb_strlen($c['label']), $cols);
    foreach ($rows as $r) { foreach ($cols as $i => $c) { $w[$i] = max($w[$i], mb_strlen($r[$i] ?? '')); } }
    $gap = 2; while (array_sum($w) + $gap * ($n - 1) > $maxW) { $k = array_search(max($w), $w, true); if ($w[$k] <= 6) { break; } $w[$k]--; }
    $line = function (array $cells) use ($cols, $w, $gap) { $o = []; foreach ($cols as $i => $c) { $t = tg_cut((string) ($cells[$i] ?? ''), $w[$i]); $pad = str_repeat(' ', max(0, $w[$i] - mb_strlen($t))); $o[] = ($c['type'] ?? 'text') === 'text' ? $t . $pad : $pad . $t; } return rtrim(implode(str_repeat(' ', $gap), $o)); };
    $out = [$line(array_map(fn($c) => $c['label'], $cols)), str_repeat('─', min($maxW, array_sum($w) + $gap * ($n - 1)))]; foreach ($rows as $r) { $out[] = $line($r); }
    return '<pre>' . tg_h(implode("\n", $out)) . '</pre>';
}
/**
 * One component -> a list of messages: ['html' => string, 'markup' => ?array, 'photo' => ?png bytes, 'rich' => bool].
 * 'rich' means something was cut short, so the caller adds an "Open in app" button.
 */
function tg_render_block(array $b, int $chat, int $uid): array {
    $t = (string) ($b['type'] ?? ''); $m = fn(string $h, array $x = []) => [['html' => $h, 'markup' => null, 'photo' => null, 'rich' => false] + $x];
    switch ($t) {
        case 'text': return $m(tg_h((string) $b['text']));
        case 'callout': return $m('<blockquote>' . ['good' => '🟢 ', 'warn' => '🟠 ', 'bad' => '🔴 '][$b['tone'] ?? 'warn'] . tg_h((string) $b['text']) . '</blockquote>');
        case 'stats':
            $o = ''; foreach ($b['items'] as $i) { $o .= ['good' => '🟢 ', 'bad' => '🔴 ', 'warn' => '🟠 '][$i['tone'] ?? ''] ?? '▪️ '; $o .= '<b>' . tg_h($i['label']) . '</b>  ' . tg_h(tg_val_fmt($i['value'], $i['type'])) . ($i['sub'] !== '' ? "\n   <i>" . tg_h($i['sub']) . '</i>' : '') . "\n"; }
            return $m(rtrim($o));
        case 'table': {
            $rows = []; foreach (array_slice($b['rows'], 0, 15) as $r) { $rows[] = array_map(fn($v, $c) => tg_val_fmt($v, $c['type']), $r, array_slice($b['columns'], 0, count($r))); }
            $more = count($b['rows']) - 15; return $m(($b['title'] !== '' ? '<b>' . tg_h($b['title']) . "</b>\n" : '') . tg_pre_table($b['columns'], $rows) . ($more > 0 ? "\n<i>…and $more more</i>" : ''), ['rich' => $more > 0]);
        }
        case 'comparison': {
            $cols = [['label' => '', 'type' => 'text']]; foreach ($b['labels'] as $l) { $cols[] = ['label' => $l, 'type' => 'number']; } $rows = [];
            foreach ($b['rows'] as $r) { $row = [$r['label']]; foreach ($b['labels'] as $i => $_) { $v = $r['values'][$i] ?? ''; $row[] = ($r['better'] === $i ? '✓ ' : '') . tg_val_fmt($v, !empty($r['money']) ? 'money' : 'text'); } $rows[] = $row; }
            return $m(($b['title'] !== '' ? '<b>' . tg_h($b['title']) . "</b>\n" : '') . tg_pre_table($cols, $rows, 36) . ($b['verdict'] !== '' ? "\n<i>" . tg_h($b['verdict']) . '</i>' : ''));
        }
        case 'goal_progress': $o = ''; foreach ($b['items'] as $i) { $o .= '<b>' . tg_h($i['name']) . '</b>' . ($i['note'] !== '' ? ' <i>' . tg_h($i['note']) . '</i>' : '') . "\n" . tg_bar((float) $i['pct']) . ' ' . round($i['pct']) . '%  ' . tg_h(inr_s((float) $i['saved'])) . ' of ' . tg_h(inr_s((float) $i['target'])) . "\n\n"; } return $m(rtrim($o));
        case 'bill_list': $o = ''; foreach ($b['items'] as $i) { $o .= ['overdue' => '🔴', 'paid' => '✅', 'upcoming' => '🟡'][$i['status']] . ' <b>' . tg_h($i['name']) . '</b> · ' . tg_h(inr_s((float) $i['amount'])) . ($i['due'] !== '' ? ' · ' . tg_h($i['due']) : '') . "\n"; } return $m(rtrim($o));
        case 'calc': {
            $steps = array_slice($b['steps'], 0, 8); $cols = [['label' => 'Step', 'type' => 'text'], ['label' => 'Value', 'type' => 'number']]; $rows = array_map(fn($s) => [(string) $s['label'], (string) $s['value']], array_filter($steps, fn($s) => ($s['value'] ?? '') !== ''));
            return $m('<b>' . tg_h($b['title']) . "</b>\n" . ($rows ? tg_pre_table($cols, array_values($rows), 36) . "\n" : '') . tg_h($b['result']['label']) . ': <b>' . tg_h($b['result']['value']) . '</b>', ['rich' => count($b['steps']) > 8]);
        }
        case 'chart': {
            $kind = $b['kind'] === 'donut' ? 'pie' : $b['kind']; $png = tg_chart_png(['kind' => $kind, 'title' => $b['title'], 'labels' => $b['labels'], 'series' => $b['series'], 'money' => $b['money']]);
            return [['html' => '', 'markup' => null, 'photo' => $png, 'rich' => false, 'caption' => $b['title']]];
        }
        case 'preview': {
            $o = '<b>Preview</b>' . "\n" . tg_h($b['title']) . "\n"; $bf = $b['before']; $af = $b['after'];
            foreach ($af as $k => $v) { if (is_scalar($v) || $v === null) { $o .= '<i>' . tg_h(ucfirst(str_replace('_', ' ', (string) $k))) . ':</i> ' . tg_h(isset($bf[$k]) && is_scalar($bf[$k]) ? tg_prev_val($bf[$k]) . ' → ' . tg_prev_val($v) : tg_prev_val($v)) . "\n"; } }
            if ($b['reason'] !== '') { $o .= '“' . tg_h($b['reason']) . '”' . "\n"; } $o .= "\n<i>Nothing has changed yet.</i>";
            return $m(rtrim($o), ['markup' => [[tg_btn('✅ Apply', tg_cb($chat, $uid, 'ap', (int) $b['action_id'])), tg_btn('Not now', tg_cb($chat, $uid, 'dm', (int) $b['action_id']))]]]);
        }
    }
    return [];
}
function tg_prev_val($v): string { return is_numeric($v) ? inr_s((float) $v) : (string) ($v ?? 'none'); }

// ============================ the conversation ============================
/** One person message -> the agent -> Telegram messages. Never throws. */
function tg_ai_message(int $chat, array $u, array $link, string $text, ?string $photoId = null): void {
    $uid = (int) $u['id'];
    try {
        if ($g = tg_ai_gate($u, $chat)) { tg_log('out', $chat, $uid, 'ai-blocked', 'skipped', $g['code']); tg_send($chat, $g['message'], null, [], $uid); return; }
        tg_log('in', $chat, $uid, 'ai'); tg_typing($chat);
        $text = trim(mb_substr($text, 0, 1500)); $image = null;
        if ($photoId) { $image = tg_download($photoId); if (!$image) { tg_send($chat, "I couldn't open that photo. Try sending it again, or type the amount.", null, [], $uid); return; } }
        $profile = ai_profile($uid); $c = tg_cfg(); $ac = ai_cfg();
        $summary = (string) (val('SELECT summary FROM {telegram_summary} WHERE user_id=?', [$uid]) ?? '');
        $rows = array_reverse(all('SELECT role,content FROM {telegram_memory} WHERE user_id=? ORDER BY id DESC LIMIT ' . ($c['memory_turns'] * 2), [$uid])); $messages = [];
        foreach ($rows as $r) { if ($messages && $messages[count($messages) - 1]['role'] === $r['role']) { $messages[count($messages) - 1]['content'] .= "\n" . $r['content']; } elseif ($messages || $r['role'] === 'user') { $messages[] = ['role' => $r['role'], 'content' => $r['content']]; } }
        if ($messages && $messages[count($messages) - 1]['role'] === 'user') { array_pop($messages); } // history must alternate; a dangling person turn is dropped
        $messages[] = ['role' => 'user', 'content' => $image ? [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['mime'], 'data' => base64_encode($image['bytes'])]], ['type' => 'text', 'text' => $text !== '' ? $text : 'Receipt.']] : $text];
        $system = tg_ai_system($u, $profile, $summary); $snap = null; $items = []; $btnRows = []; $prose = '';
        try {
            for ($n = 0; $n < $ac['max_steps']; $n++) {
                tg_typing($chat);
                $r = tg_ai_call($u, $system, $messages); $content = $r['content'] ?? []; $uses = [];
                foreach ($content as $b) { if (($b['type'] ?? '') === 'text' && trim((string) $b['text']) !== '') { $items[] = ['text', (string) $b['text']]; $prose .= (string) $b['text'] . "\n"; } elseif (($b['type'] ?? '') === 'tool_use') { $uses[] = $b; } }
                if (($r['stop_reason'] ?? '') !== 'tool_use' || !$uses) { break; }
                $messages[] = ['role' => 'assistant', 'content' => $content]; $results = [];
                foreach ($uses as $tu) {
                    $ui = []; $in = is_array($tu['input'] ?? null) ? $tu['input'] : []; $isErr = false;
                    try {
                        if ($tu['name'] === 'buttons') { [$rows2, $bad] = tg_ai_buttons($u, $chat, $in); $btnRows = array_merge($btnRows, $rows2); $res = ['registered' => array_sum(array_map('count', $rows2)), 'rejected' => $bad]; }
                        else { $res = ai_run_tool($u, (string) $tu['name'], $in, $ui, $snap); }
                    } catch (UserError $e) { $res = ['error' => $e->getMessage()]; $isErr = true; } catch (Throwable $e) { log_error($e); $res = ['error' => 'That tool failed.']; $isErr = true; }
                    foreach ($ui as $b) { $items[] = ['block', $b]; }
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $tu['id'], 'content' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'is_error' => $isErr];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
                if ($g = ai_gate($u, false)) { $items[] = ['text', $g['message']]; break; }
            }
        } catch (UserError $e) { $items[] = ['text', 'I hit a snag talking to my brain: ' . $e->getMessage()]; }
        tg_ai_deliver($chat, $u, $items, $btnRows, $c['max_reply']);
        tg_ai_remember($u, $text !== '' || !$image ? ($image ? '[sent a photo] ' : '') . $text : '[sent a photo]', $prose, $items);
    } catch (Throwable $e) { log_error($e); tg_log('out', $chat, $uid, 'ai', 'failed', get_class($e)); tg_send($chat, 'Hmm, I tripped over something on my side. Try again in a moment.', null, [], $uid); }
}
/** Turns the collected prose and components into Telegram messages; the model's buttons go under the last one. */
function tg_ai_deliver(int $chat, array $u, array $items, array $btnRows, int $maxReply): void {
    $uid = (int) $u['id']; $msgs = []; $rich = false;
    foreach ($items as [$kind, $v]) {
        if ($kind === 'text') { $t = tg_clean_prose($v, $maxReply); if ($t !== '') { $msgs[] = ['html' => tg_h($t), 'markup' => null, 'photo' => null]; } }
        else { foreach (tg_render_block($v, $chat, $uid) as $m) { $rich = $rich || !empty($m['rich']); $msgs[] = $m; } }
    }
    if (!$msgs) { $msgs[] = ['html' => tg_h('Hmm, I drew a blank on that one. Try asking it another way?'), 'markup' => null, 'photo' => null]; }
    $extra = $btnRows; if ($rich && ($ob = tg_url_btn('Open the full result', abs_url('/ai')))) { $extra[] = [$ob]; }
    $last = count($msgs) - 1; $rows = array_merge($msgs[$last]['markup'] ?? [], $extra); if ($rows) { $msgs[$last]['markup'] = $rows; }
    foreach ($msgs as $i => $m) {
        $mk = !empty($m['markup']) ? ['inline_keyboard' => array_slice($m['markup'], 0, 8)] : null;
        if ($m['photo'] !== null) { tg_typing($chat, 'upload_photo'); if (!tg_photo($chat, $m['photo'], isset($m['caption']) ? '<b>' . tg_h($m['caption']) . '</b>' : '', $mk, $uid) && $mk) { tg_send($chat, 'Here are your options:', $mk, [], $uid); } }
        else { tg_send($chat, $m['html'], $mk, [], $uid); }
    }
}

// ============================ memory ============================
/** Stores the exchange (text only: no photos, no component data), then folds old turns into the rolling summary. Contents are capped and never logged. */
function tg_ai_remember(array $u, string $userText, string $assistantProse, array $items): void {
    $uid = (int) $u['id']; $notes = []; foreach ($items as [$k, $v]) { if ($k === 'block') { $notes[] = '[showed ' . ($v['type'] === 'preview' ? 'a preview: ' . ($v['title'] ?? '') : 'a ' . ($v['type'] ?? 'component') . (!empty($v['title']) ? ': ' . $v['title'] : '')) . ']'; } }
    $a = trim($assistantProse . "\n" . implode("\n", $notes));
    if (trim($userText) !== '') { run("INSERT INTO {telegram_memory} (user_id,role,content) VALUES (?,'user',?)", [$uid, mb_substr($userText, 0, 1500)]); }
    if ($a !== '') { run("INSERT INTO {telegram_memory} (user_id,role,content) VALUES (?,'assistant',?)", [$uid, mb_substr($a, 0, 1500)]); }
    try { tg_memory_compact($u); } catch (Throwable $e) { log_error($e); }
}
function tg_memory_compact(array $u): void {
    $uid = (int) $u['id']; $keep = tg_cfg()['memory_turns'] * 2; $total = (int) val('SELECT COUNT(*) FROM {telegram_memory} WHERE user_id=?', [$uid]);
    if ($total <= $keep + 6) { return; }
    $old = all('SELECT id,role,content FROM {telegram_memory} WHERE user_id=? ORDER BY id ASC LIMIT ' . ($total - $keep), [$uid]); if (!$old) { return; }
    $ids = array_column($old, 'id'); $existing = (string) (val('SELECT summary FROM {telegram_summary} WHERE user_id=?', [$uid]) ?? ''); $tr = '';
    foreach ($old as $r) { $tr .= ($r['role'] === 'user' ? 'Person: ' : 'Advisor: ') . str_replace("\n", ' ', $r['content']) . "\n"; }
    try {
        $prompt = "SUMMARISE_CHAT. Merge the existing memory and the older conversation below into ONE short memory (max 800 characters): facts the person told you, decisions made, preferences, and open to-dos. Plain text, no markdown, no tool talk.\n\nEXISTING MEMORY:\n" . ($existing ?: '(none)') . "\n\nOLDER CONVERSATION:\n" . mb_substr($tr, 0, 6000);
        $r = tg_ai_call($u, 'You compress chat history into a short memory for a personal finance advisor. Reply with the memory text only.', [['role' => 'user', 'content' => $prompt]], false); $s = '';
        foreach ($r['content'] ?? [] as $b) { if (($b['type'] ?? '') === 'text') { $s .= $b['text']; } }
        $s = mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, 1200);
        if ($s !== '') { run('INSERT INTO {telegram_summary} (user_id,summary) VALUES (?,?) ON DUPLICATE KEY UPDATE summary=VALUES(summary)', [$uid, $s]); }
    } catch (UserError) { if ($total <= 80) { return; } } // the model is unavailable: keep the turns unless the table would grow without bound
    run('DELETE FROM {telegram_memory} WHERE user_id=? AND id IN (' . implode(',', array_map('intval', $ids)) . ')', [$uid]);
}

// ============================ callbacks that belong to the AI plugin ============================
function tg_ai_callback(string $id, int $chat, ?int $mid, array $u, array $link, array $s, string $op, array $args): void {
    $uid = (int) $u['id']; $arg = (int) ($args[0] ?? 0);
    if ($op === 'un') { // undoing is always allowed, even after the plugin lapsed
        $msg = ai_undo($uid, $arg); tg_log('out', $chat, $uid, 'action:ai-undo', 'ok', 'action#' . $arg); tg_answer($id, 'Undone.'); tg_edit($chat, $mid, '↩️ ' . tg_h($msg)); return;
    }
    if (!in_array($op, ['ap', 'dm', 'pv', 'q', 'ch'], true)) { tg_answer($id, 'That button is not valid any more.', true); return; }
    if (!feature_on($u, 'telegram_ai')) { tg_answer($id, 'The Telegram AI plugin is not on your account.', true); tg_send($chat, 'That needs the <b>Telegram AI plugin</b>: ' . tg_h(tg_shop_url()), null, [], $uid); return; }
    switch ($op) {
        case 'ap':
            if (!tg_can_write($u)) { throw new UserError('Your account is read-only until your dues are paid.'); }
            $msg = ai_apply($uid, $arg); tg_log('out', $chat, $uid, 'action:ai-apply', 'ok', 'action#' . $arg); tg_answer($id, 'Applied.');
            tg_edit($chat, $mid, '✅ ' . tg_h($msg) . "\n<i>Logged. You can undo it.</i>", tg_kb([[tg_btn('↩️ Undo', tg_cb($chat, $uid, 'un', $arg))]])); return;
        case 'dm': ai_dismiss($uid, $arg); tg_answer($id, 'Dropped.'); tg_edit($chat, $mid, 'Okay, dropped it. Nothing was changed.'); return;
        case 'pv': {
            $a = ai_action_row($uid, $arg); $d = json_decode((string) $a['params'], true) ?: []; $o = '<b>' . tg_h($a['summary']) . "</b>\nStatus: " . tg_h($a['status']) . "\n";
            foreach ($d as $k => $v) { if (is_scalar($v) && $v !== null && $v !== '') { $o .= '• ' . tg_h(str_replace('_', ' ', (string) $k)) . ': ' . tg_h(in_array($k, ['amount', 'target_amount', 'monthly_target'], true) ? inr_s($v / 100) : (string) $v) . "\n"; } }
            tg_answer($id); tg_send($chat, rtrim($o), null, [], $uid); return;
        }
        case 'q': {
            $p = tg_pending_get($arg, $uid, $chat, 'ask') ?? throw new UserError('That question expired. Just type it.'); tg_pending_close($arg); tg_answer($id);
            tg_send($chat, '<i>' . tg_h($p['data']['text']) . '</i>', null, [], $uid); tg_ai_message($chat, $u, $link, (string) $p['data']['text']); return;
        }
        case 'ch': {
            $name = TG_CHARTS[(string) ($args[0] ?? '')] ?? throw new UserError('Unknown chart.'); tg_answer($id, 'Drawing it…'); tg_typing($chat, 'upload_photo');
            $spec = tg_chart_data($uid, $name); if (!$spec) { tg_send($chat, "There is nothing to chart yet. Add a few transactions or goals first.", null, [], $uid); return; }
            tg_photo($chat, tg_chart_png($spec), '<b>' . tg_h($spec['title']) . '</b>', null, $uid); return;
        }
    }
}

// ============================ scripted model for tests (MFNC_AI_MOCK=1) ============================
/** Deterministic stand-in for the Messages API, Telegram flavour. Anything it does not know falls through to the web mock. */
function tg_mock_call(array $body): array {
    $msgs = $body['messages']; $plain = ''; $rounds = 0; $results = []; $image = false;
    for ($i = count($msgs) - 1; $i >= 0; $i--) {
        $m = $msgs[$i]; if ($m['role'] !== 'user') { continue; }
        if (is_string($m['content'])) { $plain = $m['content']; break; }
        $hasRes = false; foreach ($m['content'] as $b) { if (($b['type'] ?? '') === 'tool_result') { $hasRes = true; $results[] = json_decode((string) $b['content'], true); } }
        if ($hasRes) { $rounds++; continue; }
        foreach ($m['content'] as $b) { if (($b['type'] ?? '') === 'image') { $image = true; } if (($b['type'] ?? '') === 'text') { $plain = (string) $b['text']; } } break;
    }
    $t = mb_strtolower($plain); $tu = fn($n, $in) => ['type' => 'tool_use', 'id' => 'toolu_' . bin2hex(random_bytes(6)), 'name' => $n, 'input' => $in];
    $out = fn(array $c, string $stop) => ['id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'content' => $c, 'stop_reason' => $stop, 'usage' => ['input_tokens' => (int) ceil(strlen(json_encode($body)) / 4), 'output_tokens' => (int) ceil(strlen(json_encode($c)) / 4) + 20]];
    $text = fn($s) => $out([['type' => 'text', 'text' => $s]], 'end_turn');
    if (str_contains($t, 'summarise_chat')) { preg_match_all('/^Person: (.+)$/m', $plain, $mm); return $text('Earlier: ' . mb_substr(implode('; ', $mm[1] ?? []), 0, 500)); }
    if (str_contains($t, 'evade') || str_contains($t, 'hide income')) { return $text("Nope, I won't help hide income from the taxman. Honestly? Use the deductions you're entitled to and file properly. That's the legal way to pay less."); }
    if ($image) {
        if ($rounds === 0) { return $out([['type' => 'text', 'text' => 'Uhh, okay so let me look at this receipt.'], $tu('list_accounts', [])], 'tool_use'); }
        if ($rounds === 1) { $a = $results[0]['accounts'][0] ?? null; $cat = $results[0]['expense_categories'][0] ?? null; return $out([$tu('propose_action', ['kind' => 'create_transaction', 'params' => ['type' => 'expense', 'amount' => 349, 'account_id' => $a['id'] ?? 0, 'category_id' => $cat['id'] ?? null, 'description' => 'Receipt: Mock Cafe'], 'reason' => 'Read from your photo.'])], 'tool_use'); }
        if ($rounds === 2) { $id = (int) ($results[0]['action_id'] ?? 0); return $out([$tu('buttons', ['buttons' => [['label' => 'Apply it', 'action' => 'apply', 'action_id' => $id], ['label' => 'Not now', 'action' => 'dismiss', 'action_id' => $id]]])], 'tool_use'); }
        return $text("Looks like a ₹349 cafe bill. Tap Apply if that's right.");
    }
    if (str_contains($t, 'chart') || str_contains($t, 'graph')) {
        if ($rounds === 0) { return $out([['type' => 'text', 'text' => 'Hmm, **let me pull** that up.'], $tu('spending_by_category', [])], 'tool_use'); }
        if ($rounds === 1) { $cats = array_slice($results[0]['categories'] ?? [], 0, 5); return $out([$tu('present', ['blocks' => [['type' => 'chart', 'kind' => 'donut', 'title' => 'Spending by category', 'labels' => array_column($cats, 'category'), 'series' => [['name' => 'Spend', 'values' => array_column($cats, 'amount')]], 'money' => true]]])], 'tool_use'); }
        if ($rounds === 2) { return $out([$tu('buttons', ['buttons' => [['label' => 'Trend, last 6 months', 'action' => 'chart', 'chart' => 'trend'], ['label' => 'How do I cut it?', 'action' => 'ask', 'text' => 'How can I cut my biggest category?'], ['label' => 'Open reports', 'action' => 'open', 'page' => 'reports'], ['label' => 'Evil', 'action' => 'apply', 'action_id' => 999999], ['label' => 'Hack', 'action' => 'rm -rf'], ['label' => 'Bills', 'action' => 'open', 'page' => '../etc/passwd']]])], 'tool_use'); }
        return $text("Honestly? That biggest slice is the whole story. What if you trim it by a fifth and see?");
    }
    if (str_contains($t, 'show stats')) {
        if ($rounds === 0) { return $out([$tu('present', ['blocks' => [['type' => 'stats', 'items' => [['label' => 'Cash <b>', 'value' => 12345.5, 'type' => 'money', 'sub' => 'a & b < c'], ['label' => 'Rate', 'value' => 12.5, 'type' => 'pct', 'tone' => 'good']]], ['type' => 'table', 'title' => 'Top <spend>', 'columns' => ['Item', ['label' => 'Amount', 'type' => 'money']], 'rows' => [['Rent & <utilities>', 45000], ['Food', 12000]]], ['type' => 'callout', 'tone' => 'warn', 'text' => 'Watch <script>alert(1)</script>']]])], 'tool_use'); }
        return $text('Hmm, there you go.');
    }
    if (str_contains($t, 'long reply')) { return $text(str_repeat('This sentence is here to be long and chatty. ', 60)); }
    if (preg_match('/\b(hi|hello|hey)\b/', $t)) { return $text("Uhh, okay so hi! What's on your mind, money-wise?"); }
    return ai_mock_call($body);
}
