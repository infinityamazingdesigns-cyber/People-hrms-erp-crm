<?php
// Training register — rules. Trainings per person (added by the person, a manager above them or HR; HR's entry for
// someone else counts as verified, everyone else's waits for HR), certificates, requests (person → reporting manager →
// HR), feedback after a completed training, peer sessions, and the yearly report. Pure functions first.
declare(strict_types=1);
require_once __DIR__ . '/orgchart.php';

const TR_KINDS = ['Classroom', 'On-the-job', 'External / agency', 'Online course', 'Webinar / online session', 'Workshop / seminar', 'Conference / event', 'Industrial visit', 'Peer training'];
const TR_STATUS = ['enrolled' => 'Enrolled', 'completed' => 'Completed'];
const TR_VERIFY = ['pending' => 'Waiting for HR', 'info' => 'HR asked for details', 'verified' => 'Verified'];
const TR_REQ = ['manager' => 'With manager', 'hr' => 'With HR', 'approved' => 'Approved', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn'];
const TR_FILE_MAX = 10 * 1024 * 1024;
const TR_FILES_PER_RECORD = 12;
const TR_DEFAULTS = ['hr_roles' => ['SUPERADMIN', 'HRMANAGER', 'HROPS', 'HRPERF'], 'see_all_roles' => ['CEO'], 'feedback_days' => 90, 'day_hours' => 8, 'show_fee' => true];

function tr_settings(): array { $s = module_states()['training']['settings'] ?? []; return array_replace(TR_DEFAULTS, array_intersect_key($s, TR_DEFAULTS)); }

function tr_settings_check(array $in, array $roles): array {
    $o = ['hr_roles' => array_values(array_intersect(array_keys($roles), (array)($in['hr_roles'] ?? []))), 'see_all_roles' => array_values(array_intersect(array_keys($roles), (array)($in['see_all_roles'] ?? [])))];
    $e = $o['hr_roles'] ? [] : ['Pick at least one HR role.'];
    $o['feedback_days'] = max(7, min(365, (int)($in['feedback_days'] ?? 90)));
    $o['day_hours'] = max(1, min(12, (int)($in['day_hours'] ?? 8)));
    $o['show_fee'] = !empty($in['show_fee']);
    return [$o, $e];
}

/** The financial year label of a day ("2026-27", or "2026" for a January start). Pure. */
function tr_fy(string $day, int $start): string {
    $y = (int)substr($day, 0, 4); $m = (int)substr($day, 5, 2);
    $f = $m >= $start ? $y : $y - 1;
    return $start === 1 ? (string)$f : $f . '-' . substr((string)($f + 1), 2);
}
/** First and last day of a year label. Pure. */
function tr_fy_range(string $fy, int $start): array {
    $y = (int)substr($fy, 0, 4);
    $from = sprintf('%04d-%02d-01', $y, $start);
    return [$from, date('Y-m-d', strtotime($from . ' +1 year -1 day'))];
}

function tr_num($v, float $max): ?float {
    $s = trim(str_replace(',', '', (string)$v));
    if ($s === '') return null;
    if (!is_numeric($s) || (float)$s < 0 || (float)$s > $max) throw new InvalidArgumentException('Check the number "' . mb_substr($s, 0, 20) . '".');
    return round((float)$s, 1);
}
function tr_text($v, int $n): string { return mb_substr(trim(strip_tags(str_replace("\r", '', (string)$v))), 0, $n); }
function tr_date($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null; }

/** Clean a training. Pure. */
function tr_check(array $in, int $fyStart, string $today): array {
    $r = ['title' => tr_text($in['title'] ?? '', 160)];
    if ($r['title'] === '') throw new InvalidArgumentException('Give the training a name.');
    $r['kind'] = in_array($in['kind'] ?? '', TR_KINDS, true) ? (string)$in['kind'] : throw new InvalidArgumentException('Pick the kind of training.');
    $r['status'] = isset(TR_STATUS[$in['status'] ?? '']) ? (string)$in['status'] : 'completed';
    $r['start_on'] = tr_date($in['start_on'] ?? ''); $r['end_on'] = tr_date($in['end_on'] ?? '') ?? $r['start_on'];
    if ($r['start_on'] === null) throw new InvalidArgumentException('Give the day it started.');
    if ($r['end_on'] < $r['start_on']) throw new InvalidArgumentException('It cannot end before it started.');
    if ($r['status'] === 'completed' && $r['end_on'] > $today) throw new InvalidArgumentException('A completed training cannot end in the future.');
    $r['hours'] = tr_num($in['hours'] ?? '', 2000); $r['days'] = tr_num($in['days'] ?? '', 365);
    foreach (['trainer' => 120, 'venue' => 160, 'evaluated' => 160, 'result' => 80, 'skills' => 400] as $k => $n) $r[$k] = tr_text($in[$k] ?? '', $n);
    $r['objectives'] = tr_text($in['objectives'] ?? '', 2000); $r['outcome'] = tr_text($in['outcome'] ?? '', 2000);
    $l = trim((string)($in['link'] ?? ''));
    if ($l !== '' && !preg_match('#^https://[^\s"<>]+$#i', $l)) throw new InvalidArgumentException('A link must start with https://');
    $r['link'] = mb_substr($l, 0, 400);
    $r['fee'] = tr_num($in['fee'] ?? '', 1e9);
    $r['fy'] = tr_fy($r['start_on'], $fyStart);
    return $r;
}

/** Hours a training counts for in the report: its hours, else days × the hours of a day. Pure. */
function tr_hours(array $r, int $dayHours): float {
    if ($r['hours'] !== null && $r['hours'] !== '') return (float)$r['hours'];
    if ($r['days'] !== null && $r['days'] !== '') return (float)$r['days'] * $dayHours;
    if (!empty($r['start_on']) && !empty($r['end_on'])) return ((int)((strtotime((string)$r['end_on']) - strtotime((string)$r['start_on'])) / 86400) + 1) * $dayHours;
    return 0.0;
}

/** Can a request step happen? '' = yes. $who manager | hr | self. Pure. */
function tr_req_problem(string $status, string $act, string $who): string {
    $ok = ['decide' => ['manager' => ['manager'], 'hr' => ['manager', 'hr']], 'withdraw' => ['self' => ['manager', 'hr']]];
    return in_array($status, $ok[$act][$who] ?? [], true) ? '' : 'Not possible now (' . (TR_REQ[$status] ?? $status) . ').';
}

/** Is feedback still open for a record? Pure. */
function tr_feedback_open(array $r, string $today, int $days): bool {
    return $r['status'] === 'completed' && !empty($r['end_on']) && (string)$r['end_on'] <= $today && strtotime($today) - strtotime((string)$r['end_on']) <= $days * 86400;
}

/** The yearly report from verified completed rows. Pure. */
function tr_report(array $rows, int $dayHours): array {
    $o = ['count' => 0, 'hours' => 0.0, 'people' => [], 'kind' => [], 'skills' => [], 'certs' => 0];
    foreach ($rows as $r) {
        $h = tr_hours($r, $dayHours);
        $o['count']++; $o['hours'] += $h;
        $o['people'][$r['emp_code']] ??= ['name' => $r['name'] ?? $r['emp_code'], 'count' => 0, 'hours' => 0.0];
        $o['people'][$r['emp_code']]['count']++; $o['people'][$r['emp_code']]['hours'] += $h;
        $o['kind'][$r['kind']] = ($o['kind'][$r['kind']] ?? 0) + 1;
        foreach (array_filter(array_map('trim', explode(',', (string)$r['skills']))) as $s) { $k = mb_strtolower($s); $o['skills'][$k] = ($o['skills'][$k] ?? 0) + 1; }
        $o['certs'] += (int)($r['files'] ?? 0) > 0 ? 1 : 0;
    }
    arsort($o['kind']); arsort($o['skills']); uasort($o['people'], fn($a, $b) => $b['hours'] <=> $a['hours']);
    return $o;
}

/** CSV cell, formula starts neutralised. Pure. */
function tr_csv_cell($v): string { $s = (string)$v; if ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) $s = "'" . $s; return '"' . str_replace('"', '""', $s) . '"'; }

