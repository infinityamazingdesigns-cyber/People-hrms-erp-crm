<?php
// Performance (KRA) — rules. Role sheets: KRAs with weights totalling 100, each with KPIs (target, unit, polarity,
// relative weight). Holders by employee code. One evaluation per person, sheet and period: the person gives actuals
// and comments, submits; the reporting manager reviews (agrees or adjusts with a comment) or returns; HR closes or
// returns. Score = KPI achievement (capped) weighted inside the KRA, then by KRA weight; a band from the settings.
// Pure functions first, then the database half.
declare(strict_types=1);
require_once __DIR__ . '/orgchart.php';

const PMS_STATUS = ['none' => 'Not started', 'draft' => 'Started', 'submitted' => 'With manager', 'returned' => 'Returned to the person', 'reviewed' => 'With HR', 'closed' => 'Closed'];
const PMS_POLARITY = ['higher' => 'Higher is better', 'lower' => 'Lower is better', 'zero' => 'Zero is the target'];
const PMS_CYCLES = ['M' => 'Monthly', 'Q' => 'Quarterly', 'H' => 'Half-yearly', 'Y' => 'Yearly'];
const PMS_DEFAULTS = ['hr_roles' => ['SUPERADMIN', 'HRMANAGER', 'HRPERF'], 'cycle' => 'Q', 'cap' => 120, 'hr_step' => true, 'notify' => true,
    'bands' => [['min' => 90, 'label' => 'Outstanding'], ['min' => 75, 'label' => 'Exceeds expectations'], ['min' => 60, 'label' => 'Meets expectations'],
                ['min' => 40, 'label' => 'Needs improvement'], ['min' => 0, 'label' => 'Unsatisfactory']]];

function pms_settings(): array {
    $s = module_states()['pms']['settings'] ?? [];
    return array_replace(PMS_DEFAULTS, array_intersect_key($s, PMS_DEFAULTS));
}

function pms_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    $o['hr_roles'] = array_values(array_intersect(array_keys($roles), (array)($in['hr_roles'] ?? [])));
    if (!$o['hr_roles']) $e[] = 'Pick at least one HR role.';
    $o['cycle'] = isset(PMS_CYCLES[$in['cycle'] ?? '']) ? (string)$in['cycle'] : 'Q';
    $o['cap'] = max(100, min(200, (int)($in['cap'] ?? 120)));
    $o['hr_step'] = !empty($in['hr_step']); $o['notify'] = !empty($in['notify']);
    $b = [];
    foreach ((array)($in['b_label'] ?? []) as $i => $l) { $l = mb_substr(trim(strip_tags((string)$l)), 0, 40); if ($l !== '') $b[] = ['min' => max(0, min(200, (int)($in['b_min'][$i] ?? 0))), 'label' => $l]; }
    usort($b, fn($x, $y) => $y['min'] <=> $x['min']);
    if (!$b || end($b)['min'] !== 0) $e[] = 'The bands need one starting at 0.';
    $o['bands'] = $b ?: PMS_DEFAULTS['bands'];
    return [$o, $e];
}

