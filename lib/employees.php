<?php
/**
 * Employee master — the one list of people every other module reads.
 *
 * Rules (ported from the reference build, organisation data removed):
 *  - One record per employee code; codes are unique and never re-used silently. A passphrase-like value typed into a
 *    code box by a browser's autofill ("word-word-word") is refused.
 *  - Status as on a day: On the roll → On notice (resignation given, last working day still ahead) → Left (last working
 *    day before that day). Nobody is removed: leavers keep their record, a wrong record is switched off with a reason.
 *  - Every changed field is written to employee_log with old and new values (append-only); personal fields (date of
 *    birth, gender) are only shown to logins allowed the "personal" data class.
 *  - A code change keeps a log line and moves manager links that pointed at the old code.
 *  - Imports: Check writes nothing; each row is New · Update · Same · Possible duplicate · Error; Save takes only what
 *    was ticked. A row whose code exists under a different person's name is never merged by itself.
 *  - Pickers and lists of "people on the roll" never offer someone who has left.
 *  - Branch scope: a login tied to branches sees and changes only those branches' people.
 */
declare(strict_types=1);

const EMP_FIELDS = [   // field => [label, kind, data class]
    'code' => ['Employee code', 'code', 'basic'],
    'name' => ['Full name', 'text', 'basic'],
    'department' => ['Department', 'text', 'basic'],
    'designation' => ['Designation', 'text', 'basic'],
    'grade' => ['Grade', 'text', 'basic'],
    'category' => ['Category', 'text', 'basic'],
    'branch_id' => ['Branch', 'branch', 'basic'],
    'manager_code' => ['Reports to (code)', 'code_opt', 'basic'],
    'doj' => ['Date of joining', 'date', 'basic'],
    'confirm_date' => ['Confirmation date', 'date', 'basic'],
    'work_email' => ['Work email', 'email', 'contact'],
    'work_phone' => ['Work phone', 'phone', 'contact'],
    'gender' => ['Gender', 'gender', 'personal'],
    'dob' => ['Date of birth', 'date', 'personal'],
    'resign_date' => ['Resignation date', 'date', 'basic'],
    'last_day' => ['Last working day', 'date', 'basic'],
    'exit_type' => ['Exit type', 'exit', 'basic'],
    'exit_reason' => ['Exit reason', 'text', 'basic'],
];
const EMP_GENDERS = ['Female', 'Male', 'Other'];
const EMP_EXIT_TYPES = ['Resigned', 'Terminated', 'Retired', 'Contract ended', 'Absconding', 'Deceased', 'Other'];
const EMP_EDIT_ROLES = ['SUPERADMIN', 'HRMANAGER', 'HROPS'];
const EMP_IMPORT_MAX = 5000;

/* ---------------- pure rules ---------------- */

function emp_code_ok(string $code): ?string {
    $c = trim($code);
    if ($c === '') return 'Employee code is required.';
    if (preg_match('/^[A-Za-z]{2,}(?:-[A-Za-z]{2,}){2,}/', $c)) return 'That looks like a saved password, not an employee code.';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\/.-]{0,31}$/', $c)) return 'Employee code: letters, digits and _ / . - only (max 32).';
    return null;
}

/** Status as on $today: active | notice | left. Pure. */
function emp_status(array $e, string $today): string {
    $last = (string)($e['last_day'] ?? '');
    if ($last !== '' && $last < $today) return 'left';
    if ($last !== '' || (string)($e['resign_date'] ?? '') !== '') return 'notice';
    return 'active';
}

function emp_status_label(array $e, string $today, string $fmt = 'd M Y'): string {
    $d = (string)($e['last_day'] ?? '');
    $ds = $d !== '' ? ' · last day ' . date($fmt, strtotime($d)) : '';
    return match (emp_status($e, $today)) { 'notice' => 'On notice' . $ds, 'left' => 'Left' . $ds, default => 'On the roll' };
}

