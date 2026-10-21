<?php
declare(strict_types=1);
/**
 * Statement import: adapters. A small registry so more sources (PhonePe, Google Pay, bank CSVs) can be added later:
 *
 *   import_register_adapter('mybank', ['label' => 'My Bank', 'detect' => fn(array $wb): float, 'parse' => fn(array $wb): array, ...]);
 *
 * detect() returns a 0..1 confidence; the best one >= 0.5 wins. parse() returns a "statement":
 *   source, label, holder, period{from,to,text}, summary (null | {paid{amount,count}, received{amount,count}, accounts{name => {paid_amount, paid_count, recv_amount, recv_count}}}),
 *   sheets [{name,state,role: summary|transactions|unknown,note,rows}], rows [row...], ignored (non-transaction rows skipped), warnings [].
 * row = n, date Y-m-d, time H:i:s|null, amount (positive minor units), dir 'in'|'out', details, payee, kind, other, account, ref, order_id, tag, tag_raw, comment, key (dedupe key), hint (self-transfer suggestion).
 * Money is integer paise throughout; nothing here touches floats except reading a numeric cell.
 */

const IMPORT_ADAPTERS_MAX_ROWS = 5000;

function import_register_adapter(string $key, array $a): void { $GLOBALS['__import_adapters'][$key] = $a + ['key' => $key]; }
function import_adapters(): array {
    if (!isset($GLOBALS['__import_adapters'])) { $GLOBALS['__import_adapters'] = []; import_register_adapter('paytm', paytm_adapter()); }
    return $GLOBALS['__import_adapters'];
}
function import_adapter(?string $key): ?array { return $key ? (import_adapters()[$key] ?? null) : null; }
function import_source_label(?string $key): string { $a = import_adapter($key); return $a ? $a['label'] : ($key ? ucfirst($key) : ''); }

/** Picks the adapter and parses. Returns the statement; $statement['source'] is null when nothing recognised the file (sheets are still listed). */
function import_parse_workbook(array $wb): array {
    $best = null; $bs = 0.0;
    foreach (import_adapters() as $a) { $sc = (float) ($a['detect'])($wb); if ($sc > $bs) { $bs = $sc; $best = $a; } }
    if ($best && $bs >= 0.5) { $st = ($best['parse'])($wb); $st['score'] = $bs; return $st; }
    $sheets = [];
    foreach ($wb['sheets'] as $s) { $sheets[] = ['name' => $s['name'], 'state' => $s['state'], 'role' => 'unknown', 'note' => $s['kind'] !== 'worksheet' ? 'Not a normal worksheet' : (!$s['rows'] ? 'Empty sheet' : 'No known statement layout found'), 'rows' => count($s['rows'])]; }
    return ['source' => null, 'label' => '', 'holder' => null, 'period' => null, 'summary' => null, 'sheets' => $sheets, 'rows' => [], 'ignored' => [], 'warnings' => [], 'score' => $bs];
}

