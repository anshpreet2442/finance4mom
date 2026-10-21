<?php
declare(strict_types=1);
/**
 * AI Layout (H1): the home screen as data.
 *  - a layout is JSON {v, theme{density,font_scale,contrast,simple,big_buttons}, widgets[{id,size,variant,visible,zone,group?,md_full?}]}, one per person per device (desktop / mobile)
 *  - everything that changes a layout goes through lw_apply_ops(): a whitelist of operations, each validated; nothing else (no HTML) can reach the page
 *  - every save first stores a backup snapshot (list / restore / delete / limit)
 *  - lw_render() draws the real dashboard widgets (same HTML the original page produced) and lw_snapshot() describes what was really rendered, for the AI's verify loop
 * The default layouts reproduce the original dashboard exactly.
 */
require_once __DIR__ . '/layout_widgets.php';
const LW_SCHEMA = 1;
const LW_SIZES = ['full' => 12, 'two_thirds' => 8, 'half' => 6, 'third' => 4, 'quarter' => 3];
const LW_DEVICES = ['desktop', 'mobile'];
const LW_ZONES = ['main', 'side'];

/** type => [title, description, sizes, variants (first = default), default zone, devices, critical on, group, decor (hidden by the ease profile)] */
function lw_catalog(): array {
    $all = array_keys(LW_SIZES);
    return [
        'm_actions' => ['Quick add buttons', 'Big buttons to add income, an expense or a transfer (phone home screen).', ['full'], ['default'], 'main', ['mobile'], ['mobile'], 'msheet', false],
        'm_recent' => ['Recent transactions', 'Your last five transactions (phone home screen).', ['full'], ['default'], 'main', ['mobile'], [], 'msheet', false],
        'balance' => ['Balance overview', 'Total balance, income, expenses, savings and a six-month chart.', $all, ['full', 'compact', 'minimal'], 'main', LW_DEVICES, [], null, false],
        'glance' => ['At a glance', 'Four tiles: upcoming bills, outstanding payments, total savings, income expected.', $all, ['default'], 'main', LW_DEVICES, [], null, false],
        'limit' => ['Monthly spending limit', 'How much of this month\'s budget is used (budget status).', $all, ['default'], 'main', LW_DEVICES, [], null, false],
        'tip' => ['Tip of the day', 'The most useful insight about your money right now.', $all, ['default'], 'main', LW_DEVICES, [], null, true],
        'cost' => ['Cost analysis', 'Where this month\'s spending went (spending chart).', $all, ['default'], 'main', LW_DEVICES, [], null, true],
        'health' => ['Financial health', 'A gauge of how much of your income you save.', $all, ['default'], 'main', LW_DEVICES, [], null, true],
        'goals' => ['Goal tracker', 'Progress bars for your savings goals.', $all, ['default'], 'main', LW_DEVICES, [], null, false],
        'trend' => ['Monthly savings trend', 'Savings over six months as a line or bars.', $all, ['line', 'bars'], 'main', LW_DEVICES, [], null, true],
        'bva' => ['Budget vs actual', 'Top categories: budget against spending.', $all, ['default'], 'main', LW_DEVICES, [], null, true],
        'obligations' => ['Upcoming obligations', 'Unpaid bills for the next six months as a chart.', $all, ['default'], 'main', LW_DEVICES, [], null, true],
        'accounts' => ['My accounts', 'Total balance card and each account.', $all, ['default'], 'side', ['desktop', 'mobile'], [], 'acct', false],
        'quick_actions' => ['Quick actions', 'Add income, expense, transfer, pay a bill.', $all, ['default'], 'side', LW_DEVICES, ['desktop'], 'acct', false],
        'quick_payment' => ['Upcoming bills (quick payment)', 'The next bills due, one tap to open them.', $all, ['default'], 'side', LW_DEVICES, [], 'acct', false],
        'history' => ['Recent transactions (history)', 'Latest transactions with a link to all of them.', $all, ['default', 'compact'], 'side', LW_DEVICES, [], null, false],
        'milestones' => ['Savings milestones', 'Target dates of your goals and purchases.', $all, ['default'], 'side', LW_DEVICES, [], null, true],
        'advisor' => ['AI advisor shortcut', 'A card that opens your AI advisor.', $all, ['default'], 'main', LW_DEVICES, [], null, false],
    ];
}
function lw_ease_hidden(): array { return array_keys(array_filter(lw_catalog(), fn($c) => $c[8])); }
function lw_default_theme(): array { return ['density' => 'comfortable', 'font_scale' => 1.0, 'contrast' => 'normal', 'simple' => false, 'big_buttons' => false]; }
function lw_default_layout(string $device): array {
    $w = []; $add = function (string $id, string $size, string $zone, bool $vis = true, ?string $variant = null, array $x = []) use (&$w) { $c = lw_catalog()[$id]; $w[] = ['id' => $id, 'size' => $size, 'variant' => $variant ?? $c[3][0], 'visible' => $vis, 'zone' => $zone] + ($c[7] ? ['group' => $c[7]] : []) + $x; };
    if ($device === 'mobile') { $add('m_actions', 'full', 'main'); $add('m_recent', 'full', 'main'); }
    $add('balance', 'full', 'main'); $add('glance', 'full', 'main'); $add('limit', 'half', 'main'); $add('tip', 'half', 'main'); $add('cost', 'third', 'main'); $add('health', 'third', 'main'); $add('goals', 'third', 'main', true, null, ['md_full' => true]);
    $add('trend', 'third', 'main'); $add('bva', 'third', 'main'); $add('obligations', 'third', 'main', true, null, ['md_full' => true]); $add('advisor', 'half', 'main', false);
    $add('accounts', 'full', 'side'); $add('quick_actions', 'full', 'side'); $add('quick_payment', 'full', 'side'); $add('history', 'full', 'side'); $add('milestones', 'full', 'side');
    return ['v' => LW_SCHEMA, 'theme' => lw_default_theme(), 'widgets' => $w];
}
/** Cleans any layout (from the database or a client): unknown things dropped, values clamped, missing widgets added hidden, critical widgets forced visible. Never throws. */
function lw_normalize($l, string $device): array {
    $cat = lw_catalog(); $def = lw_default_layout($device); $l = is_array($l) ? $l : []; $th = is_array($l['theme'] ?? null) ? $l['theme'] : []; $dt = lw_default_theme();
    $theme = ['density' => in_array($th['density'] ?? '', ['comfortable', 'compact'], true) ? $th['density'] : $dt['density'], 'font_scale' => round(max(0.9, min(1.5, (float) ($th['font_scale'] ?? 1.0))), 2), 'contrast' => ($th['contrast'] ?? '') === 'high' ? 'high' : 'normal', 'simple' => !empty($th['simple']), 'big_buttons' => !empty($th['big_buttons'])];
    $out = []; $seen = [];
    foreach (is_array($l['widgets'] ?? null) ? $l['widgets'] : [] as $w) {
        if (!is_array($w)) { continue; } $id = is_string($w['id'] ?? null) ? $w['id'] : ''; if (!isset($cat[$id]) || isset($seen[$id]) || !in_array($device, $cat[$id][5], true)) { continue; } $seen[$id] = 1; $c = $cat[$id];
        $item = ['id' => $id, 'size' => in_array($w['size'] ?? '', $c[2], true) ? $w['size'] : $c[2][0], 'variant' => in_array($w['variant'] ?? '', $c[3], true) ? $w['variant'] : $c[3][0], 'visible' => !empty($w['visible']), 'zone' => in_array($w['zone'] ?? '', LW_ZONES, true) ? $w['zone'] : $c[4]];
        if ($c[7]) { $item['group'] = $c[7]; } if (!empty($w['md_full']) && $item['size'] === 'third') { $item['md_full'] = true; } $out[] = $item;
    }
    foreach ($def['widgets'] as $w) { if (!isset($seen[$w['id']])) { $w['visible'] = false; $out[] = $w; } } // new widget types arrive hidden so old layouts never change by surprise
    foreach ($out as &$w) { if (in_array($device, $cat[$w['id']][6], true)) { $w['visible'] = true; } } unset($w);
    return ['v' => LW_SCHEMA, 'theme' => $theme, 'widgets' => $out];
}
function lw_is_default(array $l, string $device): bool { return json_encode(lw_normalize($l, $device)) === json_encode(lw_default_layout($device)); }

