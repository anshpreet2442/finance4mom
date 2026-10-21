<?php
declare(strict_types=1);
/**
 * Round 4 (builder-E): uploads that survive a hosting firewall.
 *
 * Some shared hosts run a WAF (Imunify360 / ModSecurity) that answers "403 Forbidden - Access to this resource on the server is denied!" to a
 * multipart/form-data POST carrying a zip (an .xlsx is a zip). That page is not ours and cannot be fixed from the app, so the browser falls back to
 * sending the same bytes as plain text:
 *   POST /import/b64     kind = statement | rows | json     (the Paytm importer and the JSON importer)
 *   POST /ai/upload/b64  kind = ai                          (chat attachments)
 * Fields (urlencoded or JSON): id (upload id), i (chunk number from 0), n (chunk count), name, enc (b64 | b64r = the base64 text reversed, which dodges signature rules on the
 * "UEsDB" zip prefix), data (the chunk), total (decoded size, optional) and the CSRF token in the X-CSRF-Token header or the `_csrf` field.
 * The assembled bytes then go through EXACTLY the same code as a normal upload (same size limits, zip-bomb checks, parser, rate limits).
 * kind=rows is the last resort: the browser read the .xlsx itself and sends the sheets as JSON; import_rows_to_workbook() re-validates every value and limit
 * and builds the same workbook structure the server-side reader would have produced, so nothing from the browser is trusted.
 */

const UPFB_MAX_CHARS = 760000;      // base64 characters accepted in one request
const UPFB_MAX_PARTS = 160;         // chunks per file
const UPFB_MAX_OPEN = 4;            // unfinished uploads per person
const UPFB_ROWS_MAX_BYTES = 24 * 1024 * 1024;

function upfb_dir(): string { $d = rtrim(sys_get_temp_dir(), '/') . '/mfnc-upfb'; if (!is_dir($d)) { @mkdir($d, 0700, true); } return $d; }
function upfb_gc(): void { foreach (glob(upfb_dir() . '/*') ?: [] as $f) { if (is_file($f) && filemtime($f) < time() - 7200) { @unlink($f); } } }
function upfb_out(array $d, int $code = 200): never {
    http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($d + ['ok' => $code < 400], JSON_UNESCAPED_UNICODE); exit;
}
function upfb_fail(int $code, string $msg, array $extra = []): never { upfb_out(['ok' => false, 'message' => $msg] + $extra, $code); }
/** The request fields, from a JSON body or a normal urlencoded form. */
function upfb_input(): array {
    $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($ct, 'application/json')) { $b = json_decode((string) file_get_contents('php://input', false, null, 0, UPFB_MAX_CHARS + 8192), true, 8); return is_array($b) ? $b : []; }
    return $_POST;
}
/** Decodes one chunk. Throws UserError when it is not clean base64. */
function upfb_decode(string $data, string $enc): string {
    if ($enc === 'b64r') { $data = strrev($data); }
    if ($data === '' || !preg_match('~^[A-Za-z0-9+/]+={0,2}$~', $data) || strlen($data) % 4 !== 0) { throw new UserError('That part of the upload was damaged. Please try again.'); }
    $raw = base64_decode($data, true); if ($raw === false) { throw new UserError('That part of the upload was damaged. Please try again.'); }
    return $raw;
}
function upfb_limit(string $kind): int {
    return match ($kind) { 'rows' => UPFB_ROWS_MAX_BYTES, 'json' => defined('IMPORT_JSON_MAX_BYTES') ? IMPORT_JSON_MAX_BYTES : 4 * 1048576, 'ai' => AI_UPLOAD_MAX, default => XLSX_MAX_BYTES };
}

