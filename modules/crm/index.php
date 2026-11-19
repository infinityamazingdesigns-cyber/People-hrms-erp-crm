<?php
// CRM — customers (list, one customer, new / change), products, duplicates (merge / not the same), import, settings.
// Orders, dispatch and the tracking page come in the next part.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/crm.php';
$u = module_require('crm');
$db = pdo(); $set = crm_settings(); $R = crm_rights($u); $uid = (int)$u['id']; $isAdmin = has_role($u, 'SUPERADMIN');
$view = (string)($_GET['view'] ?? 'list');
$taxLabel = crm_tax_label();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $to = $id ? 'index.php?view=cust&id=' . $id : 'index.php?view=' . urlencode($view);
    try {
        switch ($a) {
            case 'cust': $id = crm_customer_save($db, $u, $id, $_POST, !empty($_POST['force'])); flash('Saved.'); $to = 'index.php?view=cust&id=' . $id; break;
            case 'note': crm_note_add($db, $u, 'customer', $id, (string)($_POST['body'] ?? '')); flash('Note added.'); break;
            case 'status': crm_customer_status($db, $u, $id, ($_POST['to'] ?? '') === 'archived', (string)($_POST['reason'] ?? '')); flash('Saved.'); break;
            case 'owner': crm_customer_owner($db, $u, $id, (int)$_POST['owner']); flash('Owner changed.'); break;
            case 'merge': crm_customer_merge($db, $u, (int)$_POST['keep'], (int)$_POST['drop']); flash('Merged.'); $to = 'index.php?view=cust&id=' . (int)$_POST['keep']; break;
            case 'notsame': crm_not_same_mark($db, $u, 'cust', (int)$_POST['x'], (int)$_POST['y']); flash('Marked as different customers.'); $to = 'index.php?view=dups'; break;
            case 'product': crm_product_save($db, $u, (int)($_POST['pid'] ?? 0), $_POST); flash('Product saved.'); $to = 'index.php?view=products'; break;
            case 'pstatus': crm_product_status($db, $u, (int)$_POST['pid'], ($_POST['on'] ?? '') === '1', (string)($_POST['reason'] ?? '')); flash('Saved.'); $to = 'index.php?view=products'; break;
            case 'import':
                crm_need($u, 'admin');
                $t = $_FILES['f']['tmp_name'] ?? ''; if (!is_string($t) || $t === '' || !is_uploaded_file($t)) throw new RuntimeException('Pick a file.');
                $rows = tbl_read($t, (string)$_FILES['f']['name']); @unlink($t);
                if (($_POST['what'] ?? '') === 'products') { $r = crm_product_import($db, $u, $rows, !empty($_POST['apply'])); flash((!empty($_POST['apply']) ? 'Saved' : 'Checked') . ": {$r['new']} new, {$r['update']} to update" . ($r['error'] ? ', ' . count($r['error']) . ' not read: ' . implode('; ', array_map(fn($x) => rtrim($x, '.'), array_slice($r['error'], 0, 3))) : '') . '.'); }
                else { $_SESSION['crm_plan'] = ['at' => time(), 'uid' => $uid, 'rows' => crm_import_plan($db, $rows), 'name' => mb_substr((string)$_FILES['f']['name'], 0, 80)]; flash('File checked — nothing saved yet.'); }
                $to = 'index.php?view=import'; break;
            case 'importsave':
                $p = $_SESSION['crm_plan'] ?? null;
                if (!$p || $p['uid'] !== $uid || time() - $p['at'] > 3600) throw new RuntimeException('Check the file again.');
                $n = crm_import_apply($db, $u, $p['rows'], array_map('intval', (array)($_POST['maybe'] ?? []))); unset($_SESSION['crm_plan']);
                flash("Saved: {$n['new']} new, {$n['filled']} existing filled, {$n['skipped']} left out."); $to = 'index.php'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = crm_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['crm'] ?? ['roles' => module_roles('crm')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['crm', 1, implode(',', $st['roles'] ?: module_roles('crm')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'crm'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        if ($a === 'cust') { $_SESSION['crm_form'] = $_POST; $to = 'index.php?view=' . ($id ? 'edit&id=' . $id : 'new'); }
    }
    redirect($to);
}

if ($view === 'csv' && $R['all']) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="customers.csv"');
    $q = fn($v) => '"' . str_replace('"', '""', (in_array(substr((string)$v, 0, 1), ['=', '+', '-', '@'], true) ? "'" : '') . (string)$v) . '"';
    echo "\xEF\xBB\xBF" . implode(',', array_map($q, ['Code', 'Name', $taxLabel, 'Category', 'Industry', 'Mobile', 'City', 'Owner', 'Status'])) . "\r\n";
    foreach (crm_customer_list($db, $u, '', 'all', 100000) as $c) echo implode(',', array_map($q, [$c['code'], $c['name'], $c['tax_id'], $c['category'], $c['industry'], $c['mobile'], $c['city'], $c['owner'], $c['status']])) . "\r\n";
    audit($uid, 'crm_export', 'customers'); exit;
}

$bn = []; foreach (branches() as $b) $bn[(int)$b['id']] = $b['name'];
$sel = fn(array $o, string $n, string $c = '', string $x = '') => '<select name="' . $n . '"' . $x . '>' . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($o), $o)) . '</select>';
$hid = fn(string $a, int $id = 0) => csrf_field() . '<input type="hidden" name="a" value="' . $a . '"><input type="hidden" name="id" value="' . $id . '">';
$custForm = function (array $c) use ($sel, $bn, $set, $taxLabel, $hid, $R): void {
    $ph = $c['phones'] ?? []; $em = $c['emails'] ?? []; $ct = $c['contacts'] ?? []; $ad = $c['addresses'] ?? []; ?>
<form method="post" class="card"><?= $hid('cust', (int)($c['id'] ?? 0)) ?><h2><?= !empty($c['id']) ? 'Change ' . h($c['name']) : 'New customer' ?></h2>
<div class="grid"><label>Name * <input name="name" value="<?= h((string)($c['name'] ?? '')) ?>" required maxlength="200"></label><label>Kind <?= $sel(CRM_KINDS, 'kind', (string)($c['kind'] ?? 'company')) ?></label>
<label><?= h($taxLabel) ?> <input name="tax_id" value="<?= h((string)($c['tax_id'] ?? '')) ?>"></label><label>Branch <?= $sel(['' => '—'] + $bn, 'branch_id', (string)($c['branch_id'] ?? '')) ?></label>
<label>Category <input name="category" list="cats" value="<?= h((string)($c['category'] ?? '')) ?>"></label><label>Industry <input name="industry" value="<?= h((string)($c['industry'] ?? '')) ?>"></label>
<label>Source <input name="source" value="<?= h((string)($c['source'] ?? '')) ?>"></label><label>Website <input name="website" value="<?= h((string)($c['website'] ?? '')) ?>"></label></div>
<datalist id="cats"><?php foreach (crm_lines($set['categories']) as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist>
<h3>Mobiles</h3><?php for ($i = 0; $i < max(2, count($ph) + 1); $i++): $p = array_values($ph)[$i] ?? []; ?><div class="row"><input name="phone[<?= $i ?>]" value="<?= h((string)($p['number'] ?? '')) ?>" placeholder="With country code" inputmode="tel"><input name="plabel[<?= $i ?>]" value="<?= h((string)($p['label'] ?? '')) ?>" placeholder="Label"><label><input type="checkbox" name="wa[<?= $i ?>]" value="1"<?= !$p || (int)($p['whatsapp'] ?? 1) ? ' checked' : '' ?>> WhatsApp</label></div><?php endfor; ?>
<h3>Emails</h3><div class="grid"><?php for ($i = 0; $i < max(2, count($em) + 1); $i++): $e = array_values($em)[$i] ?? []; ?><input type="email" name="email[]" value="<?= h((string)(is_array($e) ? ($e['email'] ?? '') : $e)) ?>"><?php endfor; ?></div>
<h3>Contact persons</h3><?php for ($i = 0; $i < max(2, count($ct) + 1); $i++): $p = $ct[$i] ?? []; ?><div class="row"><input name="cname[<?= $i ?>]" value="<?= h((string)($p['name'] ?? '')) ?>" placeholder="Name"><input name="cdesig[<?= $i ?>]" value="<?= h((string)($p['designation'] ?? '')) ?>" placeholder="Designation"></div><?php endfor; ?>
<h3>Addresses <span class="muted">(the first is the default for delivery and billing)</span></h3><?php for ($i = 0; $i < max(1, count($ad) + 1); $i++): $x = $ad[$i] ?? []; ?>
<div class="grid"><input name="alabel[<?= $i ?>]" value="<?= h((string)($x['label'] ?? '')) ?>" placeholder="Label (Factory, Office…)"><input name="line1[<?= $i ?>]" value="<?= h((string)($x['line1'] ?? '')) ?>" placeholder="Address"><input name="line2[<?= $i ?>]" value="<?= h((string)($x['line2'] ?? '')) ?>" placeholder="Area">
<input name="city[<?= $i ?>]" value="<?= h((string)($x['city'] ?? '')) ?>" placeholder="City"><input name="state[<?= $i ?>]" value="<?= h((string)($x['state'] ?? '')) ?>" placeholder="State / region"><input name="postcode[<?= $i ?>]" value="<?= h((string)($x['postcode'] ?? '')) ?>" placeholder="Postcode"><input name="country[<?= $i ?>]" value="<?= h((string)($x['country'] ?? '')) ?>" placeholder="Country"></div><?php endfor; ?>
<label><input type="checkbox" name="force" value="1"> It is a different customer (save even if it looks like an existing one)</label><br><button class="btn">Save</button></form>
<?php };
if (in_array($view, ['cust', 'edit'], true)) { $mg = $db->prepare('SELECT merged_into FROM crm_customer WHERE id = ?'); $mg->execute([(int)($_GET['id'] ?? 0)]); if ($to = (int)$mg->fetchColumn()) redirect('index.php?view=cust&id=' . $to); }
ui_head('CRM', $u, 'crm'); ?>
<nav class="row" style="flex-wrap:wrap"><a class="btn sm<?= $view === 'list' ? '' : ' ghost' ?>" href="index.php">Customers</a> <a class="btn sm ghost" href="orders.php">Orders</a>
<?php if ($R['edit']): ?> <a class="btn sm<?= $view === 'new' ? '' : ' ghost' ?>" href="?view=new">New customer</a><?php endif; ?>
 <a class="btn sm<?= $view === 'products' ? '' : ' ghost' ?>" href="?view=products">Products</a>
<?php if ($R['admin']): ?> <a class="btn sm<?= $view === 'dups' ? '' : ' ghost' ?>" href="?view=dups">Duplicates</a> <a class="btn sm<?= $view === 'import' ? '' : ' ghost' ?>" href="?view=import">Import</a><?php endif; ?>
<?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php if ($view === 'list'): $q = (string)($_GET['q'] ?? ''); $st = in_array($_GET['st'] ?? '', ['active', 'archived', 'all'], true) ? (string)$_GET['st'] : 'active'; $rows = crm_customer_list($db, $u, $q, $st); ?>
<form class="card row" style="flex-wrap:wrap"><input name="q" value="<?= h($q) ?>" placeholder="Name, code, <?= h($taxLabel) ?>, mobile or email"><?= $sel(['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'], 'st', $st) ?><button class="btn sm">Find</button><?php if ($R['all']): ?> <a class="btn sm ghost" href="?view=csv">Download CSV</a><?php endif; ?></form>
<div class="card"><table><tr><th>Code</th><th>Customer</th><th>Mobile</th><th>City</th><th>Category</th><th>Owner</th></tr>
<?php foreach ($rows as $c): ?><tr><td><?= h((string)$c['code']) ?></td><td><a href="?view=cust&id=<?= (int)$c['id'] ?>"><?= h($c['name']) ?></a><?= $c['status'] === 'archived' ? ' <span class="muted">(archived)</span>' : '' ?></td><td><?= h((string)$c['mobile']) ?></td><td><?= h((string)$c['city']) ?></td><td><?= h($c['category']) ?></td><td><?= h((string)$c['owner']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No customers<?= $q !== '' ? ' match' : ' yet' ?>.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'new' && $R['edit']): $f = $_SESSION['crm_form'] ?? []; unset($_SESSION['crm_form']);
    $c = $f ? ['name' => $f['name'] ?? '', 'kind' => $f['kind'] ?? 'company', 'tax_id' => $f['tax_id'] ?? '', 'category' => $f['category'] ?? '', 'industry' => $f['industry'] ?? '', 'source' => $f['source'] ?? '', 'website' => $f['website'] ?? '', 'branch_id' => $f['branch_id'] ?? '',
              'phones' => array_map(fn($n, $i) => ['number' => $n, 'label' => $f['plabel'][$i] ?? '', 'whatsapp' => !empty($f['wa'][$i])], (array)($f['phone'] ?? []), array_keys((array)($f['phone'] ?? []))), 'emails' => array_map(fn($e) => ['email' => $e], array_filter((array)($f['email'] ?? []))),
              'contacts' => array_map(fn($n, $i) => ['name' => $n, 'designation' => $f['cdesig'][$i] ?? ''], (array)($f['cname'] ?? []), array_keys((array)($f['cname'] ?? []))), 'addresses' => array_map(fn($l, $i) => ['label' => $f['alabel'][$i] ?? '', 'line1' => $l, 'line2' => $f['line2'][$i] ?? '', 'city' => $f['city'][$i] ?? '', 'state' => $f['state'][$i] ?? '', 'postcode' => $f['postcode'][$i] ?? '', 'country' => $f['country'][$i] ?? ''], (array)($f['line1'] ?? []), array_keys((array)($f['line1'] ?? [])))] : [];
    $custForm($c);

elseif (in_array($view, ['cust', 'edit'], true)):
    $c = crm_customer_get($db, (int)($_GET['id'] ?? 0));
    if (!$c || !crm_can($u, $c)): ?><div class="card">Not found.</div><?php elseif ($view === 'edit' && crm_can($u, $c, true)): $custForm($c); else:
    $own = $db->prepare('SELECT name FROM users WHERE id = ?'); $own->execute([(int)$c['owner_user']]);
    $notes = $db->prepare("SELECT n.*, u.name AS who FROM crm_note n LEFT JOIN users u ON u.id = n.by_user WHERE n.entity = 'customer' AND n.entity_id = ? ORDER BY n.id DESC"); $notes->execute([(int)$c['id']]);
    $hist = $db->prepare("SELECT h.*, u.name AS who FROM crm_change h LEFT JOIN users u ON u.id = h.by_user WHERE h.entity = 'customer' AND h.entity_id = ? ORDER BY h.id DESC LIMIT 60"); $hist->execute([(int)$c['id']]);
    $d = crm_dupes(['id' => (int)$c['id'], 'name' => $c['name'], 'tax_id' => (string)$c['tax_id'], 'phones' => array_column($c['phones'], 'number'), 'emails' => array_column($c['emails'], 'email'), 'places' => array_merge(array_column($c['addresses'], 'city'), array_column($c['addresses'], 'postcode'))], crm_dupe_rows($db), crm_not_same($db, 'cust', (int)$c['id']), (int)$set['phone_digits']); ?>
<div class="card"><div class="row" style="justify-content:space-between"><h2><?= h($c['name']) ?> <span class="muted"><?= h((string)$c['code']) ?></span></h2><div class="row"><?php if ($R['edit'] && $c['status'] === 'active'): ?><a class="btn sm" href="orders.php?view=new&cust=<?= (int)$c['id'] ?>">New order</a><?php endif; ?><?php if (crm_can($u, $c, true)): ?><a class="btn sm ghost" href="?view=edit&id=<?= (int)$c['id'] ?>">Change</a><?php endif; ?></div></div>
<p><?= h(implode(' · ', array_filter([CRM_KINDS[$c['kind']] ?? '', $c['category'], $c['industry'], $bn[(int)$c['branch_id']] ?? '', $c['tax_id'] ? $taxLabel . ' ' . $c['tax_id'] : '', 'Owner: ' . (string)$own->fetchColumn()]))) ?></p>
<?php if ($c['status'] === 'archived'): ?><p><b>Archived</b> — <?= h($c['archived_reason']) ?></p><?php endif; ?>
<?php if ($c['website']): ?><p><a href="<?= h($c['website']) ?>" rel="noopener" target="_blank"><?= h($c['website']) ?></a></p><?php endif; ?>
<div class="grid"><div><h3>Mobiles</h3><?php foreach ($c['phones'] as $p): ?><div>+<?= h($p['number']) ?><?= $p['label'] ? ' · ' . h($p['label']) : '' ?><?= (int)$p['whatsapp'] ? ' · <a href="https://wa.me/' . h($p['number']) . '" rel="noopener" target="_blank">WhatsApp</a>' : '' ?></div><?php endforeach; ?></div>
<div><h3>Emails</h3><?php foreach ($c['emails'] as $e): ?><div><a href="mailto:<?= h($e['email']) ?>"><?= h($e['email']) ?></a></div><?php endforeach; ?></div>
<div><h3>Contacts</h3><?php foreach ($c['contacts'] as $p): ?><div><?= h($p['name']) ?><?= $p['designation'] ? ' · ' . h($p['designation']) : '' ?></div><?php endforeach; ?></div></div>
<h3>Addresses</h3><?php foreach ($c['addresses'] as $x): ?><div><?= $x['label'] ? '<b>' . h($x['label']) . ':</b> ' : '' ?><?= h(implode(', ', array_filter([$x['line1'], $x['line2'], $x['city'], $x['state'], $x['postcode'], $x['country']]))) ?><?= (int)$x['ship_default'] ? ' <span class="muted">(default)</span>' : '' ?></div><?php endforeach; ?></div>
<?php if ($d && $R['admin']): ?><div class="card"><h3>Looks like</h3><?php foreach ($d as $x): ?><form method="post" class="row"><?= $hid('merge', 0) ?><input type="hidden" name="keep" value="<?= (int)$c['id'] ?>"><input type="hidden" name="drop" value="<?= $x['id'] ?>"><a href="?view=cust&id=<?= $x['id'] ?>"><?= h($x['name']) ?></a> <span class="muted"><?= h($x['why']) ?></span> <button class="btn sm ghost" onclick="return confirm('Merge that record into this one?')">Merge it into this one</button></form><?php endforeach; ?></div><?php endif; ?>
<?php $ords = $db->prepare('SELECT id, order_no, status, received_at FROM crm_order WHERE customer_id = ? ORDER BY id DESC LIMIT 20'); $ords->execute([(int)$c['id']]); $ords = $ords->fetchAll(); if ($ords): ?>
<div class="card"><h3>Orders</h3><?php foreach ($ords as $x): ?><div><a href="orders.php?view=order&id=<?= (int)$x['id'] ?>"><?= h($x['order_no']) ?></a> · <?= h(substr($x['received_at'], 0, 10)) ?> · <?= h($x['status']) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" class="card"><?= $hid('note', (int)$c['id']) ?><h3>Notes</h3><textarea name="body" rows="2" placeholder="Add a note"></textarea><button class="btn sm">Add</button>
<?php foreach ($notes as $n): ?><div><span class="muted"><?= h(substr((string)$n['at'], 0, 16)) ?> · <?= h((string)$n['who']) ?></span> — <?= h($n['body']) ?></div><?php endforeach; ?></form>
<?php if ($R['all'] && crm_can($u, $c, true)): ?><form method="post" class="card row"><?= $hid('owner', (int)$c['id']) ?><select name="owner"><?php foreach ($db->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name') as $x): ?><option value="<?= (int)$x['id'] ?>"<?= (int)$x['id'] === (int)$c['owner_user'] ? ' selected' : '' ?>><?= h($x['name']) ?></option><?php endforeach; ?></select><button class="btn sm ghost">Change owner</button></form><?php endif; ?>
<?php if ($R['admin']): ?><form method="post" class="card row"><?= $hid('status', (int)$c['id']) ?><input type="hidden" name="to" value="<?= $c['status'] === 'archived' ? 'active' : 'archived' ?>"><input name="reason" placeholder="Why" required><button class="btn sm ghost"><?= $c['status'] === 'archived' ? 'Bring back' : 'Archive' ?></button></form><?php endif; ?>
<details class="card"><summary>History</summary><?php foreach ($hist as $x): ?><div class="muted"><?= h(substr((string)$x['at'], 0, 16)) ?> · <?= h((string)$x['who']) ?> · <?= h($x['field']) ?>: “<?= h((string)$x['old_value']) ?>” → “<?= h((string)$x['new_value']) ?>”<?= $x['reason'] ? ' (' . h($x['reason']) . ')' : '' ?></div><?php endforeach; ?></details>
<?php endif;

elseif ($view === 'products'): $all = !empty($_GET['off']); $ps = $db->query('SELECT * FROM crm_product WHERE merged_into IS NULL' . ($all ? '' : ' AND active = 1') . ' ORDER BY name')->fetchAll(); $ep = null; if ((int)($_GET['pid'] ?? 0)) foreach ($ps as $p) if ((int)$p['id'] === (int)$_GET['pid']) $ep = $p; ?>
<?php if ($R['all']): $ep ??= ['id' => 0, 'sku' => '', 'name' => '', 'category' => '', 'unit' => 'pcs', 'tax_code' => '', 'tax_pct' => '', 'rate' => '', 'notes' => '']; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="product"><input type="hidden" name="pid" value="<?= (int)$ep['id'] ?>"><h2><?= $ep['id'] ? 'Change product' : 'New product' ?></h2>
<div class="grid"><label>Code <input name="sku" value="<?= h($ep['sku']) ?>" placeholder="given when blank"></label><label>Name * <input name="name" value="<?= h($ep['name']) ?>" required></label><label>Category <input name="category" value="<?= h($ep['category']) ?>"></label>
<label>Unit <input name="unit" list="units" value="<?= h($ep['unit']) ?>"></label><label>Tax code (HSN / SAC) <input name="tax_code" value="<?= h($ep['tax_code']) ?>"></label><label>Tax % <input name="tax_pct" value="<?= h((string)$ep['tax_pct']) ?>"></label><label>Rate <input name="rate" value="<?= h((string)$ep['rate']) ?>"></label></div>
<datalist id="units"><?php foreach (crm_lines($set['units']) as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist><input name="notes" value="<?= h($ep['notes']) ?>" placeholder="Notes"><button class="btn">Save</button></form><?php endif; ?>
<div class="card"><div class="row"><h2>Products</h2> <a class="btn sm ghost" href="?view=products<?= $all ? '' : '&off=1' ?>"><?= $all ? 'In use only' : 'Show switched off too' ?></a></div><table><tr><th>Code</th><th>Name</th><th>Unit</th><th>Tax</th><th>Rate</th><th></th></tr>
<?php foreach ($ps as $p): ?><tr><td><?= h($p['sku']) ?></td><td><?= h($p['name']) ?><div class="muted"><?= h($p['category']) ?></div></td><td><?= h($p['unit']) ?></td><td><?= h($p['tax_code']) ?> <?= (float)$p['tax_pct'] ?>%</td><td><?= h((string)$p['rate']) ?></td>
<td><?php if ($R['all']): ?><a class="btn sm ghost" href="?view=products&pid=<?= (int)$p['id'] ?>">Change</a><?php endif; ?><?php if ($R['admin']): ?><form method="post" class="row" style="display:inline-flex"><?= csrf_field() ?><input type="hidden" name="a" value="pstatus"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><input type="hidden" name="on" value="<?= (int)$p['active'] ? '0' : '1' ?>"><?php if ((int)$p['active']): ?><input name="reason" placeholder="Why"><?php endif; ?><button class="btn sm ghost"><?= (int)$p['active'] ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$ps): ?><tr><td colspan="6" class="muted">No products yet.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'dups' && $R['admin']): $pairs = crm_dupe_pairs($db); ?>
<div class="card"><h2>Possible duplicate customers</h2><p class="muted">Checked across the whole organisation: same <?= h($taxLabel) ?>, mobile or email, or a similar name at the same place. Merging keeps one record and points the other at it; nothing is deleted.</p>
<table><?php foreach ($pairs as $p): ?><tr><td><a href="?view=cust&id=<?= $p['a'] ?>"><?= h($p['an']) ?></a></td><td><a href="?view=cust&id=<?= $p['b'] ?>"><?= h($p['bn']) ?></a></td><td class="muted"><?= h($p['why']) ?></td>
<td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="merge"><input type="hidden" name="keep" value="<?= $p['a'] ?>"><input type="hidden" name="drop" value="<?= $p['b'] ?>"><button class="btn sm ghost">Keep the first</button></form>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="merge"><input type="hidden" name="keep" value="<?= $p['b'] ?>"><input type="hidden" name="drop" value="<?= $p['a'] ?>"><button class="btn sm ghost">Keep the second</button></form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="notsame"><input type="hidden" name="x" value="<?= $p['a'] ?>"><input type="hidden" name="y" value="<?= $p['b'] ?>"><button class="btn sm ghost">Not the same</button></form></td></tr><?php endforeach; ?>
<?php if (!$pairs): ?><tr><td class="muted">None found.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'import' && $R['admin']): $p = $_SESSION['crm_plan'] ?? null; if ($p && ($p['uid'] !== $uid || time() - $p['at'] > 3600)) $p = null; ?>
<form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="import"><h2>Import</h2>
<p class="muted">CSV or Excel (.xlsx), columns found by their titles. Checking writes nothing; the file is not kept.</p>
<div class="row"><select name="what"><option value="customers">Customers</option><option value="products">Products</option></select><input type="file" name="f" accept=".csv,.xlsx,.txt" required><label><input type="checkbox" name="apply" value="1"> Products: save straight away</label><button class="btn sm">Check the file</button></div></form>
<?php if ($p): $cnt = array_count_values(array_column($p['rows'], 'state')); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="importsave"><h2><?= h($p['name']) ?></h2>
<p><?= (int)($cnt['new'] ?? 0) ?> new · <?= (int)($cnt['there'] ?? 0) ?> already there (only what they lack is added) · <?= (int)($cnt['maybe'] ?? 0) ?> possible duplicates (tick to add as new) · <?= (int)($cnt['error'] ?? 0) ?> not read</p>
<table><tr><th>Line</th><th>Customer</th><th>Mobiles / emails</th><th>City</th><th>Result</th></tr>
<?php foreach ($p['rows'] as $i => $r): ?><tr><td><?= (int)$r['line'] ?></td><td><?= h($r['name']) ?><?= $r['merged'] ?? 0 ? ' <span class="muted">(+' . (int)$r['merged'] . ' rows of the same customer)</span>' : '' ?></td><td class="muted"><?= h(implode(', ', array_merge($r['phones'] ?? [], $r['emails'] ?? []))) ?><?= $r['bad'] ?? [] ? ' · not read: ' . h(implode(', ', array_unique($r['bad']))) : '' ?></td><td><?= h($r['city'] ?? '') ?></td>
<td><?= ['new' => 'New', 'there' => 'Already there: ' . h($r['match']['name'] ?? ''), 'maybe' => '<label><input type="checkbox" name="maybe[]" value="' . $i . '"> Add as new</label> — looks like ' . h($r['match']['name'] ?? '') . ' <span class="muted">(' . h($r['match']['why'] ?? '') . ')</span>', 'error' => 'Not read: ' . h($r['why'] ?? '')][$r['state']] ?></td></tr><?php endforeach; ?></table>
<button class="btn">Save</button></form><?php endif; ?>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['dispatch_roles' => 'Dispatch orders (in their branches)', 'all_roles' => 'Work on every customer (in their branches)', 'own_roles' => 'Work on their own customers (and add new ones)', 'view_roles' => 'Read every customer', 'admin_roles' => 'Merge, import, archive, products on / off'] as $k => $l): ?>
<h3><?= h($l) ?></h3><div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $code => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($code) ?>"<?= in_array($code, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<div class="grid"><label>Customer categories (one a line) <textarea name="categories" rows="5"><?= h($set['categories']) ?></textarea></label><label>Units (one a line) <textarea name="units" rows="5"><?= h($set['units']) ?></textarea></label>
<label>Digits of a local mobile number (for the duplicate check) <input type="number" name="phone_digits" min="6" max="12" value="<?= (int)$set['phone_digits'] ?>"></label></div>
<h3>Orders</h3><div class="grid"><label>Order number starts with <input name="order_prefix" value="<?= h($set['order_prefix']) ?>" maxlength="6"></label>
<label>Customer tracking link lasts (days) <input type="number" name="link_days" min="1" max="365" value="<?= (int)$set['link_days'] ?>"></label><label>Same-day cut-off (HH:MM, empty = whole day) <input name="same_day_cutoff" value="<?= h($set['same_day_cutoff']) ?>"></label>
<label>Where orders come from (one a line) <textarea name="order_sources" rows="5"><?= h($set['order_sources']) ?></textarea></label><label>Blockers on our side <textarea name="internal_blockers" rows="3"><?= h($set['internal_blockers']) ?></textarea></label><label>Blockers on the customer's side (left out of the same-day score) <textarea name="customer_blockers" rows="3"><?= h($set['customer_blockers']) ?></textarea></label></div>
<?php foreach (['ready_needs_no_blockers' => 'An order is ready for dispatch only with no open blocker', 'notify_default' => 'New orders email the customer at each stage', 'public_po' => 'Show the PO number on the tracking page', 'public_qty' => 'Show quantities on the tracking page'] as $k => $l): ?><label><input type="checkbox" name="<?= $k ?>" value="1"<?= $set[$k] ? ' checked' : '' ?>> <?= h($l) ?></label><br><?php endforeach; ?>
<p class="muted">The tax ID is checked by the country rules (<?= h($taxLabel) ?>).</p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
