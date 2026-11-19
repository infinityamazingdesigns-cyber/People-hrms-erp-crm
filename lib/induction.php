<?php
// Induction academy — HR's levels (reading, an optional https video link, a quiz), for everyone or one department.
// A person (login linked to an employee record) takes the levels that apply to them in order: a level opens once every
// earlier one is passed. The quiz is graded here; the right answers never reach the browser; a retry waits for the
// setting's minutes. Every attempt is kept. HR (settings roles) sees each person's progress within their branch scope;
// the onboarding page shows the joiner's progress.
declare(strict_types=1);
require_once __DIR__ . '/employees.php';

const IND_DEFAULTS = ['manage_roles' => ['SUPERADMIN', 'HRMANAGER'], 'pass_pct' => 70, 'retry_minutes' => 0];
const IND_OPTS_MIN = 2;
const IND_OPTS_MAX = 6;

/* ---------------- pure ---------------- */

/** Levels that apply to a department (blank level department = everyone), in order. */
function ind_levels_for(array $levels, string $dept): array {
    $d = strtolower(trim($dept));
    $o = array_values(array_filter($levels, fn($l) => (int)$l['active'] && (trim((string)$l['department']) === '' || strtolower(trim((string)$l['department'])) === $d)));
    usort($o, fn($a, $b) => [(int)$a['sort'], (int)$a['id']] <=> [(int)$b['sort'], (int)$b['id']]);
    return $o;
}
/** Each level's state for a person: done / open / locked. $passed = level id => passed at. */
function ind_states(array $levels, array $passed): array {
    $o = []; $earlier = true;
    foreach ($levels as $l) { $id = (int)$l['id']; $o[$id] = isset($passed[$id]) ? 'done' : ($earlier ? 'open' : 'locked'); if (!isset($passed[$id])) $earlier = false; }
    return $o;
}
/** Grade a quiz: $questions [id, correct]; $answers question id => option index. Unanswered = wrong. */
function ind_grade(array $questions, array $answers, int $passPct): array {
    $score = 0; foreach ($questions as $q) if (isset($answers[(int)$q['id']]) && (int)$answers[(int)$q['id']] === (int)$q['correct']) $score++;
    $total = count($questions); $pct = $total ? (int)floor(100 * $score / $total) : 0;
    return ['score' => $score, 'total' => $total, 'pct' => $pct, 'passed' => $total > 0 && $pct >= $passPct];
}
/** Check a question form. Returns [q, opts, correct, errors]. */
function ind_question_check(string $q, array $opts, $correct): array {
    $q = mb_substr(trim(strip_tags($q)), 0, 500); $e = [];
    $kept = []; $map = [];
    foreach (array_values($opts) as $i => $o) { $o = mb_substr(trim(strip_tags((string)$o)), 0, 200); if ($o !== '') { $map[$i] = count($kept); $kept[] = $o; } }
    if ($q === '') $e[] = 'Write the question.';
    if (count($kept) < IND_OPTS_MIN || count($kept) > IND_OPTS_MAX) $e[] = 'Give 2 to 6 options.';
    if (count(array_unique(array_map('mb_strtolower', $kept))) !== count($kept)) $e[] = 'Two options are the same.';
    $c = is_numeric($correct) ? ($map[(int)$correct] ?? null) : null;
    if ($c === null) $e[] = 'Pick the right option (one that is filled in).';
    return [$q, $kept, (int)$c, $e];
}
/** Minutes left before a retry is allowed. */
function ind_wait_left(?string $lastFailAt, int $retryMinutes, int $now): int {
    if (!$lastFailAt || $retryMinutes <= 0) return 0;
    return max(0, (int)ceil((strtotime($lastFailAt) + $retryMinutes * 60 - $now) / 60));
}
/** Is a video link acceptable? https only. */
function ind_video_ok(string $url): bool { return $url === '' || (bool)preg_match('#^https://[^\s<>"]+$#i', $url); }

/* ---------------- settings and rights ---------------- */

function ind_settings(): array { $s = module_states()['induction']['settings'] ?? []; return array_replace(IND_DEFAULTS, array_intersect_key($s, IND_DEFAULTS)); }
function ind_settings_check(array $in, array $roles): array {
    $o = ['manage_roles' => array_values(array_intersect(array_keys($roles), (array)($in['manage_roles'] ?? []))), 'pass_pct' => max(1, min(100, (int)($in['pass_pct'] ?? 70))), 'retry_minutes' => max(0, min(10080, (int)($in['retry_minutes'] ?? 0)))];
    return [$o, $o['manage_roles'] ? [] : ['Pick at least one role that keeps the academy.']];
}
function ind_can(array $u): bool { return has_role($u, ...ind_settings()['manage_roles']); }
function ind_need(array $u): void { if (!ind_can($u)) throw new RuntimeException('Your role does not keep the academy.'); }
function ind_log(PDO $db, int $lid, string $what, ?int $by, string $note = ''): void { $db->prepare('INSERT INTO ind_log (level_id, what, note, by_user) VALUES (?,?,?,?)')->execute([$lid, $what, mb_substr($note, 0, 300), $by]); }

