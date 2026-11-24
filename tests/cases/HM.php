<?php
/* Home page (lib/home.php) */
require_once WN_ROOT . '/lib/home.php';
$d = '2026-11-20';
t('HM01 urgent reasons', hm_task_why(['due_date' => '2026-11-19'], $d) === 'late' && hm_task_why(['due_date' => $d], $d) === 'today'
    && hm_task_why(['due_date' => '2026-11-21'], $d) === 'tomorrow' && hm_task_why(['due_date' => '2026-11-30', 'prio' => 1], $d) === 'high'
    && hm_task_why(['due_date' => '2026-11-30', 'prio' => 2], $d) === '' && hm_task_why(['prio' => 3], $d) === '');
$T = [
    ['id' => 1, 'title' => 'TEST – a', 'prio' => 1, 'due_date' => null],
    ['id' => 2, 'title' => 'TEST – b', 'prio' => 2, 'due_date' => '2026-11-21'],
    ['id' => 3, 'title' => 'TEST – c', 'prio' => 3, 'due_date' => $d, 'due_time' => '15:00:00'],
    ['id' => 4, 'title' => 'TEST – d', 'prio' => 2, 'due_date' => $d, 'due_time' => '09:00:00'],
    ['id' => 5, 'title' => 'TEST – e', 'prio' => 2, 'due_date' => '2026-11-01'],
    ['id' => 6, 'title' => 'TEST – f', 'prio' => 2, 'due_date' => '2026-12-31'],
    ['id' => 7, 'title' => 'TEST – g', 'prio' => 1, 'due_date' => '2026-11-02', 'review' => 'waiting'],
    ['id' => 8, 'title' => 'TEST – h', 'prio' => 1, 'due_date' => '2026-11-02', 'done_at' => '2026-11-03'],
];
$p = array_column(hm_task_pick($T, $d), 'id');
t('HM02 late, then today (priority, time), tomorrow, high; not urgent / waiting / done left out', $p === [5, 4, 3, 2, 1]);
t('HM03 at most ten tasks', count(hm_task_pick(array_map(fn($i) => ['id' => $i, 'prio' => 1, 'due_date' => '2026-01-01'], range(1, 30)), $d)) === HM_TASKS_SHOW);
t('HM04 waiting lines: empty dropped, biggest first', hm_waiting_clean([['A', 0, 'x'], ['B', 2, 'y'], ['C', 5, 'z'], ['D', 2, 'w']]) === [['C', 5, 'z'], ['B', 2, 'y'], ['D', 2, 'w']]);
$src = file_get_contents(WN_ROOT . '/lib/home.php');
t('HM05 every waiting count sits behind module_allowed()', str_contains($src, "if (!module_allowed(\$u, \$key)) return;") && substr_count($src, '$try(') === 14);
t('HM06 HR desks also check the module\'s own HR rule', str_contains($src, 'pf_is_hr($u) ?') && str_contains($src, 'kyc_is_hr($u) ?'));
$tk = file_get_contents(WN_ROOT . '/modules/tasks/index.php');
t('HM07 the home tick goes through Tasks\' own done rule and comes back home', str_contains($tk, "(\$_POST['back'] ?? '') === 'home' && \$a === 'done'") && str_contains(file_get_contents(WN_ROOT . '/index.php'), 'action="modules/tasks/index.php"'));