/** Clean a role sheet's KRAs. Weights must total 100; every KPI needs a name and a number target. Pure. */
function pms_body_check(array $kras): array {
    $out = []; $err = []; $sum = 0; $ids = [];
    foreach ($kras as $i => $k) {
        $name = mb_substr(trim(strip_tags((string)($k['name'] ?? ''))), 0, 120);
        if ($name === '' && !array_filter((array)($k['kpis'] ?? []), fn($x) => trim((string)($x['name'] ?? '')) !== '')) continue;
        if ($name === '') { $err[] = 'KRA ' . ($i + 1) . ' needs a name.'; continue; }
        $w = (int)($k['weight'] ?? 0); $sum += $w;
        if ($w <= 0) $err[] = "$name: give it a weight.";
        $kp = [];
        foreach ((array)($k['kpis'] ?? []) as $j => $x) {
            $n = mb_substr(trim(strip_tags((string)($x['name'] ?? ''))), 0, 160); if ($n === '') continue;
            $t = str_replace(',', '', trim((string)($x['target'] ?? '')));
            if (!is_numeric($t) || (float)$t < 0) { $err[] = "$name · $n: the target must be a number."; continue; }
            $id = preg_match('/^[a-z0-9_-]{1,20}$/i', (string)($x['id'] ?? '')) ? (string)$x['id'] : 'k' . substr(md5($name . '|' . $n), 0, 8);
            while (isset($ids[$id])) $id .= 'x';
            $ids[$id] = true;
            $kp[] = ['id' => $id, 'name' => $n, 'measure' => mb_substr(trim(strip_tags((string)($x['measure'] ?? ''))), 0, 300), 'unit' => mb_substr(trim(strip_tags((string)($x['unit'] ?? ''))), 0, 12),
                     'target' => (float)$t, 'polarity' => isset(PMS_POLARITY[$x['polarity'] ?? '']) ? (string)$x['polarity'] : 'higher', 'w' => max(1, min(10, (int)($x['w'] ?? 1)))];
        }
        if (!$kp) $err[] = "$name: add at least one KPI.";
        $out[] = ['name' => $name, 'weight' => $w, 'kpis' => $kp];
    }
    if (!$out) $err[] = 'Add at least one KRA.';
    elseif ($sum !== 100) $err[] = "The KRA weights add up to $sum — they must total 100.";
    return [$out, $err];
}

/** Achievement % of one KPI for an actual (null = not given), capped. Pure. */
function pms_ach(array $k, $a, int $cap): ?float {
    if ($a === null || $a === '' || !is_numeric($a)) return null;
    $a = (float)$a; $t = (float)$k['target'];
    if ($k['polarity'] === 'zero') $v = $a <= 0 ? 100.0 : max(0.0, 100 - 25 * $a);
    elseif ($k['polarity'] === 'lower') $v = $a <= 0 ? (float)$cap : ($t / $a) * 100;
    else $v = $t <= 0 ? ($a > 0 ? (float)$cap : 100.0) : ($a / $t) * 100;
    return round(max(0.0, min((float)$cap, $v)), 1);
}

/**
 * Score a sheet with actuals (kpi id => value). Each KRA = weighted average of its KPIs that have an actual; the
 * total = KRA scores weighted by KRA weight, over the KRAs with an actual. Pure.
 */
function pms_score(array $kras, array $actuals, int $cap): array {
    $rows = []; $got = 0.0; $wsum = 0;
    foreach ($kras as $k) {
        $s = 0.0; $w = 0; $kp = [];
        foreach ($k['kpis'] as $x) {
            $v = pms_ach($x, $actuals[$x['id']] ?? null, $cap);
            $kp[$x['id']] = $v;
            if ($v !== null) { $s += $v * $x['w']; $w += $x['w']; }
        }
        $ks = $w ? round($s / $w, 1) : null;
        if ($ks !== null) { $got += $ks * $k['weight']; $wsum += $k['weight']; }
        $rows[] = ['name' => $k['name'], 'weight' => $k['weight'], 'score' => $ks, 'kpis' => $kp];
    }
    return ['total' => $wsum ? round($got / $wsum, 1) : null, 'kras' => $rows, 'covered' => $wsum];
}

/** The band for a score. Pure. */
function pms_band(?float $v, array $bands): string {
    if ($v === null) return 'Not scored';
    usort($bands, fn($x, $y) => $y['min'] <=> $x['min']);
    foreach ($bands as $b) if ($v >= $b['min']) return $b['label'];
    return 'Not scored';
}

