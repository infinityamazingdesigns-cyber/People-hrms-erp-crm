<?php
// Shifts & weekly offs — per-person rules with a start date that win over the branch's own shift and weekly off.
// Pure rule functions first; data functions after. Rules are never deleted: a new rule from the same day replaces the
// old one (kept, marked), and a rule can be ended with a reason (the branch's values come back from then on).
declare(strict_types=1);

const SHIFT_DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const SHIFT_NOTE_MAX = 200;

/** "9:30" / "09:30" / "0930" → "09:30"; '' → null; anything else → false. */
function shift_time(string $t) {
    $t = trim($t);
    if ($t === '') return null;
    if (preg_match('/^(\d{1,2}):?(\d{2})$/', $t, $m) && (int)$m[1] < 24 && (int)$m[2] < 60) return sprintf('%02d:%02d', $m[1], $m[2]);
    return false;
}

/** Ticked days → "Sun,Wed" in week order; unknown names dropped. */
function shift_weekoffs(array $days): string {
    $days = array_map(fn($d) => ucfirst(strtolower(substr(trim((string)$d), 0, 3))), $days);
    return implode(',', array_values(array_filter(SHIFT_DAYS, fn($d) => in_array($d, $days, true))));
}

/**
 * Check a rule from a form. $in: from_day, mode (fixed / flexible / branch), start, end, weekoffs[] or wo_branch, note.
 * Returns the clean row or throws with a plain reason.
 */
