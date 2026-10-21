<?php
declare(strict_types=1);
/**
 * Minimal, defensive spreadsheet reader (no Composer, no PhpSpreadsheet): .xlsx via ZipArchive + XMLReader, and .csv.
 * Everything is read in memory under hard limits; nothing is ever extracted to disk, formulas are never evaluated (cached values only),
 * external entities / DTDs are refused, and archive entry names that try to escape (.., absolute, backslash, NUL) reject the whole file.
 *
 * workbook_load($path, $name) => ['kind' => 'xlsx'|'csv', 'date1904' => bool, 'sheets' => [sheet, ...]]
 * sheet = ['name' => string, 'state' => 'visible'|'hidden'|'veryHidden', 'kind' => 'worksheet'|'other', 'rows' => [rowNo => [colNo => scalar|XlsxDate]], 'max_col' => int, 'truncated' => bool]
 * Row and column numbers are 1-based and sparse (only non-empty cells are present).
 */

class XlsxError extends UserError {}

const XLSX_MAX_BYTES = 8 * 1024 * 1024;          // the file itself
const XLSX_MAX_ENTRIES = 300;                    // files inside the archive
const XLSX_MAX_PART = 24 * 1024 * 1024;          // one decompressed XML part
const XLSX_MAX_TOTAL = 64 * 1024 * 1024;         // all parts together (declared and actually read)
const XLSX_MAX_RATIO = 400;                      // declared uncompressed / compressed size of one part (zip-bomb guard)
const XLSX_MAX_ROWS = 50000;
const XLSX_MAX_COLS = 200;
const XLSX_MAX_CELLS = 400000;
const XLSX_MAX_STRINGS = 300000;
const XLSX_MAX_SHEETS = 60;

/** A cell holding a date (and maybe a time). Prints as Y-m-d or Y-m-d H:i:s. */
final class XlsxDate {
    public function __construct(public string $date, public ?string $time, public float $serial) {}
    public function __toString(): string { return $this->time !== null ? $this->date . ' ' . $this->time : $this->date; }
}

/** Entry point: picks the reader from the file's first bytes, never from its name alone. */
function workbook_load(string $path, string $origName = ''): array {
    $size = @filesize($path);
    if ($size === false || $size === 0) { throw new XlsxError('The file is empty.'); }
    if ($size > XLSX_MAX_BYTES) { throw new XlsxError('The file is too large (max ' . (XLSX_MAX_BYTES / 1048576) . ' MB).'); }
    $fh = @fopen($path, 'rb'); if (!$fh) { throw new XlsxError('The file could not be read.'); }
    $head = (string) fread($fh, 4096); fclose($fh);
    if (str_starts_with($head, "PK\x03\x04")) { return xlsx_read($path); }
    if (str_starts_with($head, "PK\x05\x06") || str_starts_with($head, "PK\x07\x08")) { throw new XlsxError('That is an empty or damaged zip archive, not a spreadsheet.'); }
    if (str_starts_with($head, "\xD0\xCF\x11\xE0")) { throw new XlsxError('That is an old Excel (.xls) file. Open it and save it as .xlsx or .csv, then upload it again.'); }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $binary = strpos($head, "\0") !== false || preg_match('/^(%PDF|\x89PNG|GIF8|\xFF\xD8\xFF|MZ|\x7FELF|Rar!)/', $head);
    if ($binary) { throw new XlsxError('That file is not a spreadsheet. Upload the .xlsx statement (or a .csv).'); }
    if (in_array($ext, ['csv', 'txt', ''], true)) { return csv_read($path); }
    throw new XlsxError('That file is not a spreadsheet. Upload the .xlsx statement (or a .csv).');
}

