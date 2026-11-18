<?php
/**
 * Payroll part 3 — statutory returns from a locked month, the state summary, and arrears.
 *  - Returns are read only from a confirmed / released month's snapshot (never worked out again). Which returns exist
 *    comes from the country rule pack (`returns`); the pack builds the file. Anyone without the number the return
 *    needs is listed and left out. Downloads are logged with counts only.
 *  - Arrears: a locked month is worked out again from today's attendance, leave and rules (never saved); earnings that
 *    differ from its snapshot are offered on a draft month, once per person per snapshot version. Statutory is not
 *    worked out on arrears. Taken off with a reason, never deleted.
 */
declare(strict_types=1);

const PAY_ARREAR_MONTHS = 12;     // how far back locked months are compared
const PAY_ARREAR_MIN = 1.0;       // differences below this are ignored

/** Difference in earnings between a locked line and the same month worked out again. Pure. */
function pay_arrear_diff(array $old, array $new): array {
    $parts = [];
    foreach (array_unique(array_merge(array_keys((array)($old['earned'] ?? [])), array_keys((array)($new['earned'] ?? [])))) as $k) {
        $d = round((float)($new['earned'][$k] ?? 0) - (float)($old['earned'][$k] ?? 0), 2);
        if (abs($d) >= 0.005) $parts[$k] = $d;
    }
    $t = round(array_sum($parts), 2);
    return ['parts' => abs($t) >= PAY_ARREAR_MIN ? $parts : [], 'total' => abs($t) >= PAY_ARREAR_MIN ? $t : 0.0,
            'days' => round((float)($new['paid_days'] ?? 0) - (float)($old['paid_days'] ?? 0), 2)];
}

/** Professional tax and labour welfare fund by state from a month's lines. Pure. */
function pay_state_summary(array $lines): array {
    $o = [];
    foreach ($lines as $l) {
        if (!empty($l['missing_ctc'])) continue;
        $st = (string)($l['state'] ?? '') !== '' ? (string)$l['state'] : '—';
        $o[$st] ??= ['state' => $st, 'people' => 0, 'gross' => 0.0, 'pt' => 0.0, 'pt_people' => 0, 'lwf_emp' => 0.0, 'lwf_er' => 0.0];
        $o[$st]['people']++;
        $o[$st]['gross'] += (float)($l['gross'] ?? 0);
        if (($p = (float)($l['deductions']['Professional tax'] ?? 0)) > 0) { $o[$st]['pt'] += $p; $o[$st]['pt_people']++; }
        $o[$st]['lwf_emp'] += (float)($l['deductions']['Labour welfare fund'] ?? 0);
        $o[$st]['lwf_er'] += (float)($l['employer']['Labour welfare fund'] ?? 0);
    }
    ksort($o);
    return array_values($o);
}

/** The returns this country's pack offers, plus the state summary everywhere. Pure. */
function pay_return_kinds(array $pack): array {
    $k = (array)($pack['returns'] ?? []);
    $k['state'] ??= 'Deductions by state (CSV)';
    return $k;
}

/** Which KYC field a return needs: kind => [field, digits]. Pure. */
function pay_return_ids(string $kind): ?array {
    return ['ecr' => ['social_id', 12], 'esi' => ['health_id', 10]][$kind] ?? null;
}

/* ---------------- database ---------------- */

function pay_locked(array $m): bool { return in_array($m['status'], ['confirmed', 'released'], true); }

/** code => the KYC value a return needs (unsealed only here, for the download). */
function pay_kyc_values(PDO $db, array $codes, string $field): array {
    $o = [];
    foreach ($codes as $c) {
        $v = (string)(kyc_record($db, (string)$c, true)['values'][$field] ?? '');
        if ($v !== '') $o[strtoupper((string)$c)] = $v;
    }
    return $o;
}

