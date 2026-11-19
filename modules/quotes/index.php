<?php
// Quotations — list, open enquiries waiting for one, new / change a draft, one quotation (send, revise, won / lost,
// order, archive), header settings per company and branch, module settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/quotes.php';
$u = module_require('quotes');
$db = pdo(); $R = crm_rights($u); $set = qt_settings(); $uid = (int)$u['id']; $isAdmin = has_role($u, 'SUPERADMIN'); $pack = qt_tax_pack();
$view = (string)($_GET['view'] ?? 'list');
$lines = function (): array { $o = []; foreach ((array)($_POST['lp'] ?? []) as $i => $p) $o[] = ['product_id' => $p, 'qty' => $_POST['lq'][$i] ?? '', 'rate' => $_POST['lr'][$i] ?? '', 'discount_pct' => $_POST['ld'][$i] ?? '', 'tax_pct' => $_POST['lt'][$i] ?? '', 'description' => $_POST['lx'][$i] ?? '']; return $o; };
$charges = function (): array { $o = []; foreach ((array)($_POST['ck'] ?? []) as $i => $k) $o[] = ['kind' => $k, 'amount' => $_POST['ca'][$i] ?? '', 'label' => $_POST['cl'][$i] ?? '']; return $o; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $to = 'index.php?view=q&id=' . $id;
    try {
        switch ($a) {
            case 'save': $id = qt_save($db, $u, $_POST + ['lines' => $lines(), 'charges' => $charges()], $id); flash('Saved.'); $to = 'index.php?view=q&id=' . $id; break;
            case 'send': qt_send($db, $u, $id); flash('Marked sent — it is locked now.'); break;
            case 'revise': $id = qt_revise($db, $u, $id, (string)($_POST['reason'] ?? '')); flash('Revision made — change it, then mark it sent.'); $to = 'index.php?view=edit&id=' . $id; break;
            case 'won': qt_decide($db, $u, $id, 'won', '', (string)($_POST['note'] ?? '')); flash('Won.'); break;
            case 'lost': qt_decide($db, $u, $id, 'lost', (string)($_POST['reason'] ?? ''), (string)($_POST['note'] ?? '')); flash('Marked lost.'); break;
            case 'order': $oid = qt_to_order($db, $u, $id); flash('Order made.'); $to = '../crm/orders.php?view=order&id=' . $oid; break;
            case 'archive': qt_archive($db, $u, $id, ($_POST['to'] ?? '') === 'archive', (string)($_POST['reason'] ?? '')); flash('Saved.'); break;
            case 'head': qt_head_save($db, $u, (int)($_POST['branch'] ?? 0), $_POST); flash('Header saved.'); $to = 'index.php?view=head&branch=' . (int)($_POST['branch'] ?? 0); break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new] = qt_settings_check($_POST); $st = module_states()['quotes'] ?? ['roles' => module_roles('quotes')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['quotes', 1, implode(',', $st['roles'] ?: module_roles('quotes')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'quotes'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        if ($a === 'save') { $_SESSION['qt_form'] = $_POST; $to = $id ? 'index.php?view=edit&id=' . $id : 'index.php?view=new&cust=' . (int)($_POST['customer_id'] ?? 0) . '&enq=' . (int)($_POST['enquiry_id'] ?? 0); }
        if ($a === 'head') $to = 'index.php?view=head&branch=' . (int)($_POST['branch'] ?? 0);
    }
    redirect($to);
}

$money = fn($v) => number_format((float)$v, 2);
$qty = fn($v) => rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$hid = fn(string $a, int $id) => csrf_field() . '<input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="id" value="' . $id . '">';
$products = $db->query('SELECT id, sku, name, rate, tax_pct FROM crm_product WHERE active = 1 AND merged_into IS NULL ORDER BY name')->fetchAll();
$form = function (array $q, array $cu, ?array $enq) use ($opts, $hid, $products, $db, $pack, $R, $u): void {
    $isNew = empty($q['id']); $goods = array_values(array_filter($q['lines'] ?? [], fn($l) => !empty($l['product_id']))); $chs = array_values(array_filter($q['lines'] ?? [], fn($l) => empty($l['product_id']) && ($l['kind'] ?? '') !== ''));
    $head = qt_head($db, $q['branch_id'] ?? ($cu['branch_id'] !== null ? (int)$cu['branch_id'] : null)); $pay = crm_lines($head['pay_options']); ?>
<form method="post" class="card"><?= $hid('save', (int)($q['id'] ?? 0)) ?><input type="hidden" name="customer_id" value="<?= (int)$cu['id'] ?>"><input type="hidden" name="enquiry_id" value="<?= (int)($enq['id'] ?? 0) ?>"><input type="hidden" name="version" value="<?= (int)($q['version'] ?? 1) ?>"><input type="hidden" name="request_id" value="<?= h((string)($q['request_id'] ?? so_uuid(''))) ?>">
<h2><?= $isNew ? 'New quotation' : 'Change ' . h(qt_display_no($q)) ?> · <?= h($cu['name']) ?><?= $enq ? ' <span class="muted">for ' . h($enq['ref']) . '</span>' : '' ?></h2>
<div class="grid"><label>Date <input type="date" name="quote_date" value="<?= h((string)($q['quote_date'] ?? date('Y-m-d'))) ?>"></label><label>Valid till <input type="date" name="valid_till" value="<?= h((string)($q['valid_till'] ?? '')) ?>"></label>
<label>Contact <select name="contact_id"><?= $opts(array_column($cu['contacts'], 'name', 'id'), (string)($q['contact_id'] ?? ''), '—') ?></select></label>
<label>Bill to <select name="bill_address_id"><?= $opts(array_map(fn($a) => implode(', ', array_filter([$a['label'], $a['line1'], $a['city']])), array_column($cu['addresses'], null, 'id')), '', 'Default address') ?></select></label>
<label>Deliver to <select name="ship_address_id"><?= $opts(array_map(fn($a) => implode(', ', array_filter([$a['label'], $a['line1'], $a['city']])), array_column($cu['addresses'], null, 'id')), '', 'Default address') ?></select></label>
<?php if ($pack['regions']): ?><label>Place of supply <span class="muted">(blank = from the delivery address)</span> <select name="place_region"><?= $opts($pack['regions'], (int)($q['place_hand'] ?? 0) ? (string)$q['place_region'] : '', '—') ?></select></label><?php endif; ?>
<label>Payment terms <input name="payment_terms" list="pay" value="<?= h((string)($q['payment_terms'] ?? $head['payment_terms'])) ?>"></label><label>Delivery <input name="delivery_terms" value="<?= h((string)($q['delivery_terms'] ?? '')) ?>" placeholder="e.g. 2 weeks from order"></label>
<?php if ($R['all']): ?><label>Salesperson <select name="salesperson_id"><?= $opts($db->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR), (string)($q['salesperson_id'] ?? $u['id'])) ?></select></label><?php endif; ?></div>
<datalist id="pay"><?php foreach ($pay as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist>
<h3>Products</h3><table><tr><th>Product</th><th>Qty</th><th>Rate <span class="muted">(blank = list)</span></th><th>Disc %</th><th>Tax % <span class="muted">(blank = product's)</span></th><th>Description</th></tr>
<?php for ($i = 0; $i < max(3, count($goods) + 2); $i++): $l = $goods[$i] ?? []; ?><tr><td><select name="lp[<?= $i ?>]"><option value="">—</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)($l['product_id'] ?? 0) === (int)$p['id'] ? ' selected' : '' ?>><?= h($p['name'] . ' · ' . $p['sku']) ?></option><?php endforeach; ?></select></td>
<td><input name="lq[<?= $i ?>]" value="<?= h(isset($l["qty"]) && $l["qty"] !== "" ? rtrim(rtrim(number_format((float)$l["qty"], 3, ".", ""), "0"), ".") : "") ?>" size="6"></td><td><input name="lr[<?= $i ?>]" value="<?= h((string)($l['rate'] ?? '')) ?>" size="8"></td><td><input name="ld[<?= $i ?>]" value="<?= h((string)($l['discount_pct'] ?? '')) ?>" size="4"></td><td><input name="lt[<?= $i ?>]" value="<?= h((string)($l['tax_pct'] ?? '')) ?>" size="4"></td><td><input name="lx[<?= $i ?>]" value="<?= h((string)($l['description'] ?? '')) ?>"></td></tr><?php endfor; ?></table>
<h3>Other charges <span class="muted">(taxed at the highest goods rate)</span></h3><?php for ($i = 0; $i < max(2, count($chs) + 1); $i++): $c = $chs[$i] ?? []; ?><div class="row"><select name="ck[<?= $i ?>]"><?= $opts(array_combine(QT_CHARGE_KINDS, QT_CHARGE_KINDS), (string)($c['kind'] ?? '')) ?></select><input name="ca[<?= $i ?>]" value="<?= h((string)($c['rate'] ?? '')) ?>" placeholder="Amount" size="8"><input name="cl[<?= $i ?>]" value="<?= h((string)($c['description'] ?? '')) ?>" placeholder="Note"></div><?php endfor; ?>
<label>Note on the quotation <textarea name="note" rows="2"><?= h((string)($q['note'] ?? '')) ?></textarea></label><label><input type="checkbox" name="show_intro" value="1"<?= (int)($q['show_intro'] ?? 1) ? ' checked' : '' ?>> Print the introduction page</label><br><button class="btn">Save draft</button></form>
<?php };

$q = null;
if (in_array($view, ['q', 'edit'], true)) { try { $q = qt_get($db, (int)($_GET['id'] ?? 0)); } catch (Throwable $e) { $q = null; } if (!$q || !qt_can($u, $q)) { http_response_code(404); $view = 'none'; } }
ui_head('Quotations', $u, 'quotes'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'list' ? '' : ' ghost' ?>" href="index.php">Quotations</a> <?php if ($R['edit'] && module_on('enquiries')): ?><a class="btn sm<?= $view === 'waiting' ? '' : ' ghost' ?>" href="?view=waiting">Enquiries waiting for one</a><?php endif; ?>
<?php if ($R['admin']): ?> <a class="btn sm<?= $view === 'head' ? '' : ' ghost' ?>" href="?view=head">Header & terms</a><?php endif; ?><?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php if ($view === 'list'): $st = (string)($_GET['st'] ?? ''); $rows = qt_list($db, $u, ['status' => $st, 'q' => (string)($_GET['q'] ?? ''), 'archived' => !empty($_GET['arch'])]); ?>
<form class="card row" style="flex-wrap:wrap"><input name="q" value="<?= h((string)($_GET['q'] ?? '')) ?>" placeholder="Number or customer"><select name="st"><?= $opts(array_combine(QT_STATUSES, QT_STATUSES), $st, 'All but revised') ?></select><label><input type="checkbox" name="arch" value="1"<?= !empty($_GET['arch']) ? ' checked' : '' ?>> Archived</label><button class="btn sm">Show</button><span class="muted">New quotation: open a customer in the CRM, or pick an enquiry waiting for one.</span></form>
<div class="card"><table><tr><th>Number</th><th>Customer</th><th>Date</th><th>Status</th><th>Before tax</th><th>Total</th></tr>
<?php foreach ($rows as $x): $over = $x['status'] === 'Sent' && $x['valid_till'] < date('Y-m-d'); ?><tr><td><a href="?view=q&id=<?= (int)$x['id'] ?>"><?= h(qt_display_no($x)) ?></a></td><td><?= h($x['customer']) ?></td><td><?= h($x['quote_date']) ?></td><td><?= h($x['status']) ?><?= $over ? ' · <b>validity over</b>' : '' ?></td><td><?= $money($x['taxable']) ?></td><td><?= $money($x['total']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No quotations.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'waiting' && $R['edit']): $es = array_values(array_filter(enq_list($db, $u, ['stage' => 'open']), fn($e) => in_array($e['status'], ['new', 'contacted', 'quoted', 'negotiation'], true) && !$db->query('SELECT 1 FROM qt_quote WHERE archived_at IS NULL AND enquiry_id = ' . (int)$e['id'])->fetchColumn())); ?>
<div class="card"><h2>Open enquiries without a quotation</h2><table><tr><th>Enquiry</th><th>Customer</th><th>For</th><th></th></tr>
<?php foreach ($es as $e): ?><tr><td><a href="../enquiries/index.php?view=e&id=<?= (int)$e['id'] ?>"><?= h($e['ref']) ?></a></td><td><?= h($e['customer_name']) ?><div class="muted"><?= h($e['company']) ?></div></td><td><?= h($e['product'] ?: $e['category']) ?></td>
<td><?= $e['customer_id'] ? '<a class="btn sm" href="?view=new&cust=' . (int)$e['customer_id'] . '&enq=' . (int)$e['id'] . '">Make quotation</a>' : '<span class="muted">Link a CRM customer on the enquiry first</span>' ?></td></tr><?php endforeach; ?>
<?php if (!$es): ?><tr><td colspan="4" class="muted">None waiting.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'new' && $R['edit']): $cu = crm_customer_get($db, (int)($_GET['cust'] ?? 0)); $enq = (int)($_GET['enq'] ?? 0) ? enq_get($db, (int)$_GET['enq']) : null;
    if (!$cu || $cu['status'] !== 'active' || !crm_can($u, $cu)): ?><div class="card">Pick an active customer first.</div><?php else:
    $f = $_SESSION['qt_form'] ?? []; unset($_SESSION['qt_form']);
    $q = $f ? $f + ['lines' => array_merge(array_map(fn($p, $i) => ['product_id' => $p, 'qty' => $f['lq'][$i] ?? '', 'rate' => $f['lr'][$i] ?? '', 'discount_pct' => $f['ld'][$i] ?? '', 'tax_pct' => $f['lt'][$i] ?? '', 'description' => $f['lx'][$i] ?? ''], (array)($f['lp'] ?? []), array_keys((array)($f['lp'] ?? []))),
        array_map(fn($k, $i) => ['kind' => ($f['ca'][$i] ?? '') !== '' ? $k : '', 'rate' => $f['ca'][$i] ?? '', 'description' => $f['cl'][$i] ?? ''], (array)($f['ck'] ?? []), array_keys((array)($f['ck'] ?? []))))] : [];
    $form($q, $cu, $enq); endif;

elseif ($view === 'edit' && $q['status'] === 'Draft' && !$q['archived_at'] && qt_can($u, $q, true)): $form($q, $q['customer'], $q['enquiry_id'] ? enq_get($db, (int)$q['enquiry_id']) : null);

elseif ($view === 'q' || $view === 'edit'): $w = qt_can($u, $q, true) && !$q['archived_at']; $goods = array_filter($q['lines'], fn($l) => $l['product_id'] !== null); $chs = array_filter($q['lines'], fn($l) => $l['product_id'] === null); ?>
<div class="card"><div class="row" style="justify-content:space-between"><h2><?= h(qt_display_no($q)) ?> · <a href="../crm/index.php?view=cust&id=<?= (int)$q['customer_id'] ?>"><?= h($q['customer']['name'] ?? '') ?></a></h2>
<div class="row"><a class="btn sm ghost" href="print.php?id=<?= (int)$q['id'] ?>" target="_blank" rel="noopener">Print / PDF</a><?php if ($w && $q['status'] === 'Draft'): ?> <a class="btn sm" href="?view=edit&id=<?= (int)$q['id'] ?>">Change</a><?php endif; ?></div></div>
<p><b><?= h($q['status']) ?></b><?= $q['status'] === 'Lost' ? ' — ' . h($q['lost_reason']) : '' ?><?= $q['status'] === 'Sent' && $q['valid_till'] < date('Y-m-d') ? ' · <b>validity over</b>' : '' ?> · <?= h($q['quote_date']) ?> valid till <?= h($q['valid_till']) ?><?= $q['archived_at'] ? ' · <b>Archived</b> — ' . h($q['archive_reason']) : '' ?></p>
<?php if (count($q['revs']) > 1): ?><p class="muted">Revisions: <?php foreach ($q['revs'] as $r): ?><a href="?view=q&id=<?= (int)$r['id'] ?>"><?= (int)$r['rev'] ? 'R' . (int)$r['rev'] : 'first' ?></a> (<?= h($r['status']) ?>) <?php endforeach; ?></p><?php endif; ?>
<p class="muted">Bill to: <?= h($q['bill_to']) ?> · deliver to: <?= h($q['ship_to']) ?><?= $q['place_region'] !== '' ? ' · place of supply: ' . h((string)($pack['regions'][$q['place_region']] ?? $q['place_region'])) : '' ?> · <?= h($q['payment_terms']) ?></p>
<table><tr><th>#</th><th>Product</th><th>Qty</th><th>Rate</th><th>Disc</th><th>Tax</th><th>Amount</th></tr>
<?php foreach ($goods as $l): ?><tr><td><?= (int)$l['line_no'] ?></td><td><?= h($l['name']) ?><div class="muted"><?= h($l['description']) ?></div></td><td><?= $qty($l['qty']) ?> <?= h($l['unit']) ?></td><td><?= $money($l['rate']) ?></td><td><?= (float)$l['discount_pct'] ?>%</td><td><?= (float)$l['tax_pct'] ?>%</td><td><?= $money($l['taxable']) ?></td></tr><?php endforeach; ?>
<?php foreach ($chs as $l): ?><tr><td></td><td><?= h($l['name']) ?> <span class="muted"><?= h($l['description']) ?></span></td><td></td><td></td><td></td><td><?= (float)$l['tax_pct'] ?>%</td><td><?= $money($l['taxable']) ?></td></tr><?php endforeach; ?>
<tr><td colspan="6" style="text-align:right">Before tax</td><td><?= $money($q['taxable']) ?></td></tr><?php foreach ($q['parts'] as [$lbl, $amt]): ?><tr><td colspan="6" style="text-align:right"><?= h($lbl) ?></td><td><?= $money($amt) ?></td></tr><?php endforeach; ?>
<?php if ((float)$q['round_off']): ?><tr><td colspan="6" style="text-align:right">Round off</td><td><?= $money($q['round_off']) ?></td></tr><?php endif; ?><tr><td colspan="6" style="text-align:right"><b>Total</b></td><td><b><?= $money($q['total']) ?></b></td></tr></table>
<p class="muted"><?= h(qt_words((float)$q['total'])) ?></p></div>
<?php if ($w): ?><div class="card row" style="flex-wrap:wrap">
<?php if ($q['status'] === 'Draft'): ?><form method="post"><?= $hid('send', (int)$q['id']) ?><button class="btn sm">Mark sent</button></form><?php endif; ?>
<?php if ($q['status'] === 'Sent'): ?><form method="post" class="row"><?= $hid('won', (int)$q['id']) ?><input name="note" placeholder="Note"><button class="btn sm">Won</button></form>
<form method="post" class="row"><?= $hid('lost', (int)$q['id']) ?><select name="reason"><?= $opts(array_combine(crm_lines($set['lost_reasons']), crm_lines($set['lost_reasons'])), '', 'Why lost') ?></select><input name="note" placeholder="Note"><button class="btn sm ghost">Lost</button></form>
<form method="post" class="row"><?= $hid('revise', (int)$q['id']) ?><input name="reason" placeholder="What changes" required><button class="btn sm ghost">Revise</button></form><?php endif; ?>
<?php if ($q['status'] === 'Won'): ?><?= $q['order_id'] ? '<a class="btn sm" href="../crm/orders.php?view=order&id=' . (int)$q['order_id'] . '">Open the order</a>' : '<form method="post">' . $hid('order', (int)$q['id']) . '<button class="btn sm">Make the order</button></form>' ?><?php endif; ?></div><?php endif; ?>
<?php if ($R['admin'] && !$q['order_id']): ?><form method="post" class="card row"><?= $hid('archive', (int)$q['id']) ?><input type="hidden" name="to" value="<?= $q['archived_at'] ? 'restore' : 'archive' ?>"><input name="reason" placeholder="Why" required><button class="btn sm ghost"><?= $q['archived_at'] ? 'Bring back' : 'Archive' ?></button></form><?php endif; ?>
<details class="card"><summary>History</summary><?php foreach ($q['log'] as $x): ?><div class="muted"><?= h(substr($x['at'], 0, 16)) ?> · <?= h((string)($x['who'] ?? 'system')) ?> · <?= h($x['what']) ?><?= $x['note'] !== '' ? ' — ' . h($x['note']) : '' ?></div><?php endforeach; ?></details>

<?php elseif ($view === 'head' && $R['admin']): $b = (int)($_GET['branch'] ?? 0); $rows = qt_head_rows($db); $cur = $rows[$b] ?? []; $comp = qt_head($db, null); ?>
<div class="card"><h2>Header & terms</h2><p class="muted">The company's values print on every quotation; a branch fills only what differs. A sent quotation keeps the header it was sent with.</p>
<div class="row" style="flex-wrap:wrap"><a class="btn sm<?= $b === 0 ? '' : ' ghost' ?>" href="?view=head">Company</a><?php foreach (branches() as $x): $m = qt_head_missing(qt_head($db, (int)$x['id'])); ?> <a class="btn sm<?= $b === (int)$x['id'] ? '' : ' ghost' ?>" href="?view=head&branch=<?= (int)$x['id'] ?>"><?= h($x['name']) ?><?= $m ? ' · needs ' . count($m) : '' ?></a><?php endforeach; ?></div></div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="head"><input type="hidden" name="branch" value="<?= $b ?>"><div class="grid">
<?php foreach (QT_HEAD_FIELDS as $k => [$l, $max]): $big = $max > 300; ?><label><?= h($l) ?><?= $k === 'tax_id' ? ' — ' . h(crm_tax_label()) : '' ?>
<?php if ($big): ?><textarea name="<?= $k ?>" rows="4" placeholder="<?= $b ? h(mb_substr($comp[$k], 0, 80)) : '' ?>"><?= h((string)($cur[$k] ?? '')) ?></textarea><?php else: ?><input name="<?= $k ?>" value="<?= h((string)($cur[$k] ?? '')) ?>" placeholder="<?= $b ? h($comp[$k]) : '' ?>" maxlength="<?= $max ?>"><?php endif; ?></label><?php endforeach; ?></div>
<?php if ($b): ?><p class="muted">Blank = the company's value (shown in grey).</p><?php endif; ?><button class="btn">Save</button></form>

<?php elseif ($view === 'settings' && $isAdmin): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<div class="grid"><label>Number starts with <input name="prefix" value="<?= h($set['prefix']) ?>" maxlength="6"></label><label>Follow-up on the enquiry after sending (days) <input type="number" name="follow_days" value="<?= (int)$set['follow_days'] ?>"></label>
<label>Reasons lost (one a line) <textarea name="lost_reasons" rows="6"><?= h($set['lost_reasons']) ?></textarea></label></div>
<label><input type="checkbox" name="need_tax_id" value="1"<?= $set['need_tax_id'] ? ' checked' : '' ?>> A quotation is sent only with the company's <?= h(crm_tax_label()) ?> in the header</label><br>
<p class="muted">Who makes quotations follows the CRM's groups (work on every customer / own customers). Tax is split by the country rules (<?= h($pack['label']) ?>).</p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not found.</div><?php endif;
ui_foot();