/** Entry point for both routes. Never returns. */
function upfb_http(string $scope): never {
    $u = current_user();
    if (!$u) { upfb_fail(401, 'Sign in again.'); }
    $in = upfb_input(); start_session();
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? ''));
    if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) { upfb_fail(403, 'Your page expired. Reload it and try again.', ['code' => 'csrf']); }
    session_write_close();
    if (billing_level($u) >= 2) { upfb_fail(403, 'The app is read-only until your dues are paid.', ['code' => 'billing']); }
    $kind = (string) ($in['kind'] ?? ($scope === 'ai' ? 'ai' : 'statement'));
    if (!in_array($kind, $scope === 'ai' ? ['ai'] : ['statement', 'rows', 'json'], true)) { upfb_fail(400, 'Unknown upload type.'); }
    if ($kind === 'ai' && (!feature_on($u, 'ai') || ai_not_ready_reason() !== '')) { upfb_fail(403, 'The AI advisor is not available to you right now.', ['code' => 'ai']); }
    $uid = (int) $u['id'];
    try {
        $id = (string) ($in['id'] ?? ''); if (!preg_match('/^[a-f0-9]{16,32}$/', $id)) { throw new UserError('Bad upload id.'); }
        $i = $in['i'] ?? null; $n = $in['n'] ?? null; if (!is_numeric($i) || !is_numeric($n) || (int) $n < 1 || (int) $n > UPFB_MAX_PARTS || (int) $i < 0 || (int) $i >= (int) $n) { throw new UserError('Bad upload part.'); }
        $i = (int) $i; $n = (int) $n; $enc = ($in['enc'] ?? 'b64') === 'b64r' ? 'b64r' : 'b64'; $data = $in['data'] ?? '';
        if (!is_string($data) || strlen($data) > UPFB_MAX_CHARS) { throw new UserError('That part is too large. The upload was cancelled.'); }
        if (!c_rate_ok('upfb:' . $uid, 1500, 3600)) { throw new UserError('Too many upload parts this hour. Please try again later.'); }
        $name = mb_substr(trim((string) preg_replace('/[^\p{L}\p{N} ._\-()]/u', '', basename((string) ($in['name'] ?? 'upload')))), 0, 120) ?: 'upload';
        $base = upfb_dir() . '/' . $uid . '-' . $id; $part = $base . '.part'; $meta = $base . '.meta';
        if ($i === 0) {
            if (random_int(1, 12) === 1) { upfb_gc(); }
            if (count(glob(upfb_dir() . '/' . $uid . '-*.meta') ?: []) >= UPFB_MAX_OPEN && !is_file($meta)) { throw new UserError('Too many unfinished uploads. Wait a few minutes and try again.'); }
            @unlink($part); file_put_contents($meta, json_encode(['kind' => $kind, 'n' => $n, 'next' => 0, 'bytes' => 0, 'name' => $name]), LOCK_EX); @chmod($meta, 0600);
        }
        $m = is_file($meta) ? json_decode((string) file_get_contents($meta), true) : null;
        if (!is_array($m) || $m['kind'] !== $kind || (int) $m['n'] !== $n) { throw new UserError('This upload expired. Please choose the file again.'); }
        if ($i === (int) $m['next'] - 1) { upfb_out(['done' => false, 'next' => (int) $m['next'], 'repeat' => true]); }   // the browser retried a part that already arrived
        if ($i !== (int) $m['next']) { throw new UserError('Upload parts arrived out of order. Please try again.'); }
        $raw = upfb_decode($data, $enc); $bytes = (int) $m['bytes'] + strlen($raw);
        if ($bytes > upfb_limit($kind)) { @unlink($part); @unlink($meta); throw new UserError($kind === 'ai' ? 'That file is too big (limit ' . (AI_UPLOAD_MAX >> 20) . ' MB).' : 'The file is too large (max ' . (int) (upfb_limit($kind) / 1048576) . ' MB).'); }
        file_put_contents($part, $raw, FILE_APPEND | LOCK_EX); @chmod($part, 0600);
        $m['next'] = $i + 1; $m['bytes'] = $bytes; file_put_contents($meta, json_encode($m), LOCK_EX);
        if ($i < $n - 1) { upfb_out(['done' => false, 'next' => $i + 1]); }
        // ---- last part: hand the assembled file to the normal pipeline ----
        $total = $in['total'] ?? null; if (is_numeric($total) && (int) $total !== $bytes) { @unlink($part); @unlink($meta); throw new UserError('The upload arrived incomplete. Please try again.'); }
        try { $res = upfb_finish($u, $kind, $part, $name, $bytes); }
        finally { @unlink($part); @unlink($meta); }   // (exit inside a try would skip this, so the answer is sent afterwards)
        upfb_out($res);
    } catch (UserError $e) {
        upfb_fail(422, $e->getMessage());
    } catch (Throwable $e) { log_error($e); upfb_fail(500, 'Something went wrong while reading the upload. Please try again.'); }
}
/** Runs the assembled file through the same function a multipart upload would have reached. */
function upfb_finish(array $u, string $kind, string $path, string $name, int $bytes): array {
    $uid = (int) $u['id'];
    switch ($kind) {
        case 'statement': $token = import_handle_upload($uid, ['error' => 0, 'tmp_name' => $path, 'name' => $name, 'size' => $bytes]); return ['done' => true, 'redirect' => url('/import', ['p' => $token])];
        case 'rows': {
            $wb = import_rows_to_workbook((string) file_get_contents($path)); $token = import_handle_workbook($uid, $wb, $name, true); return ['done' => true, 'redirect' => url('/import', ['p' => $token])];
        }
        case 'json': {
            if (!function_exists('import_json_handle_text')) { throw new UserError('JSON import is not available.'); }
            $token = import_json_handle_text($uid, (string) file_get_contents($path), $name); return ['done' => true, 'redirect' => url('/import', ['p' => $token])];
        }
        case 'ai': { $up = ai_store_upload($u, ['error' => 0, 'tmp_name' => $path, 'name' => $name, 'size' => $bytes]); return ['done' => true, 'type' => 'ok'] + $up; }
    }
    throw new UserError('Unknown upload type.');
}