// ============================ storage, backups ============================
function lw_enabled(?array $u): bool { return $u && feature_access($u, 'ai_layout')['ok']; }
function lw_limit(): int { return max(3, min(100, (int) site_setting('layout_backup_limit', '20'))); }
/** The layout in force for this person and device. Without the plugin everyone sees the default. */
function lw_get(int $uid, string $device, bool $ignorePlugin = false): array {
    $r = one('SELECT data FROM {c_layouts} WHERE user_id=? AND device=?', [$uid, $device]); if (!$r) { return lw_default_layout($device); }
    if (!$ignorePlugin) { $u = one('SELECT id,role FROM {users} WHERE id=?', [$uid]); if (!lw_enabled($u)) { return lw_default_layout($device); } } return lw_normalize(json_decode((string) $r['data'], true), $device);
}
function lw_current_raw(int $uid, string $device): array { $r = one('SELECT data FROM {c_layouts} WHERE user_id=? AND device=?', [$uid, $device]); return $r ? lw_normalize(json_decode((string) $r['data'], true), $device) : lw_default_layout($device); }
function lw_backup(int $uid, string $device, string $label, string $source = 'auto'): int {
    $id = insert('INSERT INTO {c_layout_backups} (user_id,device,label,source,data) VALUES (?,?,?,?,?)', [$uid, $device, mb_substr($label, 0, 100), $source, json_encode(lw_current_raw($uid, $device))]); lw_prune($uid, $device); return $id;
}
/** Keeps the newest N backups per device; manual ones are only dropped after all automatic ones. */
function lw_prune(int $uid, int|string $device): void {
    $n = lw_limit(); $rows = all('SELECT id,source FROM {c_layout_backups} WHERE user_id=? AND device=? ORDER BY id DESC', [$uid, $device]); if (count($rows) <= $n) { return; }
    $drop = count($rows) - $n; foreach (array_reverse($rows) as $r) { if ($drop <= 0) { break; } if ($r['source'] === 'auto') { run('DELETE FROM {c_layout_backups} WHERE id=?', [$r['id']]); $drop--; } }
    foreach (array_reverse(all('SELECT id FROM {c_layout_backups} WHERE user_id=? AND device=? ORDER BY id DESC', [$uid, $device])) as $r) { if ($drop <= 0) { break; } run('DELETE FROM {c_layout_backups} WHERE id=?', [$r['id']]); $drop--; }
}
/** Saves a layout. A backup of what was there goes first, always. Returns the backup id. */
function lw_save(int $uid, string $device, array $layout, string $label = 'Before a change', string $source = 'auto'): int {
    $bid = lw_backup($uid, $device, $label, $source); $json = json_encode(lw_normalize($layout, $device));
    run('INSERT INTO {c_layouts} (user_id,device,ver,data) VALUES (?,?,1,?) ON DUPLICATE KEY UPDATE data=VALUES(data), ver=ver+1', [$uid, $device, $json]); return $bid;
}
function lw_backups(int $uid, string $device): array { return all('SELECT id,label,source,created_at FROM {c_layout_backups} WHERE user_id=? AND device=? ORDER BY id DESC', [$uid, $device]); }
function lw_restore(int $uid, int $backupId): string {
    $b = one('SELECT * FROM {c_layout_backups} WHERE id=? AND user_id=?', [$backupId, $uid]); if (!$b) { throw new UserError('That backup does not exist.'); } lw_save($uid, $b['device'], json_decode((string) $b['data'], true) ?: [], 'Before restoring #' . $backupId, 'auto'); return $b['device'];
}
function lw_backup_delete(int $uid, int $id): void { if (!run('DELETE FROM {c_layout_backups} WHERE id=? AND user_id=?', [$id, $uid])) { throw new UserError('That backup does not exist.'); } }
function lw_reset(int $uid, string $device): void { lw_save($uid, $device, lw_default_layout($device), 'Before reset to default', 'auto'); }

