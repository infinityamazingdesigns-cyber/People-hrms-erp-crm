<?php
// CRM part 2 — orders, readiness blockers, dispatch (assign → accept → packing → consignments), tracking lines,
// customer tracking links and the same-day dispatch score. Pure rules first, then the database half.
// Customer-facing words never carry prices, payment or internal remarks.
declare(strict_types=1);
require_once __DIR__ . '/crm.php';

const SO_STATUSES = ['Draft', 'Submitted', 'In progress', 'Ready for dispatch', 'Partly dispatched', 'Dispatched', 'Delivered', 'Closed', 'Cancelled'];
const SO_MOVES = [   // the moves a person makes; dispatch states follow the consignments
    'Draft' => ['Submitted', 'Cancelled'],
    'Submitted' => ['In progress', 'Draft', 'Cancelled'],
    'In progress' => ['Ready for dispatch', 'Cancelled'],
    'Ready for dispatch' => ['In progress', 'Cancelled'],
    'Partly dispatched' => [], 'Dispatched' => ['Delivered'], 'Delivered' => ['Closed'], 'Closed' => [], 'Cancelled' => [],
];
const SO_DISPATCH = ['Not assigned', 'Assigned', 'Accepted', 'Packing', 'Partly dispatched', 'Dispatched', 'Delivered', 'Exception', 'Cancelled'];
const SO_ADVANCE = ['Not required', 'Pending', 'Partly received', 'Received'];
const SO_SHIP_STATUS = ['Booked', 'In transit', 'Out for delivery', 'Delivered', 'Exception', 'Returned', 'Cancelled'];
const SO_CARRIER_KINDS = ['Courier', 'Transport', 'Postal', 'Own vehicle'];
const SO_STAGES = ['received' => 'Order received', 'preparing' => 'Preparing', 'packing' => 'Packing', 'sent' => 'Handed to transport',
                   'on_way' => 'On the way', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
const SO_TOKEN_LEN = 43;
const SO_PUBLIC_PER_MIN = 30;

/* ---------------- pure ---------------- */

/** Year label for a date, by the organisation's first month (1 = calendar year "2026", else "26-27"). */
function so_fy(string $ymd, int $startMonth = 4): string {
    $y = (int)substr($ymd, 0, 4); $m = (int)substr($ymd, 5, 2);
    if ($startMonth <= 1) return (string)$y;
    $s = $m >= $startMonth ? $y : $y - 1;
    return sprintf('%02d-%02d', $s % 100, ($s + 1) % 100);
}
/** SO/BR/26-27/0001 */
function so_order_no(string $prefix, string $branch, string $fy, int $n): string {
    return implode('/', array_filter([$prefix, strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $branch)), $fy], 'strlen')) . '/' . sprintf('%04d', $n);
}
function so_can_move(string $from, string $to): bool { return in_array($to, SO_MOVES[$from] ?? [], true); }
/** [before tax, tax, with tax] of one line, rounded to 2. */
function so_line_value(float $qty, float $rate, float $disc, float $tax): array {
    $v = round($qty * $rate * (1 - $disc / 100), 2); $t = round($v * $tax / 100, 2);
    return [$v, $t, round($v + $t, 2)];
}
/** Customer-facing stage of an order. $ships = statuses of its consignments. */
function so_stage_of(array $o, array $ships): string {
    if ($o['status'] === 'Cancelled') return 'cancelled';
    if ($o['status'] === 'Draft') return '';
    if (in_array($o['status'], ['Delivered', 'Closed'], true)) return 'delivered';
    if ($ships && array_intersect($ships, ['In transit', 'Out for delivery', 'Delivered'])) return 'on_way';
    if ($ships) return 'sent';
    if (in_array($o['dispatch_status'], ['Packing', 'Accepted'], true) || $o['status'] === 'Ready for dispatch') return 'packing';
    if ($o['status'] === 'In progress') return 'preparing';
    return 'received';
}
/** The stage to store: never back for the customer (cancelled excepted). '' = no change. */
function so_stage_next(string $cur, string $new): string {
    if ($new === '' || $new === $cur) return '';
    if ($cur === '' || $new === 'cancelled') return $new;
    $r = array_flip(array_keys(SO_STAGES));
    return ($r[$new] ?? 0) > ($r[$cur] ?? 0) ? $new : '';
}
/** A carrier's tracking address for one consignment: {ref} filled, else the page to copy the number into. */
function so_carrier_link(array $car, string $ref): ?array {
    $u = (string)($car['track_url'] ?? '');
    if ($u === '') return null;
    return str_contains($u, '{ref}') ? ['url' => str_replace('{ref}', rawurlencode($ref), $u), 'copy' => false] : ['url' => $u, 'copy' => true];
}
/** The customer update text — order number, stage, link; never prices. */
function so_stage_text(string $org, string $orderNo, string $stage, string $extra, string $link): string {
    return "$org: your order $orderNo — " . (SO_STAGES[$stage] ?? $stage) . ($extra !== '' ? " ($extra)" : '') . '.' . ($link !== '' ? "\nTrack it here: $link" : '');
}
/** The day an order counts for: punch day, or the next day when punched after the cut-off (HH:MM). */
function so_punch_day(string $punchedAt, string $cutoff): string {
    $d = substr($punchedAt, 0, 10);
    return $cutoff !== '' && substr($punchedAt, 11, 5) > $cutoff ? date('Y-m-d', strtotime($d . ' +1 day')) : $d;
}
/**
 * Same-day dispatch score. $orders: [id, punched_at, first_dispatch_at, dispatcher]; $blockers[id]: [[customer_side, created_at, resolved_at]].
 * An order is same-day when its first consignment left on its punch day; one held by the customer at the end of that
 * day is left out of the score. Score = same-day ÷ (punched − customer holds).
 */
