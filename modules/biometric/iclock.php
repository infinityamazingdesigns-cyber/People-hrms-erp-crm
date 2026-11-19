<?php
// Machine direct — the ADMS / "Cloud server" push protocol (ZKTeco, eSSL, Realtime, Identix, BioMax and most ZK-based
// machines). .htaccess sends /iclock/cdata, /iclock/getrequest and /iclock/devicecmd here. Only serial numbers added
// on Biometric machines are accepted (unknown ones are listed there); only attendance lines (table ATTLOG) are kept;
// everything else is answered OK and thrown away; no command is ever sent to a machine.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/biometric.php';
module_public('biometric');
header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
$say = function (string $t, int $c = 200): never { http_response_code($c); echo $t; exit; };
$db = pdo(); $ip = bio_client_ip();
if (ip_throttled($db, $ip, 120)) $say('Too many tries', 429);
$op = (string)($_GET['op'] ?? 'cdata'); $sn = strtoupper(trim((string)($_GET['SN'] ?? $_GET['sn'] ?? '')));
if (!preg_match('/^[A-Z0-9_\-]{4,40}$/', $sn)) { ip_fail($db, $ip); $say('Unknown device', 400); }
$src = bio_by_serial($db, $sn);
if (!$src) { ip_fail($db, $ip); bio_seen_note($db, $sn, $ip); $say('Unknown device', 401); }
if (!(int)$src['active']) $say('Device paused', 403);
if (!bio_ip_ok($src, $ip)) { ip_fail($db, $ip); $say('Address not allowed', 403); }
if ($op !== 'cdata') $say('OK');                                       // getrequest / devicecmd: nothing is asked of the machine
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') { $db->prepare('UPDATE bio_source SET last_seen = NOW(), last_ip = ? WHERE id = ?')->execute([$ip, (int)$src['id']]); $say(bio_adms_options($sn)); }
$raw = (string)file_get_contents('php://input', false, null, 0, BIO_MAX_BODY + 1);
if (strlen($raw) > BIO_MAX_BODY) $say('Too large', 413);
if (strtoupper((string)($_GET['table'] ?? '')) !== 'ATTLOG') $say('OK');   // templates, users, photos, logs: not kept
$rows = bio_attlog($raw);
bio_ingest($db, $src, array_slice($rows, 0, 5000));
$say('OK: ' . count($rows));
