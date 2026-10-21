<?php
// Regression: a config.php from before the payment settings existed must still work. Run by tests/run.php in a child process.
define('MFNC', true); define('DB_HOST', getenv('MFNC_DB_HOST') ?: '127.0.0.1'); define('DB_PORT', (int) (getenv('MFNC_DB_PORT') ?: 3306)); define('DB_NAME', getenv('MFNC_DB_NAME')); define('DB_USER', getenv('MFNC_DB_USER') ?: 'root'); define('DB_PASS', getenv('MFNC_DB_PASS') ?: '');
define('TABLE_SUFFIX', '_mfnt'); define('APP_NAME', 'Moms Finance'); define('APP_TIMEZONE', 'Asia/Kolkata'); define('APP_DEBUG', true); define('SESSION_NAME', 'x'); define('FORCE_HTTPS_COOKIES', null);
require dirname(__DIR__) . '/app/bootstrap.php';
echo (!payu_configured() && PAYU_BASE_URL === '' && APP_URL === '' && billing_cfg()['enabled'] !== null) ? "OK" : "BAD";
