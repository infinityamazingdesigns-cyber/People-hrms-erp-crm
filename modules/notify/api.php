<?php
// Browser notifications API (lib/push.php). Signed-in logins with the module on.
//  GET  ?key=1                -> {key, devices}             the site's public VAPID key
//  GET  ?pending=1&since=N    -> {items:[{id,title,body,url}], latest}   for the service worker (sw.js)
//  POST JSON {op: subscribe, sub:{endpoint, keys:{p256dh, auth}}} | {op: unsubscribe, endpoint} | {op: test}   header X-CSRF
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/push.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$u = module_require_self('notify', true);
$db = pdo(); $uid = (int)$u['id'];
$fail = function (string $m, int $h = 400) { http_response_code($h); echo json_encode(['ok' => false, 'error' => $m]); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    session_write_close();
    if (isset($_GET['pending'])) { echo json_encode(push_pending($db, $uid, max(0, (int)($_GET['since'] ?? 0))), JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['key' => push_pub($db), 'devices' => push_device_count($db, $uid)]); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $fail('Use GET or POST', 405);
if (!hash_equals(csrf_token(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) $fail('Your session changed. Reload the page and try again.', 403);
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 8000), true);
if (!is_array($in)) $fail('Bad request');
session_write_close();
try {
    switch ($in['op'] ?? '') {
        case 'subscribe':
            push_subscribe($db, $uid, (array)($in['sub'] ?? []), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            audit($uid, 'push_on', (string)(parse_url((string)($in['sub']['endpoint'] ?? ''), PHP_URL_HOST) ?: ''));
            echo json_encode(['ok' => true, 'devices' => push_device_count($db, $uid)]); break;
        case 'unsubscribe':
            push_unsubscribe($db, $uid, (string)($in['endpoint'] ?? '')); audit($uid, 'push_off', '');
            echo json_encode(['ok' => true, 'devices' => push_device_count($db, $uid)]); break;
        case 'test':
            $s = $db->prepare("SELECT COUNT(*) FROM user_notice WHERE user_id = ? AND kind = 'test' AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"); $s->execute([$uid]);
            if ((int)$s->fetchColumn() >= 3) $fail('Three tests in the last few minutes. Try again a little later.', 429);
            notify_user($db, $uid, 'test', org('name'), 'Notifications are on for this device.', 'modules/notify/');
            echo json_encode(['ok' => true, 'devices' => push_device_count($db, $uid)]); break;
        default: $fail('Unknown request');
    }
} catch (RuntimeException $e) { $fail($e->getMessage()); }
