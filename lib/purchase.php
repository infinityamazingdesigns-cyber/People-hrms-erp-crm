<?php
// Purchase & procurement — rules (part 1). Vendors are registered and approved by someone other than the person who
// registered them (bank details sealed, shown masked). Requisitions carry several lines; at or above a value — or with a
// line nobody can price — they wait for the requester's reporting manager. A buyer compares quotes (QCD score: price,
// quality, delivery, payment) and makes the purchase order: Draft → approver → CEO at or above the limit → Approved →
// Issued (the number is given only at issue; header and vendor frozen). Nobody approves their own; the CEO is never the
// approver of the first step. Sales tax split by the country rule pack. Nothing is deleted; pur_log is append-only.
declare(strict_types=1);
require_once __DIR__ . '/quotes.php';        // qt_parts(), qt_region(), qt_tax_pack(), qt_head(), qt_words()
require_once __DIR__ . '/stock.php';         // stock items, balances (last rate / stock on the requisition)

const PUR_VSTATUS = ['new' => 'Not sent for approval', 'pending' => 'Waiting for approval', 'approved' => 'Approved', 'blocked' => 'Blocked'];
const PUR_ORD_KINDS = ['po' => 'Purchase order', 'jw' => 'Job work order'];
const PUR_ORD_STATUS = ['draft' => 'Draft', 'mgr' => 'With the approver', 'ceo' => 'With the CEO', 'back' => 'Sent back', 'approved' => 'Approved, to issue', 'issued' => 'Issued', 'cancelled' => 'Cancelled'];
const PUR_REQ_STATUS = ['hod' => 'With the manager', 'open' => 'Open', 'rejected' => 'Not approved', 'done' => 'Ordered', 'cancelled' => 'Cancelled'];
const PUR_DEFAULTS = [
    'buyer_roles' => ['SUPERADMIN', 'BUYER'], 'approver_roles' => ['SUPERADMIN'], 'see_roles' => ['CEO'],
    'recv_roles' => ['SUPERADMIN', 'STORE'], 'qc_roles' => ['SUPERADMIN', 'QUALITY'], 'dn_roles' => ['SUPERADMIN', 'ACCOUNTS'],
    'po_days' => 3, 'reject_reasons' => "Dimension\nMaterial / grade\nDamaged in transit\nSurface finish\nWrong item\nShort / excess\nNo test certificate\nOther",
    'limit_value' => 25000, 'hod_value' => 10000, 'prefix_po' => 'PO', 'prefix_jw' => 'JW', 'prefix_pr' => 'PR', 'prefix_cmp' => 'CMP',
    'w_price' => 50, 'w_quality' => 20, 'w_delivery' => 20, 'w_payment' => 10, 'new_quality' => 70,
    'terms' => "Payment: 30 days from the invoice\nDelivery: within 15 days of this order\nFreight: delivered to our site, paid by the supplier\nMention this order number on the invoice and the delivery challan",
];

/* ---------------- pure ---------------- */

/** A vendor name made comparable (company words and punctuation dropped). */
function pur_vkey(string $name): string { return crm_norm_name($name); }
/** Does a requisition need the manager? At or above the value, or a line nobody can price. */
function pur_req_needs_hod(float $known, int $unpriced, float $limit): bool { return $unpriced > 0 || $known >= $limit; }
/**
 * QCD score per quote [id, rate, freight, delivery_days, pay_days, vendor_id]; $stats[vendor_id] = [rej_pct, ontime_pct] (null = no history).
 * Price = cheapest landed ÷ this; quality = 100 − 10 × rejection %; delivery = half on-time %, half fastest quoted days ÷ these;
 * payment = these credit days ÷ the longest. No history → `new_quality` for the history parts.
 */
function pur_qcd(array $quotes, array $stats, array $S): array {
    // $stats from pur_vendor_stats(): rej_pct / ontime_pct are null when the vendor has no checked receipts yet
    if (!$quotes) return [];
    $land = fn($q) => (float)$q['rate'] + (float)($q['freight'] ?? 0);
    $minL = min(array_map($land, $quotes));
    $days = array_values(array_filter(array_map(fn($q) => $q['delivery_days'], $quotes), fn($x) => $x !== null && $x !== ''));
    $minD = $days ? max(1, min($days)) : null;
    $pays = array_values(array_filter(array_map(fn($q) => $q['pay_days'], $quotes), fn($x) => $x !== null && $x !== ''));
    $maxP = $pays ? max($pays) : 0;
    $W = ['price' => (float)$S['w_price'], 'quality' => (float)$S['w_quality'], 'delivery' => (float)$S['w_delivery'], 'payment' => (float)$S['w_payment']];
    $wt = array_sum($W) ?: 1; $out = [];
    foreach ($quotes as $q) {
        $h = $stats[(int)$q['vendor_id']] ?? null;
        $price = $land($q) > 0 ? 100 * $minL / $land($q) : 0;
        $quality = $h && $h['rej_pct'] !== null ? max(0, 100 - 10 * $h['rej_pct']) : (float)$S['new_quality'];
        $hist = $h && $h['ontime_pct'] !== null ? (float)$h['ontime_pct'] : (float)$S['new_quality'];
        $speed = $minD && $q['delivery_days'] !== null && $q['delivery_days'] !== '' ? 100 * $minD / max(1, (int)$q['delivery_days']) : $hist;
        $delivery = 0.5 * $hist + 0.5 * $speed;
        $payment = $maxP > 0 ? 100 * (int)($q['pay_days'] ?? 0) / $maxP : 100;
        $total = ($W['price'] * $price + $W['quality'] * $quality + $W['delivery'] * $delivery + $W['payment'] * $payment) / $wt;
        $out[(int)$q['id']] = ['price' => round($price), 'quality' => round($quality), 'delivery' => round($delivery), 'payment' => round($payment), 'total' => round($total, 1), 'history' => (bool)$h];
    }
    $bt = max(array_column($out, 'total'));
    foreach ($out as $k => $r) $out[$k]['best'] = $r['total'] === $bt;
    return $out;
}
/** Saving of the chosen quote against the highest and the first quote (landed, × quantity). */
function pur_saving(array $quotes, int $chosen, float $qty): array {
    $land = fn($q) => (float)$q['rate'] + (float)($q['freight'] ?? 0);
    $by = array_column($quotes, null, 'id'); if (!isset($by[$chosen]) || !$quotes) return ['vs_high' => 0.0, 'vs_first' => 0.0];
    $c = $land($by[$chosen]); $first = $land(reset($quotes)); $high = max(array_map($land, $quotes));
    return ['vs_high' => round(($high - $c) * $qty, 2), 'vs_first' => round(($first - $c) * $qty, 2)];
}
/** Order totals by the country's split; an unregistered vendor (or none known) → no tax. Each line's tax rounded once; total to the whole unit. */
function pur_totals(array $lines, array $parts, bool $taxFree): array {
    $c = qt_calc(array_map(fn($l) => ['qty' => $l['qty'], 'rate' => $l['rate'], 'discount_pct' => 0, 'tax_pct' => $taxFree ? 0 : $l['tax_pct']] + $l, $lines), $taxFree ? [] : $parts);
    $t = $c['totals'];
    return ['lines' => $c['lines'], 'sub' => $t['taxable'], 'tax' => $t['tax_total'], 'parts' => $t['parts'], 'round_off' => $t['round_off'], 'total' => $t['total']];
}
/** What stops an order going for approval / being issued. */
function pur_ord_problems(array $o, ?array $vendor, array $lines, array $head, array $S, bool $forIssue = false): array {
    $p = [];
    if (!$vendor) $p[] = 'Pick the vendor';
    elseif ($vendor['status'] === 'blocked') $p[] = 'The vendor is blocked';
    if (!$lines) $p[] = 'Add at least one line';
    foreach ($lines as $l) if ((float)$l['rate'] <= 0) { $p[] = 'Every line needs a rate (' . $l['item'] . ')'; break; }
    foreach (qt_head_missing($head) as $m) $p[] = 'The order header needs: ' . $m . ' (Quotations → Header & terms)';
    if ((float)$o['sub'] >= (float)$S['limit_value'] && trim((string)$o['single_note']) === '') foreach ($lines as $l) if (empty($l['cmp_id'])) { $p[] = 'At or above ' . number_format((float)$S['limit_value']) . ': link every line to a finished comparison, or write why there is a single source'; break; }
    if ($forIssue && $o['status'] !== 'approved') $p[] = 'Not approved yet';
    return $p;
}
/** Who decides the order now: [step, may this login]. Nobody decides their own; the CEO is never the first approver. */
function pur_ord_step(array $o, int $uid, bool $approver, bool $ceo): array {
    if (in_array($uid, [(int)$o['created_by'], (int)($o['sent_by'] ?? 0)], true)) return [$o['status'], false];
    if ($o['status'] === 'mgr') return ['mgr', $approver];
    if ($o['status'] === 'ceo') return ['ceo', $ceo && (int)($o['mgr_by'] ?? 0) !== $uid];
    return [$o['status'], false];
}

