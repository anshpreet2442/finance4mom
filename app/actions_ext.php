<?php
declare(strict_types=1);
/** POST handlers for email, SMS, the dock, plans/wallet and admin feature billing (AI handlers live in ai_actions.php). */

function post_bool(string $k): bool { return !empty($_POST[$k]); }
function post_micro(string $k): int { $v = trim((string) ($_POST[$k] ?? '')); if ($v === '') { return 0; } $p = parse_money($v); if ($p === null) { throw new UserError('Enter a valid amount.'); } return $p * 10000; }
function micro_input(int $micro): string { return $micro > 0 ? money_input(intdiv($micro, 10000)) : ''; }

// ---------------- the person's own alert choices ----------------
function receipt_fields(): array {
    $u = current_user(); $c = email_cfg();
    if (!$u || !$c['enabled'] || !$c['user_choice'] || !email_configured($c) || !feature_on($u, 'email')) { return []; }
    $on = (int) (val('SELECT email_alerts FROM {preferences} WHERE user_id=?', [$u['id']]) ?? 1) === 1;
    return [['name' => 'email_choice', 'type' => 'hidden', 'value' => 1], ['name' => 'email_receipt', 'label' => 'Email me a receipt for this payment', 'type' => 'checkbox', 'value' => $on]];
}
function posted_email_choice(): ?bool { return !empty($_POST['email_choice']) ? post_bool('email_receipt') : null; }
function do_alerts_save(array $u): string {
    $phone = in_line('phone', 25, false, 'Phone');
    if ($phone !== '' && !normalize_phone($phone, sms_cfg()['cc'])) { throw new UserError('That phone number does not look right. Use the international format, e.g. +919876543210.'); }
    run('UPDATE {preferences} SET phone=?, email_alerts=?, sms_alerts=? WHERE user_id=?', [$phone === '' ? null : normalize_phone($phone, sms_cfg()['cc']), post_bool('email_alerts') ? 1 : 0, post_bool('sms_alerts') ? 1 : 0, $u['id']]);
    return 'Alert settings saved.';
}

// ---------------- admin: email ----------------
function do_admin_email_settings(array $u): string {
    $host = in_str('smtp_host', 190, false, 'SMTP host'); $from = in_str('smtp_from', 190, false, 'From address');
    if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) { throw new UserError('Enter a valid From address.'); }
    $on = post_bool('email_enabled'); $tr = in_enum('smtp_transport', ['smtp', 'mail']);
    if ($on && $from === '') { throw new UserError('Enter the From address before switching email on.'); }
    if ($on && $tr === 'smtp' && $host === '') { throw new UserError('Enter the SMTP host, or choose PHP mail().'); }
    foreach (['email_enabled' => $on ? '1' : '0', 'email_user_choice' => post_bool('email_user_choice') ? '1' : '0', 'smtp_transport' => $tr, 'smtp_host' => $host, 'smtp_port' => (string) in_int('smtp_port', 1, 65535, 587), 'smtp_secure' => in_enum('smtp_secure', ['none', 'tls', 'ssl']),
        'smtp_user' => in_str('smtp_user', 190, false, 'Username'), 'smtp_from' => $from, 'smtp_from_name' => in_str('smtp_from_name', 80, false, 'From name')] as $k => $v) { set_setting($k, $v); }
    secret_from_post('smtp_pass', 'smtp_pass'); audit('admin.email.settings', 'site'); return 'Email settings saved.';
}
function do_admin_email_test(array $u): string {
    $to = in_str('to', 190, true, 'Recipient'); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { throw new UserError('Enter a valid email address.'); }
    [$sub, $body] = email_template('test'); $v = template_vars($u, ['name' => $u['name']]);
    [$ok, $err] = send_email($to, render_template($sub, $v), render_template($body, $v), (int) $u['id'], 'test');
    if (!$ok) { throw new UserError('The test email failed: ' . $err); }
    audit('admin.email.test', $to); return 'Test email sent to ' . $to . '. Check the inbox (and spam).';
}
function do_admin_email_templates(array $u): string {
    start_session();
    if (!empty($_POST['reset'])) { $ev = in_enum('reset', array_keys(EMAIL_EVENTS)); set_setting("mail_tpl_{$ev}_subject", ''); set_setting("mail_tpl_{$ev}_body", ''); return 'Template reset to the default.'; }
    $subs = $_POST['subject'] ?? []; $bodies = $_POST['body'] ?? []; if (!is_array($subs) || !is_array($bodies)) { throw new UserError('Invalid form.'); }
    if (!empty($_POST['preview'])) {
        $ev = in_enum('preview', array_keys(EMAIL_EVENTS)); $v = template_vars($u, ['title' => 'Phone bill', 'amount' => fmt_inr(125000), 'due_date' => date_label(today()), 'account' => ' from HDFC Savings']);
        $_SESSION['email_preview'] = [render_template((string) ($subs[$ev] ?? ''), $v), render_template((string) ($bodies[$ev] ?? ''), $v)]; return 'Preview below (not saved yet).';
    }
    $bad = []; foreach (array_keys(EMAIL_EVENTS) as $ev) { foreach ([$subs[$ev] ?? '', $bodies[$ev] ?? ''] as $txt) { foreach (unknown_params((string) $txt) as $k) { $bad[$k] = 1; } } }
    if ($bad) { throw new UserError('Unknown parameter' . (count($bad) > 1 ? 's' : '') . ' in the templates: {' . implode('}, {', array_keys($bad)) . '}. Available: {' . implode('}, {', array_keys(TEMPLATE_VARS)) . '}.'); }
    foreach (array_keys(EMAIL_EVENTS) as $ev) { if ($ev === 'test' || !isset($subs[$ev], $bodies[$ev])) { continue; }
        $s = mb_substr(trim((string) $subs[$ev]), 0, 200); $b = mb_substr(trim((string) $bodies[$ev]), 0, 3000); $d = default_email_template($ev);
        set_setting("mail_tpl_{$ev}_subject", $s === $d[0] ? '' : $s); set_setting("mail_tpl_{$ev}_body", str_replace("\r\n", "\n", $b) === $d[1] ? '' : $b); }
    audit('admin.email.templates', 'site'); return 'Templates saved.';
}