// ============================ operations ============================
function lw_widget_index(array $l, string $id): int { foreach ($l['widgets'] as $i => $w) { if ($w['id'] === $id) { return $i; } } return -1; }
/**
 * Applies a list of operations to a layout. Returns [layout, applied (human strings), rejected [[op, reason]]]. Invalid operations are skipped with a reason; valid ones still apply.
 * ops: add, remove, move, resize, variant, theme, density, font_scale, profile (ease|default), mirror (from desktop|mobile), reset
 */
function lw_apply_ops(array $layout, array $ops, string $device, ?int $uid = null): array {
    $cat = lw_catalog(); $layout = lw_normalize($layout, $device); $applied = []; $rej = []; $ops = array_slice($ops, 0, 40);
    $reject = function ($op, string $why) use (&$rej) { $rej[] = ['op' => is_array($op) ? $op : [], 'reason' => $why]; };
    foreach ($ops as $op) {
        if (!is_array($op) || !is_string($op['op'] ?? null)) { $reject($op, 'Each operation needs an "op" name.'); continue; } $name = $op['op']; $wid = is_string($op['widget'] ?? null) ? $op['widget'] : ''; $title = $cat[$wid][0] ?? $wid;
        if (in_array($name, ['add', 'remove', 'move', 'resize', 'variant'], true)) {
            if (!isset($cat[$wid])) { $reject($op, 'Unknown widget "' . mb_substr($wid, 0, 30) . '". Call list_widgets for the valid ids.'); continue; }
            if (!in_array($device, $cat[$wid][5], true)) { $reject($op, '"' . $wid . '" is only available on ' . implode(' / ', $cat[$wid][5]) . ' layouts.'); continue; }
        }
        $i = $wid !== '' ? lw_widget_index($layout, $wid) : -1; $w = $i >= 0 ? $layout['widgets'][$i] : null;
        switch ($name) {
            case 'add':
                if (!$w) { $reject($op, 'Widget missing from the layout.'); break; } $w['visible'] = true;
                if (isset($op['size'])) { if (!in_array($op['size'], $cat[$wid][2], true)) { $reject($op, 'Size "' . $op['size'] . '" is not allowed for ' . $wid . '. Allowed: ' . implode(', ', $cat[$wid][2]) . '.'); break; } $w['size'] = $op['size']; unset($w['md_full']); }
                if (isset($op['variant'])) { if (!in_array($op['variant'], $cat[$wid][3], true)) { $reject($op, 'Variant not allowed. Allowed: ' . implode(', ', $cat[$wid][3]) . '.'); break; } $w['variant'] = $op['variant']; }
                if (isset($op['zone'])) { if (!in_array($op['zone'], LW_ZONES, true)) { $reject($op, 'Zone must be main or side.'); break; } $w['zone'] = $op['zone']; }
                array_splice($layout['widgets'], $i, 1); $layout['widgets'][] = $w; $layout['widgets'] = lw_place($layout['widgets'], $wid, is_array($op) ? $op : []); $applied[] = 'Showed "' . $title . '"'; break;
            case 'remove':
                if (!$w) { $reject($op, 'Widget missing from the layout.'); break; } if (in_array($device, $cat[$wid][6], true)) { $reject($op, '"' . $title . '" is needed to add transactions and cannot be removed.'); break; }
                if (!$w['visible']) { $applied[] = '"' . $title . '" was already hidden'; break; } $vis = count(array_filter($layout['widgets'], fn($x) => $x['visible'])); if ($vis <= 1) { $reject($op, 'The home screen needs at least one widget.'); break; }
                $layout['widgets'][$i]['visible'] = false; $applied[] = 'Hid "' . $title . '"'; break;
            case 'move':
                if (!$w) { $reject($op, 'Widget missing from the layout.'); break; } $to = (string) ($op['to'] ?? '');
                if (!in_array($to, ['top', 'bottom', 'before', 'after', 'zone'], true)) { $reject($op, 'move.to must be top, bottom, before, after or zone.'); break; }
                if (($to === 'before' || $to === 'after') && (!isset($op['target']) || lw_widget_index($layout, (string) $op['target']) < 0 || $op['target'] === $wid)) { $reject($op, 'move before/after needs a valid "target" widget id.'); break; }
                if (isset($op['zone']) && !in_array($op['zone'], LW_ZONES, true)) { $reject($op, 'Zone must be main or side.'); break; }
                if (isset($op['zone'])) { $layout['widgets'][$i]['zone'] = $op['zone']; } if ($to !== 'zone') { $layout['widgets'] = lw_place($layout['widgets'], $wid, ['to' => $to, 'target' => $op['target'] ?? null, 'zone' => $op['zone'] ?? null]); } $applied[] = 'Moved "' . $title . '" ' . ($to === 'zone' ? 'to the ' . ($op['zone'] ?? '') . ' column' : $to . (isset($op['target']) ? ' "' . ($cat[$op['target']][0] ?? $op['target']) . '"' : '')); break;
            case 'resize':
                if (!$w) { $reject($op, 'Widget missing from the layout.'); break; } $sz = (string) ($op['size'] ?? ''); if (!in_array($sz, $cat[$wid][2], true)) { $reject($op, 'Size must be one of: ' . implode(', ', $cat[$wid][2]) . '.'); break; }
                $layout['widgets'][$i]['size'] = $sz; unset($layout['widgets'][$i]['md_full']); $applied[] = 'Resized "' . $title . '" to ' . $sz . ($device === 'mobile' ? ' (phones always show widgets full width)' : ''); break;
            case 'variant':
                $v = (string) ($op['variant'] ?? ''); if (!$w || !in_array($v, $cat[$wid][3], true)) { $reject($op, 'Variant for ' . $wid . ' must be one of: ' . implode(', ', $cat[$wid][3]) . '.'); break; } $layout['widgets'][$i]['variant'] = $v; $applied[] = 'Set "' . $title . '" to the ' . $v . ' variant'; break;
            case 'theme': case 'density': case 'font_scale':
                $t = $name === 'theme' ? $op : [$name => $op['value'] ?? ($op[$name] ?? null)]; $ch = [];
                if (array_key_exists('density', $t)) { if (!in_array($t['density'], ['comfortable', 'compact'], true)) { $reject($op, 'density must be comfortable or compact.'); continue 2; } $layout['theme']['density'] = $t['density']; $ch[] = 'density ' . $t['density']; }
                if (array_key_exists('font_scale', $t)) { if (!is_numeric($t['font_scale']) || (float) $t['font_scale'] < 0.9 || (float) $t['font_scale'] > 1.5) { $reject($op, 'font_scale must be a number from 0.9 to 1.5.'); continue 2; } $layout['theme']['font_scale'] = round((float) $t['font_scale'], 2); $ch[] = 'text size ' . round((float) $t['font_scale'] * 100) . '%'; }
                if (array_key_exists('contrast', $t)) { if (!in_array($t['contrast'], ['normal', 'high'], true)) { $reject($op, 'contrast must be normal or high.'); continue 2; } $layout['theme']['contrast'] = $t['contrast']; $ch[] = 'contrast ' . $t['contrast']; }
                if (array_key_exists('simple', $t)) { $layout['theme']['simple'] = (bool) $t['simple']; $ch[] = 'simple language ' . ($t['simple'] ? 'on' : 'off'); } if (array_key_exists('big_buttons', $t)) { $layout['theme']['big_buttons'] = (bool) $t['big_buttons']; $ch[] = 'big buttons ' . ($t['big_buttons'] ? 'on' : 'off'); }
                if (!$ch) { $reject($op, 'Nothing to change: give density, font_scale, contrast, simple or big_buttons.'); break; } $applied[] = 'Display: ' . implode(', ', $ch); break;
            case 'profile':
                $p = (string) ($op['name'] ?? ''); if ($p === 'default') { $layout = lw_default_layout($device); $applied[] = 'Restored the default home screen'; break; }
                if ($p !== 'ease') { $reject($op, 'profile must be "ease" or "default".'); break; }
                $layout['theme'] = ['density' => 'comfortable', 'font_scale' => 1.25, 'contrast' => 'high', 'simple' => true, 'big_buttons' => true]; foreach ($layout['widgets'] as &$x) { if (in_array($x['id'], lw_ease_hidden(), true) && !in_array($device, $cat[$x['id']][6], true)) { $x['visible'] = false; } if (in_array($x['size'], ['third', 'quarter', 'half'], true)) { $x['size'] = 'full'; unset($x['md_full']); } } unset($x);
                $applied[] = 'Ease of use profile: large text, high contrast, simple words, big buttons, fewer widgets'; break;
            case 'mirror':
                $from = (string) ($op['from'] ?? ''); if (!in_array($from, LW_DEVICES, true) || $from === $device) { $reject($op, 'mirror.from must be the other device (' . ($device === 'mobile' ? 'desktop' : 'mobile') . ').'); break; }
                if ($uid === null) { $reject($op, 'Mirroring needs a signed-in person.'); break; } $layout = lw_adapt(lw_get($uid, $from, true), $from, $device); $applied[] = 'Copied the ' . $from . ' layout and adapted it for ' . $device; break;
            case 'reset': $layout = lw_default_layout($device); $applied[] = 'Restored the default home screen'; break;
            default: $reject($op, 'Unknown operation "' . mb_substr($name, 0, 20) . '". Allowed: add, remove, move, resize, variant, theme, density, font_scale, profile, mirror, reset.');
        }
        $layout = lw_normalize($layout, $device);
    }
    return [$layout, $applied, $rej];
}
/** Puts a widget at top/bottom/before/after. */
function lw_place(array $widgets, string $id, array $op): array {
    $i = lw_widget_index(['widgets' => $widgets], $id); if ($i < 0) { return $widgets; } $w = $widgets[$i]; array_splice($widgets, $i, 1); $to = (string) ($op['to'] ?? 'keep'); if (!empty($op['zone']) && in_array($op['zone'], LW_ZONES, true)) { $w['zone'] = $op['zone']; }
    if ($to === 'top') { $first = 0; foreach ($widgets as $k => $x) { if ($x['zone'] === $w['zone']) { $first = $k; break; } } array_splice($widgets, $first, 0, [$w]); }
    elseif (($to === 'before' || $to === 'after') && !empty($op['target'])) { $t = lw_widget_index(['widgets' => $widgets], (string) $op['target']); if ($t >= 0) { if ($widgets[$t]['zone'] !== $w['zone'] && empty($op['zone'])) { $w['zone'] = $widgets[$t]['zone']; } array_splice($widgets, $t + ($to === 'after' ? 1 : 0), 0, [$w]); } else { $widgets[] = $w; } }
    else { $widgets[] = $w; } return $widgets;
}
/** Copies a layout to the other device: order and visibility are kept; sizes/zones are adapted. */
function lw_adapt(array $from, string $fromDev, string $toDev): array {
    $to = lw_default_layout($toDev); $to['theme'] = $from['theme']; $byId = []; foreach ($from['widgets'] as $w) { $byId[$w['id']] = $w; } $order = []; $zoneRank = ['main' => 0, 'side' => 1];
    $src = $from['widgets']; if ($fromDev === 'desktop') { usort($src, fn($a, $b) => [$zoneRank[$a['zone']], 0] <=> [$zoneRank[$b['zone']], 0]); } // phones read main first, then the side column
    foreach ($src as $w) { $order[] = $w['id']; } $res = [];
    foreach (lw_catalog() as $id => $c) { if (!in_array($toDev, $c[5], true)) { unset($byId[$id]); } }
    foreach ($to['widgets'] as $d) { $o = $byId[$d['id']] ?? null; if ($o) { $d['visible'] = $o['visible']; $d['variant'] = $o['variant']; if ($toDev === 'desktop') { $d['size'] = $o['size']; } } $res[$d['id']] = $d; }
    $final = []; if ($toDev === 'mobile') { foreach (['m_actions', 'm_recent'] as $id) { if (isset($res[$id])) { $final[] = $res[$id]; unset($res[$id]); } } } foreach ($order as $id) { if (isset($res[$id])) { $final[] = $res[$id]; unset($res[$id]); } } foreach ($res as $d) { $final[] = $d; }
    $to['widgets'] = $final; return lw_normalize($to, $toDev);
}

