<?php
// Visitor rules (lib/visitors.php) — synthetic data only.
require_once WN_ROOT . '/lib/visitors.php';
$set = VIS_DEFAULTS;
t('VS01 rights: reception has the desk only; reports, settings and removal for the listed roles', vis_rights_for(['RECEPTION'], $set) === ['desk' => true, 'report' => false, 'configure' => false, 'purge' => false]
    && vis_rights_for(['CEO'], $set) === ['desk' => true, 'report' => true, 'configure' => true, 'purge' => true] && !vis_rights_for(['HRMANAGER'], $set)['purge'] && !vis_rights_for(['EMPLOYEE'], $set)['desk']);
t('VS02 branches: Super Admin / CEO everywhere; ticked ones; reception with none ticked gets none; others with none ticked everywhere', vis_scope_for(['SUPERADMIN'], [2]) === null
    && vis_scope_for(['RECEPTION'], ['3']) === [3] && vis_scope_for(['RECEPTION'], []) === [] && vis_scope_for(['HRMANAGER'], []) === null && vis_in([3], 3) && !vis_in([], 3) && vis_in(null, 9));
t('VS03 mobiles: kept as typed with +, keyed by the last 10 digits, masked', vis_mobile(' +1 (000) 000-0099 ') === '+10000000099' && vis_mobile_key('+10000000099') === '0000000099' && vis_mask('0000000099') === '••••••0099');
$x = vis_extra_contacts(['+1 000 000 0099', '0000000088', '0000000088', '12'], ['A@example.com', 'main@example.com', 'bad'], '0000000099', 'MAIN@example.com');
t('VS04 extra contacts: not the main ones, no repeats, checked', $x === ['mobile' => ['0000000088'], 'email' => ['a@example.com']]
    && count(vis_extra_contacts(array_map(fn($i) => '00000000' . sprintf('%02d', $i), range(10, 20)), [], '', '')['mobile']) === VIS_MAX_CONTACTS);
$bad = function (callable $f): bool { try { $f(); return false; } catch (InvalidArgumentException $e) { return true; } };
t('VS05 expected visits: from today up to the limit, time optional', vis_expect_check('2026-11-20', '10:30', '2026-11-18') === ['2026-11-20', '10:30:00'] && vis_expect_check('2026-11-18', '', '2026-11-18') === ['2026-11-18', null]
    && $bad(fn() => vis_expect_check('2026-11-17', '', '2026-11-18')) && $bad(fn() => vis_expect_check('2027-03-01', '', '2026-11-18')) && $bad(fn() => vis_expect_check('2026-11-19', '25:00', '2026-11-18')) && $bad(fn() => vis_expect_check('x', '', '2026-11-18')));
t('VS06 check-in only at reception and after the host answer (or none needed)', vis_checkin_problem(['status' => 'waiting', 'approval' => 'approved']) === '' && vis_checkin_problem(['status' => 'waiting', 'approval' => 'not_needed']) === ''
    && vis_checkin_problem(['status' => 'waiting', 'approval' => 'pending']) !== '' && vis_checkin_problem(['status' => 'expected', 'approval' => 'not_needed']) !== '');
t('VS07 overdue after the hours set, only while inside', vis_overdue(['status' => 'inside', 'check_in_at' => '2026-11-18 08:00:00'], 8, strtotime('2026-11-18 16:30:00'))
    && !vis_overdue(['status' => 'inside', 'check_in_at' => '2026-11-18 09:00:00'], 8, strtotime('2026-11-18 16:30:00')) && !vis_overdue(['status' => 'out', 'check_in_at' => '2026-11-18 01:00:00'], 8, strtotime('2026-11-18 16:30:00')));
$p = ['id' => 5, 'name' => 'TEST – Guest', 'company' => 'TEST Co', 'designation' => 'Buyer', 'email' => 'g@example.com', 'address' => 'TEST street'];
t('VS08 lookup: full details only for a visitor known at the login\'s branches', vis_lookup_view($p, true, 3)['email'] === 'g@example.com' && !isset(vis_lookup_view($p, false, 3)['email']) && !isset(vis_lookup_view($p, false, 3)['address']) && vis_lookup_view($p, false, 3)['visits'] === 3);
t('VS09 CSV cells: quotes doubled, formula starts neutralised', vis_csv_cell('a"b') === '"a""b"' && vis_csv_cell('=SUM(A1)') === "\"'=SUM(A1)\"" && vis_csv_cell('-5') === "\"'-5\"");
$rows = [['status' => 'out', 'category' => 'Supplier', 'branch_id' => 1, 'host_name' => 'TEST A', 'duration_min' => 30], ['status' => 'out', 'category' => 'Supplier', 'branch_id' => 2, 'host_name' => 'TEST A', 'duration_min' => 50],
         ['status' => 'cancelled', 'category' => 'Courier', 'branch_id' => 1, 'host_name' => 'TEST B', 'duration_min' => null]];
$rp = vis_report($rows);
t('VS10 report: counts by status / category / branch / host and average minutes', $rp['total'] === 3 && $rp['avg_min'] === 40 && $rp['category'] === ['Supplier' => 2, 'Courier' => 1] && $rp['host']['TEST A'] === 2 && $rp['status']['cancelled'] === 1);
t('VS11 retention cut-off by months', vis_cutoff('2026-11-18', 12) === '2025-11-18' && vis_cutoff('2026-03-31', 1) === '2026-02-28' && vis_cutoff('2026-01-15', 2) === '2025-11-15');
[$s, $se] = vis_settings_check(['desk_roles' => ['RECEPTION'], 'report_roles' => [], 'configure_roles' => ['CEO'], 'purge_roles' => ['CEO'], 'keep_months' => 500, 'privacy' => ''], ['RECEPTION' => 'r', 'CEO' => 'c']);
t('VS12 settings: roles checked, months in range, privacy line needed', count($se) === 2 && $s['keep_months'] === 120 && $s['desk_roles'] === ['RECEPTION']);
