<?php
// Biometric machines — punches from machines of any make. Two ways in: **machine direct** (ADMS / "Cloud server" push
// spoken by ZKTeco, eSSL, Realtime, Identix, BioMax and most ZK-based machines — accepted only for a registered serial
// number; unknown ones are listed for the Super Admin to add) and an **API key** (vendor software such as Matrix COSEC,
// Suprema BioStar, Hikvision, or a script). Only attendance lines are read; templates, user lists, photos and logs are
// answered OK and thrown away; no command is ever sent to a machine. Keys are kept only as a hash and shown once.
// Times are the organisation's time zone unless they carry an offset. Each punch is kept once per person and time; the
// attendance register takes the earliest and latest of the day together with phone punches.
declare(strict_types=1);
require_once __DIR__ . '/employees.php';

const BIO_MAKES = ['zkteco' => ['ZKTeco', 'adms'], 'essl' => ['eSSL', 'adms'], 'realtime' => ['Realtime', 'adms'], 'identix' => ['Identix', 'adms'], 'biomax' => ['BioMax', 'adms'],
                   'matrix' => ['Matrix COSEC', 'key'], 'suprema' => ['Suprema BioStar', 'key'], 'hikvision' => ['Hikvision', 'key'], 'mantra' => ['Mantra', 'key'], 'other' => ['Other make', 'key']];
const BIO_WAYS = ['adms' => 'Machine direct (ADMS / Cloud server)', 'key' => 'API key (software or script sends punches)'];
const BIO_MAX_PUNCHES = 1000;
const BIO_MAX_BODY = 1048576;
const BIO_BACK_DAYS = 62;
const BIO_AHEAD_MIN = 10;

/* ---------------- pure ---------------- */

function bio_tz(): DateTimeZone { try { return new DateTimeZone((string)(org('timezone') ?: 'UTC')); } catch (Throwable $e) { return new DateTimeZone('UTC'); } }
/**
 * A punch time as local "Y-m-d H:i:s". Accepts "2026-10-17 09:31[:05]", ISO 8601 with an offset or Z,
 * day-first "17/10/2026 09:31[:05]", or Unix seconds. Null when not understood.
 */