/** Words of a name (letters only, 3+ letters). */
function emp_words(string $n): array {
    return array_values(array_filter(preg_split('/\s+/', strtolower(preg_replace('/[^a-z ]/i', ' ', $n))), fn($w) => strlen($w) > 2));
}

/** Same person? First words equal or one starts the other, and (when both have one) last words alike. Pure. */
function emp_same_person(string $a, string $b): bool {
    $x = emp_words($a); $y = emp_words($b);
    if (!$x || !$y) return false;
    $f = fn($p, $q) => $p === $q || (strlen($p) >= 3 && strlen($q) >= 3 && (str_starts_with($p, $q) || str_starts_with($q, $p)));
    if (!$f($x[0], $y[0])) return false;
    if (count($x) > 1 && count($y) > 1) {
        $lx = end($x); $ly = end($y);
        return $f($lx, $ly) || levenshtein($lx, $ly) <= 2;
    }
    return true;
}

function emp_date(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd M Y', 'j M Y', 'd-M-Y', 'Y/m/d'] as $f) {
        $d = DateTime::createFromFormat('!' . $f, $v);
        if ($d && $d->format($f) === $v) return $d->format('Y-m-d');
    }
    return 'bad';
}

/**
 * Check one record's input. $branches = list of branch rows; returns [clean array, list of errors]. Pure.
 * Only fields present in $in are checked and returned (so a partial update leaves the rest alone).
 */
function emp_check(array $in, array $branches): array {
    $out = []; $err = [];
    foreach (EMP_FIELDS as $k => [$label, $kind]) {
        if (!array_key_exists($k, $in)) continue;
        $v = trim((string)$in[$k]);
        switch ($kind) {
            case 'code': if ($e = emp_code_ok($v)) $err[] = $e; else $out[$k] = $v; break;
            case 'code_opt': if ($v !== '' && ($e = emp_code_ok($v))) $err[] = $label . ': ' . $e; else $out[$k] = $v; break;
            case 'date': $d = emp_date($v); if ($d === 'bad') $err[] = $label . ': not a date.'; else $out[$k] = $d; break;
            case 'email': if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $err[] = $label . ': not an email address.'; else $out[$k] = strtolower($v); break;
            case 'phone': if ($v !== '' && !preg_match('/^\+?[0-9 ()-]{6,20}$/', $v)) $err[] = $label . ': digits only.'; else $out[$k] = $v; break;
            case 'gender': if ($v !== '' && !in_array(ucfirst(strtolower($v)), EMP_GENDERS, true)) $err[] = 'Gender: pick from the list.'; else $out[$k] = $v === '' ? '' : ucfirst(strtolower($v)); break;
            case 'exit': if ($v !== '' && !in_array($v, EMP_EXIT_TYPES, true)) $err[] = 'Exit type: pick from the list.'; else $out[$k] = $v; break;
            case 'branch':
                if ($v === '') { $out[$k] = null; break; }
                $hit = null;
                foreach ($branches as $b) if ((string)$b['id'] === $v || branch_label($v, $branches) === $b['name']) $hit = (int)$b['id'];
                if ($hit === null) $err[] = 'Branch "' . $v . '" is not on the branch list.'; else $out[$k] = $hit;
                break;
            default: if (mb_strlen($v) > 300) $err[] = $label . ' is too long.'; else $out[$k] = $v;
        }
    }
    if (array_key_exists('name', $out) && $out['name'] === '') $err[] = 'Full name is required.';
    $doj = $out['doj'] ?? $in['_doj'] ?? null;
    if (!empty($out['last_day']) && $doj && $out['last_day'] < $doj) $err[] = 'Last working day is before the joining date.';
    if (!empty($out['resign_date']) && !empty($out['last_day']) && $out['last_day'] < $out['resign_date']) $err[] = 'Last working day is before the resignation date.';
    if (!empty($out['manager_code']) && isset($out['code']) && strcasecmp($out['manager_code'], $out['code']) === 0) $err[] = 'Nobody reports to themselves.';
    return [$out, $err];
}

