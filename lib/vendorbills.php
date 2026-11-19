<?php
// Vendor bills paid from head office — rules. Rent, electricity, internet … of a branch: entered by someone working for
// that branch (own branch + ticked branches; viewers their scope), sent → checked → approved → paid by Accounts with
// paid on, mode and a transaction reference or a payment slip. A checker / approver can return it with a note; the
// approver can reject it with the reason. Nobody checks, approves or pays a bill they entered.
// Repeating bills make a draft each month for their creator. Approved bills due soon are told to the pay roles once.
// Who sees: the person who entered a bill, and holders of a step / view role for bills of their branches
// (Super Admin / CEO every branch; others their ticked branches, none ticked = every branch).
// Files by their bytes in the database. Nothing is deleted; vb_log is append-only.
// Pure functions first, then the database half.
declare(strict_types=1);
require_once __DIR__ . '/kyc.php';   // kyc_doc_mime()

const VB_STATUSES = ['draft' => 'Draft (not sent)', 'submitted' => 'Waiting to be checked', 'returned' => 'Returned for correction', 'checked' => 'Waiting for approval',
    'approved' => 'Approved · to pay', 'paid' => 'Paid', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];
const VB_OPEN = ['submitted', 'checked', 'approved'];
const VB_PAY_MODES = ['Bank transfer', 'UPI', 'Cheque', 'Auto-debit / standing instruction', 'Cash (head office)'];
const VB_FILE_MAX = 10 * 1024 * 1024;
const VB_FILES_PER_SEND = 10;
const VB_AMOUNT_MAX = 100000000.0;
const VB_REPEAT_MAX = 200;
const VB_DEFAULTS = ['view_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO', 'ACCOUNTS'], 'check_roles' => ['SUPERADMIN', 'HRMANAGER'], 'approve_roles' => ['SUPERADMIN', 'CEO'],
    'pay_roles' => ['SUPERADMIN', 'ACCOUNTS'], 'due_warn_days' => 3,
    'kinds' => "Rent\nElectricity\nWater\nInternet / broadband\nTelephone / mobile\nMaintenance / society charges\nSecurity services\nHousekeeping\nService contract\nProperty tax\nInsurance\nOther"];

function vb_settings(): array {
    $s = module_states()['vendorbills']['settings'] ?? [];
    return array_replace(VB_DEFAULTS, array_intersect_key($s, VB_DEFAULTS));
}
function vb_kinds(array $set): array { return array_values(array_filter(array_map('trim', explode("\n", (string)$set['kinds'])))); }

function vb_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['view_roles' => 'seeing every bill', 'check_roles' => 'checking', 'approve_roles' => 'approving', 'pay_roles' => 'paying'] as $k => $l) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = "Pick at least one role for $l.";
    }
    $o['due_warn_days'] = max(0, min(30, (int)($in['due_warn_days'] ?? 3)));
    $k = array_values(array_unique(array_filter(array_map(fn($x) => vb_text($x, 60), preg_split('/\R/', (string)($in['kinds'] ?? '')) ?: []))));
    if (!$k) $e[] = 'Keep at least one kind of bill.';
    $o['kinds'] = implode("\n", array_slice($k, 0, 40));
    return [$o, $e];
}

/** Steps and branch scope for a login. Pure. */
function vb_ctx_for(array $u, array $roles, array $set, ?int $own, array $allBranches): array {
    $h = fn(array $l) => (bool)array_intersect($roles, $l);
    $R = ['view' => $h($set['view_roles']), 'check' => $h($set['check_roles']), 'approve' => $h($set['approve_roles']), 'pay' => $h($set['pay_roles'])];
    $ticked = array_values(array_map('intval', $u['branches'] ?? []));
    $all = array_values(array_map('intval', $allBranches));
    $any = $R['view'] || $R['check'] || $R['approve'] || $R['pay'];
    $see = !$any ? [] : (array_intersect($roles, ['SUPERADMIN', 'CEO']) || !$ticked ? null : $ticked);
    $enter = $see === null ? $all : array_values(array_filter($all, fn($b) => in_array($b, array_merge($see, $ticked, $own ? [$own] : []), true)));
    return ['uid' => (int)$u['id'], 'name' => (string)$u['name'], 'R' => $R, 'see' => $see, 'enter' => $enter];
}
function vb_sees(array $c, array $b): bool {
    if ((int)$b['entered_by'] === $c['uid']) return true;
    return $c['see'] === null || in_array((int)$b['branch_id'], (array)$c['see'], true);
}
/** May this login take $step on this bill? Never their own. Pure. */
function vb_can(array $c, string $step, array $b): bool { return !empty($c['R'][$step]) && (int)$b['entered_by'] !== $c['uid'] && vb_sees($c, $b); }