// ============================ CSV ============================
function csv_read(string $path): array {
    $raw = (string) file_get_contents($path, false, null, 0, XLSX_MAX_BYTES + 1);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) { $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'); }
    $first = (string) strtok($raw, "\n"); $best = ','; $bn = 0;
    foreach ([',', ';', "\t", '|'] as $d) { $c = substr_count($first, $d); if ($c > $bn) { $bn = $c; $best = $d; } }
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
    $rows = []; $n = 0; $cells = 0; $maxCol = 0;
    while (($r = fgetcsv($fh, 0, $best, '"', '')) !== false) {
        $n++; if ($n > XLSX_MAX_ROWS) { throw new XlsxError('The file has too many rows (max ' . XLSX_MAX_ROWS . ').'); }
        if (count($r) === 1 && ($r[0] === null || trim((string) $r[0]) === '')) { continue; }
        $row = []; foreach ($r as $i => $v) { $v = trim((string) $v); if ($v === '' || $i >= XLSX_MAX_COLS) { continue; } $row[$i + 1] = $v; $maxCol = max($maxCol, $i + 1); $cells++; }
        if ($cells > XLSX_MAX_CELLS) { throw new XlsxError('The file has too many cells.'); }
        if ($row) { $rows[$n] = $row; }
    }
    fclose($fh);
    return ['kind' => 'csv', 'date1904' => false, 'sheets' => [['name' => 'CSV', 'state' => 'visible', 'kind' => 'worksheet', 'rows' => $rows, 'max_col' => $maxCol, 'truncated' => false]]];
}

