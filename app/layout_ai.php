<?php
declare(strict_types=1);
/**
 * AI Layout, part 2: drafts (with undo/redo), the JSON API, the tool-using assistant with its verify loop, and the pages.
 *
 * The assistant can only call four tools: list_widgets, get_layout, set_layout_ops, preview_layout. Every set_layout_ops goes to a DRAFT through lw_apply_ops()
 * (whitelist + validation). The server then renders the draft for real (lw_snapshot) and returns that observation to the model, which must compare it with the request.
 * The message the person reads is built by the server from the verified snapshot and the diff of layouts, never from the model's own claims.
 */
const LW_AI_MAX_STEPS = 6;

// ============================ drafts ============================
function lw_device_param($d): string { return in_array($d, LW_DEVICES, true) ? (string) $d : (c_is_mobile_ua() ? 'mobile' : 'desktop'); }
function lw_draft_row(int $uid, int $id): array { $r = one('SELECT * FROM {c_layout_drafts} WHERE id=? AND user_id=?', [$id, $uid]); if (!$r) { throw new UserError('That draft is gone. Reload the page.'); } return $r; }
function lw_draft_open(int $uid, string $device): array {
    $r = one('SELECT * FROM {c_layout_drafts} WHERE user_id=? AND device=? AND updated_at > ? ORDER BY id DESC LIMIT 1', [$uid, $device, gmdate('Y-m-d H:i:s', time() - 6 * 3600)]); if ($r) { return $r; }
    $id = insert('INSERT INTO {c_layout_drafts} (user_id,device,cur,hist_undo,hist_redo,brief,msgs) VALUES (?,?,?,?,?,?,?)', [$uid, $device, json_encode(lw_get($uid, $device, true)), '[]', '[]', '', '[]']); run('DELETE FROM {c_layout_drafts} WHERE user_id=? AND id < ? AND updated_at < ?', [$uid, $id, gmdate('Y-m-d H:i:s', time() - 86400)]); return lw_draft_row($uid, $id);
}
function lw_draft_layout(array $row): array { return lw_normalize(json_decode((string) $row['cur'], true), (string) $row['device']); }
function lw_draft_set(array $row, array $layout, bool $history = true): void {
    $dev = (string) $row['device']; $layout = lw_normalize($layout, $dev); $cur = lw_draft_layout($row); if (json_encode($cur) === json_encode($layout)) { return; }
    $undo = json_decode((string) $row['hist_undo'], true) ?: []; if ($history) { $undo[] = $cur; $undo = array_slice($undo, -30); }
    run('UPDATE {c_layout_drafts} SET cur=?, hist_undo=?, hist_redo=? WHERE id=?', [json_encode($layout), json_encode($undo), '[]', $row['id']]);
}
function lw_draft_undo(array $row): void { $u = json_decode((string) $row['hist_undo'], true) ?: []; if (!$u) { throw new UserError('Nothing to undo.'); } $prev = array_pop($u); $rd = json_decode((string) $row['hist_redo'], true) ?: []; $rd[] = lw_draft_layout($row); run('UPDATE {c_layout_drafts} SET cur=?, hist_undo=?, hist_redo=? WHERE id=?', [json_encode($prev), json_encode($u), json_encode(array_slice($rd, -30)), $row['id']]); }
function lw_draft_redo(array $row): void { $rd = json_decode((string) $row['hist_redo'], true) ?: []; if (!$rd) { throw new UserError('Nothing to redo.'); } $n = array_pop($rd); $u = json_decode((string) $row['hist_undo'], true) ?: []; $u[] = lw_draft_layout($row); run('UPDATE {c_layout_drafts} SET cur=?, hist_undo=?, hist_redo=? WHERE id=?', [json_encode($n), json_encode(array_slice($u, -30)), json_encode($rd), $row['id']]); }
function lw_draft_reset_to_live(array $row): void { run('UPDATE {c_layout_drafts} SET cur=?, hist_undo=?, hist_redo=?, brief=?, msgs=? WHERE id=?', [json_encode(lw_get((int) $row['user_id'], (string) $row['device'], true)), '[]', '[]', '', '[]', $row['id']]); }
/** What changed between two layouts, in plain words, computed from the data (not from anything the model said). */
function lw_diff(array $a, array $b, string $device): array {
    $cat = lw_catalog(); $out = []; $ia = []; foreach ($a['widgets'] as $w) { $ia[$w['id']] = $w; } $ib = []; foreach ($b['widgets'] as $w) { $ib[$w['id']] = $w; }
    foreach ($ib as $id => $w) { $o = $ia[$id] ?? null; $t = $cat[$id][0]; if (!$o) { continue; }
        if ($o['visible'] !== $w['visible']) { $out[] = ($w['visible'] ? 'Now showing' : 'Now hidden') . ': ' . $t; } if ($w['visible'] && $o['size'] !== $w['size'] && $device === 'desktop') { $out[] = $t . ': size ' . $o['size'] . ' → ' . $w['size']; } if ($o['variant'] !== $w['variant']) { $out[] = $t . ': ' . $o['variant'] . ' → ' . $w['variant'] . ' style'; } if ($o['zone'] !== $w['zone'] && $device === 'desktop') { $out[] = $t . ': moved to the ' . $w['zone'] . ' column'; } }
    $oa = array_values(array_filter(array_column(array_filter($a['widgets'], fn($w) => $w['visible']), 'id'), fn($id) => ($ib[$id]['visible'] ?? false))); $ob = array_values(array_filter(array_column(array_filter($b['widgets'], fn($w) => $w['visible']), 'id'), fn($id) => ($ia[$id]['visible'] ?? false)));
    if ($oa !== $ob) { $out[] = 'New order: ' . implode(', ', array_map(fn($id) => $cat[$id][0], array_slice($ob, 0, 6))) . (count($ob) > 6 ? '…' : ''); }
    foreach (['density' => 'Density', 'contrast' => 'Contrast', 'simple' => 'Simple words', 'big_buttons' => 'Big buttons'] as $k => $l) { if ($a['theme'][$k] !== $b['theme'][$k]) { $v = $b['theme'][$k]; $out[] = $l . ': ' . (is_bool($v) ? ($v ? 'on' : 'off') : $v); } }
    if ($a['theme']['font_scale'] !== $b['theme']['font_scale']) { $out[] = 'Text size: ' . round($b['theme']['font_scale'] * 100) . '%'; } return $out;
}
function lw_state(array $u, array $row, array $extra = []): array {
    $uid = (int) $u['id']; $dev = (string) $row['device']; $layout = lw_draft_layout($row); $live = lw_get($uid, $dev, true); $cat = lw_catalog(); $ctx = lw_ctx($u); $snap = lw_snapshot($layout, $ctx, $dev); $rows = [];
    foreach ($layout['widgets'] as $w) { $c = $cat[$w['id']]; $rows[] = ['id' => $w['id'], 'title' => $c[0], 'desc' => $c[1], 'visible' => $w['visible'], 'size' => $w['size'], 'variant' => $w['variant'], 'zone' => $w['zone'], 'sizes' => $c[2], 'variants' => $c[3], 'critical' => in_array($dev, $c[6], true), 'group' => $c[7]]; }
    $rev = substr(hash('sha256', (string) $row['cur'] . $row['updated_at']), 0, 8);
    return $extra + ['ok' => true, 'draft' => (int) $row['id'], 'device' => $dev, 'rev' => $rev, 'layout' => $layout, 'widgets' => $rows, 'dirty' => json_encode($layout) !== json_encode($live), 'is_default' => lw_is_default($layout, $dev), 'can_undo' => (json_decode((string) $row['hist_undo'], true) ?: []) !== [], 'can_redo' => (json_decode((string) $row['hist_redo'], true) ?: []) !== [],
        'snapshot' => ['order' => $snap['order'], 'warnings' => $snap['warnings'], 'visible_count' => $snap['visible_count'], 'hash' => $snap['hash']], 'preview' => url('/layout/preview', ['draft' => $row['id'], 'rev' => $rev]), 'wire' => url('/layout/wire.png', ['draft' => $row['id'], 'rev' => $rev]), 'wire_svg' => lw_wire_svg($layout, $dev),
        'backups' => lw_backups($uid, $dev), 'limit' => lw_limit(), 'optin' => site_setting('lw_optin_' . $uid, '') === '1', 'diff' => lw_diff($live, $layout, $dev)];
}

