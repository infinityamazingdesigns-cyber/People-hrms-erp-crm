<?php
/**
 * Expense claims — ported from the reference build as rules; lists, roles and limits are settings.
 *  - A claim holds lines: money spent (debit) and money the person received for it, such as an advance (credit).
 *    What is paid = allowed spending − money received (below 0 = the person gives money back).
 *  - Every spending line needs its bill (PDF / JPEG / PNG / WebP by its own bytes, in the database) or "No bill" with
 *    remarks, unless the setting lifts it. Drafts stay with the person.
 *  - Steps: submitted → audit (HR: allow / not allow single lines with a reason, return with a note) → approve
 *    (approve, reject or send back with a note) → pay (date, mode, reference). Returned claims are corrected and sent
 *    again (round 2 …); a claim is withdrawn by its person while with HR or returned. Nobody decides their own claim.
 *  - Possible duplicates are flagged (never blocked): the same bill on another claim, the same date + amount on the
 *    person's other claims.
 *  - Nothing is deleted: removed lines are switched off, bills stay, ec_log is append-only. Audit lines carry ids only.
 */
declare(strict_types=1);

require_once __DIR__ . '/kyc.php';

const EC_STATUS = ['draft' => 'Draft', 'submitted' => 'With HR for audit', 'returned' => 'Returned to you', 'audited' => 'Waiting for approval',
    'approved' => 'Approved · to pay', 'paid' => 'Paid', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];
const EC_OPEN = ['submitted', 'returned', 'audited', 'approved'];
const EC_FILE_MAX = 10 * 1024 * 1024;
const EC_FILES_PER_SEND = 20;
const EC_LINES_MAX = 60;
const EC_DEFAULTS = [
    'categories' => ['Travel', 'Local conveyance', 'Food', 'Lodging', 'Fuel', 'Client meeting', 'Courier & postage', 'Office supplies',
                     'Phone & internet', 'Training', 'Software', 'Repairs', 'Other'],
    'modes' => ['Cash', 'Card', 'Bank transfer', 'Mobile payment', 'Other'],
    'credit_cats' => ['Advance from the company', 'Cash given by the office', 'Refund received', 'Other money received'],
    'pay_modes' => ['Bank transfer', 'Mobile payment', 'Cash', 'Cheque', 'With salary'],
    'bill_needed' => true, 'days_back' => 400, 'max_line' => 10000000,
    'audit_roles' => ['HRMANAGER', 'SUPERADMIN'], 'approve_roles' => ['CEO', 'SUPERADMIN'], 'pay_roles' => ['ACCOUNTS', 'SUPERADMIN'], 'report_roles' => ['CEO', 'SUPERADMIN'],
];

/* ---------------- settings and rights ---------------- */

function ec_settings(): array {
    $s = module_states()['claims']['settings'] ?? [];
    return array_replace(EC_DEFAULTS, array_intersect_key($s, EC_DEFAULTS));
}

/** Check a settings form; lists come one per line. Pure. Returns [settings, errors]. */
function ec_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['categories' => 60, 'modes' => 20, 'credit_cats' => 20, 'pay_modes' => 20] as $k => $max) {
        $l = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim(strip_tags($x)), 0, 60), preg_split('/\R/', (string)($in[$k] ?? ''))))));
        if (!$l) $e[] = "Give at least one line for $k."; elseif (count($l) > $max) $e[] = "At most $max lines for $k.";
        $o[$k] = $l;
    }
    $o['bill_needed'] = !empty($in['bill_needed']);
    foreach (['days_back' => [1, 1500], 'max_line' => [1, 1000000000]] as $k => [$lo, $hi]) {
        $v = filter_var($in[$k] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $lo || $v > $hi) $e[] = "$k: a whole number from $lo to $hi."; else $o[$k] = $v;
    }
    foreach (['audit_roles', 'approve_roles', 'pay_roles', 'report_roles'] as $k) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = "Pick at least one role for $k.";
    }
    return [$o, $e];
}

/** May this login take this step at all? Pure. */
function ec_role(array $u, string $step, array $set): bool {
    $k = ['audit' => 'audit_roles', 'approve' => 'approve_roles', 'pay' => 'pay_roles', 'report' => 'report_roles'][$step] ?? '';
    return $k !== '' && has_role($u, ...$set[$k]);
}

