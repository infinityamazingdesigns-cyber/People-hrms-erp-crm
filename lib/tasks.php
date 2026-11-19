<?php
/**
 * Tasks — ported from the reference build as rules.
 *  - Every login keeps its own tasks and gives tasks to colleagues (who may be given to is a setting: anyone, or the
 *    people below on the reporting lines). A task given to several people becomes one copy each.
 *  - A login sees only what it created or was given; managers also see the tasks of people below them on the org chart;
 *    the lead roles (setting) see everyone's. Nobody else's list, HR included.
 *  - A given task is not finished by the person's tick: it goes back to its giver, who approves it or sends it back for
 *    a redo / correction with a note (any number of rounds). A task from a colleague (not above on the reporting lines,
 *    not a lead role) may be declined with a reason — it goes back to the giver.
 *  - Reminders at the time / before / the morning of the day, once or again till done (hourly ones wait out the night);
 *    repeats every day / working day / week / month / year make the next copy when one is finished. Reminders become
 *    notices on the page and, when Email is on, an email.
 *  - Nothing is deleted: removed tasks keep their row; task_log is append-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/orgchart.php';

const TK_PRIOS = [1 => 'High', 2 => 'Medium', 3 => 'Low'];
const TK_REMINDS = ['' => 'Off', 'at' => 'At the time', '15m' => '15 min before', '1h' => '1 hour before', '1d' => '1 day before', 'am' => 'Morning of the day'];
const TK_REPEATS = ['' => 'Does not repeat', 'daily' => 'Every day', 'workday' => 'Every working day', 'weekly' => 'Every week', 'monthly' => 'Every month', 'yearly' => 'Every year'];
const TK_NAGS = ['' => 'Once', '1h' => 'Every hour till done', '3h' => 'Every 3 hours till done', '1d' => 'Every day till done', '1w' => 'Every week till done'];
const TK_NAG_SECS = ['1h' => 3600, '3h' => 10800, '1d' => 86400, '1w' => 604800];
const TK_REVIEW = ['approve' => 'Approved', 'redo' => 'Sent back for redo', 'correct' => 'Correction asked'];
const TK_GROUP_MAX = 100;
const TK_LATE = 12 * 3600;   // a reminder more than 12 h late is dropped quietly
const TK_DEFAULTS = ['give' => 'all', 'lead_roles' => ['SUPERADMIN', 'CEO'], 'morning' => 9, 'quiet_from' => 21, 'workdays' => [1, 2, 3, 4, 5, 6], 'email' => false];

/* ---------------- settings ---------------- */

function tk_settings(): array {
    $s = module_states()['tasks']['settings'] ?? [];
    return array_replace(TK_DEFAULTS, array_intersect_key($s, TK_DEFAULTS));
}

function tk_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    $o['give'] = in_array($in['give'] ?? '', ['all', 'team'], true) ? $in['give'] : 'all';
    $o['lead_roles'] = array_values(array_intersect(array_keys($roles), (array)($in['lead_roles'] ?? [])));
    foreach (['morning' => [5, 12], 'quiet_from' => [17, 23]] as $k => [$lo, $hi]) {
        $v = filter_var($in[$k] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $lo || $v > $hi) $e[] = "$k: an hour from $lo to $hi."; else $o[$k] = $v;
    }
    $o['workdays'] = array_values(array_unique(array_filter(array_map('intval', (array)($in['workdays'] ?? [])), fn($d) => $d >= 0 && $d <= 6)));
    sort($o['workdays']);
    if (!$o['workdays']) $e[] = 'Pick at least one working day.';
    $o['email'] = !empty($in['email']);
    return [$o, $e];
}

/* ---------------- pure rules ---------------- */

function tk_line(string $s, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? ''), 0, $max); }

function tk_date(?string $d): ?string {
    $d = trim((string)$d);
    if ($d === '') return null;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) throw new InvalidArgumentException('That date is not right.');
    return $d;
}

function tk_time(?string $t): ?string {
    $t = trim((string)$t);
    if ($t === '') return null;
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:00)?$/', $t, $m)) throw new InvalidArgumentException('That time is not right.');
    return "{$m[1]}:{$m[2]}:00";
}