// ============================ generic cell helpers ============================
function imp_norm(string $s): string { return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s)) ?? ''; }
function imp_clean_text(string $s, int $max = 200): string {
    $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', ' ', $s) ?? $s;
    $s = trim((string) preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
}
/** "-11,000.00", "+2,000", "₹ 1,23,456.5", "(250.00)", "250 Dr" or a numeric cell => signed integer paise (null if it is not an amount). $sign is -1/1/0 (none given). */
function imp_amount($v, ?int &$sign = null): ?int {
    $sign = 0;
    if ($v === null || $v === '' || $v instanceof XlsxDate || is_bool($v)) { return null; }
    if (is_int($v) || is_float($v)) { $sign = $v < 0 ? -1 : ($v > 0 ? 1 : 0); return (int) round($v * 100); }
    $s = trim(str_replace(["\u{00A0}", '₹', 'Rs.', 'Rs', 'INR', ' '], '', (string) $v));
    if ($s === '') { return null; }
    $neg = false; $pos = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = $m[1]; }
    if (preg_match('/(dr|debit)$/i', $s)) { $neg = true; $s = (string) preg_replace('/(dr|debit)$/i', '', $s); }
    elseif (preg_match('/(cr|credit)$/i', $s)) { $pos = true; $s = (string) preg_replace('/(cr|credit)$/i', '', $s); }
    if ($s !== '' && ($s[0] === '-' || $s[0] === '−')) { $neg = true; $s = substr($s, $s[0] === '-' ? 1 : 3); } elseif ($s !== '' && $s[0] === '+') { $pos = true; $s = substr($s, 1); }
    $s = str_replace(',', '', $s);
    if (!preg_match('/^(\d{1,13})(\.(\d{1,2})\d*)?$/', $s, $m)) { return null; }
    $paise = (int) $m[1] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
    $sign = $neg ? -1 : ($pos || $paise > 0 ? 1 : 0);
    return $neg ? -$paise : $paise;
}
function imp_month_num(string $m): int { static $names = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12]; return $names[strtolower(substr($m, 0, 3))] ?? 0; }
function imp_year(int $y): int { return $y < 100 ? 2000 + $y : $y; }
/** A date cell or text => Y-m-d (day first, as in India, unless the second number can only be a day). */
function imp_date($v): ?string {
    if ($v instanceof XlsxDate) { return valid_date($v->date) ? $v->date : null; }
    if ($v === null || is_bool($v)) { return null; }
    $s = trim((string) $v); if ($s === '') { return null; }
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $s, $m)) { $d = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); return valid_date($d) ? $d : null; }
    if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})(?:[ T].*)?$/', $s, $m)) {
        [$a, $b] = [(int) $m[1], (int) $m[2]]; if ($a <= 12 && $b > 12) { [$a, $b] = [$b, $a]; }   // 03/25/2026 => 25 March
        $d = sprintf('%04d-%02d-%02d', imp_year((int) $m[3]), $b, $a); return valid_date($d) ? $d : null;
    }
    if (preg_match('/^(\d{1,2})[ -]([A-Za-z]{3,9})[ ,\'’-]*(\d{2,4})$/', $s, $m) && imp_month_num($m[2])) { $d = sprintf('%04d-%02d-%02d', imp_year((int) $m[3]), imp_month_num($m[2]), $m[1]); return valid_date($d) ? $d : null; }
    return null;
}
function imp_time($v, $dateCell = null): ?string {
    if ($v instanceof XlsxDate) { return $v->time; }
    if (is_string($v) && preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?$/', trim($v), $m)) {
        $h = (int) $m[1]; if (!empty($m[4])) { $h = $h % 12 + (stripos($m[4], 'p') === 0 ? 12 : 0); }
        return $h < 24 && (int) $m[2] < 60 ? sprintf('%02d:%02d:%02d', $h, $m[2], $m[3] ?? 0) : null;
    }
    if (($v === null || $v === '') && $dateCell instanceof XlsxDate) { return $dateCell->time; }
    return null;
}
/** First row (within the first 40) that looks like a header for the given column vocabulary => [rowNo, [field => colNo]]; null if fewer than $min fields match. */
function imp_find_header(array $rows, array $vocab, int $min): ?array {
    $n = 0;
    foreach ($rows as $rn => $cells) {
        if (++$n > 40) { break; }
        $map = [];
        foreach ($cells as $col => $v) {
            if (!is_string($v)) { continue; }
            $h = imp_norm($v); if ($h === '') { continue; }
            foreach ($vocab as $field => $alts) {
                if (isset($map[$field])) { continue; }
                foreach ($alts as $a) { if ($h === $a || (str_ends_with($a, '*') && str_starts_with($h, rtrim($a, '*')))) { $map[$field] = $col; break 2; } }
            }
        }
        if (count($map) >= $min && isset($map['date'], $map['amount'])) { return [$rn, $map]; }
    }
    return null;
}

