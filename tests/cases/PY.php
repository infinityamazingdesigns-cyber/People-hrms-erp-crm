<?php
// Payroll part 1 rules (lib/payroll.php, rulepacks/IN) — synthetic figures only, no real person's pay.
require_once WN_ROOT . '/lib/payroll.php';
$in = pay_pack('IN'); $stat = $in['fn']; $R = pay_default_rules($in);
$R['pt'] = ['TS' => pay_pt_parse("0 15000 0\n15001 20000 150\n20001 - 200 2 300")];
$emp = ['pf_on' => 1, 'pf_full' => 0, 'esic_on' => null, 'pt_exempt' => 0];
$st = pay_structure(40000, $R);
t('PY01 structure: Basic 50%, HRA 40% of Basic, the rest Special', $st === ['Basic' => 20000.0, 'HRA' => 8000.0, 'Special allowance' => 12000.0]);
$R2 = $R; $R2['structure']['fixed'] = [['TEST Conveyance', 1600], ['TEST Medical', 20000]]; $R2['structure']['basic_min'] = 13000;
$s2 = pay_structure(20000, $R2);
t('PY02 floor on Basic (never above gross); an allowance only while the rest covers it', $s2['Basic'] == 13000 && $s2['TEST Conveyance'] == 1600 && !isset($s2['TEST Medical']) && abs(array_sum($s2) - 20000) < 0.01);
$x = $stat(['Basic' => 20000, 'HRA' => 8000, 'Special allowance' => 12000], 40000, $emp, $R, 'TS', '2026-11');
t('PY03 PF on Basic up to the ceiling, split EPF / pension, charges; no ESI above the ceiling; PT by slab',
    $x['emp']['PF'] == 1800 && $x['er']['PF (pension)'] == 1250 && $x['er']['PF (EPF)'] == 550 && $x['er']['EDLI'] == 75 && !isset($x['emp']['ESI']) && $x['emp']['Professional tax'] == 200);
t('PY04 PT: the month with its own amount; a state not set is named', $stat(['Basic' => 30000], 30000, ['pf_on' => 0] + $emp, $R, 'TS', '2027-02')['emp']['Professional tax'] == 300
    && $stat(['Basic' => 30000], 30000, ['pf_on' => 0] + $emp, $R, 'GJ', '2026-11')['notes'] === ['Professional tax not set for GJ']);
$y = $stat(['Basic' => 9000, 'Special allowance' => 9000], 18000, ['pf_full' => 1] + $emp, $R, '', '2026-11');
t('PY05 ESI below the ceiling (rounded up), PF on full wages when chosen', $y['emp']['ESI'] == 135 && $y['er']['ESI'] == 585 && $y['emp']['PF'] == 1080);
$G = pay_gross_from_ctc(42000, $emp, $R, 'TS', '2026-11', $stat);
t('PY06 gross worked back so gross + employer cost = CTC', abs($G + pay_employer_cost($G, $emp, $R, 'TS', '2026-11', $stat) - 42000) <= 1 && pay_gross_from_ctc(42000, $emp, ['ctc_includes_employer' => false] + $R, 'TS', '2026-11', $stat) == 42000.0);
$c = pay_calc(['ctc' => 40000] + $emp, ['paid' => 15, 'days' => 30], ['tds' => 500, 'other_earn' => 1000], ['ctc_includes_employer' => false] + $R, 'TS', '2026-11', $stat);
t('PY07 earned by paid days; other earnings and TDS', $c['earned']['Basic'] == 10000 && $c['gross'] == 21000 && $c['deductions']['Income tax (TDS)'] == 500 && $c['net'] == $c['gross'] - array_sum($c['deductions']));
$L = fn($d, $st, $x = []) => $x + ['day' => $d, 'status' => $st, 'in' => in_array($st, ['Present', 'Half day'], true) ? '09:30:00' : null];
$lines = [$L('2026-11-01', 'Weekly off'), $L('2026-11-02', 'Present'), $L('2026-11-03', 'Absent'), $L('2026-11-04', 'Half day'), $L('2026-11-05', 'On leave', ['leave' => ['halves' => [true, true], 'type' => 'CL']]),
          $L('2026-11-06', 'On leave', ['leave' => ['halves' => [true, true], 'type' => 'LOP']]), $L('2026-11-07', 'Missed punch'), $L('2026-11-08', 'Leave · first half', ['leave' => ['halves' => [true, false], 'type' => '']]),
          $L('2026-11-09', 'Holiday'), $L('2026-11-10', 'Present')];
$pd = pay_paid_days($lines, ['CL' => true, 'LOP' => false], null, null, '2026-11-09');
t('PY08 paid days: off days and paid leave count, absence / unpaid leave not, halves half, later days count; flags',
    $pd['paid'] == 7.0 && $pd['unpaid'] == 3.0 && $pd['flags']['missed'] === ['2026-11-07'] && $pd['flags']['leave_type'] === ['2026-11-08']);
t('PY09 days before joining / after the last day are not in the month', pay_paid_days($lines, [], '2026-11-05', '2026-11-07', '2026-11-30')['in_month'] === 3);
t('PY10 CTC in force for a month', pay_ctc_for([['id' => 1, 'from_ym' => '2026-01', 'ctc' => 30000], ['id' => 2, 'from_ym' => '2026-10', 'ctc' => 35000]], '2026-09') == 30000.0
    && pay_ctc_for([['id' => 1, 'from_ym' => '2026-10', 'ctc' => 30000]], '2026-09') === null);
$v = [['id' => 1, 'from_ym' => '2026-01', 'rules' => json_encode(['structure' => ['basic_pct' => 40]])], ['id' => 2, 'from_ym' => '2026-11', 'rules' => json_encode(['structure' => ['basic_pct' => 60, 'fixed' => [['TEST A', 100]]]])]];
t('PY11 rules by month; lists replace', pay_rules_for($v, '2026-10', $R)['structure']['basic_pct'] == 40 && pay_rules_for($v, '2026-12', $R)['structure']['fixed'] === [['TEST A', 100]] && pay_rules_for($v, '2025-12', $R)['structure']['basic_pct'] == 50);
$bad = function ($f) { try { $f(); return false; } catch (InvalidArgumentException $e) { return true; } };
t('PY12 slab and allowance lines are checked', $bad(fn() => pay_pt_parse('abc')) && $bad(fn() => pay_fixed_parse('Basic: 100')) && pay_fixed_parse("TEST Conv: 1600")[0] === ['TEST Conv', 1600.0]
    && pay_pt_text(pay_pt_parse("20001 - 200 2 300")) === '20001 - 200 2 300');
$u = ['id' => 3, 'roles' => ['HRMANAGER']]; $ceo = ['id' => 4, 'roles' => ['CEO']];
t('PY13 steps: checker is not the preparer; only the confirm role confirms and releases', pay_can_step($u, ['status' => 'draft'], 'check', $R, 3) !== null && pay_can_step($u, ['status' => 'draft'], 'check', $R, 9) === null
    && pay_can_step($u, ['status' => 'checked'], 'confirm', $R) !== null && pay_can_step($ceo, ['status' => 'checked'], 'confirm', $R) === null && pay_can_step($ceo, ['status' => 'checked'], 'release', $R) !== null);
t('PY14 no statutory pack: pay as entered', pay_calc(['ctc' => 1000] + $emp, ['paid' => 30, 'days' => 30], [], pay_default_rules(pay_pack('XX')), '', '2026-11', pay_pack('XX')['fn'])['net'] == 1000);