// ============================ rendering ============================
function lw_ctx(array $u, ?array $s = null): array {
    $s ??= load_snapshot((int) $u['id']); $month = month_of($s['today']); $d = dashboard_data($s, $month); $series = $d['series']; $prev = $series[count($series) - 2] ?? null;
    return ['u' => $u, 's' => $s, 'month' => $month, 'd' => $d, 'sum' => $d['sum'], 'series' => $series, 'prev' => $prev, 'insights' => array_values(array_filter(insights_for($s), fn($i) => $i['sev'] !== 'good'))];
}
const LW_SIMPLE = ['Balance overview' => 'Money you have', 'Income received' => 'Money in', 'Total expenses' => 'Money out', 'Saved this month' => 'Kept this month', 'Upcoming bills' => 'Bills to pay soon', 'Outstanding payments' => 'Late payments', 'Total savings' => 'Money saved', 'Income expected' => 'Money still to come',
    'Monthly spending limit' => 'Spending limit', 'Remaining monthly budget' => 'What you can still spend', 'Cost analysis' => 'Where your money goes', 'Spending overview' => 'This month', 'Financial health' => 'How you are doing', 'Goal tracker' => 'Savings goals', 'Budget vs actual' => 'Budget and spending',
    'Upcoming obligations' => 'Bills coming up', 'Unpaid, next 6 months' => 'Not paid yet', 'Transaction history' => 'Recent payments', 'Quick payment' => 'Pay a bill', 'Savings milestones' => 'Goal dates', 'Monthly savings trend' => 'Savings month by month', 'Total available balance' => 'Money you can use', 'CASH + BANK' => 'ALL YOUR MONEY'];