// ---------- database ----------

function tr_roles(array $u): array { return user_roles((int)$u['id'], !empty($u['is_owner'])); }
function tr_is_hr(array $u): bool { return (bool)array_intersect(tr_roles($u), tr_settings()['hr_roles']); }
function tr_sees_all(array $u): bool { return tr_is_hr($u) || (bool)array_intersect(tr_roles($u), tr_settings()['see_all_roles']); }
function tr_log(PDO $db, string $kind, int $id, string $action, int $uid, string $fields = ''): void { $db->prepare('INSERT INTO tr_log (kind, ref_id, action, fields, user_id) VALUES (?,?,?,?,?)')->execute([$kind, $id, $action, mb_substr($fields, 0, 300), $uid]); }
function tr_fy_start(): int { return max(1, min(12, (int)(org('fy_start_month') ?: 1))); }
function tr_below(PDO $db, array $u): array {
    $me = strtoupper((string)($u['emp_code'] ?? '')); if ($me === '') return [];
    return org_team($me, org_parent_map($db->query('SELECT code, manager_code FROM employee WHERE active = 1')->fetchAll()));
}

/** Whose trainings this login sees: own, people below, everyone (HR / see-all, in branch scope). */
function tr_can_see(PDO $db, array $u, string $code): bool {
    $code = strtoupper($code);
    if (strcasecmp($code, (string)($u['emp_code'] ?? '')) === 0) return true;
    if (tr_sees_all($u)) { $e = emp_by_code($db, $code); return (bool)$e && emp_in_scope($u, $e); }
    return in_array($code, tr_below($db, $u), true);
}
/** Who may add / change a person's trainings: the person, a manager above, HR. */
function tr_can_add(PDO $db, array $u, string $code): bool {
    if (strcasecmp($code, (string)($u['emp_code'] ?? '')) === 0) return true;
    if (tr_is_hr($u)) { $e = emp_by_code($db, strtoupper($code)); return (bool)$e && emp_in_scope($u, $e); }
    return in_array(strtoupper($code), tr_below($db, $u), true);
}

