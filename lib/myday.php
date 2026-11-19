<?php
// My day — the signed-in login's own day on one page: today's punches, every request of theirs on one tracker (attendance
// corrections, leave, expense claims, letter requests, profile updates, asset issues) with where each stands, letters
// issued to them, open tasks given to them, unread notices, latest announcements, quick actions to the screens they may
// open. Read only, own data only (the session's login and its linked employee code — no parameter picks a person), never
// a money figure. Each part shows while its module is on. Pure functions first.
declare(strict_types=1);

const MD_DAYS = 30;   // closed requests shown this many days
const MD_MAX = 25;    // requests on the tracker at most
/** kind => [label, module, link to the own screen]. */
const MD_KINDS = ['att' => ['Attendance correction', 'attendance', 'modules/attendance/requests.php'], 'leave' => ['Leave', 'leave', 'modules/leave/index.php'],
                  'claim' => ['Expense claim', 'claims', 'modules/claims/index.php'], 'letter' => ['Letter request', 'letters', 'modules/letters/mine.php'],
                  'profile' => ['Profile update', 'profile', 'modules/profile/index.php'], 'asset' => ['Asset issue', 'assets', 'modules/assets/mine.php']];

/* ---------------- pure ---------------- */

/** Where a request stands: steps, the step it is on, open / done / stopped, a short line. */
function md_stage(string $kind, array $r): array {
    $st = (string)$r['status'];
    $two = fn(array $steps, array $at, array $done, array $stop) => ['steps' => $steps, 'at' => $at[$st] ?? 0, 'state' => in_array($st, $done, true) ? 'done' : (in_array($st, $stop, true) ? 'stopped' : 'open')];
    switch ($kind) {
        case 'att': case 'leave':
            $o = $two(['Sent', 'Reporting manager', 'HR', 'Approved'], ['manager' => 1, 'hr' => 2, 'approved' => 3], ['approved'], ['rejected', 'withdrawn', 'cancelled']);
            if ($kind === 'leave' && in_array($r['wd_status'] ?? '', ['manager', 'hr'], true)) { $o['state'] = 'open'; $o['line'] = 'Asked to withdraw — with ' . ($r['wd_status'] === 'manager' ? 'your reporting manager' : 'HR'); return $o; }
            $o['line'] = ['manager' => 'With your reporting manager', 'hr' => 'With HR', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn', 'cancelled' => 'Withdrawn'][$st] ?? $st; return $o;
        case 'claim':
            $o = $two(['Sent', 'HR audit', 'Approval', 'Payment', 'Paid'], ['draft' => 0, 'submitted' => 1, 'returned' => 1, 'audited' => 2, 'approved' => 3, 'paid' => 4], ['paid'], ['rejected', 'withdrawn']);
            $o['line'] = ['draft' => 'Draft — not sent yet', 'submitted' => 'With HR for audit', 'returned' => 'Returned to you — fix and send again', 'audited' => 'Waiting for approval', 'approved' => 'Approved — waiting for payment', 'paid' => 'Paid', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'][$st] ?? $st; return $o;
        case 'letter': $o = $two(['Asked', 'HR', 'Done'], ['open' => 1, 'done' => 2], ['done'], ['closed']); $o['line'] = ['open' => 'With HR', 'done' => 'Letter issued', 'closed' => 'Closed by HR'][$st] ?? $st; return $o;
        case 'profile': $o = $two(['Sent', 'HR', 'Done'], ['hr' => 1, 'approved' => 2, 'partly' => 2], ['approved', 'partly'], ['rejected', 'withdrawn']); $o['line'] = ['hr' => 'With HR', 'approved' => 'Accepted', 'partly' => 'Partly accepted', 'rejected' => 'Sent back', 'withdrawn' => 'Withdrawn'][$st] ?? $st; return $o;
        case 'asset': $o = $two(['Reported', 'HR / IT', 'Resolved'], ['open' => 1, 'resolved' => 2, 'closed' => 2], ['resolved', 'closed'], []); $o['line'] = $o['state'] === 'done' ? 'Resolved' : 'Being looked at'; return $o;
    }
    return ['steps' => [], 'at' => 0, 'state' => 'open', 'line' => $st];
}
/** Open ones oldest first, then closed ones of the last $days newest first; at most $max. Items: [kind, at, stage …]. */
function md_sort(array $items, string $today, int $days = MD_DAYS, int $max = MD_MAX): array {
    $from = date('Y-m-d', strtotime($today . ' -' . $days . ' day'));
    $open = array_values(array_filter($items, fn($i) => $i['stage']['state'] === 'open'));
    $closed = array_values(array_filter($items, fn($i) => $i['stage']['state'] !== 'open' && substr((string)$i['at'], 0, 10) >= $from));
    usort($open, fn($a, $b) => strcmp((string)$a['at'], (string)$b['at'])); usort($closed, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    return array_slice(array_merge($open, $closed), 0, $max);
}
/** Today's punches → first in, last out, count, what to do next. Times "HH:MM:SS" (after midnight may read 25:10). */
function md_today(array $punches): array {
    usort($punches, fn($a, $b) => strcmp((string)$a['time'], (string)$b['time']));
    $in = null; $out = null;
    foreach ($punches as $p) { if ($p['type'] === 'IN' && $in === null) $in = $p['time']; if ($p['type'] === 'OUT') $out = $p['time']; }
    $last = $punches ? end($punches)['type'] : '';
    return ['in' => $in ? substr($in, 0, 5) : null, 'out' => $out && $last === 'OUT' ? substr($out, 0, 5) : null, 'n' => count($punches), 'next' => $last === 'IN' ? 'out' : 'in'];
}
function md_greeting(int $hour): string { return $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'); }

/* ---------------- database ---------------- */

function md_rows(PDO $db, string $sql, array $p): array { try { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Throwable $e) { return []; } }
function md_payload(PDO $db, array $u, string $today): array {
    $uid = (int)$u['id']; $code = (string)($u['emp_code'] ?? '');
    if ($code === '') { $s = $db->prepare('SELECT emp_code FROM users WHERE id = ?'); $s->execute([$uid]); $code = (string)$s->fetchColumn(); }
    $since = date('Y-m-d', strtotime($today . ' -' . MD_DAYS . ' day')) . ' 00:00:00';
    $o = ['code' => $code !== '', 'today' => null, 'requests' => [], 'letters' => [], 'tasks' => null, 'notices' => 0, 'posts' => [], 'links' => []];
    $mine = $code !== '' ? '(user_id = ? OR emp_code = ?)' : 'user_id = ?'; $mp = $code !== '' ? [$uid, $code] : [$uid];
    $on = fn(string $k) => module_on($k);
    if ($on('attendance') && $code !== '') $o['today'] = md_today(md_rows($db, 'SELECT type, time FROM attendance_punch WHERE emp_code = ? AND day = ?', [$code, $today]));
    $items = [];
    $add = function (string $kind, array $rows, string $what) use (&$items) { foreach ($rows as $r) $items[] = ['kind' => $kind, 'id' => (int)$r['id'], 'what' => $what === '' ? '' : (string)($r[$what] ?? ''), 'at' => (string)$r['at'], 'stage' => md_stage($kind, $r)]; };
    if ($on('attendance')) $add('att', md_rows($db, "SELECT id, status, DATE_FORMAT(day, '%e %b') AS what, created_at AS at FROM attendance_request WHERE $mine AND (status IN ('manager','hr') OR created_at >= ?)", [...$mp, $since]), 'what');
    if ($on('leave')) $add('leave', md_rows($db, "SELECT id, status, wd_status, CONCAT(DATE_FORMAT(from_date, '%e %b'), IF(to_date > from_date, CONCAT(' – ', DATE_FORMAT(to_date, '%e %b')), '')) AS what, COALESCE(wd_at, created_at) AS at FROM leave_request WHERE $mine AND (status IN ('manager','hr') OR wd_status IN ('manager','hr') OR created_at >= ? OR wd_at >= ?)", [...$mp, $since, $since]), 'what');
    if ($on('claims')) $add('claim', md_rows($db, "SELECT id, status, CONCAT(claim_no, ' ', title) AS what, COALESCE(updated_at, created_at) AS at FROM ec_claim WHERE user_id = ? AND (status IN ('draft','submitted','returned','audited','approved') OR updated_at >= ?)", [$uid, $since]), 'what');
    if ($on('letters')) $add('letter', md_rows($db, "SELECT r.id, r.status, t.name AS what, COALESCE(r.done_at, r.created_at) AS at FROM letter_request r LEFT JOIN letter_template t ON t.id = r.template_id WHERE r.$mine AND (r.status = 'open' OR r.created_at >= ? OR r.done_at >= ?)", [...$mp, $since, $since]), 'what');
    if ($on('profile')) $add('profile', md_rows($db, "SELECT id, status, '' AS what, COALESCE(decided_at, created_at) AS at FROM employee_profile_change WHERE $mine AND (status = 'hr' OR created_at >= ? OR decided_at >= ?)", [...$mp, $since, $since]), '');
    if ($on('assets')) $add('asset', md_rows($db, "SELECT id, status, type AS what, COALESCE(resolved_at, created_at) AS at FROM asset_issue WHERE $mine AND (status = 'open' OR created_at >= ? OR resolved_at >= ?)", [...$mp, $since, $since]), 'what');
    $o['requests'] = md_sort($items, $today);
    if ($on('letters') && $code !== '') $o['letters'] = md_rows($db, "SELECT l.id, l.ref, t.name, l.issued_at FROM letter l LEFT JOIN letter_template t ON t.id = l.template_id WHERE l.emp_code = ? AND l.status = 'issued' ORDER BY l.issued_at DESC LIMIT 5", [$code]);
    if ($on('tasks')) { $t = md_rows($db, "SELECT SUM(due_date < ?) late, SUM(due_date = ?) today, COUNT(*) n FROM task WHERE assignee_id = ? AND done_at IS NULL AND removed_at IS NULL", [$today, $today, $uid])[0] ?? null; $o['tasks'] = $t ? array_map('intval', $t) : null; }
    if ($on('notify')) $o['notices'] = (int)(md_rows($db, 'SELECT COUNT(*) n FROM user_notice WHERE user_id = ? AND read_at IS NULL', [$uid])[0]['n'] ?? 0);
    if ($on('board')) $o['posts'] = md_rows($db, 'SELECT id, title, created_at FROM board_post WHERE removed_at IS NULL ORDER BY pinned DESC, created_at DESC LIMIT 3', []);
    $L = [];
    if ($on('attendance') && module_allowed($u, 'attendance')) { $L[] = ['Punch', 'modules/attendance/punch.php']; $L[] = ['Attendance correction', 'modules/attendance/requests.php']; }
    foreach (['leave' => 'Apply for leave', 'claims' => 'Expense claim', 'tasks' => 'My tasks', 'helpdesk' => 'Help desk', 'board' => 'Announcements'] as $k => $l) if ($on($k) && module_allowed($u, $k)) $L[] = [$l, 'modules/' . $k . '/index.php'];
    foreach (module_menu($u)['me'] ?? [] as $k => $m) if ($k !== 'myday:self') $L[] = [$m['name'], $m['entry']];
    $o['links'] = $L;
    return $o;
}
