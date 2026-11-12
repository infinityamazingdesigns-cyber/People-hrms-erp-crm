<?php
// Onboarding and probation rules (lib/onboarding.php, lib/probation.php) — synthetic data only.
require_once WN_ROOT . '/lib/onboarding.php';
require_once WN_ROOT . '/lib/attendance.php';
require_once WN_ROOT . '/lib/probation.php';
$brs = [['id' => 1, 'name' => 'TEST North'], ['id' => 2, 'name' => 'TEST South']];
$ok = function (array $in) use ($brs) { try { return onb_check($in, $brs); } catch (InvalidArgumentException $e) { return null; } };
$j = $ok(['name' => 'TEST – Neha Joiner', 'emp_code' => 'tst900', 'join_date' => '01/12/2026', 'branch_id' => '2', 'email' => 'NEHA@example.com']);
t('ON01 joiner form: code upper-cased, dates read, email lower-cased', $j['emp_code'] === 'TST900' && $j['join_date'] === '2026-12-01' && $j['email'] === 'neha@example.com' && $j['branch_id'] === 2);
t('ON02 joiner form refuses a missing name, an unknown branch, a bad date, a password-like code',
    $ok(['name' => '']) === null && $ok(['name' => 'x', 'branch_id' => 9]) === null && $ok(['name' => 'x', 'join_date' => '31/02/2026']) === null && $ok(['name' => 'x', 'emp_code' => 'blue-sky-river']) === null);
$items = [['id' => 1, 'phase' => 'pre', 'active' => 1], ['id' => 2, 'phase' => 'pre', 'active' => 1], ['id' => 3, 'phase' => 'day1', 'active' => 1], ['id' => 4, 'phase' => 'day1', 'active' => 0]];
$p = onb_progress($items, [1 => 1, 4 => 1]);
t('ON03 progress counts live items only', $p['done'] === 1 && $p['of'] === 3 && $p['pct'] === 33 && $p['phases']['pre'] === [1, 2]);
t('ON04 converting links an existing record only for the same person', onb_same_person(['emp_code' => 'TST900', 'name' => 'TEST Neha Joiner'], ['code' => 'tst900', 'name' => 'TEST Neha K Joiner'])
    && !onb_same_person(['emp_code' => 'TST900', 'name' => 'TEST Neha Joiner'], ['code' => 'TST900', 'name' => 'TEST Arjun Other']));
t('PB01 probation end: confirmation date wins, else joining + months', pr_end(['doj' => '2026-08-31', 'confirm_date' => null], 3) === '2026-12-01' && pr_end(['doj' => '2026-08-01', 'confirm_date' => '2027-01-15'], 3) === '2027-01-15');
t('PB02 check-ins open N days early', pr_open_by('2026-11-20', 7, '2026-11-13') && !pr_open_by('2026-11-20', 7, '2026-11-12') && pr_checkin_due(['doj' => '2026-10-12'], 1) === '2026-11-12');
$areas = ['TEST Goals', 'TEST Teamwork']; $tr = ['Safety', 'Tools'];
$rc = function (array $in) use ($areas, $tr) { try { return pr_review_check($in, $areas, $tr); } catch (InvalidArgumentException $e) { return null; } };
$good = ['rate' => [4, 5], 'reco' => 'confirm', 'training' => ['Safety', 'Made up']];
$r = $rc($good);
t('PB03 review: every area rated, unknown training dropped, average', $r && json_decode($r['training'], true) === ['Safety'] && pr_avg($r['ratings']) === 4.5 && $rc(['rate' => [4]] + $good) === null);
t('PB04 review: a reason unless confirm; salary % 0–100; role change needs words',
    $rc(['reco' => 'extend'] + $good) === null && $rc(['reco' => 'extend', 'comment' => 'TEST more time'] + $good) !== null
    && $rc(['salary_rev' => 1, 'salary_pct' => '150'] + $good) === null && $rc(['role_change' => 1] + $good) === null);
$rev = ['due_date' => '2026-12-01', 'reco' => 'confirm'];
$dc = function (array $in) use ($rev) { try { return pr_decision_check($in, $rev, '2026-09-01'); } catch (InvalidArgumentException $e) { return null; } };
t('PB05 decision: dates needed, extension after the old end, a note when it differs',
    $dc(['decision' => 'confirm', 'date' => '2026-12-01']) === ['confirm', '2026-12-01', ''] && $dc(['decision' => 'confirm']) === null
    && $dc(['decision' => 'extend', 'date' => '2026-11-30', 'note' => 'x']) === null && $dc(['decision' => 'extend', 'date' => '2027-01-01']) === null
    && $dc(['decision' => 'not_confirm', 'note' => 'TEST reason'])[0] === 'not_confirm' && $dc(['decision' => 'confirm', 'date' => '2026-08-01']) === null);
[$s, $e] = pr_settings_check(['months' => 6, 'open_days' => 15, 'check_early' => 7, 'checkins' => '4, 2, 2', 'ratings' => "A\nB\n\nA", 'training' => '']);
[, $e2] = pr_settings_check(['months' => 3, 'open_days' => 15, 'check_early' => 7, 'checkins' => '1,3', 'ratings' => 'A']);
t('PB06 settings: check-ins sorted and before the end, lists tidied', !$e && $s['checkins'] === [2, 4] && $s['ratings'] === ['A', 'B'] && $e2);
t('PB07 reviews open by themselves only near the end, never for long-overdue staff', pr_auto_due('2026-11-20', 15, '2026-11-12') && !pr_auto_due('2026-12-20', 15, '2026-11-12')
    && pr_auto_due('2026-10-20', 15, '2026-11-12') && !pr_auto_due('2025-09-01', 15, '2026-11-12'));