/** The review period a day falls in, for the cycle and the year's first month: [key, label]. Pure. */
function pms_period(string $day, string $cycle, int $fyStart): array {
    $y = (int)substr($day, 0, 4); $m = (int)substr($day, 5, 2);
    if ($cycle === 'M') return [sprintf('%04d-%02d', $y, $m), date('F Y', mktime(0, 0, 0, $m, 1, $y))];
    $fy = $m >= $fyStart ? $y : $y - 1;
    $pos = ($m - $fyStart + 12) % 12;   // 0 = first month of the year
    $fyl = $fyStart === 1 ? (string)$fy : $fy . '-' . substr((string)($fy + 1), 2);
    if ($cycle === 'Q') { $n = intdiv($pos, 3) + 1; return ["FY$fy-Q$n", "Q$n $fyl"]; }
    if ($cycle === 'H') { $n = intdiv($pos, 6) + 1; return ["FY$fy-H$n", "H$n $fyl"]; }
    return ["FY$fy", "Year $fyl"];
}

/** The period before / after a key (step -1 / +1). Pure. */
function pms_period_step(string $key, int $step, string $cycle, int $fyStart): array {
    if ($cycle === 'M') { $d = date('Y-m-15', strtotime(substr($key, 0, 7) . "-15 $step months")); return pms_period($d, 'M', $fyStart); }
    if (!preg_match('/^FY(\d{4})(?:-([QH])(\d))?$/', $key, $m)) return pms_period(date('Y-m-d'), $cycle, $fyStart);
    $len = ['Q' => 3, 'H' => 6, 'Y' => 12][$cycle];
    $pos = isset($m[3]) ? ((int)$m[3] - 1) * $len : 0;
    $start = mktime(0, 0, 0, $fyStart + $pos + $step * $len, 15, (int)$m[1]);
    return pms_period(date('Y-m-d', $start), $cycle, $fyStart);
}

/** Can this step happen now? '' = yes. $who: self | manager | hr. Pure. */
function pms_step_problem(string $status, string $step, string $who): string {
    $ok = ['save' => ['self' => ['none', 'draft', 'returned']], 'submit' => ['self' => ['none', 'draft', 'returned']], 'recall' => ['self' => ['submitted']],
           'review' => ['manager' => ['submitted'], 'hr' => ['submitted']], 'return' => ['manager' => ['submitted'], 'hr' => ['submitted', 'reviewed']],
           'close' => ['hr' => ['reviewed']], 'reopen' => ['hr' => ['closed']]];
    return in_array($status, $ok[$step][$who] ?? [], true) ? '' : 'This step is not possible now (' . (PMS_STATUS[$status] ?? $status) . ').';
}

/** Actuals from a form: kpi id => number or null; comments kpi id => text. Pure. */
function pms_actuals(array $kras, array $in): array {
    $a = []; $c = [];
    foreach ($kras as $k) foreach ($k['kpis'] as $x) {
        $v = str_replace(',', '', trim((string)($in['v'][$x['id']] ?? '')));
        $a[$x['id']] = $v === '' ? null : (is_numeric($v) ? (float)$v : throw new InvalidArgumentException($x['name'] . ': the actual must be a number.'));
        $c[$x['id']] = mb_substr(trim(strip_tags((string)($in['c'][$x['id']] ?? ''))), 0, 500);
    }
    return [$a, $c];
}

// ---------- database ----------

function pms_is_hr(array $u): bool { return (bool)array_intersect(user_roles((int)$u['id'], !empty($u['is_owner'])), pms_settings()['hr_roles']); }
function pms_log(PDO $db, string $kind, int $id, string $action, int $uid): void { $db->prepare('INSERT INTO pms_log (kind, ref_id, action, user_id) VALUES (?,?,?,?)')->execute([$kind, $id, $action, $uid]); }
function pms_fy_start(): int { return max(1, min(12, (int)(org('fy_start_month') ?: 1))); }
function pms_current(): array { $s = pms_settings(); return pms_period(date('Y-m-d'), $s['cycle'], pms_fy_start()); }

