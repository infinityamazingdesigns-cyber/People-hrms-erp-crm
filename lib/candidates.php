<?php
// Candidate portal — a private link per applicant (no password, no login). HR (settings roles) makes it for one
// application with the candidate's email; only the SHA-256 of the link is kept and the link is shown once; a new link
// stops the old one. The applicant sees only their own application in candidate wording, answers interviews
// (I will attend / ask to reschedule — a changed time asks again), answers issued letters sent to their email, sends
// their details (HR uses them to fill blanks) and CVs. Access ends by itself when the application closes, or when HR
// ends it with a reason. Nothing is deleted.
declare(strict_types=1);
require_once __DIR__ . '/recruitment.php';
require_once __DIR__ . '/letters.php';

const CAND_STAGES = ['new' => 'Application received', 'screening' => 'Application received', 'shortlisted' => 'Shortlisted', 'interview' => 'Interview',
                     'offer' => 'Offer made', 'accepted' => 'Offer accepted', 'joined' => 'Joined', 'hold' => 'Under review',
                     'rejected' => 'Not taken forward', 'withdrawn' => 'Withdrawn'];
const CAND_STEPS = ['Application received', 'Shortlisted', 'Interview', 'Offer made', 'Offer accepted', 'Joined'];
const CAND_FIELDS = ['phone' => ['Mobile', 20], 'city' => ['Current city', 80], 'cur_title' => ['Current designation', 100], 'cur_company' => ['Current company', 120],
                     'total_exp' => ['Total experience (years)', 5], 'notice_days' => ['Notice period (days)', 4], 'qualification' => ['Highest qualification', 160], 'skills' => ['Key skills', 600]];
const CAND_DEFAULTS = ['manage_roles' => ['SUPERADMIN', 'HRMANAGER', 'HRRECRUIT'], 'link_days' => 60];

/* ---------------- pure ---------------- */

function cand_token_hash(string $t): string { return hash('sha256', $t); }
/** The candidate-side stage and how far along the steps it is (-1 = closed or on hold). */
function cand_stage(string $stage): array { $l = CAND_STAGES[$stage] ?? 'Application received'; $i = array_search($l, CAND_STEPS, true); return [$l, $i === false ? -1 : (int)$i]; }
/** Whether an application stage ends the access by itself. */
function cand_stage_ends(string $stage): bool { return in_array($stage, RC_END, true); }
/** What the candidate said about an interview: the newest answer counts only for the interview's current time. */
function cand_iv_state(array $answers, string $at): string {
    $last = null; foreach ($answers as $a) if ($a['for_at'] === $at && ($last === null || $a['id'] > $last['id'])) $last = $a;
    return $last ? (string)$last['answer'] : '';
}
/** May the candidate answer this interview now? Upcoming and scheduled only. */
function cand_iv_open(array $iv, string $now): bool { return $iv['status'] === 'scheduled' && (string)$iv['at'] > $now; }
/** Check the details a candidate sends. Returns [clean, errors]. */
function cand_detail_check(array $in): array {
    $o = []; $e = [];
    foreach (CAND_FIELDS as $k => [$label, $max]) $o[$k] = mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($in[$k] ?? '')))), 0, $max);
    if ($o['phone'] !== '' && !preg_match('/^\+?[\d\s-]{7,18}$/', $o['phone'])) $e[] = 'Mobile: digits only.';
    if ($o['total_exp'] !== '' && (!is_numeric($o['total_exp']) || (float)$o['total_exp'] < 0 || (float)$o['total_exp'] > 60)) $e[] = 'Experience: years, 0 to 60.';
    if ($o['notice_days'] !== '' && (!ctype_digit($o['notice_days']) || (int)$o['notice_days'] > 365)) $e[] = 'Notice period: days, 0 to 365.';
    if (!array_filter($o, fn($v) => $v !== '')) $e[] = 'Fill in at least one detail.';
    return [$o, $e];
}

/* ---------------- settings and rights ---------------- */