/** Field-by-field differences, for the log. Pure. */
function emp_diff(array $old, array $new): array {
    $d = [];
    foreach ($new as $k => $v) {
        $o = (string)($old[$k] ?? '');
        if ($o !== (string)($v ?? '')) $d[$k] = [$o, (string)($v ?? '')];
    }
    return $d;
}

/**
 * Plan an import. $rows = list of assoc rows (headers already mapped to field keys); $existing = code => record.
 * Returns list of [status, row, errors, existing?, changes]. Pure.
 */
function emp_import_plan(array $rows, array $existing, array $branches): array {
    $plan = []; $seen = [];
    $byName = [];
    foreach ($existing as $e) $byName[] = $e;
    foreach ($rows as $i => $r) {
        [$clean, $err] = emp_check($r, $branches);
        $code = strtoupper((string)($clean['code'] ?? ''));
        if (!$err && isset($seen[$code])) $err[] = 'Code appears twice in the file (row ' . ($seen[$code] + 2) . ').';
        if ($code !== '') $seen[$code] ??= $i;
        if ($err) { $plan[] = ['Error', $r, $err, null, []]; continue; }
        $old = $existing[$code] ?? null;
        if ($old) {
            if (!emp_same_person((string)$old['name'], (string)$clean['name'])) {
                $plan[] = ['Possible duplicate', $clean, ['Code ' . $clean['code'] . ' belongs to ' . $old['name'] . ' here.'], $old, []];
                continue;
            }
            $ch = emp_diff($old, array_filter($clean, fn($v) => $v !== '' && $v !== null));
            unset($ch['code']);
            $plan[] = [$ch ? 'Update' : 'Same', $clean, [], $old, $ch];
            continue;
        }
        $twin = null;
        foreach ($byName as $e) if (emp_same_person((string)$e['name'], (string)$clean['name']) && strcasecmp((string)$e['name'], (string)$clean['name']) === 0) $twin = $e;
        $plan[] = $twin ? ['Possible duplicate', $clean, ['Same name as ' . $twin['code'] . ' — new code or same person?'], $twin, []]
                        : ['New', $clean, [], null, []];
    }
    return $plan;
}

/** Map CSV headers to field keys by their titles. Pure. */
function emp_header_map(array $headers): array {
    $alias = ['code' => ['code', 'employee code', 'emp code', 'employee no', 'emp no', 'id'], 'name' => ['name', 'full name', 'employee name'],
        'department' => ['department', 'dept'], 'designation' => ['designation', 'title', 'job title', 'position'], 'grade' => ['grade', 'level'],
        'category' => ['category', 'type'], 'branch_id' => ['branch', 'location', 'office'], 'manager_code' => ['reports to', 'manager code', 'manager'],
        'doj' => ['doj', 'date of joining', 'joining date', 'joined'], 'confirm_date' => ['confirmation date', 'confirmed on'],
        'work_email' => ['work email', 'official email', 'email'], 'work_phone' => ['work phone', 'office mobile', 'phone'],
        'gender' => ['gender', 'sex'], 'dob' => ['dob', 'date of birth', 'birth date'], 'last_day' => ['last working day', 'lwd', 'date of leaving', 'dol'],
        'resign_date' => ['resignation date'], 'exit_type' => ['exit type'], 'exit_reason' => ['exit reason', 'reason for leaving']];
    $map = [];
    foreach ($headers as $i => $h) {
        $k = strtolower(trim(preg_replace('/\s+/', ' ', (string)$h)));
        foreach ($alias as $field => $names) if (in_array($k, $names, true) && !in_array($field, $map, true)) { $map[$i] = $field; break; }
    }
    return $map;
}