/* ---------------- settings and rights ---------------- */

function pur_settings(): array { $s = module_states()['purchase']['settings'] ?? []; return array_replace(PUR_DEFAULTS, array_intersect_key($s, PUR_DEFAULTS)); }
function pur_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['buyer_roles', 'approver_roles', 'see_roles', 'recv_roles', 'qc_roles', 'dn_roles'] as $k) $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
    if (!$o['buyer_roles']) $e[] = 'Pick at least one buyer role.';
    if (!$o['approver_roles']) $e[] = 'Pick at least one approver role.';
    foreach (['limit_value', 'hod_value'] as $k) $o[$k] = max(0, round((float)str_replace(',', '', (string)($in[$k] ?? 0)), 2));
    foreach (['prefix_po' => 'PO', 'prefix_jw' => 'JW', 'prefix_pr' => 'PR', 'prefix_cmp' => 'CMP'] as $k => $d) $o[$k] = substr(strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($in[$k] ?? $d))), 0, 6) ?: $d;
    foreach (['w_price', 'w_quality', 'w_delivery', 'w_payment'] as $k) $o[$k] = max(0, min(100, (int)($in[$k] ?? 0)));
    if (!array_sum(array_intersect_key($o, array_flip(['w_price', 'w_quality', 'w_delivery', 'w_payment'])))) { $e[] = 'The score weights cannot all be zero.'; $o['w_price'] = 50; }
    $o['new_quality'] = max(0, min(100, (int)($in['new_quality'] ?? 70)));
    $o['terms'] = implode("\n", array_slice(crm_lines((string)($in['terms'] ?? '')), 0, 40));
    $o['po_days'] = max(1, min(60, (int)($in['po_days'] ?? 3)));
    $o['reject_reasons'] = implode("\n", array_slice(crm_lines((string)($in['reject_reasons'] ?? '')), 0, 30)) ?: 'Other';
    return [$o, $e];
}
function pur_rights(array $u): array {
    $r = user_roles((int)$u['id'], !empty($u['is_owner'])); $s = pur_settings(); $h = fn($k) => (bool)array_intersect($r, $s[$k]);
    $admin = in_array('SUPERADMIN', $r, true);
    $recv = $h('recv_roles'); $qc = $h('qc_roles'); $dn = $h('dn_roles');
    return ['buyer' => $h('buyer_roles'), 'approver' => $h('approver_roles'), 'ceo' => in_array('CEO', $r, true) || $admin, 'recv' => $recv, 'qc' => $qc, 'dn' => $dn,
            'see' => $admin || $h('buyer_roles') || $h('approver_roles') || $h('see_roles') || $recv || $qc || $dn || in_array('CEO', $r, true), 'admin' => $admin];
}
function pur_branches(array $u): array { return stk_branches($u); }
function pur_log(PDO $db, ?int $uid, string $what, string $ref = '', string $note = ''): void { $db->prepare('INSERT INTO pur_log (by_user, what, ref, note) VALUES (?,?,?,?)')->execute([$uid, $what, mb_substr($ref, 0, 60), mb_substr($note, 0, 300)]); }
function pur_next(PDO $db, string $prefix, ?int $branch, string $d): string {
    $fy = so_fy($d, (int)(org('fy_start_month') ?: 4)); $bc = $branch ? stk_branch_code($branch) : ''; $key = "$prefix|$bc|$fy";
    $db->prepare('INSERT INTO pur_seq (prefix, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$key]);
    $s = $db->prepare('SELECT n FROM pur_seq WHERE prefix = ?'); $s->execute([$key]);
    return so_order_no($prefix, $bc, $fy, (int)$s->fetchColumn());
}
function pur_notify_roles(PDO $db, array $roles, int $skip, string $subject, string $text): void {
    if (!$roles || !module_on('email') || !function_exists('mail_queue')) return;
    try { $s = $db->prepare('SELECT DISTINCT u.email FROM users u JOIN user_role r ON r.user_id = u.id WHERE u.active = 1 AND u.id <> ? AND r.role_code IN (' . implode(',', array_fill(0, count($roles), '?')) . ')'); $s->execute([$skip, ...$roles]);
          foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $to) if (filter_var($to, FILTER_VALIDATE_EMAIL)) mail_queue($db, ['to' => $to, 'subject' => $subject, 'text' => $text . "\n\nOpen Purchase in " . org('name') . '.'], 'notice', 'purchase'); } catch (Throwable $e) {}
}

/* ---------------- vendors ---------------- */

