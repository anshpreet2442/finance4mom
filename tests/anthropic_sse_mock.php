<?php
// Minimal stand-in for POST /v1/messages with "stream": true (php -S 127.0.0.1:8095 tests/anthropic_sse_mock.php).
// The first user text picks the behaviour: "tool" streams a tool_use, "error400" answers a JSON error, "cut" ends the stream without message_stop, anything else streams text.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); if ($path !== '/v1/messages' || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(404); echo '{}'; exit; }
$b = json_decode((string) file_get_contents('php://input'), true) ?: []; $first = $b['messages'][0]['content'] ?? ''; $first = is_array($first) ? json_encode($first) : (string) $first;
file_put_contents(sys_get_temp_dir() . '/mfnc_sse_mock_last.json', json_encode(['stream' => $b['stream'] ?? null, 'accept' => $_SERVER['HTTP_ACCEPT'] ?? '', 'key' => $_SERVER['HTTP_X_API_KEY'] ?? '']));
if (str_contains($first, 'error400')) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'bad thing']]); exit; }
header('Content-Type: text/event-stream'); header('Cache-Control: no-cache'); @ini_set('output_buffering', '0'); @ini_set('zlib.output_compression', '0'); while (ob_get_level()) { ob_end_flush(); }
$ev = function (string $type, array $d) { echo "event: $type\ndata: " . json_encode(['type' => $type] + $d) . "\n\n"; flush(); };
$ev('message_start', ['message' => ['id' => 'msg_s1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => [], 'usage' => ['input_tokens' => 120, 'cache_read_input_tokens' => 30, 'output_tokens' => 1]]]); echo "event: ping\ndata: {\"type\": \"ping\"}\n\n"; flush();
if (str_contains($first, 'tool')) {
    $ev('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]); $ev('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Let me look. ']]); $ev('content_block_stop', ['index' => 0]);
    $ev('content_block_start', ['index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'list_accounts', 'input' => new stdClass()]]);
    foreach (['{"sc', 'ope":"al', 'l"}'] as $pj) { $ev('content_block_delta', ['index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $pj]]); }
    $ev('content_block_stop', ['index' => 1]); $ev('message_delta', ['delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 40]]); $ev('message_stop', []); exit;
}
$ev('content_block_start', ['index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
foreach (['Hello', ' there. This is ', 'the second sentence', '. And a third one.'] as $piece) { $ev('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $piece]]); usleep(60000); }
$ev('content_block_stop', ['index' => 0]);
if (str_contains($first, 'cut')) { exit; }
$ev('message_delta', ['delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 22]]); $ev('message_stop', []);