// ============================ Paytm ============================
function paytm_vocab(): array {
    return [
        'date' => ['date', 'transactiondate', 'txndate'], 'time' => ['time', 'transactiontime'],
        'details' => ['transactiondetails', 'details', 'description', 'narration', 'particulars'],
        'other' => ['othertransactiondetails*', 'othertransactiondetail*', 'upiid', 'counterparty', 'upiidoracno'],
        'account' => ['youraccount', 'account', 'accountname'],
        'amount' => ['amount', 'amountinrs', 'amountrs', 'amountinr'],
        'ref' => ['upirefno', 'upirefnumber', 'upireference', 'upirefid', 'utr', 'refno', 'referenceno', 'upitransactionid'],
        'order' => ['orderid', 'orderno'], 'remarks' => ['remarks', 'remark'], 'tags' => ['tags', 'tag', 'category'], 'comment' => ['comment', 'comments', 'note', 'notes', 'yourcomment'],
    ];
}
function paytm_is_summary(array $sheet): bool {
    $hits = 0; $n = 0;
    foreach ($sheet['rows'] as $cells) {
        if (++$n > 40) { break; }
        $t = imp_norm(xlsx_text($cells[min(array_keys($cells))]));   // only the first cell of a row counts (a "Money Received" tag in a data row must not)
        if (str_contains($t, 'paytmstatementfor')) { $hits += 2; }
        if (str_starts_with($t, 'moneypaid') || str_starts_with($t, 'moneyreceived')) { $hits++; }
    }
    return $hits >= 2;
}
function paytm_adapter(): array {
    return [
        'label' => 'Paytm', 'title' => 'Paytm UPI statement', 'accept' => 'Paytm app → Balance & History → UPI Statement → Download (.xlsx)',
        'detect' => function (array $wb): float {
            $score = 0.0; $hasTx = false;
            foreach ($wb['sheets'] as $s) {
                if ($s['kind'] !== 'worksheet') { continue; }
                $hdr = imp_find_header($s['rows'], paytm_vocab(), 4);
                if (!$hdr && paytm_is_summary($s)) { $score += 0.55; }
                if ($hdr) { $hasTx = true; $score += 0.2; if (count($hdr[1]) >= 6 && isset($hdr[1]['other'], $hdr[1]['ref'])) { $score += 0.3; }   // the full Paytm column set is evidence enough without a Summary sheet
                if (preg_match('/passbook|payment ?history/i', $s['name'])) { $score += 0.15; } }
            }
            if ($hasTx) { $score += 0.1; }
            return min(1.0, $score) * ($hasTx ? 1.0 : 0.6);   // no transaction sheet => not importable
        },
        'parse' => 'paytm_parse',
    ];
}

function paytm_parse(array $wb): array {
    $st = ['source' => 'paytm', 'label' => 'Paytm', 'holder' => null, 'email' => null, 'phone' => null, 'period' => null, 'summary' => null, 'sheets' => [], 'rows' => [], 'ignored' => [], 'warnings' => []];
    $txSheets = [];
    foreach ($wb['sheets'] as $idx => $s) {
        $meta = ['name' => $s['name'], 'state' => $s['state'], 'role' => 'unknown', 'note' => '', 'rows' => count($s['rows'])];
        $h = $s['kind'] === 'worksheet' && $s['rows'] ? imp_find_header($s['rows'], paytm_vocab(), 4) : null;
        if ($s['kind'] !== 'worksheet') { $meta['note'] = 'Not a normal worksheet (chart or macro sheet), skipped'; }
        elseif (!$s['rows']) { $meta['note'] = 'Empty sheet, skipped'; }
        elseif (!$h && paytm_is_summary($s)) { $meta['role'] = 'summary'; $meta['note'] = $s['state'] === 'visible' ? 'Totals used to double-check the transactions' : 'Hidden sheet; totals still used to double-check'; paytm_parse_summary($s, $st); }
        elseif ($h) {
            if ($s['state'] !== 'visible') { $meta['note'] = 'Hidden sheet that looks like transactions; not imported'; }
            else { $meta['role'] = 'transactions'; $txSheets[] = [$s, $h, $idx]; }
        } else { $meta['note'] = 'No header row (Date, Amount, ...) found, skipped'; }
        $st['sheets'][$idx] = $meta;
    }
    $seq = 0;
    foreach ($txSheets as [$s, $h, $idx]) {
        $before = count($st['rows']); paytm_parse_rows($s, $h, $st, $seq); $read = count($st['rows']) - $before; $other = count($s['rows']) - 1 - $read;
        $st['sheets'][$idx]['note'] = $read . ' transaction' . ($read === 1 ? '' : 's') . ' read' . ($other > 0 ? ', ' . $other . ' other row' . ($other === 1 ? '' : 's') . ' ignored' : '');
    }
    $st['sheets'] = array_values($st['sheets']);
    if (count($st['rows']) > IMPORT_ADAPTERS_MAX_ROWS) { throw new UserError('This statement has ' . count($st['rows']) . ' transactions. Please import at most ' . IMPORT_ADAPTERS_MAX_ROWS . ' at a time (split the period).'); }
    paytm_mark_transfers($st);
    return $st;
}

