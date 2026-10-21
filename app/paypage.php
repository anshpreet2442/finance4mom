<?php
declare(strict_types=1);
/**
 * Payment page template (H5). The administrator edits the HTML that accompanies a checkout (sent to the gateway as `page_html` in the init call and shown on /pay/page).
 *  - the template is sanitised with an allow-list (tags, attributes, CSS properties, URL schemes) when it is saved AND again every time it is rendered
 *  - {parameters} are replaced with HTML-escaped values after sanitising, in one pass (a value can never inject another parameter)
 *  - amount, hash/signature, checkout address and the pay button are produced by the server; the template can only place {pay_button}, never change what it does
 *  - every save is a version (list, preview, roll back); reset to default; preview runs in a sandboxed iframe without scripts
 */
const PAYPAGE_MAX = 20000;
const PAYPAGE_VERSIONS_KEEP = 50;
const PAYPAGE_PARAMS = [
    'app_name' => 'Name of this app', 'app_url' => 'Address of this site', 'logo_url' => 'App logo image address', 'amount' => 'Amount due, e.g. ₹1,250', 'late_fee' => 'Late fee added, if any', 'total_payable' => 'Amount plus any late fee',
    'charge_title' => 'What the payment is for', 'charge_description' => 'Extra details of the charge', 'payer_name' => 'Name of the person paying', 'due_date' => 'Due date', 'support_email' => 'Support email address',
    'return_link' => 'Where the payer returns after paying', 'order_ref' => 'Reference number of the charge', 'pay_button' => 'The Pay now button (added by the server)',
];
const PAYPAGE_URL_PARAMS = ['app_url', 'logo_url', 'return_link'];
const PAYPAGE_TAGS = ['div' => [], 'span' => [], 'p' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'small' => [], 'br' => [], 'hr' => [], 'ul' => [], 'ol' => [], 'li' => [], 'section' => [], 'header' => [], 'footer' => [], 'blockquote' => [],
    'a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height', 'title'], 'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan']];
const PAYPAGE_DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea', 'select', 'option', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'audio', 'video', 'source', 'canvas', 'title', 'head', 'xml'];
const PAYPAGE_CSS = ['color', 'background', 'background-color', 'font-size', 'font-weight', 'font-style', 'font-family', 'text-align', 'text-transform', 'text-decoration', 'letter-spacing', 'line-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-width', 'border-style', 'border-radius', 'width', 'max-width', 'min-width', 'height', 'display', 'vertical-align', 'white-space', 'opacity'];

