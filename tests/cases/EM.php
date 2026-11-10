<?php
// Employee master rules (lib/employees.php) — synthetic data only.
require_once WN_ROOT . '/lib/employees.php';
$brs = [['id' => 1, 'name' => 'Head Office', 'code' => 'HO', 'aliases' => 'HQ'], ['id' => 2, 'name' => 'North Plant', 'code' => 'NP', 'aliases' => '']];

t('EM01 passphrase-like code refused, normal code accepted', emp_code_ok('blue-river-stone') !== null && emp_code_ok('TST001') === null && emp_code_ok('') !== null);
t('EM02 status: active → notice → left by last working day',
    emp_status([], '2026-11-10') === 'active'
    && emp_status(['last_day' => '2026-11-30'], '2026-11-10') === 'notice'
    && emp_status(['resign_date' => '2026-11-01'], '2026-11-10') === 'notice'
    && emp_status(['last_day' => '2026-11-09'], '2026-11-10') === 'left'
    && emp_status(['last_day' => '2026-11-10'], '2026-11-10') === 'notice');
t('EM03 same person tolerates spelling, keeps different surnames apart',
    emp_same_person('TEST Asha Kholia', 'Test Asha Kholiya') && !emp_same_person('Test Asha Kholia', 'Test Asha Mehra') && !emp_same_person('Test Ravi', 'Test Mira'));
[$c, $e] = emp_check(['code' => 'TST1', 'name' => 'TEST – One', 'branch_id' => 'hq', 'doj' => '05/04/2025', 'work_email' => 'One@Example.com'], $brs);
t('EM04 check cleans dates, branch spellings and email', !$e && $c['branch_id'] === 1 && $c['doj'] === '2025-04-05' && $c['work_email'] === 'one@example.com');
[, $e2] = emp_check(['code' => 'TST1', 'name' => 'X', 'branch_id' => 'Moon Base', 'doj' => '2025-13-40', 'manager_code' => 'TST1'], $brs);
t('EM05 unknown branch, bad date and self-manager refused', count($e2) === 3);
[, $e3] = emp_check(['resign_date' => '2026-05-10', 'last_day' => '2026-05-01'], $brs);
t('EM06 last day before resignation refused', count($e3) === 1);
$existing = ['TST1' => ['id' => 9, 'code' => 'TST1', 'name' => 'TEST – Asha Kholia', 'department' => 'Sales', 'branch_id' => '1', 'doj' => '2025-04-05']];
$plan = emp_import_plan([
    ['code' => 'TST1', 'name' => 'TEST – Asha Kholiya', 'department' => 'Marketing'],
    ['code' => 'TST1', 'name' => 'x'],
    ['code' => 'TST2', 'name' => 'TEST – Mira Shah'],
    ['code' => 'TST3', 'name' => 'TEST – Ravi Rao', 'branch_id' => 'Nowhere'],
], $existing, $brs);
t('EM07 import plan: update / duplicate-in-file / new / error', array_column($plan, 0) === ['Update', 'Error', 'New', 'Error'] && isset($plan[0][4]['department']));
$plan2 = emp_import_plan([['code' => 'TST1', 'name' => 'TEST – Someone Else']], $existing, $brs);
t('EM08 a code held by a different person is never merged', $plan2[0][0] === 'Possible duplicate');
t('EM09 headers found by their titles', emp_header_map(['Employee Code', 'Full Name', 'Date of Joining', 'Branch', 'Odd']) === [0 => 'code', 1 => 'name', 2 => 'doj', 3 => 'branch_id']);
t('EM10 CSV cells cannot start a formula', emp_csv_cell('=SUM(A1)') === "'=SUM(A1)" && emp_csv_cell('Plain') === 'Plain');
t('EM11 blank cells in an import never wipe a field', !isset(emp_import_plan([['code' => 'TST1', 'name' => 'TEST – Asha Kholia', 'department' => '']], $existing, $brs)[0][4]['department']));
