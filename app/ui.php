<?php
declare(strict_types=1);
/** HTML components and the page layout (Tailwind classes; icons are inlined Lucide SVGs). */

function icon(string $name, string $cls = 'size-5'): string {
    if ($name === 'pills') { // the animated AI logo (assets/pillsmark.js); falls back to a static SVG
        $px = preg_match('/size-(\d+)/', $cls, $m) ? max(24, (int) $m[1] * 4) : 0; $cls = trim((string) preg_replace('/\bsize-\d+\b/', '', $cls));
        return '<span class="pills-mark ' . e($cls) . '" data-pills' . ($px ? ' data-size="' . $px . '"' : '') . '></span>';
    }
    static $cache = [];
    if (!isset($cache[$name])) {
        $f = __DIR__ . '/icons/' . preg_replace('/[^a-z0-9-]/', '', $name) . '.svg';
        $cache[$name] = is_file($f) ? (string) file_get_contents($f) : '';
        $svg = trim((string) preg_replace('/<!--.*?-->/s', '', $cache[$name]));
        // strip width/height/class from the root <svg> tag only (inner shapes keep theirs)
        $cache[$name] = (string) preg_replace_callback('/^<svg\b[^>]*>/s', fn($m) => preg_replace('/\s(width|height|class)="[^"]*"/', '', $m[0]), $svg);
    }
    if ($cache[$name] === '') { return ''; }
    return preg_replace('/^<svg/', '<svg class="' . e($cls) . ' shrink-0" aria-hidden="true"', trim($cache[$name]), 1);
}

