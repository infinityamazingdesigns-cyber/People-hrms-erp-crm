<?php
// Announcement board rules (lib/board.php) — synthetic data only.
require_once WN_ROOT . '/lib/board.php';
$set = BOARD_DEFAULTS;
t('BD01 rights: posters, moderators (who can always post), employees read', board_rights_for(['CEO'], $set) === ['post' => true, 'moderate' => false]
    && board_rights_for(['EMPLOYEE'], $set) === ['post' => false, 'moderate' => false] && board_rights_for(['HRMANAGER'], ['post_roles' => ['CEO'], 'moderate_roles' => ['HRMANAGER']])['post']);
t('BD02 files by their own bytes; documents refused in a pictures post', board_file_type("\xFF\xD8\xFFx", 'a.exe') === ['image/jpeg', true] && board_file_type('%PDF-1', 'a') === ['application/pdf', false]
    && board_file_type("PK\x03\x04", 'a.zip') === null && board_file_type('<html>', 'a.html') === null
    && board_post_check(['kind' => 'photos', 'title' => 'TEST – Day'], [['%PDF-1', 'a.pdf']])[1] !== '' && board_post_check(['kind' => 'photos', 'title' => 'TEST – Day'], [])[1] !== '');
[$p, $e] = board_post_check(['kind' => 'news', 'title' => "  TEST –   Notice ", 'body' => "a\r\nb", 'pinned' => '1'], [["\x89PNG\r\n\x1a\nx", 'p.png']]);
t('BD03 post cleaned: title squeezed, body kept, pin only on announcements', $e === '' && $p['title'] === 'TEST – Notice' && $p['body'] === "a\nb" && $p['pinned'] && count($p['files']) === 1
    && board_post_check(['kind' => 'news', 'title' => ''], [])[1] !== '' && !board_post_check(['kind' => 'photos', 'title' => 'x', 'pinned' => 1], [["\xFF\xD8\xFF", 'a.jpg']])[0]['pinned']);
t('BD04 days until a birthday; 29 Feb on 28 Feb in other years; next year when passed', board_days_until('11-18', '2026-11-18') === 0 && board_days_until('11-20', '2026-11-18') === 2
    && board_days_until('02-29', '2027-02-27') === 1 && board_days_until('11-17', '2026-11-18') === 364);
$people = [['name' => 'TEST – A', 'dept' => 'D', 'title' => 'T', 'branch' => 'B', 'dob' => '1990-11-20', 'anniversary' => '2015-11-18'],
           ['name' => 'TEST – B', 'dob' => '1985-12-30', 'anniversary' => null], ['name' => 'TEST – C', 'dob' => null]];
$c = board_celebrations($people, '2026-11-18', 7, true);
t('BD05 celebrations: day-month only, soonest first, no years or ages; anniversaries only when on', count($c) === 2 && $c[0]['kind'] === 'anniversary' && $c[1]['md'] === '11-20'
    && !array_filter($c, fn($x) => isset($x['dob']) || isset($x['year']) || str_contains(json_encode($x), '1990')) && count(board_celebrations($people, '2026-11-18', 7, false)) === 1);
[$ch, $ce] = board_challenge_check(['title' => 'TEST – Walk', 'from_day' => '2026-11-18', 'to_day' => '2026-11-10', 'branches' => ['2', '9'], 'depts' => ['Ops', 'Nope'], 'people' => 'tst001; bad*code; TST002'], [1, 2], ['Ops'], '2026-11-18');
t('BD06 challenge cleaned: end not before start, unknown branches / departments dropped, codes checked', $ce === '' && $ch['to_day'] === '2026-11-18' && $ch['branches'] === [2] && $ch['depts'] === ['Ops']
    && $ch['people'] === ['TST001', 'TST002'] && board_challenge_check(['title' => 'x', 'from_day' => '2026-11-01'], [], [], '2026-11-18')[1] !== '');
$all = ['aud_branches' => '', 'aud_depts' => '', 'aud_people' => ''];
$some = ['aud_branches' => '2', 'aud_depts' => 'Ops', 'aud_people' => 'TST009'];
t('BD07 audience: everyone, a branch, a department (any case), a person; others not', board_challenge_for($all, ['code' => '', 'branch_id' => null, 'dept' => ''])
    && board_challenge_for($some, ['code' => '', 'branch_id' => 2, 'dept' => '']) && board_challenge_for($some, ['code' => 'tst003', 'branch_id' => 1, 'dept' => 'ops'])
    && board_challenge_for($some, ['code' => 'TST009', 'branch_id' => null, 'dept' => '']) && !board_challenge_for($some, ['code' => 'TST003', 'branch_id' => 1, 'dept' => 'Sales']));
t('BD08 who-for label never shows codes to employees', board_challenge_label($some, false, [2 => 'TEST – North']) === 'TEST – North · Ops · chosen people'
    && str_contains(board_challenge_label($some, true), 'TST009') && board_challenge_label($all, false) === 'Everyone');
[$s, $se] = board_settings_check(['post_roles' => ['CEO', 'NOPE'], 'moderate_roles' => [], 'days_ahead' => 90, 'keep_days' => 1], ['CEO' => 'CEO', 'SUPERADMIN' => 'SA']);
t('BD09 settings: roles checked, numbers kept in range', $s['post_roles'] === ['CEO'] && count($se) === 1 && $s['days_ahead'] === 31 && $s['keep_days'] === 14 && !$s['leave']);
