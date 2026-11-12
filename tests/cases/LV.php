<?php
// Leave rules (lib/leave.php) — synthetic data only.
require_once WN_ROOT . '/lib/attendance.php';
t('LV01 halves: one day, half days, ranges with half ends', lv_halves('2026-11-12', 'first', '2026-11-12', 'first') === ['2026-11-12' => [true, false]]
    && lv_halves('2026-11-12', 'second', '2026-11-13', 'first') === ['2026-11-12' => [false, true], '2026-11-13' => [true, false]]
    && lv_halves('2026-11-12', 'late', '2026-11-12', 'late') === ['2026-11-12' => [false, false]]);
t('LV02 day count leaves out weekly offs and holidays', lv_days('2026-11-13', 'full', '2026-11-16', 'full') == 4.0 && lv_days('2026-11-13', 'full', '2026-11-16', 'full', ['2026-11-15' => true]) == 3.0 && lv_days('2026-11-13', 'second', '2026-11-14', 'first') == 1.0);
t('LV03 clashes: halves overlap; first + second half fine; late inside first-half leave clashes; two outings apart fine',
    lv_clash('full', [true, true], 'first', [true, false]) && !lv_clash('first', [true, false], 'second', [false, true])
    && lv_clash('late', [false, false], 'first', [true, false]) && !lv_clash('late', [false, false], 'second', [false, true])
    && !lv_clash('out', [false, false], 'out', [false, false], ['11:00', '12:00'], ['13:00', '14:00']) && lv_clash('out', [false, false], 'out', [false, false], ['11:00', '13:30'], ['13:00', '14:00']));
$rows = [['id' => 7, 'status' => 'approved', 'from_date' => '2026-11-10', 'from_session' => 'full', 'to_date' => '2026-11-14', 'to_session' => 'full', 'time_from' => null, 'time_to' => null],
         ['id' => 8, 'status' => 'rejected', 'from_date' => '2026-11-20', 'from_session' => 'full', 'to_date' => '2026-11-20', 'to_session' => 'full', 'time_from' => null, 'time_to' => null]];
$cl = lv_clash_list(['from' => '2026-11-12', 'fs' => 'first', 'to' => '2026-11-12', 'ts' => 'first'], $rows);
t('LV04 clash inside a longer application is named; rejected rows never clash', count($cl) === 1 && $cl[0]['id'] === 7 && str_contains(lv_clash_text($cl), '#7')
    && lv_clash_list(['from' => '2026-11-20', 'fs' => 'full', 'to' => '2026-11-20', 'ts' => 'full'], $rows) === []);
$set = LV_DEFAULTS; $bad = function (array $in, ?array $sh = null) use ($set) { try { lv_check($in, '2026-11-12', $set, $sh); return false; } catch (InvalidArgumentException $e) { return true; } };
$base = ['from' => '2026-11-13', 'reason' => 'TEST family function'];
t('LV05 checks: reason, dates back / ahead, span, range halves', !$bad($base) && $bad(['reason' => 'x'] + $base) && $bad(['from' => '2026-09-01'] + $base)
    && $bad(['from' => '2027-08-01'] + $base) && $bad(['to' => '2026-11-12'] + $base) && $bad(['to' => '2026-11-15', 'from_session' => 'first'] + $base));
t('LV06 time permissions need times inside the shift', $bad(['session' => 'late'] + $base) && !$bad(['session' => 'late', 'time_from' => '10:30'] + $base, ['09:30', '18:30'])
    && $bad(['session' => 'late', 'time_from' => '19:00'] + $base, ['09:30', '18:30']) && $bad(['session' => 'out', 'time_from' => '12:00', 'time_to' => '11:00'] + $base));
t('LV07 leave year follows the start month', lv_year('2026-02-10', 4) === ['2025-04-01', '2026-03-31'] && lv_year('2026-11-12', 1) === ['2026-01-01', '2026-12-31']);
$cl12 = ['code' => 'CL', 'per_year' => '12', 'prorate' => 1, 'after_confirm' => 0, 'active' => 1, 'apply_choice' => 1, 'on_day' => ''];
$el = ['code' => 'EL', 'per_year' => '15', 'prorate' => 1, 'after_confirm' => 1, 'active' => 1, 'apply_choice' => 1, 'on_day' => ''];
$lop = ['code' => 'LOP', 'per_year' => null, 'prorate' => 0, 'after_confirm' => 0, 'active' => 1, 'apply_choice' => 1, 'on_day' => ''];
$yr = ['2026-01-01', '2026-12-31'];
t('LV08 entitlement: full year, pro-rated by months (15th rule), after confirmation, no limit',
    lv_entitlement($cl12, $yr, '2020-05-01', null) == 12 && lv_entitlement($cl12, $yr, '2026-07-15', null) == 6 && lv_entitlement($cl12, $yr, '2026-07-16', null) == 5
    && lv_entitlement($el, $yr, '2025-01-01', null) == 0 && lv_entitlement($el, $yr, '2025-01-01', '2026-10-01') == 4 && lv_entitlement($lop, $yr, null, null) === null);