/** A CSV cell that could start a spreadsheet formula gets a leading quote. Pure. */
function emp_csv_cell(string $v): string { return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; }

/* ---------------- database ---------------- */

function emp_can_edit(array $u): bool { return has_role($u, ...EMP_EDIT_ROLES); }

/** Branch ids this login is limited to, or null for every branch. */
function emp_scope(array $u): ?array {
    if (is_superadmin($u) || has_role($u, 'CEO')) return null;
    return $u['branches'] ? $u['branches'] : null;
}

function emp_in_scope(array $u, array $e): bool {
    $s = emp_scope($u);
    return $s === null || in_array((int)($e['branch_id'] ?? 0), $s, true);
}

function emp_by_code(PDO $db, string $code): ?array {
    $s = $db->prepare('SELECT * FROM employee WHERE code = ?');
    $s->execute([$code]);
    return $s->fetch() ?: null;
}

/** @return array<string,array> code(upper) => record */
function emp_all(PDO $db): array {
    $out = [];
    foreach ($db->query('SELECT * FROM employee') as $r) $out[strtoupper($r['code'])] = $r;
    return $out;
}

/** People on the roll today (active + on notice), in scope — for every picker in every module. */
function emp_on_roll(PDO $db, array $u, ?string $today = null): array {
    $today ??= date('Y-m-d');
    $out = [];
    foreach ($db->query('SELECT * FROM employee WHERE active = 1 ORDER BY name') as $r)
        if (emp_status($r, $today) !== 'left' && emp_in_scope($u, $r)) $out[] = $r;
    return $out;
}

/** Write a record (new when $id is null). Returns [id, ?error]. Logs every change. */
function emp_save(PDO $db, array $u, ?int $id, array $clean, string $kind = 'edit', string $note = ''): array {
    if (!emp_can_edit($u)) return [0, 'You can view the employee master but not change it.'];
    $old = null;
    if ($id) {
        $s = $db->prepare('SELECT * FROM employee WHERE id = ?'); $s->execute([$id]);
        $old = $s->fetch() ?: null;
        if (!$old) return [0, 'Record not found.'];
        if (!emp_in_scope($u, $old)) return [0, 'This person is outside your branches.'];
        unset($clean['code']);   // codes change only through emp_recode()
    } else {
        if (empty($clean['code']) || empty($clean['name'])) return [0, 'Code and name are required.'];
        if (emp_by_code($db, $clean['code'])) return [0, 'Code ' . $clean['code'] . ' is already used.'];
    }
    if (isset($clean['branch_id']) && !emp_in_scope($u, ['branch_id' => $clean['branch_id']])) return [0, 'That branch is outside your scope.'];
    if (!data_allowed($u, 'personal')) unset($clean['dob'], $clean['gender']);
    $diff = emp_diff($old ?? [], $clean);
    if ($id && !$diff) return [$id, null];
    $db->beginTransaction();
    try {
        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($clean)));
            $db->prepare("UPDATE employee SET $sets WHERE id = ?")->execute([...array_values($clean), $id]);
        } else {
            $cols = array_keys($clean);
            $db->prepare('INSERT INTO employee (' . implode(', ', array_map(fn($k) => "`$k`", $cols)) . ', created_by) VALUES (' . rtrim(str_repeat('?, ', count($cols)), ', ') . ', ?)')
               ->execute([...array_values($clean), (int)$u['id']]);
            $id = (int)$db->lastInsertId();
            $kind = $kind === 'edit' ? 'add' : $kind;
        }
        $log = $db->prepare('INSERT INTO employee_log (emp_id, field, old_value, new_value, kind, note, by_id) VALUES (?,?,?,?,?,?,?)');
        foreach ($diff as $f => [$o, $n]) {
            $personal = (EMP_FIELDS[$f][2] ?? '') === 'personal';
            $log->execute([$id, $f, $personal && $o !== '' ? 'changed' : mb_substr($o, 0, 400), $personal ? 'set' : mb_substr($n, 0, 400), $kind, $note, (int)$u['id']]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); return [0, 'Could not save: ' . $e->getMessage()]; }
    audit((int)$u['id'], 'emp_' . $kind, '#' . $id . ' ' . implode(',', array_keys($diff)));
    return [$id, null];
}

