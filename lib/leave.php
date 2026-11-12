<?php
/**
 * Leave — types set by HR, applications, approvals, withdrawals and balances.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - One application = a day (full / first half / second half), a range (first day full or second half, last day full
 *    or first half), or a time permission inside the shift on one day (late coming, early going, short leave out & back).
 *  - No clash with the person's own waiting or approved leave on any day (first half + second half from two
 *    applications is fine; a late coming inside first-half leave is not).
 *  - Route: reporting manager → HR; no manager login → straight to HR. HR may decide while it is with the manager
 *    (manager skipped). Nobody decides their own; rejecting needs a note.
 *  - The person withdraws while it waits. Approved leave: a request to withdraw (reason) goes manager → HR; the leave
 *    stays approved until HR agrees. Once a day of the leave has passed with no punch, only the Super Admin or HR
 *    Manager may withdraw it.
 *  - HR applies for someone in their branches (also without a login), may record leave approved outside (paper,
 *    message), and sets the final category; never for their own code.
 *  - A closed month (attendance month close) takes no new leave and no withdrawal touching it.
 *  - Balances: each type has days a year (or no limit), pro-rated from joining (or confirmation for types that need
 *    it), plus HR's adjustments, minus approved leave of that type; waiting leave is shown apart.
 *  - Days: weekly offs and holidays inside a range are not counted unless the setting says so.
 *  - Approved leave shows in attendance: full day clears late and early, first half clears late, second half early,
 *    a time permission waives the mark up to the time given.
 */
declare(strict_types=1);
require_once __DIR__ . '/att_requests.php';

const LV_SESSIONS = ['full' => 'Full day', 'first' => 'First half', 'second' => 'Second half',
    'late' => 'Late coming', 'early' => 'Early going', 'out' => 'Short leave (out & back)'];
const LV_TIMED = ['late', 'early', 'out'];
const LV_STATUS = ['manager' => 'With manager', 'hr' => 'With HR', 'approved' => 'Approved', 'rejected' => 'Rejected',
    'cancelled' => 'Withdrawn', 'withdrawn' => 'Withdrawn after approval'];
const LV_WD_STATUS = ['manager' => 'Withdrawal asked · with manager', 'hr' => 'Withdrawal asked · with HR', 'rejected' => 'Withdrawal not agreed', 'done' => 'Withdrawn'];
const LV_DEFAULTS = ['back_days' => 45, 'ahead_days' => 180, 'max_span' => 92, 'count_off_days' => false, 'show_balance' => true, 'choose_type' => true];
const LV_SUPER_ROLES = ['SUPERADMIN', 'HRMANAGER'];

/* ---------------- settings ---------------- */

function lv_settings(): array {
    $s = module_states()['leave']['settings'] ?? [];
    return array_replace(LV_DEFAULTS, array_intersect_key($s, LV_DEFAULTS));
}

/** Check a settings form. Returns [settings, errors]. Pure. */
function lv_settings_check(array $in): array {
    $o = []; $e = [];
    foreach (['back_days' => [0, 366], 'ahead_days' => [1, 730], 'max_span' => [1, 366]] as $k => [$lo, $hi]) {
        $v = filter_var($in[$k] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $lo || $v > $hi) $e[] = "$k must be between $lo and $hi."; else $o[$k] = $v;
    }
    foreach (['count_off_days', 'show_balance', 'choose_type'] as $k) $o[$k] = !empty($in[$k]);
    return [$o, $e];
}

/* ---------------- pure: days and clashes ---------------- */

/** date => [first half, second half] for an application. A time permission covers neither half. */
function lv_halves(string $from, string $fromS, string $to, string $toS): array {
    $out = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $h = [true, true];
        if ($from === $to) $h = ['full' => [true, true], 'first' => [true, false], 'second' => [false, true]][$fromS] ?? [false, false];
        elseif ($d === $from && $fromS === 'second') $h = [false, true];
        elseif ($d === $to && $toS === 'first') $h = [true, false];
        $out[$d] = $h;
    }
    return $out;
}

/** Day count: 1 per full day, 0.5 per half; days in $off (weekly offs, holidays) are left out. */
function lv_days(string $from, string $fromS, string $to, string $toS, array $off = []): float {
    $n = 0.0;
    foreach (lv_halves($from, $fromS, $to, $toS) as $d => $h) if (!isset($off[$d])) $n += ($h[0] ? .5 : 0) + ($h[1] ? .5 : 0);
    return $n;
}

function lv_session_of(array $h): string { return $h[0] && $h[1] ? 'full' : ($h[0] ? 'first' : ($h[1] ? 'second' : '')); }

/** Minutes b − a for "HH:MM". */
function lv_min(string $a, string $b): int { return intdiv(att_sec($b . ':00') - att_sec($a . ':00'), 60); }

/** Minutes a time permission takes out of the shift ($shift = [start, end] or null). */
function lv_timed_minutes(string $kind, ?string $tFrom, ?string $tTo, ?array $shift): ?int {
    if ($kind === 'out') return $tFrom && $tTo ? lv_min($tFrom, $tTo) : null;
    if (!$shift || !$tFrom) return null;
    return $kind === 'late' ? lv_min($shift[0], $tFrom) : lv_min($tFrom, $shift[1]);
}