function bio_when($v, DateTimeZone $tz): ?string {
    if (is_int($v) || (is_string($v) && preg_match('/^\d{9,11}$/', trim($v)))) return (new DateTimeImmutable('@' . (int)$v))->setTimezone($tz)->format('Y-m-d H:i:s');
    if (!is_string($v)) return null;
    $s = trim($v);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})[ _](\d{1,2}):(\d{2})(?::(\d{2}))?$#', $s, $m)) {
        if (!checkdate((int)$m[2], (int)$m[1], (int)$m[3]) || (int)$m[4] > 23 || (int)$m[5] > 59) return null;
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4], $m[5], (int)($m[6] ?? 0));
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?\s*(Z|[+\-]\d{2}:?\d{2})?$/i', $s, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59) return null;
    $base = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], (int)($m[6] ?? 0));
    if (($m[7] ?? '') === '') return $base;
    $off = strtoupper($m[7]) === 'Z' ? '+00:00' : (strlen($m[7]) === 5 ? substr($m[7], 0, 3) . ':' . substr($m[7], 3) : $m[7]);
    return (new DateTimeImmutable($base, new DateTimeZone($off)))->setTimezone($tz)->format('Y-m-d H:i:s');
}
/** "machine ID = employee code" lines → map (upper case). */
function bio_parse_map(string $t): array { $o = []; foreach (preg_split('/\R/', $t) ?: [] as $l) if (preg_match('/^\s*([A-Za-z0-9_\-]{1,30})\s*[=:,]\s*([A-Za-z0-9_\-\/.]{1,32})\s*$/', $l, $m)) $o[strtoupper($m[1])] = strtoupper($m[2]); return $o; }
/** Allowed addresses (IPv4 / IPv6, optional /prefix) one per line or comma separated; invalid ones dropped. */
function bio_parse_ips(string $t): array { $o = []; foreach (preg_split('/[\s,]+/', trim($t)) ?: [] as $x) { [$ip, $bits] = array_pad(explode('/', $x, 2), 2, null); if (filter_var($ip, FILTER_VALIDATE_IP) && ($bits === null || (ctype_digit($bits) && (int)$bits <= (str_contains($ip, ':') ? 128 : 32)))) $o[] = $x; } return array_values(array_unique($o)); }
function bio_ip_in(string $ip, string $cidr): bool {
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null); $a = @inet_pton($ip); $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits = $bits === null ? strlen($a) * 8 : (int)$bits; $bytes = intdiv($bits, 8); $rest = $bits % 8;
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
    if ($rest === 0) return true; $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return (($a[$bytes] & $mask) === ($b[$bytes] & $mask));
}
function bio_ip_ok(array $src, string $ip): bool { $l = bio_parse_ips((string)$src['allowed_ips']); if (!$l) return true; foreach ($l as $c) if (bio_ip_in($ip, $c)) return true; return false; }
/** Machine user ID → employee code: the source's "ID = code" line, else prefix + ID. */
function bio_code(array $src, string $id): string {
    $id = strtoupper(trim($id)); if ($id === '') return '';
    $map = json_decode((string)($src['code_map'] ?? ''), true);
    if (is_array($map) && isset($map[$id])) return (string)$map[$id];
    $pre = strtoupper((string)($src['code_prefix'] ?? ''));
    return $pre !== '' && !str_starts_with($id, $pre) ? $pre . $id : $id;
}
/** ADMS ATTLOG body ("PIN\tYYYY-MM-DD HH:MM:SS\tstatus\tverify…") → rows. */
function bio_attlog(string $body): array { $o = []; foreach (preg_split('/\R/', $body) ?: [] as $l) { $c = explode("\t", trim($l)); if (count($c) >= 2 && trim($c[0]) !== '') $o[] = ['code' => trim($c[0]), 'time' => trim($c[1])]; } return $o; }
/** What a machine is told on first contact: attendance lines only, sent as they happen. */
function bio_adms_options(string $sn): string { return implode("\n", ['GET OPTION FROM: ' . $sn, 'ATTLOGStamp=None', 'OPERLOGStamp=9999', 'ATTPHOTOStamp=9999', 'ErrorDelay=60', 'Delay=30', 'TransTimes=00:00;14:05', 'TransInterval=1', 'TransFlag=TransData AttLog', 'Realtime=1', 'Encrypt=None']) . "\n"; }
/** A key request's body: JSON {punches:[…]} / a bare list / one punch, or "code,time[,device]" lines. */
function bio_parse_body(string $raw, string $type = ''): ?array {
    $raw = trim($raw); if ($raw === '') return null;
    $j = json_decode($raw, true);
    if (is_array($j)) { if (isset($j['punches']) && is_array($j['punches'])) return array_values($j['punches']); return array_is_list($j) ? $j : [$j]; }
    if (str_contains($type, 'json')) return null;
    $o = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $l) { $c = str_contains($l, "\t") ? explode("\t", $l) : str_getcsv($l, ',', '"', ''); if (count($c) < 2 || preg_match('/^\s*(code|emp|id|pin)\b/i', $c[0])) continue; $o[] = ['code' => trim($c[0]), 'time' => trim($c[1]), 'device' => trim($c[2] ?? '')]; }
    return $o;
}
/** Check rows. Returns [[code, at, device] ok rows, rejected [[i, why]]]. */
function bio_check_rows(array $src, array $rows, DateTimeImmutable $now, DateTimeZone $tz): array {
    $min = $now->setTimezone($tz)->modify('-' . BIO_BACK_DAYS . ' days')->format('Y-m-d H:i:s'); $max = $now->setTimezone($tz)->modify('+' . BIO_AHEAD_MIN . ' minutes')->format('Y-m-d H:i:s');
    $ok = []; $rej = [];
    foreach (array_values($rows) as $i => $r) {
        if (!is_array($r)) { $rej[] = [$i, 'not a punch']; continue; }
        $code = bio_code($src, (string)($r['code'] ?? $r['emp_code'] ?? $r['id'] ?? $r['pin'] ?? $r['user_id'] ?? ''));
        $at = bio_when($r['time'] ?? $r['punch_at'] ?? $r['datetime'] ?? $r['timestamp'] ?? null, $tz);
        if ($code === '' || !preg_match('/^[A-Z0-9_\-\/.]{1,32}$/', $code)) { $rej[] = [$i, 'employee code missing or not letters and numbers']; continue; }
        if ($at === null) { $rej[] = [$i, 'time not understood (YYYY-MM-DD HH:MM:SS local time, or ISO 8601 with offset)']; continue; }
        if ($at < $min) { $rej[] = [$i, 'older than ' . BIO_BACK_DAYS . ' days']; continue; }
        if ($at > $max) { $rej[] = [$i, 'in the future (check the machine clock)']; continue; }
        $ok[] = [$code, $at, mb_substr(trim((string)($r['device'] ?? $r['serial'] ?? ($src['serial'] ?? ''))), 0, 40)];
    }
    return [$ok, $rej];
}
/**
 * Join one day's machine times with the phone punches: the earliest of the day is the check-in, the latest the
 * check-out. $app = attendance_punch rows (type, time, no); $times = "H:i:s" sorted. Rows added have device 'machine'.
 */
