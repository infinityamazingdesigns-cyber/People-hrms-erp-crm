<?php
/**
 * Probation — check-ins during probation, the reporting manager's review before the end date, HR's decision.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - Who is on probation: active employees with a joining date (on or after "track from", when set) whose probation is
 *    not decided. Probation end = the confirmation date on the employee master when set, else joining date + months.
 *  - Check-ins at the months set (default 1 and 2): On track / Needs support / Concern, a note, agreed actions (needed
 *    unless on track). They open some days before they fall due.
 *  - The final review opens by itself some days before the end (or HR opens it earlier). Reporting manager (login linked
 *    to the manager's code) fills it in: ratings 1–5, strengths, to improve, training needed, salary revision (yes / no,
 *    suggested %, from — never a pay figure), role change, recommendation. No manager login → HR fills it in.
 *  - HR decides Confirm (date) / Extend (new end, after the old one) / Do not confirm; a decision different from the
 *    recommendation needs a note. The date goes to the employee master's confirmation date (logged there).
 *    An extension starts a new round. HR may hand a review back to the manager with a note.
 *  - Nobody reviews, checks in on or decides their own probation.
 *  - Rating areas and training needs are settings.
 */
declare(strict_types=1);
require_once __DIR__ . '/att_requests.php';

const PR_DEFAULTS = ['months' => 3, 'open_days' => 15, 'checkins' => [1, 2], 'check_early' => 7, 'track_from' => '',
    'ratings' => ['Work against goals', 'Job knowledge and learning', 'Quality and ownership', 'Attendance and punctuality', 'Teamwork and conduct', 'Communication'],
    'training' => ['Product and technical knowledge', 'Safety', 'Software and tools', 'Company processes', 'Customer handling', 'Communication', 'Leadership']];
const PR_SCALE = [1 => 'Poor', 2 => 'Needs improvement', 3 => 'Meets expectations', 4 => 'Good', 5 => 'Excellent'];
const PR_CHECK_STATUS = ['on_track' => 'On track', 'support' => 'Needs support', 'concern' => 'Concern'];
const PR_RECO = ['confirm' => 'Confirm', 'extend' => 'Extend probation', 'not_confirm' => 'Do not confirm'];
const PR_STATUS = ['manager' => 'With reporting manager', 'hr' => 'Waiting for HR', 'done' => 'Decided'];
const PR_HR_ROLES = ['SUPERADMIN', 'HRMANAGER', 'HROPS'];
const PR_AUTO_LATE = 30;   // a review still opens by itself up to this many days after the end

/* ---------------- settings ---------------- */

function pr_settings(): array {
    $s = module_states()['probation']['settings'] ?? [];
    return array_replace(PR_DEFAULTS, array_intersect_key($s, PR_DEFAULTS));
}

/** Pure. Returns [settings, errors]. Lists come as text, one per line. */
function pr_settings_check(array $in): array {
    $o = []; $e = [];
    foreach (['months' => [1, 24], 'open_days' => [0, 90], 'check_early' => [0, 30]] as $k => [$lo, $hi]) {
        $v = filter_var($in[$k] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $lo || $v > $hi) $e[] = "$k must be between $lo and $hi."; else $o[$k] = $v;
    }
    $c = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', (string)($in['checkins'] ?? ''))), fn($m) => $m > 0)));
    sort($c);
    if (isset($o['months']) && $c && max($c) >= $o['months']) $e[] = 'Check-ins must fall before the probation ends.';
    $o['checkins'] = $c;
    $t = trim((string)($in['track_from'] ?? ''));
    if ($t !== '' && emp_date($t) === 'bad') $e[] = 'Track from: not a date.';
    $o['track_from'] = $t === '' ? '' : (string)emp_date($t);
    foreach (['ratings' => 12, 'training' => 20] as $k => $max) {
        $l = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim(strip_tags($x)), 0, 60), preg_split('/\R/', (string)($in[$k] ?? ''))))));
        if (!$l && $k === 'ratings') $e[] = 'Give at least one rating area.';
        if (count($l) > $max) $e[] = "At most $max $k lines.";
        $o[$k] = $l;
    }
    return [$o, $e];
}

/* ---------------- pure ---------------- */