function lw_widget_html(string $id, array $w, array $ctx, bool $simple, bool $bare = false): string {
    $c = $ctx + ['variant' => $w['variant'] ?? 'default']; $fn = 'lw_w_' . $id;
    $html = match ($id) {
        'm_actions' => m_actions(),
        'm_recent' => '<div class="flex items-center justify-between px-6 pb-1 pt-6"><h2 class="text-xl font-semibold tracking-tight">Recent Transactions</h2><a href="' . e(url('/transactions')) . '" class="text-sm text-mute">View All</a></div><div class="px-6">' . tx_list($ctx['s'], array_slice($ctx['d']['recent'], 0, 5), 'No transactions yet. Tap Income or Expense to add your first.') . '</div>',
        'advisor' => '<section class="card p-6" aria-label="AI advisor"><div class="flex items-center gap-4"><span class="grid size-12 place-items-center rounded-2xl bg-soft">' . icon('pills', 'size-6') . '</span><div class="min-w-0 flex-1"><h2 class="text-lg font-semibold tracking-tight">Ask your advisor</h2><p class="text-sm text-mute">Questions about your money, answered with your own numbers.</p></div><a class="btn btn-dark btn-sm" href="' . e(url('/ai')) . '">Open</a></div></section>',
        default => function_exists($fn) ? $fn($c) : '',
    };
    if ($simple) { $html = strtr($html, LW_SIMPLE); } return $html;
}
/** Wraps consecutive widgets of one group in the group's chrome (a shared card, or the phone sheet). */
function lw_wrap(string $group, string $inner, string $firstId): string {
    return match ($group) { 'acct' => '<section class="card p-6" aria-label="Accounts" data-lw-group="acct">' . $inner . '</section>', 'msheet' => '<div class="m-sheet pb-6 -mx-4 mb-5" data-lw-group="msheet">' . $inner . '</div>', default => $inner };
}
/** The widgets of one layout as HTML (no page chrome). */
function lw_render_layout(array $layout, array $ctx, string $device): string {
    $simple = !empty($layout['theme']['simple']); $cat = lw_catalog(); $zones = ['main' => '', 'side' => ''];
    $vis = array_values(array_filter($layout['widgets'], fn($w) => $w['visible']));
    foreach (LW_ZONES as $z) {
        $items = array_values(array_filter($vis, fn($w) => $w['zone'] === $z)); $html = ''; $n = count($items);
        for ($k = 0; $k < $n; $k++) {
            $w = $items[$k]; $g = $cat[$w['id']][7] ?? null; $cls = '';
            if ($device === 'desktop' && $z === 'main') { $cls = 'lw-i lw-' . $w['size'] . (!empty($w['md_full']) ? ' lw-md-full' : ''); }
            if ($g) { $inner = ''; $j = $k; while ($j < $n && ($cat[$items[$j]['id']][7] ?? null) === $g) { $inner .= lw_widget_html($items[$j]['id'], $items[$j], $ctx, $simple, true); $j++; } $k = $j - 1; $piece = lw_wrap($g, $inner, $w['id']); }
            else { $piece = lw_widget_html($w['id'], $w, $ctx, $simple); }
            $html .= $cls !== '' ? '<div class="' . $cls . '" data-lw="' . e($w['id']) . '">' . $piece . '</div>' : '<div data-lw="' . e($w['id']) . '" class="' . ($device === 'desktop' && $z === 'main' ? '' : 'lw-s') . '">' . $piece . '</div>';
        }
        $zones[$z] = $html;
    }
    if ($device === 'mobile') { return '<div class="lw-stack">' . $zones['main'] . $zones['side'] . '</div>'; }
    return '<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_370px]"><div class="min-w-0 lw-grid">' . $zones['main'] . '</div><div class="min-w-0 space-y-5 lw-side">' . $zones['side'] . '</div></div>';
}
/** Theme classes and the root font-size rule for a layout. */
function lw_theme_attrs(array $t): array {
    $cls = 'lw lw-' . $t['density'] . ($t['contrast'] === 'high' ? ' lw-hc' : '') . ($t['font_scale'] >= 1.3 ? ' lw-xl' : '') . ($t['big_buttons'] ? ' lw-big' : '') . ($t['simple'] ? ' lw-simple' : ''); return [$cls, abs($t['font_scale'] - 1.0) > 0.001 ? 'html{font-size:' . round($t['font_scale'] * 100) . '%}' : ''];
}
/** The phone quick-add sheet that sits right under the hero while both layouts are the defaults (a customised mobile layout carries it as widgets). */
function lw_page_sheet(array $u, array $ctx): string {
    if (!(lw_is_default(lw_get((int) $u['id'], 'desktop'), 'desktop') && lw_is_default(lw_get((int) $u['id'], 'mobile'), 'mobile'))) { return ''; }
    return '<div class="m-sheet pb-6 lg:hidden">' . lw_widget_html('m_actions', [], $ctx, false) . lw_widget_html('m_recent', [], $ctx, false) . '</div>';
}
/** What the dashboard page shows below the greeting: default look when both layouts are untouched, otherwise one rendering per device. */
function lw_render_page(array $u, array $ctx): string {
    $uid = (int) $u['id']; $ld = lw_get($uid, 'desktop'); $lm = lw_get($uid, 'mobile');
    if (lw_is_default($ld, 'desktop') && lw_is_default($lm, 'mobile')) { return '<div class="lw">' . lw_render_layout($ld, $ctx, 'desktop') . '</div>'; } // exactly the original structure (phones get the sheet from the page itself)
    [$cd, $sd] = lw_theme_attrs($ld['theme']); [$cm, $sm] = lw_theme_attrs($lm['theme']);
    return '<style>@media (min-width:1024px){' . $sd . '}@media (max-width:1023.98px){' . $sm . '}</style><div class="max-lg:hidden ' . $cd . '">' . lw_render_layout($ld, $ctx, 'desktop') . '</div><div class="lg:hidden ' . $cm . '">' . lw_render_layout($lm, $ctx, 'mobile') . '</div>';
}

