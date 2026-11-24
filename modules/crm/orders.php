<?php
// CRM orders — list, new / change, one order (status, blockers, dispatch, consignments, tracking, customer link),
// dispatch board, same-day dispatch score, couriers & transport.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/crm_orders.php';
$u = module_require('crm');
$db = pdo(); $set = crm_settings(); $R = crm_rights($u); $uid = (int)$u['id'];
$view = (string)($_GET['view'] ?? 'list'); $id = (int)($_GET['id'] ?? 0);
$lines = function (): array { $o = []; foreach ((array)($_POST['lp'] ?? []) as $i => $p) $o[] = ['product_id' => $p, 'qty' => $_POST['lq'][$i] ?? '', 'rate' => $_POST['lr'][$i] ?? '', 'discount_pct' => $_POST['ld'][$i] ?? '', 'tax_pct' => $_POST['lt'][$i] ?? '', 'description' => $_POST['lx'][$i] ?? '']; return $o; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $oid = (int)($_POST['id'] ?? 0); $to = 'orders.php?view=order&id=' . $oid;
    try {
        switch ($a) {
            case 'create': $oid = so_create($db, $u, $_POST + ['lines' => $lines()]); flash('Order saved.'); $to = 'orders.php?view=order&id=' . $oid; break;
            case 'edit': so_edit($db, $u, $oid, $_POST + ['lines' => $lines()], (int)$_POST['version']); flash('Order changed.'); break;
            case 'status': so_status($db, $u, $oid, (string)$_POST['to'], (string)($_POST['note'] ?? '')); flash('Order is now ' . $_POST['to'] . '.'); break;
            case 'date': so_confirm_date($db, $u, $oid, (string)($_POST['date'] ?? '')); flash('Dispatch date saved.'); break;
            case 'block': so_blocker_add($db, $u, $oid, (string)$_POST['type'], (int)$_POST['owner'], (string)$_POST['reason']); flash('Blocker added.'); break;
            case 'unblock': so_blocker_resolve($db, $u, (int)$_POST['bid'], (string)$_POST['note']); flash('Blocker cleared.'); break;
            case 'assign': so_assign($db, $u, $oid, (int)$_POST['to'], (string)($_POST['note'] ?? '')); flash('Given for dispatch.'); break;
            case 'accept': so_accept($db, $u, $oid); flash('Accepted.'); break;
            case 'packing': so_packing($db, $u, $oid); flash('Packing.'); break;
            case 'ship': so_ship_add($db, $u, $oid, $_POST); flash('Consignment saved.'); break;
            case 'track': so_track_add($db, $u, (int)$_POST['sid'], $_POST); flash('Tracking added.'); break;
            case 'link': $_SESSION['crm_link'] = [$oid, so_track_url(so_link_issue($db, $u, $oid))]; flash('New tracking link made — copy it now; it is shown once.'); break;
            case 'walink':
                $o = so_row($db, $oid); $t = so_track_url(so_link_issue($db, $u, $oid));
                $n = (string)$db->query('SELECT number FROM crm_phone WHERE customer_id = ' . (int)$o['customer_id'] . ' AND active = 1 AND whatsapp = 1 ORDER BY is_primary DESC LIMIT 1')->fetchColumn();
                if ($n === '') throw new RuntimeException('The customer has no WhatsApp number.');
                redirect('https://wa.me/' . $n . '?text=' . rawurlencode(so_stage_text(org('name'), $o['order_no'], $o['stage'] ?: 'received', '', $t)));
            case 'stoplinks': so_link_stop($db, $u, $oid); flash('Every tracking link of this order is stopped.'); break;
            case 'carrier': so_carrier_save($db, $u, (int)($_POST['cid'] ?? 0), $_POST); flash('Saved.'); $to = 'orders.php?view=carriers'; break;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        if (in_array($a, ['create', 'edit'], true)) { $_SESSION['so_form'] = $_POST; $to = $a === 'create' ? 'orders.php?view=new&cust=' . (int)($_POST['customer_id'] ?? 0) : 'orders.php?view=edit&id=' . $oid; }
        if ($a === 'carrier') $to = 'orders.php?view=carriers';
    }
    redirect($to);
}

if ($view === 'csv' && $R['all']) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="orders.csv"');
    $q = fn($v) => '"' . str_replace('"', '""', (in_array(substr((string)$v, 0, 1), ['=', '+', '-', '@'], true) ? "'" : '') . (string)$v) . '"';
    echo "\xEF\xBB\xBF" . implode(',', array_map($q, ['Order', 'Customer', 'PO', 'Source', 'Received', 'Status', 'Dispatch', 'Value before tax', 'Total', 'Salesperson'])) . "\r\n";
    foreach (so_list($db, $u, ['limit' => 100000]) as $o) echo implode(',', array_map($q, [$o['order_no'], $o['customer'], $o['po_number'], $o['source'], $o['received_at'], $o['status'], $o['dispatch_status'], $o['order_value'], $o['order_total'], $o['salesperson']])) . "\r\n";
    audit($uid, 'crm_export', 'orders'); exit;
}

