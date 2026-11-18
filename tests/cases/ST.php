<?php
// Statutory compliance rules (lib/statutory.php, rulepacks) — synthetic figures only.
require_once WN_ROOT . '/lib/statutory.php';
$pf = ['scheme' => 'pf', 'state' => '', 'day' => 15, 'months' => '', 'after_m' => 1, 'on_date' => null, 'active' => 1, 'kind' => 'both'];
t('ST01 due date: day of the month after, last day for 31, only listed months', st_due_date($pf, '2026-10') === '2026-11-15' && st_due_date(['day' => 31] + $pf, '2026-01') === '2026-02-28'
    && st_due_date(['months' => '6,12'] + $pf, '2026-07') === null && st_due_date(['months' => '6,12', 'after_m' => 2] + $pf, '2026-12') === '2027-02-15' && st_due_date(['on_date' => '2026-12-01'] + $pf, '2026-10') === null);
$ds = st_dues([$pf, ['scheme' => 'lab', 'on_date' => '2026-12-05', 'label' => 'x'] + $pf, ['active' => 0] + $pf], '2026-11-01', '2026-12-31');
t('ST02 dues in a window, one-date rules included, switched-off rules not, in date order', count($ds) === 3 && $ds[0][0] === '2026-11-15' && $ds[0][2] === '2026-10' && $ds[1][0] === '2026-12-05' && $ds[1][2] === '' && $ds[2][0] === '2026-12-15');
$map = (require WN_ROOT . '/rulepacks/IN/pack.php')['statutory']['map'];
$lines = [['deductions' => ['PF' => 1800, 'Professional tax' => 200, 'Income tax (TDS)' => 500], 'employer' => ['PF (EPF)' => 551, 'PF (pension)' => 1249, 'EDLI' => 75, 'PF admin' => 75], 'state' => 'XS'],
          ['deductions' => ['PF' => 1000, 'ESI' => 100, 'Professional tax' => 150], 'employer' => ['PF (EPF)' => 400, 'PF (pension)' => 600, 'ESI' => 433], 'state' => 'XT'],
          ['deductions' => ['PF' => 900], 'employer' => ['PF (EPF)' => 900], 'state' => 'XS', 'hold' => 1], ['missing_ctc' => true, 'state' => 'XS']];
$ag = st_aggregate($lines, $map);
t('ST03 spends from payslip lines: PF one establishment with charges, PT by state, ESI and TDS; held counted apart', $ag['held'] === 1
    && $ag['rows']['pf|'] === ['people' => 2, 'ee' => 2800.0, 'er' => 2800.0, 'charges' => 150.0] && $ag['rows']['pt|XS']['ee'] == 200 && $ag['rows']['pt|XT']['ee'] == 150
    && $ag['rows']['esi|'] === ['people' => 1, 'ee' => 100.0, 'er' => 433.0, 'charges' => 0.0] && $ag['rows']['tds|']['ee'] == 500);
t('ST04 a scheme ticked "by state" is split by state', isset(st_aggregate($lines, $map, ['pf'])['rows']['pf|XT']));
$s = ['ee' => 100, 'er' => 300, 'charges' => 0, 'paid_on' => null, 'paid_amt' => null];
t('ST05 status: to pay, due this week, overdue, paid, paid late, part paid, nothing due', st_status($s, '2026-11-30', '2026-11-01') === 'To pay' && st_status($s, '2026-11-15', '2026-11-10') === 'Due this week'
    && st_status($s, '2026-11-15', '2026-11-16') === 'Overdue' && st_status(['paid_on' => '2026-11-14'] + $s, '2026-11-15', '2026-11-20') === 'Paid'
    && st_status(['paid_on' => '2026-11-16'] + $s, '2026-11-15', '2026-11-20') === 'Paid late' && st_status(['paid_on' => '2026-11-14', 'paid_amt' => 100] + $s, '2026-11-15', '2026-11-20') === 'Part paid'
    && st_status(['ee' => 0, 'er' => 0] + $s, '2026-11-15', '2026-11-20') === 'Nothing due');
