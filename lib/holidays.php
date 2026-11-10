<?php
/**
 * Holiday calendar — the one source of holidays for every module (attendance, payroll, announcement board).
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - A holiday has a date, a name, a kind (holiday / optional) and the branches it applies to; none ticked = every
 *    branch. Several days (a festival run) are entered once as a range and stored day by day.
 *  - The same festival on the same day is one row: adding it again for another branch only ticks that branch.
 *  - Nothing is deleted: a holiday is switched off with a reason; edits keep who and when.
 *  - Each person sees their own branch's holidays; HR sees all. A person can be pinned to another branch's calendar
 *    for holidays only (until a date or until further notice) — their shift and weekly off are not affected.
 *  - Lists of dates HR receives (a file) come in through Import: Check writes nothing, then Save; a date already there
 *    with the same holiday only gets its branches ticked.
 *  - Readers always go through hol_for() / hol_days() — never a date list written in code.
 */
declare(strict_types=1);
require_once __DIR__ . '/employees.php';

const HOL_KINDS = ['holiday' => 'Holiday', 'optional' => 'Optional holiday'];
const HOL_EDIT_ROLES = ['SUPERADMIN', 'HRMANAGER', 'HROPS'];
const HOL_RANGE_MAX = 15;

/* ---------------- pure ---------------- */

function hol_key(string $s): string { return preg_replace('/[^a-z0-9]/', '', strtolower($s)); }

/** Two names for the same holiday? ("Diwali" / "Diwali Day 1", "New Year" / "New Year's Day"). */
function hol_same(string $a, string $b): bool {
    $a = hol_key($a); $b = hol_key($b);
    if ($a === '' || $b === '') return false;
    $n = min(5, strlen($a), strlen($b));
    return str_contains($a, substr($b, 0, $n)) || str_contains($b, substr($a, 0, $n));
}

/** Branch id list from the stored text. */
function hol_branch_ids(string $csv): array { return array_values(array_filter(array_map('intval', explode(',', $csv)))); }

/** Does a holiday row apply to this branch? Empty list = every branch. */
function hol_applies(array $h, ?int $branchId): bool {
    $ids = is_array($h['branches']) ? $h['branches'] : hol_branch_ids((string)$h['branches']);
    return !$ids || ($branchId !== null && in_array($branchId, $ids, true));
}

/** Name of the holiday on $day for $branchId, or null. $rows = active holiday rows. */
function hol_for(array $rows, string $day, ?int $branchId, bool $withOptional = false): ?string {
    foreach ($rows as $h) {
        if ((string)$h['day'] !== $day || !(int)($h['active'] ?? 1)) continue;
        if (!$withOptional && ($h['kind'] ?? 'holiday') === 'optional') continue;
        if (hol_applies($h, $branchId)) return (string)$h['name'];
    }
    return null;
}

/** Days from $from to $to (inclusive, at most HOL_RANGE_MAX), or [] when the range is wrong. */
function hol_range(string $from, string $to): array {
    $a = DateTime::createFromFormat('!Y-m-d', $from); $b = DateTime::createFromFormat('!Y-m-d', $to ?: $from);
    if (!$a || !$b || $a > $b) return [];
    $out = [];
    for ($d = clone $a; $d <= $b && count($out) < HOL_RANGE_MAX; $d->modify('+1 day')) $out[] = $d->format('Y-m-d');
    return $d <= $b ? [] : $out;
}

/** Year shown on the calendar, by the organisation's year-start month. Returns [from, to, label]. */
function hol_year(string $day, int $startMonth): array {
    [$y, $m] = array_map('intval', explode('-', substr($day, 0, 7)));
    $start = $m >= $startMonth ? $y : $y - 1;
    if ($startMonth === 1) return ["$start-01-01", "$start-12-31", (string)$start];
    $from = sprintf('%d-%02d-01', $start, $startMonth);
    $to = date('Y-m-d', strtotime($from . ' +1 year -1 day'));
    return [$from, $to, $start . '–' . substr((string)($start + 1), 2)];
}

/**
 * Plan merging one holiday into the existing rows: returns ['add', null] (new row), ['tick', id, newBranches]
 * (same holiday that day: add branches), or ['same', id] (already there for those branches).
 */
