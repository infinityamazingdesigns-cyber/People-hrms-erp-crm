<?php
/**
 * Attendance (part 1) — phone punches with a live selfie and a precise GPS fix, the day-by-day register and
 * My attendance. Corrections, month close and late / early rules come in part 2.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - A punch needs a live camera selfie (JPEG, checked by its bytes) and a fresh GPS fix within the required
 *    precision; old or cached fixes are refused. Punches alternate In / Out, at most N a day (setting).
 *  - The office geofence comes from the branch's location and radius (Admin → Organisation). Outside every office the
 *    person may add a one-line remark; inside, no remark is kept.
 *  - Flexible-hours people: a punch before the day-change hour belongs to the previous working day (shown "01:20 (+1)").
 *  - Punches are never edited or deleted. Selfies are kept in the database with the punch.
 *  - Day status: Present (≥ full-day hours), Half day, Short, Checked in (today), Missed punch (past day, no out),
 *    Weekly off, Holiday (from the holiday calendar), Absent (past working day, nothing).
 *  - Who sees whom: each person their own; roles in "see everyone" (default Super Admin, CEO, HR Manager) everyone in
 *    their branches; managers their own team (whole reporting tree) when that setting is on.
 */
declare(strict_types=1);
require_once __DIR__ . '/employees.php';
require_once __DIR__ . '/orgchart.php';
require_once __DIR__ . '/holidays.php';

const ATT_DEFAULTS = ['accuracy_m' => 25, 'max_punches' => 6, 'full_day_hours' => 8, 'half_day_hours' => 4, 'flex_day_change' => 4,
    'see_all_roles' => ['SUPERADMIN', 'CEO', 'HRMANAGER'], 'managers_see_team' => true, 'remarks_outside' => true];
const ATT_MAX_ACCURACY_CEILING = 150;
const ATT_MAX_FIX_AGE_MS = 30000;
const ATT_SELFIE_MAX = 1500000;
const ATT_REMARK_MAX = 200;

/* ---------------- settings ---------------- */

function att_settings(): array {
    $s = module_states()['attendance']['settings'] ?? [];
    $o = array_replace(ATT_DEFAULTS, array_intersect_key($s, ATT_DEFAULTS));
    $o['accuracy_m'] = max(5, min(ATT_MAX_ACCURACY_CEILING, (int)$o['accuracy_m']));
    $o['max_punches'] = max(2, min(12, (int)$o['max_punches']));
    return $o;
}

/** Check a settings form. Returns [settings, errors]. Pure. */
function att_settings_check(array $in, array $roles): array {
    $e = [];
    $o = ['accuracy_m' => (int)($in['accuracy_m'] ?? 25), 'max_punches' => (int)($in['max_punches'] ?? 6),
          'full_day_hours' => (float)($in['full_day_hours'] ?? 8), 'half_day_hours' => (float)($in['half_day_hours'] ?? 4),
          'flex_day_change' => (int)($in['flex_day_change'] ?? 4),
          'see_all_roles' => array_values(array_intersect((array)($in['see_all_roles'] ?? []), $roles)),
          'managers_see_team' => !empty($in['managers_see_team']), 'remarks_outside' => !empty($in['remarks_outside'])];
    if ($o['accuracy_m'] < 5 || $o['accuracy_m'] > ATT_MAX_ACCURACY_CEILING) $e[] = 'GPS precision: 5 to ' . ATT_MAX_ACCURACY_CEILING . ' m.';
    if ($o['max_punches'] < 2 || $o['max_punches'] > 12 || $o['max_punches'] % 2) $e[] = 'Punches a day: an even number from 2 to 12.';
    if ($o['half_day_hours'] <= 0 || $o['full_day_hours'] <= $o['half_day_hours'] || $o['full_day_hours'] > 16) $e[] = 'Hours: half day below full day, full day at most 16.';
    if ($o['flex_day_change'] < 0 || $o['flex_day_change'] > 8) $e[] = 'Day-change hour: 0 to 8.';
    if (!in_array('SUPERADMIN', $o['see_all_roles'], true)) $o['see_all_roles'][] = 'SUPERADMIN';
    return [$o, $e];
}

/* ---------------- pure ---------------- */

function att_distance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = fn($x) => $x * M_PI / 180;
    $h = sin($r($lat2 - $lat1) / 2) ** 2 + cos($r($lat1)) * cos($r($lat2)) * sin($r($lng2 - $lng1) / 2) ** 2;
    return 2 * 6371000 * asin(min(1, sqrt($h)));
}

