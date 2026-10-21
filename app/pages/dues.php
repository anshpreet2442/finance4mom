<?php
declare(strict_types=1);

function charge_badge(array $c): string {
    return match ($c['label']) {
        'paid' => badge('Paid', 'pos'), 'waived' => badge('Waived', 'neutral'), 'cancelled' => badge('Cancelled', 'neutral'),
        'overdue' => badge($c['days_overdue'] . ' day' . ($c['days_overdue'] === 1 ? '' : 's') . ' overdue', 'neg'), 'due_today' => badge('Due today', 'warn'),
        default => badge('Due in ' . $c['days_until'] . ' day' . ($c['days_until'] === 1 ? '' : 's'), $c['days_until'] <= 3 ? 'warn' : 'neutral'),
    };
}

function page_dues(): void {
    $u = require_login(); $uid = (int) $u['id']; $s = snap(); billing_sync($uid);
    $rows = charge_rows($uid); $owed = outstanding_total($uid); $level = billing_level($u); $cfg = billing_cfg();
    $pending = array_values(array_filter($rows, fn($c) => $c['status'] === 'pending')); usort($pending, fn($a, $b) => $a['due_date'] <=> $b['due_date']); $next = $pending[0] ?? null;
    $pays = all("SELECT p.*, c.title FROM {payments} p JOIN {charges} c ON c.id=p.charge_id WHERE p.user_id=? AND p.status IN ('captured','failed') ORDER BY p.id DESC LIMIT 12", [$uid]);
    layout_start('Dues & Payments', 'dues', $s, ['hero' => true]);
    [$m1, $d1] = fmt_parts($owed, ['currency' => 'INR', 'locale' => prefs()['locale']]);
    echo m_banner($u, 'Outstanding', e($m1) . '<small>' . e($d1) . '</small>', $next ? icon('calendar-clock', 'size-4') . 'Next due ' . e(date_label($next['due_date'])) : icon('circle-check', 'size-4') . 'All paid up');
    echo '<div class="hidden px-0 lg:block">'; page_header('Dues & Payments', 'Charges posted by the administrator, and a safe way to pay them online.'); echo '</div>';
    echo '<div class="m-sheet -mt-6 px-4 pb-6 pt-6 lg:mt-0 lg:bg-transparent lg:px-0 lg:pt-0 lg:shadow-none"><div class="grid gap-5 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]"><div class="space-y-5">';

    // status
    $tone = $level >= 2 ? 'bg-neg/10 text-neg' : ($level === 1 ? 'bg-orange/20 text-orange-dk' : ($owed > 0 ? 'bg-soft' : 'bg-lime/15 text-lime-dk'));
    echo '<div class="rounded-3xl px-5 py-4 text-sm ' . $tone . '" role="status"><p class="flex items-center gap-2 font-semibold">' . icon($level >= 2 ? 'lock' : ($owed > 0 ? 'bell-ring' : 'circle-check'), 'size-5')
        . ($level === 3 ? 'The app is locked until these dues are paid' : ($level === 2 ? 'The app is read-only until these dues are paid' : ($level === 1 ? 'You have an overdue payment' : ($owed > 0 ? 'You are up to date. Something is due soon' : 'You are all paid up. Thank you!')))) . '</p>'
        . ($owed > 0 ? '<p class="mt-1 opacity-90">' . e(fmt_inr($owed)) . ' outstanding' . ($level >= 2 ? '. You can still view everything, download your data and pay.' : '.') . '</p>' : '') . '</div>';
    if (!payu_configured()) { echo '<div class="rounded-3xl bg-soft px-5 py-4 text-sm text-mute">' . icon('info', 'size-4 mr-1 inline') . 'Online payment is not switched on yet. Please pay the administrator directly and ask them to mark it as received.</div>'; }

    // charges
    card_open('Charges', count($pending) ? count($pending) . ' to pay' : 'Nothing to pay');
    if (!$rows) { echo empty_state('No charges yet', 'When the administrator posts a monthly or one-off payment it appears here, and you get a reminder.'); }
    else {
        echo '<ul class="divide-y divide-line">';
        foreach ($rows as $c) {
            if ($c['status'] === 'pending') { echo '<li class="lg:hidden -mx-6">' . swipe_row('<div class="flex items-center justify-between gap-3 px-6 py-4"><div class="min-w-0"><p class="font-medium">' . e($c['title']) . '</p><p class="text-xs text-mute">due ' . e(date_label($c['due_date'])) . '</p><div class="mt-1.5">' . charge_badge($c) . '</div></div><p class="num text-lg font-semibold">' . e(fmt_inr($c['payable'])) . '</p></div>', [['action' => 'dues-pay', 'fields' => ['charge_id' => $c['id']], 'label' => 'Pay', 'icon' => 'credit-card', 'tone' => 'ok']], [], $c['title']) . '</li>'; }
            echo '<li class="' . ($c['status'] === 'pending' ? 'max-lg:hidden ' : '') . 'flex flex-wrap items-center justify-between gap-3 py-4"><div class="min-w-0 flex-1"><p class="font-medium">' . e($c['title']) . '</p><p class="text-xs text-mute">' . e($c['kind'] === 'monthly' ? 'Monthly' : 'One-off') . ' · due ' . e(date_label($c['due_date'])) . ($c['status'] === 'paid' && $c['paid_at'] ? ' · paid ' . e(date_label(substr($c['paid_at'], 0, 10))) . ($c['paid_via'] === 'manual' ? ' (received in person)' : '') : '') . '</p>'
                . ($c['description'] ? '<p class="mt-0.5 text-xs text-mute">' . e($c['description']) . '</p>' : '') . '<div class="mt-1.5">' . charge_badge($c) . '</div></div>'
                . '<div class="text-right"><p class="num text-lg font-semibold">' . e(fmt_inr($c['status'] === 'pending' ? $c['payable'] : (int) $c['amount'])) . '</p>'
                . ($c['status'] === 'pending' && $c['late_fee'] > 0 && !$c['late_fee_waived'] ? '<p class="text-xs text-neg">includes ' . e(fmt_inr((int) $c['late_fee'])) . ' late fee</p>' : '')
                . ($c['status'] === 'pending' ? '<div class="mt-2">' . post_btn(icon('credit-card', 'size-4') . ' Pay ' . e(fmt_inr($c['payable'])), 'dues-pay', ['charge_id' => $c['id']], 'btn btn-dark btn-sm') . '</div>' : '') . '</div></li>';
        }
        echo '</ul>';
    }
    card_close();
    if ($pays) {
        card_open('Payment history', 'Receipts');
        echo '<ul class="divide-y divide-line text-sm">';
        foreach ($pays as $p) { echo '<li class="flex items-center justify-between gap-3 py-3"><div class="min-w-0"><p class="truncate font-medium">' . e($p['title']) . '</p><p class="text-xs text-mute">' . e($p['status'] === 'captured' ? date_label(substr((string) $p['captured_at'], 0, 10)) . ($p['method'] ? ' · ' . strtoupper($p['method']) : '') : 'Attempt failed' . ($p['error'] ? ': ' . $p['error'] : '')) . ' · ref ' . e(substr($p['api_payment_id'], -8)) . '</p></div><div class="text-right">' . ($p['status'] === 'captured' ? badge('Paid', 'pos') : badge('Failed', 'neg')) . '<p class="num mt-1 text-xs">' . e(fmt_inr((int) $p['amount'])) . '</p></div></li>'; }
        echo '</ul>'; card_close();
    }
    echo '</div><div class="space-y-5">';
    if ($cfg['enabled']) { card_open('What happens if a payment is late', 'Written down so there are no surprises'); echo '<ul class="space-y-2.5 text-sm">'; foreach (consequences_text($cfg) as $t) { echo '<li class="flex gap-2">' . icon('chevron-right', 'mt-0.5 size-4 text-mute') . '<span>' . e($t) . '</span></li>'; } echo '</ul>'; card_close(); }
    card_open('How paying works'); echo '<ol class="list-decimal space-y-1.5 pl-5 text-sm text-mute"><li>Press <strong class="text-ink">Pay</strong> on a charge. You are taken to the secure PayU page.</li><li>Pay with UPI, card or net banking. This app never sees your card or bank details.</li><li>You return here and your payment is confirmed with the gateway before it is marked paid.</li></ol>'; card_close();
    echo '</div></div></div>';
    layout_end();
}