// ============================ small components ============================
function badge(string $text, string $tone = 'neutral'): string {
    $c = ['neutral' => 'bg-soft text-mute', 'pos' => 'bg-lime/20 text-lime-dk', 'neg' => 'bg-neg/10 text-neg', 'warn' => 'bg-orange/20 text-orange-dk', 'dark' => 'bg-ink text-card'][$tone] ?? 'bg-soft text-mute';
    return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ' . $c . '">' . e($text) . '</span>';
}
function progress(float $pct, string $tone = 'lime', string $label = ''): string {
    $pct = max(0, min(100, $pct));
    $bar = ['lime' => 'bar-lime', 'orange' => 'bar-orange', 'neg' => 'bar-neg', 'dark' => 'bg-ink'][$tone] ?? 'bar-lime';
    return '<div role="progressbar" aria-valuenow="' . round($pct) . '" aria-valuemin="0" aria-valuemax="100" aria-label="' . e($label) . '" class="h-3 w-full overflow-hidden rounded-full bg-soft"><div class="h-full rounded-full ' . $bar . '" style="--w:' . number_format($pct, 1, '.', '') . '%"></div></div>';
}
function card_open(string $title = '', string $sub = '', string $actions = '', string $class = ''): void {
    echo '<section class="card min-w-0 ' . e($class) . '">';
    if ($title !== '' || $actions !== '') {
        echo '<header class="flex flex-wrap items-start justify-between gap-3 px-6 pt-5"><div class="min-w-0 flex-1"><h2 class="text-lg font-semibold tracking-tight">' . $title . '</h2>' . ($sub !== '' ? '<p class="text-sm text-mute">' . $sub . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="flex max-w-full flex-wrap items-center gap-2">' . $actions . '</div>' : '') . '</header>';
    }
    echo '<div class="p-6' . ($title !== '' ? ' pt-4' : '') . '">';
}
function card_close(): void { echo '</div></section>'; }
function card_flush_open(string $title = '', string $sub = '', string $actions = '', string $class = ''): void {
    echo '<section class="card min-w-0 ' . e($class) . '">';
    if ($title !== '') { echo '<header class="flex items-start justify-between gap-3 px-6 pt-5"><div><h2 class="text-lg font-semibold tracking-tight">' . $title . '</h2>' . ($sub !== '' ? '<p class="text-sm text-mute">' . $sub . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="flex shrink-0 gap-2">' . $actions . '</div>' : '') . '</header>'; }
    echo '<div class="pt-3">';
}
function stat_card(string $label, string $value, string $sub = '', string $tone = '', string $href = ''): string {
    // one tile = label / value / divider / one muted line per "\n" in $sub; nothing runs into the edges
    $t = ['pos' => 'text-lime-dk', 'neg' => 'text-neg', 'warn' => 'text-orange-dk'][$tone] ?? 'text-ink';
    $lines = $sub === '' ? '' : '<ul class="stat-tile__notes">' . implode('', array_map(fn($l) => '<li>' . $l . '</li>', array_filter(explode("\n", $sub), 'strlen'))) . '</ul>';
    $inner = '<div class="stat-tile__label">' . e($label) . '</div><div class="stat-tile__value num ' . $t . '" data-count>' . $value . '</div>' . $lines;
    return $href !== '' ? '<a href="' . e(url($href)) . '" class="card stat-tile transition hover:shadow-md">' . $inner . '</a>' : '<div class="card stat-tile">' . $inner . '</div>';
}
function empty_state(string $title, string $text = '', string $cta = '', string $ic = 'sparkles'): string {
    // $cta is trusted HTML, e.g. '<a class="btn btn-primary" href="...">Add bill</a>'
    return '<div class="empty"><span class="empty-ic">' . icon($ic, 'size-6') . '</span><p class="font-semibold">' . e($title) . '</p>' . ($text ? '<p class="max-w-md text-sm text-mute">' . $text . '</p>' : '') . $cta . '</div>';
}
function page_header(string $title, string $sub = '', string $actions = '', string $lead = ''): void {
    $actions = dock_pin_button() . $actions;
    echo '<div class="mb-6 flex flex-wrap items-end justify-between gap-3"><div' . ($lead ? ' class="flex min-w-0 items-center gap-4"' : '') . '>' . $lead . ($lead ? '<div class="min-w-0">' : '') . '<h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">' . e($title) . '</h1>' . ($sub ? '<p class="mt-1 text-sm text-mute">' . e($sub) . '</p>' : '') . ($lead ? '</div>' : '') . '</div>' . ($actions ? '<div class="flex flex-wrap items-center gap-2">' . $actions . '</div>' : '') . '</div>';
}
function btn(string $label, string $href, string $cls = 'btn', string $ic = ''): string { return '<a href="' . e($href) . '" class="' . $cls . '">' . ($ic ? icon($ic, 'size-4') : '') . $label . '</a>'; }
function month_nav(string $month, string $base): string {
    $cur = month_of(today());
    return '<div class="flex items-center gap-1" role="group" aria-label="Choose month"><a class="btn btn-sm" aria-label="Previous month" href="' . e(url($base, ['month' => add_months($month, -1)])) . '">' . icon('chevron-left', 'size-4') . '</a>'
        . '<span class="min-w-32 px-2 text-center text-sm font-medium">' . e(month_label($month)) . '</span>'
        . '<a class="btn btn-sm" aria-label="Next month" href="' . e(url($base, ['month' => add_months($month, 1)])) . '">' . icon('chevron-right', 'size-4') . '</a>'
        . ($month !== $cur ? '<a class="btn btn-ghost btn-sm" href="' . e(url($base)) . '">Today</a>' : '') . '</div>';
}
function priority_badge(string $p): string { return badge(cap($p), ['high' => 'neg', 'medium' => 'warn', 'low' => 'neutral'][$p] ?? 'neutral'); }
function status_badge(string $st): string {
    return match ($st) { 'paid' => badge('Paid', 'pos'), 'overdue' => badge('Overdue', 'neg'), 'partial' => badge('Partially paid', 'warn'), default => badge('Upcoming', 'warn') };
}
const PURCHASE_STATUS = ['planning' => 'Planning', 'saving' => 'Saving', 'ready' => 'Ready to buy', 'purchased' => 'Purchased', 'postponed' => 'Postponed'];

/** Jelly radio / tabs. $items: [value => ['label' => ..., 'href' => optional link, 'icon' => optional lucide name]]. Items with an href are navigation tabs. */
function jelly(array $items, string $active, array $o = []): string {
    $o += ['size' => 'md', 'variant' => '', 'label' => 'Options', 'class' => ''];
    $h = '<div class="jelly-radio ' . e($o['class']) . '" data-jelly data-size="' . e($o['size']) . '"' . ($o['variant'] ? ' data-variant="' . e($o['variant']) . '"' : '') . (array_filter(array_column($items, 'href')) ? ' data-nav' : '') . ' role="radiogroup" aria-label="' . e($o['label']) . '">';
    foreach ($items as $v => $it) {
        $on = (string) $v === $active; $tag = !empty($it['href']) ? 'a' : 'button'; $attr = $tag === 'a' ? ' href="' . e($it['href']) . '"' : ' type="button"';
        $h .= '<' . $tag . $attr . ' class="jelly-radio__chip" role="radio" aria-checked="' . ($on ? 'true' : 'false') . '" data-on="' . ($on ? 'true' : 'false') . '" data-value="' . e((string) $v) . '" tabindex="' . ($on ? '0' : '-1') . '"' . (!empty($it['attrs']) ? ' ' . $it['attrs'] : '') . '><span class="jelly-radio__skin">'
            . (!empty($it['icon']) ? '<span class="jelly-radio__icon">' . icon($it['icon'], 'size-4') . '</span>' : '') . '<span class="jelly-radio__label">' . e($it['label']) . '</span></span></' . $tag . '>';
    }
    return $h . '</div>';
}

// ============================ forms ============================
/** Field spec: name,label,type(text|money|date|select|number|textarea|checkbox|hidden|email|password|file),options[value=>label],value,required,hint,half,min,max,placeholder,autocomplete,accept */
function field(array $f): string {
    $type = $f['type'] ?? 'text'; $name = $f['name']; $id = 'f-' . $name . '-' . substr(md5(json_encode($f) . mt_rand()), 0, 5);
    $val = $f['value'] ?? ''; $req = !empty($f['required']) ? ' required' : '';
    if ($type === 'hidden') { return '<input type="hidden" name="' . e($name) . '" value="' . e((string) $val) . '">'; }
    $span = !empty($f['half']) ? 'col-span-1' : 'col-span-2';
    if ($type === 'checkbox') { return '<label class="' . $span . ' flex items-center gap-2 text-sm"><input type="checkbox" name="' . e($name) . '" value="1"' . ($val ? ' checked' : '') . ' class="size-4 accent-[var(--lime-dk)]"> ' . e($f['label'] ?? '') . '</label>'; }
    $hint = !empty($f['hint']) ? '<p class="mt-1 text-xs text-mute">' . e($f['hint']) . '</p>' : '';
    $lab = '<label for="' . $id . '" class="label">' . e($f['label'] ?? '') . (!empty($f['required']) ? ' <span aria-hidden="true">*</span>' : '') . '</label>';
    $common = ' id="' . $id . '" name="' . e($name) . '"' . $req;
    switch ($type) {
        case 'select':
            $o = '';
            if (empty($f['required'])) { $o .= '<option value="">' . e($f['empty'] ?? 'None') . '</option>'; }
            foreach (($f['options'] ?? []) as $k => $v) {
                if (is_array($v) && isset($v['group'])) { $o .= '<optgroup label="' . e($v['group']) . '">'; foreach ($v['options'] as $kk => $vv) { $o .= '<option value="' . e((string) $kk) . '"' . ((string) $kk === (string) $val ? ' selected' : '') . '>' . e($vv) . '</option>'; } $o .= '</optgroup>'; }
                else { $o .= '<option value="' . e((string) $k) . '"' . ((string) $k === (string) $val ? ' selected' : '') . '>' . e($v) . '</option>'; }
            }
            $ctl = '<select' . $common . ' class="input">' . $o . '</select>'; break;
        case 'textarea': $ctl = '<textarea' . $common . ' rows="2" class="input" placeholder="' . e($f['placeholder'] ?? '') . '">' . e((string) $val) . '</textarea>'; break;
        case 'money':
            $ctl = '<div class="relative"><span aria-hidden="true" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-mute">' . e($f['currency'] ?? currency_symbol()) . '</span><input' . $common . ' type="text" inputmode="decimal" autocomplete="off" value="' . e((string) $val) . '" placeholder="' . e($f['placeholder'] ?? '0') . '" pattern="-?[0-9,]*\.?[0-9]{0,2}" title="Amount, up to 2 decimals" class="input num pl-9"></div>'; break;
        case 'file': $ctl = '<input' . $common . ' type="file" accept="' . e($f['accept'] ?? '') . '" class="input">'; break;
        default:
            $ctl = '<input' . $common . ' type="' . e($type) . '" value="' . e((string) $val) . '" placeholder="' . e($f['placeholder'] ?? '') . '"'
                . (isset($f['min']) ? ' min="' . (int) $f['min'] . '"' : '') . (isset($f['max']) ? ' max="' . (int) $f['max'] . '"' : '')
                . (!empty($f['autocomplete']) ? ' autocomplete="' . e($f['autocomplete']) . '"' : '') . ' class="input">';
    }
    return '<div class="' . $span . '">' . $lab . $ctl . $hint . '</div>';
}
function form_open(string $action, string $class = 'grid grid-cols-2 gap-x-3 gap-y-4', bool $multipart = false): string {
    return '<form method="post" action="' . e(url('/do/' . $action)) . '" class="' . e($class) . '"' . ($multipart ? ' enctype="multipart/form-data"' : '') . '>' . csrf_field() . '<input type="hidden" name="_back" value="' . e(current_url()) . '">';
}
function form_fields(array $fields): string { return implode('', array_map('field', $fields)); }
function submit_row(string $label = 'Save', string $cls = 'btn btn-primary'): string { return '<div class="col-span-2 mt-1 flex justify-end gap-2"><button type="submit" class="' . $cls . '">' . e($label) . '</button></div>'; }
/** A complete inline form (fields + submit). */
function simple_form(string $action, array $fields, string $submit = 'Save', string $cls = 'btn btn-primary', bool $multipart = false): string {
    return form_open($action, 'grid grid-cols-2 gap-x-3 gap-y-4', $multipart) . form_fields($fields) . submit_row($submit, $cls) . '</form>';
}
/** Button + <dialog> containing a form. Dialog ids must be unique per page. */
function dialog_form(string $id, string $trigger, string $title, string $action, array $fields, string $submit = 'Save', string $triggerCls = 'btn', bool $danger = false): string {
    return '<button type="button" class="' . e($triggerCls) . '" data-open="#' . e($id) . '">' . $trigger . '</button>'
        . '<dialog id="' . e($id) . '" class="modal" aria-labelledby="' . e($id) . '-t"><div class="modal-body"><div class="mb-5 flex items-start justify-between gap-4"><h2 id="' . e($id) . '-t" class="text-xl font-semibold tracking-tight">' . e($title) . '</h2>'
        . '<button type="button" class="btn btn-ghost btn-sm -mr-2" data-close aria-label="Close">' . icon('x', 'size-4') . '</button></div>'
        . simple_form($action, $fields, $submit, $danger ? 'btn btn-danger-solid' : 'btn btn-primary') . '</div></dialog>';
}
/** Destructive action routed through the shared confirmation dialog. */
function confirm_btn(string $label, string $action, array $hidden, string $title, string $message, string $confirm = 'Delete', string $cls = 'btn btn-ghost btn-sm text-neg'): string {
    return '<button type="button" class="' . e($cls) . '" data-confirm data-action="' . e(url('/do/' . $action)) . '" data-fields="' . json_attr($hidden) . '" data-title="' . e($title) . '" data-message="' . e($message) . '" data-label="' . e($confirm) . '">' . $label . '</button>';
}
/** One-click POST (no dialog). */
function post_btn(string $label, string $action, array $hidden, string $cls = 'btn btn-sm', string $attrs = ''): string {
    $h = ''; foreach ($hidden as $k => $v) { $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">'; }
    return '<form method="post" action="' . e(url('/do/' . $action)) . '" class="inline">' . csrf_field() . '<input type="hidden" name="_back" value="' . e(current_url()) . '">' . $h . '<button class="' . e($cls) . '" ' . $attrs . '>' . $label . '</button></form>';
}
function acct_options(array $s): array { $o = []; foreach (active_accounts($s) as $a) { $o[$a['id']] = $a['name']; } return $o; }
function cat_options(array $s, bool $income = false, bool $withGroups = true): array {
    $o = [];
    foreach (GROUP_LABELS as $g => $label) {
        if (($g === 'income') !== $income) { continue; }
        $opts = [];
        foreach ($s['categories'] as $c) { if ($c['grp'] === $g && !$c['parent_id']) { $opts[$c['id']] = $c['name']; foreach ($s['categories'] as $x) { if ((int) $x['parent_id'] === (int) $c['id']) { $opts[$x['id']] = "\u{00A0}\u{00A0}↳ " . $x['name']; } } } }
        if ($opts) { $o[] = ['group' => $label, 'options' => $opts]; }
    }
    return $o;
}
function freq_options(bool $monthly = true): array { $o = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half-yearly' => 'Half-yearly', 'yearly' => 'Yearly']; if (!$monthly) { unset($o['monthly']); } return $o; }
function priority_options(): array { return ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']; }

// ============================ transaction dialog (shared) ============================
function tx_dialog(array $s): string {
    $incomeCats = cat_options($s, true); $expCats = cat_options($s, false);
    $expenses = []; foreach (array_slice(array_values(array_filter($s['txs'], fn($t) => $t['type'] === 'expense' && $t['status'] === 'completed')), 0, 60) as $t) { $expenses[$t['id']] = date_label($t['date']) . ' · ' . ($t['description'] ?: 'Expense') . ' · ' . fmt($t['amount']); }
    $funds = []; foreach ($s['goals'] as $g) { if ($g['kind'] === 'personal') { $funds[$g['id']] = $g['name']; } }
    $accts = acct_options($s);
    ob_start(); ?>
<dialog id="tx-dialog" class="modal" aria-labelledby="tx-title"><div class="modal-body">
  <div class="mb-4 flex items-start justify-between gap-4"><h2 id="tx-title" class="text-xl font-semibold tracking-tight">Add transaction</h2><button type="button" class="btn btn-ghost btn-sm -mr-2" data-close aria-label="Close"><?= icon('x', 'size-4') ?></button></div>
  <form method="post" action="<?= e(url('/do/transaction-save')) ?>" class="grid grid-cols-2 gap-x-3 gap-y-4" id="tx-form">
    <?= csrf_field() ?><input type="hidden" name="_back" value="<?= e(current_url()) ?>"><input type="hidden" name="id" value=""><input type="hidden" name="type" value="expense">
    <div class="col-span-2 flex flex-wrap gap-1.5" role="radiogroup" aria-label="Transaction type">
      <?php foreach (['expense' => 'Expense', 'income' => 'Income', 'transfer' => 'Transfer', 'refund' => 'Refund', 'adjustment' => 'Adjustment'] as $k => $l): ?>
        <button type="button" role="radio" data-type="<?= $k ?>" aria-checked="<?= $k === 'expense' ? 'true' : 'false' ?>" class="chip"><?= $l ?></button>
      <?php endforeach; ?>
    </div>
    <?= field(['name' => 'amount', 'label' => 'Amount', 'type' => 'money', 'required' => true, 'half' => true]) ?>
    <?= field(['name' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'half' => true, 'value' => $s['today']]) ?>
    <?= field(['name' => 'account_id', 'label' => 'Account', 'type' => 'select', 'required' => true, 'options' => $accts, 'half' => true]) ?>
    <div data-show="transfer" class="col-span-1 hidden"><?= field(['name' => 'to_account_id', 'label' => 'To account', 'type' => 'select', 'options' => $accts, 'half' => true, 'empty' => 'Choose…']) ?></div>
    <div data-hide="transfer" class="col-span-2"><label class="label" for="tx-cat">Category</label>
      <select id="tx-cat" name="category_id" class="input" data-cat-expense="1"><option value="">Uncategorised</option><?php foreach ($expCats as $g): ?><optgroup label="<?= e($g['group']) ?>"><?php foreach ($g['options'] as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
      <select id="tx-cat-inc" class="input hidden" data-cat-income="1" disabled><option value="">Uncategorised</option><?php foreach ($incomeCats as $g): ?><optgroup label="<?= e($g['group']) ?>"><?php foreach ($g['options'] as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select>
    </div>
    <?= field(['name' => 'description', 'label' => 'Description', 'placeholder' => 'e.g. Vegetables']) ?>
    <div data-hide="transfer adjustment" class="col-span-2"><?= field(['name' => 'payment_method', 'label' => 'Payment method', 'type' => 'select', 'options' => ['UPI' => 'UPI', 'Card' => 'Card', 'Cash' => 'Cash', 'Bank transfer' => 'Bank transfer', 'Cheque' => 'Cheque', 'Other' => 'Other'], 'empty' => 'Not specified']) ?></div>
    <div data-show="refund" class="col-span-2 hidden"><?= field(['name' => 'refund_of', 'label' => 'Refund of (optional)', 'type' => 'select', 'options' => $expenses, 'empty' => 'Not linked to an expense']) ?></div>
    <?php if ($funds): ?><div data-show="expense" class="col-span-2"><?= field(['name' => 'fund_goal_id', 'label' => 'Pay from personal fund', 'type' => 'select', 'options' => $funds, 'empty' => 'No, from my account balance']) ?></div><?php endif; ?>
    <?= field(['name' => 'remarks', 'label' => 'Remarks (optional)', 'placeholder' => 'A short remark shown under the description', 'hint' => 'Searchable. Different from notes: it appears in lists.']) ?>
    <?= field(['name' => 'notes', 'label' => 'Notes (optional)', 'type' => 'textarea']) ?>
    <div class="imp-box" id="tx-imp" hidden aria-live="polite"></div>
    <?php if ($rc = receipt_fields()): ?><div data-show="expense" class="col-span-2 flex flex-col gap-2" data-receipt><?= form_fields($rc) ?></div><?php endif; ?>
    <label data-show="income expense" class="col-span-2 flex items-center gap-2 text-sm"><input type="checkbox" name="planned" value="1" class="size-4 accent-[var(--lime-dk)]"> <span data-planned-label>Not paid yet (planned expense)</span></label>
    <div class="col-span-2 mt-1 flex items-center justify-between gap-2"><button type="button" id="tx-delete" class="btn btn-ghost text-neg hidden" data-confirm data-action="<?= e(url('/do/transaction-delete')) ?>" data-fields="{}" data-title="Delete transaction?" data-message="This permanently removes it and updates your balances." data-label="Delete"><?= icon('trash-2', 'size-4') ?> Delete</button><button type="submit" class="btn btn-primary ml-auto" data-tx-submit>Add transaction</button></div>
  </form></div></dialog>
<dialog id="remark-dialog" class="modal modal-sm" aria-labelledby="remark-t"><div class="modal-body">
  <div class="mb-3 flex items-start justify-between gap-4"><h2 id="remark-t" class="text-xl font-semibold tracking-tight">Remark</h2><button type="button" class="btn btn-ghost btn-sm -mr-2" data-close aria-label="Close"><?= icon('x', 'size-4') ?></button></div>
  <p class="mb-3 text-sm text-mute" id="remark-for"></p>
  <form method="post" action="<?= e(url('/do/transaction-remarks')) ?>" class="grid gap-3"><?= csrf_field() ?><input type="hidden" name="_back" value="<?= e(current_url()) ?>"><input type="hidden" name="id" value="">
    <div><label class="label" for="remark-in">Remark</label><input id="remark-in" class="input" name="remarks" maxlength="500" placeholder="e.g. Diwali gift for Neha" autocomplete="off"></div>
    <div class="flex justify-end gap-2"><button type="button" class="btn" data-close>Cancel</button><button type="submit" class="btn btn-primary">Save remark</button></div></form></div></dialog>
<?php return (string) ob_get_clean();
}

// ============================ layout ============================
function nav_items(array $u): array {
    $items = [
        ['dashboard', '/', 'Dashboard', 'layout-dashboard'],
        ['accounts', '/accounts', 'Accounts', 'landmark'],
        ['transactions', '/transactions', 'Transactions', 'arrow-left-right', [['/transactions', 'History', 'transactions'], ['/income', 'Income', 'income'], ['/expenses', 'Expenses', 'expenses'], ['/analysis', 'Analysis', 'analysis']]],
        ['budget', '/budget', 'Monthly Budget', 'calendar-check'],
        ['savings', '/goals', 'Savings', 'piggy-bank', [['/goals', 'Savings Goals', 'goals'], ['/house', 'House Shopping Fund', 'house'], ['/big-purchases', 'Big Purchases', 'big-purchases']]],
        ['bills', '/bills', 'Bills & Reminders', 'bell-ring'],
        ['yearly', '/yearly', 'Yearly Expenses', 'calendar-clock'],
        ['dues', '/dues', 'Dues & Payments', 'hand-coins'],
        ['reports', '/reports', 'Reports & Insights', 'chart-line'],
    ];
    if (site_setting('ai_enabled', '0') === '1' || $u['role'] === 'admin') { $items[] = ['ai', '/ai', 'AI advisor', 'pills']; }
    $items[] = ['plugins', '/plugins', 'Plugins', 'puzzle'];
    $items[] = ['settings', '/settings', 'Settings', 'settings'];
    if ($u['role'] === 'admin') { $items[] = ['admin', '/admin', 'Admin panel', 'shield-check']; }
    return $items;
}

/** Decides whether this page view counts as "opening the app" (first view, or back after 30+ idle minutes) and whether to show the intro. */
function track_app_open(array $u): bool {
    start_session(); $now = time(); $last = (int) ($_SESSION['last_seen'] ?? 0); $_SESSION['last_seen'] = $now;
    if (!empty($_SESSION['intro_pending'])) { return true; } // decided earlier this visit and not dismissed yet (e.g. the page was reloaded)
    if ($last && $now - $last < 1800) { return false; }
    $today = today(); $uid = (int) $u['id'];
    run('INSERT INTO {usage_days} (user_id, day, opens) VALUES (?,?,1) ON DUPLICATE KEY UPDATE opens = opens + 1', [$uid, $today]);
    $days = (int) val('SELECT COUNT(*) FROM {usage_days} WHERE user_id=?', [$uid]); $opens = (int) val('SELECT opens FROM {usage_days} WHERE user_id=? AND day=?', [$uid, $today]);
    $p = one('SELECT intro_first_day, intro_shown FROM {preferences} WHERE user_id=?', [$uid]); if (!$p) { return false; }
    if ((int) $p['intro_shown'] === 0 && $days >= 5) { run('UPDATE {preferences} SET intro_first_day=?, intro_shown=1 WHERE user_id=?', [$today, $uid]); $_SESSION['intro_pending'] = true; return true; }                       // first time: after 5 days of use
    if ((int) $p['intro_shown'] === 1 && $p['intro_first_day'] === $today && $opens >= 2) { run('UPDATE {preferences} SET intro_shown=2 WHERE user_id=?', [$uid]); $_SESSION['intro_pending'] = true; return true; } // and at most one more time that same day
    return false;
}

function intro_art(): string {
    return '<svg viewBox="0 0 360 470" role="img" aria-label="A mechanical claw lifting a payment card"><defs><linearGradient id="br" x1="0" x2="1"><stop offset="0" stop-color="#2a0c06"/><stop offset=".45" stop-color="#8a4a2a"/><stop offset="1" stop-color="#2a0c06"/></linearGradient><linearGradient id="cd" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3a0e06"/><stop offset="1" stop-color="#1b0502"/></linearGradient></defs>'
        . '<rect x="148" y="-10" width="64" height="150" rx="8" fill="url(#br)"/><rect x="138" y="30" width="84" height="14" rx="5" fill="#5a2a18"/><rect x="142" y="78" width="76" height="12" rx="5" fill="#5a2a18"/><rect x="152" y="130" width="56" height="70" rx="10" fill="url(#br)"/><circle cx="180" cy="196" r="30" fill="#4b2113" stroke="#8a4a2a" stroke-width="3"/><circle cx="180" cy="196" r="10" fill="#c46a38"/>'
        . '<path d="M156 206 C 98 238 54 282 38 372 L 72 384 C 86 318 122 268 168 236 Z" fill="url(#br)" stroke="#8a4a2a" stroke-width="2"/><path d="M204 206 C 262 238 306 282 322 372 L 288 384 C 274 318 238 268 192 236 Z" fill="url(#br)" stroke="#8a4a2a" stroke-width="2"/>'
        . '<circle cx="64" cy="378" r="14" fill="#4b2113" stroke="#8a4a2a" stroke-width="3"/><circle cx="296" cy="378" r="14" fill="#4b2113" stroke="#8a4a2a" stroke-width="3"/>'
        . '<g><rect x="74" y="318" width="212" height="132" rx="16" fill="url(#cd)" stroke="#6b2a14" stroke-width="2"/><rect x="96" y="346" width="40" height="30" rx="6" fill="#f39a4a"/><path d="M96 361h40M116 346v30" stroke="#b25a1e" stroke-width="2"/><path d="M226 318h40l-52 132h-40z" fill="#7a1308" opacity=".8"/></g></svg>';
}
function intro_html(): string {
    $slides = [['Plan every rupee', 'Start each month with a plan built from your bills, budgets and goals. It never lets you over-allocate.'], ['Works offline', 'Add transactions anywhere. They sync by themselves the moment you are back online.'], ['Stay on top of dues', 'See what is due, pay safely online and get a reminder before anything is late.']];
    $o = '<div id="intro" class="intro" role="dialog" aria-modal="true" aria-label="Welcome"><button type="button" class="intro-skip">Skip</button><div class="intro-art" data-intro-hero>' . intro_art() . '</div>'
        . '<h2 class="intro-title" data-intro-hero>Modern Tools for Modern Finance</h2>';
    foreach ($slides as $i => $sl) { $o .= '<div class="intro-slide" style="margin-top:auto"><div class="mx-auto mb-5 grid size-16 place-items-center rounded-3xl bg-white/15 text-3xl font-semibold backdrop-blur">' . ($i + 1) . '</div><h2 class="text-3xl font-semibold tracking-tight">' . e($sl[0]) . '</h2><p class="mx-auto mt-3 max-w-xs text-white/80">' . e($sl[1]) . '</p></div>'; }
    $o .= '<div class="intro-dots hidden"><i></i><i></i><i></i></div><div class="intro-cta"><button type="button" class="go">Learn More</button><button type="button" class="arrow" aria-label="Next">' . icon('arrow-right', 'size-6') . '</button></div></div>';
    return $o;
}

/** Start a full page. $active = nav key. $opts['hero'] = true when the page draws its own ember header on phones. */
function layout_start(string $title, string $active, ?array $s = null, array $opts = []): void {
    $u = current_user(); $theme = $s['prefs']['theme'] ?? 'system'; $hero = !empty($opts['hero']);
    $groupActive = fn($it) => $it[0] === $active || (!empty($it[4]) && in_array($active, array_column($it[4], 2), true));
    $overdue = 0; $notes = []; $showIntro = false; $level = 0; $owed = 0; $dueCount = 0;
    if ($s) {
        foreach (occurrence_views($s) as $o) { if ($o['overdue'] && !$o['skipped']) { $overdue++; } }
        $notes = all('SELECT kind,title,body FROM {notifications} WHERE user_id=? AND is_read=0 ORDER BY due_date LIMIT 6', [$u['id']]);
        $showIntro = $_SERVER['REQUEST_METHOD'] === 'GET' && !is_spa() && track_app_open($u) && $active !== 'credits';
        billing_sync((int) $u['id']); $level = billing_level($u); $owed = outstanding_total((int) $u['id']);
        $dueCount = (int) val("SELECT COUNT(*) FROM {charges} WHERE user_id=? AND status='pending' AND due_date <= ?", [$u['id'], day_after_n(today(), 3)]);
        $GLOBALS['__badges'] = ['bills' => $overdue, 'dues' => $dueCount]; $GLOBALS['__layout_extra'] = ['active' => $active];
        $GLOBALS['__dock'] = dock_html($u);
    }
    $notice = site_setting('site_notice'); $flash = take_flash();
    $toasts = array_map(function ($f) { [$t, $m] = $f; $short = mb_strlen($m) <= 44; return ['type' => $t === 'err' ? 'err' : ($t === 'info' ? 'info' : 'ok'), 'title' => $f[2] ?? ($short ? rtrim($m, '.') : ($t === 'err' ? 'Something needs attention' : 'All set')), 'text' => ($f[2] ?? null) || !$short ? $m : '']; }, $flash);
    $pills = [['dashboard', '/', 'Home', 'layout-dashboard', ['dashboard']], ['transactions', '/transactions', 'Transactions', 'arrow-left-right', ['transactions', 'income', 'expenses']], ['analysis', '/analysis', 'Analysis', 'chart-pie', ['analysis']], ['dues', '/dues', 'Dues', 'wallet', ['dues']]];
    ?><!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>" data-palette="auto">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<meta name="color-scheme" content="light dark"><meta name="theme-color" content="#d23d09" media="(prefers-color-scheme: light)"><meta name="theme-color" content="#2a0a04" media="(prefers-color-scheme: dark)">
<meta name="pills-src" content="<?= e(asset('pills.svg')) ?>"><link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>"><link rel="icon" type="image/png" href="<?= e(asset('icons/favicon-64.png')) ?>"><link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>">
<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<script>try{var d=document.documentElement;d.classList.add('js');var t=localStorage.getItem('mfnc-theme')||d.dataset.theme;if(t==='dark'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches))d.classList.add('dark');var p=localStorage.getItem('mfnc-palette');if(p)d.setAttribute('data-palette',p);<?php if ($s): ?>if(!sessionStorage.getItem('mfnc-seen')||sessionStorage.getItem('mfnc-reload'))d.classList.add('has-splash');<?php endif; ?>}catch(e){}</script>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>"><link rel="stylesheet" href="<?= e(asset('ext.css')) ?>"><link rel="stylesheet" href="<?= e(asset('promptbar.css')) ?>"><link rel="stylesheet" href="<?= e(asset('statusmark.css')) ?>"><link rel="stylesheet" href="<?= e(asset('c.css')) ?>"><script src="<?= e(asset('voice.js')) ?>"></script><link rel="stylesheet" href="<?= e(asset('polish.css')) ?>"><link rel="stylesheet" href="<?= e(asset('import.css')) ?>">
</head>
<body class="bg-page text-ink antialiased<?= !empty($GLOBALS['__dock']) ? ' has-dock' : '' ?>" data-base="<?= e(base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>" data-user="<?= (int) $u['id'] ?>" data-locale="<?= e(prefs()['locale']) ?>" data-symbol="<?= e(currency_symbol()) ?>" data-passkeys="<?= (int) val('SELECT COUNT(*) FROM {passkeys} WHERE user_id=?', [$u['id']]) ?>">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:rounded-lg focus:bg-ink focus:px-3 focus:py-2 focus:text-card">Skip to content</a>
<?php if ($s): ?>
<div id="splash" class="splash" aria-hidden="true">
  <i class="splash-orb" style="width:180px;height:180px;left:-50px;top:12%"></i><i class="splash-orb" style="width:110px;height:110px;right:-20px;top:22%;animation-delay:-2s"></i><i class="splash-orb" style="width:240px;height:240px;right:-90px;bottom:6%;animation-delay:-4s"></i>
  <div><div class="splash-logo mx-auto"><div class="splash-bars"><i></i><i></i><i></i><i></i></div></div><p class="splash-name"><?= e(APP_NAME) ?></p><p class="splash-sub">Plan every rupee</p></div>
</div>
<?php endif; ?>
<div id="toasts" class="toast-wrap" aria-live="polite"></div>
<?php if ($toasts): ?><script type="application/json" id="flash-data"><?= json_encode($toasts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script><noscript><?php foreach ($flash as $f): ?><p class="m-4 rounded-2xl bg-soft p-4 text-sm"><?= e($f[1]) ?></p><?php endforeach; ?></noscript><?php endif; ?>
<div id="netbar" class="net-bar" role="status" aria-live="polite"></div>
<div class="shell">
  <div id="scrim" class="fixed inset-0 z-40 hidden bg-black/40 lg:hidden" data-nav-close></div>
  <aside id="sidebar" class="sidebar" aria-label="Sidebar">
    <a href="<?= e(url('/')) ?>" class="mb-8 flex items-center gap-2.5 px-3 text-xl font-bold tracking-tight"><span class="grid size-8 place-items-center rounded-xl bg-lime text-white"><?= icon('trending-up', 'size-5') ?></span><?= e(APP_NAME) ?></a>
    <nav id="mf-nav" class="flex-1 space-y-1 overflow-y-auto pr-1" aria-label="Main">
      <?php foreach (nav_items($u) as $it): $ga = $groupActive($it); ?>
        <?php if (empty($it[4])): ?>
          <a href="<?= e(url($it[1])) ?>" class="nav-item <?= $ga ? 'is-active' : '' ?>" <?= $ga ? 'aria-current="page"' : '' ?>><?= icon($it[3], 'size-5') ?><span class="flex-1"><?= e($it[2]) ?></span><?php if (in_array($it[0], ['bills', 'dues'], true)): $bn = $it[0] === 'bills' ? $overdue : $dueCount; ?><span data-live-badge="<?= $it[0] ?>" class="rounded-full bg-neg px-1.5 text-[10px] font-semibold text-white <?= $bn ? '' : 'hidden' ?>"><?= $bn ?></span><?php endif; ?></a>
        <?php else: ?>
          <details class="group" <?= $ga ? 'open' : '' ?>><summary class="nav-item cursor-pointer list-none <?= $ga ? 'is-active' : '' ?>"><?= icon($it[3], 'size-5') ?><span class="flex-1"><?= e($it[2]) ?></span><?= icon('chevron-down', 'size-4 transition group-open:rotate-180') ?></summary>
            <div class="ml-[22px] mt-1 space-y-0.5 border-l border-line pl-4">
              <?php foreach ($it[4] as $c): ?><a href="<?= e(url($c[0])) ?>" class="block rounded-lg px-3 py-1.5 text-sm <?= $active === $c[2] ? 'font-semibold text-ink' : 'text-mute hover:text-ink' ?>" <?= $active === $c[2] ? 'aria-current="page"' : '' ?>><?= e($c[1]) ?></a><?php endforeach; ?>
            </div></details>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <?php if ($s): ?>
    <div class="mt-4 rounded-3xl border border-line bg-card p-4">
      <div class="mb-3 grid size-9 place-items-center rounded-full bg-ink text-card"><?= icon('zap', 'size-4') ?></div>
      <p class="font-semibold">Quick add</p><p class="mb-3 text-sm text-mute">Record income or an expense in seconds.</p>
      <button type="button" class="btn btn-dark w-full" data-tx-new>Add transaction</button>
    </div>
    <?php endif; ?>
    <div class="mt-3 grid grid-cols-2 gap-2">
      <button type="button" class="btn btn-sm" data-theme-toggle aria-label="Toggle dark mode"><?= icon('moon', 'size-4') ?> Theme</button>
      <button type="button" class="btn btn-sm" data-reload aria-label="Reload app"><?= icon('rotate-cw', 'size-4') ?> Reload</button>
      <button type="button" class="btn btn-sm col-span-2 hidden" data-install><?= icon('download', 'size-4') ?> Install app</button>
    </div>
    <form method="post" action="<?= e(url('/do/logout')) ?>" class="mt-2" data-logout><?= csrf_field() ?><button class="nav-item w-full"><?= icon('log-out', 'size-5') ?>Sign out</button></form>
    <a href="<?= e(url('/credits')) ?>" class="mt-1 block px-4 py-2 text-center text-xs text-mute hover:text-ink">Designed &amp; developed by <span class="font-semibold">Anshpreet Singh</span></a>
  </aside>

  <div class="main">
    <header class="topbar <?= $hero ? 'max-lg:hidden' : '' ?>">
      <button type="button" class="btn btn-icon lg:hidden" data-nav-open aria-label="Open menu" aria-controls="sidebar"><?= icon('menu', 'size-5') ?></button>
      <form action="<?= e(url('/transactions')) ?>" method="get" role="search" class="relative hidden max-w-md flex-1 sm:block">
        <label for="q" class="sr-only">Search transactions</label><?= icon('search', 'size-4 pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-mute') ?>
        <input id="q" name="q" placeholder="Quick search" class="h-12 w-full rounded-2xl border-0 bg-card pl-11 pr-4 text-sm placeholder:text-mute/70 focus:outline-2 focus:outline-lime-dk"></form>
      <div class="ml-auto flex items-center gap-2">
        <span class="hidden items-center gap-2 px-2 text-xs text-mute sm:flex" aria-hidden="true"><span class="live-dot" id="live-dot" title="Live"></span>Live</span>
        <button type="button" class="btn btn-icon" data-theme-toggle aria-label="Toggle dark mode"><?= icon('moon', 'size-5') ?></button>
        <details class="relative"><summary class="btn btn-icon list-none cursor-pointer" aria-label="Reminders"><?= icon('bell', 'size-5') ?><?php if ($notes): ?><span class="absolute right-2 top-2 size-2 rounded-full bg-neg"></span><?php endif; ?></summary>
          <div class="absolute right-0 z-30 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-3xl border border-line bg-card p-3 shadow-xl">
            <p class="px-2 pb-2 text-sm font-semibold">Reminders</p>
            <?php if (!$notes): ?><p class="px-2 py-3 text-sm text-mute">Nothing due soon.</p><?php endif; ?>
            <?php foreach ($notes as $n): ?><a href="<?= e(url(in_array($n['kind'], ['due', 'overdue', 'paid'], true) ? '/dues' : '/bills')) ?>" class="block rounded-2xl px-2 py-2 text-sm hover:bg-soft"><span class="font-medium"><?= e($n['title']) ?></span><span class="block text-xs text-mute"><?= e($n['body']) ?></span></a><?php endforeach; ?>
          </div></details>
        <a href="<?= e(url('/settings')) ?>" class="btn btn-icon hidden sm:grid" aria-label="Settings"><?= icon('settings', 'size-5') ?></a>
        <div class="hidden items-center gap-3 rounded-2xl bg-card px-3 py-2 md:flex"><span class="grid size-9 place-items-center rounded-full bg-lime/30 text-sm font-semibold"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span><span class="leading-tight"><span class="block text-sm font-medium"><?= e($u['name']) ?></span><span class="block text-xs text-mute"><?= e($u['email']) ?></span></span></div>
        <?php if ($s): ?><button type="button" class="btn btn-light hidden sm:inline-flex" data-tx-new><?= icon('circle-plus', 'size-4') ?> Add transaction</button><?php endif; ?>
      </div>
    </header>
    <div id="mf-banner"><?php if ($level >= 1 && $active !== 'dues'): ?>
      <div class="mx-4 mb-3 mt-1 flex flex-wrap items-center gap-3 rounded-3xl px-5 py-4 text-sm sm:mx-6 lg:mx-8 <?= $level >= 2 ? 'bg-neg/12 text-neg' : 'bg-orange/20 text-orange-dk' ?>" role="alert">
        <?= icon($level >= 2 ? 'lock' : 'bell-ring', 'size-5') ?>
        <p class="min-w-0 flex-1"><strong><?= $level === 3 ? 'The app is locked until your dues are paid.' : ($level === 2 ? 'The app is read-only until your dues are paid.' : 'You have an overdue payment.') ?></strong> <?= e(fmt_inr($owed)) ?> outstanding.<?= $level >= 2 ? ' You can still view everything and pay.' : '' ?></p>
        <a class="btn btn-sm btn-dark" href="<?= e(url('/dues')) ?>">Pay now</a></div>
    <?php endif; ?></div>
    <div id="pending-bar" class="mx-4 mb-3 mt-1 hidden items-center gap-3 rounded-3xl bg-ink px-5 py-3 text-sm text-card sm:mx-6 lg:mx-8 [&:not(.hidden)]:flex"><?= icon('cloud-off', 'size-5') ?><span class="min-w-0 flex-1" data-pending-text></span><button type="button" class="rounded-full bg-white/15 px-3 py-1.5 text-xs font-medium" data-open-pending>Review</button><button type="button" class="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-ink" data-sync-now>Sync now</button></div>
    <main id="main" class="<?= $hero ? 'px-0 lg:px-8' : 'px-4 sm:px-6 lg:px-8' ?> pb-32 pt-2 lg:pb-10">
      <?php if ($notice !== ''): ?><div class="mx-4 mb-4 rounded-2xl bg-orange/20 px-4 py-3 text-sm text-orange-dk lg:mx-0" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php
    $GLOBALS['__layout_snap'] = $s; $GLOBALS['__layout_extra'] = ['intro' => $showIntro, 'pills' => $pills, 'active' => $active, 'user' => $u];
}

function layout_end(): void {
    $s = $GLOBALS['__layout_snap'] ?? null; $x = $GLOBALS['__layout_extra'] ?? ['intro' => false, 'pills' => [], 'active' => '', 'user' => ['name' => '']];
    echo '</main></div></div>'; // main, .main, .shell
    echo '<dialog id="confirm-dialog" class="modal modal-sm" aria-labelledby="confirm-title"><div class="modal-body"><h2 id="confirm-title" class="text-xl font-semibold tracking-tight">Are you sure?</h2><p id="confirm-message" class="mb-5 mt-2 text-sm text-mute"></p>'
        . '<form method="post" id="confirm-form" class="flex justify-end gap-2">' . csrf_field() . '<input type="hidden" name="_back" value="' . e(current_url()) . '"><span id="confirm-fields"></span><button type="button" class="btn" data-close>Cancel</button><button type="submit" class="btn btn-danger-solid" id="confirm-ok">Delete</button></form></div></dialog>';
    if ($s) {
        echo tx_dialog($s);
        echo '<dialog id="pending-dialog" class="modal" aria-labelledby="pending-t"><div class="modal-body"><div class="mb-3 flex items-start justify-between gap-4"><h2 id="pending-t" class="text-xl font-semibold tracking-tight">Waiting to sync</h2><button type="button" class="btn btn-ghost btn-sm -mr-2" data-close aria-label="Close">' . icon('x', 'size-4') . '</button></div><p class="text-sm text-mute">These were saved on this device while you were offline. They are sent automatically when you reconnect.</p><ul id="pending-list" class="mt-2 divide-y divide-line"></ul><div class="mt-4 flex justify-end"><button type="button" class="btn btn-primary" data-sync-now>Sync now</button></div></div></dialog>';
        // mobile ember pill navigation
        echo '<nav class="m-pill lg:hidden" id="mf-pill" aria-label="Quick navigation">';
        foreach ($x['pills'] as $p) { $on = in_array($x['active'], $p[4], true); echo '<a href="' . e(url($p[1])) . '" class="' . ($on ? 'is-on' : '') . '" aria-label="' . e($p[2]) . '" ' . ($on ? 'aria-current="page"' : '') . '>' . icon($p[3], 'size-6') . '</a>'; }
        echo '<button type="button" data-nav-open aria-label="Menu">' . icon('layout-grid', 'size-6') . '</button></nav>';
        // pending-sync bar content (shown by JS)
        echo '<template id="pending-tpl"></template>';
    }
    if (!empty($GLOBALS['__dock'])) { echo $GLOBALS['__dock']; }
    if ($x['intro']) { echo intro_html(); }
    echo '<script src="' . e(asset('sync.js')) . '"></script><script src="' . e(asset('motion.js')) . '"></script><script src="' . e(asset('statusmark.js')) . '"></script><script src="' . e(asset('promptbar.js')) . '"></script><script src="' . e(asset('pillsmark.js')) . '" defer></script><script src="' . e(asset('slosh.js')) . '" defer></script><script src="' . e(asset('jelly.js')) . '" defer></script><script src="' . e(asset('passkey.js')) . '" defer></script><script src="' . e(asset('app.js')) . '" defer></script><script src="' . e(asset('spa.js')) . '" defer></script><script src="' . e(asset('pwa.js')) . '" defer></script><script src="' . e(asset('swiperow.js')) . '" defer></script><script src="' . e(asset('dock.js')) . '" defer></script><script src="' . e(asset('starborder.js')) . '" defer></script><script src="' . e(asset('upfb.js')) . '" defer></script><script src="' . e(asset('import.js')) . '" defer></script></body></html>';
}
function render_error(int $code, string $title, string $msg): void {
    http_response_code($code);
    $u = current_user();
    if ($u && $code !== 500) { layout_start($title, '', null); echo '<div class="mx-auto max-w-lg py-16 text-center"><p class="text-6xl font-bold text-lime-dk">' . $code . '</p><h1 class="mt-3 text-2xl font-semibold">' . e($title) . '</h1><p class="mt-2 text-mute">' . e($msg) . '</p><a class="btn btn-primary mt-6" href="' . e(url('/')) . '">Back to dashboard</a></div>'; layout_end(); return; }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . '</title><link rel="stylesheet" href="' . e(asset('app.css')) . '"><link rel="stylesheet" href="' . e(asset('polish.css')) . '"><body class="bg-page text-ink"><div class="mx-auto max-w-lg px-6 py-24 text-center"><p class="text-6xl font-bold text-lime-dk">' . $code . '</p><h1 class="mt-3 text-2xl font-semibold">' . e($title) . '</h1><p class="mt-2 text-mute">' . e($msg) . '</p><a class="btn btn-primary mt-6" href="' . e(url('/')) . '">Go home</a></div></body>';
}

// ============================ shared list pieces ============================
function tx_label(array $s, array $t): string {
    if ($t['description'] !== '') { return $t['description']; }
    if ($t['type'] === 'transfer') { $a = array_column($s['accounts'], 'name', 'id'); return 'Transfer to ' . ($a[$t['to_account_id']] ?? 'account'); }
    return cat_path($s, $t['category_id'] ? (int) $t['category_id'] : null);
}
function signed_amount(array $t): string {
    return match ($t['type']) { 'income', 'refund' => '+' . fmt($t['amount']), 'expense' => '−' . fmt($t['amount']), 'adjustment' => $t['amount'] > 0 ? '+' . fmt($t['amount']) : '−' . fmt(-$t['amount']), default => fmt($t['amount']) };
}
function amount_class(array $t): string { if ($t['status'] === 'planned') { return 'text-mute'; } return in_array($t['type'], ['income', 'refund'], true) || ($t['type'] === 'adjustment' && $t['amount'] > 0) ? 'text-lime-dk' : 'text-ink'; }
function tx_list(array $s, array $rows, string $empty = 'Nothing here yet.'): string {
    if (!$rows) { return '<p class="py-6 text-center text-sm text-mute">' . e($empty) . '</p>'; }
    $acc = array_column($s['accounts'], 'name', 'id'); $o = '<ul class="divide-y divide-line">';
    foreach ($rows as $t) {
        $sub = $t['type'] === 'transfer' ? ($acc[$t['account_id']] ?? '') . ' → ' . ($acc[$t['to_account_id']] ?? '') : cat_path($s, $t['category_id'] ? (int) $t['category_id'] : null);
        $o .= '<li class="flex items-center gap-3 py-3"><span class="grid size-10 shrink-0 place-items-center rounded-2xl bg-soft text-ink">' . icon(['income' => 'arrow-down-left', 'refund' => 'undo-2', 'transfer' => 'arrow-left-right', 'adjustment' => 'sliders-horizontal'][$t['type']] ?? 'arrow-up-right', 'size-4') . '</span>'
            . '<div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">' . e(tx_label($s, $t)) . '</p><p class="truncate text-xs text-mute">' . e(date_label($t['date'])) . ' · ' . e($sub) . ($t['status'] === 'planned' ? ' · Planned' : '') . '</p>' . tx_extra_line($t) . '</div>'
            . '<span class="num shrink-0 text-sm font-semibold ' . amount_class($t) . '">' . e(signed_amount($t)) . '</span></li>';
    }
    return $o . '</ul>';
}
