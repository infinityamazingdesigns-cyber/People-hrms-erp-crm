<?php
// Careers page rules (lib/careers.php) and the CV reader (lib/cv_parse.php) — synthetic data only.
require_once WN_ROOT . '/lib/careers.php';
$set = CAR_DEFAULTS; $today = '2026-11-18';
$r = ['id' => 4, 'title' => 'TEST – Planner', 'department' => 'Ops', 'branch_id' => 2, 'location' => '', 'emp_type' => 'permanent', 'exp_min' => 2, 'exp_max' => 5, 'qual_level' => 3, 'skills' => 'planning',
      'openings' => 2, 'jd' => 'Plan things', 'ctc_max' => 99999, 'weights' => '{}', 'approve_note' => 'secret note', 'raised_by' => 7];
$p = car_public($r, [2 => 'TEST – North'], false);
t('CA01 public details only: never pay, notes, raiser or weights; place from the branch; job description by setting', !array_intersect_key($p, array_flip(['ctc_max', 'weights', 'approve_note', 'raised_by']))
    && !str_contains(json_encode($p), '99999') && $p['place'] === 'TEST – North' && $p['exp'] === '2–5 years' && $p['jd'] === '' && car_public($r, [], true)['jd'] === 'Plan things'
    && car_public(['exp_min' => 0, 'exp_max' => null] + $r, [], false)['exp'] === 'Freshers welcome');
$ok = ['name' => 'TEST – Meera', 'phone' => '+1 000 000 0000', 'email' => 'meera@example.com', 'dob' => '1999-05-01', 'city' => 'TEST City', 'qualification' => 'Diploma', 'position' => '4', 'consent' => '1', 'total_exp' => '3', 'notice' => '30'];
[$c, $e] = car_check($ok, [4], $set, $today, 60);
t('CA02 a good application passes: source Website, consent kept, notice in days, position kept', $e === [] && $c['cand']['source'] === 'Website' && $c['cand']['consent'] === 1 && $c['cand']['notice_days'] === 30 && $c['position'] === 4 && $c['extra']['dob'] === '1999-05-01');
t('CA03 spam checks: hidden field, too fast, forged time', isset(car_check(['website' => 'x'] + $ok, [4], $set, $today, 60)[1]['_']) && isset(car_check($ok, [4], $set, $today, 2)[1]['_']) && isset(car_check($ok, [4], $set, $today, null)[1]['_']));
t('CA04 age: under the minimum refused (exact to the day), the day of the birthday passes', isset(car_check(['dob' => '2008-11-19'] + $ok, [4], $set, $today, 60)[1]['dob']) && !isset(car_check(['dob' => '2008-11-18'] + $ok, [4], $set, $today, 60)[1]['dob'])
    && !isset(car_check(['dob' => ''] + $ok, [4], ['ask_dob' => false] + $set, $today, 60)[1]['dob']));
$e2 = car_check(['name' => '', 'phone' => '12', 'email' => 'x', 'position' => '9', 'consent' => '', 'city' => '', 'qualification' => '', 'total_exp' => 'lots'] + $ok, [4], $set, $today, 60)[1];
t('CA05 every problem named on its field; a closed position and "no opening" when not allowed', isset($e2['name'], $e2['phone'], $e2['email'], $e2['position'], $e2['consent'], $e2['city'], $e2['qualification'], $e2['total_exp'])
    && isset(car_check(['position' => '0'] + $ok, [4], ['other' => false] + $set, $today, 60)[1]['position']) && !isset(car_check(['position' => '0'] + $ok, [4], $set, $today, 60)[1]['position']));
$k = 'TEST-key'; $st = car_stamp(1800000000, $k);
t('CA06 signed form time: age read back, a changed time or key refused', car_stamp_age($st, 1800000010, $k) === 10 && car_stamp_age('1800000005' . substr($st, 10), 1800000010, $k) === null && car_stamp_age($st, 1800000010, 'other') === null && car_stamp_age('junk', 1, $k) === null);
[$s, $se] = car_settings_check(['consent' => '', 'contact_email' => 'nope', 'rt_source' => ['TEST site', 'Bad'], 'rt_rating' => ['4.36', '9'], 'rt_count' => ['12', '1'], 'rt_url' => ['http://x', ''], 'cities' => 'A, B, A', 'min_age' => 99]);
t('CA07 settings: consent needed, email checked, ratings 1–5 with https links, cities without repeats', count($se) === 4 && $s['ratings'][0]['rating'] === 4.4 && $s['ratings'][0]['url'] === '' && count($s['ratings']) === 1 && $s['cities'] === 'A, B' && $s['min_age'] === 25);
t('CA08 ratings shown only when switched on and with reviews', car_ratings(['show_ratings' => false, 'ratings' => [['rating' => 4, 'count' => 3]]]) === [] && count(car_ratings(['show_ratings' => true, 'ratings' => [['rating' => 4, 'count' => 3], ['rating' => 4, 'count' => 0]]])) === 1);
t('CA09 notice text to days', car_notice_days('Immediate') === 0 && car_notice_days('2 months') === 60 && car_notice_days('3 weeks') === 21 && car_notice_days('45 days') === 45 && car_notice_days('') === null);
$cv = "TEST Ravi Kumar\nEmail: ravi.test@example.com | Mobile: +1 000 000 0001\nAddress: 12 Lane, TEST Town\nObjective\nTo grow.\nExperience\nPlanner at TEST Works Pvt Ltd (Jan 2019 - Present)\nEducation\nDiploma in Mechanical\nSkills\nPlanning, Excel, Scheduling\nNotice period: 30 days\nLanguages: English, Spanish";
$f = cvp_fields($cv, 2026 * 12 + 10, ['TEST Town']);
t('CV01 CV rules: name, email, phone, city from the given places, experience from dates, employer, title, qualification, skills, languages', $f['name'] === 'TEST Ravi Kumar' && $f['email'] === 'ravi.test@example.com'
    && $f['phone'] === '+10000000001' && $f['city'] === 'TEST Town' && $f['org'] !== '' && stripos($f['designation'], 'planner') !== false && $f['qualification'] === 'Diploma'
    && str_contains($f['skills'], 'Excel') && $f['languages'] === 'English, Spanish' && $f['totalExp'] !== '' && cvp_fields($cv, 2026 * 12 + 10)['city'] === '');
$stream = "BT /F1 12 Tf 72 720 Td (TEST Asha Rao) Tj 0 -14 Td (asha.test@example.com) Tj 0 -14 Td (Design engineer with 6 years of experience in planning and drawings.) Tj ET";
$pdf = "%PDF-1.4\n1 0 obj<</Type/Page/Contents 2 0 R/Resources<<>>>>endobj\n2 0 obj<</Length " . strlen($stream) . ">>stream\n$stream\nendstream endobj\ntrailer<<>>\n%%EOF";
$t = cvp_text($pdf);
t('CV02 a simple PDF is read on this server; a picture or junk gives no text', str_contains($t['text'], 'asha.test@example.com') && cvp_text("\xFF\xD8\xFFjunk")['text'] === '' && cvp_kind('hello') === '');
$rc = car_read_cv($pdf, $set);
t('CV03 suggestions for the form (only filled into empty boxes on the page)', $rc['ok'] && $rc['fields']['email'] === 'asha.test@example.com' && $rc['fields']['total_exp'] === '6');
