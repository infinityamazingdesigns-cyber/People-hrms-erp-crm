<?php
// HR dashboard (MIS) — read only, counts only. Workforce (on the roll, joiners, exits, notice, attrition, by branch /
// department, six-month trend), today's attendance, leave (waiting, on leave, used this year by type, as totals),
// probation, pending work desk by desk, statutory dues (dates and schemes, never amounts). Never a name, a code or a pay
// figure; cut to the viewer's branch scope; a section shows only while its module is on. Pure functions first.
declare(strict_types=1);

require_once __DIR__ . '/employees.php';

const MIS_DEFAULTS = ['trend_months' => 6, 'probation_days' => 30, 'dues_days' => 30, 'attrition_months' => 12];

/* ---------------- pure ---------------- */

function mis_settings(): array { $s = module_states()['mis']['settings'] ?? []; return array_replace(MIS_DEFAULTS, array_intersect_key($s, MIS_DEFAULTS)); }
function mis_settings_check(array $in): array {
    return ['trend_months' => max(3, min(24, (int)($in['trend_months'] ?? 6))), 'probation_days' => max(7, min(90, (int)($in['probation_days'] ?? 30))),
            'dues_days' => max(7, min(90, (int)($in['dues_days'] ?? 30))), 'attrition_months' => max(3, min(24, (int)($in['attrition_months'] ?? 12)))];
}
/** On the roll on $day: switched on, joined on or before it (or no date), last day not before it. */
function mis_on_roll(array $e, string $day): bool {
    return (int)$e['active'] === 1 && ($e['doj'] === null || $e['doj'] <= $day) && ($e['last_day'] === null || $e['last_day'] >= $day);
}
function mis_headcount(array $emps, string $day): int { return count(array_filter($emps, fn($e) => mis_on_roll($e, $day))); }
function mis_month_end(string $ym, string $today): string { $e = date('Y-m-t', strtotime($ym . '-01')); return $e > $today ? $today : $e; }
/** Joiners and exits in a month (exits = last working day in it). */
function mis_moves(array $emps, string $ym): array {
    $j = 0; $x = 0;
    foreach ($emps as $e) { if ($e['doj'] !== null && substr($e['doj'], 0, 7) === $ym) $j++; if ($e['last_day'] !== null && substr($e['last_day'], 0, 7) === $ym) $x++; }
    return ['joined' => $j, 'left' => $x];
}
/** Months ending $ym going back $n: [ym => [headcount at month end, joined, left]]. */
function mis_trend(array $emps, string $ym, int $n, string $today): array {
    $o = [];
    for ($i = $n - 1; $i >= 0; $i--) { $m = date('Y-m', strtotime($ym . '-01 -' . $i . ' month')); $o[$m] = ['headcount' => mis_headcount($emps, mis_month_end($m, $today))] + mis_moves($emps, $m); }
    return $o;
}
/** Exits in the last $months ÷ average headcount at those month ends × 100; retention = those on the roll at the start still on it. */
function mis_attrition(array $emps, string $today, int $months): array {
    $start = date('Y-m-d', strtotime($today . ' -' . $months . ' month')); $heads = []; $left = 0;
    for ($i = $months - 1; $i >= 0; $i--) $heads[] = mis_headcount($emps, mis_month_end(date('Y-m', strtotime($today . ' -' . $i . ' month')), $today));
    foreach ($emps as $e) if ($e['last_day'] !== null && $e['last_day'] > $start && $e['last_day'] <= $today) $left++;
    $avg = $heads ? array_sum($heads) / count($heads) : 0;
    $atStart = array_filter($emps, fn($e) => mis_on_roll($e, $start)); $stay = count(array_filter($atStart, fn($e) => mis_on_roll($e, $today)));
    return ['left' => $left, 'attrition' => $avg > 0 ? round(100 * $left / $avg, 1) : null, 'retention' => $atStart ? round(100 * $stay / count($atStart), 1) : null];
}
/** On the roll on $day counted by a field (branch name, department …); blanks as "Not set". */
function mis_by(array $emps, string $day, callable $key): array {
    $o = []; foreach ($emps as $e) if (mis_on_roll($e, $day)) { $k = (string)$key($e); $k = $k !== '' ? $k : 'Not set'; $o[$k] = ($o[$k] ?? 0) + 1; }
    arsort($o); return $o;
}
function mis_notice(array $emps, string $day): int { return count(array_filter($emps, fn($e) => mis_on_roll($e, $day) && $e['resign_date'] !== null && $e['last_day'] !== null)); }
/** Leave rows → days used by type, on leave today, starting in the next 7 days. Half-day rows count their days. */
function mis_leave(array $rows, string $today): array {
    $o = ['byType' => [], 'today' => 0, 'next7' => 0]; $wk = date('Y-m-d', strtotime($today . ' +7 day')); $seen = [];
    foreach ($rows as $r) {
        if ($r['status'] !== 'approved') continue;
        $t = (string)($r['leave_type'] ?: 'No category yet'); $o['byType'][$t] = round(($o['byType'][$t] ?? 0) + (float)$r['days'], 1);
        if ($r['from_date'] <= $today && $r['to_date'] >= $today && !isset($seen[$r['emp_code']])) { $o['today']++; $seen[$r['emp_code']] = 1; }
        if ($r['from_date'] > $today && $r['from_date'] <= $wk) $o['next7']++;
    }
    ksort($o['byType']); return $o;
}
/** Pending work desk by desk: keep the non-zero ones, biggest first. */
function mis_desks(array $counts): array { $c = array_filter($counts, fn($n) => (int)$n > 0); arsort($c); return $c; }
/** Year start (first day of the organisation's year) for $today. */
function mis_year_start(string $today, int $startMonth): string {
    $y = (int)substr($today, 0, 4); $m = (int)substr($today, 5, 2); if ($startMonth < 1 || $startMonth > 12) $startMonth = 1;
    return sprintf('%04d-%02d-01', $m >= $startMonth ? $y : $y - 1, $startMonth);
}

