<?php
// Asset register rules (lib/assets.php) — synthetic data only.
require_once WN_ROOT . '/lib/assets.php';
t('AS01 tags run per category after the highest used', as_next_tag('AST', 'LAP', []) === 'AST-LAP-001' && as_next_tag('AST', 'LAP', ['AST-LAP-009', 'AST-LAP-010', 'AST-MOB-044', 'AST-LAP-X']) === 'AST-LAP-011');
$im = str_repeat('7', 15); $in = ['make' => 'TEST', 'model' => '<b>M1</b>', 'serial' => 'ab 12', 'imei1' => substr($im, 0, 2) . '-' . substr($im, 2), 'imei2' => '', 'sim_no' => '+' . str_repeat('0', 2) . ' ' . str_repeat('5', 5) . ' ' . str_repeat('5', 5), 'card_last4' => '1234', 'cost' => '1,200', 'bought_on' => '2026-01-10', 'branch_id' => '2'];
$p = as_clean($in, 'phone', [2], '2026-11-18');
t('AS02 a phone keeps IMEI and SIM, never a card; serial tidied; branch only from the list', $p['imei1'] === $im && $p['sim_no'] === '+00' . str_repeat('5', 10) && $p['card_last4'] === '' && $p['serial'] === 'AB12'
    && $p['model'] === 'M1' && $p['cost'] == 1200 && $p['branch_id'] === 2 && as_clean($in, 'general', [3], '2026-11-18')['branch_id'] === null && as_clean($in, 'general', [], '2026-11-18')['imei1'] === '');
$bad = function (array $x, string $k) { try { as_clean($x, $k, [], '2026-11-18'); return false; } catch (InvalidArgumentException $e) { return true; } };
t('AS03 cards keep only 4 digits; IMEI 15 digits; no future purchase date', as_clean(['card_last4' => '9876'], 'card', [], '2026-11-18')['card_last4'] === '9876' && $bad(['card_last4' => str_repeat('4', 16)], 'card')
    && $bad(['imei1' => '123'], 'phone') && $bad(['bought_on' => '2026-12-01'], 'general') && $bad(['bought_on' => '2026-02-30'], 'general'));
t('AS04 nobody deletes: scrap only after a proposal with a reason, by a confirmer; scrapped stays', as_status_problem('stock', 'scrapped', true, false) === 'Propose it for scrap first.'
    && as_status_problem('stock', 'proposed', false, false) === 'Give the reason for scrapping.' && as_status_problem('stock', 'proposed', false, false, 'TEST broken') === null
    && as_status_problem('proposed', 'scrapped', false, false) === 'Only a confirmer scraps an asset.' && as_status_problem('proposed', 'scrapped', true, false) === null
    && as_status_problem('scrapped', 'stock', true, false) === 'A scrapped asset stays scrapped.' && as_status_problem('proposed', 'stock', false, false) !== null && as_status_problem('proposed', 'stock', true, false) === null);
t('AS05 in use follows allotments; lost / stolen come from an issue; out items are taken back before scrap', as_status_problem('stock', 'use', true, false) !== null && as_status_problem('use', 'repair', true, true) !== null
    && as_status_problem('stock', 'lost', true, false) !== null && as_status_problem('use', 'proposed', true, true, 'x') !== null && as_status_problem('stock', 'repair', false, false) === null);
t('AS06 warranty: over, ending soon, fine', as_warranty('2026-11-01', '2026-11-18', 30) === 'over' && as_warranty('2026-12-01', '2026-11-18', 30) === 'soon' && as_warranty('2027-06-01', '2026-11-18', 30) === '' && as_warranty(null, '2026-11-18', 30) === '');
$audit = ['started_on' => '2026-11-10', 'closed_at' => null];
t('AS07 confirmation: never confirmed, or not since the open audit began; returned items never', as_needs_ack(['ack_at' => null, 'returned_on' => null], null) && !as_needs_ack(['ack_at' => '2026-10-01 10:00:00', 'returned_on' => null], null)
    && as_needs_ack(['ack_at' => '2026-10-01 10:00:00', 'returned_on' => null], $audit) && !as_needs_ack(['ack_at' => '2026-11-12 10:00:00', 'returned_on' => null], $audit)
    && !as_needs_ack(['ack_at' => null, 'returned_on' => '2026-11-01'], $audit) && !as_needs_ack(['ack_at' => '2026-10-01 10:00:00', 'returned_on' => null], ['closed_at' => '2026-11-15'] + $audit));
$a = ['id' => 1, 'serial' => 'SN1', 'imei1' => '111111111111111', 'imei2' => '', 'sim_no' => ''];
$others = [1 => $a + ['tag' => 'T1', 'status' => 'use'], 2 => ['serial' => 'sn1', 'imei1' => '', 'imei2' => '', 'sim_no' => '', 'tag' => 'T2', 'status' => 'stock'],
           3 => ['serial' => '', 'imei1' => '', 'imei2' => '111111111111111', 'sim_no' => '', 'tag' => 'T3', 'status' => 'use'], 4 => ['serial' => 'SN1', 'imei1' => '', 'imei2' => '', 'sim_no' => '', 'tag' => 'T4', 'status' => 'scrapped']];
t('AS08 duplicates: same serial or IMEI in either slot; itself and scrapped ones left out', as_dups($a, $others) === [2 => 'T2', 3 => 'T3']);
t('AS09 the log keeps only fields that changed', as_diff(['make' => 'A', 'model' => 'B', 'tag' => 'x'], ['make' => 'A', 'model' => 'C', 'status' => 'stock']) === [['model' => 'B', 'status' => null], ['model' => 'C', 'status' => 'stock']]);
[$o, $e] = as_settings_check(['prefix' => 'ab', 'edit_roles' => ['HRMANAGER'], 'confirm_roles' => [], 'warranty_days' => '400'], ['HRMANAGER' => 'HR']);
t('AS10 settings: prefix upper-cased, roles needed, days checked', $o['prefix'] === 'AB' && count($e) === 2);
