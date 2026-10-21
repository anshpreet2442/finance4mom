<?php
declare(strict_types=1);
/**
 * Email (native SMTP client or mail()) and Twilio SMS (REST over HTTPS with curl). No Composer, no SDKs.
 * Credentials are configured by the admin (stored encrypted, masked when shown, never sent to the browser) or via environment variables.
 * Every attempt is logged (email_log / sms_log). Sending never breaks the action that triggered it.
 */

const EMAIL_EVENTS = [
    'payment.received' => 'A due / charge was paid', 'bill.paid' => 'A bill payment was recorded', 'tx.payment' => 'A payment (expense) was recorded', 'shop.receipt' => 'A plugin or usage pack was bought', 'test' => 'Test message',
];
const SMS_EVENTS = ['charge.new' => 'A new charge was posted', 'charge.overdue' => 'A charge is overdue', 'charge.link' => 'Payment link sent by admin', 'test' => 'Test message'];
const TEMPLATE_VARS = ['name' => 'Recipient name', 'amount' => 'Amount, e.g. ₹1,250', 'title' => 'What it is for (bill / charge / payment)', 'bill' => 'Same as title', 'date' => 'Date of the payment', 'due_date' => 'Due date', 'account' => 'Account paid from', 'method' => 'How it was paid', 'link' => 'Link to open / pay', 'app' => 'App name'];

function default_email_template(string $event): array {
    return match ($event) {
        'payment.received' => ['Payment received: {title}', "Hi {name},\n\nWe received your payment of {amount} for {title} on {date}. Thank you!\n\nSee your dues: {link}\n\n— {app}"],
        'bill.paid' => ['Bill paid: {title} ({amount})', "Hi {name},\n\nYou recorded a payment of {amount} for {title} on {date}{account}.\n\nOpen {app}: {link}\n\n— {app}"],
        'shop.receipt' => ['Receipt: {title} ({amount})', "Hi {name},\n\nThanks for your purchase: {title}, {amount}. Your receipt is saved under Plugins: {link}\n\n— {app}"],
        'tx.payment' => ['Payment recorded: {amount}', "Hi {name},\n\nA payment of {amount} ({title}) was recorded on {date}{account}.\n\nOpen {app}: {link}\n\n— {app}"],
        default => ['Test message from {app}', "Hi {name},\n\nThis is a test email from {app}. If you can read this, your email settings work.\n\n— {app}"],
    };
}
function default_sms_template(): string { return 'Hi {name}, {title} of {amount} is due on {due_date}. Pay here: {link} - {app}'; }

function render_template(string $tpl, array $vars): string {
    return preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $tpl) ?? $tpl;
}
/** Parameters used in a template that the app does not know (typos), so the admin hears about them when saving. */
function unknown_params(string $tpl, array $known = []): array { $known = $known ?: array_keys(TEMPLATE_VARS); preg_match_all('/\{([a-zA-Z_]+)\}/', $tpl, $m); return array_values(array_unique(array_diff($m[1], $known))); }
function template_vars(array $user, array $v = []): array {
    $title = $v['title'] ?? ($v['bill'] ?? '');
    return $v + ['name' => explode(' ', trim((string) ($user['name'] ?? '')))[0] ?: 'there', 'app' => APP_NAME, 'link' => abs_url('/'), 'title' => $title, 'bill' => $title, 'amount' => '', 'date' => today(), 'due_date' => '', 'account' => '', 'method' => ''];
}