function vb_text($v, int $max): string { return mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$v))), 0, $max); }
function vb_amount($v): float {
    $s = str_replace([',', ' '], '', (string)$v);
    if ($s === '' || !is_numeric($s)) throw new InvalidArgumentException('Amount: a number, e.g. 25000 or 1250.50.');
    $n = round((float)$s, 2);
    if ($n <= 0 || $n > VB_AMOUNT_MAX) throw new InvalidArgumentException('Amount: more than 0.');
    return $n;
}
function vb_date($v, string $label, bool $future, string $today): ?string {
    $d = trim((string)$v);
    if ($d === '') return null;
    $t = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if (!$t || $t->format('Y-m-d') !== $d) throw new InvalidArgumentException("$label: pick a date.");
    if (!$future && $d > $today) throw new InvalidArgumentException("$label cannot be in the future.");
    if ($d < date('Y-m-d', strtotime($today . ' -400 days')) || $d > date('Y-m-d', strtotime($today . ' +400 days'))) throw new InvalidArgumentException("$label: within about a year.");
    return $d;
}

/** The bill form, checked. Pure. */
function vb_input(array $in, array $branches, array $kinds, string $today): array {
    $b = (int)($in['branch'] ?? 0);
    if (!in_array($b, $branches, true)) throw new InvalidArgumentException('Pick the branch.');
    $k = vb_text($in['kind'] ?? '', 60);
    if (!in_array($k, $kinds, true)) throw new InvalidArgumentException('Pick what the bill is for.');
    $p = trim((string)($in['period'] ?? ''));
    if ($p !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p)) throw new InvalidArgumentException('Period: pick the month.');
    $o = ['branch_id' => $b, 'kind' => $k, 'vendor' => vb_text($in['vendor'] ?? '', 160), 'vendor_bill_no' => vb_text($in['vendor_bill_no'] ?? '', 60),
          'bill_date' => vb_date($in['bill_date'] ?? '', 'Bill date', false, $today), 'period' => $p, 'due_date' => vb_date($in['due_date'] ?? '', 'Due date', true, $today),
          'pay_to' => vb_text($in['pay_to'] ?? '', 300), 'note' => vb_text($in['note'] ?? '', 600), 'no_bill' => !empty($in['no_bill']) ? 1 : 0, 'no_bill_why' => vb_text($in['no_bill_why'] ?? '', 300)];
    $o['amount'] = trim((string)($in['amount'] ?? '')) === '' ? 0.0 : vb_amount($in['amount']);
    if (!$o['no_bill']) $o['no_bill_why'] = '';
    return $o;
}

/** What still stops a bill from being sent. Pure. */
function vb_send_blocks(array $b, int $files): array {
    $o = [];
    if ((string)$b['vendor'] === '') $o[] = 'Enter the vendor (who is paid).';
    if ((float)$b['amount'] <= 0) $o[] = 'Enter the amount.';
    if ((string)$b['period'] === '' && empty($b['bill_date'])) $o[] = 'Pick the month the bill is for, or the bill date.';
    if (!$files && empty($b['no_bill'])) $o[] = 'Attach the bill copy, or tick "No bill copy" and say why.';
    if (!empty($b['no_bill']) && (string)$b['no_bill_why'] === '') $o[] = 'Say why there is no bill copy.';
    return $o;
}

/** Next status for a step + decision from $status, or throws. Pure. */
function vb_next(string $step, string $decision, string $status, string $note): string {
    $need = ['check' => 'submitted', 'approve' => 'checked', 'pay' => 'approved'][$step] ?? null;
    if ($need === null) throw new RuntimeException('Unknown step.');
    if ($status !== $need) throw new RuntimeException(['check' => 'This bill is not waiting to be checked.', 'approve' => 'This bill is not waiting for approval.', 'pay' => 'Only an approved bill can be marked paid.'][$step]);
    $map = ['check' => ['ok' => 'checked', 'return' => 'returned'], 'approve' => ['approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected'], 'pay' => ['paid' => 'paid']];
    $to = $map[$step][$decision] ?? null;
    if ($to === null) throw new RuntimeException('Unknown step.');
    if (in_array($decision, ['return', 'reject'], true) && mb_strlen(trim($note)) < 3) throw new InvalidArgumentException($decision === 'reject' ? 'Give the reason (note).' : 'Say what to correct (note).');
    return $to;
}

/** The month whose draft is due for a repeating bill on $today, or ''. Pure. */
function vb_repeat_due(array $r, string $today): string {
    $ym = substr($today, 0, 7);
    if ((string)$r['last_month'] >= $ym) return '';
    return (int)substr($today, 8, 2) >= (int)$r['bill_day'] ? $ym : '';
}

