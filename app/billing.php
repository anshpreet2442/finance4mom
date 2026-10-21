<?php
declare(strict_types=1);
/**
 * Dues & payments. The admin posts monthly charges or one-off payments to a user; the user pays through the PayU gateway
 * (see the Partner API guide). Rules taken from that guide and enforced here:
 *  - the API token lives in an environment variable / config.php, is only ever used server-side and is never logged or stored in the database
 *  - a payment is marked paid ONLY from a signed webhook or a server-side status lookup, never because the browser came back
 *  - webhooks are verified (HMAC over the raw body), de-duplicated by X-UMPI-Delivery-Id, amount/currency-checked, and always answered 2xx for unknown events
 *  - payment.failed is not final: a later payment.captured is still accepted
 *  - POST /payment/init is not idempotent, so an open checkout for the same charge is reused instead of creating a second one
 */

class PayuError extends RuntimeException {
    public function __construct(string $message, public int $http = 0, public string $apiCode = '') { parent::__construct($message); }
}

function fmt_inr(int $minor): string { return fmt($minor, ['currency' => 'INR', 'locale' => prefs()['locale']]); }

// ============================ settings & rules ============================
function billing_cfg(): array {
    return [
        'enabled' => site_setting('billing_enabled', '1') === '1',
        'grace' => max(0, (int) site_setting('grace_days', '5')),
        'fee_type' => in_array(site_setting('late_fee_type', 'none'), ['none', 'flat', 'percent'], true) ? site_setting('late_fee_type', 'none') : 'none',
        'fee_flat' => max(0, (int) site_setting('late_fee_flat', '0')),
        'fee_percent' => max(0, (int) site_setting('late_fee_percent', '0')), // basis points: 500 = 5.00%
        'restrict' => max(0, (int) site_setting('restrict_after_days', '14')),
        'lock' => max(0, (int) site_setting('lock_after_days', '30')),
    ];
}
function late_fee_for(int $amount, array $cfg): int {
    return match ($cfg['fee_type']) { 'flat' => $cfg['fee_flat'], 'percent' => intdiv($amount * $cfg['fee_percent'] + 9999, 10000), default => 0 };
}
/** What the consequences are, in plain words (shown to the payer and to the admin). */
function consequences_text(array $cfg): array {
    $o = [];
    $o[] = "Reminders start the day after the due date" . ($cfg['grace'] ? " (a grace period of {$cfg['grace']} day" . ($cfg['grace'] === 1 ? '' : 's') . ' applies before any late fee)' : '') . '.';
    if ($cfg['fee_type'] === 'flat' && $cfg['fee_flat'] > 0) { $o[] = 'A one-time late fee of ' . fmt_inr($cfg['fee_flat']) . ' is added once the grace period ends.'; }
    if ($cfg['fee_type'] === 'percent' && $cfg['fee_percent'] > 0) { $o[] = 'A one-time late fee of ' . rtrim(rtrim(number_format($cfg['fee_percent'] / 100, 2), '0'), '.') . '% of the charge is added once the grace period ends.'; }
    if ($cfg['restrict'] > 0) { $o[] = "After {$cfg['restrict']} days overdue the app becomes read-only: you can still look at everything and pay, but cannot add or change records."; }
    if ($cfg['lock'] > 0) { $o[] = "After {$cfg['lock']} days overdue only the Dues page is available (you can always still pay, download your data and sign out)."; }
    $o[] = 'Paying the full amount restores everything immediately. The administrator can waive a charge or a late fee at any time.';
    return $o;
}