/** Change a code: the record, manager links and linked login follow. */
function emp_recode(PDO $db, array $u, int $id, string $new, string $reason): ?string {
    if (!emp_can_edit($u)) return 'Not allowed.';
    if ($e = emp_code_ok($new)) return $e;
    if (trim($reason) === '') return 'Give a reason for the code change.';
    $s = $db->prepare('SELECT * FROM employee WHERE id = ?'); $s->execute([$id]);
    $old = $s->fetch();
    if (!$old || !emp_in_scope($u, $old)) return 'Record not found.';
    if (strcasecmp($old['code'], $new) === 0) return 'That is already the code.';
    if (emp_by_code($db, $new)) return 'Code ' . $new . ' is already used.';
    $db->beginTransaction();
    $db->prepare('UPDATE employee SET code = ? WHERE id = ?')->execute([$new, $id]);
    $db->prepare('UPDATE employee SET manager_code = ? WHERE manager_code = ?')->execute([$new, $old['code']]);
    $db->prepare('UPDATE users SET emp_code = ? WHERE emp_code = ?')->execute([$new, $old['code']]);
    $db->prepare('INSERT INTO employee_log (emp_id, field, old_value, new_value, kind, note, by_id) VALUES (?,?,?,?,?,?,?)')
       ->execute([$id, 'code', $old['code'], $new, 'recode', mb_substr($reason, 0, 300), (int)$u['id']]);
    $db->commit();
    audit((int)$u['id'], 'emp_recode', '#' . $id);
    return null;
}

/** Switch a wrong record off (or back on). Never deletes. */
function emp_switch(PDO $db, array $u, int $id, bool $on, string $reason): ?string {
    if (!emp_can_edit($u)) return 'Not allowed.';
    if (!$on && trim($reason) === '') return 'Give a reason.';
    $s = $db->prepare('SELECT * FROM employee WHERE id = ?'); $s->execute([$id]);
    $e = $s->fetch();
    if (!$e || !emp_in_scope($u, $e)) return 'Record not found.';
    $db->prepare('UPDATE employee SET active = ?, off_reason = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? '' : mb_substr($reason, 0, 300), $id]);
    $db->prepare('INSERT INTO employee_log (emp_id, field, old_value, new_value, kind, note, by_id) VALUES (?,?,?,?,?,?,?)')
       ->execute([$id, 'active', $on ? '0' : '1', $on ? '1' : '0', $on ? 'on' : 'off', mb_substr($reason, 0, 300), (int)$u['id']]);
    audit((int)$u['id'], 'emp_' . ($on ? 'on' : 'off'), '#' . $id);
    return null;
}

/** Read a CSV upload into mapped rows. Returns [rows, unmatched headers]. */
function emp_csv_rows(string $path): array {
    $fh = fopen($path, 'r');
    if (!$fh) return [[], []];
    $first = fgets($fh);
    $sep = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ',';
    rewind($fh);
    $head = fgetcsv($fh, 0, $sep, '"', '\\') ?: [];
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($head[0] ?? ''));
    $map = emp_header_map($head);
    $rows = []; $n = 0;
    while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false && $n++ < EMP_IMPORT_MAX) {
        if (count(array_filter($r, fn($c) => trim((string)$c) !== '')) === 0) continue;
        $row = [];
        foreach ($map as $i => $f) $row[$f] = (string)($r[$i] ?? '');
        $rows[] = $row;
    }
    fclose($fh);
    $unmatched = array_values(array_diff_key($head, $map));
    return [$rows, array_filter($unmatched, fn($h) => trim((string)$h) !== '')];
}
