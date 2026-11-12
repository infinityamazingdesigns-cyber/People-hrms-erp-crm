<?php
/**
 * Attendance (part 2) — correction requests and month close.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - A person asks to correct a day (in and / or out time, a reason, a note) for this month and the previous month,
 *    until the previous month is closed for payroll. Not for days ahead or before joining. One open request per day.
 *  - Route: reporting manager (the login linked to the manager's code on the employee master) → HR. No manager login
 *    → straight to HR. HR approvers may also decide while it is with the manager ("manager skipped").
 *  - Nobody decides their own; rejecting needs a note; the person can withdraw while it is waiting.
 *  - Approved requests fill the day in the register (marked "corrected"); punches themselves are never changed.
 *  - Super Admin and HR Manager close a month: blocked while requests for it still wait; closed = no new requests.
 *    Only the Super Admin reopens, with a reason; history is kept.
 *  - Super Admin and HR Manager may add attendance directly (an approved request), also in a closed month, never for
 *    their own code.
 */
declare(strict_types=1);
require_once __DIR__ . '/attendance.php';

const ATR_REASONS = ['forgot' => 'Forgot to punch', 'device' => 'Phone / machine problem', 'onduty' => 'On duty / site visit / client meeting',
    'permission' => 'Late or early with permission', 'other' => 'Other'];
const ATR_STATUS = ['manager' => 'With manager', 'hr' => 'With HR', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];
const ATR_HR_ROLES = ['SUPERADMIN', 'HRMANAGER', 'HROPS'];
const ATR_CLOSE_ROLES = ['SUPERADMIN', 'HRMANAGER'];

/* ---------------- pure ---------------- */

/** "9:5" / "0905" / "09:05" → "09:05"; null when not a time. */
function atr_time(?string $t): ?string {
    $t = trim((string)$t);
    if ($t === '') return null;
    if (preg_match('/^(\d{1,2})[:.]?(\d{2})$/', $t, $m) && (int)$m[1] < 30 && (int)$m[2] < 60) return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    return null;
}

/** First day a request may be for: start of last month, or of this month once last month is closed. */
function atr_open_from(string $today, bool $lastMonthClosed): string {
    $first = substr($today, 0, 8) . '01';
    return $lastMonthClosed ? $first : date('Y-m-01', strtotime($first . ' -1 day'));
}

/** Check a request. Returns [clean, errors]. $flexible lets out be the next morning (before the change hour). */
function atr_check(array $in, string $today, string $openFrom, ?string $doj, bool $flexible = false, int $changeHour = 4): array {
    $e = [];
    $day = (string)($in['day'] ?? '');
    $t = DateTime::createFromFormat('!Y-m-d', $day);
    if (!$t || $t->format('Y-m-d') !== $day) $e[] = 'Choose the day.';
    elseif ($day > $today) $e[] = 'Days ahead cannot be corrected.';
    elseif ($day < $openFrom) $e[] = 'Days before ' . $openFrom . ' are closed for payroll.';
    elseif ($doj && $day < $doj) $e[] = 'That day is before the joining date.';
    $in_t = atr_time($in['in_time'] ?? null); $out_t = atr_time($in['out_time'] ?? null);
    if (($in['in_time'] ?? '') !== '' && $in_t === null) $e[] = 'In time: like 09:30.';
    if (($in['out_time'] ?? '') !== '' && $out_t === null) $e[] = 'Out time: like 18:30.';
    if ($in_t === null && $out_t === null && !array_filter($e, fn($x) => str_contains($x, 'time'))) $e[] = 'Give the in time, the out time, or both.';
    if ($in_t !== null && $out_t !== null && $out_t <= $in_t) {
        if ($flexible && (int)substr($out_t, 0, 2) < $changeHour) $out_t = sprintf('%02d:%s', (int)substr($out_t, 0, 2) + 24, substr($out_t, 3));
        else $e[] = 'Out time must be after the in time.';
    }
    $reason = (string)($in['reason'] ?? '');
    if (!isset(ATR_REASONS[$reason])) $e[] = 'Choose a reason.';
    $note = trim(mb_substr(strip_tags((string)($in['note'] ?? '')), 0, 300));
    if ($reason === 'other' && $note === '') $e[] = 'Say what happened.';
    return [['day' => $day, 'in_time' => $in_t, 'out_time' => $out_t, 'reason' => $reason, 'note' => $note], $e];
}