function tr_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT r.*, e.name, e.department, e.branch_id FROM tr_record r LEFT JOIN employee e ON e.code = r.emp_code WHERE r.id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Add or change a training. HR entering for someone else = verified; anyone else's add or change waits for HR. */
function tr_save(PDO $db, array $u, int $id, string $code, array $in): int {
    $code = strtoupper(trim($code));
    $old = $id ? tr_get($db, $id) : null;
    if ($id && (!$old || $old['removed_at'] !== null)) throw new RuntimeException('Training not found.');
    if ($old) $code = $old['emp_code'];
    if (!tr_can_add($db, $u, $code) || !emp_by_code($db, $code)) throw new RuntimeException('You can add trainings for yourself, people below you on the org chart, or (HR) anyone.');
    $r = tr_check($in, tr_fy_start(), date('Y-m-d'));
    if (!tr_settings()['show_fee'] || !tr_is_hr($u)) unset($r['fee']);
    $own = strcasecmp($code, (string)($u['emp_code'] ?? '')) === 0;
    $verified = tr_is_hr($u) && !$own;
    $r['verify'] = $verified ? 'verified' : 'pending';
    if ($verified) { $r['verified_by'] = (int)$u['id']; $r['verified_at'] = date('Y-m-d H:i:s'); }
    if ($old) {
        $changed = array_keys(array_filter($r, fn($v, $k) => !in_array($k, ['verify', 'verified_by', 'verified_at'], true) && (string)$v !== (string)($old[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        if (!$changed && $old['verify'] !== 'info') return $id;
        if (!$verified) { $r['verified_by'] = null; $r['verified_at'] = null; $r['ask_note'] = $old['verify'] === 'info' ? $old['ask_note'] : ''; }
        $cols = array_keys($r);
        $db->prepare('UPDATE tr_record SET ' . implode(', ', array_map(fn($k) => "$k = ?", $cols)) . ' WHERE id = ?')->execute([...array_values($r), $id]);
        tr_log($db, 'rec', $id, $old['verify'] === 'info' ? 'answer' : 'edit', (int)$u['id'], implode(',', $changed));
    } else {
        $r['emp_code'] = $code; $r['created_by'] = (int)$u['id'];
        $cols = array_keys($r);
        $db->prepare('INSERT INTO tr_record (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
        $id = (int)$db->lastInsertId();
        tr_log($db, 'rec', $id, 'add', (int)$u['id']);
    }
    return $id;
}

/** HR verifies, or asks for details / proof (the person answers by changing it). Never their own. */
function tr_verify(PDO $db, array $u, int $id, bool $ok, string $note): void {
    $r = tr_get($db, $id);
    if (!$r || $r['removed_at'] !== null || !tr_is_hr($u) || !emp_in_scope($u, $r)) throw new RuntimeException('Only HR verifies trainings.');
    if (strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Someone else in HR verifies your own trainings.');
    if (!$ok && ($note = tr_text($note, 500)) === '') throw new RuntimeException('Say what details or proof are needed.');
    $db->prepare('UPDATE tr_record SET verify = ?, verified_by = ?, verified_at = ?, ask_note = ? WHERE id = ?')->execute([$ok ? 'verified' : 'info', $ok ? (int)$u['id'] : null, $ok ? date('Y-m-d H:i:s') : null, $ok ? '' : $note, $id]);
    tr_log($db, 'rec', $id, $ok ? 'verify' : 'ask', (int)$u['id']);
}

/** Take a training off with a reason (the person while not verified, the one who added it, or HR); HR brings it back. */
function tr_remove(PDO $db, array $u, int $id, string $reason, bool $back = false): void {
    $r = tr_get($db, $id); if (!$r) throw new RuntimeException('Not found.');
    if ($back) { if (!tr_is_hr($u)) throw new RuntimeException('Only HR brings a training back.'); $db->prepare('UPDATE tr_record SET removed_at = NULL, removed_by = NULL, removed_reason = \'\' WHERE id = ?')->execute([$id]); tr_log($db, 'rec', $id, 'restore', (int)$u['id']); return; }
    $own = strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) === 0;
    if (!(tr_is_hr($u) || (int)$r['created_by'] === (int)$u['id'] || ($own && $r['verify'] !== 'verified'))) throw new RuntimeException('Ask HR to take this off.');
    if (($reason = tr_text($reason, 300)) === '') throw new RuntimeException('Say why.');
    $db->prepare('UPDATE tr_record SET removed_at = NOW(), removed_by = ?, removed_reason = ? WHERE id = ?')->execute([(int)$u['id'], $reason, $id]);
    tr_log($db, 'rec', $id, 'remove', (int)$u['id']);
}

/** Certificate (PDF, JPEG, PNG, WebP by its bytes; 10 MB, 12 a training). */
function tr_file_type(string $b): ?string {
    if (str_starts_with($b, '%PDF-')) return 'application/pdf';
    if (str_starts_with($b, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) return 'image/png';
    if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') return 'image/webp';
    return null;
}
function tr_file_add(PDO $db, array $u, int $id, string $bytes, string $name): void {
    $r = tr_get($db, $id);
    if (!$r || $r['removed_at'] !== null || !tr_can_add($db, $u, $r['emp_code'])) throw new RuntimeException('Not found.');
    if ($bytes === '' || strlen($bytes) > TR_FILE_MAX) throw new RuntimeException('Certificates up to 10 MB.');
    $t = tr_file_type($bytes); if (!$t) throw new RuntimeException('PDF or a photo (JPEG, PNG, WebP) only.');
    $n = $db->prepare('SELECT COUNT(*) FROM tr_file WHERE record_id = ? AND removed_at IS NULL'); $n->execute([$id]);
    if ((int)$n->fetchColumn() >= TR_FILES_PER_RECORD) throw new RuntimeException('Up to ' . TR_FILES_PER_RECORD . ' files a training.');
    $s = $db->prepare('INSERT INTO tr_file (id, record_id, name, type, size, data, uploaded_by) VALUES (?,?,?,?,?,?,?)');
    $s->bindValue(1, bin2hex(random_bytes(16))); $s->bindValue(2, $id); $s->bindValue(3, mb_substr((string)preg_replace('/[^\pL\pN ._()-]/u', '_', $name), 0, 180) ?: 'certificate'); $s->bindValue(4, $t); $s->bindValue(5, strlen($bytes)); $s->bindValue(6, $bytes, PDO::PARAM_LOB); $s->bindValue(7, (int)$u['id']);
    $s->execute();
    if ($r['verify'] === 'verified' && !tr_is_hr($u)) $db->prepare("UPDATE tr_record SET verify = 'pending', verified_by = NULL, verified_at = NULL WHERE id = ?")->execute([$id]);
    tr_log($db, 'rec', $id, 'file', (int)$u['id']);
}

/** A person's trainings (and the files count). */
function tr_of(PDO $db, string $code, bool $removed = false): array {
    $s = $db->prepare('SELECT r.*, (SELECT COUNT(*) FROM tr_file f WHERE f.record_id = r.id AND f.removed_at IS NULL) AS files, (SELECT COUNT(*) FROM tr_feedback fb WHERE fb.record_id = r.id) AS fb
                       FROM tr_record r WHERE r.emp_code = ?' . ($removed ? '' : ' AND r.removed_at IS NULL') . ' ORDER BY r.start_on DESC, r.id DESC');
    $s->execute([strtoupper($code)]);
    return $s->fetchAll();
}

/** Feedback by the person, once, while open. */
function tr_feedback(PDO $db, array $u, int $id, array $in): void {
    $r = tr_get($db, $id);
    if (!$r || strcasecmp($r['emp_code'], (string)($u['emp_code'] ?? '')) !== 0) throw new RuntimeException('Only the person who attended gives feedback.');
    if (!tr_feedback_open($r, date('Y-m-d'), (int)tr_settings()['feedback_days'])) throw new RuntimeException('Feedback is taken in the days after a completed training.');
    $rt = (int)($in['rating'] ?? 0); if ($rt < 1 || $rt > 5) throw new RuntimeException('Give a rating from 1 to 5.');
    try { $db->prepare('INSERT INTO tr_feedback (record_id, rating, recommend, learned, use_plan, by_user) VALUES (?,?,?,?,?,?)')->execute([$id, $rt, !empty($in['recommend']) ? 1 : 0, tr_text($in['learned'] ?? '', 2000), tr_text($in['use_plan'] ?? '', 2000), (int)$u['id']]); }
    catch (PDOException $e) { throw new RuntimeException('Feedback is already given.'); }
}

/** Ask for a training. Goes to the reporting manager; with no manager (or asked by a manager for their team) straight to HR. */
function tr_request(PDO $db, array $u, string $code, array $in): int {
    $code = strtoupper(trim($code)); $own = strcasecmp($code, (string)($u['emp_code'] ?? '')) === 0;
    if (!$own && !tr_can_add($db, $u, $code)) throw new RuntimeException('You can ask for yourself or for people below you.');
    $e = emp_by_code($db, $code); if (!$e || !(int)$e['active'] || ($e['last_day'] && $e['last_day'] < date('Y-m-d'))) throw new RuntimeException('Only for someone on the roll.');
    $title = tr_text($in['title'] ?? '', 160); if ($title === '') throw new RuntimeException('Name the training.');
    $why = tr_text($in['why'] ?? '', 2000); if ($why === '') throw new RuntimeException('Say why it is needed.');
    $kind = in_array($in['kind'] ?? '', TR_KINDS, true) ? (string)$in['kind'] : 'External / agency';
    $mgr = strtoupper((string)$e['manager_code']);
    $st = $own && $mgr !== '' ? 'manager' : 'hr';
    $db->prepare('INSERT INTO tr_request (emp_code, by_user, title, kind, why, when_hint, provider, cost, status, mgr_code) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$code, (int)$u['id'], $title, $kind, $why, tr_text($in['when_hint'] ?? '', 80), tr_text($in['provider'] ?? '', 160), tr_num($in['cost'] ?? '', 1e9), $st, $mgr]);
    $id = (int)$db->lastInsertId(); tr_log($db, 'req', $id, 'ask', (int)$u['id']);
    return $id;
}

function tr_req_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT q.*, e.name, e.branch_id FROM tr_request q LEFT JOIN employee e ON e.code = q.emp_code WHERE q.id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

/** Manager or HR decides. Approved by HR → an Enrolled, verified training. Decline needs a note. Never one's own. */
function tr_req_decide(PDO $db, array $u, int $id, bool $ok, string $note): void {
    $q = tr_req_get($db, $id); if (!$q) throw new RuntimeException('Not found.');
    $me = strtoupper((string)($u['emp_code'] ?? ''));
    if (strcasecmp($q['emp_code'], $me) === 0 || (int)$q['by_user'] === (int)$u['id']) throw new RuntimeException('Someone else decides a request you made or one for yourself.');
    $who = tr_is_hr($u) && emp_in_scope($u, $q) ? 'hr' : ($me !== '' && $q['mgr_code'] === $me ? 'manager' : '');
    if ($who === '' || ($p = tr_req_problem($q['status'], 'decide', $who)) !== '') throw new RuntimeException($p ?? 'Not allowed.');
    $note = tr_text($note, 500);
    if (!$ok && $note === '') throw new RuntimeException('Say why it is declined.');
    if ($who === 'manager') {
        $db->prepare('UPDATE tr_request SET status = ?, mgr_by = ?, mgr_at = NOW(), mgr_note = ? WHERE id = ?')->execute([$ok ? 'hr' : 'declined', (int)$u['id'], $note, $id]);
        tr_log($db, 'req', $id, $ok ? 'mgr_ok' : 'mgr_no', (int)$u['id']); return;
    }
    $db->beginTransaction();
    try {
        $rid = null;
        if ($ok) {
            $db->prepare("INSERT INTO tr_record (emp_code, kind, title, status, start_on, trainer, fy, verify, verified_by, verified_at, request_id, created_by) VALUES (?,?,?,'enrolled',CURDATE(),?,?,'verified',?,NOW(),?,?)")
               ->execute([$q['emp_code'], $q['kind'], $q['title'], $q['provider'], tr_fy(date('Y-m-d'), tr_fy_start()), (int)$u['id'], $id, (int)$u['id']]);
            $rid = (int)$db->lastInsertId();
        }
        $db->prepare('UPDATE tr_request SET status = ?, hr_by = ?, hr_at = NOW(), hr_note = ?, record_id = ?' . ($q['status'] === 'manager' ? ", mgr_note = CONCAT(mgr_note, '(decided by HR)')" : '') . ' WHERE id = ?')->execute([$ok ? 'approved' : 'declined', (int)$u['id'], $note, $rid, $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    tr_log($db, 'req', $id, $ok ? 'hr_ok' : 'hr_no', (int)$u['id']);
}

function tr_req_withdraw(PDO $db, array $u, int $id): void {
    $q = tr_req_get($db, $id);
    if (!$q || (int)$q['by_user'] !== (int)$u['id'] || ($p = tr_req_problem($q['status'], 'withdraw', 'self')) !== '') throw new RuntimeException($p ?? 'Not found.');
    $db->prepare("UPDATE tr_request SET status = 'withdrawn' WHERE id = ?")->execute([$id]); tr_log($db, 'req', $id, 'withdraw', (int)$u['id']);
}

/** Requests waiting for this login (manager step for their reports, HR step in scope). */
function tr_req_waiting(PDO $db, array $u): array {
    $me = strtoupper((string)($u['emp_code'] ?? '')); $hr = tr_is_hr($u);
    $rows = $db->query("SELECT q.*, e.name, e.branch_id FROM tr_request q LEFT JOIN employee e ON e.code = q.emp_code WHERE q.status IN ('manager','hr') ORDER BY q.id")->fetchAll();
    return array_values(array_filter($rows, fn($q) => strcasecmp($q['emp_code'], $me) !== 0 && (($hr && emp_in_scope($u, $q)) || ($q['status'] === 'manager' && $me !== '' && $q['mgr_code'] === $me))));
}

/** Arrange a peer session (anyone with a code; HR may name another trainer). */
function tr_session_add(PDO $db, array $u, array $in): int {
    $me = strtoupper((string)($u['emp_code'] ?? ''));
    $trainer = tr_is_hr($u) && trim((string)($in['trainer_code'] ?? '')) !== '' ? strtoupper(trim((string)$in['trainer_code'])) : $me;
    if ($trainer === '' || !emp_by_code($db, $trainer)) throw new RuntimeException('Your login needs an employee code to run a session.');
    $title = tr_text($in['title'] ?? '', 160); if ($title === '') throw new RuntimeException('Name the session.');
    $at = str_replace('T', ' ', (string)($in['at'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $at)) throw new RuntimeException('Pick the day and time.');
    $h = tr_num($in['hours'] ?? '', 24); if (!$h) throw new RuntimeException('How many hours?');
    $db->prepare('INSERT INTO tr_session (title, trainer_code, by_user, at, hours, seats, venue, about) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$title, $trainer, (int)$u['id'], $at . ':00', $h, max(1, min(500, (int)($in['seats'] ?? 20))), tr_text($in['venue'] ?? '', 160), tr_text($in['about'] ?? '', 2000)]);
    $id = (int)$db->lastInsertId(); tr_log($db, 'ses', $id, 'add', (int)$u['id']);
    return $id;
}

function tr_session_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT s.*, e.name AS trainer FROM tr_session s LEFT JOIN employee e ON e.code = s.trainer_code WHERE s.id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

/** Join / leave a session (own code; not the trainer; seats; open and not yet held). */
function tr_session_join(PDO $db, array $u, int $id, bool $join): void {
    $s = tr_session_get($db, $id); $me = strtoupper((string)($u['emp_code'] ?? ''));
    if (!$s || $s['status'] !== 'open') throw new RuntimeException('This session is not open.');
    if ($me === '' || $me === $s['trainer_code']) throw new RuntimeException('Not for the trainer.');
    if ($join) {
        $n = $db->prepare('SELECT COUNT(*) FROM tr_attendee WHERE session_id = ? AND left_at IS NULL'); $n->execute([$id]);
        if ((int)$n->fetchColumn() >= (int)$s['seats']) throw new RuntimeException('The session is full.');
        $db->prepare('INSERT INTO tr_attendee (session_id, emp_code) VALUES (?,?) ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = NOW()')->execute([$id, $me]);
    } else $db->prepare('UPDATE tr_attendee SET left_at = NOW() WHERE session_id = ? AND emp_code = ?')->execute([$id, $me]);
}

/** The trainer (or HR) marks it held: every attendee (and the trainer's own "gave a session") gets a Peer training record waiting for HR. */
function tr_session_held(PDO $db, array $u, int $id): int {
    $s = tr_session_get($db, $id); $me = strtoupper((string)($u['emp_code'] ?? ''));
    if (!$s || $s['status'] !== 'open') throw new RuntimeException('Not open.');
    if ($me !== $s['trainer_code'] && !tr_is_hr($u) && (int)$s['by_user'] !== (int)$u['id']) throw new RuntimeException('The trainer or HR marks it held.');
    if (strtotime((string)$s['at']) > time()) throw new RuntimeException('It has not happened yet.');
    $a = $db->prepare('SELECT emp_code FROM tr_attendee WHERE session_id = ? AND left_at IS NULL'); $a->execute([$id]); $codes = $a->fetchAll(PDO::FETCH_COLUMN);
    $day = substr((string)$s['at'], 0, 10); $fy = tr_fy($day, tr_fy_start());
    $db->beginTransaction();
    try {
        $ins = $db->prepare("INSERT INTO tr_record (emp_code, kind, title, status, start_on, end_on, hours, trainer, venue, fy, verify, session_id, created_by) VALUES (?, 'Peer training', ?, 'completed', ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
        foreach ($codes as $c) $ins->execute([$c, $s['title'], $day, $day, $s['hours'], (string)$s['trainer'], $s['venue'], $fy, $id, (int)$u['id']]);
        $db->prepare("UPDATE tr_session SET status = 'held', held_at = NOW(), held_by = ? WHERE id = ?")->execute([(int)$u['id'], $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    tr_log($db, 'ses', $id, 'held', (int)$u['id'], 'attendees ' . count($codes));
    return count($codes);
}

function tr_session_cancel(PDO $db, array $u, int $id, string $note): void {
    $s = tr_session_get($db, $id);
    if (!$s || $s['status'] !== 'open' || ((int)$s['by_user'] !== (int)$u['id'] && !tr_is_hr($u))) throw new RuntimeException('Not allowed.');
    $db->prepare("UPDATE tr_session SET status = 'cancelled', cancel_note = ? WHERE id = ?")->execute([tr_text($note, 300), $id]);
}

/** Verified completed trainings in a year, for the people this login sees. */
function tr_year_rows(PDO $db, array $u, string $fy): array {
    $rows = $db->prepare("SELECT r.*, e.name, e.department, e.branch_id, (SELECT COUNT(*) FROM tr_file f WHERE f.record_id = r.id AND f.removed_at IS NULL) AS files FROM tr_record r
                          LEFT JOIN employee e ON e.code = r.emp_code WHERE r.fy = ? AND r.status = 'completed' AND r.verify = 'verified' AND r.removed_at IS NULL ORDER BY e.name, r.start_on");
    $rows->execute([$fy]);
    $all = tr_sees_all($u); $below = $all ? [] : array_flip(tr_below($db, $u));
    return array_values(array_filter($rows->fetchAll(), fn($r) => $all ? emp_in_scope($u, $r) : isset($below[$r['emp_code']])));
}
