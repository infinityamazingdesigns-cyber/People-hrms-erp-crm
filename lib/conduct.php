<?php
// Feedback & conduct — rules. Feedback notes and monthly check-ins by people above the person on the org chart (or HR),
// conduct cases where the severity and what is already on file decide the step, HR review by someone other than the
// raiser, approval for serious matters, issue, the person's acknowledgement and reply, close, appeal of a warning,
// lapse after months; the code of conduct each login accepts. Pure functions first, then the database half.
declare(strict_types=1);
require_once __DIR__ . '/orgchart.php';

const DC_SEV = ['minor' => 'Minor', 'moderate' => 'Moderate', 'serious' => 'Serious', 'gross' => 'Gross misconduct'];
const DC_SEV_START = ['minor' => 'memo', 'moderate' => 'memo', 'serious' => 'showcause', 'gross' => 'showcause'];
const DC_STEPS = ['memo' => 'Memo', 'showcause' => 'Show-cause notice', 'warning' => 'Warning letter', 'final' => 'Final warning'];
const DC_STEP_RANK = ['memo' => 1, 'showcause' => 2, 'warning' => 3, 'final' => 4];
const DC_CAT = ['attendance' => 'Attendance & punctuality', 'conduct' => 'Conduct & behaviour', 'performance' => 'Work & performance', 'safety' => 'Safety', 'other' => 'Other'];
const DC_NOTE_KINDS = ['appreciation' => 'Appreciation', 'improve' => 'Needs improvement', 'concern' => 'Concern'];
const DC_CHECK = ['on_track' => 'On track', 'support' => 'Needs support', 'concern' => 'Concern'];
const DC_STATUS = ['review_hr' => 'With HR for review', 'review_appr' => 'Waiting for approval', 'returned' => 'Sent back to the raiser', 'issued' => 'Issued',
                   'closed' => 'Closed', 'withdrawn' => 'Withdrawn', 'rejected' => 'Not issued'];
const DC_APPEAL_STEPS = ['warning', 'final'];
const DC_FILE_BYTES = 10 * 1024 * 1024;
const DC_FILES_MAX = 12;
const DC_DEFAULTS = ['hr_roles' => ['SUPERADMIN', 'HRMANAGER', 'HROPS'], 'approve_roles' => ['CEO'], 'judge_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO'], 'see_all_roles' => ['CEO'],
    'approve_for' => ['serious', 'gross'], 'safety_serious' => true, 'reply_hours' => 48, 'appeal_days' => 7, 'repeat_months' => 3,
    'lapse' => ['minor' => 6, 'moderate' => 12, 'serious' => 12, 'gross' => 12], 'signatory' => 'HR Manager',
    'letter' => ['noticed' => 'It has been brought to our notice that on %s: ', 'expected' => 'What is expected: ',
        'show' => 'This conduct, if proved, is a serious breach of company rules. You are asked to show cause in writing, within %d hours of receiving this notice, why disciplinary action should not be taken against you.',
        'warn' => 'This is not acceptable, and you are hereby warned to be careful in future.',
        'final' => 'This has happened again despite the earlier action on your record. This is your final warning.',
        'warn2' => 'This warning will be kept in your personnel file. Any repetition may lead to stricter disciplinary action.',
        'final2' => 'Any further lapse will lead to strict disciplinary action, which may include termination of employment.',
        'appeal' => 'You may give your written reply within %d hours, and you may appeal against this warning within %d days.',
        'reply' => 'Please give your written explanation within %d hours of receiving this letter. If no explanation is received in time, the matter will be decided on the facts on record.',
        'clause' => 'This is in breach of the Code of Conduct: %s.']];

function dc_settings(): array {
    $s = module_states()['conduct']['settings'] ?? [];
    $o = array_replace(DC_DEFAULTS, array_intersect_key($s, DC_DEFAULTS));
    $o['letter'] = array_replace(DC_DEFAULTS['letter'], array_intersect_key((array)$o['letter'], DC_DEFAULTS['letter']));
    $o['lapse'] = array_replace(DC_DEFAULTS['lapse'], array_intersect_key((array)$o['lapse'], DC_DEFAULTS['lapse']));
    return $o;
}

