<?php
/* Finance part 2 (lib/fin_more.php) — feeds, openings, reports, bank reconciliation. Pure rules. */
require_once WN_ROOT . '/lib/fin_more.php';
$set = FIN_DEFAULTS;
$sa = fin_rights_from(['SUPERADMIN'], '', $set); $acc = fin_rights_from(['ACCOUNTS'], '', $set); $ceo = fin_rights_from(['CEO'], '', $set);
t('FM01 feeds: the Accounts team brings records in; payroll only for the payroll-feed roles; view-only never', fin_feed_can($acc, ['ACCOUNTS'], 'claim', $set) && !fin_feed_can($acc, ['ACCOUNTS'], 'payroll', $set)
    && fin_feed_can($sa, ['SUPERADMIN'], 'payroll', $set) && !fin_feed_can($ceo, ['CEO'], 'claim', $set) && !fin_feed_can($sa, ['SUPERADMIN'], 'nope', $set)
    && fin_feed_can($acc, ['ACCOUNTS', 'HR'], 'payroll', ['payroll_feed_roles' => ['HR']] + $set));
[$o] = fin_settings_check(['setup_roles' => ['CEO'], 'restricted_roles' => ['CEO'], 'payroll_feed_roles' => ['ACCOUNTS']], role_labels());
t('FM02 settings keep Super Admin on the payroll feed', in_array('SUPERADMIN', $o['payroll_feed_roles'], true) && in_array('ACCOUNTS', $o['payroll_feed_roles'], true));
$rows = [['gross' => 30000.0, 'net' => 26200.0, 'deductions' => ['Provident fund' => 1800.0, 'Income tax (TDS)' => 1500.0, 'Loan / advance' => 500.0], 'employer' => ['Provident fund' => 1950.0]],
         ['gross' => 20000.0, 'net' => 19000.0, 'deductions' => ['Professional tax' => 200.0, 'Other deductions' => 800.0], 'employer' => []], ['missing_ctc' => true, 'gross' => 0]];
[$L, $heads, $prob] = fin_payroll_lines($rows);
$dr = array_sum(array_column($L, 1)); $cr = array_sum(array_column($L, 2)); $keys = array_column($L, 0);
t('FM03 payroll comes in as totals only, balanced, deductions to their ledgers', $prob === '' && $heads === 2 && $dr === $cr && $dr === 5195000
    && in_array('pay.tds', $keys, true) && in_array('pay.loan', $keys, true) && in_array('pay.other', $keys, true) && in_array('pay.stat', $keys, true)
    && !array_filter($L, fn($l) => str_contains($l[3], 'TEST')) && fin_payroll_lines([])[2] !== '' && fin_payroll_lines([['gross' => 100, 'net' => -5]])[2] !== '');
[$C, $cp] = fin_claim_lines([['category' => 'Travel', 'kind' => 'dr', 'amount' => '1200.00', 'allowed' => 1, 'active' => 1], ['category' => 'Travel', 'kind' => 'dr', 'amount' => '300', 'allowed' => 1, 'active' => 1],
    ['category' => 'Meals', 'kind' => 'dr', 'amount' => '999', 'allowed' => 0, 'active' => 1], ['category' => 'Advance', 'kind' => 'cr', 'amount' => '500', 'allowed' => 1, 'active' => 1]], 0);
t('FM04 a paid claim: expenses by category (not-allowed left out), money received before, paid = the rest', $cp === '' && $C[0] === ['claim.expense', 150000, 0, 'Travel'] && $C[1] === ['claim.advance', 0, 50000, 'Money received before the claim'] && $C[2][2] === 100000
    && fin_claim_lines([['category' => 'X', 'kind' => 'dr', 'amount' => '100', 'allowed' => 1, 'active' => 1]], 10000)[1] !== '');
