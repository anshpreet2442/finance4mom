<?php
declare(strict_types=1);

function admin_tabs(string $active): string {
    $tabs = ['overview' => ['/admin', 'Overview', 'layout-dashboard'], 'users' => ['/admin/users', 'Users', 'users'], 'billing' => ['/admin/billing', 'Billing', 'hand-coins'], 'settings' => ['/admin/settings', 'Site settings', 'sliders-horizontal'], 'activity' => ['/admin/activity', 'Activity log', 'history'], 'features' => ['/admin/features', 'Plans & usage', 'coins'], 'email' => ['/admin/email', 'Email', 'mail'], 'sms' => ['/admin/sms', 'SMS', 'smartphone'], 'ai' => ['/admin/ai', 'AI agent', 'sparkles'], 'system' => ['/admin/system', 'System', 'server']];
    $items = []; foreach ($tabs as $k => [$href, $label, $ic]) { $items[$k] = ['label' => $label, 'href' => url($href), 'icon' => $ic]; }
    return '<div class="mb-6 max-w-full overflow-x-auto overscroll-x-contain pb-1" data-scroll-active>' . jelly($items, $active, ['label' => 'Admin sections']) . '</div>';
}
function admin_start(string $title, string $tab): void {
    $u = require_admin();
    layout_start($title, 'admin', null, ['hero' => true]);
    echo m_banner($u, 'Admin panel', e($title), icon('shield-check', 'size-4') . 'Administrators only');
    echo '<div class="hidden px-0 lg:block">'; page_header('Admin panel', 'Manage users, billing, site settings and the installation. You can see account details, never anyone’s financial records.'); echo '</div>';
    echo '<div class="m-sheet -mt-6 px-4 pb-8 pt-6 lg:mt-0 lg:bg-transparent lg:px-0 lg:pb-0 lg:pt-0 lg:shadow-none">' . admin_tabs($tab);
}
function admin_end(): void { echo '</div>'; layout_end(); }

function page_admin(): void {
    admin_start('Admin overview', 'overview');
    $n = fn(string $sql, array $p = []) => (int) val($sql, $p);
    $total = $n('SELECT COUNT(*) FROM {users}'); $active30 = $n('SELECT COUNT(*) FROM {users} WHERE last_login_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY');
    echo '<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">' . stat_card('Users', (string) $total, $n("SELECT COUNT(*) FROM {users} WHERE role='admin'") . ' administrator(s)') . stat_card('Active in 30 days', (string) $active30, 'Signed in at least once', 'pos')
        . stat_card('New this week', (string) $n('SELECT COUNT(*) FROM {users} WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY')) . stat_card('Disabled accounts', (string) $n("SELECT COUNT(*) FROM {users} WHERE status='disabled'"), '', $n("SELECT COUNT(*) FROM {users} WHERE status='disabled'") ? 'warn' : '') . '</div>';
    echo '<div class="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">' . stat_card('Transactions stored', number_format($n('SELECT COUNT(*) FROM {transactions}'))) . stat_card('Accounts', number_format($n('SELECT COUNT(*) FROM {accounts}'))) . stat_card('Goals & purchases', number_format($n('SELECT COUNT(*) FROM {goals}') + $n('SELECT COUNT(*) FROM {purchases}')))
        . stat_card('Registration', site_setting('registration_open', '1') === '1' ? 'Open' : 'Closed', '', site_setting('registration_open', '1') === '1' ? '' : 'warn', '/admin/settings') . '</div>';
    echo '<div class="mt-5 grid gap-5 lg:grid-cols-2">';
    card_flush_open('Newest users', '', '<a class="text-xs text-mute underline" href="' . e(url('/admin/users')) . '">All users</a>'); echo '<ul class="divide-y divide-line px-6 pb-3">';
    foreach (all('SELECT name,email,role,created_at FROM {users} ORDER BY id DESC LIMIT 6') as $u) { echo '<li class="flex items-center justify-between gap-2 py-3 text-sm"><div class="min-w-0"><p class="truncate font-medium">' . e($u['name']) . '</p><p class="truncate text-xs text-mute">' . e($u['email']) . '</p></div><div class="text-right">' . ($u['role'] === 'admin' ? badge('Admin', 'dark') : '') . '<p class="text-xs text-mute">' . e(date_label(substr($u['created_at'], 0, 10))) . '</p></div></li>'; }
    echo '</ul></div></section>';
    card_flush_open('Recent admin activity', '', '<a class="text-xs text-mute underline" href="' . e(url('/admin/activity')) . '">Full log</a>'); echo '<ul class="divide-y divide-line px-6 pb-3">';
    $log = all('SELECT a.action,a.target,a.created_at,u.email AS actor FROM {audit_log} a LEFT JOIN {users} u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 6');
    if (!$log) { echo '<li class="py-4 text-sm text-mute">Nothing logged yet.</li>'; }
    foreach ($log as $l) { echo '<li class="py-3 text-sm"><span class="font-medium">' . e($l['action']) . '</span> <span class="text-mute">' . e($l['target']) . '</span><p class="text-xs text-mute">' . e($l['actor'] ?? 'system') . ' · ' . e($l['created_at']) . ' UTC</p></li>'; }
    echo '</ul></div></section></div>';
    admin_end();
}

