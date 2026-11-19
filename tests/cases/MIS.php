<?php
// HR dashboard (MIS) rules (lib/mis.php) — synthetic data only.
require_once WN_ROOT . '/lib/mis.php';
$E = fn($doj, $last = null, $act = 1, $resign = null, $dept = 'TEST Ops', $br = 1) => ['doj' => $doj, 'last_day' => $last, 'active' => $act, 'resign_date' => $resign, 'department' => $dept, 'branch_id' => $br, 'confirm_date' => null, 'code' => 'T' . mt_rand()];
$emps = [$E('2025-01-10'), $E('2026-11-03'), $E('2026-12-01'), $E('2024-05-01', '2026-11-10'), $E('2024-05-01', '2026-11-30', 1, '2026-11-01', 'TEST Sales', 2), $E(null), $E('2024-01-01', null, 0)];
t('MIS01 on the roll: switched on, joined by the day, last day not before it', mis_headcount($emps, '2026-11-19') === 4 && mis_headcount($emps, '2026-11-09') === 5 && mis_headcount($emps, '2026-12-02') === 4);
t('MIS02 joiners and exits by month; on notice = resigned and still on the roll', mis_moves($emps, '2026-11') === ['joined' => 1, 'left' => 2] && mis_notice($emps, '2026-11-19') === 1);
$tr = mis_trend($emps, '2026-11', 3, '2026-11-19');
t('MIS03 trend: month-end headcount (today for this month), joined, left', array_keys($tr) === ['2026-09', '2026-10', '2026-11'] && $tr['2026-10']['headcount'] === 4 && $tr['2026-11']['headcount'] === 4 && $tr['2026-11']['left'] === 2);
$a = mis_attrition($emps, '2026-11-19', 12);
t('MIS04 attrition = exits ÷ average headcount; retention of those on the roll at the start', $a['left'] === 1 && $a['attrition'] !== null && $a['retention'] === 75.0 && mis_attrition([], '2026-11-19', 12)['attrition'] === null);
t('MIS05 by branch / department, blanks as Not set', mis_by($emps, '2026-11-19', fn($e) => $e['department']) === ['TEST Ops' => 3, 'TEST Sales' => 1] && mis_by([$E('2020-01-01', null, 1, null, '')], '2026-11-19', fn($e) => $e['department']) === ['Not set' => 1]);
$L = mis_leave([['emp_code' => 'A', 'status' => 'approved', 'from_date' => '2026-11-18', 'to_date' => '2026-11-20', 'days' => '3', 'leave_type' => 'CL'], ['emp_code' => 'A', 'status' => 'approved', 'from_date' => '2026-11-19', 'to_date' => '2026-11-19', 'days' => '0.5', 'leave_type' => null],
    ['emp_code' => 'B', 'status' => 'approved', 'from_date' => '2026-11-22', 'to_date' => '2026-11-22', 'days' => '1', 'leave_type' => 'SL'], ['emp_code' => 'C', 'status' => 'hr', 'from_date' => '2026-11-19', 'to_date' => '2026-11-19', 'days' => '1', 'leave_type' => 'CL']], '2026-11-19');
t('MIS06 leave: used by type (no category apart), each person once on leave today, starts in 7 days, waiting ones not counted', $L['byType'] === ['CL' => 3.0, 'No category yet' => 0.5, 'SL' => 1.0] && $L['today'] === 1 && $L['next7'] === 1);
t('MIS07 pending desks: zero ones dropped, biggest first; year start by the organisation\'s first month', mis_desks(['A' => 0, 'B' => 2, 'C' => 5]) === ['C' => 5, 'B' => 2] && mis_year_start('2026-11-19', 4) === '2026-04-01' && mis_year_start('2026-02-19', 4) === '2025-04-01' && mis_year_start('2026-02-19', 1) === '2026-01-01');
t('MIS08 settings kept in range', mis_settings_check(['trend_months' => '99', 'probation_days' => '1']) === ['trend_months' => 24, 'probation_days' => 7, 'dues_days' => 30, 'attrition_months' => 12]);
$src = (string)file_get_contents(WN_ROOT . '/lib/mis.php') . file_get_contents(WN_ROOT . '/modules/mis/index.php');
t('MIS09 read only: no writes outside its own settings; never reads names or pay', !preg_match('/\bINSERT\s+INTO\s+(?!module_state)|\bUPDATE\s+\w+\s+SET\b/i', $src) && !preg_match('/\bDELETE\b|e\.name|pay_snapshot|pay_emp|ctc/i', $src));
