<?php
// Purchase & procurement — waiting for you, requisitions (with the manager step), vendors (register → approve),
// price comparisons (QCD score), purchase / job-work orders (approver → CEO at or above the limit → issue), settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/purchase.php';
$u = module_require('purchase');
$db = pdo(); $R = pur_rights($u); $S = pur_settings(); $uid = (int)$u['id']; $brs = pur_branches($u);
$view = (string)($_GET['view'] ?? 'wait'); $id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $pid = (int)($_POST['id'] ?? 0); $to = 'index.php';
    try {
        switch ($a) {
            case 'req': $lines = []; foreach ((array)($_POST['li'] ?? []) as $i => $it) $lines[] = ['item' => $it, 'item_id' => $_POST['lid'][$i] ?? 0, 'qty' => $_POST['lq'][$i] ?? '', 'uom' => $_POST['lu'][$i] ?? '', 'est' => $_POST['le'][$i] ?? ''];
                $nid = pur_req_add($db, $u, $_POST + ['lines' => $lines]); $q = pur_req_get($db, $nid); flash($q['no'] . ($q['status'] === 'hod' ? ' sent to ' . ($q['hod_name'] ?: 'an approver') . ' for approval.' : ' sent to purchase.')); $to = '?view=req&id=' . $nid; break;
            case 'reqdecide': pur_req_decide($db, $u, $pid, (string)$_POST['act'], (string)($_POST['note'] ?? '')); flash('Saved.'); $to = '?view=req&id=' . $pid; break;
            case 'reqcancel': pur_req_cancel($db, $u, $pid, (string)($_POST['why'] ?? '')); flash('Cancelled.'); $to = '?view=req&id=' . $pid; break;
            case 'vendor': $nid = pur_vendor_save($db, $u, $pid, $_POST); flash('Vendor saved.'); $to = '?view=vendor&id=' . $nid; break;
            case 'vsubmit': pur_vendor_submit($db, $u, $pid); flash('Sent for approval.'); $to = '?view=vendor&id=' . $pid; break;
            case 'vdecide': pur_vendor_decide($db, $u, $pid, (string)$_POST['act'], (string)($_POST['note'] ?? '')); flash('Saved.'); $to = '?view=vendor&id=' . $pid; break;
            case 'vbank': $b = pur_vendor_bank($db, $u, $pid); $_SESSION['pur_bank'] = [$pid, $b]; $to = '?view=vendor&id=' . $pid; break;
            case 'cmp': $nid = pur_cmp_create($db, $u, $_POST); flash('Comparison started — add the quotes.'); $to = '?view=cmp&id=' . $nid; break;
            case 'quote': pur_quote_add($db, $u, $pid, $_POST); flash('Quote added.'); $to = '?view=cmp&id=' . $pid; break;
            case 'quoteoff': pur_quote_off($db, $u, (int)$_POST['quote']); flash('Quote taken off.'); $to = '?view=cmp&id=' . $pid; break;
            case 'choose': pur_cmp_choose($db, $u, $pid, (int)($_POST['quote'] ?? 0), (string)($_POST['reason'] ?? '')); flash('Comparison finished.'); $to = '?view=cmp&id=' . $pid; break;
            case 'ordnew': $nid = pur_ord_create($db, $u, (string)($_POST['kind'] ?? 'po'), (int)($_POST['branch_id'] ?? 0), (array)($_POST['lines'] ?? [])); flash('Draft order made.'); $to = '?view=order&id=' . $nid; break;
            case 'ordsave': $lines = []; foreach ((array)($_POST['li'] ?? []) as $i => $it) $lines[] = ['line_no' => $_POST['ln'][$i] ?? 0, 'item' => $it, 'uom' => $_POST['lu'][$i] ?? '', 'qty' => $_POST['lq'][$i] ?? '', 'rate' => $_POST['lr'][$i] ?? '', 'tax_pct' => $_POST['lt'][$i] ?? '', 'deliv_date' => $_POST['ld'][$i] ?? ''];
                pur_ord_save($db, $u, $pid, $_POST + ['lines' => $lines]); flash('Order saved.'); $to = '?view=order&id=' . $pid; break;
            case 'ordsend': pur_ord_submit($db, $u, $pid); flash('Sent for approval.'); $to = '?view=order&id=' . $pid; break;
            case 'orddecide': pur_ord_decide($db, $u, $pid, (string)$_POST['act'], (string)($_POST['note'] ?? '')); flash('Saved.'); $to = '?view=order&id=' . $pid; break;
            case 'ordissue': $no = pur_ord_issue($db, $u, $pid); flash('Issued as ' . $no . '.'); $to = '?view=order&id=' . $pid; break;
            case 'ordcancel': pur_ord_cancel($db, $u, $pid, (string)($_POST['why'] ?? '')); flash('Cancelled.'); $to = '?view=order&id=' . $pid; break;
            case 'settings':
                if (!$R['admin']) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = pur_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['purchase'] ?? ['roles' => module_roles('purchase')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['purchase', 1, implode(',', $st['roles'] ?: module_roles('purchase')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'purchase'); flash('Settings saved.'); $to = '?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        $to = $pid ? '?view=' . ['reqdecide' => 'req', 'reqcancel' => 'req', 'vendor' => 'vendor', 'vsubmit' => 'vendor', 'vdecide' => 'vendor', 'vbank' => 'vendor', 'quote' => 'cmp', 'quoteoff' => 'cmp', 'choose' => 'cmp'][$a] . '&id=' . $pid : '?view=' . (['req' => 'newreq', 'vendor' => 'vendor', 'cmp' => 'cmps', 'ordnew' => 'reqs', 'settings' => 'settings'][$a] ?? 'wait');
        if (str_starts_with($a, 'ord') && $pid) $to = '?view=order&id=' . $pid;
        if (in_array($a, ['req', 'vendor'], true)) $_SESSION['pur_form'] = $_POST;
    }
    redirect('index.php' . $to);
}

$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$m = fn($v) => $v === null || $v === '' ? '' : number_format((float)$v, 2); $q3 = fn($v) => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
$f = $_SESSION['pur_form'] ?? []; unset($_SESSION['pur_form']);
$hid = fn(string $a, int $i = 0) => csrf_field() . '<input type="hidden" name="a" value="' . $a . '">' . ($i ? '<input type="hidden" name="id" value="' . $i . '">' : '');
$bname = fn(int $b) => (string)(array_column(branches(false), 'name', 'id')[$b] ?? '');
$tab = fn(string $v, string $l) => ' <a class="btn sm' . ($view === $v ? '' : ' ghost') . '" href="?view=' . $v . '">' . h($l) . '</a>';
$W = pur_waiting($db, $u); $nw = count($W['reqs']) + count($W['orders']) + count($W['vendors']);
ui_head('Purchase', $u, 'purchase'); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('wait', 'Waiting for you' . ($nw ? ' · ' . $nw : '')) . $tab('reqs', 'Requisitions') . $tab('newreq', 'New requisition') ?><?php if ($R['see']): ?><?= $tab('vendors', 'Vendors') . $tab('cmps', 'Comparisons') . $tab('orders', 'Orders') ?><?php endif; ?><?php if ($R['admin']): ?><?= $tab('settings', 'Settings') ?><?php endif; ?></nav>
<?php if ((float)$S['limit_value'] === 25000.0 && $R['admin']): ?><div class="card muted">The CEO approval limit is still the placeholder (25,000). Set your own on Settings.</div><?php endif; ?>

<?php if ($view === 'wait'): ?>
<div class="card"><h2>Requisitions to approve</h2><?php if (!$W['reqs']): ?><p class="muted">None.</p><?php endif; ?><?php foreach ($W['reqs'] as $q): ?><p><a href="?view=req&id=<?= (int)$q['id'] ?>"><?= h($q['no']) ?></a> · <?= h($q['by_name']) ?> · <?= count($q['lines']) ?> item(s) · about <?= $m($q['est_value']) ?><?= (int)$q['unpriced'] ? ' + ' . (int)$q['unpriced'] . ' not priced' : '' ?></p><?php endforeach; ?></div>
<div class="card"><h2>Orders to approve</h2><?php if (!$W['orders']): ?><p class="muted">None.</p><?php endif; ?><?php foreach ($W['orders'] as $o): ?><p><a href="?view=order&id=<?= (int)$o['id'] ?>">Order #<?= (int)$o['id'] ?></a> · <?= h((string)$o['vendor']) ?> · <?= $m($o['total']) ?> · <?= h(PUR_ORD_STATUS[$o['status']]) ?></p><?php endforeach; ?></div>
<?php if ($R['approver']): ?><div class="card"><h2>Vendors to approve</h2><?php if (!$W['vendors']): ?><p class="muted">None.</p><?php endif; ?><?php foreach ($W['vendors'] as $v): ?><p><a href="?view=vendor&id=<?= (int)$v['id'] ?>"><?= h($v['code'] . ' ' . $v['name']) ?></a> <?= h($v['status_note']) ?></p><?php endforeach; ?></div><?php endif; ?>

<?php elseif ($view === 'reqs'): $rs = pur_reqs($db, $u, (string)($_GET['show'] ?? 'open')); $openLines = [];
    if ($R['buyer']) { $s = $db->query("SELECT l.*, r.no, r.branch_id, r.need_by FROM pur_req_line l JOIN pur_req r ON r.id = l.req_id WHERE l.status = 'open' ORDER BY r.need_by IS NULL, r.need_by, r.id"); foreach ($s as $l) if (isset($brs[(int)$l['branch_id']])) $openLines[] = $l; } ?>
<div class="card row"><a class="btn sm<?= ($_GET['show'] ?? 'open') === 'open' ? '' : ' ghost' ?>" href="?view=reqs">Open</a> <a class="btn sm<?= ($_GET['show'] ?? '') === 'all' ? '' : ' ghost' ?>" href="?view=reqs&show=all">All</a></div>
<div class="card"><table><tr><th>Number</th><th>Date</th><th>Branch</th><th>By</th><th>Purpose</th><th>Items</th><th>Status</th></tr>
<?php foreach ($rs as $q): ?><tr><td><a href="?view=req&id=<?= (int)$q['id'] ?>"><?= h($q['no']) ?></a></td><td><?= h($q['d']) ?></td><td><?= h($bname((int)$q['branch_id'])) ?></td><td><?= h((string)$q['by_name']) ?></td><td><?= h($q['purpose']) ?></td><td><?= (int)$q['n'] ?><?= (int)$q['n_open'] ? ' (' . (int)$q['n_open'] . ' open)' : '' ?></td><td><?= h(PUR_REQ_STATUS[$q['status']] ?? $q['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rs): ?><tr><td colspan="7" class="muted">No requisitions.</td></tr><?php endif; ?></table></div>
<?php if ($R['buyer'] && $openLines): ?><form method="post" class="card"><?= $hid('ordnew') ?><h2>Lines to buy</h2><p class="muted">Tick lines of one branch to make an order. A line with a finished comparison brings its vendor and rate.</p>
<table><tr><th></th><th>Requisition</th><th>Item</th><th>Quantity</th><th>Needed by</th><th>Last rate</th><th>Stock</th><th></th></tr><?php foreach ($openLines as $l): ?><tr><td><input type="checkbox" name="lines[]" value="<?= (int)$l['id'] ?>"></td><td><?= h($l['no']) ?>/<?= (int)$l['line_no'] ?></td><td><?= h($l['item']) ?></td><td><?= $q3($l['qty']) ?> <?= h($l['uom']) ?></td><td><?= h((string)$l['need_by']) ?></td><td><?= $m($l['last_rate']) ?></td><td><?= $l['stock_qty'] === null ? '' : $q3($l['stock_qty']) ?></td>
<td><a class="btn sm ghost" href="?view=cmps&line=<?= (int)$l['id'] ?>">Compare quotes</a></td></tr><?php endforeach; ?></table>
<p class="row"><select name="kind"><?= $opts(PUR_ORD_KINDS, 'po') ?></select><select name="branch_id"><?= $opts($brs) ?></select><button class="btn">Make order</button></p></form><?php endif; ?>

<?php elseif ($view === 'newreq'): $its = $db->query('SELECT id, code, name, uom FROM stk_item WHERE active = 1 ORDER BY name')->fetchAll(); $n = max(5, count((array)($f['li'] ?? [])) + 1); ?>
<?php if (!$brs): ?><div class="card">You have no branch. Ask the Super Admin to tick your branches on Users & access.</div><?php else: ?>
<form method="post" class="card"><?= $hid('req') ?><h2>New requisition</h2>
<p class="row" style="flex-wrap:wrap"><label>Branch <select name="branch_id"><?= $opts($brs, (string)($f['branch_id'] ?? '')) ?></select></label> <label>Needed by <input type="date" name="need_by" value="<?= h((string)($f['need_by'] ?? '')) ?>"></label> <label>Department <input name="dept" value="<?= h((string)($f['dept'] ?? '')) ?>"></label> <label>Job / order ref. <input name="job_ref" value="<?= h((string)($f['job_ref'] ?? '')) ?>"></label></p>
<p><label>Purpose <input name="purpose" style="width:100%" value="<?= h((string)($f['purpose'] ?? '')) ?>"></label></p>
<table><tr><th>Stock item (or type below)</th><th>Item</th><th>Quantity</th><th>Unit</th><th>Approx. rate</th></tr><?php for ($i = 0; $i < $n; $i++): ?><tr><td><select name="lid[<?= $i ?>]"><option value="">—</option><?php foreach ($its as $it): ?><option value="<?= (int)$it['id'] ?>"<?= (string)($f['lid'][$i] ?? '') === (string)$it['id'] ? ' selected' : '' ?>><?= h($it['code'] . ' · ' . $it['name']) ?></option><?php endforeach; ?></select></td>
<td><input name="li[<?= $i ?>]" value="<?= h((string)($f['li'][$i] ?? '')) ?>"></td><td><input name="lq[<?= $i ?>]" size="7" inputmode="decimal" value="<?= h((string)($f['lq'][$i] ?? '')) ?>"></td><td><input name="lu[<?= $i ?>]" size="5" value="<?= h((string)($f['lu'][$i] ?? '')) ?>"></td><td><input name="le[<?= $i ?>]" size="8" value="<?= h((string)($f['le'][$i] ?? '')) ?>"></td></tr><?php endfor; ?></table>
<p class="muted">At or above <?= $m($S['hod_value']) ?> — or with an item nobody can price — it goes to your reporting manager first.</p><button class="btn">Send</button></form><?php endif; ?>

<?php elseif ($view === 'req'): $q = pur_req_get($db, $id); if (!$q || !pur_req_can_see($u, $q)): ?><div class="card">Not found.</div><?php else: ?>
<div class="card"><h2><?= h($q['no']) ?> · <?= h(PUR_REQ_STATUS[$q['status']] ?? $q['status']) ?></h2>
<p><?= h($q['d']) ?> · <?= h($bname((int)$q['branch_id'])) ?> · by <?= h((string)$q['by_name']) ?><?= $q['need_by'] ? ' · needed by ' . h($q['need_by']) : '' ?><?= $q['dept'] !== '' ? ' · ' . h($q['dept']) : '' ?><?= $q['job_ref'] !== '' ? ' · ref ' . h($q['job_ref']) : '' ?></p>
<?= $q['purpose'] !== '' ? '<p>' . h($q['purpose']) . '</p>' : '' ?><?php if ($q['status'] === 'hod' || $q['hod_by']): ?><p class="muted">Manager: <?= h((string)($q['hod_name'] ?: 'any purchase approver')) ?><?= $q['hod_at'] ? ' · decided ' . h($q['hod_at']) : '' ?><?= $q['hod_note'] !== '' ? ' — ' . h($q['hod_note']) : '' ?></p><?php endif; ?>
<table><tr><th>#</th><th>Item</th><th>Quantity</th><th>Approx. rate</th><th>Last rate</th><th>Stock</th><th>Status</th></tr><?php foreach ($q['lines'] as $l): ?><tr><td><?= (int)$l['line_no'] ?></td><td><?= h($l['item']) ?></td><td><?= $q3($l['qty']) ?> <?= h($l['uom']) ?></td><td><?= $m($l['est_rate']) ?></td><td><?= $m($l['last_rate']) ?></td><td><?= $l['stock_qty'] === null ? '' : $q3($l['stock_qty']) ?></td><td><?= h($l['status']) ?><?= $l['order_id'] ? ' · <a href="?view=order&id=' . (int)$l['order_id'] . '">order</a>' : '' ?></td></tr><?php endforeach; ?></table>
<p class="muted">About <?= $m($q['est_value']) ?><?= (int)$q['unpriced'] ? ' + ' . (int)$q['unpriced'] . ' item(s) not priced' : '' ?></p></div>
<?php if (pur_req_can_decide($u, $q)): ?><form method="post" class="card row"><?= $hid('reqdecide', (int)$q['id']) ?><input name="note" placeholder="Note (needed to reject)" style="flex:1"><button class="btn" name="act" value="approve">Approve</button> <button class="btn ghost" name="act" value="reject">Reject</button></form><?php endif; ?>
<?php if (in_array($q['status'], ['hod', 'open'], true) && ((int)$q['created_by'] === $uid || $R['buyer'])): ?><form method="post" class="card row"><?= $hid('reqcancel', (int)$q['id']) ?><input name="why" placeholder="Why cancel" style="flex:1"><button class="btn ghost">Cancel requisition</button></form><?php endif; ?>
<?php endif; ?>

<?php elseif ($view === 'vendors' && $R['see']): $st = (string)($_GET['st'] ?? ''); $vs = pur_vendors($db, $st); ?>
<div class="card row" style="flex-wrap:wrap"><a class="btn sm<?= $st === '' ? '' : ' ghost' ?>" href="?view=vendors">All</a><?php foreach (PUR_VSTATUS as $k => $l): ?> <a class="btn sm<?= $st === $k ? '' : ' ghost' ?>" href="?view=vendors&st=<?= $k ?>"><?= h($l) ?></a><?php endforeach; ?><?php if ($R['buyer']): ?> <a class="btn sm" href="?view=vendor">Add a vendor</a><?php endif; ?></div>
<div class="card"><table><tr><th>Code</th><th>Name</th><th>City</th><th><?= h(crm_tax_label()) ?></th><th>Credit days</th><th>Status</th></tr><?php foreach ($vs as $v): ?><tr><td><a href="?view=vendor&id=<?= (int)$v['id'] ?>"><?= h($v['code']) ?></a></td><td><?= h($v['name']) ?></td><td><?= h($v['city']) ?></td><td><?= (int)$v['tax_none'] ? 'Not registered' : h((string)$v['tax_id']) ?></td><td><?= $v['pay_days'] === null ? '' : (int)$v['pay_days'] ?></td><td><?= h(PUR_VSTATUS[$v['status']]) ?></td></tr><?php endforeach; ?>
<?php if (!$vs): ?><tr><td colspan="6" class="muted">No vendors.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'vendor' && $R['see']): $v = $id ? pur_vendor_get($db, $id) : null; $x = $v ?: []; if ($f) $x = $f + $x; $bank = $_SESSION['pur_bank'] ?? null; unset($_SESSION['pur_bank']); ?>
<?php if ($v): ?><div class="card"><h2><?= h($v['code'] . ' · ' . $v['name']) ?> · <?= h(PUR_VSTATUS[$v['status']]) ?></h2><?= $v['status_note'] !== '' ? '<p class="muted">' . h($v['status_note']) . '</p>' : '' ?>
<p>Bank: <?= $v['bank_hint'] !== '' ? h($v['bank_hint']) : '<span class="muted">not given</span>' ?><?php if ($v['bank_box'] && ($R['approver'] || $R['buyer'])): ?> <form method="post" style="display:inline"><?= $hid('vbank', (int)$v['id']) ?><button class="btn sm ghost">Show in full</button></form><?php endif; ?></p>
<?php if ($bank && (int)$bank[0] === (int)$v['id'] && $bank[1]): ?><p><b><?= h($bank[1]['holder']) ?></b> · <?= h($bank[1]['bank']) ?> · <?= h($bank[1]['acc']) ?> · <?= h($bank[1]['code']) ?> <span class="muted">(shown once, recorded)</span></p><?php endif; ?>
<?php if ($mi = pur_vendor_missing($v)): ?><p class="muted">Still missing before approval: <?= h(implode(', ', $mi)) ?></p><?php endif; ?>
<?php if ($R['buyer'] && in_array($v['status'], ['new', 'blocked'], true)): ?><form method="post"><?= $hid('vsubmit', (int)$v['id']) ?><button class="btn sm">Send for approval</button></form><?php endif; ?>
<?php if ($R['approver'] && !in_array($uid, [(int)$v['created_by'], (int)$v['submitted_by']], true)): ?><form method="post" class="row"><?= $hid('vdecide', (int)$v['id']) ?><input name="note" placeholder="Note (needed to block)" style="flex:1"><?php if ($v['status'] === 'pending'): ?><button class="btn sm" name="act" value="approve">Approve</button><?php endif; ?><?php if ($v['status'] !== 'blocked'): ?> <button class="btn sm ghost" name="act" value="block">Block</button><?php endif; ?></form><?php endif; ?></div><?php endif; ?>
<?php if ($R['buyer']): ?><form method="post" class="card"><?= $hid('vendor', (int)($v['id'] ?? 0)) ?><h2><?= $v ? 'Change details' : 'Add a vendor' ?></h2>
<p class="row" style="flex-wrap:wrap"><label>Name <input name="name" required value="<?= h((string)($x['name'] ?? '')) ?>"></label> <label><?= h(crm_tax_label()) ?> <input name="tax_id" value="<?= h((string)($x['tax_id'] ?? '')) ?>"></label> <label><input type="checkbox" name="tax_none" value="1"<?= !empty($x['tax_none']) ? ' checked' : '' ?>> not registered for tax</label> <label>Region code <input name="region" size="5" value="<?= h((string)($x['region'] ?? '')) ?>"></label></p>
<p><label>Address <input name="address" style="width:100%" value="<?= h((string)($x['address'] ?? '')) ?>"></label></p>
<p class="row" style="flex-wrap:wrap"><label>City <input name="city" value="<?= h((string)($x['city'] ?? '')) ?>"></label> <label>Contact <input name="contact" value="<?= h((string)($x['contact'] ?? '')) ?>"></label> <label>Phone <input name="phone" value="<?= h((string)($x['phone'] ?? '')) ?>"></label> <label>Email <input name="email" value="<?= h((string)($x['email'] ?? '')) ?>"></label> <label>Credit days <input name="pay_days" size="4" value="<?= h((string)($x['pay_days'] ?? '')) ?>"></label> <label>Small business no. <input name="small_business" size="10" value="<?= h((string)($x['small_business'] ?? '')) ?>"></label></p>
<p class="row" style="flex-wrap:wrap"><b>Bank</b> <span class="muted"><?= $v ? 'leave blank to keep what is on file' : '' ?></span> <label>Account holder <input name="bank_holder" autocomplete="off"></label> <label>Bank <input name="bank_name" autocomplete="off"></label> <label>Account no. <input name="bank_acc" autocomplete="off"></label> <label>Again <input name="bank_acc2" autocomplete="off"></label> <label>Bank code <input name="bank_code" size="12" autocomplete="off"></label></p>
<?php if (!$v): ?><p><label><input type="checkbox" name="force" value="1"> a different vendor from one with the same name</label></p><?php endif; ?><button class="btn">Save</button></form><?php endif; ?>

<?php elseif ($view === 'cmps' && $R['see']): $cs = $db->query("SELECT c.*, (SELECT COUNT(*) FROM pur_quote q WHERE q.cmp_id = c.id AND q.active = 1) nq FROM pur_cmp c ORDER BY c.id DESC LIMIT 200")->fetchAll(); $line = (int)($_GET['line'] ?? 0); ?>
<?php if ($R['buyer']): ?><form method="post" class="card row" style="flex-wrap:wrap"><?= $hid('cmp') ?><?php if ($line): ?><input type="hidden" name="req_line_id" value="<?= $line ?>"><span>For requisition line #<?= $line ?></span><?php else: ?><input name="item" placeholder="Item" required><input name="qty" size="7" placeholder="Quantity" required><input name="uom" size="5" placeholder="Unit"><?php endif; ?><button class="btn sm">Start a comparison</button></form><?php endif; ?>
<div class="card"><table><tr><th>Number</th><th>Item</th><th>Quantity</th><th>Quotes</th><th>Status</th></tr><?php foreach ($cs as $c): ?><tr><td><a href="?view=cmp&id=<?= (int)$c['id'] ?>"><?= h($c['no']) ?></a></td><td><?= h($c['item']) ?></td><td><?= $q3($c['qty']) ?> <?= h($c['uom']) ?></td><td><?= (int)$c['nq'] ?></td><td><?= $c['status'] === 'done' ? 'Finished' : 'Open' ?></td></tr><?php endforeach; ?>
<?php if (!$cs): ?><tr><td colspan="5" class="muted">No comparisons.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'cmp' && $R['see']): $c = pur_cmp_get($db, $id); if (!$c): ?><div class="card">Not found.</div><?php else: $sav = $c['chosen_quote'] ? pur_saving($c['quotes'], (int)$c['chosen_quote'], (float)$c['qty']) : null; ?>
<div class="card"><h2><?= h($c['no']) ?> · <?= h($c['item']) ?> · <?= $q3($c['qty']) ?> <?= h($c['uom']) ?></h2>
<table><tr><th>Vendor</th><th>Rate</th><th>Freight</th><th>Delivery days</th><th>Credit days</th><th>Price</th><th>Quality</th><th>Delivery</th><th>Payment</th><th>Score</th><th></th></tr>
<?php foreach ($c['quotes'] as $qq): $sc = $c['score'][(int)$qq['id']]; ?><tr><td><?= h($qq['vendor']) ?><?= $qq['vstatus'] !== 'approved' ? ' <span class="muted">(' . h(PUR_VSTATUS[$qq['vstatus']]) . ')</span>' : '' ?><?= (int)$c['chosen_quote'] === (int)$qq['id'] ? ' <b>✓ chosen</b>' : '' ?></td><td><?= $m($qq['rate']) ?></td><td><?= $m($qq['freight']) ?></td><td><?= $qq['delivery_days'] ?? '' ?></td><td><?= $qq['pay_days'] ?? '' ?></td>
<td><?= $sc['price'] ?></td><td><?= $sc['quality'] ?></td><td><?= $sc['delivery'] ?></td><td><?= $sc['payment'] ?></td><td><b><?= $sc['total'] ?></b><?= $sc['best'] ? ' best' : '' ?></td>
<td><?php if ($R['buyer'] && $c['status'] === 'open'): ?><form method="post" style="display:inline"><?= $hid('quoteoff', (int)$c['id']) ?><input type="hidden" name="quote" value="<?= (int)$qq['id'] ?>"><button class="btn sm ghost">Take off</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$c['quotes']): ?><tr><td colspan="11" class="muted">No quotes yet.</td></tr><?php endif; ?></table>
<p class="muted">Weights: price <?= (int)$S['w_price'] ?> · quality <?= (int)$S['w_quality'] ?> · delivery <?= (int)$S['w_delivery'] ?> · payment <?= (int)$S['w_payment'] ?>. A vendor with no history counts <?= (int)$S['new_quality'] ?> for quality and on-time delivery.</p>
<?php if ($c['status'] === 'done'): ?><p>Finished <?= h((string)$c['decided_at']) ?><?= $c['reason'] !== '' ? ' — ' . h($c['reason']) : '' ?><?= $sav ? ' · saving ' . $m($sav['vs_high']) . ' against the highest, ' . $m($sav['vs_first']) . ' against the first quote' : '' ?></p><?php endif; ?></div>
<?php if ($R['buyer'] && $c['status'] === 'open'): $vs = array_filter(pur_vendors($db), fn($v) => $v['status'] !== 'blocked'); ?>
<form method="post" class="card row" style="flex-wrap:wrap"><?= $hid('quote', (int)$c['id']) ?><select name="vendor_id" required><?= $opts(array_column($vs, 'name', 'id'), '', 'Vendor') ?></select><input name="rate" size="8" placeholder="Rate" required><input name="freight" size="7" placeholder="Freight / unit"><input name="delivery_days" size="5" placeholder="Days"><input name="pay_days" size="5" placeholder="Credit days"><input name="note" placeholder="Note"><button class="btn sm">Add quote</button></form>
<?php if ($c['quotes']): ?><form method="post" class="card row" style="flex-wrap:wrap"><?= $hid('choose', (int)$c['id']) ?><select name="quote"><?= $opts(array_column($c['quotes'], 'vendor', 'id')) ?></select><input name="reason" placeholder="Why (needed when not the best, or a single quote)" style="flex:1"><button class="btn sm">Finish with this quote</button></form><?php endif; ?><?php endif; ?>
<?php endif; ?>

<?php elseif ($view === 'orders' && $R['see']): $sh = (string)($_GET['show'] ?? 'open'); $os = pur_orders($db, $u, $sh); ?>
<div class="card row" style="flex-wrap:wrap"><?php foreach (['open' => 'Open', 'issued' => 'Issued', 'all' => 'All'] as $k => $l): ?><a class="btn sm<?= $sh === $k ? '' : ' ghost' ?>" href="?view=orders&show=<?= $k ?>"><?= h($l) ?></a> <?php endforeach; ?><?php if ($R['buyer'] && $brs): ?><form method="post" class="row" style="display:inline-flex"><?= $hid('ordnew') ?><select name="kind"><?= $opts(PUR_ORD_KINDS, 'po') ?></select><select name="branch_id"><?= $opts($brs) ?></select><button class="btn sm">New empty order</button></form><?php endif; ?></div>
<div class="card"><table><tr><th>Number</th><th>Kind</th><th>Branch</th><th>Vendor</th><th>Before tax</th><th>Total</th><th>Status</th></tr><?php foreach ($os as $o): ?><tr><td><a href="?view=order&id=<?= (int)$o['id'] ?>"><?= h($o['no'] ?: 'Draft #' . $o['id']) ?></a></td><td><?= h(PUR_ORD_KINDS[$o['kind']]) ?></td><td><?= h($bname((int)$o['branch_id'])) ?></td><td><?= h((string)$o['vendor']) ?></td><td><?= $m($o['sub']) ?></td><td><?= $m($o['total']) ?></td><td><?= h(PUR_ORD_STATUS[$o['status']] ?? $o['status']) ?><?= (int)$o['need_ceo'] ? ' · CEO' : '' ?></td></tr><?php endforeach; ?>
<?php if (!$os): ?><tr><td colspan="7" class="muted">No orders.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'order' && $R['see']): $o = pur_ord_get($db, $id); if (!$o || !pur_ord_can_see($u, $o)): ?><div class="card">Not found.</div><?php else:
    $edit = $R['buyer'] && in_array($o['status'], ['draft', 'back'], true); [$step, $may] = pur_ord_step($o, $uid, $R['approver'], $R['ceo']);
    $prob = in_array($o['status'], ['draft', 'back', 'approved'], true) ? pur_ord_problems($o, $o['vendor'], $o['lines'], qt_head($db, (int)$o['branch_id']), $S, $o['status'] === 'approved') : []; ?>
