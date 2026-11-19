<?php
// Staff movement rules (lib/movement.php) — synthetic data only.
require_once WN_ROOT . '/lib/movement.php';
$mvSet = MV_DEFAULTS;
t('MV01 rights: Reception records, Production Head reads reports only, settings roles configure', mv_rights_for(['RECEPTION'], $mvSet) === ['record' => true, 'report' => false, 'configure' => false, 'view' => true]
    && mv_rights_for(['PRODHEAD'], $mvSet)['record'] === false && mv_rights_for(['PRODHEAD'], $mvSet)['report'] === true && mv_rights_for(['EMPLOYEE'], $mvSet)['view'] === false);
t('MV02 scope: Reception / Production Head with nothing ticked see no branch; HR Manager with none ticked sees all; Super Admin always all',
    mv_scope_for(['RECEPTION'], []) === [] && mv_scope_for(['PRODHEAD', 'EMPLOYEE'], []) === [] && mv_scope_for(['HRMANAGER'], []) === null && mv_scope_for(['PRODHEAD'], ['2']) === [2] && mv_scope_for(['SUPERADMIN'], [3]) === null);
t('MV03 department filter: blank = everyone, else any listed word', mv_dept_ok('Office', '') && mv_dept_ok('Production – Assembly', 'production, stores') && !mv_dept_ok('Accounts', 'production, stores') && !mv_dept_ok('', 'production'));
$mvErr = function (callable $f): string { try { $f(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } };
t('MV04 material: nothing out = no answer; out needs an answer; part / not back need a note', mv_material_check('', '', '') === null && mv_material_check('TEST – 2 drills', 'yes', '') === 'yes'
    && $mvErr(fn() => mv_material_check('TEST – 2 drills', '', '')) !== '' && $mvErr(fn() => mv_material_check('TEST – 2 drills', 'partial', '')) !== '' && mv_material_check('TEST – 2 drills', 'no', 'TEST – left at site') === 'no');
$mvPeople = [['code' => 'TST001', 'name' => 'TEST – One', 'dept' => 'Production', 'branch_id' => 1], ['code' => 'TST002', 'name' => 'TEST – Two', 'dept' => 'Production', 'branch_id' => 1]];
t('MV05 picking: only from the list, repeats once, nobody / too many refused', count(mv_pick(['tst001', 'TST001 ', 'TST002'], $mvPeople)) === 2
    && $mvErr(fn() => mv_pick(['TST009'], $mvPeople)) !== '' && $mvErr(fn() => mv_pick([], $mvPeople)) !== '' && $mvErr(fn() => mv_pick(['TST001', 'TST002'], $mvPeople, 1)) !== '');
$mvNow = strtotime('2026-11-19 16:00:00');
$f1 = mv_flags(['out_at' => '2026-11-19 15:00:00', 'status' => 'out'], 30, '18:30', $mvNow);
$f2 = mv_flags(['out_at' => '2026-11-19 15:45:00', 'status' => 'out'], 30, '15:50', $mvNow);
$f3 = mv_flags(['out_at' => '2026-11-18 17:00:00', 'status' => 'out'], 600, null, $mvNow);
$f4 = mv_flags(['out_at' => '2026-11-19 15:55:00', 'status' => 'out'], 30, '15:50', $mvNow);
t('MV06 late flags: over the limit, shift end passed while out, still out from an earlier day; going out after shift end is not flagged',
    $f1['overdue'] && !$f1['shift_end'] && $f1['mins'] === 60 && !$f2['overdue'] && $f2['shift_end'] && $f3['shift_end'] && !$f4['shift_end']
    && !mv_flags(['out_at' => '2026-11-19 15:00:00', 'status' => 'in'], 30, '15:30', $mvNow)['overdue']);
[$mvS, $mvE] = mv_settings_check(['record_roles' => ['RECEPTION', 'NOPE'], 'report_roles' => ['PRODHEAD'], 'configure_roles' => ['SUPERADMIN'], 'alert_roles' => [], 'overdue_min' => 2, 'branch_limits' => ['1' => '45', '9' => '20', '2' => ''], 'departments' => 'Production ,Stores'],
    ['RECEPTION' => 'r', 'PRODHEAD' => 'p', 'SUPERADMIN' => 's'], [1, 2]);
t('MV07 settings: unknown roles dropped, a role list may not be empty, limits 5–600, branch limits only for real branches', $mvS['record_roles'] === ['RECEPTION'] && count($mvE) === 1
    && $mvS['overdue_min'] === 5 && $mvS['branch_limits'] === ['1' => 45] && $mvS['departments'] === 'Production, Stores' && mv_limit($mvS, 1) === 45 && mv_limit($mvS, 2) === 5);
$mvSum = mv_summary([['emp_name' => 'TEST – One', 'emp_code' => 'TST001', 'duration_min' => 40, 'branch_id' => 1, 'status' => 'in', 'reason' => 'Bank', 'reason_other' => ''],
    ['emp_name' => 'TEST – One', 'emp_code' => 'TST001', 'duration_min' => 10, 'branch_id' => 1, 'status' => 'in', 'reason' => null, 'reason_other' => ''],
    ['emp_name' => 'TEST – Two', 'emp_code' => 'TST002', 'duration_min' => null, 'branch_id' => 1, 'status' => 'out', 'reason' => null, 'reason_other' => 'TEST – x']], MV_DEFAULTS);
t('MV08 report: counts, minutes, late returns, still out, by person and reason', $mvSum['total'] === ['n' => 3, 'min' => 50, 'late' => 1, 'open' => 1] && $mvSum['person']['TEST – One · TST001']['n'] === 2
    && isset($mvSum['reason']['No reason given'], $mvSum['reason']['Other']) && mv_dur(75) === '1 h 15 min' && mv_dur(null) === '');
$mvSrc = file_get_contents(WN_ROOT . '/lib/movement.php');
t('MV09 a record only: never writes attendance or pay, never deletes', !preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i', $mvSrc) && !preg_match('/INSERT\s+INTO\s+(att_|pay_|attendance)/i', $mvSrc));
$mvCsv = mv_csv([['mv_no' => 'M2611-00001', 'emp_code' => 'TST001', 'emp_name' => '=TEST', 'emp_dept' => '', 'branch_id' => 1, 'out_at' => '2026-11-19 10:00:00', 'in_at' => null, 'duration_min' => null,
    'reason' => null, 'reason_other' => '', 'destination' => 'TEST "x"', 'vehicle_no' => '', 'material_out' => '', 'material_back' => null, 'material_note' => '']], [1 => 'TEST Branch']);
t('MV10 CSV: one quoting, formulas made safe', str_contains($mvCsv, "\"'=TEST\"") && str_contains($mvCsv, '"TEST ""x"""') && !str_contains($mvCsv, '"""M'));
