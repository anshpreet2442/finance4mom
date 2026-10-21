<?php
// Minimal stand-in for the ElevenLabs REST API (php -S 127.0.0.1:8098 tests/elevenlabs_mock.php). Counts calls in a JSON file so tests can check caching.
$state = sys_get_temp_dir() . '/mfnc_el_mock.json'; $db = is_file($state) ? json_decode((string) file_get_contents($state), true) : ['tts' => 0, 'stt' => 0, 'last_text' => '', 'last_voice' => '', 'last_model' => ''];
$save = function () use (&$db, $state) { file_put_contents($state, json_encode($db)); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); $key = $_SERVER['HTTP_XI_API_KEY'] ?? '';
if ($path === '/__state') { header('Content-Type: application/json'); echo json_encode($db); exit; } if ($path === '/__reset') { @unlink($state); echo 'ok'; exit; }
if ($key !== 'test-el-key') { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['detail' => ['status' => 'invalid_api_key']]); exit; }
if (preg_match('~^/v1/text-to-speech/([A-Za-z0-9_-]+)$~', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode((string) file_get_contents('php://input'), true); if ($m[1] === 'failvoice') { http_response_code(500); echo 'boom'; exit; }
    $db['tts']++; $db['last_text'] = $b['text'] ?? ''; $db['last_voice'] = $m[1]; $db['last_model'] = $b['model_id'] ?? ''; $db['last_format'] = $_GET['output_format'] ?? ''; $save(); header('Content-Type: audio/mpeg');
    echo "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xFF\xFB\x90\x64" . str_repeat("\x00", 413), 6); exit;
}
if ($path === '/v1/speech-to-text' && $_SERVER['REQUEST_METHOD'] === 'POST') { $db['stt']++; $db['last_stt_bytes'] = isset($_FILES['file']) ? filesize($_FILES['file']['tmp_name']) : 0; $db['last_stt_model'] = $_POST['model_id'] ?? ''; $save(); header('Content-Type: application/json'); echo json_encode(['text' => 'hello from the mock', 'language_code' => 'en']); exit; }
http_response_code(404); echo '{}';