function pms_sheet_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM pms_sheet WHERE id = ?'); $s->execute([$id]); $r = $s->fetch();
    if ($r) $r['kras'] = (array)json_decode((string)$r['body'], true);
    return $r ?: null;
}

/** Save a sheet (HR). A change to the KRAs keeps the earlier version and raises the version. */
function pms_sheet_save(PDO $db, array $u, int $id, array $in): int {
    if (!pms_is_hr($u)) throw new RuntimeException('Only HR changes role sheets.');
    $title = mb_substr(trim(strip_tags((string)($in['title'] ?? ''))), 0, 120);
    if ($title === '') throw new RuntimeException('Give the role a title.');
    $code = strtolower(trim((string)($in['code'] ?? '')));
    $code = preg_replace('/[^a-z0-9-]+/', '-', $code !== '' ? $code : $title); $code = trim(substr((string)$code, 0, 40), '-');
    [$kras, $err] = pms_body_check((array)($in['kras'] ?? []));
    if ($err && ($in['status'] ?? 'draft') === 'active') throw new RuntimeException(implode(' ', $err));
    $body = json_encode($kras);
    $st = in_array($in['status'] ?? '', ['draft', 'active', 'retired'], true) ? (string)$in['status'] : 'draft';
    $f = [$code, $title, mb_substr(trim(strip_tags((string)($in['department'] ?? ''))), 0, 80), mb_substr(trim(strip_tags((string)($in['grade'] ?? ''))), 0, 20), mb_substr(trim(strip_tags((string)($in['summary'] ?? ''))), 0, 3000)];
    $dup = $db->prepare('SELECT id FROM pms_sheet WHERE code = ? AND id <> ?'); $dup->execute([$code, $id]);
    if ($dup->fetchColumn()) throw new RuntimeException('Another sheet has that code.');
    if ($id) {
        $old = pms_sheet_get($db, $id); if (!$old) throw new RuntimeException('Sheet not found.');
        $ver = (int)$old['version'];
        if ($old['body'] !== $body) { $db->prepare('INSERT INTO pms_sheet_rev (sheet_id, version, body, by_user) VALUES (?,?,?,?)')->execute([$id, $ver, $old['body'], (int)$u['id']]); $ver++; }
        $db->prepare('UPDATE pms_sheet SET code = ?, title = ?, department = ?, grade = ?, summary = ?, body = ?, version = ?, status = ?, updated_by = ? WHERE id = ?')->execute([...$f, $body, $ver, $st, (int)$u['id'], $id]);
        pms_log($db, 'sheet', $id, 'edit v' . $ver, (int)$u['id']);
    } else {
        $db->prepare('INSERT INTO pms_sheet (code, title, department, grade, summary, body, status, created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([...$f, $body, $st, (int)$u['id']]);
        $id = (int)$db->lastInsertId(); pms_log($db, 'sheet', $id, 'add', (int)$u['id']);
    }
    return $id;
}

/** Give a sheet to an employee (HR). A person may hold several sheets with shares adding to 100. */
function pms_assign(PDO $db, array $u, int $sheetId, string $code, int $share, string $fromYm): void {
    if (!pms_is_hr($u)) throw new RuntimeException('Only HR links sheets to people.');
    $sh = pms_sheet_get($db, $sheetId);
    if (!$sh || $sh['status'] !== 'active') throw new RuntimeException('Only an active sheet can be linked.');
    $e = emp_by_code($db, strtoupper(trim($code)));
    if (!$e || !(int)$e['active']) throw new RuntimeException('Pick someone on the roll.');
    if (!preg_match('/^\d{4}-\d{2}$/', $fromYm)) throw new RuntimeException('Give the month it starts from.');
    $share = max(1, min(100, $share));
    $s = $db->prepare('SELECT sheet_id, share FROM pms_assign WHERE emp_code = ? AND removed_at IS NULL'); $s->execute([$e['code']]); $have = $s->fetchAll();
    if (in_array($sheetId, array_map(fn($r) => (int)$r['sheet_id'], $have), true)) throw new RuntimeException($e['name'] . ' already holds this sheet.');
    if (array_sum(array_column($have, 'share')) + $share > 100) throw new RuntimeException('The shares of ' . $e['name'] . "'s sheets would pass 100%.");
    $db->prepare('INSERT INTO pms_assign (sheet_id, emp_code, share, from_ym, created_by) VALUES (?,?,?,?,?)')->execute([$sheetId, $e['code'], $share, $fromYm, (int)$u['id']]);
    pms_log($db, 'sheet', $sheetId, 'assign', (int)$u['id']);
    $db->prepare("UPDATE pms_request SET status = 'closed', closed_by = ?, closed_at = NOW(), close_note = 'Sheet linked' WHERE emp_code = ? AND status = 'open'")->execute([(int)$u['id'], $e['code']]);
}

function pms_unassign(PDO $db, array $u, int $id): void {
    if (!pms_is_hr($u)) throw new RuntimeException('Only HR changes this.');
    $db->prepare('UPDATE pms_assign SET removed_at = NOW(), removed_by = ? WHERE id = ? AND removed_at IS NULL')->execute([(int)$u['id'], $id]);
}

/** Sheets a person holds for a period (from month on or before the period's last month). */
function pms_holdings(PDO $db, string $code): array {
    $s = $db->prepare("SELECT a.*, s.title, s.code AS scode, s.version FROM pms_assign a JOIN pms_sheet s ON s.id = a.sheet_id WHERE a.emp_code = ? AND a.removed_at IS NULL AND s.status = 'active' ORDER BY a.id");
    $s->execute([strtoupper($code)]);
    return $s->fetchAll();
}

function pms_eval_get(PDO $db, string $code, int $sheetId, string $period): ?array {
    $s = $db->prepare('SELECT * FROM pms_eval WHERE emp_code = ? AND sheet_id = ? AND period = ?'); $s->execute([strtoupper($code), $sheetId, $period]);
    $e = $s->fetch();
    if ($e) { $e['kras'] = (array)json_decode((string)$e['body'], true); $e['self_a'] = (array)json_decode((string)$e['self'], true); $e['mgr_a'] = (array)json_decode((string)$e['mgr'], true); }
    return $e ?: null;
}
function pms_eval_by_id(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT emp_code, sheet_id, period FROM pms_eval WHERE id = ?'); $s->execute([$id]); $r = $s->fetch();
    return $r ? pms_eval_get($db, $r['emp_code'], (int)$r['sheet_id'], $r['period']) : null;
}

/** The person saves or submits their own evaluation. The sheet as it is today is kept with it. */
function pms_self(PDO $db, array $u, int $sheetId, string $period, array $in, bool $submit): int {
    $code = strtoupper((string)($u['emp_code'] ?? ''));
    if ($code === '') throw new RuntimeException('Your login is not linked to an employee code.');
    if (!in_array($sheetId, array_map(fn($r) => (int)$r['sheet_id'], pms_holdings($db, $code)), true)) throw new RuntimeException('You do not hold this sheet.');
    $e = pms_eval_get($db, $code, $sheetId, $period);
    if (($p = pms_step_problem($e['status'] ?? 'none', $submit ? 'submit' : 'save', 'self')) !== '') throw new RuntimeException($p);
    $sh = pms_sheet_get($db, $sheetId);
    $kras = $e && $e['status'] !== 'none' ? $e['kras'] : $sh['kras'];
    [$a, $c] = pms_actuals($kras, $in);
    if ($submit && !array_filter($a, fn($v) => $v !== null)) throw new RuntimeException('Give at least one actual before you submit.');
    $score = pms_score($kras, $a, (int)pms_settings()['cap'])['total'];
    $emp = emp_by_code($db, $code);
    $data = json_encode(['a' => $a, 'c' => $c]);
    $note = mb_substr(trim(strip_tags((string)($in['self_note'] ?? ''))), 0, 2000);
    if ($e) {
        $db->prepare('UPDATE pms_eval SET self = ?, self_note = ?, self_score = ?, status = ?, self_at = ' . ($submit ? 'NOW()' : 'self_at') . ', mgr_code = ? WHERE id = ?')
           ->execute([$data, $note, $score, $submit ? 'submitted' : 'draft', strtoupper((string)($emp['manager_code'] ?? '')), (int)$e['id']]);
        $id = (int)$e['id'];
    } else {
        $db->prepare('INSERT INTO pms_eval (emp_code, sheet_id, period, body, version, status, self, self_note, self_score, self_at, mgr_code) VALUES (?,?,?,?,?,?,?,?,?,' . ($submit ? 'NOW()' : 'NULL') . ',?)')
           ->execute([$code, $sheetId, $period, json_encode($kras), (int)$sh['version'], $submit ? 'submitted' : 'draft', $data, $note, $score, strtoupper((string)($emp['manager_code'] ?? ''))]);
        $id = (int)$db->lastInsertId();
    }
    pms_log($db, 'eval', $id, $submit ? 'submit' : 'save', (int)$u['id']);
    if ($submit) pms_notify($db, $id, 'submitted');
    return $id;
}

/** Take a submission back before the manager looks at it. */
function pms_recall(PDO $db, array $u, int $id): void {
    $e = pms_eval_by_id($db, $id);
    if (!$e || strcasecmp($e['emp_code'], (string)($u['emp_code'] ?? '')) !== 0) throw new RuntimeException('Not found.');
    if (($p = pms_step_problem($e['status'], 'recall', 'self')) !== '') throw new RuntimeException($p);
    $db->prepare("UPDATE pms_eval SET status = 'draft' WHERE id = ?")->execute([$id]); pms_log($db, 'eval', $id, 'recall', (int)$u['id']);
}

/** Who may act on an evaluation: 'manager' (the person's reporting manager), 'hr', or '' — never on one's own. */
function pms_role_on(PDO $db, array $u, array $e): string {
    $me = strtoupper((string)($u['emp_code'] ?? ''));
    if ($me !== '' && strcasecmp($me, $e['emp_code']) === 0) return '';
    if (pms_is_hr($u)) return 'hr';
    $emp = emp_by_code($db, $e['emp_code']);
    return $me !== '' && $emp && strtoupper((string)$emp['manager_code']) === $me ? 'manager' : '';
}

/** Manager (or HR) review: actuals agreed or adjusted, comments, overall note. Moves to HR (or closed when there is no HR step). */
function pms_review(PDO $db, array $u, int $id, array $in): void {
    $e = pms_eval_by_id($db, $id);
    if (!$e) throw new RuntimeException('Not found.');
    $who = pms_role_on($db, $u, $e);
    if ($who === '') throw new RuntimeException('Only the reporting manager or HR reviews this — never your own.');
    if (($p = pms_step_problem($e['status'], 'review', $who)) !== '') throw new RuntimeException($p);
    [$a, $c] = pms_actuals($e['kras'], $in);
    foreach ($a as $k => $v) if ($v === null) $a[$k] = $e['self_a']['a'][$k] ?? null;   // blank = agree with the person
    $note = mb_substr(trim(strip_tags((string)($in['mgr_note'] ?? ''))), 0, 2000);
    if ($note === '') throw new RuntimeException('Write an overall comment.');
    $score = pms_score($e['kras'], $a, (int)pms_settings()['cap'])['total'];
    $next = pms_settings()['hr_step'] ? 'reviewed' : 'closed';
    $db->prepare('UPDATE pms_eval SET mgr = ?, mgr_note = ?, mgr_score = ?, mgr_by = ?, mgr_at = NOW(), status = ? WHERE id = ?')->execute([json_encode(['a' => $a, 'c' => $c]), $note, $score, (int)$u['id'], $next, $id]);
    pms_log($db, 'eval', $id, 'review', (int)$u['id']);
    pms_notify($db, $id, $next);
}

/** Send back to the person (manager while with them; HR while with the manager or HR). A note is needed. */
function pms_return(PDO $db, array $u, int $id, string $note): void {
    $e = pms_eval_by_id($db, $id);
    if (!$e) throw new RuntimeException('Not found.');
    $who = pms_role_on($db, $u, $e);
    if ($who === '' || ($p = pms_step_problem($e['status'], 'return', $who)) !== '') throw new RuntimeException($p ?? 'Not allowed.');
    if (($note = mb_substr(trim(strip_tags($note)), 0, 500)) === '') throw new RuntimeException('Say what to change.');
    $db->prepare("UPDATE pms_eval SET status = 'returned', return_note = ? WHERE id = ?")->execute([$note, $id]);
    pms_log($db, 'eval', $id, 'return', (int)$u['id']);
    pms_notify($db, $id, 'returned');
}

/** HR closes a reviewed evaluation (with an optional note) or opens a closed one again (note needed). */
function pms_close(PDO $db, array $u, int $id, string $note, bool $reopen = false): void {
    $e = pms_eval_by_id($db, $id);
    if (!$e || pms_role_on($db, $u, $e) !== 'hr') throw new RuntimeException('Only HR closes evaluations — never their own.');
    if (($p = pms_step_problem($e['status'], $reopen ? 'reopen' : 'close', 'hr')) !== '') throw new RuntimeException($p);
    $note = mb_substr(trim(strip_tags($note)), 0, 2000);
    if ($reopen && $note === '') throw new RuntimeException('Say why it is opened again.');
    $db->prepare('UPDATE pms_eval SET status = ?, hr_note = ?, hr_by = ?, hr_at = NOW() WHERE id = ?')->execute([$reopen ? 'reviewed' : 'closed', $note, (int)$u['id'], $id]);
    pms_log($db, 'eval', $id, $reopen ? 'reopen' : 'close', (int)$u['id']);
}

/** Email the next person (Email on): manager on submit, HR when reviewed, the person when returned. Never scores. */
function pms_notify(PDO $db, int $id, string $what): void {
    if (!pms_settings()['notify'] || !module_on('email') || !function_exists('mail_queue')) return;
    try {
        $e = pms_eval_by_id($db, $id); if (!$e) return;
        $emp = emp_by_code($db, $e['emp_code']); $name = (string)($emp['name'] ?? $e['emp_code']);
        $to = [];
        if ($what === 'submitted' && $e['mgr_code'] !== '') { $s = $db->prepare('SELECT email FROM users WHERE emp_code = ? AND active = 1'); $s->execute([$e['mgr_code']]); $to[] = (string)$s->fetchColumn(); }
        if ($what === 'returned') { $s = $db->prepare('SELECT email FROM users WHERE emp_code = ? AND active = 1'); $s->execute([$e['emp_code']]); $to[] = (string)$s->fetchColumn(); }
        if ($what === 'reviewed') {
            $roles = pms_settings()['hr_roles'];
            $s = $db->prepare('SELECT DISTINCT u.email FROM users u JOIN user_role r ON r.user_id = u.id WHERE u.active = 1 AND r.role_code IN (' . implode(',', array_fill(0, count($roles), '?')) . ')');
            $s->execute($roles); $to = $s->fetchAll(PDO::FETCH_COLUMN);
        }
        $text = ['submitted' => "$name sent their self-evaluation for {$e['period']}. Open Performance to review it.", 'reviewed' => "The review of $name for {$e['period']} is ready for HR.",
                 'returned' => "Your evaluation for {$e['period']} came back with a note. Open Performance to see it.", 'closed' => ''][$what] ?? '';
        if ($text === '') return;
        foreach (array_unique(array_filter($to, fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL))) as $t) mail_queue($db, ['to' => $t, 'subject' => 'Performance: ' . $name . ' · ' . $e['period'], 'text' => $text], 'notice', 'pms:' . $id);
    } catch (Throwable $ex) { error_log('pms notice: ' . $ex->getMessage()); }
}

