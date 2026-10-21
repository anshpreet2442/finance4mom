<?php
declare(strict_types=1);
/**
 * Builder-C plumbing: its own tables (versioned separately from SCHEMA_VERSION so parallel work never collides), the router for its pages and
 * JSON endpoints (c_route, called once from index.php), small helpers and a tiny rate limiter.
 * Features living on top: AI Layout (layout.php, layout_ai.php), voice (voice.php), PDF export (pdf.php), share links (share.php, qr.php), payment page (paypage.php).
 */
const C_SCHEMA_VERSION = 5;
// the feature files (loaded here so bootstrap.php and the test runner only need to know about c_core)
foreach (['qr', 'share', 'pdf', 'voice', 'layout', 'layout_ai', 'paypage', 'ai_guard', 'ai_docs', 'import_fallback', 'import_json'] as $__f) { if (is_file(__DIR__ . "/$__f.php")) { require_once __DIR__ . "/$__f.php"; } }
unset($__f);

function c_schema(): array {
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=' . DB_COLLATION;
    return [
    'c_layouts' => "CREATE TABLE IF NOT EXISTS {c_layouts} (user_id INT UNSIGNED NOT NULL, device ENUM('desktop','mobile') NOT NULL, ver INT UNSIGNED NOT NULL DEFAULT 1, data MEDIUMTEXT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, device), FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE) $t",
    'c_layout_backups' => "CREATE TABLE IF NOT EXISTS {c_layout_backups} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, device ENUM('desktop','mobile') NOT NULL, label VARCHAR(100) NOT NULL DEFAULT '', source VARCHAR(20) NOT NULL DEFAULT 'auto',
        data MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_lb (user_id, device, id), FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE) $t",
    'c_layout_drafts' => "CREATE TABLE IF NOT EXISTS {c_layout_drafts} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, device ENUM('desktop','mobile') NOT NULL, cur MEDIUMTEXT NOT NULL, hist_undo MEDIUMTEXT NOT NULL, hist_redo MEDIUMTEXT NOT NULL,
        brief TEXT NULL, snap MEDIUMTEXT NULL, msgs MEDIUMTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_ld (user_id, id),
        FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE) $t",
    'c_share_links' => "CREATE TABLE IF NOT EXISTS {c_share_links} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL, token_hint VARCHAR(8) NOT NULL DEFAULT '', token_enc VARCHAR(255) NOT NULL DEFAULT '', title VARCHAR(80) NOT NULL, message VARCHAR(400) NOT NULL DEFAULT '',
        widgets VARCHAR(400) NOT NULL DEFAULT '', mask TINYINT(1) NOT NULL DEFAULT 1, password_hash VARCHAR(255) NULL, expires_at DATETIME NULL, max_views INT UNSIGNED NULL, views INT UNSIGNED NOT NULL DEFAULT 0, last_viewed_at DATETIME NULL,
        status ENUM('active','paused','revoked') NOT NULL DEFAULT 'active', style VARCHAR(12) NOT NULL DEFAULT 'tube', color VARCHAR(7) NOT NULL DEFAULT '#0b3d2e', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token_hash), INDEX idx_sl (user_id, id), FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE) $t",
    'c_rate' => "CREATE TABLE IF NOT EXISTS {c_rate} (k VARCHAR(90) PRIMARY KEY, win INT UNSIGNED NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 0) $t",
    'c_voice_requests' => "CREATE TABLE IF NOT EXISTS {c_voice_requests} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, status ENUM('new','in_progress','done','refused') NOT NULL DEFAULT 'new', fee_micro BIGINT NOT NULL DEFAULT 0, note VARCHAR(500) NOT NULL DEFAULT '',
        admin_note VARCHAR(500) NOT NULL DEFAULT '', ref VARCHAR(60) NOT NULL DEFAULT '', refunded TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_vr_ref (ref), INDEX idx_vr (status, id), FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE) $t",
    'c_voice_usage' => "CREATE TABLE IF NOT EXISTS {c_voice_usage} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, kind ENUM('tts','stt') NOT NULL, chars INT UNSIGNED NOT NULL DEFAULT 0, seconds INT UNSIGNED NOT NULL DEFAULT 0, cost_micro BIGINT NOT NULL DEFAULT 0,
        provider VARCHAR(16) NOT NULL DEFAULT 'elevenlabs', source VARCHAR(16) NOT NULL DEFAULT 'web', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_vu (user_id, created_at), FOREIGN KEY (user_id) REFERENCES {users}(id) ON DELETE CASCADE) $t",
    'c_pay_tpl' => "CREATE TABLE IF NOT EXISTS {c_pay_tpl} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, html MEDIUMTEXT NOT NULL, note VARCHAR(120) NOT NULL DEFAULT '', created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) $t",
    ];
}
/** Creates the Builder-C tables once per C_SCHEMA_VERSION (cheap meta lookup on every request). */
function c_install(): void {
    try { $v = val('SELECT v FROM {meta} WHERE k = ?', ['schema_c']); if ($v !== null && (int) $v >= C_SCHEMA_VERSION) { return; } } catch (PDOException $e) { if ($e->getCode() !== '42S02') { throw $e; } return; }
    foreach (c_schema() as $sql) { db()->exec(sqlt($sql)); }
    if (!column_exists('c_share_links', 'token_enc')) { db()->exec(sqlt("ALTER TABLE {c_share_links} ADD COLUMN token_enc VARCHAR(255) NOT NULL DEFAULT '' AFTER token_hint")); }
    try { db()->exec(sqlt("ALTER TABLE {c_voice_requests} MODIFY ref VARCHAR(60) NOT NULL DEFAULT ''")); } catch (PDOException) {}
    run('INSERT INTO {meta} (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ['schema_c', (string) C_SCHEMA_VERSION]);
}

// ============================ helpers ============================
/** header()/status that stay quiet when output already started (tests, CLI) and remember the last status for tests. */
function c_hdr(string $h): void { if (!headers_sent()) { header($h); } }
function c_session_close(): void { if (PHP_SAPI !== 'cli') { session_write_close(); } }
function c_status(int $code): void { $GLOBALS['__c_status'] = $code; if (!headers_sent()) { http_response_code($code); } }
function c_json(array $data, int $code = 200): never { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION); exit; }
/** Signed-in, CSRF-checked JSON request. Returns [$user, $body]. Exits with a JSON error otherwise. */
function c_json_guard(bool $stream = false, int $maxBody = 60000): array {
    $u = current_user(); $fail = function (int $code, string $msg) use ($stream) { http_response_code($code); header('Content-Type: ' . ($stream ? 'application/x-ndjson' : 'application/json') . '; charset=utf-8'); echo json_encode(['type' => 'error', 'ok' => false, 'message' => $msg]) . ($stream ? "\n" : ''); exit; };
    if (!$u) { $fail(401, 'Sign in again.'); }
    start_session(); $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''); if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) { $fail(403, 'Your page expired. Reload and try again.'); }
    if (billing_level($u) >= 2) { $fail(403, 'The app is read-only until your dues are paid.'); }
    $body = json_decode((string) file_get_contents('php://input', false, null, 0, $maxBody), true); if (!is_array($body)) { $body = []; }
    c_session_close(); header('Cache-Control: no-store');
    if ($stream) { header('Content-Type: application/x-ndjson; charset=utf-8'); header('X-Accel-Buffering: no'); }
    return [$u, $body];
}
/** Fixed-window rate limit in the database. Returns true when the call is allowed. */
function c_rate_ok(string $key, int $max, int $windowSec): bool {
    $key = mb_substr($key, 0, 90); $win = intdiv(time(), max(1, $windowSec));
    run('INSERT INTO {c_rate} (k, win, hits) VALUES (?,?,1) ON DUPLICATE KEY UPDATE hits = IF(win = VALUES(win), hits + 1, 1), win = VALUES(win)', [$key, $win]);
    if (random_int(1, 200) === 1) { run('DELETE FROM {c_rate} WHERE win < ?', [intdiv(time(), max(1, $windowSec)) - 2]); }
    return (int) val('SELECT hits FROM {c_rate} WHERE k=?', [$key]) <= $max;
}
function c_hmac(string $data): string { return hash_hmac('sha256', $data, secret_key()); }
function c_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function c_b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/'), true); }
/** Token with `bits` of randomness, URL safe. */
function c_token(int $bytes = 18): string { return c_b64u(random_bytes($bytes)); }
function c_is_mobile_ua(): bool { return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile|Opera Mini/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')); }

// ============================ router ============================
/** Dispatches the Builder-C pages and JSON endpoints. Returns when the path is not one of ours. Called from index.php before form posts. */
function c_route(string $path): void {
    $m = method();
    // public share page (no login, no CSRF; read only)
    if (preg_match('~^/s/([A-Za-z0-9_-]{16,64})(?:/(og\.png|qr\.svg))?$~', $path, $x)) { share_public($x[1], $x[2] ?? ''); exit; }
    if (preg_match('~^/pdf/shared/([A-Za-z0-9_.-]{20,400})$~', $path, $x)) { pdf_shared_download($x[1]); exit; }
    if ($m === 'POST') {
        if ($path === '/layout/api') { layout_api(); }
        if ($path === '/layout/ai') { layout_ai_http(); }
        if ($path === '/voice/tts') { voice_http_tts(); }
        if ($path === '/voice/stt') { voice_http_stt(); }
        if ($path === '/admin/payment-page/preview') { paypage_preview_http(); }
        return;
    }
    if ($m !== 'GET' && $m !== 'HEAD') { return; }
    $pages = ['/layout' => 'page_layout', '/layout/preview' => 'page_layout_preview', '/layout/wire.png' => 'layout_wire_png', '/layout/wire.svg' => 'layout_wire_png', '/share' => 'page_share', '/share/qr' => 'share_qr_http', '/share/new' => 'page_share_new', '/voice/status' => 'voice_http_status',
        '/pdf/report' => 'pdf_http_report', '/pdf/analysis' => 'pdf_http_analysis', '/admin/voice' => 'page_admin_voice', '/admin/payment-page' => 'page_admin_paypage', '/voice/audio' => 'voice_http_audio', '/pay/page' => 'paypage_http_public'];
    if (!isset($pages[$path])) { return; }
    if (($u = current_user()) && billing_level($u) === 3 && !str_starts_with($path, '/admin')) { redirect(url('/dues')); }
    $pages[$path](); exit;
}
