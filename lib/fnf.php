<?php
/**
 * Full & final settlement for leavers. Ported from the reference build as rules; every figure the reference fixed for
 * one company is a setting here.
 *  - Leave taken beyond the pro-rated share of the year is cut: share = days a year × months / 12, counted from the
 *    year's first month (or the joining month, if later) to the leaving month, both in full. The days a year and the
 *    leave types counted come from the Leave module's types unless the Super Admin sets them. Cut = excess days ×
 *    monthly gross ÷ calendar days of the leaving month. Nobody still on probation at the last working day gets the cut.
 *    HR may type the days instead (the worked-out figure is shown beside them).
 *  - Unused leave is paid only when the setting says so (with an optional limit), at the same day rate.
 *  - Pay is for the days up to the last working day; a month can be marked "full month as agreed".
 *  - Each month's tax by state (the country pack's deductions other than provident fund and state insurance — e.g.
 *    professional tax) is worked out on what is payable for that month; the leaving month carries the cut and extras.
 * Steps: Draft → Checked (not the preparer) → Approved → Paid (payment reference). Sent back returns to Draft with a
 * reason; only a draft changes. Amounts only for the pay data class; never on one's own settlement; the audit and the
 * log never carry amounts.
 */
declare(strict_types=1);

require_once __DIR__ . '/payroll.php';

const FNF_STATUS = ['draft' => 'Draft', 'checked' => 'Checked', 'approved' => 'Approved', 'paid' => 'Paid & closed'];
const FNF_DEFAULTS = ['leave_codes' => [], 'leave_days' => null, 'pay_unused' => false, 'encash_max' => 0,
    'prepare_roles' => ['SUPERADMIN', 'HRMANAGER'], 'approve_roles' => ['CEO', 'SUPERADMIN'], 'notice_text' => ''];
const FNF_DOCS = ['statement' => 'Settlement statement', 'ack' => 'Acknowledgement'];

/* ---------------- settings ---------------- */

function fnf_settings(): array {
    $s = module_states()['fnf']['settings'] ?? [];
    return array_replace(FNF_DEFAULTS, array_intersect_key($s, FNF_DEFAULTS));
}

/** Check a settings form. $types = leave types (code => row), $roles = role code => label. Pure. Returns [settings, errors]. */
function fnf_settings_check(array $in, array $types, array $roles): array {
    $o = []; $e = [];
    $o['leave_codes'] = array_values(array_intersect(array_keys($types), (array)($in['leave_codes'] ?? [])));
    $d = trim((string)($in['leave_days'] ?? ''));
    if ($d === '') $o['leave_days'] = null;
    elseif (!is_numeric($d) || (float)$d < 0 || (float)$d > 366) $e[] = 'Leave days a year: a number from 0 to 366, or blank.';
    else $o['leave_days'] = round((float)$d, 2);
    $o['pay_unused'] = !empty($in['pay_unused']);
    $m = filter_var($in['encash_max'] ?? 0, FILTER_VALIDATE_INT);
    if ($m === false || $m < 0 || $m > 366) $e[] = 'Encashment limit: whole days 0–366 (0 = no limit).'; else $o['encash_max'] = $m;
    foreach (['prepare_roles', 'approve_roles'] as $k) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = 'Pick at least one role for ' . ($k === 'prepare_roles' ? 'preparing' : 'approving') . '.';
    }
    $o['notice_text'] = mb_substr(trim(strip_tags((string)($in['notice_text'] ?? ''))), 0, 1200);
    return [$o, $e];
}

/** Leave types counted for the cut, and the days a year. $types = rows of leave_type. Pure. */
function fnf_leave_basis(array $types, array $set): array {
    $codes = $set['leave_codes'];
    if (!$codes) foreach ($types as $t) if ((int)$t['active'] && (int)$t['paid'] && $t['per_year'] !== null && $t['per_year'] !== '' && (string)$t['on_day'] === '') $codes[] = $t['code'];
    $days = $set['leave_days'];
    if ($days === null) { $days = 0.0; foreach ($types as $t) if (in_array($t['code'], $codes, true)) $days += (float)$t['per_year']; }
    return ['codes' => array_values($codes), 'days' => (float)$days];
}

