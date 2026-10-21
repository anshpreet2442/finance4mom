<?php
// Test-only front router: `php -S 127.0.0.1:PORT tests/apache_sim.php`.
// It stands in for what sits in front of index.php on the real shared host:
//   1. .htaccess `Header` rules (always set / setifempty / append) applied to the response the way mod_headers does, so duplicate or overridden headers show up;
//   2. a hosting WAF (Imunify360 / ModSecurity style). SIM_WAF=1 refuses multipart/form-data uploads that contain a zip ("PK\x03\x04");
//      SIM_WAF=2 also refuses any body that contains the base64 of a zip ("UEsD") or its reversed form; SIM_WAF=3 also refuses JSON/urlencoded bodies that carry "sheets" (client-parsed rows).
//   3. static files and the "everything else goes to index.php" rewrite, like the project's .htaccess.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$root = dirname(__DIR__);
$f = $root . $p;
if ($p !== '/' && is_file($f) && !str_ends_with($f, '.php') && !preg_match('#^/(app|tools|tests)/#', $p)) { return false; }

$level = (int) (getenv('SIM_WAF') ?: 0);
if ($level > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? ''); $hit = false;
    if (str_contains($ct, 'multipart/form-data')) { foreach ($_FILES as $fl) { foreach ((array) ($fl['tmp_name'] ?? []) as $t) { if (is_string($t) && is_file($t) && str_starts_with((string) file_get_contents($t, false, null, 0, 4), "PK\x03\x04")) { $hit = true; } } } }
    if ($level >= 2) {
        $body = (string) file_get_contents('php://input'); $all = $body . ' ' . http_build_query($_POST);
        if (str_contains($all, 'UEsD') || str_contains($all, 'DsEU')) { $hit = true; }
        if ($level >= 3 && (str_contains($all, '%22sheets%22') || str_contains($all, '"sheets"') || str_contains($all, 'sheets'))) { $hit = true; }
    }
    if ($hit) { http_response_code(403); header('Content-Type: text/html'); echo "<html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Access to this resource on the server is denied!</p></body></html>"; return true; }
}

$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['PHP_SELF'] = '/index.php';
ob_start();
register_shutdown_function(function () use ($root) {
    if (headers_sent()) { return; }
    $file = getenv('SIM_HTACCESS') ?: $root . '/.htaccess'; $in = false; $files = 0;
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        $line = trim($line);
        if (stripos($line, '<FilesMatch') === 0) { $files++; continue; } if (stripos($line, '</FilesMatch') === 0) { $files--; continue; }
        if ($files > 0) { continue; }   // these only match css/js/svg files, never index.php
        if (!preg_match('/^Header\s+(?:always\s+)?(set|setifempty|append|unset|merge)\s+(\S+)(?:\s+"?(.*?)"?)?\s*$/i', $line, $m)) { continue; }
        [$op, $name, $val] = [strtolower($m[1]), $m[2], $m[3] ?? ''];
        $present = false; foreach (headers_list() as $h) { if (stripos($h, $name . ':') === 0) { $present = true; } }
        if ($op === 'set') { header("$name: $val", true); }
        elseif ($op === 'setifempty') { if (!$present) { header("$name: $val", true); } }
        elseif ($op === 'append' || $op === 'merge') { header("$name: $val", false); }
        elseif ($op === 'unset') { header_remove($name); }
    }
});
require $root . '/index.php';