function so_sameday_calc(array $orders, array $blockers, string $cutoff): array {
    $blank = ['punched' => 0, 'same_day' => 0, 'customer_hold' => 0, 'internal_hold' => 0, 'late' => 0, 'next_day' => 0];
    $tot = $blank; $days = []; $mins = [];
    foreach ($orders as $o) {
        $d = so_punch_day((string)$o['punched_at'], $cutoff); $end = date('Y-m-d', strtotime($d . ' +1 day')) . ' 00:00:00';
        $days[$d] ??= $blank;
        $fd = $o['first_dispatch_at'] ?? null; $same = $fd !== null && substr($fd, 0, 10) === $d;
        $held = fn(int $side) => (bool)array_filter($blockers[$o['id']] ?? [], fn($b) => (int)$b['customer_side'] === $side && $b['created_at'] < $end && ($b['resolved_at'] === null || $b['resolved_at'] >= $end));
        $k = $same ? 'same_day' : ($held(1) ? 'customer_hold' : ($held(0) ? 'internal_hold' : 'late'));
        foreach ([&$days[$d], &$tot] as &$x) { $x['punched']++; $x[$k]++; if ($fd !== null && substr($fd, 0, 10) === substr($end, 0, 10)) $x['next_day']++; }
        unset($x);
        if ($fd !== null) $mins[] = max(0, (int)round((strtotime($fd) - strtotime((string)$o['punched_at'])) / 60));
    }
    $score = fn(array $x) => $x['punched'] - $x['customer_hold'] > 0 ? round(100 * $x['same_day'] / ($x['punched'] - $x['customer_hold']), 1) : null;
    sort($mins); $n = count($mins);
    $median = $n ? ($n % 2 ? $mins[intdiv($n, 2)] : (int)round(($mins[$n / 2 - 1] + $mins[$n / 2]) / 2)) : null;
    ksort($days); $dl = []; foreach ($days as $d => $x) $dl[] = ['day' => $d] + $x + ['score' => $score($x)];
    return ['total' => $tot + ['score' => $score($tot), 'median_min' => $median], 'days' => $dl];
}
function so_uuid(string $s): string {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) ? strtolower($s)
        : vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex(random_bytes(16)), 4));
}
function so_txt($v, int $max): string { return mb_substr(trim(strip_tags(str_replace("\r", '', (string)$v))), 0, $max); }
function so_num($v, string $what, float $min = 0, ?float $max = null): float {
    $v = trim(str_replace(',', '', (string)$v));
    if (!is_numeric($v) || (float)$v < $min || ($max !== null && (float)$v > $max)) throw new RuntimeException("$what: enter a number" . ($min > 0 ? " above $min" : '') . '.');
    return (float)$v;
}
function so_date(?string $s, string $what, bool $need = false): ?string {
    $s = trim((string)$s);
    if ($s === '') { if ($need) throw new RuntimeException("$what is needed."); return null; }
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) throw new RuntimeException("$what: pick a date.");
    return $s;
}

/* ---------------- rights ---------------- */

/** May this login see / change / dispatch this order? */
function so_can(array $u, array $o, string $what = 'view'): bool {
    $R = crm_rights($u);
    $branch = $o['branch_id'] === null || emp_in_scope($u, ['branch_id' => $o['branch_id']]);
    if ($what === 'dispatch') return ($R['dispatch'] || $R['all']) && $branch;
    if ($R['all']) return $branch;
    if ($R['own'] && (int)$o['salesperson_id'] === (int)$u['id']) return true;
    if ($what !== 'view') return false;
    return $branch && ($R['dispatch'] || (bool)array_intersect(crm_roles($u), crm_settings()['view_roles']));
}
function so_need(array $u, array $o, string $what = 'edit'): void { if (!so_can($u, $o, $what)) throw new RuntimeException('Your role cannot do this on this order.'); }

/* ---------------- database ---------------- */

function so_row(PDO $db, int $id, bool $lock = false): array {
    $s = $db->prepare('SELECT * FROM crm_order WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')); $s->execute([$id]);
    return $s->fetch() ?: throw new RuntimeException('Order not found.');
}
function so_event(PDO $db, int $order, ?int $by, string $kind, ?string $from, ?string $to, string $note = '', bool $public = false): void {
    $db->prepare('INSERT INTO crm_order_event (order_id, by_user, kind, from_v, to_v, note, public) VALUES (?,?,?,?,?,?,?)')
       ->execute([$order, $by, $kind, $from === null ? null : mb_substr($from, 0, 120), $to === null ? null : mb_substr($to, 0, 160), mb_substr($note, 0, 500), $public ? 1 : 0]);
}
function so_tx(PDO $db, callable $fn) {
    $own = !$db->inTransaction(); if ($own) $db->beginTransaction();
    try { $r = $fn(); if ($own) $db->commit(); return $r; } catch (Throwable $e) { if ($own && $db->inTransaction()) $db->rollBack(); throw $e; }
}
function so_user_ok(PDO $db, int $uid): bool { $s = $db->prepare('SELECT 1 FROM users WHERE id = ? AND active = 1'); $s->execute([$uid]); return (bool)$s->fetchColumn(); }
function so_branch_code(?int $id): string { foreach (branches(false) as $b) if ((int)$b['id'] === (int)$id) return (string)$b['code']; return ''; }