/* ---------------- pure: the settlement ---------------- */

/** First month (YYYY-MM) of the year holding $date when the year starts in month $start. Pure. */
function fnf_year_start(string $date, int $start): string {
    $y = (int)substr($date, 0, 4); $m = (int)substr($date, 5, 2);
    return sprintf('%04d-%02d', $m >= $start ? $y : $y - 1, $start);
}

/** Months counted for the share: the year's first month (or the joining month, if later) to the leaving month. Pure. */
function fnf_leave_months(string $lwd, string $doj, int $start): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lwd)) return 0;
    $from = fnf_year_start($lwd, $start);
    if (preg_match('/^\d{4}-\d{2}/', $doj) && substr($doj, 0, 7) > $from) $from = substr($doj, 0, 7);
    $to = substr($lwd, 0, 7);
    if ($from > $to) return 0;
    return ((int)substr($to, 0, 4) - (int)substr($from, 0, 4)) * 12 + (int)substr($to, 5, 2) - (int)substr($from, 5, 2) + 1;
}

/** "31 days October" / "6.5 of 31 days October". Pure. */
function fnf_month_label(string $ym, float $days, int $md, bool $agreed): string {
    $d = rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.');
    return ($days >= $md ? "$md days" : "$d of $md days") . ' ' . date('F Y', strtotime($ym . '-01')) . ($agreed ? ' (full month as agreed)' : '');
}

/**
 * The settlement. $f: rate (monthly gross), lwd, doj, probation, taken (counted leave taken this year), adj_days (typed,
 * '' = work it out), months [[ym, days, full]], earn / ded [[label, amount]], paid_before.
 * $b = fnf_leave_basis(); $tax = fn(float $base, string $ym): float for a month's tax by state. Pure.
 */
