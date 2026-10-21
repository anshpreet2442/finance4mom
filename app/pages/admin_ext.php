<?php
declare(strict_types=1);
/** Admin pages for email, SMS, plans & usage (and the AI agent, see admin_ai.php). */

function secret_field(string $name, string $label, string $stored, string $hint = ''): array {
    return ['name' => $name, 'label' => $label . ($stored !== '' ? ' (saved: ' . mask_secret($stored) . ')' : ''), 'type' => 'password', 'autocomplete' => 'new-password', 'placeholder' => $stored !== '' ? 'Leave blank to keep the saved value' : 'Not set', 'hint' => $hint];
}
function vars_help(array $vars): string {
    $o = '<p class="mt-2 text-xs text-mute">Parameters: '; foreach ($vars as $k => $d) { $o .= '<code class="rounded bg-soft px-1" title="' . e($d) . '">{' . e($k) . '}</code> '; } return $o . '</p>';
}
function status_pill(string $s): string { return badge(ucfirst($s), $s === 'sent' ? 'pos' : ($s === 'failed' ? 'neg' : 'warn')); }
function log_table(array $rows, array $cols): string {
    if (!$rows) { return '<p class="p-6 text-sm text-mute">Nothing sent yet.</p>'; }
    $o = '<div class="relative overflow-x-auto"><table class="w-full min-w-[640px]"><thead><tr class="border-b border-line"><th class="th">When (UTC)</th>';
    foreach ($cols as $l) { $o .= '<th class="th">' . e($l) . '</th>'; } $o .= '<th class="th">Status</th></tr></thead><tbody class="divide-y divide-line">';
    foreach ($rows as $r) { $o .= '<tr><td class="td num whitespace-nowrap text-mute">' . e($r['created_at']) . '</td>'; foreach (array_keys($cols) as $k) { $o .= '<td class="td max-w-[22rem] truncate">' . e((string) $r[$k]) . '</td>'; }
        $o .= '<td class="td">' . status_pill($r['status']) . ($r['error'] ? '<div class="max-w-[16rem] truncate text-xs text-mute" title="' . e($r['error']) . '">' . e($r['error']) . '</div>' : '') . '</td></tr>'; }
    return $o . '</tbody></table></div>';
}

