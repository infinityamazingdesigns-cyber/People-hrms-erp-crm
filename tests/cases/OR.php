<?php
/* Office registers (lib/office_reg.php) */
require_once WN_ROOT . '/lib/office_reg.php';
t('OR01 quantity read as a number, at least 1', or_qty('3 nos') === 3 && or_qty('1COPY') === 1 && or_qty('') === 1 && or_qty('0') === 1 && or_qty('999999') === 100000);
[$v, $e] = or_prop_clean(['name' => " TEST – Chair\n ", 'qty' => '12', 'branch_id' => '2', 'labels' => '<b>x</b>'], [1, 2]);
t('OR02 property cleaned', $e === null && $v['name'] === 'TEST – Chair' && $v['qty'] === 12 && $v['branch_id'] === 2 && $v['labels'] === 'x');
t('OR03 name needed, branch must be one of yours, branch-tied logins must pick one', or_prop_clean(['name' => ''], [1])[1] !== null && or_prop_clean(['name' => 'x', 'branch_id' => 9], [1])[1] !== null
    && or_book_clean(['title' => 'x'], [1], true)[1] !== null && or_book_clean(['title' => 'x'], [1], false)[1] === null);
$iss = [['returned' => null, 'due' => '2026-11-01'], ['returned' => '2026-10-02', 'due' => '2026-10-01'], ['returned' => null, 'due' => '2026-12-31']];
t('OR04 copies on the shelf and loans past due', or_free(3, $iss) === 1 && or_free(1, $iss) === 0 && count(or_overdue($iss, '2026-11-23')) === 1);
$rows = [['Book list'], ['No', 'Type', 'Book Name', 'Author Name', 'Langague', 'Branch'], ['1', 'Novel', 'TEST – A', 'TEST', 'English', ''], ['2', '', 'TEST – B', '', '', 'Second'],
    ['3', '', 'TEST – A', '', '', ''], ['4', '', '', '', '', ''], ['5', 'Poems', 'TEST – C', '', '', 'Nowhere']];
$of = fn($s) => $s === 'Second' ? 2 : -1;
$pl = or_import_plan('book', $rows, ['2|test – b'], $of, 1, [1, 2]);
t('OR05 import: titles found by heading, type carried down the group, duplicates and unknown branches caught', $pl['new'] === 1 && $pl['same'] === 2 && $pl['bad'] === 1
    && $pl['rows'][0]['v']['type'] === 'Novel' && $pl['rows'][1]['v']['type'] === 'Novel' && $pl['rows'][3]['v']['type'] === 'Poems');
t('OR06 import: a file with no title column is refused', !empty(or_import_plan('prop', [['a', 'b']], [], $of, null, [1])['error']));
$lib = file_get_contents(WN_ROOT . '/lib/office_reg.php');
t('OR07 nothing deleted; the log carries field names only', !preg_match('/DELETE\s+FROM|TRUNCATE|DROP/i', $lib) && str_contains($lib, "or_log(\$db, \$what, \$id, 'edit', \$ch, \$by)"));
t('OR08 lending only to someone on the roll in scope, never a later date, never past the copies', str_contains($lib, "!emp_in_scope(\$u, \$e)") && str_contains($lib, "\$date > date('Y-m-d')") && str_contains($lib, "if (\$b['free'] < 1)"));
t('OR09 page behind its gate', str_contains(file_get_contents(WN_ROOT . '/modules/officereg/index.php'), "module_require('officereg')"));
