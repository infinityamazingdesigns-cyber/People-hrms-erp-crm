<?php
// Visitors — rules. A visitor register by branch: register (one or a group), host answer (by the desk or by the host
// on "Visitors for me"), check in / out on server time, expected visits, corrections and detail edits that keep the
// earlier value, mobile lookup with limited details outside the login's branches, reports, CSV export after the
// password again, and retention: details of people not seen for N months are removed after a backup.
// No visitor photographs, no ID documents. Pure functions first, then the database half.
declare(strict_types=1);

const VIS_STATUS = ['waiting' => 'At reception', 'inside' => 'Inside', 'out' => 'Checked out', 'cancelled' => 'Left without entering', 'expected' => 'Expected', 'no_show' => 'Did not come'];
const VIS_NOT_ARRIVED = ['expected', 'no_show'];
const VIS_APPROVAL = ['not_needed' => 'Not needed', 'pending' => 'Waiting for host', 'approved' => 'Host approved', 'rejected' => 'Host declined', 'no_answer' => 'No answer'];
const VIS_MAX_CONTACTS = 5;
const VIS_MAX_GROUP = 25;
const VIS_EXPECT_DAYS = 60;
const VIS_REAUTH_MIN = 10;
const VIS_REAUTH_TRIES = 5;
const VIS_EDIT_FIELDS = ['name' => 'Name', 'mobile' => 'Mobile', 'company' => 'Company', 'designation' => 'Designation', 'email' => 'Email', 'address' => 'Address'];
const VIS_DEFAULTS = ['desk_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO', 'RECEPTION'], 'report_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO'],
    'configure_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO'], 'purge_roles' => ['SUPERADMIN', 'CEO'],
    'keep_months' => 12, 'overdue_hours' => 8, 'lookup_hour' => 100, 'notify_host' => true,
    'privacy' => 'Your details are used only for security at our premises and are removed some months after your last visit.'];

function vis_settings(): array {
    $s = module_states()['visitors']['settings'] ?? [];
    return array_replace(VIS_DEFAULTS, array_intersect_key($s, VIS_DEFAULTS));
}

function vis_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['desk_roles' => 'the desk', 'report_roles' => 'reports and export', 'configure_roles' => 'settings and editing details', 'purge_roles' => 'removing old details'] as $k => $l) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = "Pick at least one role for $l.";
    }
    $o['keep_months'] = max(1, min(120, (int)($in['keep_months'] ?? 12)));
    $o['overdue_hours'] = max(1, min(48, (int)($in['overdue_hours'] ?? 8)));
    $o['lookup_hour'] = max(5, min(1000, (int)($in['lookup_hour'] ?? 100)));
    $o['notify_host'] = !empty($in['notify_host']);
    $o['privacy'] = mb_substr(trim(strip_tags((string)($in['privacy'] ?? ''))), 0, 400);
    if ($o['privacy'] === '') $e[] = 'The privacy line on the pass cannot be empty.';
    return [$o, $e];
}

/** Rights from roles. Pure. */
function vis_rights_for(array $roles, array $set): array {
    $h = fn(array $l) => (bool)array_intersect($roles, $l);
    return ['desk' => $h($set['desk_roles']), 'report' => $h($set['report_roles']), 'configure' => $h($set['configure_roles']), 'purge' => $h($set['purge_roles'])];
}

/**
 * Branches a login works in: null = every branch (Super Admin, CEO, and others with nothing ticked), a list of ids
 * otherwise. A desk-only login (Reception) with nothing ticked gets no branch at all. Pure.
 */
function vis_scope_for(array $roles, array $ticked): ?array {
    if (array_intersect($roles, ['SUPERADMIN', 'CEO'])) return null;
    if ($ticked) return array_map('intval', $ticked);
    return array_diff($roles, ['RECEPTION', 'EMPLOYEE']) ? null : [];
}
function vis_in(?array $scope, int $branch): bool { return $scope === null || in_array($branch, $scope, true); }

function vis_clean($s, int $max): string { return mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$s))), 0, $max); }
/** A mobile number kept as typed (digits, + at the start) and its key (last 10 digits). Pure. */
function vis_mobile(string $m): string { $m = trim($m); $d = preg_replace('/\D/', '', $m); return $d === '' ? '' : (str_starts_with($m, '+') ? '+' : '') . $d; }
function vis_mobile_key(string $m): string { $d = preg_replace('/\D/', '', $m); return strlen($d) >= 10 ? substr($d, -10) : $d; }
function vis_mask(?string $m): string { $d = (string)$m; return strlen($d) > 4 ? str_repeat('•', strlen($d) - 4) . substr($d, -4) : $d; }

/** Extra mobiles / emails: cleaned, without the main ones or repeats, at most 5 each. Pure. */
function vis_extra_contacts(array $mobiles, array $emails, string $mainMobile, string $mainEmail): array {
    $m = []; $e = [];
    foreach ($mobiles as $x) { $x = vis_mobile((string)$x); if (strlen(preg_replace('/\D/', '', $x)) >= 8 && vis_mobile_key($x) !== vis_mobile_key($mainMobile) && !in_array($x, $m, true)) $m[] = $x; }
    foreach ($emails as $x) { $x = strtolower(trim((string)$x)); if (filter_var($x, FILTER_VALIDATE_EMAIL) && $x !== strtolower($mainEmail) && !in_array($x, $e, true)) $e[] = $x; }
    return ['mobile' => array_slice($m, 0, VIS_MAX_CONTACTS), 'email' => array_slice($e, 0, VIS_MAX_CONTACTS)];
}

