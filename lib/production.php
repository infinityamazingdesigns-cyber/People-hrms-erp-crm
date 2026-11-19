<?php
// Production workers — their own register (never the office employee master: own codes PW0001 …), hourly rate history,
// hours per worker per day, piece-rate work by type at the type's rate in force that day, an advances ledger, and a
// separate monthly pay run. Wages are worked day by day at the rate in force that day; overtime = hours above the
// standard day × the OT multiplier (both settings). Money in whole minor units (paise / cents), hours in hundredths —
// never floats. A confirmed month is read from its snapshot and locks its hours, pieces and inputs; reopening needs a
// reason. Entry roles (production heads) see hours and pieces only, never a rate, wage or advance. Nothing is deleted.
declare(strict_types=1);
require_once __DIR__ . '/employees.php';

const PRD_MAX_DAY_CENTI = 2400;
const PRD_ADV_KINDS = ['opening' => 'Opening balance', 'given' => 'Given', 'recovered' => 'Recovered', 'waived' => 'Waived'];
const PRD_SKILLS = ['Unskilled', 'Semi-skilled', 'Skilled', 'Highly skilled'];
const PRD_GENDERS = ['Male', 'Female', 'Other'];
const PRD_PAID_BY = ['bank' => 'Bank', 'cash' => 'Cash', 'cheque' => 'Cheque'];
const PRD_INPUT_KEYS = ['other_earn', 'adv_recover', 'ded_a', 'ded_b', 'ded_c', 'other_ded'];
const PRD_DEFAULTS = [
    'entry_roles' => ['PRODHEAD'], 'money_roles' => ['SUPERADMIN'], 'view_roles' => ['HRMANAGER', 'CEO'],
    'std_centi' => 800, 'ot_permille' => 1000, 'first_month' => '', 'prefix' => 'PW',
    'ded_labels' => ['Social security (employee share)', 'Health insurance (employee share)', 'Professional tax'],
];

/* ---------------- pure ---------------- */