function dc_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['hr_roles' => 'HR review', 'approve_roles' => 'approval', 'judge_roles' => 'appeals'] as $k => $l) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = "Pick at least one role for $l.";
    }
    $o['see_all_roles'] = array_values(array_intersect(array_keys($roles), (array)($in['see_all_roles'] ?? [])));
    $o['approve_for'] = array_values(array_intersect(array_keys(DC_SEV), (array)($in['approve_for'] ?? [])));
    $o['safety_serious'] = !empty($in['safety_serious']);
    $o['reply_hours'] = max(12, min(240, (int)($in['reply_hours'] ?? 48)));
    $o['appeal_days'] = max(1, min(60, (int)($in['appeal_days'] ?? 7)));
    $o['repeat_months'] = max(1, min(24, (int)($in['repeat_months'] ?? 3)));
    $o['lapse'] = []; foreach (DC_SEV as $k => $_) $o['lapse'][$k] = max(1, min(60, (int)($in['lapse'][$k] ?? DC_DEFAULTS['lapse'][$k])));
    $o['signatory'] = mb_substr(trim(strip_tags((string)($in['signatory'] ?? ''))), 0, 60) ?: 'HR Manager';
    $o['letter'] = [];
    foreach (DC_DEFAULTS['letter'] as $k => $d) {
        $v = trim(strip_tags(str_replace("\r", '', (string)($in['letter'][$k] ?? ''))));
        $v = $v === '' ? $d : mb_substr($v, 0, 800);
        if (substr_count($v, '%') !== substr_count($d, '%')) { $e[] = "Letter wording \"$k\": keep the %s / %d markers as they are."; $v = $d; }
        $o['letter'][$k] = $v;
    }
    return [$o, $e];
}

/**
 * The step a new case starts at. Minor matters need a recorded improvement / concern note (or check-in) in the
 * last months first; a repeat goes one step above the highest open case (minor ones only count when recent);
 * show-cause only for serious matters. Pure. $open = issued, not lapsed, not withdrawn cases [step, severity, issued_at].
 */
function dc_next_step_for(string $sev, array $open, bool $recentNote, string $today, int $repeatMonths): array {
    if (!isset(DC_SEV[$sev])) throw new InvalidArgumentException('Choose how serious the matter is.');
    if ($sev === 'minor' && !$recentNote) return ['step' => 'feedback', 'why' => 'For a minor matter, give a recorded feedback note first. A memo follows only if it repeats.'];
    if ($sev === 'minor') {
        $from = date('Y-m-d', strtotime($today . " -$repeatMonths months"));
        $open = array_values(array_filter($open, fn($c) => substr((string)$c['issued_at'], 0, 10) >= $from));
    }
    $hi = 0; $hiCase = null;
    foreach ($open as $c) if ((DC_STEP_RANK[$c['step']] ?? 0) > $hi) { $hi = DC_STEP_RANK[$c['step']]; $hiCase = $c; }
    $start = DC_STEP_RANK[DC_SEV_START[$sev]];
    $next = $hi ? min(4, $hi + 1) : 0;
    if ($next === 2 && !in_array($sev, ['serious', 'gross'], true)) $next = 3;   // after a memo comes a warning
    $step = (string)array_search(max($start, $next), DC_STEP_RANK, true);
    return ['step' => $step, 'why' => $hiCase ? 'Already on file: ' . DC_STEPS[$hiCase['step']] . ($hiCase['ref'] ?? '' ? ' ' . $hiCase['ref'] : '') . '.' : DC_SEV[$sev] . ' matters start with a ' . strtolower(DC_STEPS[DC_SEV_START[$sev]]) . '.'];
}

/** The day each issued case lapses: months by severity, counted from the latest case issued to the person. Pure. */
function dc_lapse_map(array $cases, array $lapse): array {
    $issued = array_values(array_filter($cases, fn($c) => !empty($c['issued_at']) && in_array($c['status'], ['issued', 'closed'], true)));
    $latest = ''; foreach ($issued as $c) $latest = max($latest, substr((string)$c['issued_at'], 0, 10));
    $out = [];
    foreach ($issued as $c) $out[(int)$c['id']] = date('Y-m-d', strtotime($latest . ' +' . (int)($lapse[$c['severity']] ?? 12) . ' months'));
    return $out;
}
function dc_open_of(array $cases, string $today, array $lapse): array {
    $lap = dc_lapse_map($cases, $lapse);
    return array_values(array_filter($cases, fn($c) => isset($lap[(int)$c['id']]) && $today < $lap[(int)$c['id']]));
}

/** Can this step happen now? '' = yes. Pure. */
function dc_case_problem(string $status, string $act): string {
    $ok = ['hr_issue' => ['review_hr'], 'hr_return' => ['review_hr'], 'hr_reject' => ['review_hr'], 'approve' => ['review_appr'], 'appr_return' => ['review_appr'],
           'resend' => ['returned'], 'ack' => ['issued'], 'reply' => ['issued'], 'close' => ['issued'], 'appeal' => ['issued', 'closed'], 'appeal_decide' => ['issued', 'closed']];
    return in_array($status, $ok[$act] ?? [], true) ? '' : 'Not possible now (' . (DC_STATUS[$status] ?? $status) . ').';
}

/** Is a reply late? Pure. */
function dc_reply_late(string $issuedAt, string $at, int $hours): bool { return strtotime($at) - strtotime($issuedAt) > $hours * 3600; }