/** Probation end: the confirmation date when set, else joining + months. */
function pr_end(array $emp, int $months): string {
    if (!empty($emp['confirm_date'])) return (string)$emp['confirm_date'];
    return $emp['doj'] ? (new DateTimeImmutable((string)$emp['doj']))->modify("+$months months")->format('Y-m-d') : '';
}

function pr_checkin_due(array $emp, int $month): string {
    return $emp['doj'] ? (new DateTimeImmutable((string)$emp['doj']))->modify("+$month months")->format('Y-m-d') : '';
}

/** Is a date open yet (from N days before it)? */
function pr_open_by(string $due, int $early, string $today): bool {
    return $due !== '' && date('Y-m-d', strtotime("$due -$early days")) <= $today;
}

/** Check the manager's review. $areas = rating area labels. Pure. */
function pr_review_check(array $in, array $areas, array $training): array {
    $rat = [];
    foreach ($areas as $i => $label) {
        $v = (int)($in['rate'][$i] ?? 0);
        if ($v < 1 || $v > 5) throw new InvalidArgumentException("Give a rating for \"$label\".");
        $rat[$label] = $v;
    }
    $tr = array_values(array_intersect($training, (array)($in['training'] ?? [])));
    $trNote = mb_substr(trim(strip_tags((string)($in['training_note'] ?? ''))), 0, 1000);
    $sal = !empty($in['salary_rev']); $pct = null; $from = null;
    if ($sal) {
        $raw = trim((string)($in['salary_pct'] ?? ''));
        if ($raw !== '' && (!is_numeric($raw) || (float)$raw <= 0 || (float)$raw > 100)) throw new InvalidArgumentException('Suggested increase: a % between 0 and 100.');
        $pct = $raw === '' ? null : round((float)$raw, 2);
        $f = emp_date((string)($in['salary_from'] ?? ''));
        if ($f === 'bad') throw new InvalidArgumentException('Salary revision from: not a date.');
        $from = $f;
    }
    $role = !empty($in['role_change']);
    $roleNote = mb_substr(trim(strip_tags((string)($in['role_note'] ?? ''))), 0, 500);
    if ($role && $roleNote === '') throw new InvalidArgumentException('Say what the role change is.');
    $reco = (string)($in['reco'] ?? '');
    if (!isset(PR_RECO[$reco])) throw new InvalidArgumentException('Choose your recommendation.');
    $comment = mb_substr(trim(strip_tags((string)($in['comment'] ?? ''))), 0, 1500);
    if ($reco !== 'confirm' && $comment === '') throw new InvalidArgumentException('Give the reason for your recommendation.');
    $txt = fn($k) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, 1000);
    return ['ratings' => json_encode($rat), 'strengths' => $txt('strengths'), 'improve' => $txt('improve'), 'training' => json_encode($tr), 'training_note' => $trNote,
            'salary_rev' => $sal ? 1 : 0, 'salary_pct' => $pct, 'salary_from' => $from, 'role_change' => $role ? 1 : 0, 'role_note' => $role ? $roleNote : '',
            'reco' => $reco, 'manager_comment' => $comment];
}

/** Check HR's decision against the review. Pure. Returns [decision, date, note]. */
function pr_decision_check(array $in, array $r, ?string $doj): array {
    $dec = (string)($in['decision'] ?? '');
    if (!isset(PR_RECO[$dec])) throw new InvalidArgumentException('Choose the decision.');
    $date = emp_date((string)($in['date'] ?? ''));
    if ($date === 'bad') throw new InvalidArgumentException('Not a date.');
    if ($dec !== 'not_confirm' && !$date) throw new InvalidArgumentException($dec === 'extend' ? 'Give the new probation end.' : 'Give the confirmation date.');
    if ($date && $doj && $date < $doj) throw new InvalidArgumentException('The date cannot be before the joining date.');
    if ($dec === 'extend' && $r['due_date'] && $date <= $r['due_date']) throw new InvalidArgumentException('The new end must be after ' . $r['due_date'] . '.');
    $note = mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 1000);
    if ($dec !== $r['reco'] && $note === '') throw new InvalidArgumentException('The decision differs from the recommendation: add a note.');
    return [$dec, $date, $note];
}

function pr_avg(?string $ratingsJson): ?float {
    $r = json_decode((string)$ratingsJson, true) ?: [];
    return $r ? round(array_sum($r) / count($r), 1) : null;
}

