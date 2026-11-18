<?php
/**
 * Asset register — ported from the reference build as rules.
 *  - Categories (name, tag code, kind: which details are asked) are kept by the organisation; switched off, never deleted.
 *  - Each asset gets the next tag <prefix>-<code>-NNN (prefix a setting). Phones keep IMEI 1 / 2, SIM cards their number
 *    and operator, cards only their last 4 digits — never a full card number.
 *  - Allot to a person on the roll; one open allotment per asset; return with date, condition and note.
 *  - Nobody deletes an asset: an editor proposes it for scrap with a reason; only a confirmer scraps it or keeps it;
 *    a scrapped asset is never brought back. Lost / stolen come from a resolved issue.
 *  - People see only their own items on "My assets", confirm they hold them, and report an issue; a yearly audit asks
 *    everyone to confirm again.
 *  - Possible duplicates: the same serial, IMEI (either slot) or SIM number on another asset.
 *  - Every change is a line in asset_log (before → after, who, when), append-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/kyc.php';

const AS_STATUS = ['stock' => 'In stock', 'reserved' => 'Reserved for a joiner', 'use' => 'In use', 'repair' => 'Under repair',
    'lost' => 'Lost', 'stolen' => 'Stolen', 'proposed' => 'Proposed for scrap', 'scrapped' => 'Scrapped', 'sold' => 'Sold to the employee'];
const AS_KINDS = ['computer' => 'Computer', 'phone' => 'Phone', 'sim' => 'SIM card', 'card' => 'Card', 'general' => 'General'];
const AS_EDITABLE_STATUS = ['stock', 'reserved', 'repair', 'sold'];      // what an editor may set by hand
const AS_CONDITIONS = ['good' => 'Good', 'damaged' => 'Damaged', 'missing' => 'Parts missing'];
const AS_ISSUE_TYPES = ['Damaged', 'Not working', 'Lost', 'Stolen', 'Accessory missing', 'Needs replacement', 'Other'];
const AS_OUTCOMES = ['repaired' => 'Repaired', 'replaced' => 'Replaced', 'lost' => 'Marked lost', 'stolen' => 'Marked stolen', 'none' => 'No action needed'];
const AS_FILE_MAX = 10 * 1024 * 1024;
const AS_DEFAULTS = ['prefix' => 'AST', 'edit_roles' => ['SUPERADMIN', 'HRMANAGER', 'ITADMIN'], 'confirm_roles' => ['SUPERADMIN', 'HRMANAGER'], 'warranty_days' => 30];
const AS_FIELDS = ['cat_id', 'make', 'model', 'serial', 'imei1', 'imei2', 'sim_no', 'operator', 'card_last4', 'bought_on', 'vendor', 'invoice_no', 'cost', 'warranty_till', 'branch_id', 'status', 'notes'];

/* ---------------- settings and rights ---------------- */

function as_settings(): array {
    $s = module_states()['assets']['settings'] ?? [];
    return array_replace(AS_DEFAULTS, array_intersect_key($s, AS_DEFAULTS));
}

function as_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    $p = strtoupper(trim((string)($in['prefix'] ?? '')));
    if (!preg_match('/^[A-Z0-9]{2,8}$/', $p)) $e[] = 'Tag prefix: 2–8 letters or digits.'; else $o['prefix'] = $p;
    foreach (['edit_roles', 'confirm_roles'] as $k) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = 'Pick at least one role for ' . ($k === 'edit_roles' ? 'editing' : 'confirming scrap') . '.';
    }
    $w = filter_var($in['warranty_days'] ?? null, FILTER_VALIDATE_INT);
    if ($w === false || $w < 0 || $w > 365) $e[] = 'Warranty warning: 0–365 days.'; else $o['warranty_days'] = $w;
    return [$o, $e];
}

function as_can_edit(array $u, array $set): bool { return has_role($u, ...$set['edit_roles']); }
function as_can_confirm(array $u, array $set): bool { return has_role($u, ...$set['confirm_roles']); }

/* ---------------- pure rules ---------------- */

/** Next tag for a category: PREFIX-CODE-001 after the highest number used with that start. Pure. */
function as_next_tag(string $prefix, string $code, array $existing): string {
    $max = 0; $start = $prefix . '-' . $code . '-';
    foreach ($existing as $t) if (str_starts_with((string)$t, $start) && preg_match('/^\d+$/', substr((string)$t, strlen($start)))) $max = max($max, (int)substr((string)$t, strlen($start)));
    return $start . sprintf('%03d', $max + 1);
}

