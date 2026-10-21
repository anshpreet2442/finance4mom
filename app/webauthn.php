<?php
declare(strict_types=1);
/**
 * Passkeys (WebAuthn level 2) without external libraries.
 *  - registration: attestation "none" is requested; whatever format comes back, the credential public key is parsed from authenticator data
 *    (attestation statements are not verified: consumer passkeys do not need it, and we never promise device provenance)
 *  - sign-in: the signature is verified over authenticatorData || SHA-256(clientDataJSON) with the stored key (ES256 or RS256),
 *    the challenge is single-use and expires in 5 minutes, origin and RP-ID hash must match, user verification is required,
 *    and a sign counter that goes backwards is treated as a cloned credential.
 */
const WA_ALGS = [-7 => 'ES256', -257 => 'RS256'];

function b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); if ($r === false) { throw new UserError('Malformed passkey data.'); } return $r; }

// ---------------- CBOR (the subset WebAuthn uses) ----------------
function cbor_decode(string $d, int &$o = 0, int $depth = 0) {
    if ($depth > 8 || $o >= strlen($d)) { throw new UserError('Malformed passkey data.'); }
    $ib = ord($d[$o++]); $mt = $ib >> 5; $ai = $ib & 31;
    $len = match (true) { $ai < 24 => $ai, $ai === 24 => cbor_uint($d, $o, 1), $ai === 25 => cbor_uint($d, $o, 2), $ai === 26 => cbor_uint($d, $o, 4), $ai === 27 => cbor_uint($d, $o, 8), default => throw new UserError('Unsupported passkey data.') };
    switch ($mt) {
        case 0: return $len;
        case 1: return -1 - $len;
        case 2: case 3: if ($len > 65536 || $o + $len > strlen($d)) { throw new UserError('Malformed passkey data.'); } $s = substr($d, $o, $len); $o += $len; return $s;
        case 4: if ($len > 64) { throw new UserError('Malformed passkey data.'); } $a = []; for ($i = 0; $i < $len; $i++) { $a[] = cbor_decode($d, $o, $depth + 1); } return $a;
        case 5: if ($len > 64) { throw new UserError('Malformed passkey data.'); } $m = []; for ($i = 0; $i < $len; $i++) { $k = cbor_decode($d, $o, $depth + 1); $m[$k] = cbor_decode($d, $o, $depth + 1); } return $m;
        case 7: return match ($ai) { 20 => false, 21 => true, 22, 23 => null, default => throw new UserError('Unsupported passkey data.') };
    }
    throw new UserError('Unsupported passkey data.');
}
function cbor_uint(string $d, int &$o, int $n): int { if ($o + $n > strlen($d)) { throw new UserError('Malformed passkey data.'); } $v = 0; for ($i = 0; $i < $n; $i++) { $v = ($v << 8) | ord($d[$o++]); } return $v; }

// ---------------- keys ----------------
function der_len(int $n): string { return $n < 128 ? chr($n) : ($n < 256 ? "\x81" . chr($n) : "\x82" . chr($n >> 8) . chr($n & 255)); }
function der(int $tag, string $body): string { return chr($tag) . der_len(strlen($body)) . $body; }
function der_int(string $bin): string { $bin = ltrim($bin, "\x00"); if ($bin === '' || (ord($bin[0]) & 0x80)) { $bin = "\x00" . $bin; } return der(0x02, $bin); }
function pem_from_der(string $spki): string { return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n"; }
/** COSE_Key map -> [pem, alg]. Supports ES256 (P-256) and RS256. */
function cose_to_pem(array $k): array {
    $alg = $k[3] ?? null; $kty = $k[1] ?? null;
    if ($kty === 2 && ($alg === -7 || $alg === null) && ($k[-1] ?? null) === 1 && strlen((string) ($k[-2] ?? '')) === 32 && strlen((string) ($k[-3] ?? '')) === 32) {
        return [pem_from_der(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3]), -7];
    }
    if ($kty === 3 && ($alg === -257 || $alg === null) && is_string($k[-1] ?? null) && is_string($k[-2] ?? null) && strlen($k[-1]) >= 256) {
        $rsa = der(0x30, der_int($k[-1]) . der_int($k[-2])); $algId = der(0x30, hex2bin('06092a864886f70d010101') . "\x05\x00");
        return [pem_from_der(der(0x30, $algId . der(0x03, "\x00" . $rsa))), -257];
    }
    throw new UserError('This passkey uses an algorithm that is not supported. Try another device or provider.');
}

