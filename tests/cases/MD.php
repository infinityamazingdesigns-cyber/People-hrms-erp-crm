<?php
// My day rules (lib/myday.php) — synthetic data only.
require_once WN_ROOT . '/lib/myday.php';
$a = md_stage('att', ['status' => 'hr']); $l = md_stage('leave', ['status' => 'approved', 'wd_status' => 'manager']); $c = md_stage('claim', ['status' => 'returned']);
t('MD01 where a request stands: step, open / done / stopped, a plain line', $a['at'] === 2 && $a['state'] === 'open' && $a['line'] === 'With HR' && md_stage('att', ['status' => 'rejected'])['state'] === 'stopped'
    && $l['state'] === 'open' && str_contains($l['line'], 'withdraw') && $c['at'] === 1 && str_contains($c['line'], 'Returned') && md_stage('claim', ['status' => 'paid'])['state'] === 'done'
    && md_stage('letter', ['status' => 'done'])['state'] === 'done' && md_stage('profile', ['status' => 'partly'])['state'] === 'done' && md_stage('asset', ['status' => 'closed'])['state'] === 'done');
$it = fn($at, $st) => ['at' => $at, 'stage' => ['state' => $st]];
$s = md_sort([$it('2026-11-18 10:00', 'done'), $it('2026-11-02 10:00', 'open'), $it('2026-10-01 10:00', 'done'), $it('2026-11-10 10:00', 'open'), $it('2026-11-15 10:00', 'stopped')], '2026-11-19', 30, 4);
t('MD02 tracker: open oldest first, then closed of the last 30 days newest first, capped', array_column($s, 'at') === ['2026-11-02 10:00', '2026-11-10 10:00', '2026-11-18 10:00', '2026-11-15 10:00']);
$d = md_today([['type' => 'OUT', 'time' => '13:05:00'], ['type' => 'IN', 'time' => '09:31:10'], ['type' => 'IN', 'time' => '14:00:00']]);
t('MD03 today: first in, out only when the last punch is out, next action', $d === ['in' => '09:31', 'out' => null, 'n' => 3, 'next' => 'out'] && md_today([])['next'] === 'in'
    && md_today([['type' => 'IN', 'time' => '09:00:00'], ['type' => 'OUT', 'time' => '25:10:00']])['out'] === '25:10');
t('MD04 greeting by the hour', md_greeting(8) === 'Good morning' && md_greeting(13) === 'Good afternoon' && md_greeting(20) === 'Good evening');
$src = (string)file_get_contents(WN_ROOT . '/lib/myday.php') . file_get_contents(WN_ROOT . '/modules/myday/index.php');
t('MD05 own data only, read only, never a money figure: no request parameter, no writes, no amounts', !preg_match('/\$_(GET|POST|REQUEST)\b/', $src) && !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $src)
    && !preg_match('/\bamount\b|approved_amount|pay_|ctc/i', $src) && str_contains($src, "module_require_self('myday')"));