<div class="card"><h2><?= h($o['no'] ?: 'Draft #' . $o['id']) ?> · <?= h(PUR_ORD_KINDS[$o['kind']]) ?> · <?= h(PUR_ORD_STATUS[$o['status']] ?? $o['status']) ?></h2>
<p><?= h($bname((int)$o['branch_id'])) ?> · <?= $o['vendor'] ? '<a href="?view=vendor&id=' . (int)$o['vendor']['id'] . '">' . h($o['vendor']['name']) . '</a> (' . h(PUR_VSTATUS[$o['vendor']['status']]) . ')' : 'no vendor yet' ?> · before tax <?= $m($o['sub']) ?><?php foreach ($o['parts'] as [$pl, $pa]): ?> · <?= h($pl) ?> <?= $m($pa) ?><?php endforeach; ?> · <b>total <?= $m($o['total']) ?></b> <a class="btn sm ghost" href="print.php?id=<?= (int)$o['id'] ?>" target="_blank">Print</a></p>
<?php if ($o['vendor'] && $o['vendor']['status'] !== 'approved'): ?><p class="muted">This vendor is not approved yet.</p><?php endif; ?>
<?php foreach (['mgr' => 'Approver', 'ceo' => 'CEO'] as $k => $l): if ($o[$k . '_at']): ?><p class="muted"><?= $l ?>: <?= h((string)$o[$k . '_at']) ?><?= $o[$k . '_note'] !== '' ? ' — ' . h($o[$k . '_note']) : '' ?></p><?php endif; endforeach; ?>
<?php if ($o['cancelled_why'] !== ''): ?><p class="muted"><?= h($o['cancelled_why']) ?></p><?php endif; ?>
<?php if ($prob): ?><p class="muted">Before it can go on: <?= h(implode('; ', $prob)) ?></p><?php endif; ?>
<?php if (!$edit): ?><table><tr><th>#</th><th>Item</th><th>Quantity</th><th>Rate</th><th>Tax %</th><th>Deliver by</th><th>Amount</th></tr><?php foreach ($o['lines'] as $l): ?><tr><td><?= (int)$l['line_no'] ?></td><td><?= h($l['item']) ?></td><td><?= $q3($l['qty']) ?> <?= h($l['uom']) ?></td><td><?= $m($l['rate']) ?></td><td><?= (float)$l['tax_pct'] ?></td><td><?= h((string)$l['deliv_date']) ?></td><td><?= $m($l['taxable']) ?></td></tr><?php endforeach; ?></table><?php endif; ?></div>
<?php if ($edit): $vs = array_filter(pur_vendors($db), fn($v) => $v['status'] !== 'blocked'); $n = count($o['lines']) + 2; ?>
<form method="post" class="card"><?= $hid('ordsave', (int)$o['id']) ?><p class="row" style="flex-wrap:wrap"><label>Vendor <select name="vendor_id"><?= $opts(array_column($vs, 'name', 'id'), (string)$o['vendor_id'], '—') ?></select></label> <label style="flex:1">Deliver to <input name="deliver_to" style="width:100%" value="<?= h($o['deliver_to']) ?>"></label></p>
<table><tr><th>Item</th><th>Unit</th><th>Quantity</th><th>Rate</th><th>Tax %</th><th>Deliver by</th></tr><?php for ($i = 0; $i < $n; $i++): $l = $o['lines'][$i] ?? []; ?><tr><td><input type="hidden" name="ln[<?= $i ?>]" value="<?= (int)($l['line_no'] ?? 0) ?>"><input name="li[<?= $i ?>]" value="<?= h((string)($l['item'] ?? '')) ?>"></td><td><input name="lu[<?= $i ?>]" size="5" value="<?= h((string)($l['uom'] ?? '')) ?>"></td><td><input name="lq[<?= $i ?>]" size="7" value="<?= isset($l['qty']) ? $q3($l['qty']) : '' ?>"></td><td><input name="lr[<?= $i ?>]" size="8" value="<?= isset($l['rate']) ? h((string)(float)$l['rate']) : '' ?>"></td><td><input name="lt[<?= $i ?>]" size="4" value="<?= isset($l['tax_pct']) ? (float)$l['tax_pct'] : '' ?>"></td><td><input type="date" name="ld[<?= $i ?>]" value="<?= h((string)($l['deliv_date'] ?? '')) ?>"></td></tr><?php endfor; ?></table>
<p><label>Terms (one per line)<br><textarea name="terms" rows="5" style="width:100%"><?= h($o['terms']) ?></textarea></label></p><p><label>Single source / no comparison — why <input name="single_note" style="width:100%" value="<?= h($o['single_note']) ?>"></label></p><button class="btn">Save</button></form>
<form method="post" class="card"><?= $hid('ordsend', (int)$o['id']) ?><button class="btn">Send for approval</button> <span class="muted"><?= (float)$o['sub'] >= (float)$S['limit_value'] ? 'At or above ' . $m($S['limit_value']) . ': approver, then the CEO.' : 'Goes to the approver.' ?></span></form><?php endif; ?>
<?php if ($may): ?><form method="post" class="card row"><?= $hid('orddecide', (int)$o['id']) ?><input name="note" placeholder="Note (needed to send back or reject)" style="flex:1"><button class="btn" name="act" value="approve">Approve</button> <button class="btn ghost" name="act" value="back">Send back</button> <button class="btn ghost" name="act" value="reject">Reject</button></form><?php endif; ?>
<?php if ($o['status'] === 'approved' && $R['buyer']): ?><form method="post" class="card"><?= $hid('ordissue', (int)$o['id']) ?><button class="btn">Issue — give the number</button> <span class="muted">The header and the vendor's details are kept as they are now.</span></form><?php endif; ?>
<?php if ($R['buyer'] && !in_array($o['status'], ['issued', 'cancelled'], true)): ?><form method="post" class="card row"><?= $hid('ordcancel', (int)$o['id']) ?><input name="why" placeholder="Why cancel" style="flex:1"><button class="btn ghost">Cancel order</button></form><?php endif; ?>
<?php endif; ?>

