<?php
/**
 * Statutory compliance — ported from the reference build as rules; the schemes, links, dues and registrations come from
 * the country rule pack (`statutory`), the states from the organisation's branches.
 *   Links          official addresses by scheme and state, for Employees / Payments / Returns & registrations. Seeded
 *                  once from the pack, then kept by the organisation; a link is "to confirm" until someone ticks it.
 *   Due dates      one rule per scheme (+ state): a day of the month after the wage month (31 = last day), only for the
 *                  listed wage months, or one date. Kind: payment / return / both / task; who: HR / Accounts / adviser.
 *   Spends         one line per wage month + scheme (+ state), pulled from payroll's locked month only (never worked out
 *                  again): people, employee share, employer share, charges. A line changed by hand keeps its figures and
 *                  shows payroll's beside it. Payments are recorded with date, reference, amount and mode.
 *   Filings        returns and tasks are marked filed / done per period; a step taken back keeps its row.
 * Access: module roles see links, dues and registrations; amounts need the pay data class; links / dues / registrations
 * are changed by the manage roles, payments by the pay roles (settings). Nothing is deleted; st_log is append-only.
 */
declare(strict_types=1);

require_once __DIR__ . '/payroll.php';

const ST_DUE_KINDS = ['pay' => 'Payment', 'file' => 'Return / filing', 'both' => 'Payment + return', 'task' => 'Task'];
const ST_WHO = ['hr' => 'HR', 'accounts' => 'Accounts', 'ca' => 'Adviser / accountant'];
const ST_AUDIENCE = ['emp' => 'Employees', 'pay' => 'Payments', 'proc' => 'Returns & registrations'];
const ST_PAY_MODES = ['netbanking' => 'Net banking', 'upi' => 'UPI', 'transfer' => 'Bank transfer', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'];
const ST_MONEY = ['people', 'ee', 'er', 'charges'];
const ST_DEFAULTS = ['manage_roles' => ['SUPERADMIN', 'HRMANAGER'], 'pay_roles' => ['SUPERADMIN', 'ACCOUNTS'], 'by_state' => [], 'from_ym' => ''];

/* ---------------- pack, settings, rights ---------------- */

function st_pack(): array {
    $p = pay_pack()['statutory'] ?? [];
    return array_replace(['schemes' => [], 'map' => [], 'state_schemes' => [], 'portals' => [], 'dues' => [], 'regs' => []], $p);
}
function st_schemes(): array { return st_pack()['schemes'] + ['gen' => 'Other']; }

function st_settings(): array {
    $s = module_states()['statutory']['settings'] ?? [];
    return array_replace(ST_DEFAULTS, array_intersect_key($s, ST_DEFAULTS));
}

/** Check a settings form. Pure. Returns [settings, errors]. */
function st_settings_check(array $in, array $roles, array $schemes): array {
    $o = []; $e = [];
    foreach (['manage_roles', 'pay_roles'] as $k) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = 'Pick at least one role for ' . ($k === 'manage_roles' ? 'managing' : 'recording payments') . '.';
    }
    $o['by_state'] = array_values(array_intersect(array_keys($schemes), (array)($in['by_state'] ?? [])));
    $f = trim((string)($in['from_ym'] ?? ''));
    if ($f !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $f)) $e[] = 'Track from: a month.';
    $o['from_ym'] = $f;
    return [$o, $e];
}

/** What a login may do here. Pure. */
function st_rights(array $u, array $set): array {
    return ['view' => true, 'amounts' => data_allowed($u, 'pay'), 'manage' => has_role($u, ...$set['manage_roles']),
            'pay' => has_role($u, ...$set['pay_roles']) && data_allowed($u, 'pay')];
}

/** The states the organisation uses, from its branches. */
function st_states(): array {
    $o = [];
    foreach (branches(false) as $b) if (($s = trim((string)$b['state'])) !== '') $o[$s] = $s;
    ksort($o);
    return $o;
}

/* ---------------- pure rules ---------------- */

function st_text($v, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v)) ?? ''), 0, $max); }

