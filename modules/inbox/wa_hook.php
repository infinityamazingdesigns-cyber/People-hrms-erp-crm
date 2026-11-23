<?php
/**
 * Public: WhatsApp Business webhook → Inbox. No sign-in. GET = Meta's address check (answers the challenge only for a
 * verify token a login made on Inbox → Settings); POST must carry X-Hub-Signature-256 (HMAC-SHA256 of the raw body with
 * a connected login's Meta app secret) — else 401 and nothing is read. Throttled per address. Rules in lib/inbox.php.
 *   Meta app → WhatsApp → Configuration → Webhook: callback https://<site>/modules/inbox/wa_hook.php
 */
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/inbox.php';
module_public('inbox');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    $db = pdo();
    $tk = 'ibwa:' . substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 40);
    if (ip_throttled($db, $tk, 600)) { http_response_code(429); exit; }
    ip_fail($db, $tk);
    if ($m === 'GET') {
        header('Content-Type: text/plain; charset=utf-8');
        $ch = ib_wa_verify($db, $_GET);
        if ($ch === null) { http_response_code(403); echo 'no'; exit; }
        echo $ch; exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($m !== 'POST') { http_response_code(405); echo '{"error":"POST only"}'; exit; }
    $raw = (string)file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
    $code = ib_wa_hook($db, $raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
    http_response_code($code);
    echo $code === 200 ? '{"ok":true}' : '{"error":"signature"}';
} catch (Throwable $e) {
    error_log('wa hook: ' . $e->getMessage());
    http_response_code(500);   // Meta sends the event again later
    echo '{"error":"try again"}';
}