/** Lay approved corrections over day lines (from att_person_days). $corr = day => [in, out]. */
function atr_apply(array $days, array $corr, array $set): array {
    foreach ($corr as $d => [$in, $out]) {
        if (!isset($days[$d])) continue;
        $l = &$days[$d];
        if ($in !== null) $l['in'] = $in . ':00';
        if ($out !== null) $l['out'] = $out . ':00';
        $l['corrected'] = true;
        $off = $l['holiday'] !== null ? 'Holiday' : (str_starts_with((string)$l['status'], 'Weekly off') ? 'Weekly off' : '');
        if ($l['in'] && $l['out'] && att_sec($l['out']) > att_sec($l['in'])) {
            $h = round(max(0, att_sec($l['out']) - att_sec($l['in']) - $l['away_min'] * 60) / 3600, 2);
            $l['hours'] = $h;
            $l['status'] = $off !== '' ? $off . ' · worked' : ($h >= $set['full_day_hours'] ? 'Present' : ($h >= $set['half_day_hours'] ? 'Half day' : 'Short'));
        }
        unset($l);
    }
    return $days;
}

/** Late / early minutes against a shift, with grace. Weekly offs and holidays are never late. */
function atr_late_early(array $days, ?string $start, ?string $end, int $grace): array {
    if (!$start || !$end) return $days;
    $s = att_sec($start . (strlen($start) === 5 ? ':00' : '')); $e = att_sec($end . (strlen($end) === 5 ? ':00' : ''));
    foreach ($days as &$l) {
        $l['late'] = 0; $l['early'] = 0;
        if ($l['holiday'] !== null || str_starts_with((string)$l['status'], 'Weekly off')) continue;
        if ($l['in'] && ($m = intdiv(att_sec($l['in']) - $s, 60)) > $grace) $l['late'] = $m;
        if ($l['out'] && ($m = intdiv($e - att_sec($l['out']), 60)) > $grace) $l['early'] = $m;
    }
    unset($l);
    return $days;
}