function page_admin_users(): void {
    $me = require_admin(); admin_start('Users', 'users'); $q = get_str('q', 100);
    $users = all('SELECT u.id,u.name,u.email,u.role,u.status,u.created_at,u.last_login_at,(SELECT COUNT(*) FROM {transactions} t WHERE t.user_id=u.id) AS txs,(SELECT COUNT(*) FROM {passkeys} k WHERE k.user_id=u.id) AS keys_n FROM {users} u WHERE (? = \'\' OR u.email LIKE ? OR u.name LIKE ?) ORDER BY u.id', [$q, '%' . $q . '%', '%' . $q . '%']);
    $new = dialog_form('user-new', icon('user-plus', 'size-4') . ' Add user', 'Create an account', 'admin-user-create', [
        ['name' => 'name', 'label' => 'Name', 'required' => true], ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'password', 'label' => 'Temporary password', 'type' => 'password', 'required' => true, 'hint' => '10 to 72 characters. Ask them to change it after signing in.', 'autocomplete' => 'new-password'],
        ['name' => 'role', 'label' => 'Role', 'type' => 'select', 'required' => true, 'options' => ['user' => 'User', 'admin' => 'Administrator'], 'value' => 'user']], 'Create account', 'btn btn-dark');
    echo '<div class="mb-4 flex flex-wrap items-end justify-between gap-3"><form method="get" class="flex min-w-0 flex-1 gap-2 sm:flex-none" role="search"><label for="uq" class="sr-only">Search users</label><input id="uq" name="q" value="' . e($q) . '" placeholder="Search name or email" class="input min-w-0 sm:w-64"><button class="btn">Search</button></form>' . $new . '</div>';
    $actions = function (array $u) use ($me) {
        $id = (int) $u['id']; if ($id === (int) $me['id']) { return '<a class="btn btn-sm" href="' . e(url('/settings')) . '">Your settings</a>'; }
        return confirm_btn($u['status'] === 'active' ? 'Disable' : 'Enable', 'admin-user-status', ['id' => $id, 'status' => $u['status'] === 'active' ? 'disabled' : 'active'], ($u['status'] === 'active' ? 'Disable ' : 'Enable ') . $u['email'] . '?', $u['status'] === 'active' ? 'They will be signed out and unable to sign in. Their data is kept.' : 'They will be able to sign in again.', $u['status'] === 'active' ? 'Disable' : 'Enable', 'btn btn-sm')
            . confirm_btn($u['role'] === 'admin' ? 'Remove admin' : 'Make admin', 'admin-user-role', ['id' => $id, 'role' => $u['role'] === 'admin' ? 'user' : 'admin'], 'Change role for ' . $u['email'] . '?', $u['role'] === 'admin' ? 'They will lose access to the admin panel.' : 'They will be able to manage all users and site settings.', 'Confirm', 'btn btn-sm')
            . confirm_btn('Reset password', 'admin-user-reset', ['id' => $id], 'Reset password for ' . $u['email'] . '?', 'A temporary password will be shown once. Their current password stops working immediately (passkeys keep working).', 'Reset password', 'btn btn-sm')
            . confirm_btn('Delete', 'admin-user-delete', ['id' => $id], 'Delete ' . $u['email'] . '?', 'This permanently deletes the account and ALL of their data. It cannot be undone.', 'Delete user');
    };
    $badges = fn($u) => ($u['role'] === 'admin' ? badge('Admin', 'dark') : badge('User')) . ' ' . ($u['status'] === 'active' ? badge('Active', 'pos') : badge('Disabled', 'neg')) . ($u['keys_n'] ? ' ' . badge($u['keys_n'] . ' passkey' . ($u['keys_n'] > 1 ? 's' : '')) : '');
    if (!$users) { echo empty_state('No users match'); admin_end(); return; }
    // phones: one card per person
    echo '<ul class="space-y-3 lg:hidden">';
    foreach ($users as $u) {
        echo '<li class="card p-4"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate font-semibold">' . e($u['name']) . ((int) $u['id'] === (int) $me['id'] ? ' <span class="text-xs font-normal text-mute">(you)</span>' : '') . '</p><p class="truncate text-xs text-mute">' . e($u['email']) . '</p></div><div class="flex shrink-0 flex-wrap justify-end gap-1">' . $badges($u) . '</div></div>'
            . '<dl class="mt-3 grid grid-cols-3 gap-2 text-center text-xs"><div class="rounded-2xl bg-soft px-2 py-2"><dt class="text-mute">Entries</dt><dd class="num mt-0.5 font-semibold">' . number_format((int) $u['txs']) . '</dd></div><div class="rounded-2xl bg-soft px-2 py-2"><dt class="text-mute">Joined</dt><dd class="mt-0.5 font-semibold">' . e(date_short(substr($u['created_at'], 0, 10))) . '</dd></div><div class="rounded-2xl bg-soft px-2 py-2"><dt class="text-mute">Last in</dt><dd class="mt-0.5 font-semibold">' . ($u['last_login_at'] ? e(date_short(substr($u['last_login_at'], 0, 10))) : 'Never') . '</dd></div></dl>'
            . '<div class="mt-3 flex flex-wrap gap-1.5">' . $actions($u) . '</div></li>';
    }
    echo '</ul>';
    // desktop: table
    echo '<div class="hidden lg:block">'; card_flush_open(); echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[760px]"><thead><tr class="border-b border-line"><th class="th">User</th><th class="th">Status</th><th class="th text-right">Transactions</th><th class="th">Joined</th><th class="th">Last sign-in</th><th class="th"><span class="sr-only">Actions</span></th></tr></thead><tbody class="divide-y divide-line">';
    foreach ($users as $u) {
        echo '<tr><td class="td"><p class="font-medium">' . e($u['name']) . ((int) $u['id'] === (int) $me['id'] ? ' <span class="text-xs text-mute">(you)</span>' : '') . '</p><p class="text-xs text-mute">' . e($u['email']) . '</p></td><td class="td"><div class="flex flex-wrap gap-1">' . $badges($u) . '</div></td><td class="td num text-right">' . number_format((int) $u['txs']) . '</td>'
            . '<td class="td num whitespace-nowrap text-mute">' . e(date_label(substr($u['created_at'], 0, 10))) . '</td><td class="td num whitespace-nowrap text-mute">' . ($u['last_login_at'] ? e(date_label(substr($u['last_login_at'], 0, 10))) : 'Never') . '</td><td class="td text-right"><div class="flex flex-wrap justify-end gap-1">' . $actions($u) . '</div></td></tr>';
    }
    echo '</tbody></table></div></div></section></div>'; admin_end();
}