/** Check an "expected later" day and time. Returns [day, time|null] or throws. Pure. */
function vis_expect_check(string $day, string $time, string $today, int $maxDays = VIS_EXPECT_DAYS): array {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($day));
    if (!$d) throw new InvalidArgumentException('Give the day the visitor is expected.');
    $day = $d->format('Y-m-d');
    if ($day < $today || $day > date('Y-m-d', strtotime($today . " +$maxDays days"))) throw new InvalidArgumentException("The expected day must be from today up to $maxDays days ahead.");
    $t = trim($time);
    if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) throw new InvalidArgumentException('Expected time should be like 10:30.');
    return [$day, $t === '' ? null : $t . ':00'];
}

/** Can this visit be checked in? '' = yes, else why not. Pure. */
function vis_checkin_problem(array $v): string {
    if ($v['status'] !== 'waiting') return 'This visit is ' . strtolower(VIS_STATUS[$v['status']] ?? $v['status']) . '; it cannot be checked in.';
    if (!in_array($v['approval'], ['approved', 'not_needed'], true)) return 'Record the host\'s answer before checking in.';
    return '';
}

/** Is a visitor still inside after the overdue hours? Pure. */
function vis_overdue(array $v, int $hours, int $now): bool { return $v['status'] === 'inside' && $v['check_in_at'] && $now - strtotime((string)$v['check_in_at']) > $hours * 3600; }

/** What a lookup by mobile shows: the full record for a visitor known at one of the login's branches, else only name, company and count. Pure. */
function vis_lookup_view(array $v, bool $here, int $visits): array {
    $base = ['id' => (int)$v['id'], 'name' => (string)$v['name'], 'company' => (string)($v['company'] ?? ''), 'visits' => $visits, 'full' => $here];
    return $here ? $base + ['designation' => (string)($v['designation'] ?? ''), 'email' => (string)($v['email'] ?? ''), 'address' => (string)($v['address'] ?? '')] : $base;
}

/** A cell for CSV: text that could start a formula gets a leading apostrophe. Pure. */
function vis_csv_cell($v): string {
    $s = (string)$v;
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) $s = "'" . $s;
    return '"' . str_replace('"', '""', $s) . '"';
}

/** The day before which a visitor's last visit makes their details due for removal. Pure. */
function vis_cutoff(string $today, int $months): string {
    [$y, $m, $d] = array_map('intval', explode('-', $today));
    $t = $y * 12 + ($m - 1) - $months; $y = intdiv($t, 12); $m = $t % 12 + 1;
    return sprintf('%04d-%02d-%02d', $y, $m, min($d, (int)date('t', mktime(0, 0, 0, $m, 1, $y))));
}

// ---------- database ----------

function vis_roles(array $u): array { return user_roles((int)$u['id'], !empty($u['is_owner'])); }
function vis_rights(array $u): array { return vis_rights_for(vis_roles($u), vis_settings()); }
function vis_scope(array $u): ?array { return vis_scope_for(vis_roles($u), $u['branches'] ?? []); }
function vis_need(array $u, string $r): void { if (!vis_rights($u)[$r]) throw new RuntimeException('Your role cannot do this.'); }
function vis_scope_sql(?array $scope, string $col = 'v.branch_id'): array {
    if ($scope === null) return ['1=1', []];
    if (!$scope) return ['1=0', []];
    return [$col . ' IN (' . implode(',', array_fill(0, count($scope), '?')) . ')', $scope];
}

function vis_get(PDO $db, int $id, ?array $scope): ?array {
    $s = $db->prepare('SELECT v.*, p.name, p.mobile, p.company, p.designation, p.email, p.address, p.purged_at, c.label AS category FROM visit v JOIN visitor p ON p.id = v.visitor_id LEFT JOIN vis_category c ON c.id = v.category_id WHERE v.id = ?');
    $s->execute([$id]); $v = $s->fetch();
    return $v && vis_in($scope, (int)$v['branch_id']) ? $v : null;
}

function vis_categories(PDO $db, bool $active = true): array { return $db->query('SELECT * FROM vis_category' . ($active ? ' WHERE active = 1' : '') . ' ORDER BY sort, label')->fetchAll(); }

