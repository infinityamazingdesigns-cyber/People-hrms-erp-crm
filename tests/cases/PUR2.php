<?php
// Purchase part 2 rules (lib/purchase_grn.php) — synthetic data only.
require_once WN_ROOT . '/lib/purchase.php';
t('PUR13 working days leave out Sundays; same day or earlier is 0', pur_working_days('2026-11-13', '2026-11-16') === 2 && pur_working_days('2026-11-14', '2026-11-16') === 1 && pur_working_days('2026-11-16', '2026-11-16') === 0 && pur_working_days('2026-11-16', '2026-11-10') === 0);
t('PUR14 vendor score: half on-time, half quality; one side alone when the other is unknown; none without receipts', pur_vendor_score(90.0, 2.0) === 85.0 && pur_vendor_score(null, 1.0) === 90.0 && pur_vendor_score(80.0, null) === 80.0 && pur_vendor_score(null, null) === null && pur_vendor_score(100.0, 20.0) === 50.0);
t('PUR15 grades A ≥ 85, B ≥ 70, C below; nothing without a score', pur_grade(85.0) === 'A' && pur_grade(84.9) === 'B' && pur_grade(70.0) === 'B' && pur_grade(69.9) === 'C' && pur_grade(null) === '');
$rs = ['Dimension', 'Other'];
t('PUR16 quality check: accepted + rejected = received, a reason when rejected, test certificate stated', pur_qc_check(10, 8, 2, 'Dimension', 'yes', $rs) === [] && pur_qc_check(10, 10, 0, '', 'na', $rs) === []
    && count(pur_qc_check(10, 8, 1, 'Dimension', 'yes', $rs)) === 1 && count(pur_qc_check(10, 8, 2, '', 'yes', $rs)) === 1 && count(pur_qc_check(10, 10, 0, '', 'maybe', $rs)) === 1 && count(pur_qc_check(10, null, null, '', '', $rs)) === 2);
t('PUR17 KRA: met depends on direction; nothing to measure stays empty', pur_kpi_met(95.0, 90.0, true) === true && pur_kpi_met(3.0, 2.0, false) === false && pur_kpi_met(0.0, 0.0, false) === true && pur_kpi_met(null, 90.0, true) === null);
[$ps] = pur_settings_check(['buyer_roles' => ['BUYER'], 'approver_roles' => ['BUYER'], 'recv_roles' => ['STORE', 'Z'], 'qc_roles' => ['QUALITY'], 'po_days' => '0', 'reject_reasons' => '', 'w_price' => 1], ['BUYER' => 'b', 'STORE' => 's', 'QUALITY' => 'q']);
t('PUR18 settings: receive / check roles, target days at least 1, a rejection reason always there', $ps['recv_roles'] === ['STORE'] && $ps['qc_roles'] === ['QUALITY'] && $ps['po_days'] === 1 && $ps['reject_reasons'] === 'Other');
$sc = pur_qcd([['id' => 1, 'vendor_id' => 7, 'rate' => 100, 'freight' => 0, 'delivery_days' => 5, 'pay_days' => 30]], [7 => ['rej_pct' => 5.0, 'ontime_pct' => 60.0]], PUR_DEFAULTS);
t('PUR19 the scorecard feeds the comparison score', $sc[1]['quality'] == 50 && $sc[1]['delivery'] == 80 && $sc[1]['history']);
