<?php
// Full & final rules (lib/fnf.php) — synthetic figures only.
require_once WN_ROOT . '/lib/leave.php';
require_once WN_ROOT . '/lib/fnf.php';
$set = FNF_DEFAULTS; $tax0 = fn(float $b, string $m) => 0.0;
t('FN01 year start follows the organisation\'s first month', fnf_year_start('2026-11-10', 4) === '2026-04' && fnf_year_start('2027-02-10', 4) === '2026-04' && fnf_year_start('2026-03-01', 1) === '2026-01');
t('FN02 months for the share: year start or joining month to the leaving month, both in full', fnf_leave_months('2026-10-15', '2024-01-01', 4) === 7 && fnf_leave_months('2026-10-15', '2026-08-20', 4) === 3
    && fnf_leave_months('2026-10-15', '', 1) === 10 && fnf_leave_months('', '', 4) === 0);
$types = [['code' => 'AL', 'active' => 1, 'paid' => 1, 'per_year' => '10', 'on_day' => ''], ['code' => 'SL', 'active' => 1, 'paid' => 1, 'per_year' => '6', 'on_day' => ''],
          ['code' => 'BD', 'active' => 1, 'paid' => 1, 'per_year' => '1', 'on_day' => 'birthday'], ['code' => 'LOP', 'active' => 1, 'paid' => 0, 'per_year' => null, 'on_day' => '']];
$b = fnf_leave_basis($types, $set);
t('FN03 leave basis from the types: paid with a yearly allowance, not birthday or without pay', $b['codes'] === ['AL', 'SL'] && $b['days'] == 16.0
    && fnf_leave_basis($types, ['leave_codes' => ['AL'], 'leave_days' => 12.0] + $set) === ['codes' => ['AL'], 'days' => 12.0]);
$f = ['rate' => 31000, 'lwd' => '2026-10-31', 'doj' => '2024-01-01', 'probation' => false, 'taken' => 16, 'adj_days' => '', 'months' => [['ym' => '2026-10', 'days' => 31, 'full' => false]], 'earn' => [], 'ded' => [], 'paid_before' => 0];
$c = fnf_calc($f, $b, $set, 4, $tax0);
t('FN04 cut = leave above the pro-rated share × gross ÷ days of the leaving month', $c['leave']['months'] === 7 && abs($c['leave']['entitled'] - 9.33) < 0.01 && abs($c['leave']['excess'] - 6.67) < 0.01
    && $c['leave']['cut'] == round((16 - 16 * 7 / 12) * 1000) && $c['net'] == 31000 - $c['leave']['cut']);
t('FN05 nobody on probation gets the cut', fnf_calc(['probation' => true] + $f, $b, $set, 4, $tax0)['leave']['cut'] == 0);
$ty = fnf_calc(['adj_days' => '2'] + $f, $b, $set, 4, $tax0);
t('FN06 days typed by HR win; the worked-out figure is kept beside them', $ty['leave']['typed'] && $ty['leave']['cut'] == 2000 && abs($ty['leave']['worked'] - 6.67) < 0.01);
$part = fnf_calc(['months' => [['ym' => '2026-09', 'days' => 30, 'full' => false], ['ym' => '2026-10', 'days' => 10, 'full' => false]], 'lwd' => '2026-10-10', 'taken' => 0] + $f, $b, $set, 4, $tax0);
t('FN07 pay for the days up to the last working day, month by month', $part['lines'][0]['amount'] == 31000 && $part['lines'][1]['amount'] == 10000 && $part['earn_total'] == 41000
    && $part['lines'][1]['label'] === '10 of 31 days October 2026');
$ag = fnf_calc(['months' => [['ym' => '2026-10', 'days' => 10, 'full' => true]], 'lwd' => '2026-10-10', 'taken' => 0] + $f, $b, $set, 4, $tax0);
t('FN08 a month marked full as agreed pays in full and says so', $ag['lines'][0]['amount'] == 31000 && str_contains($ag['lines'][0]['label'], 'full month as agreed'));
$seen = []; $tax = function (float $base, string $m) use (&$seen) { $seen[$m] = $base; return $base > 20000 ? 200.0 : 0.0; };
$tx = fnf_calc(['earn' => [['label' => 'Bonus', 'amount' => 500]], 'ded' => [['label' => 'Notice shortfall', 'amount' => 300]], 'paid_before' => 1000, 'taken' => 0] + $f, $b, $set, 4, $tax);
t('FN09 tax by state on what is payable, extras in the leaving month; balance after what was paid', $seen['2026-10'] == 31500 && $tx['tax_total'] == 200 && $tx['net'] == 31500 - 200 - 300 && $tx['balance'] == $tx['net'] - 1000);
$en = fnf_calc(['taken' => 4] + $f, $b, ['pay_unused' => true, 'encash_max' => 3] + $set, 4, $tax0);
t('FN10 unused leave paid only when set, within the limit', $en['leave']['unused'] == 3 && $en['leave']['encash'] == 3000 && fnf_calc(['taken' => 4] + $f, $b, $set, 4, $tax0)['leave']['encash'] == 0);
t('FN11 amounts in words, Indian grouping for INR and international otherwise', fnf_words(162073, 'INR') === 'One Lakh Sixty-Two Thousand Seventy-Three'
    && fnf_words(1250000, 'GBP') === 'One Million Two Hundred Fifty Thousand' && fnf_words(0) === 'Zero' && fnf_words(105, 'INR') === 'One Hundred Five');
$cl = fnf_clean(['code' => 'tst1', 'name' => 'TEST <b>One</b>', 'lwd' => '2026-10-31', 'rate' => '-5', 'adj_days' => 'x', 'm_ym' => ['2026-10', 'bad'], 'm_days' => ['31', '3'], 'm_full' => [0 => '1'],
    'earn_label' => ['Bonus', ''], 'earn_amt' => ['500', '9']]);
t('FN12 form cleaned: code upper, tags gone, no negative gross, bad months and blank lines dropped', $cl['code'] === 'TST1' && $cl['name'] === 'TEST One' && $cl['rate'] == 0 && $cl['adj_days'] === ''
    && count($cl['months']) === 1 && $cl['months'][0]['full'] && $cl['earn'] === [['label' => 'Bonus', 'amount' => 500.0]] && fnf_problem($cl) === 'Give the monthly gross.');
t('FN13 a month after the last working day is refused', fnf_problem(['rate' => 1, 'months' => [['ym' => '2026-11']]] + $cl) !== null);
$hr = ['id' => 5, 'roles' => ['HRMANAGER'], 'emp_code' => 'TST9', 'data' => ['pay' => true]];
$row = ['emp_code' => 'TST1', 'status' => 'draft', 'prepared_by' => 5, 'active' => 1];
t('FN14 steps: not one\'s own, checker and approver not the preparer, reasons needed', fnf_step_problem(['emp_code' => 'TST1'] + $hr, $row, 'check', $set) === 'Not on your own settlement.'
    && fnf_step_problem($hr, $row, 'approve', $set) === 'It must be checked first.' && fnf_step_problem($hr, ['status' => 'approved'] + $row, 'paid', $set) !== null
    && fnf_step_problem($hr, ['status' => 'paid'] + $row, 'off', $set, 'x') === 'A paid settlement stays.');