/* ---------------- data ---------------- */

function pr_is_hr(array $u): bool { return has_role($u, ...PR_HR_ROLES); }
function pr_own(array $u, string $code): bool { return strcasecmp($code, (string)($u['emp_code'] ?? '')) === 0; }

/** HR in their branches, or the person's reporting manager — never the person. */
function pr_can(PDO $db, array $u, array $emp): bool {
    if (pr_own($u, $emp['code'])) return false;
    if (pr_is_hr($u) && emp_in_scope($u, $emp)) return true;
    return atr_manager_login($db, $emp) === (int)$u['id'];
}

function pr_review(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM probation_review WHERE id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
}

function pr_open_of(PDO $db, string $code): ?array {
    $s = $db->prepare("SELECT * FROM probation_review WHERE emp_code = ? AND status IN ('manager','hr') ORDER BY id DESC LIMIT 1"); $s->execute([$code]);
    return $s->fetch() ?: null;
}

function pr_decided(PDO $db, string $code): ?array {
    $s = $db->prepare("SELECT * FROM probation_review WHERE emp_code = ? AND status = 'done' AND decision IN ('confirm','not_confirm') ORDER BY id DESC LIMIT 1"); $s->execute([$code]);
    return $s->fetch() ?: null;
}

function pr_history(PDO $db, string $code): array {
    $s = $db->prepare('SELECT * FROM probation_review WHERE emp_code = ? ORDER BY id DESC'); $s->execute([$code]);
    return $s->fetchAll();
}

function pr_checkins(PDO $db, string $code): array {
    $s = $db->prepare('SELECT c.*, u.name AS by_name FROM probation_checkin c LEFT JOIN users u ON u.id = c.done_by WHERE c.emp_code = ? ORDER BY c.id');
    $s->execute([$code]);
    $o = [];
    foreach ($s as $r) $o[(int)$r['month']] = $r;   // newest per month wins; earlier rows stay
    return $o;
}

/** Employees on probation (not decided), in this login's reach. Each: [emp, end, checks, review]. */
function pr_people(PDO $db, array $u, ?string $today = null): array {
    $set = pr_settings(); $today ??= date('Y-m-d');
    $out = [];
    $q = 'SELECT * FROM employee WHERE active = 1 AND doj IS NOT NULL AND doj <= ? AND (last_day IS NULL OR last_day >= ?)' . ($set['track_from'] ? ' AND doj >= ?' : '');
    $s = $db->prepare($q); $s->execute($set['track_from'] ? [$today, $today, $set['track_from']] : [$today, $today]);
    foreach ($s->fetchAll() as $e) {
        if (!pr_can($db, $u, $e) || pr_decided($db, $e['code'])) continue;
        $end = pr_end($e, (int)$set['months']);
        if (!empty($e['confirm_date']) && $e['confirm_date'] < $today && !pr_open_of($db, $e['code']) && !pr_history($db, $e['code'])) continue;   // confirmed before tracking
        $rows = pr_checkins($db, $e['code']); $checks = [];
        foreach ($set['checkins'] as $m) { $due = pr_checkin_due($e, $m); $checks[$m] = ['due' => $due, 'row' => $rows[$m] ?? null, 'state' => isset($rows[$m]) ? 'done' : (pr_open_by($due, (int)$set['check_early'], $today) ? 'due' : 'later')]; }
        $out[] = ['emp' => $e, 'end' => $end, 'checks' => $checks, 'review' => pr_open_of($db, $e['code'])];
    }
    usort($out, fn($a, $b) => strcmp($a['end'], $b['end']));
    return $out;
}

