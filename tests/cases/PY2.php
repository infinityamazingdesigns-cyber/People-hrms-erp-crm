<?php
// Payroll part 2 rules (lib/payroll2.php) — synthetic figures only.
require_once WN_ROOT . '/lib/payroll.php';
$loan = ['from_ym' => '2026-11', 'emi' => 2000, 'amount' => 5000, 'closed_at' => null];
t('PZ01 loan recovery: EMI, then what is left; not before its month; not after closing', pay_loan_due($loan, 0, '2026-11') == 2000 && pay_loan_due($loan, 4000, '2026-12') == 1000
    && pay_loan_due($loan, 5000, '2027-01') == 0 && pay_loan_due($loan, 0, '2026-10') == 0 && pay_loan_due(['closed_at' => '2026-11-20'] + $loan, 0, '2026-12') == 0);
t('PZ02 PIN: 4 digits, not one digit four times, not running', ps_pin_problem('4729') === null && ps_pin_problem('1111') !== null && ps_pin_problem('1234') !== null
    && ps_pin_problem('8765') !== null && ps_pin_problem('9012') !== null && ps_pin_problem('12a4') !== null && ps_pin_problem('123') !== null);
t('PZ03 payslip shown only for a released month, never while held', ps_line_visible(['status' => 'released'], ['hold' => 0]) && !ps_line_visible(['status' => 'confirmed'], ['hold' => 0])
    && !ps_line_visible(['status' => 'released'], ['hold' => 1]) && !ps_line_visible(['status' => 'released'], ['missing_ctc' => true]));
$lines = [['emp_code' => 'TST1', 'name' => 'TEST A', 'net' => 1000.5, 'pay_mode' => 'bank', 'hold' => 0], ['emp_code' => 'TST2', 'name' => 'TEST B', 'net' => 900, 'pay_mode' => 'bank', 'hold' => 1],
          ['emp_code' => 'TST3', 'name' => 'TEST C', 'net' => 800, 'pay_mode' => 'cash', 'hold' => 0], ['emp_code' => 'TST4', 'name' => 'TEST D', 'net' => 700, 'pay_mode' => 'bank', 'hold' => 0]];
$b = bank_rows($lines, ['TST1' => ['acct' => '000111222', 'code' => 'TESTCODE1', 'holder' => 'TEST A Holder']], '2026-11', 'TEST Co');
t('PZ04 bank file: only bank-paid, not held, with a verified account; the rest listed with why', count($b['rows']) === 1 && $b['rows'][0]['name'] === 'TEST A Holder' && $b['total'] == 1000.5
    && array_column($b['left_out'], 2) === ['On hold', 'Paid by cash', 'No verified bank account'] && $b['rows'][0]['ref'] === 'SALARY Nov 2026 TST1');
t('PZ05 bank CSV cells are cleaned and never start a formula', bank_csv_line(['=SUM(A1)', 'a,b', "x\ny", '"q"']) === "'=SUM(A1),a b,x y,q\r\n");
$html = ps_render(['name' => 'TEST <A>', 'emp_code' => 'TST1', 'department' => '', 'paid_days' => 30, 'days' => 30, 'parts' => ['Basic' => 100], 'earned' => ['Basic' => 100], 'other_earn' => 0,
    'gross_full' => 100, 'gross' => 100, 'deductions' => ['Loan / advance' => 10], 'employer' => [], 'net' => 90], '2026-11');
t('PZ06 payslip from the locked line, escaped', str_contains($html, 'TEST &lt;A&gt;') && str_contains($html, 'Loan / advance') && str_contains($html, 'Payslip · November 2026'));
