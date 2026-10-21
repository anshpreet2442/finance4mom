<?php
declare(strict_types=1);
/**
 * Statement import (Paytm UPI .xlsx, CSV with the same columns; more sources plug in through import_adapters.php).
 *
 * Flow: upload -> parse (nothing is written) -> a preview record is kept in `import_previews` for one hour, keyed to the user -> the user maps
 * accounts and categories, ticks rows -> confirm -> ONE database transaction writes everything (or nothing) -> history row in `imports` -> undo removes that batch.
 * The uploaded file itself is never stored; only the parsed rows live in the preview until it is committed or expires.
 */

const IMPORT_PREVIEW_TTL = 3600;
const IMPORT_MAX_ROWEDITS = 600000;

// ============================ preview store ============================
function import_gc(): void { run('DELETE FROM {import_previews} WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 3600)]); }
/** Keeps a parsed statement for an hour; returns the unguessable token. */
function import_store_preview(int $uid, string $filename, array $stmt): string {
    $token = bin2hex(random_bytes(16));
    $json = json_encode(['stmt' => $stmt], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    run('INSERT INTO {import_previews} (token,user_id,filename,data,expires_at) VALUES (?,?,?,?,?)', [$token, $uid, mb_substr($filename, 0, 190), $json, gmdate('Y-m-d H:i:s', time() + IMPORT_PREVIEW_TTL)]);
    if (random_int(1, 8) === 1) { import_gc(); }
    return $token;
}
/** The caller's own, unexpired preview or null (another person's token is simply "not found"). */
function import_load_preview(int $uid, string $token, bool $lock = false): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) { return null; }
    $r = one('SELECT * FROM {import_previews} WHERE token=? AND user_id=? AND expires_at >= ?' . ($lock ? ' FOR UPDATE' : ''), [$token, $uid, gmdate('Y-m-d H:i:s')]);
    if (!$r) { return null; }
    $d = $r['data'] !== null ? json_decode((string) $r['data'], true) : null;
    $r['stmt'] = is_array($d) ? ($d['stmt'] ?? null) : null;
    return $r;
}
function import_upload_max(): int {
    $ini = function (string $k): int { $v = trim((string) ini_get($k)); if ($v === '') { return PHP_INT_MAX; } $n = (int) $v; switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; // no break
        case 'm': $n *= 1024; // no break
        case 'k': $n *= 1024; } return $n > 0 ? $n : PHP_INT_MAX; };
    return max(1024, min(XLSX_MAX_BYTES, $ini('upload_max_filesize'), $ini('post_max_size') - 65536));
}

/** Reads the uploaded file, detects the source and stores a preview. Returns the token. Nothing touches the ledger. */
function import_handle_upload(int $uid, array $f): string {
    if (!c_rate_ok('imp-up:' . $uid, 12, 3600)) { throw new UserError('You have uploaded a lot of files this hour. Please try again a little later.'); }
    $err = (int) ($f['error'] ?? 0);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { throw new UserError('That file is too large (limit ' . max(1, (int) round(import_upload_max() / 1048576)) . ' MB).'); }
    if ($err === UPLOAD_ERR_NO_FILE) { throw new UserError('Choose the statement file first.'); }
    if ($err !== 0 || empty($f['tmp_name']) || !is_file((string) $f['tmp_name'])) { throw new UserError('The upload did not finish. Please try again.'); }
    $name = mb_substr(trim((string) preg_replace('/[^\p{L}\p{N} ._\-()]/u', '', basename((string) ($f['name'] ?? 'statement')))), 0, 120) ?: 'statement';
    $wb = workbook_load((string) $f['tmp_name'], $name);
    $stmt = import_parse_workbook($wb);
    $stmt['file'] = ['name' => $name, 'kind' => $wb['kind'], 'sheets_total' => count($wb['sheets'])];
    return import_store_preview($uid, $name, $stmt);
}