// ---------------- relying party ----------------
function webauthn_rp(): array {
    if (APP_URL !== '') { $u = parse_url(APP_URL); $scheme = $u['scheme'] ?? 'https'; $host = strtolower($u['host'] ?? 'localhost'); $port = $u['port'] ?? null; }
    else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; $scheme = $https ? 'https' : 'http';
        $hp = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')); if (!preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $hp)) { throw new UserError('Unexpected host name.'); }
        [$host, $port] = array_pad(explode(':', $hp, 2), 2, null);
    }
    $defaultPort = $scheme === 'https' ? 443 : 80;
    return ['id' => $host, 'origin' => $scheme . '://' . $host . ($port && (int) $port !== $defaultPort ? ':' . $port : ''), 'name' => APP_NAME];
}
function wa_challenge_new(string $kind): string {
    start_session(); $c = random_bytes(32); $_SESSION['wa'] = ['c' => b64u_enc($c), 't' => time(), 'k' => $kind]; return b64u_enc($c);
}
/** Single use: removed whether or not the response turns out valid. */
function wa_challenge_take(string $kind): string {
    start_session(); $w = $_SESSION['wa'] ?? null; unset($_SESSION['wa']);
    if (!$w || $w['k'] !== $kind || time() - (int) $w['t'] > 300) { throw new UserError('That request expired. Please try again.'); }
    return $w['c'];
}
function wa_check_client_data(string $json, string $type, string $challenge): void {
    $c = json_decode($json, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $type) { throw new UserError('Unexpected passkey response.'); }
    if (!hash_equals($challenge, (string) ($c['challenge'] ?? ''))) { throw new UserError('That passkey response does not match this request.'); }
    if (!hash_equals(webauthn_rp()['origin'], (string) ($c['origin'] ?? ''))) { throw new UserError('This passkey was created for a different website.'); }
    if (!empty($c['crossOrigin'])) { throw new UserError('Passkeys cannot be used from an embedded page.'); }
}
function wa_parse_auth_data(string $ad, bool $needAttested): array {
    if (strlen($ad) < 37) { throw new UserError('Malformed passkey data.'); }
    $out = ['rpIdHash' => substr($ad, 0, 32), 'flags' => ord($ad[32]), 'count' => unpack('N', substr($ad, 33, 4))[1]];
    if (!hash_equals(hash('sha256', webauthn_rp()['id'], true), $out['rpIdHash'])) { throw new UserError('This passkey belongs to a different website.'); }
    if (!($out['flags'] & 0x01)) { throw new UserError('The passkey did not confirm that you were present.'); }
    if ($needAttested) {
        if (!($out['flags'] & 0x40) || strlen($ad) < 55) { throw new UserError('The passkey did not include a credential.'); }
        $idLen = unpack('n', substr($ad, 53, 2))[1]; if ($idLen < 16 || $idLen > 512 || strlen($ad) < 55 + $idLen) { throw new UserError('Malformed passkey data.'); }
        $out['credId'] = substr($ad, 55, $idLen); $o = 55 + $idLen; $key = cbor_decode($ad, $o); if (!is_array($key)) { throw new UserError('Malformed passkey data.'); } $out['key'] = $key;
    }
    return $out;
}

// ---------------- registration ----------------
function webauthn_register_options(array $u): array {
    $rp = webauthn_rp(); $ex = [];
    foreach (all('SELECT credential_id FROM {passkeys} WHERE user_id=?', [$u['id']]) as $r) { $ex[] = ['type' => 'public-key', 'id' => b64u_enc($r['credential_id'])]; }
    return ['challenge' => wa_challenge_new('create'), 'rp' => ['id' => $rp['id'], 'name' => $rp['name']], 'user' => ['id' => b64u_enc('u' . $u['id']), 'name' => $u['email'], 'displayName' => $u['name']],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]], 'timeout' => 120000, 'attestation' => 'none',
        'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'required'], 'excludeCredentials' => $ex];
}
function webauthn_register_verify(array $u, array $in): int {
    $chal = wa_challenge_take('create');
    if ((int) val('SELECT COUNT(*) FROM {passkeys} WHERE user_id=?', [$u['id']]) >= 10) { throw new UserError('You can keep up to 10 passkeys. Remove one first.'); }
    $cdj = b64u_dec((string) ($in['clientDataJSON'] ?? '')); wa_check_client_data($cdj, 'webauthn.create', $chal);
    $att = cbor_decode(b64u_dec((string) ($in['attestationObject'] ?? ''))); if (!is_array($att) || !is_string($att['authData'] ?? null)) { throw new UserError('Malformed passkey data.'); }
    $ad = wa_parse_auth_data($att['authData'], true); [$pem, $alg] = cose_to_pem($ad['key']);
    if (val('SELECT id FROM {passkeys} WHERE credential_id=?', [$ad['credId']])) { throw new UserError('That passkey is already registered.'); }
    $tr = array_values(array_filter(array_map('strval', is_array($in['transports'] ?? null) ? $in['transports'] : []), fn($t) => preg_match('/^[a-z-]{2,16}$/', $t)));
    $name = trim((string) ($in['name'] ?? '')); $name = $name === '' ? 'Passkey' : mb_substr($name, 0, 60);
    $id = insert('INSERT INTO {passkeys} (user_id,credential_id,public_key,alg,sign_count,transports,name) VALUES (?,?,?,?,?,?,?)', [$u['id'], $ad['credId'], $pem, $alg, $ad['count'], implode(',', $tr), $name]);
    audit('passkey.added', $u['email'], $name); return $id;
}