/* ---------------- database ---------------- */

/** Scope as SQL: '' (all) or " AND alias.branch_id IN (…)". */
function mis_scope_sql(array $u, string $alias = 'e'): string { $s = emp_scope($u); return $s === null ? '' : ' AND ' . $alias . '.branch_id IN (' . (implode(',', array_map('intval', $s)) ?: '0') . ')'; }
function mis_count(PDO $db, string $sql, array $p = []): int { try { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); } catch (Throwable $e) { return 0; } }

function mis_payload(PDO $db, array $u, string $today): array {
    $set = mis_settings(); $sc = mis_scope_sql($u); $ym = substr($today, 0, 7);
    $emps = $db->query('SELECT e.id, e.code, e.department, e.branch_id, e.doj, e.confirm_date, e.resign_date, e.last_day, e.active FROM employee e WHERE 1=1' . $sc)->fetchAll();
    $bn = array_column(branches(false), 'name', 'id');
    $att = mis_attrition($emps, $today, (int)$set['attrition_months']);
    $o = ['today' => $today, 'roll' => mis_headcount($emps, $today), 'moves' => mis_moves($emps, $ym), 'notice' => mis_notice($emps, $today), 'att' => $att,
          'trend' => mis_trend($emps, $ym, (int)$set['trend_months'], $today), 'byBranch' => mis_by($emps, $today, fn($e) => $bn[$e['branch_id']] ?? ''),
          'byDept' => mis_by($emps, $today, fn($e) => $e['department']), 'sections' => []];
    $codes = array_column(array_filter($emps, fn($e) => mis_on_roll($e, $today)), 'code');
    $in = $codes ? implode(',', array_map(fn($c) => $db->quote((string)$c), $codes)) : "''";
    if (module_on('attendance')) {
        $present = mis_count($db, "SELECT COUNT(DISTINCT emp_code) FROM attendance_punch WHERE day = ? AND emp_code IN ($in)", [$today]);
        $o['sections']['attendance'] = ['present' => $present];
    }
    if (module_on('leave')) {
        $from = mis_year_start($today, (int)(org('fy_start_month') ?: 1));
        $s = $db->prepare("SELECT emp_code, status, from_date, to_date, days, leave_type FROM leave_request WHERE emp_code IN ($in) AND to_date >= ?"); $s->execute([$from]);
        $L = mis_leave($s->fetchAll(), $today);
        $L['manager'] = mis_count($db, "SELECT COUNT(*) FROM leave_request WHERE status = 'manager' AND emp_code IN ($in)");
        $L['hr'] = mis_count($db, "SELECT COUNT(*) FROM leave_request WHERE status = 'hr' AND emp_code IN ($in)");
        $L['withdraw'] = mis_count($db, "SELECT COUNT(*) FROM leave_request WHERE wd_status IN ('manager','hr') AND emp_code IN ($in)");
        $L['from'] = $from; $o['sections']['leave'] = $L;
        if (isset($o['sections']['attendance'])) $o['sections']['attendance']['leave'] = $L['today'];
    }
    if (module_on('probation')) {
        $to = date('Y-m-d', strtotime($today . ' +' . (int)$set['probation_days'] . ' day'));
        $o['sections']['probation'] = [
            'ending' => count(array_filter($emps, fn($e) => mis_on_roll($e, $today) && $e['confirm_date'] !== null && $e['confirm_date'] >= $today && $e['confirm_date'] <= $to)),
            'reviews' => mis_count($db, "SELECT COUNT(*) FROM probation_review WHERE status IN ('manager','hr') AND emp_code IN ($in)"),
            'late' => mis_count($db, "SELECT COUNT(*) FROM probation_review WHERE status IN ('manager','hr') AND due_date < ? AND emp_code IN ($in)", [$today]), 'days' => (int)$set['probation_days']];
    }
    $d = [];
    if (module_on('leave')) { $d['Leave with reporting managers'] = $o['sections']['leave']['manager']; $d['Leave waiting for HR'] = $o['sections']['leave']['hr']; $d['Leave withdrawals'] = $o['sections']['leave']['withdraw']; }
    if (module_on('attendance')) { $d['Attendance corrections with managers'] = mis_count($db, "SELECT COUNT(*) FROM attendance_request WHERE status = 'manager' AND emp_code IN ($in)");
                                   $d['Attendance corrections waiting for HR'] = mis_count($db, "SELECT COUNT(*) FROM attendance_request WHERE status = 'hr' AND emp_code IN ($in)"); }
    if (module_on('probation')) $d['Probation reviews'] = $o['sections']['probation']['reviews'];
    if (module_on('profile')) $d['Profile updates to check'] = mis_count($db, "SELECT COUNT(*) FROM employee_profile_change WHERE status = 'hr' AND emp_code IN ($in)");
    if (module_on('kyc')) $d['KYC & bank to verify'] = mis_count($db, "SELECT COUNT(*) FROM employee_kyc_change WHERE status = 'hr' AND emp_code IN ($in)");
    if (module_on('letters')) $d['Letter requests'] = mis_count($db, "SELECT COUNT(*) FROM letter_request WHERE status = 'open' AND emp_code IN ($in)");
    if (module_on('claims')) { $d['Expense claims to audit'] = mis_count($db, "SELECT COUNT(*) FROM ec_claim WHERE status = 'submitted'"); $d['Expense claims to approve'] = mis_count($db, "SELECT COUNT(*) FROM ec_claim WHERE status = 'audited'");
                               $d['Expense claims to pay'] = mis_count($db, "SELECT COUNT(*) FROM ec_claim WHERE status = 'approved'"); }
    if (module_on('onboarding')) $d['Joiners being onboarded'] = mis_count($db, 'SELECT COUNT(*) FROM onb_joiner e WHERE active = 1 AND employee_id IS NULL' . $sc);
    if (module_on('recruitment')) $d['New applications in the pipeline'] = mis_count($db, "SELECT COUNT(*) FROM rc_application WHERE stage = 'new'");
    $o['desks'] = mis_desks($d);
    if (module_on('statutory') && module_allowed($u, 'statutory')) {
        try {
            require_once WN_ROOT . '/lib/statutory.php';
            $dues = st_open_dues($db, $today, (int)$set['dues_days']);
            $o['sections']['statutory'] = ['dues' => array_map(fn($x) => ['date' => $x['date'], 'scheme' => (string)$x['rule']['scheme'], 'what' => (string)$x['rule']['label'], 'state' => (string)$x['rule']['state'], 'period' => $x['period'], 'late' => $x['late']], $dues), 'days' => (int)$set['dues_days']];
        } catch (Throwable $e) { /* left out */ }
    }
    return $o;
}