/** When the reminder goes ('Y-m-d H:i:s') or null. A date without a time counts as the morning hour. Pure. */
function tk_remind_when(string $r, ?string $date, ?string $time, int $morning = 9): ?string {
    if ($r === '' || $date === null) return null;
    $am = sprintf('%02d:00:00', $morning);
    if ($r === 'am') return $date . ' ' . $am;
    $at = strtotime($date . ' ' . ($time ?? $am));
    $at -= ['at' => 0, '15m' => 900, '1h' => 3600, '1d' => 86400][$r] ?? 0;
    return date('Y-m-d H:i:s', $at);
}

/** The next due date of a repeating task, never before $today; monthly / yearly keep the first day ($day). Pure. */
function tk_rep_next(string $rep, string $due, ?int $day, string $today, array $workdays = [1, 2, 3, 4, 5, 6]): string {
    $d = new DateTimeImmutable($due);
    $day = $day ?: (int)$d->format('j');
    $i = 0;
    do {
        if ($rep === 'daily') $d = $d->modify('+1 day');
        elseif ($rep === 'workday') { $j = 0; do { $d = $d->modify('+1 day'); } while (!in_array((int)$d->format('w'), $workdays, true) && ++$j < 7); }
        elseif ($rep === 'weekly') $d = $d->modify('+7 days');
        else {
            $y = (int)$d->format('Y'); $m = (int)$d->format('n');
            if ($rep === 'monthly') { $m++; if ($m > 12) { $m = 1; $y++; } } else $y++;
            $last = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
            $d = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, min($day, $last)));
        }
    } while ($d->format('Y-m-d') < $today && ++$i < 2000);
    return $d->format('Y-m-d');
}

/** When a reminder that repeats till done comes again: after $now; hourly ones wait out the night. Pure. */
function tk_nag_next(string $nag, int $from, int $now, int $quietFrom = 21, int $morning = 9): int {
    $step = TK_NAG_SECS[$nag] ?? 86400;
    $t = $from + $step;
    if ($t <= $now) $t += (int)ceil(($now - $t + 1) / $step) * $step;
    if ($step < 86400) {
        $h = (int)date('G', $t);
        if ($h >= $quietFrom || $h < $morning) $t = (int)strtotime(date('Y-m-d', $h >= $quietFrom ? $t + 86400 : $t) . sprintf(' %02d:00:00', $morning));
    }
    return $t;
}

/** A task from a form. Pure. Returns the columns to store (without people). */
function tk_input(array $in, int $morning = 9): array {
    $title = tk_line((string)($in['title'] ?? ''), 200);
    if ($title === '') throw new InvalidArgumentException('Say what the task is.');
    $p = (int)($in['prio'] ?? 2); if (!isset(TK_PRIOS[$p])) throw new InvalidArgumentException('Pick High, Medium or Low.');
    $date = tk_date($in['due_date'] ?? ''); $time = tk_time($in['due_time'] ?? '');
    if ($time !== null && $date === null) throw new InvalidArgumentException('Give a date with the time.');
    $r = (string)($in['remind'] ?? '');
    if ($r === 'auto') $r = $date === null ? '' : ($time === null ? 'am' : '15m');
    if (!isset(TK_REMINDS[$r])) throw new InvalidArgumentException('Pick when to be reminded.');
    if ($r !== '' && $date === null) throw new InvalidArgumentException('Give a date to be reminded.');
    $rep = (string)($in['rep'] ?? ''); if (!isset(TK_REPEATS[$rep])) throw new InvalidArgumentException('Pick how often it repeats.');
    if ($rep !== '' && $date === null) throw new InvalidArgumentException('Give a date for a repeating task.');
    $nag = (string)($in['nag'] ?? ''); if (!isset(TK_NAGS[$nag])) throw new InvalidArgumentException('Pick how often to remind.');
    return ['title' => $title, 'notes' => mb_substr(trim(strip_tags((string)($in['notes'] ?? ''))), 0, 2000), 'label' => tk_line((string)($in['label'] ?? ''), 30), 'prio' => $p,
            'due_date' => $date, 'due_time' => $time, 'remind' => $r, 'remind_at' => tk_remind_when($r, $date, $time, $morning), 'rep' => $rep,
            'rep_day' => $rep !== '' && $date !== null ? (int)substr($date, 8, 2) : null, 'nag' => $r === '' ? '' : $nag];
}

/** Where a task stands for the screen. Pure. */
function tk_state(array $t, string $today): string {
    if ($t['removed_at']) return 'removed';
    if ($t['done_at']) return 'done';
    if ($t['review'] === 'waiting') return 'waiting';
    if ($t['due_date'] && $t['due_date'] < $today) return 'late';
    if ($t['due_date'] === $today) return 'today';
    return 'open';
}

