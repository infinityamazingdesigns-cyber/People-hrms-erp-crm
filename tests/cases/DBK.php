<?php
// Branch day book rules (lib/daybook.php) — synthetic data only.
require_once WN_ROOT . '/lib/daybook.php';
$dbkSet = DBK_DEFAULTS;
$dbkCtx = fn(array $roles, array $ticked, ?int $own) => dbk_ctx_for(['id' => 7, 'name' => 'TEST – Desk', 'emp_code' => 'tst001', 'branches' => $ticked], $roles, $dbkSet, $own, [1, 2, 3]);
$ce = $dbkCtx(['EMPLOYEE'], [3], 1); $chr = $dbkCtx(['HRMANAGER'], [2], null); $cceo = $dbkCtx(['CEO'], [2], null); $chr0 = $dbkCtx(['HRMANAGER'], [], null);
t('DBK01 who: an employee fills in their own + ticked branches and is no viewer; HR Manager sees ticked branches (none = all); CEO all',
    !dbk_is_viewer($ce) && dbk_entry_branches($ce) === [1, 3] && $chr['view'] === [2] && dbk_entry_branches($chr) === [2] && $chr0['view'] === null && $cceo['view'] === null && dbk_entry_branches($cceo) === [1, 2, 3]
    && !dbk_can_use($dbkCtx(['EMPLOYEE'], [], null)) && $ce['code'] === 'TST001');