$money = fn($v) => number_format((float)$v, 2);
$qty = fn($v) => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
$sel = fn(array $o, string $n, string $c = '') => '<select name="' . $n . '">' . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o)) . '</select>';
$hid = fn(string $a, int $id) => csrf_field() . '<input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="id" value="' . $id . '">';
$both = fn(array $x) => array_combine($x, $x);
$products = $db->query('SELECT id, sku, name, unit, rate, tax_pct FROM crm_product WHERE active = 1 AND merged_into IS NULL ORDER BY name')->fetchAll();
$people = $db->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$orderForm = function (array $o, array $cu) use ($sel, $hid, $both, $set, $products, $people, $R, $u): void {
    $isNew = empty($o['id']); $ls = $o['lines'] ?? []; ?>
<form method="post" class="card"><?= $hid($isNew ? 'create' : 'edit', (int)($o['id'] ?? 0)) ?><input type="hidden" name="customer_id" value="<?= (int)$cu['id'] ?>"><input type="hidden" name="version" value="<?= (int)($o['version'] ?? 1) ?>">
<input type="hidden" name="request_id" value="<?= h((string)($o['request_id'] ?? so_uuid(''))) ?>">
<h2><?= $isNew ? 'New order' : 'Change ' . h($o['order_no']) ?> · <?= h($cu['name']) ?></h2>
<div class="grid"><label>Came from <?= $sel($both(crm_lines($set['order_sources'])), 'source', (string)($o['source'] ?? '')) ?></label><label>Reference (message / mail / portal no.) <input name="source_ref" value="<?= h((string)($o['source_ref'] ?? '')) ?>"></label>
<label>Received <input type="datetime-local" name="received_at" value="<?= h(str_replace(' ', 'T', substr((string)($o['received_at'] ?? date('Y-m-d H:i')), 0, 16))) ?>"></label>
<label>PO number <input name="po_number" value="<?= h((string)($o['po_number'] ?? '')) ?>"></label><label>PO date <input type="date" name="po_date" value="<?= h((string)($o['po_date'] ?? '')) ?>"></label>
<label>Contact <?= $sel(['' => '—'] + array_column($cu['contacts'], 'name', 'id'), 'contact_id', (string)($o['contact_id'] ?? '')) ?></label>
<?php if ($R['all']): ?><label>Salesperson <?= $sel($people, 'salesperson_id', (string)($o['salesperson_id'] ?? $u['id'])) ?></label><?php endif; ?>
<label>Advance <?= $sel($both(SO_ADVANCE), 'advance_status', (string)($o['advance_status'] ?? 'Not required')) ?></label><label>Advance amount <input name="advance_amount" value="<?= h((string)($o['advance_amount'] ?? '')) ?>"></label>
<label>Payment due <input type="date" name="payment_due" value="<?= h((string)($o['payment_due'] ?? '')) ?>"></label><label>Expected dispatch <input type="date" name="expected_dispatch" value="<?= h((string)($o['expected_dispatch'] ?? '')) ?>"></label></div>
<label>Deliver to <?= $sel(['' => 'Type below'] + array_map(fn($a) => implode(', ', array_filter([$a['label'], $a['line1'], $a['city']])), array_column($cu['addresses'], null, 'id')), 'ship_address_id', (string)($o['ship_address_id'] ?? ($cu['addresses'][0]['id'] ?? ''))) ?></label>
<div class="grid"><input name="delivery_address" value="<?= h((string)($o['delivery_address'] ?? '')) ?>" placeholder="Delivery address (blank = the address picked)"><input name="delivery_postcode" value="<?= h((string)($o['delivery_postcode'] ?? '')) ?>" placeholder="Postcode"></div>
<h3>Products</h3><table><tr><th>Product</th><th>Qty</th><th>Rate <span class="muted">(blank = list rate)</span></th><th>Disc %</th><th>Tax % <span class="muted">(blank = product's)</span></th><th>Description</th></tr>
<?php for ($i = 0; $i < max(3, count($ls) + 2); $i++): $l = $ls[$i] ?? []; ?><tr><td><select name="lp[<?= $i ?>]"><option value="">—</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)($l['product_id'] ?? 0) === (int)$p['id'] ? ' selected' : '' ?>><?= h($p['name'] . ' · ' . $p['sku'] . ($p['rate'] !== null ? ' · ' . $p['rate'] : '')) ?></option><?php endforeach; ?></select></td>
<td><input name="lq[<?= $i ?>]" value="<?= h((string)($l['qty'] ?? '')) ?>" size="6"></td><td><input name="lr[<?= $i ?>]" value="<?= h((string)($l['rate'] ?? '')) ?>" size="8"></td><td><input name="ld[<?= $i ?>]" value="<?= h((string)($l['discount_pct'] ?? '')) ?>" size="4"></td><td><input name="lt[<?= $i ?>]" value="<?= h((string)($l['tax_pct'] ?? '')) ?>" size="4"></td><td><input name="lx[<?= $i ?>]" value="<?= h((string)($l['description'] ?? '')) ?>"></td></tr><?php endfor; ?></table>
<div class="grid"><label>What the customer needs <textarea name="requirements" rows="2"><?= h((string)($o['requirements'] ?? '')) ?></textarea></label><label>Internal remarks <textarea name="internal_remarks" rows="2"><?= h((string)($o['internal_remarks'] ?? '')) ?></textarea></label>
<label>Remarks the customer sees <textarea name="customer_remarks" rows="2"><?= h((string)($o['customer_remarks'] ?? '')) ?></textarea></label></div>
<label><input type="checkbox" name="notify_email" value="1"<?= ($o['notify_email'] ?? $set['notify_default']) ? ' checked' : '' ?>> Email the customer at each stage (needs the Email module)</label>
<?php if ($isNew): ?><label><input type="checkbox" name="submit" value="1" checked> Submit now (the same-day clock starts)</label><?php endif; ?><br><button class="btn">Save</button></form>
<?php };