/** "Rent · TEST Estates · Oct 2026". Pure. */
function vb_title(array $b): string {
    $p = (string)($b['period'] ?? '');
    return implode(' · ', array_filter([(string)$b['kind'], (string)$b['vendor'], preg_match('/^\d{4}-\d{2}$/', $p) ? date('M Y', strtotime($p . '-01')) : '']));
}

/** Paid by branch / kind / month and what is still coming. Pure. */
function vb_summary(array $rows): array {
    $s = ['paid' => 0.0, 'coming' => 0.0, 'byBranch' => [], 'byKind' => [], 'byMonth' => []];
    foreach ($rows as $b) {
        $a = (float)$b['amount'];
        if ($b['status'] === 'paid') {
            $s['paid'] += $a;
            $s['byBranch'][(int)$b['branch_id']] = round(($s['byBranch'][(int)$b['branch_id']] ?? 0) + $a, 2);
            $s['byKind'][$b['kind']] = round(($s['byKind'][$b['kind']] ?? 0) + $a, 2);
            $m = substr((string)$b['paid_on'], 0, 7); $s['byMonth'][$m] = round(($s['byMonth'][$m] ?? 0) + $a, 2);
        } elseif (in_array($b['status'], VB_OPEN, true)) $s['coming'] += $a;
    }
    ksort($s['byBranch']); arsort($s['byKind']); ksort($s['byMonth']);
    $s['paid'] = round($s['paid'], 2); $s['coming'] = round($s['coming'], 2);
    return $s;
}

function vb_csv_cell($v): string { $s = (string)$v; if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) $s = "'" . $s; return '"' . str_replace('"', '""', $s) . '"'; }

// ---------- database ----------

function vb_ctx(PDO $db, array $u): array {
    $own = null;
    if (!empty($u['emp_code']) && ($e = emp_by_code($db, (string)$u['emp_code'])) && $e['branch_id']) $own = (int)$e['branch_id'];
    return vb_ctx_for($u, user_roles((int)$u['id'], !empty($u['is_owner'])), vb_settings(), $own, array_column(branches(), 'id'));
}

function vb_log(PDO $db, int $bill, ?int $uid, string $action, string $note = '', array $detail = []): void {
    $db->prepare('INSERT INTO vb_log (bill_id, user_id, action, note, detail) VALUES (?,?,?,?,?)')->execute([$bill, $uid, $action, vb_text($note, 600), $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null]);
}
function vb_next_ref(PDO $db): string {
    $y = (int)date('Y');
    $db->prepare('INSERT INTO vb_seq (yr, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$y]);
    $s = $db->prepare('SELECT n FROM vb_seq WHERE yr = ?'); $s->execute([$y]);
    return sprintf('VB-%d-%04d', $y, (int)$s->fetchColumn());
}
function vb_get(PDO $db, int $id, bool $lock = false): array {
    $q = $db->prepare('SELECT * FROM vb_bill WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')); $q->execute([$id]);
    $b = $q->fetch();
    if (!$b) throw new RuntimeException('Bill not found.');
    return $b;
}

/** $files = [[bytes, name], …] */
function vb_files_add(PDO $db, int $bill, string $kind, array $files, int $uid): int {
    $n = 0;
    foreach ($files as [$bytes, $name]) {
        if ($bytes === '') continue;
        if (strlen($bytes) > VB_FILE_MAX) throw new InvalidArgumentException('Each file: up to 10 MB.');
        if (!($mime = kyc_doc_mime($bytes))) throw new InvalidArgumentException('Files: PDF, JPG, PNG or WebP only.');
        $name = mb_substr(basename(str_replace('\\', '/', (string)$name)), 0, 150) ?: $kind;
        $db->prepare('INSERT INTO vb_file (bill_id, kind, name, mime, size, sha, bytes, user_id) VALUES (?,?,?,?,?,?,?,?)')->execute([$bill, $kind, $name, $mime, strlen($bytes), hash('sha256', $bytes), $bytes, $uid]);
        vb_log($db, $bill, $uid, 'file', '', ['kind' => $kind, 'file' => (int)$db->lastInsertId()]);
        $n++;
    }
    return $n;
}
function vb_files(PDO $db, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $o = [];
    foreach ($db->query('SELECT id, bill_id, kind, name, mime, size, created_at FROM vb_file WHERE bill_id IN (' . implode(',', $ids) . ') ORDER BY id') as $r) $o[(int)$r['bill_id']][] = $r;
    return $o;
}
function vb_file_get(PDO $db, array $c, int $fid): ?array {
    $q = $db->prepare('SELECT f.*, b.entered_by, b.branch_id FROM vb_file f JOIN vb_bill b ON b.id = f.bill_id WHERE f.id = ?'); $q->execute([$fid]);
    $f = $q->fetch();
    if (!$f || !vb_sees($c, $f)) return null;
    audit($c['uid'], 'vendorbill_file', 'bill ' . (int)$f['bill_id'] . ' file ' . $fid);
    return $f;
}