function prd_settings(): array { $s = module_states()['production']['settings'] ?? []; return array_replace(PRD_DEFAULTS, array_intersect_key($s, PRD_DEFAULTS)); }
function prd_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['entry_roles', 'money_roles', 'view_roles'] as $k) $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
    if (!$o['money_roles']) $e[] = 'Pick at least one role that runs pay.';
    try { $o['std_centi'] = prd_hours_parse((string)($in['std_hours'] ?? '8')) ?? 800; } catch (Throwable $x) { $e[] = 'Standard day: ' . $x->getMessage(); $o['std_centi'] = 800; }
    if ($o['std_centi'] < 100) $e[] = 'The standard day is at least 1 hour.';
    $m = trim((string)($in['ot_multiplier'] ?? '1')); $o['ot_permille'] = is_numeric($m) && (float)$m >= 1 && (float)$m <= 4 ? (int)round((float)$m * 1000) : 1000;
    if (!is_numeric($m) || (float)$m < 1 || (float)$m > 4) $e[] = 'Overtime multiplier is 1 to 4.';
    $f = trim((string)($in['first_month'] ?? '')); $o['first_month'] = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $f) ? $f : '';
    $o['prefix'] = substr(strtoupper((string)preg_replace('/[^A-Za-z]/', '', (string)($in['prefix'] ?? 'PW'))), 0, 4) ?: 'PW';
    $o['ded_labels'] = []; foreach ([0, 1, 2] as $i) $o['ded_labels'][$i] = mb_substr(trim((string)($in['ded_labels'][$i] ?? '')), 0, 40) ?: PRD_DEFAULTS['ded_labels'][$i];
    return [$o, $e];
}
/** view = read the module; entry = hours, pieces, workers; money = rates, wages, advances, pay run, settings figures. */
function prd_rights_for(array $roles, array $set): array {
    $sa = in_array('SUPERADMIN', $roles, true); $money = $sa || (bool)array_intersect($roles, $set['money_roles']);
    $entry = $sa || (bool)array_intersect($roles, $set['entry_roles']);
    return ['view' => $money || $entry || (bool)array_intersect($roles, $set['view_roles']), 'entry' => $entry, 'money' => $money];
}
/** Branch scope: null = every branch (Super Admin, CEO, or a money / view role with none ticked); an entry-only role with none ticked = nothing. */
function prd_scope_for(array $roles, array $ticked, array $set): ?array {
    if (array_intersect($roles, ['SUPERADMIN', 'CEO'])) return null;
    if ($ticked) return array_map('intval', $ticked);
    return array_intersect($roles, array_merge($set['money_roles'], $set['view_roles'])) ? null : [];
}
/** Hours text ("9.5", "9:30", "9 h 30") → hundredths; '' / '-' → null. */
function prd_hours_parse(string $s): ?int {
    $s = trim(str_replace(',', '.', $s));
    if ($s === '' || $s === '-') return null;
    if (preg_match('/^(\d{1,2})\s*(?::|h)\s*(\d{1,2})\s*m?$/i', $s, $m)) {
        if ((int)$m[2] > 59) throw new RuntimeException("Hours \"$s\": minutes must be under 60.");
        $c = (int)$m[1] * 100 + intdiv((int)$m[2] * 100 + 30, 60);
    } elseif (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $s)) $c = (int)round((float)$s * 100);
    else throw new RuntimeException("Hours \"$s\": write a number like 9.5 or 9:30.");
    if ($c > PRD_MAX_DAY_CENTI) throw new RuntimeException("Hours \"$s\": a day is 0 to 24 hours.");
    return $c;
}
/** Amount text ("1,250.50") → minor units; '' → 0. */
function prd_minor(string $s): int {
    $s = trim((string)preg_replace('/[^\d.]/', '', str_replace(',', '', $s)));
    if ($s === '') return 0;
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s)) throw new RuntimeException("Amount \"$s\": write it like 1250 or 1250.50.");
    [$r, $p] = array_pad(explode('.', $s), 2, '');
    return (int)$r * 100 + (int)str_pad($p, 2, '0');
}
function prd_money(int $m): string { return ($m < 0 ? '-' : '') . number_format(abs($m) / 100, 2); }
function prd_hrs(int $c): string { return $c % 100 ? rtrim(rtrim(number_format($c / 100, 2, '.', ''), '0'), '.') : (string)intdiv($c, 100); }
/** Round half up, non-negative numerators. */
function prd_div(int $n, int $d): int { return intdiv(2 * $n + $d, 2 * $d); }
/** One day → [ordinary up to the standard day, overtime]. */
function prd_split(int $c, int $std): array { $o = min($c, $std); return [$o, $c - $o]; }
function prd_wage(int $centi, int $minorHr, int $permille = 1000): int { return prd_div($centi * $minorHr * $permille, 100 * 1000); }
/** Value in force on $d from [eff_from => value]; null if none yet. */
function prd_in_force(array $hist, string $d): ?int { ksort($hist); $v = null; foreach ($hist as $f => $x) { if ($f <= $d) $v = (int)$x; else break; } return $v; }
function prd_days(string $ym): array { return array_map(fn($i) => sprintf('%s-%02d', $ym, $i), range(1, (int)date('t', strtotime("$ym-01")))); }
/**
 * One worker's month. $days [d => centi], $rates [eff_from => minor/hr], $pieces [[d, qty, minor each]], $in inputs (minor),
 * $advOut advance outstanding before this month's recovery.
 */
