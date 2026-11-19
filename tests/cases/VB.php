<?php
// Vendor bill rules (lib/vendorbills.php) — synthetic data only.
require_once WN_ROOT . '/lib/vendorbills.php';
$vbSet = VB_DEFAULTS;
$vbC = fn(array $roles, array $ticked, ?int $own, int $id = 7) => vb_ctx_for(['id' => $id, 'name' => 'TEST – A', 'branches' => $ticked], $roles, $vbSet, $own, [1, 2, 3]);
$cEmp = $vbC(['EMPLOYEE'], [3], 1); $cHr = $vbC(['HRMANAGER'], [2], null); $cCeo = $vbC(['CEO'], [2], null); $cAcc = $vbC(['ACCOUNTS'], [], null);
t('VB01 scope: staff enter for own + ticked branches and see only their own; HR sees ticked branches; CEO and no-ticks see every branch',
    $cEmp['see'] === [] && $cEmp['enter'] === [1, 3] && $cHr['see'] === [2] && $cHr['enter'] === [2] && $cCeo['see'] === null && $cAcc['see'] === null && $cAcc['R']['pay'] && !$cAcc['R']['check']);
$bill = ['id' => 1, 'entered_by' => 7, 'branch_id' => 2, 'status' => 'submitted'];
t('VB02 nobody decides on a bill they entered; steps need the role and the branch', !vb_can($cHr, 'check', $bill) && vb_can($vbC(['HRMANAGER'], [2], null, 9), 'check', $bill)
    && !vb_can($vbC(['HRMANAGER'], [3], null, 9), 'check', $bill) && !vb_can($vbC(['EMPLOYEE'], [], null, 9), 'check', $bill) && vb_sees($vbC(['EMPLOYEE'], [2], 1, 9), $bill) === false && vb_sees($cEmp, $bill) && vb_sees($cHr, $bill));
$vbE = function (callable $f): string { try { $f(); return ''; } catch (Throwable $e) { return $e->getMessage(); } };
t('VB03 the steps go in order; return and reject need a note', vb_next('check', 'ok', 'submitted', '') === 'checked' && vb_next('approve', 'reject', 'checked', 'TEST why') === 'rejected'
    && vb_next('pay', 'paid', 'approved', '') === 'paid' && $vbE(fn() => vb_next('approve', 'approve', 'submitted', '')) !== '' && $vbE(fn() => vb_next('check', 'return', 'submitted', 'x')) !== '' && $vbE(fn() => vb_next('pay', 'paid', 'checked', '')) !== '');
$kinds = vb_kinds($vbSet);
$in = vb_input(['branch' => '2', 'kind' => 'Rent', 'vendor' => ' TEST  Estates ', 'amount' => '25,000', 'period' => '2026-11', 'due_date' => '2026-12-05'], [2], $kinds, '2026-11-19');
t('VB04 form: branch and kind from the lists, amount with commas, month shape, a future bill date refused', $in['vendor'] === 'TEST Estates' && $in['amount'] === 25000.0 && $in['branch_id'] === 2
    && $vbE(fn() => vb_input(['branch' => '1', 'kind' => 'Rent'], [2], $kinds, '2026-11-19')) !== '' && $vbE(fn() => vb_input(['branch' => '2', 'kind' => 'Rent', 'period' => '2026-13'], [2], $kinds, '2026-11-19')) !== ''
    && $vbE(fn() => vb_input(['branch' => '2', 'kind' => 'Rent', 'bill_date' => '2026-12-01'], [2], $kinds, '2026-11-19')) !== '');
t('VB05 sending needs vendor, amount, month or bill date, and a bill copy or the reason there is none', count(vb_send_blocks(['vendor' => '', 'amount' => 0, 'period' => '', 'bill_date' => null, 'no_bill' => 0, 'no_bill_why' => ''], 0)) === 4
    && vb_send_blocks(['vendor' => 'T', 'amount' => 5, 'period' => '2026-11', 'no_bill' => 1, 'no_bill_why' => 'TEST lost'], 0) === [] && count(vb_send_blocks(['vendor' => 'T', 'amount' => 5, 'period' => '2026-11', 'no_bill' => 1, 'no_bill_why' => ''], 0)) === 1);
t('VB06 repeating: due from the bill day each month, once', vb_repeat_due(['last_month' => '2026-10', 'bill_day' => 5], '2026-11-19') === '2026-11' && vb_repeat_due(['last_month' => '2026-11', 'bill_day' => 5], '2026-11-19') === ''
    && vb_repeat_due(['last_month' => '', 'bill_day' => 25], '2026-11-19') === '');
$S = vb_summary([['status' => 'paid', 'amount' => 100, 'branch_id' => 1, 'kind' => 'Rent', 'paid_on' => '2026-11-02'], ['status' => 'approved', 'amount' => 50, 'branch_id' => 1, 'kind' => 'Rent', 'paid_on' => null], ['status' => 'rejected', 'amount' => 9, 'branch_id' => 1, 'kind' => 'Rent', 'paid_on' => null]]);
t('VB07 totals: paid by branch / kind / month, coming apart, rejected left out', $S['paid'] === 100.0 && $S['coming'] === 50.0 && $S['byBranch'] === [1 => 100.0] && $S['byMonth'] === ['2026-11' => 100.0] && vb_title(['kind' => 'Rent', 'vendor' => 'TEST', 'period' => '2026-11']) === 'Rent · TEST · Nov 2026');
[$so, $se] = vb_settings_check(['view_roles' => ['CEO'], 'check_roles' => ['HRMANAGER'], 'approve_roles' => [], 'pay_roles' => ['ACCOUNTS', 'NOPE'], 'kinds' => "TEST A\nTEST A"], ['CEO' => 'c', 'HRMANAGER' => 'h', 'ACCOUNTS' => 'a']);
t('VB08 settings: a role list may not be empty, unknown roles dropped, kinds deduplicated', count($se) === 1 && $so['pay_roles'] === ['ACCOUNTS'] && $so['kinds'] === 'TEST A');
$src = file_get_contents(WN_ROOT . '/lib/vendorbills.php');
t('VB09 nothing deleted; CSV made safe', !preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i', $src) && vb_csv_cell('+1') === "\"'+1\"");
