<?php
// Letters rules (lib/letters.php) — synthetic data only.
require_once WN_ROOT . '/lib/letters.php';
$body = "Dear {{name}},\n\nYou join as {{ designation }} on {{doj}}.\n- Probation {{probation_months}} months\n- Code {{code}}\n\n**Welcome** {{name}}";
t('LT01 fields found once, in order', let_fields($body) === ['name', 'designation', 'doj', 'probation_months', 'code']);
t('LT02 missing fields', let_missing($body, ['name' => 'TEST A', 'designation' => ' ', 'doj' => 'x', 'code' => 'T1']) === ['designation', 'probation_months']);
$html = let_render($body, ['name' => 'TEST <b>A</b>', 'designation' => 'Engineer', 'doj' => '1 Dec 2026', 'probation_months' => '3', 'code' => 'TST1']);
t('LT03 render: paragraphs, bullets, bold; values escaped', str_contains($html, '<p>Dear TEST &lt;b&gt;A&lt;/b&gt;,</p>') && str_contains($html, '<ul><li>Probation 3 months</li><li>Code TST1</li></ul>')
    && str_contains($html, '<b>Welcome</b>') && !str_contains($html, '<b>A</b>'));
t('LT04 an empty field shows as [field]', str_contains(let_render('Hi {{name}}', []), '<mark>[name]</mark>'));
t('LT05 typed text cannot inject markup', !str_contains(let_render('<script>x</script> {{a}}', ['a' => '"><img src=x>']), '<script') && !str_contains(let_render('{{a}}', ['a' => '"><img src=x>']), '<img'));
$auto = let_auto_vars(['name' => 'TEST B', 'code' => 'TST2', 'designation' => 'X', 'department' => '', 'doj' => '2026-12-01', 'confirm_date' => null, 'last_day' => null], 'TEST North', 'TEST Co', 3, '2026-11-12');
t('LT06 values from the record; dates written out; blanks left out', $auto['doj'] === '1 December 2026' && $auto['date'] === '12 November 2026' && !isset($auto['department']) && $auto['branch'] === 'TEST North');
t('LT07 reference and code', let_ref('OFFER', 2026, 7) === 'OFFER/2026/0007' && preg_match('/^[A-HJ-NP-Z2-9]{10}$/', let_code()) === 1);
$sg = [['role_code' => 'HRMANAGER', 'name' => 'TEST Old', 'title' => 'HR', 'from_day' => '2026-01-01', 'active' => 1], ['role_code' => 'HRMANAGER', 'name' => 'TEST New', 'title' => 'HR', 'from_day' => '2026-11-01', 'active' => 1],
       ['role_code' => 'CEO', 'name' => 'TEST Boss', 'title' => 'CEO', 'from_day' => '2026-01-01', 'active' => 1]];
t('LT08 signatory valid on the letter\'s date', let_signer_on($sg, 'HRMANAGER', '2026-10-15')['name'] === 'TEST Old' && let_signer_on($sg, 'HRMANAGER', '2026-11-12')['name'] === 'TEST New' && let_signer_on($sg, 'HROPS', '2026-11-12') === null);
$u = ['id' => 5, 'emp_code' => 'TST2', 'roles' => ['CEO']];
$l = ['status' => 'approval', 'signer_role' => 'CEO', 'created_by' => 9, 'emp_code' => 'TST9'];
t('LT09 approval: the signer role, not the maker, not about oneself', let_can_approve($u, $l) && !let_can_approve($u, ['created_by' => 5] + $l) && !let_can_approve($u, ['emp_code' => 'tst2'] + $l)
    && !let_can_approve(['roles' => ['HRMANAGER']] + $u, $l) && !let_can_approve($u, ['status' => 'draft'] + $l));
$iss = ['resp' => 'accept', 'response' => null, 'status' => 'issued', 'accept_by' => '2026-11-20'];
t('LT10 answer state: open, already answered, lapsed / past date, no answer needed', let_answer_state($iss, '2026-11-12') === 'ok' && let_answer_state(['response' => 'yes'] + $iss, '2026-11-12') === ''
    && let_answer_state($iss, '2026-11-21') !== 'ok' && let_answer_state(['status' => 'withdrawn'] + $iss, '2026-11-12') !== 'ok' && let_answer_state(['resp' => 'none'] + $iss, '2026-11-12') !== 'ok');
t('LT11 typed name must be the person\'s', let_name_ok('test neha joiner', 'TEST Neha Joiner') && !let_name_ok('TEST Arjun', 'TEST Neha Joiner'));
$tc = function (array $in) { try { return let_template_check($in, ['HRMANAGER', 'CEO']); } catch (InvalidArgumentException $e) { return null; } };
$okT = ['code' => 'offer', 'name' => 'TEST Offer', 'body' => 'Dear {{name}}, welcome aboard to the team.', 'resp' => 'accept', 'signer_role' => 'CEO'];
t('LT12 template form: code, text, answer kind, signer role; no script', $tc($okT)['code'] === 'OFFER' && $tc(['code' => '1X'] + $okT) === null && $tc(['signer_role' => 'X'] + $okT) === null
    && $tc(['body' => 'short'] + $okT) === null && $tc(['body' => 'Dear {{name}} <script>alert(1)</script> hello there'] + $okT) === null);