function pur_vendor_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM pur_vendor WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }
/** Check a vendor's details. Returns [clean, errors]. The tax ID by the country rule; bank account typed twice. */
function pur_vendor_check(array $in): array {
    $t = fn($k, $n) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $n); $e = [];
    $v = ['name' => $t('name', 200), 'tax_none' => !empty($in['tax_none']) ? 1 : 0, 'address' => $t('address', 400), 'city' => $t('city', 80), 'contact' => $t('contact', 120),
          'phone' => crm_phone_norm($t('phone', 30)), 'email' => crm_email_norm($t('email', 160)), 'small_business' => $t('small_business', 10), 'pay_days' => trim((string)($in['pay_days'] ?? '')) === '' ? null : (int)$in['pay_days'], 'region' => $t('region', 20)];
    if ($v['name'] === '') $e[] = 'Give the vendor\'s name.';
    if ($t('phone', 30) !== '' && $v['phone'] === '') $e[] = 'Check the phone number.';
    if ($t('email', 160) !== '' && $v['email'] === '') $e[] = 'Check the email.';
    if ($v['pay_days'] !== null && ($v['pay_days'] < 0 || $v['pay_days'] > 365)) $e[] = 'Credit days are 0 to 365.';
    $tx = crm_tax_check($t('tax_id', 30), crm_tax_rule());
    if (!$tx['ok']) $e[] = crm_tax_label() . ': ' . $tx['why'];
    $v['tax_id'] = ($tx['value'] ?? '') !== '' && !$v['tax_none'] ? $tx['value'] : null;
    if ($v['tax_none']) $v['tax_id'] = null;
    if (!$v['tax_none'] && $v['tax_id'] === null && crm_tax_rule()) $e[] = 'Give the ' . crm_tax_label() . ', or tick "not registered for tax".';
    if ($v['region'] === '' && !empty($tx['region'])) $v['region'] = (string)$tx['region'];
    $acc = preg_replace('/\s+/', '', (string)($in['bank_acc'] ?? '')); $acc2 = preg_replace('/\s+/', '', (string)($in['bank_acc2'] ?? ''));
    $v['bank'] = null;
    if ($acc !== '') {
        if (!preg_match('/^[A-Za-z0-9]{6,34}$/', $acc)) $e[] = 'The bank account is 6 to 34 letters or digits.';
        elseif ($acc !== $acc2) $e[] = 'The two bank account numbers differ.';
        else $v['bank'] = ['acc' => strtoupper($acc), 'code' => strtoupper($t('bank_code', 20)), 'bank' => $t('bank_name', 80), 'holder' => $t('bank_holder', 120)];
    }
    $v['vkey'] = pur_vkey($v['name']);
    return [$v, $e];
}
function pur_vendor_save(PDO $db, array $u, int $id, array $in): int {
    $R = pur_rights($u); if (!$R['buyer']) throw new RuntimeException('Only a buyer registers vendors.');
    [$v, $e] = pur_vendor_check($in); if ($e) throw new RuntimeException(implode(' ', $e));
    $old = $id ? pur_vendor_get($db, $id) : null; if ($id && !$old) throw new RuntimeException('Vendor not found.');
    if ($v['tax_id']) { $d = $db->prepare('SELECT name FROM pur_vendor WHERE tax_id = ? AND id <> ?'); $d->execute([$v['tax_id'], $id]); if ($n = $d->fetchColumn()) throw new RuntimeException(crm_tax_label() . ' already belongs to ' . $n . '.'); }
    if (!$old && empty($in['force'])) { $d = $db->prepare('SELECT name FROM pur_vendor WHERE vkey = ? LIMIT 1'); $d->execute([$v['vkey']]); if ($n = $d->fetchColumn()) throw new RuntimeException('A vendor with this name is there already (' . $n . '). Tick "a different vendor" to add it anyway.'); }
    $bank = $v['bank']; unset($v['bank']);
    $f = $v + ($bank ? ['bank_box' => seal(json_encode($bank)), 'bank_hint' => ($bank['bank'] !== '' ? $bank['bank'] . ' ' : '') . mask($bank['acc'])] : []);
    $back = $old && $old['status'] === 'approved' && (($old['tax_id'] ?? null) !== $v['tax_id'] || $bank);   // a new tax ID or bank account goes back for approval
    if ($back) $f += ['status' => 'pending', 'status_note' => 'Tax ID or bank changed — approve again'];
    if ($old) $db->prepare('UPDATE pur_vendor SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')->execute([...array_values($f), $id]);
    else {
        $db->prepare("INSERT INTO pur_seq (prefix, n) VALUES ('VENDOR', 1) ON DUPLICATE KEY UPDATE n = n + 1")->execute();
        $f += ['code' => sprintf('V%05d', (int)$db->query("SELECT n FROM pur_seq WHERE prefix = 'VENDOR'")->fetchColumn()), 'created_by' => (int)$u['id']];
        $db->prepare('INSERT INTO pur_vendor (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
        $id = (int)$db->lastInsertId();
    }
    pur_log($db, (int)$u['id'], $old ? 'vendor_edit' : 'vendor_add', '#' . $id, $bank ? 'bank details changed' : '');
    audit((int)$u['id'], $old ? 'pur_vendor_edit' : 'pur_vendor_add', '#' . $id . ($bank ? ' bank' : ''));
    return $id;
}
/** What a vendor still lacks before approval. */
function pur_vendor_missing(array $v): array {
    $m = [];
    if (!$v['tax_id'] && !(int)$v['tax_none']) $m[] = crm_tax_label();
    if (!$v['bank_box']) $m[] = 'bank details';
    if ($v['phone'] === '' && $v['email'] === '') $m[] = 'a phone or email';
    if ($v['address'] === '') $m[] = 'address';
    return $m;
}
function pur_vendor_submit(PDO $db, array $u, int $id): void {
    $v = pur_vendor_get($db, $id); if (!$v || !pur_rights($u)['buyer']) throw new RuntimeException('Vendor not found.');
    if (!in_array($v['status'], ['new', 'blocked'], true)) throw new RuntimeException('Already sent for approval.');
    if ($m = pur_vendor_missing($v)) throw new RuntimeException('Still missing: ' . implode(', ', $m) . '.');
    $db->prepare("UPDATE pur_vendor SET status = 'pending', submitted_by = ? WHERE id = ?")->execute([(int)$u['id'], $id]);
    pur_log($db, (int)$u['id'], 'vendor_submit', $v['code']);
    pur_notify_roles($db, pur_settings()['approver_roles'], (int)$u['id'], 'Vendor to approve: ' . $v['name'], 'A new vendor waits for your approval.');
}
/** Approve / block (a reason) — never by the person who registered it or sent it. */
function pur_vendor_decide(PDO $db, array $u, int $id, string $act, string $note): void {
    $v = pur_vendor_get($db, $id); $R = pur_rights($u);
    if (!$v || !$R['approver']) throw new RuntimeException('Only an approver decides vendors.');
    if (in_array((int)$u['id'], [(int)$v['created_by'], (int)$v['submitted_by']], true)) throw new RuntimeException('You registered this vendor — someone else approves it.');
    if (!in_array($act, ['approve', 'block'], true)) throw new RuntimeException('Approve or block.');
    if ($act === 'approve' && $v['status'] !== 'pending') throw new RuntimeException('It is not waiting for approval.');
    if ($act === 'approve' && ($m = pur_vendor_missing($v))) throw new RuntimeException('Still missing: ' . implode(', ', $m) . '.');
    if ($act === 'block' && ($note = trim(strip_tags($note))) === '') throw new RuntimeException('Write why it is blocked.');
    $db->prepare('UPDATE pur_vendor SET status = ?, status_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?')->execute([$act === 'approve' ? 'approved' : 'blocked', mb_substr($note, 0, 300), (int)$u['id'], $id]);
    pur_log($db, (int)$u['id'], 'vendor_' . $act, $v['code'], $note);
    audit((int)$u['id'], 'pur_vendor_' . $act, '#' . $id);
}
/** The bank details in full — approvers and buyers only, each look audited. */
function pur_vendor_bank(PDO $db, array $u, int $id): ?array {
    $R = pur_rights($u); if (!$R['approver'] && !$R['buyer']) throw new RuntimeException('Your role cannot see bank details.');
    $v = pur_vendor_get($db, $id); if (!$v || !$v['bank_box']) return null;
    audit((int)$u['id'], 'pur_vendor_bank_view', '#' . $id);
    return json_decode(unseal($v['bank_box']), true) ?: null;
}
function pur_vendors(PDO $db, string $status = ''): array {
    $s = $db->prepare('SELECT * FROM pur_vendor' . ($status !== '' ? ' WHERE status = ?' : '') . ' ORDER BY name'); $s->execute($status !== '' ? [$status] : []); return $s->fetchAll();
}

/* ---------------- requisitions ---------------- */

/** Last purchase rate and stock of an item at a branch (stock item when named, else by name). */
function pur_item_look(PDO $db, string $item, int $branch, ?int $itemId): array {
    $o = ['item_id' => $itemId, 'last_rate' => null, 'stock_qty' => null, 'uom' => ''];
    if (!$itemId) { $s = $db->prepare('SELECT id, uom FROM stk_item WHERE active = 1 AND (LOWER(name) = LOWER(?) OR code = ?) LIMIT 1'); $s->execute([$item, strtoupper($item)]); if ($r = $s->fetch()) { $o['item_id'] = (int)$r['id']; $o['uom'] = $r['uom']; } }
    if ($o['item_id']) { $s = $db->prepare('SELECT qty FROM stk_bal WHERE branch_id = ? AND item_id = ?'); $s->execute([$branch, $o['item_id']]); $q = $s->fetchColumn(); $o['stock_qty'] = $q === false ? 0.0 : (float)$q; }
    $s = $db->prepare("SELECT l.rate FROM pur_order_line l JOIN pur_order o ON o.id = l.order_id WHERE o.status = 'issued' AND l.active = 1 AND (" . ($o['item_id'] ? 'l.item_id = ? OR ' : '') . 'LOWER(l.item) = LOWER(?)) ORDER BY o.issued_at DESC LIMIT 1');
    $s->execute($o['item_id'] ? [$o['item_id'], $item] : [$item]); $r = $s->fetchColumn(); if ($r !== false) $o['last_rate'] = (float)$r;
    return $o;
}
/** The requester's reporting manager's login (org chart), or null. */
function pur_manager_of(PDO $db, array $u): ?int {
    if (($u['emp_code'] ?? '') === '') return null;
    $s = $db->prepare('SELECT m.id FROM employee e JOIN users m ON m.emp_code = e.manager_code AND m.active = 1 WHERE e.code = ? AND e.manager_code <> \'\' LIMIT 1'); $s->execute([$u['emp_code']]);
    $id = (int)$s->fetchColumn(); return $id && $id !== (int)$u['id'] ? $id : null;
}
function pur_req_add(PDO $db, array $u, array $in): int {
    $S = pur_settings(); $branch = (int)($in['branch_id'] ?? 0);
    if (!isset(pur_branches($u)[$branch])) throw new RuntimeException('Pick one of your branches.');
    $need = trim((string)($in['need_by'] ?? '')); if ($need !== '' && (!($x = DateTime::createFromFormat('!Y-m-d', $need)) || $x->format('Y-m-d') !== $need || $need < date('Y-m-d'))) throw new RuntimeException('Needed by: today or later.');
    $lines = [];
    foreach ((array)($in['lines'] ?? []) as $l) {
        $item = mb_substr(trim(strip_tags((string)($l['item'] ?? ''))), 0, 255); $iid = (int)($l['item_id'] ?? 0) ?: null;
        if ($iid) { $it = stk_item_row($db, $iid); $item = $it['name']; }
        if ($item === '' && trim((string)($l['qty'] ?? '')) === '') continue;
        if ($item === '') throw new RuntimeException('Every line needs its item.');
        $q = stk_q($l['qty'] ?? ''); if ($q === null || $q <= 0) throw new RuntimeException("$item: quantity above 0.");
        $est = trim((string)($l['est'] ?? '')) === '' ? null : stk_q($l['est']); if ($est !== null && $est <= 0) throw new RuntimeException("$item: an approximate rate above 0, or leave it blank.");
        $lk = pur_item_look($db, $item, $branch, $iid);
        $lines[] = ['item' => $item, 'item_id' => $lk['item_id'], 'qty' => $q, 'uom' => mb_substr(trim((string)($l['uom'] ?? '')) ?: ($lk['uom'] ?: 'NOS'), 0, 20), 'est' => $est, 'last' => $lk['last_rate'], 'stock' => $lk['stock_qty']];
    }
    if (!$lines) throw new RuntimeException('Add at least one item.');
    if (count($lines) > 40) throw new RuntimeException('Up to 40 items in one requisition.');
    $known = 0.0; $unp = 0; foreach ($lines as $l) { $r = $l['est'] ?? $l['last']; if ($r === null) $unp++; else $known += $r * $l['qty']; }
    $hod = pur_req_needs_hod($known, $unp, (float)$S['hod_value']); $mgr = $hod ? pur_manager_of($db, $u) : null;
    $db->beginTransaction();
    try {
        $no = pur_next($db, $S['prefix_pr'], $branch, date('Y-m-d'));
        $db->prepare('INSERT INTO pur_req (no, branch_id, d, need_by, dept, job_ref, purpose, est_value, unpriced, status, hod_user, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$no, $branch, date('Y-m-d'), $need ?: null, mb_substr(trim((string)($in['dept'] ?? '')), 0, 80), mb_substr(trim((string)($in['job_ref'] ?? '')), 0, 120), mb_substr(trim(strip_tags((string)($in['purpose'] ?? ''))), 0, 255), round($known, 2), $unp, $hod ? 'hod' : 'open', $mgr, (int)$u['id']]);
        $id = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO pur_req_line (req_id, line_no, item_id, item, uom, qty, est_rate, last_rate, stock_qty, status) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($lines as $k => $l) $ins->execute([$id, $k + 1, $l['item_id'], $l['item'], $l['uom'], $l['qty'], $l['est'], $l['last'], $l['stock'], $hod ? 'hod' : 'open']);
        pur_log($db, (int)$u['id'], 'req_add', $no, count($lines) . ' item(s)' . ($hod ? ' · to the manager' : ''));
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    if (!$hod) pur_notify_roles($db, $S['buyer_roles'], (int)$u['id'], 'New requisition ' . $no, count($lines) . ' item(s) to buy.');
    elseif (!$mgr) pur_notify_roles($db, $S['approver_roles'], (int)$u['id'], 'Requisition to approve ' . $no, 'The requester has no manager on the org chart.');
    return $id;
}
function pur_req_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT r.*, u.name AS by_name, h.name AS hod_name FROM pur_req r LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users h ON h.id = r.hod_user WHERE r.id = ?'); $s->execute([$id]);
    if (!$r = $s->fetch()) return null;
    $s = $db->prepare('SELECT * FROM pur_req_line WHERE req_id = ? ORDER BY line_no'); $s->execute([$id]); $r['lines'] = $s->fetchAll();
    return $r;
}
function pur_req_can_see(array $u, array $q): bool { $R = pur_rights($u); return (int)$q['created_by'] === (int)$u['id'] || (int)($q['hod_user'] ?? 0) === (int)$u['id'] || ($R['see'] && isset(pur_branches($u)[(int)$q['branch_id']])); }
function pur_req_can_decide(array $u, array $q): bool {
    if ($q['status'] !== 'hod' || (int)$q['created_by'] === (int)$u['id']) return false;
    return $q['hod_user'] ? (int)$q['hod_user'] === (int)$u['id'] : pur_rights($u)['approver'];
}
function pur_req_decide(PDO $db, array $u, int $id, string $act, string $note): void {
    $q = pur_req_get($db, $id); if (!$q) throw new RuntimeException('Requisition not found.');
    if ((int)$q['created_by'] === (int)$u['id']) throw new RuntimeException('You raised this requisition — your manager decides it.');
    if (!pur_req_can_decide($u, $q)) throw new RuntimeException('This requisition is not waiting for you.');
    if (!in_array($act, ['approve', 'reject'], true)) throw new RuntimeException('Approve or reject.');
    $note = mb_substr(trim(strip_tags($note)), 0, 255); if ($act === 'reject' && $note === '') throw new RuntimeException('Write why it is not approved.');
    $db->prepare("UPDATE pur_req SET status = ?, hod_by = ?, hod_at = NOW(), hod_note = ? WHERE id = ? AND status = 'hod'")->execute([$act === 'approve' ? 'open' : 'rejected', (int)$u['id'], $note, $id]);
    $db->prepare("UPDATE pur_req_line SET status = ? WHERE req_id = ? AND status = 'hod'")->execute([$act === 'approve' ? 'open' : 'cancelled', $id]);
    pur_log($db, (int)$u['id'], 'req_' . $act, $q['no'], $note);
    if ($act === 'approve') pur_notify_roles($db, pur_settings()['buyer_roles'], (int)$u['id'], 'New requisition ' . $q['no'], 'Approved by the manager.');
}
function pur_req_cancel(PDO $db, array $u, int $id, string $why): void {
    $q = pur_req_get($db, $id); if (!$q) throw new RuntimeException('Requisition not found.');
    if ((int)$q['created_by'] !== (int)$u['id'] && !pur_rights($u)['buyer']) throw new RuntimeException('Only the requester or a buyer cancels it.');
    if (!in_array($q['status'], ['hod', 'open'], true)) throw new RuntimeException('It cannot be cancelled now.');
    if (($why = trim(strip_tags($why))) === '') throw new RuntimeException('Write why.');
    $db->prepare("UPDATE pur_req SET status = 'cancelled', hod_note = ? WHERE id = ?")->execute([mb_substr($why, 0, 255), $id]);
    $db->prepare("UPDATE pur_req_line SET status = 'cancelled' WHERE req_id = ? AND status IN ('hod','open')")->execute([$id]);
    pur_log($db, (int)$u['id'], 'req_cancel', $q['no'], $why);
}
function pur_reqs(PDO $db, array $u, string $show = 'open'): array {
    $w = $show === 'open' ? "r.status IN ('hod','open')" : '1=1';
    $s = $db->query("SELECT r.*, u.name AS by_name, (SELECT COUNT(*) FROM pur_req_line l WHERE l.req_id = r.id) n, (SELECT COUNT(*) FROM pur_req_line l WHERE l.req_id = r.id AND l.status = 'open') n_open FROM pur_req r LEFT JOIN users u ON u.id = r.created_by WHERE $w ORDER BY r.id DESC LIMIT 300");
    return array_values(array_filter($s->fetchAll(), fn($q) => pur_req_can_see($u, $q)));
}

/* ---------------- comparisons ---------------- */

function pur_cmp_create(PDO $db, array $u, array $in): int {
    if (!pur_rights($u)['buyer']) throw new RuntimeException('Only a buyer makes comparisons.');
    $line = (int)($in['req_line_id'] ?? 0) ?: null; $item = mb_substr(trim(strip_tags((string)($in['item'] ?? ''))), 0, 255); $qty = stk_q($in['qty'] ?? ''); $uom = mb_substr(trim((string)($in['uom'] ?? '')), 0, 20);
    if ($line) { $s = $db->prepare("SELECT * FROM pur_req_line WHERE id = ? AND status = 'open'"); $s->execute([$line]); $l = $s->fetch() ?: throw new RuntimeException('That requisition line is not open.'); $item = $l['item']; $qty = (float)$l['qty']; $uom = $l['uom']; }
    if ($item === '' || !$qty || $qty <= 0) throw new RuntimeException('Give the item and the quantity.');
    $no = pur_next($db, pur_settings()['prefix_cmp'], null, date('Y-m-d'));
    $db->prepare('INSERT INTO pur_cmp (no, req_line_id, item, qty, uom, created_by) VALUES (?,?,?,?,?,?)')->execute([$no, $line, $item, $qty, $uom, (int)$u['id']]);
    $id = (int)$db->lastInsertId(); pur_log($db, (int)$u['id'], 'cmp_add', $no, $item);
    return $id;
}
function pur_quote_add(PDO $db, array $u, int $cmp, array $in): void {
    if (!pur_rights($u)['buyer']) throw new RuntimeException('Only a buyer adds quotes.');
    $c = pur_cmp_get($db, $cmp); if (!$c || $c['status'] !== 'open') throw new RuntimeException('This comparison is closed.');
    $v = pur_vendor_get($db, (int)($in['vendor_id'] ?? 0)); if (!$v) throw new RuntimeException('Pick the vendor.');
    if ($v['status'] === 'blocked') throw new RuntimeException('That vendor is blocked.');
    if (array_filter($c['quotes'], fn($q) => (int)$q['vendor_id'] === (int)$v['id'])) throw new RuntimeException('That vendor already quoted here — change by taking the quote off first.');
    $r = stk_q($in['rate'] ?? ''); if (!$r || $r <= 0) throw new RuntimeException('Give the rate.');
    $fr = trim((string)($in['freight'] ?? '')) === '' ? 0.0 : (stk_q($in['freight']) ?? -1); if ($fr < 0) throw new RuntimeException('Check the freight.');
    $dd = trim((string)($in['delivery_days'] ?? '')) === '' ? null : max(0, (int)$in['delivery_days']); $pd = trim((string)($in['pay_days'] ?? '')) === '' ? $v['pay_days'] : max(0, (int)$in['pay_days']);
    $db->prepare('INSERT INTO pur_quote (cmp_id, vendor_id, rate, freight, delivery_days, pay_days, note, created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$cmp, (int)$v['id'], $r, $fr, $dd, $pd, mb_substr(trim(strip_tags((string)($in['note'] ?? ''))), 0, 300), (int)$u['id']]);
}
function pur_quote_off(PDO $db, array $u, int $quote): void {
    if (!pur_rights($u)['buyer']) throw new RuntimeException('Only a buyer changes quotes.');
    $db->prepare("UPDATE pur_quote q JOIN pur_cmp c ON c.id = q.cmp_id SET q.active = 0 WHERE q.id = ? AND c.status = 'open'")->execute([$quote]);
}
function pur_cmp_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM pur_cmp WHERE id = ?'); $s->execute([$id]); if (!$c = $s->fetch()) return null;
    $s = $db->prepare('SELECT q.*, v.name AS vendor, v.status AS vstatus FROM pur_quote q JOIN pur_vendor v ON v.id = q.vendor_id WHERE q.cmp_id = ? AND q.active = 1 ORDER BY q.id'); $s->execute([$id]); $c['quotes'] = $s->fetchAll();
    $st = []; if (function_exists('pur_vendor_stats')) foreach ($c['quotes'] as $q) $st[(int)$q['vendor_id']] = pur_vendor_stats($db, (int)$q['vendor_id']);
    $c['score'] = pur_qcd($c['quotes'], array_filter($st, fn($x) => $x['n'] > 0), pur_settings());
    return $c;
}
/** Finish: the chosen quote; not the best-scored one (or not the cheapest) needs a reason. */
function pur_cmp_choose(PDO $db, array $u, int $id, int $quote, string $reason): void {
    if (!pur_rights($u)['buyer']) throw new RuntimeException('Only a buyer finishes comparisons.');
    $c = pur_cmp_get($db, $id); if (!$c || $c['status'] !== 'open') throw new RuntimeException('This comparison is closed.');
    if (!isset($c['score'][$quote])) throw new RuntimeException('Pick one of the quotes.');
    $reason = mb_substr(trim(strip_tags($reason)), 0, 300);
    if (!$c['score'][$quote]['best'] && $reason === '') throw new RuntimeException('This is not the best-scored quote — write why it is chosen.');
    if (count($c['quotes']) < 2 && $reason === '') throw new RuntimeException('Only one quote — write why (single source).');
    $db->prepare("UPDATE pur_cmp SET status = 'done', chosen_quote = ?, reason = ?, decided_at = NOW() WHERE id = ? AND status = 'open'")->execute([$quote, $reason, $id]);
    pur_log($db, (int)$u['id'], 'cmp_done', $c['no'], $reason);
}

/* ---------------- purchase orders ---------------- */

function pur_ord_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM pur_order WHERE id = ?'); $s->execute([$id]); if (!$o = $s->fetch()) return null;
    $s = $db->prepare('SELECT * FROM pur_order_line WHERE order_id = ? AND active = 1 ORDER BY line_no'); $s->execute([$id]); $o['lines'] = $s->fetchAll();
    $o['vendor'] = $o['vendor_id'] ? pur_vendor_get($db, (int)$o['vendor_id']) : null; $o['parts'] = json_decode((string)$o['tax_parts'], true) ?: [];
    return $o;
}
function pur_ord_can_see(array $u, array $o): bool { return pur_rights($u)['see'] && isset(pur_branches($u)[(int)$o['branch_id']]); }
/** A new draft from ticked requisition lines (a finished comparison on a line brings its vendor and rate) or empty. */
function pur_ord_create(PDO $db, array $u, string $kind, int $branch, array $lineIds = []): int {
    if (!pur_rights($u)['buyer']) throw new RuntimeException('Only a buyer makes orders.');
    if (!isset(PUR_ORD_KINDS[$kind])) throw new RuntimeException('Pick the kind of order.');
    if (!isset(pur_branches($u)[$branch])) throw new RuntimeException('Pick one of your branches.');
    $lines = []; $vendor = null;
    foreach (array_unique(array_map('intval', $lineIds)) as $lid) {
        $s = $db->prepare("SELECT l.*, r.branch_id FROM pur_req_line l JOIN pur_req r ON r.id = l.req_id WHERE l.id = ? AND l.status = 'open'"); $s->execute([$lid]); $l = $s->fetch();
        if (!$l) throw new RuntimeException('A ticked requisition line is not open any more.');
        if ((int)$l['branch_id'] !== $branch) throw new RuntimeException('Pick lines of one branch for one order.');
        $c = $db->prepare("SELECT c.id, q.vendor_id, q.rate FROM pur_cmp c JOIN pur_quote q ON q.id = c.chosen_quote WHERE c.req_line_id = ? AND c.status = 'done' ORDER BY c.id DESC LIMIT 1"); $c->execute([$lid]); $cm = $c->fetch() ?: null;
        if ($cm) { if ($vendor !== null && $vendor !== (int)$cm['vendor_id']) throw new RuntimeException('These lines were won by different vendors — make one order per vendor.'); $vendor = (int)$cm['vendor_id']; }
        $lines[] = ['req_line_id' => $lid, 'cmp_id' => $cm['id'] ?? null, 'item_id' => $l['item_id'], 'item' => $l['item'], 'uom' => $l['uom'], 'qty' => (float)$l['qty'], 'rate' => $cm ? (float)$cm['rate'] : (float)($l['est_rate'] ?? $l['last_rate'] ?? 0), 'tax_pct' => 0];
    }
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO pur_order (kind, branch_id, vendor_id, terms, created_by) VALUES (?,?,?,?,?)')->execute([$kind, $branch, $vendor, pur_settings()['terms'], (int)$u['id']]);
        $id = (int)$db->lastInsertId();
        pur_ord_put($db, $id, $lines);
        pur_log($db, (int)$u['id'], 'ord_add', 'O' . $id, count($lines) . ' line(s)');
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $id;
}
/** Write the lines (a draft's earlier lines are switched off) and the totals. */
function pur_ord_put(PDO $db, int $id, array $lines): void {
    $o = $db->query('SELECT * FROM pur_order WHERE id = ' . $id)->fetch();
    $v = $o['vendor_id'] ? pur_vendor_get($db, (int)$o['vendor_id']) : null;
    $head = qt_head($db, (int)$o['branch_id']); $seller = $v ? (string)$v['region'] : ''; $buyer = qt_seller_region($head, (int)$o['branch_id']);
    $T = pur_totals($lines, qt_parts($seller, $buyer), $v === null || (int)$v['tax_none'] === 1);
    $db->prepare('UPDATE pur_order_line SET active = 0 WHERE order_id = ?')->execute([$id]);
    $ins = $db->prepare('INSERT INTO pur_order_line (order_id, line_no, req_line_id, cmp_id, item_id, item, uom, qty, rate, tax_pct, deliv_date, taxable, tax) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array_values($T['lines']) as $i => $l) $ins->execute([$id, $i + 1, $l['req_line_id'] ?? null, $l['cmp_id'] ?? null, $l['item_id'] ?? null, $l['item'], $l['uom'], $l['qty'], $l['rate'], $lines[$i]['tax_pct'], $l['deliv_date'] ?? null, $l['taxable'], $l['tax']]);
    $db->prepare('UPDATE pur_order SET sub = ?, tax = ?, tax_parts = ?, round_off = ?, total = ? WHERE id = ?')->execute([$T['sub'], $T['tax'], json_encode($T['parts']), $T['round_off'], $T['total'], $id]);
}
/** Change a draft (or one sent back): vendor, delivery address, terms, single-source note, lines. */
function pur_ord_save(PDO $db, array $u, int $id, array $in): void {
    $o = pur_ord_get($db, $id); if (!$o || !pur_rights($u)['buyer'] || !pur_ord_can_see($u, $o)) throw new RuntimeException('Order not found.');
    if (!in_array($o['status'], ['draft', 'back'], true)) throw new RuntimeException('Only a draft or an order sent back is changed.');
    $vid = (int)($in['vendor_id'] ?? 0) ?: null; if ($vid && (!($v = pur_vendor_get($db, $vid)) || $v['status'] === 'blocked')) throw new RuntimeException('Pick a vendor that is not blocked.');
    $lines = []; $old = array_column($o['lines'], null, 'line_no');
    foreach ((array)($in['lines'] ?? []) as $l) {
        $item = mb_substr(trim(strip_tags((string)($l['item'] ?? ''))), 0, 255); if ($item === '' && trim((string)($l['qty'] ?? '')) === '') continue;
        if ($item === '') throw new RuntimeException('Every line needs its item.');
        $q = stk_q($l['qty'] ?? ''); $r = stk_q($l['rate'] ?? ''); $t = stk_q(($l['tax_pct'] ?? '') === '' ? '0' : $l['tax_pct']);
        if (!$q || $q <= 0) throw new RuntimeException("$item: quantity above 0."); if ($r === null || $r < 0) throw new RuntimeException("$item: check the rate."); if ($t === null || $t < 0 || $t > 100) throw new RuntimeException("$item: tax % is 0–100.");
        $dd = trim((string)($l['deliv_date'] ?? '')); if ($dd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd)) throw new RuntimeException("$item: check the delivery date.");
        $prev = $old[(int)($l['line_no'] ?? 0)] ?? null;
        $lines[] = ['req_line_id' => $prev['req_line_id'] ?? null, 'cmp_id' => $prev['cmp_id'] ?? null, 'item_id' => $prev['item_id'] ?? null, 'item' => $item, 'uom' => mb_substr(trim((string)($l['uom'] ?? '')), 0, 20), 'qty' => $q, 'rate' => $r, 'tax_pct' => $t, 'deliv_date' => $dd ?: null];
    }
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE pur_order SET vendor_id = ?, deliver_to = ?, terms = ?, single_note = ? WHERE id = ?')->execute([$vid, mb_substr(trim(strip_tags((string)($in['deliver_to'] ?? ''))), 0, 400), mb_substr(trim(strip_tags(str_replace("\r", '', (string)($in['terms'] ?? '')))), 0, 3000), mb_substr(trim(strip_tags((string)($in['single_note'] ?? ''))), 0, 300), $id]);
        pur_ord_put($db, $id, $lines);
        pur_log($db, (int)$u['id'], 'ord_edit', 'O' . $id);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
}
function pur_ord_submit(PDO $db, array $u, int $id): void {
    $o = pur_ord_get($db, $id); $S = pur_settings();
    if (!$o || !in_array($o['status'], ['draft', 'back'], true)) throw new RuntimeException('Only a draft or an order sent back goes for approval.');
    if (!pur_rights($u)['buyer']) throw new RuntimeException('Only a buyer sends orders.');
    if ($p = pur_ord_problems($o, $o['vendor'], $o['lines'], qt_head($db, (int)$o['branch_id']), $S)) throw new RuntimeException(implode('. ', $p) . '.');
    $ceo = (float)$o['sub'] >= (float)$S['limit_value'] ? 1 : 0;
    $db->prepare("UPDATE pur_order SET status = 'mgr', need_ceo = ?, sent_by = ?, sent_at = NOW(), mgr_by = NULL, mgr_at = NULL, mgr_note = '', ceo_by = NULL, ceo_at = NULL, ceo_note = '' WHERE id = ? AND status IN ('draft','back')")->execute([$ceo, (int)$u['id'], $id]);
    pur_log($db, (int)$u['id'], 'ord_send', 'O' . $id, $ceo ? 'approver + CEO' : 'approver');
    pur_notify_roles($db, $S['approver_roles'], (int)$u['id'], PUR_ORD_KINDS[$o['kind']] . ' to approve', ($o['vendor']['name'] ?? '') . ' · ' . number_format((float)$o['total'], 2));
}
function pur_ord_decide(PDO $db, array $u, int $id, string $act, string $note): void {
    $o = pur_ord_get($db, $id); if (!$o || !pur_ord_can_see($u, $o)) throw new RuntimeException('Order not found.');
    $R = pur_rights($u); [$step, $may] = pur_ord_step($o, (int)$u['id'], $R['approver'], $R['ceo']);
    if (in_array((int)$u['id'], [(int)$o['created_by'], (int)($o['sent_by'] ?? 0)], true)) throw new RuntimeException('You made this order — someone else approves it.');
    if (!in_array($step, ['mgr', 'ceo'], true)) throw new RuntimeException('Not waiting for approval.');
    if (!$may) throw new RuntimeException($step === 'ceo' ? ((int)$o['mgr_by'] === (int)$u['id'] ? 'You approved the first step — the CEO decides this one.' : 'Waiting for the CEO.') : 'Waiting for the approver.');
    if (!in_array($act, ['approve', 'back', 'reject'], true)) throw new RuntimeException('Approve, send back or reject.');
    $note = mb_substr(trim(strip_tags($note)), 0, 255); if ($act !== 'approve' && $note === '') throw new RuntimeException('Write a note for the buyer.');
    $next = $act === 'approve' ? ($step === 'mgr' && (int)$o['need_ceo'] ? 'ceo' : 'approved') : ($act === 'back' ? 'back' : 'cancelled');
    $col = $step === 'mgr' ? 'mgr' : 'ceo';
    $db->prepare("UPDATE pur_order SET status = ?, {$col}_by = ?, {$col}_at = NOW(), {$col}_note = ?, cancelled_why = IF(? = 'cancelled', ?, cancelled_why) WHERE id = ? AND status = ?")->execute([$next, (int)$u['id'], $note, $next, 'Rejected: ' . $note, $id, $step]);
    pur_log($db, (int)$u['id'], 'ord_' . $act, 'O' . $id, $note);
    audit((int)$u['id'], 'pur_ord_' . $act, '#' . $id);
    if ($next === 'ceo') pur_notify_roles($db, ['CEO'], (int)$u['id'], PUR_ORD_KINDS[$o['kind']] . ' to approve', 'An order at or above the limit waits for you.');
}
/** Issue an approved order: the number, the header and vendor as they are today. Requisition lines and comparisons are marked ordered. */
function pur_ord_issue(PDO $db, array $u, int $id): string {
    $o = pur_ord_get($db, $id); $S = pur_settings();
    if (!$o || $o['status'] !== 'approved') throw new RuntimeException('Only an approved order is issued.');
    if (!pur_rights($u)['buyer']) throw new RuntimeException('Only a buyer issues orders.');
    $head = qt_head($db, (int)$o['branch_id']);
    if ($p = pur_ord_problems($o, $o['vendor'], $o['lines'], $head, $S, true)) throw new RuntimeException(implode('. ', $p) . '.');
    $v = $o['vendor']; $snap = array_intersect_key($v, array_flip(['code', 'name', 'tax_id', 'tax_none', 'region', 'address', 'city', 'contact', 'phone', 'email']));
    $db->beginTransaction();
    try {
        $cl = $db->prepare("UPDATE pur_order SET status = 'issuing' WHERE id = ? AND status = 'approved'"); $cl->execute([$id]);
        if ($cl->rowCount() !== 1) throw new RuntimeException('Already being issued — open it again.');
        $no = pur_next($db, $o['kind'] === 'jw' ? $S['prefix_jw'] : $S['prefix_po'], (int)$o['branch_id'], date('Y-m-d'));
        $db->prepare("UPDATE pur_order SET status = 'issued', no = ?, d = ?, issued_by = ?, issued_at = NOW(), head = ?, vendor_snap = ? WHERE id = ?")->execute([$no, date('Y-m-d'), (int)$u['id'], json_encode($head, JSON_UNESCAPED_UNICODE), json_encode($snap, JSON_UNESCAPED_UNICODE), $id]);
        foreach ($o['lines'] as $l) if ($l['req_line_id']) $db->prepare("UPDATE pur_req_line SET status = 'ordered', order_id = ? WHERE id = ? AND status = 'open'")->execute([$id, (int)$l['req_line_id']]);
        $db->exec("UPDATE pur_req r SET status = 'done' WHERE status = 'open' AND NOT EXISTS (SELECT 1 FROM pur_req_line l WHERE l.req_id = r.id AND l.status IN ('open','hod'))");
        pur_log($db, (int)$u['id'], 'ord_issue', $no, number_format((float)$o['total'], 2) . ' · ' . $v['name']);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'pur_ord_issue', '#' . $id);
    return $no;
}
function pur_ord_cancel(PDO $db, array $u, int $id, string $why): void {
    $o = pur_ord_get($db, $id); if (!$o || !pur_rights($u)['buyer']) throw new RuntimeException('Order not found.');
    if (in_array($o['status'], ['issued', 'cancelled'], true)) throw new RuntimeException($o['status'] === 'issued' ? 'An issued order is not cancelled here — tell the vendor and record it in the order notes.' : 'Already cancelled.');
    if (($why = trim(strip_tags($why))) === '') throw new RuntimeException('Write why.');
    $db->prepare("UPDATE pur_order SET status = 'cancelled', cancelled_why = ? WHERE id = ?")->execute([mb_substr($why, 0, 255), $id]);
    pur_log($db, (int)$u['id'], 'ord_cancel', 'O' . $id, $why);
}
function pur_orders(PDO $db, array $u, string $show = 'open'): array {
    $w = $show === 'open' ? "o.status NOT IN ('issued','cancelled')" : ($show === 'issued' ? "o.status = 'issued'" : '1=1');
    $s = $db->query("SELECT o.*, v.name AS vendor FROM pur_order o LEFT JOIN pur_vendor v ON v.id = o.vendor_id WHERE $w ORDER BY o.id DESC LIMIT 300");
    return array_values(array_filter($s->fetchAll(), fn($o) => pur_ord_can_see($u, $o)));
}
/** What waits for this login: requisitions to approve, orders to approve, vendors to approve. */
function pur_waiting(PDO $db, array $u): array {
    $R = pur_rights($u); $uid = (int)$u['id'];
    $reqs = array_values(array_filter(array_map(fn($i) => pur_req_get($db, (int)$i), $db->query("SELECT id FROM pur_req WHERE status = 'hod'")->fetchAll(PDO::FETCH_COLUMN)), fn($q) => $q && pur_req_can_decide($u, $q)));
    $ords = array_values(array_filter(pur_orders($db, $u, 'open'), function ($o) use ($uid, $R) { [, $may] = pur_ord_step($o, $uid, $R['approver'], $R['ceo']); return $may; }));
    $vens = $R['approver'] ? array_values(array_filter(pur_vendors($db, 'pending'), fn($v) => !in_array($uid, [(int)$v['created_by'], (int)$v['submitted_by']], true))) : [];
    return ['reqs' => $reqs, 'orders' => $ords, 'vendors' => $vens];
}
require_once __DIR__ . '/purchase_grn.php';