/** Who a return would leave out, from KYC hints only (nothing unsealed). */
function pay_return_preview(PDO $db, array $lines, string $kind): array {
    $id = pay_return_ids($kind);
    if (!$id) return ['count' => count($lines), 'left_out' => []];
    $need = fn($l) => $kind === 'ecr' ? (isset($l['wages']['pf']) ? (float)$l['wages']['pf'] > 0 : isset($l['deductions']['PF'])) : isset($l['deductions']['ESI']) || isset($l['employer']['ESI']);
    $n = 0; $miss = [];
    foreach ($lines as $l) {
        if (!empty($l['missing_ctc']) || !$need($l)) continue;
        $h = (string)(kyc_record($db, (string)$l['emp_code'])['hints'][$id[0]] ?? '');
        if ($h === '') $miss[] = [$l['emp_code'], $l['name'], 'No ' . kyc_label($id[0], kyc_country())]; else $n++;
    }
    return ['count' => $n, 'left_out' => $miss];
}

/** Build a return file. Returns ['name', 'mime', 'body', 'count', 'left_out']. */
function pay_return(PDO $db, array $u, string $ym, string $kind): array {
    if (!pay_can($u)) throw new RuntimeException('Not allowed.');
    $m = pay_month_row($db, $ym);
    if (!pay_locked($m)) throw new RuntimeException('Returns are made from a confirmed month.');
    $pack = pay_pack();
    if (!isset(pay_return_kinds($pack)[$kind])) throw new InvalidArgumentException('This return is not part of the country rules.');
    $lines = array_values(array_filter(pay_build($db, $ym), fn($l) => empty($l['missing_ctc'])));
    if ($kind === 'state') {
        $body = bank_csv_line(['State', 'People', 'Gross', 'Professional tax', 'People with PT', 'Labour welfare fund (employee)', 'Labour welfare fund (employer)']);
        foreach (pay_state_summary($lines) as $r) $body .= bank_csv_line([$r['state'], $r['people'], number_format($r['gross'], 2, '.', ''), number_format($r['pt'], 2, '.', ''), $r['pt_people'], number_format($r['lwf_emp'], 2, '.', ''), number_format($r['lwf_er'], 2, '.', '')]);
        $out = ['name' => 'by-state-' . $ym . '.csv', 'mime' => 'text/csv', 'body' => $body, 'count' => count($lines), 'left_out' => []];
    } else {
        $id = pay_return_ids($kind);
        $vals = pay_kyc_values($db, array_column($lines, 'emp_code'), $id[0]);
        if ($kind === 'ecr' && function_exists('rp_in_ecr')) {
            $r = rp_in_ecr($lines, $vals);
            $out = ['name' => 'pf-ecr-' . $ym . '.txt', 'mime' => 'text/plain', 'body' => $r['text'], 'count' => $r['count'], 'left_out' => $r['left_out']];
        } elseif ($kind === 'esi' && function_exists('rp_in_esi')) {
            $exits = []; foreach ($lines as $l) if (!empty($l['last_day'])) $exits[strtoupper($l['emp_code'])] = $l['last_day'];
            $r = rp_in_esi($lines, $vals, $ym, $exits);
            $body = ''; foreach ($r['rows'] as $row) $body .= bank_csv_line($row);
            $out = ['name' => 'esi-' . $ym . '.csv', 'mime' => 'text/csv', 'body' => $body, 'count' => $r['count'], 'left_out' => $r['left_out']];
        } else throw new InvalidArgumentException('This return is not available.');
    }
    $db->prepare('INSERT INTO pay_return_log (ym, kind, lines_out, left_out, by_user) VALUES (?,?,?,?,?)')->execute([$ym, $kind, $out['count'], count($out['left_out']), (int)$u['id']]);
    audit((int)$u['id'], 'pay_return', $ym . ' ' . $kind . ' lines ' . $out['count'] . ' left out ' . count($out['left_out']));
    return $out;
}

/** code => net arrear amount on a month (active lines). */
function pay_arrear_for(PDO $db, string $ym): array {
    $s = $db->prepare('SELECT emp_code, SUM(amount) a FROM pay_arrear WHERE ym = ? AND active = 1 GROUP BY emp_code'); $s->execute([$ym]);
    $o = []; foreach ($s as $r) $o[strtoupper($r['emp_code'])] = (float)$r['a'];
    return $o;
}

function pay_arrear_lines(PDO $db, string $ym): array {
    $s = $db->prepare('SELECT a.*, e.name FROM pay_arrear a LEFT JOIN employee e ON e.code = a.emp_code WHERE a.ym = ? ORDER BY a.active DESC, a.from_ym, e.name'); $s->execute([$ym]);
    return $s->fetchAll();
}

