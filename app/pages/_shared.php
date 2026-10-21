<?php
declare(strict_types=1);
/** Field builders and cards shared by several pages. */

function cur_sym(): string { return currency_symbol(); }

// ---------------- purchases (house / big / occasional) ----------------
function purchase_fields(string $kind, ?array $p = null): array {
    $f = [['name' => 'kind', 'type' => 'hidden', 'value' => $kind]];
    if ($p) { $f[] = ['name' => 'id', 'type' => 'hidden', 'value' => $p['id']]; }
    $f[] = ['name' => 'name', 'label' => $kind === 'occasional' ? 'Expense' : 'Item name', 'required' => true, 'value' => $p['name'] ?? '', 'placeholder' => $kind === 'house' ? 'e.g. Refrigerator' : ($kind === 'big' ? 'e.g. Family car' : 'e.g. Diwali shopping')];
    if ($kind === 'house') { $f[] = ['name' => 'group_label', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => array_combine($g = ['Furniture', 'Appliances', 'Electronics', 'Kitchen equipment', 'Home decoration', 'Other'], $g), 'value' => $p['group_label'] ?? 'Furniture']; }
    if ($kind !== 'occasional') { $f[] = ['name' => 'description', 'label' => 'Description (optional)', 'type' => 'textarea', 'value' => $p['description'] ?? '']; }
    $st = PURCHASE_STATUS; unset($st['purchased']);
    return array_merge($f, [
        ['name' => 'estimated', 'label' => 'Estimated cost', 'type' => 'money', 'required' => true, 'half' => true, 'value' => $p ? money_input((int) $p['estimated']) : ''],
        ['name' => 'target_date', 'label' => $kind === 'occasional' ? 'Expected date' : 'Desired purchase date', 'type' => 'date', 'half' => true, 'value' => $p['target_date'] ?? ''],
        ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'options' => priority_options(), 'half' => true, 'value' => $p['priority'] ?? 'medium'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => $st, 'half' => true, 'value' => ($p && $p['status'] !== 'purchased') ? $p['status'] : 'planning'],
        ['name' => 'monthly_setaside', 'label' => 'Set aside each month (optional)', 'type' => 'money', 'value' => !empty($p['monthly_setaside']) ? money_input((int) $p['monthly_setaside']) : '', 'hint' => 'Leave empty to use the amount needed to reach the date.'],
        ['name' => 'account_id', 'label' => 'Keep the savings in (optional)', 'type' => 'select', 'options' => isset($GLOBALS['__snap']) ? acct_options($GLOBALS['__snap']) : [], 'value' => $p['account_id'] ?? '', 'empty' => 'No particular account'],
    ]);
}
function contribute_fields(string $type, int $id, array $s, ?int $suggest, string $label = 'Amount (use − to take money back out)'): array {
    return [
        ['name' => 'target_type', 'type' => 'hidden', 'value' => $type], ['name' => 'target_id', 'type' => 'hidden', 'value' => $id],
        ['name' => 'amount', 'label' => $label, 'type' => 'money', 'required' => true, 'value' => $suggest ? money_input($suggest) : ''],
        ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'value' => $s['today'], 'half' => true],
        ['name' => 'account_id', 'label' => 'From account', 'type' => 'select', 'options' => acct_options($s), 'half' => true],
        ['name' => 'note', 'label' => 'Note (optional)'],
    ];
}
function purchase_card(array $s, array $p, string $kind): string {
    $done = $p['status'] === 'purchased'; $pct = $p['cost'] > 0 ? min($p['saved'] / $p['cost'] * 100, 100) : 0; $id = (int) $p['id'];
    $cats = []; foreach ($s['categories'] as $c) { if ($c['grp'] !== 'income') { $cats[$c['id']] = cat_path($s, (int) $c['id']); } }
    $defCat = category_id_by_name($s['uid'], $kind === 'house' ? 'Household Necessities' : ($kind === 'occasional' ? 'Miscellaneous' : 'Shopping'));
    $o = '<article class="card p-6"><div class="flex flex-wrap items-start justify-between gap-2"><div class="min-w-0"><h3 class="truncate text-lg font-semibold">' . e($p['name']) . '</h3><p class="text-xs text-mute">' . e(($p['group_label'] ? $p['group_label'] . ' · ' : '') . ($p['target_date'] ? 'by ' . date_label($p['target_date']) : 'No target date')) . '</p></div>'
        . '<div class="flex gap-1.5">' . priority_badge($p['priority']) . badge(PURCHASE_STATUS[$p['status']], in_array($p['status'], ['purchased', 'ready'], true) ? 'pos' : ($p['status'] === 'postponed' ? 'warn' : 'neutral')) . '</div></div>';
    if ($p['description']) { $o .= '<p class="mt-2 text-sm text-mute">' . e($p['description']) . '</p>'; }
    $o .= '<div class="mt-4">' . progress($done ? 100 : $pct, $done ? 'dark' : 'lime', $p['name'] . ' funded') . '</div>'
        . '<dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4"><div><dt class="text-xs text-mute">' . ($done ? 'Paid' : 'Estimated') . '</dt><dd class="num font-semibold">' . e(fmt($done ? (int) ($p['actual'] ?? $p['estimated']) : (int) $p['estimated'])) . '</dd></div>'
        . '<div><dt class="text-xs text-mute">Saved</dt><dd class="num font-semibold">' . e(fmt($done ? 0 : $p['saved'])) . '</dd></div><div><dt class="text-xs text-mute">Remaining</dt><dd class="num font-semibold">' . e(fmt($done ? 0 : $p['remaining'])) . '</dd></div>'
        . '<div><dt class="text-xs text-mute">Needed / month</dt><dd class="num font-semibold">' . ($done || $p['remaining'] === 0 ? '—' : ($p['needed'] !== null ? e(fmt($p['needed'])) : 'Set a date')) . '</dd></div></dl>'
        . '<div class="mt-4 flex flex-wrap gap-1.5 border-t border-line pt-4">';
    if (!$done) {
        $o .= dialog_form("pur-set-$id", 'Set aside money', 'Set aside for ' . $p['name'], 'contribute', contribute_fields('purchase', $id, $s, $p['needed'] ? min($p['needed'], $p['remaining']) : null), 'Save', 'btn btn-sm');
        $o .= dialog_form("pur-buy-$id", $kind === 'occasional' ? 'Mark as spent' : 'Mark as purchased', 'Record purchase: ' . $p['name'], 'purchase-buy', [
            ['name' => 'id', 'type' => 'hidden', 'value' => $id],
            ['name' => 'actual', 'label' => 'Price paid', 'type' => 'money', 'required' => true, 'value' => money_input((int) $p['estimated']), 'hint' => $p['saved'] > 0 ? fmt($p['saved']) . ' saved for this item is released and used. It is recorded as one expense, not twice.' : ''],
            ['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'value' => $s['today'], 'half' => true],
            ['name' => 'account_id', 'label' => 'Paid from', 'type' => 'select', 'required' => true, 'options' => acct_options($s), 'half' => true],
            ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options' => $cats, 'value' => $defCat]], 'Record purchase', 'btn btn-sm');
        $o .= dialog_form("pur-edit-$id", 'Edit', 'Edit ' . $p['name'], 'purchase-save', purchase_fields($kind, $p), 'Save changes', 'btn btn-ghost btn-sm');
    }
    $o .= confirm_btn('Delete', 'purchase-delete', ['id' => $id], 'Delete ' . $p['name'] . '?', $p['saved'] > 0 && !$done ? fmt($p['saved']) . ' set aside for this item will return to your spendable balance.' : 'This cannot be undone.');
    return $o . '</div></article>';
}

// ---------------- bills ----------------
function bill_fields(string $kind, array $s, ?array $b = null): array {
    $cats = []; foreach ($s['categories'] as $c) { if ($c['grp'] !== 'income' && !$c['parent_id']) { $cats[$c['id']] = $c['name']; } }
    $due = $s['today'];
    if ($b) { $due = $b['first_due']; foreach ($s['occurrences'] as $o) { if ((int) $o['bill_id'] === (int) $b['id'] && !$o['skipped'] && $o['paid'] < $o['amount']) { $due = $o['due_date']; break; } } }
    return [
        ['name' => 'kind', 'type' => 'hidden', 'value' => $kind], ...($b ? [['name' => 'id', 'type' => 'hidden', 'value' => $b['id']]] : []),
        ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $b['name'] ?? '', 'placeholder' => $kind === 'yearly' ? 'e.g. LIC premium' : 'e.g. Electricity'],
        ['name' => 'amount', 'label' => $kind === 'yearly' ? 'Expected amount' : 'Amount', 'type' => 'money', 'required' => true, 'half' => true, 'value' => $b ? money_input((int) $b['amount']) : ''],
        ['name' => 'frequency', 'label' => 'Repeats', 'type' => 'select', 'required' => true, 'options' => freq_options($kind !== 'yearly'), 'half' => true, 'value' => $b['frequency'] ?? ($kind === 'yearly' ? 'yearly' : 'monthly')],
        ['name' => 'due_date', 'label' => 'Next due date', 'type' => 'date', 'required' => true, 'half' => true, 'value' => $due, 'hint' => 'Later due dates follow this day of the month.'],
        ['name' => 'reminder_days', 'label' => 'Remind me (days before)', 'type' => 'number', 'min' => 0, 'max' => 90, 'half' => true, 'value' => $b['reminder_days'] ?? ($kind === 'yearly' ? 30 : 7)],
        ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options' => $cats, 'half' => true, 'value' => $b['category_id'] ?? category_id_by_name($s['uid'], $kind === 'yearly' ? 'Insurance' : 'Other Bills')],
        ['name' => 'account_id', 'label' => 'Usually paid from', 'type' => 'select', 'options' => acct_options($s), 'half' => true, 'value' => $b['account_id'] ?? ''],
        ['name' => 'notes', 'label' => 'Notes (optional)', 'type' => 'textarea', 'value' => $b['notes'] ?? ''],
        ['name' => 'active', 'label' => 'Active', 'type' => 'checkbox', 'value' => $b ? (bool) $b['active'] : true],
    ];
}
function pay_button(array $s, array $o, string $label = 'Pay', string $cls = 'btn btn-sm btn-primary'): string {
    $saved = bill_saved($s, (int) $o['bill_id']); $id = (int) $o['id'];
    return dialog_form("pay-$id", $label, 'Pay ' . $o['bill']['name'], 'bill-pay', array_merge([
        ['name' => 'occurrence_id', 'type' => 'hidden', 'value' => $id],
        ['name' => 'amount', 'label' => 'Amount (' . fmt($o['outstanding']) . ' due)', 'type' => 'money', 'required' => true, 'value' => money_input($o['outstanding']), 'hint' => 'Pay less to record a partial payment.'],
        ['name' => 'date', 'label' => 'Paid on', 'type' => 'date', 'required' => true, 'value' => $s['today'], 'half' => true],
        ['name' => 'account_id', 'label' => 'Paid from', 'type' => 'select', 'required' => true, 'options' => acct_options($s), 'half' => true, 'value' => $o['bill']['account_id'] ?? ''],
    ], $saved > 0 ? [['name' => 'from_fund', 'label' => 'Use the ' . fmt($saved) . ' I set aside for this', 'type' => 'checkbox', 'value' => true]] : [], receipt_fields()), 'Record payment', $cls);
}

// ---------------- recurring templates ----------------
function template_fields(string $type, array $s, ?array $t = null): array {
    $cats = []; foreach ($s['categories'] as $c) { if (($type === 'income') === ($c['grp'] === 'income')) { $cats[$c['id']] = $c['name']; } }
    return [
        ['name' => 'type', 'type' => 'hidden', 'value' => $type], ...($t ? [['name' => 'id', 'type' => 'hidden', 'value' => $t['id']]] : []),
        ['name' => 'description', 'label' => 'Name', 'required' => true, 'value' => $t['description'] ?? '', 'placeholder' => $type === 'income' ? 'e.g. Salary' : 'e.g. Maid salary'],
        ['name' => 'amount', 'label' => 'Amount', 'type' => 'money', 'required' => true, 'half' => true, 'value' => $t ? money_input((int) $t['amount']) : ''],
        ['name' => 'frequency', 'label' => 'Repeats', 'type' => 'select', 'required' => true, 'options' => freq_options(), 'half' => true, 'value' => $t['frequency'] ?? 'monthly'],
        ['name' => 'next_date', 'label' => 'Next date', 'type' => 'date', 'required' => true, 'half' => true, 'value' => $t['next_date'] ?? $s['today']],
        ['name' => 'account_id', 'label' => 'Account', 'type' => 'select', 'required' => true, 'options' => acct_options($s), 'half' => true, 'value' => $t['account_id'] ?? ''],
        ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'options' => $cats, 'value' => $t['category_id'] ?? ''],
        ['name' => 'active', 'label' => 'Active', 'type' => 'checkbox', 'value' => $t ? (bool) $t['active'] : true],
    ];
}
function template_list(array $s, string $type): string {
    $rows = array_values(array_filter($s['templates'], fn($t) => $t['type'] === $type)); if (!$rows) { return ''; }
    $o = '<ul class="divide-y divide-line">';
    foreach ($rows as $t) {
        $id = (int) $t['id'];
        $o .= '<li class="flex items-center justify-between gap-2 py-3 text-sm"><div><p class="font-medium">' . e($t['description']) . (!$t['active'] ? ' ' . badge('Paused') : '') . '</p><p class="text-xs text-mute">' . e(fmt((int) $t['amount'])) . ' · ' . e($t['frequency']) . ' · next ' . e(date_label($t['next_date'])) . '</p></div><div class="flex shrink-0 gap-1">'
            . dialog_form("tpl-$id", 'Edit', 'Edit recurring ' . $type, 'template-save', template_fields($type, $s, $t), 'Save changes', 'btn btn-ghost btn-sm')
            . confirm_btn('Delete', 'template-delete', ['id' => $id], 'Delete recurring item?', 'Future entries stop. Entries already created stay.') . '</div></li>';
    }
    return $o . '</ul>';
}

// ---------------- tx filtering ----------------
function parse_tx_filter(): array {
    $g = fn($k) => get_str($k, 100); $iso = fn($v) => $v !== '' && valid_date($v) ? $v : '';
    return ['q' => $g('q'), 'type' => in_array($g('type'), ['income', 'expense', 'transfer', 'refund', 'adjustment'], true) ? $g('type') : '', 'category' => ctype_digit($g('category')) ? (int) $g('category') : 0,
        'account' => ctype_digit($g('account')) ? (int) $g('account') : 0, 'from' => $iso($g('from')), 'to' => $iso($g('to')), 'status' => in_array($g('status'), ['completed', 'planned'], true) ? $g('status') : '',
        'sort' => $g('sort') === 'amount' ? 'amount' : 'date', 'dir' => $g('dir') === 'asc' ? 'asc' : 'desc'];
}
function filter_txs(array $s, array $f): array {
    $cats = $f['category'] ? array_flip(cat_desc($s, $f['category'])) : null; $q = mb_strtolower($f['q']);
    $rows = array_values(array_filter($s['txs'], function ($t) use ($f, $cats, $q) {
        if ($f['type'] && $t['type'] !== $f['type']) { return false; } if ($f['status'] && $t['status'] !== $f['status']) { return false; }
        if ($cats && !($t['category_id'] && isset($cats[(int) $t['category_id']]))) { return false; }
        if ($f['account'] && (int) $t['account_id'] !== $f['account'] && (int) $t['to_account_id'] !== $f['account']) { return false; }
        if ($f['from'] && $t['date'] < $f['from']) { return false; } if ($f['to'] && $t['date'] > $f['to']) { return false; }
        if ($q !== '' && !str_contains(mb_strtolower($t['description'] . ' ' . ($t['notes'] ?? '')), $q)) { return false; }
        return true;
    }));
    $sign = $f['dir'] === 'asc' ? 1 : -1;
    usort($rows, function ($a, $b) use ($f, $sign) { $c = $f['sort'] === 'amount' ? abs($a['amount']) <=> abs($b['amount']) : [$a['date'], $a['id']] <=> [$b['date'], $b['id']]; return $c * $sign ?: $b['id'] <=> $a['id']; });
    return $rows;
}
function tx_edit_json(array $s, array $t): array {
    $fund = null; foreach ($s['contribs'] as $c) { if ((int) $c['transaction_id'] === (int) $t['id'] && $c['target_type'] === 'goal') { $fund = (int) $c['target_id']; } }
    return ['id' => $t['id'], 'type' => $t['type'], 'status' => $t['status'], 'amount' => money_input((int) $t['amount']), 'date' => $t['date'], 'account_id' => $t['account_id'], 'to_account_id' => $t['to_account_id'], 'category_id' => $t['category_id'],
        'description' => $t['description'], 'payment_method' => $t['payment_method'], 'notes' => $t['notes'], 'refund_of' => $t['refund_of'], 'fund_goal_id' => $fund];
}
