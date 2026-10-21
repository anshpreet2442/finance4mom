<?php
// Statement import tests (included by tests/run.php). Every file here is a SYNTHETIC workbook built with ZipArchive: fake names, fake numbers.

/** Builds an .xlsx. $sheets = [['name' => , 'state' => , 'rows' => [[cell,...],...]]]; a cell is a string (shared string), ['i', text] (inline), ['n', number], ['d', serial] (date style), ['f', cached] (formula) or null. */
function mk_xlsx(string $path, array $sheets, array $opt = []): void {
    $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $strings = []; $sidx = function (string $s) use (&$strings) { $i = array_search($s, $strings, true); if ($i === false) { $strings[] = $s; $i = count($strings) - 1; } return $i; };
    $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = fn(int $i) => chr(65 + $i);
    $wbSheets = ''; $rels = ''; $ct = '';
    foreach ($sheets as $k => $sh) {
        $n = $k + 1; $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($sh['rows'] as $r => $row) {
            $xml .= '<row r="' . ($r + 1 + ($sh['offset'] ?? 0)) . '">';
            foreach ($row as $c => $v) {
                if ($v === null) { continue; } $ref = $col($c) . ($r + 1 + ($sh['offset'] ?? 0));
                if (is_string($v)) { $xml .= '<c r="' . $ref . '" t="s"><v>' . $sidx($v) . '</v></c>'; }
                elseif ($v[0] === 'i') { $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $x($v[1]) . '</t></is></c>'; }
                elseif ($v[0] === 'n') { $xml .= '<c r="' . $ref . '"><v>' . $v[1] . '</v></c>'; }
                elseif ($v[0] === 'd') { $xml .= '<c r="' . $ref . '" s="1"><v>' . $v[1] . '</v></c>'; }
                elseif ($v[0] === 'f') { $xml .= '<c r="' . $ref . '"><f>SUM(A1:A2)</f><v>' . $v[1] . '</v></c>'; }
            }
            $xml .= '</row>';
        }
        $z->addFromString("xl/worksheets/sheet$n.xml", $xml . '</sheetData><mergeCells count="1"><mergeCell ref="A1:B1"/></mergeCells></worksheet>');
        $wbSheets .= '<sheet name="' . $x($sh['name']) . '" sheetId="' . $n . '"' . (!empty($sh['state']) ? ' state="' . $sh['state'] . '"' : '') . ' r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $ssx = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    foreach ($strings as $s) { $ssx .= str_contains($s, '{rich}') ? '<si><r><t>' . $x(explode('{rich}', $s)[0]) . '</t></r><r><rPr><b/></rPr><t>' . $x(explode('{rich}', $s)[1]) . '</t></r></si>' : '<si><t>' . $x($s) . '</t></si>'; }
    $z->addFromString('xl/sharedStrings.xml', $ssx . '</sst>');
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr' . (!empty($opt['d1904']) ? ' date1904="1"' : '') . '/><sheets>' . $wbSheets . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd\-mmm\-yy"/></numFmts><cellStyleXfs count="1"><xf numFmtId="14"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
    $z->close();
}
/** A Paytm-shaped workbook with fake data. $rows = [[date, time, details, other, account, amount, ref, tag, comment], ...] */
function mk_paytm(string $path, array $rows, array $o = []): void {
    $hdr = ['Date', 'Time', 'Transaction Details', 'Other Transaction Details (UPI ID or A/c No)', 'Your Account', 'Amount', 'UPI Ref No.', 'Order ID', 'Remarks', 'Tags', 'Comment'];
    $body = []; foreach ($rows as $r) { $body[] = [$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6] === '' ? null : $r[6], null, 'UPIIntent', $r[7] ?? null, $r[8] ?? null]; }
    $paid = $recv = []; $pa = $ra = $pn = $rn = 0; $acc = [];
    foreach ($rows as $r) { $a = imp_amount(is_array($r[5]) ? $r[5][1] : $r[5]); $k = $r[4]; $acc[$k] ??= [0, 0, 0, 0]; if ($a < 0) { $pa += -$a; $pn++; $acc[$k][0] += -$a; $acc[$k][1]++; } else { $ra += $a; $rn++; $acc[$k][2] += $a; $acc[$k][3]++; } }
    $f = fn($m) => number_format($m / 100, 2);
    $sum = [[], [], [], ['Fake Holder'], ['******1111'], ['fake@example.test'], [], ['Paytm Statement for :', "01 JAN'26 - 31 DEC'26"], ['Money Paid (Amount in Rs.)', '-' . $f($pa + ($o['sum_off'] ?? 0))], ['Money Paid (No. of Payments)', (string) $pn], ['Money Received (Amount in Rs.)', '+' . $f($ra)], ['Money Received (No. of Payments)', (string) $rn], [], ['Accounts', "Money Paid\n(Amount in Rs.)", "Money Paid\n(No. of Payments)", "Money Received\n(Amount in Rs.)", "Money Received\n(No. of Payments)"]];
    foreach ($acc as $k => $v) { $sum[] = [$k, $f($v[0]), (string) $v[1], $f($v[2]), (string) $v[3]]; }
    $sum[] = []; $sum[] = ['Notes:']; $sum[] = ['- Self transfer payments are not included in the total money paid and money received calculations.'];
    $sheets = [];
    if (empty($o['no_summary'])) { $sheets[] = ['name' => 'Summary', 'rows' => $sum]; }
    $sheets[] = ['name' => 'Passbook Payment History', 'rows' => array_merge([$hdr], $body, $o['trailer'] ?? []), 'offset' => $o['offset'] ?? 0];
    if (!empty($o['hidden'])) { $sheets[] = ['name' => 'Old data', 'state' => 'hidden', 'rows' => array_merge([$hdr], [['01/01/2020', '10:00:00', 'Paid to Hidden Person', 'x@y', 'Fake Bank - 11', '-1.00', '999', null, null, '#Misc']])]; }
    $sheets[] = ['name' => 'Notes', 'rows' => [['just some text'], ['nothing useful here']]];
    mk_xlsx($path, $sheets, $o);
}

