<?php
declare(strict_types=1);

function current_user(): ?array {
    if (array_key_exists('__user', $GLOBALS)) { return $GLOBALS['__user']; }
    start_session();
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $u = one('SELECT id,email,name,role,status FROM {users} WHERE id=?', [(int) $_SESSION['uid']]);
        if (!$u || $u['status'] !== 'active') { $u = null; unset($_SESSION['uid']); }
    }
    return $GLOBALS['__user'] = $u;
}
function require_login(): array {
    $u = current_user();
    if (!$u) { redirect(url('/login')); }
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') { http_response_code(403); render_error(403, 'Admins only', 'You do not have access to this area.'); exit; }
    return $u;
}
function login_user(int $id): void {
    start_session(); session_regenerate_id(true);
    $_SESSION['uid'] = $id; $_SESSION['csrf'] = bin2hex(random_bytes(32));
    run('UPDATE {users} SET last_login_at = UTC_TIMESTAMP() WHERE id=?', [$id]);
    unset($GLOBALS['__user']);
}
function logout_user(): void {
    start_session(); $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']); }
    session_destroy(); unset($GLOBALS['__user']);
}
/** Returns user id or throws UserError. Throttles by email (5/15min) and IP (30/15min). */
function attempt_login(string $email, string $password): int {
    $email = strtolower(trim($email)); $since = time() - 900; $ip = client_ip();
    run('DELETE FROM {login_attempts} WHERE at_ts < ?', [$since]);
    if ((int) val('SELECT COUNT(*) FROM {login_attempts} WHERE email=? AND at_ts>=?', [$email, $since]) >= 5 || (int) val('SELECT COUNT(*) FROM {login_attempts} WHERE ip=? AND at_ts>=?', [$ip, $since]) >= 30) {
        throw new UserError('Too many failed attempts. Please wait 15 minutes and try again.');
    }
    $u = one('SELECT id,password_hash,status FROM {users} WHERE email=?', [$email]);
    $ok = password_verify($password, $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
    if (!$u || !$ok) { run('INSERT INTO {login_attempts} (email, ip, at_ts) VALUES (?,?,?)', [$email, $ip, time()]); throw new UserError('Incorrect email or password.'); }
    if ($u['status'] !== 'active') { throw new UserError('This account has been disabled. Contact the administrator.'); }
    run('DELETE FROM {login_attempts} WHERE email=?', [$email]);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) { run('UPDATE {users} SET password_hash=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]); }
    return (int) $u['id'];
}
function snap(): array {
    if (!isset($GLOBALS['__snap'])) {
        $u = require_login();
        $s = load_snapshot((int) $u['id']);
        sync_notifications($s);
        $GLOBALS['__snap'] = $s;
    }
    return $GLOBALS['__snap'];
}