// ============================ API ============================
function layout_api(): never {
    [$u, $b] = c_json_guard(); $uid = (int) $u['id']; $a = (string) ($b['action'] ?? 'state');
    try {
        if (!lw_enabled($u)) { throw new UserError(feature_access($u, 'ai_layout')['reason'] ?: 'AI Layout is not available on your plan.'); }
        $dev = lw_device_param($b['device'] ?? null);
        if ($a === 'optin') { set_setting('lw_optin_' . $uid, !empty($b['on']) ? '1' : '0'); c_json(lw_state($u, lw_draft_open($uid, $dev))); }
        if ($a === 'state') { c_json(lw_state($u, lw_draft_open($uid, $dev))); }
        if ($a === 'reset_default') { lw_reset($uid, $dev); lw_draft_reset_to_live(lw_draft_open($uid, $dev)); c_json(lw_state($u, lw_draft_open($uid, $dev), ['message' => 'Back to the default home screen. Your previous version is saved in the backups.'])); }
        if ($a === 'restore') { $dev = lw_restore($uid, (int) ($b['id'] ?? 0)); lw_draft_reset_to_live(lw_draft_open($uid, $dev)); c_json(lw_state($u, lw_draft_open($uid, $dev), ['message' => 'Backup restored. What you had before is saved as a new backup.'])); }
        if ($a === 'backup_delete') { lw_backup_delete($uid, (int) ($b['id'] ?? 0)); c_json(lw_state($u, lw_draft_open($uid, $dev))); }
        if ($a === 'backup_now') { lw_backup($uid, $dev, mb_substr(trim((string) ($b['label'] ?? '')) ?: 'Saved by you', 0, 100), 'manual'); c_json(lw_state($u, lw_draft_open($uid, $dev), ['message' => 'Backup saved.'])); }
        $row = lw_draft_row($uid, (int) ($b['draft'] ?? 0)); $dev = (string) $row['device'];
        if ($a === 'ops') { [$new, $applied, $rej] = lw_apply_ops(lw_draft_layout($row), is_array($b['ops'] ?? null) ? $b['ops'] : [], $dev, $uid); lw_draft_set($row, $new); c_json(lw_state($u, lw_draft_row($uid, (int) $row['id']), ['applied' => $applied, 'rejected' => $rej])); }
        if ($a === 'undo') { lw_draft_undo($row); c_json(lw_state($u, lw_draft_row($uid, (int) $row['id']))); }
        if ($a === 'redo') { lw_draft_redo($row); c_json(lw_state($u, lw_draft_row($uid, (int) $row['id']))); }
        if ($a === 'discard') { lw_draft_reset_to_live($row); c_json(lw_state($u, lw_draft_row($uid, (int) $row['id']), ['message' => 'Draft discarded. Nothing was changed.'])); }
        if ($a === 'apply') { $layout = lw_draft_layout($row); $snap = lw_snapshot($layout, lw_ctx($u), $dev); lw_save($uid, $dev, $layout, 'Before applying changes', 'auto'); lw_draft_reset_to_live($row); c_json(lw_state($u, lw_draft_row($uid, (int) $row['id']), ['message' => 'Applied. Your previous home screen is in the backups.', 'applied_hash' => $snap['hash']])); }
        throw new UserError('Unknown request.');
    } catch (UserError $e) { c_json(['ok' => false, 'message' => $e->getMessage()], 200); } catch (Throwable $e) { log_error($e); c_json(['ok' => false, 'message' => 'Something went wrong.'], 500); }
}