/** A full https address, or an error. Pure. */
function st_url(string $url): string {
    $url = trim($url);
    if (!preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?(?:[/?#][^\s<>"]*)?$~i', $url) || strlen($url) > 400)
        throw new InvalidArgumentException('Address: a full https:// address.');
    return $url;
}

/** Money from a form ("1,234.50") → float ≥ 0. Pure. */
function st_money($v): float {
    $s = preg_replace('/[\s,]|[^\d.\-]+(?=\d)/u', '', (string)$v);
    if ($s === '') return 0.0;
    if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $s)) throw new InvalidArgumentException('Amounts: numbers only, up to 2 decimals.');
    return (float)$s;
}

/** Wage months "6, 12" → "6,12" ('' = every month). Pure. */
function st_months(string $s): string {
    $s = trim($s); if ($s === '') return '';
    $m = array_unique(array_map('intval', preg_split('/[\s,]+/', $s) ?: []));
    foreach ($m as $x) if ($x < 1 || $x > 12) throw new InvalidArgumentException('Wage months: numbers 1–12, e.g. 6,12.');
    sort($m);
    return implode(',', $m);
}

function st_ym_add(string $ym, int $n): string { return date('Y-m', strtotime("$ym-01 $n month")); }

/** The date wage month $ym's dues fall on under $rule, or null (not a due month / a one-date rule). Pure. */
function st_due_date(array $rule, string $ym): ?string {
    if (!empty($rule['on_date'])) return null;
    $mo = (string)($rule['months'] ?? '');
    if ($mo !== '' && !in_array((int)substr($ym, 5, 2), array_map('intval', explode(',', $mo)), true)) return null;
    $next = st_ym_add($ym, max(0, min(12, (int)($rule['after_m'] ?? 1))));
    $day = max(1, min((int)$rule['day'], (int)date('t', strtotime("$next-01"))));
    return sprintf('%s-%02d', $next, $day);
}

/** Every due between $from and $to → [date, rule, wage ym ('' for a one-date rule)], in date order. Pure. */
function st_dues(array $rules, string $from, string $to): array {
    $out = [];
    $start = st_ym_add(substr($from, 0, 7), -14); $end = substr($to, 0, 7);
    foreach ($rules as $r) {
        if (empty($r['active'])) continue;
        if (!empty($r['on_date'])) { if ($r['on_date'] >= $from && $r['on_date'] <= $to) $out[] = [$r['on_date'], $r, '']; continue; }
        for ($ym = $start; $ym <= $end; $ym = st_ym_add($ym, 1)) {
            $d = st_due_date($r, $ym);
            if ($d !== null && $d >= $from && $d <= $to) $out[] = [$d, $r, $ym];
        }
    }
    usort($out, fn($a, $b) => [$a[0], $a[1]['scheme'], $a[1]['state']] <=> [$b[0], $b[1]['scheme'], $b[1]['state']]);
    return $out;
}

/**
 * A locked month's payroll lines → spends: "scheme|state" => [people, ee, er, charges], using the pack's map of payslip
 * labels. Held salaries and lines without a CTC are left out (counted). A scheme goes by state when the pack says so or
 * the organisation ticked it. Pure.
 */
function st_aggregate(array $lines, array $map, array $byState = []): array {
    $o = []; $held = 0;
    foreach ($lines as $l) {
        if (!empty($l['missing_ctc'])) continue;
        if (!empty($l['hold'])) { $held++; continue; }
        $st = (string)($l['state'] ?? '');
        foreach ($map as $sc => $m) {
            $ee = 0.0; $er = 0.0; $ch = 0.0;
            foreach ((array)$m['ee'] as $k) $ee += (float)($l['deductions'][$k] ?? 0);
            foreach ((array)$m['er'] as $k) $er += (float)($l['employer'][$k] ?? 0);
            foreach ((array)$m['charges'] as $k) $ch += (float)($l['employer'][$k] ?? 0);
            if ($ee + $er + $ch <= 0) continue;
            $key = $sc . '|' . (!empty($m['by_state']) || in_array($sc, $byState, true) ? $st : '');
            $o[$key] ??= ['people' => 0, 'ee' => 0.0, 'er' => 0.0, 'charges' => 0.0];
            $o[$key]['people']++; $o[$key]['ee'] += $ee; $o[$key]['er'] += $er; $o[$key]['charges'] += $ch;
        }
    }
    foreach ($o as &$x) foreach (['ee', 'er', 'charges'] as $f) $x[$f] = round($x[$f], 2);
    unset($x);
    ksort($o);
    return ['rows' => $o, 'held' => $held];
}

function st_total(array $s): float { return round((float)$s['ee'] + (float)$s['er'] + (float)$s['charges'], 2); }

/** A spend line's state on $today against its due date ($due null = no rule). Pure. */
function st_status(array $s, ?string $due, string $today): string {
    $tot = st_total($s);
    if (!empty($s['paid_on'])) {
        $amt = $s['paid_amt'] === null ? $tot : (float)$s['paid_amt'];
        if ($amt + 0.5 < $tot) return 'Part paid';
        return $due !== null && $s['paid_on'] > $due ? 'Paid late' : 'Paid';
    }
    if ($tot <= 0) return 'Nothing due';
    if ($due === null) return 'To pay';
    if ($today > $due) return 'Overdue';
    return (strtotime($due) - strtotime($today)) <= 7 * 86400 ? 'Due this week' : 'To pay';
}

/** Does a line differ from payroll's figures? Pure. */
function st_differs(array $s, ?array $pulled): bool {
    if (!$pulled) return false;
    foreach (ST_MONEY as $f) if (abs((float)($s[$f] ?? 0) - (float)($pulled[$f] ?? 0)) >= 0.01) return true;
    return false;
}

/** The rule for a scheme and state: the state's own, else the one for every state. Pure. */
function st_rule_for(array $rules, string $scheme, string $state): ?array {
    $any = null;
    foreach ($rules as $r) {
        if ($r['scheme'] !== $scheme || !empty($r['on_date']) || !(int)$r['active'] || !in_array($r['kind'] ?? 'pay', ['pay', 'both'], true)) continue;
        if ($r['state'] === $state) return $r;
        if ($r['state'] === '') $any = $r;
    }
    return $any;
}

/** Steps a due goes through. Pure. */
function st_rule_steps(array $rule): array {
    return match ((string)($rule['kind'] ?? 'pay')) { 'both' => ['paid', 'filed'], 'file' => ['filed'], 'task' => ['done'], default => ['paid'] };
}

/** Is a due settled: payment → its spend line paid (or nothing due) or marked paid; filing → filed; task → done. Pure. */
function st_due_settled(array $rule, ?array $spend, array $steps): bool {
    $k = (string)($rule['kind'] ?? 'pay');
    if ($k === 'task') return isset($steps['done']);
    $paid = $spend ? (!empty($spend['paid_on']) || st_total($spend) <= 0) : isset($steps['paid']);
    if ($k === 'file') return isset($steps['filed']);
    if ($k === 'both') return $paid && isset($steps['filed']);
    return $paid;
}

/* ---------------- database ---------------- */

function st_log(PDO $db, ?int $uid, string $entity, int $id, string $action, array $detail = []): void {
    $db->prepare('INSERT INTO st_log (user_id, entity, entity_id, action, detail) VALUES (?,?,?,?,?)')->execute([$uid, $entity, $id, $action, $detail ? json_encode($detail) : null]);
}

/** Seed the pack's links, dues and registrations once each (by key); later changes are the organisation's. */
function st_seed(PDO $db): void {
    $p = st_pack();
    $ip = $db->prepare("INSERT IGNORE INTO st_portal (seed, scheme, state, audience, name, url, note, checked, sort) VALUES (?,?,'',?,?,?,?,0,?)");
    $i = 0; foreach ($p['portals'] as $k => $x) $ip->execute([$k, $x[0], $x[1], $x[2], $x[3], $x[4] ?? '', $i++]);
    $id = $db->prepare("INSERT IGNORE INTO st_due (seed, scheme, state, label, day, months, after_m, kind, who, checked) VALUES (?,?,'',?,?,?,?,?,?,?)");
    foreach ($p['dues'] as $k => $x) $id->execute([$k, $x[0], $x[1], $x[2], $x[3], $x[4], $x[5], $x[6], $x[7]]);
    $ir = $db->prepare("INSERT IGNORE INTO st_reg (seed, scheme, state, label) VALUES (?,?,'',?)");
    foreach ($p['regs'] as $k => $x) $ir->execute([$k, $x[0], $x[1]]);
}

function st_need(array $u, string $right): void {
    if (empty(st_rights($u, st_settings())[$right])) throw new RuntimeException($right === 'amounts' ? 'Amounts open only to logins with pay data.' : 'Not allowed.');
}

function st_check_scheme_state(string $sc, string $st, bool $needState = false): void {
    if (!isset(st_schemes()[$sc])) throw new InvalidArgumentException('Choose the scheme.');
    if ($st !== '' && !isset(st_states()[$st])) throw new InvalidArgumentException('Choose one of the branches\' states.');
    if ($needState && $st === '' && in_array($sc, st_pack()['state_schemes'], true)) throw new InvalidArgumentException('This one goes by state — choose the state.');
}

function st_portals(PDO $db, bool $all = false): array {
    return $db->query('SELECT * FROM st_portal' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY scheme, state, audience, sort, id')->fetchAll();
}

function st_portal_save(PDO $db, array $u, int $id, array $in): int {
    st_need($u, 'manage');
    $sc = (string)($in['scheme'] ?? ''); $st = (string)($in['state'] ?? ''); $au = (string)($in['audience'] ?? '');
    st_check_scheme_state($sc, $st);
    if (!isset(ST_AUDIENCE[$au])) throw new InvalidArgumentException('Choose who it is for.');
    $name = st_text($in['name'] ?? '', 120); if ($name === '') throw new InvalidArgumentException('Name the link.');
    $f = [$sc, $st, $au, $name, st_url((string)($in['url'] ?? '')), st_text($in['note'] ?? '', 300), !empty($in['checked']) ? 1 : 0, empty($in['off']) ? 1 : 0];
    $uid = (int)$u['id'];
    if ($id) {
        $db->prepare('UPDATE st_portal SET scheme=?, state=?, audience=?, name=?, url=?, note=?, checked=?, active=?, updated_by=? WHERE id=?')->execute([...$f, $uid, $id]);
        st_log($db, $uid, 'portal', $id, 'edit', ['name' => $name, 'active' => $f[7]]);
        return $id;
    }
    $db->prepare('INSERT INTO st_portal (scheme, state, audience, name, url, note, checked, active, sort, updated_by) VALUES (?,?,?,?,?,?,?,?,999,?)')->execute([...$f, $uid]);
    $id = (int)$db->lastInsertId();
    st_log($db, $uid, 'portal', $id, 'add', ['name' => $name]);
    return $id;
}

function st_due_rules(PDO $db, bool $all = false): array {
    return $db->query('SELECT * FROM st_due' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY on_date IS NOT NULL, scheme, state, id')->fetchAll();
}

/** A due rule from a form. Pure apart from the scheme / state check. Returns the column values. */
function st_due_clean(array $in): array {
    $sc = (string)($in['scheme'] ?? ''); $st = (string)($in['state'] ?? '');
    st_check_scheme_state($sc, $st);
    $label = st_text($in['label'] ?? '', 120); if ($label === '') throw new InvalidArgumentException('Name the due.');
    $on = trim((string)($in['on_date'] ?? ''));
    if ($on !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) throw new InvalidArgumentException('One date: pick a date.');
    $day = (int)($in['day'] ?? 0);
    if ($on === '' && ($day < 1 || $day > 31)) throw new InvalidArgumentException('Day of the month: 1–31 (31 = last day).');
    $after = trim((string)($in['after_m'] ?? '1'));
    if (!preg_match('/^\d{1,2}$/', $after === '' ? '1' : $after) || (int)$after > 12) throw new InvalidArgumentException('Months after the wage month: 0–12.');
    $kind = (string)($in['kind'] ?? 'pay'); if (!isset(ST_DUE_KINDS[$kind])) throw new InvalidArgumentException('Payment, return, both or task?');
    $who = (string)($in['who'] ?? ''); if ($who !== '' && !isset(ST_WHO[$who])) throw new InvalidArgumentException('Who does it?');
    return ['scheme' => $sc, 'state' => $st, 'label' => $label, 'day' => $on === '' ? $day : 0, 'months' => $on === '' ? st_months((string)($in['months'] ?? '')) : '',
            'on_date' => $on === '' ? null : $on, 'after_m' => $after === '' ? 1 : (int)$after, 'kind' => $kind, 'who' => $who,
            'note' => st_text($in['note'] ?? '', 300), 'checked' => !empty($in['checked']) ? 1 : 0, 'active' => empty($in['off']) ? 1 : 0];
}

function st_due_save(PDO $db, array $u, int $id, array $in): int {
    st_need($u, 'manage');
    $f = st_due_clean($in); $uid = (int)$u['id'];
    $cols = array_keys($f);
    if ($id) {
        $db->prepare('UPDATE st_due SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_by = ? WHERE id = ?')->execute([...array_values($f), $uid, $id]);
        st_log($db, $uid, 'due', $id, 'edit', ['label' => $f['label'], 'active' => $f['active']]);
        return $id;
    }
    $db->prepare('INSERT INTO st_due (' . implode(', ', $cols) . ', updated_by) VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')->execute([...array_values($f), $uid]);
    $id = (int)$db->lastInsertId();
    st_log($db, $uid, 'due', $id, 'add', ['label' => $f['label']]);
    return $id;
}

function st_regs(PDO $db): array { return $db->query('SELECT * FROM st_reg WHERE active = 1 ORDER BY scheme, state, id')->fetchAll(); }

function st_reg_save(PDO $db, array $u, int $id, array $in): int {
    st_need($u, 'manage');
    $sc = (string)($in['scheme'] ?? ''); $st = (string)($in['state'] ?? '');
    st_check_scheme_state($sc, $st);
    $label = st_text($in['label'] ?? '', 80); if ($label === '') throw new InvalidArgumentException('Say what the number is.');
    $vt = trim((string)($in['valid_till'] ?? ''));
    if ($vt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vt)) throw new InvalidArgumentException('Valid till: pick a date.');
    $f = [$sc, $st, $label, st_text($in['number'] ?? '', 60), $vt === '' ? null : $vt, st_text($in['note'] ?? '', 300), empty($in['off']) ? 1 : 0];
    $uid = (int)$u['id'];
    if ($id) {
        $db->prepare('UPDATE st_reg SET scheme=?, state=?, label=?, number=?, valid_till=?, note=?, active=?, updated_by=? WHERE id=?')->execute([...$f, $uid, $id]);
        st_log($db, $uid, 'reg', $id, 'edit', ['label' => $label, 'number_changed' => true, 'active' => $f[6]]);
        return $id;
    }
    $db->prepare('INSERT INTO st_reg (scheme, state, label, number, valid_till, note, active, updated_by) VALUES (?,?,?,?,?,?,?,?)')->execute([...$f, $uid]);
    $id = (int)$db->lastInsertId();
    st_log($db, $uid, 'reg', $id, 'add', ['label' => $label]);
    return $id;
}

/** Locked payroll months → [ym => status]. */
function st_pay_months(PDO $db): array {
    return array_column($db->query("SELECT ym, status FROM pay_month WHERE status IN ('confirmed','released') ORDER BY ym")->fetchAll(), 'status', 'ym');
}

/** Bring a locked month's figures into Spends. Lines never changed by hand follow payroll; changed lines keep theirs. */
function st_pull(PDO $db, ?int $uid, string $ym): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) throw new InvalidArgumentException('Month?');
    if (!isset(st_pay_months($db)[$ym])) throw new InvalidArgumentException('Payroll for ' . date('M Y', strtotime("$ym-01")) . ' is not confirmed yet.');
    $set = st_settings();
    $agg = st_aggregate(pay_build($db, $ym), st_pack()['map'], $set['by_state']);
    $have = $db->prepare('SELECT * FROM st_spend WHERE ym = ?'); $have->execute([$ym]);
    $cur = []; foreach ($have->fetchAll() as $r) $cur[$r['scheme'] . '|' . $r['state']] = $r;
    $n = 0;
    foreach ($agg['rows'] + array_fill_keys(array_keys($cur), null) as $k => $x) {
        [$sc, $st] = explode('|', (string)$k, 2);
        $x ??= ['people' => 0, 'ee' => 0.0, 'er' => 0.0, 'charges' => 0.0];
        $pj = json_encode($x);
        $c = $cur[$k] ?? null;
        if (!$c) {
            $db->prepare("INSERT INTO st_spend (ym, scheme, state, people, ee, er, charges, src, pulled, pulled_at) VALUES (?,?,?,?,?,?,?,'payroll',?,NOW())")
               ->execute([$ym, $sc, $st, $x['people'], $x['ee'], $x['er'], $x['charges'], $pj]);
            st_log($db, $uid, 'spend', (int)$db->lastInsertId(), 'pull', ['people' => $x['people']]); $n++;
            continue;
        }
        if ((string)$c['pulled'] === $pj) continue;
        if ($c['src'] === 'payroll') $db->prepare('UPDATE st_spend SET people=?, ee=?, er=?, charges=?, pulled=?, pulled_at=NOW() WHERE id=?')->execute([$x['people'], $x['ee'], $x['er'], $x['charges'], $pj, $c['id']]);
        else $db->prepare('UPDATE st_spend SET pulled=?, pulled_at=NOW() WHERE id=?')->execute([$pj, $c['id']]);
        st_log($db, $uid, 'spend', (int)$c['id'], 'pull', ['kept' => $c['src'] !== 'payroll']); $n++;
    }
    return [$n, $agg['held']];
}

