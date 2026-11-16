<?php
/**
 * Payroll part 1 — rules by month, pay profiles and CTC, the month run.
 *
 * Rules (ported from the reference build, organisation decisions turned into settings):
 *  - Every number is a rule with the month it applies from (pay_rule); a confirmed month is read from its snapshot and
 *    never worked out again. Statutory parts come from the country rule pack (rulepacks/<country>/pack.php).
 *  - CTC is monthly, with a "from" month (pay_ctc, append-only). "CTC includes employer contributions" (setting): gross
 *    is worked back so gross + employer statutory = CTC; else the amount entered is the gross.
 *  - Structure from gross: Basic % of gross (with an optional floor, never above gross), HRA % of Basic, fixed
 *    allowances (paid only while what is left covers them), Special = the rest.
 *  - Paid days from attendance and leave: worked days, weekly offs, holidays and paid leave count; absence and unpaid
 *    leave do not; half days count half; days before joining / after the last day are not in the month for that
 *    person. Days still to come in a running month count as worked. Leave without a type counts as paid and is flagged;
 *    missed punches are flagged.
 *  - Earned = each part × paid days ÷ days in the month, rounded to whole units. Deductions: statutory (pack), TDS and
 *    other deductions typed by HR; other earnings typed by HR. Net = earned + other − deductions.
 *  - Steps: HR prepares (inputs, hold with a reason) → someone else checks → the confirm role (CEO by default) confirms
 *    and locks (snapshot) → releases. Reopening needs a reason; a new snapshot version is made at the next confirm;
 *    the old one is kept. Nobody changes their own line's inputs.
 *  - Seen only by logins with the pay data class (config/rbac.php → data.pay); audit lines never carry amounts.
 */
declare(strict_types=1);
require_once __DIR__ . '/attendance.php';

const PAY_STATUS = ['draft' => 'Draft', 'checked' => 'Checked', 'confirmed' => 'Confirmed (locked)', 'released' => 'Released'];
const PAY_MODES = ['bank' => 'Bank transfer', 'cheque' => 'Cheque', 'cash' => 'Cash'];
const PAY_BASE_RULES = [
    'ctc_includes_employer' => true,
    'structure' => ['basic_pct' => 50, 'basic_min' => 0, 'hra_pct' => 40, 'fixed' => []],   // fixed: [[name, amount], …]
    'paid_leave' => [],            // leave type codes that are paid; empty = every type except unpaid (paid = 0) ones
    'confirm_roles' => ['CEO'],
];

/* ---------------- rule pack and rules ---------------- */

function pay_pack(?string $country = null): array {
    $c = preg_replace('/[^A-Z]/', '', strtoupper($country ?? org('country')));
    $f = WN_ROOT . '/rulepacks/' . $c . '/pack.php';
    if ($c === '' || !is_file($f)) $f = WN_ROOT . '/rulepacks/XX/pack.php';
    return require $f;
}

function pay_default_rules(?array $pack = null): array {
    $pack ??= pay_pack();
    return array_replace_recursive(PAY_BASE_RULES, $pack['defaults'] ?? []);
}

/** Rules in force for a month: the newest version from on or before it, over the defaults. Pure. */
function pay_rules_for(array $versions, string $ym, array $defaults): array {
    $pick = null;
    foreach ($versions as $v) if ($v['from_ym'] <= $ym && (!$pick || $v['from_ym'] > $pick['from_ym'] || ($v['from_ym'] === $pick['from_ym'] && $v['id'] > $pick['id']))) $pick = $v;
    if (!$pick) return $defaults;
    $r = json_decode((string)$pick['rules'], true) ?: [];
    $out = array_replace_recursive($defaults, $r);
    foreach (['fixed'] as $k) if (isset($r['structure'][$k])) $out['structure'][$k] = $r['structure'][$k];   // lists replace, never merge
    if (isset($r['pt'])) $out['pt'] = $r['pt'];
    if (isset($r['pf']['parts'])) $out['pf']['parts'] = $r['pf']['parts'];
    if (isset($r['paid_leave'])) $out['paid_leave'] = $r['paid_leave'];
    if (isset($r['confirm_roles'])) $out['confirm_roles'] = $r['confirm_roles'];
    return $out;
}

