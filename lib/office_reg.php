<?php
/**
 * Office registers — what each branch holds that is counted rather than tagged (furniture, fans, fire extinguishers,
 * a shelf of files …) and the office library (books, copies, lending to people on the roll, history).
 * Tagged things given to a person (laptops, phones, SIMs) stay in the Asset register.
 *  - Branch scope: a login tied to branches sees and changes only those branches' lines.
 *  - Nothing is deleted: a line is switched off with a reason; lending history is kept; office_reg_log is append-only
 *    and holds field names, never values.
 *  - Imports (CSV / .xlsx, columns by their titles): Check writes nothing; a line already on the register (same branch +
 *    name / title) is never duplicated or overwritten.
 */
declare(strict_types=1);
require_once __DIR__ . '/employees.php';
require_once __DIR__ . '/table_read.php';

const OR_PROP_COLS = ['no' => ['no', 'sr', 'srno', 'sno', 'number'], 'name' => ['name', 'item', 'itemname', 'property', 'description'],
    'labels' => ['labels', 'label', 'tag', 'tags', 'make', 'brand'], 'qty' => ['quantity', 'qty', 'count', 'nos'],
    'place' => ['place', 'location', 'where', 'withwhom', 'room', 'area'], 'notes' => ['notes', 'note', 'remarks', 'remark'],
    'branch' => ['branch', 'office', 'site']];
const OR_BOOK_COLS = ['no' => ['no', 'sr', 'srno', 'sno', 'bookno'], 'title' => ['title', 'bookname', 'book', 'name'],
    'type' => ['type', 'category', 'genre', 'subject'], 'author' => ['author', 'authorname', 'writer'], 'language' => ['language', 'lang', 'langague'],
    'copies' => ['copies', 'copy', 'qty', 'quantity'], 'branch' => ['branch', 'office', 'site']];
const OR_LOAN_DAYS = 30;

/* ---------- pure ---------- */

function or_line(string $s, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? ''), 0, $max); }
/** Pure: a quantity / copies figure, at least 1 and at most 100000; "1 copy", "2 nos" read as numbers. */
function or_qty($v): int { $n = (int)preg_replace('/\D/', '', (string)$v); return max(1, min(100000, $n ?: 1)); }

/** Pure: clean a property line. Returns [values, ?error]. */
function or_prop_clean(array $in, array $branchIds, bool $needBranch = false): array {
    $o = ['branch_id' => (int)($in['branch_id'] ?? 0) ?: null, 'no' => or_line((string)($in['no'] ?? ''), 10), 'name' => or_line((string)($in['name'] ?? ''), 160),
        'labels' => or_line((string)($in['labels'] ?? ''), 120), 'qty' => or_qty($in['qty'] ?? 1), 'place' => or_line((string)($in['place'] ?? ''), 160),
        'notes' => or_line((string)($in['notes'] ?? ''), 500)];
    if ($o['name'] === '') return [$o, 'Name the item.'];
    if ($o['branch_id'] === null && $needBranch) return [$o, 'Pick the branch.'];
    if ($o['branch_id'] !== null && !in_array($o['branch_id'], $branchIds, true)) return [$o, 'Pick one of your branches.'];
    return [$o, null];
}
function or_book_clean(array $in, array $branchIds, bool $needBranch = false): array {
    $o = ['branch_id' => (int)($in['branch_id'] ?? 0) ?: null, 'no' => or_line((string)($in['no'] ?? ''), 10), 'title' => or_line((string)($in['title'] ?? ''), 160),
        'type' => or_line((string)($in['type'] ?? ''), 40), 'author' => or_line((string)($in['author'] ?? ''), 120), 'language' => or_line((string)($in['language'] ?? ''), 40),
        'copies' => or_qty($in['copies'] ?? 1)];
    if ($o['title'] === '') return [$o, 'Name the book.'];
    if ($o['branch_id'] === null && $needBranch) return [$o, 'Pick the branch.'];
    if ($o['branch_id'] !== null && !in_array($o['branch_id'], $branchIds, true)) return [$o, 'Pick one of your branches.'];
    return [$o, null];
}
/** Pure: copies still on the shelf. */
function or_free(int $copies, array $issues): int { return max(0, $copies - count(array_filter($issues, fn($i) => empty($i['returned'])))); }
/** Pure: loans past their due date on $today. */
function or_overdue(array $issues, string $today): array { return array_values(array_filter($issues, fn($i) => empty($i['returned']) && !empty($i['due']) && $i['due'] < $today)); }

/**
 * Pure: plan an import. $kind 'prop' | 'book'; $have = list of "branchId|lowercase name" already on the register;
 * $branchOf = callable(string) → ?int branch id (from the file's branch column) and $default branch.
 * @return array{rows:array, new:int, same:int, bad:int}
 */
