<?php
// Purchase part 1 rules (lib/purchase.php) — synthetic data only.
require_once WN_ROOT . '/lib/purchase.php';
$S0 = PUR_DEFAULTS;
t('PUR01 requisition goes to the manager at or above the value, or with a line nobody can price', pur_req_needs_hod(10000, 0, 10000) && !pur_req_needs_hod(9999.99, 0, 10000) && pur_req_needs_hod(10, 1, 10000));
$qs = [['id' => 1, 'vendor_id' => 7, 'rate' => 100, 'freight' => 0, 'delivery_days' => 10, 'pay_days' => 30], ['id' => 2, 'vendor_id' => 8, 'rate' => 90, 'freight' => 10, 'delivery_days' => 5, 'pay_days' => 60], ['id' => 3, 'vendor_id' => 9, 'rate' => 125, 'freight' => 0, 'delivery_days' => null, 'pay_days' => null]];
$sc = pur_qcd($qs, [7 => ['rej_pct' => 2.0, 'ontime_pct' => 90.0]], $S0);
t('PUR02 QCD: landed price, rejection history, quoted days + on-time, credit days; new vendors neutral', $sc[1]['price'] == 100 && $sc[2]['price'] == 100 && $sc[3]['price'] == 80 && $sc[1]['quality'] == 80 && $sc[2]['quality'] == 70
    && $sc[1]['delivery'] == 70 && $sc[2]['delivery'] == 85 && $sc[2]['payment'] == 100 && $sc[1]['payment'] == 50 && $sc[3]['payment'] == 0 && $sc[1]['history'] && !$sc[2]['history']);
t('PUR03 QCD best is the highest total; empty list gives nothing', $sc[2]['best'] && !$sc[1]['best'] && $sc[2]['total'] > $sc[1]['total'] && pur_qcd([], [], $S0) === []);
$sv = pur_saving($qs, 2, 10);
t('PUR04 saving of the chosen quote against the highest and the first, landed × quantity', $sv['vs_high'] === 250.0 && $sv['vs_first'] === 0.0 && pur_saving($qs, 99, 1)['vs_high'] === 0.0);
$T = pur_totals([['item' => 'TEST – bolt', 'qty' => 3, 'rate' => 33.33, 'tax_pct' => 18], ['item' => 'TEST – nut', 'qty' => 1, 'rate' => 10, 'tax_pct' => 5]], [['C', .5], ['S', .5]], false);
t('PUR05 order totals: each line taxed once, split by the pack, total to the whole unit', $T['sub'] === 109.99 && $T['tax'] === 18.5 && $T['parts'][0][1] + $T['parts'][1][1] === 18.5 && $T['total'] === 128.0 && $T['round_off'] === -0.49);
$T2 = pur_totals([['item' => 'TEST – bolt', 'qty' => 2, 'rate' => 50, 'tax_pct' => 18]], [['C', .5], ['S', .5]], true);
t('PUR06 an unregistered vendor\'s order carries no tax', $T2['tax'] === 0.0 && $T2['parts'] === [] && $T2['total'] === 100.0);
$o = ['status' => 'mgr', 'created_by' => 5, 'sent_by' => 5, 'mgr_by' => null, 'need_ceo' => 1];
t('PUR07 nobody approves their own order; approver takes the first step', pur_ord_step($o, 5, true, true)[1] === false && pur_ord_step($o, 6, true, false)[1] === true && pur_ord_step($o, 6, false, true)[1] === false);
$o2 = ['status' => 'ceo', 'created_by' => 5, 'sent_by' => 5, 'mgr_by' => 6];
t('PUR08 the CEO step: never the person who approved the first step', pur_ord_step($o2, 6, true, true)[1] === false && pur_ord_step($o2, 7, false, true)[1] === true && pur_ord_step($o2, 8, true, false)[1] === false);
$hd = array_fill_keys(array_keys(QT_HEAD_NEEDED), 'x'); $ln = [['item' => 'TEST – a', 'rate' => 10, 'cmp_id' => null]];
$po = ['sub' => 30000, 'single_note' => '', 'status' => 'draft'];
t('PUR09 at or above the limit: every line on a comparison, or a single-source reason', (bool)array_filter(pur_ord_problems($po, ['status' => 'approved'], $ln, $hd, $S0), fn($p) => str_contains($p, 'single source'))
    && !array_filter(pur_ord_problems(['single_note' => 'only maker'] + $po, ['status' => 'approved'], $ln, $hd, $S0), fn($p) => str_contains($p, 'single source'))
    && !pur_ord_problems(['sub' => 100] + $po, ['status' => 'new'], $ln, $hd, $S0));
t('PUR10 no vendor, a blocked vendor, a zero rate and a missing header are named', count(pur_ord_problems(['sub' => 0] + $po, ['status' => 'blocked'], [['item' => 'TEST – b', 'rate' => 0]], [], $S0)) >= 3 && in_array('Pick the vendor', pur_ord_problems($po, null, $ln, $hd, $S0), true)
    && in_array('Not approved yet', pur_ord_problems(['sub' => 1] + $po, ['status' => 'approved'], $ln, $hd, $S0, true), true));
[$ps, $pe] = pur_settings_check(['buyer_roles' => ['BUYER', 'X'], 'approver_roles' => [], 'limit_value' => '1,00,000', 'prefix_po' => 'p-o!', 'w_price' => 0, 'w_quality' => 0, 'w_delivery' => 0, 'w_payment' => 0, 'terms' => "a\nb\n\na"], ['BUYER' => 'b']);
t('PUR11 settings: roles checked, limit read with commas, prefix cleaned, weights not all zero, terms tidied', $ps['buyer_roles'] === ['BUYER'] && $ps['limit_value'] === 100000.0 && $ps['prefix_po'] === 'PO' && $ps['w_price'] === 50 && $ps['terms'] === "a\nb" && count($pe) === 2);
t('PUR12 vendor names compare without company words', pur_vkey('TEST – Acme Tools Pvt. Ltd.') === pur_vkey('test acme tools'));