/** Same vendor bill number already in for that branch + vendor → the reason; '' when fine. */
function vb_dup(PDO $db, array $b): string {
    if ((string)$b['vendor_bill_no'] === '') return '';
    $q = $db->prepare("SELECT ref FROM vb_bill WHERE id <> ? AND branch_id = ? AND vendor = ? AND vendor_bill_no = ? AND status NOT IN ('draft','rejected','withdrawn') LIMIT 1");
    $q->execute([(int)$b['id'], (int)$b['branch_id'], $b['vendor'], $b['vendor_bill_no']]);
    $r = $q->fetchColumn();
    return $r ? "Bill no. {$b['vendor_bill_no']} of {$b['vendor']} is already in as $r." : '';
}
/** A second bill of the same kind, vendor and month for the branch — a flag for the checker, never refused. */
function vb_same_period(PDO $db, array $b): string {
    if ((string)$b['period'] === '') return '';
    $q = $db->prepare("SELECT ref FROM vb_bill WHERE id <> ? AND branch_id = ? AND kind = ? AND vendor = ? AND period = ? AND status NOT IN ('draft','rejected','withdrawn') LIMIT 1");
    $q->execute([(int)$b['id'], (int)$b['branch_id'], $b['kind'], $b['vendor'], $b['period']]);
    return (string)($q->fetchColumn() ?: '');
}