t('FM05 sale and purchase journals balance; bad totals refused', ($s = fin_sale_lines(10000, 11800, 'TEST – Customer')[0]) && array_sum(array_column($s, 1)) === array_sum(array_column($s, 2)) && count($s) === 3
    && fin_sale_lines(10000, 9000, 'x')[1] !== '' && ($p = fin_purchase_lines(1800, 11800, 'TEST – Vendor')[0]) && array_sum(array_column($p, 1)) === 11800 && fin_purchase_lines(0, 500, 'x')[0][0][1] === 500 && fin_purchase_lines(600, 500, 'x')[1] !== '');
$LL = [['id' => 1, 'name' => 'Bank', 'group_name' => 'Bank Accounts', 'nature' => 'asset', 'restricted' => 0, 'opening_minor' => 50000],
       ['id' => 2, 'name' => 'Capital', 'group_name' => 'Capital Account', 'nature' => 'liability', 'restricted' => 0, 'opening_minor' => -50000],
       ['id' => 3, 'name' => 'Sales', 'group_name' => 'Sales Accounts', 'nature' => 'income', 'restricted' => 0, 'opening_minor' => 0],
       ['id' => 4, 'name' => 'Salaries', 'group_name' => 'Indirect Expenses', 'nature' => 'expense', 'restricted' => 1, 'opening_minor' => 0],
       ['id' => 5, 'name' => 'Rent', 'group_name' => 'Indirect Expenses', 'nature' => 'expense', 'restricted' => 0, 'opening_minor' => 0]];
t('FM06 opening balances agree when Dr = Cr', fin_opening_diff($LL) === 0 && fin_opening_diff([['opening_minor' => 5]]) === 5);
$P = fin_pl_build($LL, [3 => [0, 100000], 4 => [40000, 0], 5 => [10000, 0]], false);
t('FM07 profit and loss: income Cr − Dr, expenses Dr − Cr, restricted as one total', $P['income_total'] === 100000 && $P['expense_total'] === 50000 && $P['profit'] === 50000
    && in_array(['Restricted ledgers (total)', null, 40000], $P['expense'][0]['rows'], true) && !in_array('Salaries', array_column($P['expense'][0]['rows'], 0), true)
    && in_array('Salaries', array_column(fin_pl_build($LL, [4 => [40000, 0]], true)['expense'][0]['rows'], 0), true));
$S = fin_bs_build($LL, [1 => 100000, 2 => -50000, 3 => -100000, 4 => 40000, 5 => 10000], true);
t('FM08 balance sheet: assets = liabilities + profit not yet closed', $S['asset_total'] === 100000 && $S['liability_total'] === 50000 && $S['profit'] === 50000 && $S['diff'] === 0
    && fin_bs_build($LL, [1 => 100000, 2 => -40000], true)['diff'] === 60000);
t('FM09 statement dates and amounts read in common bank shapes', fin_stmt_date('24/11/2026') === '2026-11-24' && fin_stmt_date('2026-11-24') === '2026-11-24' && fin_stmt_date('24-Nov-2026') === '2026-11-24'
    && fin_stmt_date('24 Nov 26') === '2026-11-24' && fin_stmt_date('31/02/2026') === null && fin_stmt_date('x') === null && fin_stmt_date('46350') === '2026-11-24'
    && fin_stmt_amt('1,250.50') === 125050 && fin_stmt_amt('') === 0 && fin_stmt_amt('12.345') === null && fin_stmt_amt('500 Dr') === 50000);
[$lines, $err] = fin_stmt_rows([['TEST – Bank statement'], ['Date', 'Narration', 'Chq./Ref.No.', 'Withdrawal Amt.', 'Deposit Amt.'], ['01/11/2026', 'NEFT TEST – Customer', 'R1', '', '1,000.00'],
    ['02/11/2026', 'UPI rent', '', '250', ''], ['02/11/2026', 'UPI rent', '', '250', ''], ['bad', 'x', '', '1', ''], ['', 'Closing balance', '', '', '']]);