/** Ticking a task: own → done; given → sent to the giver for approval. Pure: the new review / done state. */
function tk_tick(array $t, int $me): array {
    if ((int)$t['owner_id'] === (int)$t['assignee_id'] || (int)$t['owner_id'] === $me) return ['done' => true, 'review' => (int)$t['owner_id'] === (int)$t['assignee_id'] ? '' : 'approved'];
    return ['done' => false, 'review' => 'waiting'];
}

/* ---------------- database ---------------- */

function tk_log(PDO $db, int $id, int $round, string $act, int $by, string $note = ''): void {
    $db->prepare('INSERT INTO task_log (task_id, round, act, by_id, note) VALUES (?,?,?,?,?)')->execute([$id, $round, $act, $by, mb_substr($note, 0, 500)]);
}

function tk_notice(PDO $db, int $uid, int $taskId, string $title): void {
    $db->prepare('INSERT INTO task_notice (user_id, task_id, title) VALUES (?,?,?)')->execute([$uid, $taskId, mb_substr($title, 0, 160)]);
    if (module_on('notify')) { require_once __DIR__ . '/push.php'; notify_user($db, $uid, 'task', $title, '', 'modules/tasks/'); }
}

function tk_get(PDO $db, int $id): ?array { $q = $db->prepare('SELECT * FROM task WHERE id = ?'); $q->execute([$id]); return $q->fetch() ?: null; }

