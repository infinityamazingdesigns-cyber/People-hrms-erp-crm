<?php
/**
 * Payroll part 2 — loans and advances, payslips with a PIN, bank transfer file.
 *
 * Rules (ported from the reference build, organisation lists turned into settings):
 *  - Loans / advances: amount, monthly recovery (EMI) from a month; each draft month deducts min(EMI, what is left);
 *    recoveries are written when the month is confirmed, per snapshot version (a reopened month's newer version
 *    counts). A loan is closed with a reason, never deleted. Never for one's own code.
 *  - Payslips: each login linked to an employee sees only their own payslips for months released, read from the
 *    locked snapshot (never worked out again). A held salary's slip is not shown until the hold is lifted and the month
 *    released again. Who sees their own: everyone (default) or only people the Super Admin allows (Admin → Modules,
 *    per-person allow). Opened with the person's own 4-digit PIN (set on the first visit; no repeated or running
 *    digits), asked again after 10 minutes or when the screen is left; 5 wrong → locked 15 minutes; a Super Admin
 *    resets a forgotten PIN (never their own). Only a password hash of the PIN is kept; every look is audited.
 *  - HR with pay data may preview anyone's slip (never their own through the HR route).
 *  - Bank file: released months only, people paid by bank, not on hold, with a verified bank account on KYC; others
 *    are listed, never guessed. Needs pay and bank data. Account numbers are unsealed only for the download; the audit
 *    line carries counts only.
 */
declare(strict_types=1);
require_once __DIR__ . '/payroll.php';
require_once __DIR__ . '/kyc.php';

const PS_PIN_TTL = 600;
const PS_PIN_TRIES = 5;
const PS_DEFAULTS = ['who' => 'everyone'];
const BANK_FORMATS = ['generic' => 'Generic CSV (name, account, bank code, amount, reference)', 'neft' => 'Account, IFSC, name, amount, narration'];

/* ---------------- pure ---------------- */

/** Recovery due this month for a loan. Pure. */
function pay_loan_due(array $loan, float $recoveredBefore, string $ym): float {
    if ($loan['from_ym'] > $ym || !empty($loan['closed_at'])) return 0.0;
    return max(0.0, round(min((float)$loan['emi'], (float)$loan['amount'] - $recoveredBefore), 2));
}

/** A PIN that is too easy (all the same digit, a running sequence) or not 4 digits → reason; null = fine. Pure. */
function ps_pin_problem(string $pin): ?string {
    if (!preg_match('/^\d{4}$/', $pin)) return 'Use exactly 4 digits.';
    if (count(array_unique(str_split($pin))) === 1) return 'Do not use the same digit four times.';
    $d = array_map('intval', str_split($pin));
    $up = $down = true;
    for ($i = 1; $i < 4; $i++) { $up = $up && $d[$i] === ($d[$i - 1] + 1) % 10; $down = $down && $d[$i] === ($d[$i - 1] + 9) % 10; }
    return $up || $down ? 'Do not use running digits like 1234.' : null;
}

/** May this payslip line be shown to its owner? Released month, not held. Pure. */
function ps_line_visible(array $month, array $line): bool {
    return $month['status'] === 'released' && empty($line['hold']) && empty($line['missing_ctc']);
}