// ============================ XLSX ============================
/** Reads one archive part completely (bounded) or null when it does not exist. */
function xlsx_part(ZipArchive $zip, string $name, int &$budget, int $max = XLSX_MAX_PART): ?string {
    $idx = $zip->locateName($name, ZipArchive::FL_NOCASE);
    if ($idx === false) { return null; }
    $st = $zip->statIndex($idx);
    if ($st === false || $st['size'] > $max) { throw new XlsxError('A part of the workbook is too large to read safely.'); }
    $fp = $zip->getStream($zip->getNameIndex($idx)); if (!$fp) { return null; }
    $data = stream_get_contents($fp, $max + 1); fclose($fp);
    if ($data === false || strlen($data) > $max) { throw new XlsxError('A part of the workbook is too large to read safely.'); }
    $budget -= strlen($data);
    if ($budget < 0) { throw new XlsxError('The workbook expands to too much data.'); }
    xlsx_xml_guard($data, $name);
    return $data;
}
/** No DTDs or entity declarations in spreadsheet XML: this refuses billion-laughs and external-entity tricks outright. */
function xlsx_xml_guard(string $xml, string $name): void {
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) { throw new XlsxError('The workbook contains unsafe XML (' . basename($name) . ') and was refused.'); }
}
function xlsx_reader(string $xml): XMLReader {
    $r = new XMLReader();
    if (!$r->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR)) { throw new XlsxError('The workbook XML could not be read.'); }
    return $r;
}
/** Resolves a relationship target against its source folder; null when it would leave the package. */
function xlsx_resolve(string $base, string $target): ?string {
    $target = str_replace('\\', '/', $target);
    $p = str_starts_with($target, '/') ? ltrim($target, '/') : rtrim($base, '/') . '/' . $target;
    $out = [];
    foreach (explode('/', $p) as $seg) { if ($seg === '' || $seg === '.') { continue; } if ($seg === '..') { if (!$out) { return null; } array_pop($out); continue; } $out[] = $seg; }
    return $out ? implode('/', $out) : null;
}
function xlsx_col_index(string $letters): int {
    $n = 0; foreach (str_split(strtoupper($letters)) as $ch) { $n = $n * 26 + (ord($ch) - 64); if ($n > 16384) { return 0; } } return $n;
}
/** Text of an <si> / <is>: all <t> runs, phonetic runs (rPh) skipped. The reader must be on the opening element. */
function xlsx_read_rich(XMLReader $r): string {
    if ($r->isEmptyElement) { return ''; }
    $d0 = $r->depth; $out = ''; $ok = $r->read();
    while ($ok) {
        if ($r->nodeType === XMLReader::END_ELEMENT && $r->depth === $d0) { break; }
        if ($r->nodeType === XMLReader::ELEMENT) {
            if ($r->localName === 'rPh') { $ok = $r->next(); continue; }
            if ($r->localName === 't') { $out .= $r->readString(); }
        }
        $ok = $r->read();
    }
    return $out;
}
function xlsx_builtin_date_fmt(int $id): bool { return ($id >= 14 && $id <= 22) || ($id >= 27 && $id <= 36) || ($id >= 45 && $id <= 47) || ($id >= 50 && $id <= 58); }
function xlsx_custom_date_fmt(string $code): bool {
    $c = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]|_.|\*.|@/u', '', $code) ?? $code;   // quoted text, escapes, [Red] / [$-409] blocks, padding
    return (bool) preg_match('/[ymdhs]/i', $c) && !preg_match('/^general$/i', trim($c));
}
/** Style index => true when the cell format is a date/time. Streams styles.xml (real files can have thousands of fonts). */
function xlsx_date_styles(?string $xml): array {
    if ($xml === null) { return []; }
    $r = xlsx_reader($xml); $custom = []; $isDate = []; $inXfs = false; $i = 0;
    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT) {
            if ($r->localName === 'numFmt') { $custom[(int) $r->getAttribute('numFmtId')] = (string) $r->getAttribute('formatCode'); }
            elseif ($r->localName === 'cellXfs') { $inXfs = true; if ($r->isEmptyElement) { $inXfs = false; } }
            elseif ($inXfs && $r->localName === 'xf') { $id = (int) $r->getAttribute('numFmtId'); $isDate[$i++] = isset($custom[$id]) ? xlsx_custom_date_fmt($custom[$id]) : xlsx_builtin_date_fmt($id); }
        } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'cellXfs') { $inXfs = false; }
    }
    $r->close();
    return $isDate;
}
function xlsx_serial_to_date(float $serial, bool $d1904): ?XlsxDate {
    if ($serial < 0 || $serial >= 2958466) { return null; }
    $days = (int) floor($serial); $secs = (int) round(($serial - $days) * 86400);
    if ($secs >= 86400) { $days++; $secs -= 86400; }
    if (!$d1904 && $days < 61) { $days += ($days > 0 ? 1 : 0); }   // the fictitious 1900-02-29
    $base = new DateTimeImmutable($d1904 ? '1904-01-01 00:00:00' : '1899-12-30 00:00:00', new DateTimeZone('UTC'));
    $dt = $base->modify('+' . $days . ' days')->modify('+' . $secs . ' seconds');
    return new XlsxDate($dt->format('Y-m-d'), $secs > 0 ? $dt->format('H:i:s') : null, $serial);
}
function xlsx_shared_strings(?string $xml): array {
    if ($xml === null) { return []; }
    $r = xlsx_reader($xml); $out = [];
    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
            $out[] = xlsx_read_rich($r);
            if (count($out) > XLSX_MAX_STRINGS) { throw new XlsxError('The workbook has too many distinct text values.'); }
        }
    }
    $r->close();
    return $out;
}