/** May this login see other people's claims (any reviewing step)? Pure. */
function ec_reviewer(array $u, array $set): bool {
    foreach (['audit', 'approve', 'pay', 'report'] as $s) if (ec_role($u, $s, $set)) return true;
    return false;
}

/* ---------------- pure rules ---------------- */

function ec_text($v, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v)) ?? ''), 0, $max); }

function ec_amount($v, float $max): float {
    $s = preg_replace('/[\s,]/', '', (string)$v);
    if ($s === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $s)) throw new InvalidArgumentException('Amount: a number, up to 2 decimals.');
    $n = round((float)$s, 2);
    if ($n <= 0 || $n > $max) throw new InvalidArgumentException('Amount: more than 0 and at most ' . number_format($max) . '.');
    return $n;
}

function ec_date(string $d, string $today, int $daysBack): string {
    $t = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if (!$t || $t->format('Y-m-d') !== $d) throw new InvalidArgumentException('Pick the date.');
    if ($d > $today) throw new InvalidArgumentException('The date cannot be in the future.');
    if ($d < date('Y-m-d', strtotime("$today -$daysBack day"))) throw new InvalidArgumentException("The date is more than $daysBack days ago.");
    return $d;
}

/** One line from a form row; blank rows are null. $n = row number for messages. Pure. */
function ec_line_input(array $in, int $n, array $set, string $today, array $branchIds = []): ?array {
    if (trim((string)($in['particulars'] ?? '')) === '' && trim((string)($in['amount'] ?? '')) === '') return null;
    try {
        $kind = ($in['kind'] ?? 'dr') === 'cr' ? 'cr' : 'dr';
        $l = ['id' => (int)($in['id'] ?? 0), 'kind' => $kind, 'line_date' => ec_date((string)($in['date'] ?? ''), $today, (int)$set['days_back']),
              'particulars' => ec_text($in['particulars'] ?? '', 300), 'category' => ec_text($in['category'] ?? '', 80), 'mode' => ec_text($in['mode'] ?? '', 40),
              'amount' => ec_amount($in['amount'] ?? '', (float)$set['max_line']), 'remarks' => ec_text($in['remarks'] ?? '', 600),
              'no_receipt' => !empty($in['no_receipt']) ? 1 : 0, 'branch_id' => in_array((int)($in['branch_id'] ?? 0), $branchIds, true) ? (int)$in['branch_id'] : null];
        if (mb_strlen($l['particulars']) < 3) throw new InvalidArgumentException('say what it was for.');
        if (!in_array($l['category'], $kind === 'cr' ? $set['credit_cats'] : $set['categories'], true)) throw new InvalidArgumentException('pick a category from the list.');
        if (!in_array($l['mode'], $set['modes'], true)) throw new InvalidArgumentException($kind === 'cr' ? 'pick how the money came.' : 'pick how it was paid.');
        if ($kind === 'cr') { $l['no_receipt'] = 0; $l['branch_id'] = null; }
        elseif ($l['no_receipt'] && mb_strlen($l['remarks']) < 5) throw new InvalidArgumentException('no bill — say in remarks why.');
        return $l;
    } catch (InvalidArgumentException $e) { throw new InvalidArgumentException('Line ' . $n . ': ' . $e->getMessage()); }
}

/** The claim from the form: title + lines keyed by row key. Pure. */
function ec_claim_input(array $in, array $set, string $today, array $branchIds = []): array {
    $lines = []; $n = 0;
    foreach ((array)($in['lines'] ?? []) as $k => $row) {
        if (!is_array($row) || !preg_match('/^[a-z0-9]{1,12}$/i', (string)$k)) continue;
        $n++;
        if ($l = ec_line_input($row, $n, $set, $today, $branchIds)) $lines[(string)$k] = $l;
    }
    if (!$lines) throw new InvalidArgumentException('Add at least one line.');
    if (!array_filter($lines, fn($l) => $l['kind'] === 'dr')) throw new InvalidArgumentException('Add at least one expense — money received alone is not a claim.');
    if (count($lines) > EC_LINES_MAX) throw new InvalidArgumentException('Up to ' . EC_LINES_MAX . ' lines in one claim.');
    return [ec_text($in['title'] ?? '', 200), $lines];
}

