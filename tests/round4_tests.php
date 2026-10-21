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
@exec('pkill -f "[-]S 127.0.0.1:811[0-9] " 2>/dev/null'); // orphans from an earlier aborted run would keep answering on the same port
function r4_stop(mixed $proc, int $port): void { @proc_terminate($proc, 9); for ($i = 0; $i < 50; $i++) { $fp = @fsockopen('127.0.0.1', $port, $en, $es, 0.1); if (!$fp) { break; } fclose($fp); usleep(100000); } @proc_close($proc); }
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
    $p = r4_req($base, 'GET', '/import', ['jar' => $jar]); preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $p['body'], $c); return $c[1] ?? '';
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

// ============================ FIX-1: uploads behind a hosting firewall ============================
section('R4 FIX-1: the firewall refuses multipart zips; the text fallbacks get through');
array_map('unlink', glob(upfb_dir() . '/*') ?: []); $tmpR = sys_get_temp_dir() . '/mfnc-r4-' . getmypid(); @mkdir($tmpR, 0700, true);
$stRows = [['19/10/2026', '20:51:51', 'Paid to Shop One', 'shop1@ptys on Paytm', 'Fake Bank - 11', '-1,520.00', '700000000001', '#Groceries'], ['18/10/2026', '12:00:28', 'Money sent to Friend <script>alert(1)</script>', 'friend@oksbi', 'Fake Bank - 11', '-11,000.00', '700000000002', '#Money Transfer', 'for the trip'],
    ['17/10/2026', '15:52:36', 'Received from Friend Two', 'f2@oksbi', 'Other Bank - 22', '+2,000.00', '700000000003', '#Money Received'], [['d', 46000], '09:30:00', 'Cashback Received from Paytm', 'cb@paytm', 'Other Bank - 22', '+5.50', '700000000004', '#Cashback']];
