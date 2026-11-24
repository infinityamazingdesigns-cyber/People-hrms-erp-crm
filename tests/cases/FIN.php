<?php
/* Finance (lib/finance.php) — pure rules. */
require_once WN_ROOT . '/lib/finance.php';
t('FIN01 amounts read to whole minor units; bad amounts refused', fin_minor('25,000.50') === 2500050 && fin_minor('₹ 100') === 10000 && fin_minor('1.5') === 150 && fin_minor('1.234') === null && fin_minor('-5') === null && fin_minor('abc') === null);
t('FIN02 amounts shown with grouping, Dr / Cr', fin_amt(2550050, true) === '25,500.50' && fin_amt(1234567890, true) === '1,23,45,678.90' && fin_amt(1234567890) === '12,345,678.90' && fin_drcr(-500) === '5.00 Cr' && fin_drcr(0) === '0.00');
t('FIN03 financial year label and start follow the year-start month', fin_fy('2026-11-13', 4) === '26-27' && fin_fy('2027-03-31', 4) === '26-27' && fin_fy('2026-11-13', 1) === '2026'
    && fin_fy_start('2027-02-01', 4) === '2026-04-01' && fin_vno('payment', '26-27', 7) === 'PMT/26-27/0007');
$set = FIN_DEFAULTS;
$sa = fin_rights_from(['SUPERADMIN'], '', $set); $ceo = fin_rights_from(['CEO'], '', $set); $acc = fin_rights_from(['ACCOUNTS'], '', $set); $head = fin_rights_from(['EMPLOYEE'], 'head', $set); $aud = fin_rights_from(['CEO'], 'audit', $set);
t('FIN04 rights: setup roles only change setup / lock; CEO sees restricted, enters nothing; the team role works as executive; head posts', $sa['setup'] && $sa['lock'] && $sa['post'] && $sa['sens']
    && !$ceo['setup'] && !$ceo['enter'] && $ceo['sens'] && $acc['role'] === 'exec' && $acc['enter'] && !$acc['post'] && !$acc['sens'] && $head['post'] && $head['masters'] && !$head['setup'] && !$aud['enter']);
[$o, $e] = fin_settings_check(['setup_roles' => ['CEO'], 'restricted_roles' => ['CEO'], 'team_role' => 'NOPE'], role_labels());
t('FIN05 settings: Super Admin always keeps the setup; unknown team role falls back', !$e && in_array('SUPERADMIN', $o['setup_roles'], true) && $o['team_role'] === 'ACCOUNTS' && fin_settings_check(['setup_roles' => [], 'restricted_roles' => []], role_labels())[1] !== []);
$L = [1 => ['kind' => 'bank', 'active' => 1], 2 => ['kind' => '', 'active' => 1], 3 => ['kind' => 'cash', 'active' => 1], 4 => ['kind' => '', 'active' => 0]];
t('FIN06 a payment needs bank / cash on Cr and Dr = Cr', fin_voucher_check('payment', [[2, 5000, 0, ''], [1, 0, 5000, '']], $L)[0] === [] && fin_voucher_check('payment', [[2, 5000, 0, ''], [1, 0, 4000, '']], $L)[0] !== []
    && fin_voucher_check('payment', [[1, 5000, 0, ''], [2, 0, 5000, '']], $L)[0] !== [] && fin_voucher_check('payment', [[2, 5000, 0, ''], [1, 0, 5000, '']], $L)[1] === 5000);
t('FIN07 contra bank / cash only; journal never touches them; one side per line; switched-off ledger refused', fin_voucher_check('contra', [[3, 100, 0, ''], [1, 0, 100, '']], $L)[0] === []
    && fin_voucher_check('contra', [[2, 100, 0, ''], [1, 0, 100, '']], $L)[0] !== [] && fin_voucher_check('journal', [[2, 100, 0, ''], [3, 0, 100, '']], $L)[0] !== []
    && fin_voucher_check('journal', [[2, 100, 100, ''], [2, 0, 100, '']], $L)[0] !== [] && fin_voucher_check('journal', [[4, 100, 0, ''], [2, 0, 100, '']], $L)[0] !== []);
