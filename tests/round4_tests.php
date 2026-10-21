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

// ============================ HTTP helpers (a real PHP server behind tests/apache_sim.php) ============================
function r4_stop(mixed $proc, int $port): void { @proc_terminate($proc); for ($i = 0; $i < 50; $i++) { $fp = @fsockopen('127.0.0.1', $port, $en, $es, 0.1); if (!$fp) { break; } fclose($fp); usleep(100000); } @proc_close($proc); }
function r4_server(int $port, array $env = []): mixed {
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/apache_sim.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__), array_merge(getenv(), ['MFNC_AI_MOCK' => '1'], $env));
    for ($i = 0; $i < 60; $i++) { $fp = @fsockopen('127.0.0.1', $port, $en, $es, 0.2); if ($fp) { fclose($fp); break; } usleep(100000); }
    return $proc;
}
/** One request. $o: jar (cookie file), form (urlencoded array), json (array), files [field => path], headers [...]. Returns code, headers (lower-cased name => list of values) and body. */
function r4_req(string $base, string $method, string $path, array $o = []): array {
    $ch = curl_init($base . $path); $h = []; $hdr = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$h) { if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))][] = trim($v); } return strlen($line); }]);
    if (!empty($o['jar'])) { curl_setopt($ch, CURLOPT_COOKIEJAR, $o['jar']); curl_setopt($ch, CURLOPT_COOKIEFILE, $o['jar']); }
    foreach ($o['headers'] ?? [] as $k => $v) { $hdr[] = "$k: $v"; }
    if (isset($o['json'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json'])); $hdr[] = 'Content-Type: application/json'; }
    elseif (isset($o['files'])) { $f = $o['form'] ?? []; foreach ($o['files'] as $k => $p) { $f[$k] = new CURLFile($p, $o['mime'] ?? 'application/octet-stream', $o['fname'] ?? basename($p)); } curl_setopt($ch, CURLOPT_POSTFIELDS, $f); }
    elseif (isset($o['form'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($o['form'])); }
    if ($hdr) { curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr); }
    $body = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['code' => $code, 'h' => $h, 'body' => $body];
}
function r4_login(string $base, string $jar, string $email): string {
    $r = r4_req($base, 'GET', '/login', ['jar' => $jar]); preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
    r4_req($base, 'POST', '/do/login', ['jar' => $jar, 'form' => ['_csrf' => $m[1] ?? '', 'email' => $email, 'password' => 'password-12345']]);
    $p = r4_req($base, 'GET', '/ai', ['jar' => $jar]); preg_match('/csrf":"([a-f0-9]{64})"/', $p['body'], $c); if (empty($c[1])) { $p = r4_req($base, 'GET', '/import', ['jar' => $jar]); }
    preg_match('/<meta name="csrf-token" content="([^"]+)"|name="_csrf" value="([a-f0-9]{64})"/', $p['body'], $c2); return $c2[1] ?: ($c2[2] ?? ($c[1] ?? ''));
}

section('R4 FIX-3: preview framing, one X-Frame-Options, wireframe without GD');
$r4Port = 8111; $srv = r4_server($r4Port); $base = "http://127.0.0.1:$r4Port"; $jar = tempnam(sys_get_temp_dir(), 'jar');
$lu = $R4('r4layout@x.test'); $csrf = r4_login($base, $jar, 'r4layout@x.test');
$draft = lw_draft_open($lu, 'desktop'); $dId = (int) $draft['id'];
$pv = r4_req($base, 'GET', '/layout/preview?draft=' . $dId, ['jar' => $jar]);
eq($pv['code'], 200, 'the preview page is served'); eq($pv['h']['x-frame-options'] ?? [], ['SAMEORIGIN'], 'preview: exactly one X-Frame-Options and it is SAMEORIGIN (index.php sets DENY, the route replaces it, .htaccess only fills if empty)');
check('preview CSP allows our own origin to frame it', in_array("frame-ancestors 'self'", $pv['h']['content-security-policy'] ?? [], true)); check('preview body is the layout', str_contains($pv['body'], 'data-preview="1"'));
$ai = r4_req($base, 'GET', '/ai', ['jar' => $jar]); eq($ai['h']['x-frame-options'] ?? [], ['DENY'], 'ordinary pages keep exactly one X-Frame-Options: DENY'); check('our responses are marked X-Mfnc-App', ($ai['h']['x-mfnc-app'] ?? []) === ['1']);
check('no other header on the preview forbids framing', !array_filter($pv['h']['content-security-policy'] ?? [], fn($v) => str_contains($v, "frame-ancestors 'none'")));
$ht = file_get_contents(dirname(__DIR__) . '/.htaccess'); check('.htaccess fills X-Frame-Options only when empty (setifempty)', str_contains($ht, 'setifempty X-Frame-Options') && !preg_match('/Header\s+(always\s+)?set\s+X-Frame-Options/i', $ht));
$bad = tempnam(sys_get_temp_dir(), 'ht'); file_put_contents($bad, "<IfModule mod_headers.c>\nHeader always set X-Frame-Options \"DENY\"\n</IfModule>\n"); r4_stop($srv, $r4Port); $srv = r4_server($r4Port, ['SIM_HTACCESS' => $bad]);
$pv2 = r4_req($base, 'GET', '/layout/preview?draft=' . $dId, ['jar' => $jar]); eq($pv2['h']['x-frame-options'] ?? [], ['DENY'], 'the emulation does catch the old always-set rule (DENY overrides the preview)'); unlink($bad); r4_stop($srv, $r4Port); $srv = r4_server($r4Port);
$w = r4_req($base, 'GET', '/layout/wire.png?draft=' . $dId, ['jar' => $jar]); $ct = $w['h']['content-type'][0] ?? '';
check('wire.png answers with an image whose content type matches its bytes', (str_starts_with($ct, 'image/png') && str_starts_with($w['body'], "\x89PNG")) || (str_starts_with($ct, 'image/svg+xml') && str_starts_with($w['body'], '<svg')));
$ws = r4_req($base, 'GET', '/layout/wire.svg?draft=' . $dId, ['jar' => $jar]); check('wire.svg is an SVG through the rewrite (a non-existing .svg path reaches index.php)', str_starts_with($ws['h']['content-type'][0] ?? '', 'image/svg+xml') && str_starts_with($ws['body'], '<svg'));
$wq = r4_req($base, 'GET', '/layout/wire.png?draft=' . $dId . '&svg=1', ['jar' => $jar]); check('the PNG url can be forced to SVG', str_starts_with($wq['body'], '<svg'));
$sv = simplexml_load_string($ws['body']); check('the wireframe SVG is well-formed XML with one box per visible widget', $sv !== false && count($sv->xpath('//*[local-name()="rect"][@rx="4"]')) >= 3);
$lay = lw_draft_layout($draft); $lay['widgets'][0]['visible'] = true; $svg = lw_wire_svg($lay, 'mobile'); check('mobile wireframe SVG renders', str_starts_with($svg, '<svg') && simplexml_load_string($svg) !== false);
$st = lw_state(['id' => $lu, 'name' => 'x', 'role' => 'user'], lw_draft_row($lu, $dId)); check('the layout state carries the inline SVG (no extra request, no GD)', str_starts_with($st['wire_svg'], '<svg'));
$GLOBALS['__no_gd'] = 1; check('without GD the PNG decision is off', !lw_png_ok() && !gd_png_ok()); unset($GLOBALS['__no_gd']);
$ES = fn(string $t) => $t; $evil = $lay; $evil['widgets'][0]['visible'] = true; $svgE = lw_wire_svg($evil, 'desktop'); check('wireframe text is escaped', !preg_match('/<text[^>]*>[^<]*<script/i', $svgE));
// share images and QR without GD
// share images and QR without GD
set_setting('feat_share_links_mode', 'free'); $sl = share_create($lu, ['title' => 'Family <b>view</b>', 'widgets' => ['summary'], 'mask' => true]);
$og = r4_req($base, 'GET', '/s/' . $sl['token'] . '/og.png'); check('og.png works through the rewrite (a PNG here, GD is installed)', str_starts_with($og['h']['content-type'][0] ?? '', 'image/png') && str_starts_with($og['body'], "\x89PNG"));
$qs = r4_req($base, 'GET', '/s/' . $sl['token'] . '/qr.svg'); check('qr.svg works through the rewrite', str_starts_with($qs['h']['content-type'][0] ?? '', 'image/svg+xml') && str_contains($qs['body'], '<svg'));
$GLOBALS['__no_gd'] = 1; ob_start(); share_og_png(share_find($sl['token'])); $ogs = ob_get_clean(); unset($GLOBALS['__no_gd']);
check('og.png without GD is an SVG card instead of a fatal error', str_starts_with($ogs, '<svg') && str_contains($ogs, 'Family') && !str_contains($ogs, '<b>'));
$qr = one('SELECT * FROM {c_share_links} WHERE id=?', [$sl['id']]); $_GET = ['id' => $sl['id'], 'fmt' => 'png']; $_SESSION['uid'] = $lu; unset($GLOBALS['__user']); $GLOBALS['__no_gd'] = 1; ob_start(); share_qr_http(); $qb = ob_get_clean(); unset($GLOBALS['__no_gd']); $_GET = [];
check('qr as PNG without GD falls back to SVG', str_starts_with($qb, '<svg') || str_contains($qb, '<svg'));
$GLOBALS['__no_gd'] = 1; eq(pdf_chart_bars(['a'], [['x', '#000000', [1]]]), '', 'PDF charts are skipped without GD'); eq(pdf_chart_pie([['a', 5]]), '', 'pie too'); unset($GLOBALS['__no_gd']);
$GLOBALS['__no_gd'] = 1; $rb = tg_render_block(['type' => 'chart', 'kind' => 'donut', 'title' => 'Spend', 'labels' => ['Food', 'Rent'], 'series' => [['name' => 's', 'values' => [10.0, 20.0]]], 'money' => true], 1, $lu); unset($GLOBALS['__no_gd']);
check('Telegram charts without GD become a text table', $rb && $rb[0]['photo'] === null && str_contains($rb[0]['html'], 'Food') && str_contains($rb[0]['html'], '<pre>'));
$rbp = tg_render_block(['type' => 'chart', 'kind' => 'donut', 'title' => 'Spend', 'labels' => ['Food', 'Rent'], 'series' => [['name' => 's', 'values' => [10.0, 20.0]]], 'money' => true], 1, $lu); check('and a PNG when GD is there', $rbp[0]['photo'] !== null && str_starts_with((string) $rbp[0]['photo'], "\x89PNG"));
r4_stop($srv, $r4Port);