function page_admin_settings(): void {
    admin_start('Site settings', 'settings'); $cur = []; foreach (CURRENCIES as $k => $sym) { $cur[$k] = $k . ' (' . trim($sym) . ')'; }
    echo '<div class="max-w-2xl">'; card_open('Site settings', 'Applies to the whole installation.');
    echo simple_form('admin-settings', [
        ['name' => 'registration_open', 'label' => 'Allow new people to register themselves', 'type' => 'checkbox', 'value' => site_setting('registration_open', '1') === '1'],
        ['name' => 'default_currency', 'label' => 'Default currency for new accounts', 'type' => 'select', 'required' => true, 'half' => true, 'options' => $cur, 'value' => site_setting('default_currency', 'INR')],
        ['name' => 'default_locale', 'label' => 'Default number format', 'type' => 'select', 'required' => true, 'half' => true, 'options' => LOCALES, 'value' => site_setting('default_locale', 'en-IN')],
        ['name' => 'site_notice', 'label' => 'Site-wide notice (optional)', 'type' => 'textarea', 'value' => site_setting('site_notice'), 'hint' => 'Shown at the top of every page for signed-in users, e.g. planned maintenance.'],
    ], 'Save settings'); card_close(); echo '</div>'; admin_end();
}

function page_admin_activity(): void {
    admin_start('Activity log', 'activity');
    $rows = all('SELECT a.id,a.action,a.target,a.detail,a.ip,a.created_at,u.email AS actor FROM {audit_log} a LEFT JOIN {users} u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 200');
    card_flush_open('Activity log', 'Latest 200 events: registrations, admin actions and settings changes. Times are UTC.');
    if (!$rows) { echo '<p class="p-6 text-sm text-mute">Nothing logged yet.</p>'; }
    else { echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[700px]"><thead><tr class="border-b border-line"><th class="th">When (UTC)</th><th class="th">Actor</th><th class="th">Action</th><th class="th">Target</th><th class="th">Detail</th><th class="th">IP</th></tr></thead><tbody class="divide-y divide-line">';
        foreach ($rows as $r) { echo '<tr><td class="td num whitespace-nowrap text-mute">' . e($r['created_at']) . '</td><td class="td">' . e($r['actor'] ?? 'system') . '</td><td class="td font-medium">' . e($r['action']) . '</td><td class="td">' . e($r['target']) . '</td><td class="td text-mute">' . e($r['detail']) . '</td><td class="td num text-mute">' . e($r['ip']) . '</td></tr>'; }
        echo '</tbody></table></div>'; }
    echo '</div></section>'; admin_end();
}