function paypage_default(): string {
    return '<div style="max-width:520px;margin:0 auto;padding:24px;font-family:Arial,Helvetica,sans-serif;color:#1e2823">' . "\n"
        . '  <div style="text-align:center;margin-bottom:16px"><img src="{logo_url}" alt="{app_name}" width="56" height="56"></div>' . "\n"
        . '  <h2 style="text-align:center;margin:0 0 4px">{charge_title}</h2>' . "\n"
        . '  <p style="text-align:center;color:#5f6e66;margin:0 0 20px">{charge_description}</p>' . "\n"
        . '  <div style="background:#f3f6f3;border-radius:14px;padding:18px;text-align:center">' . "\n"
        . '    <p style="margin:0;color:#5f6e66;font-size:13px">Amount to pay</p>' . "\n"
        . '    <p style="margin:4px 0 0;font-size:32px;font-weight:bold">{total_payable}</p>' . "\n"
        . '    <p style="margin:6px 0 0;color:#5f6e66;font-size:13px">Due {due_date} &middot; Ref {order_ref}</p>' . "\n"
        . '  </div>' . "\n"
        . '  <p style="margin:18px 0 6px">Hi {payer_name}, this payment is for {app_name}.</p>' . "\n"
        . '  <div style="text-align:center;margin:18px 0">{pay_button}</div>' . "\n"
        . '  <p style="color:#5f6e66;font-size:12px;text-align:center">Questions? Write to <a href="mailto:{support_email}">{support_email}</a> &middot; <a href="{return_link}">Back to {app_name}</a></p>' . "\n"
        . '</div>';
}
function paypage_safe_url(string $v, bool $img = false): ?string {
    $v = trim(preg_replace('/[\x00-\x20\x7f]+/', '', $v) ?? ''); if ($v === '') { return null; }
    $ph = str_ireplace(['%7B', '%7D'], ['{', '}'], $v); if (preg_match('/^\{(' . implode('|', array_diff(array_keys(PAYPAGE_PARAMS), ['pay_button'])) . ')\}$/', $ph)) { return $ph; } // {pay_button} is markup: never allowed inside an attribute
    if (preg_match('~^https?://[^\s"<>\\\\]+$~i', $v)) { return $v; } if (!$img && preg_match('~^(mailto:|tel:)[^\s"<>\\\\]+$~i', $v)) { return $v; }
    if (!$img && preg_match('~^mailto:\{support_email\}$~i', $v)) { return $v; }
    if ($img && strlen($v) < 200000 && preg_match('~^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$~i', $v)) { return $v; }
    return null;
}
function paypage_safe_css(string $css): string {
    $out = []; foreach (explode(';', $css) as $decl) {
        if (!str_contains($decl, ':')) { continue; } [$p, $v] = array_map('trim', explode(':', $decl, 2)); $p = strtolower($p); $v = trim($v); if (!in_array($p, PAYPAGE_CSS, true) || $v === '' || strlen($v) > 120) { continue; }
        if (preg_match('/url\s*\(|expression|javascript|behaviou?r|@import|[\\\\<>{}]|var\s*\(|calc\s*\(|image-set|\/\*|-moz-binding/i', $v)) { continue; } if (!preg_match('/^[#%(),.\w\s"\'\/-]*$/u', $v)) { continue; }
        if ($p === 'display' && !in_array(strtolower($v), ['block', 'inline', 'inline-block', 'flex', 'none', 'table'], true)) { continue; } $out[] = $p . ':' . $v;
    }
    return implode(';', $out);
}
/** Returns safe HTML (placeholders still in place). Unsafe tags are removed, unknown tags unwrapped, every attribute checked. */
function paypage_sanitize(string $html): string {
    $html = mb_substr($html, 0, PAYPAGE_MAX); if (trim($html) === '') { return ''; } $doc = new DOMDocument(); $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="pp-root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR); libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $doc->getElementById('pp-root'); if (!$root) { return ''; }
    $walk = function (DOMNode $n) use (&$walk, $doc) {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c instanceof DOMComment || $c instanceof DOMProcessingInstruction || $c instanceof DOMDocumentType || $c instanceof DOMCdataSection) { $n->removeChild($c); continue; } if (!($c instanceof DOMElement)) { continue; }
            $tag = strtolower($c->tagName);
            if (in_array($tag, PAYPAGE_DROP, true)) { $n->removeChild($c); continue; }
            if (!isset(PAYPAGE_TAGS[$tag])) { $walk($c); while ($c->firstChild) { $n->insertBefore($c->firstChild, $c); } $n->removeChild($c); continue; } // unknown tag: keep its text, lose the tag
            foreach (iterator_to_array($c->attributes) as $a) {
                $name = strtolower($a->name); $val = $a->value; $c->removeAttributeNode($a); if (str_starts_with($name, 'on')) { continue; } if (preg_match('/(?:\{|%7B)\s*pay_button\s*(?:\}|%7D)/i', $val)) { continue; }
                if ($name === 'style') { $s = paypage_safe_css($val); if ($s !== '') { $c->setAttribute('style', $s); } }
                elseif ($name === 'class') { $k = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $val) ?? ''); if ($k !== '') { $c->setAttribute('class', $k); } }
                elseif (in_array($name, PAYPAGE_TAGS[$tag], true)) {
                    if ($name === 'href' || $name === 'src') { $u = paypage_safe_url($val, $name === 'src'); if ($u !== null) { $c->setAttribute($name, $u); } }
                    elseif (in_array($name, ['width', 'height', 'colspan', 'rowspan'], true)) { if (preg_match('/^\d{1,4}$/', $val)) { $c->setAttribute($name, $val); } } else { $c->setAttribute($name, mb_substr($val, 0, 200)); } }
            }
            if ($tag === 'a') { $c->setAttribute('rel', 'noopener noreferrer nofollow'); $c->setAttribute('target', '_blank'); } if ($tag === 'img' && !$c->hasAttribute('alt')) { $c->setAttribute('alt', ''); }
            $walk($c);
        }
    };
    $walk($root); $o = ''; foreach ($root->childNodes as $c) { $o .= $doc->saveHTML($c); }
    $o = preg_replace_callback('/%7B([a-z_]+)%7D/i', fn($m) => isset(PAYPAGE_PARAMS[strtolower($m[1])]) ? '{' . strtolower($m[1]) . '}' : $m[0], $o) ?? $o; // the DOM URL-encodes braces in href/src; keep the readable form in the editor
    return trim($o);
}
function paypage_unknown_params(string $tpl): array { preg_match_all('/\{([a-zA-Z_]+)\}/', $tpl, $m); return array_values(array_unique(array_diff($m[1], array_keys(PAYPAGE_PARAMS)))); }
/** Substitutes escaped values into safe HTML in a single pass. Unknown {names} are left as they are. */
function paypage_fill(string $safe, array $vars, string $payButton = ''): string {
    return (string) preg_replace_callback('/(?:\{|%7B)([a-z_]+)(?:\}|%7D)/i', function ($m) use ($vars, $payButton) {
        $k = strtolower($m[1]); if ($k === 'pay_button') { return $payButton; } if (!isset(PAYPAGE_PARAMS[$k])) { return $m[0]; } $v = (string) ($vars[$k] ?? '');
        if (in_array($k, PAYPAGE_URL_PARAMS, true) && !preg_match('~^https?://~i', $v)) { $v = ''; } return e($v);
    }, $safe);
}
function paypage_sample(): array {
    return ['app_name' => APP_NAME, 'app_url' => abs_url('/'), 'logo_url' => abs_url('/assets/icons/icon-192.png'), 'amount' => '₹1,250', 'late_fee' => '₹0', 'total_payable' => '₹1,250', 'charge_title' => 'Monthly plan · October', 'charge_description' => 'Your plan for October 2026', 'payer_name' => 'Asha',
        'due_date' => '28 Oct 2026', 'support_email' => paypage_support_email(), 'return_link' => abs_url('/dues/return'), 'order_ref' => 'C-1042'];
}
function paypage_support_email(): string { $e = site_setting('support_email', ''); if ($e === '') { $e = site_setting('smtp_from', ''); } return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : 'support@example.com'; }
function paypage_vars(array $charge, array $user): array {
    $payable = charge_payable($charge); return ['app_name' => APP_NAME, 'app_url' => abs_url('/'), 'logo_url' => abs_url('/assets/icons/icon-192.png'), 'amount' => fmt_inr((int) $charge['amount']), 'late_fee' => fmt_inr($charge['late_fee_waived'] ? 0 : (int) $charge['late_fee']), 'total_payable' => fmt_inr($payable),
        'charge_title' => (string) $charge['title'], 'charge_description' => (string) ($charge['description'] ?? ''), 'payer_name' => (string) ($user['name'] ?? ''), 'due_date' => date_label((string) $charge['due_date']), 'support_email' => paypage_support_email(), 'return_link' => abs_url('/dues/return'), 'order_ref' => 'C-' . (int) $charge['id']];
}
// ============================ storage + versions ============================
function paypage_current(): string { $h = site_setting('paypage_html', ''); return $h === '' ? paypage_default() : $h; }
function paypage_enabled(): bool { return site_setting('paypage_enabled', '0') === '1'; }
function paypage_versions(int $limit = 50): array { return all('SELECT v.id,v.note,v.created_at,LENGTH(v.html) AS size,u.name AS by_name FROM {c_pay_tpl} v LEFT JOIN {users} u ON u.id=v.created_by ORDER BY v.id DESC LIMIT ' . (int) $limit); }
/** Sanitises and stores a new version (no-op when identical to the current one). Returns the stored HTML. */
function paypage_save(string $html, ?int $by, string $note = ''): string {
    if (mb_strlen($html) > PAYPAGE_MAX) { throw new UserError('The template is too long (limit ' . number_format(PAYPAGE_MAX) . ' characters).'); }
    $safe = paypage_sanitize($html); if ($safe === '') { throw new UserError('The template is empty after cleaning. Add some text or a heading.'); }
    if (!str_contains($safe, '{total_payable}') && !str_contains($safe, '{amount}')) { throw new UserError('Show the amount somewhere with {total_payable} (or {amount}) so payers know what they are paying.'); }
    if ($safe === paypage_current()) { return $safe; } set_setting('paypage_html', $safe);
    insert('INSERT INTO {c_pay_tpl} (html,note,created_by) VALUES (?,?,?)', [$safe, mb_substr($note, 0, 120), $by]); run('DELETE FROM {c_pay_tpl} WHERE id NOT IN (SELECT id FROM (SELECT id FROM {c_pay_tpl} ORDER BY id DESC LIMIT ' . PAYPAGE_VERSIONS_KEEP . ') x)'); return $safe;
}
function paypage_reset(?int $by): void { set_setting('paypage_html', ''); insert('INSERT INTO {c_pay_tpl} (html,note,created_by) VALUES (?,?,?)', [paypage_sanitize(paypage_default()), 'Reset to default', $by]); }
function paypage_rollback(int $id, ?int $by): void { $r = one('SELECT html FROM {c_pay_tpl} WHERE id=?', [$id]); if (!$r) { throw new UserError('That version does not exist.'); } paypage_save((string) $r['html'], $by, 'Rolled back to version ' . $id); }
// ============================ gateway payload ============================
/** Extra fields for the checkout init call. Only `page_html` (sanitised, filled, no pay button) and a flag; amount, redirect URLs and signatures stay in start_payment(). */
function paypage_extra(array $charge, array $user): array {
    if (!paypage_enabled()) { return []; } $safe = paypage_sanitize(paypage_current()); if ($safe === '') { return []; }
    return ['page_html' => paypage_fill($safe, paypage_vars($charge, $user), '')];
}
// ============================ pages ============================
function paypage_shell(string $bodyHtml): string {
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><meta name="color-scheme" content="light"><style>html{background:#fff}body{margin:0;padding:12px;background:#fff;font:15px/1.5 Arial,Helvetica,sans-serif;color:#1e2823}img{max-width:100%;height:auto}table{border-collapse:collapse}</style></head><body>' . $bodyHtml . '</body></html>';
}
/** The sandboxed preview for the admin. Sample data and a disabled stand-in for the pay button. */
function paypage_preview_html(string $tpl): string {
    $safe = paypage_sanitize($tpl); return paypage_shell(paypage_fill($safe, paypage_sample(), '<span style="display:inline-block;padding:12px 26px;border-radius:12px;background:#0b6b4d;color:#fff;font-weight:bold">Pay now</span>'));
}
function paypage_preview_http(): void {
    require_admin(); $u = current_user(); start_session(); $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''); if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) { c_json(['ok' => false, 'message' => 'Reload the page.'], 403); }
    $body = json_decode((string) file_get_contents('php://input', false, null, 0, 90000), true); $tpl = is_array($body) ? (string) ($body['html'] ?? '') : '';
    c_json(['ok' => true, 'html' => paypage_preview_html($tpl), 'unknown' => paypage_unknown_params($tpl), 'length' => mb_strlen($tpl)]);
}
/** /pay/page?charge=N : what the payer sees before the secure checkout, with the real (server-built) Pay button. */
function paypage_http_public(): void {
    $u = require_login(); $c = one('SELECT * FROM {charges} WHERE id=? AND user_id=?', [(int) ($_GET['charge'] ?? 0), $u['id']]); if (!$c || $c['status'] !== 'pending') { render_error(404, 'Not found', 'That payment is not open.'); return; }
    $btn = post_btn('Pay ' . e(fmt_inr(charge_payable($c))), 'dues-pay', ['charge_id' => $c['id']], 'btn btn-dark', 'style="display:inline-block;padding:12px 26px;border-radius:12px;background:#0b6b4d;color:#fff;font-weight:bold;border:0;cursor:pointer"');
    c_hdr('Content-Type: text/html; charset=utf-8'); c_hdr("Content-Security-Policy: default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"); c_hdr('Cache-Control: no-store');
    echo paypage_shell(paypage_fill(paypage_sanitize(paypage_current()), paypage_vars($c, $u), $btn));
}
function page_admin_paypage(): void {
    $u = require_admin(); admin_start('Payment page', 'paypage'); $cur = paypage_current(); $vers = paypage_versions(15);
    echo '<link rel="stylesheet" href="' . e(asset('c.css')) . '"><div class="grid gap-5 xl:grid-cols-2">';
    card_open('Payment page HTML', 'Shown with each checkout and sent to the gateway. ' . (paypage_enabled() ? badge('Sent to gateway', 'pos') : badge('Not sent yet', 'warn')));
    echo form_open('admin-paypage-save', 'space-y-3') . '<div><label class="label" for="pp-html">Template</label><textarea class="input font-mono text-xs" id="pp-html" name="html" rows="18" maxlength="' . PAYPAGE_MAX . '" spellcheck="false" data-pp-editor>' . e($cur) . '</textarea>'
        . '<p class="mt-1 text-xs text-mute"><span data-pp-count>' . mb_strlen($cur) . '</span> / ' . number_format(PAYPAGE_MAX) . ' characters. Scripts, forms, event handlers and unsafe links are removed automatically.</p></div>'
        . '<div><p class="label">Parameters <span class="font-normal text-mute">(tap to insert)</span></p><div class="flex flex-wrap gap-1.5">';
    foreach (PAYPAGE_PARAMS as $k => $d) { echo '<button type="button" class="chip" data-pp-insert="{' . e($k) . '}" title="' . e($d) . '">{' . e($k) . '}</button>'; }
    echo '</div></div><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="enabled" value="1" class="size-4 accent-[var(--lime-dk)]"' . (paypage_enabled() ? ' checked' : '') . '> Send this page to the payment gateway with every checkout</label>'
        . '<p class="rounded-2xl bg-soft p-3 text-xs text-mute">The amount, the signature, the checkout address and the Pay button are produced by the server. You can place <code>{pay_button}</code> but cannot change what it does or any payment field.</p>'
        . '<div class="flex flex-wrap justify-between gap-2"><button class="btn btn-ghost btn-sm" name="reset" value="1" formnovalidate>Reset to default</button><button class="btn btn-dark">Save new version</button></div></form>'; card_close();
    echo '<div class="space-y-5">'; card_open('Live preview', 'Sample data in an isolated frame. No scripts can run in it.');
    echo '<iframe title="Payment page preview" class="pp-frame" sandbox="" referrerpolicy="no-referrer" data-pp-frame srcdoc="' . e(paypage_preview_html($cur)) . '"></iframe><p class="mt-2 text-xs text-mute" data-pp-warn></p>'; card_close();
    card_open('Send a test', 'Emails the rendered page, with sample data, to you.'); echo simple_form('admin-paypage-test', [['name' => 'to', 'label' => 'Send to', 'type' => 'email', 'required' => true, 'value' => $u['email']]], 'Send test email', 'btn btn-dark'); card_close();
    card_open('Version history', 'Every save is kept (latest ' . PAYPAGE_VERSIONS_KEEP . '). Roll back any time.');
    if (!$vers) { echo '<p class="text-sm text-mute">No saved versions yet. The built-in default is in use until you save.</p>'; }
    else { echo '<ul class="divide-y divide-line text-sm">'; foreach ($vers as $i => $v) { echo '<li class="flex flex-wrap items-center justify-between gap-2 py-2.5"><span><span class="font-medium">#' . (int) $v['id'] . '</span> <span class="text-mute">' . e(substr((string) $v['created_at'], 0, 16)) . ' UTC' . ($v['by_name'] ? ' · ' . e($v['by_name']) : '') . ($v['note'] ? ' · ' . e($v['note']) : '') . ' · ' . number_format((int) $v['size']) . ' chars</span>' . ($i === 0 ? ' ' . badge('Current', 'pos') : '') . '</span>'
        . ($i === 0 ? '' : post_btn('Roll back', 'admin-paypage-rollback', ['id' => $v['id']], 'btn btn-sm btn-ghost')) . '</li>'; } echo '</ul>'; }
    card_close(); echo '</div></div><script>window.PAYPAGE_PREVIEW=' . json_encode(url('/admin/payment-page/preview')) . ';</script><script src="' . e(asset('paypage.js')) . '" defer></script>'; admin_end();
}
// ============================ POST handlers ============================
function do_admin_paypage_save(array $u): string {
    if (!empty($_POST['reset'])) { paypage_reset((int) $u['id']); audit('admin.paypage', 'site', 'reset'); return 'Reset to the default template.'; }
    $before = paypage_current(); $safe = paypage_save((string) ($_POST['html'] ?? ''), (int) $u['id'], 'Edited'); set_setting('paypage_enabled', !empty($_POST['enabled']) ? '1' : '0'); audit('admin.paypage', 'site', 'saved');
    $unknown = paypage_unknown_params((string) ($_POST['html'] ?? '')); $changed = $safe !== trim($_POST['html'] ?? '') ? ' Some unsafe or unsupported parts were removed.' : '';
    return 'Saved as a new version.' . ($unknown ? ' Unknown parameters left as they are: {' . implode('}, {', $unknown) . '}.' : '') . $changed;
}
function do_admin_paypage_rollback(array $u): string { paypage_rollback(in_id('id'), (int) $u['id']); audit('admin.paypage', 'site', 'rollback'); return 'Rolled back. It is now the newest version.'; }
function do_admin_paypage_test(array $u): string {
    $to = in_str('to', 190); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { throw new UserError('Enter a valid email address.'); } $c = email_cfg(); if (!email_configured($c)) { throw new UserError('Email is not configured yet (Admin, Email), so the test cannot be sent. The preview on this page shows the same result.'); }
    $html = paypage_preview_html(paypage_current()); $text = 'This is a test of your payment page template (' . APP_NAME . "). Open it in an HTML-capable mail app.";
    try {
        if (getenv('MFNC_MAIL_DRYRUN')) { run('INSERT INTO {email_log} (user_id,to_email,subject,event,status,error) VALUES (?,?,?,?,?,NULL)', [$u['id'], mb_substr($to, 0, 190), 'Payment page test', 'paypage.test', 'sent']); return 'Test sent to ' . $to . '.'; }
        $raw = build_mime($c['from'], $c['from_name'], $to, APP_NAME . ': payment page test', $text, $html);
        if ($c['transport'] === 'mail') { $pos = strpos($raw, "\r\n\r\n"); $hdr = implode("\r\n", array_filter(explode("\r\n", substr($raw, 0, $pos)), fn($l) => !preg_match('/^(To|Subject):/i', $l))); if (!@mail(clean_header($to), mime_header(APP_NAME . ': payment page test'), substr($raw, $pos + 4), $hdr)) { throw new RuntimeException('PHP mail() refused the message.'); } } else { smtp_send($c, $to, $raw); }
        run('INSERT INTO {email_log} (user_id,to_email,subject,event,status,error) VALUES (?,?,?,?,?,NULL)', [$u['id'], mb_substr($to, 0, 190), 'Payment page test', 'paypage.test', 'sent']);
    } catch (Throwable $e) { throw new UserError('The test email failed: ' . $e->getMessage()); }
    return 'Test sent to ' . $to . '.';
}