/** Nearest office (branch with a location) and whether the fix is inside its radius. */
function att_geofence(float $lat, float $lng, array $branches): array {
    $best = null;
    foreach ($branches as $b) {
        if ($b['lat'] === null || $b['lng'] === null || $b['lat'] === '' || $b['lng'] === '') continue;
        $d = att_distance($lat, $lng, (float)$b['lat'], (float)$b['lng']);
        if ($best === null || $d < $best[1]) $best = [$b, $d];
    }
    if ($best === null) return ['office_id' => null, 'distance' => null, 'geofence' => 'none'];
    return ['office_id' => (int)$best[0]['id'], 'distance' => (int)round($best[1]),
            'geofence' => $best[1] <= (int)($best[0]['radius_m'] ?: 200) ? 'inside' : 'outside'];
}

function att_decode_selfie(string $dataUrl): string {
    if (!preg_match('~^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$~', $dataUrl, $m)) throw new RuntimeException('Selfie missing. Take a selfie first.');
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 2000) throw new RuntimeException('Selfie could not be read. Retake it.');
    if (strlen($bin) > ATT_SELFIE_MAX) throw new RuntimeException('Selfie is too large. Retake it.');
    if (substr($bin, 0, 3) !== "\xFF\xD8\xFF") throw new RuntimeException('Selfie must be a camera photo.');
    return $bin;
}

/** [day, time] the punch belongs to. Flexible: before the change hour = previous day, time past 24:00. */
function att_work_day(bool $flexible, DateTimeImmutable $now, int $changeHour): array {
    $h = (int)$now->format('G');
    if ($flexible && $h < $changeHour) return [$now->modify('-1 day')->format('Y-m-d'), sprintf('%02d:%s', $h + 24, $now->format('i:s'))];
    return [$now->format('Y-m-d'), $now->format('H:i:s')];
}

function att_sec(string $t): int { $p = array_map('intval', explode(':', $t) + [0, 0, 0]); return $p[0] * 3600 + $p[1] * 60 + ($p[2] ?? 0); }
function att_hm(?string $t): string {
    if ($t === null || $t === '') return '';
    $s = att_sec($t); $h = intdiv($s, 3600);
    return sprintf('%02d:%02d', $h % 24, intdiv($s % 3600, 60)) . ($h >= 24 ? ' (+1)' : '');
}

/** What the next punch must be, given today's punches (ordered). '' = all used. */
function att_next(array $punches, int $max): string {
    if (count($punches) >= $max) return '';
    return !$punches || end($punches)['type'] === 'OUT' ? 'IN' : 'OUT';
}

/** Is $day a weekly off for a branch? $weekoffs = "Sun" / "Sat,Sun". */
function att_is_weekoff(string $day, string $weekoffs): bool {
    return in_array(date('D', strtotime($day)), array_map('trim', explode(',', $weekoffs)), true);
}

/**
 * Day lines for one person: day => [status, in, out, hours, punches, holiday, away_min].
 * $punches = this person's punch rows in the range; $hol = day => name; $weekoffs = branch weekly offs.
 */
function att_person_days(array $punches, array $hol, string $weekoffs, string $from, string $to, string $today, array $set, ?string $doj = null, ?string $lastDay = null): array {
    $by = [];
    foreach ($punches as $p) $by[(string)$p['day']][] = $p;
    $out = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if ($doj && $d < $doj) continue;
        if ($lastDay && $d > $lastDay) break;
        $ps = $by[$d] ?? [];
        usort($ps, fn($a, $b) => (int)$a['no'] <=> (int)$b['no']);
        $line = ['day' => $d, 'status' => '', 'in' => null, 'out' => null, 'hours' => null, 'punches' => $ps, 'holiday' => $hol[$d] ?? null, 'away_min' => 0];
        $ins = array_values(array_filter($ps, fn($p) => $p['type'] === 'IN'));
        $outs = array_values(array_filter($ps, fn($p) => $p['type'] === 'OUT'));
        if ($ins) $line['in'] = $ins[0]['time'];
        if ($outs && end($ps)['type'] === 'OUT') $line['out'] = end($outs)['time'];
        $prev = null;
        foreach ($ps as $p) { if ($p['type'] === 'OUT') $prev = $p['time']; elseif ($prev !== null) { $line['away_min'] += max(0, intdiv(att_sec($p['time']) - att_sec($prev), 60)); $prev = null; } }
        $off = $line['holiday'] !== null ? 'Holiday' : (att_is_weekoff($d, $weekoffs) ? 'Weekly off' : '');
        if ($line['in'] && $line['out']) {
            $h = round((att_sec($line['out']) - att_sec($line['in']) - $line['away_min'] * 60) / 3600, 2);
            $line['hours'] = $h;
            $line['status'] = $off !== '' ? $off . ' · worked' : ($h >= $set['full_day_hours'] ? 'Present' : ($h >= $set['half_day_hours'] ? 'Half day' : 'Short'));
        } elseif ($line['in']) {
            $line['status'] = $d < $today ? 'Missed punch' : 'Checked in';
        } elseif ($d > $today) {
            $line['status'] = $off;
        } else {
            $line['status'] = $off !== '' ? $off : ($d === $today ? 'Not in yet' : 'Absent');
        }
        $out[$d] = $line;
    }
    return $out;
}