function fnf_calc(array $f, array $b, array $set, int $yearStart, callable $tax): array {
    $rate = max(0.0, (float)($f['rate'] ?? 0)); $lwd = (string)($f['lwd'] ?? '');
    $lwdYm = substr($lwd, 0, 7); $lwdDays = preg_match('/^\d{4}-\d{2}$/', $lwdYm) ? (int)date('t', strtotime($lwdYm . '-01')) : 30;
    $prob = !empty($f['probation']);
    $months = fnf_leave_months($lwd, (string)($f['doj'] ?? ''), $yearStart);
    $ent = $prob ? 0.0 : $b['days'] * $months / 12;
    $taken = max(0.0, (float)($f['taken'] ?? 0));
    $worked = $prob ? 0.0 : max(0.0, $taken - $ent);
    $typed = trim((string)($f['adj_days'] ?? ''));
    $isTyped = $typed !== '' && is_numeric($typed);
    $excess = $isTyped ? max(0.0, (float)$typed) : $worked;
    $perDay = $rate / max(1, $lwdDays);
    $cut = round($excess * $perDay);
    $unused = $prob ? 0.0 : max(0.0, $ent - $taken);
    if ((int)$set['encash_max'] > 0) $unused = min($unused, (float)$set['encash_max']);
    $encash = !empty($set['pay_unused']) && !$isTyped && $worked == 0.0 ? round($unused * $perDay) : 0.0;

    $earn = []; $extra = 0.0;
    foreach ((array)($f['earn'] ?? []) as $x) if (trim((string)($x['label'] ?? '')) !== '' && round((float)($x['amount'] ?? 0)) != 0) { $earn[] = ['label' => trim((string)$x['label']), 'amount' => round((float)$x['amount'])]; $extra += round((float)$x['amount']); }
    if ($encash > 0) { $earn[] = ['label' => 'Unused leave (' . round($unused, 2) . ' days)', 'amount' => $encash]; $extra += $encash; }
    $ded = [];
    foreach ((array)($f['ded'] ?? []) as $x) if (trim((string)($x['label'] ?? '')) !== '' && round((float)($x['amount'] ?? 0)) != 0) $ded[] = ['label' => trim((string)$x['label']), 'amount' => round((float)$x['amount'])];

    $mon = array_values(array_filter((array)($f['months'] ?? []), fn($m) => preg_match('/^\d{4}-\d{2}$/', (string)($m['ym'] ?? ''))));
    usort($mon, fn($a, $b2) => strcmp((string)$a['ym'], (string)$b2['ym']));
    $lines = []; $pay = 0.0; $last = count($mon) - 1;
    foreach ($mon as $i => $m) {
        $md = (int)date('t', strtotime($m['ym'] . '-01'));
        $full = !empty($m['full']);
        $days = $full ? (float)$md : max(0.0, min((float)$md, (float)($m['days'] ?? 0)));
        $amt = $full ? round($rate) : round($rate * $days / $md);
        $base = max(0.0, $amt + ($i === $last ? $extra - $cut : 0));
        $t = round((float)$tax($base, (string)$m['ym']));
        $agreed = $full && $m['ym'] === $lwdYm && (int)substr($lwd, 8, 2) < $md;
        $lines[] = ['ym' => $m['ym'], 'label' => fnf_month_label($m['ym'], $days, $md, $agreed), 'days' => $days, 'month_days' => $md, 'full' => $full, 'amount' => $amt, 'tax' => $t];
        $pay += $amt;
    }
    $earnTotal = $pay + $extra;
    $taxTotal = array_sum(array_column($lines, 'tax'));
    $dedTotal = $taxTotal + $cut + array_sum(array_column($ded, 'amount'));
    $net = $earnTotal - $dedTotal;
    $before = max(0.0, round((float)($f['paid_before'] ?? 0)));
    return ['lines' => $lines, 'earn' => $earn, 'ded' => $ded, 'earn_total' => $earnTotal, 'tax_total' => $taxTotal,
            'leave' => ['probation' => $prob, 'months' => $months, 'entitled' => round($ent, 2), 'taken' => round($taken, 2), 'excess' => round($excess, 2),
                        'worked' => round($worked, 2), 'typed' => $isTyped, 'per_day' => round($perDay, 2), 'cut' => $cut, 'unused' => round($unused, 2), 'encash' => $encash],
            'ded_total' => $dedTotal, 'net' => $net, 'paid_before' => $before, 'balance' => $net - $before];
}

/** A whole amount in words: Indian grouping (lakh, crore) for INR, else thousand / million / billion. Pure. */
function fnf_words(float $n, string $currency = ''): string {
    $n = (int)round(abs($n));
    if ($n === 0) return 'Zero';
    $one = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $ten = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = fn(int $x) => $x < 20 ? $one[$x] : $ten[intdiv($x, 10)] . ($x % 10 ? '-' . $one[$x % 10] : '');
    $three = fn(int $x) => trim(($x >= 100 ? $one[intdiv($x, 100)] . ' Hundred ' : '') . ($x % 100 ? $two($x % 100) : ''));
    $steps = $currency === 'INR' ? [[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] : [[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand']];
    $out = [];
    foreach ($steps as [$d, $w]) if ($n >= $d) { $q = intdiv($n, $d); $out[] = ($q >= 1000 ? fnf_words((float)$q, $currency) : $three($q)) . " $w"; $n %= $d; }
    if ($n) $out[] = $three($n);
    return implode(' ', $out);
}

/** Form post → the stored body. Pure. */
function fnf_clean(array $in): array {
    $s = fn(string $k, int $n = 160) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $n);
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in[$k] ?? '')) ? (string)$in[$k] : '';
    $num = fn($v) => is_numeric($v) ? (float)$v : 0.0;
    $f = [];
    foreach (['code' => 32, 'name' => 160, 'designation' => 120, 'department' => 120, 'branch' => 80, 'state' => 40, 'address' => 400, 'reason' => 160, 'note' => 400] as $k => $n) $f[$k] = $s($k, $n);
    $f['code'] = strtoupper($f['code']);
    foreach (['lwd', 'doj', 'resigned', 'settle_date'] as $k) $f[$k] = $d($k);
    foreach (['rate', 'taken', 'paid_before'] as $k) $f[$k] = round(max(0.0, $num($in[$k] ?? 0)), 2);
    $ad = trim((string)($in['adj_days'] ?? ''));
    $f['adj_days'] = ($ad !== '' && is_numeric($ad)) ? (string)round(min(366.0, max(0.0, (float)$ad)), 2) : '';
    $f['probation'] = !empty($in['probation']); $f['tax_exempt'] = !empty($in['tax_exempt']);
    $f['paid_before_note'] = $s('paid_before_note', 160);
    $f['months'] = [];
    foreach ((array)($in['m_ym'] ?? []) as $i => $ym) {
        if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) continue;
        $f['months'][] = ['ym' => (string)$ym, 'days' => round(max(0.0, $num($in['m_days'][$i] ?? 0)), 2), 'full' => !empty($in['m_full'][$i])];
    }
    foreach (['earn', 'ded'] as $g) {
        $f[$g] = [];
        foreach ((array)($in[$g . '_label'] ?? []) as $i => $l) if (trim((string)$l) !== '') $f[$g][] = ['label' => mb_substr(trim(strip_tags((string)$l)), 0, 80), 'amount' => round($num($in[$g . '_amt'][$i] ?? 0))];
    }
    return $f;
}

