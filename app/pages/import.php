<?php
declare(strict_types=1);
/** /import: upload -> preview (nothing written) -> confirm -> result; plus history with undo. Logic lives in app/import.php. */

function page_import(): void {
    $u = require_login(); $uid = (int) $u['id']; $s = snap();
    if (isset($_GET['p'])) { import_page_preview($u, $s, get_str('p', 40)); return; }
    if (isset($_GET['done'])) { import_page_done($u, $s, (int) get_str('done', 12)); return; }
    layout_start('Import', 'transactions', $s);
    page_header('Import transactions', 'Bring in a bank or UPI statement instead of typing every payment.', btn('Back to transactions', url('/transactions'), 'btn', 'arrow-left'));
    $max = import_upload_max(); $tab = get_str('tab', 10) === 'json' ? 'json' : 'file';
    echo '<nav class="imp-tabs" aria-label="Import method"><a class="imp-tab' . ($tab === 'file' ? ' is-on' : '') . '" href="' . e(url('/import')) . '"' . ($tab === 'file' ? ' aria-current="page"' : '') . '>' . icon('file', 'size-4') . ' Statement file</a>'
        . '<a class="imp-tab' . ($tab === 'json' ? ' is-on' : '') . '" data-imp-jsonlink href="' . e(url('/import', ['tab' => 'json'])) . '#json"' . ($tab === 'json' ? ' aria-current="page"' : '') . '>' . icon('sparkles', 'size-4') . ' Use your own AI (JSON)</a></nav>';
    if ($tab === 'json') { echo import_json_panel_html(); }
    if ($tab === 'file') {
    echo '<div class="imp-wrap"><section class="card imp-card"><h2 class="imp-h">' . icon('upload', 'size-5') . ' Upload a statement</h2>'
        . '<p class="imp-p">Nothing is saved yet. You will see exactly what was found, check it against the statement\'s own totals, and choose accounts and categories before anything is added.</p>'
        . str_replace('<form ', '<form data-b64="' . e(url('/import/b64')) . '" ', form_open('import-upload', 'imp-up', true)) . '<label class="label" for="imp-file">Statement file</label><input id="imp-file" class="input" type="file" name="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required data-max="' . $max . '">'
        . '<p class="imp-hint">.xlsx or .csv, up to ' . max(1, (int) round($max / 1048576)) . ' MB. The file is read on the server and not kept; the parsed preview is deleted after an hour or when you finish.</p>'
        . '<div class="imp-prog" data-imp-prog hidden aria-live="polite"></div><p class="imp-err" data-imp-err role="alert" hidden></p><button class="btn btn-primary" data-imp-upload>' . icon('search', 'size-4') . ' Read statement</button></form></section>';
    echo '<section class="card imp-card"><h2 class="imp-h">' . icon('file', 'size-5') . ' Supported statements</h2><ul class="imp-src">';
    foreach (import_adapters() as $a) { if ($a['key'] === 'json') { continue; } echo '<li>' . import_badge(['source' => $a['key']], 'src', true) . '<span class="imp-src__t"><b>' . e($a['title'] ?? $a['label']) . '</b><span>' . e($a['accept'] ?? '') . '</span></span></li>'; }
    echo '</ul><p class="imp-p">Other apps (PhonePe, Google Pay, bank CSV files) can be added without changing anything else. A CSV with the same column headings as the Paytm sheet also works. Have a PDF, a photo or any other statement? <a href="' . e(url('/import', ['tab' => 'json'])) . '#json">Let your own AI turn it into JSON</a>.</p></section></div>';
    }
    $hist = all('SELECT * FROM {imports} WHERE user_id=? ORDER BY id DESC LIMIT 30', [$uid]);
    echo '<section class="card imp-card imp-hist" id="history"><h2 class="imp-h">' . icon('history', 'size-5') . ' Import history</h2>';
    if (!$hist) { echo '<p class="imp-p">No imports yet.</p>'; }
    else {
        echo '<ul class="imp-hlist">';
        foreach ($hist as $h) {
            $sm = json_decode((string) $h['summary'], true) ?: []; $removed = $h['status'] === 'removed';
            echo '<li class="imp-h-row' . ($removed ? ' is-removed' : '') . '"><div class="imp-h-main"><p class="imp-h-t">' . import_badge(['source' => $h['source']], 'src', true) . ' <span class="imp-h-file">' . e($h['filename']) . '</span></p>'
                . '<p class="imp-h-s">' . e(date_label(substr((string) $h['created_at'], 0, 10))) . ' · ' . (int) $h['rows_imported'] . ' added' . ((int) $h['rows_skipped'] ? ', ' . (int) $h['rows_skipped'] . ' duplicates skipped' : '')
                . (!empty($sm['from']) ? ' · ' . e(date_short($sm['from'])) . ' to ' . e(date_label($sm['to'])) : '') . ($removed ? ' · <b>removed</b>' : '') . '</p></div><div class="imp-h-act">'
                . (!$removed ? btn('View', url('/transactions', ['import' => $h['id']]), 'btn btn-sm', 'search') . confirm_btn('Remove this import', 'import-remove', ['id' => $h['id']], 'Remove this import?', 'This deletes the ' . (int) $h['rows_imported'] . ' transactions it added (and the balance correction made for it). Anything you edited on them is lost. Other transactions are not touched.', 'Remove import', 'btn btn-sm btn-ghost text-neg') : '')
                . '</div></li>';
        }
        echo '</ul>';
    }
    echo '</section>'; layout_end();
}