// ---------------- admin: SMS ----------------
function do_admin_sms_settings(array $u): string {
    $sid = in_str('twilio_sid', 60, false, 'Account SID'); $from = in_str('twilio_from', 40, false, 'From number'); $cc = in_str('sms_default_cc', 5, false, 'Country code') ?: '+91';
    if (!preg_match('/^\+\d{1,4}$/', $cc)) { throw new UserError('The default country code looks like +91.'); }
    if ($sid !== '' && !preg_match('/^AC[0-9a-zA-Z]{8,}$/', $sid)) { throw new UserError('An Account SID starts with AC.'); }
    $on = post_bool('sms_enabled'); secret_from_post('twilio_token', 'twilio_token');
    if ($on && ($sid === '' || $from === '' || secret_get('twilio_token', 'TWILIO_AUTH_TOKEN') === '')) { throw new UserError('Enter the Account SID, Auth Token and From number before switching SMS on.'); }
    foreach (['sms_enabled' => $on ? '1' : '0', 'sms_auto_charges' => post_bool('sms_auto_charges') ? '1' : '0', 'sms_direct_link' => post_bool('sms_direct_link') ? '1' : '0', 'twilio_sid' => $sid, 'twilio_from' => $from, 'sms_default_cc' => $cc] as $k => $v) { set_setting($k, $v); }
    audit('admin.sms.settings', 'site'); return 'SMS settings saved.';
}
function do_admin_sms_template(array $u): string {
    if (!empty($_POST['reset'])) { set_setting('sms_template', ''); return 'Template reset to the default.'; }
    $t = trim(in_str('template', 400, true, 'Template')); if ($bad = unknown_params($t)) { throw new UserError('Unknown parameter' . (count($bad) > 1 ? 's' : '') . ': {' . implode('}, {', $bad) . '}. Available: {' . implode('}, {', array_keys(TEMPLATE_VARS)) . '}.'); } set_setting('sms_template', $t === default_sms_template() ? '' : $t); audit('admin.sms.template', 'site'); return 'SMS template saved.';
}
function do_admin_sms_test(array $u): string {
    $c = sms_cfg(); $v = template_vars($u, ['title' => 'Test payment', 'amount' => fmt_inr(125000), 'due_date' => date_label(day_after_n(today(), 7)), 'link' => abs_url('/dues')]);
    [$ok, $err] = send_sms(in_line('phone', 25, true, 'Phone'), render_template($c['template'], $v), (int) $u['id'], 'test');
    if (!$ok) { throw new UserError('The test SMS failed: ' . $err); }
    audit('admin.sms.test'); return 'Test SMS sent.';
}
function do_admin_charge_sms(array $u): string {
    $c = one('SELECT * FROM {charges} WHERE id=?', [in_id('id')]); if (!$c) { throw new UserError('Charge not found.'); }
    if ($c['status'] !== 'pending') { throw new UserError('Only an unpaid charge needs a payment link.'); }
    [$ok, $err] = sms_charge_alert((int) $c['user_id'], $c, 'charge.link', true); if (!$ok) { throw new UserError($err); }
    audit('billing.charge.sms', 'charge#' . $c['id']); return 'Payment link sent by SMS.';
}