/** What a settlement must have. Pure. Returns the first problem or null. */
function fnf_problem(array $f): ?string {
    if ($f['code'] === '' || $f['name'] === '') return 'Pick the employee.';
    if ($f['lwd'] === '') return 'Give the last working day.';
    if ($f['rate'] <= 0) return 'Give the monthly gross.';
    if (!$f['months']) return 'Add at least one month to pay.';
    foreach ($f['months'] as $m) if ($m['ym'] > substr($f['lwd'], 0, 7)) return 'A month after the last working day cannot be paid here.';
    return null;
}

/**
 * Who may do a step: amounts need the pay data class; prepare / pay by the prepare roles, approve by the approve roles,
 * check by a prepare or approve role. $own = the settlement is the login's own. Pure.
 */
function fnf_can(array $u, string $step, array $set, bool $own = false): bool {
    if (!data_allowed($u, 'pay') || $own) return false;
    return match ($step) {
        'see' => true,
        'prepare', 'pay' => has_role($u, ...$set['prepare_roles']),
        'approve' => has_role($u, ...$set['approve_roles']),
        'check' => has_role($u, ...$set['prepare_roles']) || has_role($u, ...$set['approve_roles']),
        default => false,
    };
}

/** Whether a step is allowed on a settlement row; null = yes, else the reason. Pure. */
function fnf_step_problem(array $u, array $r, string $act, array $set, string $note = ''): ?string {
    $own = strcasecmp((string)$r['emp_code'], (string)($u['emp_code'] ?? '')) === 0;
    if ($own) return 'Not on your own settlement.';
    if (!(int)$r['active']) return 'This settlement is switched off.';
    $st = $r['status']; $uid = (int)$u['id'];
    return match ($act) {
        'check' => $st !== 'draft' ? 'Only a draft is checked.' : (!fnf_can($u, 'check', $set) ? 'Not allowed to check.' : ((int)$r['prepared_by'] === $uid ? 'The person who prepared it cannot check it.' : null)),
        'approve' => $st !== 'checked' ? 'It must be checked first.' : (!fnf_can($u, 'approve', $set) ? 'Not allowed to approve.' : ((int)$r['prepared_by'] === $uid ? 'The person who prepared it cannot approve it.' : null)),
        'back' => !in_array($st, ['checked', 'approved'], true) ? 'Only a checked or approved settlement is sent back.'
            : (!fnf_can($u, $st === 'approved' ? 'approve' : 'check', $set) ? 'Not allowed.' : (trim($note) === '' ? 'Give the reason.' : null)),
        'paid' => $st !== 'approved' ? 'Only an approved settlement is marked paid.' : (!fnf_can($u, 'pay', $set) ? 'Not allowed.' : (trim($note) === '' ? 'Give the payment reference.' : null)),
        'off' => $st === 'paid' ? 'A paid settlement stays.' : (!fnf_can($u, 'prepare', $set) ? 'Not allowed.' : (trim($note) === '' ? 'Give the reason.' : null)),
        default => 'Unknown step.',
    };
}