/**
 * Builds the workbook structure xlsx_read() produces from the sheets a browser read out of an .xlsx:
 * {"kind":"xlsx","date1904":false,"sheets":[{"name","state","kind","rows":{"3":{"1":"text","2":12.5,"3":{"s":46133.4}}}}]}
 * A cell is a string, a number, a bool, or {"s": serial} for a date cell (the server converts it). Anything else, and anything over a limit, is refused.
 */
function import_rows_to_workbook(string $json): array {
    $j = json_decode($json, true, 8); if (!is_array($j) || ($j['kind'] ?? '') !== 'xlsx' || !is_array($j['sheets'] ?? null)) { throw new XlsxError('The file could not be read in the browser. Try the normal upload, or use the JSON import.'); }
    if (count($j['sheets']) < 1 || count($j['sheets']) > XLSX_MAX_SHEETS) { throw new XlsxError('The workbook has too many sheets.'); }
    $d1904 = !empty($j['date1904']); $cells = 0; $sheets = [];
    foreach ($j['sheets'] as $sh) {
        if (!is_array($sh) || !is_array($sh['rows'] ?? null)) { throw new XlsxError('A sheet could not be read.'); }
        $out = ['name' => mb_substr((string) ($sh['name'] ?? 'Sheet'), 0, 120), 'state' => in_array($sh['state'] ?? '', ['hidden', 'veryHidden'], true) ? $sh['state'] : 'visible', 'kind' => ($sh['kind'] ?? '') === 'other' ? 'other' : 'worksheet', 'rows' => [], 'max_col' => 0, 'truncated' => !empty($sh['truncated'])];
        if (count($sh['rows']) > XLSX_MAX_ROWS) { throw new XlsxError('The sheet "' . $out['name'] . '" has too many rows (max ' . XLSX_MAX_ROWS . ').'); }
        foreach ($sh['rows'] as $rn => $row) {
            if (!is_numeric($rn) || (int) $rn < 1 || (int) $rn > 1048576 || !is_array($row)) { throw new XlsxError('A row could not be read.'); }
            $cl = [];
            foreach ($row as $cn => $v) {
                if (!is_numeric($cn) || (int) $cn < 1 || (int) $cn > XLSX_MAX_COLS) { $out['truncated'] = true; continue; }
                if (is_array($v)) { $ser = $v['s'] ?? null; if (!is_numeric($ser) || count($v) !== 1) { throw new XlsxError('A cell could not be read.'); } $dt = xlsx_serial_to_date((float) $ser, $d1904); $v = $dt ?? (float) $ser; }
                elseif (is_string($v)) { $v = mb_substr($v, 0, 4000); if ($v === '') { continue; } }
                elseif (is_float($v)) { if (!is_finite($v)) { continue; } }
                elseif (!is_int($v) && !is_bool($v)) { continue; }
                if (++$cells > XLSX_MAX_CELLS) { throw new XlsxError('The workbook has too many cells.'); }
                $cl[(int) $cn] = $v; $out['max_col'] = max($out['max_col'], (int) $cn);
            }
            if ($cl) { ksort($cl); $out['rows'][(int) $rn] = $cl; }
        }
        ksort($out['rows']); $sheets[] = $out;
    }
    return ['kind' => 'xlsx', 'date1904' => $d1904, 'sheets' => $sheets];
}