function prd_pay(array $days, array $rates, array $pieces, array $in, int $advOut, int $std, int $otPermille): array {
    $reg = $ot = $regPay = $otPay = $noRate = $worked = 0; ksort($days);
    foreach ($days as $d => $c) {
        if ($c <= 0) continue;
        $worked++; [$a, $b] = prd_split($c, $std); $reg += $a; $ot += $b;
        $r = prd_in_force($rates, $d); if ($r === null) { $noRate++; continue; }
        $regPay += prd_wage($a, $r); $otPay += prd_wage($b, $r, $otPermille);
    }
    $pcs = 0; $pcPay = 0; $noPieceRate = 0;
    foreach ($pieces as [$d, $q, $each]) { $pcs += $q; if ($each === null) $noPieceRate++; else $pcPay += $q * $each; }
    $I = []; foreach (PRD_INPUT_KEYS as $k) $I[$k] = (int)($in[$k] ?? 0);
    $gross = $regPay + $otPay + $pcPay + $I['other_earn']; $ded = $I['adv_recover'] + $I['ded_a'] + $I['ded_b'] + $I['ded_c'] + $I['other_ded']; $net = $gross - $ded;
    $flags = [];
    if ($noRate) $flags[] = ['bad', "No hourly rate for $noRate day" . ($noRate > 1 ? 's' : '') . ' worked'];
    if ($noPieceRate) $flags[] = ['bad', 'Pieces of a type with no rate on that day'];
    if ($I['adv_recover'] > max(0, $advOut)) $flags[] = ['bad', 'Recovery more than the advance outstanding'];
    if ($net < 0) $flags[] = ['bad', 'Net pay below zero'];
    if ($gross > 0 && $ded * 2 > $gross) $flags[] = ['warn', 'Deductions above half of gross — check'];
    return ['days' => $worked, 'worked' => $reg + $ot, 'reg' => $reg, 'ot' => $ot, 'reg_pay' => $regPay, 'ot_pay' => $otPay, 'pcs' => $pcs, 'pc_pay' => $pcPay,
            'gross' => $gross, 'ded' => $ded, 'net' => $net, 'adv_out' => $advOut, 'adv_after' => $advOut - $I['adv_recover'], 'in' => $I, 'flags' => $flags];
}
/** Hours-only view for logins without money rights: never a rate, wage or advance. */
function prd_hours_only(array $p): array { return array_intersect_key($p, array_flip(['days', 'worked', 'reg', 'ot', 'pcs'])); }
/** Advance ledger lines → outstanding per code ($skipYm: leave out recoveries posted by that month's pay run). */
function prd_adv_sum(array $lines, ?string $skipYm = null): array {
    $o = [];
    foreach ($lines as $r) { if ($skipYm !== null && ($r['pay_ym'] ?? null) === $skipYm) continue; $c = (string)$r['code']; $o[$c] = ($o[$c] ?? 0) + (in_array($r['kind'], ['opening', 'given'], true) ? 1 : -1) * (int)$r['minor']; }
    return $o;
}
function prd_next_code(string $prefix, int $highest): string { return sprintf('%s%04d', $prefix, $highest + 1); }
/** The worker form → clean values (throws). $branches = ids the login may use. */
function prd_worker_input(array $f, array $branches, string $today): array {
    $t = fn($k, $n) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($f[$k] ?? ''))), 0, $n);
    $name = $t('name', 120); if (mb_strlen($name) < 2 || !preg_match('/\p{L}/u', $name)) throw new RuntimeException('Type the worker\'s full name.');
    $br = (int)($f['branch_id'] ?? 0); if (!in_array($br, $branches, true)) throw new RuntimeException('Pick a branch in your scope.');
    $mob = (string)preg_replace('/\D/', '', (string)($f['mobile'] ?? '')); if ($mob !== '' && (strlen($mob) < 7 || strlen($mob) > 15)) throw new RuntimeException('Mobile: 7 to 15 digits, or leave it blank.');
    $doj = trim((string)($f['doj'] ?? '')); if ($doj !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $doj) || $doj > $today)) throw new RuntimeException('Date of joining: a date, not later than today.');
    $card = strtoupper((string)preg_replace('/\s+/', '', (string)($f['card_no'] ?? ''))); if ($card !== '' && !preg_match('/^[A-Z0-9\/-]{1,30}$/', $card)) throw new RuntimeException('Card no.: letters, digits, / or - only.');
    return ['name' => $name, 'name_local' => $t('name_local', 120), 'guardian' => $t('guardian', 120), 'gender' => in_array($f['gender'] ?? '', PRD_GENDERS, true) ? (string)$f['gender'] : '',
            'mobile' => $mob, 'dept' => $t('dept', 60), 'branch_id' => $br, 'doj' => $doj ?: null, 'card_no' => $card ?: null,
            'skill' => in_array($f['skill'] ?? '', PRD_SKILLS, true) ? (string)$f['skill'] : '', 'paid_by' => isset(PRD_PAID_BY[$f['paid_by'] ?? '']) ? (string)$f['paid_by'] : 'bank', 'note' => $t('note', 255)];
}
function prd_csv_cell($v): string { $v = (string)$v; if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) $v = "'" . $v; return '"' . str_replace('"', '""', $v) . '"'; }

/* ---------------- database ---------------- */