/** Do two applications clash on one date? $a / $b = kind ('full' for leave, or a time kind); $ha / $hb = halves. */
function lv_clash(string $a, array $ha, string $b, array $hb, array $ta = [], array $tb = []): bool {
    if (($ha[0] && $hb[0]) || ($ha[1] && $hb[1])) return true;
    $inside = fn($k, $h) => match ($k) { 'late' => $h[0], 'early' => $h[1], 'out' => $h[0] && $h[1], default => false };
    if (in_array($a, LV_TIMED, true) && $inside($a, $hb)) return true;
    if (in_array($b, LV_TIMED, true) && $inside($b, $ha)) return true;
    if ($a === $b && in_array($a, LV_TIMED, true)) {
        if ($a !== 'out') return true;
        return !($ta[1] <= $tb[0] || $tb[1] <= $ta[0]);
    }
    return false;
}

/** "Full day" / "First half" / "Late coming 10:30" for one date of an application. */
function lv_part_label(array $r, string $d): string {
    if ($r['from_date'] === $r['to_date'] && in_array($r['from_session'], LV_TIMED, true))
        return LV_SESSIONS[$r['from_session']] . ' ' . $r['time_from'] . ($r['from_session'] === 'out' && $r['time_to'] ? '–' . $r['time_to'] : '');
    return LV_SESSIONS[lv_session_of(lv_halves($r['from_date'], $r['from_session'], $r['to_date'], $r['to_session'])[$d] ?? [true, true])] ?? 'Full day';
}

/** Clashing dates between an asked application and existing rows. Pure. */
function lv_clash_list(array $ask, array $rows): array {
    $mine = lv_halves($ask['from'], $ask['fs'], $ask['to'], $ask['ts']);
    $myKind = $ask['from'] === $ask['to'] ? $ask['fs'] : 'full';
    $out = [];
    foreach ($rows as $r) {
        if (!in_array($r['status'], ['manager', 'hr', 'approved'], true)) continue;
        $rk = $r['from_date'] === $r['to_date'] ? $r['from_session'] : 'full';
        foreach (lv_halves($r['from_date'], $r['from_session'], $r['to_date'], $r['to_session']) as $d => $h)
            if (isset($mine[$d]) && lv_clash($myKind, $mine[$d], $rk, $h, [$ask['tf'] ?? null, $ask['tt'] ?? null], [$r['time_from'], $r['time_to']]))
                $out[] = ['date' => $d, 'part' => lv_part_label($r, $d), 'id' => (int)$r['id'], 'status' => LV_STATUS[$r['status']]];
    }
    return $out;
}

function lv_clash_text(array $cl): string {
    $by = [];
    foreach ($cl as $c) $by[$c['id']][] = date('j M', strtotime($c['date'])) . ' (' . $c['part'] . ')';
    $p = [];
    foreach ($by as $id => $ds) $p[] = implode(', ', $ds) . ' — application #' . $id . ', ' . strtolower($cl[array_search($id, array_column($cl, 'id'))]['status']);
    return 'Leave is already applied on ' . implode('; ', $p) . '. Change the dates or withdraw that application first.';
}

/**
 * Check an application. $in: from, to, session (one day) or from_session / to_session, time_from, time_to, reason.
 * $shift = [start, end] when the person has fixed hours. Returns the clean row; throws with a plain reason.
 */
function lv_check(array $in, string $today, array $set, ?array $shift = null): array {
    $ok = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && date('Y-m-d', strtotime($d)) === $d;
    $from = (string)($in['from'] ?? ''); $to = (string)($in['to'] ?? '') ?: $from;
    if (!$ok($from)) throw new InvalidArgumentException('Choose the from date.');
    if (!$ok($to)) throw new InvalidArgumentException('Choose the to date.');
    if ($to < $from) throw new InvalidArgumentException('The to date is before the from date.');
    if ($from < date('Y-m-d', strtotime("$today -{$set['back_days']} days"))) throw new InvalidArgumentException("Leave can be applied up to {$set['back_days']} days back. Speak to HR for older days.");
    if ($to > date('Y-m-d', strtotime("$today +{$set['ahead_days']} days"))) throw new InvalidArgumentException("Leave can be applied up to {$set['ahead_days']} days ahead.");
    if ((strtotime($to) - strtotime($from)) / 86400 + 1 > $set['max_span']) throw new InvalidArgumentException("One application covers up to {$set['max_span']} days. Split it.");
    $tf = $tt = null;
    if ($from === $to) {
        $fs = $ts = (string)($in['session'] ?? 'full');
        if (!isset(LV_SESSIONS[$fs])) throw new InvalidArgumentException('Choose the part of the day.');
        if (in_array($fs, LV_TIMED, true)) {
            $tf = atr_time($in['time_from'] ?? null);
            $tt = $fs === 'out' ? atr_time($in['time_to'] ?? null) : null;
            if (!$tf) throw new InvalidArgumentException(['late' => 'Give the time you will reach.', 'early' => 'Give the time you will leave.', 'out' => 'Give the time you will go out.'][$fs]);
            if ($fs === 'out' && (!$tt || $tt <= $tf)) throw new InvalidArgumentException('Give a back time after the going-out time.');
            if ($shift) {
                if ($fs !== 'out' && ($tf <= $shift[0] || $tf >= $shift[1])) throw new InvalidArgumentException("The time must be inside your shift ({$shift[0]}–{$shift[1]}).");
                if ($fs === 'out' && ($tf < $shift[0] || $tt > $shift[1])) throw new InvalidArgumentException("Short leave must be inside your shift ({$shift[0]}–{$shift[1]}).");
            }
        }
    } else {
        $fs = (string)($in['from_session'] ?? 'full'); $ts = (string)($in['to_session'] ?? 'full');
        if (!in_array($fs, ['full', 'second'], true)) throw new InvalidArgumentException('The first day can be a full day or the second half.');
        if (!in_array($ts, ['full', 'first'], true)) throw new InvalidArgumentException('The last day can be a full day or the first half.');
    }
    $reason = trim(mb_substr(strip_tags((string)($in['reason'] ?? '')), 0, 500));
    if (mb_strlen($reason) < 3) throw new InvalidArgumentException('Write the reason.');
    return ['from' => $from, 'fs' => $fs, 'to' => $to, 'ts' => $ts, 'tf' => $tf, 'tt' => $tt, 'reason' => $reason];
}