function hol_plan_one(array $rows, string $day, string $name, array $branchIds): array {
    foreach ($rows as $h) {
        if ((string)$h['day'] !== $day || !(int)$h['active'] || !hol_same((string)$h['name'], $name)) continue;
        $have = hol_branch_ids((string)$h['branches']);
        if (!$have) return ['same', (int)$h['id']];                // already for every branch
        if (!$branchIds) return ['tick', (int)$h['id'], []];       // widen to every branch
        $new = array_values(array_unique(array_merge($have, $branchIds)));
        sort($new);
        return count($new) === count($have) ? ['same', (int)$h['id']] : ['tick', (int)$h['id'], $new];
    }
    return ['add', null];
}

/** Headers of an import file → field keys. */
function hol_header_map(array $head): array {
    $alias = ['day' => ['date', 'day', 'holiday date', 'from', 'from date'], 'to' => ['to', 'to date', 'till', 'until'],
        'name' => ['holiday', 'name', 'occasion', 'festival', 'description'], 'branches' => ['branch', 'branches', 'location', 'applies to'],
        'kind' => ['type', 'kind']];
    $map = [];
    foreach ($head as $i => $h) {
        $k = strtolower(trim((string)$h));
        foreach ($alias as $f => $names) if (in_array($k, $names, true) && !in_array($f, $map, true)) { $map[$i] = $f; break; }
    }
    return $map;
}

/** Branch names in a cell ("All", "Head Office, North Plant") → ids; unknown names reported. */
function hol_branch_cell(string $cell, array $branches): array {
    $cell = trim($cell);
    if ($cell === '' || preg_match('/^(all|every|all branches)$/i', $cell)) return [[], []];
    $ids = []; $bad = [];
    foreach (preg_split('/[,;\/|]+/', $cell) as $p) {
        if (($p = trim($p)) === '') continue;
        $name = branch_label($p, $branches); $hit = null;
        foreach ($branches as $b) if ($b['name'] === $name) $hit = (int)$b['id'];
        if ($hit === null) $bad[] = $p; else $ids[] = $hit;
    }
    sort($ids);
    return [array_values(array_unique($ids)), $bad];
}

/* ---------------- database ---------------- */

function hol_can_edit(array $u): bool { return has_role($u, ...HOL_EDIT_ROLES); }