function xlsx_read(string $path): array {
    $zip = new ZipArchive();
    $rc = $zip->open($path, ZipArchive::RDONLY);
    if ($rc !== true) { throw new XlsxError('That file is not a valid .xlsx workbook (it is not a readable zip archive).'); }
    try {
        $n = $zip->numFiles;
        if ($n < 3 || $n > XLSX_MAX_ENTRIES) { throw new XlsxError($n > XLSX_MAX_ENTRIES ? 'The workbook contains too many files.' : 'That file is not a valid .xlsx workbook.'); }
        $declared = 0;
        for ($i = 0; $i < $n; $i++) {
            $st = $zip->statIndex($i); if ($st === false) { throw new XlsxError('The workbook archive is damaged.'); }
            $nm = (string) $st['name'];
            if ($nm === '' || str_contains($nm, "\0") || str_contains($nm, '\\') || str_starts_with($nm, '/') || preg_match('~(^|/)\.\.(/|$)~', $nm) || preg_match('~^[A-Za-z]:~', $nm)) { throw new XlsxError('The workbook contains unsafe file names and was refused.'); }
            $declared += (int) $st['size'];
            if ($declared > XLSX_MAX_TOTAL * 4 || (int) $st['size'] > XLSX_MAX_PART * 2) { throw new XlsxError('The workbook expands to far too much data and was refused.'); }
            if ((int) $st['comp_size'] > 0 && $st['size'] / $st['comp_size'] > XLSX_MAX_RATIO && $st['size'] > 1048576) { throw new XlsxError('The workbook looks like a compression bomb and was refused.'); }
        }
        $budget = XLSX_MAX_TOTAL;
        $ct = xlsx_part($zip, '[Content_Types].xml', $budget, 262144);
        if ($ct === null || !str_contains($ct, 'spreadsheetml')) { throw new XlsxError('That file is a zip archive but not an Excel workbook (.xlsx).'); }
        $wbXml = xlsx_part($zip, 'xl/workbook.xml', $budget, 1048576);
        $relXml = xlsx_part($zip, 'xl/_rels/workbook.xml.rels', $budget, 1048576);
        if ($wbXml === null || $relXml === null) { throw new XlsxError('That file is a zip archive but not an Excel workbook (.xlsx).'); }

        // relationships: id => [target, type]
        $rels = []; $r = xlsx_reader($relXml);
        while ($r->read()) { if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship') { $rels[(string) $r->getAttribute('Id')] = [(string) $r->getAttribute('Target'), (string) $r->getAttribute('Type'), (string) $r->getAttribute('TargetMode')]; } }
        $r->close();
        // sheets in workbook order
        $sheetsMeta = []; $d1904 = false; $r = xlsx_reader($wbXml);
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT) { continue; }
            if ($r->localName === 'workbookPr') { $v = strtolower((string) $r->getAttribute('date1904')); $d1904 = $v === '1' || $v === 'true'; }
            elseif ($r->localName === 'sheet') {
                $rid = (string) ($r->getAttribute('r:id') ?? $r->getAttribute('id') ?? ''); $state = (string) $r->getAttribute('state');
                $sheetsMeta[] = ['name' => mb_substr((string) $r->getAttribute('name'), 0, 120), 'state' => in_array($state, ['hidden', 'veryHidden'], true) ? $state : 'visible', 'rid' => $rid];
                if (count($sheetsMeta) > XLSX_MAX_SHEETS) { throw new XlsxError('The workbook has too many sheets.'); }
            }
        }
        $r->close();
        if (!$sheetsMeta) { throw new XlsxError('The workbook has no sheets.'); }

        $dateStyles = xlsx_date_styles(xlsx_part($zip, 'xl/styles.xml', $budget));
        $strings = xlsx_shared_strings(xlsx_part($zip, 'xl/sharedStrings.xml', $budget));
        $cellBudget = XLSX_MAX_CELLS; $sheets = [];
        foreach ($sheetsMeta as $m) {
            $rel = $rels[$m['rid']] ?? null; $sheet = $m + ['kind' => 'other', 'rows' => [], 'max_col' => 0, 'truncated' => false];
            $target = $rel && $rel[2] !== 'External' ? xlsx_resolve('xl', $rel[0]) : null;
            if ($target !== null && str_ends_with($rel[1], '/worksheet')) {
                $xml = xlsx_part($zip, $target, $budget);
                if ($xml !== null) { $sheet['kind'] = 'worksheet'; xlsx_read_sheet($xml, $strings, $dateStyles, $d1904, $sheet, $cellBudget); }
            }
            unset($sheet['rid']); $sheets[] = $sheet;
        }
        return ['kind' => 'xlsx', 'date1904' => $d1904, 'sheets' => $sheets];
    } finally { $zip->close(); }
}

