<?php
// Staff movement — rules. A gate record only: who went out during working hours, when, why (optional), where,
// vehicle, material taken; when they came back. No approval; nothing reaches attendance or pay.
// Out / in on server time. One open entry per person (unique open_key), so double taps and two guards at once cannot
// double-record. Several people go out together (group_id, all or nothing); each comes back on their own. Material
// taken out is answered on return. Corrections keep the original. Late: red after the branch limit, flagged when the
// person's shift end passes (or the day changes) while out; each flag emails the alert logins of that branch once.
// Pure functions first, then the database half.
declare(strict_types=1);
require_once __DIR__ . '/visitors.php';
require_once __DIR__ . '/shifts.php';
require_once __DIR__ . '/mail.php';

const MV_MAX_GROUP = 30;
const MV_MATERIAL_BACK = ['yes' => 'All back', 'partial' => 'Part back', 'no' => 'Not back'];
const MV_ALERT_KINDS = ['overdue' => 'Out longer than the limit', 'shift_end' => 'Still out at shift end'];
const MV_DEFAULTS = ['record_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO', 'RECEPTION'], 'report_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO', 'PRODHEAD'],
    'configure_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO'], 'alert_roles' => ['PRODHEAD'],
    'overdue_min' => 30, 'branch_limits' => [], 'departments' => '', 'alert_email' => true];

function mv_settings(): array {
    $s = module_states()['movement']['settings'] ?? [];
    return array_replace(MV_DEFAULTS, array_intersect_key($s, MV_DEFAULTS));
}

/** Settings form → saved values + problems. Pure. */
function mv_settings_check(array $in, array $roles, array $branchIds): array {
    $o = []; $e = [];
    foreach (['record_roles' => 'recording', 'report_roles' => 'the log and reports', 'configure_roles' => 'settings', 'alert_roles' => 'late alerts'] as $k => $l) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = "Pick at least one role for $l.";
    }
    $o['overdue_min'] = mv_limit_clean($in['overdue_min'] ?? 30);
    $o['branch_limits'] = [];
    foreach ((array)($in['branch_limits'] ?? []) as $b => $m) if (in_array((int)$b, $branchIds, true) && trim((string)$m) !== '') $o['branch_limits'][(string)(int)$b] = mv_limit_clean($m);
    $o['departments'] = mb_substr(trim((string)preg_replace('/\s*,\s*/', ', ', strip_tags((string)($in['departments'] ?? '')))), 0, 300);
    $o['alert_email'] = !empty($in['alert_email']);
    return [$o, $e];
}
function mv_limit_clean($m): int { return max(5, min(600, (int)$m ?: 30)); }
function mv_limit(array $set, ?int $branch): int { return (int)($set['branch_limits'][(string)(int)$branch] ?? $set['overdue_min']); }

/** Rights from roles. Viewing = recording or reports. Pure. */
function mv_rights_for(array $roles, array $set): array {
    $h = fn(array $l) => (bool)array_intersect($roles, $l);
    $r = ['record' => $h($set['record_roles']), 'report' => $h($set['report_roles']), 'configure' => $h($set['configure_roles'])];
    $r['view'] = $r['record'] || $r['report'] || $r['configure'];
    return $r;
}

/** Branches: null = every branch. Reception / Production Head with nothing ticked get none; others with none ticked get all. Pure. */
function mv_scope_for(array $roles, array $ticked): ?array {
    if (array_intersect($roles, ['SUPERADMIN', 'CEO'])) return null;
    if ($ticked) return array_map('intval', $ticked);
    return array_diff($roles, ['RECEPTION', 'PRODHEAD', 'EMPLOYEE']) ? null : [];
}

/** Does a department pass the setting? Blank setting = everyone. Pure. */
function mv_dept_ok(string $dept, string $list): bool {
    $want = array_filter(array_map(fn($x) => mb_strtolower(trim($x)), explode(',', $list)));
    if (!$want) return true;
    $d = mb_strtolower(trim($dept));
    foreach ($want as $w) if ($d !== '' && str_contains($d, $w)) return true;
    return false;
}

