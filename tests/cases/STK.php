<?php
// Stock & inventory rules (lib/stock.php) — synthetic data only.
require_once WN_ROOT . '/lib/stock.php';
$bad = function (callable $f): bool { try { $f(); return false; } catch (RuntimeException $e) { return true; } };
t('STK01 quantities read to 3 decimals, shown without trailing zeros', stk_q('1,250.5') === 1250.5 && stk_q('0.0004') === 0.0 && stk_q('x') === null && stk_show(12.5) === '12.5' && stk_show(1000.0) === '1,000' && stk_show(-0.0) === '0');
$ok = stk_check_lines('OUT', [['item_id' => 1, 'qty' => '-3', 'kind' => 'OUT_SALE'], ['item_id' => 2, 'qty' => '-1.25', 'kind' => 'OUT_ISS', 'rate' => 'x']]);
t('STK02 lines checked: kind belongs to the entry, sign follows the kind, zero refused, a bad rate dropped', count($ok) === 2 && $ok[1]['qty'] === -1.25 && $ok[1]['rate'] === null
    && $bad(fn() => stk_check_lines('OUT', [['item_id' => 1, 'qty' => '3', 'kind' => 'OUT_SALE']])) && $bad(fn() => stk_check_lines('IN', [['item_id' => 1, 'qty' => '3', 'kind' => 'OUT_SALE']]))
    && $bad(fn() => stk_check_lines('IN', [['item_id' => 1, 'qty' => '0', 'kind' => 'IN_PUR']])) && $bad(fn() => stk_check_lines('IN', [])) && count(stk_check_lines('CNT', [['item_id' => 1, 'qty' => '-2', 'kind' => 'ADJ'], ['item_id' => 1, 'qty' => '2', 'kind' => 'ADJ']])) === 2
    && count(stk_check_lines('REV', [['item_id' => 1, 'qty' => '5', 'kind' => 'OUT_SALE']])) === 1);
$sh = stk_short(['1:5' => 10.0, '1:6' => 2.0], [['key' => '1:5', 'qty' => -4.0], ['key' => '1:5', 'qty' => -7.0], ['key' => '1:6', 'qty' => -2.0], ['key' => '1:7', 'qty' => 3.0]]);
t('STK03 never below zero: two lines of one item add up; exactly zero is fine', array_keys($sh) === ['1:5'] && $sh['1:5'] === ['have' => 10.0, 'need' => 11.0] && stk_short(['1:5' => 1.0], [['key' => '1:5', 'qty' => 1.0]]) === []);
t('STK04 state and reorder suggestion count stock in transit', stk_state(-1, 5) === 'below_zero' && stk_state(2, 5) === 'below_min' && stk_state(2, 5, 3) === 'ok' && stk_state(0, 0) === 'zero' && stk_state(4, 0) === 'ok'
    && stk_suggest(2, 1, 10, 0) === 7.0 && stk_suggest(2, 1, 10, 20) === 20.0 && stk_suggest(8, 2, 10, 20) === 0.0);
t('STK05 a count posts only the differences, both figures kept in the note', stk_count_lines([1 => 10.0, 2 => 3.0], [1 => 10.0, 2 => 1.5, 3 => 4.0]) === [['item_id' => 2, 'qty' => -1.5, 'kind' => 'ADJ', 'note' => 'Books 3, counted 1.5'], ['item_id' => 3, 'qty' => 4.0, 'kind' => 'ADJ', 'note' => 'Books 0, counted 4']]);
[$l1, $s1, $st1] = stk_receive_plan([1 => 10.0, 2 => 5.0], [], false);
[$l2, $s2, $st2] = stk_receive_plan([1 => 10.0, 2 => 5.0], [1 => 4], false);
[$l3, $s3, $st3] = stk_receive_plan([1 => 10.0, 2 => 5.0], [1 => 4], true);
t('STK06 receiving a transfer: all by default, part keeps it in transit, closing short ends it; more than sent refused', $l1 === [1 => 10.0, 2 => 5.0] && $st1 === 'received' && $l2 === [1 => 4.0, 2 => 5.0] && $st2 === 'transit'
    && $l3 === [1 => 4.0] && $st3 === 'short' && $bad(fn() => stk_receive_plan([1 => 2.0], [1 => 3], false)) && $bad(fn() => stk_receive_plan([1 => 2.0], [1 => 0], false)));
$mv = [['item_id' => 1, 'd' => '2026-10-31', 'qty' => 10, 'kind' => 'OPEN'], ['item_id' => 1, 'd' => '2026-11-02', 'qty' => 5, 'kind' => 'IN_PUR'], ['item_id' => 1, 'd' => '2026-11-03', 'qty' => -3, 'kind' => 'OUT_SALE'],
       ['item_id' => 1, 'd' => '2026-11-04', 'qty' => -1, 'kind' => 'ADJ'], ['item_id' => 1, 'd' => '2026-11-05', 'qty' => -2, 'kind' => 'XFR_OUT'], ['item_id' => 1, 'd' => '2026-12-01', 'qty' => 50, 'kind' => 'IN_PUR'], ['item_id' => 2, 'd' => '2026-11-10', 'qty' => 1, 'kind' => 'IN_OTH']];
$s = stk_statement_calc($mv, '2026-11-01', '2026-11-30');
t('STK07 statement: opening before the period, columns in it (outs as positive), adjustments signed, closing, later moves left out', $s[1]['opening'] === 10.0 && $s[1]['in_pur'] === 5.0 && $s[1]['out_sale'] === 3.0 && $s[1]['adj'] === -1.0 && $s[1]['out_xfr'] === 2.0 && $s[1]['closing'] === 9.0 && $s[2]['in_oth'] === 1.0
    && $s[1]['opening'] + $s[1]['in_pur'] + $s[1]['in_xfr'] + $s[1]['in_oth'] - $s[1]['out_sale'] - $s[1]['out_use'] - $s[1]['out_xfr'] - $s[1]['out_oth'] + $s[1]['adj'] === $s[1]['closing']);
[$st, $se] = stk_settings_check(['store_roles' => [], 'lock_until' => '2026-13-01', 'units' => "kg\nKG\n"], ['STORE' => 's']);
t('STK08 settings: a store role needed, lock date checked, units upper case', count($se) === 2 && $st['lock_until'] === '' && $st['units'] === 'KG' && $st['negative'] === false);
t('STK09 the ledger is insert-only: no UPDATE or DELETE of stk_move anywhere', !preg_match('/(UPDATE|DELETE\s+FROM)\s+stk_move/i', (string)file_get_contents(WN_ROOT . '/lib/stock.php') . (string)file_get_contents(WN_ROOT . '/modules/stock/index.php')));
