<?php
declare(strict_types=1);

function page_bills(): void {
    $u = require_login(); $s = snap(); $month = month_of($s['today']);
    $occ = array_values(array_filter(occurrence_views($s), fn($o) => $o['bill']['kind'] === 'bill')); $live = array_values(array_filter($occ, fn($o) => !$o['skipped']));
    $attention = array_values(array_filter($live, fn($o) => $o['outstanding'] > 0 && ($o['overdue'] || $o['status'] === 'partial')));
    $upcoming = array_values(array_filter($live, fn($o) => $o['outstanding'] > 0 && !$o['overdue'] && $o['status'] !== 'partial' && $o['days'] <= 45));
    $paid = array_filter($live, fn($o) => $o['status'] === 'paid'); usort($paid, fn($a, $b) => $b['due_date'] <=> $a['due_date']); $paid = array_slice($paid, 0, 8);
    $bills = array_values(array_filter($s['bills'], fn($b) => $b['kind'] === 'bill'));
    $notes = all('SELECT id,kind,title,body FROM {notifications} WHERE user_id=? AND is_read=0 ORDER BY due_date', [$u['id']]);
    $row = function ($o) use ($s) {
        $sub = $o['status'] === 'paid' ? '' : ($o['overdue'] ? (-$o['days']) . ' days late' : ($o['days'] === 0 ? 'due today' : 'in ' . $o['days'] . ' days'));
        return '<tr><td class="td"><div class="font-medium">' . e($o['bill']['name']) . '</div><div class="text-xs text-mute">' . e($o['bill']['frequency']) . '</div></td><td class="td num whitespace-nowrap text-mute">' . e(date_label($o['due_date'])) . '<div class="text-xs">' . e($sub) . '</div></td>'
            . '<td class="td num text-right">' . e(fmt((int) $o['amount'])) . ($o['paid'] > 0 ? '<div class="text-xs text-mute">paid ' . e(fmt($o['paid'])) . '</div>' : '') . '</td><td class="td">' . status_badge($o['status']) . '</td>'
            . '<td class="td text-right"><div class="flex justify-end gap-1">' . ($o['outstanding'] > 0 ? pay_button($s, $o) : '') . ($o['paid'] === 0 && $o['outstanding'] > 0 ? post_btn('Skip', 'occurrence-skip', ['id' => $o['id']], 'btn btn-ghost btn-sm') : '') . '</div></td></tr>';
    };
    $head = '<thead><tr class="border-b border-line"><th class="th">Bill</th><th class="th">Due</th><th class="th text-right">Amount</th><th class="th">Status</th><th class="th"><span class="sr-only">Actions</span></th></tr></thead>';
    $mrow = function ($o) {
        $sub = $o['status'] === 'paid' ? 'paid' : ($o['overdue'] ? (-$o['days']) . ' days late' : ($o['days'] === 0 ? 'due today' : 'in ' . $o['days'] . ' days'));
        $c = '<div class="m-row"><span class="m-row__ic">' . icon('bell-ring', 'size-4') . '</span><div class="m-row__main"><p class="m-row__t">' . e($o['bill']['name']) . '</p><p class="m-row__s">' . e(date_label($o['due_date'])) . ' · ' . e($sub) . ' · ' . e($o['bill']['frequency']) . '</p></div>'
            . '<div class="text-right"><p class="m-row__amt">' . e(fmt((int) $o['amount'])) . '</p><div class="mt-1">' . status_badge($o['status']) . '</div></div></div>';
        $start = $o['outstanding'] > 0 ? [['type' => 'open', 'open' => '#pay-' . (int) $o['id'], 'label' => 'Pay', 'icon' => 'circle-check', 'tone' => 'ok']] : [];
        $end = $o['paid'] === 0 && $o['outstanding'] > 0 ? [['action' => 'occurrence-skip', 'fields' => ['id' => $o['id']], 'label' => 'Skip', 'icon' => 'arrow-right', 'tone' => 'warn']] : [];
        return '<li>' . swipe_row($c, $start, $end, $o['bill']['name']) . '</li>';
    };
    $table = fn(array $rows) => '<ul class="m-rows swipe-list lg:hidden">' . implode('', array_map($mrow, $rows)) . '</ul><div class="relative overflow-x-auto max-lg:hidden"><table class="w-full min-w-[560px]">' . $head . '<tbody class="divide-y divide-line">' . implode('', array_map($row, $rows)) . '</tbody></table></div>';
    layout_start('Bills & Reminders', 'bills', $s);
    page_header('Bills & Reminders', 'Recurring bills create their own due dates. An unpaid bill is a liability, not an expense, until you pay it.', dialog_form('bill-new', icon('plus', 'size-4') . ' Add bill', 'New recurring bill', 'bill-save', bill_fields('bill', $s), 'Save', 'btn btn-dark'));
    $od = array_filter($live, fn($o) => $o['overdue']);
    echo '<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">' . stat_card('Overdue', e(fmt(array_sum(array_column($od, 'outstanding')))), count($od) . ' bill(s)', $od ? 'neg' : '')
        . stat_card('Due in 30 days', e(fmt(array_sum(array_map(fn($o) => !$o['overdue'] && $o['outstanding'] > 0 && $o['days'] <= 30 ? $o['outstanding'] : 0, $live)))), '', 'warn')
        . stat_card('Paid this month', e(fmt(array_sum(array_map(fn($o) => month_of($o['due_date']) === $month ? $o['paid'] : 0, $live)))), '', 'pos') . stat_card('Active bills', (string) count(array_filter($bills, fn($b) => $b['active']))) . '</div>';
    if ($notes) {
        echo '<div class="mt-5">'; card_open('Reminders', '', post_btn('Dismiss all', 'notifications-read', [], 'btn btn-ghost btn-sm')); echo '<ul class="space-y-2 text-sm">';
        foreach ($notes as $n) { echo '<li class="lg:hidden -mx-2">' . swipe_row('<div class="flex flex-wrap items-center gap-2 px-2 py-3">' . badge($n['kind'] === 'overdue' ? 'Overdue' : 'Soon', $n['kind'] === 'overdue' ? 'neg' : 'warn') . '<span class="font-medium">' . e($n['title']) . '</span><span class="text-mute">' . e($n['body']) . '</span></div>', [], [['action' => 'notification-dismiss', 'fields' => ['id' => $n['id']], 'label' => 'Dismiss', 'icon' => 'check', 'tone' => 'dark']], $n['title']) . '</li>'; }
        foreach ($notes as $n) { echo '<li class="flex flex-wrap items-center gap-2 max-lg:hidden">' . badge($n['kind'] === 'overdue' ? 'Overdue' : 'Soon', $n['kind'] === 'overdue' ? 'neg' : 'warn') . '<span class="font-medium">' . e($n['title']) . '</span><span class="text-mute">' . e($n['body']) . '</span></li>'; }
        echo '</ul>'; card_close(); echo '</div>';
    }
    if ($attention) { echo '<div class="mt-5">'; card_flush_open('Needs attention'); echo $table($attention) . '</div></section></div>'; }
    echo '<div class="mt-5">'; card_flush_open('Upcoming');
    echo $upcoming ? $table($upcoming) : '<div class="p-6">' . empty_state('Nothing due in the next 45 days', $bills ? 'You are all caught up.' : 'Add your electricity, internet, rent and other regular bills.') . '</div>';
    echo '</div></section></div>';
    if ($paid) { echo '<div class="mt-5">'; card_flush_open('Recently paid'); echo $table($paid) . '</div></section></div>'; }
    echo '<div class="mt-5">'; card_flush_open('All recurring bills');
    if (!$bills) { echo '<p class="p-6 text-sm text-mute">No bills yet.</p>'; }
    else {
        echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[520px]"><thead><tr class="border-b border-line"><th class="th">Bill</th><th class="th text-right">Amount</th><th class="th">Repeats</th><th class="th">Next due</th><th class="th"><span class="sr-only">Actions</span></th></tr></thead><tbody class="divide-y divide-line">';
        foreach ($bills as $b) {
            $next = null; foreach ($occ as $o) { if ((int) $o['bill_id'] === (int) $b['id'] && !$o['skipped'] && $o['outstanding'] > 0) { $next = $o; break; } }
            echo '<tr><td class="td font-medium">' . e($b['name']) . (!$b['active'] ? ' ' . badge('Paused') : '') . '</td><td class="td num text-right">' . e(fmt((int) $b['amount'])) . '</td><td class="td capitalize text-mute">' . e($b['frequency']) . '</td><td class="td num text-mute">' . ($next ? e(date_label($next['due_date'])) : '—') . '</td>'
                . '<td class="td text-right"><div class="flex justify-end gap-1">' . dialog_form('bill-edit-' . $b['id'], 'Edit', 'Edit bill', 'bill-save', bill_fields('bill', $s, $b), 'Save changes', 'btn btn-ghost btn-sm')
                . confirm_btn('Delete', 'bill-delete', ['id' => $b['id']], 'Delete ' . $b['name'] . '?', 'Future due dates are removed. Payments you already recorded stay in your transactions.') . '</div></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section></div>';
    layout_end();
}

function page_yearly(): void {
    require_login(); $s = snap(); $items = yearly_views($s); $active = array_values(array_filter($items, fn($i) => $i['active'])); $month = month_of($s['today']);
    $perYear = 0; foreach ($active as $i) { $perYear += (int) $i['amount'] * intdiv(12, FREQ_MONTHS[$i['frequency']]); }
    $timeline = [];
    for ($k = 0; $k < 12; $k++) { $m = add_months($month, $k); $t = 0; foreach ($items as $i) { foreach ($s['occurrences'] as $o) { if ((int) $o['bill_id'] === (int) $i['id'] && !$o['skipped'] && month_of($o['due_date']) === $m) { $t += $o['amount'] - $o['paid']; } } } $timeline[] = ['label' => substr(month_label($m, true), 0, 3), 'values' => [$t]]; }
    layout_start('Yearly Expenses', 'yearly', $s);
    page_header('Yearly Expenses', 'Premiums, taxes, renewals and other costs that are easy to forget. Set a little aside every month so the full amount is ready when it is due.', dialog_form('yr-new', icon('plus', 'size-4') . ' Add obligation', 'New yearly obligation', 'bill-save', bill_fields('yearly', $s), 'Save', 'btn btn-dark'));
    echo '<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">' . stat_card('Commitments per year', e(fmt($perYear)), count($active) . ' obligation' . (count($active) === 1 ? '' : 's')) . stat_card('Set aside each month', e(fmt(array_sum(array_column($active, 'standard')))), 'Yearly amount ÷ 12', 'warn')
        . stat_card('Already set aside', e(fmt(array_sum(array_column($active, 'saved')))), '', 'pos')
        . stat_card('Still to set aside', e(fmt(array_sum(array_map(fn($i) => $i['next'] && $i['next']['status'] !== 'paid' ? max($i['next']['outstanding'] - $i['saved'], 0) : 0, $active)))), 'For the next due dates') . '</div>';
    if (!$items) { echo '<div class="mt-5">' . empty_state('No yearly obligations yet', 'Add LIC premiums, vehicle insurance, property tax, memberships and annual subscriptions. We will tell you how much to set aside each month.') . '</div>'; layout_end(); return; }
    echo '<div class="mt-5">'; card_open('When money is due', 'Next 12 months'); echo chart_bars($timeline, [['name' => 'Due', 'color' => 'orange']], 210); card_close(); echo '</div><div class="mt-5 grid gap-4 md:grid-cols-2">';
    foreach ($items as $i) {
        $n = $i['next']; $id = (int) $i['id']; $need = $n ? $n['outstanding'] : (int) $i['amount']; $pct = $need > 0 ? min($i['saved'] / $need * 100, 100) : 100; $months = FREQ_MONTHS[$i['frequency']];
        echo '<article class="card p-6"><div class="flex items-start justify-between gap-2"><div class="min-w-0"><h3 class="truncate text-lg font-semibold">' . e($i['name']) . '</h3><p class="text-xs text-mute">' . e(fmt((int) $i['amount'])) . ' · ' . e($i['frequency']) . ($n ? ' · due ' . e(date_label($n['due_date'])) : '') . '</p></div><div class="flex gap-1.5">' . (!$i['active'] ? badge('Paused') : '') . ($n ? status_badge($n['status']) : '') . '</div></div>'
            . '<div class="mt-4">' . progress($pct, $pct >= 100 ? 'dark' : ($n && $n['days'] < 30 ? 'orange' : 'lime'), $i['name'] . ' set aside') . '<p class="num mt-1.5 text-xs text-mute">' . e(fmt($i['saved'])) . ' set aside of ' . e(fmt($need)) . ' needed</p></div>'
            . '<dl class="mt-4 grid grid-cols-2 gap-2 text-sm"><div><dt class="text-xs text-mute">Set aside / month</dt><dd class="num font-semibold">' . e(fmt($i['standard'])) . '</dd></div><div><dt class="text-xs text-mute">Catch-up / month</dt><dd class="num font-semibold">' . ($i['catch_up'] ? e(fmt($i['catch_up'])) : '—') . '</dd></div></dl>'
            . '<p class="mt-2 text-xs text-mute">' . e(fmt((int) $i['amount'])) . ' ÷ ' . $months . ' months = ' . e(fmt($i['standard'])) . ' each month.' . ($i['catch_up'] > $i['standard'] ? ' To be fully covered by the due date you would need ' . e(fmt($i['catch_up'])) . ' per month from now.' : '') . ' Reminder ' . (int) $i['reminder_days'] . ' days before.</p>'
            . '<div class="mt-4 flex flex-wrap gap-1.5 border-t border-line pt-4">' . dialog_form("yr-set-$id", 'Set aside', 'Set aside for ' . $i['name'], 'contribute', contribute_fields('bill', $id, $s, $i['standard'], 'Amount (use − to take money back)'), 'Save', 'btn btn-sm')
            . ($n && $n['outstanding'] > 0 ? pay_button($s, $n, 'Record payment', 'btn btn-sm') : '') . dialog_form("yr-edit-$id", 'Edit', 'Edit obligation', 'bill-save', bill_fields('yearly', $s, $i), 'Save changes', 'btn btn-ghost btn-sm')
            . confirm_btn('Delete', 'bill-delete', ['id' => $id], 'Delete ' . $i['name'] . '?', ($i['saved'] > 0 ? fmt($i['saved']) . ' set aside returns to your spendable balance. ' : '') . 'Past payments stay in your transactions.') . '</div>';
        if ($i['history']) { echo '<details class="mt-2"><summary class="cursor-pointer text-xs text-mute">Payment history</summary><ul class="mt-1 divide-y divide-line text-xs">'; foreach (array_reverse(array_slice($i['history'], -6)) as $h) { echo '<li class="flex justify-between py-1"><span class="text-mute">' . e(date_label($h['due_date'])) . '</span><span class="num">' . e(fmt($h['paid'])) . '</span></li>'; } echo '</ul></details>'; }
        echo '</article>';
    }
    echo '</div>'; layout_end();
}
