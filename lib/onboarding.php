<?php
/**
 * Onboarding — a checklist per new joiner from offer to the first weeks, then convert to the employee master.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - The checklist (phases: before joining, joining day, first week, first month) is HR's own list; a neutral starter
 *    set is installed, items are switched off (never deleted) and new ones added.
 *  - A joiner is added by HR with name and, when known, code, joining date, branch, department, designation, manager.
 *    "Already joined" ticks the before-joining and joining-day items.
 *  - Ticks keep who and when; an untick keeps the row (undone). Progress = ticked live items ÷ live items.
 *  - Convert to employee: needs a code, a name and a joining date; makes the employee master record (or links one
 *    already there with the same code and the same person). The employee's confirmation (probation) follows on the
 *    Probation page.
 *  - A joiner is never deleted: switched off with a reason (not joining, duplicate …).
 *  - HR roles only; within their branches.
 */
declare(strict_types=1);
require_once __DIR__ . '/employees.php';

const ONB_PHASES = ['pre' => 'Before joining', 'day1' => 'Joining day', 'wk1' => 'First week', 'month1' => 'First month'];
const ONB_BEFORE_START = ['pre', 'day1'];
const ONB_ROLES = ['SUPERADMIN', 'HRMANAGER', 'HROPS'];

function onb_can(array $u): bool { return has_role($u, ...ONB_ROLES); }