/** Can an appeal still be made? Pure. */
function dc_appeal_open(array $c, string $now, int $days): bool {
    return in_array($c['step'], DC_APPEAL_STEPS, true) && !empty($c['issued_at']) && empty($c['appeal_at']) && in_array($c['status'], ['issued', 'closed'], true)
        && strtotime($now) - strtotime((string)$c['issued_at']) <= $days * 86400;
}

/** The letter text for a case. Pure. */
function dc_letter(array $c, array $set, string $name, ?string $ackedOn = null): array {
    $L = $set['letter']; $step = (string)$c['step'];
    $p = [sprintf($L['noticed'], date('d/m/Y', strtotime((string)$c['inc_date']))) . $c['what']];
    if ($step === 'showcause') $p[] = sprintf($L['show'], (int)$set['reply_hours']);
    if ($step === 'warning') $p[] = $L['warn'];
    if ($step === 'final') $p[] = $L['final'];
    $cl = json_decode((string)($c['clauses'] ?? ''), true) ?: [];
    if ($cl) $p[] = sprintf($L['clause'], implode(', ', array_map(fn($x) => $x['no'] . ' (' . $x['title'] . ')', $cl))) . ($ackedOn ? ' You accepted the Code of Conduct on ' . date('d/m/Y', strtotime($ackedOn)) . '.' : '');
    if (trim((string)$c['expected']) !== '') $p[] = $L['expected'] . $c['expected'];
    if ($step === 'warning') $p[] = $L['warn2'];
    if ($step === 'final') $p[] = $L['final2'];
    $p[] = in_array($step, DC_APPEAL_STEPS, true) ? sprintf($L['appeal'], (int)$set['reply_hours'], (int)$set['appeal_days']) : sprintf($L['reply'], (int)$set['reply_hours']);
    return ['title' => DC_STEPS[$step] ?? $step, 'subject' => (DC_STEPS[$step] ?? $step) . ' — ' . (DC_CAT[$c['category']] ?? ''), 'dear' => 'Dear ' . trim($name) . ',', 'paras' => $p,
            'sign' => in_array($c['severity'], $set['approve_for'], true) ? 'Approved by the management' : $set['signatory']];
}