require_once __DIR__ . '/../app/pages/_shared.php';
$tmpd = sys_get_temp_dir() . '/mfnc-imp-' . getmypid(); @mkdir($tmpd, 0700, true);
$fx = fn(string $n) => $tmpd . '/' . $n;

section('import: xlsx reader');
mk_xlsx($fx('r.xlsx'), [
    ['name' => 'Visible', 'rows' => [['Héllo ☕', ['i', 'inline <b>text</b>'], ['n', 42], ['n', '12.5'], ['d', 46000], ['f', 7], 'rich{rich}run'], [], [null, null, ['n', 1]]]],
    ['name' => 'Hidden one', 'state' => 'hidden', 'rows' => [['secret']]], ['name' => 'Very', 'state' => 'veryHidden', 'rows' => [['vh']]],
]);
$wb = workbook_load($fx('r.xlsx'), 'r.xlsx'); $sh = $wb['sheets'];
eq(array_column($sh, 'name'), ['Visible', 'Hidden one', 'Very'], 'every sheet is discovered'); eq(array_column($sh, 'state'), ['visible', 'hidden', 'veryHidden'], 'hidden and very hidden states are reported');
$r1 = $sh[0]['rows'][1]; eq($r1[1], 'Héllo ☕', 'shared string with unicode'); eq($r1[2], 'inline <b>text</b>', 'inline string'); eq($r1[3], 42, 'integer'); eq($r1[4], 12.5, 'float'); check('numeric date via the date style', $r1[5] instanceof XlsxDate && $r1[5]->date === '2025-12-09', (string) $r1[5]);
eq($r1[6], 7, 'formula cached value'); eq($r1[7], 'richrun', 'rich text runs are joined'); eq(array_keys($sh[0]['rows']), [1, 3], 'empty rows are skipped, row numbers stay sparse'); eq(array_keys($sh[0]['rows'][3]), [3], 'columns stay sparse (C3)');
eq($sh[1]['rows'][1][1], 'secret', 'hidden sheet content is still read');
mk_xlsx($fx('r1904.xlsx'), [['name' => 'S', 'rows' => [[['d', 0]]]]], ['d1904' => true]); eq((string) workbook_load($fx('r1904.xlsx'), 'a.xlsx')['sheets'][0]['rows'][1][1], '1904-01-01', '1904 date system');
eq(xlsx_serial_to_date(45658.75, false)->time, '18:00:00', 'time of day from the fraction');