/** Clean an asset form for a category kind. Pure. Returns the column values (only the fields this kind keeps). */
function as_clean(array $in, string $kind, array $branchIds, string $today): array {
    $t = fn(string $k, int $n) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $n);
    $d = function (string $k, string $label) use ($in) {
        $v = trim((string)($in[$k] ?? ''));
        if ($v === '') return null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4))) throw new InvalidArgumentException("$label: pick a date.");
        return $v;
    };
    $o = ['make' => $t('make', 80), 'model' => $t('model', 80), 'serial' => strtoupper(preg_replace('/\s+/', '', $t('serial', 80))), 'imei1' => '', 'imei2' => '', 'sim_no' => '', 'operator' => '', 'card_last4' => '',
          'bought_on' => $d('bought_on', 'Bought on'), 'vendor' => $t('vendor', 120), 'invoice_no' => $t('invoice_no', 60), 'cost' => null, 'warranty_till' => $d('warranty_till', 'Warranty till'),
          'branch_id' => in_array((int)($in['branch_id'] ?? 0), $branchIds, true) ? (int)$in['branch_id'] : null, 'notes' => $t('notes', 600)];
    if ($o['bought_on'] !== null && $o['bought_on'] > $today) throw new InvalidArgumentException('Bought on cannot be in the future.');
    $c = trim((string)($in['cost'] ?? ''));
    if ($c !== '') { if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', str_replace(',', '', $c))) throw new InvalidArgumentException('Cost: a number.'); $o['cost'] = (float)str_replace(',', '', $c); }
    if ($kind === 'phone') foreach (['imei1', 'imei2'] as $k) {
        $v = preg_replace('/\D/', '', (string)($in[$k] ?? ''));
        if ($v !== '' && strlen($v) !== 15) throw new InvalidArgumentException('IMEI: 15 digits.');
        $o[$k] = $v;
    }
    if ($kind === 'sim' || $kind === 'phone') { $o['sim_no'] = preg_replace('/[^\d+]/', '', $t('sim_no', 30)); $o['operator'] = $t('operator', 40); }
    if ($kind === 'card') {
        $v = preg_replace('/\D/', '', (string)($in['card_last4'] ?? ''));
        if ($v !== '' && strlen($v) !== 4) throw new InvalidArgumentException('Card: the last 4 digits only.');
        $o['card_last4'] = $v;
    }
    return $o;
}

/** Status changes allowed by hand. $from → $to; $confirm = may confirm scrap. Null = fine, else the reason. Pure. */
function as_status_problem(string $from, string $to, bool $confirm, bool $out, string $reason = ''): ?string {
    if ($from === $to) return null;
    if ($from === 'scrapped') return 'A scrapped asset stays scrapped.';
    if ($to === 'use' || $from === 'use' && $out) return 'In use follows the allotment — give or take it back instead.';
    if ($to === 'proposed') return trim($reason) === '' ? 'Give the reason for scrapping.' : ($out ? 'Take it back before proposing it for scrap.' : null);
    if ($to === 'scrapped') return !$confirm ? 'Only a confirmer scraps an asset.' : ($from !== 'proposed' ? 'Propose it for scrap first.' : null);
    if (in_array($to, ['lost', 'stolen'], true)) return 'Lost and stolen come from a reported issue.';
    if ($from === 'proposed' && !$confirm) return 'Only a confirmer keeps an asset proposed for scrap.';
    return in_array($to, AS_EDITABLE_STATUS, true) ? null : 'That status is not set by hand.';
}

/** Warranty state on $today: '', 'soon', 'over'. Pure. */
function as_warranty(?string $till, string $today, int $days): string {
    if (!$till) return '';
    if ($till < $today) return 'over';
    return $till <= date('Y-m-d', strtotime("$today +$days day")) ? 'soon' : '';
}

/** Does an allotment need the person's confirmation: never confirmed, or not since the open audit began. Pure. */
function as_needs_ack(array $allot, ?array $audit): bool {
    if (!empty($allot['returned_on'])) return false;
    if (empty($allot['ack_at'])) return true;
    return $audit && empty($audit['closed_at']) && substr((string)$allot['ack_at'], 0, 10) < (string)$audit['started_on'];
}