/** Open a final review (or return the open one). */
function pr_open(PDO $db, ?array $u, array $emp): int {
    if ($o = pr_open_of($db, $emp['code'])) return (int)$o['id'];
    if (pr_decided($db, $emp['code'])) throw new RuntimeException('Probation is already decided.');
    if ($u && !pr_is_hr($u)) throw new RuntimeException('Only HR opens a review early.');
    if ($u && pr_own($u, $emp['code'])) throw new RuntimeException('Not for your own probation.');
    $s = $db->prepare('SELECT COALESCE(MAX(round), 0) + 1 FROM probation_review WHERE emp_code = ?'); $s->execute([$emp['code']]);
    $mgr = atr_manager_login($db, $emp);
    if ($mgr && ($l = $db->query('SELECT emp_code FROM users WHERE id = ' . (int)$mgr)->fetchColumn()) && strcasecmp((string)$l, $emp['code']) === 0) $mgr = null;
    $db->prepare('INSERT INTO probation_review (emp_code, round, due_date, status, manager_id, opened_by) VALUES (?,?,?,?,?,?)')
       ->execute([$emp['code'], (int)$s->fetchColumn(), pr_end($emp, (int)pr_settings()['months']) ?: null, $mgr ? 'manager' : 'hr', $mgr, $u['id'] ?? null]);
    $id = (int)$db->lastInsertId();
    audit($u ? (int)$u['id'] : null, 'probation_open', '#' . $id . ' ' . $emp['code']);
    return $id;
}

/** Should a review open by itself? From N days before the end until PR_AUTO_LATE days after it (older ones HR opens). Pure. */
function pr_auto_due(string $end, int $openDays, string $today): bool {
    return $end !== '' && pr_open_by($end, $openDays, $today) && $end >= date('Y-m-d', strtotime("$today -" . PR_AUTO_LATE . ' days'));
}

/** Open reviews that are due soon (or just overdue) for people on probation. */
function pr_autostart(PDO $db, array $u, ?string $today = null): int {
    $set = pr_settings(); $today ??= date('Y-m-d'); $n = 0;
    foreach (pr_people($db, $u, $today) as $p) if (!$p['review'] && pr_auto_due($p['end'], (int)$set['open_days'], $today)) { pr_open($db, null, $p['emp']); $n++; }
    return $n;
}

function pr_checkin_save(PDO $db, array $u, array $emp, int $month, array $in, ?string $today = null): void {
    $set = pr_settings(); $today ??= date('Y-m-d');
    if (!pr_can($db, $u, $emp)) throw new RuntimeException(pr_own($u, $emp['code']) ? 'Not for your own probation.' : 'Only HR or the reporting manager records this.');
    if (!in_array($month, $set['checkins'], true)) throw new RuntimeException('Unknown check-in.');
    if (pr_decided($db, $emp['code'])) throw new RuntimeException('Probation is already decided.');
    $due = pr_checkin_due($emp, $month);
    if (!pr_open_by($due, (int)$set['check_early'], $today)) throw new RuntimeException('This check-in opens on ' . date('j M Y', strtotime("$due -{$set['check_early']} days")) . '.');
    $st = (string)($in['status'] ?? '');
    if (!isset(PR_CHECK_STATUS[$st])) throw new InvalidArgumentException('Choose: on track, needs support or concern.');
    $note = mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 1500);
    if ($note === '') throw new InvalidArgumentException('Write a short note on how it is going.');
    $act = mb_substr(trim(strip_tags((string)($in['actions'] ?? ''))), 0, 1000);
    if ($st !== 'on_track' && $act === '') throw new InvalidArgumentException('Write the agreed actions.');
    $db->prepare('INSERT INTO probation_checkin (emp_code, month, status, note, actions, done_by) VALUES (?,?,?,?,?,?)')->execute([$emp['code'], $month, $st, $note, $act, (int)$u['id']]);
    audit((int)$u['id'], 'probation_checkin', $emp['code'] . ' m' . $month);
}

/** Manager (or HR when there is no manager / on their behalf) submits the review. */
function pr_submit(PDO $db, array $u, int $id, array $in): void {
    $r = pr_review($db, $id);
    if (!$r || !in_array($r['status'], ['manager', 'hr'], true) || $r['manager_at']) throw new RuntimeException('This review is not waiting for feedback.');
    if (pr_own($u, $r['emp_code'])) throw new RuntimeException('Not for your own probation.');
    $emp = emp_by_code($db, $r['emp_code']);
    if (!((int)$r['manager_id'] === (int)$u['id'] || (pr_is_hr($u) && $emp && emp_in_scope($u, $emp)))) throw new RuntimeException('This review is not waiting for you.');
    $set = pr_settings();
    $c = pr_review_check($in, $set['ratings'], $set['training']);
    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($c)));
    $db->prepare("UPDATE probation_review SET $sets, status = 'hr', manager_by = ?, manager_at = NOW() WHERE id = ?")->execute([...array_values($c), (int)$u['id'], $id]);
    audit((int)$u['id'], 'probation_review', '#' . $id);
}