function bio_merge_day(array $app, array $times, string $day, string $label): array {
    if (!$times) return $app;
    sort($times); $mk = fn(string $type, string $t, int $no) => ['day' => $day, 'time' => $t, 'type' => $type, 'no' => $no, 'geofence' => 'machine', 'remarks' => null, 'selfie_id' => '', 'distance_m' => null, 'device' => 'machine', 'machine' => $label];
    if (!$app) return count($times) > 1 ? [$mk('IN', $times[0], 1), $mk('OUT', end($times), 2)] : [$mk('IN', $times[0], 1)];
    usort($app, fn($a, $b) => (int)$a['no'] <=> (int)$b['no']);
    $first = $times[0]; $last = end($times);
    $i = array_search('IN', array_column($app, 'type'), true);
    if ($i !== false && att_sec($first) < att_sec((string)$app[$i]['time'])) $app[$i] = $mk('IN', $first, (int)$app[$i]['no']);
    $n = count($app) - 1;
    if ($app[$n]['type'] === 'OUT') { if (att_sec($last) > att_sec((string)$app[$n]['time'])) $app[$n] = $mk('OUT', $last, (int)$app[$n]['no']); }
    elseif (att_sec($last) > att_sec((string)$app[$n]['time'])) $app[] = $mk('OUT', $last, (int)$app[$n]['no'] + 1);
    return $app;
}

/* ---------------- sources ---------------- */