/** Material answer on return. Returns the stored value (null when nothing went out) or throws. Pure. */
function mv_material_check(string $out, string $back, string $note): ?string {
    if (trim($out) === '') return null;
    if (!isset(MV_MATERIAL_BACK[$back])) throw new RuntimeException('Material went out: say whether it came back.');
    if ($back !== 'yes' && trim($note) === '') throw new RuntimeException('Material not fully back: note what is still out.');
    return $back;
}

/** The codes a send-out form picked, checked against the list on screen. Pure. */
function mv_pick(array $codes, array $people, int $max = MV_MAX_GROUP): array {
    $codes = array_values(array_unique(array_filter(array_map(fn($c) => strtoupper(trim((string)$c)), $codes))));
    if (!$codes) throw new RuntimeException('Pick who is going out.');
    if (count($codes) > $max) throw new RuntimeException("Up to $max people can go out together.");
    $by = []; foreach ($people as $p) $by[strtoupper((string)$p['code'])] = $p;
    $out = [];
    foreach ($codes as $c) { if (!isset($by[$c])) throw new RuntimeException('Pick people from the list.'); $out[] = $by[$c]; }
    return $out;
}

/**
 * Flags for an open entry at $now: overdue (minutes out ≥ limit), shift_end (still out from an earlier day, or the
 * shift end of the day they went out has passed and they left before it). Pure.
 */
function mv_flags(array $m, int $limit, ?string $shiftEnd, int $now): array {
    $out = strtotime((string)$m['out_at']);
    $mins = (int)floor(($now - $out) / 60);
    $open = ($m['status'] ?? 'out') === 'out';
    $se = false;
    if ($open) {
        if (date('Y-m-d', $out) < date('Y-m-d', $now)) $se = true;
        elseif ($shiftEnd !== null && $shiftEnd !== '') { $end = strtotime(date('Y-m-d', $out) . ' ' . substr($shiftEnd, 0, 5) . ':00'); $se = $now >= $end && $out < $end; }
    }
    return ['overdue' => $open && $mins >= $limit, 'shift_end' => $se, 'mins' => $mins, 'limit' => $limit];
}

function mv_dur(?int $min): string { if ($min === null) return ''; return $min < 60 ? $min . ' min' : intdiv($min, 60) . ' h ' . ($min % 60) . ' min'; }

/** Report rows: count, minutes, late returns per person and per reason. Pure. */
function mv_summary(array $rows, array $set): array {
    $by = ['person' => [], 'reason' => []]; $tot = ['n' => 0, 'min' => 0, 'late' => 0, 'open' => 0];
    foreach ($rows as $r) {
        $min = $r['duration_min'] !== null ? (int)$r['duration_min'] : null;
        $late = $min !== null && $min >= mv_limit($set, $r['branch_id'] !== null ? (int)$r['branch_id'] : null);
        $tot['n']++; $tot['min'] += (int)$min; $tot['late'] += $late ? 1 : 0; $tot['open'] += $r['status'] === 'out' ? 1 : 0;
        foreach (['person' => $r['emp_name'] . ' · ' . $r['emp_code'], 'reason' => $r['reason'] ?: ($r['reason_other'] !== '' ? 'Other' : 'No reason given')] as $k => $key) {
            $by[$k][$key] ??= ['n' => 0, 'min' => 0, 'late' => 0];
            $by[$k][$key]['n']++; $by[$k][$key]['min'] += (int)$min; $by[$k][$key]['late'] += $late ? 1 : 0;
        }
    }
    foreach ($by as &$l) uasort($l, fn($a, $b) => $b['n'] <=> $a['n']); unset($l);
    return ['total' => $tot] + $by;
}

// ---------- database ----------

function mv_roles(array $u): array { return user_roles((int)$u['id'], !empty($u['is_owner'])); }
function mv_rights(array $u): array { return mv_rights_for(mv_roles($u), mv_settings()); }
function mv_scope(array $u): ?array { return mv_scope_for(mv_roles($u), $u['branches'] ?? []); }

function mv_reasons(PDO $db, bool $active = true): array { return $db->query('SELECT * FROM mv_reason' . ($active ? ' WHERE active = 1' : '') . ' ORDER BY sort, label')->fetchAll(); }