function so_next_no(PDO $db, ?int $branch, string $ymd): string {
    $set = crm_settings(); $fy = so_fy($ymd, (int)(org('fy_start_month') ?: 4)); $bc = so_branch_code($branch);
    $key = $set['order_prefix'] . '|' . $bc . '|' . $fy;
    $db->prepare('INSERT INTO crm_order_seq (prefix, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$key]);
    $s = $db->prepare('SELECT n FROM crm_order_seq WHERE prefix = ?'); $s->execute([$key]);
    return so_order_no($set['order_prefix'], $bc, $fy, (int)$s->fetchColumn());
}

/** $in: request_id, branch_id, customer_id, contact_id, ship_address_id, source, source_ref, received_at (Y-m-d H:i), po_number, po_date,
 *  salesperson_id, advance_status, advance_amount, payment_due, delivery_address, delivery_postcode, expected_dispatch, requirements,
 *  internal_remarks, customer_remarks, notify_email, submit, lines: [[product_id, qty, rate, discount_pct, tax_pct, description]] */
function so_create(PDO $db, array $u, array $in): int {
    crm_need($u, 'edit');
    $req = so_uuid((string)($in['request_id'] ?? ''));
    $s = $db->prepare('SELECT id FROM crm_order WHERE request_id = ?'); $s->execute([$req]);
    if ($id = (int)$s->fetchColumn()) return $id;
    $cu = crm_customer_get($db, (int)($in['customer_id'] ?? 0));
    if (!$cu || $cu['status'] !== 'active' || !crm_can($u, $cu)) throw new RuntimeException('Pick an active customer you work with.');
    $branch = (int)($in['branch_id'] ?? 0) ?: ($cu['branch_id'] !== null ? (int)$cu['branch_id'] : null);
    if ($branch && !emp_in_scope($u, ['branch_id' => $branch])) throw new RuntimeException('That branch is outside your scope.');
    $set = crm_settings(); $h = so_header($db, $u, $cu, $in, $set);
    return so_tx($db, function () use ($db, $u, $in, $req, $branch, $cu, $h, $set) {
        $submit = !empty($in['submit']);
        $no = so_next_no($db, $branch, date('Y-m-d'));
        $f = $h + ['order_no' => $no, 'request_id' => $req, 'branch_id' => $branch, 'customer_id' => (int)$cu['id'], 'status' => $submit ? 'Submitted' : 'Draft',
                   'submitted_at' => $submit ? date('Y-m-d H:i:s') : null, 'created_by' => (int)$u['id'],
                   'notify_email' => array_key_exists('notify_email', $in) ? (int)!empty($in['notify_email']) : (int)$set['notify_default']];
        $db->prepare('INSERT INTO crm_order (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
        $id = (int)$db->lastInsertId();
        so_put_lines($db, $id, (array)($in['lines'] ?? []));
        so_event($db, $id, (int)$u['id'], 'status', null, $f['status'], 'Source: ' . $h['source'] . ($h['source_ref'] !== '' ? ' ' . $h['source_ref'] : ''));
        so_stage_sync($db, $id, (int)$u['id']);
        audit((int)$u['id'], 'crm_order_add', '#' . $id . ' lines ' . count((array)$in['lines']));
        return $id;
    });
}
/** The order header fields from a form (create and change). */
function so_header(PDO $db, array $u, array $cu, array $in, array $set): array {
    $src = so_txt($in['source'] ?? '', 60);
    if (!in_array($src, crm_lines($set['order_sources']), true)) throw new RuntimeException('Pick where the order came from.');
    $rcv = trim((string)($in['received_at'] ?? '')); $rcv = $rcv === '' ? date('Y-m-d H:i:s') : str_replace('T', ' ', $rcv);
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $rcv) || strtotime($rcv) === false) throw new RuntimeException('Order received: pick a date and time.');
    if (strtotime($rcv) > time() + 300) throw new RuntimeException('Order received cannot be in the future.');
    $po = so_date($in['po_date'] ?? '', 'PO date'); if ($po !== null && $po > date('Y-m-d')) throw new RuntimeException('PO date cannot be in the future.');
    $sp = (int)($in['salesperson_id'] ?? 0) ?: (int)$u['id'];
    if (!so_user_ok($db, $sp)) throw new RuntimeException('Pick an active salesperson.');
    if ($sp !== (int)$u['id'] && !crm_rights($u)['all']) throw new RuntimeException('You register orders in your own name.');
    $adv = (string)($in['advance_status'] ?? 'Not required'); if (!in_array($adv, SO_ADVANCE, true)) throw new RuntimeException('Pick an advance status.');
    $contact = (int)($in['contact_id'] ?? 0) ?: null;
    if ($contact && !in_array($contact, array_map(fn($c) => (int)$c['id'], $cu['contacts']), true)) throw new RuntimeException('Pick a contact person of this customer.');
    $aid = (int)($in['ship_address_id'] ?? 0); $ad = null;
    foreach ($cu['addresses'] as $x) if ($aid ? (int)$x['id'] === $aid : true) { $ad = $x; break; }
    if ($aid && !$ad) throw new RuntimeException('Pick a delivery address of this customer.');
    $addr = so_txt($in['delivery_address'] ?? '', 500);
    if ($addr === '' && $ad) $addr = implode(', ', array_filter([$ad['line1'], $ad['line2'], $ad['city'], $ad['state'], $ad['country']]));
    if ($addr === '') throw new RuntimeException('A delivery address is needed.');
    return ['contact_id' => $contact, 'ship_address_id' => $ad ? (int)$ad['id'] : null, 'po_number' => so_txt($in['po_number'] ?? '', 60), 'po_date' => $po,
            'source' => $src, 'source_ref' => so_txt($in['source_ref'] ?? '', 80), 'received_at' => strlen($rcv) === 16 ? $rcv . ':00' : $rcv, 'salesperson_id' => $sp,
            'currency' => strtoupper(substr((string)preg_replace('/[^A-Za-z]/', '', (string)($in['currency'] ?? org('currency') ?: 'INR')), 0, 3)) ?: 'INR',
            'advance_status' => $adv, 'advance_amount' => trim((string)($in['advance_amount'] ?? '')) === '' ? null : so_num($in['advance_amount'], 'Advance amount'),
            'payment_due' => so_date($in['payment_due'] ?? '', 'Payment due'), 'delivery_address' => $addr,
            'delivery_postcode' => so_txt(($in['delivery_postcode'] ?? '') !== '' ? $in['delivery_postcode'] : ($ad['postcode'] ?? ''), 12),
            'expected_dispatch' => so_date($in['expected_dispatch'] ?? '', 'Expected dispatch'), 'requirements' => so_txt($in['requirements'] ?? '', 1000),
            'internal_remarks' => so_txt($in['internal_remarks'] ?? '', 1000), 'customer_remarks' => so_txt($in['customer_remarks'] ?? '', 500)];
}
function so_put_lines(PDO $db, int $order, array $lines): void {
    $lines = array_values(array_filter($lines, fn($l) => (int)($l['product_id'] ?? 0) > 0 || trim((string)($l['qty'] ?? '')) !== ''));
    if (!$lines) throw new RuntimeException('Add at least one product line.');
    if (count($lines) > 300) throw new RuntimeException('Too many lines on one order.');
    $db->prepare('DELETE FROM crm_order_line WHERE order_id = ? AND qty_dispatched = 0')->execute([$order]);   // only before anything left (callers check)
    $p = $db->prepare('SELECT * FROM crm_product WHERE id = ? AND active = 1 AND merged_into IS NULL');
    $ins = $db->prepare('INSERT INTO crm_order_line (order_id, line_no, product_id, description, unit, qty, rate, discount_pct, tax_pct, line_value, line_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($lines as $i => $l) {
        $n = $i + 1; $p->execute([(int)($l['product_id'] ?? 0)]); $pr = $p->fetch();
        if (!$pr) throw new RuntimeException("Line $n: pick a product.");
        $rate = trim((string)($l['rate'] ?? '')) === '' ? $pr['rate'] : $l['rate'];
        if ($rate === null) throw new RuntimeException("Line $n: enter the rate (the product has none).");
        $q = so_num($l['qty'] ?? '', "Line $n quantity", 0.001, 1e8); $r = so_num($rate, "Line $n rate", 0, 1e11);
        $d = so_num(($l['discount_pct'] ?? '') === '' ? 0 : $l['discount_pct'], "Line $n discount", 0, 100);
        $t = so_num(($l['tax_pct'] ?? '') === '' ? $pr['tax_pct'] : $l['tax_pct'], "Line $n tax", 0, 100);
        [$v, , $tot] = so_line_value($q, $r, $d, $t);
        $ins->execute([$order, $n, (int)$pr['id'], so_txt($l['description'] ?? '', 300) ?: $pr['name'], $pr['unit'], $q, $r, $d, $t, $v, $tot]);
    }
    $db->prepare('UPDATE crm_order SET order_value = (SELECT COALESCE(SUM(line_value),0) FROM crm_order_line WHERE order_id = ?), order_total = (SELECT COALESCE(SUM(line_total),0) FROM crm_order_line WHERE order_id = ?) WHERE id = ?')->execute([$order, $order, $order]);
}
/** Change header and lines while Draft / Submitted. */
function so_edit(PDO $db, array $u, int $id, array $in, int $version): void {
    $o = so_row($db, $id); so_need($u, $o);
    if (!in_array($o['status'], ['Draft', 'Submitted'], true)) throw new RuntimeException('Only a draft or submitted order can be changed.');
    if ((int)$o['version'] !== $version) throw new RuntimeException('Someone changed this order meanwhile — open it again.');
    $cu = crm_customer_get($db, (int)$o['customer_id']); $h = so_header($db, $u, $cu, $in, crm_settings());
    so_tx($db, function () use ($db, $u, $id, $in, $h) {
        $h['notify_email'] = (int)!empty($in['notify_email']);
        $db->prepare('UPDATE crm_order SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($h))) . ', version = version + 1 WHERE id = ?')->execute([...array_values($h), $id]);
        so_put_lines($db, $id, (array)($in['lines'] ?? []));
        so_event($db, $id, (int)$u['id'], 'edit', null, null, 'Order changed');
    });
    audit((int)$u['id'], 'crm_order_edit', '#' . $id);
}
function so_status(PDO $db, array $u, int $id, string $to, string $note = ''): void {
    so_tx($db, function () use ($db, $u, $id, $to, $note) {
        $o = so_row($db, $id, true); so_need($u, $o);
        if (!so_can_move($o['status'], $to)) throw new RuntimeException("An order cannot go from {$o['status']} to $to.");
        $note = so_txt($note, 500);
        if ($to === 'Cancelled' && $note === '') throw new RuntimeException('Say why the order is cancelled.');
        if ($to === 'Ready for dispatch' && crm_settings()['ready_needs_no_blockers']) {
            $b = $db->prepare('SELECT COUNT(*) FROM crm_blocker WHERE order_id = ? AND resolved_at IS NULL'); $b->execute([$id]);
            if ((int)$b->fetchColumn()) throw new RuntimeException('Clear the open readiness blockers first.');
        }
        $db->prepare('UPDATE crm_order SET status = ?, version = version + 1, submitted_at = COALESCE(submitted_at, IF(? = \'Submitted\', NOW(), NULL)),
                      closed_at = IF(? IN (\'Closed\',\'Cancelled\'), NOW(), closed_at), dispatch_status = IF(? = \'Cancelled\', \'Cancelled\', dispatch_status) WHERE id = ?')->execute([$to, $to, $to, $to, $id]);
        if ($to === 'Cancelled') $db->prepare('UPDATE crm_dispatch_assign SET released_at = NOW() WHERE order_id = ? AND released_at IS NULL')->execute([$id]);
        so_event($db, $id, (int)$u['id'], 'status', $o['status'], $to, $note);
        so_stage_sync($db, $id, (int)$u['id']);
    });
    audit((int)$u['id'], 'crm_order_status', '#' . $id . ' ' . $to);
}
function so_confirm_date(PDO $db, array $u, int $id, ?string $date): void {
    $o = so_row($db, $id); so_need($u, $o);
    $d = so_date($date, 'Dispatch date');
    $db->prepare('UPDATE crm_order SET confirmed_dispatch = ?, version = version + 1 WHERE id = ?')->execute([$d, $id]);
    so_event($db, $id, (int)$u['id'], 'date', $o['confirmed_dispatch'], $d, 'Dispatch date confirmed with the customer');
}

function so_blocker_add(PDO $db, array $u, int $order, string $type, int $owner, string $reason): int {
    return so_tx($db, function () use ($db, $u, $order, $type, $owner, $reason) {
        $o = so_row($db, $order, true); so_need($u, $o);
        $set = crm_settings(); $cust = in_array($type, crm_lines($set['customer_blockers']), true);
        if (!$cust && !in_array($type, crm_lines($set['internal_blockers']), true)) throw new RuntimeException('Pick a blocker from the list.');
        if (!in_array($o['status'], ['Submitted', 'In progress', 'Ready for dispatch', 'Partly dispatched'], true)) throw new RuntimeException('Blockers are for open orders.');
        if (!so_user_ok($db, $owner)) throw new RuntimeException('Pick who follows it up.');
        if (($reason = so_txt($reason, 500)) === '') throw new RuntimeException('Say what is holding it.');
        $db->prepare('INSERT INTO crm_blocker (order_id, blocker, customer_side, owner_user, reason, created_by) VALUES (?,?,?,?,?,?)')->execute([$order, $type, (int)$cust, $owner, $reason, (int)$u['id']]);
        $id = (int)$db->lastInsertId();
        if ($o['status'] === 'Ready for dispatch' && $set['ready_needs_no_blockers']) { $db->prepare("UPDATE crm_order SET status = 'In progress', version = version + 1 WHERE id = ?")->execute([$order]); so_event($db, $order, (int)$u['id'], 'status', $o['status'], 'In progress', 'Blocker added'); }
        so_event($db, $order, (int)$u['id'], 'blocker', null, $type, $reason);
        return $id;
    });
}
function so_blocker_resolve(PDO $db, array $u, int $id, string $note): void {
    $s = $db->prepare('SELECT * FROM crm_blocker WHERE id = ?'); $s->execute([$id]); $b = $s->fetch();
    if (!$b || $b['resolved_at'] !== null) throw new RuntimeException('That blocker is not open.');
    $o = so_row($db, (int)$b['order_id']);
    if (!so_can($u, $o) && (int)$b['owner_user'] !== (int)$u['id']) throw new RuntimeException('Your role cannot do this on this order.');
    if (($note = so_txt($note, 500)) === '') throw new RuntimeException('Say how it was cleared.');
    $db->prepare('UPDATE crm_blocker SET resolved_at = NOW(), resolved_by = ?, resolution = ? WHERE id = ? AND resolved_at IS NULL')->execute([(int)$u['id'], $note, $id]);
    so_event($db, (int)$b['order_id'], (int)$u['id'], 'blocker_done', $b['blocker'], null, $note);
}

function so_dispatch_status(PDO $db, int $order, ?int $by, string $to): void {
    $s = $db->prepare('SELECT dispatch_status FROM crm_order WHERE id = ?'); $s->execute([$order]); $from = (string)$s->fetchColumn();
    if ($from === $to) return;
    $db->prepare('UPDATE crm_order SET dispatch_status = ?, version = version + 1 WHERE id = ?')->execute([$to, $order]);
    so_event($db, $order, $by, 'dispatch', $from, $to);
}
/** Logins who may dispatch this order (dispatch roles, branch scope). */
function so_dispatch_people(PDO $db, array $o): array {
    $roles = crm_settings()['dispatch_roles']; if (!$roles) return [];
    $s = $db->prepare('SELECT DISTINCT u.* FROM users u JOIN user_role r ON r.user_id = u.id WHERE u.active = 1 AND r.role_code IN (' . implode(',', array_fill(0, count($roles), '?')) . ') ORDER BY u.name');
    $s->execute($roles);
    return array_values(array_filter($s->fetchAll(), fn($x) => so_can($x, $o, 'dispatch')));
}
function so_assign(PDO $db, array $u, int $order, int $to, string $note = ''): void {
    so_tx($db, function () use ($db, $u, $order, $to, $note) {
        $o = so_row($db, $order, true); so_need($u, $o, 'dispatch');
        if (!in_array($o['status'], ['Ready for dispatch', 'Partly dispatched'], true)) throw new RuntimeException("The order is {$o['status']} — not ready for dispatch.");
        if (!in_array($to, array_map(fn($x) => (int)$x['id'], so_dispatch_people($db, $o)), true)) throw new RuntimeException('That person cannot dispatch for this branch.');
        $db->prepare('UPDATE crm_dispatch_assign SET released_at = NOW() WHERE order_id = ? AND released_at IS NULL')->execute([$order]);
        $db->prepare('INSERT INTO crm_dispatch_assign (order_id, assignee, assigned_by, note) VALUES (?,?,?,?)')->execute([$order, $to, (int)$u['id'], so_txt($note, 300)]);
        if (in_array($o['dispatch_status'], ['Not assigned', 'Assigned', 'Accepted'], true)) so_dispatch_status($db, $order, (int)$u['id'], 'Assigned');
    });
    audit((int)$u['id'], 'crm_dispatch_assign', '#' . $order . ' > ' . $to);
}
function so_accept(PDO $db, array $u, int $order): void {
    so_tx($db, function () use ($db, $u, $order) {
        $o = so_row($db, $order, true); so_need($u, $o, 'dispatch');
        $s = $db->prepare('SELECT id FROM crm_dispatch_assign WHERE order_id = ? AND assignee = ? AND released_at IS NULL AND accepted_at IS NULL'); $s->execute([$order, (int)$u['id']]);
        if (!$aid = (int)$s->fetchColumn()) throw new RuntimeException('Only the person it is given to accepts it.');
        $db->prepare('UPDATE crm_dispatch_assign SET accepted_at = NOW() WHERE id = ?')->execute([$aid]);
        so_dispatch_status($db, $order, (int)$u['id'], 'Accepted'); so_stage_sync($db, $order, (int)$u['id']);
    });
}
function so_packing(PDO $db, array $u, int $order): void {
    so_tx($db, function () use ($db, $u, $order) {
        $o = so_row($db, $order, true); so_need($u, $o, 'dispatch');
        if (!in_array($o['status'], ['Ready for dispatch', 'Partly dispatched'], true)) throw new RuntimeException('Packing starts once the order is ready for dispatch.');
        so_dispatch_status($db, $order, (int)$u['id'], 'Packing'); so_stage_sync($db, $order, (int)$u['id']);
    });
}
/** A consignment: carrier, number, dates, the quantities in it (never more than is left — checked under a row lock). */
function so_ship_add(PDO $db, array $u, int $order, array $in): int {
    $req = so_uuid((string)($in['request_id'] ?? ''));
    $id = so_tx($db, function () use ($db, $u, $order, $in, $req) {
        $o = so_row($db, $order, true); so_need($u, $o, 'dispatch');
        $s = $db->prepare('SELECT id FROM crm_shipment WHERE request_id = ?'); $s->execute([$req]);
        if ($sid = (int)$s->fetchColumn()) return $sid;
        if (!in_array($o['status'], ['Ready for dispatch', 'Partly dispatched'], true)) throw new RuntimeException("The order is {$o['status']} — it cannot be dispatched.");
        $c = $db->prepare('SELECT * FROM crm_carrier WHERE id = ? AND active = 1'); $c->execute([(int)($in['carrier_id'] ?? 0)]);
        $car = $c->fetch() ?: throw new RuntimeException('Pick the courier or transport.');
        if (($ref = so_txt($in['ref'] ?? '', 60)) === '') throw new RuntimeException($car['ref_type'] . ' is needed.');
        $book = so_date($in['booking_date'] ?? '', 'Booking date', true); $disp = so_date($in['dispatch_date'] ?? '', 'Dispatch date', true);
        if ($disp < $book) throw new RuntimeException('The dispatch date cannot be before the booking date.');
        if ($disp > date('Y-m-d')) throw new RuntimeException('The dispatch date cannot be in the future.');
        $q = $db->prepare('SELECT COALESCE(MAX(seq),0)+1 FROM crm_shipment WHERE order_id = ?'); $q->execute([$order]);
        $db->prepare('INSERT INTO crm_shipment (order_id, seq, request_id, carrier_id, ref, booking_date, dispatch_date, vehicle_no, transport_doc, packages, weight_kg, remarks, status, status_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$order, (int)$q->fetchColumn(), $req, (int)$car['id'], $ref, $book, $disp, strtoupper(so_txt($in['vehicle_no'] ?? '', 20)), so_txt($in['transport_doc'] ?? '', 40),
                      trim((string)($in['packages'] ?? '')) === '' ? null : (int)so_num($in['packages'], 'Packages', 1, 100000),
                      trim((string)($in['weight_kg'] ?? '')) === '' ? null : so_num($in['weight_kg'], 'Weight', 0, 1e6), so_txt($in['remarks'] ?? '', 500), 'Booked', $disp . ' 00:00:00', (int)$u['id']]);
        $sid = (int)$db->lastInsertId();
        $get = $db->prepare('SELECT qty - qty_dispatched FROM crm_order_line WHERE id = ? AND order_id = ? FOR UPDATE');
        $put = $db->prepare('INSERT INTO crm_shipment_line (shipment_id, line_id, qty) VALUES (?,?,?)');
        $add = $db->prepare('UPDATE crm_order_line SET qty_dispatched = qty_dispatched + ? WHERE id = ?');
        $any = false;
        foreach ((array)($in['qty'] ?? []) as $lid => $qty) {
            if (trim((string)$qty) === '' || (float)$qty == 0.0) continue;
            $get->execute([(int)$lid, $order]); $left = $get->fetchColumn();
            if ($left === false) throw new RuntimeException('A line does not belong to this order.');
            $n = so_num($qty, 'Quantity', 0.001);
            if ($n > (float)$left + 1e-9) throw new RuntimeException('More than the quantity left (' . rtrim(rtrim((string)$left, '0'), '.') . ').');
            $put->execute([$sid, (int)$lid, $n]); $add->execute([$n, (int)$lid]); $any = true;
        }
        if (!$any) throw new RuntimeException('Enter the quantities in this consignment.');
        $db->prepare('INSERT INTO crm_track (shipment_id, at, status, by_user) VALUES (?,?,?,?)')->execute([$sid, $disp . ' 00:00:00', 'Booked', (int)$u['id']]);
        $db->prepare('UPDATE crm_order SET first_dispatch_at = COALESCE(first_dispatch_at, NOW()) WHERE id = ?')->execute([$order]);
        $r = $db->prepare('SELECT COALESCE(SUM(qty - qty_dispatched),0) FROM crm_order_line WHERE order_id = ?'); $r->execute([$order]);
        $to = (float)$r->fetchColumn() > 1e-9 ? 'Partly dispatched' : 'Dispatched';
        if ($o['status'] !== $to) { $db->prepare('UPDATE crm_order SET status = ?, version = version + 1 WHERE id = ?')->execute([$to, $order]); so_event($db, $order, null, 'status', $o['status'], $to); }
        so_event($db, $order, (int)$u['id'], 'shipment', null, $car['name'] . ' ' . $ref);
        so_dispatch_status($db, $order, null, $to);
        so_stage_sync($db, $order, (int)$u['id']);
        return $sid;
    });
    audit((int)$u['id'], 'crm_shipment_add', '#' . $order . ' ship ' . $id);
    return $id;
}
/** A tracking line typed in by staff; the latest time sets the consignment's status; all delivered → order Delivered. */
function so_track_add(PDO $db, array $u, int $ship, array $in): void {
    so_tx($db, function () use ($db, $u, $ship, $in) {
        $s = $db->prepare('SELECT * FROM crm_shipment WHERE id = ? FOR UPDATE'); $s->execute([$ship]);
        $sh = $s->fetch() ?: throw new RuntimeException('Consignment not found.');
        $o = so_row($db, (int)$sh['order_id'], true); so_need($u, $o, 'dispatch');
        $st = (string)($in['status'] ?? ''); if (!in_array($st, SO_SHIP_STATUS, true)) throw new RuntimeException('Pick a tracking status.');
        $at = str_replace('T', ' ', trim((string)($in['at'] ?? ''))) ?: date('Y-m-d H:i');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $at) || strtotime($at) > time() + 600) throw new RuntimeException('Pick the time of this update (not in the future).');
        $db->prepare('INSERT INTO crm_track (shipment_id, at, status, location, note, by_user) VALUES (?,?,?,?,?,?)')->execute([$ship, $at . ':00', $st, so_txt($in['location'] ?? '', 160), so_txt($in['note'] ?? '', 200), (int)$u['id']]);
        $l = $db->prepare('SELECT status, at FROM crm_track WHERE shipment_id = ? ORDER BY at DESC, id DESC LIMIT 1'); $l->execute([$ship]); $last = $l->fetch();
        $pod = so_txt($in['pod_ref'] ?? '', 120);
        $db->prepare('UPDATE crm_shipment SET status = ?, status_at = ?, delivered_at = IF(? = \'Delivered\', ?, NULL), pod_ref = IF(? <> \'\', ?, pod_ref) WHERE id = ?')
           ->execute([$last['status'], $last['at'], $last['status'], $last['at'], $pod, $pod, $ship]);
        $order = (int)$sh['order_id'];
        so_event($db, $order, (int)$u['id'], 'tracking', null, $st, 'Consignment ' . $sh['seq'] . ($in['location'] ?? '' ? ': ' . so_txt($in['location'], 160) : ''));
        $open = $db->prepare("SELECT COUNT(*) FROM crm_shipment WHERE order_id = ? AND status NOT IN ('Delivered','Cancelled')"); $open->execute([$order]);
        if ($o['status'] === 'Dispatched' && (int)$open->fetchColumn() === 0) {
            $db->prepare("UPDATE crm_order SET status = 'Delivered', version = version + 1 WHERE id = ?")->execute([$order]);
            so_event($db, $order, null, 'status', 'Dispatched', 'Delivered'); so_dispatch_status($db, $order, null, 'Delivered');
        } elseif ($last['status'] === 'Exception') so_dispatch_status($db, $order, null, 'Exception');
        elseif ($o['dispatch_status'] === 'Exception') so_dispatch_status($db, $order, null, $o['status'] === 'Partly dispatched' ? 'Partly dispatched' : 'Dispatched');
        so_stage_sync($db, $order, (int)$u['id']);
    });
}

