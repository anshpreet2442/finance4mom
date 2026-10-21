<?php
// Tiny SMTP server for tests: php tests/smtp_mock.php 127.0.0.1:2525 /tmp/out.json — accepts AUTH PLAIN/LOGIN, stores each message, exits after QUIT of the Nth connection (or on SIGTERM).
[, $addr, $out] = $argv; $srv = stream_socket_server("tcp://$addr", $en, $es); if (!$srv) { fwrite(STDERR, "listen failed: $es\n"); exit(1); }
$msgs = [];
while ($c = @stream_socket_accept($srv, 60)) {
    $m = ['from' => '', 'to' => '', 'data' => '', 'auth' => '']; $w = fn($s) => fwrite($c, $s . "\r\n"); $w('220 mock ESMTP');
    while (($l = fgets($c)) !== false) {
        $l = rtrim($l); $u = strtoupper($l);
        if (str_starts_with($u, 'EHLO')) { fwrite($c, "250-mock\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME\r\n"); }
        elseif (str_starts_with($u, 'AUTH PLAIN')) { $m['auth'] = base64_decode(substr($l, 11)); $w('235 ok'); }
        elseif ($u === 'AUTH LOGIN') { $w('334 VXNlcm5hbWU6'); $m['auth'] = base64_decode(rtrim(fgets($c))); $w('334 UGFzc3dvcmQ6'); $m['auth'] .= '|' . base64_decode(rtrim(fgets($c))); $w('235 ok'); }
        elseif (str_starts_with($u, 'MAIL FROM')) { $m['from'] = $l; $w('250 ok'); }
        elseif (str_starts_with($u, 'RCPT TO')) { if (str_contains($l, 'reject@')) { $w('550 no such user'); } else { $m['to'] = $l; $w('250 ok'); } }
        elseif ($u === 'DATA') { $w('354 go'); $d = ''; while (($x = fgets($c)) !== false && rtrim($x) !== '.') { $d .= $x; } $m['data'] = $d; $w('250 queued'); $msgs[] = $m; file_put_contents($out, json_encode($msgs)); }
        elseif ($u === 'QUIT') { $w('221 bye'); break; }
        else { $w('250 ok'); }
    }
    fclose($c);
}