<?php elseif ($view === 'settings' && $R['admin']): $rl = role_labels(); ?>
<form method="post" class="card"><?= $hid('settings') ?><h2>Purchase settings</h2>
<?php foreach (['buyer_roles' => 'Buyers (vendors, comparisons, orders)', 'approver_roles' => 'Approvers (first step; vendors)', 'see_roles' => 'See purchase (read)'] as $k => $l): ?><p><b><?= h($l) ?></b><br><?php foreach ($rl as $c => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($c) ?>"<?= in_array($c, $S[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label> <?php endforeach; ?></p><?php endforeach; ?>
<p class="row" style="flex-wrap:wrap"><label>CEO approves at or above <input name="limit_value" size="10" value="<?= h((string)$S['limit_value']) ?>"></label> <label>Manager approves requisitions at or above <input name="hod_value" size="10" value="<?= h((string)$S['hod_value']) ?>"></label></p>
<p class="row" style="flex-wrap:wrap"><?php foreach (['prefix_po' => 'Order prefix', 'prefix_jw' => 'Job work prefix', 'prefix_pr' => 'Requisition prefix', 'prefix_cmp' => 'Comparison prefix'] as $k => $l): ?><label><?= h($l) ?> <input name="<?= $k ?>" size="5" value="<?= h($S[$k]) ?>"></label> <?php endforeach; ?></p>
<p class="row" style="flex-wrap:wrap"><b>Score weights</b> <?php foreach (['w_price' => 'Price', 'w_quality' => 'Quality', 'w_delivery' => 'Delivery', 'w_payment' => 'Payment', 'new_quality' => 'New vendor counts'] as $k => $l): ?><label><?= h($l) ?> <input name="<?= $k ?>" size="3" value="<?= (int)$S[$k] ?>"></label> <?php endforeach; ?></p>
<p><label>Default order terms (one per line)<br><textarea name="terms" rows="6" style="width:100%"><?= h($S['terms']) ?></textarea></label></p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Your role does not see this. Ask the Super Admin.</div><?php endif; ?>
<?php ui_foot();