/** Totals from active lines: spent, allowed spending, received, payable. Pure. */
function ec_totals(array $lines): array {
    $dr = 0.0; $ok = 0.0; $cr = 0.0;
    foreach ($lines as $l) {
        if (isset($l['active']) && !(int)$l['active']) continue;
        if ($l['kind'] === 'cr') $cr += (float)$l['amount'];
        else { $dr += (float)$l['amount']; if (!isset($l['allowed']) || (int)$l['allowed']) $ok += (float)$l['amount']; }
    }
    return ['spent' => round($dr, 2), 'allowed' => round($ok, 2), 'received' => round($cr, 2), 'payable' => round($ok - $cr, 2)];
}

/** What is paid: the approved spending once HR looked at the lines, else everything spent, less money received. Pure. */
function ec_payable(array $c): float {
    $dr = $c['approved_amount'] !== null && $c['approved_amount'] !== '' ? (float)$c['approved_amount'] : (float)$c['amount'];
    return round($dr - (float)$c['credit_amount'], 2);
}

/** Lines that still need a bill when sending: spending, no bill on file, not marked "No bill". Pure. */
function ec_bills_missing(array $lines, array $files, bool $needed): array {
    if (!$needed) return [];
    $o = [];
    foreach (array_values(array_filter($lines, fn($l) => !isset($l['active']) || (int)$l['active'])) as $i => $l) {
        if ($l['kind'] === 'cr' || (int)$l['no_receipt']) continue;
        if (!array_filter($files, fn($f) => (int)$f['line_id'] === (int)$l['id'] && (!isset($f['active']) || (int)$f['active']))) $o[] = 'Line ' . ($i + 1) . ' (' . $l['particulars'] . ')';
    }
    return $o;
}

/**
 * Whether a step may happen: null = yes, else the reason. $own = the claim is the login's. Pure.
 * audit: ok | return · approve: approve | reject | back · pay: paid
 */
function ec_step_problem(array $c, string $step, string $decision, bool $mayStep, bool $own, string $note = '', array $pay = []): ?string {
    if ($own) return 'You cannot decide on your own claim.';
    if (!$mayStep) return 'Your login does not have this step.';
    $need = ['audit' => 'submitted', 'approve' => 'audited', 'pay' => 'approved'][$step] ?? null;
    if ($need === null) return 'Unknown step.';
    if ($c['status'] !== $need) return 'This claim is not at that step.';
    if (in_array("$step:$decision", ['audit:return', 'approve:reject', 'approve:back'], true) && mb_strlen(trim($note)) < 3) return 'Give a note.';
    if ("$step:$decision" === 'audit:ok' && $c['approved_amount'] !== null && (float)$c['approved_amount'] <= 0) return 'No line is allowed — return the claim instead.';
    if ($step === 'pay') {
        if ($decision !== 'paid') return 'Unknown step.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($pay['paid_on'] ?? ''))) return 'Give the date it was paid.';
        if (trim((string)($pay['pay_mode'] ?? '')) === '') return 'Pick how it was paid.';
        if (ec_payable($c) > 0 && trim((string)($pay['pay_ref'] ?? '')) === '') return 'Give the payment reference.';
    }
    if (!in_array("$step:$decision", ['audit:ok', 'audit:return', 'approve:approve', 'approve:reject', 'approve:back', 'pay:paid'], true)) return 'Unknown step.';
    return null;
}

/** Next claim number in the year: CL-2026-0001. Pure given the last number. */
function ec_number(string $last, string $year): string {
    $n = preg_match('/^CL-' . preg_quote($year, '/') . '-(\d+)$/', $last, $m) ? (int)$m[1] + 1 : 1;
    return sprintf('CL-%s-%04d', $year, $n);
}

/* ---------------- database ---------------- */

function ec_log(PDO $db, int $id, ?int $uid, string $action, string $note = ''): void {
    $db->prepare('INSERT INTO ec_log (claim_id, user_id, action, note) VALUES (?,?,?,?)')->execute([$id, $uid, $action, mb_substr($note, 0, 600)]);
}