$rules = [['id' => 1, 'from_date' => '2026-01-01', 'limit_minor' => 100000, 'approver_id' => 9], ['id' => 2, 'from_date' => '2026-12-01', 'limit_minor' => 50000, 'approver_id' => 8]];
$r = fin_rule_on($rules, '2026-11-24');
t('FIN08 the rule in force on the date; at or above the limit (or no rule) goes to the approver', $r['id'] === 1 && fin_rule_on($rules, '2026-12-02')['id'] === 2 && fin_rule_on($rules, '2025-01-01') === null
    && fin_voucher_route($head, $r, 100000) === ['approver', 9] && fin_voucher_route($head, $r, 99999) === ['posted'] && fin_voucher_route($acc, $r, 10) === ['check'] && fin_voucher_route($head, null, 1) === ['approver', 0]);
$w = ['status' => 'waiting', 'created_by' => 5, 'need' => 'check', 'restricted' => 0];
t('FIN09 nobody decides their own voucher; the named approver or setup roles over the limit; restricted only for its roles', !fin_can_decide($head, 5, $w) && fin_can_decide($head, 6, $w) && !fin_can_decide($acc, 6, $w)
    && fin_can_decide($acc, 9, ['need' => 'approver', 'approver_id' => 9] + $w) && !fin_can_decide($head, 6, ['need' => 'approver', 'approver_id' => 9] + $w) && fin_can_decide($sa, 6, ['need' => 'approver', 'approver_id' => 9] + $w)
    && !fin_can_decide($head, 6, ['restricted' => 1] + $w) && !fin_can_decide($head, 6, ['status' => 'posted'] + $w));
$tb = fin_tb_build([['id' => 1, 'name' => 'Bank', 'group_name' => 'Bank Accounts', 'nature' => 'asset', 'restricted' => 0, 'active' => 1, 'opening_minor' => 0],
    ['id' => 2, 'name' => 'Salaries', 'group_name' => 'Indirect Expenses', 'nature' => 'expense', 'restricted' => 1, 'active' => 1, 'opening_minor' => 0],
    ['id' => 3, 'name' => 'Capital', 'group_name' => 'Capital Account', 'nature' => 'liability', 'restricted' => 0, 'active' => 1, 'opening_minor' => 0]], [1 => 7000, 2 => 3000, 3 => -10000], false);
$names = []; foreach ($tb['groups'] as $g) foreach ($g['rows'] as $row) $names[] = $row[0];
t('FIN10 trial balance agrees and folds restricted ledgers into one total line', $tb['dr'] === 10000 && $tb['cr'] === 10000 && !in_array('Salaries', $names, true) && in_array('Restricted ledgers (total)', $names, true) && $tb['groups'][0]['nature'] === 'asset');
t('FIN11 group kind walks up to the parent', fin_group_kind([['id' => 1, 'kind' => 'bank', 'parent_id' => null], ['id' => 2, 'kind' => '', 'parent_id' => 1]], 2) === 'bank');
$lib = (string)file_get_contents(WN_ROOT . '/lib/finance.php'); $pg = (string)file_get_contents(WN_ROOT . '/modules/finance/index.php');
t('FIN12 nothing deleted; bank numbers sealed and never logged; numbers given at posting; reversal never edits', !preg_match('/DELETE\s+FROM|TRUNCATE|DROP\s/i', $lib) && str_contains($lib, 'seal($acct)')
    && !preg_match('/fin_log\([^;]*\$acct/', $lib) && str_contains($lib, "status = \"posted\", number = ?") && str_contains($lib, 'Only a draft is changed'));
t('FIN13 page behind its gate, CSRF on posts, audit chained', str_contains($pg, "module_require('finance')") && str_contains($pg, 'check_csrf()') && in_array('fin_', AUDIT_CHAINED_PREFIXES, true));
