<?php
declare(strict_types=1);
if (!defined('MFNC')) { http_response_code(403); exit; }

// A config.php written for an older version may lack newer settings. Give every setting a safe default so an upgrade never crashes.
foreach ([
    'TABLE_SUFFIX' => '_mfnc', 'APP_NAME' => 'Moms Finance', 'APP_TIMEZONE' => 'Asia/Kolkata', 'APP_DEBUG' => (bool) getenv('MFNC_DEBUG'), 'SESSION_NAME' => 'mfnc_session', 'FORCE_HTTPS_COOKIES' => null,
    'APP_URL' => getenv('MFNC_APP_URL') ?: '', 'PAYU_BASE_URL' => rtrim((string) (getenv('PAYU_BASE_URL') ?: ''), '/'), 'PAYU_API_TOKEN' => (string) (getenv('PAYU_API_TOKEN') ?: ''), 'PAYU_WEBHOOK_SECRET' => (string) (getenv('PAYU_WEBHOOK_SECRET') ?: ''),
] as $k => $v) { defined($k) || define($k, $v); }

mb_internal_encoding('UTF-8');
date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

foreach (['db', 'schema', 'helpers', 'engine', 'services', 'snapshot', 'auth', 'webauthn', 'billing', 'ext', 'notify', 'ai_calc', 'ai', 'ai_chat', 'telegram', 'telegram_bot', 'telegram_ai', 'telegram_charts', 'telegram_actions', 'shop', 'ai_files', 'ui', 'charts', 'mobile'] as $f) {
    require_once __DIR__ . "/$f.php";
}

mfnc_install();   // creates database + tables on first run, upgrades when SCHEMA_VERSION changes