function ec_get(PDO $db, int $id, bool $lock = false): ?array {
    $q = $db->prepare('SELECT c.*, u.name AS person, u.emp_code FROM ec_claim c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function ec_lines(PDO $db, int $id, bool $all = false): array {
    $q = $db->prepare('SELECT * FROM ec_line WHERE claim_id = ?' . ($all ? '' : ' AND active = 1') . ' ORDER BY pos, id'); $q->execute([$id]);
    return $q->fetchAll();
}

function ec_files(PDO $db, int $id): array {
    $q = $db->prepare('SELECT id, claim_id, line_id, kind, name, mime, size, sha, active FROM ec_file WHERE claim_id = ? AND active = 1 ORDER BY id'); $q->execute([$id]);
    return $q->fetchAll();
}

/** Who may see a claim: its person, or a reviewer when the person's employee record is in their branch scope. */
function ec_may_see(PDO $db, array $u, array $c, array $set): bool {
    if ((int)$c['user_id'] === (int)$u['id']) return true;
    if (!ec_reviewer($u, $set)) return false;
    $e = !empty($c['emp_code']) ? emp_by_code($db, (string)$c['emp_code']) : null;
    return !$e || emp_in_scope($u, $e);
}

/** Uploaded files from $_FILES['bill'][rowKey][] → rowKey => [[bytes, name]]. Real bytes only. */
function ec_uploads(array $files): array {
    $o = [];
    foreach ((array)($files['bill']['tmp_name'] ?? []) as $k => $list) foreach ((array)$list as $i => $tmp) {
        if ((int)($files['bill']['error'][$k][$i] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$tmp)) continue;
        $o[(string)$k][] = [(string)file_get_contents((string)$tmp), (string)($files['bill']['name'][$k][$i] ?? 'bill')];
    }
    return $o;
}

function ec_file_add(PDO $db, int $claimId, ?int $lineId, string $bytes, string $name, int $uid, string $kind = 'receipt'): void {
    if (strlen($bytes) > EC_FILE_MAX) throw new InvalidArgumentException('A file is over 10 MB.');
    $mime = kyc_doc_mime($bytes);
    if ($mime === null) throw new InvalidArgumentException('Bills: PDF, JPEG, PNG or WebP only.');
    $db->prepare('INSERT INTO ec_file (claim_id, line_id, kind, name, mime, size, sha, bytes, user_id) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$claimId, $lineId, $kind, mb_substr(basename($name), 0, 160), $mime, strlen($bytes), hash('sha256', $bytes), $bytes, $uid]);
}

/** Save the person's claim: new, draft, returned, or still with HR. $send false = draft. Returns [id, duplicate warnings]. */
function ec_save(PDO $db, array $u, int $id, array $in, array $filesByKey, bool $send, ?string $today = null): array {
    $today ??= date('Y-m-d');
    $set = ec_settings(); $uid = (int)$u['id'];
    [$title, $lines] = ec_claim_input($in, $set, $today, array_map('intval', array_column(branches(false), 'id')));
    if (array_sum(array_map('count', $filesByKey)) > EC_FILES_PER_SEND) throw new InvalidArgumentException('Up to ' . EC_FILES_PER_SEND . ' files in one send — save as draft and add the rest.');
    $db->beginTransaction();
    try {
        if ($id) {
            $old = ec_get($db, $id, true);
            if (!$old || (int)$old['user_id'] !== $uid) throw new RuntimeException('Claim not found.');
            if (!in_array($old['status'], ['draft', 'returned', 'submitted'], true)) throw new RuntimeException('This claim can no longer be changed.');
            if (!$send && $old['status'] !== 'draft') throw new RuntimeException('A sent claim cannot go back to draft — send it again.');
        } else {
            $year = substr($today, 0, 4);
            $last = (string)$db->query("SELECT claim_no FROM ec_claim WHERE claim_no LIKE " . $db->quote('CL-' . $year . '-%') . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
            $db->prepare("INSERT INTO ec_claim (claim_no, user_id, status) VALUES (?,?,'draft')")->execute([ec_number($last, $year), $uid]);
            $id = (int)$db->lastInsertId();
            $old = ec_get($db, $id);
            ec_log($db, $id, $uid, 'created');
        }
        $have = array_column(ec_lines($db, $id), null, 'id'); $keep = []; $lineOf = []; $pos = 0;
        foreach ($lines as $k => $l) {
            $pos++;
            $vals = [$pos, $l['kind'], $l['line_date'], $l['particulars'], $l['category'], $l['mode'], $l['amount'], $l['no_receipt'], $l['remarks'], $l['branch_id']];
            if ($l['id'] && isset($have[$l['id']])) {
                $db->prepare('UPDATE ec_line SET pos=?, kind=?, line_date=?, particulars=?, category=?, mode=?, amount=?, no_receipt=?, remarks=?, branch_id=? WHERE id=?')->execute([...$vals, $l['id']]);
                $lid = $l['id'];
            } else {
                $db->prepare('INSERT INTO ec_line (pos, kind, line_date, particulars, category, mode, amount, no_receipt, remarks, branch_id, claim_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([...$vals, $id]);
                $lid = (int)$db->lastInsertId();
            }
            $keep[$lid] = true; $lineOf[$k] = $lid;
        }
        foreach ($have as $lid => $l) if (!isset($keep[$lid])) $db->prepare('UPDATE ec_line SET active = 0 WHERE id = ?')->execute([$lid]);
        foreach ($filesByKey as $k => $files) if (isset($lineOf[$k])) foreach ($files as [$b, $n]) ec_file_add($db, $id, $lineOf[$k], $b, $n, $uid);
        if ($send) $db->prepare("UPDATE ec_line SET allowed = 1, disallow_note = '', disallow_by = NULL WHERE claim_id = ? AND active = 1")->execute([$id]);
        $L = ec_lines($db, $id); $t = ec_totals($L);
        $first = reset($lines);
        $db->prepare('UPDATE ec_claim SET title = ?, claim_date = ?, amount = ?, approved_amount = NULL, credit_amount = ? WHERE id = ?')
           ->execute([$title !== '' ? $title : (count($lines) === 1 ? $first['particulars'] : 'Expenses · ' . count($lines) . ' lines'), min(array_column($L, 'line_date')), $t['spent'], $t['received'], $id]);
        if ($send) {
            if ($miss = ec_bills_missing($L, ec_files($db, $id), (bool)$set['bill_needed'])) throw new InvalidArgumentException(implode(', ', $miss) . ': add the bill, or tick "No bill" and say why.');
            $round = (int)$old['round'] + ($old['status'] === 'returned' ? 1 : 0);
            $db->prepare("UPDATE ec_claim SET status = 'submitted', round = ?, submitted_at = NOW(), audit_by = NULL, audit_at = NULL, approve_by = NULL, approve_at = NULL WHERE id = ?")->execute([$round, $id]);
            ec_log($db, $id, $uid, $old['status'] === 'returned' ? 'sent again' : ($old['status'] === 'submitted' ? 'changed' : 'sent'));
        } else ec_log($db, $id, $uid, 'draft saved');
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    if ($send) audit($uid, 'claim_submit', '#' . $id);
    return [$id, ec_dup_flags($db, ec_get($db, $id))];
}

/** HR allows or does not allow one spending line (with a reason) while the claim waits for audit. */
function ec_line_decide(PDO $db, array $u, int $claimId, int $lineId, bool $allow, string $note = ''): void {
    $set = ec_settings(); $note = ec_text($note, 600);
    $db->beginTransaction();
    try {
        $c = ec_get($db, $claimId, true);
        if (!$c || !ec_may_see($db, $u, $c, $set)) throw new RuntimeException('Claim not found.');
        if ((int)$c['user_id'] === (int)$u['id']) throw new RuntimeException('You cannot decide on your own claim.');
        if (!ec_role($u, 'audit', $set)) throw new RuntimeException('Your login does not have this step.');
        if ($c['status'] !== 'submitted') throw new RuntimeException('Lines are checked while the claim waits for audit.');
        $l = array_column(ec_lines($db, $claimId), null, 'id')[$lineId] ?? null;
        if (!$l) throw new RuntimeException('Line not found.');
        if ($l['kind'] === 'cr') throw new RuntimeException('Money received always counts.');
        if (!$allow && mb_strlen($note) < 3) throw new InvalidArgumentException('Say why this line is not allowed.');
        $db->prepare('UPDATE ec_line SET allowed = ?, disallow_note = ?, disallow_by = ? WHERE id = ?')->execute([$allow ? 1 : 0, $allow ? '' : $note, $allow ? null : (int)$u['id'], $lineId]);
        $t = ec_totals(ec_lines($db, $claimId));
        $db->prepare('UPDATE ec_claim SET approved_amount = ? WHERE id = ?')->execute([$t['allowed'] < $t['spent'] ? $t['allowed'] : null, $claimId]);
        ec_log($db, $claimId, (int)$u['id'], $allow ? 'line allowed' : 'line not allowed', $note);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

/** A step on a claim. */
function ec_decide(PDO $db, array $u, int $id, string $step, string $decision, string $note = '', array $pay = []): void {
    $set = ec_settings(); $uid = (int)$u['id']; $note = ec_text($note, 600);
    $db->beginTransaction();
    try {
        $c = ec_get($db, $id, true);
        if (!$c || !ec_may_see($db, $u, $c, $set)) throw new RuntimeException('Claim not found.');
        if ($p = ec_step_problem($c, $step, $decision, ec_role($u, $step, $set), (int)$c['user_id'] === $uid, $note, $pay)) throw new RuntimeException($p);
        if ($step === 'pay' && (string)$pay['paid_on'] > date('Y-m-d')) throw new RuntimeException('The payment date cannot be in the future.');
        [$sql, $args] = match ("$step:$decision") {
            'audit:ok' => ["status='audited', audit_by=?, audit_at=NOW(), audit_note=?", [$uid, $note]],
            'audit:return' => ["status='returned', audit_by=?, audit_at=NOW(), audit_note=?", [$uid, $note]],
            'approve:approve' => ["status='approved', approve_by=?, approve_at=NOW(), approve_note=?", [$uid, $note]],
            'approve:reject' => ["status='rejected', approve_by=?, approve_at=NOW(), approve_note=?", [$uid, $note]],
            'approve:back' => ["status='submitted', approve_note=?, audit_by=NULL, audit_at=NULL", [$note]],
            'pay:paid' => ["status='paid', paid_by=?, paid_at=NOW(), paid_on=?, pay_mode=?, pay_ref=?", [$uid, $pay['paid_on'], ec_text($pay['pay_mode'], 60), ec_text($pay['pay_ref'] ?? '', 120)]],
        };
        $db->prepare("UPDATE ec_claim SET $sql WHERE id = ?")->execute([...$args, $id]);
        ec_log($db, $id, $uid, "$step: $decision", $note);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit($uid, 'claim_' . $step . '_' . $decision, '#' . $id);
}

/** The person withdraws a draft, a claim with HR, or one returned to them. */
function ec_withdraw(PDO $db, array $u, int $id, string $note = ''): void {
    $c = ec_get($db, $id);
    if (!$c || (int)$c['user_id'] !== (int)$u['id']) throw new RuntimeException('Claim not found.');
    if (!in_array($c['status'], ['draft', 'submitted', 'returned'], true)) throw new RuntimeException('Only a draft, a claim with HR or one returned to you is withdrawn.');
    $db->prepare("UPDATE ec_claim SET status = 'withdrawn' WHERE id = ?")->execute([$id]);
    ec_log($db, $id, (int)$u['id'], 'withdrawn', ec_text($note, 600));
}

/** Possible duplicates: the same bill on another live claim, the same date + amount on the person's other claims. line id => [sentences]. */
function ec_dup_flags(PDO $db, ?array $c): array {
    if (!$c) return [];
    $o = []; $live = "c.status NOT IN ('withdrawn','rejected')";
    $byBill = $db->prepare("SELECT DISTINCT c.claim_no FROM ec_file f JOIN ec_claim c ON c.id = f.claim_id WHERE f.active = 1 AND f.sha = ? AND c.id <> ? AND $live");
    $byAmt = $db->prepare("SELECT DISTINCT c.claim_no FROM ec_line l JOIN ec_claim c ON c.id = l.claim_id WHERE l.active = 1 AND l.kind = 'dr' AND c.user_id = ? AND c.id <> ? AND $live AND l.line_date = ? AND l.amount = ?");
    $files = ec_files($db, (int)$c['id']);
    foreach (ec_lines($db, (int)$c['id']) as $l) {
        if ($l['kind'] === 'cr') continue;
        $w = [];
        foreach ($files as $f) if ((int)$f['line_id'] === (int)$l['id']) { $byBill->execute([$f['sha'], $c['id']]); foreach ($byBill->fetchAll(PDO::FETCH_COLUMN) as $no) $w[] = 'The same bill is on claim ' . $no . '.'; }
        $byAmt->execute([$c['user_id'], $c['id'], $l['line_date'], $l['amount']]);
        foreach ($byAmt->fetchAll(PDO::FETCH_COLUMN) as $no) $w[] = 'The same date and amount is on claim ' . $no . '.';
        if ($w) $o[(int)$l['id']] = array_values(array_unique($w));
    }
    return $o;
}

/** Claims for a list: 'mine', or a reviewer's queue ('audit', 'approve', 'pay', 'all'). */
function ec_list(PDO $db, array $u, string $who): array {
    $set = ec_settings();
    if ($who === 'mine') { $q = $db->prepare('SELECT c.*, u.name AS person, u.emp_code FROM ec_claim c LEFT JOIN users u ON u.id = c.user_id WHERE c.user_id = ? ORDER BY c.id DESC'); $q->execute([(int)$u['id']]); return $q->fetchAll(); }
    $st = ['audit' => ['submitted'], 'approve' => ['audited'], 'pay' => ['approved']][$who] ?? null;
    if ($st !== null && !ec_role($u, $who, $set)) return [];
    if ($st === null && !ec_reviewer($u, $set)) return [];
    $sql = "SELECT c.*, u.name AS person, u.emp_code FROM ec_claim c LEFT JOIN users u ON u.id = c.user_id WHERE c.status <> 'draft'" . ($st ? " AND c.status IN ('" . implode("','", $st) . "')" : '') . ' ORDER BY c.submitted_at DESC, c.id DESC LIMIT 500';
    return array_values(array_filter($db->query($sql)->fetchAll(), fn($c) => (int)$c['user_id'] !== (int)$u['id'] && ec_may_see($db, $u, $c, $set)));
}

/** Waiting counts for this login: to audit / approve / pay, and own claims returned. */
function ec_waiting(PDO $db, array $u): array {
    $o = []; foreach (['audit', 'approve', 'pay'] as $s) $o[$s] = count(ec_list($db, $u, $s));
    $q = $db->prepare("SELECT COUNT(*) FROM ec_claim WHERE user_id = ? AND status = 'returned'"); $q->execute([(int)$u['id']]);
    $o['returned'] = (int)$q->fetchColumn();
    return $o;
}

/** Totals for the report: by status, by category (allowed spending lines), by month. Not one's own lines. */
function ec_report(PDO $db, array $u, string $from, string $to): array {
    $claims = array_values(array_filter(ec_list($db, $u, 'all'), fn($c) => (string)$c['submitted_at'] >= $from && substr((string)$c['submitted_at'], 0, 10) <= $to));
    $o = ['claims' => count($claims), 'by_status' => [], 'by_category' => [], 'by_month' => [], 'paid' => 0.0, 'open' => 0.0];
    foreach ($claims as $c) {
        $o['by_status'][$c['status']] = ($o['by_status'][$c['status']] ?? 0) + 1;
        if ($c['status'] === 'paid') $o['paid'] += ec_payable($c); elseif (in_array($c['status'], EC_OPEN, true)) $o['open'] += ec_payable($c);
        foreach (ec_lines($db, (int)$c['id']) as $l) if ($l['kind'] === 'dr' && (int)$l['allowed'] && !in_array($c['status'], ['rejected', 'withdrawn'], true)) {
            $o['by_category'][$l['category']] = ($o['by_category'][$l['category']] ?? 0) + (float)$l['amount'];
            $m = substr($l['line_date'], 0, 7); $o['by_month'][$m] = ($o['by_month'][$m] ?? 0) + (float)$l['amount'];
        }
    }
    arsort($o['by_category']); ksort($o['by_month']);
    return $o;
}
