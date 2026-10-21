<?php
// For `php -S localhost:8000 router.php` (development). Apache/Nginx use .htaccess / nginx rules instead.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$f = __DIR__ . $p;
if ($p !== '/' && is_file($f) && !str_ends_with($f, '.php') && !preg_match('#^/(app|tools)/#', $p)) { return false; }
require __DIR__ . '/index.php';
