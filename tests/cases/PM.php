<?php
// Performance (KRA) rules (lib/pms.php) — synthetic data only.
require_once WN_ROOT . '/lib/pms.php';
$in = [['name' => 'TEST – Sales', 'weight' => 60, 'kpis' => [['name' => 'Revenue vs target', 'target' => '100', 'unit' => '%', 'polarity' => 'higher', 'w' => 2], ['name' => 'Returns', 'target' => '5', 'polarity' => 'lower', 'w' => 1], ['name' => '', 'target' => '']]],
       ['name' => 'TEST – Safety', 'weight' => 40, 'kpis' => [['name' => 'Incidents', 'target' => '0', 'polarity' => 'zero']]], ['name' => '', 'weight' => '', 'kpis' => []]];
[$k, $e] = pms_body_check($in);
t('PM01 sheet cleaned: empty rows dropped, ids given, weights total 100', $e === [] && count($k) === 2 && count($k[0]['kpis']) === 2 && $k[0]['kpis'][0]['id'] !== '' && $k[0]['kpis'][0]['id'] !== $k[0]['kpis'][1]['id'] && $k[1]['kpis'][0]['polarity'] === 'zero');
[, $e2] = pms_body_check([['name' => 'A', 'weight' => 50, 'kpis' => [['name' => 'x', 'target' => 'lots']]], ['name' => 'B', 'weight' => 30, 'kpis' => []]]);
t('PM02 problems named: weights not 100, a target that is not a number, a KRA without KPIs', count($e2) === 4 && (bool)preg_grep('/add up to 80/', $e2) && (bool)preg_grep('/must be a number/', $e2) && pms_body_check([])[1] === ['Add at least one KRA.']);
$h = ['target' => 100, 'polarity' => 'higher']; $l = ['target' => 5, 'polarity' => 'lower']; $z = ['target' => 0, 'polarity' => 'zero'];
t('PM03 achievement: higher, lower, zero target; capped; blank = not given', pms_ach($h, 90, 120) === 90.0 && pms_ach($h, 200, 120) === 120.0 && pms_ach($l, 10, 120) === 50.0 && pms_ach($l, 0, 120) === 120.0
    && pms_ach($z, 0, 120) === 100.0 && pms_ach($z, 2, 120) === 50.0 && pms_ach($z, 9, 120) === 0.0 && pms_ach($h, '', 120) === null && pms_ach(['target' => 0, 'polarity' => 'higher'], 3, 120) === 120.0);
$ids = [$k[0]['kpis'][0]['id'], $k[0]['kpis'][1]['id'], $k[1]['kpis'][0]['id']];
$s = pms_score($k, [$ids[0] => 90, $ids[1] => 5, $ids[2] => 0], 120);
t('PM04 score: KPI weights inside the KRA, then KRA weights', $s['kras'][0]['score'] === 93.3 && $s['kras'][1]['score'] === 100.0 && $s['total'] === 96.0 && $s['covered'] === 100);
$p = pms_score($k, [$ids[0] => 90], 120);
t('PM05 score with part of the actuals: only KRAs with an actual count, and how much is covered', $p['kras'][1]['score'] === null && $p['total'] === 90.0 && $p['covered'] === 60 && pms_score($k, [], 120)['total'] === null);
$bands = PMS_DEFAULTS['bands'];
t('PM06 bands', pms_band(96.0, $bands) === 'Outstanding' && pms_band(60.0, $bands) === 'Meets expectations' && pms_band(10.0, $bands) === 'Unsatisfactory' && pms_band(null, $bands) === 'Not scored');
t('PM07 periods by cycle with an April year start; calendar years stay plain', pms_period('2026-11-18', 'Q', 4) === ['FY2026-Q3', 'Q3 2026-27'] && pms_period('2027-02-01', 'Q', 4)[0] === 'FY2026-Q4'
    && pms_period('2026-11-18', 'H', 4)[0] === 'FY2026-H2' && pms_period('2026-04-01', 'Y', 4) === ['FY2026', 'Year 2026-27'] && pms_period('2026-11-18', 'M', 4)[0] === '2026-11'
    && pms_period('2026-11-18', 'Q', 1) === ['FY2026-Q4', 'Q4 2026']);
t('PM08 previous / next period', pms_period_step('FY2026-Q3', 1, 'Q', 4)[0] === 'FY2026-Q4' && pms_period_step('FY2026-Q4', 1, 'Q', 4)[0] === 'FY2027-Q1' && pms_period_step('FY2026-Q1', -1, 'Q', 4)[0] === 'FY2025-Q4'
    && pms_period_step('2026-01', -1, 'M', 4)[0] === '2025-12' && pms_period_step('FY2026', 1, 'Y', 4)[0] === 'FY2027' && pms_period_step('FY2026-H2', 1, 'H', 4)[0] === 'FY2027-H1');
t('PM09 steps: the person saves / submits / takes back; manager reviews or returns; HR closes, returns or reopens', pms_step_problem('none', 'submit', 'self') === '' && pms_step_problem('returned', 'save', 'self') === ''
    && pms_step_problem('submitted', 'save', 'self') !== '' && pms_step_problem('submitted', 'recall', 'self') === '' && pms_step_problem('submitted', 'review', 'manager') === ''
    && pms_step_problem('reviewed', 'return', 'manager') !== '' && pms_step_problem('reviewed', 'return', 'hr') === '' && pms_step_problem('reviewed', 'close', 'hr') === ''
    && pms_step_problem('closed', 'reopen', 'hr') === '' && pms_step_problem('reviewed', 'close', 'manager') !== '');
$bad = function (callable $f): bool { try { $f(); return false; } catch (InvalidArgumentException $e) { return true; } };
[$a, $c] = pms_actuals($k, ['v' => [$ids[0] => '1,200', $ids[1] => ''], 'c' => [$ids[0] => '<b>good</b>']]);
t('PM10 actuals from the form: numbers, blanks as not given, comments cleaned', $a[$ids[0]] === 1200.0 && $a[$ids[1]] === null && $a[$ids[2]] === null && $c[$ids[0]] === 'good' && $bad(fn() => pms_actuals($k, ['v' => [$ids[0] => 'lots']])));
[$st, $se] = pms_settings_check(['hr_roles' => ['HRPERF'], 'cycle' => 'X', 'cap' => 999, 'b_label' => ['Top', 'Rest'], 'b_min' => ['80', '10']], ['HRPERF' => 'p']);
t('PM11 settings: cycle falls back, cap kept in range, bands need one from 0', $st['cycle'] === 'Q' && $st['cap'] === 200 && count($se) === 1 && $st['bands'][0]['label'] === 'Top');
