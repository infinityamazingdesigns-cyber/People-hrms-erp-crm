<?php
// Recruitment rules (lib/recruitment.php) — synthetic data only.
require_once WN_ROOT . '/lib/recruitment.php';
$set = RC_DEFAULTS;
t('RQ01 rights: pipeline, approvers, raisers (managers raise only)', rc_rights_for(['HRRECRUIT'], $set) === ['manage' => true, 'approve' => false, 'raise' => true]
    && rc_rights_for(['MANAGER'], $set) === ['manage' => false, 'approve' => false, 'raise' => true] && rc_rights_for(['CEO'], $set)['approve'] && !rc_rights_for(['EMPLOYEE'], $set)['raise']);
t('RQ02 mobile key = last 10 digits; same person by mobile or email, not by name', rc_phone_key('+91 00000 12345') === '0000012345'
    && rc_same_person(['phone_key' => '0000012345', 'email' => ''], [['id' => 3, 'phone_key' => '0000012345', 'email' => 'x@example.com']])['id'] === 3
    && rc_same_person(['phone_key' => '', 'email' => 'A@Example.com'], [['id' => 4, 'phone_key' => '', 'email' => 'a@example.com']])['id'] === 4
    && rc_same_person(['phone_key' => '12345', 'email' => ''], [['id' => 5, 'phone_key' => '12345', 'email' => '']]) === null
    && rc_same_person(['phone_key' => '0000012345', 'email' => ''], [['id' => 3, 'phone_key' => '0000012345', 'email' => '']], 3) === null);
$bad = function (callable $f): bool { try { $f(); return false; } catch (InvalidArgumentException $e) { return true; } };
$c = rc_cand_check(['name' => ' TEST –  Asha ', 'email' => 'ASHA@example.com', 'total_exp' => '6', 'cur_ctc' => '50000', 'source' => 'Nope'], false);
t('RQ03 candidate cleaned: needs a mobile or email; pay kept only with pay rights; unknown source → Other', $c['name'] === 'TEST – Asha' && $c['email'] === 'asha@example.com' && $c['total_exp'] === 6.0
    && !array_key_exists('cur_ctc', $c) && $c['source'] === 'Other' && rc_cand_check(['name' => 'x', 'phone' => '0000012345', 'exp_ctc' => '1,000'], true)['exp_ctc'] === 1000.0
    && $bad(fn() => rc_cand_check(['name' => 'x'], false)) && $bad(fn() => rc_cand_check(['name' => 'x', 'email' => 'nope'], false)) && $bad(fn() => rc_cand_check(['name' => 'x', 'phone' => '1', 'total_exp' => '-2'], false)));
$r = rc_req_check(['title' => 'TEST – Engineer', 'branch_id' => '2', 'exp_min' => '3', 'exp_max' => '6', 'w_skills' => '50', 'ctc_max' => '90000', '_form' => 1], [1, 2], false);
t('RQ04 requisition cleaned: branch from the list, experience range, weights, titles default, pay only with rights', $r['branch_id'] === 2 && $r['titles'] === 'TEST – Engineer'
    && json_decode($r['weights'], true)['skills'] === 50 && json_decode($r['weights'], true)['exp'] === 25 && !isset($r['ctc_max']) && $r['missing_zero'] === 0
    && $bad(fn() => rc_req_check(['title' => 'x', 'branch_id' => 9], [1], false)) && $bad(fn() => rc_req_check(['title' => 'x', 'exp_min' => 5, 'exp_max' => 2], [], false)) && $bad(fn() => rc_req_check([], [], false)));
t('RQ05 stage moves: reasons for reject / hold / reopen, joined only from accepted, nothing after joined', rc_move_problem('new', 'screening', '') === '' && rc_move_problem('new', 'rejected', '') !== ''
    && rc_move_problem('rejected', 'screening', '') !== '' && rc_move_problem('rejected', 'screening', 'again') === '' && rc_move_problem('interview', 'joined', '') !== ''
    && rc_move_problem('accepted', 'joined', '') === '' && rc_move_problem('joined', 'new', 'x') !== '' && rc_move_problem('shortlisted', 'accepted', '') !== '' && rc_move_problem('new', 'new', '') !== '');