function hol_rows(PDO $db, string $from, string $to, bool $activeOnly = true): array {
    try {
        $s = $db->prepare('SELECT * FROM holiday WHERE day BETWEEN ? AND ?' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY day, name');
        $s->execute([$from, $to]);
        return $s->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** The branch whose calendar a person follows (their own, unless pinned to another until a date). */
function hol_branch_of(PDO $db, string $code, ?int $ownBranch, ?string $day = null): ?int {
    $day ??= date('Y-m-d');
    try {
        $s = $db->prepare('SELECT branch_id, until_day FROM holiday_branch_override WHERE emp_code = ?');
        $s->execute([strtoupper($code)]);
        if (($o = $s->fetch()) && ($o['until_day'] === null || $o['until_day'] >= $day)) return (int)$o['branch_id'];
    } catch (Throwable $e) {}
    return $ownBranch;
}

/** Holidays of one person between two dates: day => name. Every module reads this. */
function hol_days(PDO $db, string $code, ?int $ownBranch, string $from, string $to): array {
    $rows = hol_rows($db, $from, $to);
    $out = [];
    foreach ($rows as $h) {
        $b = hol_branch_of($db, $code, $ownBranch, (string)$h['day']);
        if ($h['kind'] === 'holiday' && hol_applies($h, $b)) $out[(string)$h['day']] = (string)$h['name'];
    }
    return $out;
}

/** Add (or merge) a holiday over a range of days. Returns [added, ticked, same]. */
function hol_add(PDO $db, array $u, string $from, string $to, string $name, string $kind, array $branchIds): array {
    if (!hol_can_edit($u)) throw new RuntimeException('Only HR changes the holiday calendar.');
    $name = trim(mb_substr(strip_tags($name), 0, 80));
    if ($name === '') throw new RuntimeException('Give the holiday a name.');
    if (!isset(HOL_KINDS[$kind])) $kind = 'holiday';
    $days = hol_range($from, $to);
    if (!$days) throw new RuntimeException('Check the dates (at most ' . HOL_RANGE_MAX . ' days at a time).');
    $n = ['add' => 0, 'tick' => 0, 'same' => 0];
    foreach ($days as $d) {
        $plan = hol_plan_one(hol_rows($db, $d, $d), $d, $name, $branchIds);
        if ($plan[0] === 'add') $db->prepare('INSERT INTO holiday (day, name, kind, branches, created_by) VALUES (?,?,?,?,?)')->execute([$d, $name, $kind, implode(',', $branchIds), (int)$u['id']]);
        elseif ($plan[0] === 'tick') $db->prepare('UPDATE holiday SET branches = ?, updated_by = ? WHERE id = ?')->execute([implode(',', $plan[2]), (int)$u['id'], $plan[1]]);
        $n[$plan[0]]++;
    }
    audit((int)$u['id'], 'holiday_add', "$name · added {$n['add']}, ticked {$n['tick']}");
    return [$n['add'], $n['tick'], $n['same']];
}

function hol_edit(PDO $db, array $u, int $id, string $day, string $name, string $kind, array $branchIds): void {
    if (!hol_can_edit($u)) throw new RuntimeException('Only HR changes the holiday calendar.');
    $name = trim(mb_substr(strip_tags($name), 0, 80));
    if ($name === '' || !hol_range($day, $day)) throw new RuntimeException('Check the date and name.');
    $s = $db->prepare('UPDATE holiday SET day = ?, name = ?, kind = ?, branches = ?, updated_by = ? WHERE id = ? AND active = 1');
    $s->execute([$day, $name, isset(HOL_KINDS[$kind]) ? $kind : 'holiday', implode(',', $branchIds), (int)$u['id'], $id]);
    if (!$s->rowCount()) throw new RuntimeException('Holiday not found.');
    audit((int)$u['id'], 'holiday_edit', '#' . $id);
}

function hol_off(PDO $db, array $u, int $id, string $reason): void {
    if (!hol_can_edit($u)) throw new RuntimeException('Only HR changes the holiday calendar.');
    if (trim($reason) === '') throw new RuntimeException('Give a reason.');
    $s = $db->prepare('UPDATE holiday SET active = 0, off_reason = ?, updated_by = ? WHERE id = ? AND active = 1');
    $s->execute([mb_substr(trim($reason), 0, 300), (int)$u['id'], $id]);
    if (!$s->rowCount()) throw new RuntimeException('Holiday not found.');
    audit((int)$u['id'], 'holiday_off', '#' . $id);
}

/** Copy one year's holidays forward by a year (names and branches; HR then corrects the dates of moving festivals). */
function hol_copy_year(PDO $db, array $u, string $from, string $to): int {
    $n = 0;
    foreach (hol_rows($db, $from, $to) as $h) {
        $d = date('Y-m-d', strtotime($h['day'] . ' +1 year'));
        $n += hol_add($db, $u, $d, $d, (string)$h['name'], (string)$h['kind'], hol_branch_ids((string)$h['branches']))[0];
    }
    return $n;
}

function hol_override_set(PDO $db, array $u, string $code, ?int $branchId, ?string $until, string $reason): void {
    if (!hol_can_edit($u)) throw new RuntimeException('Only HR sets this.');
    if (!emp_by_code($db, $code)) throw new RuntimeException('Code not on the employee master.');
    if ($branchId === null) {
        $db->prepare('DELETE FROM holiday_branch_override WHERE emp_code = ?')->execute([strtoupper($code)]);
    } else {
        if (trim($reason) === '') throw new RuntimeException('Give a reason.');
        $db->prepare('INSERT INTO holiday_branch_override (emp_code, branch_id, until_day, reason, set_by) VALUES (?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE branch_id = VALUES(branch_id), until_day = VALUES(until_day), reason = VALUES(reason), set_by = VALUES(set_by), set_at = NOW()')
           ->execute([strtoupper($code), $branchId, $until ?: null, mb_substr($reason, 0, 300), (int)$u['id']]);
    }
    audit((int)$u['id'], 'holiday_override', strtoupper($code) . ' ' . ($branchId === null ? 'cleared' : 'set'));
}
