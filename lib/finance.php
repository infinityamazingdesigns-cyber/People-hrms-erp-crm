<?php
/**
 * Finance — the organisation's own books (ported from the reference build's Finance steps 1–2, logic only).
 *
 * Setup: tax registrations (number checked by the country rule pack's tax-ID rule; a registration may wait for its
 * number), bank accounts (number sealed, last 4 shown; a collection account may settle into another), the Finance team
 * (head / manager / executive / auditor) and the voucher approval rule (amount limit + approver, from a date; earlier
 * rules kept).
 * Books: account groups seeded once, ledgers with an opening balance, vouchers (payment / receipt / contra / journal)
 * with insert-only entry lines, maker-checker on the approval rule, numbers only at posting (<PREFIX>/<year>/0001 per
 * type and financial year), reversal (never an edit of a posted voucher), books start and period lock, restricted
 * ledgers seen only by the roles in settings, day book, ledger statement, trial balance, overview.
 *
 * Rules:
 *  - Money is whole minor units (BIGINT), never float. Every posted voucher balances (Dr = Cr > 0).
 *  - Nobody decides a voucher they made. Only setup roles change the setup, the lock, restricted ticks.
 *  - Nothing is deleted: rows are switched off with a reason; fin_log is append-only (field names, never numbers).
 * Pure functions first, then the database half.
 */
declare(strict_types=1);
require_once __DIR__ . '/crm.php';   // crm_pack(), crm_tax_rule(), crm_tax_label()

const FIN_ROLES = ['head' => 'Accounts head', 'manager' => 'Accounts manager', 'exec' => 'Accounts executive', 'audit' => 'Auditor (view only)'];
const FIN_BANK_KINDS = ['current' => 'Current account', 'virtual' => 'Collection account', 'od' => 'Overdraft / cash credit', 'savings' => 'Savings', 'salary' => 'Salary account', 'other' => 'Other'];
const FIN_NATURES = ['asset' => 'Assets', 'liability' => 'Liabilities', 'income' => 'Income', 'expense' => 'Expenses'];
const FIN_VTYPES = ['payment' => ['Payment', 'PMT'], 'receipt' => ['Receipt', 'RCT'], 'contra' => ['Contra', 'CTR'], 'journal' => ['Journal', 'JV']];
const FIN_VSTATUS = ['draft' => 'Draft', 'waiting' => 'Waiting for approval', 'posted' => 'Posted'];
const FIN_MAX_LINES = 60;
const FIN_DEFAULTS = ['setup_roles' => ['SUPERADMIN'], 'restricted_roles' => ['SUPERADMIN', 'CEO'], 'team_role' => 'ACCOUNTS'];
/** Seeded groups: [name, nature, parent name or '', kind ('bank' | 'cash' | 'tax' | '')]. Parents come first. */
const FIN_GROUP_SEED = [
    ['Capital Account', 'liability', '', ''], ['Reserves & Surplus', 'liability', 'Capital Account', ''],
    ['Loans (Liability)', 'liability', '', ''], ['Bank OD Accounts', 'liability', 'Loans (Liability)', 'bank'],
    ['Secured Loans', 'liability', 'Loans (Liability)', ''], ['Unsecured Loans', 'liability', 'Loans (Liability)', ''],
    ['Current Liabilities', 'liability', '', ''], ['Duties & Taxes', 'liability', 'Current Liabilities', 'tax'],
    ['Provisions', 'liability', 'Current Liabilities', ''], ['Sundry Creditors', 'liability', 'Current Liabilities', ''],
    ['Suspense Account', 'liability', '', ''],
    ['Fixed Assets', 'asset', '', ''], ['Investments', 'asset', '', ''], ['Current Assets', 'asset', '', ''],
    ['Bank Accounts', 'asset', 'Current Assets', 'bank'], ['Cash-in-Hand', 'asset', 'Current Assets', 'cash'],
    ['Deposits (Asset)', 'asset', 'Current Assets', ''], ['Loans & Advances (Asset)', 'asset', 'Current Assets', ''],
    ['Stock-in-Hand', 'asset', 'Current Assets', ''], ['Sundry Debtors', 'asset', 'Current Assets', ''],
    ['Sales Accounts', 'income', '', ''], ['Direct Incomes', 'income', '', ''], ['Indirect Incomes', 'income', '', ''],
    ['Purchase Accounts', 'expense', '', ''], ['Direct Expenses', 'expense', '', ''], ['Indirect Expenses', 'expense', '', ''],
];

/* ------------------------------------------------------------------ settings and rights (pure) */

function fin_settings(): array {
    $s = module_states()['finance']['settings'] ?? [];
    return array_replace(FIN_DEFAULTS, array_intersect_key($s, FIN_DEFAULTS));
}
function fin_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['setup_roles' => 'changing the setup', 'restricted_roles' => 'seeing restricted ledgers'] as $k => $l) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = "Pick at least one role for $l.";
    }
    if (!in_array('SUPERADMIN', $o['setup_roles'] ?? [], true)) $o['setup_roles'][] = 'SUPERADMIN';
    $t = (string)($in['team_role'] ?? '');
    $o['team_role'] = $t === '' || isset($roles[$t]) ? $t : 'ACCOUNTS';
    return [$o, $e];
}

/**
 * Rights from the login's role codes, its Finance team role (or '') and the settings. Pure.
 * A holder of the settings' team role with no Finance team row works as an executive.
 */
function fin_rights_from(array $codes, string $finRole, array $set): array {
    $setup = (bool)array_intersect($codes, (array)$set['setup_roles']);
    $role = isset(FIN_ROLES[$finRole]) ? $finRole : (($set['team_role'] ?? '') !== '' && in_array($set['team_role'], $codes, true) ? 'exec' : '');
    $boss = in_array($role, ['head', 'manager'], true);
    return [
        'view' => true, 'setup' => $setup, 'role' => $role,
        'sens' => (bool)array_intersect($codes, (array)$set['restricted_roles']),
        'enter' => $setup || $boss || $role === 'exec',
        'post' => $setup || $boss,      // posts below the limit, checks an executive's voucher, reverses
        'masters' => $setup || $boss,   // groups, ledgers, openings
        'lock' => $setup,
    ];
}

/* ------------------------------------------------------------------ pure helpers */