/** Check a joiner form. Pure. Returns the clean row; throws with a plain reason. */
function onb_check(array $in, array $branches): array {
    $name = trim(strip_tags((string)($in['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 120) throw new InvalidArgumentException('Give the joiner\'s name.');
    $code = strtoupper(trim((string)($in['emp_code'] ?? '')));
    if ($code !== '' && ($e = emp_code_ok($code))) throw new InvalidArgumentException('Employee code: ' . $e);
    $mgr = strtoupper(trim((string)($in['manager_code'] ?? '')));
    if ($mgr !== '' && ($e = emp_code_ok($mgr))) throw new InvalidArgumentException('Reports to: ' . $e);
    $join = emp_date((string)($in['join_date'] ?? ''));
    if ($join === 'bad') throw new InvalidArgumentException('Joining date: not a date.');
    $br = (int)($in['branch_id'] ?? 0);
    if ($br && !in_array($br, array_map(fn($b) => (int)$b['id'], $branches), true)) throw new InvalidArgumentException('Pick the branch from the list.');
    $email = strtolower(trim((string)($in['email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email: not an email address.');
    $phone = trim((string)($in['phone'] ?? ''));
    if ($phone !== '' && !preg_match('/^\+?[0-9 ()-]{6,20}$/', $phone)) throw new InvalidArgumentException('Phone: digits only.');
    $t = fn($k, $n) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $n);
    return ['name' => $name, 'emp_code' => $code, 'designation' => $t('designation', 80), 'department' => $t('department', 80), 'branch_id' => $br ?: null,
            'manager_code' => $mgr, 'join_date' => $join ?: null, 'email' => $email, 'phone' => $phone, 'note' => $t('note', 500)];
}

/** Progress over live items. $ticked = item ids ticked. Pure. */
function onb_progress(array $items, array $ticked): array {
    $live = array_filter($items, fn($i) => (int)$i['active']);
    $done = count(array_filter($live, fn($i) => isset($ticked[(int)$i['id']])));
    $by = [];
    foreach ($live as $i) { $by[$i['phase']][0] = ($by[$i['phase']][0] ?? 0) + (isset($ticked[(int)$i['id']]) ? 1 : 0); $by[$i['phase']][1] = ($by[$i['phase']][1] ?? 0) + 1; }
    return ['done' => $done, 'of' => count($live), 'pct' => $live ? (int)round($done * 100 / count($live)) : 0, 'phases' => $by];
}

/** Does an employee record already there belong to this joiner? Same code and the same person. Pure. */
function onb_same_person(array $joiner, array $emp): bool {
    return strcasecmp($joiner['emp_code'], $emp['code']) === 0 && emp_same_person($joiner['name'], $emp['name']);
}

/* ---------------- data ---------------- */

function onb_items(PDO $db, bool $activeOnly = false): array {
    return $db->query('SELECT * FROM onb_item' . ($activeOnly ? ' WHERE active = 1' : '') . " ORDER BY FIELD(phase,'pre','day1','wk1','month1'), sort, id")->fetchAll();
}

function onb_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM onb_joiner WHERE id = ?'); $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Live ticks: item id => [by, at]. */
function onb_ticks(PDO $db, int $id): array {
    $s = $db->prepare('SELECT t.item_id, t.done_at, u.name FROM onb_tick t LEFT JOIN users u ON u.id = t.done_by WHERE t.joiner_id = ? AND t.undone_at IS NULL');
    $s->execute([$id]);
    $o = [];
    foreach ($s as $r) $o[(int)$r['item_id']] = ['by' => (string)$r['name'], 'at' => (string)$r['done_at']];
    return $o;
}

function onb_guard(array $u, ?array $j = null): void {
    if (!onb_can($u)) throw new RuntimeException('Only HR runs onboarding.');
    if ($j && $j['branch_id'] && !emp_in_scope($u, $j)) throw new RuntimeException('This joiner is outside your branches.');
}

function onb_code_taken(PDO $db, string $code, int $except = 0): bool {
    if ($code === '') return false;
    $s = $db->prepare('SELECT COUNT(*) FROM onb_joiner WHERE emp_code = ? AND active = 1 AND id <> ?'); $s->execute([$code, $except]);
    return (int)$s->fetchColumn() > 0;
}

function onb_add(PDO $db, array $u, array $in, bool $alreadyJoined): int {
    onb_guard($u);
    $r = onb_check($in, branches(false));
    if ($r['branch_id'] && !emp_in_scope($u, $r)) throw new RuntimeException('That branch is outside your scope.');
    if (onb_code_taken($db, $r['emp_code'])) throw new RuntimeException('There is already a checklist for code ' . $r['emp_code'] . '.');
    $db->prepare('INSERT INTO onb_joiner (name, emp_code, designation, department, branch_id, manager_code, join_date, email, phone, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([...array_values($r), (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    if ($alreadyJoined) {
        $ins = $db->prepare('INSERT INTO onb_tick (joiner_id, item_id, done_by) VALUES (?,?,?)');
        foreach (onb_items($db, true) as $i) if (in_array($i['phase'], ONB_BEFORE_START, true)) $ins->execute([$id, $i['id'], (int)$u['id']]);
    }
    audit((int)$u['id'], 'onb_add', '#' . $id);
    return $id;
}

function onb_edit(PDO $db, array $u, int $id, array $in): void {
    $j = onb_get($db, $id); onb_guard($u, $j);
    if (!$j) throw new RuntimeException('Joiner not found.');
    $r = onb_check($in, branches(false));
    if ($j['employee_id']) unset($r['emp_code']);   // the code is the employee master's now
    elseif (onb_code_taken($db, $r['emp_code'], $id)) throw new RuntimeException('There is already a checklist for code ' . $r['emp_code'] . '.');
    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($r)));
    $db->prepare("UPDATE onb_joiner SET $sets WHERE id = ?")->execute([...array_values($r), $id]);
    audit((int)$u['id'], 'onb_edit', '#' . $id);
}

function onb_tick(PDO $db, array $u, int $id, int $item, bool $on): void {
    $j = onb_get($db, $id); onb_guard($u, $j);
    if (!$j || !$j['active']) throw new RuntimeException('Joiner not found.');
    $s = $db->prepare('SELECT COUNT(*) FROM onb_item WHERE id = ? AND active = 1'); $s->execute([$item]);
    if (!(int)$s->fetchColumn()) throw new RuntimeException('Unknown checklist item.');
    $live = isset(onb_ticks($db, $id)[$item]);
    if ($on && !$live) $db->prepare('INSERT INTO onb_tick (joiner_id, item_id, done_by) VALUES (?,?,?)')->execute([$id, $item, (int)$u['id']]);
    if (!$on && $live) $db->prepare('UPDATE onb_tick SET undone_by = ?, undone_at = NOW() WHERE joiner_id = ? AND item_id = ? AND undone_at IS NULL')->execute([(int)$u['id'], $id, $item]);
}

function onb_off(PDO $db, array $u, int $id, string $reason): void {
    $j = onb_get($db, $id); onb_guard($u, $j);
    if (!$j) throw new RuntimeException('Joiner not found.');
    if (trim($reason) === '') throw new InvalidArgumentException('Give a reason.');
    $db->prepare('UPDATE onb_joiner SET active = 0, off_reason = ? WHERE id = ?')->execute([mb_substr(trim($reason), 0, 300), $id]);
    audit((int)$u['id'], 'onb_off', '#' . $id);
}

/** Make (or link) the employee master record. Returns the employee id. */
function onb_convert(PDO $db, array $u, int $id): int {
    $j = onb_get($db, $id); onb_guard($u, $j);
    if (!$j || !$j['active']) throw new RuntimeException('Joiner not found.');
    if ($j['employee_id']) return (int)$j['employee_id'];
    if ($j['emp_code'] === '' || !$j['join_date']) throw new RuntimeException('Set the employee code and the joining date first.');
    if ($emp = emp_by_code($db, $j['emp_code'])) {
        if (!onb_same_person($j, $emp)) throw new RuntimeException('Code ' . $j['emp_code'] . ' already belongs to ' . $emp['name'] . ' on the employee master.');
        $eid = (int)$emp['id'];
    } else {
        $clean = array_filter(['code' => $j['emp_code'], 'name' => $j['name'], 'designation' => $j['designation'], 'department' => $j['department'],
            'branch_id' => $j['branch_id'], 'manager_code' => $j['manager_code'], 'doj' => $j['join_date'], 'work_email' => $j['email'], 'work_phone' => $j['phone']], fn($v) => $v !== null && $v !== '');
        [$eid, $err] = emp_save($db, $u, null, $clean, 'add', 'From onboarding #' . $id);
        if ($err) throw new RuntimeException($err);
    }
    $db->prepare('UPDATE onb_joiner SET employee_id = ? WHERE id = ?')->execute([$eid, $id]);
    audit((int)$u['id'], 'onb_convert', '#' . $id . ' → employee #' . $eid);
    return (int)$eid;
}

function onb_item_add(PDO $db, array $u, string $phase, string $text, string $owner): void {
    onb_guard($u);
    if (!isset(ONB_PHASES[$phase])) throw new InvalidArgumentException('Pick the phase.');
    $text = trim(strip_tags($text));
    if ($text === '' || mb_strlen($text) > 160) throw new InvalidArgumentException('Write the item (up to 160 characters).');
    $s = $db->prepare('SELECT COALESCE(MAX(sort), 0) + 10 FROM onb_item WHERE phase = ?'); $s->execute([$phase]);
    $db->prepare('INSERT INTO onb_item (phase, text, owner, sort) VALUES (?,?,?,?)')->execute([$phase, $text, mb_substr(trim(strip_tags($owner)), 0, 60), (int)$s->fetchColumn()]);
    audit((int)$u['id'], 'onb_item_add', $phase);
}

function onb_item_switch(PDO $db, array $u, int $id, bool $on, string $reason = ''): void {
    onb_guard($u);
    if (!$on && trim($reason) === '') throw new InvalidArgumentException('Give a reason.');
    $db->prepare('UPDATE onb_item SET active = ?, off_reason = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? '' : mb_substr(trim($reason), 0, 200), $id]);
    audit((int)$u['id'], 'onb_item_' . ($on ? 'on' : 'off'), '#' . $id);
}

/** Live joiners in this login's branches, newest joining first. */
function onb_list(PDO $db, array $u, bool $withOff = false): array {
    $rows = $db->query('SELECT * FROM onb_joiner' . ($withOff ? '' : ' WHERE active = 1') . ' ORDER BY COALESCE(join_date, created_at) DESC, id DESC')->fetchAll();
    return array_values(array_filter($rows, fn($j) => !$j['branch_id'] || emp_in_scope($u, $j)));
}