function xlsx_read_sheet(string $xml, array $strings, array $dateStyles, bool $d1904, array &$sheet, int &$cellBudget): void {
    $r = xlsx_reader($xml); $rowNo = 0; $rows = []; $maxCol = 0; $nRows = 0;
    while ($r->read()) {
        if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'row') { continue; }
        $ra = (int) $r->getAttribute('r'); $rowNo = $ra > 0 ? $ra : $rowNo + 1;
        if (++$nRows > XLSX_MAX_ROWS || $rowNo > 1048576) { throw new XlsxError('The sheet "' . $sheet['name'] . '" has too many rows (max ' . XLSX_MAX_ROWS . ').'); }
        if ($r->isEmptyElement) { continue; }
        $rd = $r->depth; $colNo = 0; $cells = [];
        $ok = $r->read();
        while ($ok) {
            if ($r->nodeType === XMLReader::END_ELEMENT && $r->depth === $rd) { break; }
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'c') {
                $ref = (string) $r->getAttribute('r'); $t = (string) $r->getAttribute('t'); $s = $r->getAttribute('s');
                if ($ref !== '' && preg_match('/^([A-Za-z]{1,3})\d+$/', $ref, $mm)) { $colNo = xlsx_col_index($mm[1]); } else { $colNo++; }
                $v = null; $inline = null;
                if (!$r->isEmptyElement) {
                    $cd = $r->depth; $ok2 = $r->read();
                    while ($ok2) {
                        if ($r->nodeType === XMLReader::END_ELEMENT && $r->depth === $cd) { break; }
                        if ($r->nodeType === XMLReader::ELEMENT) {
                            if ($r->localName === 'v') { $v = $r->readString(); }
                            elseif ($r->localName === 'is') { $inline = xlsx_read_rich($r); }
                            elseif ($r->localName === 'f') { $ok2 = $r->next(); continue; }   // formulas are never evaluated; the cached <v> is used
                        }
                        $ok2 = $r->read();
                    }
                }
                $val = xlsx_cell_value($t, $v, $inline, $s === null ? null : (int) $s, $strings, $dateStyles, $d1904);
                if ($val !== null && $val !== '') {
                    if ($colNo < 1 || $colNo > XLSX_MAX_COLS) { $sheet['truncated'] = true; }
                    else {
                        if (--$cellBudget < 0) { throw new XlsxError('The workbook has too many cells.'); }
                        $cells[$colNo] = $val; $maxCol = max($maxCol, $colNo);
                    }
                }
            }
            $ok = $r->read();
        }
        if ($cells) { ksort($cells); $rows[$rowNo] = $cells; }
    }
    $r->close();
    ksort($rows); $sheet['rows'] = $rows; $sheet['max_col'] = $maxCol;
}

/** One cell's PHP value: string, int, float, bool, XlsxDate or null. */
function xlsx_cell_value(string $t, ?string $v, ?string $inline, ?int $style, array $strings, array $dateStyles, bool $d1904) {
    switch ($t) {
        case 'inlineStr': return $inline !== null ? $inline : ($v ?? null);
        case 's': return $v !== null && ctype_digit(trim($v)) ? ($strings[(int) $v] ?? null) : null;
        case 'str': return $v;
        case 'b': return $v === null ? null : trim($v) === '1';
        case 'e': return null;
        case 'd': { $v = trim((string) $v); if ($v === '') { return null; } try { $dt = new DateTimeImmutable($v); return new XlsxDate($dt->format('Y-m-d'), str_contains($v, 'T') || str_contains($v, ':') ? $dt->format('H:i:s') : null, 0.0); } catch (Throwable) { return $v; } }
        default:
            if ($v === null || trim($v) === '') { return $inline; }
            $v = trim($v); if (!is_numeric($v)) { return $v; }
            if ($style !== null && !empty($dateStyles[$style])) { $d = xlsx_serial_to_date((float) $v, $d1904); if ($d) { return $d; } }
            return preg_match('/^-?\d{1,15}$/', $v) ? (int) $v : (float) $v;
    }
}

/** Cell text for matching: strings trimmed, numbers without a spurious ".0", dates as Y-m-d. */
function xlsx_text($v): string {
    if ($v === null) { return ''; }
    if ($v instanceof XlsxDate) { return (string) $v; }
    if (is_bool($v)) { return $v ? 'TRUE' : 'FALSE'; }
    if (is_float($v)) { return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.'); }
    return trim((string) $v);
}