$dbkErr = function (callable $f): string { try { $f(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } };
$cats = dbk_petty_cats($dbkSet);
$bl = dbk_clean('bill', ['bill_no' => 'T-1', 'party' => 'TEST – Party', 'amount' => '1,250.50'], null, $cats);
t('DBK02 bill: a number and party needed, amount read with commas, no cash effect', $bl['amount'] === 1250.5 && $bl['cash'] === 0.0
    && $dbkErr(fn() => dbk_clean('bill', ['party' => 'x', 'amount' => '1'], null, $cats)) !== '' && $dbkErr(fn() => dbk_clean('bill', ['bill_no' => '1', 'party' => 'x', 'amount' => '0'], null, $cats)) !== '');
$bill = ['id' => 5, 'party' => 'TEST – Party', 'city' => 'TEST City'];
$dp = dbk_clean('dispatch', ['how' => 'lr', 'transporter' => 'TEST Lines', 'lr_no' => 'L9', 'auto_amt' => '100', 'auto_mode' => 'Cash', 'frt_by' => 'ours', 'frt_amt' => '400', 'frt_mode' => 'UPI'], $bill, $cats);
t('DBK03 dispatch: needs the bill and LR details; only cash paid lowers cash in hand', $dp['cash'] === -100.0 && $dp['amount'] === 500.0 && $dp['ref'] === 5 && $dp['party'] === 'TEST – Party'
    && $dbkErr(fn() => dbk_clean('dispatch', ['how' => 'lr'], $bill, $cats)) !== '' && $dbkErr(fn() => dbk_clean('dispatch', ['how' => 'direct'], null, $cats)) !== '');
$rc = dbk_clean('receipt', ['amount' => '900', 'mode' => 'UPI', 'into' => 'person', 'person' => 'TEST – Ravi', 'ref' => 'U1'], $bill, $cats);
t('DBK04 money received: party from the bill, into a person = with employee and needs a reference; cash adds to cash in hand', $rc['withEmp'] === 1 && $rc['cash'] === 0.0 && $rc['party'] === 'TEST – Party'
    && $dbkErr(fn() => dbk_clean('receipt', ['party' => 'x', 'amount' => '9', 'mode' => 'UPI', 'into' => 'person', 'person' => 'x'], null, $cats)) !== ''
    && dbk_clean('receipt', ['party' => 'x', 'amount' => '9', 'mode' => 'Cash'], null, $cats)['cash'] === 9.0);
t('DBK05 petty cash: category from the list, no receipt needs why; cash paid lowers cash', dbk_clean('petty', ['particulars' => 'TEST tea', 'category' => $cats[0], 'amount' => '60', 'mode' => 'Cash'], null, $cats)['cash'] === -60.0
    && $dbkErr(fn() => dbk_clean('petty', ['particulars' => 'x', 'category' => 'Nope', 'amount' => '1', 'mode' => 'Cash'], null, $cats)) !== ''
    && $dbkErr(fn() => dbk_clean('petty', ['particulars' => 'x', 'category' => $cats[0], 'amount' => '1', 'mode' => 'Cash', 'no_receipt' => '1'], null, $cats)) !== '');
$days = [['day' => '2026-11-01', 'state' => 'closed', 'opening' => 1000, 'opening_src' => 'set', 'counted' => 1450], ['day' => '2026-11-02', 'state' => 'open', 'opening' => null, 'opening_src' => 'auto', 'counted' => null]];
$cash = ['2026-11-01' => [500.0, -40.0], '2026-11-02' => [200.0, -50.0]];
$w1 = dbk_walk($days, $cash, '2026-11-01'); $w2 = dbk_walk($days, $cash, '2026-11-02'); $w3 = dbk_walk($days, $cash, '2026-11-05'); $w0 = dbk_walk([], [], '2026-11-01');
t('DBK06 balance: opening set, next day opens at the cash counted, a later empty day carries on, nothing before = not set',
    $w1['opening'] === 1000.0 && $w1['calc'] === 1460.0 && $w2['opening'] === 1450.0 && $w2['calc'] === 1600.0 && $w3['opening'] === 1600.0 && $w0['opening'] === null && $w0['openingSrc'] === 'none');
$ents = [['kind' => 'receipt', 'withEmp' => true, 'files' => [], 'data' => ['party' => 'TEST P'], 'amount' => 9.0, 'by' => 'T'], ['kind' => 'petty', 'files' => [], 'data' => ['particulars' => 'x', 'no_receipt' => true], 'amount' => 1.0, 'by' => 'T'],
    ['kind' => 'petty', 'files' => [['id' => 1]], 'data' => ['particulars' => 'y'], 'amount' => 2.0, 'by' => 'T'], ['kind' => 'petty', 'files' => [], 'data' => ['particulars' => 'z'], 'amount' => 3.0, 'by' => 'T', 'removedAt' => '2026-11-19']];
t('DBK07 closing blocked by a missing screenshot / receipt only', count(dbk_close_blocks($ents)) === 1 && str_contains(dbk_close_blocks($ents)[0], 'screenshot'));
$tt = dbk_totals([['branch' => 1, 'byId' => 7, 'by' => 'TEST A', 'byCode' => 'T1', 'kind' => 'receipt', 'amount' => 100.0, 'withEmp' => true, 'settledAt' => null, 'data' => ['mode' => 'UPI']],
    ['branch' => 1, 'byId' => 8, 'by' => 'TEST B', 'byCode' => 'T2', 'kind' => 'petty', 'amount' => 30.0, 'withEmp' => false, 'data' => ['mode' => 'Cash']]]);
t('DBK08 totals by branch and by person', $tt['branches'][1]['received'] === 100.0 && $tt['branches'][1]['withEmp'] === 100.0 && $tt['branches'][1]['recUpi'] === 100.0 && $tt['branches'][1]['pettyCash'] === 30.0 && count($tt['people']) === 2);
[$so, $se] = dbk_settings_check(['view_roles' => ['CEO', 'X'], 'back_days' => 99, 'petty_cats' => "TEST A\n\nTEST A\nTEST B"], ['CEO' => 'c']);
t('DBK09 settings: roles from the list, back days capped, categories deduplicated', $so['view_roles'] === ['CEO'] && $so['back_days'] === 31 && $so['petty_cats'] === "TEST A\nTEST B" && !$se && count(dbk_settings_check([], ['CEO' => 'c'])[1]) === 2);
$src = file_get_contents(WN_ROOT . '/lib/daybook.php');
t('DBK10 nothing deleted; CSV made safe', !preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i', $src) && dbk_csv_cell('=1') === "\"'=1\"" && dbk_amount('2,000') === 2000.0);