/* ---------------- pure: leave year and balance ---------------- */

/** [from, to] of the leave year holding $day; year starts in month $start (1 = January). */
function lv_year(string $day, int $start): array {
    $y = (int)substr($day, 0, 4); $m = (int)substr($day, 5, 2);
    if ($m < $start) $y--;
    $f = sprintf('%04d-%02d-01', $y, $start);
    return [$f, date('Y-m-d', strtotime($f . ' +1 year -1 day'))];
}

/**
 * Days of a type for one year. null = no limit. Pro-rated by whole months left from the start date (a month counts
 * when the start is on or before the 15th), rounded to the nearest half day. A type that needs confirmation starts
 * from the confirmation date (none yet = 0).
 */
function lv_entitlement(array $type, array $year, ?string $doj, ?string $confirm): ?float {
    if ($type['per_year'] === null || $type['per_year'] === '') return null;
    $per = (float)$type['per_year'];
    $start = (int)$type['after_confirm'] ? $confirm : $doj;
    if ((int)$type['after_confirm'] && !$confirm) return 0.0;
    if ($start && $start > $year[1]) return 0.0;
    if (!(int)$type['prorate'] || !$start || $start <= $year[0]) return $per;
    $months = 0;
    for ($m = substr($year[0], 0, 7); $m <= substr($year[1], 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month')))
        if ($m > substr($start, 0, 7) || ($m === substr($start, 0, 7) && (int)substr($start, 8, 2) <= 15)) $months++;
    return round($per * $months / 12 * 2) / 2;
}

/**
 * Balance per type. $taken / $waiting / $adj = code => days. Returns code => [entitled, adjusted, taken, waiting, left]
 * (left null = no limit).
 */
function lv_balance(array $types, array $year, ?string $doj, ?string $confirm, array $taken, array $waiting, array $adj): array {
    $o = [];
    foreach ($types as $t) {
        if (!(int)$t['active']) continue;
        $c = $t['code'];
        $e = lv_entitlement($t, $year, $doj, $confirm);
        $a = (float)($adj[$c] ?? 0); $tk = (float)($taken[$c] ?? 0); $w = (float)($waiting[$c] ?? 0);
        $o[$c] = ['entitled' => $e, 'adjusted' => $a, 'taken' => $tk, 'waiting' => $w, 'left' => $e === null ? null : $e + $a - $tk];
    }
    return $o;
}

/** Types a person may ask for on these dates: active, open to choose, confirmed if needed, birthday only on it. */
function lv_allowed(array $types, array $ask, ?string $dob, ?string $confirm): array {
    $o = [];
    foreach ($types as $t) {
        if (!(int)$t['active'] || !(int)$t['apply_choice']) continue;
        if (in_array($ask['fs'], LV_TIMED, true)) continue;
        if ((int)$t['after_confirm'] && (!$confirm || $confirm > $ask['from'])) continue;
        if ($t['on_day'] === 'birthday' && (!$dob || $ask['from'] !== $ask['to'] || substr($dob, 5) !== substr($ask['from'], 5))) continue;
        $o[] = $t['code'];
    }
    return $o;
}

/**
 * Put approved leave on attendance day lines. $leave = day => [halves, type, times[]]. Full leave with no punch →
 * "On leave"; half leave with no punch → "Leave · first half"; leave waives late (first half) / early (second half);
 * a time permission waives the mark up to the time given.
 */
function lv_overlay(array $days, array $leave): array {
    foreach ($leave as $d => $l) {
        if (!isset($days[$d])) continue;
        $x = &$days[$d];
        if ($x['holiday'] !== null || str_starts_with((string)$x['status'], 'Weekly off')) { unset($x); continue; }
        $x['leave'] = $l;
        $h = $l['halves'];
        if ($h[0] || $h[1]) {
            $noPunch = !$x['in'];
            if ($h[0] && $h[1]) $x['status'] = $noPunch ? 'On leave' : 'On leave · worked';
            elseif ($noPunch) $x['status'] = 'Leave · ' . ($h[0] ? 'first half' : 'second half');
            elseif (in_array(explode(' · ', (string)$x['status'])[0], ['Half day', 'Short', 'Missed punch'], true)) $x['status'] = 'Present · ' . ($h[0] ? 'first' : 'second') . ' half leave';
        }
        if ($h[0]) $x['late'] = 0;
        if ($h[1]) $x['early'] = 0;
        foreach ($l['times'] ?? [] as $t) {
            if ($t['kind'] === 'late' && !empty($x['late']) && $x['in'] && substr((string)$x['in'], 0, 5) <= $t['from']) $x['late'] = 0;
            if ($t['kind'] === 'early' && !empty($x['early']) && $x['out'] && substr((string)$x['out'], 0, 5) >= $t['from']) $x['early'] = 0;
        }
        unset($x);
    }
    return $days;
}

/** Days of an application already begun (before today, or today) — pure. */
function lv_begun_days(array $r, string $today): array {
    return array_values(array_filter(array_keys(lv_halves($r['from_date'], $r['from_session'], $r['to_date'], $r['to_session'])), fn($d) => $d <= $today));
}

/* ---------------- data ---------------- */

function lv_is_hr(array $u): bool { return atr_is_hr($u); }
function lv_super(array $u): bool { return has_role($u, ...LV_SUPER_ROLES); }

function lv_types(PDO $db, bool $activeOnly = true): array {
    return $db->query('SELECT * FROM leave_type' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, name')->fetchAll();
}

function lv_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM leave_request WHERE id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Weekly offs + holidays between two dates for a person (day => true), unless the setting counts them. */
function lv_off_days(PDO $db, array $emp, string $from, string $to): array {
    if (lv_settings()['count_off_days']) return [];
    $off = hol_days($db, $emp['code'], $emp['branch_id'] ? (int)$emp['branch_id'] : null, $from, $to);
    foreach (shift_by_day(shift_rules($db, [$emp['code']])[strtoupper($emp['code'])], shift_branch($emp['branch_id'] ? (int)$emp['branch_id'] : null), $from, $to) as $d => $e)
        if (att_is_weekoff($d, $e['weekoffs'])) $off[$d] = true;
    return array_map(fn() => true, $off);
}

/** Refuse any day in a month closed for payroll. */
function lv_closed_guard(PDO $db, string $from, string $to, string $what = 'No new leave'): void {
    for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month')))
        if (atr_month_closed($db, $m)) throw new RuntimeException(date('F Y', strtotime($m . '-01')) . " is closed for payroll. $what can touch that month — speak to HR.");
}

/** The person's own rows that could clash. */
function lv_rows_for(PDO $db, string $code, string $from, string $to, int $except = 0): array {
    $s = $db->prepare("SELECT * FROM leave_request WHERE emp_code = ? AND status IN ('manager','hr','approved') AND from_date <= ? AND to_date >= ? AND id <> ?");
    $s->execute([$code, $to, $from, $except]);
    return $s->fetchAll();
}

/** Login of an employee code, or 0. */
function lv_login_of(PDO $db, string $code): int {
    $s = $db->prepare('SELECT id FROM users WHERE emp_code = ? AND active = 1'); $s->execute([$code]);
    return (int)($s->fetchColumn() ?: 0);
}

/**
 * Apply. $u = who is applying; $emp = whose leave. HR applying for someone else: never their own code, inside their
 * branches; $approved = record leave approved outside (with a type). Returns the id.
 */
function lv_create(PDO $db, array $u, array $emp, array $in, bool $approved = false): int {
    $self = strcasecmp($emp['code'], (string)($u['emp_code'] ?? '')) === 0;
    if (!$self) {
        if (!lv_is_hr($u)) throw new RuntimeException('Only HR applies leave for someone else.');
        if (!emp_in_scope($u, $emp)) throw new RuntimeException('This person is outside your branches.');
    }
    if ($approved && $self) throw new RuntimeException('Your own leave goes through your manager and HR.');
    $set = lv_settings();
    $today = date('Y-m-d');
    $sh = shift_for($db, $emp, (string)($in['from'] ?? $today));
    $c = lv_check($in, $today, $set, $sh['flexible'] ? null : [$sh['start'], $sh['end']]);
    if ($emp['doj'] && $c['from'] < $emp['doj']) throw new RuntimeException('That is before the joining date.');
    if ($emp['last_day'] && $c['to'] > $emp['last_day']) throw new RuntimeException('That is after the last working day.');
    lv_closed_guard($db, $c['from'], $c['to']);
    if ($cl = lv_clash_list($c, lv_rows_for($db, $emp['code'], $c['from'], $c['to']))) throw new RuntimeException(lv_clash_text($cl));
    $timed = in_array($c['fs'], LV_TIMED, true);
    $days = $timed ? 0.0 : lv_days($c['from'], $c['fs'], $c['to'], $c['ts'], lv_off_days($db, $emp, $c['from'], $c['to']));
    if (!$timed && $days <= 0) throw new RuntimeException('Those days are weekly offs or holidays — nothing to apply for.');
    $types = lv_types($db);
    $req = strtoupper(trim((string)($in['type'] ?? '')));
    if ($req !== '' && !in_array($req, lv_allowed($types, $c, $emp['dob'], $emp['confirm_date']), true) && !($approved && in_array($req, array_column($types, 'code'), true)))
        throw new RuntimeException('That leave type is not open for these dates.');
    $req = $timed || $req === '' ? null : $req;
    if ($approved && !$timed && !$req) throw new RuntimeException('Pick the leave type it was approved as.');
    $hand = (int)($in['handover'] ?? 0);
    $who = lv_login_of($db, $emp['code']);
    if ($hand) {
        $s = $db->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND active = 1'); $s->execute([$hand]);
        if ($hand === $who || !(int)$s->fetchColumn()) throw new RuntimeException('Choose someone else from the list to look after the work.');
    }
    $mgr = $approved ? null : atr_manager_login($db, $emp);
    if ($mgr === $who || $mgr === (int)$u['id']) $mgr = null;
    $status = $approved ? 'approved' : ($mgr ? 'manager' : 'hr');
    $db->prepare('INSERT INTO leave_request (user_id, emp_code, branch_id, from_date, from_session, to_date, to_session, days, time_from, time_to, minutes,
                  req_type, leave_type, reason, handover_user_id, status, manager_id, hr_by, hr_at, hr_note, mgr_skipped, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$who, $emp['code'], $emp['branch_id'], $c['from'], $c['fs'], $c['to'], $c['ts'], $days, $c['tf'], $c['tt'],
                  $timed ? lv_timed_minutes($c['fs'], $c['tf'], $c['tt'], $sh['flexible'] ? null : [$sh['start'], $sh['end']]) : null,
                  $approved ? null : $req, $approved ? $req : null, $c['reason'], $hand ?: null, $status, $mgr,
                  $approved ? (int)$u['id'] : null, $approved ? date('Y-m-d H:i:s') : null, $approved ? mb_substr('Recorded by HR – approved earlier. ' . trim((string)($in['note'] ?? '')), 0, 500) : '',
                  $approved ? 1 : 0, (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    audit((int)$u['id'], $approved ? 'leave_record_approved' : 'leave_apply', '#' . $id . ' ' . $emp['code'] . ' ' . $c['from'] . '..' . $c['to']);
    return $id;
}

/** Approve / reject (manager or HR) / cancel (the person, while waiting). $type = HR's category on approval. */
function lv_act(PDO $db, array $u, int $id, string $act, string $note = '', string $type = ''): string {
    $note = trim(mb_substr(strip_tags($note), 0, 500));
    $uid = (int)$u['id'];
    $types = array_column(lv_types($db), 'code');
    if ($act === 'cancel' && ($pre = lv_get($db, $id))) lv_begun_guard($db, $u, $pre);
    $db->beginTransaction();
    try {
        $s = $db->prepare('SELECT * FROM leave_request WHERE id = ? FOR UPDATE'); $s->execute([$id]);
        $r = $s->fetch();
        if (!$r) throw new RuntimeException('Application not found.');
        $own = (int)$r['user_id'] === $uid || strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0;
        if ($act === 'cancel') {
            if (!$own && (int)$r['created_by'] !== $uid) throw new RuntimeException('Only the person who applied can withdraw it.');
            if ($r['status'] === 'approved') throw new RuntimeException('It is already approved — ask to withdraw instead.');
            if (!in_array($r['status'], ['manager', 'hr'], true)) throw new RuntimeException('Only a waiting application can be withdrawn.');
            $new = 'cancelled';
            $db->prepare("UPDATE leave_request SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        } elseif (!in_array($act, ['approve', 'reject'], true)) {
            throw new RuntimeException('Unknown action.');
        } elseif ($own) {
            throw new RuntimeException('You cannot decide your own leave.');
        } elseif ($act === 'reject' && $note === '') {
            throw new RuntimeException('Write why it is rejected.');
        } elseif ($r['status'] === 'manager' && (int)$r['manager_id'] === $uid) {
            $new = $act === 'approve' ? 'hr' : 'rejected';
            $db->prepare('UPDATE leave_request SET status = ?, mgr_by = ?, mgr_at = NOW(), mgr_note = ? WHERE id = ?')->execute([$new, $uid, $note, $id]);
        } elseif (in_array($r['status'], ['manager', 'hr'], true)) {
            if (!lv_is_hr($u)) throw new RuntimeException('This is waiting for ' . ($r['status'] === 'manager' ? 'the reporting manager.' : 'HR.'));
            if (!emp_in_scope($u, ['branch_id' => $r['branch_id']])) throw new RuntimeException('This person is outside your branches.');
            $new = $act === 'approve' ? 'approved' : 'rejected';
            $t = strtoupper(trim($type)) ?: (string)$r['req_type'];
            if ($new === 'approved' && !in_array($r['from_session'], LV_TIMED, true) && $t !== '' && !in_array($t, $types, true)) throw new RuntimeException('Unknown leave type.');
            $db->prepare('UPDATE leave_request SET status = ?, hr_by = ?, hr_at = NOW(), hr_note = ?, mgr_skipped = ?, leave_type = ? WHERE id = ?')
               ->execute([$new, $uid, $note, $r['status'] === 'manager' ? 1 : (int)$r['mgr_skipped'], $new === 'approved' && $t !== '' && !in_array($r['from_session'], LV_TIMED, true) ? $t : $r['leave_type'], $id]);
        } else {
            throw new RuntimeException('This application is already ' . strtolower(LV_STATUS[$r['status']]) . '.');
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit($uid, 'leave_' . $act . (($r['status'] === 'manager' && $new !== 'hr' && $act !== 'cancel') ? '_skip_manager' : ''), '#' . $id . ' → ' . $new);
    return $new;
}

/** Days of this leave already begun without a punch: only the Super Admin / HR Manager may withdraw then. */
function lv_begun_guard(PDO $db, array $u, array $r): void {
    if (lv_super($u)) return;
    $days = lv_begun_days($r, date('Y-m-d'));
    if (!$days) return;
    $s = $db->prepare('SELECT DISTINCT day FROM attendance_punch WHERE emp_code = ? AND day BETWEEN ? AND ?');
    $s->execute([$r['emp_code'], min($days), max($days)]);
    $punched = array_flip(array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($days as $d) if (!isset($punched[$d]))
        throw new RuntimeException('A day of this leave has passed with no attendance, so only the Super Admin or HR Manager can withdraw it.');
}

/** The person asks to withdraw approved leave (reason). Goes manager → HR; the leave stays approved meanwhile. */
function lv_withdraw_request(PDO $db, array $u, int $id, string $reason): string {
    $r = lv_get($db, $id);
    if (!$r || ((int)$r['user_id'] !== (int)$u['id'] && (int)$r['created_by'] !== (int)$u['id'])) throw new RuntimeException('Not your application.');
    if ($r['status'] !== 'approved') throw new RuntimeException('Only approved leave needs a withdrawal request.');
    if (in_array($r['wd_status'], ['manager', 'hr'], true)) throw new RuntimeException('A withdrawal request is already waiting.');
    $reason = trim(mb_substr(strip_tags($reason), 0, 500));
    if (mb_strlen($reason) < 3) throw new RuntimeException('Write why you want to withdraw it.');
    lv_closed_guard($db, $r['from_date'], $r['to_date'], 'No withdrawal');
    lv_begun_guard($db, $u, $r);
    $emp = emp_by_code($db, $r['emp_code']);
    $mgr = $emp ? atr_manager_login($db, $emp) : null;
    if ($mgr === (int)$r['user_id']) $mgr = null;
    $st = $mgr ? 'manager' : 'hr';
    $db->prepare('UPDATE leave_request SET wd_status = ?, wd_reason = ?, wd_at = NOW(), wd_by = ?, wd_manager_id = ?, wd_mgr_by = NULL, wd_mgr_at = NULL, wd_mgr_note = \'\', wd_hr_by = NULL, wd_hr_at = NULL, wd_hr_note = \'\' WHERE id = ?')
       ->execute([$st, $reason, (int)$u['id'], $mgr, $id]);
    audit((int)$u['id'], 'leave_withdraw_request', '#' . $id);
    return $st;
}

/** Decide a withdrawal request: agree (leave withdrawn) or keep (note needed). HR may skip the manager. */
function lv_withdraw_act(PDO $db, array $u, int $id, string $act, string $note = ''): string {
    $note = trim(mb_substr(strip_tags($note), 0, 500)); $uid = (int)$u['id'];
    $db->beginTransaction();
    try {
        $s = $db->prepare('SELECT * FROM leave_request WHERE id = ? FOR UPDATE'); $s->execute([$id]);
        $r = $s->fetch();
        if (!$r || $r['status'] !== 'approved' || !in_array($r['wd_status'], ['manager', 'hr'], true)) throw new RuntimeException('No withdrawal request waiting.');
        if ($act === 'takeback') {
            if ((int)$r['wd_by'] !== $uid) throw new RuntimeException('Only the person who asked can take it back.');
            $db->prepare('UPDATE leave_request SET wd_status = NULL WHERE id = ?')->execute([$id]);
            $new = 'kept';
        } else {
            if ((int)$r['user_id'] === $uid || strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('You cannot decide your own.');
            if (!in_array($act, ['agree', 'keep'], true)) throw new RuntimeException('Unknown action.');
            if ($act === 'keep' && $note === '') throw new RuntimeException('Write why the leave is kept.');
            if ($r['wd_status'] === 'manager' && (int)$r['wd_manager_id'] === $uid) {
                $new = $act === 'agree' ? 'hr' : 'rejected';
                $db->prepare('UPDATE leave_request SET wd_status = ?, wd_mgr_by = ?, wd_mgr_at = NOW(), wd_mgr_note = ? WHERE id = ?')->execute([$new, $uid, $note, $id]);
            } else {
                if (!lv_is_hr($u) || !emp_in_scope($u, ['branch_id' => $r['branch_id']])) throw new RuntimeException('This is waiting for ' . ($r['wd_status'] === 'manager' ? 'the reporting manager.' : 'HR.'));
                $new = $act === 'agree' ? 'done' : 'rejected';
                $db->prepare('UPDATE leave_request SET wd_status = ?, wd_hr_by = ?, wd_hr_at = NOW(), wd_hr_note = ?' . ($new === 'done' ? ", status = 'withdrawn'" : '') . ' WHERE id = ?')->execute([$new, $uid, $note, $id]);
            }
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit($uid, 'leave_withdraw_' . $act, '#' . $id . ' → ' . $new);
    return $new;
}

/** Super Admin / HR Manager withdraw any waiting or approved leave themselves, with a note; never their own. */
function lv_hr_withdraw(PDO $db, array $u, int $id, string $note): string {
    if (!lv_super($u)) throw new RuntimeException('Only the Super Admin or HR Manager does this.');
    $note = trim(mb_substr(strip_tags($note), 0, 500));
    if ($note === '') throw new RuntimeException('Write a note.');
    $r = lv_get($db, $id);
    if (!$r || !in_array($r['status'], ['manager', 'hr', 'approved'], true)) throw new RuntimeException('Nothing to withdraw.');
    if (strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Not for your own leave.');
    if (!emp_in_scope($u, ['branch_id' => $r['branch_id']])) throw new RuntimeException('This person is outside your branches.');
    $new = $r['status'] === 'approved' ? 'withdrawn' : 'cancelled';
    $db->prepare('UPDATE leave_request SET status = ?, wd_status = ?, wd_hr_by = ?, wd_hr_at = NOW(), wd_hr_note = ? WHERE id = ?')
       ->execute([$new, $new === 'withdrawn' ? 'done' : $r['wd_status'], (int)$u['id'], $note, $id]);
    audit((int)$u['id'], 'leave_hr_withdraw', '#' . $id);
    return $new;
}

/** HR sets / changes the category of approved or waiting leave; never their own. */
function lv_set_type(PDO $db, array $u, int $id, string $type): void {
    if (!lv_is_hr($u)) throw new RuntimeException('Only HR sets the leave type.');
    $r = lv_get($db, $id);
    if (!$r || in_array($r['from_session'], LV_TIMED, true) || !in_array($r['status'], ['hr', 'approved'], true)) throw new RuntimeException('That application takes no leave type.');
    if (strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Not for your own leave.');
    if (!emp_in_scope($u, ['branch_id' => $r['branch_id']])) throw new RuntimeException('This person is outside your branches.');
    $type = strtoupper(trim($type));
    if ($type !== '' && !in_array($type, array_column(lv_types($db), 'code'), true)) throw new RuntimeException('Unknown leave type.');
    $col = $r['status'] === 'approved' ? 'leave_type' : 'req_type';
    $db->prepare("UPDATE leave_request SET $col = ? WHERE id = ?")->execute([$type ?: null, $id]);
    audit((int)$u['id'], 'leave_set_type', '#' . $id . ' ' . ($type ?: 'none'));
}

/** Lists for the screen. */
function lv_lists(PDO $db, array $u): array {
    $uid = (int)$u['id']; $code = (string)($u['emp_code'] ?? '');
    $q = function (string $sql, array $a = []) use ($db) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchAll(); };
    $sel = 'SELECT r.*, e.name FROM leave_request r LEFT JOIN employee e ON e.code = r.emp_code';
    $notOwn = fn(array $rows) => array_values(array_filter($rows, fn($r) => (int)$r['user_id'] !== $uid && strcasecmp($r['emp_code'], $code) !== 0));
    $o = ['mine' => $code !== '' ? $q("$sel WHERE r.emp_code = ? ORDER BY r.from_date DESC, r.id DESC LIMIT 100", [$code]) : [],
          'manager' => $notOwn($q("$sel WHERE r.manager_id = ? AND r.status = 'manager' ORDER BY r.from_date", [$uid])),
          'wd_manager' => $notOwn($q("$sel WHERE r.status = 'approved' AND r.wd_status = 'manager' AND r.wd_manager_id = ? ORDER BY r.from_date", [$uid])),
          'handover' => $q("$sel WHERE r.handover_user_id = ? AND r.status IN ('manager','hr','approved') AND r.to_date >= CURDATE() ORDER BY r.from_date", [$uid]),
          'byme' => $notOwn($q("$sel WHERE r.created_by = ? AND r.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY r.id DESC LIMIT 100", [$uid])),
          'hr' => [], 'with_manager' => [], 'wd_hr' => [], 'wd_with_manager' => [], 'upcoming' => [], 'decided' => []];
    if (lv_is_hr($u)) {
        $in = fn(array $rows) => array_values(array_filter($notOwn($rows), fn($r) => emp_in_scope($u, ['branch_id' => $r['branch_id']])));
        $o['hr'] = $in($q("$sel WHERE r.status = 'hr' ORDER BY r.from_date"));
        $o['with_manager'] = $in($q("$sel WHERE r.status = 'manager' AND COALESCE(r.manager_id, 0) <> ? ORDER BY r.from_date", [$uid]));
        $o['wd_hr'] = $in($q("$sel WHERE r.status = 'approved' AND r.wd_status = 'hr' ORDER BY r.from_date"));
        $o['wd_with_manager'] = $in($q("$sel WHERE r.status = 'approved' AND r.wd_status = 'manager' AND COALESCE(r.wd_manager_id, 0) <> ? ORDER BY r.from_date", [$uid]));
        $o['upcoming'] = $in($q("$sel WHERE r.status = 'approved' AND r.to_date >= CURDATE() ORDER BY r.from_date LIMIT 300"));
        $o['decided'] = $in($q("$sel WHERE r.status IN ('approved','rejected','withdrawn') AND COALESCE(r.hr_at, r.mgr_at, r.created_at) >= DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY COALESCE(r.hr_at, r.mgr_at) DESC LIMIT 200"));
    }
    return $o;
}

/** Waiting for this login's decision. */
function lv_waiting(PDO $db, array $u): int {
    try { $l = lv_lists($db, $u); return count($l['manager']) + count($l['wd_manager']) + count($l['hr']) + count($l['wd_hr']); } catch (Throwable $e) { return 0; }
}

/** Approved leave for a code by day: day => [halves, type, times[], id]. */
function lv_by_day(PDO $db, string $code, string $from, string $to): array {
    $s = $db->prepare("SELECT * FROM leave_request WHERE emp_code = ? AND status = 'approved' AND from_date <= ? AND to_date >= ? ORDER BY id");
    $s->execute([$code, $to, $from]);
    $m = [];
    foreach ($s->fetchAll() as $r) foreach (lv_halves($r['from_date'], $r['from_session'], $r['to_date'], $r['to_session']) as $d => $h) {
        if ($d < $from || $d > $to) continue;
        $p = $m[$d] ?? ['halves' => [false, false], 'type' => '', 'times' => [], 'id' => (int)$r['id']];
        $p['halves'] = [$p['halves'][0] || $h[0], $p['halves'][1] || $h[1]];
        if (in_array($r['from_session'], LV_TIMED, true)) $p['times'][] = ['kind' => $r['from_session'], 'from' => $r['time_from'], 'to' => $r['time_to']];
        else $p['type'] = (string)$r['leave_type'];
        $m[$d] = $p;
    }
    return $m;
}

/** A person's balance for the leave year holding $day. */
function lv_balance_for(PDO $db, array $emp, ?string $day = null): array {
    $year = lv_year($day ?? date('Y-m-d'), (int)(org('fy_start_month') ?: 1));
    $q = function (string $col, string $st) use ($db, $emp, $year) {
        $s = $db->prepare("SELECT $col AS t, SUM(days) AS d FROM leave_request WHERE emp_code = ? AND $st AND from_date BETWEEN ? AND ? AND $col IS NOT NULL GROUP BY $col");
        $s->execute([$emp['code'], $year[0], $year[1]]);
        return array_column($s->fetchAll(), 'd', 't');
    };
    $adj = $db->prepare('SELECT type_code, SUM(days) AS d FROM leave_adjust WHERE emp_code = ? AND year_from = ? GROUP BY type_code');
    $adj->execute([$emp['code'], $year[0]]);
    return ['year' => $year, 'rows' => lv_balance(lv_types($db), $year, $emp['doj'], $emp['confirm_date'],
        $q('leave_type', "status = 'approved'"), $q('req_type', "status IN ('manager','hr')"), array_column($adj->fetchAll(), 'd', 'type_code'))];
}

/** HR adds or takes days off a balance (append-only, reason needed); never their own. */
function lv_adjust(PDO $db, array $u, array $emp, string $type, float $days, string $note): void {
    if (!lv_super($u)) throw new RuntimeException('Only the Super Admin or HR Manager changes balances.');
    if (strcasecmp($emp['code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Not for your own balance.');
    if (!emp_in_scope($u, $emp)) throw new RuntimeException('This person is outside your branches.');
    if (!in_array(strtoupper($type), array_column(lv_types($db), 'code'), true)) throw new RuntimeException('Unknown leave type.');
    if ($days == 0 || abs($days) > 366 || fmod($days * 2, 1) != 0) throw new RuntimeException('Days must be in halves, not zero.');
    $note = trim(mb_substr(strip_tags($note), 0, 300));
    if ($note === '') throw new RuntimeException('Write the reason.');
    $year = lv_year(date('Y-m-d'), (int)(org('fy_start_month') ?: 1));
    $db->prepare('INSERT INTO leave_adjust (emp_code, type_code, year_from, days, note, by_user) VALUES (?,?,?,?,?,?)')->execute([$emp['code'], strtoupper($type), $year[0], $days, $note, (int)$u['id']]);
    audit((int)$u['id'], 'leave_adjust', $emp['code'] . ' ' . strtoupper($type));
}

/** Check a leave type form. Pure. */
function lv_type_check(array $in): array {
    $code = strtoupper(trim((string)($in['code'] ?? '')));
    if (!preg_match('/^[A-Z]{1,8}$/', $code)) throw new InvalidArgumentException('Code: 1–8 letters (e.g. CL).');
    $name = trim(strip_tags((string)($in['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 60) throw new InvalidArgumentException('Give a name up to 60 characters.');
    $per = trim((string)($in['per_year'] ?? ''));
    if ($per !== '' && (!is_numeric($per) || $per < 0 || $per > 366 || fmod((float)$per * 2, 1) != 0)) throw new InvalidArgumentException('Days a year: blank for no limit, else 0–366 in halves.');
    return ['code' => $code, 'name' => $name, 'paid' => !empty($in['paid']) ? 1 : 0, 'per_year' => $per === '' ? null : (float)$per,
        'prorate' => !empty($in['prorate']) ? 1 : 0, 'after_confirm' => !empty($in['after_confirm']) ? 1 : 0,
        'on_day' => ($in['on_day'] ?? '') === 'birthday' ? 'birthday' : '', 'apply_choice' => !empty($in['apply_choice']) ? 1 : 0, 'sort' => (int)($in['sort'] ?? 0)];
}

function lv_type_save(PDO $db, array $u, array $in): void {
    if (!lv_super($u)) throw new RuntimeException('Only the Super Admin or HR Manager sets leave types.');
    $t = lv_type_check($in);
    $db->prepare('INSERT INTO leave_type (code, name, paid, per_year, prorate, after_confirm, on_day, apply_choice, sort) VALUES (?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE name = VALUES(name), paid = VALUES(paid), per_year = VALUES(per_year), prorate = VALUES(prorate),
                  after_confirm = VALUES(after_confirm), on_day = VALUES(on_day), apply_choice = VALUES(apply_choice), sort = VALUES(sort), active = 1, off_reason = \'\'')
       ->execute(array_values($t));
    audit((int)$u['id'], 'leave_type_save', $t['code']);
}

function lv_type_off(PDO $db, array $u, string $code, string $reason): void {
    if (!lv_super($u)) throw new RuntimeException('Only the Super Admin or HR Manager sets leave types.');
    if (trim($reason) === '') throw new InvalidArgumentException('Give a reason.');
    $db->prepare('UPDATE leave_type SET active = 0, off_reason = ? WHERE code = ?')->execute([mb_substr(trim($reason), 0, 200), strtoupper($code)]);
    audit((int)$u['id'], 'leave_type_off', strtoupper($code));
}

/** People to hand work to: active logins, name + department only, never oneself. */
function lv_handover_people(PDO $db, int $except): array {
    $s = $db->prepare('SELECT u.id, u.name, COALESCE(e.department, \'\') AS dept FROM users u LEFT JOIN employee e ON e.code = u.emp_code
                       WHERE u.active = 1 AND u.id <> ? AND (e.code IS NULL OR e.last_day IS NULL OR e.last_day >= CURDATE()) ORDER BY u.name');
    $s->execute([$except]);
    return $s->fetchAll();
}

/** Waiting leave in a month (blocks its close). */
function lv_month_blockers(PDO $db, string $ym): int {
    $s = $db->prepare("SELECT COUNT(*) FROM leave_request WHERE (status IN ('manager','hr') OR wd_status IN ('manager','hr')) AND from_date <= ? AND to_date >= ?");
    $s->execute([date('Y-m-t', strtotime($ym . '-01')), $ym . '-01']);
    return (int)$s->fetchColumn();
}

/** "12 Nov" / "12–14 Nov (second half → first half)" / "12 Nov · Late coming 10:30". */
function lv_span_text(array $r): string {
    $f = date('j M', strtotime($r['from_date'])); $t = date('j M Y', strtotime($r['to_date']));
    if ($r['from_date'] === $r['to_date']) return date('j M Y', strtotime($r['from_date'])) . ' · ' . lv_part_label($r, $r['from_date']);
    $p = ($r['from_session'] === 'second' ? 'from 2nd half' : '') . ($r['to_session'] === 'first' ? ($r['from_session'] === 'second' ? ', ' : '') . 'to 1st half' : '');
    return "$f – $t" . ($p !== '' ? " ($p)" : '');
}