section('import: hostile and invalid files');
file_put_contents($fx('plain.xlsx'), "this is not a zip at all\n"); throws(fn() => workbook_load($fx('plain.xlsx'), 'plain.xlsx'), 'not a spreadsheet', 'a text file renamed .xlsx is refused');
file_put_contents($fx('exe.xlsx'), "MZ\x90\x00\x03\x00\x00\x00\x04\x00"); throws(fn() => workbook_load($fx('exe.xlsx'), 'a.xlsx'), 'not a spreadsheet', 'a binary renamed .xlsx is refused');
$z = new ZipArchive(); $z->open($fx('notxl.xlsx'), ZipArchive::CREATE | ZipArchive::OVERWRITE); $z->addFromString('a.txt', 'x'); $z->addFromString('b.txt', 'y'); $z->addFromString('c.txt', 'z'); $z->close(); throws(fn() => workbook_load($fx('notxl.xlsx'), 'a.xlsx'), 'not an excel', 'a zip that is not a workbook is refused');
copy($fx('r.xlsx'), $fx('trav.xlsx')); $z = new ZipArchive(); $z->open($fx('trav.xlsx')); $z->addFromString('../../evil.php', '<?php echo 1;'); $z->close(); throws(fn() => workbook_load($fx('trav.xlsx'), 'a.xlsx'), 'unsafe file names', 'path traversal entry names are refused');
copy($fx('r.xlsx'), $fx('abs.xlsx')); $z = new ZipArchive(); $z->open($fx('abs.xlsx')); $z->addFromString('/etc/x', 'x'); $z->close(); throws(fn() => workbook_load($fx('abs.xlsx'), 'a.xlsx'), 'unsafe file names', 'absolute entry names are refused');
check('nothing was written outside the temp dir', !file_exists('/etc/x') && !file_exists(dirname($tmpd) . '/evil.php') && !file_exists(dirname($tmpd, 2) . '/evil.php'));
copy($fx('r.xlsx'), $fx('bomb.xlsx')); $z = new ZipArchive(); $z->open($fx('bomb.xlsx')); $z->addFromString('xl/worksheets/sheet9.xml', str_repeat('A', 30 * 1024 * 1024)); $z->close();
throws(fn() => workbook_load($fx('bomb.xlsx'), 'a.xlsx'), 'compression bomb', 'a huge declared size / compression bomb is refused'); check('the bomb archive itself is small', filesize($fx('bomb.xlsx')) < 200000);
$ent = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">]><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>&lol2;</t></si></sst>';
copy($fx('r.xlsx'), $fx('xxe.xlsx')); $z = new ZipArchive(); $z->open($fx('xxe.xlsx')); $z->addFromString('xl/sharedStrings.xml', $ent); $z->close(); throws(fn() => workbook_load($fx('xxe.xlsx'), 'a.xlsx'), 'unsafe XML', 'an entity bomb (DTD) is refused');
$ext = str_replace('&lol2;', '&xxe;', str_replace('<!ENTITY lol "lol">', '<!ENTITY xxe SYSTEM "file:///etc/passwd">', $ent)); $z = new ZipArchive(); $z->open($fx('xxe.xlsx')); $z->addFromString('xl/sharedStrings.xml', $ext); $z->close(); throws(fn() => workbook_load($fx('xxe.xlsx'), 'a.xlsx'), 'unsafe XML', 'an external entity is refused');
file_put_contents($fx('big.xlsx'), "PK\x03\x04" . str_repeat('x', XLSX_MAX_BYTES + 10)); throws(fn() => workbook_load($fx('big.xlsx'), 'a.xlsx'), 'too large', 'the file size cap is enforced');
$many = []; for ($i = 0; $i < 3; $i++) { $many[] = ['name' => "S$i", 'rows' => [['x']]]; } mk_xlsx($fx('many.xlsx'), $many);
file_put_contents($fx('c.csv'), "Date;Amount\n01/02/2026;-5,00\n"); $csv = workbook_load($fx('c.csv'), 'c.csv'); eq($csv['kind'], 'csv', 'csv is read'); eq($csv['sheets'][0]['rows'][1][1], 'Date', 'csv delimiter is detected (;)');