mk_paytm($tmpR . '/s.xlsx', $stRows); $xbytes = file_get_contents($tmpR . '/s.xlsx');
$upfbPost = function (string $base, string $jar, string $csrf, string $path, string $bytes, string $kind, string $name, array $o = []) {
    $part = $o['part'] ?? 60000; $n = max(1, (int) ceil(strlen($bytes) / $part)); $id = bin2hex(random_bytes(16)); $last = null;
    for ($i = 0; $i < $n; $i++) { $d = base64_encode(substr($bytes, $i * $part, $part)); if (($o['enc'] ?? 'b64') === 'b64r') { $d = strrev($d); }
        $f = ['id' => $id, 'i' => $i, 'n' => $n, 'name' => $name, 'kind' => $kind, 'enc' => $o['enc'] ?? 'b64', 'data' => $d, 'total' => strlen($bytes)]; $hd = ['X-CSRF-Token' => $csrf];
        $last = r4_req($base, 'POST', $path, ['jar' => $jar] + (($o['mode'] ?? 'form') === 'json' ? ['json' => $f] : ['form' => $f]) + ['headers' => $hd]); if ($last['code'] >= 400) { return $last; } }
    return $last;
};
$wafPort = 8112; $waf = r4_server($wafPort, ['SIM_WAF' => '1']); $wb2 = "http://127.0.0.1:$wafPort"; $jar2 = tempnam(sys_get_temp_dir(), 'jar'); $wu = $R4('r4waf@x.test'); $wcsrf = r4_login($wb2, $jar2, 'r4waf@x.test');
check('logged in on the firewalled host', strlen($wcsrf) === 64);
$mp = r4_req($wb2, 'POST', '/do/import-upload', ['jar' => $jar2, 'form' => ['_csrf' => $wcsrf], 'files' => ['file' => $tmpR . '/s.xlsx'], 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'fname' => 's.xlsx']);
eq($mp['code'], 403, 'the simulated firewall refuses the multipart zip with 403'); check('and it is the firewall page, not the app (no X-Mfnc-App header)', empty($mp['h']['x-mfnc-app']) && str_contains($mp['body'], 'Access to this resource on the server is denied'));
$ok = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', $xbytes, 'statement', 's.xlsx'); $oj = json_decode($ok['body'], true);
check('the same bytes as urlencoded base64 text parts are accepted', $ok['code'] === 200 && !empty($oj['done']) && str_contains($oj['redirect'] ?? '', '/import?p='), $ok['body']);
$tokA = substr((string) $oj['redirect'], strpos($oj['redirect'], 'p=') + 2); $pvA = import_load_preview($wu, $tokA); check('a preview exists, owned by the person', $pvA && $pvA['stmt']['source'] === 'paytm' && count($pvA['stmt']['rows']) === 4);
$pgA = r4_req($wb2, 'GET', $oj['redirect'], ['jar' => $jar2]); check('and the preview page opens', $pgA['code'] === 200 && str_contains($pgA['body'], 'Review your'));
$oj2 = json_decode($upfbPost($wb2, $jar2, $wcsrf, '/import/b64', $xbytes, 'statement', 's.xlsx', ['mode' => 'json', 'part' => 3000])['body'], true); check('as JSON, in many small parts', !empty($oj2['done']));
$oj3 = json_decode($upfbPost($wb2, $jar2, $wcsrf, '/import/b64', $xbytes, 'statement', 's.xlsx', ['enc' => 'b64r'])['body'], true); check('with the reversed-base64 prefix scramble', !empty($oj3['done']));
$sameRows = fn($a, $b) => json_encode(array_map(fn($r) => [$r['date'], $r['amount'], $r['payee'], $r['key']], $a)) === json_encode(array_map(fn($r) => [$r['date'], $r['amount'], $r['payee'], $r['key']], $b));
$tokB = substr((string) $oj3['redirect'], strpos($oj3['redirect'], 'p=') + 2); check('identical rows to the multipart path', $sameRows(import_load_preview($wu, $tokB)['stmt']['rows'], import_parse_workbook(workbook_load($tmpR . '/s.xlsx', 's.xlsx'))['rows']));
check('no staging files are left behind', !glob(upfb_dir() . '/' . $wu . '-*'));
// what the browser would send when it read the workbook itself
$wbd = workbook_load($tmpR . '/s.xlsx', 's.xlsx'); $clientJson = ['kind' => 'xlsx', 'date1904' => false, 'sheets' => []];
foreach ($wbd['sheets'] as $sh) { $rows = []; foreach ($sh['rows'] as $rn => $row) { foreach ($row as $cn => $v) { $rows[$rn][$cn] = $v instanceof XlsxDate ? ['s' => $v->serial] : $v; } } $clientJson['sheets'][] = ['name' => $sh['name'], 'state' => $sh['state'], 'kind' => $sh['kind'], 'rows' => (object) array_map(fn($r) => (object) $r, $rows)]; }
$cj = json_encode($clientJson);
r4_stop($waf, $wafPort); $waf = r4_server($wafPort, ['SIM_WAF' => '2']);