/** HR console rows for a period: every holder with their evaluation state (and scores). Branch scope applies. */
function pms_console(PDO $db, array $u, string $period): array {
    $rows = $db->query("SELECT a.emp_code, a.sheet_id, a.share, s.title, e.name, e.department, e.branch_id, e.manager_code FROM pms_assign a JOIN pms_sheet s ON s.id = a.sheet_id
                        JOIN employee e ON e.code = a.emp_code WHERE a.removed_at IS NULL AND s.status = 'active' AND e.active = 1 ORDER BY e.name")->fetchAll();
    $ev = $db->prepare('SELECT id, status, self_score, mgr_score, period FROM pms_eval WHERE emp_code = ? AND sheet_id = ? AND period = ?');
    $other = $db->prepare("SELECT period FROM pms_eval WHERE emp_code = ? AND sheet_id = ? AND period <> ? AND status IN ('submitted','reviewed')");
    $out = [];
    foreach ($rows as $r) {
        if (!emp_in_scope($u, $r)) continue;
        $ev->execute([$r['emp_code'], (int)$r['sheet_id'], $period]); $x = $ev->fetch() ?: null;
        $other->execute([$r['emp_code'], (int)$r['sheet_id'], $period]);
        $out[] = $r + ['eval' => $x, 'elsewhere' => $other->fetchAll(PDO::FETCH_COLUMN)];
    }
    return $out;
}

/** People below this login (any depth) and their evaluations for the period — for managers. */
function pms_team(PDO $db, array $u, string $period): array {
    $me = strtoupper((string)($u['emp_code'] ?? '')); if ($me === '') return [];
    $people = $db->query('SELECT code, name, manager_code, department FROM employee WHERE active = 1')->fetchAll();
    $team = org_team($me, org_parent_map($people));
    if (!$team) return [];
    $names = array_column($people, null, 'code');
    $s = $db->prepare("SELECT a.emp_code, a.sheet_id, s.title, ev.id AS eval_id, ev.status, ev.self_score, ev.mgr_score FROM pms_assign a JOIN pms_sheet s ON s.id = a.sheet_id
                       LEFT JOIN pms_eval ev ON ev.emp_code = a.emp_code AND ev.sheet_id = a.sheet_id AND ev.period = ? WHERE a.removed_at IS NULL AND s.status = 'active' AND a.emp_code IN (" . implode(',', array_fill(0, count($team), '?')) . ')');
    $s->execute([$period, ...$team]);
    return array_map(fn($r) => $r + ['name' => $names[$r['emp_code']]['name'] ?? $r['emp_code'], 'direct' => strtoupper((string)($names[$r['emp_code']]['manager_code'] ?? '')) === $me], $s->fetchAll());
}

function pms_request(PDO $db, array $u, string $note): void {
    $code = strtoupper((string)($u['emp_code'] ?? ''));
    if ($code === '') throw new RuntimeException('Your login is not linked to an employee code — ask HR.');
    $s = $db->prepare("SELECT COUNT(*) FROM pms_request WHERE emp_code = ? AND status = 'open'"); $s->execute([$code]);
    if ((int)$s->fetchColumn()) throw new RuntimeException('You already asked; HR will link a sheet.');
    $db->prepare('INSERT INTO pms_request (emp_code, user_id, note) VALUES (?,?,?)')->execute([$code, (int)$u['id'], mb_substr(trim(strip_tags($note)), 0, 300)]);
}