function mv_reason_save(PDO $db, array $u, int $id, string $label, int $sort, bool $active): int {
    $label = vis_clean($label, 80);
    if ($label === '') throw new RuntimeException('Give the reason a name.');
    try {
        if ($id) $db->prepare('UPDATE mv_reason SET label = ?, sort = ?, active = ?, updated_by = ? WHERE id = ?')->execute([$label, $sort, $active ? 1 : 0, $u['id'], $id]);
        else { $db->prepare('INSERT INTO mv_reason (label, sort, updated_by) VALUES (?,?,?)')->execute([$label, $sort, $u['id']]); $id = (int)$db->lastInsertId(); }
    } catch (PDOException $e) { throw new RuntimeException('That reason is already on the list.'); }
    audit((int)$u['id'], 'movement_reason', '#' . $id . ($active ? '' : ' off'));
    return $id;
}

/** People who may be sent out: on the roll, in scope, department per the setting. Code, name, department, branch. */
function mv_people(PDO $db, ?array $scope, array $set): array {
    [$w, $p] = vis_scope_sql($scope, 'e.branch_id');
    $s = $db->prepare("SELECT e.code, e.name, e.department AS dept, e.branch_id FROM employee e WHERE e.active = 1 AND (e.last_day IS NULL OR e.last_day >= CURDATE()) AND $w ORDER BY e.name");
    $s->execute($p);
    return array_values(array_filter($s->fetchAll(), fn($r) => mv_dept_ok((string)$r['dept'], (string)$set['departments'])));
}

/** Send people out. All or nothing; the same form sent twice returns the first group. Returns [first id, count]. */
function mv_send_out(PDO $db, array $u, array $in, array $people, ?array $scope): array {
    $uid = (int)$u['id'];
    $token = preg_match('/^[0-9a-f]{32}$/', (string)($in['token'] ?? '')) ? (string)$in['token'] : null;
    if ($token) {
        $s = $db->prepare('SELECT id, group_id FROM mv_entry WHERE form_token = ?'); $s->execute([$token]);
        if ($r = $s->fetch()) { $c = $db->prepare('SELECT COUNT(*) FROM mv_entry WHERE group_id = ?'); $c->execute([(int)$r['group_id']]); return [(int)$r['id'], $r['group_id'] ? (int)$c->fetchColumn() : 1]; }
    }
    $pick = mv_pick((array)($in['codes'] ?? []), $people);
    foreach ($pick as $p) if (!vis_in($scope, (int)$p['branch_id'])) throw new RuntimeException('You can record movement only for your branches.');
    $rid = (int)($in['reason_id'] ?? 0) ?: null;
    if ($rid) { $c = $db->prepare('SELECT id FROM mv_reason WHERE id = ? AND active = 1'); $c->execute([$rid]); if (!$c->fetchColumn()) throw new RuntimeException('Choose a reason from the list.'); }
    $other = vis_clean($in['reason_other'] ?? '', 200);
    $dest = vis_clean($in['destination'] ?? '', 200);
    $veh = strtoupper((string)preg_replace('/[^A-Za-z0-9 -]/', '', vis_clean($in['vehicle_no'] ?? '', 20)));
    $mat = vis_clean($in['material_out'] ?? '', 300);
    $db->beginTransaction();
    try {
        $first = 0;
        $ins = $db->prepare("INSERT INTO mv_entry (group_id, emp_code, emp_name, emp_dept, branch_id, reason_id, reason_other, destination, vehicle_no, material_out, status, out_at, open_key, form_token, created_by, updated_by)
                             VALUES (?,?,?,?,?,?,?,?,?,?,'out',NOW(),?,?,?,?)");
        foreach ($pick as $i => $p) {
            $code = strtoupper((string)$p['code']);
            $o = $db->prepare('SELECT mv_no FROM mv_entry WHERE open_key = ?'); $o->execute([$code]);
            if ($n = $o->fetchColumn()) throw new RuntimeException($p['name'] . ' is already out (' . $n . '). Mark them back in first.');
            $ins->execute([$first ?: null, $code, $p['name'], (string)$p['dept'], $p['branch_id'] !== null ? (int)$p['branch_id'] : null, $rid, $other, $dest, $veh, $mat, $code, $i === 0 ? $token : null, $uid, $uid]);
            $id = (int)$db->lastInsertId();
            $db->prepare("UPDATE mv_entry SET mv_no = CONCAT('M', DATE_FORMAT(out_at, '%y%m'), '-', LPAD(?, 5, '0')) WHERE id = ?")->execute([$id, $id]);
            if (!$first) $first = $id;
        }
        if (count($pick) > 1) $db->prepare('UPDATE mv_entry SET group_id = ? WHERE id = ?')->execute([$first, $first]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'uk_open')) throw new RuntimeException('Someone in this list is already out. Mark them back in first.');
        throw $e;
    }
    audit($uid, 'movement_out', '#' . $first . ' · ' . count($pick) . ' out');
    return [$first, count($pick)];
}

