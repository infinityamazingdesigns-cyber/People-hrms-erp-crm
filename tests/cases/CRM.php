<?php
// CRM part 1 rules (lib/crm.php, lib/table_read.php, IN rule pack tax ID) — synthetic data only.
require_once WN_ROOT . '/lib/crm.php';
require_once WN_ROOT . '/rulepacks/IN/pack.php';
// A synthetic GSTIN-shaped value, built in pieces with its own check character (never a real registration).
$g14 = '27' . 'TESTA' . '0000' . 'A' . '1' . 'Z';
$chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $sum = 0;
for ($i = 0; $i < 14; $i++) { $p = strpos($chars, $g14[$i]) * ($i % 2 ? 2 : 1); $sum += intdiv($p, 36) + $p % 36; }
$good = $g14 . $chars[(36 - $sum % 36) % 36]; $bad = $g14 . ($good[14] === '0' ? '1' : '0');
$ok = crm_tax_check(strtolower($good), 'rp_in_tax_id');
t('CRM01 tax ID by the country rule: check character, region and inner part; wrong one refused; no rule = kept as typed', $ok['ok'] && $ok['value'] === $good && $ok['region'] === '27' && $ok['inner'] === substr($good, 2, 10)
    && !crm_tax_check($bad, 'rp_in_tax_id')['ok'] && crm_tax_check('', 'rp_in_tax_id')['ok'] && crm_tax_check('ab-12/3', null)['value'] === 'AB-12/3' && !crm_tax_check('a', null)['ok']);
t('CRM02 mobiles as digits, 00 dropped, short refused; the key is the local part', crm_phone_norm('+1 555-555-0123') === '15555550123' && crm_phone_norm('0044 20 1555 000') === '44201555000'
    && crm_phone_norm('12345') === '' && crm_phone_key('15555550123', 10) === '5555550123' && crm_phone_key('5555550123', 10) === '5555550123');
t('CRM03 names compared without Pvt / Ltd and punctuation; alike names score high', crm_norm_name('TEST Gears Pvt. Ltd.') === 'test gears' && crm_norm_name('Ltd') === 'ltd'
    && crm_name_sim('test gears', 'test gears') === 100 && crm_name_sim('test gears', 'testgears') >= 99 && crm_name_sim('test gears', 'quiet lamps') < 60 && crm_name_sim('', 'x') === 0);
$rows = [['id' => 1, 'name' => 'TEST Gears Pvt Ltd', 'tax_id' => $good, 'phones' => ['15555550123'], 'emails' => ['buy@example.com'], 'places' => ['TEST Town']],
         ['id' => 2, 'name' => 'TEST Gear', 'tax_id' => '', 'phones' => [], 'emails' => [], 'places' => ['TEST Town']],
         ['id' => 3, 'name' => 'Quiet Lamps', 'tax_id' => '', 'phones' => [], 'emails' => [], 'places' => ['Elsewhere']]];
$d = crm_dupes(['id' => 0, 'name' => 'Other', 'tax_id' => '', 'phones' => ['5555550123'], 'emails' => [], 'places' => []], $rows);
$s = crm_dupes(['id' => 0, 'name' => 'TEST Gears', 'tax_id' => '', 'phones' => [], 'emails' => [], 'places' => ['test town']], $rows);
t('CRM04 duplicates: the same mobile with or without the country code is exact; a similar name at the same place is a maybe; "not the same" skipped; self skipped',
    count($d) === 1 && $d[0]['id'] === 1 && $d[0]['exact'] && $d[0]['why'] === 'same mobile'
    && count($s) === 2 && !$s[0]['exact'] && !array_filter($s, fn($x) => $x['id'] === 3)
    && count(crm_dupes(['id' => 0, 'name' => 'TEST Gears', 'places' => ['test town']], $rows, [1, 2])) === 0 && !crm_dupes($rows[0] + [], [$rows[0]]));
t('CRM05 codes follow the last one; the change list compares screen words', crm_next_code('C', null) === 'C00001' && crm_next_code('C', 'C00041') === 'C00042' && crm_next_code('P', 'P00009') === 'P00010'
    && crm_diff(['Name' => 'A', 'Mobiles' => ['1', '2']], ['Name' => 'A', 'Mobiles' => ['1'], 'City' => 'X']) === ['Mobiles' => ['1, 2', '1'], 'City' => ['', 'X']]);
[$hi, $map] = tbl_map([['Report of customers'], [], ['Sr', 'Party Name', 'Mobile No.', 'E-mail', 'City', 'GSTIN']], CRM_IMPORT_COLS, 1);
t('CRM06 file columns found by their titles below a title row', $hi === 2 && $map['name'] === 1 && $map['mobile'] === 2 && $map['email'] === 3 && $map['city'] === 4 && $map['tax_id'] === 5
    && tbl_map([['x', 'y']], CRM_IMPORT_COLS, 1)[0] === -1);
$r = crm_import_row(['1', ' TEST  Gears ', '+1 555 555 0123 / 12', 'Buy@Example.com; nope', 'TEST Town', $bad], $map, 'rp_in_tax_id');
t('CRM07 one file row: names tidied, several numbers / emails in a cell, what could not be read named', $r['name'] === 'TEST Gears' && $r['phones'] === ['15555550123'] && $r['emails'] === ['buy@example.com']
    && in_array('mobile', $r['bad'], true) && in_array('email', $r['bad'], true) && in_array('tax ID', $r['bad'], true) && $r['tax_id'] === '' && $r['places'] === ['TEST Town']);
$csv = sys_get_temp_dir() . '/wn_crm_' . getmypid() . '.csv'; file_put_contents($csv, "Name;City\nTEST A;X\n\"TEST; B\";Y\n"); $rd = tbl_read($csv, 'a.csv'); @unlink($csv);
t('CRM08 CSV read with its own separator and quotes', $rd === [['Name', 'City'], ['TEST A', 'X'], ['TEST; B', 'Y']]);
[$st, $se] = crm_settings_check(['all_roles' => ['NOPE'], 'own_roles' => ['SALES'], 'categories' => "A\nA\n B ", 'units' => '', 'phone_digits' => 3], ['SALES' => 's', 'CEO' => 'c']);
t('CRM09 settings: unknown roles dropped, lists tidied, units never empty, digits in range', $st['all_roles'] === [] && $st['own_roles'] === ['SALES'] && $st['categories'] === "A\nB" && $st['units'] === 'pcs' && $st['phone_digits'] >= 6);
$bp = function (array $in): bool { try { crm_product_input($in); return false; } catch (RuntimeException $e) { return true; } };
$pp = crm_product_input(['sku' => 'tst-1', 'name' => ' TEST bolt ', 'tax_pct' => '18%', 'rate' => '1,250.50', 'unit' => '']);
t('CRM10 products: code upper case, tax % and rate read; no name, bad code, tax over 100, negative rate refused', $pp['sku'] === 'TST-1' && $pp['name'] === 'TEST bolt' && $pp['tax_pct'] === 18.0 && $pp['rate'] === 1250.5 && $pp['unit'] === 'pcs'
    && $bp(['name' => '']) && $bp(['name' => 'x', 'sku' => 'a b']) && $bp(['name' => 'x', 'tax_pct' => '120']) && $bp(['name' => 'x', 'rate' => '-1']));