t('RQ06 text helpers: tokens drop filler, stem, synonyms; education levels; years in a text', rc_tokens('Senior Mechanical Engg with 5 years experience') === ['senior', 'mechanical', 'engineer']
    && rc_edu_levels('B.Tech Mechanical, Diploma') === [4, 3] && rc_edu_levels('MBA finance')[0] === 5 && rc_years_in('I have 11+ years of experience') === 11.0
    && rc_years_in('experience: about 7 yrs') === 7.0 && rc_years_in('born 1990') === null && rc_disciplines('B.Com, accounts') === ['account', 'commerce']);
$req = ['title' => 'Design Engineer', 'titles' => 'Design Engineer', 'skills' => 'autocad, solidworks, sheet metal', 'exp_min' => 3, 'exp_max' => 6, 'qual_level' => 4, 'disciplines' => 'mechanical',
        'location' => 'TEST City', 'ctc_max' => 60000, 'notice_max' => 30, 'hire_by' => null, 'jd' => '', 'weights' => '', 'missing_zero' => 1];
$good = ['skills' => 'AutoCAD, SolidWorks, sheet metal design', 'cur_title' => 'Design Engineer', 'qualification' => 'B.E. Mechanical', 'notes' => '', 'cv_text' => '',
         'total_exp' => 5, 'rel_exp' => 4, 'city' => 'TEST City', 'relocate' => '', 'exp_ctc' => 55000, 'notice_days' => 15];
$g = rc_ats($good, $req, '2026-11-18');
t('RQ07 ATS: a good fit scores high with every check on', $g['pct'] === 100 && $g['stars'] === 5.0 && count(array_filter($g['rows'], fn($r) => $r['on'])) === 7 && $g['missing'] === [] && count($g['hit']) === 3);
$empty = ['skills' => '', 'cur_title' => '', 'qualification' => '', 'notes' => '', 'cv_text' => '', 'total_exp' => null, 'rel_exp' => null, 'city' => '', 'relocate' => '', 'exp_ctc' => null, 'notice_days' => 15];
$e = rc_ats($empty, $req, '2026-11-18');
t('RQ08 ATS: a nearly empty application does not score high (missing = 0), and says what is missing', $e['pct'] !== null && $e['pct'] <= 10 && count($e['missing']) === 4
    && rc_ats($empty, ['missing_zero' => 0] + $req, '2026-11-18')['pct'] === 100);
$p = rc_ats(['exp_ctc' => 80000, 'total_exp' => 1.5, 'rel_exp' => null, 'city' => 'Elsewhere', 'relocate' => 'yes', 'notice_days' => 90, 'qualification' => 'Diploma Mechanical'] + $good, $req, '2026-11-18');
$v = array_column($p['rows'], 'v', 'k');
t('RQ09 ATS parts: short experience, qualification one level below, pay above range, relocation, long notice', abs($v['exp'] - 0.425) < 0.001 && $v['qual'] === 0.6 && $v['sal'] === 0.0 && $v['loc'] === 0.9 && abs($v['notice'] - 1 / 3) < 0.001);
$cv = rc_ats(['skills' => '', 'cur_title' => '', 'qualification' => '', 'total_exp' => null, 'rel_exp' => null, 'cv_text' => "TEST – Ravi\nDesign Engineer\nB.Tech Mechanical. 8 years of experience in AutoCAD and SolidWorks."] + $good, $req, '2026-11-18');
t('RQ10 ATS reads the CV text when the form is blank', $cv['missing'] === [] && str_contains(array_column($cv['rows'], 'd', 'k')['exp'], 'from the CV') && in_array('autocad', array_map('strtolower', $cv['hit']), true));
t('RQ11 stars, references, CV types by bytes', rc_stars(3.5) === '★★★½☆' && rc_stars(null) === '—' && rc_next_ref('RQ', '2026', 'RQ-2026-0009') === 'RQ-2026-0010' && rc_next_ref('RQ', '2026', null) === 'RQ-2026-0001'
    && rc_cv_type('%PDF-1.4', 'a') === 'application/pdf' && rc_cv_type("PK\x03\x04", 'a.zip') === null && rc_cv_type("MZ\x00", 'a.txt') === null && rc_cv_type('hello', 'a.txt') === 'text/plain');
[$s, $se] = rc_settings_check(['manage_roles' => ['HRRECRUIT'], 'approve_roles' => [], 'raise_roles' => ['MANAGER'], 'ref_prefix' => 'hr-9x!'], ['HRRECRUIT' => 'a', 'MANAGER' => 'b']);
t('RQ12 settings: roles checked, prefix letters only', $s['manage_roles'] === ['HRRECRUIT'] && count($se) === 1 && $s['ref_prefix'] === 'HRX' && !$s['approval']);