function mv_get(PDO $db, int $id, ?array $scope): ?array {
    $s = $db->prepare('SELECT m.*, r.label AS reason FROM mv_entry m LEFT JOIN mv_reason r ON r.id = m.reason_id WHERE m.id = ?');
    $s->execute([$id]); $m = $s->fetch();
    return $m && ($scope === null || ($m['branch_id'] !== null && vis_in($scope, (int)$m['branch_id']))) ? $m : null;
}

/** Back in (server time). False when already in. */
function mv_mark_in(PDO $db, array $u, int $id, ?array $scope, string $material = '', string $note = ''): bool {
    $m = mv_get($db, $id, $scope);
    if (!$m) throw new RuntimeException('Entry not found.');
    if ($m['status'] === 'in') return false;
    $mb = mv_material_check((string)$m['material_out'], $material, $note);
    $s = $db->prepare("UPDATE mv_entry SET status = 'in', in_at = GREATEST(NOW(), out_at), duration_min = TIMESTAMPDIFF(MINUTE, out_at, GREATEST(NOW(), out_at)),
                       open_key = NULL, material_back = ?, material_note = ?, in_by = ?, updated_by = ? WHERE id = ? AND status = 'out'");
    $s->execute([$mb, vis_clean($note, 300), $u['id'], $u['id'], $id]);
    if (!$s->rowCount()) return false;
    audit((int)$u['id'], 'movement_in', '#' . $id . ($mb ? ' · material ' . $mb : ''));
    return true;
}

/** Everyone of the group of entry $id still out (in scope) — for "all back". Material entries are not included. */
function mv_group_open(PDO $db, int $id, ?array $scope): array {
    $m = mv_get($db, $id, $scope);
    if (!$m) return [];
    if (!$m['group_id']) return $m['status'] === 'out' ? [$m] : [];
    $s = $db->prepare("SELECT * FROM mv_entry WHERE group_id = ? AND status = 'out' ORDER BY id"); $s->execute([(int)$m['group_id']]);
    return array_values(array_filter($s->fetchAll(), fn($r) => $scope === null || ($r['branch_id'] !== null && vis_in($scope, (int)$r['branch_id']))));
}

/** Correct an out / in time ('Y-m-d H:i'); a reason is needed and the original is kept. */
function mv_correct_time(PDO $db, array $u, int $id, string $field, string $value, string $reason): void {
    if (!in_array($field, ['out_at', 'in_at'], true)) throw new RuntimeException('Unknown field.');
    $reason = vis_clean($reason, 300);
    if ($reason === '') throw new RuntimeException('Give a reason for the correction.');
    $t = DateTimeImmutable::createFromFormat('Y-m-d H:i', str_replace('T', ' ', trim($value)));
    if (!$t) throw new RuntimeException('Give a date and time.');
    $new = $t->format('Y-m-d H:i:00');
    if ($new > date('Y-m-d H:i:s')) throw new RuntimeException('A time in the future cannot be recorded.');
    $m = mv_get($db, $id, null);
    if (!$m || !$m[$field]) throw new RuntimeException('Nothing to correct.');
    $out = $field === 'out_at' ? $new : (string)$m['out_at'];
    $in = $field === 'in_at' ? $new : $m['in_at'];
    if ($in !== null && $in < $out) throw new RuntimeException('Back-in time cannot be before going out.');
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO mv_correction (entry_id, field, old_value, new_value, reason, by_user) VALUES (?,?,?,?,?,?)')->execute([$id, $field, $m[$field], $new, $reason, $u['id']]);
        $dur = $in === null ? null : intdiv(strtotime((string)$in) - strtotime($out), 60);
        $db->prepare("UPDATE mv_entry SET $field = ?, duration_min = ?, updated_by = ? WHERE id = ?")->execute([$new, $dur, $u['id'], $id]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'movement_correct', '#' . $id . ' ' . $field);
}

function mv_corrections(PDO $db, int $id): array {
    $s = $db->prepare('SELECT c.*, u.name AS by_name FROM mv_correction c LEFT JOIN users u ON u.id = c.by_user WHERE c.entry_id = ? ORDER BY c.id'); $s->execute([$id]);
    return $s->fetchAll();
}

/** Shift end (HH:MM) for a person on a day, from Shifts (rule, else branch); null = flexible / unknown. */
function mv_shift_end(PDO $db, string $code, string $day): ?string {
    try { $e = emp_by_code($db, $code); if (!$e) return null; $s = shift_for($db, $e, $day); return $s['flexible'] ? null : ($s['end'] ? substr((string)$s['end'], 0, 5) : null); }
    catch (Throwable $e) { return null; }
}

/** People still out in scope (optionally one branch), oldest first, each with flags. */
function mv_open(PDO $db, ?array $scope, int $branch = 0, ?int $now = null): array {
    $now ??= time(); $set = mv_settings();
    [$w, $p] = vis_scope_sql($scope, 'm.branch_id');
    if ($branch) { $w .= ' AND m.branch_id = ?'; $p[] = $branch; }
    $s = $db->prepare("SELECT m.*, r.label AS reason FROM mv_entry m LEFT JOIN mv_reason r ON r.id = m.reason_id WHERE m.status = 'out' AND $w ORDER BY m.out_at");
    $s->execute($p);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) $r += mv_flags($r, mv_limit($set, $r['branch_id'] !== null ? (int)$r['branch_id'] : null), mv_shift_end($db, (string)$r['emp_code'], substr((string)$r['out_at'], 0, 10)), $now);
    unset($r);
    return $rows;
}