function import_page_done(array $u, array $s, int $id): void {
    $im = $id ? one('SELECT * FROM {imports} WHERE id=? AND user_id=?', [$id, $u['id']]) : null;
    if (!$im) { flash('err', 'That import was not found.'); redirect(url('/import')); }
    $sm = json_decode((string) $im['summary'], true) ?: [];
    layout_start('Import finished', 'transactions', $s);
    page_header($im['status'] === 'removed' ? 'Import removed' : 'Import finished', $im['status'] === 'removed' ? 'These transactions were deleted again.' : 'Your statement is in the ledger.');
    echo '<section class="card imp-card imp-done"><span class="imp-done__ic">' . icon($im['status'] === 'removed' ? 'rotate-ccw' : 'circle-check', 'size-8') . '</span><h2 class="imp-h2">' . (int) $im['rows_imported'] . ' transaction' . ((int) $im['rows_imported'] === 1 ? '' : 's') . ($im['status'] === 'removed' ? ' were removed' : ' added') . '</h2><dl class="imp-dl">'
        . '<div><dt>Source</dt><dd>' . import_badge(['source' => $im['source']], 'src', true) . '</dd></div><div><dt>File</dt><dd>' . e($im['filename']) . '</dd></div>'
        . '<div><dt>Skipped as already imported</dt><dd>' . (int) $im['rows_skipped'] . '</dd></div>' . (!empty($sm['excluded']) ? '<div><dt>Left out by you</dt><dd>' . (int) $sm['excluded'] . '</dd></div>' : '')
        . (!empty($sm['accounts_created']) ? '<div><dt>New accounts</dt><dd>' . (int) $sm['accounts_created'] . '</dd></div>' : '') . (!empty($sm['categories_created']) ? '<div><dt>New categories</dt><dd>' . (int) $sm['categories_created'] . '</dd></div>' : '')
        . (!empty($sm['x_bills']) ? '<div><dt>Bills added</dt><dd>' . count($sm['x_bills']) . '</dd></div>' : '') . (!empty($sm['x_goals']) ? '<div><dt>Goals added</dt><dd>' . count($sm['x_goals']) . '</dd></div>' : '') . (!empty($sm['x_budgets']) ? '<div><dt>Budgets set</dt><dd>' . count($sm['x_budgets']) . '</dd></div>' : '')
        . (!empty($sm['from']) ? '<div><dt>Dates</dt><dd>' . e(date_label($sm['from'])) . ' to ' . e(date_label($sm['to'])) . '</dd></div>' : '')
        . '<div><dt>Balances</dt><dd>' . (($sm['mode'] ?? 'keep') === 'keep' ? 'Unchanged (a balance correction keeps today\'s balance as it was)' : 'Updated by these transactions') . '</dd></div></dl><div class="imp-actions">'
        . ($im['status'] === 'done' && (int) $im['rows_imported'] > 0 ? btn('See these transactions', url('/transactions', ['import' => $im['id']]), 'btn btn-primary', 'search') : '') . btn('Import another', url('/import'), 'btn', 'upload') . btn('Dashboard', url('/'), 'btn btn-ghost') . '</div></section>';
    layout_end();
}