function page_admin_system(): void {
    admin_start('System', 'system');
    $rows = all('SELECT TABLE_NAME AS t, TABLE_ROWS AS r, ROUND((DATA_LENGTH + INDEX_LENGTH)/1024) AS kb FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME', [DB_NAME, '%' . TABLE_SUFFIX]);
    $info = ['PHP' => PHP_VERSION, 'MySQL' => (string) val('SELECT VERSION()'), 'Schema version' => (string) val('SELECT v FROM {meta} WHERE k = ?', ['schema']) . ' (code expects ' . SCHEMA_VERSION . ')', 'Database' => DB_NAME . ' @ ' . DB_HOST . ':' . DB_PORT, 'Table suffix' => TABLE_SUFFIX,
        'Timezone' => APP_TIMEZONE, 'Debug mode' => APP_DEBUG ? 'ON (turn off in production)' : 'off', 'HTTPS' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'yes' : 'no (use HTTPS in production)', 'Base path' => base_path() ?: '/'];
    echo '<div class="grid gap-5 lg:grid-cols-2">'; card_open('Environment'); echo '<dl class="divide-y divide-line text-sm">';
    foreach ($info as $k => $v) { echo '<div class="flex justify-between gap-4 py-2.5"><dt class="text-mute">' . e($k) . '</dt><dd class="text-right font-medium">' . e($v) . '</dd></div>'; }
    echo '</dl>'; card_close();
    card_flush_open('Database tables', 'Created automatically by config.php. Row counts are estimates.'); echo '<div class="relative overflow-x-auto"><table class="w-full"><thead><tr class="border-b border-line"><th class="th">Table</th><th class="th text-right">Rows</th><th class="th text-right">Size</th></tr></thead><tbody class="divide-y divide-line">';
    $kb = 0; foreach ($rows as $r) { $kb += (int) $r['kb']; echo '<tr><td class="td font-mono text-xs">' . e($r['t']) . '</td><td class="td num text-right">' . number_format((int) $r['r']) . '</td><td class="td num text-right text-mute">' . number_format((int) $r['kb']) . ' KB</td></tr>'; }
    echo '<tr class="bg-soft/60 font-semibold"><td class="td">' . count($rows) . ' tables</td><td class="td"></td><td class="td num text-right">' . number_format($kb) . ' KB</td></tr></tbody></table></div></div></section></div>';
    admin_end();
}

