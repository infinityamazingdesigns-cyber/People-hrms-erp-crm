<?php
// Production worker rules (lib/production.php) — synthetic data only.
require_once WN_ROOT . '/lib/production.php';
$pwE = function (callable $f): string { try { $f(); return ''; } catch (Throwable $e) { return $e->getMessage(); } };
$S = PRD_DEFAULTS;
$rH = prd_rights_for(['PRODHEAD'], $S); $rS = prd_rights_for(['SUPERADMIN'], $S); $rC = prd_rights_for(['CEO'], $S); $rE = prd_rights_for(['EMPLOYEE'], $S);
t('PW01 rights: a production head enters but never sees money; Super Admin runs pay; CEO reads; others nothing', $rH['entry'] && !$rH['money'] && $rS['money'] && $rS['entry'] && $rC['view'] && !$rC['entry'] && !$rE['view']);
t('PW02 scope: a production head with no branch ticked sees nothing; CEO / Super Admin every branch; ticked branches otherwise', prd_scope_for(['PRODHEAD'], [], $S) === [] && prd_scope_for(['PRODHEAD'], [2], $S) === [2]
    && prd_scope_for(['CEO'], [2], $S) === null && prd_scope_for(['HRMANAGER'], [], $S) === null);
t('PW03 hours: 9.5, 9:30 and "9 h 30" read the same; blank = not worked; nonsense refused', prd_hours_parse('9.5') === 950 && prd_hours_parse('9:30') === 950 && prd_hours_parse('9 h 30') === 950 && prd_hours_parse('') === null
    && $pwE(fn() => prd_hours_parse('25')) !== '' && $pwE(fn() => prd_hours_parse('9:75')) !== '' && $pwE(fn() => prd_hours_parse('abc')) !== '');
t('PW04 money in whole minor units, never floats', prd_minor('1,250.50') === 125050 && prd_minor('7') === 700 && prd_minor('') === 0 && $pwE(fn() => prd_minor('1.234')) !== '' && prd_money(125050) === '1,250.50' && prd_money(-5) === '-0.05');
$days = ['2026-10-01' => 1000, '2026-10-02' => 800, '2026-10-20' => 950, '2026-10-21' => 0];
$p = prd_pay($days, ['2026-10-01' => 5000, '2026-10-15' => 6000], [['2026-10-05', 10, 50], ['2026-10-06', 4, 70]], ['other_earn' => 1000, 'adv_recover' => 2000, 'ded_a' => 500], 3000, 800, 1500);
t('PW05 pay: each day at the rate in force that day, hours above the standard day as overtime × the multiplier, pieces at their rate', $p['days'] === 3 && $p['reg'] === 2400 && $p['ot'] === 350
    && $p['reg_pay'] === 40000 + 40000 + 48000 && $p['ot_pay'] === prd_wage(200, 5000, 1500) + prd_wage(150, 6000, 1500) && $p['pc_pay'] === 780 && $p['gross'] === $p['reg_pay'] + $p['ot_pay'] + 780 + 1000
    && $p['net'] === $p['gross'] - 2500 && $p['adv_after'] === 1000 && !$p['flags']);
$q = prd_pay(['2026-10-01' => 800], [], [['2026-10-01', 3, null]], ['adv_recover' => 900], 500, 800, 1000);
t('PW06 red flags: no rate on a worked day, pieces with no rate, recovery above the outstanding, net below zero', count(array_filter($q['flags'], fn($f) => $f[0] === 'bad')) === 4);
t('PW07 a production head sees hours and pieces only — never a wage or advance', array_keys(prd_hours_only($p)) === ['days', 'worked', 'reg', 'ot', 'pcs']);
$L = [['code' => 'PW0001', 'kind' => 'opening', 'minor' => 5000, 'pay_ym' => null], ['code' => 'PW0001', 'kind' => 'given', 'minor' => 2000, 'pay_ym' => null], ['code' => 'PW0001', 'kind' => 'recovered', 'minor' => 1500, 'pay_ym' => '2026-10'], ['code' => 'PW0002', 'kind' => 'waived', 'minor' => 0, 'pay_ym' => null]];
t('PW08 advances: opening + given − recovered − waived; a month\'s own recoveries left out while it is worked out', prd_adv_sum($L)['PW0001'] === 5500 && prd_adv_sum($L, '2026-10')['PW0001'] === 7000 && prd_in_force(['2026-10-15' => 6, '2026-10-01' => 5], '2026-10-10') === 5 && prd_in_force(['2026-10-15' => 6], '2026-10-10') === null);
$v = prd_worker_input(['name' => ' TEST  Ravi ', 'branch_id' => '2', 'mobile' => '+1 555 000 1234', 'card_no' => 'tk 12', 'skill' => 'Skilled', 'paid_by' => 'cash'], [2], '2026-11-19');
t('PW09 worker form: own codes, branch in scope, card tidied; future joining refused', $v['name'] === 'TEST Ravi' && $v['card_no'] === 'TK12' && $v['paid_by'] === 'cash' && prd_next_code('PW', 41) === 'PW0042'
    && $pwE(fn() => prd_worker_input(['name' => 'TEST A', 'branch_id' => '3'], [2], '2026-11-19')) !== '' && $pwE(fn() => prd_worker_input(['name' => 'TEST A', 'branch_id' => '2', 'doj' => '2027-01-01'], [2], '2026-11-19')) !== '');
[$so, $se] = prd_settings_check(['money_roles' => [], 'std_hours' => '9:00', 'ot_multiplier' => '2', 'first_month' => '2026-13', 'prefix' => 'w-1'], ['SUPERADMIN' => 's']);
t('PW10 settings: someone must run pay; standard day, multiplier, month and prefix checked', count($se) === 1 && $so['std_centi'] === 900 && $so['ot_permille'] === 2000 && $so['first_month'] === '' && $so['prefix'] === 'W');
$src = (string)file_get_contents(WN_ROOT . '/lib/production.php');
t('PW11 nothing deleted; production audit family chained; no float money', !preg_match('/\b(DELETE\s+FROM|TRUNCATE|DROP\s+TABLE)\b/i', $src) && in_array('prd_', AUDIT_CHAINED_PREFIXES, true) && !str_contains($src, 'DECIMAL'));