// ============================ groups, defaults, reconciliation ============================
function import_norm_name(string $s): string { return preg_replace('/\s+/u', ' ', mb_strtolower(trim($s))) ?? ''; }
/** The statement's own accounts ("Indian Bank - 46"), in order of first appearance. idx is what the forms post. */
function import_account_groups(array $stmt): array {
    $g = [];
    foreach ($stmt['rows'] as $r) {
        $k = $r['account']; $i = array_search($k, array_column($g, 'name'), true);
        if ($i === false) { $g[] = ['name' => $k, 'rows' => 0, 'min' => $r['date'], 'max' => $r['date'], 'out' => 0, 'in' => 0, 'out_n' => 0, 'in_n' => 0]; $i = count($g) - 1; }
        $g[$i]['rows']++; $g[$i]['min'] = min($g[$i]['min'], $r['date']); $g[$i]['max'] = max($g[$i]['max'], $r['date']);
        $g[$i][$r['dir']] += $r['amount']; $g[$i][$r['dir'] . '_n']++;
    }
    return $g;
}
/** Distinct (direction, tag) pairs: a Paytm tag can appear on money in and money out, and income and expense categories are separate lists. */
function import_tag_groups(array $stmt): array {
    $g = [];
    foreach ($stmt['rows'] as $r) {
        $k = $r['dir'] . '|' . $r['tag'];
        if (!isset($g[$k])) { $g[$k] = ['dir' => $r['dir'], 'tag' => $r['tag'], 'tag_raw' => $r['tag_raw'], 'rows' => 0, 'total' => 0]; }
        $g[$k]['rows']++; $g[$k]['total'] += $r['amount'];
    }
    return array_values($g);
}
function import_row_group(array $groups, array $r): int { foreach ($groups as $i => $g) { if ($g['dir'] === $r['dir'] && $g['tag'] === $r['tag']) { return $i; } } return -1; }

const IMPORT_TAG_SYNONYMS = [
    'groceries' => ['groceries', 'grocery', 'supermarket'], 'food' => ['food & dining', 'food', 'dining'], 'shopping' => ['shopping'], 'medical' => ['medical', 'health'], 'health' => ['medical'], 'pharmacy' => ['medical'],
    'fuel' => ['transport & travel', 'transport', 'travel'], 'petrol' => ['transport & travel'], 'travel' => ['transport & travel', 'travel'], 'transport' => ['transport & travel'],
    'bill payments' => ['other bills'], 'bills' => ['other bills'], 'utilities' => ['other bills'], 'recharges' => ['internet & mobile'], 'recharge' => ['internet & mobile'], 'mobile' => ['internet & mobile'],
    'education' => ['education'], 'insurance' => ['insurance'], 'entertainment' => ['entertainment'], 'miscellaneous' => ['miscellaneous'], 'misc' => ['miscellaneous'], 'others' => ['miscellaneous'],
    'cashback' => ['other income'], 'money received' => ['other income'], 'salary' => ['salary'], 'interest' => ['interest'], 'rent' => ['rent'], 'electricity' => ['electricity'], 'subscriptions' => ['subscriptions'],
];
/** Best existing category for a source tag: exact name, then a small synonym list, then a contained word. null => offer to create it. */
function import_match_category(array $cats, string $tag, bool $income): ?int {
    $tag = trim($tag); if ($tag === '') { return null; }
    $pool = array_values(array_filter($cats, fn($c) => ($c['grp'] === 'income') === $income));
    $n = fn(string $s) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
    $byName = []; foreach ($pool as $c) { $byName[$n($c['name'])][] = $c; }
    $pick = function (string $name) use ($byName, $n) { $l = $byName[$n($name)] ?? []; usort($l, fn($a, $b) => ((int) (bool) $a['parent_id']) <=> ((int) (bool) $b['parent_id'])); return $l ? (int) $l[0]['id'] : null; };
    if (($id = $pick($tag)) !== null) { return $id; }
    $lt = mb_strtolower($tag);
    foreach (IMPORT_TAG_SYNONYMS[$lt] ?? [] as $syn) { if (($id = $pick($syn)) !== null) { return $id; } }
    if (str_ends_with($lt, 's') && ($id = $pick(substr($tag, 0, -1))) !== null) { return $id; }
    $nt = $n($tag);
    if (strlen($nt) >= 5) { foreach ($pool as $c) { $nc = $n($c['name']); if (strlen($nc) >= 5 && (str_contains($nc, $nt) || str_contains($nt, $nc))) { return (int) $c['id']; } } }
    return null;
}
function import_default_choices(array $s, array $stmt): array {
    $accs = array_values(array_filter($s['accounts'], fn($a) => !$a['archived'])); $ch = ['acc' => [], 'accname' => [], 'bal' => [], 'cat' => [], 'rowcat' => [], 'rowrem' => [], 'exclude' => [], 'mode' => 'keep'];
    foreach (import_account_groups($stmt) as $i => $g) {
        $hit = null; $nn = import_norm_name($g['name']); $sq = preg_replace('/[^a-z0-9]+/', '', $nn);
        foreach ($accs as $a) { if (import_norm_name($a['name']) === $nn) { $hit = $a; break; } }
        if (!$hit && $sq !== '') { foreach ($accs as $a) { if (preg_replace('/[^a-z0-9]+/', '', import_norm_name($a['name'])) === $sq) { $hit = $a; break; } } }
        $ch['acc'][$i] = $hit ? (int) $hit['id'] : 'new'; $ch['accname'][$i] = mb_substr($g['name'] !== '' ? $g['name'] : 'Imported account', 0, 60); $ch['bal'][$i] = '';
    }
    foreach (import_tag_groups($stmt) as $i => $g) {
        if ($g['tag'] === '') { $ch['cat'][$i] = ''; continue; }
        $id = import_match_category($s['categories'], $g['tag'], $g['dir'] === 'in'); $ch['cat'][$i] = $id !== null ? $id : 'new';
    }
    return $ch;
}
/** Statement Summary vs the rows we read: [['label','expected','actual','kind'=>'money'|'count','ok'], ...] or null when the file has no Summary. */
function import_reconcile(array $stmt): ?array {
    $sum = $stmt['summary'] ?? null; if (!$sum) { return null; }
    $paid = $recv = ['amount' => 0, 'count' => 0]; $acc = [];
    foreach ($stmt['rows'] as $r) {
        $k = $r['dir'] === 'out' ? 'paid' : 'recv'; $a = &$acc[$r['account']]; $a ??= ['paid_amount' => 0, 'paid_count' => 0, 'recv_amount' => 0, 'recv_count' => 0];
        $a[$k . '_amount'] += $r['amount']; $a[$k . '_count']++; unset($a);
        if ($r['dir'] === 'out') { $paid['amount'] += $r['amount']; $paid['count']++; } else { $recv['amount'] += $r['amount']; $recv['count']++; }
    }
    $lines = []; $add = function (string $label, $exp, int $act, string $kind) use (&$lines) { if ($exp === null) { return; } $lines[] = ['label' => $label, 'expected' => (int) $exp, 'actual' => $act, 'kind' => $kind, 'ok' => (int) $exp === $act]; };
    $add('Money paid', $sum['paid']['amount'], $paid['amount'], 'money'); $add('Payments made', $sum['paid']['count'], $paid['count'], 'count');
    $add('Money received', $sum['received']['amount'], $recv['amount'], 'money'); $add('Payments received', $sum['received']['count'], $recv['count'], 'count');
    foreach ($sum['accounts'] ?? [] as $name => $e) {
        $a = $acc[$name] ?? ['paid_amount' => 0, 'paid_count' => 0, 'recv_amount' => 0, 'recv_count' => 0];
        $add($name . ' · paid', $e['paid_amount'], $a['paid_amount'], 'money'); $add($name . ' · payments', $e['paid_count'], $a['paid_count'], 'count');
        $add($name . ' · received', $e['recv_amount'], $a['recv_amount'], 'money'); $add($name . ' · receipts', $e['recv_count'], $a['recv_count'], 'count');
    }
    foreach ($acc as $name => $a) { if (!isset(($sum['accounts'] ?? [])[$name]) && $sum['accounts']) { $lines[] = ['label' => $name . ' (not in the Summary)', 'expected' => 0, 'actual' => $a['paid_count'] + $a['recv_count'], 'kind' => 'count', 'ok' => false]; } }
    return $lines;
}