/** Totals over day lines. */
function att_summary(array $lines): array {
    $s = ['Present' => 0, 'Half day' => 0, 'Short' => 0, 'Absent' => 0, 'Missed punch' => 0, 'Weekly off' => 0, 'Holiday' => 0, 'hours' => 0.0];
    foreach ($lines as $l) {
        $k = explode(' · ', (string)$l['status'])[0];
        if (isset($s[$k])) $s[$k]++;
        $s['hours'] += (float)($l['hours'] ?? 0);
    }
    $s['hours'] = round($s['hours'], 1);
    return $s;
}

/* ---------------- scope ---------------- */

/** Codes this login may see: null = every code (within branch scope), else a list. */
function att_scope(PDO $db, array $u): ?array {
    $set = att_settings();
    if (has_role($u, ...$set['see_all_roles'])) return null;
    $me = strtoupper((string)($u['emp_code'] ?? ''));
    $codes = $me !== '' ? [$me] : [];
    if ($set['managers_see_team'] && $me !== '') {
        $parents = org_parent_map($db->query('SELECT code, manager_code FROM employee WHERE active = 1')->fetchAll());
        $codes = array_merge($codes, org_team($me, $parents));
    }
    return array_values(array_unique($codes));
}

function att_can_see(PDO $db, array $u, array $emp): bool {
    $sc = att_scope($db, $u);
    if ($sc === null) return emp_in_scope($u, $emp);
    return in_array(strtoupper((string)$emp['code']), $sc, true);
}

/* ---------------- database ---------------- */

function att_today(PDO $db, int $uid, string $day): array {
    $s = $db->prepare('SELECT * FROM attendance_punch WHERE user_id = ? AND day = ? ORDER BY no');
    $s->execute([$uid, $day]);
    return $s->fetchAll();
}