// ---------------- dock ----------------
function do_dock_pin(array $u): string {
    $k = in_enum('page', array_keys(dock_pages($u['role'] === 'admin'))); if (!feature_on($u, 'dock')) { throw new UserError(feature_access($u, 'dock')['reason']); }
    $n = (int) val('SELECT COUNT(*) FROM {dock_pins} WHERE user_id=?', [$u['id']]); if ($n >= 12) { throw new UserError('The dock holds up to 12 pages. Unpin one first.'); }
    run('INSERT IGNORE INTO {dock_pins} (user_id,page_key,sort) VALUES (?,?,?)', [$u['id'], $k, $n]); return 'Pinned to the dock.';
}
function do_dock_unpin(array $u): string { run('DELETE FROM {dock_pins} WHERE user_id=? AND page_key=?', [$u['id'], in_str('page', 30)]); return 'Removed from the dock.'; }
function do_dock_order(array $u): ?string {
    $keys = array_filter(explode(',', in_str('order', 400, false)), fn($k) => preg_match('/^[a-z]+$/', $k)); $i = 0;
    foreach ($keys as $k) { run('UPDATE {dock_pins} SET sort=? WHERE user_id=? AND page_key=?', [$i++, $u['id'], $k]); }
    return null;
}

// ---------------- plans & wallet (user) ----------------
function do_plan_buy(array $u): ?string {
    $f = in_enum('feature', array_keys(features())); $c = feature_cfg($f);
    if ($c['mode'] !== 'paid' || $c['price'] <= 0) { throw new UserError('That is not sold as a plan.'); }
    $id = create_self_charge((int) $u['id'], features()[$f]['label'] . ' · ' . $c['days'] . ' days', $c['price'], ['feature' => $f, 'days' => $c['days'], 'desc' => 'Access to ' . features()[$f]['label']]);
    $GLOBALS['__redirect'] = url('/dues', ['charge' => $id]); return 'Pay the charge below to unlock ' . features()[$f]['label'] . '.';
}
function do_wallet_topup(array $u): ?string {
    $amt = in_money('amount', true, false, false, 'Amount'); if ($amt < 1000 || $amt > 5000000) { throw new UserError('Top up between ₹10 and ₹50,000.'); }
    $id = create_self_charge((int) $u['id'], 'Wallet top-up ' . fmt_inr($amt), $amt, ['topup_micro' => $amt * 10000, 'desc' => 'Credit for pay-as-you-go features']);
    $GLOBALS['__redirect'] = url('/dues', ['charge' => $id]); return 'Pay the charge below to add the credit.';
}

// ---------------- admin: plans, budgets, wallets ----------------
function do_admin_features_save(array $u): string {
    // validate everything first, then write, so a rejected save leaves the stored plans untouched
    $rows = [];
    foreach (features() as $f => $m) {
        $mode = in_enum("mode_$f", $m['modes']);
        $price = in_money("price_$f", false, false, true, 'Price');
        $days = in_int("days_$f", 1, 366, 30);
        $unit = (in_array('payg', $m['modes'], true) && empty($m['metered']) && $f !== 'ai') ? post_micro("unit_$f") : null;
        if ($mode === 'paid' && $price <= 0) { throw new UserError($m['label'] . ' is marked paid, so it needs a price.'); }
        $rows[$f] = [$mode, $price, $days, $unit];
    }
    foreach ($rows as $f => [$mode, $price, $days, $unit]) {
        set_setting("feat_{$f}_mode", $mode); set_setting("feat_{$f}_price", (string) $price); set_setting("feat_{$f}_days", (string) $days);
        if ($unit !== null) { set_setting("feat_{$f}_unit", (string) $unit); }
    }
    audit('admin.features', 'site'); return 'Plans saved.';
}
function do_admin_user_grant(array $u): string {
    $uid = in_id('user_id', 'person'); $f = in_enum('feature', array_keys(features())); if (!one('SELECT id FROM {users} WHERE id=?', [$uid])) { throw new UserError('User not found.'); }
    if (in_str('op', 10) === 'revoke') { revoke_feature($uid, $f); audit('admin.feature.revoke', "user#$uid", $f); return 'Access removed.'; }
    $until = grant_feature($uid, $f, in_int('days', 1, 3650, 30), 'admin'); audit('admin.feature.grant', "user#$uid", "$f until $until"); return 'Access granted until ' . date_label($until) . '.';
}
function do_admin_user_budget(array $u): string {
    $uid = in_id('user_id', 'person'); $raw = trim((string) ($_POST['budget'] ?? ''));
    run('UPDATE {users} SET ai_budget_micro=? WHERE id=?', [$raw === '' ? null : post_micro('budget'), $uid]); audit('admin.ai.budget', "user#$uid", $raw === '' ? 'default' : $raw); return $raw === '' ? 'This person now follows the default AI budget.' : 'AI budget saved.';
}
function do_admin_wallet_adjust(array $u): string {
    $uid = in_id('user_id', 'person'); if (!one('SELECT id FROM {users} WHERE id=?', [$uid])) { throw new UserError('User not found.'); }
    $sign = in_enum('op', ['credit', 'debit']); $m = post_micro('amount'); if ($m <= 0) { throw new UserError('Enter an amount.'); }
    wallet_add($uid, $sign === 'credit' ? $m : -$m, 'adjust', in_line('note', 120, false) ?: 'Admin adjustment'); audit('admin.wallet', "user#$uid", $sign . ' ' . micro_to_inr($m)); return 'Wallet updated.';
}