function paytm_parse_summary(array $sheet, array &$st): void {
    $rows = $sheet['rows']; $before = true; $sum = ['paid' => ['amount' => null, 'count' => null], 'received' => ['amount' => null, 'count' => null], 'accounts' => []]; $found = false;
    $tableHead = null;
    foreach ($rows as $rn => $cells) {
        $first = xlsx_text($cells[min(array_keys($cells))]); $fn = imp_norm($first); $texts = array_map('xlsx_text', $cells);
        $second = count($cells) > 1 ? xlsx_text(array_values($cells)[1]) : '';
        if (str_contains($fn, 'paytmstatementfor')) {
            $before = false; $st['period'] = paytm_period($second);
            continue;
        }
        if ($before) {   // holder name, masked phone and e-mail sit above the period line
            if (count($cells) === 1) {
                if (str_contains($first, '@')) { $st['email'] = $st['email'] ?? imp_clean_text($first, 120); }
                elseif (preg_match('/^[\*xX\d\s+-]{6,}$/', $first)) { $st['phone'] = $st['phone'] ?? imp_clean_text($first, 30); }
                elseif ($st['holder'] === null && !preg_match('/^(paytm|passbook|statement)/i', $first)) { $st['holder'] = imp_clean_text($first, 80); }
            }
            continue;
        }
        if ($tableHead === null && $fn === 'accounts') {
            $tableHead = [];
            foreach ($cells as $col => $v) { $h = imp_norm(xlsx_text($v)); if (!str_contains($h, 'money')) { continue; } $paid = str_contains($h, 'paid'); $isCount = str_contains($h, 'no') && !str_contains($h, 'amount') || str_contains($h, 'number');
                $tableHead[$col] = ($paid ? 'paid_' : 'recv_') . ($isCount ? 'count' : 'amount'); }
            $found = $found || count($tableHead) >= 2; continue;
        }
        if ($tableHead !== null) {
            if (count($cells) < 2 || preg_match('/^(notes?|all payments|in case)/i', $first)) { $tableHead = null; if (count($cells) < 2) { continue; } }
            else {
                $acc = ['paid_amount' => 0, 'paid_count' => 0, 'recv_amount' => 0, 'recv_count' => 0]; $ok = false;
                foreach ($tableHead as $col => $field) { $val = $cells[$col] ?? null; if ($val === null) { continue; } if (str_ends_with($field, 'count')) { $acc[$field] = (int) preg_replace('/\D/', '', xlsx_text($val)); $ok = true; } else { $a = imp_amount($val); if ($a !== null) { $acc[$field] = abs($a); $ok = true; } } }
                if ($ok) { $sum['accounts'][imp_clean_text($first, 80)] = $acc; }
                continue;
            }
        }
        // total lines: "Money Paid (Amount in Rs.)" | -502,850.72 etc.
        if (str_contains($fn, 'moneypaid') || str_contains($fn, 'moneyreceived')) {
            $k = str_contains($fn, 'moneypaid') ? 'paid' : 'received'; $isCount = str_contains($fn, 'noof') || str_contains($fn, 'number') || str_contains($fn, 'count');
            if ($isCount) { $sum[$k]['count'] = (int) preg_replace('/\D/', '', $second); $found = true; }
            else { $a = imp_amount(count($cells) > 1 ? array_values($cells)[1] : null); if ($a !== null) { $sum[$k]['amount'] = abs($a); $found = true; } }
        }
    }
    if ($found) { $st['summary'] = $sum; }
}
function paytm_period(string $text): ?array {
    if (!preg_match_all('/(\d{1,2})\s*([A-Za-z]{3,9})\s*[\'’]?\s*(\d{2,4})/', $text, $m, PREG_SET_ORDER) || count($m) < 2) { return $text !== '' ? ['from' => null, 'to' => null, 'text' => imp_clean_text($text, 60)] : null; }
    $d = fn($x) => imp_month_num($x[2]) ? sprintf('%04d-%02d-%02d', imp_year((int) $x[3]), imp_month_num($x[2]), (int) $x[1]) : null;
    $f = $d($m[0]); $t = $d($m[1]);
    return ['from' => $f && valid_date($f) ? $f : null, 'to' => $t && valid_date($t) ? $t : null, 'text' => imp_clean_text($text, 60)];
}