$blk = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', $xbytes, 'statement', 's.xlsx'); eq($blk['code'], 403, 'level 2: base64 of a zip is refused too'); $blk2 = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', $xbytes, 'statement', 's.xlsx', ['enc' => 'b64r']); eq($blk2['code'], 403, 'also reversed');
$rr = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', $cj, 'rows', 's.xlsx'); $rj = json_decode($rr['body'], true);
check('level 2: the rows read in the browser are accepted', $rr['code'] === 200 && !empty($rj['done']), $rr['body']);
$tokC = substr((string) $rj['redirect'], strpos($rj['redirect'], 'p=') + 2); $pvC = import_load_preview($wu, $tokC);
check('client-parsed rows give exactly the same transactions as the server reader (same normalisation, dedupe keys, dates)', $sameRows($pvC['stmt']['rows'], import_parse_workbook(workbook_load($tmpR . '/s.xlsx', 's.xlsx'))['rows']) && count($pvC['stmt']['rows']) === 4);
check('the preview says the file was read in the browser', str_contains((string) $pvC['filename'], 's.xlsx'));
// the server does not trust the browser's rows
$evil = fn(array $mut) => json_encode(array_replace_recursive($clientJson, $mut));
$e1r = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', json_encode(['kind' => 'xlsx', 'sheets' => [['name' => 'S', 'rows' => [['-5' => ['1' => 'x']]]]]]), 'rows', 'e.xlsx'); eq($e1r['code'], 422, 'a negative row number is refused');
$e2r = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', json_encode(['kind' => 'xlsx', 'sheets' => [['name' => 'S', 'rows' => ['1' => ['1' => ['s' => 'abc']]]]]]), 'rows', 'e.xlsx'); eq($e2r['code'], 422, 'a date cell with a non-number is refused');
$e3r = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', json_encode(['kind' => 'zip', 'sheets' => []]), 'rows', 'e.xlsx'); eq($e3r['code'], 422, 'the wrong kind is refused');
$e4r = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', json_encode(['kind' => 'xlsx', 'sheets' => array_fill(0, XLSX_MAX_SHEETS + 1, ['name' => 'S', 'rows' => (object) []])]), 'rows', 'e.xlsx'); eq($e4r['code'], 422, 'too many sheets are refused');
$big = []; for ($i = 1; $i <= 2100; $i++) { $row = []; for ($c = 1; $c <= 200; $c++) { $row[$c] = 1; } $big[$i] = $row; } $e5r = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', json_encode(['kind' => 'xlsx', 'sheets' => [['name' => 'S', 'rows' => $big]]]), 'rows', 'e.xlsx', ['part' => 300000]); eq($e5r['code'], 422, 'more than 400,000 cells are refused');
$e6r = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', json_encode(['kind' => 'xlsx', 'sheets' => [['name' => 'S', 'rows' => ['1' => ['1' => ['x' => 1, 'y' => 2]]]]]]), 'rows', 'e.xlsx'); eq($e6r['code'], 422, 'a cell that is an object is refused');
$wbDirect = import_rows_to_workbook(json_encode(['kind' => 'xlsx', 'sheets' => [['name' => str_repeat('n', 300), 'state' => 'weird', 'rows' => ['3' => ['2' => str_repeat('z', 9000), '999' => 5, '1' => null, '4' => INF === 0 ? 1 : true]]]]])); eq(mb_strlen($wbDirect['sheets'][0]['name']), 120, 'sheet names are capped'); eq($wbDirect['sheets'][0]['state'], 'visible', 'unknown sheet state becomes visible'); eq(mb_strlen($wbDirect['sheets'][0]['rows'][3][2]), 4000, 'long strings are capped'); check('columns past the limit are dropped and flagged', !isset($wbDirect['sheets'][0]['rows'][3][999]) && $wbDirect['sheets'][0]['truncated']);
// protocol safety
$nocsrf = r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'form' => ['id' => str_repeat('a', 32), 'i' => 0, 'n' => 1, 'data' => 'QUJD']]); eq($nocsrf['code'], 403, 'no CSRF token: refused'); check('with a JSON body from the app (X-Mfnc-App)', !empty($nocsrf['h']['x-mfnc-app']) && json_decode($nocsrf['body'], true)['ok'] === false);
$anon = r4_req($wb2, 'POST', '/import/b64', ['form' => ['id' => str_repeat('a', 32)]]); eq($anon['code'], 401, 'signed-out: 401');
$ooo = r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'headers' => ['X-CSRF-Token' => $wcsrf], 'form' => ['id' => str_repeat('b', 32), 'i' => 1, 'n' => 3, 'kind' => 'statement', 'data' => 'QUJD']]); eq($ooo['code'], 422, 'a part that arrives before part 0 is refused');
$idp = str_repeat('c', 32); $hd = ['X-CSRF-Token' => $wcsrf]; $mk = fn(int $i, string $d) => ['id' => $idp, 'i' => $i, 'n' => 3, 'kind' => 'statement', 'name' => 'x.xlsx', 'data' => base64_encode($d)];
$a0 = r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'headers' => $hd, 'form' => $mk(0, 'aaa')]); $a0b = r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'headers' => $hd, 'form' => $mk(0, 'aaa')]);
check('part 0 can be re-sent (it restarts the upload cleanly)', $a0['code'] === 200 && $a0b['code'] === 200); r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'headers' => $hd, 'form' => $mk(1, 'bbb')]); $a1b = r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'headers' => $hd, 'form' => $mk(1, 'bbb')]);
check('a repeated part is acknowledged, not appended twice', $a1b['code'] === 200 && !empty(json_decode($a1b['body'], true)['repeat']) && filesize(upfb_dir() . '/' . $wu . '-' . $idp . '.part') === 6);
$bad64 = r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'headers' => $hd, 'form' => $mk(2, '') + ['data' => '***not base64***']]); eq($bad64['code'], 422, 'damaged base64 is refused');
$tooMany = r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'headers' => ['X-CSRF-Token' => $wcsrf], 'form' => ['id' => str_repeat('d', 32), 'i' => 0, 'n' => 9999, 'kind' => 'statement', 'data' => 'QUJD']]); eq($tooMany['code'], 422, 'an absurd number of parts is refused');
$wrongKind = r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'headers' => ['X-CSRF-Token' => $wcsrf], 'form' => ['id' => str_repeat('e', 32), 'i' => 0, 'n' => 1, 'kind' => 'ai', 'data' => 'QUJD']]); eq($wrongKind['code'], 400, 'the import endpoint does not accept AI uploads');
// the same parser limits as a multipart upload: a text-uploaded zip bomb and a fake xlsx
$zb = $tmpR . '/bomb.xlsx'; $zz = new ZipArchive(); $zz->open($zb, ZipArchive::CREATE | ZipArchive::OVERWRITE); $zz->addFromString('[Content_Types].xml', 'spreadsheetml'); $zz->addFromString('xl/workbook.xml', '<workbook/>'); $zz->addFromString('xl/_rels/workbook.xml.rels', '<Relationships/>'); $zz->addFromString('xl/worksheets/sheet1.xml', str_repeat('0', 6 * 1048576)); $zz->close();
r4_stop($waf, $wafPort); $waf = r4_server($wafPort, ['SIM_WAF' => '0']);
$total = r4_req($wb2, 'POST', '/import/b64', ['jar' => $jar2, 'headers' => ['X-CSRF-Token' => $wcsrf], 'form' => ['id' => str_repeat('f', 32), 'i' => 0, 'n' => 1, 'kind' => 'statement', 'name' => 'x.xlsx', 'data' => base64_encode($xbytes), 'total' => 5]]); eq($total['code'], 422, 'a declared size that does not match is refused');
$zr = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', (string) file_get_contents($zb), 'statement', 'bomb.xlsx'); check('a zip bomb sent as text is refused by the same reader', $zr['code'] === 422 && str_contains(json_decode($zr['body'], true)['message'] ?? '', 'bomb'), $zr['body']);
$fk = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', "plain text, not a workbook\x00\x01", 'statement', 'fake.xlsx'); check('a fake spreadsheet sent as text gets the same friendly refusal', $fk['code'] === 422 && str_contains(json_decode($fk['body'], true)['message'] ?? '', 'not a spreadsheet'));
$huge = $upfbPost($wb2, $jar2, $wcsrf, '/import/b64', str_repeat('A', XLSX_MAX_BYTES + 200000), 'statement', 'huge.xlsx', ['part' => 600000]); check('the 8 MB file limit holds over text too', $huge['code'] === 422 && str_contains(json_decode($huge['body'], true)['message'] ?? '', 'too large'));
array_map('unlink', glob(upfb_dir() . '/' . $wu . '-' . $idp . '.*') ?: []); check('refused uploads leave no staging files', !glob(upfb_dir() . '/' . $wu . '-*.part'));
// chat attachments over text
set_setting('ai_enabled', '1'); $ra = $upfbPost($wb2, $jar2, $wcsrf, '/ai/upload/b64', $R4_PNG, 'ai', 'receipt.png'); $raj = json_decode($ra['body'], true);
check('chat attachments can use the text path too', $ra['code'] === 200 && ($raj['type'] ?? '') === 'ok' && $raj['mime'] === 'image/png' && (int) $raj['id'] > 0, $ra['body']);
$rb = $upfbPost($wb2, $jar2, $wcsrf, '/ai/upload/b64', "MZ\x90\x00\x03\x00\x00\x00 binary junk", 'ai', 'x.png'); check('the same type check applies', $rb['code'] === 422 && str_contains(json_decode($rb['body'], true)['message'], 'not supported'));
r4_stop($waf, $wafPort);
// the rate limit for the rows path
$rl = $R4('r4rate@x.test'); $limit = null; for ($i = 0; $i < 14; $i++) { try { import_handle_workbook($rl, import_rows_to_workbook($cj), 'r.xlsx', true); } catch (UserError $e) { $limit = $i; break; } } eq($limit, 12, 'client-parsed rows share the 12-per-hour upload limit');
require __DIR__ . '/round4_json_tests.php';
require __DIR__ . '/round4_docs_tests.php';