/** Bring in every locked month not seen yet. Never throws. */
function st_pull_all(PDO $db): void {
    try { $from = st_settings()['from_ym']; foreach (array_keys(st_pay_months($db)) as $ym) if ($from === '' || $ym >= $from) st_pull($db, null, (string)$ym); }
    catch (Throwable $e) { error_log('st_pull_all: ' . $e->getMessage()); }
}

function st_spends(PDO $db, string $ym): array { $q = $db->prepare('SELECT * FROM st_spend WHERE ym = ? ORDER BY scheme, state'); $q->execute([$ym]); return $q->fetchAll(); }
function st_spend(PDO $db, int $id): ?array { $q = $db->prepare('SELECT * FROM st_spend WHERE id = ?'); $q->execute([$id]); return $q->fetch() ?: null; }

function st_spend_vals(array $in): array {
    $p = trim((string)($in['people'] ?? '0'));
    if (!preg_match('/^\d{1,5}$/', $p === '' ? '0' : $p)) throw new InvalidArgumentException('People: a whole number.');
    return ['people' => (int)$p, 'ee' => st_money($in['ee'] ?? ''), 'er' => st_money($in['er'] ?? ''), 'charges' => st_money($in['charges'] ?? '')];
}

/** A line added by hand (a month before payroll was used, a challan payroll does not know). */
function st_spend_add(PDO $db, array $u, string $ym, array $in): int {
    st_need($u, 'manage'); st_need($u, 'amounts');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) throw new InvalidArgumentException('Month?');
    $sc = (string)($in['scheme'] ?? ''); $st = (string)($in['state'] ?? '');
    st_check_scheme_state($sc, $st, true);
    $q = $db->prepare('SELECT id FROM st_spend WHERE ym = ? AND scheme = ? AND state = ?'); $q->execute([$ym, $sc, $st]);
    if ($q->fetchColumn()) throw new InvalidArgumentException('That line is already there for the month — change it instead.');
    $v = st_spend_vals($in);
    $db->prepare("INSERT INTO st_spend (ym, scheme, state, people, ee, er, charges, src, note, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,'manual',?,?,NOW())")
       ->execute([$ym, $sc, $st, $v['people'], $v['ee'], $v['er'], $v['charges'], st_text($in['note'] ?? '', 300), (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    st_log($db, (int)$u['id'], 'spend', $id, 'add');
    return $id;
}

/** Change a line's figures (a reason when they differ from payroll's); "back" returns to payroll's figures. */
function st_spend_edit(PDO $db, array $u, int $id, array $in): void {
    st_need($u, 'manage'); st_need($u, 'amounts');
    $s = st_spend($db, $id); if (!$s) throw new InvalidArgumentException('Line not found.');
    $pulled = $s['pulled'] ? json_decode((string)$s['pulled'], true) : null;
    if (!empty($in['back'])) {
        if (!$pulled) throw new InvalidArgumentException('Payroll has no figures for this line.');
        $db->prepare("UPDATE st_spend SET people=?, ee=?, er=?, charges=?, src='payroll', note='', updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$pulled['people'], $pulled['ee'], $pulled['er'], $pulled['charges'], (int)$u['id'], $id]);
        st_log($db, (int)$u['id'], 'spend', $id, 'back');
        return;
    }
    $v = st_spend_vals($in); $note = st_text($in['note'] ?? '', 300);
    $src = $pulled ? (st_differs($v, $pulled) ? 'edited' : 'payroll') : $s['src'];
    if ($src === 'edited' && $note === '') throw new InvalidArgumentException('Say why the figures differ from payroll.');
    $db->prepare('UPDATE st_spend SET people=?, ee=?, er=?, charges=?, src=?, note=?, updated_by=?, updated_at=NOW() WHERE id=?')
       ->execute([$v['people'], $v['ee'], $v['er'], $v['charges'], $src, $note, (int)$u['id'], $id]);
    st_log($db, (int)$u['id'], 'spend', $id, 'edit', ['src' => $src]);
}

/** Record or take off a line's payment. A blank date takes it off, with a reason. */
function st_spend_paid(PDO $db, array $u, int $id, array $in, ?string $today = null): void {
    st_need($u, 'pay');
    $today ??= date('Y-m-d');
    $s = st_spend($db, $id); if (!$s) throw new InvalidArgumentException('Line not found.');
    $on = trim((string)($in['paid_on'] ?? ''));
    if ($on === '') {
        if (!$s['paid_on']) throw new InvalidArgumentException('Pick the date it was paid.');
        $why = st_text($in['note'] ?? '', 300); if ($why === '') throw new InvalidArgumentException('Say why the payment is taken off.');
        $db->prepare("UPDATE st_spend SET paid_on=NULL, paid_ref='', paid_amt=NULL, paid_mode='', paid_by=?, paid_at=NOW() WHERE id=?")->execute([(int)$u['id'], $id]);
        st_log($db, (int)$u['id'], 'spend', $id, 'unpaid', ['note' => $why]);
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > $today) throw new InvalidArgumentException('Paid on: a date up to today.');
    $ref = st_text($in['paid_ref'] ?? '', 60); if ($ref === '') throw new InvalidArgumentException('Give the challan / payment reference.');
    $amt = trim((string)($in['paid_amt'] ?? '')) === '' ? st_total($s) : st_money($in['paid_amt']);
    $mode = (string)($in['paid_mode'] ?? ''); if ($mode !== '' && !isset(ST_PAY_MODES[$mode])) throw new InvalidArgumentException('Mode?');
    $db->prepare('UPDATE st_spend SET paid_on=?, paid_ref=?, paid_amt=?, paid_mode=?, paid_by=?, paid_at=NOW() WHERE id=?')->execute([$on, $ref, $amt, $mode, (int)$u['id'], $id]);
    st_log($db, (int)$u['id'], 'spend', $id, 'paid', ['on' => $on, 'mode' => $mode]);
}

/** Steps recorded: "rule|period" => [step => row], taken-back rows left out. */
function st_filings(PDO $db): array {
    $o = [];
    foreach ($db->query('SELECT * FROM st_filing WHERE reverted = 0 ORDER BY id') as $r) $o[$r['rule_id'] . '|' . $r['period']][$r['step']] = $r;
    return $o;
}

/** Mark a step of a due (paid only for a due without a spend line; filed; done), or take it back with a reason. */
function st_filing_add(PDO $db, array $u, int $ruleId, string $period, string $step, array $in, ?string $today = null): void {
    $today ??= date('Y-m-d');
    $q = $db->prepare('SELECT * FROM st_due WHERE id = ?'); $q->execute([$ruleId]); $r = $q->fetch();
    if (!$r) throw new InvalidArgumentException('Due not found.');
    if (!in_array($step, st_rule_steps($r), true)) throw new InvalidArgumentException('That step is not part of this due.');
    st_need($u, $step === 'paid' ? 'pay' : (has_role($u, ...st_settings()['pay_roles']) ? 'view' : 'manage'));
    if (!preg_match('/^(\d{4}-\d{2}|\d{4}-\d{2}-\d{2})$/', $period)) throw new InvalidArgumentException('Period?');
    $have = st_filings($db)[$ruleId . '|' . $period][$step] ?? null;
    if (!empty($in['undo'])) {
        if (!$have) throw new InvalidArgumentException('Nothing to take back.');
        $why = st_text($in['note'] ?? '', 300); if ($why === '') throw new InvalidArgumentException('Say why it is taken back.');
        $db->prepare('UPDATE st_filing SET reverted = 1, revert_reason = ? WHERE id = ?')->execute([$why, $have['id']]);
        st_log($db, (int)$u['id'], 'filing', (int)$have['id'], 'undo');
        return;
    }
    if ($have) throw new InvalidArgumentException('Already marked.');
    $on = trim((string)($in['on_date'] ?? '')) ?: $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || $on > $today) throw new InvalidArgumentException('Date: up to today.');
    $ref = st_text($in['ref'] ?? '', 80);
    if (in_array($step, ['paid', 'filed'], true) && $ref === '') throw new InvalidArgumentException('Give the acknowledgement / reference.');
    $db->prepare('INSERT INTO st_filing (rule_id, period, step, on_date, ref, note, by_user) VALUES (?,?,?,?,?,?,?)')->execute([$ruleId, $period, $step, $on, $ref, st_text($in['note'] ?? '', 300), (int)$u['id']]);
    st_log($db, (int)$u['id'], 'filing', (int)$db->lastInsertId(), $step, ['rule' => $ruleId, 'period' => $period]);
}

/** From which wage month dues are followed: the setting, else the first locked payroll month, else this month. Pure. */
function st_track_from(array $set, array $lockedYms, string $today): string {
    if ($set['from_ym'] !== '') return $set['from_ym'];
    sort($lockedYms);
    return $lockedYms ? (string)$lockedYms[0] : substr($today, 0, 7);
}

/** Dues overdue (last 120 days) or within $days, not settled. */
function st_open_dues(PDO $db, string $today, int $days = 30): array {
    $set = st_settings();
    $from = date('Y-m-d', strtotime("$today -120 day")); $to = date('Y-m-d', strtotime("$today +$days day"));
    $have = [];
    $q = $db->prepare('SELECT * FROM st_spend WHERE ym >= ?'); $q->execute([st_ym_add(substr($from, 0, 7), -14)]);
    foreach ($q->fetchAll() as $s) $have[$s['ym'] . '|' . $s['scheme'] . '|' . $s['state']] = $s;
    $locked = st_pay_months($db); $F = st_filings($db); $out = [];
    $track = st_track_from($set, array_keys($locked), $today);
    foreach (st_dues(st_due_rules($db), $from, $to) as [$d, $r, $ym]) {
        if (($ym !== '' ? $ym : substr($d, 0, 7)) < $track) continue;
        $isPay = in_array($r['kind'], ['pay', 'both'], true);
        $s = $isPay && $ym !== '' ? ($have["$ym|{$r['scheme']}|{$r['state']}"] ?? null) : null;
        $steps = $F[$r['id'] . '|' . ($ym !== '' ? $ym : $r['on_date'])] ?? [];
        if (st_due_settled($r, $s, $steps)) continue;
        if ($isPay && !$s && $ym !== '' && isset($locked[$ym]) && $r['kind'] === 'pay') continue;   // locked, and nobody in this scheme / state
        $out[] = ['date' => $d, 'rule' => $r, 'ym' => $ym, 'period' => $ym !== '' ? $ym : (string)$r['on_date'], 'spend' => $s, 'steps' => $steps, 'late' => $d < $today];
    }
    return $out;
}

/** Year totals by month and scheme for the year starting in month $start of year $y0: [ym => [scheme => [total, paid]]]. */
function st_year(PDO $db, int $y0, int $start): array {
    $from = sprintf('%04d-%02d', $y0, $start); $to = st_ym_add($from, 11);
    $q = $db->prepare('SELECT ym, scheme, SUM(ee + er + charges) t, SUM(CASE WHEN paid_on IS NULL THEN 0 ELSE COALESCE(paid_amt, ee + er + charges) END) p FROM st_spend WHERE ym BETWEEN ? AND ? GROUP BY ym, scheme');
    $q->execute([$from, $to]);
    $o = []; foreach ($q->fetchAll() as $r) $o[$r['ym']][$r['scheme']] = [(float)$r['t'], (float)$r['p']];
    ksort($o);
    return $o;
}