/** "25,000.50" / "₹ 25000" → minor units; null when not a non-negative amount with at most 2 decimals. Pure. */
function fin_minor(string $s): ?int {
    $s = preg_replace('/[\s,\x{00A0}₹$£€]|AED/u', '', trim($s));
    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s)) return null;
    [$r, $p] = array_pad(explode('.', $s, 2), 2, '');
    return (int)$r * 100 + (int)str_pad($p, 2, '0');
}
/** 2550050 → "25,500.50"; Indian grouping when $indian. Pure. */
function fin_amt(int $minor, bool $indian = false): string {
    $neg = $minor < 0; $minor = abs($minor);
    $r = (string)intdiv($minor, 100); $p = str_pad((string)($minor % 100), 2, '0', STR_PAD_LEFT);
    if ($indian && strlen($r) > 3) $r = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($r, 0, -3)) . ',' . substr($r, -3);
    elseif (!$indian) $r = number_format((int)$r);
    return ($neg ? '-' : '') . $r . '.' . $p;
}
function fin_money(int $minor): string { return fin_amt($minor, org('currency') === 'INR'); }
/** Signed minor units as "1,250.00 Dr" / "Cr". Pure. */
function fin_drcr(int $p, bool $indian = false): string { return $p === 0 ? '0.00' : fin_amt(abs($p), $indian) . ($p > 0 ? ' Dr' : ' Cr'); }
function fin_date_ok(string $d): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)); }
/** Financial year label: '26-27' for 2026-11-13 with an April start; '2026' with a January start. Pure. */
function fin_fy(string $date, int $start): string {
    $y = (int)substr($date, 0, 4); $m = (int)substr($date, 5, 2);
    if ($start <= 1) return (string)$y;
    $a = $m >= $start ? $y : $y - 1;
    return sprintf('%02d-%02d', $a % 100, ($a + 1) % 100);
}
function fin_fy_start(string $date, int $start): string {
    $start = max(1, $start); $y = (int)substr($date, 0, 4); $m = (int)substr($date, 5, 2);
    return sprintf('%04d-%02d-01', $m >= $start ? $y : $y - 1, $start);
}
function fin_vno(string $vtype, string $fy, int $n): string { return (FIN_VTYPES[$vtype][1] ?? 'V') . '/' . $fy . '/' . str_pad((string)$n, 4, '0', STR_PAD_LEFT); }
function fin_acct_ok(string $a): bool { return (bool)preg_match('/^[A-Z0-9]{6,34}$/', $a); }

/** The approval rule in force on a date (newest from_date ≤ date; rows newest first or not). Pure. */
function fin_rule_on(array $rules, string $date): ?array {
    $best = null;
    foreach ($rules as $r) if ($r['from_date'] <= $date && (!$best || [$r['from_date'], (int)$r['id']] > [$best['from_date'], (int)$best['id']])) $best = $r;
    return $best;
}
/** At or above the limit, or no rule at all, needs the approver. Pure. */
function fin_needs_approval(?array $rule, int $total): bool { return !$rule || $total >= (int)$rule['limit_minor']; }

/**
 * Check a voucher's lines. $lines: [[ledger_id, dr, cr, note]], $L: ledger_id => ['kind', 'active'].
 * Returns [errors[], total]. Pure.
 */
function fin_voucher_check(string $vtype, array $lines, array $L, bool $indian = false): array {
    $err = []; $dr = 0; $cr = 0; $cashDr = 0; $cashCr = 0; $other = 0;
    if (!isset(FIN_VTYPES[$vtype])) $err[] = 'Pick a voucher type.';
    if (count($lines) < 2) $err[] = 'A voucher needs at least two lines.';
    if (count($lines) > FIN_MAX_LINES) $err[] = 'At most ' . FIN_MAX_LINES . ' lines.';
    foreach ($lines as $i => $ln) {
        [$lid, $d, $c] = [(int)$ln[0], (int)$ln[1], (int)$ln[2]];
        $n = $i + 1;
        if (!isset($L[$lid])) { $err[] = "Line $n: pick a ledger."; continue; }
        if (empty($L[$lid]['active'])) $err[] = "Line $n: that ledger is switched off.";
        if (($d > 0) === ($c > 0) || $d < 0 || $c < 0) { $err[] = "Line $n: put an amount in Dr or Cr, not both."; continue; }
        $dr += $d; $cr += $c;
        if (in_array($L[$lid]['kind'] ?? '', ['bank', 'cash'], true)) { if ($d > 0) $cashDr++; else $cashCr++; } else $other++;
    }
    if (!$err && $dr !== $cr) $err[] = 'Dr and Cr do not match (difference ' . fin_amt(abs($dr - $cr), $indian) . ').';
    if (!$err && $dr <= 0) $err[] = 'The voucher has no amount.';
    if (!$err) {
        if ($vtype === 'payment' && !$cashCr) $err[] = 'A payment needs a bank or cash ledger on the Cr side.';
        if ($vtype === 'receipt' && !$cashDr) $err[] = 'A receipt needs a bank or cash ledger on the Dr side.';
        if ($vtype === 'contra' && $other) $err[] = 'A contra moves money between bank and cash ledgers only.';
        if ($vtype === 'journal' && ($cashDr || $cashCr)) $err[] = 'A journal does not touch bank or cash; use payment, receipt or contra.';
    }
    return [$err, $dr];
}

/** Who acts on a submitted voucher: ['posted'], ['approver', id] or ['check']. Pure. */
function fin_voucher_route(array $R, ?array $rule, int $total): array {
    if (fin_needs_approval($rule, $total)) return ['approver', $rule ? (int)$rule['approver_id'] : 0];
    return !empty($R['post']) ? ['posted'] : ['check'];
}
/** May this login decide a waiting voucher? Never one they made. Pure. */
function fin_can_decide(array $R, int $uid, array $v): bool {
    if (($v['status'] ?? '') !== 'waiting' || (int)($v['created_by'] ?? 0) === $uid) return false;
    if (!empty($v['restricted']) && empty($R['sens'])) return false;
    if (($v['need'] ?? '') === 'approver') return !empty($R['setup']) || (int)($v['approver_id'] ?? 0) === $uid;
    return !empty($R['post']);
}
/** Read the posted form's lines: line_ledger[], line_dr[], line_cr[], line_note[] (blank rows dropped). */
function fin_lines_input(array $in): array {
    $out = []; $bad = [];
    foreach ((array)($in['line_ledger'] ?? []) as $i => $lid) {
        $drs = trim((string)($in['line_dr'][$i] ?? '')); $crs = trim((string)($in['line_cr'][$i] ?? ''));
        if ((int)$lid === 0 && $drs === '' && $crs === '') continue;
        $dr = $drs === '' ? 0 : fin_minor($drs); $cr = $crs === '' ? 0 : fin_minor($crs);
        if ($dr === null || $cr === null) { $bad[] = 'Line ' . (count($out) + 1) . ': an amount like 25000 or 25,000.50.'; $dr ??= 0; $cr ??= 0; }
        $out[] = [(int)$lid, $dr, $cr, mb_substr(trim((string)($in['line_note'][$i] ?? '')), 0, 200)];
    }
    if ($bad) throw new RuntimeException(implode(' ', $bad));
    return $out;
}
/** The group's kind, walking up to its parent when blank. Pure. */
function fin_group_kind(array $groups, int $gid): string {
    $by = []; foreach ($groups as $g) $by[(int)$g['id']] = $g;
    for ($i = 0; $i < 8 && isset($by[$gid]); $i++) {
        if ($by[$gid]['kind'] !== '') return (string)$by[$gid]['kind'];
        $gid = (int)($by[$gid]['parent_id'] ?? 0);
    }
    return '';
}
/** Trial balance from ledgers + balances. $L rows: id, name, group_name, nature, restricted, active, opening_minor. Pure. */
function fin_tb_build(array $L, array $bal, bool $sens): array {
    $by = []; $open = 0;
    foreach ($L as $l) {
        $b = $bal[(int)$l['id']] ?? 0; $open += (int)$l['opening_minor'];
        if ($b === 0 && !(int)$l['active']) continue;
        $g = (string)$l['group_name'];
        $by[$g] ??= ['group' => $g, 'nature' => $l['nature'], 'rows' => [], 'hidden' => 0, 'dr' => 0, 'cr' => 0];
        if ((int)$l['restricted'] && !$sens) $by[$g]['hidden'] += $b; else $by[$g]['rows'][] = [$l['name'], (int)$l['id'], $b];
        if ($b > 0) $by[$g]['dr'] += $b; else $by[$g]['cr'] -= $b;
    }
    $dr = 0; $cr = 0;
    foreach ($by as &$g) { if ($g['hidden'] !== 0) $g['rows'][] = ['Restricted ledgers (total)', null, $g['hidden']]; $dr += $g['dr']; $cr += $g['cr']; }
    unset($g);
    $order = array_flip(array_keys(FIN_NATURES));
    uasort($by, fn($a, $b) => [$order[$a['nature']] ?? 9, $a['group']] <=> [$order[$b['nature']] ?? 9, $b['group']]);
    return ['groups' => array_values($by), 'dr' => $dr, 'cr' => $cr, 'opening_diff' => $open];
}