/* ---------------- database ---------------- */

function fnf_log(PDO $db, int $id, string $t, ?int $by): void {
    $db->prepare('INSERT INTO fnf_log (fnf_id, text, by_user) VALUES (?,?,?)')->execute([$id, mb_substr($t, 0, 300), $by]);
}

function fnf_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM fnf WHERE id = ?'); $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) return null;
    $r['f'] = json_decode((string)$r['body'], true) ?: [];
    return $r;
}

/** Settlements the login may see (branch scope from the employee), newest first. */
function fnf_list(PDO $db, array $u, bool $all = false): array {
    $rows = $db->query('SELECT * FROM fnf ' . ($all ? '' : 'WHERE active = 1 ') . 'ORDER BY lwd DESC, id DESC')->fetchAll();
    return array_values(array_filter($rows, function ($r) use ($db, $u) { $e = emp_by_code($db, $r['emp_code']); return !$e || emp_in_scope($u, $e); }));
}

/** The year's start month, the leave basis and the month tax function for a settlement. */
function fnf_context(PDO $db, array $f): array {
    $set = fnf_settings();
    $b = fnf_leave_basis(lv_types($db, false), $set);
    $ym = substr((string)($f['lwd'] ?: date('Y-m-d')), 0, 7);
    $R = pay_rules($db, $ym); $stat = pay_pack()['fn'];
    $state = (string)($f['state'] ?? ''); $ex = !empty($f['tax_exempt']);
    $tax = function (float $base, string $m) use ($stat, $db, $state, $ex) {
        if ($base <= 0 || $state === '') return 0.0;
        $o = $stat(['Gross' => $base], $base, ['pf_on' => 0, 'pf_full' => 0, 'esic_on' => 0, 'pt_exempt' => $ex ? 1 : 0], pay_rules($db, $m), $state, $m);
        return (float)array_sum($o['emp']);
    };
    return ['set' => $set, 'basis' => $b, 'start' => (int)(org('fy_start_month') ?: 1), 'tax' => $tax];
}

function fnf_figures(PDO $db, array $f): array {
    $c = fnf_context($db, $f);
    return fnf_calc($f, $c['basis'], $c['set'], $c['start'], $c['tax']);
}

