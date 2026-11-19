<?php
// Induction academy rules (lib/induction.php) — synthetic data only.
require_once WN_ROOT . '/lib/induction.php';
$lv = [['id' => 3, 'sort' => 2, 'active' => 1, 'department' => ''], ['id' => 1, 'sort' => 1, 'active' => 1, 'department' => ''], ['id' => 2, 'sort' => 1, 'active' => 1, 'department' => 'TEST Sales'], ['id' => 4, 'sort' => 0, 'active' => 0, 'department' => '']];
t('IND01 levels for a person: everyone + own department, switched-off left out, in order', array_column(ind_levels_for($lv, 'test sales'), 'id') === [1, 2, 3] && array_column(ind_levels_for($lv, 'TEST Stores'), 'id') === [1, 3]);
$st = ind_states(ind_levels_for($lv, 'TEST Sales'), [1 => 'x']);
t('IND02 a level opens once every earlier one is passed', $st === [1 => 'done', 2 => 'open', 3 => 'locked'] && ind_states(ind_levels_for($lv, 'x'), [3 => 'y'])[1] === 'open');
$qs = [['id' => 10, 'correct' => 0], ['id' => 11, 'correct' => 2], ['id' => 12, 'correct' => 1]];
t('IND03 grading on the server: unanswered is wrong, pass at the mark', ind_grade($qs, [10 => 0, 11 => 2], 60) === ['score' => 2, 'total' => 3, 'pct' => 66, 'passed' => true] && !ind_grade($qs, [10 => 0, 11 => 2], 70)['passed'] && !ind_grade([], [], 0)['passed']);
[$q, $o, $c, $e] = ind_question_check('TEST – Where is the first-aid box?', ['Reception', '', 'Store', ''], 2);
t('IND04 question check: blank options dropped, the right one follows its option', $e === [] && $o === ['Reception', 'Store'] && $c === 1);
t('IND05 question check refuses a blank right option, one option, repeats', count(ind_question_check('Q', ['A', '', 'B'], 1)[3]) === 1 && count(ind_question_check('Q', ['A'], 0)[3]) === 1 && count(ind_question_check('Q', ['A', 'a'], 0)[3]) === 1);
$pub = ind_quiz_public([['id' => 5, 'q' => 'Q', 'opts' => '["A","B"]', 'correct' => 1]]);
t('IND06 the quiz sent to the browser never carries the right answer', $pub === [['id' => 5, 'q' => 'Q', 'opts' => ['A', 'B']]] && !str_contains(json_encode($pub), 'correct'));
t('IND07 retry wait in minutes; no wait without a setting or a failed try', ind_wait_left('2026-11-19 10:00:00', 30, strtotime('2026-11-19 10:10:00')) === 20 && ind_wait_left('2026-11-19 10:00:00', 30, strtotime('2026-11-19 10:40:00')) === 0 && ind_wait_left(null, 30, 0) === 0 && ind_wait_left('2026-11-19 10:00:00', 0, 0) === 0);
t('IND08 video links are https only', ind_video_ok('') && ind_video_ok('https://example.com/v') && !ind_video_ok('http://example.com') && !ind_video_ok('javascript:alert(1)'));