function cand_settings(): array { $s = module_states()['candidates']['settings'] ?? []; return array_replace(CAND_DEFAULTS, array_intersect_key($s, CAND_DEFAULTS)); }
function cand_settings_check(array $in, array $roles): array {
    $o = ['manage_roles' => array_values(array_intersect(array_keys($roles), (array)($in['manage_roles'] ?? []))), 'link_days' => max(1, min(180, (int)($in['link_days'] ?? 60)))];
    return [$o, $o['manage_roles'] ? [] : ['Pick at least one role that manages candidate access.']];
}
function cand_can(array $u): bool { return has_role($u, ...cand_settings()['manage_roles']); }
function cand_need(array $u): void { if (!cand_can($u)) throw new RuntimeException('Your role does not manage candidate access.'); }
function cand_log(PDO $db, int $aid, ?int $by, string $what, string $detail = ''): void { $db->prepare('INSERT INTO cand_log (access_id, by_user, what, detail) VALUES (?,?,?,?)')->execute([$aid, $by, $what, mb_substr($detail, 0, 300)]); }

/* ---------------- HR side ---------------- */

function cand_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT x.*, c.name, c.removed_at, a.stage, a.req_id, r.title AS req_title FROM cand_access x JOIN rc_candidate c ON c.id = x.candidate_id JOIN rc_application a ON a.id = x.application_id LEFT JOIN rc_requisition r ON r.id = a.req_id WHERE x.id = ?');
    $s->execute([$id]); return $s->fetch() ?: null;
}
function cand_list(PDO $db, bool $ended = false): array {
    return $db->query('SELECT x.*, c.name, a.stage, r.title AS req_title FROM cand_access x JOIN rc_candidate c ON c.id = x.candidate_id JOIN rc_application a ON a.id = x.application_id LEFT JOIN rc_requisition r ON r.id = a.req_id'
        . ($ended ? '' : ' WHERE x.ended_at IS NULL') . ' ORDER BY x.ended_at IS NOT NULL, x.id DESC LIMIT 300')->fetchAll();
}
/** Applications that may get access: open, candidate not removed, no live access. */
function cand_open_apps(PDO $db): array {
    return $db->query("SELECT a.id, a.stage, c.name, c.email, r.title AS req_title FROM rc_application a JOIN rc_candidate c ON c.id = a.candidate_id LEFT JOIN rc_requisition r ON r.id = a.req_id
        WHERE c.removed_at IS NULL AND a.stage NOT IN ('" . implode("','", RC_END) . "') AND NOT EXISTS (SELECT 1 FROM cand_access x WHERE x.application_id = a.id AND x.ended_at IS NULL) ORDER BY c.name")->fetchAll();
}
/** Make access for one application. Returns [access id, the link token (shown once)]. */
function cand_create(PDO $db, array $u, int $appId, string $email, string $message = ''): array {
    cand_need($u);
    $a = rc_app_get($db, $appId); if (!$a || cand_stage_ends((string)$a['stage'])) throw new RuntimeException('Pick an open application.');
    $c = rc_cand_get($db, (int)$a['candidate_id']); if (!$c || $c['removed_at'] !== null) throw new RuntimeException('That candidate is switched off.');
    $email = strtolower(trim($email)) ?: strtolower((string)$c['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Give the candidate's email.");
    $s = $db->prepare('SELECT 1 FROM users WHERE LOWER(email) = ? AND active = 1'); $s->execute([$email]); if ($s->fetchColumn()) throw new RuntimeException('That email belongs to an active login — a candidate needs their own email.');
    $s = $db->prepare('SELECT 1 FROM cand_access WHERE application_id = ? AND ended_at IS NULL'); $s->execute([$appId]); if ($s->fetchColumn()) throw new RuntimeException('This application already has a live access.');
    $tok = bin2hex(random_bytes(24)); $till = date('Y-m-d', strtotime('+' . (int)cand_settings()['link_days'] . ' days'));
    try { $db->prepare('INSERT INTO cand_access (candidate_id, application_id, email, live_email, token_hash, link_until, hr_message, created_by) VALUES (?,?,?,?,?,?,?,?)')
             ->execute([(int)$c['id'], $appId, $email, $email, cand_token_hash($tok), $till, mb_substr(trim(strip_tags($message)), 0, 600), (int)$u['id']]); }
    catch (PDOException $e) { if ($e->getCode() === '23000') throw new RuntimeException('That email already has a live candidate access.'); throw $e; }
    $id = (int)$db->lastInsertId(); cand_log($db, $id, (int)$u['id'], 'create'); audit((int)$u['id'], 'cand_access_create', '#' . $id);
    return [$id, $tok];
}
function cand_new_link(PDO $db, array $u, int $id): string {
    cand_need($u); $x = cand_get($db, $id); if (!$x || $x['ended_at'] !== null) throw new RuntimeException('This access has ended.');
    $tok = bin2hex(random_bytes(24));
    $db->prepare('UPDATE cand_access SET token_hash = ?, link_until = ? WHERE id = ?')->execute([cand_token_hash($tok), date('Y-m-d', strtotime('+' . (int)cand_settings()['link_days'] . ' days')), $id]);
    cand_log($db, $id, (int)$u['id'], 'new_link'); audit((int)$u['id'], 'cand_access_link', '#' . $id);
    return $tok;
}
function cand_message(PDO $db, array $u, int $id, string $msg): void {
    cand_need($u); $db->prepare('UPDATE cand_access SET hr_message = ? WHERE id = ? AND ended_at IS NULL')->execute([mb_substr(trim(strip_tags($msg)), 0, 600), $id]); cand_log($db, $id, (int)$u['id'], 'message');
}
/** End an access for good (the link stops; the same email may get a new access later). */
function cand_end(PDO $db, ?array $u, int $id, string $reason): void {
    if ($u) cand_need($u);
    if (($reason = trim(strip_tags($reason))) === '') throw new RuntimeException('Write why the access ends.');
    $db->prepare('UPDATE cand_access SET ended_at = NOW(), ended_by = ?, end_reason = ?, live_email = NULL, token_hash = NULL WHERE id = ? AND ended_at IS NULL')->execute([$u ? (int)$u['id'] : null, mb_substr($reason, 0, 300), $id]);
    cand_log($db, $id, $u ? (int)$u['id'] : null, 'end', $reason);
}
/** HR copies the newest details the candidate sent into the blanks of the candidate record. */
function cand_use_details(PDO $db, array $u, int $id): array {
    cand_need($u); rc_need($u, 'manage');
    $x = cand_get($db, $id); $d = cand_details($db, $id)[0] ?? null; if (!$x || !$d) throw new RuntimeException('No details sent yet.');
    $v = json_decode((string)$d['data'], true) ?: [];
    $old = rc_cand_get($db, (int)$x['candidate_id']); $c = [];
    foreach ($v as $k => $val) if (isset(CAND_FIELDS[$k]) && $val !== '') $c[$k] = in_array($k, ['total_exp'], true) ? (float)$val : ($k === 'notice_days' ? (int)$val : $val);
    if (isset($c['phone'])) $c['phone_key'] = rc_phone_key((string)$c['phone']);
    rc_cand_fill($db, (int)$x['candidate_id'], $c);
    $filled = array_values(array_filter(array_keys($c), fn($k) => $k !== 'phone_key' && ($old[$k] === null || $old[$k] === '')));
    $db->prepare('UPDATE cand_detail SET used_by = ?, used_at = NOW() WHERE id = ?')->execute([(int)$u['id'], (int)$d['id']]);
    cand_log($db, $id, (int)$u['id'], 'details_used', implode(', ', $filled));
    return $filled;
}
function cand_details(PDO $db, int $id): array { $s = $db->prepare('SELECT * FROM cand_detail WHERE access_id = ? ORDER BY id DESC'); $s->execute([$id]); return $s->fetchAll(); }
function cand_history(PDO $db, int $id): array { $s = $db->prepare('SELECT l.*, u.name AS by_name FROM cand_log l LEFT JOIN users u ON u.id = l.by_user WHERE l.access_id = ? ORDER BY l.id DESC LIMIT 100'); $s->execute([$id]); return $s->fetchAll(); }

/* ---------------- the applicant's side ---------------- */

/** The live access behind a link, or null. Ends an access whose application has closed. */
function cand_by_token(PDO $db, string $tok, bool $touch = true): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
    $s = $db->prepare('SELECT id FROM cand_access WHERE token_hash = ? AND ended_at IS NULL AND link_until >= CURDATE()'); $s->execute([cand_token_hash($tok)]);
    $id = (int)$s->fetchColumn(); if (!$id) return null;
    $x = cand_get($db, $id);
    if (!$x || $x['removed_at'] !== null || cand_stage_ends((string)$x['stage'])) { cand_end($db, null, $id, 'Application closed: ' . (CAND_STAGES[$x['stage'] ?? ''] ?? 'candidate switched off')); return null; }
    if ($touch) $db->prepare('UPDATE cand_access SET last_seen = NOW() WHERE id = ?')->execute([$id]);
    return $x;
}
/** Interviews of the application with the candidate's answer for the current time. */
function cand_ivs(PDO $db, array $x): array {
    $s = $db->prepare("SELECT i.* FROM rc_interview i WHERE i.application_id = ? AND i.status <> 'cancelled' ORDER BY i.at"); $s->execute([(int)$x['application_id']]); $ivs = $s->fetchAll();
    $a = $db->prepare('SELECT * FROM cand_iv_answer WHERE access_id = ? ORDER BY id'); $a->execute([(int)$x['id']]); $ans = $a->fetchAll();
    $names = $db->prepare('SELECT name FROM users WHERE id = ?');
    foreach ($ivs as &$i) {
        $i['answer'] = cand_iv_state(array_filter($ans, fn($r) => (int)$r['interview_id'] === (int)$i['id']), (string)$i['at']);
        $i['panel_names'] = []; foreach (array_filter(array_map('intval', explode(',', (string)$i['panel']))) as $pid) { $names->execute([$pid]); if ($n = $names->fetchColumn()) $i['panel_names'][] = (string)$n; }
    }
    return $ivs;
}
function cand_iv_answer(PDO $db, array $x, int $ivId, string $answer, string $note, ?string $now = null): void {
    $iv = array_column(cand_ivs($db, $x), null, 'id')[$ivId] ?? null; $now ??= date('Y-m-d H:i:s');
    if (!$iv) throw new RuntimeException('Interview not found.');
    if (!cand_iv_open($iv, $now)) throw new RuntimeException('This interview cannot be answered any more.');
    if (!in_array($answer, ['attend', 'resched'], true)) throw new RuntimeException('Pick an answer.');
    $note = mb_substr(trim(strip_tags($note)), 0, 400); if ($answer === 'resched' && $note === '') throw new RuntimeException('Say which times suit you.');
    $db->prepare('INSERT INTO cand_iv_answer (access_id, interview_id, for_at, answer, note) VALUES (?,?,?,?,?)')->execute([(int)$x['id'], $ivId, $iv['at'], $answer, $note]);
    cand_log($db, (int)$x['id'], null, 'iv_' . $answer, 'round ' . $iv['round']);
    cand_tell_hr($db, $x, $answer === 'attend' ? 'confirmed the interview' : 'asked to reschedule the interview');
}
function cand_detail_send(PDO $db, array $x, array $in): void {
    [$d, $e] = cand_detail_check($in); if ($e) throw new RuntimeException(implode(' ', $e));
    $db->prepare('INSERT INTO cand_detail (access_id, data) VALUES (?,?)')->execute([(int)$x['id'], json_encode($d, JSON_UNESCAPED_UNICODE)]);
    cand_log($db, (int)$x['id'], null, 'details', implode(', ', array_keys(array_filter($d, fn($v) => $v !== ''))));
    cand_tell_hr($db, $x, 'sent their details');
}
function cand_cv_send(PDO $db, array $x, string $bytes, string $name): void {
    rc_cv_add($db, ['id' => 0], (int)$x['candidate_id'], $bytes, $name);
    cand_log($db, (int)$x['id'], null, 'cv'); cand_tell_hr($db, $x, 'sent a CV');
}
/** Issued letters addressed to the access email (any answer state). */
function cand_letters(PDO $db, array $x): array {
    $s = $db->prepare("SELECT l.*, t.name AS tname FROM letter l JOIN letter_template t ON t.id = l.template_id WHERE l.status IN ('issued','lapsed') AND LOWER(l.email) = ? ORDER BY l.issued_at DESC"); $s->execute([strtolower((string)$x['email'])]);
    return $s->fetchAll();
}
function cand_letter(PDO $db, array $x, int $lid): ?array { foreach (cand_letters($db, $x) as $l) if ((int)$l['id'] === $lid) return $l; return null; }
/** One notice to the HR login that made the access. */
function cand_tell_hr(PDO $db, array $x, string $what): void {
    if (!module_on('email') || !function_exists('mail_queue')) return;
    try { $s = $db->prepare('SELECT email FROM users WHERE id = ? AND active = 1'); $s->execute([(int)$x['created_by']]); $to = (string)$s->fetchColumn();
          if (filter_var($to, FILTER_VALIDATE_EMAIL)) mail_queue($db, ['to' => $to, 'subject' => 'Candidate ' . $x['name'] . ' ' . $what, 'text' => $x['name'] . ' ' . $what . ' on the candidate portal.'], 'notice', 'cand:' . $x['id']); } catch (Throwable $e) {}
}