function or_import_plan(string $kind, array $rows, array $have, callable $branchOf, ?int $default, array $branchIds, bool $needBranch = false): array {
    $cols = $kind === 'book' ? OR_BOOK_COLS : OR_PROP_COLS; $key = $kind === 'book' ? 'title' : 'name';
    [$hi, $m] = tbl_map($rows, $cols, 1);
    $out = ['rows' => [], 'new' => 0, 'same' => 0, 'bad' => 0];
    if ($hi < 0 || !isset($m[$key])) return $out + ['error' => 'No "' . ($kind === 'book' ? 'Title' : 'Name') . '" column found.'];
    $seen = array_fill_keys($have, true); $lastType = '';
    foreach (array_slice($rows, $hi + 1) as $n => $r) {
        $in = []; foreach ($m as $f => $j) $in[$f] = trim((string)($r[$j] ?? ''));
        if (($in[$key] ?? '') === '') continue;
        if ($kind === 'book') { if (($in['type'] ?? '') === '') $in['type'] = $lastType; else $lastType = $in['type']; }   // a type written once for a group
        $b = ($in['branch'] ?? '') !== '' ? $branchOf($in['branch']) : $default;
        $in['branch_id'] = $b;
        [$v, $err] = $kind === 'book' ? or_book_clean($in, $branchIds, $needBranch) : or_prop_clean($in, $branchIds, $needBranch);
        $k = ($v['branch_id'] ?? 0) . '|' . mb_strtolower($v[$key]);
        $st = $err ? 'bad' : (isset($seen[$k]) ? 'same' : 'new');
        if ($st === 'new') $seen[$k] = true;
        $out[$st]++;
        $out['rows'][] = ['line' => $hi + 2 + $n, 'status' => $st, 'why' => (string)$err, 'v' => $v];
    }
    return $out;
}

/* ---------- database ---------- */

function or_can(array $u): bool { return module_allowed($u, 'officereg'); }
function or_branch_ids(array $u): array { $s = emp_scope($u); return $s ?? array_map(fn($b) => (int)$b['id'], branches(false)); }
function or_in_scope(array $u, array $r): bool { $s = emp_scope($u); return $s === null || in_array((int)($r['branch_id'] ?? 0), $s, true); }

function or_log(PDO $db, string $what, int $id, string $act, array $fields, int $by): void {
    $db->prepare('INSERT INTO office_reg_log (what, ref_id, act, fields, by_user) VALUES (?,?,?,?,?)')->execute([$what, $id, $act, mb_substr(implode(',', $fields), 0, 250), $by]);
}

function or_props(PDO $db, array $u, bool $off = false): array {
    $rows = $db->query('SELECT * FROM office_prop WHERE active = ' . ($off ? 0 : 1) . ' ORDER BY branch_id, CAST(no AS UNSIGNED), name')->fetchAll();
    return array_values(array_filter($rows, fn($r) => or_in_scope($u, $r)));
}
function or_books(PDO $db, array $u, bool $off = false): array {
    $b = array_values(array_filter($db->query('SELECT * FROM office_book WHERE active = ' . ($off ? 0 : 1) . ' ORDER BY branch_id, type, title')->fetchAll(), fn($r) => or_in_scope($u, $r)));
    if (!$b) return [];
    $iss = [];
    foreach ($db->query('SELECT * FROM office_book_issue WHERE book_id IN (' . implode(',', array_map(fn($x) => (int)$x['id'], $b)) . ') ORDER BY issued, id') as $i) $iss[(int)$i['book_id']][] = $i;
    foreach ($b as &$x) { $x['issues'] = $iss[(int)$x['id']] ?? []; $x['free'] = or_free((int)$x['copies'], $x['issues']); }
    return $b;
}
function or_get(PDO $db, array $u, string $what, int $id): ?array {
    $s = $db->prepare('SELECT * FROM ' . ($what === 'book' ? 'office_book' : 'office_prop') . ' WHERE id = ?'); $s->execute([$id]);
    $r = $s->fetch();
    return $r && or_in_scope($u, $r) ? $r : null;
}

