<?php
// Aptitude test — HR keeps a question bank by section, job family and level range; a person (login linked to an
// employee record, once the unlock rule allows — after the induction academy by default) starts a paper drawn by their
// level's blueprint: own level first, then the nearest, questions not asked before first, options shuffled. The
// deadline is the server's; scoring is on the server; the right answers never reach the browser and **the result is
// never shown to the person** — HR (settings roles, branch scope, never their own) sees it. One open paper at a time;
// a re-test only when HR allows one with a reason. Nothing is deleted.
declare(strict_types=1);
require_once __DIR__ . '/employees.php';
require_once __DIR__ . '/induction.php';

const APT_SECTIONS = ['reason' => 'Reasoning', 'num' => 'Numbers', 'role' => 'Role knowledge', 'safe' => 'Safety & conduct', 'lead' => 'Leadership & judgement'];
const APT_UNLOCK = ['induction' => 'After every induction academy level', 'anyone' => 'Any linked login', 'invite' => 'Only people HR invites'];
const APT_GRACE_SEC = 120;
const APT_DEFAULTS = [
    'manage_roles' => ['SUPERADMIN', 'HRMANAGER'], 'view_roles' => ['CEO'], 'unlock' => 'induction',
    'tracks' => "ops | Operations & production\ntech | Technical & service\nsales | Sales & marketing\noffice | Office & administration",
    'levels' => "Foundation\nSkilled & support\nExecutive\nManager & leader",
    'default_track' => 'office', 'default_level' => 1,
    'minutes' => [1 => 30, 2 => 30, 3 => 35, 4 => 40],
    'blueprint' => [1 => ['reason' => 7, 'num' => 8, 'role' => 7, 'safe' => 8, 'lead' => 0], 2 => ['reason' => 7, 'num' => 8, 'role' => 9, 'safe' => 6, 'lead' => 0],
                    3 => ['reason' => 7, 'num' => 7, 'role' => 8, 'safe' => 4, 'lead' => 4], 4 => ['reason' => 6, 'num' => 6, 'role' => 7, 'safe' => 3, 'lead' => 8]],
    'bands' => ['Excellent' => 85, 'Very good' => 70, 'Average' => 55, 'Weak' => 40],
    'min_items' => 5,
];

/* ---------------- pure ---------------- */

/** Job families from the settings text "key | label" per line. */
function apt_tracks(?array $S = null): array { $o = []; foreach (preg_split('/\R/', (string)($S ?? apt_settings())['tracks']) ?: [] as $l) { [$k, $v] = array_pad(array_map('trim', explode('|', $l, 2)), 2, ''); $k = strtolower((string)preg_replace('/[^a-z0-9]/i', '', $k)); if ($k !== '' && $v !== '') $o[$k] = mb_substr($v, 0, 60); } return $o; }
/** Level names 1–4 from the settings text, one per line. */
function apt_levels(?array $S = null): array { $l = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($S ?? apt_settings())['levels']) ?: []))); $o = []; for ($i = 1; $i <= 4; $i++) $o[$i] = 'L' . $i . ' · ' . mb_substr($l[$i - 1] ?? 'Level ' . $i, 0, 40); return $o; }
function apt_tracks_of(string $t, array $tracks): array { return $t === '*' ? array_keys($tracks) : array_values(array_intersect(array_keys($tracks), explode(',', $t))); }
/**
 * Draw a paper: for each section the blueprint's count — the person's level first, then the nearest levels; a question
 * asked before only after new ones one level further. $rand(max) for tests.
 */
