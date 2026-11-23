<?php
// Asset register — list, one asset (details, give / take back, scrap, invoice, history), add, reported issues, audit, categories, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/assets.php';
$u = module_require('assets');
$db = pdo();
$set = as_settings(); $edit = as_can_edit($u, $set); $conf = as_can_confirm($u, $set);
$view = (string)($_GET['view'] ?? 'list');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isAdmin = has_role($u, 'SUPERADMIN');
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $to = 'index.php?view=one&id=' . $id;
    try {
        switch ($a) {
            case 'save': $id = as_save($db, $u, $id, $_POST); flash('Saved.'); $to = 'index.php?view=one&id=' . $id; break;
            case 'give': as_allot($db, $u, $id, (string)$_POST['code'], (string)$_POST['on'], (string)($_POST['acc'] ?? ''), (string)($_POST['note'] ?? '')); flash('Given.'); break;
            case 'take': as_return($db, $u, $id, (string)$_POST['on'], (string)$_POST['cond'], (string)($_POST['note'] ?? '')); flash('Taken back.'); break;
            case 'invoice':
                $f = $_FILES['file'] ?? null;
                if (!$f || (int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new InvalidArgumentException('Choose the invoice file.');
                as_file_add($db, $u, $id, (string)file_get_contents($f['tmp_name']), (string)$f['name']); flash('Invoice added.'); break;
            case 'resolve': as_resolve($db, $u, (int)$_POST['issue'], (string)$_POST['outcome'], (string)($_POST['note'] ?? '')); flash('Resolved.'); $to = 'index.php?view=issues'; break;
            case 'audit': as_audit_step($db, $u, (string)$_POST['act'], (string)($_POST['label'] ?? ''), (string)($_POST['due'] ?? '')); flash('Done.'); $to = 'index.php?view=audit'; break;
            case 'cat': as_cat_save($db, $u, (int)($_POST['cid'] ?? 0), $_POST); flash('Saved.'); $to = 'index.php?view=cats'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = as_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['assets'] ?? ['roles' => module_roles('assets')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['assets', 1, implode(',', $st['roles'] ?: module_roles('assets')), json_encode($new), (int)$u['id']]);
                audit((int)$u['id'], 'module_settings', 'assets');
                flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'save' && !$id) $to = 'index.php?view=add'; }
    redirect($to);
}

if ($view === 'file') {
    $q = $db->prepare('SELECT * FROM asset_file WHERE id = ?'); $q->execute([(int)($_GET['f'] ?? 0)]); $f = $q->fetch();
    $a = $f ? as_get($db, (int)$f['asset_id']) : null;
    if (!$f || !$a || ($a['branch_id'] && !emp_in_scope($u, ['branch_id' => $a['branch_id']]))) { http_response_code(404); exit('Not found'); }
    header('Content-Type: ' . $f['mime']); header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    echo $f['bytes']; exit;
}

$cats = as_cats($db, true); $brs = branches();
$form = function (?array $a) use ($cats, $brs, $edit, $conf): string {
    $a ??= ['id' => 0, 'cat_id' => '', 'make' => '', 'model' => '', 'serial' => '', 'imei1' => '', 'imei2' => '', 'sim_no' => '', 'operator' => '', 'card_last4' => '', 'bought_on' => '', 'vendor' => '', 'invoice_no' => '', 'cost' => '', 'warranty_till' => '', 'branch_id' => '', 'status' => 'stock', 'notes' => ''];
    $v = fn($k) => h((string)($a[$k] ?? ''));
    $o = '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="a" value="save"><input type="hidden" name="id" value="' . (int)$a['id'] . '"><div class="grid">'
       . '<label>Category <select name="cat_id" required><option value="">—</option>';
    foreach ($cats as $c) if ((int)$c['active'] || (int)$c['id'] === (int)$a['cat_id']) $o .= '<option value="' . (int)$c['id'] . '"' . ((int)$c['id'] === (int)$a['cat_id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>';
    $o .= '</select></label>';
    foreach (['make' => 'Make', 'model' => 'Model', 'serial' => 'Serial no.', 'imei1' => 'IMEI 1 (phones)', 'imei2' => 'IMEI 2 (phones)', 'sim_no' => 'SIM / mobile number', 'operator' => 'Operator', 'card_last4' => 'Card — last 4 digits only',
              'vendor' => 'Bought from', 'invoice_no' => 'Invoice no.', 'cost' => 'Cost'] as $k => $l) $o .= '<label>' . $l . ' <input name="' . $k . '" value="' . $v($k) . '"></label>';
    $o .= '<label>Bought on <input type="date" name="bought_on" value="' . $v('bought_on') . '"></label><label>Warranty till <input type="date" name="warranty_till" value="' . $v('warranty_till') . '"></label>';
    if ($brs) { $o .= '<label>Branch <select name="branch_id"><option value="">—</option>'; foreach ($brs as $b) $o .= '<option value="' . (int)$b['id'] . '"' . ((int)$b['id'] === (int)$a['branch_id'] ? ' selected' : '') . '>' . h($b['name']) . '</option>'; $o .= '</select></label>'; }
    if ($a['id']) {
        $o .= '<label>Status <select name="status">';
        foreach (AS_STATUS as $k => $l) if ($k === $a['status'] || in_array($k, AS_EDITABLE_STATUS, true) || $k === 'proposed' || ($k === 'scrapped' && $conf && $a['status'] === 'proposed')) $o .= '<option value="' . $k . '"' . ($k === $a['status'] ? ' selected' : '') . '>' . h($l) . '</option>';
        $o .= '</select></label><label>Reason (to propose scrap) <input name="reason"></label>';
    } else $o .= '<label>Status <select name="status"><option value="stock">In stock</option><option value="reserved">Reserved for a joiner</option><option value="repair">Under repair</option></select></label>';
    return $o . '</div><label>Notes <input name="notes" value="' . $v('notes') . '"></label>' . ($edit ? '<button class="btn sm">Save</button>' : '') . '</form>';
};

ui_head('Asset register', $u, 'assets');
$open = (int)$db->query("SELECT COUNT(*) FROM asset_issue WHERE status = 'open'")->fetchColumn();
$tabs = ['list' => 'Register'] + ($edit ? ['add' => 'Add asset'] : []) + ['issues' => 'Reported' . ($open ? " ($open)" : ''), 'audit' => 'Yearly audit'] + ($conf ? ['cats' => 'Categories'] : []) + ($isAdmin ? ['settings' => 'Settings'] : []); ?>
<nav class="row"><?php foreach ($tabs as $k => $l): ?><a class="btn sm<?= $view === $k ? '' : ' ghost' ?>" href="?view=<?= $k ?>"><?= h($l) ?></a><?php endforeach; ?> <a class="btn sm ghost" href="mine.php">My assets</a></nav>

<?php if ($view === 'list'):
    $all = as_all($db, $u); $fs = (string)($_GET['st'] ?? ''); $fc = (int)($_GET['cat'] ?? 0); $qq = trim((string)($_GET['q'] ?? ''));
    $rows = array_filter($all, fn($a) => ($fs === '' || $a['status'] === $fs) && (!$fc || (int)$a['cat_id'] === $fc) && ($qq === '' || stripos($a['tag'] . ' ' . $a['make'] . ' ' . $a['model'] . ' ' . $a['serial'] . ' ' . $a['imei1'] . ' ' . $a['sim_no'] . ' ' . $a['holder_name'], $qq) !== false));
    $byId = array_column($all, null, 'id'); ?>
<section class="card"><form class="row"><input type="hidden" name="view" value="list"><input name="q" value="<?= h($qq) ?>" placeholder="Find tag, serial, IMEI, number, person"><select name="cat" aria-label="Category"><option value="">Every category</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $fc === (int)$c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select>
  <select name="st" aria-label="Status"><option value="">Every status</option><?php foreach (AS_STATUS as $k => $l): ?><option value="<?= $k ?>"<?= $fs === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select><button class="btn sm ghost">Show</button></form>
  <table><thead><tr><th>Tag</th><th>Item</th><th>With</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $a): $d = as_dups($a, $byId); $w = as_warranty($a['warranty_till'], $today, (int)$set['warranty_days']); ?><tr><td><?= h($a['tag']) ?></td><td><?= h($a['cat'] . ' · ' . trim($a['make'] . ' ' . $a['model'])) ?><?= $d ? ' <span class="pill off">Same as ' . h(implode(', ', $d)) . '?</span>' : '' ?><?= $w ? ' <span class="pill port">Warranty ' . ($w === 'over' ? 'over' : 'ending') . '</span>' : '' ?></td>
    <td><?= h((string)$a['holder_name']) ?></td><td><?= h(AS_STATUS[$a['status']]) ?></td><td><a href="?view=one&id=<?= (int)$a['id'] ?>">Open</a></td></tr><?php endforeach; ?>
  <?= $rows ? '' : '<tr><td colspan="5" class="muted">None.</td></tr>' ?></tbody></table><p class="muted"><?= count($rows) ?> of <?= count($all) ?> assets.</p></section>

<?php elseif ($view === 'add' && $edit): ?><h2>Add an asset</h2><p class="muted">The tag is given on save. Phones: IMEI; SIM cards: number and operator; cards: the last 4 digits only.</p><?= $form(null) ?>

<?php elseif ($view === 'one' && $id && ($a = as_get($db, $id)) && (!$a['branch_id'] || emp_in_scope($u, ['branch_id' => $a['branch_id']]))):
    $al = as_open_allot($db, $id); $hist = $db->prepare('SELECT l.*, us.name AS who FROM asset_log l LEFT JOIN users us ON us.id = l.by_user WHERE asset_id = ? ORDER BY id DESC'); $hist->execute([$id]);
    $allots = $db->prepare('SELECT al.*, e.name FROM asset_allot al LEFT JOIN employee e ON e.code = al.emp_code WHERE asset_id = ? ORDER BY id DESC'); $allots->execute([$id]);
    $files = $db->prepare('SELECT id, name, at FROM asset_file WHERE asset_id = ? ORDER BY id'); $files->execute([$id]); ?>
<h2><?= h($a['tag']) ?> · <?= h($a['cat']) ?> <span class="pill"><?= h(AS_STATUS[$a['status']]) ?></span></h2>
<?php if ($a['status'] === 'proposed'): ?><p class="flash err">Proposed for scrap: <?= h($a['scrap_reason']) ?><?= $conf ? ' — set Scrapped to confirm, or another status to keep it.' : '' ?></p><?php endif; ?>
<?= $a['status'] === 'scrapped' ? '<section class="card"><p>Scrapped: ' . h($a['scrap_reason']) . '</p></section>' : $form($a) ?>
<?php if ($edit && $a['status'] !== 'scrapped'): ?><section class="card"><?php if ($al): ?><h3>With <?= h((string)(emp_by_code($db, $al['emp_code'])['name'] ?? $al['emp_code'])) ?> since <?= h(date('j M Y', strtotime($al['given_on']))) ?><?= $al['ack_at'] ? ' · confirmed' : ' · not confirmed yet' ?></h3>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="take"><input type="hidden" name="id" value="<?= $id ?>"><input type="date" name="on" value="<?= $today ?>" max="<?= $today ?>"><select name="cond"><?php foreach (AS_CONDITIONS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select><input name="note" placeholder="Note"><button class="btn sm">Take back</button></form>
  <?php elseif (in_array($a['status'], ['stock', 'reserved'], true)): ?><h3>Give to</h3><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="give"><input type="hidden" name="id" value="<?= $id ?>"><select name="code" required aria-label="Give to"><option value="">Person on the roll</option><?php foreach (emp_on_roll($db, $u) as $e): ?><option value="<?= h($e['code']) ?>"><?= h($e['name'] . ' · ' . $e['code']) ?></option><?php endforeach; ?></select>
    <input type="date" name="on" value="<?= $today ?>" max="<?= $today ?>"><input name="acc" placeholder="With (charger, case…)"><input name="note" placeholder="Note"><button class="btn sm">Give</button></form><?php endif; ?></section><?php endif; ?>
<section class="card"><h3>Invoices</h3><?php foreach ($files as $f): ?><a href="?view=file&f=<?= (int)$f['id'] ?>" target="_blank"><?= h($f['name']) ?></a> <small class="muted"><?= h(substr($f['at'], 0, 10)) ?></small> · <?php endforeach; ?>
  <?php if ($edit): ?><form method="post" enctype="multipart/form-data" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="invoice"><input type="hidden" name="id" value="<?= $id ?>"><input type="file" name="file" accept="application/pdf,image/*" required aria-label="File"><button class="btn sm ghost">Add invoice</button></form><p class="muted">Earlier copies are kept.</p><?php endif; ?></section>
<section class="card"><h3>Who had it</h3><table><tbody><?php foreach ($allots as $r): ?><tr><td><?= h((string)($r['name'] ?: $r['emp_code'])) ?></td><td><?= h($r['given_on']) ?> – <?= h((string)($r['returned_on'] ?? 'now')) ?></td><td><?= h(AS_CONDITIONS[$r['return_condition']] ?? '') ?> <?= h($r['return_note']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<section class="card"><h3>Change history</h3><table><tbody><?php foreach ($hist as $l): ?><tr><td><?= h(date('j M Y H:i', strtotime($l['at']))) ?></td><td><?= h(ucfirst($l['what'])) ?></td><td><small><?= h(implode(' · ', array_map(fn($k, $v) => $k . ': ' . (is_scalar($v) ? (string)$v : ''), array_keys(json_decode((string)$l['after_json'], true) ?: []), json_decode((string)$l['after_json'], true) ?: []))) ?></small></td><td><?= h((string)$l['who']) ?></td></tr><?php endforeach; ?></tbody></table></section>

<?php elseif ($view === 'issues'): $rows = $db->query("SELECT i.*, a.tag, e.name FROM asset_issue i JOIN asset a ON a.id = i.asset_id LEFT JOIN employee e ON e.code = i.emp_code ORDER BY i.status = 'open' DESC, i.id DESC LIMIT 300")->fetchAll(); ?>
<section class="card"><h2>Reported by people</h2><table><tbody><?php foreach ($rows as $i): ?><tr><td><?= h(date('j M Y', strtotime($i['created_at']))) ?></td><td><a href="?view=one&id=<?= (int)$i['asset_id'] ?>"><?= h($i['tag']) ?></a> · <?= h($i['type']) ?> · <?= h((string)$i['name']) ?><br><small class="muted"><?= h($i['description']) ?></small></td>
  <td><?php if ($i['status'] === 'open' && $edit): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="resolve"><input type="hidden" name="issue" value="<?= (int)$i['id'] ?>"><select name="outcome"><?php foreach (AS_OUTCOMES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select><input name="note" placeholder="Note"><button class="btn sm ghost">Resolve</button></form><?php else: ?><?= h(AS_OUTCOMES[$i['outcome']] ?? 'Open') ?><?php endif; ?></td></tr><?php endforeach; ?>
<?= $rows ? '' : '<tr><td class="muted">Nothing reported.</td></tr>' ?></tbody></table></section>

<?php elseif ($view === 'audit'): $au = as_audit_open($db); $past = $db->query('SELECT * FROM asset_audit WHERE closed_at IS NOT NULL ORDER BY id DESC LIMIT 10')->fetchAll(); ?>
<section class="card"><h2>Yearly audit</h2><?php if ($au): $p = as_audit_progress($db, $au); ?><p><b><?= h($au['label']) ?></b> · started <?= h(date('j M Y', strtotime($au['started_on']))) ?><?= $au['due_on'] ? ' · due ' . h(date('j M Y', strtotime($au['due_on']))) : '' ?> · <?= $p['done'] ?> of <?= $p['total'] ?> items confirmed</p>
  <?php if ($p['pending']): ?><p class="muted">Still to confirm: <?= h(implode(', ', $p['pending'])) ?></p><?php endif; ?>
  <?php if ($conf): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="audit"><input type="hidden" name="act" value="close"><button class="btn sm">Close the audit</button></form><?php endif; ?>
<?php else: ?><p class="muted">No audit is open. Starting one asks everyone holding an item to confirm it again on My assets.</p><?php if ($conf): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="audit"><input type="hidden" name="act" value="start"><input name="label" placeholder="e.g. Audit <?= date('Y') ?>" required><input type="date" name="due" aria-label="Due date"><button class="btn sm">Start</button></form><?php endif; endif; ?>
<?php foreach ($past as $x): ?><p class="muted"><?= h($x['label']) ?> · closed <?= h(substr((string)$x['closed_at'], 0, 10)) ?> · <?= h($x['result']) ?></p><?php endforeach; ?></section>

<?php elseif ($view === 'cats' && $conf): ?>
<section class="card"><h2>Categories</h2><?php foreach ($cats as $c): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="cat"><input type="hidden" name="cid" value="<?= (int)$c['id'] ?>"><input name="name" value="<?= h($c['name']) ?>" aria-label="Name"><input name="code" value="<?= h($c['code']) ?>" size="6" aria-label="Tag code">
  <select name="kind" aria-label="Kind"><?php foreach (AS_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= $c['kind'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><label class="tick"><input type="checkbox" name="off" value="1"<?= (int)$c['active'] ? '' : ' checked' ?>> Off</label><button class="btn sm ghost">Save</button></form><?php endforeach; ?>
  <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="cat"><input name="name" placeholder="New category" required><input name="code" placeholder="Code" size="6" required><select name="kind" aria-label="Kind"><?php foreach (AS_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select><button class="btn sm">Add</button></form>
  <p class="muted">The kind decides which details are asked. A category in use stays on.</p></section>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2><div class="grid"><label>Tag prefix <input name="prefix" value="<?= h($set['prefix']) ?>"></label><label>Warn this many days before a warranty ends <input type="number" name="warranty_days" min="0" max="365" value="<?= (int)$set['warranty_days'] ?>"></label></div>
  <?php foreach (['edit_roles' => 'Add, change, give and take back', 'confirm_roles' => 'Confirm scrap, categories, audit'] as $k => $lab): ?><p><?= h($lab) ?>: <?php foreach (role_labels() as $rc => $rl): ?><label class="tick"><input type="checkbox" name="<?= $k ?>[]" value="<?= h($rc) ?>"<?= in_array($rc, $set[$k], true) ? ' checked' : '' ?>> <?= h($rl) ?></label> <?php endforeach; ?></p><?php endforeach; ?>
  <button class="btn sm">Save</button></form>
<?php else: ?><section class="card"><p>Not found.</p></section><?php endif;
ui_foot();