/* ---------------- levels and questions ---------------- */

function ind_levels(PDO $db, bool $all = false): array { return $db->query('SELECT l.*, (SELECT COUNT(*) FROM ind_question q WHERE q.level_id = l.id AND q.active = 1) nq FROM ind_level l' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort, id')->fetchAll(); }
function ind_level_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM ind_level WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
function ind_questions(PDO $db, int $lid, bool $all = false): array { $s = $db->prepare('SELECT * FROM ind_question WHERE level_id = ?' . ($all ? '' : ' AND active = 1') . ' ORDER BY sort, id'); $s->execute([$lid]); return $s->fetchAll(); }
function ind_level_save(PDO $db, array $u, int $id, array $in): int {
    ind_need($u);
    $l = ['title' => mb_substr(trim(strip_tags((string)($in['title'] ?? ''))), 0, 160), 'summary' => mb_substr(trim(strip_tags((string)($in['summary'] ?? ''))), 0, 300),
          'body' => mb_substr(trim(strip_tags(str_replace("\r", '', (string)($in['body'] ?? '')))), 0, 60000), 'video_url' => mb_substr(trim((string)($in['video_url'] ?? '')), 0, 400),
          'department' => mb_substr(trim(strip_tags((string)($in['department'] ?? ''))), 0, 80), 'sort' => (int)($in['sort'] ?? 0),
          'pass_pct' => trim((string)($in['pass_pct'] ?? '')) === '' ? null : max(1, min(100, (int)$in['pass_pct']))];
    if ($l['title'] === '') throw new RuntimeException('Give the level a title.');
    if (!ind_video_ok($l['video_url'])) throw new RuntimeException('A video link must start with https://');
    if ($id) { if (!ind_level_get($db, $id)) throw new RuntimeException('Level not found.'); $db->prepare('UPDATE ind_level SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($l))) . ', updated_by = ? WHERE id = ?')->execute([...array_values($l), (int)$u['id'], $id]); ind_log($db, $id, 'edit', (int)$u['id']); }
    else { $db->prepare('INSERT INTO ind_level (' . implode(',', array_keys($l)) . ', created_by, updated_by) VALUES (' . implode(',', array_fill(0, count($l) + 2, '?')) . ')')->execute([...array_values($l), (int)$u['id'], (int)$u['id']]); $id = (int)$db->lastInsertId(); ind_log($db, $id, 'add', (int)$u['id']); }
    return $id;
}
function ind_level_active(PDO $db, array $u, int $id, bool $on, string $why): void {
    ind_need($u); if (!ind_level_get($db, $id)) throw new RuntimeException('Level not found.');
    $why = mb_substr(trim(strip_tags($why)), 0, 200); if (!$on && $why === '') throw new RuntimeException('Write why the level is switched off.');
    $db->prepare('UPDATE ind_level SET active = ?, off_reason = ?, updated_by = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? '' : $why, (int)$u['id'], $id]); ind_log($db, $id, $on ? 'on' : 'off', (int)$u['id'], $why);
}
function ind_question_add(PDO $db, array $u, int $lid, array $in): void {
    ind_need($u); if (!ind_level_get($db, $lid)) throw new RuntimeException('Level not found.');
    [$q, $opts, $c, $e] = ind_question_check((string)($in['q'] ?? ''), (array)($in['opt'] ?? []), $in['correct'] ?? null); if ($e) throw new RuntimeException(implode(' ', $e));
    $db->prepare('INSERT INTO ind_question (level_id, sort, q, opts, correct, created_by) VALUES (?,?,?,?,?,?)')->execute([$lid, (int)($in['sort'] ?? 0), $q, json_encode($opts, JSON_UNESCAPED_UNICODE), $c, (int)$u['id']]);
    ind_log($db, $lid, 'question', (int)$u['id']);
}
/** A question is switched off, never changed after people have answered it (a new one is added instead). */
function ind_question_off(PDO $db, array $u, int $qid): void { ind_need($u); $s = $db->prepare('SELECT level_id FROM ind_question WHERE id = ?'); $s->execute([$qid]); $lid = (int)$s->fetchColumn(); if (!$lid) throw new RuntimeException('Question not found.'); $db->prepare('UPDATE ind_question SET active = 0 WHERE id = ?')->execute([$qid]); ind_log($db, $lid, 'question_off', (int)$u['id'], '#' . $qid); }

/* ---------------- the learner ---------------- */

/** Passed levels of a person: level id => first passed at. */
function ind_passed(PDO $db, string $code): array { $s = $db->prepare('SELECT level_id, MIN(at) at FROM ind_attempt WHERE emp_code = ? AND passed = 1 GROUP BY level_id'); $s->execute([strtoupper($code)]); $o = []; foreach ($s as $r) $o[(int)$r['level_id']] = (string)$r['at']; return $o; }
/** The person's path: levels that apply, each with its state. */
function ind_path(PDO $db, string $code): array {
    $e = emp_by_code($db, strtoupper($code)); $lv = ind_levels_for(ind_levels($db), (string)($e['department'] ?? '')); $p = ind_passed($db, $code); $st = ind_states($lv, $p);
    return ['levels' => $lv, 'state' => $st, 'passed' => $p, 'done' => count(array_filter($st, fn($x) => $x === 'done')), 'of' => count($lv)];
}
/** Quiz for the browser — questions and options only, never which option is right. */
function ind_quiz_public(array $questions): array { return array_map(fn($q) => ['id' => (int)$q['id'], 'q' => (string)$q['q'], 'opts' => json_decode((string)$q['opts'], true) ?: []], $questions); }
function ind_last_fail(PDO $db, string $code, int $lid): ?string { $s = $db->prepare('SELECT MAX(at) FROM ind_attempt WHERE emp_code = ? AND level_id = ? AND passed = 0'); $s->execute([strtoupper($code), $lid]); return ($x = $s->fetchColumn()) ? (string)$x : null; }
/** Submit a quiz for an open level. Returns the grade. */
function ind_submit(PDO $db, array $u, int $lid, array $answers, ?int $now = null): array {
    $code = strtoupper((string)($u['emp_code'] ?? '')); if ($code === '') throw new RuntimeException('Your login is not linked to an employee record yet — ask HR.');
    $path = ind_path($db, $code); $st = $path['state'][$lid] ?? null;
    if ($st === null) throw new RuntimeException('This level is not on your path.');
    if ($st === 'locked') throw new RuntimeException('Pass the earlier levels first.');
    if ($st === 'done') throw new RuntimeException('You have passed this level already.');
    $S = ind_settings(); $wait = ind_wait_left(ind_last_fail($db, $code, $lid), (int)$S['retry_minutes'], $now ?? time());
    if ($wait > 0) throw new RuntimeException("You can try again in $wait minute(s).");
    $qs = ind_questions($db, $lid); if (!$qs) throw new RuntimeException('This level has no quiz yet.');
    $lv = ind_level_get($db, $lid); $clean = []; foreach ($answers as $k => $v) if (ctype_digit((string)$k) && is_numeric($v)) $clean[(int)$k] = (int)$v;
    $g = ind_grade($qs, $clean, (int)($lv['pass_pct'] ?? $S['pass_pct']));
    $db->prepare('INSERT INTO ind_attempt (emp_code, user_id, level_id, score, total, pct, passed, answers) VALUES (?,?,?,?,?,?,?,?)')->execute([$code, (int)$u['id'], $lid, $g['score'], $g['total'], $g['pct'], $g['passed'] ? 1 : 0, json_encode($clean)]);
    audit((int)$u['id'], 'ind_attempt', '#' . $lid . ' ' . ($g['passed'] ? 'passed' : 'not passed'));
    return $g;
}
/** A level with no quiz is finished by reading it ("I have read this"). */
function ind_mark_read(PDO $db, array $u, int $lid): void {
    $code = strtoupper((string)($u['emp_code'] ?? '')); if ($code === '') throw new RuntimeException('Your login is not linked to an employee record yet — ask HR.');
    $st = ind_path($db, $code)['state'][$lid] ?? null; if ($st !== 'open') throw new RuntimeException($st === 'locked' ? 'Pass the earlier levels first.' : 'Nothing to do here.');
    if (ind_questions($db, $lid)) throw new RuntimeException('This level has a quiz.');
    $db->prepare("INSERT INTO ind_attempt (emp_code, user_id, level_id, score, total, pct, passed, answers) VALUES (?,?,?,0,0,100,1,'{}')")->execute([$code, (int)$u['id'], $lid]);
}
/** Everyone's progress on the roll in this login's scope. */
function ind_progress(PDO $db, array $u): array {
    $all = ind_levels($db); $o = [];
    $last = []; foreach ($db->query('SELECT emp_code, MAX(at) at FROM ind_attempt GROUP BY emp_code') as $r) $last[strtoupper($r['emp_code'])] = (string)$r['at'];
    $passed = []; foreach ($db->query('SELECT emp_code, level_id, MIN(at) at FROM ind_attempt WHERE passed = 1 GROUP BY emp_code, level_id') as $r) $passed[strtoupper($r['emp_code'])][(int)$r['level_id']] = (string)$r['at'];
    foreach (emp_on_roll($db, $u) as $e) { $lv = ind_levels_for($all, (string)$e['department']); $st = ind_states($lv, $passed[strtoupper($e['code'])] ?? []);
        $o[] = ['code' => $e['code'], 'name' => $e['name'], 'department' => $e['department'], 'done' => count(array_filter($st, fn($x) => $x === 'done')), 'of' => count($lv), 'last' => $last[strtoupper($e['code'])] ?? '']; }
    return $o;
}