function apt_draw(array $bank, string $track, int $level, array $blueprint, array $tracks, array $avoid = [], ?callable $rand = null): array {
    $rand ??= fn(int $max) => random_int(0, $max);
    $shuffle = function (array $a) use ($rand) { for ($i = count($a) - 1; $i > 0; $i--) { $j = $rand($i); [$a[$i], $a[$j]] = [$a[$j], $a[$i]]; } return $a; };
    $avoid = array_flip($avoid); $out = [];
    foreach (APT_SECTIONS as $sec => $_) {
        $need = (int)($blueprint[$sec] ?? 0); if ($need <= 0) continue;
        $pool = array_values(array_filter($bank, fn($q) => (int)$q['active'] && $q['sec'] === $sec && in_array($track, apt_tracks_of((string)$q['tracks'], $tracks), true)));
        $tiers = [];
        foreach ($pool as $q) { $d = $level < (int)$q['lvl_min'] ? (int)$q['lvl_min'] - $level : ($level > (int)$q['lvl_max'] ? $level - (int)$q['lvl_max'] : 0); $tiers[$d * 2 + (isset($avoid[(int)$q['id']]) ? 3 : 0)][] = $q; }
        ksort($tiers); $pick = [];
        foreach ($tiers as $t) foreach ($shuffle($t) as $q) { if (count($pick) >= $need) break 2; $pick[] = $q; }
        foreach ($shuffle($pick) as $q) $out[] = $q;
    }
    return $out;
}
/** Paper items: question id, section, option order shown, right option in that order, the text as it was. */
function apt_items(array $qs, ?callable $rand = null): array {
    $rand ??= fn(int $max) => random_int(0, $max); $items = [];
    foreach ($qs as $q) { $opts = json_decode((string)$q['opts'], true) ?: []; $o = array_keys($opts);
        if (!(int)$q['keep_order']) for ($i = count($o) - 1; $i > 0; $i--) { $j = $rand($i); [$o[$i], $o[$j]] = [$o[$j], $o[$i]]; }
        $items[] = ['q' => (int)$q['id'], 's' => (string)$q['sec'], 'o' => $o, 'r' => (int)array_search((int)$q['ans'], $o, true), 't' => (string)$q['q'], 'opts' => $opts]; }
    return $items;
}
/** What the browser gets: number, section, question and options in the shown order — never the right one. */
function apt_items_public(array $items): array { $o = []; foreach ($items as $i => $x) $o[] = ['n' => $i, 's' => $x['s'], 'q' => $x['t'], 'opts' => array_map(fn($k) => $x['opts'][$k], $x['o'])]; return $o; }
/** [score, total, sections {sec: [right, of]}] */
function apt_score(array $items, array $answers): array {
    $score = 0; $sec = [];
    foreach ($items as $i => $x) { $sec[$x['s']] ??= [0, 0]; $sec[$x['s']][1]++; if (array_key_exists((string)$i, $answers) && (int)$answers[(string)$i] === (int)$x['r']) { $score++; $sec[$x['s']][0]++; } }
    return [$score, count($items), $sec];
}
function apt_band(float $pct, array $bands): string { arsort($bands); foreach ($bands as $b => $from) if ($pct >= (float)$from) return (string)$b; return 'Very weak'; }
/** Answers sent with a paper: index => option shown (only valid ones kept). */
function apt_answers_clean(array $in, array $items): array { $o = []; foreach ($in as $i => $v) { if (!ctype_digit((string)$i) || !isset($items[(int)$i]) || !is_numeric($v)) continue; if ((int)$v >= 0 && (int)$v < count($items[(int)$i]['o'])) $o[(string)(int)$i] = (int)$v; } return $o; }
/** Is the induction academy finished (every level that applies passed; none = nothing to wait for)? */
function apt_induction_done(array $path): bool { return $path['done'] >= $path['of']; }   // no levels for them = nothing to wait for
/** The person's state. */
function apt_state_calc(string $code, ?array $open, bool $hasDone, ?array $person, string $unlock, bool $inductionDone): string {
    if ($code === '') return 'nocode';
    if ($open) return 'open';
    if ($hasDone && !(int)($person['retest'] ?? 0)) return 'done';
    if ((int)($person['retest'] ?? 0) || (int)($person['invited'] ?? 0)) return 'ready';
    if ($unlock === 'invite') return 'locked';
    if ($unlock === 'induction' && !$inductionDone) return 'locked';
    return 'ready';
}
/** Check a question form. Returns [clean, errors]. */
function apt_q_check(array $in, array $tracks): array {
    $e = []; $q = mb_substr(trim(strip_tags((string)($in['q'] ?? ''))), 0, 600);
    $sec = (string)($in['sec'] ?? ''); if (!isset(APT_SECTIONS[$sec])) $e[] = 'Pick the section.';
    $tr = (array)($in['tracks'] ?? []); $tr = in_array('*', $tr, true) || !$tr ? '*' : implode(',', array_values(array_intersect(array_keys($tracks), $tr)));
    if ($tr === '') $e[] = 'Pick the job families (or every family).';
    $lmin = max(1, min(4, (int)($in['lvl_min'] ?? 1))); $lmax = max(1, min(4, (int)($in['lvl_max'] ?? 4))); if ($lmax < $lmin) $e[] = 'Level "to" is below level "from".';
    [$qq, $opts, $c, $qe] = ind_question_check($q, (array)($in['opt'] ?? []), $in['ans'] ?? null); $e = array_merge($e, $qe);
    return [['sec' => $sec, 'tracks' => $tr, 'lvl_min' => $lmin, 'lvl_max' => $lmax, 'q' => $qq, 'opts' => json_encode($opts, JSON_UNESCAPED_UNICODE), 'ans' => $c, 'keep_order' => empty($in['keep_order']) ? 0 : 1], $e];
}