/* ------------------------------------------------------------------ database: rights, log, setup */

function fin_rights(PDO $db, array $u): array {
    $codes = user_roles((int)$u['id'], !empty($u['is_owner']));
    $q = $db->prepare('SELECT role FROM fin_member WHERE user_id = ? AND active = 1'); $q->execute([(int)$u['id']]);
    return fin_rights_from($codes, (string)($q->fetchColumn() ?: ''), fin_settings());
}
function fin_need(array $R, string $k, string $why): void { if (empty($R[$k])) throw new RuntimeException($why); }
function fin_log(PDO $db, ?int $uid, string $action, string $entity, string $id, string $detail = ''): void {
    $db->prepare('INSERT INTO fin_log (user_id, action, entity, entity_id, detail) VALUES (?,?,?,?,?)')->execute([$uid, $action, $entity, $id, mb_substr($detail, 0, 600)]);
    audit($uid, 'fin_' . $action, $entity . ' #' . $id);
}
function fin_book(PDO $db): array {
    $r = $db->query('SELECT * FROM fin_book WHERE id = 1')->fetch();
    return ($r ?: ['books_from' => null, 'locked_upto' => null]) + ['fy_start' => max(1, (int)(org('fy_start_month') ?: 4))];
}
function fin_lock_check(array $B, string $date): void {
    if (!empty($B['locked_upto']) && $date <= $B['locked_upto']) throw new RuntimeException('The books are locked up to ' . $B['locked_upto'] . '; pick a later date.');
    if (!empty($B['books_from']) && $date < $B['books_from']) throw new RuntimeException('The books start on ' . $B['books_from'] . '.');
}