// ============================ email ============================
function email_cfg(): array {
    return [
        'enabled' => site_setting('email_enabled', '0') === '1', 'transport' => in_array(site_setting('smtp_transport', 'smtp'), ['smtp', 'mail'], true) ? site_setting('smtp_transport', 'smtp') : 'smtp',
        'host' => site_setting('smtp_host', (string) getenv('MFNC_SMTP_HOST')), 'port' => (int) site_setting('smtp_port', (string) (getenv('MFNC_SMTP_PORT') ?: 587)),
        'secure' => in_array(site_setting('smtp_secure', 'tls'), ['none', 'tls', 'ssl'], true) ? site_setting('smtp_secure', 'tls') : 'tls', 'user' => site_setting('smtp_user', (string) getenv('MFNC_SMTP_USER')),
        'pass' => secret_get('smtp_pass', 'MFNC_SMTP_PASS'), 'from' => site_setting('smtp_from', (string) getenv('MFNC_SMTP_FROM')), 'from_name' => site_setting('smtp_from_name', APP_NAME),
        'user_choice' => site_setting('email_user_choice', '1') === '1', 'timeout' => 15,
    ];
}
function email_configured(array $c): bool { return filter_var($c['from'], FILTER_VALIDATE_EMAIL) && ($c['transport'] === 'mail' || ($c['host'] !== '' && $c['port'] > 0)); }
function mime_header(string $s): string { $s = str_replace(["\r", "\n"], ' ', $s); return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }
function clean_header(string $s): string { return trim(str_replace(["\r", "\n", "\0"], ' ', $s)); }
function email_html(string $text): string {
    $body = nl2br(preg_replace_callback('~https?://[^\s<&"\']+~', function ($m) { $u = rtrim($m[0], '.,;:!?)'); return '<a href="' . $u . '">' . $u . '</a>' . substr($m[0], strlen($u)); }, e($text)) ?? e($text)); // trailing punctuation stays outside the link
    return '<!doctype html><html><body style="margin:0;background:#f5f5f3;font-family:Arial,Helvetica,sans-serif;color:#141414"><div style="max-width:520px;margin:0 auto;padding:24px"><div style="background:#fff;border-radius:16px;padding:24px;line-height:1.55;font-size:15px">' . $body . '</div></div></body></html>';
}
/** Builds the raw message (headers + multipart body), CRLF line ends. */
function build_mime(string $from, string $fromName, string $to, string $subject, string $text, string $html, string $host = '', array $attachments = []): string {
    $b = 'mfnc_' . bin2hex(random_bytes(8)); $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $h = ['Date: ' . date('r'), 'From: ' . ($fromName !== '' ? mime_header(clean_header($fromName)) . ' <' . clean_header($from) . '>' : clean_header($from)), 'To: ' . clean_header($to), 'Subject: ' . mime_header(clean_header($subject)),
        'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>', 'MIME-Version: 1.0', 'Content-Type: multipart/alternative; boundary="' . $b . '"', 'X-Mailer: Moms Finance'];
    $part = fn($type, $c) => "--$b\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($c), 76, "\r\n");
    if ($attachments) { // [['name' => 'report.pdf', 'type' => 'application/pdf', 'data' => bytes], ...]: multipart/mixed wrapping the text + html alternative
        $m = 'mfnc_m' . bin2hex(random_bytes(8)); $h[6] = 'Content-Type: multipart/mixed; boundary="' . $m . '"';
        $o = implode("\r\n", $h) . "\r\n\r\n--$m\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n" . $part('text/plain', $text) . $part('text/html', $html) . "--$b--\r\n";
        foreach ($attachments as $a) { $n = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($a['name'] ?? 'file')); $o .= "--$m\r\nContent-Type: " . preg_replace('/[^a-z0-9.+\/-]/i', '', (string) ($a['type'] ?? 'application/octet-stream')) . "; name=\"$n\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$n\"\r\n\r\n" . chunk_split(base64_encode((string) ($a['data'] ?? '')), 76, "\r\n"); }
        return $o . "--$m--\r\n";
    }
    return implode("\r\n", $h) . "\r\n\r\n" . $part('text/plain', $text) . $part('text/html', $html) . "--$b--\r\n";
}
/** Minimal SMTP client: EHLO, STARTTLS / implicit TLS, AUTH PLAIN|LOGIN, one message. Throws RuntimeException with the server's words. */
function smtp_send(array $c, string $to, string $raw): void {
    $ssl = $c['secure'] === 'ssl'; $ctx = stream_context_create(['ssl' => ['verify_peer' => !in_array($c['host'], ['127.0.0.1', 'localhost'], true), 'verify_peer_name' => !in_array($c['host'], ['127.0.0.1', 'localhost'], true)]]);
    $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'], $en, $es, (float) $c['timeout'], STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { throw new RuntimeException("Could not connect to {$c['host']}:{$c['port']} ($es)"); }
    stream_set_timeout($fp, (int) $c['timeout']);
    $read = function () use ($fp): array { $lines = []; while (($l = fgets($fp, 1024)) !== false) { $lines[] = rtrim($l); if (strlen($l) < 4 || $l[3] !== '-') { break; } } if (!$lines) { throw new RuntimeException('The mail server closed the connection.'); } return [(int) substr($lines[0], 0, 3), implode("\n", $lines)]; };
    $cmd = function (string $line, array $ok) use ($fp, $read): string { fwrite($fp, $line . "\r\n"); [$code, $txt] = $read(); if (!in_array($code, $ok, true)) { throw new RuntimeException('SMTP ' . preg_replace('/^(AUTH\s+\S+).*/', '$1', $line) . ' failed: ' . $txt); } return $txt; };
    try {
        [$code, $txt] = $read(); if ($code !== 220) { throw new RuntimeException('Unexpected greeting: ' . $txt); }
        $me = parse_url(abs_url('/'), PHP_URL_HOST) ?: 'localhost';
        $caps = $cmd('EHLO ' . $me, [250]);
        if ($c['secure'] === 'tls') {
            if (stripos($caps, 'STARTTLS') === false) { throw new RuntimeException('The server does not offer STARTTLS. Choose another security setting.'); }
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new RuntimeException('Could not start TLS with the mail server.'); }
            $caps = $cmd('EHLO ' . $me, [250]);
        }
        if ($c['user'] !== '') {
            if (preg_match('/AUTH[^\n]*\bPLAIN\b/i', $caps)) { $cmd('AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . $c['pass']), [235]); }
            else { $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($c['user']), [334]); $cmd(base64_encode($c['pass']), [235]); }
        }
        $cmd('MAIL FROM:<' . clean_header($c['from']) . '>', [250]); $cmd('RCPT TO:<' . clean_header($to) . '>', [250, 251]); $cmd('DATA', [354]);
        $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $raw)); $body = str_replace("\n", "\r\n", $body);
        fwrite($fp, rtrim($body, "\r\n") . "\r\n.\r\n"); [$code, $txt] = $read(); if ($code !== 250) { throw new RuntimeException('Message rejected: ' . $txt); }
        @fwrite($fp, "QUIT\r\n");
    } finally { @fclose($fp); }
}
/** Sends one email now and logs it. Returns [ok, error]. Never throws. */
function send_email(string $to, string $subject, string $text, ?int $uid = null, string $event = '', array $attachments = []): array {
    $c = email_cfg();
    $log = function (string $status, ?string $err) use ($uid, $to, $subject, $event) { try { run('INSERT INTO {email_log} (user_id,to_email,subject,event,status,error) VALUES (?,?,?,?,?,?)', [$uid, mb_substr($to, 0, 190), mb_substr($subject, 0, 250), $event, $status, $err ? mb_substr($err, 0, 400) : null]); } catch (Throwable) {} };
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $log('failed', 'Invalid recipient address'); return [false, 'Invalid recipient address.']; }
    if (!email_configured($c)) { $log('skipped', 'Email is not configured'); return [false, 'Email is not configured yet.']; }
    try {
        if (getenv('MFNC_MAIL_DRYRUN')) { $log('sent', null); return [true, '']; }
        $raw = build_mime($c['from'], $c['from_name'], $to, $subject, $text, email_html($text), '', $attachments);
        if ($c['transport'] === 'mail') {
            $pos = strpos($raw, "\r\n\r\n"); $hdr = substr($raw, 0, $pos); $body = substr($raw, $pos + 4);
            $hdr = implode("\r\n", array_filter(explode("\r\n", $hdr), fn($l) => !preg_match('/^(To|Subject):/i', $l)));
            if (!@mail(clean_header($to), mime_header(clean_header($subject)), $body, $hdr)) { throw new RuntimeException('PHP mail() refused the message. Use SMTP instead.'); }
        } else { smtp_send($c, $to, $raw); }
        $log('sent', null); return [true, ''];
    } catch (Throwable $e) { $log('failed', $e->getMessage()); return [false, $e->getMessage()]; }
}
function email_template(string $event): array {
    $d = default_email_template($event);
    $s = site_setting("mail_tpl_{$event}_subject", ''); $b = site_setting("mail_tpl_{$event}_body", '');
    return [$s !== '' ? $s : $d[0], $b !== '' ? $b : $d[1]];
}
/** Receipt-style email to a user for an event. $choice: null = follow the user's setting, true/false = the person's choice for this one event. */
function notify_email(int $uid, string $event, array $vars, ?bool $choice = null, bool $gated = true): bool {
    try {
        $c = email_cfg(); if (!$c['enabled'] || !email_configured($c)) { return false; }
        $u = one('SELECT u.id,u.name,u.email,u.role,p.email_alerts FROM {users} u LEFT JOIN {preferences} p ON p.user_id=u.id WHERE u.id=?', [$uid]); if (!$u) { return false; }
        $want = $choice ?? ((int) ($u['email_alerts'] ?? 1) === 1); if (!$want) { return false; }
        if ($gated) { $fa = feature_access($u, 'email'); if (!$fa['ok']) { return false; } }
        [$sub, $body] = email_template($event); $v = template_vars($u, $vars);
        [$ok] = send_email($u['email'], render_template($sub, $v), render_template($body, $v), $uid, $event);
        if ($ok && $gated) { charge_unit($u, 'email', 'Email: ' . $event); }
        return $ok;
    } catch (Throwable $e) { log_error($e); return false; }
}
/** Pay-as-you-go: deduct the per-message price from the wallet when that feature is metered. */
function charge_unit(array $u, string $feature, string $note): void {
    if (is_exempt_user($u)) { return; } $c = feature_cfg($feature);
    if ($c['mode'] === 'payg' && $c['unit_micro'] > 0) { wallet_add((int) $u['id'], -$c['unit_micro'], 'usage', $note); }
}

