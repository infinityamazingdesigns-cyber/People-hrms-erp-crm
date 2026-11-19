<?php
// Group insurance rules (lib/insurance.php) — synthetic data only.
require_once WN_ROOT . '/lib/insurance.php';
t('INS01 card numbers kept as on the card; spaces dropped; odd text refused', ins_card_clean(' tx 1234-01a ') === 'TX1234-01A' && ins_card_clean('') === '' && ins_card_clean('ab') === null && ins_card_clean('A$B1234') === null);
t('INS02 policy period: future, in force, renewal due, ended', ins_period_state('2026-12-01', '2027-11-30', '2026-11-19', 45) === 'future' && ins_period_state('2026-01-01', '2027-06-30', '2026-11-19', 45) === 'ok'
    && ins_period_state('2026-01-01', '2026-12-31', '2026-11-19', 45) === 'soon' && ins_period_state('2025-01-01', '2026-10-31', '2026-11-19', 45) === 'over' && ins_period_state(null, null, '2026-11-19', 45) === '');
[$p, $e] = ins_policy_check(['kind' => 'health', 'name' => 'TEST – Health 26-27', 'cover_from' => '2026-05-01', 'cover_till' => '2026-04-30', 'claims_email' => 'nope', 'sum_insured' => '5,00,000']);
t('INS03 policy check: period order, emails, sum read with commas', count($e) === 2 && $p['sum_insured'] === 500000.0 && count(ins_policy_check(['name' => 'x'])[1]) === 1);
[$nn, $ne] = ins_member_merge([], ['card_no' => 'TX123456'], '2026-11-19');
t('INS04 a card number means covered; not covered clears the card', $ne === [] && $nn['covered'] === 1 && ins_member_merge(['covered' => 1, 'card_no' => 'TX123456'], ['covered' => '0'], '2026-11-19')[0]['card_no'] === '');
[, $e2] = ins_member_merge([], ['nom_relation' => 'Spouse', 'nom_dob' => '2030-01-01', 'nom_phone' => '12'], '2026-11-19');
t('INS05 nominee: name needed, relation from the list, past date of birth, phone digits', count($e2) === 3 && count(ins_member_merge([], ['nom_name' => 'TEST – Asha', 'nom_relation' => 'Cousin'], '2026-11-19')[1]) === 1);
t('INS06 missing keys keep their value; changes are named by field', ins_member_merge(['covered' => 1, 'card_no' => 'TX123456', 'ins_id' => 'T1'], ['sum_insured' => '300000'], '2026-11-19')[0]['card_no'] === 'TX123456'
    && ins_changed(['covered' => 1, 'card_no' => 'A1'], ['covered' => 1, 'card_no' => 'B2']) === ['card_no']);
$rows = ins_parse_list("1  TST001  TX25221019-01E  Test Person One  19/06/1980  MALE  Self  300000\nTST002, TX25221020-01E\nnothing here\nTST001  TX25221019-01E  dup");
t('INS07 insurer list: ID, card, name, date of birth, relation, sum; repeats and junk left out', count($rows) === 2 && $rows[0]['ins_id'] === 'TST001' && $rows[0]['card'] === 'TX25221019-01E' && $rows[0]['dob'] === '1980-06-19' && $rows[0]['rel'] === 'Self' && $rows[0]['sum'] === '300000' && $rows[1]['ins_id'] === 'TST002');
$master = ['TST001' => ['name' => 'TEST Person One'], 'TST011' => ['name' => 'TEST Rohan Mehta'], 'QQX555' => ['name' => 'TEST Rohan Mehta']];
t('INS08 code suggestions: same code, near code with the same name, single name match, else none', ins_match(['ins_id' => 'TST001', 'name' => 'x'], $master) === ['TST001', 'code']
    && ins_match(['ins_id' => 'TST010', 'name' => 'Rohan Mehta'], $master) === ['TST011', 'near'] && ins_match(['ins_id' => 'ZZ9', 'name' => 'Rohan Mehta'], $master) === ['', ''] && ins_match(['ins_id' => 'ZZ9', 'name' => 'Person One'], $master) === ['TST001', 'name']);
[$st, $se] = ins_settings_check(['edit_roles' => ['HRMANAGER', 'X'], 'renew_days' => 999], ['HRMANAGER' => 'h']);
t('INS09 settings: roles checked, renewal window 1–180 days', $st['edit_roles'] === ['HRMANAGER'] && $st['renew_days'] === 180 && $se === [] && ins_settings_check([], [])[1] !== []);
