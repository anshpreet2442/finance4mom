<?php
declare(strict_types=1);
/** Front controller. Apache (.htaccess) and `php -S ... router.php` send every non-file request here. */
require __DIR__ . '/config.php';

foreach (glob(__DIR__ . '/app/pages/*.php') as $f) { require_once $f; }
require_once __DIR__ . '/app/actions.php'; require_once __DIR__ . '/app/actions_ext.php';

set_exception_handler(function (Throwable $e) {
    log_error($e);
    if (!headers_sent()) { http_response_code(500); }
    if (APP_DEBUG) { echo '<pre style="padding:1rem;white-space:pre-wrap">' . e((string) $e) . '</pre>'; return; }
    $admin = false; try { $cu = current_user(); $admin = $cu && $cu['role'] === 'admin'; } catch (Throwable) {}
    // administrators get the real reason (it is also in the PHP error log); everyone else gets a calm message
    render_error(500, 'Something went wrong', $admin ? get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'The problem has been logged. Please try again in a moment.');
});

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (!in_array(request_path(), ['/sw.js', '/manifest.webmanifest'], true)) { header('Cache-Control: no-store'); }

$path = request_path();

function request_headers(): array { $h = []; foreach ($_SERVER as $k => $v) { if (str_starts_with($k, 'HTTP_')) { $h[str_replace('_', '-', substr($k, 5))] = $v; } } return $h; }
const LOCKED_GET = ['/api/pulse', '/dues', '/dues/return', '/credits', '/offline', '/manifest.webmanifest', '/sw.js', '/settings/backup', '/transactions/export'];
const LOCKED_POST = ['dues-pay', 'logout', 'theme-quick'];
const READONLY_POST = ['dues-pay', 'logout', 'theme-quick', 'prefs-save', 'profile-save', 'password-change', 'notifications-read'];

// ---- gateway webhook: public, signature-checked, no session / CSRF ----
if (method() === 'POST' && $path === '/webhooks/payu') {
    header('Content-Type: text/plain; charset=utf-8'); [$code, $msg] = handle_payu_webhook((string) file_get_contents('php://input'), request_headers()); http_response_code($code); echo $msg; exit;
}
// ---- Telegram bot webhook: public, secret-header checked, no session / CSRF, answers at once and then does the work ----
if ($path === '/webhooks/telegram') {
    if (method() !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
    tg_handle_request((string) file_get_contents('php://input', false, null, 0, TG_MAX_BODY + 1), request_headers()); exit;
}
// ---- passkeys (WebAuthn) JSON endpoints ----
if (method() === 'POST' && str_starts_with($path, '/webauthn/')) { handle_webauthn($path); }
// ---- offline sync: JSON batch from the browser / service worker ----
if (method() === 'POST' && $path === '/sync') {
    header('Content-Type: application/json'); $u = current_user();
    if (!$u) { http_response_code(401); echo json_encode(['error' => 'sign in']); exit; }
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''); start_session();
    if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) { http_response_code(403); echo json_encode(['error' => 'csrf']); exit; }
    if (billing_level($u) >= 2) { http_response_code(403); echo json_encode(['error' => 'restricted']); exit; }
    $body = json_decode((string) file_get_contents('php://input', false, null, 0, 1_000_000), true);
    if (!is_array($body) || !is_array($body['ops'] ?? null) || count($body['ops']) > 50) { http_response_code(400); echo json_encode(['error' => 'bad request']); exit; }
    if (isset($body['user']) && (string) $body['user'] !== (string) $u['id']) { http_response_code(403); echo json_encode(['error' => 'These changes belong to another account.']); exit; }
    echo json_encode(['results' => sync_apply_ops($u, $body['ops'])]); exit;
}

// ---- AI advisor JSON endpoints (streamed NDJSON; CSRF in a header, like /sync) ----
if (method() === 'POST' && $path === '/ai/chat') { handle_ai_chat(); }
if (method() === 'POST' && $path === '/ai/act') { handle_ai_act(); }
if (method() === 'POST' && $path === '/ai/upload') { handle_ai_upload(); }