/** "0 7500 0" / "7501 10000 175" / "10001 - 200 2 300" (month 2 = February pays 300) → slabs. Pure. */
function pay_pt_parse(string $text): array {
    $o = [];
    foreach (preg_split('/\R/', trim($text)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $p = preg_split('/[\s,:]+/', $line);
        if (count($p) < 3 || !is_numeric($p[0]) || !($p[1] === '-' || is_numeric($p[1])) || !is_numeric($p[2])) throw new InvalidArgumentException('Professional tax line "' . $line . '": write from, to (or -), amount [month, amount].');
        $s = ['min' => (float)$p[0], 'max' => $p[1] === '-' ? null : (float)$p[1], 'amt' => (float)$p[2]];
        if (isset($p[3], $p[4]) && ctype_digit($p[3]) && (int)$p[3] >= 1 && (int)$p[3] <= 12 && is_numeric($p[4])) { $s['month'] = (int)$p[3]; $s['month_amt'] = (float)$p[4]; }
        $o[] = $s;
    }
    usort($o, fn($a, $b) => $a['min'] <=> $b['min']);
    return $o;
}

function pay_pt_text(array $slabs): string {
    return implode("\n", array_map(fn($s) => rtrim(rtrim(sprintf('%.2f', $s['min']), '0'), '.') . ' ' . ($s['max'] === null ? '-' : rtrim(rtrim(sprintf('%.2f', $s['max']), '0'), '.')) . ' ' . rtrim(rtrim(sprintf('%.2f', $s['amt']), '0'), '.')
        . (isset($s['month']) ? ' ' . $s['month'] . ' ' . rtrim(rtrim(sprintf('%.2f', $s['month_amt']), '0'), '.') : ''), $slabs));
}

/** "Conveyance: 1600" lines → [[name, amount], …]. Pure. */
function pay_fixed_parse(string $text): array {
    $o = [];
    foreach (preg_split('/\R/', trim($text)) as $line) {
        if (trim($line) === '') continue;
        if (!preg_match('/^\s*([^:]{2,40}):\s*([0-9]+(?:\.[0-9]+)?)\s*$/', $line, $m)) throw new InvalidArgumentException('Allowance line "' . trim($line) . '": write Name: amount.');
        if (in_array(strtolower(trim($m[1])), ['basic', 'hra', 'special allowance'], true)) throw new InvalidArgumentException('"' . trim($m[1]) . '" is worked out by itself.');
        $o[] = [trim($m[1]), (float)$m[2]];
    }
    return $o;
}

/* ---------------- pure: structure and salary ---------------- */

/** Monthly parts from a gross. Pure. */
function pay_structure(float $G, array $R): array {
    $st = $R['structure'];
    $basic = min($G, max($G * (float)$st['basic_pct'] / 100, (float)$st['basic_min']));
    $out = ['Basic' => round($basic, 2)];
    $hra = round($basic * (float)$st['hra_pct'] / 100, 2);
    if ($hra > 0 && $basic + $hra <= $G) $out['HRA'] = $hra; elseif ($hra > 0 && $G - $basic > 0) $out['HRA'] = round($G - $basic, 2);
    $left = $G - array_sum($out);
    foreach ((array)$st['fixed'] as [$n, $a]) if ($a > 0 && $left >= $a) { $out[$n] = (float)$a; $left -= $a; }
    if ($left > 0.004) $out['Special allowance'] = round($left, 2);
    return $out;
}

/** Employer statutory for a full month at gross G. Pure given the pack function. */
function pay_employer_cost(float $G, array $emp, array $R, string $state, string $ym, callable $stat): float {
    return array_sum($stat(pay_structure($G, $R), $G, $emp, $R, $state, $ym)['er']);
}

/** Gross from a monthly CTC: CTC − employer statutory when CTC includes it (worked back), else CTC. Pure. */
function pay_gross_from_ctc(float $ctc, array $emp, array $R, string $state, string $ym, callable $stat): float {
    if (empty($R['ctc_includes_employer'])) return $ctc;
    $G = $ctc;
    for ($i = 0; $i < 40; $i++) {
        $n = round($ctc - pay_employer_cost($G, $emp, $R, $state, $ym, $stat), 2);
        if (abs($n - $G) < 0.01) break;
        $G = $n;
    }
    return max(0.0, floor($G));
}

/** Pay value of one attendance day line (0, 0.5, 1) or null when outside employment, with flags. Pure. */
function pay_day_value(array $l, array $paid, ?string $doj, ?string $last, string $today): array {
    $d = $l['day'];
    if (($doj && $d < $doj) || ($last && $d > $last)) return [null, []];
    if ($d > $today) return [1.0, []];
    $st = explode(' · ', (string)$l['status'])[0];
    $flags = [];
    if (!empty($l['leave'])) {
        $h = $l['leave']['halves']; $lh = ($h[0] ? .5 : 0) + ($h[1] ? .5 : 0);
        if ($lh > 0) {
            $t = (string)($l['leave']['type'] ?? '');
            if ($t === '') $flags[] = 'leave_type';
            $lp = $t === '' || ($paid[$t] ?? true) ? $lh : 0.0;
            return [$lp + ($l['in'] ? 1 - $lh : 0.0), $flags];
        }
    }
    if ($st === 'Missed punch') $flags[] = 'missed';
    $v = match ($st) { 'Present', 'Weekly off', 'Holiday', 'Missed punch', 'Checked in', 'Not in yet', '' => 1.0, 'Half day' => 0.5, default => 0.0 };
    return [$v, $flags];
}

/** Paid days for a month from day lines. Pure. */
function pay_paid_days(array $lines, array $paid, ?string $doj, ?string $last, string $today): array {
    $o = ['paid' => 0.0, 'in_month' => 0, 'unpaid' => 0.0, 'flags' => []];
    foreach ($lines as $l) {
        [$v, $f] = pay_day_value($l, $paid, $doj, $last, $today);
        if ($v === null) continue;
        $o['in_month']++; $o['paid'] += $v; $o['unpaid'] += 1 - $v;
        foreach ($f as $x) $o['flags'][$x][] = $l['day'];
    }
    return $o;
}

/**
 * One person's salary for a month. $p = [ctc, pf_on, pf_full, esic_on, pt_exempt], $att = [paid, days] (days = days in
 * the calendar month), $in = [other_earn, other_ded, tds]. Pure given the pack function.
 */
function pay_calc(array $p, array $att, array $in, array $R, string $state, string $ym, callable $stat): array {
    $G = pay_gross_from_ctc((float)$p['ctc'], $p, $R, $state, $ym, $stat);
    $parts = pay_structure($G, $R);
    $f = $att['days'] > 0 ? max(0.0, min(1.0, $att['paid'] / $att['days'])) : 0.0;
    $earn = array_map(fn($a) => round($a * $f), $parts);
    $s = $stat($earn, $G, $p, $R, $state, $ym);
    $other = round((float)($in['other_earn'] ?? 0), 2);
    $ded = $s['emp'];
    if (($t = round((float)($in['tds'] ?? 0), 2)) > 0) $ded['Income tax (TDS)'] = $t;
    if (($o = round((float)($in['other_ded'] ?? 0), 2)) > 0) $ded['Other deductions'] = $o;
    $gross = array_sum($earn) + $other;
    $net = $gross - array_sum($ded);
    $flags = $s['notes'];
    if ($net < 0) $flags[] = 'Deductions are more than the pay';
    return ['gross_full' => $G, 'parts' => $parts, 'earned' => $earn, 'other_earn' => $other, 'gross' => $gross, 'deductions' => $ded,
            'employer' => $s['er'], 'net' => $net, 'cost' => $gross + array_sum($s['er']), 'paid_days' => $att['paid'], 'days' => $att['days'], 'flags' => $flags];
}

/** The CTC in force for a month from its history rows. Pure. */
function pay_ctc_for(array $rows, string $ym): ?float {
    $pick = null;
    foreach ($rows as $r) if ($r['from_ym'] <= $ym && (!$pick || $r['from_ym'] > $pick['from_ym'] || ($r['from_ym'] === $pick['from_ym'] && $r['id'] > $pick['id']))) $pick = $r;
    return $pick ? (float)$pick['ctc'] : null;
}

/** Can this login take a step? Pure over the month and the user. */
function pay_can_step(array $u, array $month, string $step, array $R, ?int $preparedBy = null): ?string {
    return match ($step) {
        'check' => $month['status'] !== 'draft' ? 'Only a draft month is checked.' : (($preparedBy !== null && $preparedBy === (int)$u['id']) ? 'Someone other than the person who entered the inputs checks.' : null),
        'confirm' => $month['status'] !== 'checked' ? 'Check the month first.' : (!has_role($u, ...(array)$R['confirm_roles']) ? 'Only ' . implode(' / ', array_map(fn($r) => role_labels()[$r] ?? $r, (array)$R['confirm_roles'])) . ' confirms.' : null),
        'release' => $month['status'] !== 'confirmed' ? 'Confirm the month first.' : (!has_role($u, ...(array)$R['confirm_roles']) ? 'Only the confirming role releases.' : null),
        'reopen' => !in_array($month['status'], ['confirmed', 'released', 'checked'], true) ? 'The month is still a draft.' : (!has_role($u, ...(array)$R['confirm_roles']) && $month['status'] !== 'checked' ? 'Only the confirming role reopens.' : null),
        default => 'Unknown step.',
    };
}

/* ---------------- data ---------------- */

function pay_can(array $u): bool { return data_allowed($u, 'pay') && module_allowed($u, 'payroll'); }

function pay_rule_versions(PDO $db): array { return $db->query('SELECT * FROM pay_rule ORDER BY from_ym, id')->fetchAll(); }
function pay_rules(PDO $db, string $ym): array { return pay_rules_for(pay_rule_versions($db), $ym, pay_default_rules()); }

function pay_rule_save(PDO $db, array $u, string $fromYm, array $rules, string $reason): void {
    if (!is_superadmin($u) && !has_role($u, 'CEO')) throw new RuntimeException('Only the Super Admin or CEO changes payroll rules.');
    if (!preg_match('/^\d{4}-\d{2}$/', $fromYm)) throw new InvalidArgumentException('Pick the month the rules apply from.');
    $s = $db->prepare("SELECT ym FROM pay_month WHERE ym >= ? AND status IN ('confirmed','released') ORDER BY ym LIMIT 1"); $s->execute([$fromYm]);
    if ($c = $s->fetchColumn()) throw new RuntimeException(date('F Y', strtotime($c . '-01')) . ' is already confirmed — rules can start after it, or reopen that month first.');
    if (trim($reason) === '') throw new InvalidArgumentException('Give a reason.');
    $db->prepare('INSERT INTO pay_rule (from_ym, rules, reason, by_user) VALUES (?,?,?,?)')->execute([$fromYm, json_encode($rules), mb_substr(trim($reason), 0, 300), (int)$u['id']]);
    audit((int)$u['id'], 'pay_rules', 'from ' . $fromYm);
}

function pay_profile(PDO $db, string $code): array {
    $s = $db->prepare('SELECT * FROM pay_emp WHERE emp_code = ?'); $s->execute([$code]);
    return $s->fetch() ?: ['emp_code' => $code, 'pf_on' => 1, 'pf_full' => 0, 'esic_on' => null, 'pt_exempt' => 0, 'pay_mode' => 'bank', 'active' => 1, 'off_reason' => ''];
}

function pay_ctc_rows(PDO $db, ?string $code = null): array {
    if ($code !== null) { $s = $db->prepare('SELECT * FROM pay_ctc WHERE emp_code = ? ORDER BY from_ym, id'); $s->execute([$code]); return $s->fetchAll(); }
    $o = [];
    foreach ($db->query('SELECT * FROM pay_ctc ORDER BY from_ym, id') as $r) $o[strtoupper($r['emp_code'])][] = $r;
    return $o;
}

function pay_profile_save(PDO $db, array $u, string $code, array $in): void {
    if (!pay_can($u)) throw new RuntimeException('Not allowed.');
    $e = emp_by_code($db, $code);
    if (!$e) throw new RuntimeException('No such employee.');
    if (strcasecmp($code, (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Not for your own pay.');
    $esic = ($in['esic_on'] ?? '') === '' ? null : (int)($in['esic_on'] === '1');
    $mode = isset(PAY_MODES[$in['pay_mode'] ?? '']) ? $in['pay_mode'] : 'bank';
    $db->prepare('INSERT INTO pay_emp (emp_code, pf_on, pf_full, esic_on, pt_exempt, pay_mode, updated_by) VALUES (?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE pf_on = VALUES(pf_on), pf_full = VALUES(pf_full), esic_on = VALUES(esic_on), pt_exempt = VALUES(pt_exempt), pay_mode = VALUES(pay_mode), updated_by = VALUES(updated_by)')
       ->execute([$e['code'], !empty($in['pf_on']) ? 1 : 0, !empty($in['pf_full']) ? 1 : 0, $esic, !empty($in['pt_exempt']) ? 1 : 0, $mode, (int)$u['id']]);
    $ctc = trim((string)($in['ctc'] ?? ''));
    if ($ctc !== '') {
        if (!is_numeric($ctc) || (float)$ctc < 0 || (float)$ctc > 100000000) throw new InvalidArgumentException('CTC: a monthly amount.');
        $from = (string)($in['from_ym'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $from)) throw new InvalidArgumentException('Pick the month the CTC applies from.');
        $s = $db->prepare("SELECT COUNT(*) FROM pay_month WHERE ym >= ? AND status IN ('confirmed','released')"); $s->execute([$from]);
        if ((int)$s->fetchColumn()) throw new RuntimeException('A confirmed month comes after that — start the CTC after it.');
        $db->prepare('INSERT INTO pay_ctc (emp_code, from_ym, ctc, note, by_user) VALUES (?,?,?,?,?)')->execute([$e['code'], $from, round((float)$ctc, 2), mb_substr(trim((string)($in['note'] ?? '')), 0, 300), (int)$u['id']]);
    }
    audit((int)$u['id'], 'pay_profile', $e['code'] . ($ctc !== '' ? ' ctc' : ''));
}

function pay_month_row(PDO $db, string $ym): array {
    $s = $db->prepare('SELECT * FROM pay_month WHERE ym = ?'); $s->execute([$ym]);
    return $s->fetch() ?: ['ym' => $ym, 'status' => 'draft', 'ver' => 0];
}

function pay_log(PDO $db, string $ym, string $t, ?int $by): void { $db->prepare('INSERT INTO pay_log (ym, text, by_user) VALUES (?,?,?)')->execute([$ym, mb_substr($t, 0, 300), $by]); }

function pay_inputs(PDO $db, string $ym): array {
    $s = $db->prepare('SELECT * FROM pay_input WHERE ym = ?'); $s->execute([$ym]);
    $o = []; foreach ($s as $r) $o[strtoupper($r['emp_code'])] = $r; return $o;
}

function pay_input_save(PDO $db, array $u, string $ym, string $code, array $in): void {
    if (!pay_can($u)) throw new RuntimeException('Not allowed.');
    if (pay_month_row($db, $ym)['status'] !== 'draft') throw new RuntimeException('The month is not a draft — reopen it to change inputs.');
    if (strcasecmp($code, (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Not for your own pay.');
    $n = fn($k) => round(max(0.0, (float)($in[$k] ?? 0)), 2);
    $hold = !empty($in['hold']);
    $why = trim(mb_substr(strip_tags((string)($in['hold_reason'] ?? '')), 0, 300));
    if ($hold && $why === '') throw new InvalidArgumentException('Give the reason for holding the salary.');
    $db->prepare('INSERT INTO pay_input (ym, emp_code, other_earn, other_ded, tds, note, hold, hold_reason, by_user) VALUES (?,?,?,?,?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE other_earn = VALUES(other_earn), other_ded = VALUES(other_ded), tds = VALUES(tds), note = VALUES(note), hold = VALUES(hold), hold_reason = VALUES(hold_reason), by_user = VALUES(by_user)')
       ->execute([$ym, strtoupper($code), $n('other_earn'), $n('other_ded'), $n('tds'), mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 300), $hold ? 1 : 0, $hold ? $why : '', (int)$u['id']]);
    audit((int)$u['id'], 'pay_input', $ym . ' ' . strtoupper($code));
}

/** Everyone on payroll for a month, worked out now (draft) or read from the snapshot (confirmed). */
function pay_build(PDO $db, string $ym, ?array $u = null): array {
    $m = pay_month_row($db, $ym);
    if (in_array($m['status'], ['confirmed', 'released'], true)) {
        $s = $db->prepare('SELECT emp_code, data FROM pay_snapshot WHERE ym = ? AND ver = ? ORDER BY emp_code'); $s->execute([$ym, $m['ver']]);
        $rows = array_map(fn($r) => json_decode($r['data'], true), $s->fetchAll());
        return $u ? array_values(array_filter($rows, fn($r) => emp_in_scope($u, ['branch_id' => $r['branch_id']]))) : $rows;
    }
    $R = pay_rules($db, $ym); $pack = pay_pack(); $stat = $pack['fn'];
    $from = $ym . '-01'; $to = date('Y-m-t', strtotime($from)); $days = (int)date('t', strtotime($from)); $today = date('Y-m-d');
    $paidMap = [];
    foreach (lv_types($db, false) as $t) $paidMap[$t['code']] = $R['paid_leave'] ? in_array($t['code'], $R['paid_leave'], true) : (bool)$t['paid'];
    $ctc = pay_ctc_rows($db); $inputs = pay_inputs($db, $ym);
    $brs = array_column(branches(false), null, 'id');
    $s = $db->prepare('SELECT * FROM employee WHERE active = 1 AND doj IS NOT NULL AND doj <= ? AND (last_day IS NULL OR last_day >= ?) ORDER BY name');
    $s->execute([$to, $from]);
    $emps = $s->fetchAll();
    $codes = array_column($emps, 'code');
    $punches = $codes ? att_punches($db, $from, $to, $codes) : [];
    $out = [];
    foreach ($emps as $e) {
        if ($u && !emp_in_scope($u, $e)) continue;
        $p = pay_profile($db, $e['code']);
        if (!(int)$p['active']) continue;
        $c = pay_ctc_for($ctc[strtoupper($e['code'])] ?? [], $ym);
        $lines = att_days_for($db, $e, $from, $to, $punches[strtoupper($e['code'])] ?? []);
        $pd = pay_paid_days($lines, $paidMap, $e['doj'], $e['last_day'], $today);
        $in = $inputs[strtoupper($e['code'])] ?? [];
        $state = (string)($brs[$e['branch_id']]['state'] ?? '');
        $row = ['emp_code' => $e['code'], 'name' => $e['name'], 'branch_id' => $e['branch_id'], 'department' => $e['department'], 'pay_mode' => $p['pay_mode'],
                'hold' => (int)($in['hold'] ?? 0), 'hold_reason' => (string)($in['hold_reason'] ?? ''), 'note' => (string)($in['note'] ?? ''), 'ctc' => $c, 'state' => $state];
        if ($c === null) { $out[] = $row + ['missing_ctc' => true, 'net' => 0, 'gross' => 0, 'cost' => 0, 'flags' => ['No CTC'], 'paid_days' => $pd['paid'], 'days' => $days]; continue; }
        $calc = pay_calc(['ctc' => $c] + $p, ['paid' => $pd['paid'], 'days' => $days], $in, $R, $state, $ym, $stat);
        $flags = $calc['flags'];
        if (!empty($pd['flags']['missed'])) $flags[] = count($pd['flags']['missed']) . ' missed punch(es)';
        if (!empty($pd['flags']['leave_type'])) $flags[] = 'Leave without a type on ' . count($pd['flags']['leave_type']) . ' day(s)';
        if ($pd['in_month'] < $days) $flags[] = 'Part of the month (' . $pd['in_month'] . ' of ' . $days . ' days)';
        $out[] = $row + $calc + ['flags' => $flags, 'unpaid' => $pd['unpaid']];
    }
    return $out;
}

/** A step on the month. Confirm makes the snapshot (new version); reopen keeps the old snapshot. */
function pay_step(PDO $db, array $u, string $ym, string $step, string $note = ''): void {
    if (!pay_can($u)) throw new RuntimeException('Not allowed.');
    $m = pay_month_row($db, $ym); $R = pay_rules($db, $ym);
    $lastBy = $db->prepare('SELECT by_user FROM pay_input WHERE ym = ? ORDER BY at DESC LIMIT 1'); $lastBy->execute([$ym]);
    if ($err = pay_can_step($u, $m, $step, $R, ($b = $lastBy->fetchColumn()) !== false ? (int)$b : null)) throw new RuntimeException($err);
    $uid = (int)$u['id'];
    $db->prepare('INSERT IGNORE INTO pay_month (ym) VALUES (?)')->execute([$ym]);
    if ($step === 'check') {
        $rows = pay_build($db, $ym);
        if ($miss = array_filter($rows, fn($r) => !empty($r['missing_ctc']))) throw new RuntimeException(count($miss) . ' people have no CTC — enter it or switch them off payroll first.');
        $db->prepare("UPDATE pay_month SET status = 'checked', checked_by = ?, checked_at = NOW() WHERE ym = ?")->execute([$uid, $ym]);
    } elseif ($step === 'confirm') {
        if ($ym >= date('Y-m')) throw new RuntimeException('A month is confirmed once it has ended.');
        $rows = pay_build($db, $ym);
        $ver = (int)$m['ver'] + 1;
        $db->beginTransaction();
        try {
            $ins = $db->prepare('INSERT INTO pay_snapshot (ym, ver, emp_code, data) VALUES (?,?,?,?)');
            foreach ($rows as $r) $ins->execute([$ym, $ver, $r['emp_code'], json_encode($r)]);
            $db->prepare("UPDATE pay_month SET status = 'confirmed', ver = ?, confirmed_by = ?, confirmed_at = NOW() WHERE ym = ?")->execute([$ver, $uid, $ym]);
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
    } elseif ($step === 'release') {
        $db->prepare("UPDATE pay_month SET status = 'released', released_by = ?, released_at = NOW() WHERE ym = ?")->execute([$uid, $ym]);
    } elseif ($step === 'reopen') {
        if (trim($note) === '') throw new InvalidArgumentException('Give the reason for reopening.');
        $db->prepare("UPDATE pay_month SET status = 'draft', checked_by = NULL, checked_at = NULL WHERE ym = ?")->execute([$ym]);
    }
    pay_log($db, $ym, ucfirst($step) . ($note !== '' ? ': ' . $note : ''), $uid);
    audit($uid, 'pay_' . $step, $ym);
}

/** Totals for a month's rows. Pure. */
function pay_totals(array $rows): array {
    $t = ['people' => 0, 'gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'employer' => 0.0, 'cost' => 0.0, 'held' => 0, 'flags' => 0];
    foreach ($rows as $r) {
        $t['people']++;
        if (!empty($r['missing_ctc'])) { $t['flags']++; continue; }
        $t['gross'] += $r['gross']; $t['deductions'] += array_sum($r['deductions']); $t['net'] += $r['net']; $t['employer'] += array_sum($r['employer']); $t['cost'] += $r['cost'];
        if ($r['hold']) $t['held']++;
        if ($r['flags']) $t['flags']++;
    }
    return $t;
}

function pay_money(float $v): string {
    $c = org('currency');
    $sym = ['INR' => '₹', 'GBP' => '£', 'USD' => '$', 'EUR' => '€', 'AED' => 'AED '][$c] ?? '';
    if ($c === 'INR') {   // 12,34,567
        $n = (string)round(abs($v)); $last = substr($n, -3); $rest = substr($n, 0, -3);
        $f = $rest !== '' ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last : $last;
        return ($v < 0 ? '−' : '') . $sym . $f;
    }
    return ($v < 0 ? '−' : '') . $sym . number_format(abs($v), 0);
}
