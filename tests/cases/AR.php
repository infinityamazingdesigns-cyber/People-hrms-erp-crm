<?php
// Attendance part 2 rules (lib/att_requests.php) — synthetic data only.
require_once WN_ROOT . '/lib/attendance.php';
t('AR01 times read loosely, nonsense refused', atr_time('9:5') === null && atr_time('905') === '09:05' && atr_time('18.30') === '18:30' && atr_time('xx') === null);
t('AR02 open from: last month until it is closed', atr_open_from('2026-11-12', false) === '2026-10-01' && atr_open_from('2026-11-12', true) === '2026-11-01');
[$c, $e] = atr_check(['day' => '2026-11-05', 'in_time' => '09:30', 'out_time' => '18:30', 'reason' => 'forgot'], '2026-11-12', '2026-10-01', '2025-01-01');
t('AR03 a good request passes', !$e && $c['in_time'] === '09:30');
[, $e] = atr_check(['day' => '2026-11-20', 'reason' => 'forgot'], '2026-11-12', '2026-10-01', null);
t('AR04 days ahead and no times refused', count($e) === 2);
[, $e] = atr_check(['day' => '2026-09-30', 'in_time' => '9:30', 'reason' => 'x'], '2026-11-12', '2026-10-01', null);
t('AR05 closed month and unknown reason refused', count($e) === 2);
[, $e] = atr_check(['day' => '2026-11-05', 'in_time' => '18:00', 'out_time' => '09:00', 'reason' => 'other'], '2026-11-12', '2026-10-01', null);
t('AR06 out before in refused; "other" needs a note', count($e) === 2);
[$c, $e] = atr_check(['day' => '2026-11-05', 'in_time' => '20:00', 'out_time' => '02:00', 'reason' => 'forgot'], '2026-11-12', '2026-10-01', null, true, 4);
t('AR07 flexible: out after midnight becomes 26:00', !$e && $c['out_time'] === '26:00');
$set = ATT_DEFAULTS;
$days = ['2026-11-05' => ['day' => '2026-11-05', 'status' => 'Missed punch', 'in' => '09:30:00', 'out' => null, 'hours' => null, 'punches' => [], 'holiday' => null, 'away_min' => 0]];
$fixed = atr_apply($days, ['2026-11-05' => [null, '18:30']], $set);
t('AR08 an approved correction completes the day', $fixed['2026-11-05']['status'] === 'Present' && !empty($fixed['2026-11-05']['corrected']));
$le = atr_late_early(['a' => ['in' => '09:40:00', 'out' => '18:20:00', 'holiday' => null, 'status' => 'Present'], 'b' => ['in' => '09:34:00', 'out' => '18:30:00', 'holiday' => null, 'status' => 'Present'],
    'c' => ['in' => '11:00:00', 'out' => null, 'holiday' => 'Test', 'status' => 'Holiday · worked']], '09:30', '18:30', 5);
t('AR09 late / early beyond grace; inside grace and holidays are not', $le['a']['late'] === 10 && $le['a']['early'] === 10 && $le['b']['late'] === 0 && $le['c']['late'] === 0);
$mgr = ['id' => 7, 'emp_code' => 'M1', 'roles' => ['MANAGER']]; $hr = ['id' => 8, 'emp_code' => 'H1', 'roles' => ['HROPS']];
$r = ['user_id' => 3, 'emp_code' => 'E1', 'status' => 'manager', 'manager_id' => 7];
t('AR10 manager acts on their step; HR may skip it; nobody on their own', atr_can_act($mgr, $r, false) && atr_can_act($hr, $r, true)
    && !atr_can_act(['id' => 3, 'emp_code' => 'E1', 'roles' => ['HRMANAGER']], $r, true) && !atr_can_act($mgr, ['status' => 'hr'] + $r, false));
t('AR11 manager approval moves to HR; HR approval is final', atr_next($r, false, true) === 'hr' && atr_next($r, true, false) === 'approved' && atr_next(['status' => 'hr'] + $r, true, false) === 'approved');