/** Where the gateway sends the payer back to. Shows a result, but only believes the gateway (webhook / status lookup), never the browser. */
function page_dues_return(): void {
    $u = require_login(); $uid = (int) $u['id']; $s = snap();
    $apiId = get_str('api_payment_id', 80); $pay = $apiId !== '' ? find_payment($apiId) : null;
    if ($pay && (int) $pay['user_id'] !== $uid) { $pay = null; }
    layout_start('Payment', 'dues', $s);
    echo '<div class="mx-auto max-w-md py-10 text-center">';
    if (!$pay) {
        echo '<span class="mx-auto grid size-20 place-items-center rounded-full bg-soft">' . icon('search', 'size-9 text-mute') . '</span><h1 class="mt-5 text-2xl font-semibold tracking-tight">We could not find that payment</h1><p class="mt-2 text-mute">If money left your account, it will show on the Dues page shortly. Otherwise nothing was charged.</p>';
    } else {
        $verified = isset($_GET['sig']) ? verify_return_sig($_GET, PAYU_WEBHOOK_SECRET) : null; // informational only
        $pay = refresh_payment($pay); $charge = one('SELECT * FROM {charges} WHERE id=?', [$pay['charge_id']]); $n = (int) get_str('n', 3);
        if ($pay['status'] === 'captured') {
            echo '<span class="mx-auto grid size-24 place-items-center rounded-full bg-lime/20 text-lime-dk" style="animation: splash-pop .8s cubic-bezier(.2,.9,.3,1.3) both">' . icon('circle-check', 'size-14') . '</span><h1 class="mt-6 text-3xl font-semibold tracking-tight">Payment received</h1>'
                . '<p class="num mt-2 text-4xl font-semibold">' . e(fmt_inr((int) $pay['amount'])) . '</p><p class="mt-2 text-mute">' . e($charge['title'] ?? '') . '. Thank you! A receipt is on your Dues page.</p>' . ($verified === false ? '<p class="mt-3 text-xs text-mute">The return link could not be verified, but the gateway has confirmed this payment.</p>' : '');
        } elseif (in_array($pay['status'], ['pending'], true) && $n < 12) {
            echo '<span class="mx-auto grid size-24 place-items-center rounded-full bg-soft"><span class="size-10 animate-spin rounded-full border-4 border-line border-t-[var(--lime)]"></span></span><h1 class="mt-6 text-2xl font-semibold tracking-tight">Confirming your payment…</h1><p class="mt-2 text-mute">This usually takes a few seconds. Please keep this page open.</p>'
                . '<script>setTimeout(function(){location.replace(' . json_encode(url('/dues/return', ['api_payment_id' => $pay['api_payment_id'], 'n' => $n + 1])) . ')},4000)</script>';
        } elseif ($pay['status'] === 'pending') {
            echo '<span class="mx-auto grid size-20 place-items-center rounded-full bg-orange/20 text-orange-dk">' . icon('clock', 'size-9') . '</span><h1 class="mt-5 text-2xl font-semibold tracking-tight">Still waiting for the bank</h1><p class="mt-2 text-mute">It is taking longer than usual. You do not need to pay again: your dues page updates by itself as soon as the gateway confirms.</p>';
        } else {
            echo '<span class="mx-auto grid size-20 place-items-center rounded-full bg-neg/10 text-neg">' . icon('circle-x', 'size-10') . '</span><h1 class="mt-5 text-2xl font-semibold tracking-tight">That payment did not go through</h1><p class="mt-2 text-mute">' . e($pay['error'] ?: 'The bank or gateway did not complete it.') . ' Nothing was kept from you. You can try again.</p>'
                . ($charge && $charge['status'] === 'pending' ? '<div class="mt-5">' . post_btn('Try again', 'dues-pay', ['charge_id' => $charge['id']], 'btn btn-dark') . '</div>' : '');
        }
    }
    echo '<div class="mt-6"><a href="' . e(url('/dues')) . '" class="btn">Back to dues</a></div></div>';
    layout_end();
}