/** Everyone a visitor can come to see at a branch: name + department of active employees (never codes), with the login. */
function vis_hosts(PDO $db, ?array $scope): array {
    [$w, $p] = vis_scope_sql($scope, 'e.branch_id');
    $s = $db->prepare("SELECT e.name, e.department, e.branch_id, u.id AS user_id FROM employee e LEFT JOIN users u ON u.emp_code = e.code AND u.active = 1
                       WHERE e.active = 1 AND (e.last_day IS NULL OR e.last_day >= CURDATE()) AND $w ORDER BY e.name");
    $s->execute($p);
    return $s->fetchAll();
}

/** Lookup by mobile, counted per login per hour. */
function vis_lookup(PDO $db, array $u, string $mobile, ?array $scope): ?array {
    $k = vis_mobile_key($mobile);
    if (strlen($k) < 8) return null;
    $h = date('Y-m-d H:00:00');
    $c = $db->prepare('SELECT n FROM visitor_lookup WHERE user_id = ? AND hour_start = ?'); $c->execute([(int)$u['id'], $h]);
    if ((int)$c->fetchColumn() >= (int)vis_settings()['lookup_hour']) throw new RuntimeException('Too many look-ups this hour. Type the details instead.');
    $db->prepare('INSERT INTO visitor_lookup (user_id, hour_start, n) VALUES (?,?,1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([(int)$u['id'], $h]);
    $s = $db->prepare('SELECT * FROM visitor WHERE purged_at IS NULL AND (mobile_key = ? OR id IN (SELECT visitor_id FROM visitor_contact WHERE kind = \'mobile\' AND RIGHT(value, 10) = ?)) ORDER BY id LIMIT 1');
    $s->execute([$k, $k]); $v = $s->fetch();
    if (!$v) return null;
    [$w, $p] = vis_scope_sql($scope);
    $q = $db->prepare("SELECT COUNT(*) FROM visit v WHERE v.visitor_id = ? AND v.status NOT IN ('expected','no_show') AND $w"); $q->execute([(int)$v['id'], ...$p]); $here = (int)$q->fetchColumn();
    $n = $db->prepare("SELECT COUNT(*) FROM visit WHERE visitor_id = ? AND status NOT IN ('expected','no_show')"); $n->execute([(int)$v['id']]);
    return vis_lookup_view($v, $here > 0, (int)$n->fetchColumn());
}

/**
 * Register one visitor for a visit (or an expected visit). The same form sent twice returns the first visit.
 * A known visitor (picked by lookup) keeps what is on file where a box is left blank.
 */
function vis_register(PDO $db, array $u, array $in, ?array $scope, ?int $group = null): int {
    vis_need($u, 'desk');
    $uid = (int)$u['id'];
    $token = preg_match('/^[0-9a-f]{32}$/', (string)($in['token'] ?? '')) ? (string)$in['token'] : null;
    if ($token && !$group) { $s = $db->prepare('SELECT id FROM visit WHERE form_token = ?'); $s->execute([$token]); if ($id = $s->fetchColumn()) return (int)$id; }
    $name = vis_clean($in['name'] ?? '', 120);
    if ($name === '') throw new RuntimeException('Visitor name is required.');
    $mobile = vis_mobile((string)($in['mobile'] ?? ''));
    if ($mobile !== '' && strlen(preg_replace('/\D/', '', $mobile)) < 8) throw new RuntimeException('Check the mobile number.');
    $email = strtolower(vis_clean($in['email'] ?? '', 160));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email address does not look right.');
    $branch = (int)($in['branch_id'] ?? 0);
    if (!in_array($branch, array_map(fn($b) => (int)$b['id'], branches()), true)) throw new RuntimeException('Choose the branch.');
    if (!vis_in($scope, $branch)) throw new RuntimeException('You can register visitors only for your branches.');
    $cat = (int)($in['category_id'] ?? 0);
    $c = $db->prepare('SELECT id FROM vis_category WHERE id = ? AND active = 1'); $c->execute([$cat]);
    if (!$c->fetchColumn()) throw new RuntimeException('Choose the visitor category.');
    $host = vis_clean($in['host_name'] ?? '', 120);
    if ($host === '') throw new RuntimeException('Choose the person being visited (or type a name / department).');
    $hostUser = (int)($in['host_user_id'] ?? 0) ?: null;
    $approval = ($in['approval'] ?? 'pending') === 'not_needed' ? 'not_needed' : 'pending';
    $exp = ($in['when'] ?? '') === 'later'; $expOn = $expTime = null;
    if ($exp) [$expOn, $expTime] = vis_expect_check((string)($in['exp_on'] ?? ''), (string)($in['exp_time'] ?? ''), date('Y-m-d'));
    $extra = vis_extra_contacts((array)($in['mobiles'] ?? []), (array)($in['emails'] ?? []), $mobile, $email);
    $own = !$db->inTransaction(); if ($own) $db->beginTransaction();
    try {
        $vid = (int)($in['visitor_id'] ?? 0);
        if (!$vid && $mobile !== '') {   // the same mobile is the same person: one record, so one open visit
            $s = $db->prepare('SELECT id FROM visitor WHERE mobile_key = ? AND purged_at IS NULL ORDER BY id LIMIT 1'); $s->execute([vis_mobile_key($mobile)]);
            $vid = (int)$s->fetchColumn();
        }
        $f = [$name, $mobile ?: null, $mobile !== '' ? vis_mobile_key($mobile) : null, vis_clean($in['company'] ?? '', 160) ?: null, vis_clean($in['designation'] ?? '', 120) ?: null, $email ?: null, vis_clean($in['address'] ?? '', 400) ?: null];
        if ($vid) {
            $s = $db->prepare('SELECT id FROM visitor WHERE id = ? AND purged_at IS NULL FOR UPDATE'); $s->execute([$vid]);
            if (!$s->fetchColumn()) throw new RuntimeException('That earlier visitor record is no longer available.');
            $db->prepare('UPDATE visitor SET name = ?, mobile = COALESCE(?, mobile), mobile_key = COALESCE(?, mobile_key), company = COALESCE(?, company), designation = COALESCE(?, designation),
                          email = COALESCE(?, email), address = COALESCE(?, address), updated_by = ? WHERE id = ?')->execute([...$f, $uid, $vid]);
        } else {
            $db->prepare('INSERT INTO visitor (name, mobile, mobile_key, company, designation, email, address, created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([...$f, $uid]);
            $vid = (int)$db->lastInsertId();
        }
        $ci = $db->prepare('INSERT IGNORE INTO visitor_contact (visitor_id, kind, value, created_by) VALUES (?,?,?,?)');
        foreach ($extra as $kind => $list) foreach ($list as $x) $ci->execute([$vid, $kind, $x, $uid]);
        if ($exp) {
            $q = $db->prepare("SELECT visit_no FROM visit WHERE visitor_id = ? AND status = 'expected' AND exp_on = ? AND branch_id = ? LIMIT 1"); $q->execute([$vid, $expOn, $branch]);
            if ($o = $q->fetch()) throw new RuntimeException("This visitor is already expected that day ({$o['visit_no']}).");
        } else {
            $q = $db->prepare("SELECT visit_no FROM visit WHERE visitor_id = ? AND status IN ('waiting','inside') LIMIT 1"); $q->execute([$vid]);
            if ($o = $q->fetch()) throw new RuntimeException("$name already has an open visit ({$o['visit_no']}). Check that one out first.");
        }
        $now = !$exp && !empty($in['check_in_now']) && $approval === 'not_needed';
        $db->prepare('INSERT INTO visit (visitor_id, group_id, category_id, purpose, branch_id, host_user_id, host_name, host_dept, approval, status, exp_on, exp_time, exp_added_at,
                      check_in_at, checked_in_by, remarks, open_key, form_token, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,' . ($exp ? 'NOW()' : 'NULL') . ',' . ($now ? 'NOW()' : 'NULL') . ',?,?,?,?,?)')
           ->execute([$vid, $group, $cat, vis_clean($in['purpose'] ?? '', 300), $branch, $hostUser, $host, vis_clean($in['host_dept'] ?? '', 80), $approval,
                      $exp ? 'expected' : ($now ? 'inside' : 'waiting'), $expOn, $expTime, $now ? $uid : null, vis_clean($in['remarks'] ?? '', 500), $exp ? null : $vid, $group ? null : $token, $uid]);
        $id = (int)$db->lastInsertId();
        $db->prepare("UPDATE visit SET visit_no = CONCAT('V', DATE_FORMAT(created_at, '%y%m'), '-', LPAD(?, 5, '0')) WHERE id = ?")->execute([$id, $id]);
        $db->prepare('INSERT INTO visit_approval (visit_id, approval, note, by_user) VALUES (?,?,?,?)')->execute([$id, $approval, $exp ? 'Expected on ' . $expOn : ($approval === 'pending' ? 'Registered – host to be asked' : 'Registered – no host answer needed'), $uid]);
        audit($uid, $exp ? 'visit_expect' : 'visit_register', '#' . $id . ' visitor ' . $vid . ' branch ' . $branch . ($now ? ' in' : ''));
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'uk_open')) throw new RuntimeException('This visitor already has an open visit. Check that one out first.');
        throw $e;
    }
    if (!$exp && $approval === 'pending' && $hostUser && !$group) vis_notify_host($db, $id);
    return $id;
}

/** People who came together: one visit each, linked, all or nothing. Returns [first id, count]. */
function vis_register_group(PDO $db, array $u, array $in, array $others, ?array $scope): array {
    $others = array_values(array_filter($others, fn($o) => vis_clean($o['name'] ?? '', 120) !== ''));
    if (count($others) + 1 > VIS_MAX_GROUP) throw new RuntimeException('Up to ' . VIS_MAX_GROUP . ' visitors together.');
    $token = preg_match('/^[0-9a-f]{32}$/', (string)($in['token'] ?? '')) ? (string)$in['token'] : null;
    if ($token) { $s = $db->prepare('SELECT id, group_id FROM visit WHERE form_token = ?'); $s->execute([$token]); if ($r = $s->fetch()) { $n = $db->prepare('SELECT COUNT(*) FROM visit WHERE group_id = ?'); $n->execute([(int)$r['group_id']]); return [(int)$r['id'], (int)$n->fetchColumn() ?: 1]; } }
    $db->beginTransaction();
    try {
        $first = vis_register($db, $u, $in, $scope, -1);
        $db->prepare('UPDATE visit SET group_id = id, form_token = ? WHERE id = ?')->execute([$token, $first]);
        $shared = array_intersect_key($in, array_flip(['branch_id', 'category_id', 'purpose', 'host_name', 'host_dept', 'host_user_id', 'approval', 'when', 'exp_on', 'exp_time', 'check_in_now']));
        foreach ($others as $o) vis_register($db, $u, $shared + array_intersect_key($o, array_flip(['name', 'mobile', 'email', 'company', 'designation'])), $scope, $first);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    $v = vis_get($db, $first, $scope);
    if ($v && $v['status'] === 'waiting' && $v['approval'] === 'pending' && $v['host_user_id']) vis_notify_host($db, $first);
    return [$first, count($others) + 1];
}

function vis_group(PDO $db, int $groupId, ?array $scope): array {
    $s = $db->prepare('SELECT v.id, v.visit_no, v.status, v.approval, v.branch_id, p.name FROM visit v JOIN visitor p ON p.id = v.visitor_id WHERE v.group_id = ? ORDER BY v.id'); $s->execute([$groupId]);
    return array_values(array_filter($s->fetchAll(), fn($r) => vis_in($scope, (int)$r['branch_id'])));
}

/** Tell the host by email (when Email is on and the host's login has an address). */
function vis_notify_host(PDO $db, int $id): void {
    if (!vis_settings()['notify_host'] || !function_exists('mail_queue') || !module_on('email')) return;
    try {
        $s = $db->prepare('SELECT v.visit_no, v.purpose, u.email, p.name, p.company FROM visit v JOIN visitor p ON p.id = v.visitor_id JOIN users u ON u.id = v.host_user_id WHERE v.id = ?'); $s->execute([$id]);
        $r = $s->fetch();
        if (!$r || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) return;
        $n = $db->prepare('SELECT COUNT(*) FROM visit WHERE group_id = (SELECT group_id FROM visit WHERE id = ?)'); $n->execute([$id]); $cnt = max(1, (int)$n->fetchColumn());
        mail_queue($db, ['to' => $r['email'], 'subject' => 'Visitor at reception: ' . $r['name'] . ($cnt > 1 ? ' + ' . ($cnt - 1) : ''),
            'text' => $r['name'] . ($r['company'] ? ' (' . $r['company'] . ')' : '') . ($cnt > 1 ? ' and ' . ($cnt - 1) . ' more' : '') . " is at reception to meet you." . ($r['purpose'] ? "\nPurpose: " . $r['purpose'] : '') . "\nOpen Visitors for me to let them in or decline."], 'notice', 'visit:' . $id);
    } catch (Throwable $e) { error_log('visitor notice: ' . $e->getMessage()); }
}

/** The host's answer — from the desk, or by the host themselves (their own visitors only). */
function vis_set_approval(PDO $db, array $u, int $id, string $ans, string $note, ?array $scope, bool $asHost = false): void {
    if (!in_array($ans, ['approved', 'rejected', 'no_answer', 'pending'], true)) throw new RuntimeException('Unknown answer.');
    $v = $asHost ? vis_get($db, $id, null) : vis_get($db, $id, $scope);
    if (!$v) throw new RuntimeException('Visit not found.');
    if ($asHost) { if ((int)$v['host_user_id'] !== (int)$u['id'] || !in_array($ans, ['approved', 'rejected'], true)) throw new RuntimeException('Only the person being visited answers here.'); }
    else vis_need($u, 'desk');
    if ($v['status'] !== 'waiting') throw new RuntimeException('The answer can be recorded only while the visitor is at reception.');
    $ids = $v['group_id'] ? array_column(vis_group($db, (int)$v['group_id'], null), 'id') : [$id];
    $db->beginTransaction();
    try {
        $up = $db->prepare("UPDATE visit SET approval = ?, updated_by = ? WHERE id = ? AND status = 'waiting'");
        $log = $db->prepare('INSERT INTO visit_approval (visit_id, approval, note, by_user) VALUES (?,?,?,?)');
        foreach ($ids as $x) { $up->execute([$ans, (int)$u['id'], (int)$x]); if ($up->rowCount()) $log->execute([(int)$x, $ans, ($asHost ? 'By the host. ' : '') . vis_clean($note, 280), (int)$u['id']]); }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'visit_host_' . $ans, '#' . $id . ($asHost ? ' host' : ''));
}

/** Check in (server time). False when it was already done. */
function vis_check_in(PDO $db, array $u, int $id, ?array $scope): bool {
    vis_need($u, 'desk');
    $v = vis_get($db, $id, $scope);
    if (!$v) throw new RuntimeException('Visit not found.');
    if ($v['status'] === 'inside') return false;
    if (($p = vis_checkin_problem($v)) !== '') throw new RuntimeException($p);
    $s = $db->prepare("UPDATE visit SET status = 'inside', check_in_at = NOW(), checked_in_by = ?, updated_by = ? WHERE id = ? AND status = 'waiting'");
    $s->execute([(int)$u['id'], (int)$u['id'], $id]);
    if ($s->rowCount()) audit((int)$u['id'], 'visit_check_in', '#' . $id);
    return (bool)$s->rowCount();
}

function vis_check_out(PDO $db, array $u, int $id, ?array $scope): bool {
    vis_need($u, 'desk');
    $v = vis_get($db, $id, $scope);
    if (!$v) throw new RuntimeException('Visit not found.');
    if ($v['status'] === 'out') return false;
    if ($v['status'] !== 'inside') throw new RuntimeException('A visitor can be checked out only after check-in.');
    $s = $db->prepare("UPDATE visit SET status = 'out', check_out_at = GREATEST(NOW(), check_in_at), duration_min = TIMESTAMPDIFF(MINUTE, check_in_at, GREATEST(NOW(), check_in_at)),
                       open_key = NULL, checked_out_by = ?, updated_by = ? WHERE id = ? AND status = 'inside'");
    $s->execute([(int)$u['id'], (int)$u['id'], $id]);
    if ($s->rowCount()) audit((int)$u['id'], 'visit_check_out', '#' . $id);
    return (bool)$s->rowCount();
}

/** Do the same step for everyone of a group who can take it. Returns how many moved. */
function vis_group_step(PDO $db, array $u, int $groupId, string $step, ?array $scope): int {
    $n = 0;
    foreach (vis_group($db, $groupId, $scope) as $g) {
        try { if ($step === 'in' ? vis_check_in($db, $u, (int)$g['id'], $scope) : vis_check_out($db, $u, (int)$g['id'], $scope)) $n++; } catch (RuntimeException $e) {}
    }
    return $n;
}

/** An expected visitor arrived: the visit starts now (counted on that day), checked in at once when no answer is needed. */
function vis_arrive(PDO $db, array $u, int $id, ?array $scope, bool $checkIn): bool {
    vis_need($u, 'desk');
    $v = vis_get($db, $id, $scope);
    if (!$v) throw new RuntimeException('Visit not found.');
    if (in_array($v['status'], ['waiting', 'inside'], true)) return false;
    if ($v['status'] !== 'expected') throw new RuntimeException('Only an expected visit can be marked as arrived.');
    $in = $checkIn && in_array($v['approval'], ['approved', 'not_needed'], true);
    try {
        $s = $db->prepare('UPDATE visit SET status = ?, open_key = visitor_id, created_at = NOW(), check_in_at = ' . ($in ? 'NOW()' : 'NULL') . ", checked_in_by = ?, updated_by = ? WHERE id = ? AND status = 'expected'");
        $s->execute([$in ? 'inside' : 'waiting', $in ? (int)$u['id'] : null, (int)$u['id'], $id]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'uk_open')) throw new RuntimeException('This visitor already has an open visit. Check that one out first.');
        throw $e;
    }
    if ($s->rowCount()) { audit((int)$u['id'], 'visit_arrive', '#' . $id); if (!$in && $v['approval'] === 'pending' && $v['host_user_id']) vis_notify_host($db, $id); }
    return (bool)$s->rowCount();
}

function vis_no_show(PDO $db, array $u, int $id, string $note, ?array $scope): void {
    vis_need($u, 'desk');
    $v = vis_get($db, $id, $scope);
    if (!$v || $v['status'] !== 'expected') throw new RuntimeException('Only an expected visitor can be marked as not come.');
    $db->prepare("UPDATE visit SET status = 'no_show', remarks = TRIM(CONCAT(remarks, ' · Did not come', IF(? = '', '', CONCAT(': ', ?)))), updated_by = ? WHERE id = ?")->execute([$note = vis_clean($note, 200), $note, (int)$u['id'], $id]);
}

/** Left without going in (host declined, no answer, walked away). A reason is needed. */
function vis_cancel(PDO $db, array $u, int $id, string $reason, ?array $scope): void {
    vis_need($u, 'desk');
    $v = vis_get($db, $id, $scope);
    if (!$v || $v['status'] !== 'waiting') throw new RuntimeException('Only a visitor still at reception can be marked as left.');
    if (($reason = vis_clean($reason, 300)) === '') throw new RuntimeException('Give a reason.');
    $db->prepare("UPDATE visit SET status = 'cancelled', open_key = NULL, remarks = TRIM(CONCAT(remarks, ' · Left: ', ?)), updated_by = ? WHERE id = ? AND status = 'waiting'")->execute([$reason, (int)$u['id'], $id]);
    audit((int)$u['id'], 'visit_cancel', '#' . $id);
}

/** Correct a check-in / check-out time (configure right). The original is kept; the result must stay in order. */
function vis_correct_time(PDO $db, array $u, int $id, string $field, string $value, string $reason, ?array $scope): void {
    vis_need($u, 'configure');
    if (!in_array($field, ['check_in_at', 'check_out_at'], true)) throw new RuntimeException('Unknown field.');
    if (($reason = vis_clean($reason, 300)) === '') throw new RuntimeException('Give a reason for the correction.');
    $t = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', trim($value)) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', trim($value));
    if (!$t) throw new RuntimeException('Give a date and time.');
    $new = $t->format('Y-m-d H:i:00');
    if ($new > date('Y-m-d H:i:s')) throw new RuntimeException('A time in the future cannot be recorded.');
    $v = vis_get($db, $id, $scope);
    if (!$v || !$v[$field]) throw new RuntimeException('Nothing to correct.');
    $in = $field === 'check_in_at' ? $new : (string)$v['check_in_at']; $out = $field === 'check_out_at' ? $new : $v['check_out_at'];
    if ($out !== null && $out < $in) throw new RuntimeException('Check-out cannot be before check-in.');
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO visit_correction (visit_id, field, old_value, new_value, reason, by_user) VALUES (?,?,?,?,?,?)')->execute([$id, $field, $v[$field], $new, $reason, (int)$u['id']]);
        $db->prepare("UPDATE visit SET $field = ?, duration_min = ?, updated_by = ? WHERE id = ?")->execute([$new, $out === null ? null : intdiv(strtotime((string)$out) - strtotime($in), 60), (int)$u['id'], $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'visit_correct_time', '#' . $id . ' ' . $field);
}

/** Edit a visitor's details (configure right) with a reason; each changed field keeps its earlier value. */
function vis_edit_details(PDO $db, array $u, int $id, array $in, ?array $scope): array {
    vis_need($u, 'configure');
    $v = vis_get($db, $id, $scope);
    if (!$v || $v['purged_at'] !== null) throw new RuntimeException('Visit not found or its details were removed.');
    if (($reason = vis_clean($in['reason'] ?? '', 300)) === '') throw new RuntimeException('Give a reason for the change.');
    $new = [];
    foreach (VIS_EDIT_FIELDS as $k => $_) {
        if (!array_key_exists($k, $in)) continue;
        $x = $k === 'mobile' ? vis_mobile((string)$in[$k]) : ($k === 'email' ? strtolower(vis_clean($in[$k], 160)) : vis_clean($in[$k], $k === 'address' ? 400 : 160));
        if ($k === 'name' && $x === '') throw new RuntimeException('The name cannot be empty.');
        if ($k === 'email' && $x !== '' && !filter_var($x, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email address does not look right.');
        if ((string)$x !== (string)($v[$k] ?? '')) $new[$k] = $x;
    }
    if (isset($new['mobile']) && $new['mobile'] !== '') {
        $s = $db->prepare('SELECT id FROM visitor WHERE mobile_key = ? AND id <> ? AND purged_at IS NULL'); $s->execute([vis_mobile_key($new['mobile']), (int)$v['visitor_id']]);
        if ($s->fetchColumn()) throw new RuntimeException('Another visitor record already has that mobile.');
    }
    if (!$new) return [];
    $db->beginTransaction();
    try {
        $e = $db->prepare('INSERT INTO visitor_edit (visitor_id, visit_id, field, old_value, reason, by_user) VALUES (?,?,?,?,?,?)');
        foreach ($new as $k => $x) $e->execute([(int)$v['visitor_id'], $id, $k, $v[$k], $reason, (int)$u['id']]);
        if (isset($new['mobile'])) $new['mobile_key'] = $new['mobile'] !== '' ? vis_mobile_key($new['mobile']) : null;
        $db->prepare('UPDATE visitor SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($new))) . ', updated_by = ? WHERE id = ?')
           ->execute([...array_map(fn($x) => $x === '' ? null : $x, array_values($new)), (int)$u['id'], (int)$v['visitor_id']]);
        $db->commit();
    } catch (Throwable $e2) { $db->rollBack(); throw $e2; }
    audit((int)$u['id'], 'visitor_edit', '#' . $v['visitor_id'] . ' ' . implode(',', array_keys(array_intersect_key($new, VIS_EDIT_FIELDS))));
    return array_keys(array_intersect_key($new, VIS_EDIT_FIELDS));
}

/** The desk: at reception, inside (overdue marked), expected today and in the next 7 days. */
function vis_desk(PDO $db, ?array $scope, int $branch = 0): array {
    [$w, $p] = vis_scope_sql($scope);
    if ($branch) { $w .= ' AND v.branch_id = ?'; $p[] = $branch; }
    $s = $db->prepare("SELECT v.*, p.name, p.company, p.mobile, c.label AS category FROM visit v JOIN visitor p ON p.id = v.visitor_id LEFT JOIN vis_category c ON c.id = v.category_id
                       WHERE $w AND (v.status IN ('waiting','inside') OR (v.status = 'expected' AND v.exp_on BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))
                       OR (v.status IN ('out','cancelled') AND DATE(v.created_at) = CURDATE())) ORDER BY FIELD(v.status,'waiting','inside','expected','out','cancelled'), v.exp_on, v.exp_time, v.id DESC");
    $s->execute($p);
    return $s->fetchAll();
}

/** Visits between two days for the register / reports (not arrived left out). */
function vis_list(PDO $db, ?array $scope, string $from, string $to, int $branch = 0, int $cat = 0, string $q = ''): array {
    [$w, $p] = vis_scope_sql($scope);
    $w .= " AND v.status NOT IN ('expected','no_show') AND DATE(v.created_at) BETWEEN ? AND ?"; array_push($p, $from, $to);
    if ($branch) { $w .= ' AND v.branch_id = ?'; $p[] = $branch; }
    if ($cat) { $w .= ' AND v.category_id = ?'; $p[] = $cat; }
    if (($q = trim($q)) !== '') { $w .= ' AND (p.name LIKE ? OR p.company LIKE ? OR v.host_name LIKE ? OR p.mobile_key LIKE ?)'; $l = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; array_push($p, $l, $l, $l, $l); }
    $s = $db->prepare("SELECT v.*, p.name, p.company, p.designation, p.mobile, p.email, p.purged_at, c.label AS category FROM visit v JOIN visitor p ON p.id = v.visitor_id LEFT JOIN vis_category c ON c.id = v.category_id WHERE $w ORDER BY v.created_at DESC LIMIT 2000");
    $s->execute($p);
    return $s->fetchAll();
}

/** Report figures from a list: by status, category, branch, host; average minutes inside. Pure. */
function vis_report(array $rows): array {
    $o = ['total' => count($rows), 'status' => [], 'category' => [], 'branch' => [], 'host' => [], 'avg_min' => null];
    $mins = [];
    foreach ($rows as $r) {
        foreach (['status' => $r['status'], 'category' => $r['category'] ?? '', 'branch' => (string)$r['branch_id'], 'host' => $r['host_name']] as $k => $x) $o[$k][$x] = ($o[$k][$x] ?? 0) + 1;
        if ($r['duration_min'] !== null) $mins[] = (int)$r['duration_min'];
    }
    foreach (['category', 'host'] as $k) arsort($o[$k]);
    if ($mins) $o['avg_min'] = (int)round(array_sum($mins) / count($mins));
    return $o;
}

/** Password again before export / backup / removal: fresh for 10 minutes; 5 wrong tries → wait 15 minutes. */
function vis_reauth_fresh(): bool { return isset($_SESSION['vis_reauth']) && time() - (int)$_SESSION['vis_reauth'] < VIS_REAUTH_MIN * 60; }
function vis_reauth(PDO $db, array $u, string $password): bool {
    $t = $_SESSION['vis_reauth_fail'] ?? ['n' => 0, 'at' => 0];
    if ($t['n'] >= VIS_REAUTH_TRIES && time() - $t['at'] < 900) throw new RuntimeException('Too many wrong passwords. Wait 15 minutes.');
    $s = $db->prepare('SELECT pass_hash FROM users WHERE id = ?'); $s->execute([(int)$u['id']]);
    if (!password_verify($password, (string)$s->fetchColumn())) { $_SESSION['vis_reauth_fail'] = ['n' => ($t['n'] ?? 0) + 1, 'at' => time()]; audit((int)$u['id'], 'visit_reauth_fail'); return false; }
    $_SESSION['vis_reauth'] = time(); unset($_SESSION['vis_reauth_fail']);
    return true;
}

/** CSV of visits (contacts only for the configure right). */
function vis_csv(array $rows, array $bnames, bool $contacts): string {
    $head = ['Visit', 'Date', 'Branch', 'Visitor', 'Company', 'Designation', 'Category', 'Purpose', 'Meeting', 'Department', 'Host answer', 'Status', 'In', 'Out', 'Minutes'];
    if ($contacts) array_push($head, 'Mobile', 'Email');
    $out = implode(',', array_map('vis_csv_cell', $head)) . "\r\n";
    foreach ($rows as $r) {
        $line = [$r['visit_no'], substr((string)$r['created_at'], 0, 10), $bnames[(int)$r['branch_id']] ?? '', $r['name'], $r['company'], $r['designation'], $r['category'], $r['purpose'], $r['host_name'], $r['host_dept'],
                 VIS_APPROVAL[$r['approval']] ?? '', VIS_STATUS[$r['status']] ?? '', $r['check_in_at'], $r['check_out_at'], $r['duration_min']];
        if ($contacts) array_push($line, $r['mobile'], $r['email']);
        $out .= implode(',', array_map('vis_csv_cell', $line)) . "\r\n";
    }
    return $out;
}

/** Visitors whose last visit is before the cut-off and whose details are still kept. */
function vis_retention_due(PDO $db, int $months): array {
    $s = $db->prepare('SELECT p.id, p.name, MAX(v.created_at) AS last FROM visitor p LEFT JOIN visit v ON v.visitor_id = p.id WHERE p.purged_at IS NULL GROUP BY p.id, p.name
                       HAVING COALESCE(MAX(v.created_at), MAX(p.created_at)) < ? AND SUM(v.status IN (\'waiting\',\'inside\',\'expected\')) = 0 ORDER BY last');
    $s->execute([vis_cutoff(date('Y-m-d'), $months)]);
    return $s->fetchAll();
}

/** The backup CSV of the visitors due (full details), recorded so the removal can go ahead within 24 hours. */
function vis_retention_backup(PDO $db, array $u, int $months): string {
    vis_need($u, 'purge');
    $due = vis_retention_due($db, $months);
    $csv = implode(',', array_map('vis_csv_cell', ['Visitor id', 'Name', 'Mobile', 'Company', 'Designation', 'Email', 'Address', 'Last visit'])) . "\r\n";
    $g = $db->prepare('SELECT * FROM visitor WHERE id = ?');
    foreach ($due as $d) { $g->execute([(int)$d['id']]); $p = $g->fetch(); $csv .= implode(',', array_map('vis_csv_cell', [$p['id'], $p['name'], $p['mobile'], $p['company'], $p['designation'], $p['email'], $p['address'], $d['last']])) . "\r\n"; }
    $db->prepare('INSERT INTO vis_backup (by_user, rows_n) VALUES (?,?)')->execute([(int)$u['id'], count($due)]);
    audit((int)$u['id'], 'visit_retention_backup', 'rows ' . count($due));
    return $csv;
}

/** Remove the details of visitors due — only after a backup in the last 24 hours. Visit counts stay. Returns how many. */
function vis_retention_purge(PDO $db, array $u, int $months): int {
    vis_need($u, 'purge');
    $b = $db->query('SELECT COUNT(*) FROM vis_backup WHERE at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)')->fetchColumn();
    if (!(int)$b) throw new RuntimeException('Download the backup first (within the last 24 hours).');
    $ids = array_map(fn($r) => (int)$r['id'], vis_retention_due($db, $months));
    if (!$ids) return 0;
    $in = implode(',', $ids);
    $db->beginTransaction();
    try {
        $db->exec("UPDATE visitor SET name = 'Removed after retention', mobile = NULL, mobile_key = NULL, company = NULL, designation = NULL, email = NULL, address = NULL, purged_at = NOW() WHERE id IN ($in)");
        $db->exec("DELETE FROM visitor_contact WHERE visitor_id IN ($in)");   // personal contact details: removed by the retention rule
        $db->exec("UPDATE visit SET purpose = '', remarks = '' WHERE visitor_id IN ($in)");
        $db->exec("UPDATE visit_approval SET note = '' WHERE visit_id IN (SELECT id FROM visit WHERE visitor_id IN ($in))");
        $db->exec("UPDATE visitor_edit SET old_value = NULL, reason = '' WHERE visitor_id IN ($in)");
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'visit_retention_purge', 'visitors ' . count($ids));
    return count($ids);
}

/** Visitors waiting for this login as host (Visitors for me). */
function vis_for_host(PDO $db, int $uid): array {
    $s = $db->prepare("SELECT v.*, p.name, p.company, p.designation, c.label AS category FROM visit v JOIN visitor p ON p.id = v.visitor_id LEFT JOIN vis_category c ON c.id = v.category_id
                       WHERE v.host_user_id = ? AND (v.status IN ('waiting','inside') OR (v.status = 'expected' AND v.exp_on >= CURDATE()) OR v.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
                       ORDER BY FIELD(v.status,'waiting','inside','expected'), v.id DESC LIMIT 100");
    $s->execute([$uid]);
    return $s->fetchAll();
}

function vis_category_save(PDO $db, array $u, int $id, string $label, int $sort, bool $active): void {
    vis_need($u, 'configure');
    if (($label = vis_clean($label, 60)) === '') throw new RuntimeException('Give the category a name.');
    if ($id) $db->prepare('UPDATE vis_category SET label = ?, sort = ?, active = ? WHERE id = ?')->execute([$label, $sort, $active ? 1 : 0, $id]);
    else $db->prepare('INSERT INTO vis_category (label, sort) VALUES (?,?)')->execute([$label, $sort]);
}
