<?php
// Feedback & conduct rules (lib/conduct.php) — synthetic data only.
require_once WN_ROOT . '/lib/conduct.php';
$t = '2026-11-18'; $R = 3;
t('DC01 minor matter: a recorded note first, then a memo', dc_next_step_for('minor', [], false, $t, $R)['step'] === 'feedback' && dc_next_step_for('minor', [], true, $t, $R)['step'] === 'memo');
$memo = ['step' => 'memo', 'severity' => 'minor', 'issued_at' => '2026-10-01 10:00:00', 'ref' => 'DC-2026-0001'];
$warn = ['step' => 'warning', 'severity' => 'moderate', 'issued_at' => '2026-06-01 10:00:00', 'ref' => 'DC-2026-0002'];
t('DC02 repeats go one step up; after a memo comes a warning (show-cause only for serious); then a final warning', dc_next_step_for('moderate', [$memo], true, $t, $R)['step'] === 'warning'
    && dc_next_step_for('serious', [$memo], true, $t, $R)['step'] === 'showcause' && dc_next_step_for('moderate', [$warn], true, $t, $R)['step'] === 'final'
    && dc_next_step_for('moderate', [['step' => 'final'] + $warn], true, $t, $R)['step'] === 'final' && dc_next_step_for('gross', [], true, $t, $R)['step'] === 'showcause');
t('DC03 for a minor matter only recent cases count as repeats', dc_next_step_for('minor', [$warn], true, $t, $R)['step'] === 'memo' && dc_next_step_for('minor', [$memo], true, $t, $R)['step'] === 'warning'
    && str_contains(dc_next_step_for('moderate', [$memo], true, $t, $R)['why'], 'DC-2026-0001'));
$cs = [['id' => 1, 'severity' => 'minor', 'status' => 'closed', 'issued_at' => '2025-01-10 10:00:00'], ['id' => 2, 'severity' => 'moderate', 'status' => 'issued', 'issued_at' => '2025-09-01 10:00:00'],
       ['id' => 3, 'severity' => 'moderate', 'status' => 'withdrawn', 'issued_at' => '2026-01-01 10:00:00'], ['id' => 4, 'severity' => 'minor', 'status' => 'review_hr', 'issued_at' => null]];
$lap = dc_lapse_map($cs, DC_DEFAULTS['lapse']);
t('DC04 lapse counted from the latest case issued; withdrawn and not issued cases never count', $lap === [1 => '2026-03-01', 2 => '2026-09-01'] && dc_open_of($cs, '2026-02-01', DC_DEFAULTS['lapse']) !== [] && dc_open_of($cs, '2026-11-18', DC_DEFAULTS['lapse']) === []);
t('DC05 case steps by status', dc_case_problem('review_hr', 'hr_issue') === '' && dc_case_problem('issued', 'hr_issue') !== '' && dc_case_problem('review_appr', 'approve') === '' && dc_case_problem('returned', 'resend') === ''
    && dc_case_problem('issued', 'reply') === '' && dc_case_problem('closed', 'close') !== '' && dc_case_problem('closed', 'appeal') === '');
t('DC06 reply time and appeal window', dc_reply_late('2026-11-16 10:00:00', '2026-11-18 11:00:00', 48) && !dc_reply_late('2026-11-16 10:00:00', '2026-11-18 09:00:00', 48)
    && dc_appeal_open(['step' => 'warning', 'issued_at' => '2026-11-15 10:00:00', 'appeal_at' => null, 'status' => 'issued'], '2026-11-18 10:00:00', 7)
    && !dc_appeal_open(['step' => 'memo', 'issued_at' => '2026-11-15 10:00:00', 'appeal_at' => null, 'status' => 'issued'], '2026-11-18 10:00:00', 7)
    && !dc_appeal_open(['step' => 'final', 'issued_at' => '2026-11-01 10:00:00', 'appeal_at' => null, 'status' => 'issued'], '2026-11-18 10:00:00', 7)
    && !dc_appeal_open(['step' => 'final', 'issued_at' => '2026-11-15 10:00:00', 'appeal_at' => '2026-11-16', 'status' => 'issued'], '2026-11-18 10:00:00', 7));
$L = dc_letter(['step' => 'warning', 'severity' => 'moderate', 'category' => 'attendance', 'inc_date' => '2026-11-10', 'what' => 'TEST came late.', 'expected' => 'Be on time.', 'clauses' => json_encode([['no' => '3.1', 'title' => 'Timekeeping']])], DC_DEFAULTS, 'TEST – Ravi', '2026-05-01');
t('DC07 letter: what happened, warning wording, clause and acceptance date, expected, appeal line, signatory', str_contains($L['paras'][0], '10/11/2026') && str_contains($L['paras'][0], 'TEST came late.')
    && in_array(DC_DEFAULTS['letter']['warn'], $L['paras'], true) && str_contains(implode(' ', $L['paras']), '3.1 (Timekeeping)') && str_contains(implode(' ', $L['paras']), '01/05/2026')
    && str_contains(end($L['paras']), '7 days') && $L['sign'] === 'HR Manager' && $L['dear'] === 'Dear TEST – Ravi,'
    && dc_letter(['step' => 'showcause', 'severity' => 'serious', 'category' => 'safety', 'inc_date' => '2026-11-10', 'what' => 'x', 'expected' => '', 'clauses' => null], DC_DEFAULTS, 'x')['sign'] === 'Approved by the management');
t('DC08 evidence by its bytes; code version changes with an active clause only', dc_file_type('%PDF-1') === 'application/pdf' && dc_file_type("\xFF\xD8\xFF") === 'image/jpeg' && dc_file_type('<html>') === null
    && dc_code_version_of([]) === '' && dc_code_version_of([['no' => '1', 'title' => 'A', 'text' => 'x', 'active' => 1]]) !== dc_code_version_of([['no' => '1', 'title' => 'A', 'text' => 'y', 'active' => 1]])
    && dc_code_version_of([['no' => '1', 'title' => 'A', 'text' => 'x', 'active' => 1], ['no' => '2', 'title' => 'B', 'text' => 'z', 'active' => 0]]) === dc_code_version_of([['no' => '1', 'title' => 'A', 'text' => 'x', 'active' => 1]]));
[$s, $se] = dc_settings_check(['hr_roles' => ['HRMANAGER'], 'approve_roles' => [], 'judge_roles' => ['CEO'], 'reply_hours' => 1, 'letter' => ['warn' => 'Changed.', 'show' => 'No marker here.']], ['HRMANAGER' => 'h', 'CEO' => 'c']);
t('DC09 settings: roles checked, hours in range, letter markers kept', count($se) === 2 && $s['reply_hours'] === 12 && $s['letter']['warn'] === 'Changed.' && $s['letter']['show'] === DC_DEFAULTS['letter']['show']);