// ---------------- AI advisor (person) ----------------
function ai_require(array $u): void { if ($why = ai_not_ready_reason()) { throw new UserError($why); } $fa = feature_access($u, 'ai'); if (!$fa['ok']) { throw new UserError($fa['reason']); } }
function do_ai_profile_save(array $u): string {
    ai_require($u); $name = ai_clean_name(in_line('agent_name', 40, true, 'Name')); if (mb_strlen($name) < 2) { throw new UserError('A name needs at least 2 letters or digits.'); }
    run("UPDATE {ai_profiles} SET agent_name=?, avatar_style=?, stage=IF(stage='naming','ready',stage) WHERE user_id=?", [$name, in_enum('avatar_style', array_keys(AI_STYLES)), $u['id']]); ai_profile((int) $u['id']); return 'Saved.';
}
function do_ai_context_save(array $u): string { ai_profile((int) $u['id']); run('UPDATE {ai_profiles} SET context=? WHERE user_id=?', [in_str('context', 3000, false), $u['id']]); return 'What your advisor knows about you is updated.'; }
function do_ai_reset(array $u): string {
    $uid = (int) $u['id']; run('DELETE FROM {ai_messages} WHERE user_id=?', [$uid]); run("UPDATE {ai_profiles} SET stage='interview', qa=NULL, context=NULL, suggested_name=NULL, agent_name=NULL WHERE user_id=?", [$uid]); $GLOBALS['__redirect'] = url('/ai'); return 'Setup restarted.';
}
function do_ai_undo(array $u): string { return ai_undo((int) $u['id'], in_id('id')); }

// ---------------- AI advisor (admin) ----------------
function do_admin_ai_settings(array $u): string {
    $model = in_enum('ai_model', array_keys(AI_MODELS)); $prompt = trim(str_replace("\r\n", "\n", (string) ($_POST['ai_system_prompt'] ?? ''))); if (mb_strlen($prompt) > 12000) { throw new UserError('The system prompt is too long (12,000 characters).'); }
    $on = post_bool('ai_enabled'); secret_from_post('ai_key', 'ai_key');
    if ($on && secret_get('ai_key', 'ANTHROPIC_API_KEY') === '' && !ai_mock()) { throw new UserError('Add the API key before switching the AI advisor on.'); }
    set_setting('ai_enabled', $on ? '1' : '0'); set_setting('ai_model', $model); set_setting('ai_max_tokens', (string) in_int('ai_max_tokens', 200, 8000, 1500)); set_setting('ai_rate_min', (string) in_int('ai_rate_min', 1, 120, 6)); set_setting('ai_rate_day', (string) in_int('ai_rate_day', 1, 5000, 200));
    set_setting('ai_default_budget_micro', (string) post_micro('ai_default_budget')); set_setting('ai_system_prompt', $prompt === trim(ai_default_prompt()) ? '' : $prompt);
    foreach (array_keys(AI_MODELS) as $m) { foreach (['in', 'out'] as $d) { $v = trim((string) ($_POST["price_{$d}_$m"] ?? '')); if ($v !== '') { if (!is_numeric($v) || (float) $v < 0 || (float) $v > 1000000) { throw new UserError('Prices are rupees per million tokens, e.g. 250.'); } set_setting("ai_price_{$d}_$m", (string) (float) $v); } } }
    audit('admin.ai.settings', 'site', $model); return 'AI settings saved.';
}
function do_admin_ai_prompt_reset(array $u): string { set_setting('ai_system_prompt', ''); return 'System prompt reset to the default.'; }
function do_admin_ai_test(array $u): string {
    if ($why = ai_not_ready_reason()) { throw new UserError($why); }
    $p = ai_profile((int) $u['id']); $r = ai_call($u, $p, [['role' => 'user', 'content' => 'Reply with exactly: OK']], false); $t = ''; foreach ($r['content'] ?? [] as $b) { if (($b['type'] ?? '') === 'text') { $t .= $b['text']; } }
    return 'The AI service answered (' . ai_cfg()['model'] . '): ' . mb_substr(trim($t), 0, 80) . ' — ' . ($r['usage']['input_tokens'] ?? '?') . ' in / ' . ($r['usage']['output_tokens'] ?? '?') . ' out tokens.';
}