t('ST06 money, months and addresses are checked', st_money('1,234.50') == 1234.5 && st_money('') == 0.0 && st_months('12, 6') === '6,12' && st_url('https://example.com/a') === 'https://example.com/a'
    && (function () { try { st_url('http://example.com'); return false; } catch (InvalidArgumentException $e) { return true; } })()
    && (function () { try { st_months('13'); return false; } catch (InvalidArgumentException $e) { return true; } })()
    && (function () { try { st_money('-5'); return false; } catch (InvalidArgumentException $e) { return true; } })());
$rules = [['scheme' => 'pt', 'state' => '', 'id' => 1] + $pf, ['scheme' => 'pt', 'state' => 'XS', 'id' => 2] + $pf, ['scheme' => 'pt', 'state' => 'XT', 'id' => 3, 'kind' => 'file'] + $pf];
t('ST07 the state\'s own rule wins, else the one for every state; return-only rules never set a payment\'s due', st_rule_for($rules, 'pt', 'XS')['id'] === 2 && st_rule_for($rules, 'pt', 'XT')['id'] === 1 && st_rule_for($rules, 'pf', 'XS') === null);
t('ST08 settled: payment by its line, return when filed, both needs both, task when done', st_due_settled(['kind' => 'pay'], ['paid_on' => '2026-11-10'] + $s, []) && !st_due_settled(['kind' => 'both'], ['paid_on' => '2026-11-10'] + $s, [])
    && st_due_settled(['kind' => 'both'], ['paid_on' => '2026-11-10'] + $s, ['filed' => 1]) && st_due_settled(['kind' => 'file'], null, ['filed' => 1]) && !st_due_settled(['kind' => 'task'], null, ['filed' => 1])
    && st_due_settled(['kind' => 'pay'], null, ['paid' => 1]) && st_due_settled(['kind' => 'pay'], ['ee' => 0, 'er' => 0] + $s, []));
t('ST09 changed figures are told apart from payroll\'s', st_differs(['people' => 2, 'ee' => 10, 'er' => 0, 'charges' => 0], ['people' => 2, 'ee' => 11, 'er' => 0, 'charges' => 0]) && !st_differs(['people' => 2, 'ee' => 10, 'er' => 0, 'charges' => 0], null));
[$o, $e] = st_settings_check(['manage_roles' => ['HRMANAGER', 'NOPE'], 'pay_roles' => [], 'by_state' => ['esi', 'zz'], 'from_ym' => '2026-13'], ['HRMANAGER' => 'HR', 'ACCOUNTS' => 'Acc'], ['esi' => 'ESI']);
t('ST10 settings: unknown roles and schemes dropped, a role needed for payments, month checked', $o['manage_roles'] === ['HRMANAGER'] && $o['by_state'] === ['esi'] && count($e) === 2);
$in = (require WN_ROOT . '/rulepacks/IN/pack.php')['statutory']; $xx = (require WN_ROOT . '/rulepacks/XX/pack.php')['statutory'];
$urlsOk = true; foreach ($in['portals'] as $p) { try { st_url($p[3]); } catch (Throwable $ex) { $urlsOk = false; } }
t('ST11 packs: India seeds national links (https), dues and registrations; the plain pack seeds none', $urlsOk && isset($in['dues']['pf'], $in['regs']['tan']) && !$xx['portals'] && !$xx['dues'] && isset($xx['map']['tax'])
    && !array_diff(array_keys($in['map']), array_keys($in['schemes'])));
t('ST12 dues are followed from the setting, else the first locked month, else this month', st_track_from(['from_ym' => '2026-09'], ['2026-10'], '2026-11-18') === '2026-09'
    && st_track_from(['from_ym' => ''], ['2026-11', '2026-10'], '2026-11-18') === '2026-10' && st_track_from(['from_ym' => ''], [], '2026-11-18') === '2026-11');
