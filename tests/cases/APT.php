<?php
// Aptitude test rules (lib/aptitude.php) — synthetic data only.
require_once WN_ROOT . '/lib/aptitude.php';
$S0 = APT_DEFAULTS; $tr = apt_tracks($S0);
t('APT01 job families and level names come from the settings text', array_keys($tr) === ['ops', 'tech', 'sales', 'office'] && apt_levels($S0)[3] === 'L3 · Executive' && apt_tracks(['tracks' => "bad line\nx|"]) === []);
$mk = fn($id, $sec, $min, $max, $t = '*') => ['id' => $id, 'sec' => $sec, 'tracks' => $t, 'lvl_min' => $min, 'lvl_max' => $max, 'active' => 1, 'q' => "TEST Q$id", 'opts' => '["A","B","C","D"]', 'ans' => 2, 'keep_order' => 0];
$bank = [$mk(1, 'num', 1, 1), $mk(2, 'num', 3, 4), $mk(3, 'num', 2, 2), $mk(4, 'num', 1, 2, 'sales'), $mk(5, 'reason', 1, 4)];
$noRand = fn(int $m) => $m;
$d = apt_draw($bank, 'ops', 1, ['num' => 2, 'reason' => 1], $tr, [], $noRand);
t('APT02 draw: own level first, then the nearest; other families left out; the blueprint count per section', count($d) === 3 && in_array(1, array_column($d, 'id'), true) && in_array(3, array_column($d, 'id'), true) && !in_array(4, array_column($d, 'id'), true) && !in_array(2, array_column($d, 'id'), true));
$d2 = apt_draw($bank, 'ops', 1, ['num' => 1], $tr, [1], $noRand);
t('APT03 a question asked before comes after a new one one level away', array_column($d2, 'id') === [3]);
$it = apt_items([$mk(9, 'num', 1, 4)], fn(int $m) => 0);
t('APT04 options shuffled; the right option follows its text', $it[0]['opts'][$it[0]['o'][$it[0]['r']]] === 'C' && count($it[0]['o']) === 4);
$pub = apt_items_public($it);
t('APT05 the paper sent to the browser has no right answer', !isset($pub[0]['r']) && !str_contains(json_encode($pub), '"r"') && count($pub[0]['opts']) === 4);
[$sc, $tot, $sec] = apt_score($it, ['0' => $it[0]['r']]);
t('APT06 scoring per section; bands from the settings', $sc === 1 && $tot === 1 && $sec['num'] === [1, 1] && apt_band(86, $S0['bands']) === 'Excellent' && apt_band(56, $S0['bands']) === 'Average' && apt_band(10, $S0['bands']) === 'Very weak');
t('APT07 only valid answers are kept', apt_answers_clean(['0' => '1', '5' => '0', 'x' => '1', '1' => '9'], [['o' => [0, 1]], ['o' => [0, 1]]]) === ['0' => 1]);
t('APT08 state: no code, open, done, invited / re-test, locked by the rule', apt_state_calc('', null, false, null, 'anyone', true) === 'nocode' && apt_state_calc('T1', ['id' => 1], true, null, 'anyone', true) === 'open'
    && apt_state_calc('T1', null, true, null, 'anyone', true) === 'done' && apt_state_calc('T1', null, true, ['retest' => 1], 'invite', false) === 'ready'
    && apt_state_calc('T1', null, false, null, 'invite', true) === 'locked' && apt_state_calc('T1', null, false, ['invited' => 1], 'invite', false) === 'ready'
    && apt_state_calc('T1', null, false, null, 'induction', false) === 'locked' && apt_state_calc('T1', null, false, null, 'induction', true) === 'ready');
[$q, $qe] = apt_q_check(['sec' => 'num', 'tracks' => ['sales', 'zz'], 'lvl_min' => 3, 'lvl_max' => 2, 'q' => 'TEST – 2+2?', 'opt' => ['3', '4'], 'ans' => 1], $tr);
t('APT09 question check: families kept from the list, level range in order', $q['tracks'] === 'sales' && count($qe) === 1 && apt_q_check(['sec' => 'num', 'q' => 'x', 'opt' => ['a', 'b'], 'ans' => 0], $tr)[0]['tracks'] === '*');
[$st, $se] = apt_settings_check(['manage_roles' => ['HRMANAGER'], 'tracks' => "a | TEST A", 'blueprint' => [1 => ['num' => 3]], 'band_name' => ['Good', ''], 'band_from' => [60, '']], ['HRMANAGER' => 'h']);
t('APT10 settings: a level with no questions is refused; bands kept in order', count($se) === 3 && $st['bands'] === ['Good' => 60] && $st['default_track'] === 'a');
t('APT11 induction finished when every level that applies is passed; no levels = nothing to wait for', apt_induction_done(['done' => 2, 'of' => 2]) && !apt_induction_done(['done' => 1, 'of' => 2]) && apt_induction_done(['done' => 0, 'of' => 0]));
