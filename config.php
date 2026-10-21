<?php
/**
 * Moms Finance — configuration.
 *
 * Edit the values below (or set the MFNC_* environment variables), upload, and open the site.
 * On the first request this file connects to MySQL, creates the database if your user is allowed to,
 * and creates every table it needs. All tables end with TABLE_SUFFIX, e.g. `users_mfnc`.
 * The first account registered on /register becomes the administrator.
 */

if (!defined('MFNC')) {
    define('MFNC', true);
}

// ---- Database ---------------------------------------------------------------------------------
define('DB_HOST', getenv('MFNC_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('MFNC_DB_PORT') ?: 3306));
define('DB_NAME', getenv('MFNC_DB_NAME') ?: 'momsfinance');
define('DB_USER', getenv('MFNC_DB_USER') ?: 'root');
define('DB_PASS', getenv('MFNC_DB_PASS') !== false ? getenv('MFNC_DB_PASS') : '');
define('TABLE_SUFFIX', getenv('MFNC_TABLE_SUFFIX') ?: '_mfnc');

// ---- Application ------------------------------------------------------------------------------
define('APP_NAME', 'Moms Finance');
define('APP_TIMEZONE', 'Asia/Kolkata');   // used for "today" and month boundaries
define('APP_DEBUG', (bool) getenv('MFNC_DEBUG')); // true shows error details; keep false in production
define('SESSION_NAME', 'mfnc_session');
define('FORCE_HTTPS_COOKIES', null);       // null = auto-detect, true/false to force the Secure cookie flag
define('APP_URL', getenv('MFNC_APP_URL') ?: '');  // public address of this site, e.g. https://finance.example.com (used for payment return links; set it in production)

// ---- PayU payment gateway (Partner API) ---------------------------------------------------------
// Prefer environment variables so the token never lands in a file that could be committed or copied around.
// The API token is only ever used server-side; it is never stored in the database, logged or sent to the browser.
define('PAYU_BASE_URL', rtrim((string) (getenv('PAYU_BASE_URL') ?: ''), '/'));          // e.g. https://YOUR-GATEWAY/api/v1
define('PAYU_API_TOKEN', (string) (getenv('PAYU_API_TOKEN') ?: ''));                    // umpi_live_...
define('PAYU_WEBHOOK_SECRET', (string) (getenv('PAYU_WEBHOOK_SECRET') ?: ''));          // verifies X-UMPI-Signature on webhooks

require_once __DIR__ . '/app/bootstrap.php';   // loads the app and auto-installs / upgrades the tables