function prd_ctx(array $u): array {
    $set = prd_settings(); $roles = user_roles((int)$u['id'], !empty($u['is_owner']));
    $sc = prd_scope_for($roles, user_branch_ids((int)$u['id']), $set);
    $all = array_map('intval', array_column(branches(), 'id'));
    return ['R' => prd_rights_for($roles, $set), 'scope' => $sc, 'branches' => $sc === null ? $all : array_values(array_intersect($all, $sc)), 'set' => $set, 'uid' => (int)$u['id']];
}
function prd_need(array $x, string $r): void { if (empty($x['R'][$r])) throw new RuntimeException('Your role cannot do this.'); }
function prd_log(PDO $db, ?int $uid, string $what, string $code = '', string $ref = '', string $detail = ''): void {
    $db->prepare('INSERT INTO prd_log (by_user, what, code, ref, detail) VALUES (?,?,?,?,?)')->execute([$uid, $what, $code, mb_substr($ref, 0, 60), mb_substr($detail, 0, 400)]);
}
/** Workers in scope: code => row. */
function prd_workers(PDO $db, array $x, bool $activeOnly = false): array {
    $o = [];
    foreach ($db->query('SELECT * FROM prd_worker ORDER BY active DESC, name') as $w) {
        if ($x['scope'] !== null && !in_array((int)$w['branch_id'], $x['scope'], true)) continue;
        if ($activeOnly && !(int)$w['active']) continue;
        $o[(string)$w['code']] = $w;
    }
    return $o;
}
function prd_worker_dupes(PDO $db, array $v, string $except, bool $sameNameOk): void {
    if ($v['card_no'] !== null) { $q = $db->prepare('SELECT code FROM prd_worker WHERE card_no = ? AND code <> ?'); $q->execute([$v['card_no'], $except]); if ($c = $q->fetchColumn()) throw new RuntimeException('That card no. is already with ' . $c . '.'); }
    if ($v['mobile'] !== '') { $q = $db->prepare('SELECT code FROM prd_worker WHERE mobile = ? AND active = 1 AND code <> ?'); $q->execute([$v['mobile'], $except]); if ($c = $q->fetchColumn()) throw new RuntimeException('That mobile number is already with ' . $c . '.'); }
    if (!$sameNameOk) { $q = $db->prepare('SELECT code FROM prd_worker WHERE branch_id = ? AND active = 1 AND LOWER(name) = LOWER(?) AND code <> ?'); $q->execute([$v['branch_id'], $v['name'], $except]);
        if ($c = $q->fetchColumn()) throw new RuntimeException($v['name'] . ' is already a worker there (' . $c . '). If this is a different person, tick "A different person" and save again.'); }
}
function prd_worker_save(PDO $db, array $x, array $f, string $code = ''): string {
    prd_need($x, 'entry'); $v = prd_worker_input($f, $x['branches'], date('Y-m-d'));
    if ($code !== '') {
        $W = prd_workers($db, $x); if (!isset($W[$code])) throw new RuntimeException('Worker not found.'); $old = $W[$code];
        prd_worker_dupes($db, $v, $code, !empty($f['same_ok']) || strcasecmp($old['name'], $v['name']) === 0);
        $act = empty($f['active']) ? 0 : 1; $why = mb_substr(trim((string)($f['off_reason'] ?? '')), 0, 255);
        if (!$act && (int)$old['active'] && $why === '') throw new RuntimeException('Say why the worker is switched off.');
        $db->prepare('UPDATE prd_worker SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($v))) . ', active = ?, off_reason = ?, updated_at = NOW() WHERE code = ?')->execute([...array_values($v), $act, $act ? '' : ($why ?: $old['off_reason']), $code]);
        $ch = array_keys(array_filter($v, fn($val, $k) => (string)$val !== (string)$old[$k], ARRAY_FILTER_USE_BOTH)); if ($act !== (int)$old['active']) $ch[] = $act ? 'switched on' : 'switched off';
        if ($ch) prd_log($db, $x['uid'], 'worker_edit', $code, '', implode(', ', $ch));
        return $code;
    }
    prd_worker_dupes($db, $v, '', !empty($f['same_ok']));
    $p = $x['set']['prefix']; $hi = $db->prepare("SELECT MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)) FROM prd_worker WHERE code LIKE ?"); $hi->execute([strlen($p) + 1, $p . '%']);
    $code = prd_next_code($p, (int)$hi->fetchColumn());
    $db->prepare('INSERT INTO prd_worker (code, ' . implode(',', array_keys($v)) . ', added_by) VALUES (?,' . implode(',', array_fill(0, count($v), '?')) . ',?)')->execute([$code, ...array_values($v), $x['uid']]);
    prd_log($db, $x['uid'], 'worker_add', $code); audit($x['uid'], 'prd_worker_add', $code);
    return $code;
}
function prd_rate_hist(PDO $db): array { $o = []; foreach ($db->query('SELECT code, eff_from, minor_hr FROM prd_rate WHERE removed_at IS NULL ORDER BY eff_from, id') as $r) $o[(string)$r['code']][(string)$r['eff_from']] = (int)$r['minor_hr']; return $o; }
function prd_rate_add(PDO $db, array $x, string $code, string $from, string $amount, string $note): void {
    prd_need($x, 'money'); $W = prd_workers($db, $x); if (!isset($W[$code])) throw new RuntimeException('Worker not found.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) throw new RuntimeException('Give the date the rate starts.');
    $m = prd_minor($amount); if ($m <= 0) throw new RuntimeException('Give the hourly rate.');
    prd_open_or_fail($db, substr($from, 0, 7), 'A rate starting in that month');
    $db->prepare('INSERT INTO prd_rate (code, eff_from, minor_hr, note, created_by) VALUES (?,?,?,?,?)')->execute([$code, $from, $m, mb_substr(trim($note), 0, 255), $x['uid']]);
    prd_log($db, $x['uid'], 'rate_add', $code, $from); audit($x['uid'], 'prd_rate', $code . ' from ' . $from);
}
function prd_piece_types(PDO $db, bool $activeOnly = false): array {
    $o = []; foreach ($db->query('SELECT * FROM prd_piece_type ORDER BY sort, name') as $t) if (!$activeOnly || (int)$t['active']) $o[(int)$t['id']] = $t + ['hist' => []];
    foreach ($db->query('SELECT type_id, eff_from, minor FROM prd_piece_rate ORDER BY eff_from, id') as $r) if (isset($o[(int)$r['type_id']])) $o[(int)$r['type_id']]['hist'][(string)$r['eff_from']] = (int)$r['minor'];
    return $o;
}
function prd_piece_type_save(PDO $db, array $x, int $id, string $name, bool $active): int {
    prd_need($x, 'money'); $name = mb_substr(trim($name), 0, 60); if ($name === '') throw new RuntimeException('Name the type of work.');
    $q = $db->prepare('SELECT id FROM prd_piece_type WHERE name = ? AND id <> ?'); $q->execute([$name, $id]); if ($q->fetchColumn()) throw new RuntimeException('That type is already on the list.');
    if ($id) $db->prepare('UPDATE prd_piece_type SET name = ?, active = ? WHERE id = ?')->execute([$name, $active ? 1 : 0, $id]);
    else { $db->prepare('INSERT INTO prd_piece_type (name, created_by) VALUES (?,?)')->execute([$name, $x['uid']]); $id = (int)$db->lastInsertId(); }
    prd_log($db, $x['uid'], 'piece_type', '', (string)$id, $name); return $id;
}
function prd_piece_rate_add(PDO $db, array $x, int $tid, string $from, string $amount): void {
    prd_need($x, 'money'); if (!isset(prd_piece_types($db)[$tid])) throw new RuntimeException('Type not found.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) throw new RuntimeException('Give the date the rate starts.');
    $m = prd_minor($amount); if ($m <= 0) throw new RuntimeException('Give the rate for one piece.');
    prd_open_or_fail($db, substr($from, 0, 7), 'A rate starting in that month');
    $db->prepare('INSERT INTO prd_piece_rate (type_id, eff_from, minor, created_by) VALUES (?,?,?,?)')->execute([$tid, $from, $m, $x['uid']]);
    prd_log($db, $x['uid'], 'piece_rate', '', (string)$tid, 'from ' . $from);
}
function prd_month(PDO $db, string $ym): array { $q = $db->prepare('SELECT * FROM prd_month WHERE ym = ?'); $q->execute([$ym]); return $q->fetch() ?: ['ym' => $ym, 'status' => 'draft', 'snapshot' => null]; }
function prd_open_or_fail(PDO $db, string $ym, string $what = 'This'): void {
    if (prd_month($db, $ym)['status'] === 'confirmed') throw new RuntimeException("$what: " . date('F Y', strtotime("$ym-01")) . ' is confirmed. Reopen it first.');
    $f = prd_settings()['first_month']; if ($f !== '' && $ym < $f) throw new RuntimeException('Production pay starts from ' . date('F Y', strtotime("$f-01")) . '.');
}
function prd_hours(PDO $db, string $ym): array { $q = $db->prepare('SELECT code, d, centi FROM prd_day WHERE d BETWEEN ? AND ?'); $q->execute(["$ym-01", date('Y-m-t', strtotime("$ym-01"))]); $o = []; foreach ($q as $r) $o[(string)$r['code']][(string)$r['d']] = (int)$r['centi']; return $o; }
/** $cells = [code => [d => text]]; only workers in scope, only days up to today, a month that is not confirmed. Returns changed cells. */
function prd_hours_save(PDO $db, array $x, string $ym, array $cells, string $today): int {
    prd_need($x, 'entry'); prd_open_or_fail($db, $ym, 'The hours'); $W = prd_workers($db, $x); $old = prd_hours($db, $ym); $n = 0;
    $up = $db->prepare('REPLACE INTO prd_day (code, d, centi, updated_by) VALUES (?,?,?,?)'); $del = $db->prepare('UPDATE prd_day SET centi = 0, updated_by = ? WHERE code = ? AND d = ?');
    $db->beginTransaction();
    try {
        foreach ($cells as $code => $days) {
            if (!isset($W[$code]) || !is_array($days)) continue;
            foreach ($days as $d => $txt) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) || substr((string)$d, 0, 7) !== $ym || $d > $today) continue;
                $c = prd_hours_parse((string)$txt); $was = $old[$code][$d] ?? null;
                if ($c === $was || ($c === null && !$was)) continue;
                if ($c === null) $del->execute([$x['uid'], $code, $d]); else $up->execute([$code, $d, $c, $x['uid']]);
                $n++;
            }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    if ($n) prd_log($db, $x['uid'], 'hours', '', $ym, $n . ' cells');
    return $n;
}
function prd_pieces(PDO $db, string $ym, array $W, bool $removed = false): array {
    $q = $db->prepare('SELECT p.*, t.name AS type FROM prd_piece p JOIN prd_piece_type t ON t.id = p.type_id WHERE p.d BETWEEN ? AND ?' . ($removed ? '' : ' AND p.removed_at IS NULL') . ' ORDER BY p.d DESC, p.id DESC');
    $q->execute(["$ym-01", date('Y-m-t', strtotime("$ym-01"))]); return array_values(array_filter($q->fetchAll(), fn($r) => isset($W[(string)$r['code']])));
}
/** Pieces made: one line, or a team's pieces split evenly (any remainder to the first ones). */
function prd_pieces_add(PDO $db, array $x, string $d, int $tid, array $codes, int $qty, string $note, string $today): int {
    prd_need($x, 'entry'); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d > $today) throw new RuntimeException('The day: not later than today.');
    prd_open_or_fail($db, substr($d, 0, 7), 'That day');
    $T = prd_piece_types($db, true); if (!isset($T[$tid])) throw new RuntimeException('Pick the type of work.');
    $W = prd_workers($db, $x, true); $codes = array_values(array_unique(array_filter($codes, fn($c) => isset($W[$c]))));
    if (!$codes) throw new RuntimeException('Pick the worker (or the team).'); if ($qty <= 0 || $qty > 1000000) throw new RuntimeException('Give how many pieces.');
    $each = intdiv($qty, count($codes)); $left = $qty % count($codes);
    $ins = $db->prepare('INSERT INTO prd_piece (d, code, type_id, qty, note, created_by) VALUES (?,?,?,?,?,?)'); $n = 0;
    foreach ($codes as $i => $c) { $q = $each + ($i < $left ? 1 : 0); if ($q > 0) { $ins->execute([$d, $c, $tid, $q, mb_substr(trim($note), 0, 255), $x['uid']]); $n++; } }
    prd_log($db, $x['uid'], 'pieces', '', $d, $n . ' lines'); return $n;
}
function prd_piece_remove(PDO $db, array $x, int $id, string $why): void {
    prd_need($x, 'entry'); $why = trim($why); if (mb_strlen($why) < 3) throw new RuntimeException('Say why the line is taken off.');
    $q = $db->prepare('SELECT * FROM prd_piece WHERE id = ? AND removed_at IS NULL'); $q->execute([$id]); $r = $q->fetch();
    if (!$r || !isset(prd_workers($db, $x)[(string)$r['code']])) throw new RuntimeException('Line not found.');
    prd_open_or_fail($db, substr((string)$r['d'], 0, 7), 'That line');
    $db->prepare('UPDATE prd_piece SET removed_at = NOW(), removed_by = ?, removed_why = ? WHERE id = ?')->execute([$x['uid'], mb_substr($why, 0, 255), $id]);
    prd_log($db, $x['uid'], 'piece_off', (string)$r['code'], (string)$id);
}
function prd_adv_lines(PDO $db, string $to): array { $q = $db->prepare('SELECT * FROM prd_adv WHERE removed_at IS NULL AND d <= ? ORDER BY d, id'); $q->execute([$to]); return $q->fetchAll(); }
function prd_adv_add(PDO $db, array $x, array $f, string $today): void {
    prd_need($x, 'money'); $code = (string)($f['code'] ?? ''); $k = (string)($f['kind'] ?? ''); $d = (string)($f['d'] ?? '');
    if (!isset(prd_workers($db, $x)[$code])) throw new RuntimeException('Pick the worker.'); if (!isset(PRD_ADV_KINDS[$k])) throw new RuntimeException('Pick what the line is.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d > $today) throw new RuntimeException('Date: not later than today.');
    $m = prd_minor((string)($f['amount'] ?? '')); if ($m <= 0) throw new RuntimeException('Give the amount.');
    if (in_array($k, ['recovered', 'waived'], true) && $m > (prd_adv_sum(prd_adv_lines($db, $today))[$code] ?? 0)) throw new RuntimeException('More than the advance outstanding.');
    if ($k === 'given' && trim((string)($f['ref'] ?? '')) === '') throw new RuntimeException('An advance given needs its payment reference.');
    $db->prepare('INSERT INTO prd_adv (code, d, kind, minor, ref, note, created_by) VALUES (?,?,?,?,?,?,?)')->execute([$code, $d, $k, $m, mb_substr(trim((string)($f['ref'] ?? '')), 0, 120), mb_substr(trim((string)($f['note'] ?? '')), 0, 255), $x['uid']]);
    prd_log($db, $x['uid'], 'adv_' . $k, $code, $d); audit($x['uid'], 'prd_advance', $code . ' ' . $k);
}
function prd_adv_remove(PDO $db, array $x, int $id, string $why): void {
    prd_need($x, 'money'); $why = trim($why); if (mb_strlen($why) < 3) throw new RuntimeException('Say why the line is taken off.');
    $q = $db->prepare('SELECT * FROM prd_adv WHERE id = ? AND removed_at IS NULL'); $q->execute([$id]); $r = $q->fetch();
    if (!$r || !isset(prd_workers($db, $x)[(string)$r['code']])) throw new RuntimeException('Line not found.');
    if ($r['pay_ym'] !== null) throw new RuntimeException('This recovery came from a confirmed pay run — reopen that month instead.');
    $db->prepare('UPDATE prd_adv SET removed_at = NOW(), removed_by = ?, removed_why = ? WHERE id = ?')->execute([$x['uid'], mb_substr($why, 0, 255), $id]);
    prd_log($db, $x['uid'], 'adv_off', (string)$r['code'], (string)$id);
}
function prd_inputs(PDO $db, string $ym): array { $q = $db->prepare('SELECT * FROM prd_input WHERE ym = ?'); $q->execute([$ym]); $o = []; foreach ($q as $r) $o[(string)$r['code']] = $r; return $o; }
function prd_inputs_save(PDO $db, array $x, string $ym, array $rows): int {
    prd_need($x, 'money'); prd_open_or_fail($db, $ym, 'The pay run'); $W = prd_workers($db, $x); $old = prd_inputs($db, $ym); $n = 0;
    $up = $db->prepare('REPLACE INTO prd_input (ym, code, ' . implode(',', PRD_INPUT_KEYS) . ', note, updated_by, updated_at) VALUES (?,?,' . implode(',', array_fill(0, count(PRD_INPUT_KEYS), '?')) . ',?,?,NOW())');
    $db->beginTransaction();
    try {
        foreach ($rows as $code => $f) {
            if (!isset($W[$code]) || !is_array($f)) continue;
            $v = []; foreach (PRD_INPUT_KEYS as $k) $v[$k] = prd_minor((string)($f[$k] ?? '')); $note = mb_substr(trim((string)($f['note'] ?? '')), 0, 255); $o = $old[$code] ?? null;
            if (($o && $note === (string)$o['note'] && !array_filter(PRD_INPUT_KEYS, fn($k) => (int)$o[$k] !== $v[$k])) || (!$o && !array_filter($v) && $note === '')) continue;
            $up->execute([$ym, $code, ...array_values($v), $note, $x['uid']]); $n++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    if ($n) prd_log($db, $x['uid'], 'inputs', '', $ym, $n . ' workers');
    return $n;
}
/** The month for workers in scope: code => worker + pay. A confirmed month comes from its snapshot. $money false → hours and pieces only. */
function prd_month_rows(PDO $db, array $x, string $ym, ?bool $money = null): array {
    $money ??= $x['R']['money']; $W = prd_workers($db, $x); $M = prd_month($db, $ym);
    if ($M['status'] === 'confirmed' && $M['snapshot']) {
        $o = []; foreach ((json_decode((string)$M['snapshot'], true)['rows'] ?? []) as $c => $r) if (isset($W[$c])) $o[$c] = $W[$c] + ['pay' => $money ? $r['pay'] : prd_hours_only($r['pay'])];
        return $o;
    }
    $S = $x['set']; $H = prd_hours($db, $ym); $R = prd_rate_hist($db); $T = prd_piece_types($db); $I = prd_inputs($db, $ym);
    $A = prd_adv_sum(prd_adv_lines($db, date('Y-m-t', strtotime("$ym-01"))), $ym); $P = [];
    foreach (prd_pieces($db, $ym, $W) as $l) $P[(string)$l['code']][] = [(string)$l['d'], (int)$l['qty'], prd_in_force($T[(int)$l['type_id']]['hist'] ?? [], (string)$l['d'])];
    $o = [];
    foreach ($W as $c => $w) {
        if (!(int)$w['active'] && !isset($H[$c]) && !isset($P[$c]) && !isset($I[$c])) continue;
        $p = prd_pay($H[$c] ?? [], $R[$c] ?? [], $P[$c] ?? [], $I[$c] ?? [], $A[$c] ?? 0, (int)$S['std_centi'], (int)$S['ot_permille']);
        $o[$c] = $w + ['pay' => $money ? $p : prd_hours_only($p)];
    }
    return $o;
}
/** Confirm a month once it has ended; refused while any row has a red flag. Recoveries go into the advances ledger, once. Every worker in the organisation is snapshotted. */
function prd_month_confirm(PDO $db, array $x, string $ym, string $today): void {
    prd_need($x, 'money'); if ($x['scope'] !== null) throw new RuntimeException('Only a login that sees every branch confirms the pay run.');
    prd_open_or_fail($db, $ym, 'The pay run'); if ($ym >= substr($today, 0, 7)) throw new RuntimeException('A month can be confirmed once it has ended.');
    $rows = prd_month_rows($db, $x, $ym, true);
    $bad = array_filter($rows, fn($r) => array_filter($r['pay']['flags'], fn($f) => $f[0] === 'bad'));
    if ($bad) throw new RuntimeException('Fix before confirming — ' . implode(' · ', array_slice(array_map(fn($c, $r) => $c . ': ' . implode(', ', array_column(array_filter($r['pay']['flags'], fn($f) => $f[0] === 'bad'), 1)), array_keys($bad), $bad), 0, 5)));
    $snap = json_encode(['rules' => ['std_centi' => $x['set']['std_centi'], 'ot_permille' => $x['set']['ot_permille']], 'rows' => array_map(fn($r) => ['pay' => $r['pay']], $rows)]);
    $last = date('Y-m-t', strtotime("$ym-01"));
    $db->beginTransaction();
    try {
        $ins = $db->prepare("INSERT INTO prd_adv (code, d, kind, minor, ref, note, pay_ym, created_by) VALUES (?, ?, 'recovered', ?, ?, 'Recovered in the pay run', ?, ?)");
        foreach ($rows as $c => $r) if (($m = (int)$r['pay']['in']['adv_recover']) > 0) $ins->execute([$c, $last, $m, 'Pay run ' . $ym, $ym, $x['uid']]);
        $db->prepare("INSERT INTO prd_month (ym, status, rules, snapshot, confirmed_by, confirmed_at) VALUES (?, 'confirmed', '', ?, ?, NOW()) ON DUPLICATE KEY UPDATE status = 'confirmed', snapshot = VALUES(snapshot), confirmed_by = VALUES(confirmed_by), confirmed_at = NOW()")->execute([$ym, $snap, $x['uid']]);
        prd_log($db, $x['uid'], 'month_confirm', '', $ym, count($rows) . ' workers');
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit($x['uid'], 'prd_month_confirm', $ym . ' · ' . count($rows) . ' workers');
}
function prd_month_reopen(PDO $db, array $x, string $ym, string $why): void {
    prd_need($x, 'money'); $why = trim($why); if (mb_strlen($why) < 5) throw new RuntimeException('Say why the month is reopened.');
    if (prd_month($db, $ym)['status'] !== 'confirmed') throw new RuntimeException('The month is not confirmed.');
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE prd_adv SET removed_at = NOW(), removed_by = ?, removed_why = 'Pay run reopened' WHERE pay_ym = ? AND removed_at IS NULL")->execute([$x['uid'], $ym]);
        $db->prepare("UPDATE prd_month SET status = 'draft', reopened_by = ?, reopened_at = NOW(), reopen_why = ? WHERE ym = ?")->execute([$x['uid'], mb_substr($why, 0, 255), $ym]);
        prd_log($db, $x['uid'], 'month_reopen', '', $ym, $why);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit($x['uid'], 'prd_month_reopen', $ym);
}