/** Other assets with the same serial, IMEI (either slot) or SIM number. $others = id => row. Pure. */
function as_dups(array $a, array $others): array {
    $o = [];
    $keys = array_filter(['serial' => (string)$a['serial'], 'sim_no' => (string)$a['sim_no']]);
    $imeis = array_filter([(string)$a['imei1'], (string)$a['imei2']]);
    foreach ($others as $id => $b) {
        if ((int)$id === (int)($a['id'] ?? 0) || ($b['status'] ?? '') === 'scrapped') continue;
        foreach ($keys as $k => $v) if ($v !== '' && strcasecmp($v, (string)$b[$k]) === 0) $o[$id] = $b['tag'];
        foreach ($imeis as $v) if (in_array($v, [(string)$b['imei1'], (string)$b['imei2']], true)) $o[$id] = $b['tag'];
    }
    return $o;
}

/** Fields that changed between two rows (for the log). Pure. */
function as_diff(array $before, array $after): array {
    $b = []; $a = [];
    foreach ($after as $k => $v) if (in_array($k, AS_FIELDS, true) && (string)($before[$k] ?? '') !== (string)$v) { $b[$k] = $before[$k] ?? null; $a[$k] = $v; }
    return [$b, $a];
}

/* ---------------- database ---------------- */

function as_log(PDO $db, int $assetId, string $what, ?array $before, ?array $after, ?int $by): void {
    $db->prepare('INSERT INTO asset_log (asset_id, what, before_json, after_json, by_user) VALUES (?,?,?,?,?)')
       ->execute([$assetId, $what, $before ? json_encode($before) : null, $after ? json_encode($after) : null, $by]);
}