function bio_key_new(): string { return 'wnbio_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='); }
function bio_key_hash(string $k): string { return hash('sha256', 'wn-bio|' . trim($k)); }
function bio_log(PDO $db, ?int $sid, string $what, ?int $by, string $note = ''): void { $db->prepare('INSERT INTO bio_log (source_id, what, note, by_user) VALUES (?,?,?,?)')->execute([$sid, $what, mb_substr($note, 0, 300), $by]); }
function bio_sources(PDO $db): array { return $db->query('SELECT s.*, (SELECT COUNT(*) FROM bio_punch p WHERE p.source_id = s.id) n, (SELECT MAX(punch_at) FROM bio_punch p WHERE p.source_id = s.id) last_punch FROM bio_source s ORDER BY s.active DESC, s.name')->fetchAll(); }
function bio_source_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM bio_source WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
/** Add or change a source. A new key source returns its key (shown once). */
function bio_source_save(PDO $db, array $u, int $id, array $in): ?string {
    if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin keeps biometric machines.');
    $name = mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 80); if ($name === '') throw new RuntimeException('Give the machine a name (e.g. its place).');
    $make = isset(BIO_MAKES[$in['make'] ?? '']) ? (string)$in['make'] : 'other';
    $way = isset(BIO_WAYS[$in['way'] ?? '']) ? (string)$in['way'] : BIO_MAKES[$make][1];
    $serial = strtoupper(trim((string)($in['serial'] ?? '')));
    if ($way === 'adms' && !preg_match('/^[A-Z0-9_\-]{4,40}$/', $serial)) throw new RuntimeException('Machine direct needs the serial number shown on the machine (4–40 letters / digits).');
    $branch = (int)($in['branch_id'] ?? 0) ?: null; if ($branch && !in_array($branch, array_map(fn($b) => (int)$b['id'], branches(false)), true)) throw new RuntimeException('Pick a branch.');
    $ips = bio_parse_ips((string)($in['allowed_ips'] ?? ''));
    $f = ['name' => $name, 'make' => $make, 'model' => mb_substr(trim(strip_tags((string)($in['model'] ?? ''))), 0, 80), 'way' => $way, 'serial' => $way === 'adms' ? $serial : null, 'branch_id' => $branch,
          'code_prefix' => strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['code_prefix'] ?? '')), 0, 12)), 'code_map' => json_encode(bio_parse_map((string)($in['code_map'] ?? ''))), 'allowed_ips' => implode("\n", $ips)];
    $key = null;
    try {
        if ($id) { $old = bio_source_get($db, $id); if (!$old) throw new RuntimeException('Machine not found.'); if ($way === 'key' && !$old['key_hash']) { $key = bio_key_new(); $f += ['key_hash' => bio_key_hash($key), 'key_hint' => substr($key, -4)]; }
            $db->prepare('UPDATE bio_source SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $id]); bio_log($db, $id, 'edit', (int)$u['id']); }
        else { if ($way === 'key') { $key = bio_key_new(); $f += ['key_hash' => bio_key_hash($key), 'key_hint' => substr($key, -4)]; }
            $db->prepare('INSERT INTO bio_source (' . implode(',', array_keys($f)) . ', created_by) VALUES (' . implode(',', array_fill(0, count($f) + 1, '?')) . ')')->execute([...array_values($f), (int)$u['id']]); $id = (int)$db->lastInsertId(); bio_log($db, $id, 'add', (int)$u['id']);
            if ($serial !== '') $db->prepare('DELETE FROM bio_seen WHERE serial = ?')->execute([$serial]); }
    } catch (PDOException $e) { if ($e->getCode() === '23000') throw new RuntimeException('That serial number is already on another machine.'); throw $e; }
    audit((int)$u['id'], 'bio_source_save', '#' . $id);
    return $key;
}
/** A new key stops the old one at once. */
function bio_key_rotate(PDO $db, array $u, int $id): string {
    if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin keeps biometric machines.');
    $s = bio_source_get($db, $id); if (!$s || $s['way'] !== 'key') throw new RuntimeException('Only an API-key source has a key.');
    $k = bio_key_new(); $db->prepare('UPDATE bio_source SET key_hash = ?, key_hint = ? WHERE id = ?')->execute([bio_key_hash($k), substr($k, -4), $id]);
    bio_log($db, $id, 'new_key', (int)$u['id']); audit((int)$u['id'], 'bio_key_rotate', '#' . $id); return $k;
}
function bio_source_active(PDO $db, array $u, int $id, bool $on, string $why): void {
    if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin keeps biometric machines.');
    $why = mb_substr(trim(strip_tags($why)), 0, 200); if (!$on && $why === '') throw new RuntimeException('Write why the machine is paused.');
    $db->prepare('UPDATE bio_source SET active = ?, off_reason = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? '' : $why, $id]); bio_log($db, $id, $on ? 'on' : 'pause', (int)$u['id'], $why);
}
function bio_by_serial(PDO $db, string $sn): ?array { $s = $db->prepare('SELECT * FROM bio_source WHERE serial = ? AND way = \'adms\''); $s->execute([$sn]); return $s->fetch() ?: null; }
function bio_by_key(PDO $db, string $k): ?array { if (!preg_match('/^wnbio_[A-Za-z0-9_\-]{20,}$/', trim($k))) return null; $s = $db->prepare('SELECT * FROM bio_source WHERE key_hash = ? AND way = \'key\''); $s->execute([bio_key_hash($k)]); return $s->fetch() ?: null; }
function bio_seen_note(PDO $db, string $sn, string $ip): void { $db->prepare('INSERT INTO bio_seen (serial, ip) VALUES (?,?) ON DUPLICATE KEY UPDATE hits = hits + 1, ip = VALUES(ip), last_seen = NOW()')->execute([$sn, $ip]); }
function bio_seen(PDO $db): array { return $db->query('SELECT * FROM bio_seen ORDER BY last_seen DESC LIMIT 20')->fetchAll(); }
/** Keep checked punches from one source. */
function bio_ingest(PDO $db, array $src, array $rows, ?DateTimeImmutable $now = null): array {
    $tz = bio_tz(); [$ok, $rej] = bio_check_rows($src, $rows, $now ?? new DateTimeImmutable('now', $tz), $tz);
    $ins = $db->prepare('INSERT IGNORE INTO bio_punch (source_id, emp_code, punch_at, device) VALUES (?,?,?,?)'); $new = 0; $dup = 0;
    foreach ($ok as [$c, $at, $dev]) { $ins->execute([(int)$src['id'], $c, $at, $dev]); $ins->rowCount() ? $new++ : $dup++; }
    $db->prepare('UPDATE bio_source SET last_seen = NOW(), last_ip = ? WHERE id = ?')->execute([mb_substr(bio_client_ip(), 0, 45), (int)$src['id']]);
    if ($new) audit(null, 'bio_punch', '#' . $src['id'] . ' new ' . $new);
    return ['new' => $new, 'duplicate' => $dup, 'rejected' => $rej];
}
function bio_client_ip(): string { $ip = (string)($_SERVER['REMOTE_ADDR'] ?? ''); return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : ''; }
/** Machine times per code and day: [code => [day => [label, [times]]]]. */
function bio_times(PDO $db, string $from, string $to, ?array $codes): array {
    try {
        $sql = 'SELECT p.emp_code, p.punch_at, s.name FROM bio_punch p JOIN bio_source s ON s.id = p.source_id WHERE p.punch_at >= ? AND p.punch_at < DATE_ADD(?, INTERVAL 1 DAY)'; $args = [$from . ' 00:00:00', $to];
        if ($codes !== null) { if (!$codes) return []; $sql .= ' AND p.emp_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')'; $args = array_merge($args, $codes); }
        $s = $db->prepare($sql . ' ORDER BY p.punch_at'); $s->execute($args);
    } catch (Throwable $e) { return []; }
    $o = []; foreach ($s as $r) { $c = strtoupper((string)$r['emp_code']); $d = substr((string)$r['punch_at'], 0, 10); $o[$c][$d][0] = (string)$r['name']; $o[$c][$d][1][] = substr((string)$r['punch_at'], 11, 8); }
    return $o;
}
/** Codes machines sent this month that match nobody on the employee master. */
function bio_unknown_codes(PDO $db): array {
    return $db->query("SELECT p.emp_code, COUNT(*) n, MAX(s.name) machine FROM bio_punch p JOIN bio_source s ON s.id = p.source_id LEFT JOIN employee e ON e.code = p.emp_code WHERE e.id IS NULL AND p.punch_at >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY p.emp_code ORDER BY p.emp_code")->fetchAll();
}