function pr_return(PDO $db, array $u, int $id, string $note): void {
    $r = pr_review($db, $id);
    if (!$r || $r['status'] !== 'hr' || !$r['manager_at'] || !pr_is_hr($u) || pr_own($u, $r['emp_code'])) throw new RuntimeException('This review is not waiting for an HR decision.');
    if (!$r['manager_id']) throw new RuntimeException('No reporting manager — HR filled it in.');
    if (trim($note) === '') throw new InvalidArgumentException('Say what the manager should add or change.');
    $db->prepare("UPDATE probation_review SET status = 'manager', manager_at = NULL, hr_note = ? WHERE id = ?")->execute([mb_substr(trim(strip_tags($note)), 0, 1000), $id]);
    audit((int)$u['id'], 'probation_return', '#' . $id);
}

/** HR decides; the date goes to the employee master (confirmation / new probation end). */
function pr_decide(PDO $db, array $u, int $id, array $in): string {
    $r = pr_review($db, $id);
    if (!$r || $r['status'] !== 'hr' || !$r['manager_at']) throw new RuntimeException('This review is not waiting for an HR decision.');
    if (!pr_is_hr($u) || pr_own($u, $r['emp_code'])) throw new RuntimeException(pr_own($u, $r['emp_code']) ? 'Not for your own probation.' : 'Only HR decides.');
    $emp = emp_by_code($db, $r['emp_code']);
    if (!$emp || !emp_in_scope($u, $emp)) throw new RuntimeException('This person is outside your branches.');
    [$dec, $date, $note] = pr_decision_check($in, $r, $emp['doj']);
    $db->prepare("UPDATE probation_review SET status = 'done', decision = ?, decision_date = ?, hr_note = ?, hr_by = ?, hr_at = NOW() WHERE id = ?")->execute([$dec, $date, $note, (int)$u['id'], $id]);
    if ($date) { [, $err] = emp_save($db, $u, (int)$emp['id'], ['confirm_date' => $date], 'edit', 'Probation: ' . strtolower(PR_RECO[$dec])); if ($err) throw new RuntimeException($err); }
    audit((int)$u['id'], 'probation_decide', '#' . $id . ' ' . $dec);
    return $dec;
}

/** Older staff confirmed before tracking began: set their confirmation date in one go (logged on the master). */
function pr_mark_confirmed(PDO $db, array $u, array $codes): int {
    if (!pr_is_hr($u)) throw new RuntimeException('Only HR does this.');
    $m = (int)pr_settings()['months']; $n = 0;
    foreach ($codes as $c) {
        $e = emp_by_code($db, (string)$c);
        if (!$e || !$e['doj'] || pr_own($u, $e['code']) || !emp_in_scope($u, $e) || pr_open_of($db, $e['code'])) continue;
        [, $err] = emp_save($db, $u, (int)$e['id'], ['confirm_date' => pr_end(['doj' => $e['doj'], 'confirm_date' => null], $m)], 'edit', 'Confirmed before probation tracking');
        if (!$err) $n++;
    }
    return $n;
}

/** Reviews waiting for this login. */
function pr_lists(PDO $db, array $u): array {
    $o = ['mine' => [], 'hr_fill' => [], 'hr' => [], 'with_manager' => [], 'done' => []];
    foreach ($db->query("SELECT r.*, e.name, e.branch_id, e.doj FROM probation_review r JOIN employee e ON e.code = r.emp_code WHERE r.status IN ('manager','hr') OR r.hr_at >= DATE_SUB(NOW(), INTERVAL 120 DAY) ORDER BY r.due_date, r.id") as $r) {
        if (pr_own($u, $r['emp_code'])) continue;
        $hr = pr_is_hr($u) && emp_in_scope($u, $r);
        if ($r['status'] === 'manager' && (int)$r['manager_id'] === (int)$u['id']) $o['mine'][] = $r;
        elseif ($hr && $r['status'] === 'manager') $o['with_manager'][] = $r;
        elseif ($hr && $r['status'] === 'hr' && !$r['manager_at']) $o['hr_fill'][] = $r;
        elseif ($hr && $r['status'] === 'hr') $o['hr'][] = $r;
        elseif ($hr && $r['status'] === 'done') $o['done'][] = $r;
    }
    return $o;
}