if ($view === 'order' || $view === 'edit') {
    try { $o = so_get($db, $id); } catch (Throwable $e) { $o = null; }
    if (!$o || !so_can($u, $o, 'view')) { http_response_code(404); ui_head('Orders', $u, 'crm'); echo '<div class="card">Not found.</div>'; ui_foot(); exit; }
}
ui_head('Orders', $u, 'crm'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm ghost" href="index.php">Customers</a> <a class="btn sm<?= $view === 'list' ? '' : ' ghost' ?>" href="orders.php">Orders</a>
<?php if ($R['dispatch'] || $R['all']): ?> <a class="btn sm<?= $view === 'board' ? '' : ' ghost' ?>" href="?view=board">Dispatch board</a><?php endif; ?>
 <a class="btn sm<?= $view === 'sameday' ? '' : ' ghost' ?>" href="?view=sameday">Same-day dispatch</a>
<?php if ($R['admin']): ?> <a class="btn sm<?= $view === 'carriers' ? '' : ' ghost' ?>" href="?view=carriers">Couriers & transport</a><?php endif; ?></nav>

<?php if ($view === 'list'): $st = (string)($_GET['st'] ?? 'open'); $q = (string)($_GET['q'] ?? ''); $rows = so_list($db, $u, ['status' => $st === 'all' ? '' : $st, 'q' => $q]); ?>
<form class="card row" style="flex-wrap:wrap"><input name="q" value="<?= h($q) ?>" placeholder="Order, PO or customer"><?= $sel(['open' => 'Open', 'all' => 'All'] + $both(SO_STATUSES), 'st', $st) ?><button class="btn sm">Find</button>
<?php if ($R['all']): ?> <a class="btn sm ghost" href="?view=csv">Download CSV</a><?php endif; ?><?php if ($R['edit']): ?> <span class="muted">New order: open the customer and pick “New order”.</span><?php endif; ?></form>
<div class="card"><table><tr><th>Order</th><th>Customer</th><th>Received</th><th>Status</th><th>Dispatch</th><th>Value</th></tr>
<?php foreach ($rows as $o): ?><tr><td><a href="?view=order&id=<?= (int)$o['id'] ?>"><?= h($o['order_no']) ?></a><?= (int)$o['blockers'] ? ' <span class="muted">· ' . (int)$o['blockers'] . ' blocked</span>' : '' ?></td><td><?= h($o['customer']) ?></td><td><?= h(substr($o['received_at'], 0, 16)) ?></td><td><?= h($o['status']) ?></td><td><?= h($o['dispatch_status']) ?><?= $o['assignee'] ? ' · ' . h($o['assignee']) : '' ?></td><td><?= $money($o['order_value']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No orders.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'new' && $R['edit']): $cu = crm_customer_get($db, (int)($_GET['cust'] ?? 0));
    if (!$cu || $cu['status'] !== 'active' || !crm_can($u, $cu)): ?><div class="card">Pick an active customer first (open the customer, then “New order”).</div><?php else:
    $f = $_SESSION['so_form'] ?? []; unset($_SESSION['so_form']);
    $o = $f ? $f + ['lines' => array_map(fn($p, $i) => ['product_id' => $p, 'qty' => $f['lq'][$i] ?? '', 'rate' => $f['lr'][$i] ?? '', 'discount_pct' => $f['ld'][$i] ?? '', 'tax_pct' => $f['lt'][$i] ?? '', 'description' => $f['lx'][$i] ?? ''], (array)($f['lp'] ?? []), array_keys((array)($f['lp'] ?? [])))] : [];
    $orderForm($o, $cu); endif;

elseif ($view === 'edit' && so_can($u, $o) && in_array($o['status'], ['Draft', 'Submitted'], true)): $orderForm($o, crm_customer_get($db, (int)$o['customer_id']));

elseif ($view === 'order' || $view === 'edit'):
    $cu = crm_customer_get($db, (int)$o['customer_id']); $can = so_can($u, $o); $disp = so_can($u, $o, 'dispatch'); $mine = $o['assign'] && (int)$o['assign'][0]['assignee'] === $uid;
    $link = ($_SESSION['crm_link'][0] ?? 0) === (int)$o['id'] ? $_SESSION['crm_link'][1] : ''; unset($_SESSION['crm_link']);
    $bt = $both(array_merge(crm_lines($set['internal_blockers']), crm_lines($set['customer_blockers']))); ?>
<div class="card"><div class="row" style="justify-content:space-between"><h2><?= h($o['order_no']) ?> · <a href="index.php?view=cust&id=<?= (int)$cu['id'] ?>"><?= h($cu['name']) ?></a></h2>
<?php if ($can && in_array($o['status'], ['Draft', 'Submitted'], true)): ?><a class="btn sm" href="?view=edit&id=<?= (int)$o['id'] ?>">Change</a><?php endif; ?></div>
<p><b><?= h($o['status']) ?></b> · dispatch: <?= h($o['dispatch_status']) ?><?= $o['assign'] ? ' (' . h($o['assign'][0]['who']) . ($o['assign'][0]['accepted_at'] ? ', accepted' : '') . ')' : '' ?> · customer sees: <?= h(SO_STAGES[$o['stage']] ?? '—') ?></p>
<p class="muted"><?= h(implode(' · ', array_filter(['From ' . $o['source'] . ($o['source_ref'] !== '' ? ' ' . $o['source_ref'] : ''), 'received ' . substr($o['received_at'], 0, 16), $o['po_number'] !== '' ? 'PO ' . $o['po_number'] . ($o['po_date'] ? ' of ' . $o['po_date'] : '') : '', 'advance ' . $o['advance_status'], $o['expected_dispatch'] ? 'expected ' . $o['expected_dispatch'] : '', $o['confirmed_dispatch'] ? 'confirmed ' . $o['confirmed_dispatch'] : '']))) ?></p>
<p>Deliver to: <?= h($o['delivery_address']) ?> <?= h($o['delivery_postcode']) ?></p>
<table><tr><th>#</th><th>Product</th><th>Qty</th><th>Sent</th><th>Rate</th><th>Disc</th><th>Tax</th><th>Value</th></tr><?php foreach ($o['lines'] as $l): ?><tr><td><?= (int)$l['line_no'] ?></td><td><?= h($l['description']) ?></td><td><?= $qty($l['qty']) ?> <?= h($l['unit']) ?></td><td><?= $qty($l['qty_dispatched']) ?></td><td><?= $money($l['rate']) ?></td><td><?= (float)$l['discount_pct'] ?>%</td><td><?= (float)$l['tax_pct'] ?>%</td><td><?= $money($l['line_value']) ?></td></tr><?php endforeach; ?>
<tr><td colspan="7" style="text-align:right">Before tax / with tax</td><td><?= $money($o['order_value']) ?> / <?= $money($o['order_total']) ?></td></tr></table>
<?php foreach (['requirements' => 'Needs', 'internal_remarks' => 'Internal', 'customer_remarks' => 'Customer sees'] as $k => $l) if ($o[$k] !== ''): ?><p><b><?= $l ?>:</b> <?= h($o[$k]) ?></p><?php endif; ?></div>

<?php if ($can && SO_MOVES[$o['status']]): ?><form method="post" class="card row" style="flex-wrap:wrap"><?= $hid('status', (int)$o['id']) ?><?= $sel($both(SO_MOVES[$o['status']]), 'to') ?><input name="note" placeholder="Note (needed to cancel)"><button class="btn sm">Move</button></form><?php endif; ?>
<?php if ($can && !in_array($o['status'], ['Draft', 'Delivered', 'Closed', 'Cancelled'], true)): ?><form method="post" class="card row"><?= $hid('date', (int)$o['id']) ?>Dispatch date agreed with the customer <input type="date" name="date" value="<?= h((string)$o['confirmed_dispatch']) ?>"><button class="btn sm ghost">Save</button></form><?php endif; ?>

<div class="card"><h3>Readiness blockers</h3><?php foreach ($o['blockers'] as $b): ?><div><b><?= h($b['blocker']) ?></b><?= (int)$b['customer_side'] ? ' <span class="muted">(customer side)</span>' : '' ?> — <?= h($b['reason']) ?> · <?= h((string)$b['owner']) ?>
<?php if ($b['resolved_at']): ?> <span class="muted">cleared <?= h(substr($b['resolved_at'], 0, 16)) ?>: <?= h($b['resolution']) ?></span><?php elseif ($can || (int)$b['owner_user'] === $uid): ?><form method="post" class="row" style="display:inline-flex"><?= $hid('unblock', (int)$o['id']) ?><input type="hidden" name="bid" value="<?= (int)$b['id'] ?>"><input name="note" placeholder="How it was cleared" required><button class="btn sm ghost">Clear</button></form><?php endif; ?></div><?php endforeach; ?>
<?php if ($can && $bt && in_array($o['status'], ['Submitted', 'In progress', 'Ready for dispatch', 'Partly dispatched'], true)): ?><form method="post" class="row" style="flex-wrap:wrap"><?= $hid('block', (int)$o['id']) ?><?= $sel($bt, 'type') ?><?= $sel($people, 'owner', (string)$uid) ?><input name="reason" placeholder="What is holding it" required><button class="btn sm ghost">Add blocker</button></form><?php endif; ?></div>

<?php if (in_array($o['status'], ['Ready for dispatch', 'Partly dispatched', 'Dispatched', 'Delivered', 'Closed'], true) || $o['ships']): ?><div class="card"><h3>Dispatch</h3>
<?php if ($disp && in_array($o['status'], ['Ready for dispatch', 'Partly dispatched'], true)): ?><form method="post" class="row"><?= $hid('assign', (int)$o['id']) ?><?= $sel(array_column(so_dispatch_people($db, $o), 'name', 'id'), 'to') ?><input name="note" placeholder="Note"><button class="btn sm ghost">Give for dispatch</button></form>
<?php if ($mine && !$o['assign'][0]['accepted_at']): ?><form method="post"><?= $hid('accept', (int)$o['id']) ?><button class="btn sm">Accept</button></form><?php endif; ?>
<?php if (!in_array($o['dispatch_status'], ['Packing', 'Partly dispatched'], true)): ?><form method="post"><?= $hid('packing', (int)$o['id']) ?><button class="btn sm ghost">Packing started</button></form><?php endif; ?>
<form method="post"><?= $hid('ship', (int)$o['id']) ?><input type="hidden" name="request_id" value="<?= h(so_uuid('')) ?>"><h3>New consignment</h3>
<div class="grid"><label>Courier / transport <?= $sel(array_column(so_carriers($db), 'name', 'id'), 'carrier_id') ?></label><label>Number (AWB / LR …) <input name="ref" required></label><label>Booked <input type="date" name="booking_date" value="<?= date('Y-m-d') ?>"></label><label>Dispatched <input type="date" name="dispatch_date" value="<?= date('Y-m-d') ?>"></label>
<label>Vehicle <input name="vehicle_no"></label><label>E-way bill / permit <input name="transport_doc"></label><label>Packages <input name="packages" size="4"></label><label>Weight kg <input name="weight_kg" size="6"></label></div>
<table><tr><th>Product</th><th>Left</th><th>In this consignment</th></tr><?php foreach ($o['lines'] as $l): $left = (float)$l['qty'] - (float)$l['qty_dispatched']; if ($left <= 0) continue; ?><tr><td><?= h($l['description']) ?></td><td><?= $qty($left) ?></td><td><input name="qty[<?= (int)$l['id'] ?>]" value="<?= $qty($left) ?>" size="6"></td></tr><?php endforeach; ?></table>
<input name="remarks" placeholder="Remarks"><button class="btn sm">Save consignment</button></form><?php endif; ?>
<?php foreach ($o['ships'] as $s): $tl = so_carrier_link($s, $s['ref']); ?><div class="card"><b>#<?= (int)$s['seq'] ?> <?= h($s['carrier']) ?> <?= h($s['ref']) ?></b> · <?= h($s['dispatch_date']) ?> · <?= h($s['status']) ?><?= $tl ? ' · <a href="' . h($tl['url']) . '" rel="noopener" target="_blank">Track</a>' : '' ?>
<?php foreach ($s['track'] as $t): ?><div class="muted"><?= h(substr($t['at'], 0, 16)) ?> · <?= h($t['status']) ?><?= $t['location'] !== '' ? ' · ' . h($t['location']) : '' ?><?= $t['note'] !== '' ? ' — ' . h($t['note']) : '' ?></div><?php endforeach; ?>
<?php if ($disp && !in_array($s['status'], ['Delivered', 'Cancelled'], true)): ?><form method="post" class="row" style="flex-wrap:wrap"><?= $hid('track', (int)$o['id']) ?><input type="hidden" name="sid" value="<?= (int)$s['id'] ?>"><?= $sel($both(SO_SHIP_STATUS), 'status', 'In transit') ?><input type="datetime-local" name="at" value="<?= date('Y-m-d\TH:i') ?>"><input name="location" placeholder="Where"><input name="pod_ref" placeholder="Proof of delivery no."><button class="btn sm ghost">Add update</button></form><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if (!in_array($o['status'], ['Draft', 'Cancelled'], true) && ($can || $disp)): ?><div class="card"><h3>Customer tracking link</h3>
<p class="muted"><?= (int)$o['links']['live'] ? (int)$o['links']['live'] . ' link(s) open till ' . h(substr((string)$o['links']['till'], 0, 10)) . ', opened ' . (int)$o['links']['hits'] . ' times.' : 'No open link.' ?> The page shows the stage, items and consignments — never prices or internal remarks.</p>
<?php if ($link !== ''): ?><p><input readonly value="<?= h($link) ?>" style="width:100%" onclick="this.select()"></p><?php endif; ?>
<div class="row"><form method="post"><?= $hid('link', (int)$o['id']) ?><button class="btn sm ghost">New link</button></form><form method="post"><?= $hid('walink', (int)$o['id']) ?><button class="btn sm ghost">Send on WhatsApp</button></form><?php if ($can): ?><form method="post"><?= $hid('stoplinks', (int)$o['id']) ?><button class="btn sm ghost">Stop every link</button></form><?php endif; ?></div></div><?php endif; ?>
<details class="card"><summary>History</summary><?php foreach ($o['events'] as $e): ?><div class="muted"><?= h(substr($e['at'], 0, 16)) ?> · <?= h((string)($e['who'] ?? 'system')) ?> · <?= h($e['kind']) ?>: <?= h(trim(($e['from_v'] ?? '') . ' → ' . ($e['to_v'] ?? ''), ' →')) ?><?= $e['note'] !== '' ? ' — ' . h($e['note']) : '' ?></div><?php endforeach; ?></details>

<?php elseif ($view === 'board' && ($R['dispatch'] || $R['all'])): $mineOnly = !empty($_GET['mine']); $rows = so_list($db, $u, ['dispatch' => 1, 'mine_dispatch' => $mineOnly]); ?>
<div class="card"><div class="row"><h2>Ready to dispatch</h2> <a class="btn sm ghost" href="?view=board<?= $mineOnly ? '' : '&mine=1' ?>"><?= $mineOnly ? 'Everyone\'s' : 'Given to me' ?></a></div>
<table><tr><th>Order</th><th>Customer</th><th>Punched</th><th>Dispatch</th><th>Agreed date</th></tr><?php foreach ($rows as $o): ?><tr><td><a href="?view=order&id=<?= (int)$o['id'] ?>"><?= h($o['order_no']) ?></a></td><td><?= h($o['customer']) ?></td><td><?= h(substr((string)($o['submitted_at'] ?? $o['created_at']), 0, 16)) ?></td><td><?= h($o['dispatch_status']) ?><?= $o['assignee'] ? ' · ' . h($o['assignee']) : '' ?></td><td><?= h((string)($o['confirmed_dispatch'] ?? $o['expected_dispatch'] ?? '')) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="muted">Nothing waiting.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'sameday'): $from = (string)($_GET['from'] ?? date('Y-m-01')); $to = (string)($_GET['to'] ?? date('Y-m-d'));
    try { $sd = so_sameday($db, $u, $from, $to); } catch (RuntimeException $e) { $sd = null; flash($e->getMessage(), 'err'); } ?>
