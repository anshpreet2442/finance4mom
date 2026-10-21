<?php
// Sends bill reminders in Telegram to people who opted in. Run once a day from cron, e.g.:
//   0 8 * * *  php /path/to/tools/telegram_cron.php
// Set APP_URL in config.php (or MFNC_APP_URL) so the links in the messages are correct.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config.php';
echo tg_send_due_reminders() . " reminder(s) sent\n";