function fin_regs(PDO $db, bool $all = false): array { return $db->query('SELECT * FROM fin_reg' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY active DESC, region, id')->fetchAll(); }
function fin_banks(PDO $db, bool $all = false): array { return $db->query('SELECT id, kind, bank, branch_name, code, last4, settles_into, reg_id, is_main, note, active, off_reason FROM fin_bank' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY active DESC, is_main DESC, bank')->fetchAll(); }
function fin_rules(PDO $db): array { return $db->query('SELECT * FROM fin_approval ORDER BY from_date DESC, id DESC')->fetchAll(); }
function fin_members(PDO $db): array { return $db->query('SELECT m.*, u.name FROM fin_member m JOIN users u ON u.id = m.user_id ORDER BY m.active DESC, u.name')->fetchAll(); }

function fin_reg_save(PDO $db, array $R, int $uid, array $in): int {
    fin_need($R, 'setup', 'Only the setup roles change the Finance setup.');
    $id = (int)($in['id'] ?? 0);
    $num = strtoupper(preg_replace('/\s+/', '', (string)($in['number'] ?? '')));
    $region = mb_substr(trim((string)($in['region'] ?? '')), 0, 60);
    if ($num !== '') {
        $c = crm_tax_check($num, crm_tax_rule());
        if (!$c['ok']) throw new RuntimeException(crm_tax_label() . ': ' . $c['why']);
        $num = $c['value'];
        if ($region === '' && ($c['region'] ?? '') !== '') $region = (string)$c['region'];
        $q = $db->prepare('SELECT COUNT(*) FROM fin_reg WHERE number = ? AND id <> ?'); $q->execute([$num, $id]);
        if ((int)$q->fetchColumn()) throw new RuntimeException('That registration is already listed.');
        if (($c['inner'] ?? '') !== '') {   // every registration belongs to the same legal entity (e.g. the PAN inside a GSTIN)
            $q = $db->prepare('SELECT number FROM fin_reg WHERE number IS NOT NULL AND active = 1 AND id <> ?'); $q->execute([$id]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $other) { $oc = crm_tax_check((string)$other, crm_tax_rule()); if (($oc['inner'] ?? '') !== '' && $oc['inner'] !== $c['inner']) throw new RuntimeException('This number belongs to a different legal entity than the registrations already listed.'); }
        }
    }
    if ($num === '' && $region === '') throw new RuntimeException('Give the number, or the region it is waiting for.');
    $br = implode(',', array_map('intval', array_filter((array)($in['branches'] ?? []))));
    $row = [$num !== '' ? $num : null, $region, $br, mb_substr(trim((string)($in['address'] ?? '')), 0, 500), mb_substr(trim((string)($in['note'] ?? '')), 0, 300)];
    if ($id) {
        $db->prepare('UPDATE fin_reg SET number=?, region=?, branches=?, address=?, note=?, updated_by=?, updated_at=NOW() WHERE id=?')->execute([...$row, $uid, $id]);
        fin_log($db, $uid, 'reg_edit', 'reg', (string)$id, 'number, region, branches, address, note');
    } else {
        $db->prepare('INSERT INTO fin_reg (number, region, branches, address, note, created_by) VALUES (?,?,?,?,?,?)')->execute([...$row, $uid]);
        $id = (int)$db->lastInsertId();
        fin_log($db, $uid, 'reg_add', 'reg', (string)$id, $num === '' ? 'pending' : '');
    }
    return $id;
}

function fin_bank_save(PDO $db, array $R, int $uid, array $in): int {
    fin_need($R, 'setup', 'Only the setup roles change the Finance setup.');
    $id = (int)($in['id'] ?? 0);
    $kind = isset(FIN_BANK_KINDS[$in['kind'] ?? '']) ? (string)$in['kind'] : 'current';
    $bank = mb_substr(trim((string)($in['bank'] ?? '')), 0, 120);
    if ($bank === '') throw new RuntimeException('Give the bank name.');
    $code = strtoupper(preg_replace('/\s+/', '', (string)($in['code'] ?? '')));
    if ($code !== '' && !preg_match('/^[A-Z0-9-]{4,20}$/', $code)) throw new RuntimeException('Branch / routing code: 4–20 letters and digits.');
    $acct = strtoupper(preg_replace('/[\s-]+/', '', (string)($in['account'] ?? '')));
    $settles = (int)($in['settles_into'] ?? 0) ?: null;
    if ($kind === 'virtual' && !$settles) throw new RuntimeException('A collection account names the account it settles into.');
    if ($settles && $settles === $id) throw new RuntimeException('An account cannot settle into itself.');
    $reg = (int)($in['reg_id'] ?? 0) ?: null;
    $main = !empty($in['is_main']) ? 1 : 0;
    $fields = [$kind, $bank, mb_substr(trim((string)($in['branch_name'] ?? '')), 0, 120), $code, $settles, $reg, $main, mb_substr(trim((string)($in['note'] ?? '')), 0, 300)];
    $db->beginTransaction();
    try {
        if ($main) $db->exec('UPDATE fin_bank SET is_main = 0');
        if ($id) {
            $db->prepare('UPDATE fin_bank SET kind=?, bank=?, branch_name=?, code=?, settles_into=?, reg_id=?, is_main=?, note=?, updated_by=?, updated_at=NOW() WHERE id=?')->execute([...$fields, $uid, $id]);
            $ch = 'kind, bank, branch, code, settles into, registration, main, note';
            if ($acct !== '') {
                if (!fin_acct_ok($acct)) throw new RuntimeException('Account number: 6–34 letters and digits.');
                $db->prepare('UPDATE fin_bank SET box=?, last4=?, acct_hash=? WHERE id=?')->execute([seal($acct), substr($acct, -4), hash('sha256', $acct), $id]);
                $ch .= ', account number (sealed)';
            }
            fin_log($db, $uid, 'bank_edit', 'bank', (string)$id, $ch);
        } else {
            if (!fin_acct_ok($acct)) throw new RuntimeException('Account number: 6–34 letters and digits.');
            $q = $db->prepare('SELECT COUNT(*) FROM fin_bank WHERE acct_hash = ?'); $q->execute([hash('sha256', $acct)]);
            if ((int)$q->fetchColumn()) throw new RuntimeException('That account is already listed.');
            $db->prepare('INSERT INTO fin_bank (kind, bank, branch_name, code, settles_into, reg_id, is_main, note, box, last4, acct_hash, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([...$fields, seal($acct), substr($acct, -4), hash('sha256', $acct), $uid]);
            $id = (int)$db->lastInsertId();
            fin_log($db, $uid, 'bank_add', 'bank', (string)$id, 'account number (sealed)');
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $id;
}

/** Switch a registration / bank account / ledger off (reason needed) or back on. */
function fin_switch(PDO $db, array $R, int $uid, string $what, int $id, bool $on, string $reason): void {
    $t = ['reg' => 'fin_reg', 'bank' => 'fin_bank', 'ledger' => 'fin_ledger'][$what] ?? '';
    if ($t === '') throw new RuntimeException('Unknown item.');
    fin_need($R, $what === 'ledger' ? 'masters' : 'setup', 'Your Finance role cannot change this.');
    $reason = trim($reason);
    if (!$on && $reason === '') throw new RuntimeException('Give a reason to switch it off.');
    $db->prepare("UPDATE $t SET active = ?, off_reason = ? WHERE id = ?")->execute([$on ? 1 : 0, $on ? null : mb_substr($reason, 0, 300), $id]);
    fin_log($db, $uid, $what . ($on ? '_on' : '_off'), $what, (string)$id, $on ? '' : 'reason');
}

function fin_member_save(PDO $db, array $R, int $uid, int $who, string $role): void {
    fin_need($R, 'setup', 'Only the setup roles change the Finance team.');
    if ($who === $uid) throw new RuntimeException('Nobody sets their own Finance role.');
    if ($role !== '' && !isset(FIN_ROLES[$role])) throw new RuntimeException('Pick a role.');
    $q = $db->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND active = 1'); $q->execute([$who]);
    if (!(int)$q->fetchColumn()) throw new RuntimeException('Pick an active login.');
    $db->prepare('INSERT INTO fin_member (user_id, role, active, set_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE role = VALUES(role), active = VALUES(active), set_by = VALUES(set_by), set_at = NOW()')
       ->execute([$who, $role !== '' ? $role : 'audit', $role !== '' ? 1 : 0, $uid]);
    fin_log($db, $uid, 'member_set', 'member', (string)$who, $role !== '' ? FIN_ROLES[$role] : 'removed');
}

function fin_rule_add(PDO $db, array $R, int $uid, array $in, string $today): int {
    fin_need($R, 'setup', 'Only the setup roles set the approval rule.');
    $lim = fin_minor((string)($in['limit'] ?? ''));
    if ($lim === null || $lim <= 0) throw new RuntimeException('Limit: an amount above 0.');
    $who = (int)($in['approver_id'] ?? 0);
    $q = $db->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND active = 1'); $q->execute([$who]);
    if (!(int)$q->fetchColumn()) throw new RuntimeException('Pick the approver.');
    $from = (string)($in['from_date'] ?? $today);
    if (!fin_date_ok($from) || $from < $today) throw new RuntimeException('A rule starts today or later; earlier rules stay as history.');
    $db->prepare('INSERT INTO fin_approval (limit_minor, approver_id, from_date, note, set_by) VALUES (?,?,?,?,?)')->execute([$lim, $who, $from, mb_substr(trim((string)($in['note'] ?? '')), 0, 300), $uid]);
    $id = (int)$db->lastInsertId();
    fin_log($db, $uid, 'rule_add', 'rule', (string)$id, 'limit, approver, from');
    return $id;
}

/** Super Admin (setup roles): books start date and the period lock. */
function fin_books_set(PDO $db, array $R, int $uid, string $from, string $lock, string $reason): void {
    fin_need($R, 'lock', 'Only the setup roles set the books start and lock.');
    $B = fin_book($db); $from = trim($from); $lock = trim($lock);
    if ($from !== '' && !fin_date_ok($from)) throw new RuntimeException('Books start: pick a date.');
    if ($lock !== '' && !fin_date_ok($lock)) throw new RuntimeException('Lock: pick a date.');
    $earlier = (string)($B['locked_upto'] ?? '');
    if ($earlier !== '' && ($lock === '' || $lock < $earlier) && trim($reason) === '') throw new RuntimeException('Opening a locked period needs a reason.');
    if ($from !== '' && (string)($B['books_from'] ?? '') !== $from) {
        $q = $db->prepare('SELECT COUNT(*) FROM fin_voucher WHERE status = "posted" AND vdate < ?'); $q->execute([$from]);
        if ((int)$q->fetchColumn()) throw new RuntimeException('Posted vouchers are dated before that day; the books cannot start later.');
    }
    $db->prepare('INSERT INTO fin_book (id, books_from, locked_upto, updated_by) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE books_from = VALUES(books_from), locked_upto = VALUES(locked_upto), updated_by = VALUES(updated_by), updated_at = NOW()')
       ->execute([$from ?: null, $lock ?: null, $uid]);
    fin_log($db, $uid, 'books_set', 'book', '1', 'books start, lock' . (trim($reason) !== '' ? ', reason' : ''));
}

/* ------------------------------------------------------------------ masters */

function fin_seed_groups(PDO $db, ?int $uid = null): int {
    if ((int)$db->query('SELECT COUNT(*) FROM fin_group')->fetchColumn()) return 0;
    $ids = []; $n = 0;
    $ins = $db->prepare('INSERT INTO fin_group (name, nature, parent_id, kind, seeded, created_by) VALUES (?,?,?,?,1,?)');
    foreach (FIN_GROUP_SEED as [$name, $nat, $par, $kind]) { $ins->execute([$name, $nat, $par !== '' ? ($ids[$par] ?? null) : null, $kind, $uid]); $ids[$name] = (int)$db->lastInsertId(); $n++; }
    fin_log($db, $uid, 'groups_seed', 'group', '0', "$n groups");
    return $n;
}
function fin_groups(PDO $db, bool $all = false): array {
    return $db->query('SELECT * FROM fin_group' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY FIELD(nature,"asset","liability","income","expense"), COALESCE(parent_id, id), parent_id IS NOT NULL, name')->fetchAll();
}
function fin_ledgers(PDO $db, bool $all = false): array {
    return $db->query('SELECT l.*, g.name AS group_name, g.nature FROM fin_ledger l JOIN fin_group g ON g.id = l.group_id' . ($all ? '' : ' WHERE l.active = 1') . ' ORDER BY g.nature, g.name, l.name')->fetchAll();
}
function fin_ledger_map(PDO $db): array { $m = []; foreach (fin_ledgers($db, true) as $l) $m[(int)$l['id']] = $l; return $m; }

function fin_group_save(PDO $db, array $R, int $uid, array $in): int {
    fin_need($R, 'masters', 'Only an Accounts head / manager or the setup roles keep the chart of accounts.');
    $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Give the group a name.');
    $q = $db->prepare('SELECT * FROM fin_group WHERE id = ?'); $q->execute([(int)($in['parent_id'] ?? 0)]);
    $p = $q->fetch() ?: throw new RuntimeException('Pick the group it sits under.');
    $q = $db->prepare('SELECT COUNT(*) FROM fin_group WHERE name = ?'); $q->execute([$name]);
    if ((int)$q->fetchColumn()) throw new RuntimeException('A group with that name is already there.');
    $db->prepare('INSERT INTO fin_group (name, nature, parent_id, kind, created_by) VALUES (?,?,?,?,?)')->execute([$name, $p['nature'], (int)$p['id'], '', $uid]);
    $id = (int)$db->lastInsertId();
    fin_log($db, $uid, 'group_add', 'group', (string)$id, 'name, under');
    return $id;
}

function fin_ledger_save(PDO $db, array $R, int $uid, array $in): int {
    fin_need($R, 'masters', 'Only an Accounts head / manager or the setup roles keep ledgers.');
    $id = (int)($in['id'] ?? 0);
    $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
    if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Give the ledger a name.');
    $gid = (int)($in['group_id'] ?? 0);
    $G = fin_groups($db);
    if (!in_array($gid, array_map(fn($g) => (int)$g['id'], $G), true)) throw new RuntimeException('Pick the group.');
    $kind = fin_group_kind($G, $gid);
    $op = trim((string)($in['opening'] ?? ''));
    $opM = $op === '' ? 0 : fin_minor($op);
    if ($opM === null) throw new RuntimeException('Opening balance: an amount like 25000 or 25,000.50.');
    if (($in['opening_side'] ?? 'dr') === 'cr') $opM = -$opM;
    $bank = (int)($in['bank_id'] ?? 0) ?: null;
    if ($bank !== null && $kind !== 'bank') throw new RuntimeException('Only a ledger under a bank group points at a bank account.');
    $reg = (int)($in['reg_id'] ?? 0) ?: null;
    $restricted = !empty($in['restricted']) ? 1 : 0;
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 300);
    $q = $db->prepare('SELECT id FROM fin_ledger WHERE name = ? AND id <> ?'); $q->execute([$name, $id]);
    if ($q->fetchColumn()) throw new RuntimeException('A ledger with that name is already there.');
    if ($id) {
        $q = $db->prepare('SELECT * FROM fin_ledger WHERE id = ?'); $q->execute([$id]);
        $old = $q->fetch() ?: throw new RuntimeException('Ledger not found.');
        if ((int)$old['restricted'] && empty($R['sens'])) throw new RuntimeException('This ledger is restricted.');
        if (empty($R['setup'])) $restricted = (int)$old['restricted'];
        $ch = [];
        foreach (['name' => $name, 'group_id' => $gid, 'opening_minor' => $opM, 'bank_id' => $bank, 'reg_id' => $reg, 'restricted' => $restricted, 'note' => $note] as $k => $v) if ((string)$old[$k] !== (string)$v) $ch[] = $k;
        $db->prepare('UPDATE fin_ledger SET name=?, group_id=?, kind=?, opening_minor=?, bank_id=?, reg_id=?, restricted=?, note=?, updated_by=?, updated_at=NOW() WHERE id=?')
           ->execute([$name, $gid, $kind, $opM, $bank, $reg, $restricted, $note, $uid, $id]);
        if ($ch) fin_log($db, $uid, 'ledger_edit', 'ledger', (string)$id, implode(', ', $ch));
        return $id;
    }
    if (empty($R['setup'])) $restricted = 0;
    $db->prepare('INSERT INTO fin_ledger (group_id, name, kind, bank_id, reg_id, restricted, opening_minor, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$gid, $name, $kind, $bank, $reg, $restricted, $opM, $note, $uid]);
    $id = (int)$db->lastInsertId();
    fin_log($db, $uid, 'ledger_add', 'ledger', (string)$id, 'name, group' . ($opM ? ', opening' : ''));
    return $id;
}

/** A ledger under "Bank Accounts" (or "Bank OD Accounts" for an overdraft) for every active bank account that has none. */
function fin_bank_ledgers(PDO $db, array $R, int $uid): int {
    fin_need($R, 'masters', 'Only an Accounts head / manager or the setup roles keep ledgers.');
    fin_seed_groups($db, $uid);
    $gid = $db->query('SELECT name, id FROM fin_group WHERE name IN ("Bank Accounts","Bank OD Accounts")')->fetchAll(PDO::FETCH_KEY_PAIR);
    $n = 0;
    foreach (fin_banks($db) as $b) {
        $q = $db->prepare('SELECT COUNT(*) FROM fin_ledger WHERE bank_id = ?'); $q->execute([(int)$b['id']]);
        if ((int)$q->fetchColumn()) continue;
        $g = (int)($b['kind'] === 'od' ? ($gid['Bank OD Accounts'] ?? 0) : ($gid['Bank Accounts'] ?? 0));
        if (!$g) continue;
        $name = $b['bank'] . ' ··' . $b['last4'];
        $q = $db->prepare('SELECT COUNT(*) FROM fin_ledger WHERE name = ?'); $q->execute([$name]);
        if ((int)$q->fetchColumn()) $name .= ' (' . (FIN_BANK_KINDS[$b['kind']] ?? $b['kind']) . ')';
        $db->prepare('INSERT INTO fin_ledger (group_id, name, kind, bank_id, reg_id, created_by) VALUES (?,?,?,?,?,?)')->execute([$g, $name, 'bank', (int)$b['id'], $b['reg_id'], $uid]);
        $n++;
    }
    if ($n) fin_log($db, $uid, 'ledger_bank', 'ledger', '0', "$n bank ledgers");
    return $n;
}

/* ------------------------------------------------------------------ vouchers */

function fin_voucher(PDO $db, int $id): ?array {
    $q = $db->prepare('SELECT * FROM fin_voucher WHERE id = ?'); $q->execute([$id]);
    $v = $q->fetch();
    if (!$v) return null;
    $q = $db->prepare('SELECT e.*, l.name AS ledger_name, l.kind, l.restricted FROM fin_entry e JOIN fin_ledger l ON l.id = e.ledger_id WHERE e.voucher_id = ? AND e.rev = ? ORDER BY e.line_no');
    $q->execute([$id, (int)$v['rev']]);
    $v['lines'] = $q->fetchAll();
    return $v;
}

/** Save a draft (new or the same draft again) and, with $submit, send it on. The same request_id returns the same voucher. */
function fin_voucher_save(PDO $db, array $R, int $uid, array $in, bool $submit, string $today): int {
    fin_need($R, 'enter', 'Only the Accounts team enters vouchers.');
    $B = fin_book($db);
    $id = (int)($in['id'] ?? 0);
    $req = preg_replace('/[^a-z0-9-]/i', '', (string)($in['request_id'] ?? ''));
    if (!$id && $req !== '') { $q = $db->prepare('SELECT id FROM fin_voucher WHERE request_id = ?'); $q->execute([$req]); if ($f = (int)$q->fetchColumn()) return $f; }
    $vtype = (string)($in['vtype'] ?? ''); $date = (string)($in['vdate'] ?? '');
    if (!fin_date_ok($date)) throw new RuntimeException('Pick the voucher date.');
    if ($date > $today) throw new RuntimeException('A voucher cannot be dated after today.');
    fin_lock_check($B, $date);
    $lines = fin_lines_input($in);
    $L = fin_ledger_map($db);
    [$err, $total] = fin_voucher_check($vtype, $lines, $L, org('currency') === 'INR');
    if ($err) throw new RuntimeException(implode(' ', $err));
    $restricted = 0; foreach ($lines as $ln) if (!empty($L[$ln[0]]['restricted'])) $restricted = 1;
    if ($restricted && empty($R['sens'])) throw new RuntimeException('Only the restricted-ledger roles enter a voucher on a restricted ledger.');
    $reg = (int)($in['reg_id'] ?? 0) ?: null;
    $narr = mb_substr(trim((string)($in['narration'] ?? '')), 0, 500);
    $fy = fin_fy($date, (int)$B['fy_start']);
    $db->beginTransaction();
    try {
        if ($id) {
            $q = $db->prepare('SELECT * FROM fin_voucher WHERE id = ? FOR UPDATE'); $q->execute([$id]);
            $v = $q->fetch() ?: throw new RuntimeException('Voucher not found.');
            if ($v['status'] !== 'draft') throw new RuntimeException('Only a draft is changed. A posted voucher is corrected by reversing it.');
            if ((int)$v['created_by'] !== $uid && empty($R['post'])) throw new RuntimeException('Only the person who made the draft (or a head / manager) changes it.');
            $rev = (int)$v['rev'] + 1;
            $db->prepare('UPDATE fin_voucher SET vtype=?, vdate=?, fy=?, reg_id=?, narration=?, total_minor=?, rev=?, restricted=?, updated_at=NOW() WHERE id=?')
               ->execute([$vtype, $date, $fy, $reg, $narr, $total, $rev, $restricted, $id]);
            fin_log($db, $uid, 'voucher_edit', 'voucher', (string)$id, 'lines, header');
        } else {
            $rev = 1;
            $db->prepare('INSERT INTO fin_voucher (reg_id, vtype, vdate, fy, narration, total_minor, status, rev, restricted, request_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$reg, $vtype, $date, $fy, $narr, $total, 'draft', 1, $restricted, $req !== '' ? $req : null, $uid]);
            $id = (int)$db->lastInsertId();
            fin_log($db, $uid, 'voucher_add', 'voucher', (string)$id, FIN_VTYPES[$vtype][0]);
        }
        $ins = $db->prepare('INSERT INTO fin_entry (voucher_id, rev, line_no, ledger_id, dr_minor, cr_minor, line_note) VALUES (?,?,?,?,?,?,?)');
        foreach ($lines as $i => $ln) $ins->execute([$id, $rev, $i + 1, $ln[0], $ln[1], $ln[2], $ln[3]]);
        if ($submit) fin_submit_in($db, $R, $uid, $B, $id, $total, $date);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $id;
}

/** Inside a transaction: route a draft on (post, or wait for the check / the approver). */
function fin_submit_in(PDO $db, array $R, int $uid, array $B, int $id, int $total, string $date): void {
    $route = fin_voucher_route($R, fin_rule_on(fin_rules($db), $date), $total);
    if ($route[0] === 'posted') { fin_post_in($db, $uid, $B, $id); return; }
    $db->prepare('UPDATE fin_voucher SET status = "waiting", need = ?, approver_id = ?, submitted_by = ?, submitted_at = NOW(), decided_by = NULL, decided_at = NULL, decide_note = NULL WHERE id = ?')
       ->execute([$route[0], $route[0] === 'approver' ? ($route[1] ?: null) : null, $uid, $id]);
    fin_log($db, $uid, 'voucher_submit', 'voucher', (string)$id, $route[0] === 'approver' ? 'to the approver' : 'to a head / manager');
    if ($route[0] === 'approver' && $route[1] && function_exists('notify_user')) notify_user($db, $route[1], 'finance', 'Voucher to approve', 'A voucher waits for your approval.', 'modules/finance/index.php?v=voucher&id=' . $id);
}

/** Inside a transaction: give the number and post. */
function fin_post_in(PDO $db, int $uid, array $B, int $id): void {
    $q = $db->prepare('SELECT * FROM fin_voucher WHERE id = ? FOR UPDATE'); $q->execute([$id]);
    $v = $q->fetch();
    if (!$v || $v['status'] === 'posted') throw new RuntimeException('This voucher is already posted.');
    fin_lock_check($B, (string)$v['vdate']);
    $s = $db->prepare('SELECT next_no FROM fin_seq WHERE vtype = ? AND fy = ? FOR UPDATE'); $s->execute([$v['vtype'], $v['fy']]);
    $n = $s->fetchColumn();
    if ($n === false) { $db->prepare('INSERT INTO fin_seq (vtype, fy, next_no) VALUES (?,?,2)')->execute([$v['vtype'], $v['fy']]); $n = 1; }
    else $db->prepare('UPDATE fin_seq SET next_no = next_no + 1 WHERE vtype = ? AND fy = ?')->execute([$v['vtype'], $v['fy']]);
    $no = fin_vno((string)$v['vtype'], (string)$v['fy'], (int)$n);
    $db->prepare('UPDATE fin_voucher SET status = "posted", number = ?, posted_by = ?, posted_at = NOW(), submitted_by = COALESCE(submitted_by, ?), submitted_at = COALESCE(submitted_at, NOW()) WHERE id = ?')
       ->execute([$no, $uid, $uid, $id]);
    fin_log($db, $uid, 'voucher_post', 'voucher', (string)$id, $no);
}

function fin_voucher_submit(PDO $db, array $R, int $uid, int $id): void {
    fin_need($R, 'enter', 'Only the Accounts team sends vouchers on.');
    $B = fin_book($db);
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM fin_voucher WHERE id = ? FOR UPDATE'); $q->execute([$id]);
        $v = $q->fetch();
        if (!$v || $v['status'] !== 'draft') throw new RuntimeException('Only a draft is sent on.');
        if ((int)$v['created_by'] !== $uid && empty($R['post'])) throw new RuntimeException('Only the person who made the draft (or a head / manager) sends it on.');
        fin_submit_in($db, $R, $uid, $B, $id, (int)$v['total_minor'], (string)$v['vdate']);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

/** Approve (post) or send back a waiting voucher. */
function fin_voucher_decide(PDO $db, array $R, int $uid, int $id, bool $ok, string $note): void {
    $note = trim($note);
    if (!$ok && $note === '') throw new RuntimeException('Say why it goes back.');
    $B = fin_book($db);
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT * FROM fin_voucher WHERE id = ? FOR UPDATE'); $q->execute([$id]);
        $v = $q->fetch();
        if (!$v || !fin_can_decide($R, $uid, $v)) throw new RuntimeException('You cannot decide this voucher (nobody decides a voucher they made).');
        $db->prepare('UPDATE fin_voucher SET decided_by = ?, decided_at = NOW(), decide_note = ? WHERE id = ?')->execute([$uid, mb_substr($note, 0, 300) ?: null, $id]);
        if ($ok) fin_post_in($db, $uid, $B, $id);
        else { $db->prepare('UPDATE fin_voucher SET status = "draft", need = "" WHERE id = ?')->execute([$id]); fin_log($db, $uid, 'voucher_back', 'voucher', (string)$id, 'note'); }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    if (function_exists('notify_user') && (int)$v['created_by'] !== $uid) notify_user($db, (int)$v['created_by'], 'finance', $ok ? 'Voucher posted' : 'Voucher sent back', $ok ? 'Your voucher was approved and posted.' : 'Your voucher came back: ' . $note, 'modules/finance/index.php?v=voucher&id=' . $id);
}

/** Reverse a posted voucher with a new posted voucher of opposite lines; the original stays, marked. Returns the new id. */
function fin_voucher_reverse(PDO $db, array $R, int $uid, int $id, string $reason, string $date): int {
    fin_need($R, 'post', 'Only an Accounts head / manager or the setup roles reverse a voucher.');
    $reason = trim($reason);
    if ($reason === '') throw new RuntimeException('Give a reason for the reversal.');
    if (!fin_date_ok($date)) throw new RuntimeException('Pick the reversal date.');
    $B = fin_book($db);
    fin_lock_check($B, $date);
    $v = fin_voucher($db, $id);
    if (!$v || $v['status'] !== 'posted') throw new RuntimeException('Only a posted voucher is reversed.');
    if ($v['reversed_by_id'] || $v['reverses_id']) throw new RuntimeException('This voucher is already a reversal or reversed.');
    if ($v['restricted'] && empty($R['sens'])) throw new RuntimeException('This voucher is on a restricted ledger.');
    if ($date < $v['vdate']) throw new RuntimeException('The reversal cannot be dated before the voucher.');
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO fin_voucher (reg_id, vtype, vdate, fy, narration, total_minor, status, rev, restricted, created_by, reverses_id) VALUES (?,?,?,?,?,?,?,1,?,?,?)')
           ->execute([$v['reg_id'], $v['vtype'], $date, fin_fy($date, (int)$B['fy_start']), mb_substr('Reversal of ' . $v['number'] . ' — ' . $reason, 0, 500), (int)$v['total_minor'], 'draft', (int)$v['restricted'], $uid, $id]);
        $nid = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO fin_entry (voucher_id, rev, line_no, ledger_id, dr_minor, cr_minor, line_note) VALUES (?,1,?,?,?,?,?)');
        foreach ($v['lines'] as $i => $ln) $ins->execute([$nid, $i + 1, (int)$ln['ledger_id'], (int)$ln['cr_minor'], (int)$ln['dr_minor'], $ln['line_note']]);
        fin_post_in($db, $uid, $B, $nid);
        $db->prepare('UPDATE fin_voucher SET reversed_by_id = ?, reverse_reason = ? WHERE id = ? AND reversed_by_id IS NULL')->execute([$nid, mb_substr($reason, 0, 300), $id]);
        fin_log($db, $uid, 'voucher_reverse', 'voucher', (string)$id, 'by #' . $nid);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $nid;
}

/* ------------------------------------------------------------------ reading the books */

/** Movement per ledger of posted vouchers between dates (inclusive; '' = open end): ledger_id => [dr, cr]. */
function fin_movements(PDO $db, string $from, string $to): array {
    $sql = 'SELECT e.ledger_id, SUM(e.dr_minor) dr, SUM(e.cr_minor) cr FROM fin_entry e JOIN fin_voucher v ON v.id = e.voucher_id AND e.rev = v.rev WHERE v.status = "posted"';
    $p = [];
    if ($from !== '') { $sql .= ' AND v.vdate >= ?'; $p[] = $from; }
    if ($to !== '') { $sql .= ' AND v.vdate <= ?'; $p[] = $to; }
    $q = $db->prepare($sql . ' GROUP BY e.ledger_id'); $q->execute($p);
    $m = []; foreach ($q->fetchAll() as $r) $m[(int)$r['ledger_id']] = [(int)$r['dr'], (int)$r['cr']];
    return $m;
}
function fin_balances(PDO $db, string $date): array {
    $mv = fin_movements($db, '', $date); $out = [];
    foreach (fin_ledgers($db, true) as $l) { [$d, $c] = $mv[(int)$l['id']] ?? [0, 0]; $out[(int)$l['id']] = (int)$l['opening_minor'] + $d - $c; }
    return $out;
}
function fin_trial_balance(PDO $db, array $R, string $date): array { return fin_tb_build(fin_ledgers($db, true), fin_balances($db, $date), !empty($R['sens'])); }

function fin_day_book(PDO $db, array $R, string $from, string $to, string $vtype = '', int $limit = 300): array {
    $sql = 'SELECT * FROM fin_voucher WHERE status = "posted" AND vdate BETWEEN ? AND ?'; $p = [$from, $to];
    if (isset(FIN_VTYPES[$vtype])) { $sql .= ' AND vtype = ?'; $p[] = $vtype; }
    if (empty($R['sens'])) $sql .= ' AND restricted = 0';
    $q = $db->prepare($sql . ' ORDER BY vdate DESC, id DESC LIMIT ' . max(1, min(2000, $limit))); $q->execute($p);
    return $q->fetchAll();
}
function fin_voucher_list(PDO $db, array $R, int $uid, string $status, int $limit = 200): array {
    $sql = 'SELECT * FROM fin_voucher WHERE status = ?'; $p = [$status];
    if ($status === 'draft' && empty($R['post'])) { $sql .= ' AND created_by = ?'; $p[] = $uid; }
    if (empty($R['sens'])) $sql .= ' AND restricted = 0';
    $q = $db->prepare($sql . ' ORDER BY vdate DESC, id DESC LIMIT ' . max(1, min(1000, $limit))); $q->execute($p);
    return $q->fetchAll();
}
function fin_ledger_statement(PDO $db, array $R, int $lid, string $from, string $to): array {
    $q = $db->prepare('SELECT l.*, g.name AS group_name FROM fin_ledger l JOIN fin_group g ON g.id = l.group_id WHERE l.id = ?'); $q->execute([$lid]);
    $l = $q->fetch() ?: throw new RuntimeException('Ledger not found.');
    if ((int)$l['restricted'] && empty($R['sens'])) throw new RuntimeException('This ledger is restricted.');
    $before = fin_movements($db, '', date('Y-m-d', strtotime($from . ' -1 day')))[$lid] ?? [0, 0];
    $open = (int)$l['opening_minor'] + $before[0] - $before[1];
    $q = $db->prepare('SELECT v.id, v.vdate, v.number, v.vtype, v.narration, e.dr_minor, e.cr_minor, e.line_note FROM fin_entry e JOIN fin_voucher v ON v.id = e.voucher_id AND e.rev = v.rev
        WHERE e.ledger_id = ? AND v.status = "posted" AND v.vdate BETWEEN ? AND ? ORDER BY v.vdate, v.id, e.line_no');
    $q->execute([$lid, $from, $to]);
    $rows = []; $run = $open; $dr = 0; $cr = 0;
    foreach ($q->fetchAll() as $r) { $run += (int)$r['dr_minor'] - (int)$r['cr_minor']; $dr += (int)$r['dr_minor']; $cr += (int)$r['cr_minor']; $r['bal'] = $run; $rows[] = $r; }
    return ['ledger' => $l, 'open' => $open, 'rows' => $rows, 'dr' => $dr, 'cr' => $cr, 'close' => $run];
}

/** Vouchers waiting for this login (Waiting for you on the home page). */
function fin_waiting(PDO $db, array $u): int {
    try { $R = fin_rights($db, $u); $n = 0; foreach (fin_voucher_list($db, $R, (int)$u['id'], 'waiting') as $v) if (fin_can_decide($R, (int)$u['id'], $v)) $n++; return $n; }
    catch (Throwable $e) { return 0; }
}

/** Overview figures. */
function fin_dash(PDO $db, array $R, int $uid, string $today, int $fyStart): array {
    $bal = fin_balances($db, $today); $cash = []; $cashTotal = 0;
    foreach (fin_ledgers($db) as $l) if (in_array($l['kind'], ['bank', 'cash'], true) && (!(int)$l['restricted'] || !empty($R['sens']))) {
        $b = $bal[(int)$l['id']] ?? 0; $cash[] = [$l['name'], (int)$l['id'], $b, $l['kind']]; $cashTotal += $b;
    }
    $restr = empty($R['sens']) ? ' AND restricted = 0' : '';
    $cnt = function (string $sql, array $p) use ($db) { $q = $db->prepare($sql); $q->execute($p); return (int)$q->fetchColumn(); };
    $month = substr($today, 0, 7) . '-01'; $fy = fin_fy_start($today, $fyStart);
    $tb = fin_trial_balance($db, $R, $today);
    $mine = 0; foreach (fin_voucher_list($db, $R, $uid, 'waiting') as $v) if (fin_can_decide($R, $uid, $v)) $mine++;
    $byType = [];
    $q = $db->prepare('SELECT vtype, COUNT(*) n, SUM(total_minor) t FROM fin_voucher WHERE status = "posted" AND vdate BETWEEN ? AND ?' . $restr . ' GROUP BY vtype'); $q->execute([$month, $today]);
    foreach ($q->fetchAll() as $r) $byType[$r['vtype']] = [(int)$r['n'], (int)$r['t']];
    return ['cash' => $cash, 'cash_total' => $cashTotal, 'ledgers' => count(fin_ledgers($db)),
        'posted_month' => $cnt('SELECT COUNT(*) FROM fin_voucher WHERE status = "posted" AND vdate BETWEEN ? AND ?' . $restr, [$month, $today]),
        'posted_fy' => $cnt('SELECT COUNT(*) FROM fin_voucher WHERE status = "posted" AND vdate BETWEEN ? AND ?' . $restr, [$fy, $today]),
        'waiting' => $cnt('SELECT COUNT(*) FROM fin_voucher WHERE status = "waiting"' . $restr, []), 'waiting_mine' => $mine,
        'drafts' => count(fin_voucher_list($db, $R, $uid, 'draft')), 'by_type' => $byType,
        'tb_ok' => $tb['dr'] === $tb['cr'], 'tb_diff' => $tb['dr'] - $tb['cr'], 'recent' => fin_day_book($db, $R, $fy, $today, '', 8)];
}

/** Setup still to do (for the overview). */
function fin_todo(PDO $db): array {
    $t = [];
    if (!(int)$db->query('SELECT COUNT(*) FROM fin_bank WHERE active = 1')->fetchColumn()) $t[] = 'Add the bank accounts.';
    if (!(int)$db->query('SELECT COUNT(*) FROM fin_approval')->fetchColumn()) $t[] = 'Set the voucher approval rule (until then every voucher waits for the setup roles).';
    if (!(int)$db->query('SELECT COUNT(*) FROM fin_group')->fetchColumn()) $t[] = 'Make the chart of accounts (groups are seeded on the Ledgers screen).';
    if ((int)$db->query('SELECT COUNT(*) FROM fin_reg WHERE active = 1 AND number IS NULL')->fetchColumn()) $t[] = 'A tax registration is still waiting for its number.';
    $B = fin_book($db); if (empty($B['books_from'])) $t[] = 'Set the date the books start.';
    return $t;
}