// ============================ Twilio SMS ============================
function sms_cfg(): array {
    return ['enabled' => site_setting('sms_enabled', '0') === '1', 'sid' => site_setting('twilio_sid', (string) getenv('TWILIO_ACCOUNT_SID')), 'token' => secret_get('twilio_token', 'TWILIO_AUTH_TOKEN'), 'from' => site_setting('twilio_from', (string) getenv('TWILIO_FROM')),
        'cc' => site_setting('sms_default_cc', '+91'), 'template' => site_setting('sms_template', '') ?: default_sms_template(), 'auto' => site_setting('sms_auto_charges', '1') === '1', 'direct_link' => site_setting('sms_direct_link', '0') === '1',
        'base' => rtrim((string) (getenv('MFNC_TWILIO_BASE') ?: 'https://api.twilio.com'), '/')];
}
function sms_configured(array $c): bool { return preg_match('/^AC[0-9a-zA-Z]{8,}$/', $c['sid']) === 1 && $c['token'] !== '' && $c['from'] !== ''; }
function normalize_phone(string $p, string $cc = '+91'): ?string {
    $p = preg_replace('/[\s\-().]/', '', trim($p)) ?? ''; if ($p === '') { return null; }
    if (str_starts_with($p, '00')) { $p = '+' . substr($p, 2); }
    if ($p[0] !== '+') { $p = ltrim($p, '0'); $p = $cc . $p; }
    return preg_match('/^\+[1-9]\d{7,14}$/', $p) ? $p : null;
}
function twilio_post(array $c, string $to, string $body): array {
    $ch = curl_init($c['base'] . '/2010-04-01/Accounts/' . rawurlencode($c['sid']) . '/Messages.json');
    $local = (bool) preg_match('~^http://(127\.0\.0\.1|localhost)~', $c['base']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERPWD => $c['sid'] . ':' . $c['token'],
        CURLOPT_POSTFIELDS => http_build_query(['To' => $to, 'From' => $c['from'], 'Body' => $body]), CURLOPT_SSL_VERIFYPEER => !$local, CURLOPT_SSL_VERIFYHOST => $local ? 0 : 2]);
    $raw = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch);
    if ($raw === false) { throw new RuntimeException('Could not reach Twilio (' . $err . ').'); }
    $r = json_decode((string) $raw, true) ?: [];
    if ($http >= 300) { throw new RuntimeException('Twilio refused the message: ' . ($r['message'] ?? "HTTP $http") . (isset($r['code']) ? ' (code ' . $r['code'] . ')' : '')); }
    return $r;
}
/** Sends one SMS and logs it. Returns [ok, error]. Never throws. */
function send_sms(string $phone, string $body, ?int $uid = null, string $event = ''): array {
    $c = sms_cfg();
    $log = function (string $st, ?string $sid, ?string $err) use ($uid, $phone, $body, $event) { try { run('INSERT INTO {sms_log} (user_id,to_phone,body,event,status,sid,error) VALUES (?,?,?,?,?,?,?)', [$uid, mb_substr($phone, 0, 30), mb_substr($body, 0, 700), $event, $st, $sid, $err ? mb_substr($err, 0, 400) : null]); } catch (Throwable) {} };
    $to = normalize_phone($phone, $c['cc']); if (!$to) { $log('failed', null, 'Invalid phone number'); return [false, 'That phone number does not look right. Use the international format, e.g. +919876543210.']; }
    if (!sms_configured($c)) { $log('skipped', null, 'SMS is not configured'); return [false, 'SMS is not configured yet.']; }
    try { $r = twilio_post($c, $to, $body); $log('sent', (string) ($r['sid'] ?? ''), null); return [true, '']; }
    catch (Throwable $e) { $log('failed', null, $e->getMessage()); return [false, $e->getMessage()]; }
}
function charge_link(int $uid, array $charge, array $c): string {
    if ($c['direct_link'] && payu_configured()) { try { return start_payment($uid, (int) $charge['id']); } catch (Throwable) {} }
    return abs_url('/dues?charge=' . (int) $charge['id']);
}
/** Alert about a charge with a payment link. $kind: charge.new | charge.overdue | charge.link. Respects the global switch and the person's phone/opt-out unless $force. */
function sms_charge_alert(int $uid, array $charge, string $kind, bool $force = false): array {
    try {
        $c = sms_cfg(); if (!$c['enabled']) { return [false, 'SMS alerts are switched off.']; }
        $u = one('SELECT u.id,u.name,u.role,p.phone,p.sms_alerts FROM {users} u LEFT JOIN {preferences} p ON p.user_id=u.id WHERE u.id=?', [$uid]); if (!$u || !$u['phone']) { return [false, 'This person has not added a phone number.']; }
        if (!$force && !(int) $u['sms_alerts']) { return [false, 'This person turned SMS alerts off.']; }
        $v = template_vars($u, ['title' => $charge['title'], 'amount' => fmt_inr(charge_payable($charge)), 'due_date' => date_label($charge['due_date']), 'link' => charge_link($uid, $charge, $c)]);
        return send_sms((string) $u['phone'], render_template($c['template'], $v), $uid, $kind);
    } catch (Throwable $e) { log_error($e); return [false, 'Could not send the SMS.']; }
}