function shift_check(array $in, string $today): array {
    $from = (string)($in['from_day'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) throw new InvalidArgumentException('Give the date the rule starts from.');
    $mode = (string)($in['mode'] ?? 'fixed');
    if (!in_array($mode, ['fixed', 'flexible', 'branch'], true)) throw new InvalidArgumentException('Pick fixed hours, flexible hours or the branch\'s hours.');
    $row = ['from_day' => $from, 'start_time' => null, 'end_time' => null, 'flexible' => $mode === 'flexible' ? 1 : 0, 'use_branch_shift' => $mode === 'branch' ? 1 : 0];
    if ($mode === 'fixed') {
        $s = shift_time((string)($in['start'] ?? '')); $e = shift_time((string)($in['end'] ?? ''));
        if (!$s || !$e) throw new InvalidArgumentException('Give the shift start and end (e.g. 09:30 and 18:30).');
        if ($s === $e) throw new InvalidArgumentException('The shift cannot start and end at the same time.');
        $row['start_time'] = $s; $row['end_time'] = $e;
    }
    $row['weekoffs'] = !empty($in['wo_branch']) ? null : shift_weekoffs((array)($in['weekoffs'] ?? []));
    if ($row['weekoffs'] !== null && $row['weekoffs'] !== '' && count(explode(',', $row['weekoffs'])) > 3) throw new InvalidArgumentException('More than three weekly offs a week — check the ticks.');
    if ($row['use_branch_shift'] && $row['weekoffs'] === null) throw new InvalidArgumentException('That is the branch\'s shift and weekly off already — nothing to set.');
    $note = trim(strip_tags((string)($in['note'] ?? '')));
    if (mb_strlen($note) > SHIFT_NOTE_MAX) throw new InvalidArgumentException('Keep the note under ' . SHIFT_NOTE_MAX . ' characters.');
    $row['note'] = $note;
    if ($from > date('Y-m-d', strtotime($today . ' +1 year'))) throw new InvalidArgumentException('A rule can start at most a year ahead.');
    return $row;
}

/** Does a shift cross midnight (22:00–06:00)? */
function shift_overnight(?string $s, ?string $e): bool { return $s !== null && $e !== null && $e < $s; }

/**
 * What applies on $day: the person's newest live rule with from_day ≤ $day, else the branch.
 * Returns [start, end, flexible, weekoffs, source]. $rules = live rule rows for the person (any order).
 * A branch with no shift start counts as flexible hours.
 */
function shift_effective(array $rules, array $branch, string $day): array {
    $bs = !empty($branch['shift_start']) ? substr((string)$branch['shift_start'], 0, 5) : null;
    $be = !empty($branch['shift_end']) ? substr((string)$branch['shift_end'], 0, 5) : null;
    $out = ['start' => $bs, 'end' => $be, 'flexible' => $bs === null, 'weekoffs' => (string)($branch['weekoffs'] ?? 'Sun'), 'source' => 'branch'];
    $pick = null;
    foreach ($rules as $r) {
        if (!empty($r['removed_at']) || (string)$r['from_day'] > $day) continue;
        if ($pick === null || (string)$r['from_day'] > (string)$pick['from_day'] || ((string)$r['from_day'] === (string)$pick['from_day'] && (int)$r['id'] > (int)$pick['id'])) $pick = $r;
    }
    if ($pick === null) return $out;
    if (!(int)$pick['use_branch_shift']) {
        $out['flexible'] = (bool)(int)$pick['flexible'];
        $out['start'] = $out['flexible'] ? null : $pick['start_time'];
        $out['end'] = $out['flexible'] ? null : $pick['end_time'];
    }
    if ($pick['weekoffs'] !== null) $out['weekoffs'] = (string)$pick['weekoffs'];
    $out['source'] = 'rule';
    $out['rule_id'] = (int)$pick['id'];
    return $out;
}

/** Day => effective values for a range. */
function shift_by_day(array $rules, array $branch, string $from, string $to): array {
    $o = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $o[$d] = shift_effective($rules, $branch, $d);
    return $o;
}

/** One line for screens: "09:30–18:30 · off Sun,Wed" / "Flexible hours · off Sun". */
function shift_label(array $eff): string {
    $h = $eff['flexible'] ? 'Flexible hours' : ($eff['start'] . '–' . $eff['end'] . (shift_overnight($eff['start'], $eff['end']) ? ' (next day)' : ''));
    return $h . ' · off ' . ($eff['weekoffs'] !== '' ? $eff['weekoffs'] : 'none');
}

// ---- data ----------------------------------------------------------------------------------------------------------

function shift_can_edit(array $u): bool { return has_role($u, 'SUPERADMIN', 'HRMANAGER', 'HROPS'); }

/** Live rules for several codes: code => [rows]. Cached per request. */
function shift_rules(PDO $db, ?array $codes = null): array {
    if (!isset($GLOBALS['__shift_rules'])) {
        $all = [];
        try { foreach ($db->query('SELECT * FROM shift_rule WHERE removed_at IS NULL ORDER BY from_day, id') as $r) $all[strtoupper($r['emp_code'])][] = $r; }
        catch (Throwable $e) { $all = []; }
        $GLOBALS['__shift_rules'] = $all;
    }
    $all = $GLOBALS['__shift_rules'];
    if ($codes === null) return $all;
    $o = [];
    foreach ($codes as $c) $o[strtoupper((string)$c)] = $all[strtoupper((string)$c)] ?? [];
    return $o;
}

/** Every rule a person ever had, newest first (history). */
function shift_history(PDO $db, string $code): array {
    $s = $db->prepare('SELECT r.*, u.name AS by_name, x.name AS removed_name FROM shift_rule r LEFT JOIN users u ON u.id = r.set_by LEFT JOIN users x ON x.id = r.removed_by WHERE r.emp_code = ? ORDER BY r.from_day DESC, r.id DESC');
    $s->execute([$code]);
    return $s->fetchAll();
}

function shift_branch(?int $id): array {
    foreach (branches(false) as $b) if ((int)$b['id'] === (int)$id) return $b;
    return ['weekoffs' => 'Sun', 'shift_start' => null, 'shift_end' => null];
}

/** What applies to an employee on a day. */
function shift_for(PDO $db, array $emp, string $day): array {
    return shift_effective(shift_rules($db, [(string)$emp['code']])[strtoupper((string)$emp['code'])], shift_branch($emp['branch_id'] ? (int)$emp['branch_id'] : null), $day);
}

/** Save a rule. A live rule from the same day is replaced (kept, marked "Replaced"). */
function shift_save(PDO $db, array $u, array $emp, array $in): int {
    if (!shift_can_edit($u)) throw new RuntimeException('Only HR sets shifts.');
    $row = shift_check($in, date('Y-m-d'));
    $db->beginTransaction();
    try {
        $old = $db->prepare('SELECT id FROM shift_rule WHERE emp_code = ? AND from_day = ? AND removed_at IS NULL FOR UPDATE');
        $old->execute([$emp['code'], $row['from_day']]);
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $id)
            $db->prepare("UPDATE shift_rule SET removed_at = NOW(), removed_by = ?, removed_reason = 'Replaced by a new rule from the same day' WHERE id = ?")->execute([$u['id'], $id]);
        $db->prepare('INSERT INTO shift_rule (emp_code, from_day, start_time, end_time, flexible, use_branch_shift, weekoffs, note, set_by) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$emp['code'], $row['from_day'], $row['start_time'], $row['end_time'], $row['flexible'], $row['use_branch_shift'], $row['weekoffs'], $row['note'], $u['id']]);
        $id = (int)$db->lastInsertId();
        audit((int)$u['id'], 'shift_rule_set', 'rule #' . $id . ' for ' . $emp['code'] . ' from ' . $row['from_day']);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    unset($GLOBALS['__shift_rules']);
    return $id;
}

/** End a rule (the branch's values or the earlier rule apply again). Needs a reason. */
function shift_end(PDO $db, array $u, int $id, string $reason): void {
    if (!shift_can_edit($u)) throw new RuntimeException('Only HR sets shifts.');
    $reason = trim(strip_tags($reason));
    if ($reason === '') throw new InvalidArgumentException('Give a reason.');
    $s = $db->prepare('UPDATE shift_rule SET removed_at = NOW(), removed_by = ?, removed_reason = ? WHERE id = ? AND removed_at IS NULL');
    $s->execute([$u['id'], mb_substr($reason, 0, 200), $id]);
    if (!$s->rowCount()) throw new RuntimeException('That rule is already ended.');
    audit((int)$u['id'], 'shift_rule_end', 'rule #' . $id);
    unset($GLOBALS['__shift_rules']);
}