// ---------------- sign-in ----------------
function webauthn_login_options(?string $email = null): array {
    $rp = webauthn_rp(); $allow = [];
    if ($email) { $uid = val('SELECT id FROM {users} WHERE email=?', [strtolower(trim($email))]); if ($uid) { foreach (all('SELECT credential_id, transports FROM {passkeys} WHERE user_id=?', [$uid]) as $r) { $allow[] = ['type' => 'public-key', 'id' => b64u_enc($r['credential_id'])] + ($r['transports'] ? ['transports' => explode(',', $r['transports'])] : []); } } }
    return ['challenge' => wa_challenge_new('get'), 'rpId' => $rp['id'], 'timeout' => 120000, 'userVerification' => 'required', 'allowCredentials' => $allow];
}
/** Verifies an assertion and returns the user id. The caller starts the session. */
function webauthn_login_verify(array $in): int {
    $ip = client_ip(); $since = time() - 900;
    if ((int) val("SELECT COUNT(*) FROM {login_attempts} WHERE email='(passkey)' AND ip=? AND at_ts>=?", [$ip, $since]) >= 20) { throw new UserError('Too many attempts. Please wait a few minutes.'); }
    $fail = function (string $m) use ($ip) { run("INSERT INTO {login_attempts} (email, ip, at_ts) VALUES ('(passkey)', ?, ?)", [$ip, time()]); throw new UserError($m); };
    try { $chal = wa_challenge_take('get'); $cdj = b64u_dec((string) ($in['clientDataJSON'] ?? '')); $ad = b64u_dec((string) ($in['authenticatorData'] ?? '')); $sig = b64u_dec((string) ($in['signature'] ?? '')); $credId = b64u_dec((string) ($in['id'] ?? '')); }
    catch (UserError $e) { $fail($e->getMessage()); }
    $row = one('SELECT * FROM {passkeys} WHERE credential_id=?', [$credId]); if (!$row) { $fail('That passkey is not registered here. Sign in with your password instead.'); }
    try {
        wa_check_client_data($cdj, 'webauthn.get', $chal); $p = wa_parse_auth_data($ad, false);
        if (!($p['flags'] & 0x04)) { throw new UserError('The passkey did not verify you (fingerprint, face or PIN).'); }
    } catch (UserError $e) { $fail($e->getMessage()); }
    if (openssl_verify($ad . hash('sha256', $cdj, true), $sig, $row['public_key'], OPENSSL_ALGO_SHA256) !== 1) { $fail('The passkey signature could not be verified.'); }
    $stored = (int) $row['sign_count']; if (($stored > 0 || $p['count'] > 0) && $p['count'] <= $stored) { audit('passkey.counter_rollback', (string) $row['user_id'], $row['name']); $fail('This passkey looks cloned, so it was refused. Remove it in Settings and add it again.'); }
    $u = one('SELECT id,email,status FROM {users} WHERE id=?', [$row['user_id']]);
    if (!$u) { $fail('That account no longer exists.'); } if ($u['status'] !== 'active') { throw new UserError('This account has been disabled. Contact the administrator.'); }
    if (!empty($in['userHandle']) && !hash_equals('u' . $u['id'], b64u_dec((string) $in['userHandle']))) { $fail('That passkey does not belong to this account.'); }
    run('UPDATE {passkeys} SET sign_count=?, last_used_at=UTC_TIMESTAMP() WHERE id=?', [$p['count'], $row['id']]); audit('passkey.login', $u['email'], $row['name']);
    return (int) $u['id'];
}

/** JSON endpoints used by assets/passkey.js. */
function handle_webauthn(string $path): never {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''); start_session();
    if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) { http_response_code(403); echo json_encode(['error' => 'Your page expired. Reload and try again.']); exit; }
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 200000), true); $in = is_array($in) ? $in : [];
    try {
        switch ($path) {
            case '/webauthn/register/options': $u = require_login(); echo json_encode(webauthn_register_options($u)); break;
            case '/webauthn/register/verify': $u = require_login(); webauthn_register_verify($u, $in); echo json_encode(['ok' => true]); break;
            case '/webauthn/login/options': echo json_encode(webauthn_login_options(isset($in['email']) ? (string) $in['email'] : null)); break;
            case '/webauthn/login/verify': $id = webauthn_login_verify($in); login_user($id); echo json_encode(['ok' => true, 'redirect' => url('/')]); break;
            default: http_response_code(404); echo json_encode(['error' => 'Not found']);
        }
    } catch (UserError $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); }
    catch (Throwable $e) { log_error($e); http_response_code(500); echo json_encode(['error' => 'Something went wrong. Please try again.']); }
    exit;
}
