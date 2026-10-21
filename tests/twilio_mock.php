<?php
// Stand-in for the Twilio REST API (php -S 127.0.0.1:8098 tests/twilio_mock.php). Records each message in a JSON file.
$f = sys_get_temp_dir() . '/mfnc_twilio_mock.json'; $db = is_file($f) ? json_decode(file_get_contents($f), true) : [];
header('Content-Type: application/json');
if (!preg_match('~^/2010-04-01/Accounts/(AC\w+)/Messages\.json$~', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) { http_response_code(404); echo '{"message":"not found"}'; exit; }
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== $m[1] || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'tok_test') { http_response_code(401); echo '{"code":20003,"message":"Authenticate"}'; exit; }
if (!preg_match('/^\+\d{8,15}$/', $_POST['To'] ?? '')) { http_response_code(400); echo '{"code":21211,"message":"Invalid To number"}'; exit; }
$db[] = ['to' => $_POST['To'], 'from' => $_POST['From'] ?? '', 'body' => $_POST['Body'] ?? '']; file_put_contents($f, json_encode($db));
http_response_code(201); echo json_encode(['sid' => 'SM' . bin2hex(random_bytes(8)), 'status' => 'queued']);