/** People who have left or are leaving within the last few months (a last day set on the employee master). */
function fnf_leavers(PDO $db, array $u, int $months = 6): array {
    $s = $db->prepare('SELECT * FROM employee WHERE last_day IS NOT NULL AND last_day >= ? ORDER BY last_day DESC');
    $s->execute([date('Y-m-01', strtotime("-$months months"))]);
    $done = array_flip(array_map('strtoupper', $db->query('SELECT emp_code FROM fnf WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN)));
    $own = strtoupper((string)($u['emp_code'] ?? ''));
    $o = [];
    foreach ($s as $e) if (emp_in_scope($u, $e) && strtoupper($e['code']) !== $own) $o[] = $e + ['has_fnf' => isset($done[strtoupper($e['code'])])];
    return $o;
}

/** Counted leave approved for a person in the year up to the last working day. */
function fnf_leave_taken(PDO $db, string $code, string $lwd, array $codes, int $start): float {
    if (!$codes) return 0.0;
    $from = fnf_year_start($lwd, $start) . '-01';
    $in = implode(',', array_fill(0, count($codes), '?'));
    $s = $db->prepare("SELECT COALESCE(SUM(days), 0) FROM leave_request WHERE emp_code = ? AND status = 'approved' AND leave_type IN ($in) AND from_date BETWEEN ? AND ?");
    $s->execute(array_merge([strtoupper($code)], $codes, [$from, $lwd]));
    return (float)$s->fetchColumn();
}

/** A settlement filled from the records: employee master, payroll profile and CTC, leave, locked payroll months. HR checks every field. */
function fnf_prefill(PDO $db, array $e): array {
    $lwd = (string)($e['last_day'] ?: date('Y-m-d'));
    $brs = array_column(branches(false), null, 'id');
    $state = (string)($brs[$e['branch_id']]['state'] ?? '');
    $ym = substr($lwd, 0, 7);
    $R = pay_rules($db, $ym); $stat = pay_pack()['fn'];
    $p = pay_profile($db, $e['code']);
    $ctc = pay_ctc_for(pay_ctc_rows($db)[strtoupper($e['code'])] ?? [], $ym);
    $rate = $ctc === null ? 0.0 : round(pay_gross_from_ctc($ctc, $p, $R, $state, $ym, $stat), 2);
    $c = fnf_context($db, ['lwd' => $lwd]);
    $locked = (string)$db->query("SELECT COALESCE(MAX(ym), '') FROM pay_month WHERE status IN ('confirmed','released')")->fetchColumn();
    $from = $locked !== '' ? date('Y-m', strtotime($locked . '-01 +1 month')) : $ym;
    if (!empty($e['doj']) && substr((string)$e['doj'], 0, 7) > $from) $from = substr((string)$e['doj'], 0, 7);
    $months = [];
    for ($m = $from; $m <= $ym; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $md = (int)date('t', strtotime($m . '-01'));
        $first = (!empty($e['doj']) && substr((string)$e['doj'], 0, 7) === $m) ? (int)substr((string)$e['doj'], 8, 2) : 1;
        $lastD = $m === $ym ? (int)substr($lwd, 8, 2) : $md;
        $months[] = ['ym' => $m, 'days' => max(0, $lastD - $first + 1), 'full' => false];
    }
    return ['code' => $e['code'], 'name' => $e['name'], 'designation' => (string)($e['designation'] ?? ''), 'department' => (string)($e['department'] ?? ''),
            'branch' => (string)($brs[$e['branch_id']]['name'] ?? ''), 'state' => $state, 'address' => '', 'reason' => '', 'note' => '',
            'lwd' => $lwd, 'doj' => (string)($e['doj'] ?? ''), 'resigned' => '', 'settle_date' => '', 'rate' => $rate,
            'taken' => fnf_leave_taken($db, $e['code'], $lwd, $c['basis']['codes'], $c['start']), 'adj_days' => '',
            'probation' => empty($e['confirm_date']) || (string)$e['confirm_date'] > $lwd, 'tax_exempt' => !empty($p['pt_exempt']),
            'paid_before' => 0.0, 'paid_before_note' => '', 'months' => $months, 'earn' => [], 'ded' => [],
            'missing' => $ctc === null ? 'No CTC on payroll for that month — type the monthly gross.' : ''];
}

function fnf_save(PDO $db, array $u, ?int $id, array $in): int {
    $set = fnf_settings();
    $f = fnf_clean($in);
    if ($p = fnf_problem($f)) throw new InvalidArgumentException($p);
    $e = emp_by_code($db, $f['code']);
    if (!$e || !emp_in_scope($u, $e)) throw new RuntimeException('Not found.');
    if (!fnf_can($u, 'prepare', $set, strcasecmp($f['code'], (string)($u['emp_code'] ?? '')) === 0)) throw new RuntimeException('Not allowed to prepare this settlement.');
    $body = json_encode($f, JSON_UNESCAPED_UNICODE);
    if ($id) {
        $r = fnf_get($db, $id);
        if (!$r || !(int)$r['active']) throw new RuntimeException('Not found.');
        if ($r['status'] !== 'draft') throw new RuntimeException('Only a draft changes — send it back first.');
        if (strcasecmp($r['emp_code'], $f['code']) !== 0) throw new RuntimeException('A settlement stays with its employee.');
        $db->prepare('UPDATE fnf SET name = ?, lwd = ?, body = ?, prepared_by = ?, prepared_at = NOW(), updated_at = NOW() WHERE id = ?')->execute([$f['name'], $f['lwd'], $body, (int)$u['id'], $id]);
        fnf_log($db, $id, 'Saved', (int)$u['id']);
        audit((int)$u['id'], 'fnf_save', '#' . $id);
        return $id;
    }
    $dup = $db->prepare('SELECT id FROM fnf WHERE emp_code = ? AND active = 1 LIMIT 1'); $dup->execute([$f['code']]);
    if ($dup->fetchColumn()) throw new RuntimeException('This person already has a settlement — open it, or switch it off first.');
    $db->prepare("INSERT INTO fnf (emp_code, name, lwd, status, body, prepared_by, prepared_at, updated_at) VALUES (?,?,?,'draft',?,?,NOW(),NOW())")->execute([$f['code'], $f['name'], $f['lwd'], $body, (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    fnf_log($db, $id, 'Prepared', (int)$u['id']);
    audit((int)$u['id'], 'fnf_create', '#' . $id);
    return $id;
}

/** Move a settlement on: check / approve / back / paid / off. */
function fnf_step(PDO $db, array $u, int $id, string $act, string $note = ''): void {
    $r = fnf_get($db, $id);
    if (!$r) throw new RuntimeException('Not found.');
    $e = emp_by_code($db, $r['emp_code']);
    if ($e && !emp_in_scope($u, $e)) throw new RuntimeException('Not found.');
    if ($p = fnf_step_problem($u, $r, $act, fnf_settings(), $note)) throw new RuntimeException($p);
    $uid = (int)$u['id']; $n = mb_substr(trim(strip_tags($note)), 0, 255);
    $sql = match ($act) {
        'check' => ["status = 'checked', checked_by = ?, checked_at = NOW(), back_note = ''", [$uid]],
        'approve' => ["status = 'approved', approved_by = ?, approved_at = NOW()", [$uid]],
        'back' => ["status = 'draft', checked_by = NULL, checked_at = NULL, approved_by = NULL, approved_at = NULL, back_note = ?", [$n]],
        'paid' => ["status = 'paid', paid_by = ?, paid_at = NOW(), paid_ref = ?", [$uid, mb_substr($n, 0, 160)]],
        'off' => ['active = 0, off_reason = ?', [$n]],
    };
    $db->prepare("UPDATE fnf SET {$sql[0]}, updated_at = NOW() WHERE id = ?")->execute([...$sql[1], $id]);
    fnf_log($db, $id, ['check' => 'Checked', 'approve' => 'Approved', 'back' => 'Sent back: ' . $n, 'paid' => 'Marked paid', 'off' => 'Switched off: ' . $n][$act], $uid);
    audit($uid, 'fnf_' . $act, '#' . $id);
}

/** The bank line for an approved or paid settlement (the balance to pay), from the verified KYC bank account. */
function fnf_bank(PDO $db, array $u, array $r): array {
    if (!bank_can($u)) throw new RuntimeException('The bank file needs pay and bank data.');
    if (strcasecmp((string)$r['emp_code'], (string)($u['emp_code'] ?? '')) === 0) throw new RuntimeException('Not on your own settlement.');
    if (!in_array($r['status'], ['approved', 'paid'], true)) throw new RuntimeException('The bank line is made after approval.');
    $fig = fnf_figures($db, $r['f']);
    $line = ['emp_code' => $r['emp_code'], 'name' => $r['name'], 'net' => max(0.0, $fig['balance']), 'pay_mode' => 'bank', 'hold' => 0];
    $b = bank_rows([$line], bank_details($db, [$r['emp_code']]), substr((string)$r['lwd'], 0, 7), org('name'));
    foreach ($b['rows'] as &$x) $x['ref'] = 'FINAL ' . strtoupper((string)$r['emp_code']);
    audit((int)$u['id'], 'fnf_bank', '#' . $r['id'] . ' rows ' . count($b['rows']));
    return $b;
}
