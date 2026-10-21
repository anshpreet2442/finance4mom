<?php
declare(strict_types=1);
/**
 * Self-contained test runner (no Composer / PHPUnit needed):
 *   MFNC_DB_PORT=3307 php tests/run.php
 * It uses its own database (momsfinance_test) and table suffix (_mfnt), so real data is never touched.
 * The test tables are dropped and recreated on every run.
 */
@session_start(); // CLI: start the session before any output so session helpers work
putenv('MFNC_DB_NAME=' . (getenv('MFNC_TEST_DB') ?: 'momsfinance_testc'));
putenv('MFNC_TABLE_SUFFIX=_mfnt');
putenv('MFNC_DEBUG=1');
$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['REQUEST_URI'] = '/';

// drop leftovers before the app boots (FK checks off so order does not matter)
define('MFNC', true);
require __DIR__ . '/../app/db.php';
define('DB_HOST', getenv('MFNC_DB_HOST') ?: '127.0.0.1'); define('DB_PORT', (int) (getenv('MFNC_DB_PORT') ?: 3306)); define('DB_NAME', getenv('MFNC_DB_NAME'));
define('DB_USER', getenv('MFNC_DB_USER') ?: 'root'); define('DB_PASS', getenv('MFNC_DB_PASS') ?: ''); define('TABLE_SUFFIX', '_mfnt');
define('APP_NAME', 'Test'); define('APP_TIMEZONE', 'Asia/Kolkata'); define('APP_DEBUG', true); define('SESSION_NAME', 'test'); define('FORCE_HTTPS_COOKIES', false);
define('APP_URL', 'https://finance.test'); define('PAYU_BASE_URL', 'http://127.0.0.1:8099/api/v1'); define('PAYU_API_TOKEN', 'umpi_live_test'); define('PAYU_WEBHOOK_SECRET', 'whsec_test');
$pdo = db(); $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SHOW TABLES LIKE '%\\_mfnt'")->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec("DROP TABLE `$t`"); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
foreach (['schema', 'helpers', 'engine', 'services', 'snapshot', 'auth', 'webauthn', 'billing', 'ext', 'notify', 'ai_calc', 'ai', 'ai_chat', 'telegram', 'telegram_bot', 'telegram_ai', 'telegram_charts', 'telegram_actions', 'shop', 'ai_files', 'xlsx', 'import_adapters', 'import', 'ui', 'charts', 'mobile', 'c_core'] as $f) { require __DIR__ . "/../app/$f.php"; }
mfnc_install(); c_install();
require __DIR__ . '/../app/actions.php'; require __DIR__ . '/../app/actions_ext.php';

// ---- tiny assertion helpers ----
$pass = 0; $fail = 0; $section = '';
function section(string $n): void { global $section; $section = $n; echo "\n$n\n"; }
function check(string $name, bool $ok, string $detail = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  ✓ $name\n"; } else { $fail++; echo "  ✗ $name $detail\n"; } }
function eq($a, $b, string $name): void { check($name, $a === $b, '(got ' . var_export($a, true) . ', expected ' . var_export($b, true) . ')'); }
function throws(callable $fn, string $needle, string $name): void { try { $fn(); check($name, false, '(no exception)'); } catch (Throwable $e) { check($name, stripos($e->getMessage(), $needle) !== false, '(got "' . $e->getMessage() . '")'); } }

// Builder-C tests only: `MFNC_DB_USER=mfnc MFNC_DB_PASS=mfnc php tests/c_run.php` (own database so it can run beside the main suite)
$AIM = null;
require __DIR__ . '/c_tests.php';
echo "\n" . ($fail ? "FAILED: $fail failed, $pass passed\n" : "OK: $pass passed\n");
exit($fail ? 1 : 0);