function page_admin_billing(): void {
    $me = require_admin(); admin_start('Billing', 'billing'); $cfg = billing_cfg(); $today = today();
    $users = all("SELECT id,name,email,role,billing_enforced FROM {users} WHERE status='active' ORDER BY name"); foreach ($users as $x) { billing_sync((int) $x['id']); }
    $opts = []; foreach ($users as $x) { $opts[$x['id']] = $x['name'] . ' (' . $x['email'] . ')'; }
    $status = in_array(get_str('status', 10), ['pending', 'paid', 'waived', 'cancelled'], true) ? get_str('status', 10) : '';
    $charges = all('SELECT c.*, u.name AS uname, u.email AS uemail FROM {charges} c JOIN {users} u ON u.id=c.user_id ' . ($status !== '' ? 'WHERE c.status = ?' : '') . ' ORDER BY (c.status=\'pending\') DESC, c.due_date DESC, c.id DESC LIMIT 150', $status !== '' ? [$status] : []);
    $plans = all('SELECT p.*, u.name AS uname FROM {charge_plans} p JOIN {users} u ON u.id=p.user_id ORDER BY p.active DESC, p.id DESC');
    $chargeFields = [['name' => 'user_id', 'label' => 'Charge to', 'type' => 'select', 'required' => true, 'options' => $opts], ['name' => 'title', 'label' => 'What is it for?', 'required' => true, 'placeholder' => 'e.g. Phone bill share'],
        ['name' => 'description', 'label' => 'Note (optional)', 'type' => 'textarea'], ['name' => 'amount', 'label' => 'Amount (₹)', 'type' => 'money', 'required' => true, 'half' => true, 'currency' => '₹'], ['name' => 'due_date', 'label' => 'Due date', 'type' => 'date', 'required' => true, 'half' => true, 'value' => day_after_n($today, 7)]];
    $planFields = fn(?array $p = null) => array_merge($p ? [['name' => 'id', 'type' => 'hidden', 'value' => $p['id']]] : [], [
        ['name' => 'user_id', 'label' => 'Charge to', 'type' => 'select', 'required' => true, 'options' => $opts, 'value' => $p['user_id'] ?? ''], ['name' => 'title', 'label' => 'What is it for?', 'required' => true, 'value' => $p['title'] ?? '', 'placeholder' => 'e.g. Monthly family contribution'],
        ['name' => 'description', 'label' => 'Note (optional)', 'type' => 'textarea', 'value' => $p['description'] ?? ''], ['name' => 'amount', 'label' => 'Amount each month (₹)', 'type' => 'money', 'required' => true, 'half' => true, 'currency' => '₹', 'value' => $p ? money_input((int) $p['amount']) : ''],
        ['name' => 'day_of_month', 'label' => 'Due on day (1-28)', 'type' => 'number', 'min' => 1, 'max' => 28, 'half' => true, 'required' => true, 'value' => $p['day_of_month'] ?? 5],
        ['name' => 'start_month', 'label' => 'First month', 'type' => 'text', 'required' => true, 'half' => true, 'value' => $p['start_month'] ?? month_of($today), 'placeholder' => 'YYYY-MM'], ['name' => 'end_month', 'label' => 'Last month (optional)', 'type' => 'text', 'half' => true, 'value' => $p['end_month'] ?? '', 'placeholder' => 'YYYY-MM'],
        ['name' => 'active', 'label' => 'Active', 'type' => 'checkbox', 'value' => $p ? (bool) $p['active'] : true]]);
    echo '<div class="grid gap-5 lg:grid-cols-2">';
    // gateway
    card_open('Payment gateway (PayU)', 'Connection status. Secrets live in environment variables or config.php, never in the database.');
    $row = fn($l, $ok, $detail = '') => '<li class="flex flex-col gap-1 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between sm:gap-3"><span>' . e($l) . '</span><span class="flex items-center gap-2 text-mute sm:text-right">' . e($detail) . ($ok ? badge('Set', 'pos') : badge('Missing', 'neg')) . '</span></li>';
    echo '<ul class="divide-y divide-line">' . $row('Gateway address (PAYU_BASE_URL)', PAYU_BASE_URL !== '', PAYU_BASE_URL !== '' ? parse_url(PAYU_BASE_URL, PHP_URL_HOST) : '') . $row('API token (PAYU_API_TOKEN)', PAYU_API_TOKEN !== '', PAYU_API_TOKEN !== '' ? substr(PAYU_API_TOKEN, 0, 10) . '…' : '') . $row('Webhook secret (PAYU_WEBHOOK_SECRET)', PAYU_WEBHOOK_SECRET !== '') . $row('Public site address (MFNC_APP_URL)', APP_URL !== '', APP_URL !== '' ? APP_URL : 'auto-detected: set it in production') . '</ul>';
    echo '<div class="mt-4 rounded-2xl bg-soft p-4 text-sm"><p class="font-medium">Webhook URL to give the gateway</p><p class="mt-1 break-all font-mono text-xs">' . e(abs_url('/webhooks/payu')) . '</p><p class="mt-2 text-xs text-mute">Set it as the Webhook URL of your API app, and copy the app’s webhook secret into PAYU_WEBHOOK_SECRET. Events are signature-checked and de-duplicated.</p></div>';
    echo '<div class="mt-4 flex flex-wrap gap-2">' . post_btn(icon('plug', 'size-4') . ' Test connection', 'admin-billing-test', [], 'btn') . '</div>'; card_close();
    // rules
    card_open('If a payment is late', 'What happens, and when. Shown to the payer in plain words.');
    echo simple_form('admin-billing-settings', [
        ['name' => 'billing_enabled', 'label' => 'Enforce dues (reminders, late fees, restrictions)', 'type' => 'checkbox', 'value' => $cfg['enabled']],
        ['name' => 'grace_days', 'label' => 'Grace days before a late fee', 'type' => 'number', 'min' => 0, 'max' => 60, 'half' => true, 'value' => $cfg['grace']],
        ['name' => 'late_fee_type', 'label' => 'Late fee', 'type' => 'select', 'required' => true, 'half' => true, 'options' => ['none' => 'None', 'flat' => 'Flat amount', 'percent' => 'Percent of the charge'], 'value' => $cfg['fee_type']],
        ['name' => 'late_fee_flat', 'label' => 'Flat late fee (₹)', 'type' => 'money', 'half' => true, 'currency' => '₹', 'value' => $cfg['fee_flat'] ? money_input($cfg['fee_flat']) : ''],
        ['name' => 'late_fee_percent', 'label' => 'Percent late fee (%)', 'type' => 'text', 'half' => true, 'value' => $cfg['fee_percent'] ? rtrim(rtrim(number_format($cfg['fee_percent'] / 100, 2, '.', ''), '0'), '.') : '', 'placeholder' => 'e.g. 5'],
        ['name' => 'restrict_after_days', 'label' => 'Read-only after (days overdue, 0 = never)', 'type' => 'number', 'min' => 0, 'max' => 365, 'half' => true, 'value' => $cfg['restrict']],
        ['name' => 'lock_after_days', 'label' => 'Locked after (days overdue, 0 = never)', 'type' => 'number', 'min' => 0, 'max' => 365, 'half' => true, 'value' => $cfg['lock']],
    ], 'Save rules');
    echo '<ul class="mt-4 space-y-1.5 border-t border-line pt-4 text-xs text-mute">'; foreach (consequences_text($cfg) as $t) { echo '<li>• ' . e($t) . '</li>'; } echo '</ul>'; card_close();
    echo '</div>';
    // post
    echo '<div class="mt-5">'; card_open('Post a charge', 'A one-off payment, or a monthly charge that creates itself every month.',
        dialog_form('charge-new', icon('plus', 'size-4') . ' One-off payment', 'Post a payment', 'admin-charge-create', $chargeFields, 'Post charge', 'btn btn-dark') . dialog_form('plan-new', icon('repeat', 'size-4') . ' Monthly charge', 'Set up a monthly charge', 'admin-plan-save', $planFields(), 'Save monthly charge', 'btn'));
    if (!$plans) { echo '<p class="text-sm text-mute">No monthly charges set up yet.</p>'; }
    else { echo '<ul class="divide-y divide-line">'; foreach ($plans as $p) { echo '<li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"><div class="min-w-0"><p class="font-medium">' . e($p['title']) . ' ' . ($p['active'] ? '' : badge('Paused')) . '</p><p class="text-xs text-mute">' . e($p['uname']) . ' · ' . e(fmt_inr((int) $p['amount'])) . ' on day ' . (int) $p['day_of_month'] . ' · from ' . e($p['start_month']) . ($p['end_month'] ? ' to ' . e($p['end_month']) : '') . '</p></div><div class="flex gap-1">'
        . dialog_form('plan-' . $p['id'], 'Edit', 'Edit monthly charge', 'admin-plan-save', $planFields($p), 'Save changes', 'btn btn-ghost btn-sm') . post_btn($p['active'] ? 'Pause' : 'Resume', 'admin-plan-toggle', ['id' => $p['id'], 'active' => $p['active'] ? 0 : 1], 'btn btn-ghost btn-sm') . '</div></li>'; } echo '</ul>'; }
    card_close(); echo '</div>';
    // charges
    $actionsFor = function (array $c) { $id = (int) $c['id']; if ($c['status'] !== 'pending') { return ''; } $o = '';
        if ($c['late_fee'] > 0 && !$c['late_fee_waived']) { $o .= confirm_btn('Waive late fee', 'admin-charge-action', ['id' => $id, 'action' => 'waive_fee'], 'Waive the late fee?', 'The late fee of ' . fmt_inr((int) $c['late_fee']) . ' will no longer be payable.', 'Waive late fee', 'btn btn-sm'); }
        return $o . confirm_btn('Mark paid', 'admin-charge-action', ['id' => $id, 'action' => 'paid'], 'Mark as paid?', 'Use this when the money was handed over in person. It is recorded as received outside the app.', 'Mark paid', 'btn btn-sm')
            . confirm_btn('Waive charge', 'admin-charge-action', ['id' => $id, 'action' => 'waive'], 'Waive this charge?', 'The charge is forgiven and no longer counts against ' . $c['uname'] . '.', 'Waive', 'btn btn-ghost btn-sm')
            . confirm_btn('Cancel', 'admin-charge-action', ['id' => $id, 'action' => 'cancel'], 'Cancel this charge?', 'Use this if it was posted by mistake.', 'Cancel charge', 'btn btn-ghost btn-sm text-neg'); };
    echo '<div class="mt-5">'; card_open('Charges', '', jelly(array_combine(['all', 'pending', 'paid', 'waived', 'cancelled'], array_map(fn($k, $l) => ['label' => $l, 'href' => url('/admin/billing', $k ? ['status' => $k] : [])], ['', 'pending', 'paid', 'waived', 'cancelled'], ['All', 'Unpaid', 'Paid', 'Waived', 'Cancelled'])), $status === '' ? 'all' : $status, ['size' => 'sm', 'class' => 'max-w-full overflow-x-auto', 'label' => 'Filter charges']));
    if (!$charges) { echo '<p class="text-sm text-mute">No charges.</p>'; }
    else {
        echo '<ul class="divide-y divide-line">';
        foreach ($charges as $c) {
            $st = charge_state($c, $today);
            echo '<li class="flex flex-col gap-3 py-4 lg:flex-row lg:items-center"><div class="min-w-0 lg:w-1/4"><p class="truncate font-medium">' . e($c['uname']) . '</p><p class="truncate text-xs text-mute">' . e($c['uemail']) . '</p></div>'
                . '<div class="min-w-0 flex-1"><p class="font-medium">' . e($c['title']) . '</p><p class="text-xs text-mute">' . ($c['kind'] === 'monthly' ? 'Monthly' : 'One-off') . ' · due ' . e(date_label($c['due_date'])) . ($c['note'] ? ' · ' . e($c['note']) : '') . ($c['paid_via'] ? ' · paid via ' . e($c['paid_via']) : '') . '</p><div class="mt-1.5">' . charge_badge($st + $c) . '</div></div>'
                . '<div class="num text-left lg:w-32 lg:text-right"><p class="font-semibold">' . e(fmt_inr((int) $c['amount'])) . '</p>' . ($c['late_fee'] > 0 ? '<p class="text-xs ' . ($c['late_fee_waived'] ? 'text-mute line-through' : 'text-neg') . '">+' . e(fmt_inr((int) $c['late_fee'])) . ' late fee</p>' : '') . '</div>'
                . '<div class="flex flex-wrap gap-1 lg:w-72 lg:justify-end">' . $actionsFor($c) . '</div></li>';
        }
        echo '</ul>';
    }
    card_close(); echo '</div>';
    // people
    echo '<div class="mt-5">'; card_open('People & enforcement', 'Exempt someone to stop reminders, late fees and restrictions for them. Admins are always exempt.'); echo '<ul class="divide-y divide-line">';
    foreach ($users as $x) { $owed = outstanding_total((int) $x['id']); $lvl = billing_level($x);
        echo '<li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"><div class="min-w-0"><p class="font-medium">' . e($x['name']) . ($x['role'] === 'admin' ? ' ' . badge('Admin', 'dark') : '') . '</p><p class="text-xs text-mute">' . ($owed ? e(fmt_inr($owed)) . ' outstanding' : 'Nothing outstanding') . '</p></div><div class="flex items-center gap-2">' . ($lvl === 3 ? badge('Locked', 'neg') : ($lvl === 2 ? badge('Read-only', 'neg') : ($lvl === 1 ? badge('Overdue', 'warn') : ''))) . ($x['billing_enforced'] ? badge('Enforced') : badge('Exempt', 'warn')) . ($x['role'] === 'admin' ? '' : post_btn($x['billing_enforced'] ? 'Exempt' : 'Enforce', 'admin-user-billing', ['id' => $x['id'], 'enforced' => $x['billing_enforced'] ? 0 : 1], 'btn btn-sm')) . '</div></li>'; }
    echo '</ul>'; card_close(); echo '</div>';
    admin_end();
}
