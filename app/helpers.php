<?php
declare(strict_types=1);

// ============================ output / urls ============================
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function base_path(): string {
    static $b = null;
    if ($b === null) { $d = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')); $b = $d === '/' || $d === '.' ? '' : rtrim($d, '/'); }
    return $b;
}
/** Pretty URL (no .php). */
function url(string $path = '/', array $query = []): string {
    $u = base_path() . ($path === '' ? '/' : $path);
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    return $u . ($query ? '?' . http_build_query($query) : '');
}
function asset(string $p): string { $f = __DIR__ . '/../assets/' . $p; return base_path() . '/assets/' . $p . (is_file($f) ? '?v=' . filemtime($f) : ''); }
function request_path(): string {
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $p = rawurldecode($p);
    $b = base_path();
    if ($b !== '' && str_starts_with($p, $b)) { $p = substr($p, strlen($b)); }
    $p = '/' . trim($p, '/');
    return $p;
}
function redirect(string $to): never {
    if (!preg_match('~^(/|https?://)~', $to)) { $to = url('/'); }
    if (is_spa()) { header('X-Mfnc-Redirect: ' . $to); http_response_code(204); exit; } // the in-page router follows it itself (and sends the browser off-site for gateways)
    header('Location: ' . $to, true, 303);
    exit;
}
/** Only same-site relative paths are allowed for "return to" targets. */
function safe_back(?string $b): string {
    if ($b && $b[0] === '/' && !str_starts_with($b, '//') && !str_contains($b, '\\')) { return $b; }
    return url('/');
}
/** True for requests made by the single-page router (assets/spa.js). */
function is_spa(): bool { return ($_SERVER['HTTP_X_MFNC_SPA'] ?? '') === '1'; }
function current_url(): string { return $_SERVER['REQUEST_URI'] ?? url('/'); }
function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

// ============================ session / csrf / flash ============================
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $https = FORCE_HTTPS_COOKIES ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name(SESSION_NAME);
    session_set_cookie_params(['lifetime' => 0, 'path' => base_path() ?: '/', 'secure' => (bool) $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 14));
    session_start();
}
function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_ok(): bool { start_session(); $t = $_POST['_csrf'] ?? ''; return is_string($t) && $t !== '' && hash_equals($_SESSION['csrf'] ?? '', $t); }
function flash(string $type, string $msg, ?string $title = null): void { start_session(); $_SESSION['flash'][] = $title === null ? [$type, $msg] : [$type, $msg, $title]; }
function take_flash(): array { start_session(); $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function client_ip(): string { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

// ============================ request input ============================
function in_str(string $k, int $max = 200, bool $required = true, string $label = ''): string {
    $v = $_POST[$k] ?? '';
    $v = is_string($v) ? trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', str_replace(["\r\n", "\r"], "\n", $v))) : ''; // no control characters; line breaks only survive in multi-line fields
    $label = $label ?: ucfirst(str_replace('_', ' ', $k));
    if ($v === '' && $required) { throw new UserError("$label is required."); }
    if (mb_strlen($v) > $max) { throw new UserError("$label is too long (max $max characters)."); }
    return $v;
}
/** A single-line text field: line breaks and tabs become spaces (names, titles, descriptions end up in emails, SMS and headers). */
function in_line(string $k, int $max = 200, bool $required = true, string $label = ''): string { $_POST[$k] = is_string($_POST[$k] ?? null) ? preg_replace('/\s+/u', ' ', $_POST[$k]) : ''; return in_str($k, $max, $required, $label); }
function in_opt(string $k, int $max = 500): ?string { $v = in_str($k, $max, false); return $v === '' ? null : $v; }
function in_id(string $k, string $label = ''): int {
    $v = $_POST[$k] ?? ''; $label = $label ?: ucfirst(str_replace('_', ' ', $k));
    if (!is_string($v) || !ctype_digit($v) || (int) $v < 1) { throw new UserError("Choose a valid " . strtolower($label) . "."); }
    return (int) $v;
}
function in_opt_id(string $k): ?int { $v = $_POST[$k] ?? ''; return is_string($v) && ctype_digit($v) && (int) $v > 0 ? (int) $v : null; }
function in_date(string $k, string $label = ''): string {
    $v = in_str($k, 10, true, $label ?: 'Date');
    if (!valid_date($v)) { throw new UserError(($label ?: 'Date') . ' is not a valid date.'); }
    return $v;
}
function in_opt_date(string $k): ?string { $v = in_str($k, 10, false); if ($v === '') { return null; } if (!valid_date($v)) { throw new UserError('Enter a valid date.'); } return $v; }
function in_enum(string $k, array $allowed, ?string $default = null): string {
    $v = $_POST[$k] ?? $default;
    if (!is_string($v) || !in_array($v, $allowed, true)) { throw new UserError('Invalid ' . str_replace('_', ' ', $k) . '.'); }
    return $v;
}
function in_bool(string $k): bool { return isset($_POST[$k]) && in_array($_POST[$k], ['1', 'on', 'true'], true); }
function in_int(string $k, int $min, int $max, ?int $default = null): int {
    $v = $_POST[$k] ?? ($default !== null ? (string) $default : '');
    if (!is_string($v) || !preg_match('/^-?\d+$/', $v) || (int) $v < $min || (int) $v > $max) { throw new UserError(ucfirst(str_replace('_', ' ', $k)) . " must be between $min and $max."); }
    return (int) $v;
}
function in_money(string $k, bool $required = true, bool $allowNegative = false, bool $allowZero = false, string $label = ''): int {
    $v = $_POST[$k] ?? '';
    $label = $label ?: 'Amount';
    if (!is_string($v) || trim($v) === '') { if ($required) { throw new UserError("$label is required."); } return 0; }
    $m = parse_money($v, $allowNegative);
    if ($m === null) { throw new UserError("$label is not a valid amount."); }
    if ($m === 0 && !$allowZero && $required) { throw new UserError("$label must be greater than zero."); }
    if ($m < 0 && !$allowNegative) { throw new UserError("$label cannot be negative."); }
    return $m;
}
function get_str(string $k, int $max = 100): string { $v = $_GET[$k] ?? ''; return is_string($v) ? mb_substr(trim($v), 0, $max) : ''; }

// ============================ money ============================
const CURRENCIES = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'AUD' => 'A$', 'CAD' => 'C$', 'SGD' => 'S$'];
const LOCALES = ['en-IN' => 'English (India) · 1,25,000', 'en-US' => 'English (US) · 125,000', 'en-GB' => 'English (UK) · 125,000', 'en-AU' => 'English (Australia)', 'en-CA' => 'English (Canada)', 'de-DE' => 'Deutsch · 125.000,00', 'fr-FR' => 'Français · 125 000,00'];

/** Parse "1,25,000.50" / "₹ 500" into integer minor units without floating point. null if invalid. */
function parse_money(string $s, bool $allowNegative = false): ?int {
    $s = trim(str_replace([' ', '₹', '$', '€', '£', "\u{00A0}"], '', $s));
    // locales that write 1.234,50: the comma is the decimal mark (anywhere else every comma is a thousands separator)
    if (function_exists('prefs') && in_array(prefs()['locale'] ?? '', ['de-DE', 'fr-FR'], true) && preg_match('/^-?(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$/', $s)) { $s = str_replace(',', '.', str_replace('.', '', $s)); }
    else { $s = str_replace(',', '', $s); }
    $neg = false;
    if ($s !== '' && $s[0] === '-') { if (!$allowNegative) { return null; } $neg = true; $s = substr($s, 1); }
    if (!preg_match('/^\d+(\.\d{0,2})?$/', $s)) { return null; }
    [$w, $f] = array_pad(explode('.', $s), 2, '');
    if (strlen($w) > 13) { return null; }
    $m = (int) $w * 100 + (int) substr($f . '00', 0, 2);
    return $neg ? -$m : $m;
}
/** Plain decimal for form inputs: 12550 => "125.5". */
function money_input(int $minor): string {
    $neg = $minor < 0; $a = abs($minor); $w = intdiv($a, 100); $f = $a % 100;
    $s = $f === 0 ? (string) $w : $w . '.' . rtrim(str_pad((string) $f, 2, '0', STR_PAD_LEFT), '0');
    return ($neg ? '-' : '') . $s;
}
function group_digits(string $int, string $locale): string {
    if ($locale === 'en-IN') {
        if (strlen($int) <= 3) { return $int; }
        $last = substr($int, -3); $rest = substr($int, 0, -3);
        return preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last;
    }
    $sep = match ($locale) { 'de-DE' => '.', 'fr-FR' => "\u{202F}", default => ',' };
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', $sep, $int);
}
function fmt_number(int $minor, ?array $p = null, ?bool $fraction = null): string {
    $p ??= prefs();
    $neg = $minor < 0; $a = abs($minor);
    $int = (string) intdiv($a, 100); $frac = $a % 100;
    $dec = in_array($p['locale'], ['de-DE', 'fr-FR'], true) ? ',' : '.';
    $showFrac = $fraction ?? ($frac !== 0);
    $s = group_digits($int, $p['locale']) . ($showFrac ? $dec . str_pad((string) $frac, 2, '0', STR_PAD_LEFT) : '');
    return ($neg ? '−' : '') . $s;
}
function fmt(int $minor, ?array $p = null): string {
    $p ??= prefs();
    $sym = CURRENCIES[$p['currency']] ?? $p['currency'] . ' ';
    $n = fmt_number(abs($minor), $p);
    $suffix = in_array($p['locale'], ['de-DE', 'fr-FR'], true);
    $s = $suffix ? $n . "\u{00A0}" . trim($sym) : $sym . $n;
    return ($minor < 0 ? '−' : '') . $s;
}
/** Signed with explicit + for inflows. */
function fmt_signed(int $minor, ?array $p = null): string { return ($minor > 0 ? '+' : '') . fmt($minor, $p); }
/** Short form for chart axes: 1.2L / 3.4Cr (en-IN), 1.2k otherwise. Takes minor units. */
function fmt_short(int $minor, ?array $p = null): string {
    $p ??= prefs(); $v = abs($minor) / 100; $sign = $minor < 0 ? '-' : '';
    if ($p['locale'] === 'en-IN') {
        if ($v >= 1e7) { return $sign . rtrim(rtrim(number_format($v / 1e7, 1), '0'), '.') . 'Cr'; }
        if ($v >= 1e5) { return $sign . rtrim(rtrim(number_format($v / 1e5, 1), '0'), '.') . 'L'; }
    } elseif ($v >= 1e6) { return $sign . rtrim(rtrim(number_format($v / 1e6, 1), '0'), '.') . 'M'; }
    if ($v >= 1e3) { return $sign . rtrim(rtrim(number_format($v / 1e3, 1), '0'), '.') . 'k'; }
    return $sign . (string) (int) round($v);
}
function currency_symbol(?array $p = null): string { $p ??= prefs(); return trim(CURRENCIES[$p['currency']] ?? $p['currency']); }
/** Ceil division for plans so they never under-save. */
function ceil_div(int $amount, int $d): int { if ($amount <= 0) { return 0; } if ($d <= 0) { return $amount; } return intdiv($amount + $d - 1, $d); }

// ============================ dates (ISO strings) ============================
const FREQ_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'half-yearly' => 6, 'yearly' => 12];
const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function today(): string { return (new DateTimeImmutable('now'))->format('Y-m-d'); }
function month_of(string $d): string { return substr($d, 0, 7); }
function month_start(string $m): string { return $m . '-01'; }
function days_in_month(string $m): int { return (int) (new DateTimeImmutable($m . '-01'))->format('t'); }
function month_end(string $m): string { return $m . '-' . str_pad((string) days_in_month($m), 2, '0', STR_PAD_LEFT); }
function add_months(string $m, int $n): string {
    [$y, $mm] = array_map('intval', explode('-', $m));
    $idx = $y * 12 + ($mm - 1) + $n;
    return sprintf('%04d-%02d', intdiv($idx, 12), $idx % 12 + 1);
}
function add_months_to_date(string $date, int $n, ?int $anchor = null): string {
    $day = $anchor ?? (int) substr($date, 8, 2);
    $m = add_months(month_of($date), $n);
    return $m . '-' . str_pad((string) min($day, days_in_month($m)), 2, '0', STR_PAD_LEFT);
}
function next_due(string $date, string $freq, ?int $anchor = null): string { return add_months_to_date($date, FREQ_MONTHS[$freq], $anchor); }
function months_between(string $from, string $to): int {
    [$fy, $fm] = array_map('intval', explode('-', $from)); [$ty, $tm] = array_map('intval', explode('-', $to));
    return ($ty - $fy) * 12 + ($tm - $fm);
}
function days_between(string $from, string $to): int {
    return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
}
function day_before(string $d): string { return (new DateTimeImmutable($d))->modify('-1 day')->format('Y-m-d'); }
function day_after_n(string $d, int $n): string { return (new DateTimeImmutable($d))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d'); }
function valid_date(string $s): bool { $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s); return $d !== false && $d->format('Y-m-d') === $s; }
function month_label(string $m, bool $short = false): string {
    [$y, $mm] = explode('-', $m); $n = MONTH_NAMES[(int) $mm - 1];
    return $short ? substr($n, 0, 3) . " '" . substr($y, 2) : $n . ' ' . $y;
}
function date_label(string $d): string { return (int) substr($d, 8, 2) . ' ' . substr(MONTH_NAMES[(int) substr($d, 5, 2) - 1], 0, 3) . ' ' . substr($d, 0, 4); }
function date_short(string $d): string { return (int) substr($d, 8, 2) . ' ' . substr(MONTH_NAMES[(int) substr($d, 5, 2) - 1], 0, 3); }
function months_list(string $end, int $count): array { $o = []; for ($i = 0; $i < $count; $i++) { $o[] = add_months($end, $i - $count + 1); } return $o; }
function month_param(): string { $m = $_GET['month'] ?? ''; return is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : month_of(today()); }