/* ---------------- carriers ---------------- */
function so_carriers(PDO $db, bool $activeOnly = true): array { return $db->query('SELECT * FROM crm_carrier' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, name')->fetchAll(); }
function so_carrier_save(PDO $db, array $u, int $id, array $in): void {
    crm_need($u, 'admin');
    $code = strtoupper(trim((string)($in['code'] ?? '')));
    if (!preg_match('/^[A-Z0-9_]{2,20}$/', $code)) throw new RuntimeException('Code: 2–20 capital letters, digits or _.');
    $name = so_txt($in['name'] ?? '', 120); $ref = so_txt($in['ref_type'] ?? '', 30) ?: 'Tracking no.';
    if ($name === '') throw new RuntimeException('Give the company name.');
    $kind = in_array($in['kind'] ?? '', SO_CARRIER_KINDS, true) ? (string)$in['kind'] : 'Courier';
    $url = trim((string)($in['track_url'] ?? '')); $site = trim((string)($in['website'] ?? ''));
    foreach ([$url, $site] as $x) if ($x !== '' && !preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$#i', $x)) throw new RuntimeException('Addresses must start with https://');
    $v = [$code, $name, $kind, $ref, $site, $url, so_txt($in['phone'] ?? '', 40), so_txt($in['notes'] ?? '', 500), empty($in['off']) ? 1 : 0];
    $d = $db->prepare('SELECT id FROM crm_carrier WHERE code = ? AND id <> ?'); $d->execute([$code, $id]);
    if ($d->fetchColumn()) throw new RuntimeException('Another company has that code.');
    if ($id) $db->prepare('UPDATE crm_carrier SET code=?, name=?, kind=?, ref_type=?, website=?, track_url=?, phone=?, notes=?, active=? WHERE id=?')->execute([...$v, $id]);
    else $db->prepare('INSERT INTO crm_carrier (code, name, kind, ref_type, website, track_url, phone, notes, active) VALUES (?,?,?,?,?,?,?,?,?)')->execute($v);
    audit((int)$u['id'], 'crm_carrier_save', $code);
}

/* ---------------- stage, customer updates, links ---------------- */
function so_stage_sync(PDO $db, int $order, ?int $by): void {
    $o = so_row($db, $order);
    $s = $db->prepare('SELECT s.status, c.name FROM crm_shipment s JOIN crm_carrier c ON c.id = s.carrier_id WHERE s.order_id = ? ORDER BY s.seq'); $s->execute([$order]); $ships = $s->fetchAll();
    $st = so_stage_next((string)$o['stage'], so_stage_of($o, array_column($ships, 'status')));
    if ($st === '') return;
    $db->prepare('UPDATE crm_order SET stage = ? WHERE id = ?')->execute([$st, $order]);
    $extra = in_array($st, ['sent', 'on_way'], true) ? implode(', ', array_unique(array_column($ships, 'name'))) : '';
    so_event($db, $order, null, 'stage', $o['stage'] ?: null, SO_STAGES[$st] . ($extra !== '' ? ' · ' . $extra : ''), '', true);
    if ($st !== 'cancelled') so_notify_customer($db, $o, $st, $extra, $by);
}
function so_new_token(PDO $db, int $order, int $by, int $days): string {
    $t = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $db->prepare('INSERT INTO crm_track_token (order_id, token_hash, issued_by, expires_at) VALUES (?,?,?, NOW() + INTERVAL ? DAY)')->execute([$order, hash('sha256', $t, true), $by, $days]);
    return $t;
}
function so_track_url(string $token): string { return wn_site_url() . 'modules/crm/track.php?t=' . rawurlencode($token); }
/** Email the customer the new stage with a link of its own (only its hash is kept). Needs the Email module and the order's tick. */
function so_notify_customer(PDO $db, array $o, string $stage, string $extra, ?int $by): void {
    if (!(int)$o['notify_email'] || !module_on('email') || !function_exists('mail_queue')) return;
    $e = $db->prepare('SELECT email FROM crm_email WHERE customer_id = ? AND active = 1 ORDER BY is_primary DESC, id LIMIT 1'); $e->execute([(int)$o['customer_id']]);
    if (!$to = (string)$e->fetchColumn()) return;
    $link = so_track_url(so_new_token($db, (int)$o['id'], (int)($by ?: $o['created_by']), (int)crm_settings()['link_days']));
    try { mail_queue($db, ['to' => $to, 'subject' => org('name') . ' · order ' . $o['order_no'] . ' · ' . SO_STAGES[$stage], 'text' => so_stage_text(org('name'), $o['order_no'], $stage, $extra, $link)], 'notice', 'crm_order:' . $o['id'], $by); }
    catch (Throwable $x) { /* a bad address never stops the order */ }
}
function so_link_issue(PDO $db, array $u, int $order): string {
    $o = so_row($db, $order); so_need($u, $o, 'view');
    if (!so_can($u, $o) && !so_can($u, $o, 'dispatch')) throw new RuntimeException('Your role cannot do this on this order.');
    if (in_array($o['status'], ['Draft', 'Cancelled'], true)) throw new RuntimeException('No tracking link for a draft or cancelled order.');
    $t = so_new_token($db, $order, (int)$u['id'], (int)crm_settings()['link_days']);
    audit((int)$u['id'], 'crm_link_issue', '#' . $order);
    return $t;
}
function so_link_stop(PDO $db, array $u, int $order): void {
    $o = so_row($db, $order); so_need($u, $o);
    $db->prepare('UPDATE crm_track_token SET revoked_at = NOW(), revoked_by = ? WHERE order_id = ? AND revoked_at IS NULL')->execute([(int)$u['id'], $order]);
    audit((int)$u['id'], 'crm_link_stop', '#' . $order);
}
function so_public_throttle(PDO $db): bool {
    $h = substr(hash('sha256', 'crm|' . (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . seal_key()), 0, 16); $m = date('YmdHi');
    $db->prepare('INSERT INTO crm_track_hit (ip_hash, minute, n) VALUES (?,?,1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$h, $m]);
    if (random_int(1, 200) === 1) $db->prepare('DELETE FROM crm_track_hit WHERE minute < ?')->execute([date('YmdHi', time() - 3600)]);   // only the throttle counter is tidied
    $s = $db->prepare('SELECT n FROM crm_track_hit WHERE ip_hash = ? AND minute = ?'); $s->execute([$h, $m]);
    return (int)$s->fetchColumn() <= SO_PUBLIC_PER_MIN;
}
/** Public view by link: customer-safe fields only (never price, payment, internal remarks, staff). null = unknown / expired / stopped. */
function so_public_view(PDO $db, string $token): ?array {
    if (strlen($token) !== SO_TOKEN_LEN || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) return null;
    $s = $db->prepare('SELECT * FROM crm_track_token WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()'); $s->execute([hash('sha256', $token, true)]);
    if (!$t = $s->fetch()) return null;
    $db->prepare('UPDATE crm_track_token SET hits = hits + 1, last_hit = NOW() WHERE id = ?')->execute([$t['id']]);
    $o = so_row($db, (int)$t['order_id']); $set = crm_settings();
    $cu = $db->prepare('SELECT name FROM crm_customer WHERE id = ?'); $cu->execute([$o['customer_id']]);
    $l = $db->prepare('SELECT description, unit, qty, qty_dispatched FROM crm_order_line WHERE order_id = ? ORDER BY line_no'); $l->execute([$o['id']]);
    $ev = $db->prepare("SELECT at, to_v FROM crm_order_event WHERE order_id = ? AND public = 1 ORDER BY at, id"); $ev->execute([$o['id']]);
    $sh = $db->prepare('SELECT s.seq, s.ref, s.dispatch_date, s.status, s.delivered_at, c.name, c.track_url, c.kind FROM crm_shipment s JOIN crm_carrier c ON c.id = s.carrier_id WHERE s.order_id = ? ORDER BY s.seq'); $sh->execute([$o['id']]);
    return ['order' => $o['order_no'], 'customer' => (string)$cu->fetchColumn(), 'po' => $set['public_po'] && $o['po_number'] !== '' ? $o['po_number'] : null,
            'stage' => $o['stage'] ?: 'received', 'dispatch_date' => $o['confirmed_dispatch'] ?: $o['expected_dispatch'], 'confirmed' => $o['confirmed_dispatch'] !== null, 'remarks' => $o['customer_remarks'],
            'items' => array_map(fn($x) => ['description' => $x['description'], 'unit' => $x['unit'], 'ordered' => $set['public_qty'] ? (float)$x['qty'] : null, 'dispatched' => $set['public_qty'] ? (float)$x['qty_dispatched'] : null], $l->fetchAll()),
            'timeline' => $ev->fetchAll(),
            'shipments' => array_map(fn($x) => ['no' => (int)$x['seq'], 'carrier' => $x['name'], 'kind' => $x['kind'], 'ref' => $x['ref'], 'date' => $x['dispatch_date'], 'status' => $x['status'], 'delivered_at' => $x['delivered_at'], 'track' => so_carrier_link($x, $x['ref'])], $sh->fetchAll())];
}

/* ---------------- reads ---------------- */
function so_list(PDO $db, array $u, array $f = []): array {
    $w = ['1=1']; $p = [];
    if (($f['status'] ?? '') !== '') { if ($f['status'] === 'open') $w[] = "o.status NOT IN ('Delivered','Closed','Cancelled')"; else { $w[] = 'o.status = ?'; $p[] = $f['status']; } }
    if (($f['q'] ?? '') !== '') { $l = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($f['q'])) . '%'; $w[] = '(o.order_no LIKE ? OR o.po_number LIKE ? OR c.name LIKE ?)'; array_push($p, $l, $l, $l); }
    if (!empty($f['mine_dispatch'])) { $w[] = 'EXISTS (SELECT 1 FROM crm_dispatch_assign a WHERE a.order_id = o.id AND a.assignee = ? AND a.released_at IS NULL)'; $p[] = (int)$u['id']; }
    if (!empty($f['dispatch'])) $w[] = "o.status IN ('Ready for dispatch','Partly dispatched')";
    $s = $db->prepare('SELECT o.*, c.name AS customer, u.name AS salesperson, (SELECT COUNT(*) FROM crm_blocker b WHERE b.order_id = o.id AND b.resolved_at IS NULL) AS blockers,
                       (SELECT x.name FROM crm_dispatch_assign a JOIN users x ON x.id = a.assignee WHERE a.order_id = o.id AND a.released_at IS NULL LIMIT 1) AS assignee
                       FROM crm_order o JOIN crm_customer c ON c.id = o.customer_id LEFT JOIN users u ON u.id = o.salesperson_id WHERE ' . implode(' AND ', $w) . ' ORDER BY o.id DESC LIMIT ' . (int)($f['limit'] ?? 300));
    $s->execute($p);
    return array_values(array_filter($s->fetchAll(), fn($o) => so_can($u, $o, 'view')));
}
function so_get(PDO $db, int $id): array {
    $o = so_row($db, $id);
    foreach (['lines' => 'SELECT * FROM crm_order_line WHERE order_id = ? ORDER BY line_no', 'events' => 'SELECT e.*, u.name AS who FROM crm_order_event e LEFT JOIN users u ON u.id = e.by_user WHERE e.order_id = ? ORDER BY e.id DESC',
              'blockers' => 'SELECT b.*, u.name AS owner FROM crm_blocker b LEFT JOIN users u ON u.id = b.owner_user WHERE b.order_id = ? ORDER BY b.resolved_at IS NULL DESC, b.id DESC',
              'ships' => 'SELECT s.*, c.name AS carrier, c.track_url, c.ref_type FROM crm_shipment s JOIN crm_carrier c ON c.id = s.carrier_id WHERE s.order_id = ? ORDER BY s.seq',
              'assign' => 'SELECT a.*, u.name AS who FROM crm_dispatch_assign a JOIN users u ON u.id = a.assignee WHERE a.order_id = ? AND a.released_at IS NULL'] as $k => $q) {
        $s = $db->prepare($q); $s->execute([$id]); $o[$k] = $s->fetchAll();
    }
    foreach ($o['ships'] as &$x) { $t = $db->prepare('SELECT * FROM crm_track WHERE shipment_id = ? ORDER BY at DESC, id DESC'); $t->execute([$x['id']]); $x['track'] = $t->fetchAll(); } unset($x);
    $k = $db->prepare('SELECT COUNT(*) live, MAX(expires_at) till, SUM(hits) hits FROM crm_track_token WHERE order_id = ? AND revoked_at IS NULL AND expires_at > NOW()'); $k->execute([$id]); $o['links'] = $k->fetch();
    return $o;
}
/** Same-day score for the orders this login sees, punched from $from to $to (a year at most). */
function so_sameday(PDO $db, array $u, string $from, string $to): array {
    $from = so_date($from, 'From', true); $to = so_date($to, 'To', true);
    if ($to < $from) throw new RuntimeException('"To" is before "From".');
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) throw new RuntimeException('Up to one year at a time.');
    $s = $db->prepare("SELECT id, branch_id, salesperson_id, COALESCE(submitted_at, created_at) AS punched_at, first_dispatch_at FROM crm_order
                       WHERE status NOT IN ('Draft','Cancelled') AND COALESCE(submitted_at, created_at) BETWEEN ? AND ?");
    $s->execute([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 23:59:59']);
    $cut = (string)crm_settings()['same_day_cutoff'];
    $rows = array_values(array_filter($s->fetchAll(), fn($o) => so_can($u, $o, 'view') && ($d = so_punch_day((string)$o['punched_at'], $cut)) >= $from && $d <= $to));
    $bl = [];
    if ($rows) { $in = implode(',', array_map(fn($r) => (int)$r['id'], $rows)); foreach ($db->query("SELECT order_id, customer_side, created_at, resolved_at FROM crm_blocker WHERE order_id IN ($in)") as $b) $bl[(int)$b['order_id']][] = $b; }
    return so_sameday_calc($rows, $bl, $cut) + ['from' => $from, 'to' => $to, 'cutoff' => $cut];
}