// ============================ charge lifecycle ============================
function charge_payable(array $c): int { return (int) $c['amount'] + ($c['late_fee_waived'] ? 0 : (int) $c['late_fee']); }
function charge_state(array $c, string $today): array {
    $days = $c['status'] === 'pending' ? days_between($c['due_date'], $today) : 0; // >0 means past the due date
    $label = match ($c['status']) { 'paid' => 'paid', 'waived' => 'waived', 'cancelled' => 'cancelled', default => $days > 0 ? 'overdue' : ($days === 0 ? 'due_today' : 'upcoming') };
    return ['label' => $label, 'days_overdue' => max($days, 0), 'days_until' => max(-$days, 0), 'payable' => charge_payable($c)];
}
/** Generates monthly charges from active plans and applies one-time late fees. Idempotent; call before reading charges. */
function billing_sync(int $uid, ?string $today = null): void {
    $today ??= today(); $cfg = billing_cfg(); $thisMonth = month_of($today);
    foreach (all('SELECT * FROM {charge_plans} WHERE user_id=? AND active=1', [$uid]) as $p) {
        $m = $p['start_month'];
        for ($i = 0; $i < 60 && $m <= $thisMonth; $i++, $m = add_months($m, 1)) {
            if ($p['end_month'] && $m > $p['end_month']) { break; }
            $due = $m . '-' . str_pad((string) min((int) $p['day_of_month'], days_in_month($m)), 2, '0', STR_PAD_LEFT);
            $new = run("INSERT IGNORE INTO {charges} (user_id,plan_id,period,kind,title,description,amount,due_date,created_by) VALUES (?,?,?,?,?,?,?,?,?)",
                [$uid, $p['id'], $m, 'monthly', $p['title'] . ' · ' . month_label($m), $p['description'], $p['amount'], $due, $p['created_by']]);
            if ($new) { notify_charge($uid, (int) val('SELECT id FROM {charges} WHERE plan_id=? AND period=?', [$p['id'], $m]), 'new'); }
        }
    }
    if ($cfg['fee_type'] !== 'none') {
        foreach (all("SELECT id,amount,due_date FROM {charges} WHERE user_id=? AND status='pending' AND late_fee_at IS NULL AND late_fee_waived=0", [$uid]) as $c) {
            if (days_between($c['due_date'], $today) > $cfg['grace']) {
                run('UPDATE {charges} SET late_fee=?, late_fee_at=UTC_TIMESTAMP() WHERE id=? AND late_fee_at IS NULL', [late_fee_for((int) $c['amount'], $cfg), $c['id']]);
            }
        }
    }
    foreach (all("SELECT * FROM {charges} WHERE user_id=? AND status='pending'", [$uid]) as $c) {
        $st = charge_state($c, $today);
        if ($st['label'] === 'overdue') { notify_charge($uid, (int) $c['id'], 'overdue', $c); }
    }
}
function notify_charge(int $uid, int $chargeId, string $kind, ?array $c = null): void {
    $c ??= one('SELECT * FROM {charges} WHERE id=? AND user_id=?', [$chargeId, $uid]); if (!$c) { return; }
    $title = $kind === 'new' ? 'New charge: ' . $c['title'] : $c['title'] . ' is overdue';
    $body = $kind === 'new' ? fmt_inr(charge_payable($c)) . ' due on ' . date_label($c['due_date']) . '.' : fmt_inr(charge_payable($c)) . ' was due on ' . date_label($c['due_date']) . '. Pay it on the Dues page.';
    $fresh = run('INSERT INTO {notifications} (user_id,dedupe_key,kind,title,body,due_date) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title), body=VALUES(body)', [$uid, "charge:$chargeId:$kind", $kind === 'new' ? 'due' : 'overdue', $title, $body, $c['due_date']]);
    if ($fresh === 1 && $c['status'] === 'pending' && sms_cfg()['auto']) { sms_charge_alert($uid, $c, $kind === 'new' ? 'charge.new' : 'charge.overdue'); } // first time only: the notification row is the de-duplication
    if ($fresh === 1 && $c['status'] === 'pending' && function_exists('tg_notify')) { tg_notify($uid, $kind === 'new' ? 'charge.new' : 'charge.overdue', ['title' => $c['title'], 'amount' => fmt_inr(charge_payable($c)), 'due_date' => date_label($c['due_date']), 'link' => abs_url('/dues?charge=' . (int) $chargeId)], "charge:$chargeId:$kind"); } // Telegram reminder for people who opted in
}
/** 0 = fine, 1 = reminder, 2 = restricted (read-only), 3 = locked (Dues page only). Admins and exempt users are always 0. */
function billing_level(array $user, ?string $today = null): int {
    $cfg = billing_cfg(); if (!$cfg['enabled'] || $user['role'] === 'admin') { return 0; }
    if (!(int) (val('SELECT billing_enforced FROM {users} WHERE id=?', [$user['id']]) ?? 1)) { return 0; }
    $today ??= today(); $max = 0;
    foreach (all("SELECT due_date FROM {charges} WHERE user_id=? AND status='pending'", [$user['id']]) as $c) {
        $d = days_between($c['due_date'], $today); if ($d <= 0) { continue; }
        $lvl = ($cfg['lock'] > 0 && $d >= $cfg['lock']) ? 3 : (($cfg['restrict'] > 0 && $d >= $cfg['restrict']) ? 2 : 1);
        $max = max($max, $lvl);
    }
    return $max;
}
function outstanding_total(int $uid, ?string $today = null): int {
    $t = 0; foreach (all("SELECT * FROM {charges} WHERE user_id=? AND status='pending'", [$uid]) as $c) { $t += charge_payable($c); } return $t;
}
function charge_rows(int $uid, ?string $today = null): array {
    $today ??= today();
    return array_map(fn($c) => $c + charge_state($c, $today), all('SELECT * FROM {charges} WHERE user_id=? ORDER BY (status="pending") DESC, due_date DESC, id DESC', [$uid]));
}

