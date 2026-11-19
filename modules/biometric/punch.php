<?php
// API key — vendor software or a script sends punches: POST with "Authorization: Bearer wnbio_…" (or X-API-Key),
// JSON {punches:[{code, time, device?}]} / a bare list / "code,time" lines; at most 1000 a request, 1 MB. GET checks the key.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/biometric.php';
module_public('biometric');
header('Content-Type: application/json'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
$say = function (array $j, int $c = 200): never { http_response_code($c); echo json_encode($j); exit; };
$db = pdo(); $ip = bio_client_ip();
if (ip_throttled($db, $ip, 120)) $say(['error' => 'too_many_tries'], 429);
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$key = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
$src = $key !== '' ? bio_by_key($db, $key) : null;
if (!$src) { ip_fail($db, $ip); $say(['error' => 'key_not_valid'], 401); }
if (!(int)$src['active']) $say(['error' => 'source_paused'], 403);
if (!bio_ip_ok($src, $ip)) { ip_fail($db, $ip); $say(['error' => 'address_not_allowed'], 403); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') $say(['ok' => true, 'source' => $src['name']]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $say(['error' => 'use_post'], 405);
$raw = (string)file_get_contents('php://input', false, null, 0, BIO_MAX_BODY + 1);
if (strlen($raw) > BIO_MAX_BODY) $say(['error' => 'too_large'], 413);
$rows = bio_parse_body($raw, (string)($_SERVER['CONTENT_TYPE'] ?? ''));
if ($rows === null) $say(['error' => 'body_not_understood'], 400);
if (count($rows) > BIO_MAX_PUNCHES) $say(['error' => 'too_many_punches', 'max' => BIO_MAX_PUNCHES], 413);
$r = bio_ingest($db, $src, $rows);
$say(['ok' => true, 'new' => $r['new'], 'duplicate' => $r['duplicate'], 'rejected' => array_map(fn($x) => ['row' => $x[0], 'why' => $x[1]], $r['rejected'])]);