/** May $u act on request $r at its current step? Pure: $isHr, $managerOfIt. */
function atr_can_act(array $u, array $r, bool $isHr): bool {
    if ((int)$r['user_id'] === (int)$u['id'] || strcasecmp((string)$r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) return false;
    if ($r['status'] === 'manager') return (int)$r['manager_id'] === (int)$u['id'] || $isHr;
    if ($r['status'] === 'hr') return $isHr;
    return false;
}

/** Next status after an approve: manager → hr (or approved when HR skips), hr → approved. */
function atr_next(array $r, bool $actorIsHr, bool $actorIsManager): string {
    if ($r['status'] === 'manager' && $actorIsManager && !$actorIsHr) return 'hr';
    return 'approved';
}

/* ---------------- database ---------------- */

function atr_is_hr(array $u): bool { return has_role($u, ...ATR_HR_ROLES); }
function atr_can_close(array $u): bool { return has_role($u, ...ATR_CLOSE_ROLES); }

function atr_month_closed(PDO $db, string $ym): bool {
    $s = $db->prepare('SELECT closed_at, reopened_at FROM attendance_month_close WHERE ym = ?');
    $s->execute([$ym]);
    $r = $s->fetch();
    return $r && $r['closed_at'] && (!$r['reopened_at'] || $r['reopened_at'] < $r['closed_at']);
}

function atr_open_from_db(PDO $db, ?string $today = null): string {
    $today ??= date('Y-m-d');
    return atr_open_from($today, atr_month_closed($db, date('Y-m', strtotime(substr($today, 0, 8) . '01 -1 day'))));
}

/** The manager's login for an employee, or null. */
function atr_manager_login(PDO $db, array $emp): ?int {
    if (trim((string)$emp['manager_code']) === '') return null;
    $s = $db->prepare('SELECT id FROM users WHERE emp_code = ? AND active = 1');
    $s->execute([$emp['manager_code']]);
    return ($id = $s->fetchColumn()) ? (int)$id : null;
}

function atr_create(PDO $db, array $u, array $emp, array $in): int {
    $set = att_settings();
    $br = null; foreach (branches(false) as $b) if ((int)$b['id'] === (int)$emp['branch_id']) $br = $b;
    [$c, $e] = atr_check($in, date('Y-m-d'), atr_open_from_db($db), $emp['doj'], !$br || empty($br['shift_start']), (int)$set['flex_day_change']);
    if ($e) throw new RuntimeException(implode(' ', $e));
    if ($c['out_time'] !== null && $c['in_time'] === null) {
        $q = $db->prepare("SELECT MIN(time) FROM attendance_punch WHERE emp_code = ? AND day = ? AND type = 'IN'");
        $q->execute([$emp['code'], $c['day']]);
        if (($first = $q->fetchColumn()) && att_sec($c['out_time'] . ':00') <= att_sec((string)$first))
            throw new RuntimeException('Out time is before your check-in at ' . att_hm((string)$first) . ' — give the in time too.');
    }
    $s = $db->prepare("SELECT COUNT(*) FROM attendance_request WHERE emp_code = ? AND day = ? AND status IN ('manager','hr')");
    $s->execute([$emp['code'], $c['day']]);
    if ((int)$s->fetchColumn()) throw new RuntimeException('A request for that day is already waiting.');
    $mgr = atr_manager_login($db, $emp);
    if ($mgr === (int)$u['id']) $mgr = null;
    $db->prepare('INSERT INTO attendance_request (user_id, emp_code, branch_id, day, in_time, out_time, reason, note, status, manager_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([(int)$u['id'], $emp['code'], $emp['branch_id'], $c['day'], $c['in_time'], $c['out_time'], $c['reason'], $c['note'], $mgr ? 'manager' : 'hr', $mgr, (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    audit((int)$u['id'], 'att_request', '#' . $id . ' ' . $c['day']);
    return $id;
}

/** HR adds attendance directly (approved at once). Allowed in a closed month; never for one's own code. */
function atr_hr_add(PDO $db, array $u, array $emp, array $in): int {
    if (!atr_can_close($u)) throw new RuntimeException('Only the Super Admin or HR Manager adds attendance directly.');
    if (strcasecmp($emp['code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Use a correction request for your own day.');
    if (!emp_in_scope($u, $emp)) throw new RuntimeException('This person is outside your branches.');
    [$c, $e] = atr_check($in, date('Y-m-d'), '1900-01-01', $emp['doj']);
    if ($e) throw new RuntimeException(implode(' ', $e));
    $s = $db->prepare('SELECT id FROM users WHERE emp_code = ?'); $s->execute([$emp['code']]);
    $db->prepare("INSERT INTO attendance_request (user_id, emp_code, branch_id, day, in_time, out_time, reason, note, status, hr_by, hr_at, hr_note, mgr_skipped, created_by)
                  VALUES (?,?,?,?,?,?,?,?, 'approved', ?, NOW(), 'Added directly by HR', 1, ?)")
       ->execute([(int)($s->fetchColumn() ?: 0), $emp['code'], $emp['branch_id'], $c['day'], $c['in_time'], $c['out_time'], $c['reason'], $c['note'], (int)$u['id'], (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    audit((int)$u['id'], 'att_hr_add', '#' . $id . ' ' . $emp['code'] . ' ' . $c['day']);
    return $id;
}

function atr_act(PDO $db, array $u, int $id, string $act, string $note): string {
    $note = trim(mb_substr(strip_tags($note), 0, 300));
    $db->beginTransaction();
    try {
        $s = $db->prepare('SELECT * FROM attendance_request WHERE id = ? FOR UPDATE'); $s->execute([$id]);
        $r = $s->fetch();
        if (!$r) throw new RuntimeException('Request not found.');
        if ($act === 'withdraw') {
            if ((int)$r['user_id'] !== (int)$u['id'] || !in_array($r['status'], ['manager', 'hr'], true)) throw new RuntimeException('Only a waiting request of your own can be withdrawn.');
            $db->prepare("UPDATE attendance_request SET status = 'withdrawn' WHERE id = ?")->execute([$id]);
            $new = 'withdrawn';
        } else {
            $isHr = atr_is_hr($u);
            if (!atr_can_act($u, $r, $isHr)) throw new RuntimeException('You cannot decide this request.');
            if ($isHr && !emp_in_scope($u, ['branch_id' => $r['branch_id']])) throw new RuntimeException('This person is outside your branches.');
            if ($act === 'reject' && $note === '') throw new RuntimeException('Write why it is rejected.');
            $isMgr = (int)$r['manager_id'] === (int)$u['id'];
            $skip = $r['status'] === 'manager' && !$isMgr;
            $new = $act === 'reject' ? 'rejected' : atr_next($r, $isHr && !$isMgr, $isMgr);
            if ($r['status'] === 'manager' && $isMgr) {
                $db->prepare('UPDATE attendance_request SET status = ?, mgr_by = ?, mgr_at = NOW(), mgr_note = ? WHERE id = ?')->execute([$new, (int)$u['id'], $note, $id]);
            } else {
                $db->prepare('UPDATE attendance_request SET status = ?, hr_by = ?, hr_at = NOW(), hr_note = ?, mgr_skipped = ? WHERE id = ?')->execute([$new, (int)$u['id'], $note, $skip ? 1 : (int)$r['mgr_skipped'], $id]);
            }
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'att_request_' . $act, '#' . $id . ' → ' . $new);
    return $new;
}

/** Waiting for this login: as manager, and (for HR) with HR or still with managers, in branch scope. */
function atr_waiting(PDO $db, array $u): array {
    $isHr = atr_is_hr($u);
    $out = ['mine_mgr' => [], 'hr' => [], 'with_mgr' => []];
    foreach ($db->query("SELECT r.*, e.name FROM attendance_request r LEFT JOIN employee e ON e.code = r.emp_code WHERE r.status IN ('manager','hr') ORDER BY r.day") as $r) {
        if ((int)$r['user_id'] === (int)$u['id'] || strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) continue;
        if ($r['status'] === 'manager' && (int)$r['manager_id'] === (int)$u['id']) $out['mine_mgr'][] = $r;
        elseif ($isHr && emp_in_scope($u, ['branch_id' => $r['branch_id']])) $out[$r['status'] === 'hr' ? 'hr' : 'with_mgr'][] = $r;
    }
    return $out;
}

/** Approved corrections for codes in a range: code => day => [in, out]. */
function atr_corrections(PDO $db, string $from, string $to): array {
    $out = [];
    $s = $db->prepare("SELECT emp_code, day, in_time, out_time FROM attendance_request WHERE status = 'approved' AND day BETWEEN ? AND ? ORDER BY id");
    $s->execute([$from, $to]);
    foreach ($s as $r) {
        $k = strtoupper($r['emp_code']); $d = (string)$r['day'];
        $prev = $out[$k][$d] ?? [null, null];
        $out[$k][$d] = [$r['in_time'] ? substr($r['in_time'], 0, 5) : $prev[0], $r['out_time'] ? substr($r['out_time'], 0, 5) : $prev[1]];
    }
    return $out;
}

/** Requests still waiting for a month (block its close). */
function atr_month_blockers(PDO $db, string $ym): int {
    $s = $db->prepare("SELECT COUNT(*) FROM attendance_request WHERE status IN ('manager','hr') AND day BETWEEN ? AND ?");
    $s->execute([$ym . '-01', date('Y-m-t', strtotime($ym . '-01'))]);
    return (int)$s->fetchColumn() + (function_exists('lv_month_blockers') ? lv_month_blockers($db, $ym) : 0);
}

function atr_month_close(PDO $db, array $u, string $ym, string $note): void {
    if (!atr_can_close($u)) throw new RuntimeException('Only the Super Admin or HR Manager closes a month.');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym) || $ym >= date('Y-m')) throw new RuntimeException('Only a past month can be closed.');
    if ($n = atr_month_blockers($db, $ym)) throw new RuntimeException("$n request(s) for that month are still waiting — decide them first.");
    $db->prepare('INSERT INTO attendance_month_close (ym, closed_by, closed_at, close_note) VALUES (?,?,NOW(),?) ON DUPLICATE KEY UPDATE closed_by = VALUES(closed_by), closed_at = NOW(), close_note = VALUES(close_note)')
       ->execute([$ym, (int)$u['id'], mb_substr(trim($note), 0, 300)]);
    audit((int)$u['id'], 'att_month_close', $ym);
}

function atr_month_reopen(PDO $db, array $u, string $ym, string $note): void {
    if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin reopens a month.');
    if (trim($note) === '') throw new RuntimeException('Give a reason.');
    if (!atr_month_closed($db, $ym)) throw new RuntimeException('That month is not closed.');
    $db->prepare('UPDATE attendance_month_close SET reopened_by = ?, reopened_at = NOW(), reopen_note = ? WHERE ym = ?')->execute([(int)$u['id'], mb_substr(trim($note), 0, 300), $ym]);
    audit((int)$u['id'], 'att_month_reopen', $ym);
}
