<?php
// Round 4 (builder-E) tests, included by tests/run.php after the import tests. All data is synthetic.
$R4 = fn(string $e) => create_user($e, 'Round Four', 'password-12345');
$R4_PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$R4_body = fn(array $msgs, array $extra = []) => $extra + ['model' => 'claude-sonnet-5-5', 'max_tokens' => 1000, 'messages' => $msgs, 'tools' => ai_tools()];

section('R4 FIX-2: the request validator behaves like the API');
$tuOk = ['type' => 'tool_use', 'id' => 'toolu_abc123', 'name' => 'list_accounts', 'input' => new stdClass()];
$good = [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => [$tuOk]], ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_abc123', 'content' => '{"a":1}', 'is_error' => false]]]];
eq(ai_validate_request($R4_body($good)), null, 'a correct tool round-trip is accepted');
$bad = $good; $bad[1]['content'][0]['input'] = [];
check('tool_use.input [] (an empty PHP array) is rejected', str_contains((string) ai_validate_request($R4_body($bad)), 'tool_use.input'));
eq(ai_validate_request($R4_body([$good[0], ['role' => 'assistant', 'content' => ai_fix_content($bad[1]['content'])], $good[2]])), null, 'ai_fix_content repairs it');
check('a tool_use without a following tool_result is rejected', str_contains((string) ai_validate_request($R4_body([$good[0], $good[1]])), 'tool_result'));
check('a tool_result for an unknown id is rejected', ai_validate_request($R4_body([$good[0], $good[1], ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'nope', 'content' => 'x']]]])) !== null);
check('a tool_result with no tool_use before it is rejected', str_contains((string) ai_validate_request($R4_body([$good[0], ['role' => 'assistant', 'content' => 'ok'], ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'nope', 'content' => 'x']]]])), 'tool_use_id'));
check('tool_result after text is rejected (must come first)', str_contains((string) ai_validate_request($R4_body([$good[0], $good[1], ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello'], $good[2]['content'][0]]]])), 'come first'));
check('two user messages in a row are rejected', str_contains((string) ai_validate_request($R4_body([['role' => 'user', 'content' => 'a'], ['role' => 'user', 'content' => 'b']])), 'alternate'));
check('the first message must be from the user', str_contains((string) ai_validate_request($R4_body([['role' => 'assistant', 'content' => 'a']])), 'first message'));
check('empty text blocks are rejected', str_contains((string) ai_validate_request($R4_body([['role' => 'user', 'content' => [['type' => 'text', 'text' => '  ']]]])), 'non-empty'));
check('empty string content is rejected', ai_validate_request($R4_body([['role' => 'user', 'content' => '']])) !== null);
check('max_tokens must exceed the thinking budget', str_contains((string) ai_validate_request($R4_body([$good[0]], ['max_tokens' => 1500, 'thinking' => ['type' => 'enabled', 'budget_tokens' => 2000]])), 'max_tokens'));
check('thinking budget below 1024 is rejected', ai_validate_request($R4_body([$good[0]], ['thinking' => ['type' => 'enabled', 'budget_tokens' => 500]])) !== null);
eq(ai_validate_request($R4_body([$good[0]], ['max_tokens' => 4000, 'thinking' => ['type' => 'enabled', 'budget_tokens' => 2000]])), null, 'a valid thinking budget passes');
eq(ai_validate_request($R4_body([$good[0]], ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'high']])), null, 'adaptive thinking + effort passes');
check('a bad effort value is rejected', ai_validate_request($R4_body([$good[0]], ['output_config' => ['effort' => 'turbo']])) !== null);
$t1 = ai_tools(); $t1[0]['input_schema']['properties'] = []; check('tool schema with properties [] (a list) is rejected', str_contains((string) ai_validate_request(['model' => 'm', 'max_tokens' => 10, 'messages' => [$good[0]], 'tools' => $t1]), 'properties'));
$t2 = ai_tools(); $t2[0]['input_schema'] = 'x'; check('tool input_schema must be an object', ai_validate_request(['model' => 'm', 'max_tokens' => 10, 'messages' => [$good[0]], 'tools' => $t2]) !== null);
$t3 = ai_tools(); $t3[] = $t3[0]; check('duplicate tool names are rejected', str_contains((string) ai_validate_request(['model' => 'm', 'max_tokens' => 10, 'messages' => [$good[0]], 'tools' => $t3]), 'unique'));
$img = fn(string $mt, string $data) => ['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mt, 'data' => $data]], ['type' => 'text', 'text' => 'look']]];
eq(ai_validate_request($R4_body([$img('image/png', base64_encode($R4_PNG))])), null, 'a real PNG passes');
check('PNG bytes labelled as JPEG are rejected', str_contains((string) ai_validate_request($R4_body([$img('image/jpeg', base64_encode($R4_PNG))])), 'does not match'));
check('invalid base64 is rejected', str_contains((string) ai_validate_request($R4_body([$img('image/png', 'not base64 !!')])), 'base64'));
check('an unsupported media type is rejected', ai_validate_request($R4_body([$img('image/bmp', base64_encode($R4_PNG))])) !== null);
check('a document that is not a PDF is rejected', str_contains((string) ai_validate_request($R4_body([['role' => 'user', 'content' => [['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode('hello world')]], ['type' => 'text', 'text' => 'x']]]])), 'PDF'));

section('R4 FIX-2: the mock transports fail like the API');
putenv('MFNC_AI_MOCK=1'); $m1 = $R4('r4a@x.test'); $r4u = ['id' => $m1, 'role' => 'user', 'name' => 'Round Four'];
$e1 = null; try { ai_mock_call($R4_body([$good[0], ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_x', 'name' => 'list_accounts', 'input' => []]]], ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_x', 'content' => '{}']]]])); } catch (UserError $e) { $e1 = $e; }
check('ai_mock_call throws AiApiError(invalid_request_error) for the old Telegram bug', $e1 instanceof AiApiError && $e1->apiType === 'invalid_request_error' && str_contains($e1->apiMessage, 'tool_use.input'));
$e2 = null; try { tg_mock_call($R4_body([['role' => 'assistant', 'content' => 'x']])); } catch (UserError $e) { $e2 = $e; } check('tg_mock_call validates too', $e2 instanceof AiApiError);
$e3 = null; try { lw_mock_call($R4_body([['role' => 'user', 'content' => 'a'], ['role' => 'user', 'content' => 'b']])); } catch (UserError $e) { $e3 = $e; } check('lw_mock_call validates too', $e3 instanceof AiApiError);
check('ai_redact removes keys and tokens', !str_contains(ai_redact('bad key sk-ant-api03-abcdefghijklmnop and Bearer abcdefghijklmnopqrstuv'), 'abcdefghijklmnop'));
eq(ai_fix_body(['messages' => [['role' => 'assistant', 'content' => [['type' => 'text', 'text' => ' '], ['type' => 'tool_use', 'id' => 'a', 'name' => 'x', 'input' => []]]]]])['messages'][0]['content'][0]['input'] instanceof stdClass, true, 'ai_fix_body repairs input and drops empty text');