/** Bank file rows: [name, account, code, amount, ref] + the people left out with why. Pure. */
function bank_rows(array $lines, array $bank, string $ym, string $org): array {
    $ok = []; $out = [];
    foreach ($lines as $l) {
        $why = !empty($l['missing_ctc']) ? 'No CTC' : (!empty($l['hold']) ? 'On hold' : (($l['pay_mode'] ?? 'bank') !== 'bank' ? 'Paid by ' . ($l['pay_mode'] ?? '') : ($l['net'] <= 0 ? 'Nothing to pay' : '')));
        $b = $bank[strtoupper($l['emp_code'])] ?? null;
        if ($why === '' && (!$b || empty($b['acct']) || empty($b['code']))) $why = 'No verified bank account';
        if ($why !== '') { $out[] = [$l['emp_code'], $l['name'], $why]; continue; }
        $ok[] = ['name' => $b['holder'] ?: $l['name'], 'acct' => $b['acct'], 'code' => $b['code'], 'amount' => round((float)$l['net'], 2),
                 'ref' => substr(preg_replace('/[^A-Za-z0-9 ]/', '', 'SALARY ' . date('M Y', strtotime($ym . '-01')) . ' ' . $l['emp_code']), 0, 30), 'code_emp' => $l['emp_code']];
    }
    return ['rows' => $ok, 'left_out' => $out, 'total' => round(array_sum(array_column($ok, 'amount')), 2)];
}

/** One CSV line; text never starts a formula; commas / quotes cleaned for bank uploads. Pure. */
function bank_csv_line(array $cells): string {
    return implode(',', array_map(function ($c) {
        $c = trim(str_replace(["\r", "\n", '"', ','], ' ', (string)$c));
        return preg_match('/^[=+\-@]/', $c) ? "'" . $c : $c;
    }, $cells)) . "\r\n";
}

/* ---------------- loans ---------------- */

function pay_loans(PDO $db, ?string $code = null, bool $openOnly = false): array {
    $q = 'SELECT l.*, e.name FROM pay_loan l LEFT JOIN employee e ON e.code = l.emp_code WHERE 1' . ($code !== null ? ' AND l.emp_code = ?' : '') . ($openOnly ? ' AND l.closed_at IS NULL' : '') . ' ORDER BY l.id DESC';
    $s = $db->prepare($q); $s->execute($code !== null ? [$code] : []);
    return $s->fetchAll();
}

/** Recovered before a month: counted from each confirmed month's current snapshot version. */
function pay_loan_recovered(PDO $db, int $loanId, string $beforeYm): float {
    $s = $db->prepare("SELECT COALESCE(SUM(r.amount), 0) FROM pay_loan_rec r JOIN pay_month m ON m.ym = r.ym AND m.ver = r.ver WHERE r.loan_id = ? AND r.ym < ? AND m.status IN ('confirmed','released')");
    $s->execute([$loanId, $beforeYm]);
    return (float)$s->fetchColumn();
}

/** Loan recoveries due for a month: code => [loan id => amount]. */
function pay_loan_dues(PDO $db, string $ym): array {
    $o = [];
    foreach (pay_loans($db, null, true) as $l) if (($d = pay_loan_due($l, pay_loan_recovered($db, (int)$l['id'], $ym), $ym)) > 0) $o[strtoupper($l['emp_code'])][(int)$l['id']] = $d;
    return $o;
}

