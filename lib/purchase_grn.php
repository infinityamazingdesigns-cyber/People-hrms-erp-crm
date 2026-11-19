<?php
// Purchase part 2 — goods received against issued order lines, quality check by someone other than the receiver
// (accepted + rejected = received, a reason for rejections, test certificate), purchase return / debit note numbers,
// accepted quantity into stock once (stock module, stk_link 'grn'), vendor scorecards feeding the comparison score,
// monthly reviews, and the buyer's KRA figures for a month. Nothing is deleted; a receipt is cancelled with a reason
// only before its check.
declare(strict_types=1);

const PUR_TC = ['yes' => 'Received', 'no' => 'Not received', 'na' => 'Not needed'];
const PUR_GRADES = ['A' => 'A — keep', 'B' => 'B — watch', 'C' => 'C — improve or replace'];

/* ---------------- pure ---------------- */

/** Working days from $from to $to (Sundays off); 0 when $to is on or before $from. */
function pur_working_days(string $from, string $to): int {
    $a = new DateTimeImmutable(substr($from, 0, 10)); $b = new DateTimeImmutable(substr($to, 0, 10)); $n = 0;
    for ($d = $a->modify('+1 day'); $d <= $b; $d = $d->modify('+1 day')) if ($d->format('N') !== '7') $n++;
    return $n;
}
function pur_grade(?float $score): string { return $score === null ? '' : ($score >= 85 ? 'A' : ($score >= 70 ? 'B' : 'C')); }
/** A vendor's score from its checked receipts: half on-time %, half quality (100 − 10 × rejection %). */
function pur_vendor_score(?float $ontime, ?float $rej): ?float {
    if ($ontime === null && $rej === null) return null;
    $q = $rej === null ? null : max(0.0, 100 - 10 * $rej);
    return round($ontime === null ? $q : ($q === null ? $ontime : 0.5 * $ontime + 0.5 * $q), 1);
}
/** The quality check's numbers. Returns errors. */
function pur_qc_check(float $recv, ?float $acc, ?float $rej, string $reason, string $tc, array $reasons): array {
    $e = [];
    if ($acc === null || $rej === null || $acc < 0 || $rej < 0) $e[] = 'Give the accepted and rejected quantities (0 or more).';
    elseif (abs($acc + $rej - $recv) > 0.0005) $e[] = 'Accepted + rejected must make the ' . rtrim(rtrim(number_format($recv, 3, '.', ''), '0'), '.') . ' received.';
    if ($rej !== null && $rej > 0 && !in_array($reason, $reasons, true)) $e[] = 'Pick why it was rejected.';
    if (!isset(PUR_TC[$tc])) $e[] = 'Say whether the test certificate came.';
    return $e;
}
/** KRA line: met when the value is on the right side of the target. Null value = nothing to measure. */
function pur_kpi_met(?float $v, float $target, bool $higher): ?bool { return $v === null ? null : ($higher ? $v >= $target : $v <= $target); }

/* ---------------- receipts ---------------- */