// ---- form posts: /do/{action} ----
if (method() === 'POST') {
    if (!str_starts_with($path, '/do/')) { render_error(404, 'Page not found', 'That address does not exist.'); exit; }
    $name = substr($path, 4);
    $fn = 'do_' . str_replace('-', '_', $name);
    if (!preg_match('/^[a-z][a-z-]*$/', $name) || !function_exists($fn)) { render_error(404, 'Page not found', 'That address does not exist.'); exit; }
    $back = safe_back($_POST['_back'] ?? null);
    if (!csrf_ok()) { flash('err', 'Your session expired. Please try again.'); redirect($back); }
    $u = in_array($name, PUBLIC_ACTIONS, true) || $name === 'logout' ? current_user() : (str_starts_with($name, 'admin-') ? require_admin() : require_login());
    if ($u && !in_array($name, PUBLIC_ACTIONS, true)) { // overdue dues: read-only / locked stages (admins and exempt people are never affected)
        $lvl = billing_level($u);
        if (($lvl === 3 && !in_array($name, LOCKED_POST, true)) || ($lvl === 2 && !in_array($name, READONLY_POST, true))) { flash('err', $lvl === 3 ? 'The app is locked until your dues are paid. You can still pay below.' : 'The app is read-only until your dues are paid. You can still look around and pay.', 'Payment overdue'); redirect(url('/dues')); }
    }
    try {
        $msg = $fn($u);
        if ($msg) { flash('ok', $msg); }
    } catch (UserError $e) {
        flash('err', $e->getMessage());
    } catch (PDOException $e) {
        log_error($e);
        flash('err', $e->getCode() === '23000' ? 'That change conflicts with existing data (for example a duplicate or a record still in use).' : 'The database rejected that change. Please check the values and try again.');
    } catch (Throwable $e) {
        log_error($e);
        flash('err', APP_DEBUG ? $e->getMessage() : 'Something went wrong. Please try again.');
    }
    redirect($GLOBALS['__redirect'] ?? $back);
}

// ---- pages ----
$routes = [
    '/' => 'page_dashboard', '/login' => 'page_login', '/register' => 'page_register',
    '/transactions' => 'page_transactions', '/transactions/export' => 'page_transactions_export',
    '/income' => 'page_income', '/expenses' => 'page_expenses', '/budget' => 'page_budget',
    '/goals' => 'page_goals', '/house' => 'page_house', '/big-purchases' => 'page_big', '/bills' => 'page_bills', '/yearly' => 'page_yearly',
    '/analysis' => 'page_analysis', '/accounts' => 'page_accounts', '/dues' => 'page_dues', '/dues/return' => 'page_dues_return', '/admin/billing' => 'page_admin_billing',
    '/manifest.webmanifest' => 'page_manifest', '/api/pulse' => 'page_pulse', '/sw.js' => 'page_sw', '/offline' => 'page_offline', '/credits' => 'page_credits',
    '/reports' => 'page_reports', '/settings' => 'page_settings', '/settings/backup' => 'page_backup',
    '/ai' => 'page_ai', '/ai/actions' => 'page_ai_actions', '/ai/settings' => 'page_ai_settings', '/ai/avatar' => 'page_ai_avatar', '/plans' => 'page_plans', '/plugins' => 'page_plugins', '/plugins/receipt' => 'page_plugin_receipt', '/admin/plugins' => 'page_admin_plugins', '/welcome' => 'page_welcome',
    '/admin/telegram' => 'page_admin_telegram', '/api/telegram' => 'page_api_telegram', '/admin/features' => 'page_admin_features', '/admin/ai' => 'page_admin_ai', '/admin/email' => 'page_admin_email', '/admin/sms' => 'page_admin_sms',
    '/admin' => 'page_admin', '/admin/users' => 'page_admin_users', '/admin/settings' => 'page_admin_settings', '/admin/activity' => 'page_admin_activity', '/admin/system' => 'page_admin_system',
];
if (!in_array(method(), ['GET', 'HEAD'], true)) { http_response_code(405); exit; }
if ($path === '/' && !current_user()) { page_welcome(); exit; } // signed-out visitors get the landing page
if (!isset($routes[$path])) { render_error(404, 'Page not found', 'That address does not exist.'); exit; }
if (!in_array($path, ['/login', '/register', '/manifest.webmanifest', '/sw.js', '/offline', '/credits'], true) && ($u = current_user()) && billing_level($u) === 3 && !in_array($path, LOCKED_GET, true)) { redirect(url('/dues')); }
$routes[$path]();