function as_cats(PDO $db, bool $all = false): array {
    return array_column($db->query('SELECT * FROM asset_cat' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort, name')->fetchAll(), null, 'id');
}

function as_cat_save(PDO $db, array $u, int $id, array $in): void {
    if (!as_can_confirm($u, as_settings())) throw new RuntimeException('Only a confirmer changes categories.');
    $name = mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 60);
    $code = strtoupper(trim((string)($in['code'] ?? '')));
    $kind = (string)($in['kind'] ?? 'general');
    if ($name === '' || !preg_match('/^[A-Z0-9]{2,6}$/', $code) || !isset(AS_KINDS[$kind])) throw new InvalidArgumentException('Give a name, a tag code of 2–6 letters or digits, and the kind.');
    $off = !empty($in['off']);
    if ($id && $off) {
        $q = $db->prepare("SELECT COUNT(*) FROM asset WHERE cat_id = ? AND status <> 'scrapped'"); $q->execute([$id]);
        if ($q->fetchColumn()) throw new RuntimeException('This category is in use — it stays on.');
    }
    if ($id) $db->prepare('UPDATE asset_cat SET name = ?, code = ?, kind = ?, active = ? WHERE id = ?')->execute([$name, $code, $kind, $off ? 0 : 1, $id]);
    else $db->prepare('INSERT INTO asset_cat (name, code, kind, sort) VALUES (?,?,?,50)')->execute([$name, $code, $kind]);
    audit((int)$u['id'], 'asset_cat', $id ? '#' . $id : 'new');
}

function as_get(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT a.*, c.name AS cat, c.kind FROM asset a JOIN asset_cat c ON c.id = a.cat_id WHERE a.id = ?'); $q->execute([$id]);
    return $q->fetch() ?: null;
}

function as_open_allot(PDO $db, int $assetId): ?array {
    $q = $db->prepare('SELECT * FROM asset_allot WHERE open_key = ?'); $q->execute([$assetId]);
    return $q->fetch() ?: null;
}

function as_all(PDO $db, array $u): array {
    $rows = $db->query("SELECT a.*, c.name AS cat, c.kind, al.emp_code AS holder, al.id AS allot_id, e.name AS holder_name
        FROM asset a JOIN asset_cat c ON c.id = a.cat_id LEFT JOIN asset_allot al ON al.open_key = a.id LEFT JOIN employee e ON e.code = al.emp_code ORDER BY a.tag")->fetchAll();
    return array_values(array_filter($rows, fn($a) => !$a['branch_id'] || emp_in_scope($u, ['branch_id' => $a['branch_id']])));
}

/** Add or change an asset. Status changes go through as_status_problem(); "use" follows allotments. */
function as_save(PDO $db, array $u, int $id, array $in, ?string $today = null): int {
    $set = as_settings(); $today ??= date('Y-m-d');
    if (!as_can_edit($u, $set)) throw new RuntimeException('Not allowed.');
    $cats = as_cats($db, true); $catId = (int)($in['cat_id'] ?? 0);
    if (!isset($cats[$catId]) || (!$id && !(int)$cats[$catId]['active'])) throw new InvalidArgumentException('Pick the category.');
    $f = ['cat_id' => $catId] + as_clean($in, $cats[$catId]['kind'], array_map('intval', array_column(branches(false), 'id')), $today);
    $uid = (int)$u['id'];
    if ($id) {
        $old = as_get($db, $id); if (!$old) throw new RuntimeException('Not found.');
        $to = (string)($in['status'] ?? $old['status']);
        if (!isset(AS_STATUS[$to])) throw new InvalidArgumentException('Status?');
        if ($p = as_status_problem($old['status'], $to, as_can_confirm($u, $set), (bool)as_open_allot($db, $id), (string)($in['reason'] ?? ''))) throw new RuntimeException($p);
        if ($old['status'] === 'scrapped') throw new RuntimeException('A scrapped asset stays as it is.');
        $f['status'] = $to;
        [$b, $a] = as_diff($old, $f);
        if (!$a) return $id;
        $cols = array_keys($f);
        $db->prepare('UPDATE asset SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ($to === 'proposed' ? ', scrap_reason = ?' : '') . ' WHERE id = ?')
           ->execute([...array_values($f), ...($to === 'proposed' ? [mb_substr(trim(strip_tags((string)$in['reason'])), 0, 300)] : []), $id]);
        as_log($db, $id, $to !== $old['status'] ? 'status' : 'edit', $b, $a, $uid);
        return $id;
    }
    $f['status'] = in_array($in['status'] ?? 'stock', ['stock', 'reserved', 'repair'], true) ? (string)($in['status'] ?? 'stock') : 'stock';
    $tag = as_next_tag($set['prefix'], $cats[$catId]['code'], $db->query('SELECT tag FROM asset')->fetchAll(PDO::FETCH_COLUMN));
    $cols = array_keys($f);
    $db->prepare('INSERT INTO asset (tag, ' . implode(', ', $cols) . ', created_by) VALUES (?, ' . implode(',', array_fill(0, count($cols), '?')) . ', ?)')->execute([$tag, ...array_values($f), $uid]);
    $id = (int)$db->lastInsertId();
    as_log($db, $id, 'added', null, ['tag' => $tag] + $f, $uid);
    return $id;
}

/** Give an asset to a person on the roll. */
function as_allot(PDO $db, array $u, int $id, string $code, string $on, string $acc = '', string $note = '', ?string $today = null): void {
    $set = as_settings(); $today ??= date('Y-m-d');
    if (!as_can_edit($u, $set)) throw new RuntimeException('Not allowed.');
    $a = as_get($db, $id); if (!$a) throw new RuntimeException('Not found.');
    if (!in_array($a['status'], ['stock', 'reserved'], true)) throw new RuntimeException('Only an asset in stock or reserved is given out.');
    $e = emp_by_code($db, $code);
    if (!$e || !(int)$e['active'] || (!empty($e['last_day']) && $e['last_day'] < $today)) throw new InvalidArgumentException('Pick a person on the roll.');
    if (!emp_in_scope($u, $e)) throw new RuntimeException('Not found.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > $today) throw new InvalidArgumentException('Given on: a date up to today.');
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO asset_allot (asset_id, emp_code, given_on, accessories, given_note, given_by, open_key) VALUES (?,?,?,?,?,?,?)')
           ->execute([$id, $e['code'], $on, mb_substr(trim(strip_tags($acc)), 0, 300), mb_substr(trim(strip_tags($note)), 0, 300), (int)$u['id'], $id]);
        $db->prepare("UPDATE asset SET status = 'use' WHERE id = ?")->execute([$id]);
        as_log($db, $id, 'given', ['status' => $a['status']], ['status' => 'use', 'to' => $e['code'], 'on' => $on], (int)$u['id']);
        $db->commit();
    } catch (Throwable $ex) { $db->rollBack(); throw $ex instanceof PDOException ? new RuntimeException('This asset is already with someone.') : $ex; }
}

/** Take an asset back. */
function as_return(PDO $db, array $u, int $id, string $on, string $cond, string $note = '', ?string $today = null): void {
    $set = as_settings(); $today ??= date('Y-m-d');
    if (!as_can_edit($u, $set)) throw new RuntimeException('Not allowed.');
    $al = as_open_allot($db, $id); if (!$al) throw new RuntimeException('This asset is not with anyone.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > $today || $on < $al['given_on']) throw new InvalidArgumentException('Returned on: between the day it was given and today.');
    if (!isset(AS_CONDITIONS[$cond])) throw new InvalidArgumentException('Condition?');
    $status = $cond === 'good' ? 'stock' : 'repair';
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE asset_allot SET returned_on = ?, return_condition = ?, return_note = ?, received_by = ?, open_key = NULL WHERE id = ?')
           ->execute([$on, $cond, mb_substr(trim(strip_tags($note)), 0, 300), (int)$u['id'], $al['id']]);
        $db->prepare('UPDATE asset SET status = ? WHERE id = ?')->execute([$status, $id]);
        as_log($db, $id, 'returned', ['status' => 'use', 'from' => $al['emp_code']], ['status' => $status, 'on' => $on, 'condition' => $cond], (int)$u['id']);
        $db->commit();
    } catch (Throwable $ex) { $db->rollBack(); throw $ex; }
}

function as_file_add(PDO $db, array $u, int $id, string $bytes, string $name): void {
    if (!as_can_edit($u, as_settings())) throw new RuntimeException('Not allowed.');
    if (strlen($bytes) > AS_FILE_MAX) throw new InvalidArgumentException('The file is over 10 MB.');
    $mime = kyc_doc_mime($bytes); if ($mime === null) throw new InvalidArgumentException('Invoice: PDF, JPEG, PNG or WebP only.');
    $db->prepare('INSERT INTO asset_file (asset_id, name, mime, size, bytes, by_user) VALUES (?,?,?,?,?,?)')->execute([$id, mb_substr(basename($name), 0, 160), $mime, strlen($bytes), $bytes, (int)$u['id']]);
    as_log($db, $id, 'invoice', null, ['file' => mb_substr(basename($name), 0, 160)], (int)$u['id']);
}

function as_audit_open(PDO $db): ?array { return $db->query('SELECT * FROM asset_audit WHERE closed_at IS NULL ORDER BY id DESC LIMIT 1')->fetch() ?: null; }

/** The person's own items: held (with whether they must confirm), returned, and their issues. */
function as_mine(PDO $db, string $code): array {
    if ($code === '') return ['held' => [], 'returned' => [], 'issues' => []];
    $q = $db->prepare('SELECT al.*, a.tag, a.make, a.model, a.serial, a.imei1, a.sim_no, a.card_last4, a.warranty_till, c.name AS cat FROM asset_allot al JOIN asset a ON a.id = al.asset_id JOIN asset_cat c ON c.id = a.cat_id WHERE al.emp_code = ? ORDER BY al.given_on DESC');
    $q->execute([$code]);
    $audit = as_audit_open($db); $held = []; $ret = [];
    foreach ($q->fetchAll() as $r) { $r['needs_ack'] = as_needs_ack($r, $audit); if ($r['returned_on']) $ret[] = $r; else $held[] = $r; }
    $i = $db->prepare('SELECT i.*, a.tag FROM asset_issue i JOIN asset a ON a.id = i.asset_id WHERE i.emp_code = ? ORDER BY i.id DESC'); $i->execute([$code]);
    return ['held' => $held, 'returned' => $ret, 'issues' => $i->fetchAll()];
}

/** The person confirms they hold their own items. Returns how many. */
function as_ack(PDO $db, array $u, array $allotIds): int {
    $code = (string)($u['emp_code'] ?? ''); $n = 0;
    if ($code === '') return 0;
    $q = $db->prepare('UPDATE asset_allot SET ack_at = NOW() WHERE id = ? AND emp_code = ? AND returned_on IS NULL');
    foreach (array_map('intval', $allotIds) as $aid) {
        $q->execute([$aid, $code]);
        if ($q->rowCount()) { $n++; $s = $db->prepare('SELECT asset_id FROM asset_allot WHERE id = ?'); $s->execute([$aid]); as_log($db, (int)$s->fetchColumn(), 'confirmed', null, ['by' => $code], (int)$u['id']); }
    }
    return $n;
}

/** The person reports an issue with an item they hold. */
function as_report(PDO $db, array $u, int $allotId, string $type, string $desc, string $when = '', ?string $today = null): int {
    $today ??= date('Y-m-d'); $code = (string)($u['emp_code'] ?? '');
    $q = $db->prepare('SELECT * FROM asset_allot WHERE id = ? AND emp_code = ? AND returned_on IS NULL'); $q->execute([$allotId, $code]);
    $al = $q->fetch(); if (!$al || $code === '') throw new RuntimeException('Not found.');
    if (!in_array($type, AS_ISSUE_TYPES, true)) throw new InvalidArgumentException('Pick what happened.');
    $desc = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($desc)) ?? ''), 0, 600);
    if (mb_strlen($desc) < 3) throw new InvalidArgumentException('Add a line about what happened.');
    if ($when !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $when) || $when > $today)) throw new InvalidArgumentException('When: a date up to today.');
    $db->prepare('INSERT INTO asset_issue (allot_id, asset_id, emp_code, user_id, type, happened_on, description) VALUES (?,?,?,?,?,?,?)')
       ->execute([$allotId, $al['asset_id'], $code, (int)$u['id'], $type, $when ?: null, $desc]);
    $id = (int)$db->lastInsertId();
    as_log($db, (int)$al['asset_id'], 'issue', null, ['type' => $type], (int)$u['id']);
    return $id;
}