function pur_grn_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT g.*, l.item, l.uom, l.rate, l.item_id, l.deliv_date, o.no AS order_no, v.name AS vendor, ru.name AS recv_name, qu.name AS qc_name
        FROM pur_grn g JOIN pur_order_line l ON l.id = g.order_line_id JOIN pur_order o ON o.id = g.order_id LEFT JOIN pur_vendor v ON v.id = g.vendor_id
        LEFT JOIN users ru ON ru.id = g.recv_by LEFT JOIN users qu ON qu.id = g.qc_by WHERE g.id = ?'); $s->execute([$id]);
    if (!$g = $s->fetch()) return null;
    $g['stock_doc'] = null;
    try { $k = $db->prepare("SELECT doc_id FROM stk_link WHERE src = 'grn' AND src_id = ? AND live = 1"); $k->execute([$id]); $g['stock_doc'] = ($x = $k->fetchColumn()) ? (int)$x : null; } catch (Throwable $e) {}
    return $g;
}
/** Issued order lines with something still to come, in the login's branches. */
function pur_recv_pending(PDO $db, array $u): array {
    $brs = pur_branches($u); if (!$brs) return [];
    $s = $db->query("SELECT l.*, o.no AS order_no, o.branch_id, o.vendor_id, v.name AS vendor, l.qty - l.qty_recv AS pending FROM pur_order_line l JOIN pur_order o ON o.id = l.order_id LEFT JOIN pur_vendor v ON v.id = o.vendor_id
        WHERE o.status = 'issued' AND l.active = 1 AND l.qty - l.qty_recv > 0.0005 ORDER BY l.deliv_date IS NULL, l.deliv_date, o.id, l.line_no");
    return array_values(array_filter($s->fetchAll(), fn($l) => isset($brs[(int)$l['branch_id']])));
}
function pur_grn_add(PDO $db, array $u, array $in): int {
    if (!pur_rights($u)['recv']) throw new RuntimeException('Your role does not receive goods.');
    $rid = so_uuid((string)($in['request_id'] ?? ''));
    $s = $db->prepare('SELECT id FROM pur_grn WHERE request_id = ?'); $s->execute([$rid]); if ($had = (int)$s->fetchColumn()) return $had;
    $d = trim((string)($in['d'] ?? '')) ?: date('Y-m-d');
    if (!($x = DateTime::createFromFormat('!Y-m-d', $d)) || $x->format('Y-m-d') !== $d || $d > date('Y-m-d')) throw new RuntimeException('Received on: today or earlier.');
    $q = stk_q($in['qty'] ?? ''); if (!$q || $q <= 0) throw new RuntimeException('Give the quantity received.');
    $db->beginTransaction();
    try {
        $s = $db->prepare("SELECT l.*, o.no, o.branch_id, o.vendor_id, o.status AS ostatus, o.d AS od FROM pur_order_line l JOIN pur_order o ON o.id = l.order_id WHERE l.id = ? AND l.active = 1 FOR UPDATE"); $s->execute([(int)($in['line_id'] ?? 0)]);
        $l = $s->fetch(); if (!$l || $l['ostatus'] !== 'issued') throw new RuntimeException('That order line is not open for receiving.');
        if (!isset(pur_branches($u)[(int)$l['branch_id']])) throw new RuntimeException('That order is for a branch you do not work with.');
        if ($d < (string)$l['od']) throw new RuntimeException('Received on: not before the order date.');
        $left = round((float)$l['qty'] - (float)$l['qty_recv'], 3);
        if ($q - $left > 0.0005) throw new RuntimeException('Only ' . rtrim(rtrim(number_format($left, 3, '.', ''), '0'), '.') . ' ' . $l['uom'] . ' is still to come on this line.');
        $no = pur_next($db, 'RCV', (int)$l['branch_id'], $d);
        $db->prepare('INSERT INTO pur_grn (no, request_id, order_id, order_line_id, branch_id, vendor_id, d, challan, qty, note, recv_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$no, $rid, (int)$l['order_id'], (int)$l['id'], (int)$l['branch_id'], $l['vendor_id'], $d, mb_substr(trim(strip_tags((string)($in['challan'] ?? ''))), 0, 60), $q, mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 300), (int)$u['id']]);
        $id = (int)$db->lastInsertId();
        $db->prepare('UPDATE pur_order_line SET qty_recv = qty_recv + ? WHERE id = ?')->execute([$q, (int)$l['id']]);
        pur_log($db, (int)$u['id'], 'grn_add', $no, $l['no'] . ' line ' . $l['line_no']);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    pur_notify_roles($db, pur_settings()['qc_roles'], (int)$u['id'], 'Goods to check: ' . $no, 'A receipt waits for the quality check.');
    return $id;
}
/** The quality check — never by the person who received the goods. */
function pur_grn_qc(PDO $db, array $u, int $id, array $in): void {
    $g = pur_grn_get($db, $id); if (!$g || !isset(pur_branches($u)[(int)$g['branch_id']])) throw new RuntimeException('Receipt not found.');
    if (!pur_rights($u)['qc']) throw new RuntimeException('Your role does not do the quality check.');
    if ((int)$g['recv_by'] === (int)$u['id']) throw new RuntimeException('You received these goods — someone else checks them.');
    if ($g['status'] !== 'qc') throw new RuntimeException('Already checked.');
    $acc = stk_q($in['acc'] ?? ''); $rej = stk_q(($in['rej'] ?? '') === '' ? '0' : $in['rej']); $reason = trim((string)($in['reason'] ?? '')); $tc = (string)($in['tc'] ?? '');
    if ($e = pur_qc_check((float)$g['qty'], $acc, $rej, $reason, $tc, crm_lines(pur_settings()['reject_reasons']))) throw new RuntimeException(implode(' ', $e));
    $db->prepare("UPDATE pur_grn SET status = 'done', acc_qty = ?, rej_qty = ?, rej_reason = ?, tc = ?, qc_note = ?, qc_by = ?, qc_at = NOW() WHERE id = ? AND status = 'qc'")
       ->execute([$acc, $rej, $rej > 0 ? $reason : '', $tc, mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 300), (int)$u['id'], $id]);
    pur_log($db, (int)$u['id'], 'grn_qc', $g['no'], 'accepted ' . $acc . ', rejected ' . $rej);
    if ($rej > 0) pur_notify_roles($db, pur_settings()['buyer_roles'], (int)$u['id'], 'Goods rejected: ' . $g['no'], 'Part of a receipt was rejected — arrange the return.');
}
/** Cancel a receipt before its check (receiver or a buyer, a reason); the quantity is open again on the order line. */
function pur_grn_cancel(PDO $db, array $u, int $id, string $why): void {
    $g = pur_grn_get($db, $id); if (!$g) throw new RuntimeException('Receipt not found.');
    if ((int)$g['recv_by'] !== (int)$u['id'] && !pur_rights($u)['buyer']) throw new RuntimeException('Only the receiver or a buyer cancels it.');
    if ($g['status'] !== 'qc') throw new RuntimeException('A checked receipt is not cancelled — record a return instead.');
    if (($why = trim(strip_tags($why))) === '') throw new RuntimeException('Write why.');
    $db->beginTransaction();
    try {
        $c = $db->prepare("UPDATE pur_grn SET status = 'cancelled', cancel_why = ? WHERE id = ? AND status = 'qc'"); $c->execute([mb_substr($why, 0, 255), $id]);
        if ($c->rowCount() === 1) $db->prepare('UPDATE pur_order_line SET qty_recv = GREATEST(0, qty_recv - ?) WHERE id = ?')->execute([(float)$g['qty'], (int)$g['order_line_id']]);
        pur_log($db, (int)$u['id'], 'grn_cancel', $g['no'], $why);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
/** Purchase return no. (buyer or quality) and debit note no. (accounts only), only on a receipt with rejections. */
function pur_grn_refs(PDO $db, array $u, int $id, array $in): void {
    $g = pur_grn_get($db, $id); $R = pur_rights($u); if (!$g) throw new RuntimeException('Receipt not found.');
    if ($g['status'] !== 'done' || (float)$g['rej_qty'] <= 0) throw new RuntimeException('Only a checked receipt with rejections gets a return / debit note.');
    $ret = array_key_exists('ret_no', $in) ? mb_substr(trim(strip_tags((string)$in['ret_no'])), 0, 60) : null; $dn = array_key_exists('dn_no', $in) ? mb_substr(trim(strip_tags((string)$in['dn_no'])), 0, 60) : null;
    if ($ret !== null && $ret !== $g['ret_no'] && !$R['buyer'] && !$R['qc']) throw new RuntimeException('Only a buyer or quality records the return.');
    if ($dn !== null && $dn !== $g['dn_no'] && !$R['dn']) throw new RuntimeException('Only accounts records the debit note.');
    $db->prepare('UPDATE pur_grn SET ret_no = ?, dn_no = ? WHERE id = ?')->execute([$ret ?? $g['ret_no'], $dn ?? $g['dn_no'], $id]);
    pur_log($db, (int)$u['id'], 'grn_refs', $g['no'], trim(($ret !== null ? 'return ' : '') . ($dn !== null ? 'debit note' : '')));
}
function pur_grns(PDO $db, array $u, string $show = 'open'): array {
    $w = ['open' => "g.status = 'qc' OR (g.status = 'done' AND g.rej_qty > 0 AND (g.ret_no = '' OR g.dn_no = ''))", 'qc' => "g.status = 'qc'", 'all' => '1=1'][$show] ?? '1=1';
    $s = $db->query("SELECT g.*, l.item, l.uom, o.no AS order_no, v.name AS vendor FROM pur_grn g JOIN pur_order_line l ON l.id = g.order_line_id JOIN pur_order o ON o.id = g.order_id LEFT JOIN pur_vendor v ON v.id = g.vendor_id WHERE $w ORDER BY g.id DESC LIMIT 300");
    $brs = pur_branches($u); return array_values(array_filter($s->fetchAll(), fn($g) => isset($brs[(int)$g['branch_id']])));
}

/* ---------------- into stock ---------------- */

/** Checked receipts with accepted quantity not in stock yet (stock module on). */
function pur_stock_waiting(PDO $db, array $u): array {
    if (!module_on('stock')) return [];
    $s = $db->query("SELECT g.*, l.item, l.uom, l.item_id, l.rate, o.no AS order_no, v.name AS vendor FROM pur_grn g JOIN pur_order_line l ON l.id = g.order_line_id JOIN pur_order o ON o.id = g.order_id LEFT JOIN pur_vendor v ON v.id = g.vendor_id
        WHERE g.status = 'done' AND g.acc_qty > 0 AND NOT EXISTS (SELECT 1 FROM stk_link k WHERE k.src = 'grn' AND k.src_id = g.id AND k.live = 1) ORDER BY g.id");
    $brs = stk_branches($u); return array_values(array_filter($s->fetchAll(), fn($g) => isset($brs[(int)$g['branch_id']])));
}
/** Take a checked receipt's accepted quantity into stock once (Goods in · Purchase receipt at the order's rate). A line with no stock item gets the one picked here. */
function pur_grn_to_stock(PDO $db, array $u, int $id, int $itemId = 0): int {
    if (!module_on('stock')) throw new RuntimeException('Stock is switched off.');
    $g = pur_grn_get($db, $id); if (!$g || $g['status'] !== 'done' || (float)$g['acc_qty'] <= 0) throw new RuntimeException('Only a checked receipt with accepted goods goes into stock.');
    if ($g['stock_doc']) throw new RuntimeException('Already in stock.');
    $item = (int)$g['item_id'] ?: $itemId; if (!$item) throw new RuntimeException('Pick the stock item for "' . $g['item'] . '".');
    stk_item_row($db, $item);
    $db->beginTransaction();
    try {
        if (!(int)$g['item_id']) $db->prepare('UPDATE pur_order_line SET item_id = ? WHERE id = ? AND item_id IS NULL')->execute([$item, (int)$g['order_line_id']]);
        $doc = stk_post($db, $u, ['kind' => 'IN', 'sub' => 'IN_PUR', 'branch_id' => (int)$g['branch_id'], 'd' => $g['d'], 'ref_no' => trim($g['order_no'] . ' · ' . $g['no'] . ' ' . $g['challan']), 'party' => (string)$g['vendor'], 'note' => 'Purchase receipt', 'request_id' => so_uuid('')],
                        [['item_id' => $item, 'qty' => (float)$g['acc_qty'], 'kind' => 'IN_PUR', 'rate' => (float)$g['rate'], 'note' => $g['no']]], ['own_tx' => false, 'date_ok' => true]);
        $db->prepare("INSERT INTO stk_link (src, src_id, doc_id, by_user) VALUES ('grn', ?, ?, ?)")->execute([$id, $doc, (int)$u['id']]);
        pur_log($db, (int)$u['id'], 'grn_stock', $g['no']);
        $db->commit();
    } catch (PDOException $e) { if ($db->inTransaction()) $db->rollBack(); if ($e->getCode() === '23000') throw new RuntimeException('Already in stock.'); throw $e; }
    catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return $doc;
}

/* ---------------- vendor scorecards ---------------- */

/** Checked receipts of the last $months: rejection % by value, on-time % (where the line had a date), average days from order to receipt. */
function pur_vendor_stats(PDO $db, int $vid, int $months = 12): array {
    $s = $db->prepare("SELECT g.d, g.qty, g.rej_qty, l.rate, l.deliv_date, o.d AS od FROM pur_grn g JOIN pur_order_line l ON l.id = g.order_line_id JOIN pur_order o ON o.id = g.order_id
        WHERE g.vendor_id = ? AND g.status = 'done' AND g.d >= ?"); $s->execute([$vid, date('Y-m-d', strtotime("-$months months"))]);
    $n = 0; $val = 0.0; $rv = 0.0; $dated = 0; $late = 0; $lead = [];
    foreach ($s as $r) {
        $n++; $val += (float)$r['qty'] * (float)$r['rate']; $rv += (float)$r['rej_qty'] * (float)$r['rate'];
        if ($r['deliv_date']) { $dated++; if ($r['d'] > $r['deliv_date']) $late++; }
        if ($r['od']) $lead[] = (int)(new DateTime($r['od']))->diff(new DateTime($r['d']))->days;
    }
    $rej = $n && $val > 0 ? round(100 * $rv / $val, 2) : null; $on = $dated ? round(100 * ($dated - $late) / $dated, 1) : null; $sc = $n ? pur_vendor_score($on, $rej) : null;
    return ['n' => $n, 'rej_pct' => $rej, 'ontime_pct' => $on, 'lead_days' => $lead ? round(array_sum($lead) / count($lead), 1) : null, 'value' => round($val, 2), 'rej_value' => round($rv, 2), 'score' => $sc, 'grade' => pur_grade($sc)];
}
function pur_vreview_add(PDO $db, array $u, int $vid, string $ym, string $grade, string $note): void {
    $R = pur_rights($u); if (!$R['buyer'] && !$R['qc']) throw new RuntimeException('Only a buyer or quality reviews vendors.');
    if (!pur_vendor_get($db, $vid)) throw new RuntimeException('Vendor not found.');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) || $ym > date('Y-m')) throw new RuntimeException('Pick a month up to this one.');
    if (!isset(PUR_GRADES[$grade])) throw new RuntimeException('Pick a grade.');
    $note = mb_substr(trim(strip_tags($note)), 0, 300); if ($grade !== 'A' && $note === '') throw new RuntimeException('A grade below A needs a note.');
    $db->prepare('INSERT INTO pur_vreview (vendor_id, ym, grade, note, by_user) VALUES (?,?,?,?,?)')->execute([$vid, $ym, $grade, $note, (int)$u['id']]);
    pur_log($db, (int)$u['id'], 'vendor_review', '#' . $vid, $ym . ' ' . $grade);
}
function pur_vreviews(PDO $db, int $vid): array { $s = $db->prepare('SELECT r.*, u.name AS by_name FROM pur_vreview r LEFT JOIN users u ON u.id = r.by_user WHERE r.vendor_id = ? ORDER BY r.ym DESC, r.id DESC LIMIT 24'); $s->execute([$vid]); return $s->fetchAll(); }

/* ---------------- KRA figures for a month ---------------- */

/** The buyer's monthly figures in the login's branches: [key => [label, value, target, unit, higher-is-better, met]]. */
function pur_kpis(PDO $db, array $u, string $ym): array {
    $S = pur_settings(); $brs = array_keys(pur_branches($u)); $in = $brs ? implode(',', array_map('intval', $brs)) : '0';
    $from = $ym . '-01'; $to = date('Y-m-t', strtotime($from));
    $q = function (string $sql, array $p = []) use ($db) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchAll(); };
    $o = [];
    $po = $q("SELECT COUNT(*) n, COALESCE(SUM(sub),0) v, SUM(sub >= ?) big, SUM(sub >= ? AND (single_note <> '' OR NOT EXISTS (SELECT 1 FROM pur_order_line l WHERE l.order_id = pur_order.id AND l.active = 1 AND l.cmp_id IS NULL))) ok
        FROM pur_order WHERE status = 'issued' AND branch_id IN ($in) AND d BETWEEN ? AND ?", [$S['limit_value'], $S['limit_value'], $from, $to])[0];
    $o['orders'] = ['Orders issued', (float)$po['n'], null, 'value ' . number_format((float)$po['v'], 2), true];
    $o['compared'] = ['Orders at or above the limit with a comparison or single-source reason', (int)$po['big'] ? round(100 * (int)$po['ok'] / (int)$po['big'], 1) : null, 100.0, '%', true];
    $days = []; foreach ($q("SELECT r.d, r.hod_at, o.issued_at FROM pur_req_line l JOIN pur_req r ON r.id = l.req_id JOIN pur_order o ON o.id = l.order_id WHERE o.status = 'issued' AND o.branch_id IN ($in) AND o.d BETWEEN ? AND ?", [$from, $to]) as $r)
        $days[] = pur_working_days((string)($r['hod_at'] ?: $r['d']), (string)$r['issued_at']);
    $o['pr_po'] = ['Requisition to order, within ' . (int)$S['po_days'] . ' working days', $days ? round(100 * count(array_filter($days, fn($d) => $d <= (int)$S['po_days'])) / count($days), 1) : null, 90.0, '%', true];
    $g = $q("SELECT g.d, g.qty, g.rej_qty, l.rate, l.deliv_date FROM pur_grn g JOIN pur_order_line l ON l.id = g.order_line_id WHERE g.status = 'done' AND g.branch_id IN ($in) AND g.d BETWEEN ? AND ?", [$from, $to]);
    $dated = array_filter($g, fn($r) => $r['deliv_date']);
    $o['ontime'] = ['Deliveries on time', $dated ? round(100 * count(array_filter($dated, fn($r) => $r['d'] <= $r['deliv_date'])) / count($dated), 1) : null, 90.0, '%', true];
    $val = array_sum(array_map(fn($r) => (float)$r['qty'] * (float)$r['rate'], $g)); $rv = array_sum(array_map(fn($r) => (float)$r['rej_qty'] * (float)$r['rate'], $g));
    $o['reject'] = ['Rejected by value', $val > 0 ? round(100 * $rv / $val, 2) : null, 2.0, '%', false];
    $sav = 0.0; $nc = 0; foreach ($q("SELECT id, qty, chosen_quote FROM pur_cmp WHERE status = 'done' AND decided_at BETWEEN ? AND ?", [$from, $to . ' 23:59:59']) as $c) { $qs = $q('SELECT * FROM pur_quote WHERE cmp_id = ? AND active = 1', [(int)$c['id']]); $sav += pur_saving($qs, (int)$c['chosen_quote'], (float)$c['qty'])['vs_high']; $nc++; }
    $o['saving'] = ['Saving against the highest quote', $nc ? round($sav, 2) : null, 0.0, '', true];
    $late = $q("SELECT COUNT(*) n FROM pur_order_line l JOIN pur_order o ON o.id = l.order_id WHERE o.status = 'issued' AND o.branch_id IN ($in) AND l.active = 1 AND l.qty - l.qty_recv > 0.0005 AND l.deliv_date < ?", [min($to, date('Y-m-d'))])[0];
    $o['late'] = ['Order lines past their delivery date, still to come', (float)$late['n'], 0.0, 'lines', false];
    $open = $q("SELECT COUNT(*) n FROM pur_req_line l JOIN pur_req r ON r.id = l.req_id WHERE l.status = 'open' AND r.branch_id IN ($in) AND COALESCE(r.hod_at, r.created_at) <= ?", [date('Y-m-d H:i:s', strtotime('-' . ((int)$S['po_days'] + 1) . ' days'))])[0];
    $o['waiting'] = ['Requisition lines waiting longer than the target', (float)$open['n'], 0.0, 'lines', false];
    foreach ($o as $k => $r) $o[$k][5] = $r[2] === null ? null : pur_kpi_met($r[1] === null ? null : (float)$r[1], (float)$r[2], $r[4]);
    return $o;
}