// ============================ the assistant ============================
function lw_tools(): array {
    $ops = ['type' => 'array', 'description' => 'Operations, applied in order to the draft.', 'items' => ['type' => 'object', 'properties' => [
        'op' => ['type' => 'string', 'enum' => ['add', 'remove', 'move', 'resize', 'variant', 'theme', 'density', 'font_scale', 'profile', 'mirror', 'reset']], 'widget' => ['type' => 'string', 'description' => 'Widget id from list_widgets.'], 'size' => ['type' => 'string', 'enum' => array_keys(LW_SIZES)],
        'variant' => ['type' => 'string'], 'to' => ['type' => 'string', 'enum' => ['top', 'bottom', 'before', 'after', 'zone']], 'target' => ['type' => 'string', 'description' => 'Widget id for move before/after.'], 'zone' => ['type' => 'string', 'enum' => LW_ZONES],
        'density' => ['type' => 'string', 'enum' => ['comfortable', 'compact']], 'font_scale' => ['type' => 'number', 'description' => '0.9 to 1.5, 1 is normal'], 'contrast' => ['type' => 'string', 'enum' => ['normal', 'high']], 'simple' => ['type' => 'boolean'], 'big_buttons' => ['type' => 'boolean'],
        'name' => ['type' => 'string', 'enum' => ['ease', 'default']], 'from' => ['type' => 'string', 'enum' => LW_DEVICES], 'value' => ['type' => 'number']], 'required' => ['op']]];
    return [
        ['name' => 'list_widgets', 'description' => 'List every widget that can appear on this person\'s home screen, with its id, what it shows, the sizes and variants it supports.', 'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'get_layout', 'description' => 'The current DRAFT layout for the device being edited (order, sizes, variants, visibility, theme).', 'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'set_layout_ops', 'description' => 'Change the draft with validated operations. The reply is what the server really rendered afterwards (ordered widgets, their visible text, warnings) plus any rejected operations. Compare it with the request and fix what does not match.', 'input_schema' => ['type' => 'object', 'properties' => ['ops' => $ops], 'required' => ['ops']]],
        ['name' => 'preview_layout', 'description' => 'Render the current draft again and return the verified snapshot without changing anything.', 'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
    ];
}
function lw_system_prompt(array $u, string $device): string {
    return "You customise the home screen of a personal finance app for " . explode(' ', (string) $u['name'])[0] . ". You are editing the " . strtoupper($device) . " layout" . ($device === 'mobile' ? ' (phone: one column, widget sizes are ignored)' : ' (desktop: a main grid and a side column)') . ".\n"
        . "RULES\n- You can ONLY change the layout through the tools. Never write HTML, CSS or code, and never claim a change you did not make.\n- Call set_layout_ops with the smallest set of operations that does what was asked. Use widget ids from list_widgets.\n"
        . "- After set_layout_ops you receive the server's verified snapshot of what was really rendered. Read it. If it does not match the request (wrong order, hidden, warnings such as overflow or empty widgets), fix it with more operations or say plainly what could not be done.\n"
        . "- Critical widgets (quick add actions) cannot be removed. For people who find the app hard to use, use the 'ease' profile: large text, high contrast, simple words, big buttons, fewer widgets.\n"
        . "- A request about the other device (phone/desktop) is a different layout: use op mirror, or tell the person to switch device. Keep any text reply to one or two short sentences; the server shows the verified result itself.\nToday is " . today() . '.';
}
/** One metered model call for the layout assistant (mock aware). */
function lw_model_call(array $u, string $model, array $messages, string $system): array {
    $body = ['model' => $model, 'max_tokens' => 1800, 'system' => [['type' => 'text', 'text' => $system]], 'messages' => $messages, 'tools' => lw_tools()];
    $r = ai_mock() ? lw_mock_call($body) : ai_http($body); $in = (int) ($r['usage']['input_tokens'] ?? 0) + (int) ($r['usage']['cache_creation_input_tokens'] ?? 0) + (int) ($r['usage']['cache_read_input_tokens'] ?? 0); ai_record_usage($u, $model, $in, (int) ($r['usage']['output_tokens'] ?? 0)); return $r;
}
function lw_tool_result(array $u, array &$row, string $name, array $in, array &$turn): array {
    $dev = (string) $row['device']; $uid = (int) $u['id']; $ctx = $turn['ctx'] ?? ($turn['ctx'] = lw_ctx($u));
    $snapOf = function () use (&$row, $dev, $uid, $ctx) { $row = lw_draft_row($uid, (int) $row['id']); return lw_snapshot(lw_draft_layout($row), $ctx, $dev); };
    return match ($name) {
        'list_widgets' => ['device' => $dev, 'widgets' => array_values(array_map(fn($id, $c) => ['id' => $id, 'title' => $c[0], 'shows' => $c[1], 'sizes' => $c[2], 'variants' => $c[3], 'default_zone' => $c[4], 'critical' => in_array($dev, $c[6], true)], array_keys(array_filter(lw_catalog(), fn($c) => in_array($dev, $c[5], true))), array_values(array_filter(lw_catalog(), fn($c) => in_array($dev, $c[5], true))))),
            'sizes_note' => 'full=12/12, two_thirds=8, half=6, third=4, quarter=3 (columns of 12, desktop main grid only)', 'theme_fields' => ['density' => 'comfortable|compact', 'font_scale' => '0.9-1.5', 'contrast' => 'normal|high', 'simple' => 'bool', 'big_buttons' => 'bool']],
        'get_layout', 'preview_layout' => ['device' => $dev, 'snapshot' => $snapOf()],
        'set_layout_ops' => (function () use (&$row, $u, $dev, $uid, $in, &$turn, $snapOf) {
            $ops = is_array($in['ops'] ?? null) ? $in['ops'] : []; [$new, $applied, $rej] = lw_apply_ops(lw_draft_layout($row), $ops, $dev, $uid); lw_draft_set($row, $new); $turn['ops'] = ($turn['ops'] ?? 0) + count($ops); $turn['rejected'] = array_merge($turn['rejected'] ?? [], $rej); $turn['applied'] = array_merge($turn['applied'] ?? [], $applied);
            $snap = $snapOf(); $turn['snap'] = $snap; return ['applied' => $applied, 'rejected' => $rej, 'snapshot' => $snap, 'instruction' => 'This is what was really rendered. Compare it with the request; fix mismatches with more operations or explain what is impossible.'];
        })(),
        default => ['error' => 'Unknown tool.'],
    };
}
/** The message people read, built from the data: what changed (diff), what the screen now shows (snapshot), what was refused, warnings. */
function lw_final_message(array $before, array $after, array $snap, array $turn, string $modelText, string $device): array {
    $cat = lw_catalog(); $diff = lw_diff($before, $after, $device); $lines = []; $rej = $turn['rejected'] ?? [];
    if (empty($turn['ops'])) { return ['kind' => 'note', 'text' => $modelText !== '' ? mb_substr($modelText, 0, 600) : 'I did not change anything. Tell me what you would like on your home screen, for example "put my bills first" or "make everything bigger and simpler".', 'changes' => [], 'warnings' => []]; }
    if ($diff) { $lines[] = 'Verified on the preview: ' . count($diff) . ' change' . (count($diff) === 1 ? '' : 's') . '.'; } else { $lines[] = 'Nothing is different from before' . ($rej ? ', because I could not apply what was asked.' : ' (it already looked like that).'); }
    $show = array_slice(array_map(fn($id) => $cat[$id][0], $snap['order']), 0, 8); $lines[] = 'Your ' . $device . ' home screen now shows, in order: ' . implode(', ', $show) . (count($snap['order']) > 8 ? ' and ' . (count($snap['order']) - 8) . ' more' : '') . '.';
    $rj = array_map(fn($r) => ($r['reason'] ?? ''), $rej); return ['kind' => 'result', 'text' => implode(' ', $lines), 'changes' => $diff, 'refused' => array_values(array_unique($rj)), 'warnings' => $snap['warnings']];
}
/** One user request on a draft. Emits events: status, step, snapshot (preview should reload), message, error, done. */
function lw_ai_turn(array $u, int $draftId, string $text, callable $emit): void {
    $uid = (int) $u['id']; $text = trim(mb_substr($text, 0, 800)); if ($text === '') { $emit(['type' => 'error', 'message' => 'Say what you would like to change.']); return; }
    if (!lw_enabled($u)) { $emit(['type' => 'error', 'code' => 'plan', 'message' => feature_access($u, 'ai_layout')['reason']]); return; }
    if ($g = ai_gate($u)) { $emit(['type' => 'error', 'code' => $g['code'], 'message' => $g['message']]); return; }
    try { $model = ai_pick_model($u, ''); } catch (UserError $e) { $emit(['type' => 'error', 'code' => 'model', 'message' => $e->getMessage()]); return; }
    $row = lw_draft_row($uid, $draftId); $dev = (string) $row['device']; $before = lw_draft_layout($row); $turn = []; $hist = json_decode((string) $row['msgs'], true) ?: [];
    $prior = $hist ? "Earlier requests in this session (already applied to the draft): " . implode(' | ', array_map(fn($h) => $h['text'], array_slice($hist, -4))) . "\n" : ''; $messages = [['role' => 'user', 'content' => $prior . 'Request: ' . $text]]; $modelText = '';
    try {
        for ($n = 0; $n < LW_AI_MAX_STEPS; $n++) {
            if (ai_should_stop()) { break; } $emit(['type' => 'status', 'text' => $n === 0 ? 'Thinking about your home screen…' : 'Checking the result…']); $r = lw_model_call($u, $model, $messages, lw_system_prompt($u, $dev)); $content = ai_fix_content($r['content'] ?? []); $uses = [];
            foreach ($content as $b) { if (($b['type'] ?? '') === 'text') { $modelText = trim((string) $b['text']); } elseif (($b['type'] ?? '') === 'tool_use') { $uses[] = $b; } } if (($r['stop_reason'] ?? '') !== 'tool_use' || !$uses) { break; }
            $messages[] = ['role' => 'assistant', 'content' => $content]; $results = [];
            foreach ($uses as $k => $tu) {
                $label = ['list_widgets' => 'Looking at the available widgets', 'get_layout' => 'Reading the current layout', 'set_layout_ops' => 'Changing the draft', 'preview_layout' => 'Rendering the preview'][$tu['name']] ?? 'Working'; $sid = "s{$n}_$k"; $emit(['type' => 'step', 'id' => $sid, 'label' => $label, 'status' => 'running']);
                try { $res = lw_tool_result($u, $row, (string) $tu['name'], is_array($tu['input'] ?? null) ? $tu['input'] : [], $turn); $err = false; } catch (UserError $e) { $res = ['error' => $e->getMessage()]; $err = true; } catch (Throwable $e) { log_error($e); $res = ['error' => 'That tool failed.']; $err = true; }
                $emit(['type' => 'step', 'id' => $sid, 'label' => $label . ($tu['name'] === 'set_layout_ops' && !empty($res['rejected']) ? ' (' . count($res['rejected']) . ' refused, checking)' : ''), 'status' => $err ? 'failed' : 'done']);
                if ($tu['name'] === 'set_layout_ops' && !$err) { $row = lw_draft_row($uid, (int) $row['id']); $emit(['type' => 'snapshot', 'state' => lw_state($u, $row)]); }
                $results[] = ['type' => 'tool_result', 'tool_use_id' => $tu['id'], 'content' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 'is_error' => $err];
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }
    } catch (UserError $e) { $emit(['type' => 'error', 'code' => 'service', 'message' => $e->getMessage()]); }
    $row = lw_draft_row($uid, (int) $row['id']); $after = lw_draft_layout($row); $ctx = $turn['ctx'] ?? lw_ctx($u); $snap = lw_snapshot($after, $ctx, $dev); // the final, independent verification
    $msg = lw_final_message($before, $after, $snap, $turn, $modelText, $dev); $hist[] = ['text' => $text]; run('UPDATE {c_layout_drafts} SET msgs=?, snap=?, brief=? WHERE id=?', [json_encode(array_slice($hist, -10)), json_encode($snap), mb_substr($text, 0, 500), $row['id']]);
    $emit(['type' => 'message', 'message' => $msg, 'state' => lw_state($u, lw_draft_row($uid, (int) $row['id']))]); $emit(['type' => 'done']);
}
function layout_ai_http(): never {
    [$u, $b] = c_json_guard(true); set_time_limit(150); ignore_user_abort(true); while (ob_get_level() > 0) { ob_end_flush(); } $emit = function (array $ev) { echo json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n"; flush(); };
    try {
        $uid = (int) $u['id']; $row = lw_draft_row($uid, (int) ($b['draft'] ?? 0)); $hint = lw_device_param($b['device'] ?? $row['device']); if ($hint !== $row['device']) { $row = lw_draft_open($uid, $hint); $emit(['type' => 'status', 'text' => 'Editing your ' . $hint . ' layout (that is the device you are on).']); }
        lw_ai_turn($u, (int) $row['id'], (string) ($b['message'] ?? ''), $emit);
    } catch (UserError $e) { $emit(['type' => 'error', 'message' => $e->getMessage()]); } catch (Throwable $e) { log_error($e); $emit(['type' => 'error', 'message' => 'Something went wrong. Please try again.']); } exit;
}

// ============================ mock model (MFNC_AI_MOCK=1) ============================
/** Deterministic stand-in: reads the request keywords, proposes operations, and reacts to the server's rejections (so the verify loop is testable). */
function lw_mock_call(array $body): array {
    ai_mock_guard($body);
    $msgs = $body['messages']; $first = $msgs[0]['content']; $t = mb_strtolower(is_string($first) ? (str_contains($first, 'Request: ') ? substr($first, (int) strrpos($first, 'Request: ') + 9) : $first) : ''); $rounds = (int) floor((count($msgs) - 1) / 2); $last = end($msgs); $results = [];
    if (is_array($last['content'] ?? null)) { foreach ($last['content'] as $b) { if (($b['type'] ?? '') === 'tool_result') { $results[] = json_decode((string) $b['content'], true); } } }
    $tu = fn($n, $in) => ['type' => 'tool_use', 'id' => 'toolu_' . bin2hex(random_bytes(6)), 'name' => $n, 'input' => $in]; $out = fn($c, $s) => ['id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'], 'content' => $c, 'stop_reason' => $s, 'usage' => ['input_tokens' => (int) ceil(strlen(json_encode($body)) / 4), 'output_tokens' => 60]];
    $names = ['tip' => 'tip', 'health' => 'health', 'trend' => 'trend', 'cost' => 'cost', 'goal' => 'goals', 'milestone' => 'milestones', 'history' => 'history', 'transaction' => 'history', 'advisor' => 'advisor', 'budget' => 'limit', 'bill' => 'quick_payment', 'account' => 'accounts', 'balance' => 'balance', 'obligation' => 'obligations'];
    if ($rounds >= 1) { // second round: react to what the server rendered
        $res = $results[0] ?? []; if (!empty($res['rejected']) && str_contains($t, 'flawmock') && empty($GLOBALS['__lw_mock_fixed'])) { $fix = []; foreach ($res['rejected'] as $r) { $o = $r['op'] ?? []; if (($o['widget'] ?? '') === 'nonexistent') { $o['widget'] = 'goals'; $fix[] = $o; } } if ($fix) { $GLOBALS['__lw_mock_fixed'] = 1; return $out([$tu('set_layout_ops', ['ops' => $fix])], 'tool_use'); } }
        return $out([['type' => 'text', 'text' => 'Done. (mock)']], 'end_turn');
    }
    $ops = [];
    if (preg_match('/\b(ease|simple|simpler|elderly|easy|easier|my mother|hard to read)\b/', $t) && !str_contains($t, 'simple widget')) { $ops[] = ['op' => 'profile', 'name' => 'ease']; }
    if (preg_match('/(bigger|larger|large) (text|font|writing)|text (bigger|larger)|font/', $t) && !$ops) { $ops[] = ['op' => 'font_scale', 'value' => 1.25]; }
    if (str_contains($t, 'compact')) { $ops[] = ['op' => 'density', 'value' => 'compact']; } if (str_contains($t, 'comfortable') || str_contains($t, 'roomy')) { $ops[] = ['op' => 'density', 'value' => 'comfortable']; }
    if (str_contains($t, 'high contrast')) { $ops[] = ['op' => 'theme', 'contrast' => 'high']; } if (str_contains($t, 'big button')) { $ops[] = ['op' => 'theme', 'big_buttons' => true]; }
    if (preg_match('/\b(reset|default|original)\b/', $t)) { $ops[] = ['op' => 'profile', 'name' => 'default']; } if (preg_match('/same as (my )?(desktop|computer)|mirror.*desktop|copy.*desktop/', $t)) { $ops[] = ['op' => 'mirror', 'from' => 'desktop']; } elseif (preg_match('/same as (my )?(phone|mobile)|mirror.*(phone|mobile)|copy.*(phone|mobile)/', $t)) { $ops[] = ['op' => 'mirror', 'from' => 'mobile']; }
    foreach ($names as $kw => $id) { if (!preg_match('/\b' . $kw . '/', $t)) { continue; }
        if (preg_match('/\b(hide|remove|drop|without|get rid of)\b[^.]*' . $kw . '/', $t)) { $ops[] = ['op' => 'remove', 'widget' => $id]; }
        elseif (preg_match('/\b(bigger|larger|biggest|enlarge|wider)\b[^.]*' . $kw . '|' . $kw . '[^.]*\b(bigger|larger|wider)\b/', $t)) { $ops[] = ['op' => 'add', 'widget' => $id, 'zone' => 'main', 'size' => 'full']; $ops[] = ['op' => 'move', 'widget' => $id, 'to' => 'top', 'zone' => 'main']; }
        elseif (preg_match('/\b(first|top|up front|start)\b[^.]*' . $kw . '|' . $kw . '[^.]*\b(first|top)\b/', $t)) { $ops[] = ['op' => 'add', 'widget' => $id, 'zone' => 'main']; $ops[] = ['op' => 'move', 'widget' => $id, 'to' => 'top', 'zone' => 'main']; }
        elseif (preg_match('/\b(show|add|put|include|want)\b[^.]*' . $kw . '/', $t)) { $ops[] = ['op' => 'add', 'widget' => $id]; } }
    if (str_contains($t, 'flawmock')) { array_unshift($ops, ['op' => 'resize', 'widget' => 'nonexistent', 'size' => 'half']); }
    if (!$ops) { return $out([['type' => 'text', 'text' => 'I can reorder, hide or resize widgets, change text size and density, or switch on an easy-to-use profile. What would you like? (mock)']], 'end_turn'); }
    return $out([['type' => 'text', 'text' => 'Applying that to the draft.'], $tu('set_layout_ops', ['ops' => $ops])], 'tool_use');
}

// ============================ pages ============================
function lw_gate_page(array $u): ?string { $fa = feature_access($u, 'ai_layout'); return $fa['ok'] ? null : $fa['reason']; }
function page_layout(): void {
    $u = require_login(); $s = snap(); layout_start('Customize home', 'dashboard', $s);
    page_header('Customize your home screen', 'Arrange the widgets, text size and simplicity for this device. Changes are saved as a draft until you press Apply.', '<span class="badge-beta">Beta</span><a class="btn btn-sm" href="' . e(url('/')) . '">Back to home</a>');
    if ($g = lw_gate_page($u)) { echo empty_state('AI Layout is a plugin', e($g), '<a class="btn btn-primary" href="' . e(url('/plugins') . '#plug-ai_layout') . '">See plugins</a>', 'layers'); layout_end(); return; }
    $aiOk = ai_gate($u, false) === null; $aiWhy = $aiOk ? '' : (ai_gate($u, false)['message'] ?? '');
    $data = ['csrf' => csrf_token(), 'endpoints' => ['api' => url('/layout/api'), 'ai' => url('/layout/ai')], 'ai' => $aiOk, 'ai_why' => $aiWhy, 'simple' => LW_SIMPLE ? true : false, 'suggestions' => ['Put my bills first', 'Make the text bigger and simpler', 'Hide the tips and charts', 'Show my goals bigger', 'Make it easy for my mother to use', 'Make it compact'], 'sizes' => array_keys(LW_SIZES), 'home' => url('/')];
    echo '<link rel="stylesheet" href="' . e(asset('c.css')) . '"><div class="lw-editor" data-lw-editor><script type="application/json" id="lw-data">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script><noscript><p class="text-sm text-mute">This page needs JavaScript.</p></noscript></div><script src="' . e(asset('layout.js')) . '" defer></script>'; layout_end();
}
function page_layout_preview(): void {
    $u = require_login(); if (!lw_enabled($u)) { render_error(403, 'Not available', 'AI Layout is not on your plan.'); return; } $row = one('SELECT * FROM {c_layout_drafts} WHERE id=? AND user_id=?', [(int) ($_GET['draft'] ?? 0), $u['id']]); if (!$row) { render_error(404, 'Not found', 'That draft is gone.'); return; }
    $dev = (string) $row['device']; $layout = lw_draft_layout($row); $ctx = lw_ctx($u); [$cls, $css] = lw_theme_attrs($layout['theme']); c_hdr('X-Frame-Options: SAMEORIGIN'); c_hdr("Content-Security-Policy: frame-ancestors 'self'"); c_hdr('Content-Type: text/html; charset=utf-8'); c_hdr('Cache-Control: no-store');
    echo '<!doctype html><html lang="en"' . (!empty($_GET['dark']) ? ' class="dark"' : '') . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Preview</title><meta name="color-scheme" content="light dark">'
        . '<link rel="stylesheet" href="' . e(asset('app.css')) . '"><link rel="stylesheet" href="' . e(asset('ext.css')) . '"><link rel="stylesheet" href="' . e(asset('polish.css')) . '"><link rel="stylesheet" href="' . e(asset('c.css')) . '"><style>' . $css . 'body{overflow-x:hidden}.splash{display:none!important}</style></head><body class="bg-page text-ink antialiased" data-preview="1" data-layout-hash="' . e(lw_snapshot($layout, $ctx, $dev)['hash']) . '"><main class="' . ($dev === 'mobile' ? 'px-4' : 'px-8') . ' py-5"><div class="' . e($cls) . '" data-lw-device="' . e($dev) . '">' . lw_render_layout($layout, $ctx, $dev) . '</div></main></body></html>';
}
function layout_wire_png(): void {
    $u = require_login(); $row = one('SELECT * FROM {c_layout_drafts} WHERE id=? AND user_id=?', [(int) ($_GET['draft'] ?? 0), $u['id']]); if (!$row) { c_status(404); return; } c_hdr('Cache-Control: private, no-store'); c_hdr('X-Content-Type-Options: nosniff');
    // PNG only where GD + FreeType can really draw it; everywhere else the same URL answers with the SVG (an <img> shows either), so there is never a broken image
    try { if (lw_png_ok() && !isset($_GET['svg']) && !str_ends_with(request_path(), '.svg')) { $png = lw_wire_png(lw_draft_layout($row), (string) $row['device']); if ($png !== '') { c_hdr('Content-Type: image/png'); echo $png; return; } } } catch (Throwable $e) { log_error($e); }
    c_hdr('Content-Type: image/svg+xml; charset=utf-8'); c_hdr("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'"); echo lw_wire_svg(lw_draft_layout($row), (string) $row['device']);
}