/** Record a punch. $in: type, lat, lng, acc, fixAge, photo, remarks, flexible. */
function att_record(PDO $db, array $u, ?array $emp, array $in, ?DateTimeImmutable $now = null): array {
    $set = att_settings();
    $type = (string)($in['type'] ?? '');
    if ($type !== 'IN' && $type !== 'OUT') throw new RuntimeException('Choose Check in or Check out.');
    $lat = filter_var($in['lat'] ?? null, FILTER_VALIDATE_FLOAT); $lng = filter_var($in['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    $acc = filter_var($in['acc'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) throw new RuntimeException('Location missing. Turn on GPS and allow location for this site.');
    if ($acc === false || $acc <= 0 || $acc > $set['accuracy_m']) throw new RuntimeException('GPS is not precise enough yet (needs ' . $set['accuracy_m'] . ' m or better). Wait a moment and try again.');
    if (!isset($in['fixAge']) || !is_numeric($in['fixAge']) || (float)$in['fixAge'] < 0 || (float)$in['fixAge'] > ATT_MAX_FIX_AGE_MS) throw new RuntimeException('Your location reading is too old. Wait for a fresh fix.');
    $jpeg = att_decode_selfie((string)($in['photo'] ?? ''));
    $now ??= new DateTimeImmutable('now', new DateTimeZone(org('timezone') ?: 'UTC'));
    [$day, $time] = att_work_day(!empty($in['flexible']), $now, (int)$set['flex_day_change']);
    $today = att_today($db, (int)$u['id'], $day);
    $next = att_next($today, (int)$set['max_punches']);
    if ($next === '') throw new RuntimeException('You have used all ' . $set['max_punches'] . ' punches for today.');
    if ($type !== $next) throw new RuntimeException($type === 'IN' ? 'You are already checked in. Check out first.' : 'Check in first, then check out.');
    $fence = att_geofence((float)$lat, (float)$lng, branches());
    $remark = null;
    if ($fence['geofence'] !== 'inside' && $set['remarks_outside']) {
        $r = trim(preg_replace('/\s+/', ' ', strip_tags((string)($in['remarks'] ?? ''))));
        if ($r !== '') $remark = mb_substr($r, 0, ATT_REMARK_MAX);
    }
    $id = bin2hex(random_bytes(16));
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $device = preg_match('/iPhone|iPad|Android|Mobile/i', $ua) ? 'phone' : 'computer';
    $db->beginTransaction();
    try {
        $s = $db->prepare('INSERT INTO attendance_selfie (id, user_id, bytes, size) VALUES (?,?,?,?)');
        $s->bindValue(1, $id); $s->bindValue(2, (int)$u['id'], PDO::PARAM_INT); $s->bindValue(3, $jpeg, PDO::PARAM_LOB); $s->bindValue(4, strlen($jpeg), PDO::PARAM_INT);
        $s->execute();
        $db->prepare('INSERT INTO attendance_punch (user_id, emp_code, branch_id, day, time, type, no, lat, lng, accuracy_m, office_id, distance_m, geofence, remarks, selfie_id, device, ip)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([(int)$u['id'], $emp['code'] ?? null, $emp['branch_id'] ?? null, $day, $time, $type, count($today) + 1, round((float)$lat, 6), round((float)$lng, 6),
                      (int)round((float)$acc), $fence['office_id'], $fence['distance'], $fence['geofence'], $remark, $id, $device, mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        if ($e->getCode() === '23000') throw new RuntimeException('That punch was already recorded. Reload the page.');
        throw $e;
    }
    audit((int)$u['id'], 'att_punch', "$type #" . (count($today) + 1) . ' ' . $fence['geofence']);
    return ['type' => $type, 'day' => $day, 'time' => $time, 'geofence' => $fence['geofence'], 'distance' => $fence['distance'], 'office_id' => $fence['office_id']];
}

/** Punches of codes in a range: code => rows. Logins with no code are keyed "U<id>". */
function att_punches(PDO $db, string $from, string $to, ?array $codes): array {
    $sql = 'SELECT * FROM attendance_punch WHERE day BETWEEN ? AND ?';
    $args = [$from, $to];
    if ($codes !== null) {
        if (!$codes) return [];
        $sql .= ' AND emp_code IN (' . rtrim(str_repeat('?,', count($codes)), ',') . ')';
        $args = array_merge($args, $codes);
    }
    $out = [];
    $s = $db->prepare($sql . ' ORDER BY day, no'); $s->execute($args);
    foreach ($s as $r) $out[$r['emp_code'] ? strtoupper($r['emp_code']) : 'U' . $r['user_id']][] = $r;
    return $out;
}

/** Register rows for the people this login may see (optionally one person / one branch). */
function att_register(PDO $db, array $u, string $from, string $to, ?string $oneCode = null, int $branchId = 0): array {
    $set = att_settings(); $today = date('Y-m-d');
    $sc = att_scope($db, $u);
    $people = [];
    foreach ($db->query('SELECT * FROM employee WHERE active = 1 ORDER BY name') as $e) {
        if ($sc !== null && !in_array(strtoupper($e['code']), $sc, true)) continue;
        if ($sc === null && !emp_in_scope($u, $e)) continue;
        if ($oneCode && strcasecmp($e['code'], $oneCode) !== 0) continue;
        if ($branchId && (int)$e['branch_id'] !== $branchId) continue;
        if ($e['last_day'] && $e['last_day'] < $from) continue;
        if ($e['doj'] && $e['doj'] > $to) continue;
        $people[] = $e;
    }
    $punches = att_punches($db, $from, $to, array_map(fn($p) => strtoupper($p['code']), $people));
    $brW = array_column(branches(false), 'weekoffs', 'id');
    $out = [];
    foreach ($people as $p) {
        $hol = hol_days($db, $p['code'], $p['branch_id'] ? (int)$p['branch_id'] : null, $from, $to);
        $out[] = ['emp' => $p, 'days' => att_person_days($punches[strtoupper($p['code'])] ?? [], $hol, (string)($brW[$p['branch_id']] ?? 'Sun'), $from, $to, $today, $set, $p['doj'], $p['last_day'])];
    }
    return $out;
}

/** Stream a selfie if this login may see that punch. */
function att_selfie_send(PDO $db, array $u, string $id): void {
    $s = $db->prepare('SELECT p.user_id, p.emp_code, p.branch_id, s.bytes FROM attendance_punch p JOIN attendance_selfie s ON s.id = p.selfie_id WHERE p.selfie_id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    $ok = $r && ((int)$r['user_id'] === (int)$u['id'] || ($r['emp_code'] && att_can_see($db, $u, ['code' => $r['emp_code'], 'branch_id' => $r['branch_id']])));
    if (!$ok) { http_response_code(404); exit('Not found'); }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $r['bytes'];
    exit;
}
