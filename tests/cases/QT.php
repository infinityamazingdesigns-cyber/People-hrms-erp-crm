<?php
// Quotation rules (lib/quotes.php, India pack sales tax) — synthetic data only.
require_once WN_ROOT . '/lib/quotes.php';
require_once WN_ROOT . '/rulepacks/IN/pack.php';
$in = (require WN_ROOT . '/rulepacks/IN/pack.php')['sales_tax'];
t('QT01 regions from a name, a code, an alias; unknown = blank', qt_region('Gujarat', $in['regions']) === '24' && qt_region('27', $in['regions']) === '27' && qt_region('Orissa', $in['regions'], $in['aliases']) === '21'
    && qt_region('tamil-nadu', $in['regions']) === '33' && qt_region('Atlantis', $in['regions']) === '' && qt_region('', $in['regions']) === '');
t('QT02 India split: the seller\'s state → CGST + SGST, else IGST; no seller region → IGST', rp_in_sales_tax('24', '24') === [['CGST', 0.5], ['SGST', 0.5]] && rp_in_sales_tax('24', '27') === [['IGST', 1.0]] && rp_in_sales_tax('', '') === [['IGST', 1.0]]);
$lines = [['qty' => 2, 'rate' => 100, 'discount_pct' => 10, 'tax_pct' => 18], ['qty' => 1, 'rate' => 50.55, 'discount_pct' => 0, 'tax_pct' => 5], ['qty' => 9, 'rate' => 40, 'discount_pct' => 50, 'tax_pct' => 0, 'charge' => true]];
$c = qt_calc($lines, [['CGST', 0.5], ['SGST', 0.5]]);
$tt = $c["totals"];
t('QT03 totals: discount before tax, a charge taxed at the highest goods rate with quantity 1 and no discount, halves add up, total rounded', $tt['taxable'] === 270.55 && $tt['discount'] === 20.0 && $tt['charges'] === 40.0
    && $c['lines'][2]['tax_pct'] === 18.0 && $c['lines'][2]['tax'] === 7.2 && $tt['tax_total'] === 42.13 && abs($tt['parts'][0][1] + $tt['parts'][1][1] - 42.13) < 0.001 && $tt['total'] === 313.0 && $tt['round_off'] === 0.32);
$ni = qt_calc($lines, [['IGST', 1.0]], false);
t('QT04 one tax part; no rounding keeps the paise', $ni["totals"]['parts'] === [['IGST', 42.13]] && $ni["totals"]['total'] === 312.68 && $ni["totals"]['round_off'] === 0.0);
t('QT05 amount in words: Indian lakh / crore, international million', rp_in_words(120000) === 'One Lakh Twenty Thousand Rupees Only' && rp_in_words(12345678.5) === 'One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight Rupees And Fifty Paise Only'
    && rp_in_words(0) === 'Zero Rupees Only' && qt_words_intl(1200000, 'Dollars', 'Cents') === 'One Million Two Hundred Thousand Dollars Only' && qt_words_intl(15.05, 'Euros', 'Cents') === 'Fifteen Euros And Five Cents Only');
t('QT06 revision numbers and the branch header filled from the company', qt_display_no(['quote_no' => 'QT/B1/26-27/0003', 'rev' => 0]) === 'QT/B1/26-27/0003' && qt_display_no(['quote_no' => 'QT/1', 'rev' => 2]) === 'QT/1-R2'
    && qt_head_merge(['legal_name' => 'TEST Co', 'address' => 'TEST Road'], ['address' => 'TEST Branch Road', 'legal_name' => ' '])['legal_name'] === 'TEST Co' && qt_head_merge(['address' => 'A'], ['address' => 'B'])['address'] === 'B');
t('QT07 the header needs a name, address and sales phone', qt_head_missing(['legal_name' => 'x', 'address' => '', 'sales_phone' => '']) === ['Address', 'Sales phone'] && qt_head_missing(['legal_name' => 'x', 'address' => 'y', 'sales_phone' => 'z']) === []);
[$s] = qt_settings_check(['prefix' => 'q-t!', 'lost_reasons' => '', 'follow_days' => 99]);
t('QT08 settings tidied', $s['prefix'] === 'QT' && $s['lost_reasons'] === 'Other' && $s['follow_days'] === 60 && $s['need_tax_id'] === false);