/** Evidence files by their bytes: photos and PDF. Pure. */
function dc_file_type(string $b): ?string {
    if (str_starts_with($b, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) return 'image/png';
    if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') return 'image/webp';
    if (str_starts_with($b, '%PDF-')) return 'application/pdf';
    return null;
}

/** Version of the code people accept: changes when an active clause's number, title or text changes. Pure. */
function dc_code_version_of(array $clauses): string {
    $a = array_values(array_filter($clauses, fn($c) => (int)$c['active'] === 1));
    return $a ? substr(sha1(json_encode(array_map(fn($x) => [$x['no'], $x['title'], $x['text']], $a))), 0, 12) : '';
}

// ---------- database ----------

function dc_roles(array $u): array { return user_roles((int)$u['id'], !empty($u['is_owner'])); }
function dc_has(array $u, string $k): bool { return (bool)array_intersect(dc_roles($u), dc_settings()[$k]); }
function dc_is_hr(array $u): bool { return dc_has($u, 'hr_roles'); }
function dc_sees_all(array $u): bool { return dc_is_hr($u) || dc_has($u, 'see_all_roles') || dc_has($u, 'judge_roles'); }
function dc_log(PDO $db, int $caseId, string $action, int $uid): void { $db->prepare('INSERT INTO dc_log (case_id, action, user_id) VALUES (?,?,?)')->execute([$caseId, $action, $uid]); }

/** Codes of everyone below this login on the org chart. */
function dc_below(PDO $db, array $u): array {
    $me = strtoupper((string)($u['emp_code'] ?? '')); if ($me === '') return [];
    return org_team($me, org_parent_map($db->query('SELECT code, manager_code FROM employee WHERE active = 1')->fetchAll()));
}
/** Direct reports (for check-ins). */
function dc_direct(PDO $db, array $u): array {
    $me = strtoupper((string)($u['emp_code'] ?? '')); if ($me === '') return [];
    $s = $db->prepare('SELECT code, name, department FROM employee WHERE active = 1 AND UPPER(manager_code) = ? AND (last_day IS NULL OR last_day >= CURDATE()) ORDER BY name'); $s->execute([$me]);
    return $s->fetchAll();
}

/** May this login raise notes / cases about the person? Never about oneself. */
function dc_can_raise(PDO $db, array $u, string $code): bool {
    $code = strtoupper($code);
    if ($code === '' || strcasecmp($code, (string)($u['emp_code'] ?? '')) === 0) return false;
    if (dc_is_hr($u) || dc_has($u, 'see_all_roles')) { $e = emp_by_code($db, $code); return $e && emp_in_scope($u, $e); }
    return in_array($code, dc_below($db, $u), true);
}
/** May this login see the person's notes and cases? The person themselves see only their own (issued cases). */
function dc_can_see(PDO $db, array $u, string $code): bool {
    if (strcasecmp($code, (string)($u['emp_code'] ?? '')) === 0) return true;
    if (dc_sees_all($u)) { $e = emp_by_code($db, $code); return $e && emp_in_scope($u, $e); }
    return in_array(strtoupper($code), dc_below($db, $u), true);
}

function dc_note_add(PDO $db, array $u, string $code, array $in): int {
    if (!dc_can_raise($db, $u, $code)) throw new RuntimeException('Notes can be given by people above the person on the org chart or by HR — never about yourself.');
    $kind = isset(DC_NOTE_KINDS[$in['kind'] ?? '']) ? (string)$in['kind'] : throw new RuntimeException('Pick the kind of note.');
    $text = mb_substr(trim(strip_tags(str_replace("\r", '', (string)($in['text'] ?? '')))), 0, 2000);
    if ($text === '') throw new RuntimeException('Write the note.');
    $action = mb_substr(trim(strip_tags((string)($in['action'] ?? ''))), 0, 500);
    if ($kind === 'improve' && $action === '') throw new RuntimeException('Write the agreed action for an improvement note.');
    $share = $kind === 'appreciation' && !empty($in['share']) && module_on('board');
    $db->prepare('INSERT INTO dc_note (emp_code, by_user, kind, text, action, share_board) VALUES (?,?,?,?,?,?)')->execute([strtoupper($code), (int)$u['id'], $kind, $text, $action, $share ? 1 : 0]);
    $id = (int)$db->lastInsertId();
    if ($share) {
        $e = emp_by_code($db, strtoupper($code));
        $db->prepare("INSERT INTO board_post (kind, title, body, author_id) VALUES ('news', ?, ?, ?)")->execute(['Appreciation: ' . ($e['name'] ?? ''), $text, (int)$u['id']]);
        $db->prepare('UPDATE dc_note SET board_post_id = ? WHERE id = ?')->execute([(int)$db->lastInsertId(), $id]);
    }
    audit((int)$u['id'], 'dc_note', '#' . $id . ' ' . $kind);
    return $id;
}

function dc_note_remove(PDO $db, array $u, int $id, string $reason): void {
    $s = $db->prepare('SELECT * FROM dc_note WHERE id = ? AND removed_at IS NULL'); $s->execute([$id]); $n = $s->fetch();
    if (!$n || ((int)$n['by_user'] !== (int)$u['id'] && !dc_is_hr($u))) throw new RuntimeException('Only the author or HR takes a note off.');
    if (($reason = trim(strip_tags($reason))) === '') throw new RuntimeException('Say why.');
    $db->prepare('UPDATE dc_note SET removed_at = NOW(), removed_by = ?, removed_reason = ? WHERE id = ?')->execute([(int)$u['id'], mb_substr($reason, 0, 300), $id]);
}

/** Monthly check-in for a direct report (one a month; HR may give one for anyone in scope). */
function dc_checkin(PDO $db, array $u, string $code, string $ym, string $state, string $note): void {
    $direct = in_array(strtoupper($code), array_map('strtoupper', array_column(dc_direct($db, $u), 'code')), true);
    if (!$direct && !(dc_is_hr($u) && dc_can_raise($db, $u, $code))) throw new RuntimeException('Check-ins are given by the reporting manager.');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym) || $ym > date('Y-m')) throw new RuntimeException('Pick this month or an earlier one.');
    if (!isset(DC_CHECK[$state])) throw new RuntimeException('Pick how they are doing.');
    $note = mb_substr(trim(strip_tags($note)), 0, 2000);
    if ($state !== 'on_track' && $note === '') throw new RuntimeException('Say what support is needed or what the concern is.');
    try { $db->prepare('INSERT INTO dc_checkin (emp_code, ym, by_user, state, note) VALUES (?,?,?,?,?)')->execute([strtoupper($code), $ym, (int)$u['id'], $state, $note]); }
    catch (PDOException $e) { throw new RuntimeException('There is already a check-in for that month.'); }
}

/** Direct reports still without a check-in for this month. */
function dc_checkins_due(PDO $db, array $u, string $ym): array {
    $s = $db->prepare('SELECT emp_code FROM dc_checkin WHERE ym = ?'); $s->execute([$ym]); $done = array_flip($s->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter(dc_direct($db, $u), fn($p) => !isset($done[strtoupper($p['code'])])));
}