// ============================ misc ============================
function json_attr($v): string { return e(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)); }
function cap(string $s): string { return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
function log_error(Throwable $e): void { error_log('[mfnc] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); }
function site_setting(string $k, string $default = ''): string {
    if (!isset($GLOBALS['__site_settings'])) { try { $GLOBALS['__site_settings'] = array_column(all('SELECT k, v FROM {settings}'), 'v', 'k'); } catch (Throwable) { $GLOBALS['__site_settings'] = []; } }
    return $GLOBALS['__site_settings'][$k] ?? $default;
}
function site_settings_flush(): void { unset($GLOBALS['__site_settings']); }
function audit(string $action, string $target = '', string $detail = ''): void {
    $u = $GLOBALS['__user'] ?? null;
    try { run('INSERT INTO {audit_log} (actor_id, action, target, detail, ip) VALUES (?,?,?,?,?)', [$u['id'] ?? null, $action, mb_substr($target, 0, 190), mb_substr($detail, 0, 500), client_ip()]); } catch (Throwable) {}
}

/** A text cell for a CSV that may be opened in a spreadsheet: values that would run as a formula get a leading apostrophe. Plain negative numbers ("-12.50") stay numbers. */
function csv_safe_cell($v): string { $v = (string) ($v ?? ''); return ($v !== '' && (preg_match('/^[=+@\t\r]/', $v) || (str_starts_with($v, '-') && !preg_match('/^-\d+(\.\d+)?$/', $v)))) ? "'" . $v : $v; }