const PAYTM_PREFIXES = ['Cashback Received from ' => 'cashback', 'Automatic payment for ' => 'auto', 'Money sent to ' => 'sent', 'Received from ' => 'received', 'Money received from ' => 'received', 'Paid to ' => 'paid', 'Payment to ' => 'paid', 'Sent to ' => 'sent', 'Refund from ' => 'refund'];
function paytm_payee(string $details): array {
    $d = imp_clean_text($details, 400);
    foreach (PAYTM_PREFIXES as $p => $kind) { if (stripos($d, $p) === 0) { $rest = trim(substr($d, strlen($p))); return [$rest !== '' ? $rest : $d, $kind]; } }
    if (preg_match('/^recharge of/i', $d)) { return [$d, 'recharge']; }
    if (preg_match('/^premium payment/i', $d)) { return [$d, 'premium']; }
    return [$d, 'other'];
}
/** "#🛒 Groceries" / "#⛽️ Fuel" => "Groceries" / "Fuel" (leading # and every emoji / symbol / variation selector removed). */
function import_clean_tag(string $raw): string {
    $t = preg_replace('/[^\p{L}\p{N}\s&\/\'’\-.,()+]+/u', '', $raw) ?? $raw;
    return imp_clean_text($t, 60);
}

function paytm_parse_rows(array $sheet, array $h, array &$st, int &$seq): void {
    [$hr, $map] = $h; $cnt = [];
    foreach ($sheet['rows'] as $rn => $cells) {
        if ($rn <= $hr) { continue; }
        $get = fn(string $f) => isset($map[$f]) ? ($cells[$map[$f]] ?? null) : null;
        $dateCell = $get('date'); $date = imp_date($dateCell);
        $sign = 0; $amt = imp_amount($get('amount'), $sign);
        $details = imp_clean_text(xlsx_text($get('details')), 400);
        if ($date === null || $amt === null) {
            $line = trim(implode(' | ', array_map(fn($v) => imp_clean_text(xlsx_text($v), 40), array_slice($cells, 0, 4, true))));
            if ($line !== '' && count($st['ignored']) < 20) { $st['ignored'][] = ['sheet' => $sheet['name'], 'row' => $rn, 'text' => mb_substr($line, 0, 120), 'why' => $date === null ? 'No valid date' : 'No valid amount']; }
            continue;
        }
        if ($amt === 0) { if (count($st['ignored']) < 20) { $st['ignored'][] = ['sheet' => $sheet['name'], 'row' => $rn, 'text' => mb_substr($details, 0, 80), 'why' => 'Zero amount']; } continue; }
        [$payee, $kind] = paytm_payee($details);
        if ($sign === 0 || ($sign > 0 && $amt > 0 && !preg_match('/^[+]/', trim((string) ($get('amount') ?? ''))) && is_string($get('amount')))) { $sign = in_array($kind, ['received', 'cashback', 'refund'], true) ? 1 : -1; }   // unsigned amounts: guess from the wording
        $dir = $sign < 0 ? 'out' : 'in'; $amount = abs($amt);
        $time = imp_time($get('time'), $dateCell);
        $account = imp_clean_text(xlsx_text($get('account')), 80);
        $ref = preg_replace('/[^A-Za-z0-9_.\-\/]/', '', xlsx_text($get('ref'))) ?? '';
        $ref = mb_substr($ref, 0, 60);
        $tagRaw = imp_clean_text(xlsx_text($get('tags')), 80);
        // the technical "Remarks" column (Transaction success, UPIIntent, ...) is ignored on purpose; only the user's own Comment becomes a remark
        $comment = imp_clean_text(xlsx_text($get('comment')), 500);
        $row = ['n' => ++$seq, 'date' => $date, 'time' => $time, 'amount' => $amount, 'dir' => $dir, 'details' => $details, 'payee' => mb_substr($payee, 0, 200), 'kind' => $kind,
            'other' => imp_clean_text(xlsx_text($get('other')), 190), 'account' => $account, 'ref' => $ref, 'order_id' => imp_clean_text(xlsx_text($get('order')), 60),
            'tag' => import_clean_tag($tagRaw), 'tag_raw' => $tagRaw, 'comment' => $comment, 'hint' => null];
        // dedupe key: the UPI reference when there is one, else a hash of what identifies the payment. The Nth identical key in a file gets "#N", so repeats stay distinct and stable.
        $base = $ref !== '' ? $ref : 'h:' . substr(sha1(implode('|', [$date, $time ?? '', $dir, $amount, $details, $row['other'], $account])), 0, 32);
        $ck = mb_strtolower($base . '|' . $account); $cnt[$ck] = ($cnt[$ck] ?? 0) + 1;
        $row['key'] = $cnt[$ck] > 1 ? $base . '#' . $cnt[$ck] : $base;
        $st['rows'][] = $row;
    }
}

