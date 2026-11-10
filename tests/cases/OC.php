<?php
// Org chart rules (lib/orgchart.php) — synthetic data only.
require_once WN_ROOT . '/lib/orgchart.php';
$P = fn($c, $n, $m = '', $last = null, $active = 1) => ['code' => $c, 'name' => $n, 'manager_code' => $m, 'designation' => 'D', 'department' => 'Ops',
    'branch_id' => 1, 'last_day' => $last, 'resign_date' => null, 'doj' => '2025-01-01', 'active' => $active];
$people = [$P('T1', 'TEST Top'), $P('T2', 'TEST Mid', 'T1', '2026-01-31'), $P('T3', 'TEST Low', 'T2'), $P('T4', 'TEST Side', 'T1'), $P('T5', 'TEST Notice', 'T4', '2026-12-31')];
$today = '2026-11-10';
$pm = org_parent_map($people);
t('OC01 chain from the top', org_chain('T3', $pm) === ['T1', 'T2', 'T3']);
t('OC02 moving a manager under their own team is a circle', org_would_cycle('T1', 'T3', $pm) && !org_would_cycle('T3', 'T4', $pm));
$emp = org_build($people, [], false, $today);
t('OC03 leaver hidden; their report hangs from the next visible person', !isset($emp['nodes']['T2']) && $emp['parent']['T3'] === 'T1');
t('OC04 employees never see status, notice or open positions', !isset($emp['nodes']['T5']['status']) && !isset($emp['nodes']['T5']['last_day']));
$pos = [['id' => 7, 'status' => 'open', 'title' => 'Engineer', 'department' => 'Ops', 'branch_id' => 1, 'manager_code' => 'T2', 'replaces_code' => 'T2']];
$hr = org_build($people, $pos, true, $today);
t('OC05 holders see notice and open positions; position under a leaver moves up', $hr['nodes']['T5']['status'] === 'notice' && isset($hr['nodes']['P7']) && $hr['parent']['P7'] === 'T1');
t('OC06 holders can show leavers', isset(org_build($people, [], true, $today, true)['nodes']['T2']));
t('OC07 switched-off records never appear', !isset(org_build([$P('T9', 'TEST Off', '', null, 0)], [], true, $today)['nodes']['T9']));
$ck = array_column(org_checks($people, $pos, $today), 0);
t('OC08 checks: report of a leaver and position under a leaver', in_array('mgrleft', $ck, true) && in_array('posleft', $ck, true) && !in_array('nomgr', $ck, true));
$ck2 = array_column(org_checks([$P('A1', 'TEST A', 'A2'), $P('A2', 'TEST B', 'A1'), $P('A3', 'TEST C', 'ZZ9')], [], $today), 0);
t('OC09 checks: circles and unknown manager codes', in_array('cycle', $ck2, true) && in_array('unknown', $ck2, true));
t('OC10 team counts everyone below', count(org_team('T1', $pm)) === 4);