/** Entries back today in scope (desk). */
function mv_back_today(PDO $db, ?array $scope, int $branch = 0): array {
    [$w, $p] = vis_scope_sql($scope, 'm.branch_id');
    if ($branch) { $w .= ' AND m.branch_id = ?'; $p[] = $branch; }
    $s = $db->prepare("SELECT m.*, r.label AS reason FROM mv_entry m LEFT JOIN mv_reason r ON r.id = m.reason_id WHERE m.status = 'in' AND m.in_at >= CURDATE() AND $w ORDER BY m.in_at DESC");
    $s->execute($p);
    return $s->fetchAll();
}

/** Logins to alert for a branch: holders of an alert role with that branch ticked (nothing ticked = no branch). */
function mv_alert_people(PDO $db, ?int $branch, array $roles): array {
    if ($branch === null || !$roles) return [];
    $in = implode(',', array_fill(0, count($roles), '?'));
    $s = $db->prepare("SELECT DISTINCT u.id, u.name, u.email FROM users u JOIN user_role r ON r.user_id = u.id JOIN user_branch b ON b.user_id = u.id
                       WHERE u.active = 1 AND r.role_code IN ($in) AND b.branch_id = ?");
    $s->execute([...$roles, $branch]);
    return $s->fetchAll();
}

/** Raise alerts for late entries (all branches): once per entry + kind + person; email when switched on. Returns new alerts. */
function mv_alert_tick(PDO $db, ?int $now = null): int {
    $set = mv_settings(); $n = 0;
    foreach (mv_open($db, null, 0, $now) as $m) {
        foreach (array_keys(MV_ALERT_KINDS) as $k) {
            if (empty($m[$k])) continue;
            foreach (mv_alert_people($db, $m['branch_id'] !== null ? (int)$m['branch_id'] : null, (array)$set['alert_roles']) as $p) {
                $ins = $db->prepare("INSERT IGNORE INTO mv_alert (entry_id, kind, user_id, status) VALUES (?,?,?,'noted')");
                $ins->execute([(int)$m['id'], $k, (int)$p['id']]);
                if (!$ins->rowCount()) continue;
                $aid = (int)$db->lastInsertId(); $n++;
                if (!$set['alert_email'] || !module_on('email') || !mail_valid((string)$p['email'])) continue;
                try {
                    $mid = mail_queue($db, ['to' => $p['email'], 'subject' => 'Staff movement: ' . $m['emp_name'] . ' — ' . MV_ALERT_KINDS[$k],
                        'text' => $m['emp_name'] . ' (' . $m['emp_code'] . ') went out at ' . substr((string)$m['out_at'], 11, 5) . ' on ' . substr((string)$m['out_at'], 0, 10)
                            . ' and is not back yet (' . mv_dur((int)$m['mins']) . ").\n" . ($m['destination'] !== '' ? 'Going to: ' . $m['destination'] . "\n" : '')
                            . "\nOpen: " . wn_site_url() . 'modules/movement/index.php?view=entry&id=' . (int)$m['id']], 'notice', 'movement:' . (int)$m['id']);
                    $db->prepare("UPDATE mv_alert SET mail_id = ?, status = 'queued' WHERE id = ?")->execute([$mid, $aid]);
                } catch (Throwable $e) { $db->prepare("UPDATE mv_alert SET status = 'failed' WHERE id = ?")->execute([$aid]); }
            }
        }
    }
    if ($n) audit(null, 'movement_alerts', (string)$n);
    return $n;
}

function mv_alerts_for(PDO $db, int $id): array {
    $s = $db->prepare('SELECT a.*, u.name FROM mv_alert a LEFT JOIN users u ON u.id = a.user_id WHERE a.entry_id = ? ORDER BY a.id'); $s->execute([$id]);
    return $s->fetchAll();
}

/** Log: filters from / to / branch / reason / text. */
function mv_list(PDO $db, ?array $scope, string $from, string $to, int $branch = 0, int $reason = 0, string $q = '', int $limit = 1000): array {
    [$w, $p] = vis_scope_sql($scope, 'm.branch_id');
    $w .= ' AND m.out_at >= ? AND m.out_at < DATE_ADD(?, INTERVAL 1 DAY)'; $p[] = $from; $p[] = $to;
    if ($branch) { $w .= ' AND m.branch_id = ?'; $p[] = $branch; }
    if ($reason) { $w .= ' AND m.reason_id = ?'; $p[] = $reason; }
    if (trim($q) !== '') { $w .= ' AND (m.emp_name LIKE ? OR m.emp_code LIKE ? OR m.destination LIKE ?)'; $l = '%' . trim($q) . '%'; array_push($p, $l, $l, $l); }
    $s = $db->prepare("SELECT m.*, r.label AS reason FROM mv_entry m LEFT JOIN mv_reason r ON r.id = m.reason_id WHERE $w ORDER BY m.out_at DESC LIMIT " . max(1, $limit));
    $s->execute($p);
    return $s->fetchAll();
}

function mv_csv(array $rows, array $bn): string {
    $line = fn(array $c) => implode(',', array_map('vis_csv_cell', $c)) . "\r\n";
    $o = $line(['No', 'Code', 'Name', 'Department', 'Branch', 'Out', 'In', 'Minutes', 'Reason', 'Destination', 'Vehicle', 'Material out', 'Material back', 'Material note']);
    foreach ($rows as $r) $o .= $line([$r['mv_no'], $r['emp_code'], $r['emp_name'], $r['emp_dept'], $bn[(int)$r['branch_id']] ?? '', $r['out_at'], $r['in_at'],
        $r['duration_min'], $r['reason'] ?: $r['reason_other'], $r['destination'], $r['vehicle_no'], $r['material_out'], MV_MATERIAL_BACK[$r['material_back'] ?? ''] ?? '', $r['material_note']]);
    return $o;
}