function dc_cases_of(PDO $db, string $code): array { $s = $db->prepare('SELECT * FROM dc_case WHERE emp_code = ? ORDER BY id'); $s->execute([strtoupper($code)]); return $s->fetchAll(); }
function dc_case_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT c.*, e.name, e.department, e.branch_id FROM dc_case c LEFT JOIN employee e ON e.code = c.emp_code WHERE c.id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

/** Has the person had a recorded improvement / concern note or a check-in with a concern in the last months? */
function dc_recent_note(PDO $db, string $code, string $today, int $months): bool {
    $from = date('Y-m-d', strtotime($today . " -$months months"));
    $s = $db->prepare("SELECT (SELECT COUNT(*) FROM dc_note WHERE emp_code = ? AND kind IN ('improve','concern') AND removed_at IS NULL AND created_at >= ?) + (SELECT COUNT(*) FROM dc_checkin WHERE emp_code = ? AND state IN ('support','concern') AND created_at >= ?)");
    $s->execute([strtoupper($code), $from, strtoupper($code), $from]);
    return (int)$s->fetchColumn() > 0;
}

function dc_next_step(PDO $db, string $code, string $sev, ?string $today = null): array {
    $set = dc_settings(); $today ??= date('Y-m-d');
    return dc_next_step_for($sev, dc_open_of(dc_cases_of($db, $code), $today, $set['lapse']), dc_recent_note($db, $code, $today, (int)$set['repeat_months']), $today, (int)$set['repeat_months']);
}

