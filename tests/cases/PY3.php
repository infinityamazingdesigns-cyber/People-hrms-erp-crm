<?php
// Payroll part 3 rules (lib/payroll3.php, rulepacks/IN) — synthetic figures only.
require_once WN_ROOT . '/lib/payroll.php';
$in = pay_pack('IN'); $stat = $in['fn']; $R = pay_default_rules($in);
$emp = ['pf_on' => 1, 'pf_full' => 0, 'esic_on' => null, 'pt_exempt' => 0];
$c = pay_calc(['ctc' => 30000] + $emp, ['paid' => 30, 'days' => 30], [], $R, 'XS', '2026-11', $stat);
t('PR301 the pack reports the PF wage (capped) and the ESI wage', ($c['wages']['pf'] ?? 0) == min($c['earned']['Basic'], 15000) && $c['wages']['pf'] > 0 && !isset($c['wages']['esi']));
$c2 = pay_calc(['ctc' => 15000] + $emp, ['paid' => 30, 'days' => 30], [], $R, 'XS', '2026-11', $stat);
t('PR302 ESI wage is the month\'s gross when covered', ($c2['wages']['esi'] ?? 0) == $c2['gross'] && $c2['gross'] > 0);
$lw = rp_in_lwf_parse('6 18 12,6');
t('PR303 LWF text: employee, employer, months sorted; blank = none; bad text refused', $lw === ['emp' => 6.0, 'er' => 18.0, 'months' => [6, 12]] && rp_in_lwf_parse(' ') === null
    && (function () { try { rp_in_lwf_parse('six'); return false; } catch (InvalidArgumentException $e) { return true; } })());
$RL = $R; $RL['lwf'] = ['XS' => $lw]; $RL['lwf_deduct'] = true;
$dec = pay_calc(['ctc' => 30000] + $emp, ['paid' => 30, 'days' => 30], [], $RL, 'XS', '2026-12', $stat);
$nov = pay_calc(['ctc' => 30000] + $emp, ['paid' => 30, 'days' => 30], [], $RL, 'XS', '2026-11', $stat);
t('PR304 LWF only in its months, employee share deducted, employer share on the cost', ($dec['deductions']['Labour welfare fund'] ?? 0) == 6 && ($dec['employer']['Labour welfare fund'] ?? 0) == 18
    && !isset($nov['deductions']['Labour welfare fund']) && $dec['gross'] == $nov['gross']);
$RL['lwf_deduct'] = false; $dec2 = pay_calc(['ctc' => 30000] + $emp, ['paid' => 30, 'days' => 30], [], $RL, 'XS', '2026-12', $stat);
t('PR305 LWF paid fully by the employer when not deducted', !isset($dec2['deductions']['Labour welfare fund']) && ($dec2['employer']['Labour welfare fund'] ?? 0) == 24);
$ln = $c + ['emp_code' => 'TST1', 'name' => 'TEST One'];
$e = rp_in_ecr([$ln, ['emp_code' => 'TST2', 'name' => 'TEST Two'] + $c, ['emp_code' => 'TST3', 'name' => 'TEST No PF', 'wages' => []] + $c], ['TST1' => '1001 2003 0004', 'TST2' => '12345']);
$f = explode('#~#', trim($e['text']));
t('PR306 ECR: one line per PF member with a 12-digit UAN, 11 fields; the rest listed', $e['count'] === 1 && count($f) === 11 && $f[0] === '100120030004' && $f[1] === 'TEST ONE'
    && (int)$f[3] === (int)round($c['wages']['pf']) && (int)$f[6] === (int)$c['deductions']['PF'] && (int)$f[7] + (int)$f[8] === (int)round($c['employer']['PF (pension)'] + $c['employer']['PF (EPF)']) && $e['left_out'][0][0] === 'TST2');
$esl = $c2 + ['emp_code' => 'TST4', 'name' => 'TEST Four'];
$es = rp_in_esi([$esl, ['emp_code' => 'TST5', 'name' => 'TEST Five', 'paid_days' => 0] + $c2, $ln], ['TST4' => '1000000001', 'TST5' => '1000000002'], '2026-11', ['TST5' => '2026-11-10']);
t('PR307 ESI sheet: covered people only; leaver gets reason 2 and the last day', $es['count'] === 2 && $es['rows'][1][0] === '1000000001' && $es['rows'][1][3] == round($c2['gross'])
    && $es['rows'][2][4] === 2 && $es['rows'][2][5] === '10/11/2026' && $es['rows'][2][3] === 0);
$sum = pay_state_summary([$dec + ['state' => 'XS'], $nov + ['state' => 'XS'], ['missing_ctc' => true, 'state' => 'XS']]);
t('PR308 state summary: people, PT and LWF by state, missing CTC left out', count($sum) === 1 && $sum[0]['people'] === 2 && $sum[0]['lwf_emp'] == 6 && $sum[0]['lwf_er'] == 18);
$old = ['earned' => ['Basic' => 10000, 'HRA' => 4000], 'paid_days' => 28];
$new = ['earned' => ['Basic' => 10714, 'HRA' => 4286], 'paid_days' => 30];
$d = pay_arrear_diff($old, $new);
t('PR309 arrears: earnings that differ, total and days; tiny differences ignored', $d['total'] == 1000 && $d['parts'] === ['Basic' => 714.0, 'HRA' => 286.0] && $d['days'] == 2
    && pay_arrear_diff($old, ['earned' => ['Basic' => 10000.4, 'HRA' => 4000], 'paid_days' => 28])['total'] == 0.0);
$a = pay_calc(['ctc' => 30000] + $emp, ['paid' => 30, 'days' => 30], ['arrear' => 500], $R, 'XS', '2026-11', $stat);
$r = pay_calc(['ctc' => 30000] + $emp, ['paid' => 30, 'days' => 30], ['arrear' => -300], $R, 'XS', '2026-11', $stat);
t('PR310 arrears paid add to gross, recovered are a deduction; statutory unchanged', $a['gross'] == $c['gross'] + 500 && $a['deductions']['PF'] == $c['deductions']['PF']
    && ($r['deductions']['Arrears recovered'] ?? 0) == 300 && $r['net'] == $c['net'] - 300);
t('PR311 returns follow the pack; the state summary is always offered', array_keys(pay_return_kinds($in)) === ['ecr', 'esi', 'state'] && array_keys(pay_return_kinds(pay_pack('XX'))) === ['state']
    && pay_return_ids('ecr') === ['social_id', 12] && pay_return_ids('state') === null);
t('PR312 LWF is never part of working CTC back to gross', pay_gross_from_ctc(30000, $emp, ['ctc_includes_employer' => true] + $RL, 'XS', '2026-12', $stat) == pay_gross_from_ctc(30000, $emp, ['ctc_includes_employer' => true] + $RL, 'XS', '2026-11', $stat));