function or_save(PDO $db, array $u, string $what, array $in, int $id = 0): int {
    $book = $what === 'book';
    $need = emp_scope($u) !== null;   // a branch-tied login keeps only lines of its branches
    [$v, $err] = $book ? or_book_clean($in, or_branch_ids($u), $need) : or_prop_clean($in, or_branch_ids($u), $need);
    if ($err) throw new InvalidArgumentException($err);
    $t = $book ? 'office_book' : 'office_prop'; $cols = array_keys($v); $by = (int)$u['id'];
    if ($id) {
        $old = or_get($db, $u, $what, $id); if (!$old) throw new RuntimeException('Not found.');
        if ($book) { $out = count(array_filter(or_books($db, $u), fn($b) => (int)$b['id'] === $id && (int)$b['copies'] - $b['free'] > $v['copies'])); if ($out) throw new InvalidArgumentException('More copies are lent out than that.'); }
        $ch = array_values(array_filter($cols, fn($c) => (string)$old[$c] !== (string)$v[$c]));
        if (!$ch) return $id;
        $db->prepare("UPDATE $t SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_by = ? WHERE id = ?')->execute([...array_values($v), $by, $id]);
        or_log($db, $what, $id, 'edit', $ch, $by);
    } else {
        $db->prepare("INSERT INTO $t (" . implode(', ', $cols) . ', updated_by) VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')->execute([...array_values($v), $by]);
        $id = (int)$db->lastInsertId();
        or_log($db, $what, $id, 'add', [], $by);
    }
    return $id;
}
function or_switch(PDO $db, array $u, string $what, int $id, bool $on, string $reason): void {
    $r = or_get($db, $u, $what, $id); if (!$r) throw new RuntimeException('Not found.');
    $reason = or_line($reason, 200);
    if (!$on && $reason === '') throw new InvalidArgumentException('Say why it is taken off the register.');
    if (!$on && $what === 'book') { $b = array_values(array_filter(or_books($db, $u), fn($x) => (int)$x['id'] === $id))[0] ?? null; if ($b && $b['free'] < (int)$b['copies']) throw new InvalidArgumentException('Take the lent copies back first.'); }
    $db->prepare('UPDATE ' . ($what === 'book' ? 'office_book' : 'office_prop') . ' SET active = ?, off_reason = ?, updated_by = ? WHERE id = ?')->execute([$on ? 1 : 0, $on ? '' : $reason, (int)$u['id'], $id]);
    or_log($db, $what, $id, $on ? 'on' : 'off', [], (int)$u['id']);
}

/** Lend a copy to someone on the roll (by code) or to a named person. */
function or_lend(PDO $db, array $u, int $bookId, string $code, string $person, string $date, ?string $due = null): int {
    $b = array_values(array_filter(or_books($db, $u), fn($x) => (int)$x['id'] === $bookId))[0] ?? null;
    if (!$b) throw new RuntimeException('Not found.');
    if ($b['free'] < 1) throw new InvalidArgumentException('Every copy is lent out — take one back first.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) throw new InvalidArgumentException('Choose the date it was given (not a later day).');
    $code = strtoupper(trim($code)); $name = or_line($person, 120);
    if ($code !== '') { $e = emp_by_code($db, $code); if (!$e || !emp_in_scope($u, $e)) throw new InvalidArgumentException('Pick someone on the roll.'); $name = (string)$e['name']; }
    if ($name === '') throw new InvalidArgumentException('Who is taking the book?');
    $due = $due && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) && $due >= $date ? $due : date('Y-m-d', strtotime($date . ' +' . OR_LOAN_DAYS . ' day'));
    $db->prepare('INSERT INTO office_book_issue (book_id, emp_code, person, issued, due, created_by) VALUES (?,?,?,?,?,?)')->execute([$bookId, $code ?: null, $name, $date, $due, (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    or_log($db, 'book', $bookId, 'lend', [], (int)$u['id']);
    return $id;
}
function or_take_back(PDO $db, array $u, int $issueId, string $date): void {
    $s = $db->prepare('SELECT i.*, b.branch_id FROM office_book_issue i JOIN office_book b ON b.id = i.book_id WHERE i.id = ? AND i.returned IS NULL'); $s->execute([$issueId]);
    $i = $s->fetch(); if (!$i || !or_in_scope($u, $i)) throw new RuntimeException('Not found.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $i['issued'] || $date > date('Y-m-d')) throw new InvalidArgumentException('Choose the day it came back.');
    $db->prepare('UPDATE office_book_issue SET returned = ?, returned_by = ? WHERE id = ? AND returned IS NULL')->execute([$date, (int)$u['id'], $issueId]);
    or_log($db, 'book', (int)$i['book_id'], 'return', [], (int)$u['id']);
}

/** Save an import plan's new lines (already checked); returns how many were added. */
function or_import_save(PDO $db, array $u, string $kind, array $plan): int {
    $n = 0;
    foreach ($plan['rows'] as $r) if ($r['status'] === 'new') { or_save($db, $u, $kind, $r['v'] + ['branch_id' => $r['v']['branch_id'] ?? 0]); $n++; }
    audit((int)$u['id'], 'office_reg_import', $kind . ' · ' . $n);
    return $n;
}
function or_have(PDO $db, string $kind): array {
    $col = $kind === 'book' ? 'title' : 'name';
    return array_map(fn($r) => ((int)$r['branch_id']) . '|' . mb_strtolower((string)$r['n']), $db->query("SELECT branch_id, $col AS n FROM " . ($kind === 'book' ? 'office_book' : 'office_prop'))->fetchAll());
}