/** An editor resolves an issue; lost / stolen close the allotment and set the asset. */
function as_resolve(PDO $db, array $u, int $issueId, string $outcome, string $note = ''): void {
    if (!as_can_edit($u, as_settings())) throw new RuntimeException('Not allowed.');
    if (!isset(AS_OUTCOMES[$outcome])) throw new InvalidArgumentException('Pick the outcome.');
    $q = $db->prepare("SELECT * FROM asset_issue WHERE id = ? AND status = 'open'"); $q->execute([$issueId]);
    $i = $q->fetch(); if (!$i) throw new RuntimeException('Not found.');
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE asset_issue SET status = 'closed', outcome = ?, outcome_note = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?")->execute([$outcome, mb_substr(trim(strip_tags($note)), 0, 300), (int)$u['id'], $issueId]);
        if (in_array($outcome, ['lost', 'stolen'], true)) {
            $db->prepare("UPDATE asset_allot SET returned_on = CURDATE(), return_condition = 'missing', return_note = ?, received_by = ?, open_key = NULL WHERE id = ? AND returned_on IS NULL")->execute([AS_OUTCOMES[$outcome], (int)$u['id'], $i['allot_id']]);
            $db->prepare('UPDATE asset SET status = ? WHERE id = ?')->execute([$outcome, $i['asset_id']]);
        }
        as_log($db, (int)$i['asset_id'], 'issue resolved', null, ['outcome' => $outcome], (int)$u['id']);
        $db->commit();
    } catch (Throwable $ex) { $db->rollBack(); throw $ex; }
}

