<?php
// Expense claim rules (lib/claims.php) — synthetic figures only.
require_once WN_ROOT . '/lib/claims.php';
$set = EC_DEFAULTS; $today = '2026-11-18';
$row = ['date' => '2026-11-10', 'particulars' => 'TEST taxi', 'category' => 'Travel', 'mode' => 'Cash', 'amount' => '1,250.50', 'remarks' => ''];
$l = ec_line_input($row, 1, $set, $today, [3]);
t('EC01 a line is read: amount, date, category and mode from the lists', $l['amount'] == 1250.5 && $l['kind'] === 'dr' && $l['branch_id'] === null && ec_line_input(['particulars' => '', 'amount' => ''], 1, $set, $today) === null
    && ec_line_input(['branch_id' => '3'] + $row, 1, $set, $today, [3])['branch_id'] === 3);
$bad = function (array $r) use ($set, $today) { try { ec_line_input($r, 2, $set, $today); return null; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
t('EC02 bad lines are refused with their number', str_starts_with((string)$bad(['date' => '2026-12-01'] + $row), 'Line 2:') && $bad(['category' => 'Made up'] + $row) !== null
    && $bad(['amount' => '-5'] + $row) !== null && $bad(['date' => '2020-01-01'] + $row) !== null && $bad(['no_receipt' => 1] + $row) !== null && $bad(['no_receipt' => 1, 'remarks' => 'TEST lost bill'] + $row) === null);
$cr = ec_line_input(['kind' => 'cr', 'category' => 'Advance from the company', 'no_receipt' => 1] + $row, 1, $set, $today);
t('EC03 money received uses its own categories and needs no bill', $cr['kind'] === 'cr' && $cr['no_receipt'] === 0 && $bad(['kind' => 'cr'] + $row) !== null);
t('EC04 a claim needs at least one expense', (function () use ($set, $today, $row) { try { ec_claim_input(['lines' => ['a' => ['kind' => 'cr', 'category' => 'Refund received'] + $row]], $set, $today); return false; } catch (InvalidArgumentException $e) { return true; } })()
    && count(ec_claim_input(['lines' => ['a' => $row, 'b' => ['particulars' => '', 'amount' => ''], 'bad key!' => $row]], $set, $today)[1]) === 1);
$lines = [['kind' => 'dr', 'amount' => 1000, 'allowed' => 1, 'active' => 1, 'id' => 1, 'no_receipt' => 0, 'particulars' => 'a'], ['kind' => 'dr', 'amount' => 400, 'allowed' => 0, 'active' => 1, 'id' => 2, 'no_receipt' => 1, 'particulars' => 'b'],
          ['kind' => 'cr', 'amount' => 300, 'allowed' => 1, 'active' => 1, 'id' => 3, 'no_receipt' => 0, 'particulars' => 'c'], ['kind' => 'dr', 'amount' => 999, 'allowed' => 1, 'active' => 0, 'id' => 4, 'no_receipt' => 0, 'particulars' => 'd']];
t('EC05 totals: spent, allowed, received, payable; switched-off lines left out', ec_totals($lines) === ['spent' => 1400.0, 'allowed' => 1000.0, 'received' => 300.0, 'payable' => 700.0]);
t('EC06 payable: approved spending once set, else spent, less money received; may be below 0', ec_payable(['amount' => 1400, 'approved_amount' => 1000, 'credit_amount' => 300]) == 700
    && ec_payable(['amount' => 1400, 'approved_amount' => null, 'credit_amount' => 0]) == 1400 && ec_payable(['amount' => 100, 'approved_amount' => null, 'credit_amount' => 300]) == -200);
t('EC07 bills: each expense needs one unless "No bill"; money received never; off by setting', ec_bills_missing($lines, [], true) === ['Line 1 (a)'] && ec_bills_missing($lines, [['line_id' => 1]], true) === [] && ec_bills_missing($lines, [], false) === []);
$c = ['status' => 'submitted', 'approved_amount' => null, 'amount' => 100, 'credit_amount' => 0];
t('EC08 steps: never one\'s own, the right step and role, notes where needed', ec_step_problem($c, 'audit', 'ok', true, true) === 'You cannot decide on your own claim.'
    && ec_step_problem($c, 'audit', 'ok', false, false) !== null && ec_step_problem($c, 'audit', 'ok', true, false) === null && ec_step_problem($c, 'audit', 'return', true, false) === 'Give a note.'
    && ec_step_problem($c, 'approve', 'approve', true, false) === 'This claim is not at that step.' && ec_step_problem(['approved_amount' => 0] + $c, 'audit', 'ok', true, false) !== null);
$ap = ['status' => 'approved'] + $c;
t('EC09 paying needs a date, a mode and a reference (not when the person gives money back)', ec_step_problem($ap, 'pay', 'paid', true, false, '', ['paid_on' => '2026-11-18', 'pay_mode' => 'Cash']) === 'Give the payment reference.'
    && ec_step_problem($ap, 'pay', 'paid', true, false, '', ['paid_on' => '2026-11-18', 'pay_mode' => 'Cash', 'pay_ref' => 'TEST1']) === null
    && ec_step_problem(['credit_amount' => 500] + $ap, 'pay', 'paid', true, false, '', ['paid_on' => '2026-11-18', 'pay_mode' => 'Cash']) === null);
t('EC10 claim numbers run within the year', ec_number('', '2026') === 'CL-2026-0001' && ec_number('CL-2026-0041', '2026') === 'CL-2026-0042' && ec_number('CL-2025-0041', '2026') === 'CL-2026-0001');
[$o, $e] = ec_settings_check(['categories' => "Travel\nTravel\n<b>Food</b>", 'modes' => 'Cash', 'credit_cats' => '', 'pay_modes' => 'Bank', 'days_back' => '30', 'max_line' => '0', 'audit_roles' => ['HRMANAGER'], 'approve_roles' => ['X'], 'pay_roles' => ['ACCOUNTS'], 'report_roles' => ['CEO']],
    ['HRMANAGER' => 'HR', 'ACCOUNTS' => 'A', 'CEO' => 'C']);
t('EC11 settings: lists cleaned and de-duplicated, ranges and roles checked', $o['categories'] === ['Travel', 'Food'] && $o['days_back'] === 30 && count($e) === 3);