<form class="card row"><input type="hidden" name="view" value="sameday">From <input type="date" name="from" value="<?= h($from) ?>" aria-label="From"> to <input type="date" name="to" value="<?= h($to) ?>" aria-label="To"><button class="btn sm">Show</button></form>
<?php if ($sd): $t = $sd['total']; ?><div class="card"><h2>Same-day dispatch: <?= $t['score'] === null ? '—' : $t['score'] . '%' ?></h2>
<p><?= $t['punched'] ?> orders punched · <?= $t['same_day'] ?> dispatched the same day · <?= $t['next_day'] ?> the next day · <?= $t['customer_hold'] ?> held by the customer (left out) · <?= $t['internal_hold'] ?> held by us · <?= $t['late'] ?> late<?= $t['median_min'] !== null ? ' · median ' . $t['median_min'] . ' min to dispatch' : '' ?></p>
<p class="muted">An order counts for the day it was submitted<?= $sd['cutoff'] !== '' ? ' (after ' . h($sd['cutoff']) . ' it counts for the next day)' : '' ?>; it is same-day when its first consignment left that day.</p>
<table><tr><th>Day</th><th>Punched</th><th>Same day</th><th>Customer hold</th><th>Our hold</th><th>Late</th><th>Score</th></tr><?php foreach ($sd['days'] as $d): ?><tr><td><?= h($d['day']) ?></td><td><?= $d['punched'] ?></td><td><?= $d['same_day'] ?></td><td><?= $d['customer_hold'] ?></td><td><?= $d['internal_hold'] ?></td><td><?= $d['late'] ?></td><td><?= $d['score'] === null ? '—' : $d['score'] . '%' ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<?php elseif ($view === 'carriers' && $R['admin']): $cs = so_carriers($db, false); $ec = null; foreach ($cs as $c) if ((int)$c['id'] === (int)($_GET['cid'] ?? 0)) $ec = $c; $ec ??= ['id' => 0, 'code' => '', 'name' => '', 'kind' => 'Courier', 'ref_type' => 'Tracking no.', 'website' => '', 'track_url' => '', 'phone' => '', 'notes' => '', 'active' => 1]; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="carrier"><input type="hidden" name="cid" value="<?= (int)$ec['id'] ?>"><h2><?= $ec['id'] ? 'Change ' . h($ec['name']) : 'Add a courier or transport company' ?></h2>
<div class="grid"><label>Code <input name="code" value="<?= h($ec['code']) ?>" required></label><label>Name <input name="name" value="<?= h($ec['name']) ?>" required></label><label>Kind <?= $sel($both(SO_CARRIER_KINDS), 'kind', $ec['kind']) ?></label><label>Their number is called <input name="ref_type" value="<?= h($ec['ref_type']) ?>"></label>
<label>Website <input name="website" value="<?= h($ec['website']) ?>" placeholder="https://…"></label><label>Tracking page <input name="track_url" value="<?= h($ec['track_url']) ?>" placeholder="https://…?no={ref}"></label><label>Phone <input name="phone" value="<?= h($ec['phone']) ?>"></label><label>Notes <input name="notes" value="<?= h($ec['notes']) ?>"></label></div>
<p class="muted">{ref} in the tracking page is replaced with the consignment number; without it the customer copies the number. No address is filled in for you.</p>
<?php if ($ec['id']): ?><label><input type="checkbox" name="off" value="1"<?= (int)$ec['active'] ? '' : ' checked' ?>> Not in use</label><?php endif; ?><button class="btn">Save</button></form>
<div class="card"><table><tr><th>Code</th><th>Name</th><th>Kind</th><th>Tracking</th><th></th></tr><?php foreach ($cs as $c): ?><tr><td><?= h($c['code']) ?></td><td><?= h($c['name']) ?><?= (int)$c['active'] ? '' : ' <span class="muted">(not in use)</span>' ?></td><td><?= h($c['kind']) ?></td><td><?= $c['track_url'] !== '' ? 'Their page' : 'We enter updates' ?></td><td><a class="btn sm ghost" href="?view=carriers&cid=<?= (int)$c['id'] ?>">Change</a></td></tr><?php endforeach; ?>
<?php if (!$cs): ?><tr><td colspan="5" class="muted">None yet — add the companies you send with.</td></tr><?php endif; ?></table></div>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