// ============================ plan (what a commit would do) ============================
/** Existing imported keys for this user and source: "ref|accountId" => true. */
function import_existing_keys(int $uid, string $source, array $keys): array {
    $out = []; $keys = array_values(array_unique($keys));
    foreach (array_chunk($keys, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach (all("SELECT source_ref, account_id FROM {transactions} WHERE user_id=? AND source=? AND source_ref IN ($ph)", array_merge([$uid, $source], $chunk)) as $r) { $out[mb_strtolower((string) $r['source_ref']) . '|' . (int) $r['account_id']] = true; }
    }
    return $out;
}
/** Resolves every statement row against the choices: target account, category, duplicate / excluded flags, remarks. Pure with respect to the ledger (reads only). */
function import_plan(int $uid, array $stmt, array $ch): array {
    $ag = import_account_groups($stmt); $tg = import_tag_groups($stmt);
    $accIdx = []; foreach ($ag as $i => $g) { $accIdx[$g['name']] = $i; }
    $existing = import_existing_keys($uid, (string) $stmt['source'], array_column($stmt['rows'], 'key'));
    $exclude = array_flip(array_map('intval', $ch['exclude'] ?? [])); $seen = []; $plan = [];
    foreach ($stmt['rows'] as $r) {
        $ai = $accIdx[$r['account']]; $target = $ch['acc'][$ai] ?? 'new'; $ti = import_row_group($tg, $r);
        $cat = array_key_exists($r['n'], $ch['rowcat'] ?? []) ? $ch['rowcat'][$r['n']] : ($ch['cat'][$ti] ?? '');
        $key = $r['key']; $tk = $target === 'new' ? 'n' . $ai : (string) (int) $target;
        if (isset($seen[mb_strtolower($key) . '|' . $tk])) { $key .= '@' . $ai; } $seen[mb_strtolower($r['key']) . '|' . $tk] = true; $seen[mb_strtolower($key) . '|' . $tk] = true;
        $dup = $target !== 'new' && isset($existing[mb_strtolower($key) . '|' . (int) $target]);
        $plan[] = ['row' => $r, 'ai' => $ai, 'ti' => $ti, 'target' => $target, 'cat' => $cat, 'key' => mb_substr($key, 0, 80), 'dup' => $dup, 'include' => !isset($exclude[$r['n']]),
            'remarks' => array_key_exists($r['n'], $ch['rowrem'] ?? []) ? (string) $ch['rowrem'][$r['n']] : (string) $r['comment']];
    }
    return $plan;
}
function import_cash_effect(string $dir, int $amount): int { return $dir === 'in' ? $amount : -$amount; }

// ============================ choices from the form ============================
/** Cleans what the preview form posted into the structure import_plan()/import_commit() use. Unknown indexes are ignored; ids must belong to this user. */
function import_choices_from_post(int $uid, array $stmt, array $s, array $post): array {
    $ch = import_default_choices($s, $stmt); $owned = array_column($s['accounts'], 'id'); $ownedCats = array_column($s['categories'], 'id');
    foreach (import_account_groups($stmt) as $i => $g) {
        $v = $post['acc'][$i] ?? null;
        if ($v === 'new') { $ch['acc'][$i] = 'new'; }
        elseif (is_string($v) && ctype_digit($v)) {
            $a = null; foreach ($s['accounts'] as $x) { if ((int) $x['id'] === (int) $v) { $a = $x; } }
            if (!$a || $a['archived']) { throw new UserError('Choose one of your accounts for “' . $g['name'] . '”.'); } $ch['acc'][$i] = (int) $v;
        }
        $nm = $post['accname'][$i] ?? null; if (is_string($nm)) { $nm = imp_clean_text($nm, 60); if ($nm !== '') { $ch['accname'][$i] = $nm; } }
        $b = $post['bal'][$i] ?? ''; if (is_string($b) && trim($b) !== '') { $m = parse_money($b, true); if ($m === null) { throw new UserError('“Balance today” for ' . $g['name'] . ' is not a valid amount.'); } $ch['bal'][$i] = $m; }
    }
    foreach (import_tag_groups($stmt) as $i => $g) {
        $v = $post['cat'][$i] ?? null;
        if ($v === 'new' || $v === '') { $ch['cat'][$i] = $g['tag'] === '' ? '' : $v; }
        elseif (is_string($v) && ctype_digit($v)) { if (!in_array((int) $v, array_map('intval', $ownedCats), true)) { throw new UserError('Choose one of your categories for “' . $g['tag'] . '”.'); } $ch['cat'][$i] = (int) $v; }
    }
    $mode = $post['mode'] ?? 'keep'; $ch['mode'] = $mode === 'reflect' ? 'reflect' : 'keep';
    $re = $post['rowedits'] ?? '';
    if (is_string($re) && $re !== '') {
        if (strlen($re) > IMPORT_MAX_ROWEDITS) { throw new UserError('Too many row edits at once.'); }
        $e = json_decode($re, true); if (!is_array($e)) { throw new UserError('The row edits could not be read. Reload the page and try again.'); }
        $valid = array_flip(array_column($stmt['rows'], 'n'));
        foreach ((array) ($e['ex'] ?? []) as $n) { if (is_int($n) && isset($valid[$n])) { $ch['exclude'][] = $n; } }
        foreach ((array) ($e['cat'] ?? []) as $n => $v) {
            $n = (int) $n; if (!isset($valid[$n])) { continue; }
            if ($v === 'none' || $v === '') { $ch['rowcat'][$n] = ''; } elseif (is_int($v) || (is_string($v) && ctype_digit($v))) { if (!in_array((int) $v, array_map('intval', $ownedCats), true)) { throw new UserError('A row uses a category that is not yours.'); } $ch['rowcat'][$n] = (int) $v; }
        }
        foreach ((array) ($e['rm'] ?? []) as $n => $v) { $n = (int) $n; if (isset($valid[$n]) && is_string($v)) { $ch['rowrem'][$n] = imp_clean_text($v, 500); } }
    }
    return $ch;
}

// ============================ commit / undo ============================
function import_meta_json(array $r): string {
    $m = ['d' => mb_substr($r['details'], 0, 200), 'o' => mb_substr($r['other'], 0, 190), 'i' => mb_substr($r['order_id'], 0, 40), 't' => mb_substr($r['tag_raw'], 0, 60), 'a' => mb_substr($r['account'], 0, 80)];
    $j = json_encode(array_filter($m, fn($v) => $v !== ''), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return mb_strlen($j) > 1000 ? mb_substr($j, 0, 0) : (string) $j;   // never a truncated (invalid) JSON string
}
/**
 * Writes the import. All or nothing: one database transaction, the preview row is locked so a double submit imports once.
 * Returns ['import_id','imported','duplicates','excluded','accounts_created','categories_created','already'].
 */
function import_commit(int $uid, string $token, array $ch): array {
    if (!c_rate_ok('imp-commit:' . $uid, 20, 3600)) { throw new UserError('Too many imports this hour. Please try again later.'); }
    return tx(function () use ($uid, $token, $ch) {
        $pv = import_load_preview($uid, $token, true);
        if (!$pv) { throw new UserError('This import preview was not found or has expired. Upload the file again.'); }
        if ($pv['import_id']) { $im = one('SELECT * FROM {imports} WHERE id=? AND user_id=?', [$pv['import_id'], $uid]); return ['import_id' => (int) $pv['import_id'], 'imported' => (int) ($im['rows_imported'] ?? 0), 'duplicates' => (int) ($im['rows_skipped'] ?? 0), 'excluded' => 0, 'accounts_created' => 0, 'categories_created' => 0, 'already' => true]; }
        $stmt = $pv['stmt']; if (!$stmt || empty($stmt['source']) || empty($stmt['rows'])) { throw new UserError('Nothing importable was found in this file.'); }
        $source = (string) $stmt['source']; $today = today();
        $accounts = []; foreach (all('SELECT id,name,opening_date,opening_balance,archived FROM {accounts} WHERE user_id=?', [$uid]) as $a) { $accounts[(int) $a['id']] = $a; }
        foreach ($ch['acc'] as $v) { if ($v !== 'new' && (!isset($accounts[(int) $v]) || $accounts[(int) $v]['archived'])) { throw new UserError('One of the chosen accounts is no longer available.'); } }
        $cats = []; foreach (all('SELECT id,name,grp,parent_id FROM {categories} WHERE user_id=?', [$uid]) as $c) { $cats[(int) $c['id']] = $c; }
        foreach (array_merge($ch['cat'], $ch['rowcat']) as $v) { if ($v !== 'new' && $v !== '' && !isset($cats[(int) $v])) { throw new UserError('One of the chosen categories is no longer available.'); } }

        $plan = import_plan($uid, $stmt, $ch); $todo = []; $dups = 0; $excl = 0;
        foreach ($plan as $p) { if (!$p['include']) { $excl++; } elseif ($p['dup']) { $dups++; } else { $todo[] = $p; } }
        if (!$todo) { run('DELETE FROM {import_previews} WHERE token=? AND user_id=?', [$token, $uid]); return ['import_id' => 0, 'imported' => 0, 'duplicates' => $dups, 'excluded' => $excl, 'accounts_created' => 0, 'categories_created' => 0, 'already' => false]; }
        usort($todo, fn($a, $b) => [$a['row']['date'], $a['row']['time'] ?? '', $a['row']['n']] <=> [$b['row']['date'], $b['row']['time'] ?? '', $b['row']['n']]);

        $ag = import_account_groups($stmt); $tg = import_tag_groups($stmt);
        $imp = insert('INSERT INTO {imports} (user_id,source,filename,rows_total) VALUES (?,?,?,?)', [$uid, $source, $pv['filename'], count($stmt['rows'])]);

        // categories first (only the ones some imported row really uses)
        $newCat = []; $catsCreated = 0;
        $catFor = function ($choice, int $ti) use (&$newCat, &$catsCreated, $tg, $uid, &$cats) {
            if ($choice === '' || $choice === null) { return null; }
            if ($choice !== 'new') { return (int) $choice; }
            if (isset($newCat[$ti])) { return $newCat[$ti]; }
            $g = $tg[$ti]; $name = mb_substr($g['tag'], 0, 60); $grp = $g['dir'] === 'in' ? 'income' : 'custom';
            foreach ($cats as $c) { if (!$c['parent_id'] && mb_strtolower($c['name']) === mb_strtolower($name) && ($c['grp'] === 'income') === ($grp === 'income')) { return $newCat[$ti] = (int) $c['id']; } }
            $id = insert('INSERT INTO {categories} (user_id,name,grp) VALUES (?,?,?)', [$uid, $name, $grp]); $cats[$id] = ['id' => $id, 'name' => $name, 'grp' => $grp, 'parent_id' => null]; $catsCreated++;
            return $newCat[$ti] = $id;
        };
        // accounts: create the ones that are needed, with their opening date/balance
        $minDate = []; $net = [];
        foreach ($todo as $p) { $ai = $p['ai']; $minDate[$ai] = isset($minDate[$ai]) ? min($minDate[$ai], $p['row']['date']) : $p['row']['date']; }
        $accId = []; $accCreated = 0; $usedNames = array_map(fn($a) => mb_strtolower($a['name']), $accounts);
        foreach ($minDate as $ai => $md) {
            $t = $ch['acc'][$ai] ?? 'new';
            if ($t !== 'new') { $accId[$ai] = (int) $t; continue; }
            $name = $ch['accname'][$ai] ?? ($ag[$ai]['name'] ?: 'Imported account'); $base = $name; $k = 2;
            while (in_array(mb_strtolower($name), $usedNames, true)) { $name = mb_substr($base, 0, 54) . ' (' . $k++ . ')'; }
            $usedNames[] = mb_strtolower($name);
            $type = preg_match('/wallet/i', $name) ? 'wallet' : 'bank'; $od = min($md, $today);
            $accId[$ai] = insert('INSERT INTO {accounts} (user_id,name,type,opening_balance,opening_date,purpose) VALUES (?,?,?,?,?,?)', [$uid, $name, $type, 0, $od, 'spending']);
            $accounts[$accId[$ai]] = ['id' => $accId[$ai], 'name' => $name, 'opening_date' => $od, 'opening_balance' => 0, 'archived' => 0, 'new' => true]; $accCreated++;
        }
        // rows
        $cols = '(user_id,type,status,amount,date,account_id,category_id,description,payment_method,remarks,source,source_ref,import_id,txn_time,source_meta)';
        $count = 0; $first = true;
        foreach (array_chunk($todo, 150) as $chunk) {
            $ph = []; $args = [];
            foreach ($chunk as $p) {
                $r = $p['row']; $aid = $accId[$p['ai']]; $cid = $catFor($p['cat'], $p['ti']);
                $ph[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push($args, $uid, $r['dir'] === 'in' ? 'income' : 'expense', 'completed', $r['amount'], $r['date'], $aid, $cid, mb_substr($r['payee'] !== '' ? $r['payee'] : $r['details'], 0, 200), 'UPI', tx_remarks($p['remarks']), $source, $p['key'], $imp, $r['time'], import_meta_json($r) ?: null);
                $od = $accounts[$aid]['opening_date']; if ($r['date'] >= $od) { $net[$aid] = ($net[$aid] ?? 0) + import_cash_effect($r['dir'], $r['amount']); }
                $count++;
            }
            run('INSERT INTO {transactions} ' . $cols . ' VALUES ' . implode(',', $ph), $args);
            if ($first && !empty($GLOBALS['__import_fail_after_first_chunk'])) { throw new RuntimeException('simulated failure'); }   // test hook: proves the whole import rolls back
            $first = false;
        }
        // balances
        $keep = ($ch['mode'] ?? 'keep') === 'keep'; $adjusted = 0;
        if ($keep) {
            foreach ($accId as $ai => $aid) {
                $e = $net[$aid] ?? 0; $a = $accounts[$aid];
                if (!empty($a['new'])) { $target = $ch['bal'][$ai] ?? ''; $target = $target === '' ? 0 : (int) $target; run('UPDATE {accounts} SET opening_balance=? WHERE id=? AND user_id=?', [$target - $e, $aid, $uid]); }
                elseif ($e !== 0) {
                    $d = max($a['opening_date'], day_before($minDate[$ai])); if ($d < $a['opening_date']) { $d = $a['opening_date']; }
                    insert('INSERT INTO {transactions} (user_id,type,status,amount,date,account_id,description,notes,import_id) VALUES (?,?,?,?,?,?,?,?,?)',
                        [$uid, 'adjustment', 'completed', -$e, $d, $aid, 'Balance correction for ' . import_source_label($source) . ' import', 'Keeps today\'s balance unchanged. It is removed together with the import.', $imp]); $adjusted++;
                }
            }
        }
        $dates = array_column(array_column($todo, 'row'), 'date'); sort($dates);
        $newAccs = []; foreach ($accId as $aid) { if (!empty($accounts[$aid]['new'])) { $newAccs[$aid] = $keep ? ($net[$aid] ?? 0) : 0; } }   // account id => the shift put into its opening balance (so undo can take it back)
        $summary = ['period' => $stmt['period']['text'] ?? null, 'from' => $dates[0], 'to' => end($dates), 'mode' => $keep ? 'keep' : 'reflect', 'accounts_created' => $accCreated, 'categories_created' => $catsCreated, 'adjustments' => $adjusted, 'excluded' => $excl,
            'new_accounts' => $newAccs, 'new_categories' => array_values($newCat)];
        run('UPDATE {imports} SET rows_imported=?, rows_skipped=?, summary=? WHERE id=?', [$count, $dups, json_encode($summary, JSON_UNESCAPED_UNICODE), $imp]);
        run('UPDATE {import_previews} SET import_id=?, data=NULL WHERE token=? AND user_id=?', [$imp, $token, $uid]);
        audit('import.commit', 'import#' . $imp, import_source_label($source) . ': ' . $count . ' added, ' . $dups . ' duplicates skipped');
        return ['import_id' => $imp, 'imported' => $count, 'duplicates' => $dups, 'excluded' => $excl, 'accounts_created' => $accCreated, 'categories_created' => $catsCreated, 'already' => false];
    });
}
/** Removes one import batch (its transactions and the balance correction made for it). Returns how many transactions went. */
function import_remove(int $uid, int $importId): int {
    return tx(function () use ($uid, $importId) {
        $im = one('SELECT * FROM {imports} WHERE id=? AND user_id=? FOR UPDATE', [$importId, $uid]);
        if (!$im) { throw new UserError('Import not found.'); }
        if ($im['status'] === 'removed') { throw new UserError('That import was already removed.'); }
        $n = run('DELETE FROM {transactions} WHERE import_id=? AND user_id=?', [$importId, $uid]);
        $sum = json_decode((string) $im['summary'], true) ?: [];
        foreach (($sum['new_accounts'] ?? []) as $aid => $shift) {   // accounts this import created: gone again when nothing else lives in them, otherwise their opening balance is put back
            $aid = (int) $aid; $a = one('SELECT id FROM {accounts} WHERE id=? AND user_id=?', [$aid, $uid]); if (!$a) { continue; }
            if ((int) val('SELECT COUNT(*) FROM {transactions} WHERE user_id=? AND (account_id=? OR to_account_id=?)', [$uid, $aid, $aid]) === 0) { run('DELETE FROM {accounts} WHERE id=? AND user_id=?', [$aid, $uid]); }
            elseif ((int) $shift !== 0) { run('UPDATE {accounts} SET opening_balance = opening_balance + ? WHERE id=? AND user_id=?', [(int) $shift, $aid, $uid]); }
        }
        foreach (($sum['new_categories'] ?? []) as $cid) {   // same for categories nobody has used since
            $cid = (int) $cid; if (!one('SELECT id FROM {categories} WHERE id=? AND user_id=?', [$cid, $uid])) { continue; }
            $used = (int) val('SELECT (SELECT COUNT(*) FROM {transactions} WHERE category_id=?) + (SELECT COUNT(*) FROM {budgets} WHERE category_id=?) + (SELECT COUNT(*) FROM {bills} WHERE category_id=?) + (SELECT COUNT(*) FROM {recurring_templates} WHERE category_id=?) + (SELECT COUNT(*) FROM {categories} WHERE parent_id=?)', [$cid, $cid, $cid, $cid, $cid]);
            if ($used === 0) { run('DELETE FROM {categories} WHERE id=? AND user_id=?', [$cid, $uid]); }
        }
        run("UPDATE {imports} SET status='removed', removed_at=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $importId]);
        audit('import.remove', 'import#' . $importId, $n . ' transactions removed');
        return $n;
    });
}

// ============================ actions ============================
function do_import_upload(array $u): ?string {
    $token = import_handle_upload((int) $u['id'], $_FILES['file'] ?? []);
    $GLOBALS['__redirect'] = url('/import', ['p' => $token]); return null;
}
function do_import_commit(array $u): ?string {
    $uid = (int) $u['id']; $token = in_str('token', 40); $pv = import_load_preview($uid, $token);
    if (!$pv) { throw new UserError('This import preview was not found or has expired. Upload the file again.'); }
    if ($pv['import_id']) { $GLOBALS['__redirect'] = url('/import', ['done' => (int) $pv['import_id']]); return null; }   // double submit: show the finished import
    $s = load_snapshot($uid); $ch = import_choices_from_post($uid, $pv['stmt'], $s, $_POST);
    try { $res = import_commit($uid, $token, $ch); }
    catch (PDOException $e) { if ($e->getCode() === '23000') { throw new UserError('Some of these transactions were imported a moment ago. Nothing was changed; open the import page and check again.'); } throw $e; }
    if ($res['import_id'] === 0) { $GLOBALS['__redirect'] = url('/import'); return 'Nothing new to import: ' . $res['duplicates'] . ' transaction' . ($res['duplicates'] === 1 ? ' was' : 's were') . ' already imported.'; }
    $GLOBALS['__redirect'] = url('/import', ['done' => $res['import_id']]); return null;
}
function do_import_remove(array $u): string {
    $n = import_remove((int) $u['id'], in_id('id', 'import')); return 'Import removed (' . $n . ' transaction' . ($n === 1 ? '' : 's') . ' deleted). Your balances are back to what they were.';
}
function do_import_discard(array $u): string { run('DELETE FROM {import_previews} WHERE token=? AND user_id=?', [in_str('token', 40), $u['id']]); return 'Preview discarded. Nothing was imported.'; }

// ============================ the "Imported from ..." label ============================
function import_labels_on(): bool { return (int) (prefs()['show_import_labels'] ?? 1) === 1; }
function import_dates(int $uid): array {
    static $cache = []; if (!isset($cache[$uid])) { $cache[$uid] = array_column(all('SELECT id, created_at FROM {imports} WHERE user_id=?', [$uid]), 'created_at', 'id'); } return $cache[$uid];
}
/** Own-drawn mark (an arrow dropping into a tray on a blue gradient disc): no third-party artwork. */
function import_mark(): string {
    return '<span class="imp-mark" aria-hidden="true"><svg viewBox="0 0 16 16" focusable="false"><path d="M8 3.2v5.6M5.6 6.6 8 9l2.4-2.4M4.6 11.6h6.8" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
}
/** The pill. mode 'src' => just the source ("Paytm", for import screens), 'full' => "Imported from Paytm", 'compact' => "Paytm" (visually; the full text stays for screen readers). Empty when the transaction was not imported or the person switched labels off. */
function import_badge(array $t, string $mode = 'compact', bool $force = false): string {
    if (empty($t['source']) || (!$force && !import_labels_on())) { return ''; }
    $label = import_source_label((string) $t['source']); $tip = 'Imported from ' . $label;
    if (!empty($t['source_ref']) && !str_starts_with((string) $t['source_ref'], 'h:')) { $tip .= ' · UPI ref ' . preg_replace('/[#@].*$/', '', (string) $t['source_ref']); }
    $u = current_user(); if ($u && !empty($t['import_id'])) { $d = import_dates((int) $u['id'])[$t['import_id']] ?? null; if ($d) { $tip .= ' · imported ' . date_label(substr((string) $d, 0, 10)); } }
    if ($mode === 'src') { return '<span class="imp-pill imp-pill--lg imp-pill--' . e($t['source']) . '">' . import_mark() . '<span>' . e($label) . '</span></span>'; }
    return '<span class="imp-pill imp-pill--' . e($t['source']) . '" title="' . e($tip) . '">' . import_mark() . ($mode === 'full' ? '<span>Imported from ' . e($label) . '</span>' : '<span aria-hidden="true">' . e($label) . '</span><span class="sr-only">Imported from ' . e($label) . '</span>') . '</span>';
}
/** Muted remarks line + badge shown under a transaction's description ('' when there is neither). */
function tx_extra_line(array $t, string $mode = 'compact'): string {
    $b = import_badge($t, $mode); $r = (string) ($t['remarks'] ?? ''); if ($b === '' && $r === '') { return ''; }
    return '<span class="imp-line">' . $b . ($r !== '' ? '<span class="imp-remark">' . e($r) . '</span>' : '') . '</span>';
}
/** Data for the imported-from block in the edit dialog (escaped when it is rendered by import.js with textContent). */
function tx_import_info(array $t): ?array {
    if (empty($t['source'])) { return null; }
    $m = json_decode((string) ($t['source_meta'] ?? ''), true); $m = is_array($m) ? $m : [];
    $u = current_user(); $d = $u && !empty($t['import_id']) ? (import_dates((int) $u['id'])[$t['import_id']] ?? null) : null;
    return ['label' => import_source_label((string) $t['source']), 'on' => import_labels_on(), 'ref' => str_starts_with((string) $t['source_ref'], 'h:') ? '' : preg_replace('/[#@].*$/', '', (string) $t['source_ref']), 'time' => $t['txn_time'] ?? null,
        'details' => $m['d'] ?? '', 'other' => $m['o'] ?? '', 'order' => $m['i'] ?? '', 'tag' => $m['t'] ?? '', 'account' => $m['a'] ?? '', 'imported' => $d ? date_label(substr((string) $d, 0, 10)) : ''];
}