$b = lv_balance([$cl12, $lop], $yr, '2020-01-01', null, ['CL' => 3], ['CL' => 1], ['CL' => -0.5]);
t('LV09 balance = entitled + changes − taken; waiting shown apart', $b['CL']['left'] == 8.5 && $b['CL']['waiting'] == 1 && $b['LOP']['left'] === null);
$bd = ['code' => 'BL', 'per_year' => '1', 'prorate' => 0, 'after_confirm' => 0, 'active' => 1, 'apply_choice' => 1, 'on_day' => 'birthday'];
$ask = ['from' => '2026-11-12', 'fs' => 'full', 'to' => '2026-11-12', 'ts' => 'full'];
t('LV10 allowed types: birthday only on it, confirmation needed, none for time permissions',
    lv_allowed([$cl12, $el, $bd], $ask, '1990-11-12', null) === ['CL', 'BL'] && lv_allowed([$cl12, $el, $bd], $ask, '1990-11-13', '2026-01-01') === ['CL', 'EL']
    && lv_allowed([$cl12], ['fs' => 'late'] + $ask, null, null) === []);
$days = ['2026-11-12' => ['day' => '2026-11-12', 'status' => 'Absent', 'in' => null, 'out' => null, 'holiday' => null, 'late' => 0, 'early' => 0],
         '2026-11-13' => ['day' => '2026-11-13', 'status' => 'Half day', 'in' => '14:00:00', 'out' => '18:30:00', 'holiday' => null, 'late' => 270, 'early' => 0],
         '2026-11-14' => ['day' => '2026-11-14', 'status' => 'Present', 'in' => '10:20:00', 'out' => '18:30:00', 'holiday' => null, 'late' => 50, 'early' => 0],
         '2026-11-15' => ['day' => '2026-11-15', 'status' => 'Weekly off', 'in' => null, 'out' => null, 'holiday' => null]];
$o = lv_overlay($days, ['2026-11-12' => ['halves' => [true, true], 'type' => 'CL', 'times' => []], '2026-11-13' => ['halves' => [true, false], 'type' => 'CL', 'times' => []],
                        '2026-11-14' => ['halves' => [false, false], 'type' => '', 'times' => [['kind' => 'late', 'from' => '10:30', 'to' => null]]], '2026-11-15' => ['halves' => [true, true], 'type' => 'CL', 'times' => []]]);
t('LV11 attendance: on leave, half-day leave clears late, a late permission waives up to its time, weekly off untouched',
    $o['2026-11-12']['status'] === 'On leave' && $o['2026-11-13']['late'] === 0 && str_contains($o['2026-11-13']['status'], 'first half leave')
    && $o['2026-11-14']['late'] === 0 && $o['2026-11-15']['status'] === 'Weekly off' && empty($o['2026-11-15']['leave']));
t('LV12 begun days and timed minutes', lv_begun_days(['from_date' => '2026-11-10', 'from_session' => 'full', 'to_date' => '2026-11-14', 'to_session' => 'full'], '2026-11-12') === ['2026-11-10', '2026-11-11', '2026-11-12']
    && lv_timed_minutes('late', '10:30', null, ['09:30', '18:30']) === 60 && lv_timed_minutes('out', '13:00', '14:30', null) === 90);
$tc = function (array $in) { try { return lv_type_check($in); } catch (InvalidArgumentException $e) { return null; } };
t('LV13 type form: code letters, halves, blank = no limit', $tc(['code' => 'cl', 'name' => 'TEST Casual', 'per_year' => '7.5'])['code'] === 'CL' && $tc(['code' => 'C1', 'name' => 'x']) === null
    && $tc(['code' => 'XL', 'name' => 'x', 'per_year' => '2.3']) === null && $tc(['code' => 'XL', 'name' => 'x'])['per_year'] === null);
[$s2, $e2] = lv_settings_check(['back_days' => 30, 'ahead_days' => 90, 'max_span' => 400]);
t('LV14 settings are checked', $e2 && !$s2['show_balance']);
