<?php
// Candidate portal rules (lib/candidates.php) — synthetic data only.
require_once WN_ROOT . '/lib/candidates.php';
t('CND01 candidate wording for stages; closed / on hold are off the step line', cand_stage('screening') === ['Application received', 0] && cand_stage('offer') === ['Offer made', 3] && cand_stage('hold')[1] === -1 && cand_stage('rejected')[0] === 'Not taken forward');
t('CND02 a closed application ends the access by itself', cand_stage_ends('joined') && cand_stage_ends('rejected') && cand_stage_ends('withdrawn') && !cand_stage_ends('offer') && !cand_stage_ends('hold'));
$ans = [['id' => 1, 'for_at' => '2026-11-20 10:00:00', 'answer' => 'attend'], ['id' => 2, 'for_at' => '2026-11-20 10:00:00', 'answer' => 'resched'], ['id' => 3, 'for_at' => '2026-11-18 10:00:00', 'answer' => 'attend']];
t('CND03 the newest answer counts, and only for the interview time it was given for (a new time asks again)', cand_iv_state($ans, '2026-11-20 10:00:00') === 'resched' && cand_iv_state($ans, '2026-11-25 11:00:00') === '' && cand_iv_state([], 'x') === '');
t('CND04 only upcoming, scheduled interviews can be answered', cand_iv_open(['status' => 'scheduled', 'at' => '2026-11-20 10:00:00'], '2026-11-19 10:00:00') && !cand_iv_open(['status' => 'scheduled', 'at' => '2026-11-18 10:00:00'], '2026-11-19 10:00:00') && !cand_iv_open(['status' => 'done', 'at' => '2026-11-20 10:00:00'], '2026-11-19 10:00:00'));
[$d, $e] = cand_detail_check(['phone' => 'abc', 'total_exp' => '70', 'notice_days' => '30', 'city' => ' TEST <b>town</b> ']);
t('CND05 details sent are checked and tidied; an empty form is refused', count($e) === 2 && $d['city'] === 'TEST town' && $d['notice_days'] === '30' && cand_detail_check([])[1] === ['Fill in at least one detail.']);
t('CND06 only the hash of a link is kept', cand_token_hash('abc') === hash('sha256', 'abc') && strlen(cand_token_hash('x')) === 64);
[$st, $se] = cand_settings_check(['manage_roles' => ['HRRECRUIT', 'X'], 'link_days' => 0], ['HRRECRUIT' => 'r']);
t('CND07 settings: roles checked, link days 1–180', $st['manage_roles'] === ['HRRECRUIT'] && $st['link_days'] === 1 && $se === [] && cand_settings_check([], [])[1] !== []);
