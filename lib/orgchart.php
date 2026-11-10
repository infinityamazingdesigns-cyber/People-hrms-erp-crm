<?php
/**
 * Org chart — reporting lines drawn from the employee master (employee.manager_code) plus open positions.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - Every login with the module sees the chart: name, designation, department, branch, reports-to — nothing else.
 *  - Open positions, people on notice and leavers are shown only to chart holders (Super Admin, HR Manager, CEO).
 *  - Someone whose manager is hidden (left, switched off, out of the list) hangs from the first visible person above,
 *    so a leaver never breaks the tree for everyone else.
 *  - Moving a person never makes a circle (nobody may report to someone in their own team) and is logged through
 *    the employee master's history.
 *  - A leaver's seat can be opened as a replacement position (one open replacement per leaver).
 *  - Checks list what needs HR: no manager, manager has left, manager code unknown, circles, open positions under a
 *    leaver.
 */
declare(strict_types=1);
require_once __DIR__ . '/employees.php';

const ORG_HOLDER_ROLES = ['SUPERADMIN', 'HRMANAGER', 'CEO'];

function org_holder(array $u): bool { return has_role($u, ...ORG_HOLDER_ROLES); }

/* ---------------- pure ---------------- */

/** code => manager code, for the people given. */
function org_parent_map(array $people): array {
    $m = [];
    foreach ($people as $p) $m[strtoupper((string)$p['code'])] = strtoupper(trim((string)$p['manager_code']));
    return $m;
}

/** First visible person above $code (walking past hidden managers), or ''. */
function org_visible_parent(string $code, array $allParents, array $visible): string {
    $p = $allParents[strtoupper($code)] ?? '';
    for ($i = 0; $i < 60 && $p !== ''; $i++) {
        if (isset($visible[$p])) return $p;
        $p = $allParents[$p] ?? '';
    }
    return '';
}

/** Line from the top down to $code (inclusive). Stops on a circle. */
function org_chain(string $code, array $parents): array {
    $out = [strtoupper($code)]; $seen = [strtoupper($code) => true];
    $p = $parents[strtoupper($code)] ?? '';
    while ($p !== '' && isset($parents[$p]) && !isset($seen[$p])) { $out[] = $p; $seen[$p] = true; $p = $parents[$p] ?? ''; }
    return array_reverse($out);
}

/** Would making $code report to $newMgr create a circle? */
function org_would_cycle(string $code, string $newMgr, array $parents): bool {
    $code = strtoupper($code); $p = strtoupper($newMgr);
    for ($i = 0; $i < 200 && $p !== ''; $i++) {
        if ($p === $code) return true;
        $p = $parents[$p] ?? '';
    }
    return false;
}

/** All people below $code (any depth). */
function org_team(string $code, array $parents): array {
    $kids = [];
    foreach ($parents as $c => $p) $kids[$p][] = $c;
    $out = []; $stack = $kids[strtoupper($code)] ?? [];
    while ($stack) { $c = array_pop($stack); if (isset($out[$c])) continue; $out[$c] = true; foreach ($kids[$c] ?? [] as $k) $stack[] = $k; }
    return array_keys($out);
}

/**
 * Build the visible tree. $people = employee rows; returns ['nodes' => code => node, 'kids' => code => [codes], 'roots' => [codes]].
 * Holders also get notice / left people (left only when $showLeft) and open positions as nodes "P<id>".
 */
function org_build(array $people, array $positions, bool $holder, string $today, bool $showLeft = false): array {
    $all = org_parent_map($people);
    $nodes = [];
    foreach ($people as $p) {
        if (!$p['active']) continue;
        $st = emp_status($p, $today);
        if ($st === 'left' && !($holder && $showLeft)) continue;
        $c = strtoupper((string)$p['code']);
        $n = ['code' => $p['code'], 'name' => $p['name'], 'designation' => $p['designation'], 'department' => $p['department'], 'branch_id' => $p['branch_id']];
        if ($holder) $n += ['status' => $st, 'last_day' => $p['last_day'], 'doj' => $p['doj']];
        $nodes[$c] = $n;
    }
    $parent = [];
    foreach ($nodes as $c => $n) $parent[$c] = org_visible_parent($c, $all, $nodes);
    if ($holder) foreach ($positions as $pos) {
        if ($pos['status'] !== 'open') continue;
        $k = 'P' . $pos['id'];
        $nodes[$k] = ['code' => $k, 'name' => 'Open position', 'designation' => $pos['title'], 'department' => $pos['department'],
            'branch_id' => $pos['branch_id'], 'status' => 'open', 'position' => (int)$pos['id'], 'replaces' => $pos['replaces_code']];
        $m = strtoupper((string)$pos['manager_code']);
        $parent[$k] = isset($nodes[$m]) ? $m : org_visible_parent($m, $all, $nodes);
    }
    $kids = []; $roots = [];
    foreach ($parent as $c => $p) { if ($p === '' || !isset($nodes[$p])) $roots[] = $c; else $kids[$p][] = $c; }
    $byName = fn($a, $b) => strcasecmp((string)$nodes[$a]['name'], (string)$nodes[$b]['name']);
    foreach ($kids as &$k) usort($k, $byName);
    unset($k);
    usort($roots, fn($a, $b) => count(org_team($b, $parent)) <=> count(org_team($a, $parent)) ?: $byName($a, $b));
    return ['nodes' => $nodes, 'kids' => $kids, 'roots' => $roots, 'parent' => $parent];
}

