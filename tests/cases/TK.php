<?php
// Task rules (lib/tasks.php) — synthetic data only.
require_once WN_ROOT . '/lib/tasks.php';
t('TK01 reminders: before the time, a date alone counts as the morning hour, morning of the day', tk_remind_when('15m', '2026-11-20', '10:00:00') === '2026-11-20 09:45:00'
    && tk_remind_when('1h', '2026-11-20', null, 9) === '2026-11-20 08:00:00' && tk_remind_when('am', '2026-11-20', '15:00:00', 8) === '2026-11-20 08:00:00' && tk_remind_when('', '2026-11-20', null) === null && tk_remind_when('at', null, null) === null);
t('TK02 repeats: daily, working days skip off days, weekly, never before today', tk_rep_next('daily', '2026-11-18', null, '2026-11-18') === '2026-11-19' && tk_rep_next('workday', '2026-11-21', null, '2026-11-21', [1, 2, 3, 4, 5]) === '2026-11-23'
    && tk_rep_next('weekly', '2026-10-01', null, '2026-11-18') === '2026-11-19');
t('TK03 monthly keeps the first day, cut to the month end; yearly', tk_rep_next('monthly', '2027-01-31', 31, '2027-01-31') === '2027-02-28' && tk_rep_next('monthly', '2027-02-28', 31, '2027-02-28') === '2027-03-31'
    && tk_rep_next('yearly', '2028-02-29', 29, '2028-02-29') === '2029-02-28');
$base = strtotime('2026-11-18 10:00:00');
t('TK04 reminders again till done: next step after now; hourly ones wait out the night', tk_nag_next('1h', $base, $base) === $base + 3600 && date('H:i', tk_nag_next('1h', strtotime('2026-11-18 20:30:00'), strtotime('2026-11-18 20:31:00'), 21, 9)) === '09:00'
    && date('Y-m-d', tk_nag_next('1h', strtotime('2026-11-18 20:30:00'), strtotime('2026-11-18 20:31:00'), 21, 9)) === '2026-11-19' && tk_nag_next('1d', $base, $base + 3 * 86400) === $base + 4 * 86400);
$f = tk_input(['title' => ' TEST <b>call</b> ', 'prio' => '1', 'due_date' => '2026-11-20', 'due_time' => '10:30', 'remind' => 'auto', 'rep' => 'monthly', 'nag' => '1d']);
t('TK05 a task from a form: tidy title, suggested reminder, repeat day kept', $f['title'] === 'TEST call' && $f['remind'] === '15m' && $f['remind_at'] === '2026-11-20 10:15:00' && $f['rep_day'] === 20 && $f['nag'] === '1d'
    && tk_input(['title' => 'x', 'due_date' => '2026-11-20', 'remind' => 'auto'])['remind'] === 'am' && tk_input(['title' => 'x', 'remind' => 'auto', 'nag' => '1h'])['nag'] === '');
$bad = function (array $in) { try { tk_input($in); return false; } catch (InvalidArgumentException $e) { return true; } };
t('TK06 bad tasks refused: no title, a time without a date, a reminder or repeat without a date, bad values', $bad([]) && $bad(['title' => 'x', 'due_time' => '10:00']) && $bad(['title' => 'x', 'remind' => '1h'])
    && $bad(['title' => 'x', 'rep' => 'weekly']) && $bad(['title' => 'x', 'prio' => '7']) && $bad(['title' => 'x', 'due_date' => '2026-02-30']));
$t = ['owner_id' => 1, 'assignee_id' => 2, 'removed_at' => null, 'done_at' => null, 'review' => '', 'due_date' => '2026-11-17'];
t('TK07 ticking: own task is done; a given task goes to the giver; the giver ticking closes it as approved', tk_tick(['assignee_id' => 1] + $t, 1) === ['done' => true, 'review' => '']
    && tk_tick($t, 2) === ['done' => false, 'review' => 'waiting'] && tk_tick($t, 1) === ['done' => true, 'review' => 'approved']);
t('TK08 states: late, today, waiting, done, removed', tk_state($t, '2026-11-18') === 'late' && tk_state(['due_date' => '2026-11-18'] + $t, '2026-11-18') === 'today' && tk_state(['review' => 'waiting'] + $t, '2026-11-18') === 'waiting'
    && tk_state(['done_at' => '2026-11-18'] + $t, '2026-11-18') === 'done' && tk_state(['removed_at' => '2026-11-18'] + $t, '2026-11-18') === 'removed');
$ics = tk_ics(['id' => 5, 'title' => 'TEST; one, two', 'notes' => '', 'due_date' => '2026-11-20', 'due_time' => '10:30:00'], 'UTC', 'example.com');
t('TK09 calendar file: start with time zone, escaped title, an alarm', str_contains($ics, 'DTSTART;TZID=UTC:20261120T103000') && str_contains($ics, 'SUMMARY:TEST\; one\, two') && str_contains($ics, 'BEGIN:VALARM') && str_ends_with($ics, "\r\n"));
[$o, $e] = tk_settings_check(['give' => 'team', 'lead_roles' => ['CEO', 'X'], 'morning' => '3', 'quiet_from' => '21', 'workdays' => ['1', '9']], ['CEO' => 'CEO']);
t('TK10 settings: who may be given to, lead roles, hours and working days checked', $o['give'] === 'team' && $o['lead_roles'] === ['CEO'] && $o['workdays'] === [1] && count($e) === 1);