function page_admin_email(): void {
    $me = require_admin(); admin_start('Email', 'email'); $c = email_cfg();
    $stat = !$c['enabled'] ? badge('Off', 'warn') : (email_configured($c) ? badge('On', 'pos') : badge('Not configured', 'neg'));
    echo '<div class="grid gap-5 lg:grid-cols-2">';
    card_open('Email settings', 'Sent when a payment is received or recorded. ' . $stat);
    echo simple_form('admin-email-settings', [
        ['name' => 'email_enabled', 'label' => 'Send emails (global switch)', 'type' => 'checkbox', 'value' => $c['enabled']],
        ['name' => 'email_user_choice', 'label' => 'Let people choose, per payment, whether to email them', 'type' => 'checkbox', 'value' => $c['user_choice']],
        ['name' => 'smtp_transport', 'label' => 'Send using', 'type' => 'select', 'required' => true, 'options' => ['smtp' => 'SMTP server', 'mail' => 'PHP mail() (host default)'], 'value' => $c['transport']],
        ['name' => 'smtp_host', 'label' => 'SMTP host', 'half' => true, 'value' => $c['host'], 'placeholder' => 'smtp.example.com', 'autocomplete' => 'off'],
        ['name' => 'smtp_port', 'label' => 'Port', 'type' => 'number', 'min' => 1, 'max' => 65535, 'half' => true, 'value' => $c['port']],
        ['name' => 'smtp_secure', 'label' => 'Security', 'type' => 'select', 'required' => true, 'half' => true, 'options' => ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None (25, local only)'], 'value' => $c['secure']],
        ['name' => 'smtp_user', 'label' => 'Username', 'half' => true, 'value' => $c['user'], 'autocomplete' => 'off'],
        secret_field('smtp_pass', 'Password', $c['pass'], 'Stored encrypted on the server. Or set MFNC_SMTP_PASS in the environment.'),
        ['name' => 'smtp_pass_clear', 'label' => 'Remove the saved password', 'type' => 'checkbox', 'value' => false],
        ['name' => 'smtp_from', 'label' => 'From address', 'type' => 'email', 'half' => true, 'value' => $c['from'], 'placeholder' => 'no-reply@yourdomain.com'],
        ['name' => 'smtp_from_name', 'label' => 'From name', 'half' => true, 'value' => $c['from_name']],
    ], 'Save email settings'); card_close();
    echo '<div class="space-y-5">'; card_open('Send a test email', 'Uses the saved settings above.');
    echo simple_form('admin-email-test', [['name' => 'to', 'label' => 'Send to', 'type' => 'email', 'required' => true, 'value' => $me['email']]], 'Send test email', 'btn btn-dark'); card_close();
    card_open('Who gets what', ''); echo '<ul class="space-y-1.5 text-sm text-mute"><li>• Every user can switch email receipts on or off in <a class="underline" href="' . e(url('/settings')) . '">Settings</a>.</li><li>• When someone records a bill payment or an expense they can tick “Email me a receipt” for that one payment' . ($c['user_choice'] ? '' : ' (disabled by you above)') . '.</li><li>• Dues paid through the gateway always email the payer (if email is on).</li></ul>'; card_close(); echo '</div></div>';
    echo '<div class="mt-5">'; card_open('Email templates', 'Subject and body per event. Leave a field empty to use the default.');
    $rows = ''; foreach (EMAIL_EVENTS as $ev => $label) { if ($ev === 'test') { continue; } [$sub, $body] = email_template($ev);
        $rows .= '<div class="rounded-2xl border border-line p-4"><p class="mb-3 text-sm font-semibold">' . e($label) . ' <span class="font-mono text-xs font-normal text-mute">' . e($ev) . '</span></p>'
            . '<label class="label" for="s-' . e($ev) . '">Subject</label><input class="input" id="s-' . e($ev) . '" name="subject[' . e($ev) . ']" value="' . e($sub) . '" maxlength="200">'
            . '<label class="label mt-3" for="b-' . e($ev) . '">Message</label><textarea class="input" id="b-' . e($ev) . '" name="body[' . e($ev) . ']" rows="5" maxlength="3000">' . e($body) . '</textarea>'
            . '<div class="mt-2 flex flex-wrap items-center justify-between gap-2"><button type="submit" name="preview" value="' . e($ev) . '" class="btn btn-sm">Preview</button><button type="submit" name="reset" value="' . e($ev) . '" class="btn btn-ghost btn-sm" formnovalidate>Reset to default</button></div></div>'; }
    echo form_open('admin-email-templates', 'space-y-4') . $rows . vars_help(TEMPLATE_VARS) . '<div class="flex justify-end"><button class="btn btn-dark">Save templates</button></div></form>';
    $pv = $_SESSION['email_preview'] ?? null; unset($_SESSION['email_preview']);
    if ($pv) { echo '<div class="mt-4 rounded-2xl bg-soft p-4 text-sm"><p class="text-xs text-mute">Preview</p><p class="font-semibold">' . e($pv[0]) . '</p><pre class="mt-2 whitespace-pre-wrap font-sans">' . e($pv[1]) . '</pre></div>'; }
    card_close(); echo '</div>';
    echo '<div class="mt-5">'; card_flush_open('Email log', 'Latest 50 attempts.');
    echo log_table(all('SELECT created_at,to_email,subject,event,status,error FROM {email_log} ORDER BY id DESC LIMIT 50'), ['to_email' => 'To', 'subject' => 'Subject', 'event' => 'Event']) . '</div></section></div>'; admin_end();
}

function page_admin_sms(): void {
    $me = require_admin(); admin_start('SMS', 'sms'); $c = sms_cfg();
    $stat = !$c['enabled'] ? badge('Off', 'warn') : (sms_configured($c) ? badge('On', 'pos') : badge('Not configured', 'neg'));
    echo '<div class="grid gap-5 lg:grid-cols-2">';
    card_open('Twilio SMS', 'Text people a payment link when a charge is posted or overdue. ' . $stat);
    echo simple_form('admin-sms-settings', [
        ['name' => 'sms_enabled', 'label' => 'Send SMS alerts (global switch)', 'type' => 'checkbox', 'value' => $c['enabled']],
        ['name' => 'sms_auto_charges', 'label' => 'Automatically text when a charge is posted or goes overdue', 'type' => 'checkbox', 'value' => $c['auto']],
        ['name' => 'sms_direct_link', 'label' => 'Put the gateway checkout link in the text (expires in 30 minutes)', 'type' => 'checkbox', 'value' => $c['direct_link']],
        ['name' => 'twilio_sid', 'label' => 'Account SID', 'value' => $c['sid'], 'placeholder' => 'ACxxxxxxxxxxxxxxxx', 'autocomplete' => 'off'],
        secret_field('twilio_token', 'Auth Token', $c['token'], 'Stored encrypted on the server. Or set TWILIO_AUTH_TOKEN in the environment.'),
        ['name' => 'twilio_token_clear', 'label' => 'Remove the saved token', 'type' => 'checkbox', 'value' => false],
        ['name' => 'twilio_from', 'label' => 'From number (or Messaging Service SID)', 'half' => true, 'value' => $c['from'], 'placeholder' => '+15551234567'],
        ['name' => 'sms_default_cc', 'label' => 'Default country code', 'half' => true, 'value' => $c['cc'], 'placeholder' => '+91'],
    ], 'Save SMS settings'); card_close();
    echo '<div class="space-y-5">'; card_open('Message template', 'The default text. People never see the template, only the result.');
    echo form_open('admin-sms-template', 'space-y-3') . '<div><label class="label" for="sms-tpl">Template</label><textarea class="input" id="sms-tpl" name="template" rows="4" maxlength="400" data-sms-tpl>' . e($c['template']) . '</textarea></div>' . vars_help(TEMPLATE_VARS)
        . '<div class="rounded-2xl bg-soft p-3 text-sm"><p class="text-xs text-mute">Live preview <span data-sms-count></span></p><p data-sms-preview class="mt-1 whitespace-pre-wrap"></p></div>'
        . '<div class="flex flex-wrap justify-between gap-2"><button class="btn btn-ghost btn-sm" name="reset" value="1" formnovalidate>Reset to default</button><button class="btn btn-dark">Save template</button></div></form>';
    echo '<script>(function(){var t=document.querySelector("[data-sms-tpl]"),p=document.querySelector("[data-sms-preview]"),c=document.querySelector("[data-sms-count]");if(!t||!p)return;var S={name:"Asha",amount:"₹1,250",title:"Phone bill share",bill:"Phone bill share",date:"21 Oct 2026",due_date:"28 Oct 2026",account:"",method:"UPI",link:"https://example.com/dues?charge=12",app:' . json_encode(APP_NAME) . '};function r(){var s=t.value.replace(/\{([a-z_]+)\}/g,function(m,k){return k in S?S[k]:m});p.textContent=s;c.textContent="· "+s.length+" characters, "+Math.ceil(s.length/160)+" SMS segment(s)"}t.addEventListener("input",r);r()})()</script>';
    card_close();
    card_open('Send a test text', 'Uses the saved settings and the template above.');
    echo simple_form('admin-sms-test', [['name' => 'phone', 'label' => 'Phone number', 'required' => true, 'placeholder' => '+919876543210', 'autocomplete' => 'tel']], 'Send test SMS', 'btn btn-dark'); card_close(); echo '</div></div>';
    echo '<div class="mt-5">'; card_flush_open('SMS log', 'Latest 50 attempts.');
    echo log_table(all('SELECT created_at,to_phone,body,event,status,error FROM {sms_log} ORDER BY id DESC LIMIT 50'), ['to_phone' => 'To', 'body' => 'Message', 'event' => 'Event']) . '</div></section></div>'; admin_end();
}