/** Things HR should look at. Returns list of [kind, code, text]. */
function org_checks(array $people, array $positions, string $today): array {
    $byCode = []; foreach ($people as $p) if ($p['active']) $byCode[strtoupper((string)$p['code'])] = $p;
    $parents = org_parent_map(array_values($byCode));
    $out = []; $roots = 0;
    foreach ($byCode as $c => $p) {
        if (emp_status($p, $today) === 'left') continue;
        $m = $parents[$c];
        if ($m === '') { $roots++; $out[] = ['nomgr', $p['code'], $p['name'] . ' has no manager']; continue; }
        if (!isset($byCode[$m])) $out[] = ['unknown', $p['code'], $p['name'] . ' reports to code ' . $m . ', which is not on the master'];
        elseif (emp_status($byCode[$m], $today) === 'left') $out[] = ['mgrleft', $p['code'], $p['name'] . ' reports to ' . $byCode[$m]['name'] . ', who has left'];
        if (org_would_cycle($c, $m, array_merge($parents, [$c => '']))) $out[] = ['cycle', $p['code'], $p['name'] . ' is in a reporting circle'];
    }
    if ($roots === 1) $out = array_values(array_filter($out, fn($r) => $r[0] !== 'nomgr'));   // one top person is normal
    foreach ($positions as $pos) {
        if ($pos['status'] !== 'open') continue;
        $m = strtoupper((string)$pos['manager_code']);
        if ($m !== '' && isset($byCode[$m]) && emp_status($byCode[$m], $today) === 'left')
            $out[] = ['posleft', 'P' . $pos['id'], 'Open position "' . $pos['title'] . '" sits under ' . $byCode[$m]['name'] . ', who has left'];
    }
    return $out;
}

/* ---------------- database ---------------- */

function org_positions(PDO $db, string $status = 'open'): array {
    try {
        $s = $db->prepare('SELECT * FROM org_position' . ($status === 'all' ? '' : ' WHERE status = ?') . ' ORDER BY id');
        $s->execute($status === 'all' ? [] : [$status]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** Move someone under a new manager. Logged through the employee master. */
function org_move(PDO $db, array $u, string $code, string $newMgr): ?string {
    if (!emp_can_edit($u)) return 'You can view the chart but not change it.';
    $e = emp_by_code($db, $code);
    if (!$e || !$e['active']) return 'Person not found.';
    $newMgr = trim($newMgr);
    if ($newMgr !== '') {
        $m = emp_by_code($db, $newMgr);
        if (!$m || !$m['active'] || emp_status($m, date('Y-m-d')) === 'left') return 'The new manager is not on the roll.';
        if (org_would_cycle($e['code'], $m['code'], org_parent_map($db->query('SELECT code, manager_code FROM employee WHERE active = 1')->fetchAll())))
            return 'That would make a circle — ' . $m['name'] . ' is in ' . $e['name'] . "'s own team.";
        $newMgr = $m['code'];
    }
    [, $err] = emp_save($db, $u, (int)$e['id'], ['manager_code' => $newMgr], 'edit', 'org chart move');
    return $err;
}

function org_position_add(PDO $db, array $u, array $in): ?string {
    if (!emp_can_edit($u)) return 'Not allowed.';
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') return 'Give the position a title.';
    $rep = trim((string)($in['replaces_code'] ?? ''));
    if ($rep !== '') {
        $s = $db->prepare("SELECT COUNT(*) FROM org_position WHERE status = 'open' AND replaces_code = ?");
        $s->execute([$rep]);
        if ((int)$s->fetchColumn() > 0) return 'There is already an open replacement for ' . $rep . '.';
    }
    $db->prepare('INSERT INTO org_position (title, department, branch_id, manager_code, replaces_code, note, created_by) VALUES (?,?,?,?,?,?,?)')
       ->execute([mb_substr($title, 0, 80), mb_substr(trim((string)($in['department'] ?? '')), 0, 80), ((int)($in['branch_id'] ?? 0)) ?: null,
                  trim((string)($in['manager_code'] ?? '')), $rep, mb_substr(trim((string)($in['note'] ?? '')), 0, 300), (int)$u['id']]);
    audit((int)$u['id'], 'org_position_add', '#' . $db->lastInsertId());
    return null;
}

/** Fill (with the code of the person who joined) or close (with a reason) an open position. Never deletes. */
function org_position_close(PDO $db, array $u, int $id, string $filledCode, string $note): ?string {
    if (!emp_can_edit($u)) return 'Not allowed.';
    $filledCode = trim($filledCode);
    if ($filledCode === '' && trim($note) === '') return 'Give the code of the person who filled it, or a reason for closing.';
    if ($filledCode !== '' && !emp_by_code($db, $filledCode)) return 'Code ' . $filledCode . ' is not on the master.';
    $s = $db->prepare("UPDATE org_position SET status = ?, filled_code = ?, note = IF(? = '', note, ?), closed_by = ?, closed_at = NOW() WHERE id = ? AND status = 'open'");
    $s->execute([$filledCode !== '' ? 'filled' : 'closed', $filledCode, $note, mb_substr($note, 0, 300), (int)$u['id'], $id]);
    if (!$s->rowCount()) return 'Position not found or already closed.';
    audit((int)$u['id'], 'org_position_' . ($filledCode !== '' ? 'fill' : 'close'), '#' . $id);
    return null;
}