/* ---------------- settings and rights ---------------- */

function apt_settings(): array { $s = module_states()['aptitude']['settings'] ?? []; return array_replace(APT_DEFAULTS, array_intersect_key($s, APT_DEFAULTS)); }
function apt_settings_check(array $in, array $roles): array {
    $e = []; $o = [];
    foreach (['manage_roles', 'view_roles'] as $k) $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
    if (!$o['manage_roles']) $e[] = 'Pick at least one role that runs the test.';
    $o['unlock'] = isset(APT_UNLOCK[$in['unlock'] ?? '']) ? (string)$in['unlock'] : 'induction';
    $o['tracks'] = mb_substr(trim(str_replace("\r", '', strip_tags((string)($in['tracks'] ?? '')))), 0, 2000); $tr = apt_tracks($o); if (!$tr) $e[] = 'Give at least one job family as "key | name".';
    $o['levels'] = mb_substr(trim(str_replace("\r", '', strip_tags((string)($in['levels'] ?? '')))), 0, 400);
    $o['default_track'] = isset($tr[$in['default_track'] ?? '']) ? (string)$in['default_track'] : (string)array_key_first($tr ?: ['' => '']);
    $o['default_level'] = max(1, min(4, (int)($in['default_level'] ?? 1)));
    $o['min_items'] = max(1, min(100, (int)($in['min_items'] ?? 5)));
    for ($l = 1; $l <= 4; $l++) { $o['minutes'][$l] = max(5, min(180, (int)($in['minutes'][$l] ?? 30))); foreach (APT_SECTIONS as $s => $_) $o['blueprint'][$l][$s] = max(0, min(50, (int)($in['blueprint'][$l][$s] ?? 0))); if (!array_sum($o['blueprint'][$l])) $e[] = "Level $l needs at least one question."; }
    $b = []; foreach ((array)($in['band_name'] ?? []) as $i => $n) { $n = mb_substr(trim(strip_tags((string)$n)), 0, 20); if ($n !== '') $b[$n] = max(0, min(100, (int)($in['band_from'][$i] ?? 0))); } arsort($b); $o['bands'] = $b ?: APT_DEFAULTS['bands'];
    return [$o, $e];
}
function apt_can(array $u): bool { return has_role($u, ...apt_settings()['manage_roles']); }
function apt_can_view(array $u): bool { return apt_can($u) || has_role($u, ...apt_settings()['view_roles']); }
function apt_need(array $u): void { if (!apt_can($u)) throw new RuntimeException('Your role does not run the aptitude test.'); }
function apt_log(PDO $db, ?int $by, string $what, string $code = '', ?int $ref = null, string $note = ''): void { $db->prepare('INSERT INTO apt_log (by_user, what, emp_code, ref, note) VALUES (?,?,?,?,?)')->execute([$by, $what, $code, $ref, mb_substr($note, 0, 300)]); }

/* ---------------- bank ---------------- */