// ============================ admin actions ============================
function admin_create_charge(int $adminId, int $userId, string $title, ?string $desc, int $amount, string $due): int {
    if (!one('SELECT id FROM {users} WHERE id=?', [$userId])) { throw new UserError('User not found.'); }
    if ($amount <= 0) { throw new UserError('Amount must be greater than zero.'); }
    $id = insert("INSERT INTO {charges} (user_id,kind,title,description,amount,due_date,created_by) VALUES (?,?,?,?,?,?,?)", [$userId, 'one_time', $title, $desc, $amount, $due, $adminId]);
    notify_charge($userId, $id, 'new'); audit('billing.charge.create', "user#$userId", "$title " . fmt_inr($amount)); return $id;
}
function admin_save_plan(int $adminId, ?int $id, int $userId, string $title, ?string $desc, int $amount, int $day, string $start, ?string $end, bool $active): int {
    if (!one('SELECT id FROM {users} WHERE id=?', [$userId])) { throw new UserError('User not found.'); }
    if ($amount <= 0) { throw new UserError('Amount must be greater than zero.'); }
    if ($end && $end < $start) { throw new UserError('The last month cannot be before the first month.'); }
    if ($id) {
        if (!one('SELECT id FROM {charge_plans} WHERE id=?', [$id])) { throw new UserError('Plan not found.'); }
        run('UPDATE {charge_plans} SET user_id=?,title=?,description=?,amount=?,day_of_month=?,start_month=?,end_month=?,active=? WHERE id=?', [$userId, $title, $desc, $amount, $day, $start, $end, (int) $active, $id]);
        // future unpaid charges follow the plan's new amount; paid history is never rewritten
        run("UPDATE {charges} SET amount=? WHERE plan_id=? AND status='pending' AND late_fee_at IS NULL AND due_date >= ?", [$amount, $id, today()]);
        audit('billing.plan.update', "plan#$id", $title); return $id;
    }
    $id = insert('INSERT INTO {charge_plans} (user_id,title,description,amount,day_of_month,start_month,end_month,active,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [$userId, $title, $desc, $amount, $day, $start, $end, (int) $active, $adminId]);
    audit('billing.plan.create', "plan#$id", $title . ' ' . fmt_inr($amount) . '/month'); return $id;
}
function admin_charge_action(int $chargeId, string $action, ?string $note): string {
    $c = one('SELECT * FROM {charges} WHERE id=?', [$chargeId]); if (!$c) { throw new UserError('Charge not found.'); }
    if ($action === 'waive_fee') { run('UPDATE {charges} SET late_fee_waived=1 WHERE id=?', [$chargeId]); audit('billing.fee.waive', "charge#$chargeId"); return 'Late fee waived.'; }
    if ($c['status'] !== 'pending') { throw new UserError('Only an unpaid charge can be changed.'); }
    $map = ['waive' => ['waived', 'billing.charge.waive', 'Charge waived.'], 'cancel' => ['cancelled', 'billing.charge.cancel', 'Charge cancelled.'], 'paid' => ['paid', 'billing.charge.manual_paid', 'Marked as paid (received outside the app).']];
    if (!isset($map[$action])) { throw new UserError('Unknown action.'); }
    [$st, $audit, $msg] = $map[$action];
    run("UPDATE {charges} SET status=?, note=?, paid_at=" . ($st === 'paid' ? 'UTC_TIMESTAMP()' : 'NULL') . ", paid_via=" . ($st === 'paid' ? "'manual'" : 'NULL') . " WHERE id=? AND status='pending'", [$st, $note, $chargeId]);
    run("UPDATE {payments} SET status='cancelled' WHERE charge_id=? AND status='pending'", [$chargeId]);
    run('DELETE FROM {notifications} WHERE user_id=? AND dedupe_key IN (?,?)', [$c['user_id'], "charge:$chargeId:overdue", "charge:$chargeId:new"]); // the reminders no longer apply
    audit($audit, "charge#$chargeId", (string) $note); if ($st === 'paid') { after_charge_paid($chargeId, 'received outside the app'); } return $msg;
}

// ============================ gateway client ============================
function payu_configured(): bool { return PAYU_BASE_URL !== '' && PAYU_API_TOKEN !== ''; }
function payu_request(string $method, string $path, ?array $body = null): array {
    if (!payu_configured()) { throw new PayuError('The payment gateway is not configured yet. Set PAYU_BASE_URL and PAYU_API_TOKEN (see README).', 0, 'not_configured'); }
    $ch = curl_init(rtrim(PAYU_BASE_URL, '/') . $path);
    $local = (bool) preg_match('~^http://(127\.0\.0\.1|localhost)~', PAYU_BASE_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, // a redirect would turn the POST into a GET
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . PAYU_API_TOKEN, 'Content-Type: application/json', 'Accept: application/json'], CURLOPT_SSL_VERIFYPEER => !$local, CURLOPT_SSL_VERIFYHOST => $local ? 0 : 2]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)); }
    $raw = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch);
    if ($raw === false) { log_error(new RuntimeException("payu $method $path: $err")); throw new PayuError('Could not reach the payment gateway (' . $err . '). Check PAYU_BASE_URL and the server’s outbound connection.', 0, 'network'); }
    $r = json_decode((string) $raw, true);
    if (!is_array($r)) { log_error(new RuntimeException("payu $method $path: HTTP $http non-JSON")); throw new PayuError("The gateway returned an unexpected response (HTTP $http).", $http, 'bad_response'); }
    if (empty($r['success'])) {
        $m = $r['error']['message'] ?? 'The gateway refused the request.'; $code = $r['error']['code'] ?? '';
        log_error(new RuntimeException("payu $method $path: HTTP $http $code")); throw new PayuError($m, $http, (string) $code);
    }
    return $r;
}
function abs_url(string $path): string {
    if (APP_URL !== '') { return rtrim(APP_URL, '/') . $path; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path() . $path;
}

// ============================ paying ============================
/** Starts (or reuses) a gateway checkout for a charge and returns the page the payer should be sent to. */
function start_payment(int $uid, int $chargeId): string {
    billing_sync($uid);
    $c = one('SELECT * FROM {charges} WHERE id=? AND user_id=?', [$chargeId, $uid]);
    if (!$c) { throw new UserError('Record not found.'); }
    if ($c['status'] !== 'pending') { throw new UserError('This charge is already settled.'); }
    $amount = charge_payable($c);
    run("UPDATE {payments} SET status='expired' WHERE charge_id=? AND status='pending' AND expires_at <= UTC_TIMESTAMP()", [$chargeId]);
    $open = one("SELECT embed_url FROM {payments} WHERE charge_id=? AND status='pending' AND amount=? AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1", [$chargeId, $amount]);
    if ($open) { return $open['embed_url']; }
    $u = one('SELECT name,email FROM {users} WHERE id=?', [$uid]);
    try {
        $r = payu_request('POST', '/payment/init', [
            'amount' => $amount / 100, 'currency' => 'INR', 'payment_name' => mb_substr($c['title'], 0, 100), 'description' => mb_substr(($c['description'] ?: $c['title']) . ' · ' . APP_NAME, 0, 250),
            'customer' => ['name' => $u['name'], 'email' => $u['email']], 'expires_in_minutes' => 30, 'redirect_mode' => 'signed',
            'success_redirect_url' => abs_url('/dues/return'), 'failure_redirect_url' => abs_url('/dues/return'),
            'metadata' => ['app' => 'mfnc', 'charge_id' => (int) $c['id'], 'user_id' => $uid],
        ]);
    } catch (PayuError $e) {
        // not retried automatically: the call is not idempotent. The real reason goes to the log; the payer gets a clear message.
        throw new UserError($e->apiCode === 'not_configured' ? 'Online payment is not set up yet. Please ask the administrator.' : 'The payment page could not be opened (' . ($e->apiCode ?: 'gateway') . '). Please try again in a minute, or ask the administrator.');
    }
    insert('INSERT INTO {payments} (user_id,charge_id,api_payment_id,embed_url,amount,currency,expires_at) VALUES (?,?,?,?,?,?,?)',
        [$uid, $chargeId, (string) $r['api_payment_id'], (string) $r['embed_url'], $amount, 'INR', gmdate('Y-m-d H:i:s', time() + 30 * 60)]);
    return (string) $r['embed_url'];
}
/** Marks a charge paid exactly once, from a verified capture. Safe to call repeatedly. */
function settle_capture(array $pay, ?string $gatewayPaymentId, ?string $method): string {
    $res = settle_capture_tx($pay, $gatewayPaymentId, $method);
    if ($res === 'paid') { after_charge_paid((int) $pay['charge_id'], 'the payment gateway'); }
    return $res;
}
/** Runs once when a charge becomes paid: grants plans / credits top-ups and sends the receipt email. */
function after_charge_paid(int $chargeId, string $via): void {
    try {
        $c = one('SELECT * FROM {charges} WHERE id=?', [$chargeId]); if (!$c) { return; }
        apply_charge_effects($c);
        notify_email((int) $c['user_id'], 'payment.received', ['title' => $c['title'], 'amount' => fmt_inr(charge_payable($c)), 'date' => date_label(today()), 'method' => $via, 'link' => abs_url('/dues')], null, false);
    } catch (Throwable $e) { log_error($e); }
}
function settle_capture_tx(array $pay, ?string $gatewayPaymentId, ?string $method): string {
    return tx(function () use ($pay, $gatewayPaymentId, $method) {
        $changed = run("UPDATE {payments} SET status='captured', gateway_payment_id=COALESCE(?, gateway_payment_id), method=COALESCE(?, method), captured_at=COALESCE(captured_at, UTC_TIMESTAMP()), error=NULL WHERE id=? AND status<>'captured'", [$gatewayPaymentId, $method, $pay['id']]);
        $charge = one('SELECT * FROM {charges} WHERE id=? FOR UPDATE', [$pay['charge_id']]);
        if (!$charge) { return 'charge_missing'; }
        if ($charge['status'] === 'pending') {
            run("UPDATE {charges} SET status='paid', paid_at=UTC_TIMESTAMP(), paid_via='gateway', payment_id=? WHERE id=? AND status='pending'", [$pay['id'], $charge['id']]);
            run("INSERT INTO {notifications} (user_id,dedupe_key,kind,title,body,due_date) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title)", [$pay['user_id'], 'charge:' . $charge['id'] . ':paid', 'paid', 'Payment received', fmt_inr((int) $pay['amount']) . ' for ' . $charge['title'] . '. Thank you!', $charge['due_date']]);
            run("DELETE FROM {notifications} WHERE user_id=? AND dedupe_key IN (?,?)", [$pay['user_id'], 'charge:' . $charge['id'] . ':overdue', 'charge:' . $charge['id'] . ':new']);
            return 'paid';
        }
        return $changed ? 'captured_charge_not_open' : 'already_settled'; // e.g. the admin cancelled it meanwhile: money arrived, so flag it for a manual refund/decision
    });
}
/** Applies a gateway outcome to a payment row. $info: status, amount (rupees, optional), currency (optional), rzp ids, method, error. */
function apply_gateway_outcome(array $pay, array $info): string {
    $status = $info['status'] ?? '';
    if ($status === 'captured') {
        if (isset($info['amount']) && (int) round(((float) $info['amount']) * 100) !== (int) $pay['amount']) { audit('billing.payment.amount_mismatch', $pay['api_payment_id'], 'got ' . $info['amount']); return 'amount_mismatch'; }
        if (isset($info['currency']) && strtoupper((string) $info['currency']) !== $pay['currency']) { audit('billing.payment.currency_mismatch', $pay['api_payment_id']); return 'currency_mismatch'; }
        $res = settle_capture($pay, $info['gateway_payment_id'] ?? null, $info['method'] ?? null);
        if ($res === 'captured_charge_not_open') { audit('billing.payment.captured_for_closed_charge', $pay['api_payment_id'], 'refund or reconcile manually'); }
        return $res;
    }
    if ($status === 'failed') { // not final: the payer can retry the same checkout and a capture may still follow
        run("UPDATE {payments} SET error=? WHERE id=? AND status NOT IN ('captured')", [mb_substr((string) ($info['error'] ?? 'Payment failed'), 0, 300), $pay['id']]); return 'failed_recorded';
    }
    if (in_array($status, ['cancelled', 'expired'], true)) { run("UPDATE {payments} SET status=? WHERE id=? AND status='pending'", [$status, $pay['id']]); return $status; }
    return 'ignored';
}
function find_payment(string $apiId): ?array { return one('SELECT * FROM {payments} WHERE api_payment_id=?', [$apiId]); }

/** Server-side lookup with the gateway (at most once per ~10 s per payment). The truth source for the return page. */
function refresh_payment(array $pay): array {
    if ($pay['status'] === 'captured') { return $pay; }
    if ($pay['last_checked_at'] && time() - strtotime($pay['last_checked_at'] . ' UTC') < 10) { return $pay; }
    run('UPDATE {payments} SET last_checked_at=UTC_TIMESTAMP() WHERE id=?', [$pay['id']]);
    try { $r = payu_request('GET', '/payment/status/' . rawurlencode($pay['api_payment_id'])); }
    catch (PayuError) { return $pay; } // keep showing "confirming"; the webhook will still arrive
    apply_gateway_outcome($pay, ['status' => $r['status'] ?? '', 'amount' => $r['amount'] ?? null, 'currency' => $r['currency'] ?? null, 'gateway_payment_id' => $r['rzp_payment_id'] ?? null, 'method' => $r['method'] ?? null, 'error' => $r['error']['message'] ?? null]);
    return find_payment($pay['api_payment_id']) ?? $pay;
}
/** Verifies the `sig` the gateway appends in signed return mode (guide §7b). Display only: never used to mark anything paid. */
function verify_return_sig(array $q, string $secret, int $maxAge = 900): bool {
    $sig = (string) ($q['sig'] ?? ''); $p = [];
    foreach (['api_payment_id', 'status', 'payment_id', 'order_id', 'amount', 'currency', 'ts', 'error_code'] as $k) { if (isset($q[$k]) && $q[$k] !== '') { $p[$k] = (string) $q[$k]; } }
    ksort($p);
    return $secret !== '' && $sig !== '' && hash_equals(hash_hmac('sha256', http_build_query($p), $secret), $sig) && abs(time() - (int) ($p['ts'] ?? 0)) <= $maxAge;
}

// ============================ webhook ============================
/** @return array{0:int,1:string} HTTP status and short body. Pure of globals so it can be tested. */
function handle_payu_webhook(string $raw, array $headers): array {
    if (PAYU_WEBHOOK_SECRET === '') { return [503, 'webhook secret not configured']; }
    $h = array_change_key_case($headers, CASE_LOWER);
    $expected = 'sha256=' . hash_hmac('sha256', $raw, PAYU_WEBHOOK_SECRET);
    if (!hash_equals($expected, (string) ($h['x-umpi-signature'] ?? ''))) { return [401, 'bad signature']; } // verified BEFORE the body is parsed
    $e = json_decode($raw, true);
    if (!is_array($e)) { return [400, 'bad body']; }
    $event = (string) ($e['event'] ?? ''); $apiId = isset($e['api_payment_id']) ? (string) $e['api_payment_id'] : null;
    $delivery = (string) ($h['x-umpi-delivery-id'] ?? '') ?: 'h:' . hash('sha256', $raw);
    if (run('INSERT IGNORE INTO {webhook_events} (delivery_id,event,api_payment_id) VALUES (?,?,?)', [$delivery, $event, $apiId]) === 0) { return [200, 'duplicate']; }
    try {
        $result = 'ignored';
        if (in_array($event, ['payment.captured', 'payment.failed', 'payment.status_updated'], true) && $apiId) {
            $pay = find_payment($apiId);
            if (!$pay) { $result = 'unknown_payment'; }
            else {
                $status = $event === 'payment.captured' ? 'captured' : ($event === 'payment.failed' ? 'failed' : (string) ($e['status'] ?? ''));
                $result = apply_gateway_outcome($pay, ['status' => $status, 'amount' => $e['amount'] ?? null, 'currency' => $e['currency'] ?? null, 'gateway_payment_id' => $e['rzp_payment_id'] ?? null, 'method' => $e['method'] ?? null, 'error' => $e['error']['message'] ?? null]);
            }
        }
        run('UPDATE {webhook_events} SET result=? WHERE delivery_id=?', [$result, $delivery]);
        return [200, 'ok']; // unknown events are acknowledged too, otherwise the gateway keeps retrying them
    } catch (Throwable $t) {
        log_error($t); run('DELETE FROM {webhook_events} WHERE delivery_id=?', [$delivery]); // let the gateway's retry process it
        return [500, 'error'];
    }
}