function tk_names(PDO $db, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (!$ids) return [];
    $q = $db->prepare('SELECT id, name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute($ids);
    return array_column($q->fetchAll(), 'name', 'id');
}

/** Login ids below this login on the reporting lines (by linked employee codes). */
function tk_team(PDO $db, array $u): array {
    $code = (string)($u['emp_code'] ?? ''); if ($code === '') return [];
    $people = $db->query('SELECT code, manager_code FROM employee WHERE active = 1')->fetchAll();
    $below = org_team($code, org_parent_map($people));
    if (!$below) return [];
    $q = $db->prepare('SELECT id FROM users WHERE active = 1 AND emp_code IN (' . implode(',', array_fill(0, count($below), '?')) . ')'); $q->execute(array_values($below));
    return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}

function tk_lead(array $u, array $set): bool { return (bool)$set['lead_roles'] && has_role($u, ...$set['lead_roles']); }

/** Logins this login may give tasks to: active, not itself. */
function tk_people(PDO $db, array $u, array $set): array {
    $rows = $db->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll();
    $team = $set['give'] === 'team' && !tk_lead($u, $set) ? array_flip(tk_team($db, $u)) : null;
    return array_values(array_filter($rows, fn($r) => (int)$r['id'] !== (int)$u['id'] && ($team === null || isset($team[(int)$r['id']]))));
}

function tk_can_see(PDO $db, array $u, array $t, array $set): bool {
    $me = (int)$u['id'];
    if ((int)$t['owner_id'] === $me || (int)$t['assignee_id'] === $me || tk_lead($u, $set)) return true;
    $team = array_flip(tk_team($db, $u));
    return isset($team[(int)$t['assignee_id']]);
}

/** Add a task for oneself or one or more people; one copy each. Returns the ids. */
function tk_add(PDO $db, array $u, array $in): array {
    $set = tk_settings(); $me = (int)$u['id'];
    $f = tk_input($in, (int)$set['morning']);
    $to = array_values(array_unique(array_map('intval', (array)($in['to'] ?? []))));
    if (!$to) $to = [$me];
    if (count($to) > TK_GROUP_MAX) throw new InvalidArgumentException('At most ' . TK_GROUP_MAX . ' people at once.');
    $ok = array_flip(array_map(fn($r) => (int)$r['id'], tk_people($db, $u, $set))) + [$me => true];
    foreach ($to as $t) if (!isset($ok[$t])) throw new RuntimeException('You cannot give a task to that person.');
    $team = array_flip(tk_team($db, $u)); $lead = tk_lead($u, $set);
    $ids = []; $grp = null;
    foreach ($to as $t) {
        $peer = $t !== $me && !$lead && !isset($team[$t]) ? 1 : 0;
        $cols = $f + ['owner_id' => $me, 'assignee_id' => $t, 'grp' => $grp, 'peer' => $peer];
        $db->prepare('INSERT INTO task (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
        $id = (int)$db->lastInsertId();
        if (count($to) > 1 && $grp === null) { $grp = $id; $db->prepare('UPDATE task SET grp = ? WHERE id = ?')->execute([$id, $id]); }
        tk_log($db, $id, 0, 'added', $me);
        if ($t !== $me) { tk_notice($db, $t, $id, 'New task from ' . ($u['name'] ?? 'a colleague') . ': ' . $f['title']); audit($me, 'task_give', '#' . $id); }
        $ids[] = $id;
    }
    return $ids;
}

/** The creator changes a task (not once done). */
function tk_edit(PDO $db, array $u, int $id, array $in): void {
    $t = tk_get($db, $id);
    if (!$t || (int)$t['owner_id'] !== (int)$u['id'] || $t['removed_at']) throw new RuntimeException('Only the person who created the task changes it.');
    if ($t['done_at']) throw new RuntimeException('A finished task stays as it is.');
    $f = tk_input($in, (int)tk_settings()['morning']);
    $f['reminded_at'] = null;
    $db->prepare('UPDATE task SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $id]);
    tk_log($db, $id, (int)$t['review_round'], 'changed', (int)$u['id']);
    if ((int)$t['assignee_id'] !== (int)$u['id']) tk_notice($db, (int)$t['assignee_id'], $id, 'Task changed: ' . $f['title']);
}

/** Tick a task: own → done (and the next repeat); given → to the giver for approval. Reopen own unapproved tasks. */
function tk_done(PDO $db, array $u, int $id, bool $done, string $reply = ''): void {
    $t = tk_get($db, $id); $me = (int)$u['id'];
    if (!$t || $t['removed_at'] || ((int)$t['owner_id'] !== $me && (int)$t['assignee_id'] !== $me)) throw new RuntimeException('That task is not on your list.');
    $reply = tk_line($reply, 300);
    if (!$done) {
        if (!$t['done_at'] && $t['review'] !== 'waiting') return;
        if ((int)$t['owner_id'] !== (int)$t['assignee_id'] && (int)$t['owner_id'] !== $me) {
            if ($t['done_at']) throw new RuntimeException('An approved task stays done.');
            $db->prepare("UPDATE task SET review = '', submitted_at = NULL WHERE id = ?")->execute([$id]);
            tk_log($db, $id, (int)$t['review_round'], 'withdrawn', $me); return;
        }
        $db->prepare("UPDATE task SET done_at = NULL, done_by = NULL, review = '' WHERE id = ?")->execute([$id]);
        tk_log($db, $id, (int)$t['review_round'], 'reopened', $me); return;
    }
    if ($t['done_at']) return;
    $x = tk_tick($t, $me);
    if ($x['done']) {
        $db->prepare('UPDATE task SET done_at = NOW(), done_by = ?, review = ?, reply = IF(? = \'\', reply, ?) WHERE id = ?')->execute([$me, $x['review'], $reply, $reply, $id]);
        tk_log($db, $id, (int)$t['review_round'], 'done', $me, $reply);
        tk_repeat_after($db, $id);
    } else {
        if ($t['review'] === 'waiting') return;
        $round = (int)$t['review_round'] + 1;
        $db->prepare("UPDATE task SET review = 'waiting', review_round = ?, submitted_at = NOW(), reply = IF(? = '', reply, ?) WHERE id = ?")->execute([$round, $reply, $reply, $id]);
        tk_log($db, $id, $round, 'submitted', $me, $reply);
        tk_notice($db, (int)$t['owner_id'], $id, 'To approve: ' . $t['title']);
    }
}

/** The giver approves, or sends back for a redo / correction with a note. */
function tk_review(PDO $db, array $u, int $id, string $act, string $note = ''): void {
    $t = tk_get($db, $id); $me = (int)$u['id'];
    if (!$t || (int)$t['owner_id'] !== $me) throw new RuntimeException('Only the person who gave the task approves it.');
    if (!isset(TK_REVIEW[$act])) throw new InvalidArgumentException('Approve, redo or correction.');
    if ($t['review'] !== 'waiting') throw new RuntimeException('It has not been sent for approval.');
    $note = tk_line($note, 500);
    if ($act !== 'approve' && $note === '') throw new InvalidArgumentException('Say what to redo or correct.');
    if ($act === 'approve') $db->prepare("UPDATE task SET review = 'approved', review_note = ?, done_at = NOW(), done_by = ? WHERE id = ?")->execute([$note, (int)$t['assignee_id'], $id]);
    else $db->prepare('UPDATE task SET review = ?, review_note = ?, submitted_at = NULL WHERE id = ?')->execute([$act, $note, $id]);
    tk_log($db, $id, (int)$t['review_round'], $act, $me, $note);
    tk_notice($db, (int)$t['assignee_id'], $id, TK_REVIEW[$act] . ': ' . $t['title']);
    if ($act === 'approve') tk_repeat_after($db, $id);
}

/** The person declines a colleague's task with a reason: it goes back to the giver as their own task. */
function tk_decline(PDO $db, array $u, int $id, string $why): void {
    $t = tk_get($db, $id); $me = (int)$u['id'];
    if (!$t || (int)$t['assignee_id'] !== $me || (int)$t['owner_id'] === $me || $t['done_at'] || $t['removed_at']) throw new RuntimeException('Not found.');
    if (!(int)$t['peer']) throw new RuntimeException('A task from your manager is not declined — ask for more time instead.');
    if ($t['review'] === 'waiting') throw new RuntimeException('It is waiting for approval.');
    $why = tk_line($why, 300); if (mb_strlen($why) < 3) throw new InvalidArgumentException('Say why you are declining it.');
    $db->prepare("UPDATE task SET assignee_id = owner_id, peer = 0, review = '' WHERE id = ?")->execute([$id]);
    tk_log($db, $id, (int)$t['review_round'], 'declined', $me, $why);
    tk_notice($db, (int)$t['owner_id'], $id, 'Declined: ' . $t['title'] . ' — ' . $why);
}

function tk_remove(PDO $db, array $u, int $id): void {
    $t = tk_get($db, $id);
    if (!$t || (int)$t['owner_id'] !== (int)$u['id']) throw new RuntimeException('Only the person who created the task removes it.');
    $db->prepare('UPDATE task SET removed_at = NOW() WHERE id = ? AND removed_at IS NULL')->execute([$id]);
    tk_log($db, $id, (int)$t['review_round'], 'removed', (int)$u['id']);
}

/** A finished repeating task makes the next copy once. */
function tk_repeat_after(PDO $db, int $id): ?int {
    $t = tk_get($db, $id);
    if (!$t || $t['rep'] === '' || !$t['due_date'] || !$t['done_at'] || $t['next_id'] !== null) return null;
    $c = $db->prepare('UPDATE task SET next_id = 0 WHERE id = ? AND next_id IS NULL'); $c->execute([$id]);
    if ($c->rowCount() !== 1) return null;
    $set = tk_settings();
    $due = tk_rep_next($t['rep'], $t['due_date'], $t['rep_day'] !== null ? (int)$t['rep_day'] : null, date('Y-m-d'), $set['workdays']);
    $time = $t['due_time'];
    $cols = ['owner_id' => $t['owner_id'], 'assignee_id' => $t['assignee_id'], 'grp' => $t['grp'], 'title' => $t['title'], 'notes' => $t['notes'], 'label' => $t['label'], 'prio' => $t['prio'],
             'due_date' => $due, 'due_time' => $time, 'remind' => $t['remind'], 'remind_at' => tk_remind_when((string)$t['remind'], $due, $time, (int)$set['morning']), 'rep' => $t['rep'], 'rep_day' => $t['rep_day'], 'nag' => $t['nag'], 'peer' => $t['peer']];
    $db->prepare('INSERT INTO task (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    $nid = (int)$db->lastInsertId();
    $db->prepare('UPDATE task SET next_id = ? WHERE id = ?')->execute([$nid, $id]);
    tk_log($db, $id, (int)$t['review_round'], 'repeat', (int)$t['owner_id'], 'Next: ' . $due);
    return $nid;
}

/** Send due reminders as notices (and emails when set). Returns how many went. Safe to run often. */
function tk_remind_tick(PDO $db, ?int $now = null): int {
    $set = tk_settings(); $now ??= time(); $nowS = date('Y-m-d H:i:s', $now);
    $q = $db->prepare("SELECT t.*, u.email, u.active AS user_active FROM task t JOIN users u ON u.id = t.assignee_id WHERE t.remind_at IS NOT NULL AND t.remind_at <= ? AND t.reminded_at IS NULL
                       AND t.done_at IS NULL AND t.removed_at IS NULL AND t.review <> 'waiting' ORDER BY t.remind_at LIMIT 200");
    $q->execute([$nowS]);
    $claim = $db->prepare('UPDATE task SET reminded_at = ? WHERE id = ? AND reminded_at IS NULL');
    $again = $db->prepare('UPDATE task SET remind_at = ?, reminded_at = NULL WHERE id = ? AND reminded_at = ?');
    $n = 0;
    foreach ($q->fetchAll() as $t) {
        $claim->execute([$nowS, $t['id']]); if ($claim->rowCount() !== 1) continue;
        $at = (int)strtotime((string)$t['remind_at']);
        if ($t['nag'] !== '' && isset(TK_NAG_SECS[$t['nag']])) $again->execute([date('Y-m-d H:i:s', tk_nag_next($t['nag'], $at, $now, (int)$set['quiet_from'], (int)$set['morning'])), $t['id'], $nowS]);
        if ($now - $at > TK_LATE || !(int)$t['user_active']) continue;
        $late = $t['due_date'] && strtotime($t['due_date'] . ' ' . ($t['due_time'] ?? '23:59:59')) < $now;
        $title = ($late ? 'Still pending · overdue' : 'Reminder') . ((int)$t['prio'] === 1 ? ' · High' : '') . ': ' . $t['title'];
        tk_notice($db, (int)$t['assignee_id'], (int)$t['id'], $title);
        if ($set['email'] && $t['email'] !== '' && module_on('email')) {
            try {
                require_once __DIR__ . '/mail.php';
                mail_queue($db, ['to' => (string)$t['email'], 'subject' => $title, 'text' => $title . ($t['due_date'] ? "\nDue " . $t['due_date'] . ($t['due_time'] ? ' ' . substr($t['due_time'], 0, 5) : '') : '')], 'notice', 'task#' . (int)$t['id']);
            } catch (Throwable $e) { error_log('task mail: ' . $e->getMessage()); }
        }
        $n++;
    }
    return $n;
}

/** Lists for the page: mine (given to me or made by me for myself), given by me to others, to approve, my team. */
function tk_lists(PDO $db, array $u): array {
    $set = tk_settings(); $me = (int)$u['id'];
    $q = $db->prepare('SELECT * FROM task WHERE removed_at IS NULL AND (assignee_id = ? OR owner_id = ?) AND (done_at IS NULL OR done_at > NOW() - INTERVAL 30 DAY) ORDER BY done_at IS NOT NULL, prio, due_date IS NULL, due_date, due_time, id');
    $q->execute([$me, $me]);
    $rows = $q->fetchAll();
    $o = ['mine' => [], 'given' => [], 'approve' => [], 'team' => []];
    foreach ($rows as $t) {
        if ((int)$t['assignee_id'] === $me) $o['mine'][] = $t;
        elseif ((int)$t['owner_id'] === $me) { $o['given'][] = $t; if ($t['review'] === 'waiting') $o['approve'][] = $t; }
    }
    $ids = tk_lead($u, $set) ? null : tk_team($db, $u);
    if ($ids === null || $ids) {
        $sql = 'SELECT * FROM task WHERE removed_at IS NULL AND assignee_id <> ? AND (done_at IS NULL OR done_at > NOW() - INTERVAL 30 DAY)' . ($ids ? ' AND assignee_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '') . ' ORDER BY assignee_id, done_at IS NOT NULL, due_date LIMIT 1000';
        $s = $db->prepare($sql); $s->execute([$me]);
        $o['team'] = $s->fetchAll();
    }
    return $o;
}

/** A task as a calendar file. Pure. */
function tk_ics(array $t, string $tz, string $host): string {
    $start = $t['due_time'] ? str_replace([':', '-'], '', $t['due_date'] . 'T' . $t['due_time']) : str_replace('-', '', (string)$t['due_date']);
    $esc = fn(string $s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//WorkNest HR//Tasks//EN', 'BEGIN:VEVENT', 'UID:task-' . (int)$t['id'] . '@' . $host, 'DTSTAMP:' . gmdate('Ymd\THis\Z'),
              $t['due_time'] ? 'DTSTART;TZID=' . $tz . ':' . $start : 'DTSTART;VALUE=DATE:' . $start, 'SUMMARY:' . $esc((string)$t['title'])];
    if (!empty($t['notes'])) $lines[] = 'DESCRIPTION:' . $esc((string)$t['notes']);
    $lines = array_merge($lines, ['BEGIN:VALARM', 'TRIGGER:-PT15M', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc((string)$t['title']), 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR']);
    return implode("\r\n", $lines) . "\r\n";
}