function pay_loan_add(PDO $db, array $u, array $in): int {
    if (!pay_can($u)) throw new RuntimeException('Not allowed.');
    $e = emp_by_code($db, strtoupper(trim((string)($in['code'] ?? ''))));
    if (!$e || !emp_in_scope($u, $e)) throw new RuntimeException('Pick the person.');
    if (strcasecmp($e['code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Not for your own pay.');
    $amt = (float)($in['amount'] ?? 0); $emi = (float)($in['emi'] ?? 0);
    if ($amt <= 0 || $amt > 100000000) throw new InvalidArgumentException('Give the amount.');
    if ($emi <= 0 || $emi > $amt) throw new InvalidArgumentException('Monthly recovery: more than zero and not above the amount.');
    $from = (string)($in['from_ym'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $from)) throw new InvalidArgumentException('Pick the first month of recovery.');
    if (in_array(pay_month_row($db, $from)['status'], ['confirmed', 'released'], true)) throw new RuntimeException('That month is confirmed — start from a later month.');
    $kind = ($in['kind'] ?? '') === 'advance' ? 'advance' : 'loan';
    $db->prepare('INSERT INTO pay_loan (emp_code, kind, amount, emi, from_ym, note, by_user) VALUES (?,?,?,?,?,?,?)')->execute([$e['code'], $kind, round($amt, 2), round($emi, 2), $from, mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 300), (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    audit((int)$u['id'], 'pay_loan_add', '#' . $id . ' ' . $e['code']);
    return $id;
}

function pay_loan_close(PDO $db, array $u, int $id, string $reason): void {
    if (!pay_can($u)) throw new RuntimeException('Not allowed.');
    if (trim($reason) === '') throw new InvalidArgumentException('Give a reason.');
    $s = $db->prepare('UPDATE pay_loan SET closed_at = NOW(), closed_by = ?, close_reason = ? WHERE id = ? AND closed_at IS NULL');
    $s->execute([(int)$u['id'], mb_substr(trim(strip_tags($reason)), 0, 300), $id]);
    if (!$s->rowCount()) throw new RuntimeException('That loan is already closed.');
    audit((int)$u['id'], 'pay_loan_close', '#' . $id);
}

/** Write recoveries for a month's snapshot version (called inside the confirm transaction). */
function pay_loan_record(PDO $db, string $ym, int $ver, array $rows): void {
    $ins = $db->prepare('INSERT INTO pay_loan_rec (loan_id, ym, ver, amount) VALUES (?,?,?,?)');
    foreach ($rows as $r) foreach ((array)($r['loans'] ?? []) as $lid => $amt) if ($amt > 0) $ins->execute([(int)$lid, $ym, $ver, $amt]);
}

/* ---------------- payslips and PIN ---------------- */

function ps_settings(): array { return array_replace(PS_DEFAULTS, array_intersect_key(module_states()['payslips']['settings'] ?? [], PS_DEFAULTS)); }

/** May this login see its own payslips at all? */
function ps_own_open(array $u): bool {
    if (empty($u['emp_code']) || !module_on('payslips')) return false;
    $p = module_person((int)$u['id'], 'payslips');
    if ($p === 'deny') return false;
    return ps_settings()['who'] === 'everyone' || $p === 'allow';
}

/** The login's released months with its line. */
function ps_own_months(PDO $db, string $code): array {
    $s = $db->prepare("SELECT m.*, s.data FROM pay_month m JOIN pay_snapshot s ON s.ym = m.ym AND s.ver = m.ver AND s.emp_code = ? WHERE m.status = 'released' ORDER BY m.ym DESC");
    $s->execute([$code]);
    $o = [];
    foreach ($s as $r) { $line = json_decode($r['data'], true); if (ps_line_visible($r, $line)) $o[$r['ym']] = $line; }
    return $o;
}

function ps_pin_row(PDO $db, int $uid): ?array { $s = $db->prepare('SELECT * FROM pay_pin WHERE user_id = ?'); $s->execute([$uid]); return $s->fetch() ?: null; }
function ps_pin_log(PDO $db, int $uid, string $what, ?int $by = null): void { $db->prepare('INSERT INTO pay_pin_log (user_id, what, by_user) VALUES (?,?,?)')->execute([$uid, $what, $by]); }

function ps_pin_set(PDO $db, int $uid, string $pin, string $again): void {
    if (ps_pin_row($db, $uid)) throw new RuntimeException('Your PIN is already set. Ask the Super Admin to reset it if you forgot it.');
    if ($p = ps_pin_problem($pin)) throw new InvalidArgumentException($p);
    if (!hash_equals($pin, $again)) throw new InvalidArgumentException('The two PINs are not the same.');
    $db->prepare('INSERT INTO pay_pin (user_id, pin_hash) VALUES (?,?)')->execute([$uid, password_hash($pin, PASSWORD_DEFAULT)]);
    ps_pin_log($db, $uid, 'set', $uid);
    ps_pin_open_mark();
}

function ps_pin_check(PDO $db, int $uid, string $pin): void {
    $r = ps_pin_row($db, $uid);
    if (!$r) throw new RuntimeException('Set your PIN first.');
    if ($r['locked_until'] && strtotime((string)$r['locked_until']) > time()) throw new RuntimeException('Too many wrong PINs. Try again after ' . date('H:i', strtotime((string)$r['locked_until'])) . '.');
    if (!password_verify($pin, (string)$r['pin_hash'])) {
        usleep(300000);
        $w = (int)$r['wrong'] + 1;
        $db->prepare('UPDATE pay_pin SET wrong = ?, locked_until = ? WHERE user_id = ?')->execute([$w >= PS_PIN_TRIES ? 0 : $w, $w >= PS_PIN_TRIES ? date('Y-m-d H:i:s', time() + 900) : null, $uid]);
        ps_pin_log($db, $uid, $w >= PS_PIN_TRIES ? 'locked' : 'wrong', $uid);
        throw new RuntimeException($w >= PS_PIN_TRIES ? 'Too many wrong PINs. Locked for 15 minutes.' : 'That PIN is not right. ' . (PS_PIN_TRIES - $w) . ' tries left.');
    }
    $db->prepare('UPDATE pay_pin SET wrong = 0, locked_until = NULL WHERE user_id = ?')->execute([$uid]);
    ps_pin_open_mark();
}

function ps_pin_open_mark(): void { start_session(); $_SESSION['ps_open'] = time(); }
function ps_pin_is_open(): bool { start_session(); return isset($_SESSION['ps_open']) && time() - (int)$_SESSION['ps_open'] < PS_PIN_TTL; }
function ps_pin_close(): void { start_session(); unset($_SESSION['ps_open']); }

function ps_pin_reset(PDO $db, array $actor, int $uid): void {
    if (!is_superadmin($actor)) throw new RuntimeException('Only the Super Admin resets a payslip PIN.');
    if ($uid === (int)$actor['id']) throw new RuntimeException('Not for your own PIN.');
    $s = $db->prepare('SELECT * FROM users WHERE id = ?'); $s->execute([$uid]); $t = $s->fetch();
    if (!$t) throw new RuntimeException('Login not found.');
    if ($e = owner_guard($actor, $t, 'edit')) throw new RuntimeException($e);
    $db->prepare('DELETE FROM pay_pin WHERE user_id = ?')->execute([$uid]);   // a credential: the person sets a new one; pay_pin_log keeps the history
    ps_pin_log($db, $uid, 'reset', (int)$actor['id']);
    audit((int)$actor['id'], 'pay_pin_reset', '#' . $uid);
}

/* ---------------- bank file ---------------- */

/** Verified bank details of the people in a month (unsealed — only for the download). */
function bank_details(PDO $db, array $codes): array {
    $o = [];
    foreach ($codes as $c) {
        $k = kyc_record($db, (string)$c, true);
        $v = $k['values']; $ver = $k['verified'];
        if (!empty($ver['bank_acct']) && !empty($ver['bank_code'])) $o[strtoupper((string)$c)] = ['acct' => (string)($v['bank_acct'] ?? ''), 'code' => (string)($v['bank_code'] ?? ''), 'holder' => (string)($v['bank_holder'] ?? '')];
    }
    return $o;
}

/** Counts and the people left out, from verification flags only (nothing unsealed). */
function bank_preview(PDO $db, array $rows, string $ym): array {
    $ready = [];
    foreach ($rows as $r) { $v = kyc_record($db, (string)$r['emp_code'])['verified']; if (!empty($v['bank_acct']) && !empty($v['bank_code'])) $ready[strtoupper((string)$r['emp_code'])] = ['acct' => '-', 'code' => '-', 'holder' => '']; }
    return bank_rows($rows, $ready, $ym, '');
}

function bank_can(array $u): bool { return pay_can($u) && data_allowed($u, 'bank'); }

/** One payslip as HTML from a locked line. Pure apart from org() wording. */
function ps_render(array $l, string $ym): string {
    $M = fn($v) => h(pay_money((float)$v));
    $rowsE = ''; foreach ((array)$l['earned'] as $k => $v) $rowsE .= '<tr><td>' . h($k) . '</td><td class="n">' . $M($l['parts'][$k] ?? 0) . '</td><td class="n">' . $M($v) . '</td></tr>';
    if (!empty($l['other_earn'])) $rowsE .= '<tr><td>Other earnings</td><td></td><td class="n">' . $M($l['other_earn']) . '</td></tr>';
    if (!empty($l['arrear']) && $l['arrear'] > 0) $rowsE .= '<tr><td>Arrears</td><td></td><td class="n">' . $M($l['arrear']) . '</td></tr>';
    $rowsD = ''; foreach ((array)$l['deductions'] as $k => $v) $rowsD .= '<tr><td>' . h($k) . '</td><td class="n">' . $M($v) . '</td></tr>';
    $rowsR = ''; foreach ((array)$l['employer'] as $k => $v) $rowsR .= '<tr><td>' . h($k) . '</td><td class="n">' . $M($v) . '</td></tr>';
    return '<div class="slip"><div class="sh"><b>' . h(org('legal_name') ?: org('name')) . '</b><small>' . h(org('address')) . '</small><h2>Payslip · ' . h(date('F Y', strtotime($ym . '-01'))) . '</h2></div>'
        . '<table class="kv"><tr><td>Name</td><td>' . h($l['name']) . '</td><td>Code</td><td>' . h($l['emp_code']) . '</td></tr><tr><td>Department</td><td>' . h((string)($l['department'] ?? '')) . '</td><td>Paid days</td><td>' . h((string)$l['paid_days']) . ' of ' . (int)$l['days'] . '</td></tr></table>'
        . '<div class="two"><table><thead><tr><th>Earnings</th><th class="n">Monthly</th><th class="n">This month</th></tr></thead><tbody>' . $rowsE . '<tr class="t"><td>Gross</td><td class="n">' . $M($l['gross_full']) . '</td><td class="n">' . $M($l['gross']) . '</td></tr></tbody></table>'
        . '<table><thead><tr><th>Deductions</th><th class="n">Amount</th></tr></thead><tbody>' . ($rowsD ?: '<tr><td colspan="2">None</td></tr>') . '<tr class="t"><td>Total</td><td class="n">' . $M(array_sum((array)$l['deductions'])) . '</td></tr></tbody></table></div>'
        . '<p class="net">Net pay: <b>' . $M($l['net']) . '</b></p>'
        . ($rowsR ? '<table class="er"><thead><tr><th>Paid by the employer (not deducted)</th><th class="n">Amount</th></tr></thead><tbody>' . $rowsR . '</tbody></table>' : '')
        . '<p class="foot">Made by the payroll system from the locked month. No signature needed.</p></div>';
}

const PS_CSS = '.slip{background:#fff;color:#111;border:1px solid #ccd;border-radius:10px;padding:20px;max-width:820px;margin:0 auto;font:14px/1.5 Arial,sans-serif}.slip .sh{border-bottom:2px solid #13265C;padding-bottom:8px;margin-bottom:10px}.slip .sh small{display:block;color:#555}.slip h2{margin:6px 0 0;font-size:18px}.slip table{width:100%;border-collapse:collapse;margin:8px 0}.slip td,.slip th{padding:4px 6px;border-bottom:1px solid #eee;text-align:left}.slip .n{text-align:right}.slip .two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.slip .t td{font-weight:bold}.slip .net{font-size:17px;text-align:right}.slip .foot{color:#666;font-size:12px}@media(max-width:640px){.slip .two{grid-template-columns:1fr}}@media print{body *{visibility:hidden}.slip,.slip *{visibility:visible}.slip{position:absolute;top:0;left:0;right:0;border:0}}';
