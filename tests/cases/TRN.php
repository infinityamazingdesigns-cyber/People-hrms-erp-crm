<?php
// Training register rules (lib/training.php) — synthetic data only.
require_once WN_ROOT . '/lib/training.php';
t('TRN01 year labels and ranges by the year\'s first month', tr_fy('2026-11-19', 4) === '2026-27' && tr_fy('2027-02-01', 4) === '2026-27' && tr_fy('2026-03-31', 4) === '2025-26' && tr_fy('2026-06-01', 1) === '2026'
    && tr_fy_range('2026-27', 4) === ['2026-04-01', '2027-03-31'] && tr_fy_range('2026', 1) === ['2026-01-01', '2026-12-31']);
$bad = function (callable $f): bool { try { $f(); return false; } catch (InvalidArgumentException $e) { return true; } };
$r = tr_check(['title' => ' TEST – Forklift ', 'kind' => 'Classroom', 'start_on' => '2026-11-10', 'hours' => '6', 'link' => 'https://example.com/c', 'skills' => 'Lifting, Safety'], 4, '2026-11-19');
t('TRN02 a training cleaned: end defaults to start, year from the start', $r['title'] === 'TEST – Forklift' && $r['end_on'] === '2026-11-10' && $r['fy'] === '2026-27' && $r['hours'] === 6.0 && $r['status'] === 'completed');
t('TRN03 problems refused: no name, unknown kind, no start, ends first, completed in the future, plain-http link', $bad(fn() => tr_check(['kind' => 'Classroom', 'start_on' => '2026-11-10'], 4, '2026-11-19'))
    && $bad(fn() => tr_check(['title' => 'x', 'kind' => 'Nope', 'start_on' => '2026-11-10'], 4, '2026-11-19')) && $bad(fn() => tr_check(['title' => 'x', 'kind' => 'Classroom'], 4, '2026-11-19'))
    && $bad(fn() => tr_check(['title' => 'x', 'kind' => 'Classroom', 'start_on' => '2026-11-10', 'end_on' => '2026-11-01'], 4, '2026-11-19'))
    && $bad(fn() => tr_check(['title' => 'x', 'kind' => 'Classroom', 'start_on' => '2026-11-18', 'end_on' => '2026-11-25'], 4, '2026-11-19'))
    && !$bad(fn() => tr_check(['title' => 'x', 'kind' => 'Classroom', 'status' => 'enrolled', 'start_on' => '2026-11-18', 'end_on' => '2026-11-25'], 4, '2026-11-19'))
    && $bad(fn() => tr_check(['title' => 'x', 'kind' => 'Classroom', 'start_on' => '2026-11-10', 'link' => 'http://example.com'], 4, '2026-11-19')));
t('TRN04 hours counted: hours, else days × day hours, else the days it ran', tr_hours(['hours' => 3, 'days' => 2], 8) === 3.0 && tr_hours(['hours' => null, 'days' => 2], 8) === 16.0
    && tr_hours(['hours' => null, 'days' => null, 'start_on' => '2026-11-10', 'end_on' => '2026-11-12'], 8) === 24.0 && tr_hours(['hours' => null, 'days' => null], 8) === 0.0);
t('TRN05 request steps: manager at the manager step, HR at either, the asker withdraws while open', tr_req_problem('manager', 'decide', 'manager') === '' && tr_req_problem('hr', 'decide', 'manager') !== ''
    && tr_req_problem('manager', 'decide', 'hr') === '' && tr_req_problem('approved', 'decide', 'hr') !== '' && tr_req_problem('hr', 'withdraw', 'self') === '' && tr_req_problem('declined', 'withdraw', 'self') !== '');
t('TRN06 feedback window after a completed training', tr_feedback_open(['status' => 'completed', 'end_on' => '2026-11-01'], '2026-11-19', 90) && !tr_feedback_open(['status' => 'completed', 'end_on' => '2026-05-01'], '2026-11-19', 90)
    && !tr_feedback_open(['status' => 'enrolled', 'end_on' => '2026-11-01'], '2026-11-19', 90) && !tr_feedback_open(['status' => 'completed', 'end_on' => '2026-11-25'], '2026-11-19', 90));
$rows = [['emp_code' => 'TST1', 'name' => 'TEST A', 'kind' => 'Classroom', 'hours' => 4, 'days' => null, 'skills' => 'Safety, Lifting', 'files' => 1],
         ['emp_code' => 'TST1', 'name' => 'TEST A', 'kind' => 'Online course', 'hours' => null, 'days' => 1, 'skills' => 'safety', 'files' => 0],
         ['emp_code' => 'TST2', 'name' => 'TEST B', 'kind' => 'Classroom', 'hours' => 2, 'days' => null, 'skills' => '', 'files' => 0]];
$rp = tr_report($rows, 8);
t('TRN07 year report: counts, hours, people (most hours first), kinds, skills case-insensitive, certificates', $rp['count'] === 3 && $rp['hours'] === 14.0 && array_key_first($rp['people']) === 'TST1'
    && $rp['people']['TST1']['hours'] === 12.0 && $rp['kind']['Classroom'] === 2 && $rp['skills']['safety'] === 2 && $rp['certs'] === 1);
t('TRN08 files by their bytes; CSV cells neutralised', tr_file_type('%PDF-1') === 'application/pdf' && tr_file_type("\x89PNG\r\n\x1a\nx") === 'image/png' && tr_file_type('MZ') === null && tr_csv_cell('=1+1') === "\"'=1+1\"");
[$s, $se] = tr_settings_check(['hr_roles' => [], 'day_hours' => 30], ['HRMANAGER' => 'h']);
t('TRN09 settings: an HR role needed, day hours in range', count($se) === 1 && $s['day_hours'] === 12 && !$s['show_fee']);