/** Start or close the yearly audit (confirmers). Closing keeps the result. */
function as_audit_step(PDO $db, array $u, string $act, string $label = '', string $due = ''): void {
    if (!as_can_confirm($u, as_settings())) throw new RuntimeException('Only a confirmer runs the audit.');
    $open = as_audit_open($db);
    if ($act === 'start') {
        if ($open) throw new RuntimeException('An audit is already open.');
        $label = mb_substr(trim(strip_tags($label)), 0, 80); if ($label === '') throw new InvalidArgumentException('Name the audit.');
        $db->prepare('INSERT INTO asset_audit (label, started_on, due_on, by_user) VALUES (?, CURDATE(), ?, ?)')->execute([$label, preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null, (int)$u['id']]);
    } else {
        if (!$open) throw new RuntimeException('No audit is open.');
        $p = as_audit_progress($db, $open);
        $db->prepare('UPDATE asset_audit SET closed_at = NOW(), result = ? WHERE id = ?')->execute([$p['done'] . ' of ' . $p['total'] . ' items confirmed', $open['id']]);
    }
    audit((int)$u['id'], 'asset_audit_' . $act, '');
}

function as_audit_progress(PDO $db, array $audit): array {
    $rows = $db->query('SELECT al.*, e.name FROM asset_allot al LEFT JOIN employee e ON e.code = al.emp_code WHERE al.returned_on IS NULL')->fetchAll();
    $pend = array_filter($rows, fn($r) => as_needs_ack($r, $audit));
    return ['total' => count($rows), 'done' => count($rows) - count($pend), 'pending' => array_values(array_unique(array_map(fn($r) => (string)($r['name'] ?: $r['emp_code']), $pend)))];
}
