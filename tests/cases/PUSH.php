<?php
// Browser notification rules (lib/push.php) — synthetic data only.
require_once WN_ROOT . '/lib/push.php';
t('PUSH01 endpoints only on the known push services, https, no port or user', push_endpoint_ok('https://fcm.googleapis.com/fcm/send/abc') && push_endpoint_ok('https://web.push.apple.com/QH')
    && push_endpoint_ok('https://db5p.notify.windows.com/w/?token=x') && !push_endpoint_ok('http://fcm.googleapis.com/x') && !push_endpoint_ok('https://evil.example.com/x')
    && !push_endpoint_ok('https://fcm.googleapis.com:8443/x') && !push_endpoint_ok('https://user' . chr(64) . 'fcm.googleapis.com/x') && !push_endpoint_ok('https://fcm.googleapis.com.evil.example/x'));
$pk = push_make_key();
$pjwt = push_jwt($pk, 'https://fcm.googleapis.com', 'https://example.com', 1000);
[$ph, $pc, $ps] = explode('.', $pjwt);
$praw = push_b64u_dec($ps); $pub = push_b64u_dec($pk['pub']);
$der = function (string $raw): string { $i = function (string $x) { $x = ltrim($x, "\0"); if (ord($x[0]) & 0x80) $x = "\0" . $x; return "\x02" . chr(strlen($x)) . $x; }; $b = $i(substr($raw, 0, 32)) . $i(substr($raw, 32)); return "\x30" . chr(strlen($b)) . $b; };
$spki = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $pub;
$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
t('PUSH02 VAPID: a P-256 key, ES256 token whose raw signature verifies against the public key', strlen($pub) === 65 && $pub[0] === "\x04" && strlen($praw) === 64
    && json_decode(push_b64u_dec($pc), true)['aud'] === 'https://fcm.googleapis.com' && openssl_verify("$ph.$pc", $der($praw), $pem, OPENSSL_ALGO_SHA256) === 1);
t('PUSH03 notice links stay inside the site', push_url_clean('/modules/tasks/') === 'modules/tasks/' && push_url_clean('modules/meet/index.php?r=abc-defg-hjk') !== ''
    && push_url_clean('https://evil.example.com') === '' && push_url_clean('//evil.example.com') === '' && push_url_clean('javascript:alert(1)') === '' && push_url_clean('../x') === '');
t('PUSH04 base64url round trip', push_b64u_dec(push_b64u("\xff\xfe\x00abc")) === "\xff\xfe\x00abc" && !str_contains(push_b64u(str_repeat("\xff", 9)), '+'));
$sw = file_get_contents(WN_ROOT . '/sw.js');
t('PUSH05 the service worker caches nothing but its counter, intercepts no page, and opens only site links', !str_contains($sw, "'fetch'") && !str_contains($sw, '"fetch"') && str_contains($sw, 'url.startsWith(BASE)'));