// ============================ snapshot (what was really rendered) ============================
function lw_text(string $html): string { return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''); }
/** Structural description of the rendered result: ordered widgets with size, visible text and warnings. Built from the same HTML the page would show. */
function lw_snapshot(array $layout, array $ctx, string $device): array {
    $cat = lw_catalog(); $simple = !empty($layout['theme']['simple']); $items = []; $warn = []; $grp = []; $t = $layout['theme']; $order = 0; $visCount = 0;
    foreach ($layout['widgets'] as $w) {
        $c = $cat[$w['id']]; $row = ['id' => $w['id'], 'title' => $c[0], 'visible' => (bool) $w['visible'], 'zone' => $w['zone'], 'size' => $device === 'mobile' ? 'full' : $w['size'], 'variant' => $w['variant']];
        if ($w['visible']) { $visCount++; $html = lw_widget_html($w['id'], $w, $ctx, $simple); $txt = lw_text($html); $row['order'] = ++$order; $row['text'] = mb_substr($txt, 0, 260); $row['chars'] = mb_strlen($txt); $row['has_chart'] = str_contains($html, '<svg') && (str_contains($html, 'role="img"') || str_contains($html, '<polyline') || str_contains($html, '<rect')); $ww = [];
            if (mb_strlen($txt) < 3 || preg_match('/(Create a savings goal|No bills due soon|Set a monthly budget|No transactions yet|Add target dates|Nothing to show|No data)/i', $txt)) { $ww[] = 'empty: this widget has no data to show yet'; }
            if ($device === 'desktop' && in_array($w['size'], ['third', 'quarter'], true) && $t['font_scale'] >= 1.25) { $ww[] = 'overflow risk: a narrow widget with large text may wrap badly'; }
            if ($device === 'desktop' && $w['zone'] === 'side' && $w['size'] !== 'full') { $ww[] = 'size is ignored in the side column'; } if ($device === 'mobile' && $w['size'] !== 'full' && !in_array($w['id'], ['m_actions', 'm_recent'], true)) { $ww[] = 'size is ignored on phones (full width)'; }
            $row['warnings'] = $ww; foreach ($ww as $x) { $grp[$x][] = $c[0]; } }
        $items[] = $row;
    }
    foreach ($grp as $msg => $titles) { $warn[] = implode(', ', array_slice($titles, 0, 4)) . (count($titles) > 4 ? ' and ' . (count($titles) - 4) . ' more' : '') . ': ' . $msg; }
    foreach ($cat as $id => $c) { if (in_array($device, $c[6], true)) { $w = $layout['widgets'][lw_widget_index($layout, $id)] ?? null; if (!$w || !$w['visible']) { $warn[] = 'critical widget "' . $c[0] . '" is missing'; } } }
    if ($t['font_scale'] >= 1.3 && $t['density'] === 'compact') { $warn[] = 'overflow risk: compact density with very large text looks cramped'; } if ($device === 'mobile' && $visCount > 14) { $warn[] = 'long page: ' . $visCount . ' widgets stacked on a phone'; } if ($visCount < 3) { $warn[] = 'very few widgets visible (' . $visCount . ')'; }
    $vis = array_values(array_filter($items, fn($r) => $r['visible'])); usort($vis, fn($a, $b) => $a['order'] <=> $b['order']);
    return ['device' => $device, 'theme' => $t, 'visible_count' => $visCount, 'order' => array_column($vis, 'id'), 'widgets' => $items, 'warnings' => array_values(array_unique($warn)), 'is_default' => lw_is_default($layout, $device), 'hash' => substr(hash('sha256', json_encode($layout)), 0, 12)];
}
/** Wireframe PNG of a layout (boxes with titles), used as a thumbnail and in the verify loop log. */
function lw_wire_png(array $layout, string $device): string {
    $W = $device === 'mobile' ? 300 : 760; $cat = lw_catalog(); $vis = array_values(array_filter($layout['widgets'], fn($w) => $w['visible'])); $rows = []; $im = null;
    $mk = function (int $h) use (&$im, $W) { $im = imagecreatetruecolor($W, $h); imagefilledrectangle($im, 0, 0, $W, $h, imagecolorallocate($im, 244, 241, 234)); };
    $boxes = []; $y = 10; $side = []; $pad = 8;
    if ($device === 'mobile') { foreach ($vis as $w) { $h = in_array($w['id'], ['balance', 'accounts', 'm_recent'], true) ? 70 : 46; $boxes[] = [10, $y, $W - 20, $h, $w['id']]; $y += $h + $pad; } $H = $y + 6; }
    else { $mainW = (int) (($W - 30) * 0.68); $x0 = 10; $col = 0; $rowH = 0; $rowY = $y; $sy = $y; $mainBottom = $y;
        foreach ($vis as $w) { if ($w['zone'] === 'side') { $h = $w['id'] === 'accounts' ? 90 : 52; $boxes[] = [$W - 10 - (int) (($W - 30) * 0.32), $sy, (int) (($W - 30) * 0.32), $h, $w['id']]; $sy += $h + $pad; continue; }
            $span = LW_SIZES[$w['size']]; $bw = (int) (($mainW - $pad * 11) / 12 * $span + $pad * ($span - 1)); if ($col + $span > 12) { $rowY += $rowH + $pad; $col = 0; $rowH = 0; } $h = in_array($w['id'], ['balance'], true) ? 90 : 52; $bx = $x0 + (int) (($mainW - $pad * 11) / 12 * $col + $pad * $col); $boxes[] = [$bx, $rowY, $bw, $h, $w['id']]; $col += $span; $rowH = max($rowH, $h); $mainBottom = $rowY + $rowH; }
        $H = max($mainBottom, $sy) + 10; }
    $mk(max(60, $H)); $ink = imagecolorallocate($im, 30, 40, 35); $card = imagecolorallocate($im, 255, 255, 255); $line = imagecolorallocate($im, 214, 218, 210); $acc = imagecolorallocate($im, 11, 107, 77); $f = pdf_font_file(false);
    foreach ($boxes as [$x, $yy, $w, $h, $id]) { imagefilledrectangle($im, $x, $yy, $x + $w, $yy + $h, $card); imagerectangle($im, $x, $yy, $x + $w, $yy + $h, $line); imagefilledrectangle($im, $x, $yy, $x + 3, $yy + $h, $acc); $t = mb_substr($cat[$id][0], 0, (int) ($w / 7));
        if ($f) { imagettftext($im, 8, 0, $x + 9, $yy + 17, $ink, $f, $t); } else { imagestring($im, 2, $x + 9, $yy + 5, preg_replace('/[^\x20-\x7e]/', '', $t), $ink); } }
    ob_start(); imagepng($im); return (string) ob_get_clean();
}