function apt_questions(PDO $db, string $sec = '', bool $off = false): array { $s = $db->prepare('SELECT * FROM apt_question WHERE active = ?' . (isset(APT_SECTIONS[$sec]) ? ' AND sec = ?' : '') . ' ORDER BY sec, lvl_min, id'); $s->execute(isset(APT_SECTIONS[$sec]) ? [$off ? 0 : 1, $sec] : [$off ? 0 : 1]); return $s->fetchAll(); }
function apt_q_add(PDO $db, array $u, array $in): int {
    apt_need($u); [$q, $e] = apt_q_check($in, apt_tracks()); if ($e) throw new RuntimeException(implode(' ', $e));
    $db->prepare('INSERT INTO apt_question (sec, tracks, lvl_min, lvl_max, q, opts, ans, keep_order, created_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([...array_values($q), (int)$u['id']]);
    $id = (int)$db->lastInsertId(); apt_log($db, (int)$u['id'], 'q_add', '', $id); return $id;
}
/** A question is never edited (papers keep it as given); it is switched off with a reason and a new one added. */
function apt_q_active(PDO $db, array $u, int $id, bool $on, string $why): void {
    apt_need($u); $why = mb_substr(trim(strip_tags($why)), 0, 300); if (!$on && $why === '') throw new RuntimeException('Write why the question is taken out.');
    $db->prepare('UPDATE apt_question SET active = ?, off_reason = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? '' : $why, $id]); apt_log($db, (int)$u['id'], $on ? 'q_on' : 'q_off', '', $id, $why);
}
/** How many active questions each section × level could give a family (for the coverage table). */
function apt_coverage(PDO $db): array {
    $tr = apt_tracks(); $o = []; $bank = apt_questions($db);
    foreach ($tr as $t => $_) for ($l = 1; $l <= 4; $l++) foreach (APT_SECTIONS as $s => $__) $o[$t][$l][$s] = count(array_filter($bank, fn($q) => $q['sec'] === $s && (int)$q['lvl_min'] <= $l && (int)$q['lvl_max'] >= $l && in_array($t, apt_tracks_of((string)$q['tracks'], $tr), true)));
    return $o;
}

/* ---------------- people and papers ---------------- */

function apt_person(PDO $db, string $code): ?array { $s = $db->prepare('SELECT * FROM apt_person WHERE emp_code = ?'); $s->execute([strtoupper($code)]); return $s->fetch() ?: null; }
/** HR sets a person's job family / level, invites them, or allows one re-test (a reason). Never their own. */
function apt_person_set(PDO $db, array $u, string $code, array $in): void {
    apt_need($u); $e = emp_by_code($db, strtoupper($code)); if (!$e || !emp_in_scope($u, $e)) throw new RuntimeException('Pick a person in your access.');
    if (strtoupper((string)($u['emp_code'] ?? '')) === strtoupper($e['code'])) throw new RuntimeException('Your own test is set by another HR person.');
    $old = apt_person($db, $e['code']) ?? ['track' => '', 'level' => null, 'invited' => 0, 'retest' => 0, 'retest_reason' => ''];
    $tr = apt_tracks(); $track = array_key_exists('track', $in) ? (isset($tr[$in['track']]) ? (string)$in['track'] : '') : $old['track'];
    $level = array_key_exists('level', $in) ? ((int)$in['level'] >= 1 && (int)$in['level'] <= 4 ? (int)$in['level'] : null) : $old['level'];
    $inv = array_key_exists('invited', $in) ? (empty($in['invited']) ? 0 : 1) : (int)$old['invited'];
    $re = (int)$old['retest']; $why = $old['retest_reason'];
    if (!empty($in['retest'])) { $why = mb_substr(trim(strip_tags((string)($in['retest_reason'] ?? ''))), 0, 300); if ($why === '') throw new RuntimeException('Write why a re-test is allowed.'); $re = 1; }
    $db->prepare('INSERT INTO apt_person (emp_code, track, level, invited, retest, retest_reason, set_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE track = VALUES(track), level = VALUES(level), invited = VALUES(invited), retest = VALUES(retest), retest_reason = VALUES(retest_reason), set_by = VALUES(set_by)')
       ->execute([strtoupper($e['code']), $track, $level, $inv, $re, $why, (int)$u['id']]);
    apt_log($db, (int)$u['id'], 'person', strtoupper($e['code']), null, !empty($in['retest']) ? 'retest: ' . $why : '');
}
/** Close papers whose time ran out (with the answers saved so far). */
function apt_expire(PDO $db): void { foreach ($db->query("SELECT id FROM apt_attempt WHERE status = 'open' AND deadline < DATE_SUB(NOW(), INTERVAL " . APT_GRACE_SEC . " SECOND)")->fetchAll(PDO::FETCH_COLUMN) as $id) apt_finalize($db, (int)$id, 'time'); }
function apt_open_of(PDO $db, string $code): ?array { $s = $db->prepare("SELECT * FROM apt_attempt WHERE emp_code = ? AND status = 'open'"); $s->execute([strtoupper($code)]); return $s->fetch() ?: null; }
/** The login's state and profile (job family, level). */
function apt_state(PDO $db, array $u): array {
    apt_expire($db); $code = strtoupper((string)($u['emp_code'] ?? '')); $S = apt_settings();
    if ($code === '') return ['state' => 'nocode', 'code' => ''];
    $p = apt_person($db, $code); $open = apt_open_of($db, $code);
    $s = $db->prepare("SELECT COUNT(*) FROM apt_attempt WHERE emp_code = ? AND status = 'done'"); $s->execute([$code]); $done = (int)$s->fetchColumn() > 0;
    $ind = module_on('induction') ? apt_induction_done(ind_path($db, $code)) : true;
    $tr = apt_tracks($S); $track = $p && isset($tr[$p['track']]) ? (string)$p['track'] : (string)$S['default_track']; if (!isset($tr[$track])) $track = (string)array_key_first($tr);
    return ['state' => apt_state_calc($code, $open, $done, $p, (string)$S['unlock'], $ind), 'code' => $code, 'open' => $open, 'track' => $track, 'level' => (int)($p['level'] ?? $S['default_level'])];
}
/** Start a paper (one open paper per person: a double tap returns the same one). */
function apt_start(PDO $db, array $u, ?callable $rand = null): int {
    $st = apt_state($db, $u); if ($st['state'] === 'open') return (int)$st['open']['id'];
    if ($st['state'] !== 'ready') throw new RuntimeException('The aptitude test is not open for you.');
    $S = apt_settings(); $code = $st['code'];
    $asked = []; $s = $db->prepare("SELECT items FROM apt_attempt WHERE emp_code = ?"); $s->execute([$code]); foreach ($s as $r) foreach (json_decode((string)$r['items'], true) ?: [] as $x) $asked[] = (int)$x['q'];
    $items = apt_items(apt_draw(apt_questions($db), $st['track'], $st['level'], $S['blueprint'][$st['level']] ?? [], apt_tracks($S), $asked, $rand), $rand);
    if (count($items) < (int)$S['min_items']) throw new RuntimeException('The question bank has too few questions for this paper yet. Please tell HR.');
    $min = (int)($S['minutes'][$st['level']] ?? 30);
    try { $db->prepare("INSERT INTO apt_attempt (emp_code, user_id, track, level, status, open_key, started_at, deadline, minutes, items, answers, total) VALUES (?,?,?,?, 'open', ?, NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, ?, '{}', ?)")
             ->execute([$code, (int)$u['id'], $st['track'], $st['level'], $code, $min, $min, json_encode($items, JSON_UNESCAPED_UNICODE), count($items)]); }
    catch (PDOException $e) { if ($e->getCode() !== '23000') throw $e; return (int)apt_open_of($db, $code)['id']; }
    $id = (int)$db->lastInsertId();
    $db->prepare('UPDATE apt_person SET retest = 0 WHERE emp_code = ?')->execute([$code]);
    apt_log($db, (int)$u['id'], 'start', $code, $id);
    return $id;
}
/** Save answers and close the paper (answers after the deadline + grace are not taken). */
function apt_submit(PDO $db, array $u, int $id, array $answers, int $leaves): void {
    $code = strtoupper((string)($u['emp_code'] ?? '')); $s = $db->prepare("SELECT * FROM apt_attempt WHERE id = ? AND status = 'open' AND emp_code = ?"); $s->execute([$id, $code]); $a = $s->fetch();
    if (!$a) return;
    if (strtotime((string)$a['deadline']) + APT_GRACE_SEC >= time()) {
        $items = json_decode((string)$a['items'], true) ?: [];
        $db->prepare("UPDATE apt_attempt SET answers = ?, leaves = ? WHERE id = ? AND status = 'open'")->execute([json_encode((object)apt_answers_clean($answers, $items)), max(0, min(999, $leaves)), $id]);
    }
    apt_finalize($db, $id, 'submitted');
}
function apt_finalize(PDO $db, int $id, string $ended): bool {
    $s = $db->prepare("SELECT * FROM apt_attempt WHERE id = ? AND status = 'open'"); $s->execute([$id]); $a = $s->fetch(); if (!$a) return false;
    [$score, $total, $sec] = apt_score(json_decode((string)$a['items'], true) ?: [], json_decode((string)$a['answers'], true) ?: []);
    $pct = $total ? round($score * 100 / $total, 1) : 0.0; $band = apt_band($pct, apt_settings()['bands']);
    $up = $db->prepare("UPDATE apt_attempt SET status = 'done', open_key = NULL, submitted_at = NOW(), ended = ?, score = ?, pct = ?, band = ?, sections = ? WHERE id = ? AND status = 'open'");
    $up->execute([$ended, $score, $pct, $band, json_encode($sec), $id]);
    if ($up->rowCount() !== 1) return false;
    apt_log($db, null, 'done', (string)$a['emp_code'], $id, $ended);
    apt_tell_hr($db, $a, $pct, $band);
    return true;
}
/** One email to the people who run the test (never the person taken it), when email is on. */
function apt_tell_hr(PDO $db, array $a, float $pct, string $band): void {
    if (!module_on('email') || !function_exists('mail_queue')) return;
    try { $e = emp_by_code($db, (string)$a['emp_code']); $roles = apt_settings()['manage_roles'];
        $s = $db->prepare('SELECT DISTINCT u.email, u.id, u.emp_code FROM users u JOIN user_role r ON r.user_id = u.id WHERE u.active = 1 AND r.role_code IN (' . implode(',', array_fill(0, count($roles), '?')) . ')'); $s->execute($roles);
        foreach ($s as $r) if (filter_var($r['email'], FILTER_VALIDATE_EMAIL) && strtoupper((string)$r['emp_code']) !== strtoupper((string)$a['emp_code']) && (int)$r['id'] !== (int)$a['user_id'])
            mail_queue($db, ['to' => $r['email'], 'subject' => 'Aptitude test result: ' . ($e['name'] ?? $a['emp_code']), 'text' => ($e['name'] ?? $a['emp_code']) . ' finished the aptitude test: ' . $pct . '% (' . $band . ').'], 'notice', 'apt:' . $a['id']);
    } catch (Throwable $ex) {}
}
/** Results HR may see: in scope, never the viewer's own. */
function apt_results(PDO $db, array $u, bool $void = false): array {
    apt_expire($db); $me = strtoupper((string)($u['emp_code'] ?? ''));
    $rows = $db->query("SELECT a.id, a.emp_code, a.track, a.level, a.status, a.started_at, a.submitted_at, a.ended, a.score, a.total, a.pct, a.band, a.sections, a.leaves, a.hr_note, a.void_reason, e.name, e.department, e.branch_id FROM apt_attempt a LEFT JOIN employee e ON e.code = a.emp_code WHERE a.status " . ($void ? "= 'void'" : "IN ('done','open')") . ' ORDER BY a.id DESC LIMIT 500')->fetchAll();
    return array_values(array_filter($rows, fn($r) => strtoupper($r['emp_code']) !== $me && emp_in_scope($u, ['branch_id' => $r['branch_id']])));
}
function apt_result(PDO $db, array $u, int $id): ?array { foreach (array_merge(apt_results($db, $u), apt_results($db, $u, true)) as $r) if ((int)$r['id'] === $id) { $s = $db->prepare('SELECT items, answers FROM apt_attempt WHERE id = ?'); $s->execute([$id]); return $r + $s->fetch(); } return null; }
function apt_note(PDO $db, array $u, int $id, string $note): void { apt_need($u); if (!apt_result($db, $u, $id)) throw new RuntimeException('Paper not found.'); $db->prepare('UPDATE apt_attempt SET hr_note = ? WHERE id = ?')->execute([mb_substr(trim(strip_tags($note)), 0, 600), $id]); apt_log($db, (int)$u['id'], 'note', '', $id); }
function apt_void(PDO $db, array $u, int $id, string $why): void {
    apt_need($u); $r = apt_result($db, $u, $id); if (!$r || $r['status'] !== 'done') throw new RuntimeException('Only a finished paper is voided.');
    if (($why = trim(strip_tags($why))) === '') throw new RuntimeException('Write why the paper is voided.');
    $db->prepare("UPDATE apt_attempt SET status = 'void', void_by = ?, void_reason = ? WHERE id = ?")->execute([(int)$u['id'], mb_substr($why, 0, 300), $id]); apt_log($db, (int)$u['id'], 'void', (string)$r['emp_code'], $id, $why);
}