/** Raise a case. The step is worked out (HR may change it at review). Clauses of the code are kept as they are now. */
function dc_raise(PDO $db, array $u, array $in): int {
    $code = strtoupper(trim((string)($in['emp_code'] ?? '')));
    if (!dc_can_raise($db, $u, $code)) throw new RuntimeException('Cases can be raised by people above the person on the org chart or by HR — never about yourself.');
    $cat = isset(DC_CAT[$in['category'] ?? '']) ? (string)$in['category'] : throw new RuntimeException('Pick the category.');
    $sev = (string)($in['severity'] ?? '');
    if ($cat === 'safety' && dc_settings()['safety_serious'] && in_array($sev, ['minor', 'moderate'], true)) $sev = 'serious';
    $next = dc_next_step($db, $code, $sev);
    if ($next['step'] === 'feedback') throw new RuntimeException($next['why']);
    $d = (string)($in['inc_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d > date('Y-m-d')) throw new RuntimeException('Give the day it happened (not in the future).');
    $what = mb_substr(trim(strip_tags(str_replace("\r", '', (string)($in['what'] ?? '')))), 0, 3000);
    if ($what === '') throw new RuntimeException('Write what happened.');
    $cl = [];
    $ids = array_slice(array_map('intval', (array)($in['clauses'] ?? [])), 0, 5);
    if ($ids) { $q = $db->prepare('SELECT no, title FROM dc_code WHERE active = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute($ids); $cl = $q->fetchAll(); }
    $db->prepare('INSERT INTO dc_case (emp_code, category, severity, step, inc_date, what, expected, clauses, raised_by) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$code, $cat, $sev, $next['step'], $d, $what, mb_substr(trim(strip_tags((string)($in['expected'] ?? ''))), 0, 1500), $cl ? json_encode($cl) : null, (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    $db->prepare("UPDATE dc_case SET ref = CONCAT('DC-', YEAR(created_at), '-', LPAD(?, 4, '0')) WHERE id = ?")->execute([$id, $id]);
    dc_log($db, $id, 'raise', (int)$u['id']); audit((int)$u['id'], 'dc_raise', '#' . $id);
    return $id;
}

/** HR review: issue (or send for approval), send back with a note, or decide not to issue. Another HR person than the raiser, never about themselves. */
function dc_hr_review(PDO $db, array $u, int $id, string $act, array $in): void {
    $c = dc_case_get($db, $id);
    if (!$c || !dc_is_hr($u) || !emp_in_scope($u, $c)) throw new RuntimeException('Only HR reviews cases.');
    if ((int)$c['raised_by'] === (int)$u['id']) throw new RuntimeException('Another HR person reviews a case you raised.');
    if (strcasecmp($c['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('You cannot review a case about yourself.');
    if (($p = dc_case_problem($c['status'], 'hr_' . $act)) !== '') throw new RuntimeException($p);
    $note = mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 500);
    if ($act !== 'issue' && $note === '') throw new RuntimeException('Write a note.');
    $set = dc_settings();
    $sev = isset(DC_SEV[$in['severity'] ?? '']) ? (string)$in['severity'] : $c['severity'];
    $step = isset(DC_STEPS[$in['step'] ?? '']) ? (string)$in['step'] : $c['step'];
    if ($step === 'showcause' && !in_array($sev, ['serious', 'gross'], true)) throw new RuntimeException('A show-cause notice is for serious matters.');
    $what = trim((string)($in['what'] ?? '')) !== '' ? mb_substr(trim(strip_tags((string)$in['what'])), 0, 3000) : $c['what'];
    $expected = array_key_exists('expected', $in) ? mb_substr(trim(strip_tags((string)$in['expected'])), 0, 1500) : $c['expected'];
    $to = ['issue' => in_array($sev, $set['approve_for'], true) ? 'review_appr' : 'issued', 'return' => 'returned', 'reject' => 'rejected'][$act];
    $db->prepare('UPDATE dc_case SET severity = ?, step = ?, what = ?, expected = ?, status = ?, hr_by = ?, hr_at = NOW(), hr_note = ?' . ($to === 'issued' ? ', issued_at = NOW()' : '') . ' WHERE id = ?')
       ->execute([$sev, $step, $what, $expected, $to, (int)$u['id'], $note, $id]);
    dc_log($db, $id, 'hr_' . $act, (int)$u['id']); audit((int)$u['id'], 'dc_hr_' . $act, '#' . $id);
    if ($to === 'issued') dc_tell($db, $id, 'issued');
}

/** Approval for serious matters: approve (issued) or send back to HR with a note. Not the raiser, never about oneself. */
function dc_approve(PDO $db, array $u, int $id, bool $ok, string $note): void {
    $c = dc_case_get($db, $id);
    if (!$c || !dc_has($u, 'approve_roles')) throw new RuntimeException('Only the approver decides this.');
    if ((int)$c['raised_by'] === (int)$u['id'] || strcasecmp($c['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Not on a case you raised or one about yourself.');
    if (($p = dc_case_problem($c['status'], $ok ? 'approve' : 'appr_return')) !== '') throw new RuntimeException($p);
    if (!$ok && trim($note) === '') throw new RuntimeException('Write what to change.');
    $db->prepare('UPDATE dc_case SET status = ?, appr_by = ?, appr_at = NOW(), appr_note = ?' . ($ok ? ', issued_at = NOW()' : '') . ' WHERE id = ?')->execute([$ok ? 'issued' : 'review_hr', (int)$u['id'], mb_substr(trim(strip_tags($note)), 0, 500), $id]);
    dc_log($db, $id, $ok ? 'approve' : 'appr_return', (int)$u['id']); audit((int)$u['id'], $ok ? 'dc_approve' : 'dc_appr_return', '#' . $id);
    if ($ok) dc_tell($db, $id, 'issued');
}

/** The raiser sends a returned case again (with the changes). */
function dc_resend(PDO $db, array $u, int $id, array $in): void {
    $c = dc_case_get($db, $id);
    if (!$c || (int)$c['raised_by'] !== (int)$u['id']) throw new RuntimeException('Only the person who raised it sends it again.');
    if (($p = dc_case_problem($c['status'], 'resend')) !== '') throw new RuntimeException($p);
    $what = mb_substr(trim(strip_tags((string)($in['what'] ?? ''))), 0, 3000) ?: $c['what'];
    $db->prepare("UPDATE dc_case SET what = ?, expected = ?, status = 'review_hr' WHERE id = ?")->execute([$what, mb_substr(trim(strip_tags((string)($in['expected'] ?? $c['expected']))), 0, 1500), $id]);
    dc_log($db, $id, 'resend', (int)$u['id']);
}

/** The person acknowledges and replies (own case). A reply after the hours is kept and marked late. HR may record a paper reply. */
function dc_reply(PDO $db, array $u, int $id, string $text, bool $ackOnly): void {
    $c = dc_case_get($db, $id);
    $own = $c && strcasecmp($c['emp_code'], (string)($u['emp_code'] ?? '')) === 0;
    if (!$c || (!$own && !dc_is_hr($u))) throw new RuntimeException('Not found.');
    if ($c['status'] !== 'issued') throw new RuntimeException('Replies are taken while the case is open.');
    if ($ackOnly) { if (!$own) throw new RuntimeException('Only the person acknowledges.'); $db->prepare('UPDATE dc_case SET ack_at = COALESCE(ack_at, NOW()) WHERE id = ?')->execute([$id]); dc_log($db, $id, 'ack', (int)$u['id']); return; }
    if ($c['reply_at']) throw new RuntimeException('The reply is already on file.');
    if (($text = mb_substr(trim(strip_tags(str_replace("\r", '', $text))), 0, 4000)) === '') throw new RuntimeException('Write the reply.');
    $late = dc_reply_late((string)$c['issued_at'], date('Y-m-d H:i:s'), (int)dc_settings()['reply_hours']);
    $db->prepare('UPDATE dc_case SET reply = ?, reply_at = NOW(), reply_late = ?, reply_by = ?, ack_at = COALESCE(ack_at, NOW()) WHERE id = ?')->execute([($own ? '' : '[Recorded by HR from a written reply] ') . $text, $late ? 1 : 0, (int)$u['id'], $id]);
    dc_log($db, $id, $own ? 'reply' : 'reply_hr', (int)$u['id']);
}

/** HR closes after the reply (or once the reply time is over) with the outcome. */
function dc_close(PDO $db, array $u, int $id, string $outcome): void {
    $c = dc_case_get($db, $id);
    if (!$c || !dc_is_hr($u) || strcasecmp($c['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Only HR closes cases — never about themselves.');
    if (($p = dc_case_problem($c['status'], 'close')) !== '') throw new RuntimeException($p);
    if (!$c['reply_at'] && !dc_reply_late((string)$c['issued_at'], date('Y-m-d H:i:s'), (int)dc_settings()['reply_hours'])) throw new RuntimeException('Wait for the reply or for the reply time to end.');
    if (($outcome = mb_substr(trim(strip_tags($outcome)), 0, 500)) === '') throw new RuntimeException('Write the outcome.');
    $db->prepare("UPDATE dc_case SET status = 'closed', outcome = ?, closed_by = ?, closed_at = NOW() WHERE id = ?")->execute([$outcome, (int)$u['id'], $id]);
    dc_log($db, $id, 'close', (int)$u['id']); audit((int)$u['id'], 'dc_close', '#' . $id);
}

/** The person appeals a warning / final warning within the days. */
function dc_appeal(PDO $db, array $u, int $id, string $text): void {
    $c = dc_case_get($db, $id);
    if (!$c || strcasecmp($c['emp_code'], (string)($u['emp_code'] ?? '')) !== 0) throw new RuntimeException('Only the person appeals their own case.');
    if (!dc_appeal_open($c, date('Y-m-d H:i:s'), (int)dc_settings()['appeal_days'])) throw new RuntimeException('An appeal is possible only against a warning, once, within ' . (int)dc_settings()['appeal_days'] . ' days.');
    if (($text = mb_substr(trim(strip_tags(str_replace("\r", '', $text))), 0, 4000)) === '') throw new RuntimeException('Write the appeal.');
    $db->prepare('UPDATE dc_case SET appeal = ?, appeal_at = NOW() WHERE id = ?')->execute([$text, $id]);
    dc_log($db, $id, 'appeal', (int)$u['id']);
}

/** An appeal decided by a judge role (not the raiser): upheld = the case is withdrawn (kept, marked), dismissed = it stands. */
function dc_appeal_decide(PDO $db, array $u, int $id, bool $upheld, string $note): void {
    $c = dc_case_get($db, $id);
    if (!$c || !dc_has($u, 'judge_roles') || !$c['appeal_at'] || $c['appeal_result']) throw new RuntimeException('Nothing to decide.');
    if ((int)$c['raised_by'] === (int)$u['id'] || strcasecmp($c['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Not on a case you raised or one about yourself.');
    if (($note = mb_substr(trim(strip_tags($note)), 0, 500)) === '') throw new RuntimeException('Write the reason.');
    $db->prepare('UPDATE dc_case SET appeal_result = ?, appeal_note = ?, appeal_by = ?, appeal_done_at = NOW()' . ($upheld ? ", status = 'withdrawn'" : '') . ' WHERE id = ?')->execute([$upheld ? 'upheld' : 'dismissed', $note, (int)$u['id'], $id]);
    dc_log($db, $id, $upheld ? 'appeal_upheld' : 'appeal_dismissed', (int)$u['id']); audit((int)$u['id'], 'dc_appeal', '#' . $id);
}

function dc_file_add(PDO $db, array $u, int $id, string $bytes, string $name, string $note): void {
    $c = dc_case_get($db, $id);
    if (!$c || strcasecmp($c['emp_code'], (string)($u['emp_code'] ?? '')) === 0 || !dc_can_see($db, $u, $c['emp_code'])) throw new RuntimeException('Not found.');
    if (strlen($bytes) > DC_FILE_BYTES || $bytes === '') throw new RuntimeException('Files up to 10 MB.');
    $t = dc_file_type($bytes); if (!$t) throw new RuntimeException('Photos (JPEG, PNG, WebP) or PDF only.');
    $n = $db->prepare('SELECT COUNT(*) FROM dc_file WHERE case_id = ?'); $n->execute([$id]);
    if ((int)$n->fetchColumn() >= DC_FILES_MAX) throw new RuntimeException('Up to ' . DC_FILES_MAX . ' files on a case.');
    $s = $db->prepare('INSERT INTO dc_file (id, case_id, name, type, size, data, note, uploaded_by) VALUES (?,?,?,?,?,?,?,?)');
    $s->bindValue(1, bin2hex(random_bytes(16))); $s->bindValue(2, $id); $s->bindValue(3, mb_substr((string)preg_replace('/[^\pL\pN ._()-]/u', '_', $name), 0, 180)); $s->bindValue(4, $t); $s->bindValue(5, strlen($bytes));
    $s->bindValue(6, $bytes, PDO::PARAM_LOB); $s->bindValue(7, mb_substr(trim(strip_tags($note)), 0, 300)); $s->bindValue(8, (int)$u['id']);
    $s->execute();
    dc_log($db, $id, 'file', (int)$u['id']);
}

/** Cases this login sees: the person their own issued ones; the chain above and HR / see-all roles the rest. */
function dc_case_visible(PDO $db, array $u, array $c): bool {
    if (strcasecmp($c['emp_code'], (string)($u['emp_code'] ?? '')) === 0) return in_array($c['status'], ['issued', 'closed', 'withdrawn'], true);
    return (int)$c['raised_by'] === (int)$u['id'] || dc_can_see($db, $u, $c['emp_code']);
}

/** Tell the person by email that a letter is issued (Email on). Never the text. */
function dc_tell(PDO $db, int $id, string $what): void {
    if (!module_on('email') || !function_exists('mail_queue')) return;
    try {
        $c = dc_case_get($db, $id); $s = $db->prepare('SELECT email FROM users WHERE emp_code = ? AND active = 1'); $s->execute([$c['emp_code']]); $to = (string)$s->fetchColumn();
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) mail_queue($db, ['to' => $to, 'subject' => 'A letter for you: ' . (DC_STEPS[$c['step']] ?? ''), 'text' => "There is a letter for you in Feedback & conduct ($c[ref]). Please read it and give your reply within " . (int)dc_settings()['reply_hours'] . ' hours.'], 'notice', 'dc:' . $id);
    } catch (Throwable $e) { error_log('dc notice: ' . $e->getMessage()); }
}

/** Waiting for this login: HR reviews, approvals, appeals, own letters to reply, own code to accept, check-ins due. */
function dc_waiting(PDO $db, array $u): array {
    $o = ['hr' => 0, 'approve' => 0, 'appeal' => 0, 'reply' => 0, 'code' => 0, 'checkins' => 0];
    $all = $db->query("SELECT c.*, e.branch_id FROM dc_case c LEFT JOIN employee e ON e.code = c.emp_code WHERE c.status IN ('review_hr','review_appr','issued','closed')")->fetchAll();
    $me = strtoupper((string)($u['emp_code'] ?? ''));
    foreach ($all as $c) {
        $mine = strcasecmp($c['emp_code'], $me) === 0;
        if ($c['status'] === 'review_hr' && dc_is_hr($u) && !$mine && (int)$c['raised_by'] !== (int)$u['id'] && emp_in_scope($u, $c)) $o['hr']++;
        if ($c['status'] === 'review_appr' && dc_has($u, 'approve_roles') && !$mine && (int)$c['raised_by'] !== (int)$u['id']) $o['approve']++;
        if ($c['appeal_at'] && !$c['appeal_result'] && dc_has($u, 'judge_roles') && !$mine && (int)$c['raised_by'] !== (int)$u['id']) $o['appeal']++;
        if ($mine && $c['status'] === 'issued' && !$c['reply_at']) $o['reply']++;
    }
    $v = dc_code_version_of($db->query('SELECT * FROM dc_code')->fetchAll());
    if ($v !== '' && $me !== '') { $s = $db->prepare('SELECT COUNT(*) FROM dc_code_ack WHERE user_id = ? AND version = ?'); $s->execute([(int)$u['id'], $v]); $o['code'] = (int)$s->fetchColumn() ? 0 : 1; }
    $o['checkins'] = count(dc_checkins_due($db, $u, date('Y-m')));
    return $o;
}

function dc_code_save(PDO $db, array $u, int $id, array $in): void {
    if (!dc_is_hr($u)) throw new RuntimeException('Only HR writes the code of conduct.');
    $no = mb_substr(trim(strip_tags((string)($in['no'] ?? ''))), 0, 10); $title = mb_substr(trim(strip_tags((string)($in['title'] ?? ''))), 0, 120); $text = mb_substr(trim(strip_tags(str_replace("\r", '', (string)($in['text'] ?? '')))), 0, 4000);
    if ($no === '' || $title === '' || $text === '') throw new RuntimeException('Give the clause a number, a title and its text.');
    if ($id) $db->prepare('UPDATE dc_code SET no = ?, title = ?, text = ?, sort = ?, active = ?, updated_by = ? WHERE id = ?')->execute([$no, $title, $text, (int)($in['sort'] ?? 0), !empty($in['active']) ? 1 : 0, (int)$u['id'], $id]);
    else $db->prepare('INSERT INTO dc_code (no, title, text, sort, updated_by) VALUES (?,?,?,?,?)')->execute([$no, $title, $text, (int)($in['sort'] ?? 0), (int)$u['id']]);
}
