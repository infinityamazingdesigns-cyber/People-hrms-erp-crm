<?php
// My profile rules (lib/profile.php) — synthetic data only.
require_once WN_ROOT . '/lib/profile.php';
$base = array_fill_keys(array_keys(PF_FIELDS), '');
$in = array_merge($base, ['dob' => '1990-05-04', 'gender' => 'Female', 'marital' => 'Single', 'anniversary' => '2015-01-01', 'spouse_name' => 'TEST Spouse',
    'mobile' => '+1 (555) 010-2030', 'personal_email' => 'Me@Example.org', 'cur_pin' => 'ab1 2cd', 'exp_years' => '2,5', 'pass_year' => '2012']);
[$c, $e] = pf_clean($in, 'example.com', '2026-11-10');
t('PF01 clean: phone digits, email lower-case, postal code, years', !$e && $c['mobile'] === '+15550102030' && $c['personal_email'] === 'me@example.org' && $c['cur_pin'] === 'AB12CD' && $c['exp_years'] === '2.5');
t('PF02 anniversary and spouse dropped when not married', $c['anniversary'] === '' && $c['spouse_name'] === '');
[, $e2] = pf_clean(array_merge($base, ['personal_email' => 'me@example.com']), 'example.com', '2026-11-10');
t('PF03 personal email on the work domain refused', count($e2) === 1);
[, $e3] = pf_clean(array_merge($base, ['dob' => '2030-01-01', 'gender' => 'Robot', 'mobile' => '123']), '', '2026-11-10');
t('PF04 future date, unknown choice and short phone refused', count($e3) === 3);
[, $e4] = pf_clean(array_merge($base, ['mobile' => '5550102030', 'em_phone' => '5550102030']), '', '2026-11-10');
t('PF05 emergency contact cannot be your own number', count($e4) === 1);
t('PF06 diff lists only changed fields', pf_diff(['mobile' => '1', 'blood' => 'O+'], ['mobile' => '2', 'blood' => 'O+']) === ['mobile' => ['1', '2']]);
[$after, $acc, $dec, $st] = pf_apply(['blood' => 'A+'], ['blood' => ['A+', 'B+'], 'mobile' => ['', '5550102030']], ['blood']);
t('PF07 HR accepts field by field', $after['blood'] === 'B+' && !isset($after['mobile']) && $acc === ['blood'] && $dec === ['mobile'] && $st === 'partly');
t('PF08 nothing accepted = sent back', pf_apply([], ['blood' => ['', 'O+']], [])[3] === 'rejected');
t('PF09 completeness counts spouse only when married', pf_completeness(['marital' => 'Married'])[1] === pf_completeness(['marital' => 'Single'])[1] + 1);