/** <option>s for a category <select>, grouped like the rest of the app. */
function import_cat_options(array $s, bool $income, $selected): string {
    $o = ''; foreach (cat_options($s, $income) as $g) { $o .= '<optgroup label="' . e($g['group']) . '">'; foreach ($g['options'] as $id => $name) { $o .= '<option value="' . (int) $id . '"' . ((string) $selected === (string) $id ? ' selected' : '') . '>' . e($name) . '</option>'; } $o .= '</optgroup>'; }
    return $o;
}
function import_cat_label(array $s, $choice, string $tag): string {
    if ($choice === 'new') { return 'New: ' . $tag; }
    if ($choice === '' || $choice === null) { return 'Uncategorised'; }
    return cat_name($s, (int) $choice);
}

function import_page_preview(array $u, array $s, string $token): void {
    $uid = (int) $u['id']; $pv = import_load_preview($uid, $token);
    if (!$pv) { flash('err', 'This preview was not found or has expired. Upload the file again.'); redirect(url('/import')); }
    if ($pv['import_id']) { redirect(url('/import', ['done' => (int) $pv['import_id']])); }
    $stmt = $pv['stmt'];
    if (!$stmt) { flash('err', 'This preview could not be read. Upload the file again.'); redirect(url('/import')); }
    layout_start('Review import', 'transactions', $s);
    $sheets = '<section class="card imp-card" id="sheets"><h2 class="imp-h">' . icon('layers', 'size-5') . ' Sheets found</h2><ul class="imp-sheets">';
    foreach ($stmt['sheets'] as $sh) {
        $role = ['summary' => ['Summary', 'imp-role--sum'], 'transactions' => ['Transactions', 'imp-role--tx'], 'unknown' => ['Skipped', 'imp-role--no']][$sh['role']] ?? ['Skipped', 'imp-role--no'];
        $sheets .= '<li><span class="imp-sheet__n">' . e($sh['name']) . ($sh['state'] !== 'visible' ? ' <i class="imp-hid">' . e($sh['state'] === 'veryHidden' ? 'very hidden' : 'hidden') . '</i>' : '') . '</span><span class="imp-role ' . $role[1] . '">' . e($role[0]) . '</span><span class="imp-sheet__d">' . e($sh['note']) . '</span></li>';
    }
    $sheets .= '</ul></section>'; if (empty($stmt['sheets'])) { $sheets = ''; }   // a JSON import has no sheets: no empty card
    if (empty($stmt['source'])) {
        page_header('We could not recognise this file', 'Nothing was imported.', btn('Try another file', url('/import'), 'btn', 'upload'));
        echo '<section class="card imp-card"><p class="imp-p">The file was read, but none of its sheets looks like a statement we know. Supported today: <b>' . e(implode(', ', array_map(fn($a) => $a['title'] ?? $a['label'], import_adapters()))) . '</b>. '
            . 'A CSV with the headings Date, Time, Transaction Details, Your Account, Amount, UPI Ref No., Tags also works.</p></section>' . $sheets;
        layout_end(); return;
    }
    $ch = import_default_choices($s, $stmt); $plan = import_plan($uid, $stmt, $ch);
    $ag = import_account_groups($stmt); $tg = import_tag_groups($stmt); $rec = import_reconcile($stmt);
    $n = count($stmt['rows']); $dup = count(array_filter($plan, fn($p) => $p['dup'])); $ready = count(array_filter($plan, fn($p) => $p['include'] && !$p['dup']));
    $dates = array_column($stmt['rows'], 'date'); sort($dates); $paid = $recv = 0; $paidN = $recvN = 0; $hints = 0;
    foreach ($stmt['rows'] as $r) { if (($r['xt'] ?? '') === 'transfer') { continue; } if ($r['dir'] === 'out') { $paid += $r['amount']; $paidN++; } else { $recv += $r['amount']; $recvN++; } if ($r['hint']) { $hints++; } }
    $label = $stmt['label']; $isJson = $stmt['source'] === 'json';
    page_header($isJson ? 'Review your JSON import' : 'Review your ' . $label . ' statement', 'Nothing has been added yet. Check the numbers, choose where things go, then confirm.', btn('Cancel', url('/import'), 'btn btn-ghost'));
    // ---- overview ----
    echo '<section class="card imp-card imp-over"><div class="imp-over__top">' . import_badge(['source' => $stmt['source']], 'src', true) . '<span class="imp-file">' . e($pv['filename']) . '</span></div><dl class="imp-tiles">'
        . ($stmt['holder'] ? '<div><dt>Account holder</dt><dd>' . e($stmt['holder']) . '</dd></div>' : '')
        . '<div><dt>Statement period</dt><dd>' . e($stmt['period']['text'] ?? ($dates ? date_short($dates[0]) . ' to ' . date_label(end($dates)) : '—')) . '</dd></div>'
        . '<div><dt>Transactions found</dt><dd>' . $n . '</dd></div><div><dt>Dates in the rows</dt><dd>' . ($dates ? e(date_short($dates[0])) . ' to ' . e(date_label(end($dates))) : '—') . '</dd></div>'
        . '<div><dt>Money paid</dt><dd class="num">' . e(fmt($paid)) . ' <small>' . $paidN . ' payments</small></dd></div><div><dt>Money received</dt><dd class="num">' . e(fmt($recv)) . ' <small>' . $recvN . ' receipts</small></dd></div>'
        . '<div class="' . ($dup ? 'is-dup' : '') . '"><dt>Already imported before</dt><dd>' . $dup . ($dup ? ' <small>will be skipped</small>' : ' <small>none</small>') . '</dd></div><div><dt>Ready to import</dt><dd id="imp-ready-top">' . $ready . '</dd></div></dl></section>';
    echo $sheets;
    // ---- reconciliation ----
    if ($isJson) { echo import_json_checks_html($stmt, $plan, $token); }
    elseif ($rec === null) {
        echo '<section class="card imp-card" id="reconcile"><h2 class="imp-h">' . icon('info', 'size-5') . ' Double-check</h2><p class="imp-p">This file has no Summary sheet, so the rows cannot be compared with the statement\'s own totals. Look through the list below before importing.</p></section>';
    } else {
        $bad = array_filter($rec, fn($l) => !$l['ok']);
        $money = fn($l, $v) => $l['kind'] === 'money' ? fmt($v) : (string) $v;
        echo '<section class="card imp-card imp-rec ' . ($bad ? 'is-warn' : 'is-ok') . '" id="reconcile"><h2 class="imp-h">' . icon($bad ? 'triangle-alert' : 'circle-check', 'size-5') . ($bad ? ' Some totals do not match the statement' : ' Matches the statement\'s own totals') . '</h2>'
            . '<p class="imp-p">' . ($bad ? 'The rows we read differ from the Summary sheet. The Summary leaves out payments between your own accounts, so differences can be harmless, but please look before importing.' : 'Every count and amount we read equals the Summary sheet, in total and for each account.') . '</p>'
            . '<div class="imp-tablewrap"><table class="imp-table"><thead><tr><th>Item</th><th class="r">Statement says</th><th class="r">We read</th><th class="r">Result</th></tr></thead><tbody>';
        foreach ($rec as $l) {
            $diff = $l['actual'] - $l['expected'];
            echo '<tr class="' . ($l['ok'] ? '' : 'is-bad') . '"><td>' . e($l['label']) . '</td><td class="r num">' . e($money($l, $l['expected'])) . '</td><td class="r num">' . e($money($l, $l['actual'])) . '</td><td class="r">'
                . ($l['ok'] ? '<span class="imp-ok">' . icon('check', 'size-4') . ' Equal</span>' : '<span class="imp-warn">' . icon('triangle-alert', 'size-4') . ' ' . ($diff > 0 ? '+' : '−') . e($money($l, abs($diff))) . '</span>') . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
    if ($stmt['ignored']) {
        echo '<section class="card imp-card"><h2 class="imp-h">' . icon('info', 'size-5') . ' Rows ignored</h2><p class="imp-p">These rows are not transactions (totals, notes or blank-looking lines) and were skipped:</p><ul class="imp-ign">';
        foreach (array_slice($stmt['ignored'], 0, 6) as $ig) { echo '<li><b>' . e($ig['sheet']) . ' row ' . (int) $ig['row'] . '</b> ' . e($ig['why']) . ' · <span>' . e($ig['text']) . '</span></li>'; }
        echo '</ul></section>';
    }
    // ---- the form ----
    $accOpts = acct_options($s); $openByAcc = array_column($s['accounts'], 'opening_date', 'id');
    echo form_open('import-commit', 'imp-form', false) . '<input type="hidden" name="token" value="' . e($token) . '"><input type="hidden" name="rowedits" value="" data-imp-rowedits id="imp-form-edits">';
    // accounts
    echo '<section class="card imp-card" id="accounts"><h2 class="imp-h">' . icon('landmark', 'size-5') . ' Where should each account go?</h2><p class="imp-p">The statement names the bank accounts the payments came from. Pick one of yours, or let a new account be created.</p><div class="imp-maps">';
    foreach ($ag as $i => $g) {
        $def = $ch['acc'][$i]; $old = 0;
        if ($def !== 'new') { foreach ($stmt['rows'] as $r) { if ($r['account'] === $g['name'] && $r['date'] < ($openByAcc[$def] ?? '0000')) { $old++; } } }
        echo '<div class="imp-map" data-imp-accrow="' . $i . '"><div class="imp-map__src"><b>' . e($g['name'] !== '' ? $g['name'] : '(no account named)') . '</b><small>' . $g['rows'] . ' transactions · ' . e(date_short($g['min'])) . ' to ' . e(date_label($g['max'])) . ' · paid ' . e(fmt($g['out'])) . ($g['in'] ? ' · received ' . e(fmt($g['in'])) : '') . '</small></div>'
            . '<div class="imp-map__dst"><label class="sr-only" for="imp-acc-' . $i . '">Account for ' . e($g['name']) . '</label><select class="input" id="imp-acc-' . $i . '" name="acc[' . $i . ']" data-imp-acc="' . $i . '">'
            . '<option value="new"' . ($def === 'new' ? ' selected' : '') . '>Create new bank account “' . e($ch['accname'][$i]) . '”</option>';
        foreach ($accOpts as $id => $nm) { echo '<option value="' . (int) $id . '"' . ((string) $def === (string) $id ? ' selected' : '') . '>' . e($nm) . '</option>'; }
        echo '</select><div class="imp-newacc" data-imp-newacc' . ($def === 'new' ? '' : ' hidden') . '><label class="label" for="imp-an-' . $i . '">Name of the new account</label><input class="input" id="imp-an-' . $i . '" name="accname[' . $i . ']" maxlength="60" value="' . e($ch['accname'][$i]) . '">'
            . '<label class="label" for="imp-bal-' . $i . '" data-imp-ballabel>Balance today (optional)</label><input class="input num" id="imp-bal-' . $i . '" name="bal[' . $i . ']" inputmode="decimal" placeholder="0.00" autocomplete="off"></div>'
            . ($old ? '<p class="imp-note" data-imp-old>' . $old . ' of these rows are dated before the account\'s opening date, so they will not change its balance.</p>' : '') . '</div></div>';
    }
    echo '</div></section>';
    // balances
    echo '<section class="card imp-card" id="balances"><h2 class="imp-h">' . icon('wallet', 'size-5') . ' What should happen to your balances?</h2><div class="imp-modes">'
        . '<label class="imp-mode"><input type="radio" name="mode" value="keep" checked><span><b>Keep my balances as they are</b><small>Recommended when the account already shows what the bank shows. The history is added for reports and analysis, and a balance correction (shown as a correction, not income or expense) keeps today\'s balance unchanged.</small></span></label>'
        . '<label class="imp-mode"><input type="radio" name="mode" value="reflect"><span><b>Let these transactions change my balances</b><small>Use this when the account does not include these payments yet. Existing accounts go up and down by the imported amounts; new accounts start at zero on the first date.</small></span></label></div></section>';
    if ($isJson) { echo import_json_extras_html($stmt); }
    // categories
    echo '<section class="card imp-card" id="categories"><h2 class="imp-h">' . icon('layers', 'size-5') . ' Categories</h2><p class="imp-p">Each tag from the statement is matched to one of your categories by name. Change a match, or create a category from the tag. Money coming in becomes <b>income</b>, money going out becomes an <b>expense</b>; cashback counts as income.</p><div class="imp-maps">';
    foreach ($tg as $i => $g) {
        $inc = $g['dir'] === 'in'; $def = $ch['cat'][$i];
        echo '<div class="imp-map"><div class="imp-map__src"><b>' . ($g['tag_raw'] !== '' ? e($g['tag_raw']) : 'No tag') . '</b><small>' . ($inc ? 'Income' : 'Expense') . ' · ' . $g['rows'] . ' transaction' . ($g['rows'] === 1 ? '' : 's') . ' · ' . e(fmt($g['total'])) . '</small></div>'
            . '<div class="imp-map__dst"><label class="sr-only" for="imp-cat-' . $i . '">Category for ' . e($g['tag_raw']) . '</label><select class="input" id="imp-cat-' . $i . '" name="cat[' . $i . ']" data-imp-cat="' . $i . '">'
            . ($g['tag'] !== '' ? '<option value="new"' . ($def === 'new' ? ' selected' : '') . '>Create category “' . e($g['tag']) . '”</option>' : '') . '<option value=""' . ($def === '' ? ' selected' : '') . '>Uncategorised</option>' . import_cat_options($s, $inc, $def) . '</select></div></div>';
    }
    echo '</div></section>';
    // rows
    echo '<section class="card imp-card" id="rows"><h2 class="imp-h">' . icon('list-checks', 'size-5') . ' Transactions</h2><p class="imp-p">Untick anything you do not want. Tap a category or “Add remark” to change a single row.</p>'
        . '<div class="imp-filter" role="search"><label class="sr-only" for="imp-q">Search these transactions</label><input class="input" id="imp-q" type="search" placeholder="Search payee, UPI id, tag…" data-imp-q>'
        . '<label class="sr-only" for="imp-f-dir">Direction</label><select class="input" id="imp-f-dir" data-imp-fdir><option value="">Paid and received</option><option value="out">Money paid</option><option value="in">Money received</option></select>'
        . '<label class="sr-only" for="imp-f-show">Show</label><select class="input" id="imp-f-show" data-imp-fshow><option value="">All rows</option><option value="todo">Will be imported</option><option value="off">Left out</option>' . ($dup ? '<option value="dup">Already imported</option>' : '') . ($hints ? '<option value="hint">Maybe own transfers</option>' : '') . '</select>'
        . (count($ag) > 1 ? '<label class="sr-only" for="imp-f-acc">Account</label><select class="input" id="imp-f-acc" data-imp-facc><option value="">All accounts</option>' . implode('', array_map(fn($g, $i) => '<option value="' . $i . '">' . e($g['name']) . '</option>', $ag, array_keys($ag))) . '</select>' : '')
        . '<div class="imp-filter__b"><button type="button" class="btn btn-sm" data-imp-all>Tick shown</button><button type="button" class="btn btn-sm" data-imp-none>Untick shown</button></div></div>'
        . '<p class="imp-count" data-imp-shown aria-live="polite"></p>'
        . '<select hidden id="imp-tpl-out"><option value="__map">Use the tag\'s category</option><option value="">Uncategorised</option>' . import_cat_options($s, false, null) . '</select><select hidden id="imp-tpl-in"><option value="__map">Use the tag\'s category</option><option value="">Uncategorised</option>' . import_cat_options($s, true, null) . '</select>'
        . '<ul class="imp-rows" data-imp-rows>';
    foreach ($plan as $p) {
        $r = $p['row']; $q = mb_strtolower(implode(' ', [$r['payee'], $r['details'], $r['other'], $r['tag'], $r['account'], $r['ref'], $r['comment'], fmt($r['amount'])]));
        $hint = $r['hint'] ? (isset($r['hint']['pair']) ? 'Matches payment #' . $r['hint']['pair'] . ' in your other account: probably money moved between your own accounts.' : 'Sent to or from you: possibly a transfer between your own accounts.') : '';
        $tr = ($r['xt'] ?? '') === 'transfer';
        echo '<li class="imp-row' . ($p['dup'] ? ' is-dup' : '') . ($p['blocked'] ? ' is-blocked' : '') . '" data-n="' . (int) $r['n'] . '" data-ti="' . $p['ti'] . '" data-dir="' . ($tr ? 'tr' : $r['dir']) . '" data-cg="' . e($r['cgroup'] ?? $r['dir']) . '" data-ai="' . $p['ai'] . '" data-amt="' . ($tr ? 0 : $r['amount']) . '" data-blocked="' . (int) $p['blocked'] . '" data-dup="' . (int) $p['dup'] . '" data-hint="' . ($hint !== '' ? 1 : 0) . '" data-q="' . e($q) . '">'
            . '<label class="imp-chk"><input type="checkbox" data-imp-inc aria-label="Import ' . e($r['payee']) . '"' . ($p['dup'] || $p['blocked'] ? ' disabled' : ' checked') . '><i></i></label>'
            . '<div class="imp-when"><b>' . e(date_label($r['date'])) . '</b><span>' . e($r['time'] ? substr($r['time'], 0, 5) : '') . '</span></div>'
            . '<div class="imp-who"><span class="imp-payee">' . e($r['payee'] !== '' ? $r['payee'] : '(no name)') . '</span><span class="imp-sub">' . e($r['other']) . '</span>'
            . ($p['dup'] ? '<span class="imp-tag imp-tag--dup">Already imported</span>' : '') . import_json_row_chips($r) . ($hint !== '' ? '<span class="imp-tag imp-tag--hint" title="' . e($hint) . '">Maybe own transfer</span>' : '')
            . '<div class="imp-remarkrow"><button type="button" class="imp-link" data-imp-remark' . ($p['remarks'] !== '' ? ' hidden' : '') . '>' . icon('message-square', 'size-3') . ' Add remark</button><input class="input imp-rem" type="text" maxlength="500" value="' . e($p['remarks']) . '" data-orig="' . e($p['remarks']) . '" placeholder="Remark" aria-label="Remark for ' . e($r['payee']) . '"' . ($p['remarks'] !== '' ? '' : ' hidden') . '></div></div>'
            . '<div class="imp-acc">' . e($r['account']) . ($tr ? ' → ' . e($r['to_account']) : '') . '</div>'
            . '<div class="imp-cat">' . ($tr ? '<span class="imp-tr">Transfer</span>' : '<button type="button" class="imp-catbtn" data-imp-catbtn aria-label="Change category">' . '<span data-imp-catlabel>' . e(import_cat_label($s, $p['cat'], $r['tag'])) . '</span>' . icon('chevron-down', 'size-3') . '</button>') . '</div>'
            . '<div class="imp-amt ' . ($tr ? 'tr' : $r['dir']) . '">' . ($tr ? '⇄' : ($r['dir'] === 'in' ? '+' : '−')) . e(fmt($r['amount'])) . '</div></li>';
    }
    echo '</ul></section>';
    echo '<div class="imp-bar" role="region" aria-label="Confirm import"><div class="imp-bar__t"><b data-imp-n>' . $ready . '</b> to import<span data-imp-extra></span></div><button type="submit" class="btn btn-primary" data-imp-go>' . icon('check', 'size-4') . ' <span data-imp-golabel>Import ' . $ready . ' transactions</span></button></div></form>';
    echo '<form method="post" action="' . e(url('/do/import-discard')) . '" class="imp-discard">' . csrf_field() . '<input type="hidden" name="_back" value="' . e(url('/import')) . '"><input type="hidden" name="token" value="' . e($token) . '"><button class="btn btn-ghost btn-sm">Discard this preview</button></form>';
    layout_end();
}