/** New bill or change a draft / returned one; $send = send to be checked. */
function vb_save(PDO $db, array $u, int $id, array $in, array $files, bool $send): int {
    $c = vb_ctx($db, $u);
    $d = vb_input($in, $c['enter'], vb_kinds(vb_settings()), date('Y-m-d'));
    if (count($files) > VB_FILES_PER_SEND) throw new InvalidArgumentException('Up to ' . VB_FILES_PER_SEND . ' files at a time.');
    $uid = $c['uid'];
    $db->beginTransaction();
    try {
        if ($id) {
            $b = vb_get($db, $id, true);
            if ((int)$b['entered_by'] !== $uid && !($c['R']['check'] && vb_sees($c, $b))) throw new RuntimeException('You can change only bills you entered.');
            if (!in_array($b['status'], ['draft', 'returned'], true)) throw new RuntimeException('This bill is ' . mb_strtolower(VB_STATUSES[$b['status']] ?? $b['status']) . ' — it cannot be changed now.');
            $db->prepare('UPDATE vb_bill SET branch_id=?, kind=?, vendor=?, vendor_bill_no=?, bill_date=?, period=?, amount=?, due_date=?, pay_to=?, note=?, no_bill=?, no_bill_why=? WHERE id=?')
               ->execute([$d['branch_id'], $d['kind'], $d['vendor'], $d['vendor_bill_no'], $d['bill_date'], $d['period'], $d['amount'], $d['due_date'], $d['pay_to'], $d['note'], $d['no_bill'], $d['no_bill_why'], $id]);
            vb_log($db, $id, $uid, 'edit', '', ['fields' => array_keys($d)]);
        } else {
            $ref = vb_next_ref($db);
            $db->prepare("INSERT INTO vb_bill (ref, branch_id, kind, vendor, vendor_bill_no, bill_date, period, amount, due_date, pay_to, note, no_bill, no_bill_why, status, entered_by, entered_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?,?)")
               ->execute([$ref, $d['branch_id'], $d['kind'], $d['vendor'], $d['vendor_bill_no'], $d['bill_date'], $d['period'], $d['amount'], $d['due_date'], $d['pay_to'], $d['note'], $d['no_bill'], $d['no_bill_why'], $uid, mb_substr($c['name'], 0, 120)]);
            $id = (int)$db->lastInsertId();
            vb_log($db, $id, $uid, 'create', '', ['ref' => $ref]);
        }
        vb_files_add($db, $id, 'bill', $files, $uid);
        if ($send) {
            $b = vb_get($db, $id);
            $n = $db->prepare("SELECT COUNT(*) FROM vb_file WHERE kind = 'bill' AND bill_id = ?"); $n->execute([$id]);
            if ($x = vb_send_blocks($b, (int)$n->fetchColumn())) throw new InvalidArgumentException(implode(' ', $x));
            if ($dup = vb_dup($db, $b)) throw new InvalidArgumentException($dup);
            $db->prepare("UPDATE vb_bill SET status = 'submitted', round = round + 1, submitted_at = NOW() WHERE id = ?")->execute([$id]);
            vb_log($db, $id, $uid, 'send');
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    if ($send) vb_after($db, $id, 'submitted', $uid);
    return $id;
}

function vb_withdraw(PDO $db, array $u, int $id, string $note): void {
    $c = vb_ctx($db, $u); $b = vb_get($db, $id);
    if ((int)$b['entered_by'] !== $c['uid'] && !($c['R']['check'] && vb_sees($c, $b))) throw new RuntimeException('You can withdraw only bills you entered.');
    if (!in_array($b['status'], ['draft', 'submitted', 'returned'], true)) throw new RuntimeException('Only a draft, a returned bill or one still waiting to be checked can be withdrawn.');
    $db->prepare("UPDATE vb_bill SET status = 'withdrawn' WHERE id = ?")->execute([$id]);
    vb_log($db, $id, $c['uid'], 'withdraw', $note);
}

/** check (ok | return) · approve (approve | return | reject) · pay (paid, with paid_on / pay_mode / pay_ref or a slip). */
function vb_decide(PDO $db, array $u, int $id, string $step, string $decision, string $note = '', array $pay = [], array $slip = []): void {
    $c = vb_ctx($db, $u); $uid = $c['uid']; $note = vb_text($note, 600);
    $db->beginTransaction();
    try {
        $b = vb_get($db, $id, true);
        if (!vb_can($c, $step, $b)) throw new RuntimeException((int)$b['entered_by'] === $uid ? 'You cannot decide on a bill you entered.' : 'Your login does not have this step.');
        $to = vb_next($step, $decision, (string)$b['status'], $note);
        if ($step === 'check') $db->prepare('UPDATE vb_bill SET status = ?, check_by = ?, check_at = NOW(), check_note = ? WHERE id = ?')->execute([$to, $uid, $note, $id]);
        elseif ($step === 'approve') $db->prepare('UPDATE vb_bill SET status = ?, approve_by = ?, approve_at = NOW(), approve_note = ? WHERE id = ?')->execute([$to, $uid, $note, $id]);
        else {
            $on = vb_date($pay['paid_on'] ?? '', 'Paid on', false, date('Y-m-d'));
            if ($on === null) throw new InvalidArgumentException('Paid on: pick a date.');
            $mode = vb_text($pay['pay_mode'] ?? '', 60);
            if (!in_array($mode, VB_PAY_MODES, true)) throw new InvalidArgumentException('Pick how it was paid.');
            $ref = vb_text($pay['pay_ref'] ?? '', 120);
            if ($ref === '' && !array_filter($slip, fn($f) => $f[0] !== '')) throw new InvalidArgumentException('Give the transaction reference / cheque no., or attach the payment slip.');
            $db->prepare("UPDATE vb_bill SET status = 'paid', paid_by = ?, paid_at = NOW(), paid_on = ?, pay_mode = ?, pay_ref = ?, pay_note = ? WHERE id = ?")->execute([$uid, $on, $mode, $ref, $note, $id]);
            vb_files_add($db, $id, 'slip', $slip, $uid);
        }
        vb_log($db, $id, $uid, "$step:$decision", $note);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit($uid, 'vendorbill_' . $step . '_' . $decision, 'bill ' . $id);
    vb_after($db, $id, $to, $uid);
}

function vb_add_slip(PDO $db, array $u, int $id, array $slip): void {
    $c = vb_ctx($db, $u); $b = vb_get($db, $id);
    if (!vb_can($c, 'pay', $b)) throw new RuntimeException('Your login does not have this step.');
    if ($b['status'] !== 'paid') throw new RuntimeException('Mark the bill paid first.');
    if (!vb_files_add($db, $id, 'slip', $slip, $c['uid'])) throw new InvalidArgumentException('Choose the payment slip.');
}

/** Logins (with an email) holding a step role who may see this branch, except $skip. */
function vb_people_for(PDO $db, array $roles, int $branch, int $skip): array {
    if (!$roles) return [];
    $s = $db->prepare('SELECT DISTINCT u.id, u.email FROM users u JOIN user_role r ON r.user_id = u.id WHERE u.active = 1 AND u.id <> ? AND r.role_code IN (' . implode(',', array_fill(0, count($roles), '?')) . ')');
    $s->execute([$skip, ...$roles]);
    $o = [];
    foreach ($s->fetchAll() as $p) { $t = user_branch_ids((int)$p['id']); $rl = user_roles((int)$p['id']); if (array_intersect($rl, ['SUPERADMIN', 'CEO']) || !$t || in_array($branch, $t, true)) $o[] = $p; }
    return $o;
}
function vb_mail(PDO $db, array $people, string $subject, string $text): void {
    if (module_on('notify')) { require_once __DIR__ . '/push.php'; foreach ($people as $p) notify_user($db, (int)$p['id'], 'vendorbill', $subject, $text, 'modules/vendorbills/'); }
    if (!module_on('email') || !function_exists('mail_queue')) return;
    foreach ($people as $p) if (filter_var((string)$p['email'], FILTER_VALIDATE_EMAIL)) { try { mail_queue($db, ['to' => $p['email'], 'subject' => $subject, 'text' => $text . "\n\nOpen Vendor bills in " . org('name') . '.'], 'notice', 'vendorbill'); } catch (Throwable $e) {} }
}

/** Tell the next step and the person who entered it. Never throws. */
function vb_after(PDO $db, int $id, string $status, int $by): void {
    try {
        $b = vb_get($db, $id); $set = vb_settings();
        $t = vb_title($b) . ' · ' . number_format((float)$b['amount'], 2) . ' · ' . $b['ref'];
        $me = function () use ($db, $b, $by) { if ((int)$b['entered_by'] === $by) return []; $s = $db->prepare('SELECT id, email FROM users WHERE id = ?'); $s->execute([(int)$b['entered_by']]); return $s->fetchAll(); };
        match ($status) {
            'submitted' => vb_mail($db, vb_people_for($db, $set['check_roles'], (int)$b['branch_id'], (int)$b['entered_by']), 'Vendor bill to check', $t),
            'checked' => vb_mail($db, vb_people_for($db, $set['approve_roles'], (int)$b['branch_id'], (int)$b['entered_by']), 'Vendor bill to approve', $t),
            'approved' => vb_mail($db, vb_people_for($db, $set['pay_roles'], (int)$b['branch_id'], (int)$b['entered_by']), 'Vendor bill to pay', $t . ($b['due_date'] ? ' · due ' . $b['due_date'] : '')),
            'returned' => vb_mail($db, $me(), 'Vendor bill returned for correction', $t),
            'rejected' => vb_mail($db, $me(), 'Vendor bill not approved', $t),
            'paid' => vb_mail($db, $me(), 'Vendor bill paid', $t),
            default => null,
        };
    } catch (Throwable $e) { error_log('vb_after: ' . $e->getMessage()); }
}

function vb_repeat_save(PDO $db, array $u, array $in): int {
    $c = vb_ctx($db, $u);
    $b = (int)($in['branch'] ?? 0);
    if (!in_array($b, $c['enter'], true)) throw new InvalidArgumentException('Pick the branch.');
    $k = vb_text($in['kind'] ?? '', 60);
    if (!in_array($k, vb_kinds(vb_settings()), true)) throw new InvalidArgumentException('Pick what the bill is for.');
    $v = vb_text($in['vendor'] ?? '', 160);
    if ($v === '') throw new InvalidArgumentException('Enter the vendor (who is paid).');
    $day = (int)($in['bill_day'] ?? 1);
    if ($day < 1 || $day > 28) throw new InvalidArgumentException('The bill comes on: a day from 1 to 28.');
    $due = trim((string)($in['due_day'] ?? '')) === '' ? null : (int)$in['due_day'];
    if ($due !== null && ($due < 1 || $due > 28)) throw new InvalidArgumentException('Due on: a day from 1 to 28.');
    $amt = trim((string)($in['amount'] ?? '')) === '' ? null : vb_amount($in['amount']);
    if ((int)$db->query('SELECT COUNT(*) FROM vb_repeat WHERE active = 1')->fetchColumn() >= VB_REPEAT_MAX) throw new RuntimeException('Too many repeating bills.');
    $db->prepare('INSERT INTO vb_repeat (branch_id, kind, vendor, amount, bill_day, due_day, pay_to, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$b, $k, $v, $amt, $day, $due, vb_text($in['pay_to'] ?? '', 300), vb_text($in['note'] ?? '', 600), $c['uid']]);
    $id = (int)$db->lastInsertId();
    audit($c['uid'], 'vendorbill_repeat', '#' . $id);
    return $id;
}
function vb_repeat_stop(PDO $db, array $u, int $id): void {
    $c = vb_ctx($db, $u);
    $q = $db->prepare('SELECT * FROM vb_repeat WHERE id = ? AND active = 1'); $q->execute([$id]); $r = $q->fetch();
    if (!$r) throw new RuntimeException('Not found.');
    if ((int)$r['created_by'] !== $c['uid'] && !($c['R']['check'] && ($c['see'] === null || in_array((int)$r['branch_id'], (array)$c['see'], true)))) throw new RuntimeException('Only the person who set it up or a checker can stop it.');
    $db->prepare('UPDATE vb_repeat SET active = 0, stopped_by = ?, stopped_at = NOW() WHERE id = ?')->execute([$c['uid'], $id]);
}
function vb_repeats(PDO $db, array $c): array {
    $rows = $db->query('SELECT r.*, u.name AS by_name FROM vb_repeat r LEFT JOIN users u ON u.id = r.created_by WHERE r.active = 1 ORDER BY r.branch_id, r.kind, r.vendor')->fetchAll();
    return array_values(array_filter($rows, fn($r) => (int)$r['created_by'] === $c['uid'] || $c['see'] === null || in_array((int)$r['branch_id'], (array)$c['see'], true)));
}

/** This month's drafts of repeating bills (once per bill + month) and the due-soon warning to the pay roles (once). */
function vb_repeat_tick(PDO $db, ?string $today = null): array {
    $today ??= date('Y-m-d'); $made = 0; $told = 0; $set = vb_settings();
    foreach ($db->query('SELECT * FROM vb_repeat WHERE active = 1')->fetchAll() as $r) {
        $ym = vb_repeat_due($r, $today);
        if ($ym === '') continue;
        $id = 0;
        $db->beginTransaction();
        try {
            $up = $db->prepare('UPDATE vb_repeat SET last_month = ? WHERE id = ? AND last_month < ?'); $up->execute([$ym, (int)$r['id'], $ym]);
            if ($up->rowCount() !== 1) { $db->rollBack(); continue; }
            $due = $r['due_day'] !== null ? sprintf('%s-%02d', $ym, (int)$r['due_day']) : null;
            $nm = $db->prepare('SELECT name FROM users WHERE id = ?'); $nm->execute([(int)$r['created_by']]);
            $ins = $db->prepare("INSERT IGNORE INTO vb_bill (ref, branch_id, kind, vendor, period, amount, due_date, pay_to, note, status, repeat_id, entered_by, entered_name) VALUES (?,?,?,?,?,?,?,?,?,'draft',?,?,?)");
            $ins->execute([vb_next_ref($db), (int)$r['branch_id'], $r['kind'], $r['vendor'], $ym, $r['amount'] ?? 0, $due, $r['pay_to'], $r['note'], (int)$r['id'], (int)$r['created_by'], mb_substr((string)$nm->fetchColumn(), 0, 120)]);
            if ($ins->rowCount() === 1) { $id = (int)$db->lastInsertId(); vb_log($db, $id, null, 'repeat', 'Made from the repeating bill'); $made++; }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); error_log('vb_repeat_tick: ' . $e->getMessage()); continue; }
        if ($id) { $s = $db->prepare('SELECT id, email FROM users WHERE id = ?'); $s->execute([(int)$r['created_by']]); vb_mail($db, $s->fetchAll(), 'Vendor bill to fill in', $r['kind'] . ' · ' . $r['vendor'] . ' · ' . date('M Y', strtotime($ym . '-01')) . ' — add the amount and bill copy, then send.'); }
    }
    $q = $db->prepare("SELECT * FROM vb_bill WHERE status = 'approved' AND due_date IS NOT NULL AND due_date <= ? AND due_told IS NULL");
    $q->execute([date('Y-m-d', strtotime($today . ' +' . (int)$set['due_warn_days'] . ' days'))]);
    foreach ($q->fetchAll() as $b) {
        $up = $db->prepare('UPDATE vb_bill SET due_told = ? WHERE id = ? AND due_told IS NULL'); $up->execute([$today, (int)$b['id']]);
        if ($up->rowCount() !== 1) continue;
        vb_mail($db, vb_people_for($db, $set['pay_roles'], (int)$b['branch_id'], (int)$b['entered_by']), ((string)$b['due_date'] < $today ? 'Vendor bill overdue' : 'Vendor bill due ' . $b['due_date']), vb_title($b) . ' · ' . number_format((float)$b['amount'], 2) . ' · ' . $b['ref']);
        $told++;
    }
    return [$made, $told];
}

/** Bills for a screen. $who: mine | check | approve | pay | all. */
function vb_list(PDO $db, array $c, string $who, array $f = []): array {
    $w = []; $p = [];
    switch ($who) {
        case 'mine': $w[] = 'b.entered_by = ?'; $p[] = $c['uid']; break;
        case 'check': $w[] = "b.status = 'submitted'"; break;
        case 'approve': $w[] = "b.status = 'checked'"; break;
        case 'pay': $w[] = "b.status = 'approved'"; break;
        default: $w[] = "b.status <> 'draft'";
    }
    if ((int)($f['branch'] ?? 0)) { $w[] = 'b.branch_id = ?'; $p[] = (int)$f['branch']; }
    if (isset(VB_STATUSES[$f['status'] ?? ''])) { $w[] = 'b.status = ?'; $p[] = $f['status']; }
    if (!empty($f['from'])) { $w[] = 'COALESCE(b.paid_on, b.bill_date, DATE(b.created_at)) >= ?'; $p[] = $f['from']; }
    if (!empty($f['to'])) { $w[] = 'COALESCE(b.paid_on, b.bill_date, DATE(b.created_at)) <= ?'; $p[] = $f['to']; }
    $q = $db->prepare('SELECT b.*, (SELECT name FROM users WHERE id = b.check_by) AS check_name, (SELECT name FROM users WHERE id = b.approve_by) AS approve_name, (SELECT name FROM users WHERE id = b.paid_by) AS paid_name FROM vb_bill b WHERE '
        . implode(' AND ', $w) . ' ORDER BY ' . ($who === 'pay' ? 'b.due_date IS NULL, b.due_date, b.id' : 'b.id DESC') . ' LIMIT 1000');
    $q->execute($p);
    $rows = array_values(array_filter($q->fetchAll(), fn($b) => vb_sees($c, $b)));
    if (in_array($who, ['check', 'approve', 'pay'], true)) $rows = array_values(array_filter($rows, fn($b) => vb_can($c, $who, $b)));
    return $rows;
}
function vb_history(PDO $db, int $id): array { $q = $db->prepare('SELECT l.*, u.name AS who FROM vb_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.bill_id = ? ORDER BY l.id'); $q->execute([$id]); return $q->fetchAll(); }

/** What waits for this login. */
function vb_waiting(PDO $db, array $c): array {
    $o = ['check' => 0, 'approve' => 0, 'pay' => 0, 'returned' => 0, 'drafts' => 0];
    foreach ($db->query("SELECT id, branch_id, status, entered_by FROM vb_bill WHERE status IN ('submitted','checked','approved','returned','draft')") as $b) {
        if ((int)$b['entered_by'] === $c['uid']) { if ($b['status'] === 'returned') $o['returned']++; if ($b['status'] === 'draft') $o['drafts']++; continue; }
        foreach (['submitted' => 'check', 'checked' => 'approve', 'approved' => 'pay'] as $st => $step) if ($b['status'] === $st && vb_can($c, $step, $b)) $o[$step]++;
    }
    return $o;
}

/** Paid (in range) and coming bills by branch, for the day book. Read only; empty when the tables are not there. */
function vb_branch_lines(PDO $db, ?array $branches, string $from, string $to, int $branch = 0): array {
    $o = ['paid' => [], 'coming' => []];
    try {
        $q = $db->prepare("SELECT * FROM vb_bill WHERE ((status = 'paid' AND paid_on BETWEEN ? AND ?) OR status IN ('submitted','checked','approved'))" . ($branch ? ' AND branch_id = ?' : '') . ' ORDER BY id');
        $q->execute([$from, $to, ...($branch ? [$branch] : [])]);
        foreach ($q->fetchAll() as $b) {
            if ($branches !== null && !in_array((int)$b['branch_id'], $branches, true)) continue;
            $o[$b['status'] === 'paid' ? 'paid' : 'coming'][] = $b;
        }
    } catch (Throwable $e) {}
    return $o;
}

function vb_vendors(PDO $db): array {
    return array_map('strval', $db->query("SELECT DISTINCT vendor FROM vb_bill WHERE vendor <> '' UNION SELECT DISTINCT vendor FROM vb_repeat WHERE vendor <> '' ORDER BY 1 LIMIT 2000")->fetchAll(PDO::FETCH_COLUMN));
}

function vb_csv(array $rows, array $bn): string {
    $line = fn(array $c) => implode(',', array_map('vb_csv_cell', $c)) . "\r\n";
    $o = $line(['Ref', 'Branch', 'Kind', 'Vendor', 'Vendor bill no.', 'Bill date', 'Month', 'Amount', 'Due', 'Status', 'Paid on', 'Mode', 'Reference', 'Entered by']);
    foreach ($rows as $b) $o .= $line([$b['ref'], $bn[(int)$b['branch_id']] ?? '', $b['kind'], $b['vendor'], $b['vendor_bill_no'], $b['bill_date'], $b['period'], number_format((float)$b['amount'], 2, '.', ''), $b['due_date'],
        VB_STATUSES[$b['status']] ?? $b['status'], $b['paid_on'], $b['pay_mode'], $b['pay_ref'], $b['entered_name']]);
    return $o;
}