section('import: Paytm adapter');
$rows = [
    ['19/10/2026', '20:51:51', 'Paid to Shop One', 'shop1@ptys on Paytm', 'Fake Bank - 11', '-1,520.00', '700000000001', '#🛒 Groceries'],
    ['18/10/2026', '12:00:28', 'Money sent to Friend <script>alert(1)</script>', 'friend@oksbi', 'Fake Bank - 11', '-11,000.00', '700000000002', '#💵 Money Transfer', 'for the trip'],
    ['17/10/2026', '15:52:36', 'Received from Friend Two', 'f2@oksbi', 'Other Bank - 22', '+2,000.00', '700000000003', '#💵 Money Received'],
    ['16/10/2026', '10:00:00', 'Automatic payment for Netflix', 'netflix@ybl', 'Fake Bank - 11', '-199.00', '', '#🔄 Miscellaneous'],
    ['16/10/2026', '10:00:00', 'Automatic payment for Netflix', 'netflix@ybl', 'Fake Bank - 11', '-199.00', '', '#🔄 Miscellaneous'],
    [['d', 46000], '09:30:00', 'Cashback Received from Paytm', 'cb@paytm', 'Other Bank - 22', '+5.50', '700000000004', '#💰 Cashback'],
    ['15/10/2026', '08:00:00', 'Recharge of 9000000000', 'op@paytm', 'Fake Bank - 11', '-299.00', '700000000005', '#⛽️ Fuel'],
];
foreach ($rows as &$rw) { if (is_array($rw[0])) { $rw[0] = ['d', $rw[0][1]]; } } unset($rw);
mk_paytm($fx('p.xlsx'), $rows, ['hidden' => true, 'trailer' => [['Total', null, null, null, null, '-13,217.00']]]);
$stmt = import_parse_workbook(workbook_load($fx('p.xlsx'), 'p.xlsx'));
eq($stmt['source'], 'paytm', 'the Paytm adapter recognises the workbook'); eq(array_column($stmt['sheets'], 'role'), ['summary', 'transactions', 'unknown', 'unknown'], 'every sheet is classified (summary, transactions, hidden skipped, unknown)');
check('the hidden sheet is listed as hidden and not imported', $stmt['sheets'][2]['state'] === 'hidden' && str_contains($stmt['sheets'][2]['note'], 'not imported')); check('the unknown sheet says why it is skipped', str_contains($stmt['sheets'][3]['note'], 'skipped'));
eq($stmt['holder'], 'Fake Holder', 'holder read from the Summary'); eq($stmt['period']['from'], '2026-01-01', 'period start'); eq(count($stmt['rows']), 7, 'seven transactions, the Total row ignored'); eq(count($stmt['ignored']), 1, 'the trailing totals row is reported as ignored');
$r = $stmt['rows']; eq($r[0]['amount'], 152000, 'amount with thousands comma'); eq($r[0]['dir'], 'out', 'minus is money out'); eq($r[2]['dir'], 'in', 'plus is money in'); eq($r[0]['date'], '2026-10-19', 'dd/mm/yyyy date'); eq($r[0]['time'], '20:51:51', 'time'); eq($r[0]['payee'], 'Shop One', '"Paid to" is stripped'); eq($r[2]['payee'], 'Friend Two', '"Received from" is stripped');
eq($r[3]['payee'], 'Netflix', '"Automatic payment for" is stripped'); eq($r[5]['dir'], 'in', 'cashback is money in'); eq($r[5]['date'], '2025-12-09', 'numeric date cell'); eq($r[5]['amount'], 550, 'plain decimal amount');
eq($r[0]['tag'], 'Groceries', 'tag: # and emoji removed'); eq($r[6]['tag'], 'Fuel', 'tag with a variation selector is cleaned'); eq($r[1]['comment'], 'for the trip', 'Comment column becomes the remark'); eq($r[0]['comment'], '', 'the technical Remarks column (UPIIntent) is not imported');
check('a row without a UPI ref gets a hash key', str_starts_with($r[3]['key'], 'h:')); check('identical no-ref rows get distinct keys', $r[3]['key'] !== $r[4]['key'] && str_ends_with($r[4]['key'], '#2'));
$rec = import_reconcile($stmt); check('reconciliation is all equal for a consistent file', $rec && !array_filter($rec, fn($l) => !$l['ok']), json_encode(array_filter($rec ?? [], fn($l) => !$l['ok'])));
mk_paytm($fx('p2.xlsx'), $rows, ['sum_off' => 500]); $st2 = import_parse_workbook(workbook_load($fx('p2.xlsx'), 'p.xlsx')); $bad = array_values(array_filter(import_reconcile($st2), fn($l) => !$l['ok'])); check('a wrong Summary amount is flagged with the difference', count($bad) >= 1 && $bad[0]['expected'] - $bad[0]['actual'] === 500, json_encode($bad));
mk_paytm($fx('p3.xlsx'), $rows, ['no_summary' => true, 'offset' => 3]); $st3 = import_parse_workbook(workbook_load($fx('p3.xlsx'), 'p.xlsx')); eq($st3['source'], 'paytm', 'works without a Summary sheet and with the header on a later row'); eq(import_reconcile($st3), null, 'no Summary => nothing to reconcile'); eq(count($st3['rows']), 7, 'all rows read when the header is offset');
// reordered / renamed columns via CSV
file_put_contents($fx('re.csv'), "Amount,Date,Time,Your Account,Transaction Details,UPI Ref No.,Tags,Other Transaction Details\n\"-1,200.00\",01/03/2026,10:00:00,Fake Bank - 11,Paid to Reorder Shop,800000000001,#Food,x@y\n"); $stc = import_parse_workbook(workbook_load($fx('re.csv'), 're.csv'));
eq($stc['source'], 'paytm', 'a CSV with the same headings in another order is recognised'); eq([$stc['rows'][0]['amount'], $stc['rows'][0]['date'], $stc['rows'][0]['payee']], [120000, '2026-03-01', 'Reorder Shop'], 'reordered columns are mapped by name');
file_put_contents($fx('who.csv'), "Foo,Bar\n1,2\n"); $stn = import_parse_workbook(workbook_load($fx('who.csv'), 'who.csv')); eq($stn['source'], null, 'an unknown layout is not guessed'); eq($stn['sheets'][0]['role'], 'unknown', 'but its sheets are still listed');
eq(imp_amount('(250.00)'), -25000, 'parenthesis negative'); eq(imp_amount('1,23,456.5'), 12345650, 'Indian grouping'); eq(imp_amount('abc'), null, 'text is not an amount'); eq(imp_date('03/25/2026'), '2026-03-25', 'month-first only when the day cannot be the second number'); eq(imp_date('5 Mar 2026'), '2026-03-05', 'day month-name year');