/** Suggestions only: legs of a payment between the user's own accounts (same amount, opposite direction, other account, within 15 minutes) and rows addressed to the holder. */
function paytm_mark_transfers(array &$st): void {
    $rows = &$st['rows']; $holder = imp_norm((string) ($st['holder'] ?? '')); $tail = preg_match('/(\d{3,4})\D*$/', (string) ($st['phone'] ?? ''), $m) ? $m[1] : '';
    $ts = fn($r) => strtotime($r['date'] . ' ' . ($r['time'] ?? '12:00:00') . ' UTC');
    $byAmt = []; foreach ($rows as $i => $r) { $byAmt[$r['amount']][] = $i; }
    foreach ($byAmt as $idxs) {
        if (count($idxs) < 2) { continue; }
        foreach ($idxs as $i) {
            if ($rows[$i]['dir'] !== 'out' || $rows[$i]['hint']) { continue; }
            foreach ($idxs as $j) {
                if ($rows[$j]['dir'] !== 'in' || $rows[$j]['hint'] || $rows[$i]['account'] === $rows[$j]['account']) { continue; }
                $dt = abs($ts($rows[$i]) - $ts($rows[$j])); $limit = $rows[$i]['time'] !== null && $rows[$j]['time'] !== null ? 900 : 86400;
                if ($dt <= $limit) { $rows[$i]['hint'] = ['pair' => $rows[$j]['n']]; $rows[$j]['hint'] = ['pair' => $rows[$i]['n']]; break; }
            }
        }
    }
    foreach ($rows as $i => $r) {
        if ($r['hint']) { continue; }
        if (($holder !== '' && imp_norm($r['payee']) === $holder) || ($tail !== '' && preg_match('/\*+' . preg_quote($tail, '/') . '@/', $r['other']))) { $rows[$i]['hint'] = ['self' => true]; }
    }
}
