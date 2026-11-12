<?php
// Shifts & weekly offs rules (lib/shifts.php) — synthetic data only.
require_once WN_ROOT . '/lib/attendance.php';
$br = ['id' => 1, 'shift_start' => '09:30:00', 'shift_end' => '18:30:00', 'weekoffs' => 'Sun'];
$flexBr = ['id' => 2, 'shift_start' => null, 'shift_end' => null, 'weekoffs' => 'Sun'];
$r = fn(int $id, string $from, array $x) => $x + ['id' => $id, 'from_day' => $from, 'start_time' => null, 'end_time' => null, 'flexible' => 0, 'use_branch_shift' => 0, 'weekoffs' => null, 'removed_at' => null];
t('SH01 no rule = the branch', shift_effective([], $br, '2026-11-12') === ['start' => '09:30', 'end' => '18:30', 'flexible' => false, 'weekoffs' => 'Sun', 'source' => 'branch']);
t('SH02 a branch with no shift start is flexible', shift_effective([], $flexBr, '2026-11-12')['flexible'] === true);
$rules = [$r(1, '2026-10-01', ['start_time' => '10:00', 'end_time' => '18:30']), $r(2, '2026-11-10', ['use_branch_shift' => 1, 'weekoffs' => 'Sun,Wed'])];
$a = shift_effective($rules, $br, '2026-10-15'); $b = shift_effective($rules, $br, '2026-11-11'); $c = shift_effective($rules, $br, '2026-09-30');
t('SH03 rules apply from their date; earlier days keep what they had', $a['start'] === '10:00' && $a['weekoffs'] === 'Sun' && $c['source'] === 'branch');
t('SH04 "branch\'s shift" rule changes only the weekly offs', $b['start'] === '09:30' && $b['weekoffs'] === 'Sun,Wed' && $b['source'] === 'rule');
$rules[] = $r(3, '2026-11-11', ['flexible' => 1, 'removed_at' => '2026-11-12 10:00:00']);
t('SH05 an ended rule is skipped', shift_effective($rules, $br, '2026-11-12')['rule_id'] === 2);
t('SH06 flexible rule clears the hours', shift_effective([$r(4, '2026-01-01', ['flexible' => 1])], $br, '2026-11-12')['start'] === null);
t('SH07 times and weekly offs are tidied', shift_time('930') === '09:30' && shift_time('25:00') === false && shift_time('') === null && shift_weekoffs(['wed', 'Sun', 'x']) === 'Sun,Wed');
$ok = function (array $in) { try { shift_check($in, '2026-11-12'); return true; } catch (InvalidArgumentException $e) { return false; } };
t('SH08 checks: hours needed for a fixed shift, no empty "branch" rule, at most 3 weekly offs',
    $ok(['from_day' => '2026-11-12', 'mode' => 'fixed', 'start' => '09:30', 'end' => '18:30', 'weekoffs' => ['Sun']])
    && !$ok(['from_day' => '2026-11-12', 'mode' => 'fixed', 'start' => '09:30'])
    && !$ok(['from_day' => '2026-11-12', 'mode' => 'branch', 'wo_branch' => 1])
    && !$ok(['from_day' => '2026-11-12', 'mode' => 'flexible', 'weekoffs' => ['Sun', 'Mon', 'Tue', 'Wed']])
    && !$ok(['from_day' => '2028-01-01', 'mode' => 'flexible']));
t('SH09 no weekly off at all is allowed (ticks all cleared)', shift_check(['from_day' => '2026-11-12', 'mode' => 'flexible'], '2026-11-12')['weekoffs'] === '');
$eff = shift_by_day([$r(5, '2026-11-11', ['weekoffs' => 'Wed', 'use_branch_shift' => 1])], $br, '2026-11-08', '2026-11-11');
$set = att_settings();
$days = att_person_days([], [], array_map(fn($e) => $e['weekoffs'], $eff), '2026-11-08', '2026-11-11', '2026-11-12', $set);
t('SH10 weekly off changes on the rule\'s date in the day lines', $days['2026-11-08']['status'] === 'Weekly off' && $days['2026-11-11']['status'] === 'Weekly off' && $days['2026-11-10']['status'] === 'Absent');
$line = ['2026-11-10' => ['day' => '2026-11-10', 'status' => 'Present', 'in' => '10:20:00', 'out' => '18:30:00', 'holiday' => null]];
$fx = att_late_early_by_day($line, ['2026-11-10' => shift_effective([$r(6, '2026-01-01', ['flexible' => 1])], $br, '2026-11-10')], 5);
$fixed = att_late_early_by_day($line, ['2026-11-10' => shift_effective([], $br, '2026-11-10')], 5);
t('SH11 late / early follow the day\'s shift; flexible days are never late', $fx['2026-11-10']['late'] === 0 && $fixed['2026-11-10']['late'] === 50);
t('SH12 label', shift_label(shift_effective([], $br, '2026-11-12')) === '09:30–18:30 · off Sun' && shift_overnight('22:00', '06:00'));