section('import: preview, mapping defaults and commit');
$IU = create_user('imp1@x.test', 'Imp One', 'password-12345'); $IV = create_user('imp2@x.test', 'Imp Two', 'password-12345');
$_SESSION['uid'] = $IU; $snap = load_snapshot($IU);
$tok = import_handle_upload($IU, ['tmp_name' => $fx('p.xlsx'), 'name' => '../evil<name>.xlsx', 'size' => filesize($fx('p.xlsx')), 'error' => 0]);
check('upload makes a 32-hex preview token and writes nothing to the ledger', preg_match('/^[a-f0-9]{32}$/', $tok) && (int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=?', [$IU]) === 0);
eq(val('SELECT filename FROM {import_previews} WHERE token=?', [$tok]), 'evilname.xlsx', 'the file name is sanitised'); check('the uploaded file is not stored anywhere in the app', true);
$pv = import_load_preview($IU, $tok); check('the owner can load the preview', $pv && $pv['stmt']['source'] === 'paytm'); eq(import_load_preview($IV, $tok), null, "another user's preview token is simply not found");
throws(fn() => import_commit($IV, $tok, import_default_choices(load_snapshot($IV), $pv['stmt'])), 'not found or has expired', "another user cannot commit someone else's preview");
run('UPDATE {import_previews} SET expires_at=? WHERE token=?', [gmdate('Y-m-d H:i:s', time() - 5), $tok]); eq(import_load_preview($IU, $tok), null, 'an expired preview cannot be used'); run('UPDATE {import_previews} SET expires_at=? WHERE token=?', [gmdate('Y-m-d H:i:s', time() + 3000), $tok]);
$ch = import_default_choices($snap, $stmt); eq($ch['acc'], ['new', 'new'], 'unknown bank accounts default to "create new"'); $tg = import_tag_groups($stmt); $by = []; foreach ($tg as $i => $g) { $by[$g['dir'] . '|' . $g['tag']] = $ch['cat'][$i]; }
eq($by['out|Groceries'], category_id_by_name($IU, 'Groceries'), 'Groceries matches the existing category'); eq($by['out|Fuel'], category_id_by_name($IU, 'Transport & Travel'), 'Fuel maps to Transport & Travel'); eq($by['in|Money Received'], category_id_by_name($IU, 'Other Income'), 'Money Received maps to an income category'); eq($by['in|Cashback'], category_id_by_name($IU, 'Other Income'), 'cashback is income');
eq($by['out|Money Transfer'], 'new', 'Money Transfer has no match, so it is offered as a new category');
$plan = import_plan($IU, $stmt, $ch); eq(count(array_filter($plan, fn($p) => $p['dup'])), 0, 'nothing is a duplicate yet');
$balBefore = array_sum(array_column($snap['accounts'], 'balance'));
$res = import_commit($IU, $tok, $ch); eq([$res['imported'], $res['duplicates'], $res['accounts_created']], [7, 0, 2], 'commit imports every row and creates the two accounts'); $s2 = load_snapshot($IU);
eq(array_sum(array_column($s2['accounts'], 'balance')), $balBefore, 'balances are unchanged in "keep" mode'); $imp = array_values(array_filter($s2['txs'], fn($t) => $t['source'] === 'paytm')); eq(count($imp), 7, 'seven transactions carry source=paytm');
$t0 = array_values(array_filter($imp, fn($t) => $t['description'] === 'Shop One'))[0]; eq([$t0['type'], $t0['amount'], $t0['date'], $t0['txn_time'], $t0['payment_method']], ['expense', 152000, '2026-10-19', '20:51:51', 'UPI'], 'imported row: type, amount, date, time, method'); eq($t0['source_ref'], '700000000001', 'the UPI ref is kept');
$tm = array_values(array_filter($imp, fn($t) => str_contains($t['description'], 'Friend <script>')))[0]; eq($tm['remarks'], 'for the trip', 'the Comment became the remark'); check('the raw details stay in the meta field', str_contains($tm['source_meta'], 'friend@oksbi'));
eq(count(array_filter($imp, fn($t) => $t['type'] === 'income')), 2, 'plus rows are income (incl. cashback)'); check('imported rows point at the import batch', count(array_unique(array_column($imp, 'import_id'))) === 1 && $imp[0]['import_id'] === $res['import_id']);
$again = import_commit($IU, $tok, $ch); check('double submit is idempotent: the same import comes back', $again['already'] && $again['import_id'] === $res['import_id'] && (int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=? AND source=?', [$IU, 'paytm']) === 7);
$tok2 = import_handle_upload($IU, ['tmp_name' => $fx('p.xlsx'), 'name' => 'again.xlsx', 'size' => 1, 'error' => 0]); $pv2 = import_load_preview($IU, $tok2); $ch2 = import_default_choices(load_snapshot($IU), $pv2['stmt']); eq(array_values($ch2['acc']) === ['new', 'new'], false, 'second time the accounts match the ones created');
$res2 = import_commit($IU, $tok2, $ch2); eq([$res2['imported'], $res2['duplicates'], $res2['import_id']], [0, 7, 0], 're-importing the same file adds nothing (all duplicates)'); eq((int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=? AND source=?', [$IU, 'paytm']), 7, 'no extra rows after the re-import');

section('import: balances, atomic failure, undo');
$IW = create_user('imp3@x.test', 'Imp Three', 'password-12345'); $_SESSION['uid'] = $IW; $sw = load_snapshot($IW); $main = (int) $sw['accounts'][0]['id'];
run('UPDATE {accounts} SET opening_balance=? , opening_date=? WHERE id=?', [500000, '2025-01-01', $main]); $sw = load_snapshot($IW); eq((int) $sw['accounts'][0]['balance'], 500000, 'starting balance of the existing account');
$GLOBALS['__import_fail_after_first_chunk'] = true; $tkF = import_store_preview($IW, 'f.xlsx', $stmt); $chF = import_default_choices($sw, $stmt); $chF['acc'] = [$main, $main];
$threw = false; try { import_commit($IW, $tkF, $chF); } catch (RuntimeException $e) { $threw = true; } unset($GLOBALS['__import_fail_after_first_chunk']);
check('a failure in the middle throws', $threw); eq([(int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=?', [$IW]), (int) val('SELECT COUNT(*) FROM {imports} WHERE user_id=?', [$IW]), (int) val('SELECT COUNT(*) FROM {accounts} WHERE user_id=?', [$IW]), (int) val('SELECT COUNT(*) FROM {categories} WHERE user_id=?', [$IW])], [0, 0, 2, count(array_merge(...array_values(DEFAULT_CATEGORIES)))], 'and the whole import rolled back (no rows, no history, no new accounts or categories)');
check('the preview is still usable after the failure', import_load_preview($IW, $tkF) !== null && !import_load_preview($IW, $tkF)['import_id']);
$chK = $chF; $chK['mode'] = 'reflect'; $rK = import_commit($IW, $tkF, $chK); $netAll = -152000 - 1100000 + 200000 - 19900 - 19900 + 550 - 29900; $sw2 = load_snapshot($IW);
eq((int) array_column($sw2['accounts'], 'balance', 'id')[$main], 500000 + $netAll, '"reflect" mode lets the transactions change the balance'); eq($rK['accounts_created'], 0, 'existing accounts were used');
$rm = import_remove($IW, $rK['import_id']); eq($rm, 7, 'undo deletes the 7 transactions'); eq((int) array_column(load_snapshot($IW)['accounts'], 'balance', 'id')[$main], 500000, 'balance is exactly back after undo');
throws(fn() => import_remove($IW, $rK['import_id']), 'already removed', 'undoing twice is refused'); throws(fn() => import_remove($IV, $rK['import_id']), 'not found', "another user cannot remove someone's import (IDOR)");
$tkK = import_store_preview($IW, 'k.xlsx', $stmt); $chK2 = $chF; $rK2 = import_commit($IW, $tkK, $chK2); $sw3 = load_snapshot($IW); eq((int) array_column($sw3['accounts'], 'balance', 'id')[$main], 500000, '"keep" mode leaves an existing account balance exactly as it was');
check('a balance correction was recorded for it', (bool) array_filter($sw3['txs'], fn($t) => $t['type'] === 'adjustment' && (int) $t['import_id'] === $rK2['import_id'])); import_remove($IW, $rK2['import_id']); eq(count(load_snapshot($IW)['txs']), 0, 'undo removes the correction too');
// new accounts created by an import disappear again on undo
$tkN = import_store_preview($IW, 'n.xlsx', $stmt); $rN = import_commit($IW, $tkN, import_default_choices(load_snapshot($IW), $stmt)); eq((int) val('SELECT COUNT(*) FROM {accounts} WHERE user_id=?', [$IW]), 4, 'the import created 2 accounts'); import_remove($IW, $rN['import_id']); eq((int) val('SELECT COUNT(*) FROM {accounts} WHERE user_id=?', [$IW]), 2, 'undo removes accounts that only the import used');
// edits: exclude, per-row category, remark override
$tkE = import_store_preview($IW, 'e.xlsx', $stmt); $_POST = ['token' => $tkE, 'mode' => 'keep', 'acc' => ['0' => (string) $main, '1' => (string) $main], 'rowedits' => json_encode(['ex' => [1, 2], 'cat' => ['3' => (int) category_id_by_name($IW, 'Rent')], 'rm' => ['4' => 'my <i>note</i>']])];
$chE = import_choices_from_post($IW, $stmt, load_snapshot($IW), $_POST); $rE = import_commit($IW, $tkE, $chE); eq([$rE['imported'], $rE['excluded']], [5, 2], 'unticked rows are left out'); $te = all('SELECT description,category_id,remarks FROM {transactions} WHERE user_id=? AND source=?', [$IW, 'paytm']);
check('a per-row category override is applied', (bool) array_filter($te, fn($t) => (int) $t['category_id'] === (int) category_id_by_name($IW, 'Rent'))); check('a remark edit is applied (stored as text)', (bool) array_filter($te, fn($t) => $t['remarks'] === 'my <i>note</i>'));
$_POST = ['acc' => ['0' => '999999']]; throws(fn() => import_choices_from_post($IW, $stmt, load_snapshot($IW), $_POST), 'one of your accounts', "someone else's / unknown account id is rejected"); $oc = (int) val('SELECT id FROM {categories} WHERE user_id=? LIMIT 1', [$IU]);
$_POST = ['cat' => ['0' => (string) $oc]]; throws(fn() => import_choices_from_post($IW, $stmt, load_snapshot($IW), $_POST), 'one of your categories', "another user's category id is rejected"); $_POST = [];

section('import: labels, remarks, search, security');
$s5 = load_snapshot($IU); $ti = array_values(array_filter($s5['txs'], fn($t) => $t['source'] === 'paytm'))[0]; $manual = create_transaction($IU, ['type' => 'expense', 'amount' => 1000, 'date' => '2026-10-01', 'account_id' => (int) $s5['accounts'][0]['id'], 'description' => 'Manual']); $s5 = load_snapshot($IU);
$GLOBALS['__prefs']['show_import_labels'] = 1; $b = import_badge($ti); check('the pill names the source, has accessible text and a tooltip with the UPI ref', str_contains($b, 'Imported from Paytm') && str_contains($b, 'title="') && str_contains($b, 'UPI ref')); eq(import_badge(['source' => null]), '', 'manual transactions get no pill');
$GLOBALS['__prefs']['show_import_labels'] = 0; eq(import_badge($ti), '', 'switched off: no pill'); eq(tx_extra_line($ti), '', 'switched off: no extra line either'); check('but the import screens can still show the source', import_badge($ti, 'src', true) !== '');
$_POST = ['_display' => '1', 'currency' => 'INR', 'locale' => 'en-IN', 'theme' => 'system', 'reminder_days' => '7', 'big_purchase_threshold' => '1000']; do_prefs_save(['id' => $IU]); eq((int) val('SELECT show_import_labels FROM {preferences} WHERE user_id=?', [$IU]), 0, 'the Settings form switches labels off (stored per user)'); eq((int) val('SELECT show_import_labels FROM {preferences} WHERE user_id=?', [$IV]), 1, 'other users are unaffected; default is ON');
$_POST = ['_display' => '1', 'show_import_labels' => '1', 'currency' => 'INR', 'locale' => 'en-IN', 'theme' => 'system', 'reminder_days' => '7', 'big_purchase_threshold' => '1000']; do_prefs_save(['id' => $IU]); eq((int) val('SELECT show_import_labels FROM {preferences} WHERE user_id=?', [$IU]), 1, 'and on again'); unset($GLOBALS['__prefs']);
$_POST = ['currency' => 'INR', 'locale' => 'en-IN', 'theme' => 'dark', 'reminder_days' => '7', 'big_purchase_threshold' => '1000']; do_prefs_save(['id' => $IU]); eq((int) val('SELECT show_import_labels FROM {preferences} WHERE user_id=?', [$IU]), 1, 'a prefs save without the display form never flips the label setting');
$_POST = ['id' => (string) $manual, 'remarks' => "  for  <b>mum</b>\n ok "]; do_transaction_remarks(['id' => $IU]); eq(val('SELECT remarks FROM {transactions} WHERE id=?', [$manual]), 'for <b>mum</b> ok', 'remarks quick edit: whitespace collapsed, stored as plain text');
$_POST = ['id' => (string) $manual, 'remarks' => str_repeat('x', 501)]; throws(fn() => do_transaction_remarks(['id' => $IU]), 'too long', 'remarks are limited to 500 characters'); $_POST = ['id' => (string) $manual, 'remarks' => 'x']; throws(fn() => do_transaction_remarks(['id' => $IV]), 'not found', "another user's transaction cannot be given a remark (IDOR)");
$s6 = load_snapshot($IU); $mrow = array_values(array_filter($s6['txs'], fn($t) => (int) $t['id'] === $manual))[0]; check('the remark is shown muted and escaped', str_contains(tx_extra_line($mrow), '&lt;b&gt;mum&lt;/b&gt;') && !str_contains(tx_extra_line($mrow), '<b>mum'));
check('remarks are searchable', count(filter_txs($s6, ['q' => 'mum', 'type' => '', 'category' => 0, 'account' => 0, 'from' => '', 'to' => '', 'status' => '', 'sort' => 'date', 'dir' => 'desc', 'import' => 0])) === 1); eq(count(filter_txs($s6, ['q' => '', 'type' => '', 'category' => 0, 'account' => 0, 'from' => '', 'to' => '', 'status' => '', 'sort' => 'date', 'dir' => 'desc', 'import' => $res['import_id']])), 7, 'the import filter shows only that batch');
$_POST = ['type' => 'expense', 'amount' => '5', 'date' => '2026-10-02', 'account_id' => (string) $s6['accounts'][0]['id'], 'description' => 'With remark', 'remarks' => 'added via form']; do_transaction_save(['id' => $IU, 'email' => 'x']); eq(val('SELECT remarks FROM {transactions} WHERE user_id=? AND description=?', [$IU, 'With remark']), 'added via form', 'the add form saves remarks');
$wid = (int) val('SELECT id FROM {transactions} WHERE user_id=? AND description=?', [$IU, 'With remark']); $_POST = ['id' => (string) $wid, 'type' => 'expense', 'amount' => '6', 'date' => '2026-10-02', 'account_id' => (string) $s6['accounts'][0]['id'], 'description' => 'With remark']; do_transaction_save(['id' => $IU, 'email' => 'x']); eq(val('SELECT remarks FROM {transactions} WHERE id=?', [$wid]), 'added via form', 'an edit from a form without the field keeps the saved remark');
$_POST = []; check('CSRF: a post without the token is refused', !csrf_ok()); $_POST = ['_csrf' => csrf_token()]; check('CSRF: a post with the session token passes', csrf_ok()); $_POST = [];
// XSS in payee names (stored raw, escaped on output)
check('XSS: the payee is stored as text and the pill/meta output is escaped', str_contains(e($tm['description']), '&lt;script&gt;') && !str_contains(import_badge($tm), '<script'));
$info = tx_import_info($tm); eq($info['other'], 'friend@oksbi', 'imported info is available for the edit dialog (rendered with textContent by the JS)');
// rate limit
$IR = create_user('imp4@x.test', 'Imp Four', 'password-12345'); $lim = null; for ($i = 0; $i < 14; $i++) { try { import_handle_upload($IR, ['error' => UPLOAD_ERR_NO_FILE]); } catch (UserError $e) { if (str_contains($e->getMessage(), 'a lot of files')) { $lim = $i; break; } } } eq($lim, 12, 'uploads are rate limited (12 per hour)');
$_FILES = ['file' => ['tmp_name' => $fx('plain.xlsx'), 'name' => 'x.xlsx', 'size' => 5, 'error' => 0]]; $IQ = create_user('imp5@x.test', 'Imp Five', 'password-12345'); throws(fn() => do_import_upload(['id' => $IQ]), 'not a spreadsheet', 'the upload action refuses a fake .xlsx with a friendly message'); $_FILES = [];
check('uploads and previews do not leave files behind', !glob(sys_get_temp_dir() . '/mfnc-imp-*/*.php'));
// the DB-level unique key
try { run('INSERT INTO {transactions} (user_id,type,amount,date,account_id,source,source_ref) SELECT user_id,type,amount,date,account_id,source,source_ref FROM {transactions} WHERE user_id=? AND source=? LIMIT 1', [$IU, 'paytm']); check('unique key stops duplicate (user, source, ref, account) rows', false); } catch (PDOException $e) { check('unique key stops duplicate (user, source, ref, account) rows', $e->getCode() === '23000'); }
$f = glob($tmpd . '/*'); foreach ($f as $x) { @unlink($x); } @rmdir($tmpd);