/** Locked months before $ym whose earnings differ when worked out again, not yet carried. */
function pay_arrears(PDO $db, ?array $u, string $ym): array {
    $s = $db->prepare("SELECT ym, ver FROM pay_month WHERE status IN ('confirmed','released') AND ym < ? AND ym >= ? ORDER BY ym");
    $s->execute([$ym, date('Y-m', strtotime($ym . '-01 -' . PAY_ARREAR_MONTHS . ' months'))]);
    $done = $db->query('SELECT from_ym, from_ver, emp_code FROM pay_arrear WHERE active = 1')->fetchAll();
    $seen = []; foreach ($done as $d) $seen[$d['from_ym'] . '|' . $d['from_ver'] . '|' . strtoupper($d['emp_code'])] = true;
    $own = strtoupper((string)($u['emp_code'] ?? ''));
    $out = [];
    foreach ($s->fetchAll() as $mo) {
        $old = []; foreach (pay_build($db, $mo['ym'], $u) as $l) $old[strtoupper($l['emp_code'])] = $l;
        foreach (pay_build($db, $mo['ym'], $u, true) as $n) {
            $c = strtoupper($n['emp_code']);
            if ($c === $own || !empty($n['missing_ctc']) || !isset($old[$c]) || !empty($old[$c]['missing_ctc']) || isset($seen[$mo['ym'] . '|' . $mo['ver'] . '|' . $c])) continue;
            $d = pay_arrear_diff($old[$c], $n);
            if ($d['total'] != 0.0) $out[] = ['from_ym' => $mo['ym'], 'ver' => (int)$mo['ver'], 'emp_code' => $n['emp_code'], 'name' => $n['name']] + $d;
        }
    }
    return $out;
}

/** Carry one person's arrears from a locked month into a draft month. Worked out again here, never from the form. */
function pay_arrear_add(PDO $db, array $u, string $ym, string $fromYm, string $code, string $note = ''): float {
    if (!pay_can($u)) throw new RuntimeException('Not allowed.');
    if (pay_month_row($db, $ym)['status'] !== 'draft') throw new RuntimeException('Arrears go on a draft month.');
    $code = strtoupper($code);
    if ($code === strtoupper((string)($u['emp_code'] ?? ''))) throw new RuntimeException('Not on your own pay.');
    foreach (pay_arrears($db, $u, $ym) as $a) {
        if ($a['from_ym'] !== $fromYm || strtoupper($a['emp_code']) !== $code) continue;
        $db->prepare('INSERT INTO pay_arrear (ym, from_ym, from_ver, emp_code, amount, parts, note, by_user) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$ym, $fromYm, $a['ver'], $code, $a['total'], json_encode($a['parts']), mb_substr(trim(strip_tags($note)), 0, 300), (int)$u['id']]);
        pay_log($db, $ym, 'Arrears from ' . $fromYm . ' added for ' . $code, (int)$u['id']);
        audit((int)$u['id'], 'pay_arrear_add', $ym . ' from ' . $fromYm . ' ' . $code);
        return (float)$a['total'];
    }
    throw new RuntimeException('Nothing to carry for this person from that month.');
}

function pay_arrear_off(PDO $db, array $u, int $id, string $reason): void {
    if (!pay_can($u)) throw new RuntimeException('Not allowed.');
    if (trim($reason) === '') throw new InvalidArgumentException('Give a reason.');
    $s = $db->prepare('SELECT * FROM pay_arrear WHERE id = ? AND active = 1'); $s->execute([$id]);
    if (!($r = $s->fetch())) throw new RuntimeException('Not found.');
    if (pay_month_row($db, $r['ym'])['status'] !== 'draft') throw new RuntimeException('That month is locked.');
    $db->prepare('UPDATE pay_arrear SET active = 0, off_reason = ?, off_by = ?, off_at = NOW() WHERE id = ?')->execute([mb_substr(trim($reason), 0, 300), (int)$u['id'], $id]);
    pay_log($db, $r['ym'], 'Arrears from ' . $r['from_ym'] . ' taken off for ' . $r['emp_code'] . ': ' . $reason, (int)$u['id']);
    audit((int)$u['id'], 'pay_arrear_off', '#' . $id);
}
