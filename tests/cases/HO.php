<?php
// Holiday calendar rules (lib/holidays.php) — synthetic data only.
require_once WN_ROOT . '/lib/holidays.php';
$rows = [['id' => 1, 'day' => '2026-11-09', 'name' => 'Festival of Lights', 'kind' => 'holiday', 'branches' => '', 'active' => 1],
         ['id' => 2, 'day' => '2026-11-10', 'name' => 'Festival of Lights Day 2', 'kind' => 'holiday', 'branches' => '2,3', 'active' => 1],
         ['id' => 3, 'day' => '2026-11-11', 'name' => 'Local Fair', 'kind' => 'optional', 'branches' => '', 'active' => 1],
         ['id' => 4, 'day' => '2026-11-12', 'name' => 'Old Holiday', 'kind' => 'holiday', 'branches' => '', 'active' => 0]];
t('HO01 no branches ticked = every branch', hol_for($rows, '2026-11-09', 7) === 'Festival of Lights' && hol_for($rows, '2026-11-09', null) === 'Festival of Lights');
t('HO02 ticked branches only', hol_for($rows, '2026-11-10', 2) !== null && hol_for($rows, '2026-11-10', 5) === null);
t('HO03 optional holidays and switched-off rows are not days off', hol_for($rows, '2026-11-11', 1) === null && hol_for($rows, '2026-11-11', 1, true) === 'Local Fair' && hol_for($rows, '2026-11-12', 1) === null);
t('HO04 same holiday under a slightly different name', hol_same('New Year', "New Year's Day") && !hol_same('Labour Day', 'Republic Day'));
t('HO05 adding the same holiday for another branch only ticks it', hol_plan_one($rows, '2026-11-10', 'Festival of Lights - 2', [4]) === ['tick', 2, [2, 3, 4]]);
t('HO06 already there for every branch = nothing to do', hol_plan_one($rows, '2026-11-09', 'Festival of Lights', [5])[0] === 'same' && hol_plan_one($rows, '2026-11-13', 'New', [])[0] === 'add');
t('HO07 ranges expand day by day, capped and ordered', count(hol_range('2026-11-07', '2026-11-11')) === 5 && hol_range('2026-11-11', '2026-11-07') === [] && hol_range('2026-01-01', '2026-03-01') === []);
t('HO08 year follows the organisation year start', hol_year('2026-02-10', 4) === ['2025-04-01', '2026-03-31', '2025–26'] && hol_year('2026-02-10', 1)[0] === '2026-01-01');
$brs = [['id' => 1, 'name' => 'Head Office', 'code' => 'HO', 'aliases' => 'HQ'], ['id' => 2, 'name' => 'North Plant', 'code' => 'NP', 'aliases' => '']];
t('HO09 branch cell: "All", spellings, unknown names reported', hol_branch_cell('All', $brs) === [[], []] && hol_branch_cell('hq, North Plant', $brs) === [[1, 2], []] && hol_branch_cell('Moon', $brs)[1] === ['Moon']);
t('HO10 import headers by title', hol_header_map(['Date', 'To Date', 'Holiday', 'Branch']) === [0 => 'day', 1 => 'to', 2 => 'name', 3 => 'branches']);