t('FM10 statement rows: columns by their titles, two identical lines stay two, unreadable dates named', count($lines) === 3 && $lines[0][4] === 100000 && $lines[1][3] === 25000
    && $lines[1][5] !== $lines[2][5] && count($err) === 1 && fin_stmt_rows([['A', 'B']])[1] !== []
    && count(fin_stmt_rows([['Date', 'Description', 'Amount'], ['2026-11-01', 'x', '-75'], ['2026-11-02', 'y', '80']])[0]) === 2);
$sl = [['id' => 1, 'line_date' => '2026-11-01', 'out_minor' => 0, 'in_minor' => 100000], ['id' => 2, 'line_date' => '2026-11-02', 'out_minor' => 25000, 'in_minor' => 0], ['id' => 3, 'line_date' => '2026-11-03', 'out_minor' => 25000, 'in_minor' => 0]];
$en = [['key' => '7:1', 'vdate' => '2026-10-30', 'dr_minor' => 100000, 'cr_minor' => 0], ['key' => '8:2', 'vdate' => '2026-11-02', 'dr_minor' => 0, 'cr_minor' => 25000], ['key' => '9:2', 'vdate' => '2026-12-30', 'dr_minor' => 0, 'cr_minor' => 100000]];
$cd = fin_rec_candidates($sl, $en); $sure = fin_rec_sure($cd);
t('FM11 matching: same side, amount and within the days; sure only when one voucher fits one line', $cd[1] === ['7:1'] && $cd[2] === ['8:2'] && $cd[3] === ['8:2'] && $sure === [1 => '7:1']);
t('FM12 suggested ledger from earlier matched lines with the same first words', fin_rec_suggest('NEFT TEST Landlord rent Nov', [['NEFT TEST Landlord rent Oct', 5], ['UPI tea', 9], ['IMPS TEST landlord rent', 5]]) === [5, 2]
    && fin_rec_suggest('12345', [['x', 1]]) === null && fin_rec_words('NEFT-UPI/123/Electricity board') === 'electricity board');
$b = fin_brs_build(100000, [['dr_minor' => 20000, 'cr_minor' => 0], ['dr_minor' => 0, 'cr_minor' => 5000]], [['in_minor' => 300, 'out_minor' => 0], ['in_minor' => 0, 'out_minor' => 1000]]);
t('FM13 reconciliation statement: books − deposits not cleared + payments not presented + bank credits − bank debits', $b['bank'] === 100000 - 20000 + 5000 + 300 - 1000);
t('FM14 open opening balances until the books start is locked', fin_openings_open(['books_from' => null, 'locked_upto' => null]) && fin_openings_open(['books_from' => '2026-04-01', 'locked_upto' => '2026-03-31'])
    && !fin_openings_open(['books_from' => '2026-04-01', 'locked_upto' => '2026-04-01']));
t('FM15 every feed names map keys that exist; mapping keys have a nature', !array_filter(FIN_FEEDS, fn($f) => array_diff($f[1], array_keys(FIN_MAP_KEYS))) && !array_filter(FIN_MAP_KEYS, fn($k) => !isset(FIN_NATURES[$k[1]])));
$src = file_get_contents(WN_ROOT . '/lib/fin_more.php');
t('FM16 nothing in part 2 deletes or posts by itself (drafts only)', !preg_match('/\bDELETE\s+FROM|\bDROP\s|\bTRUNCATE\b/i', $src) && !preg_match('/fin_voucher_save\([^;]*,\s*true\s*,/', $src) && !str_contains($src, 'fin_post_in('));
$note = file_get_contents(WN_ROOT . '/lib/fin_note.php');
t('FM17 other modules show where a record stands in the books, only when Finance is allowed', str_contains($note, "module_allowed(\$u, 'finance')")
    && count(array_filter(['claims/index.php', 'vendorbills/index.php', 'payroll/index.php', 'crm/orders.php', 'purchase/index.php'], fn($f) => str_contains(file_get_contents(WN_ROOT . '/modules/' . $f), 'fin_feed_note('))) === 5);
