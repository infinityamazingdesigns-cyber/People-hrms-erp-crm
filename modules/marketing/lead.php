<?php
// Public: website forms send a lead here with the lead hook key (Authorization: Bearer <key>, or X-Api-Key). Only a
// fingerprint of the key is kept. Throttled per address. Takes name, phone, email, campaign / utm_campaign / utm_id,
// kind, id (the form's own reference — a repeat adds nothing). Keeps number / mail / name / campaign only, never the text.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/marketing.php';
module_public('marketing');
header('X-Robots-Tag: noindex');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (int $code, array $j): void { http_response_code($code); echo json_encode($j); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'error' => 'Send a POST.']);
$db = pdo();
$tk = 'mk:' . substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 40);
if (ip_throttled($db, $tk, (int)mk_settings()['hook_per_15min'])) $out(429, ['ok' => false, 'error' => 'Too many calls. Try again later.']);
ip_fail($db, $tk);
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$key = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
if (!mk_hook_ok($db, $key)) $out(401, ['ok' => false, 'error' => 'Key not accepted.']);
$raw = (string)file_get_contents('php://input', false, null, 0, 65536);
$in = str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') ? (json_decode($raw, true) ?: []) : $_POST;
if (!is_array($in)) $out(400, ['ok' => false, 'error' => 'Not read.']);
$pick = fn(string ...$k) => (function () use ($in, $k) { foreach ($k as $x) if (isset($in[$x]) && is_scalar($in[$x]) && trim((string)$in[$x]) !== '') return (string)$in[$x]; return ''; })();
$ext = $pick('id', 'ref_id', 'lead_id');
try {
    [$id, $new] = mk_lead_add($db, ['name' => $pick('name', 'full_name'), 'phone' => $pick('phone', 'mobile', 'whatsapp'), 'email' => $pick('email'),
        'kind' => $pick('kind') ?: 'form', 'campaign' => $pick('campaign', 'campaign_name'), 'utm_campaign' => $pick('utm_campaign'), 'utm_id' => $pick('utm_id'),
        'ref' => $pick('ref', 'code'), 'ext_ref' => $ext !== '' ? 'hook:' . mb_substr($ext, 0, 70) : ''], 'hook', null);
    $out(200, ['ok' => true, 'lead' => $id, 'new' => $new]);
} catch (RuntimeException $e) { $out(422, ['ok' => false, 'error' => $e->getMessage()]); }
