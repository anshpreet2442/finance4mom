<?php
// Minimal stand-in for the PayU Partner API, used only by tests (php -S 127.0.0.1:8099 gateway_mock.php).
// State lives in a JSON file so the test process can inspect it and decide outcomes.
$state = sys_get_temp_dir() . '/mfnc_gateway_mock.json';
$db = is_file($state) ? json_decode(file_get_contents($state), true) : ['payments' => [], 'inits' => 0];
$save = function () use (&$db, $state) { file_put_contents($state, json_encode($db)); };
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); $method = $_SERVER['REQUEST_METHOD'];
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$out = function (int $code, array $body) { http_response_code($code); echo json_encode($body); exit; };
if ($auth !== 'Bearer umpi_live_test') { $out(401, ['success' => false, 'error' => ['code' => 'unauthorized', 'message' => 'Token not recognised.']]); }
if ($path === '/api/v1/ping') { $out(200, ['success' => true, 'status' => 'authenticated', 'app' => 'Test', 'build' => 'mock']); }
if ($path === '/api/v1/payment/init' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b) || ($b['amount'] ?? 0) <= 0) { $out(400, ['success' => false, 'error' => ['code' => 'invalid_amount', 'message' => 'Amount must be positive.']]); }
    $db['inits']++; $id = 'apay_' . bin2hex(random_bytes(8));
    $db['payments'][$id] = ['amount' => $b['amount'], 'currency' => $b['currency'], 'status' => 'pending', 'metadata' => $b['metadata'] ?? null, 'redirect_mode' => $b['redirect_mode'] ?? null, 'success' => $b['success_redirect_url'] ?? null];
    $save(); $out(201, ['success' => true, 'api_payment_id' => $id, 'embed_url' => "https://gateway.test/embed?ct=$id", 'amount' => $b['amount'], 'currency' => 'INR']);
}
if (preg_match('~^/api/v1/payment/status/(.+)$~', $path, $m)) {
    $p = $db['payments'][$m[1]] ?? null; if (!$p) { $out(404, ['success' => false, 'error' => ['code' => 'not_found', 'message' => 'No such payment.']]); }
    $out(200, ['success' => true, 'api_payment_id' => $m[1], 'status' => $p['status'], 'amount' => $p['amount'], 'currency' => $p['currency'], 'rzp_payment_id' => 'pay_mock', 'method' => 'upi']);
}
$out(404, ['success' => false, 'error' => ['code' => 'not_found', 'message' => 'Route not found: ' . $method . ' ' . $path]]);
