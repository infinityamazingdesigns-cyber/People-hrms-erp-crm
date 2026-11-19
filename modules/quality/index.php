<?php
// Complaints & CAPA — register, new / change a case, one case (moves, owner, notes, evidence, repeats, CAPA with 5 whys,
// actions and effectiveness check, timeline), my actions, dashboard, CSV, settings. Rules in lib/quality.php.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/quality.php';
$u = module_require('quality');
$db = pdo(); $set = qc_settings(); $R = qc_rights($u); $uid = (int)$u['id']; $isAdmin = has_role($u, 'SUPERADMIN');
$view = (string)($_GET['view'] ?? 'list'); $today = date('Y-m-d');

if ($view === 'file') {
    try { $f = qc_file_get($db, $u, (int)($_GET['id'] ?? 0)); } catch (Throwable $e) { http_response_code(404); exit('Not found'); }
    header('Content-Type: ' . $f['mime']); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $f['name']) . '"');
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'"); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    echo $f['data']; exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $back = 'index.php?view=c&id=' . $id;
    try {
        switch ($a) {
            case 'save': $id = qc_save($db, $u, $_POST, $id); flash('Saved.'); $back = 'index.php?view=c&id=' . $id;
                if (!empty($_POST['submit'])) { qc_status($db, $u, $id, 'Submitted', ''); flash('Sent to quality.'); } break;
            case 'move': qc_status($db, $u, $id, (string)($_POST['to'] ?? ''), (string)($_POST['note'] ?? '')); flash('Moved to ' . $_POST['to'] . '.'); break;
            case 'assign': qc_assign($db, $u, $id, $_POST); flash('Saved.'); break;
            case 'note': qc_note($db, $u, $id, (string)($_POST['note'] ?? '')); flash('Note added.'); break;
            case 'file': $t = $_FILES['f']['tmp_name'] ?? ''; if (!is_string($t) || $t === '' || !is_uploaded_file($t)) throw new RuntimeException('Pick a file.');
                qc_file_add($db, $u, $id, $t, (string)$_FILES['f']['name']); flash('File added.'); break;
            case 'fileoff': qc_file_off($db, $u, (int)($_POST['fid'] ?? 0), (string)($_POST['reason'] ?? '')); flash('File taken off.'); break;
            case 'link': qc_link_set($db, $u, $id, (int)($_POST['other'] ?? 0), (string)($_POST['type'] ?? ''), (string)($_POST['note'] ?? '')); flash('Saved.'); break;
            case 'capa': qc_capa_start($db, $u, $id); flash('CAPA started.'); break;
            case 'capasave': qc_capa_save($db, $u, (int)($_POST['kid'] ?? 0), $_POST); flash('CAPA saved.'); break;
            case 'rc': qc_capa_confirm($db, $u, (int)($_POST['kid'] ?? 0), ($_POST['on'] ?? '') === '1', (string)($_POST['note'] ?? '')); flash('Saved.'); break;
            case 'action': qc_action_add($db, $u, (int)($_POST['kid'] ?? 0), $_POST); flash('Action added.'); break;
            case 'actup': qc_action_update($db, $u, (int)($_POST['aid'] ?? 0), (string)($_POST['what'] ?? ''), $_POST); flash('Saved.'); if (!empty($_POST['back'])) $back = 'index.php?view=actions'; break;
            case 'verify': qc_capa_verify($db, $u, (int)($_POST['kid'] ?? 0), (string)($_POST['result'] ?? ''), (string)($_POST['note'] ?? '')); flash('Check recorded.'); break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = qc_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['quality'] ?? ['roles' => module_roles('quality')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['quality', 1, implode(',', $st['roles'] ?: module_roles('quality')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'quality'); flash('Settings saved.'); $back = 'index.php?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'save') { $_SESSION['qc_form'] = $_POST; $back = 'index.php?view=' . ($id ? 'edit&id=' . $id : 'new'); } if ($a === 'settings') $back = 'index.php?view=settings'; if ($a === 'actup' && !empty($_POST['back'])) $back = 'index.php?view=actions'; }
    redirect($back);
}

$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$list = fn(array $x) => array_combine($x, $x) ?: [];
$bn = array_column(branches(), 'name', 'id');
$people = $db->query('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$f = ['status' => (string)($_GET['status'] ?? 'open'), 'severity' => (string)($_GET['severity'] ?? ''), 'q' => (string)($_GET['q'] ?? ''), 'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? '')];

if ($view === 'csv' && $R['view']) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="complaints.csv"');
    echo "\xEF\xBB\xBF" . implode(',', array_map('qc_csv_cell', ['Case', 'Reported', 'Kind', 'Status', 'Severity', 'Safety', 'Branch', 'Customer', 'Product', 'Serial', 'Batch', 'Faulty', 'Lot', 'Category', 'Title', 'Owner', 'Target', 'Closed', 'Repeat of', '5 Whys', 'Root cause'])) . "\r\n";
    $roots = []; foreach (qc_list($db, $u, ['status' => 'all'] + $f + ['limit' => 100000]) as $c) {
        $k = qc_capa($db, (int)$c['id']); $w = $k ? implode(' / ', array_filter(qc_whys($db, (int)$k['id']), 'strlen')) : '';
        $r = $c['root_id'] && (int)$c['root_id'] !== (int)$c['id'] ? ($roots[$c['root_id']] ??= (string)(qc_case($db, (int)$c['root_id'])['case_no'] ?? '')) : '';
        echo implode(',', array_map('qc_csv_cell', [$c['case_no'], $c['reported_on'], QC_KINDS[$c['kind']] ?? '', $c['status'], $c['severity'], $c['safety'] ? 'Yes' : '', $bn[$c['branch_id']] ?? '', $c['customer_text'], $c['product_text'], $c['serial_no'], $c['batch'],
            $c['qty'], $c['lot_qty'], $c['category'], $c['title'], $c['owner_name'], $c['target_close'], $c['closed_at'], $r, $w, $k['root_cause'] ?? ''])) . "\r\n";
    }
    audit($uid, 'qc_export', 'csv'); exit;
}
if (in_array($view, ['c', 'edit'], true)) { $c = qc_case($db, (int)($_GET['id'] ?? 0)); if (!$c || !qc_sees($db, $u, $c)) { http_response_code(404); $view = 'none'; } }

ui_head('Complaints & CAPA', $u, 'quality');
$tab = fn(string $k, string $l) => '<a class="btn sm' . ($view === $k ? '' : ' ghost') . '" href="?view=' . $k . '">' . h($l) . '</a> ';
$mine = qc_my_actions($db, $uid); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('list', 'Cases') . $tab('new', 'New complaint') . $tab('actions', 'My actions' . ($mine ? ' (' . count($mine) . ')' : '')) . ($R['view'] ? $tab('dash', 'Dashboard') : '') . ($isAdmin ? $tab('settings', 'Settings') : '') ?></nav>

<?php if ($view === 'list'): $rows = qc_list($db, $u, $f); ?>
<form class="card row" style="flex-wrap:wrap"><input name="q" value="<?= h($f['q']) ?>" placeholder="Case, customer, product, serial"><select name="status"><?= $opts(['open' => 'Open', 'all' => 'Every status'] + $list(QC_STATUSES), $f['status']) ?></select>
<select name="severity"><?= $opts($list(QC_SEVERITY), $f['severity'], 'Any severity') ?></select> From <input type="date" name="from" value="<?= h($f['from']) ?>"> to <input type="date" name="to" value="<?= h($f['to']) ?>"><button class="btn sm">Show</button>
<?php if ($R['view']): ?> <a class="btn sm ghost" href="?view=csv&<?= h(http_build_query($f)) ?>">Download CSV</a><?php endif; ?></form>
<div class="card"><table><tr><th>Case</th><th>Customer / product</th><th>Status</th><th>Severity</th><th>Owner</th><th>Target</th></tr>
<?php foreach ($rows as $r): $late = $r['target_close'] && $r['target_close'] < $today && in_array($r['status'], QC_OPEN, true); ?><tr><td><a href="?view=c&id=<?= (int)$r['id'] ?>"><?= h($r['case_no']) ?></a><div class="muted"><?= h($r['reported_on']) ?></div></td>
<td><?= h($r['customer_text'] ?: QC_KINDS[$r['kind']]) ?><div class="muted"><?= h(trim($r['product_text'] . ' · ' . $r['category'], ' ·')) ?></div></td><td><?= h($r['status']) ?><?= $r['root_id'] && (int)$r['root_id'] !== (int)$r['id'] ? ' <span class="muted">repeat</span>' : '' ?></td>
<td><?= h($r['severity']) ?><?= $r['safety'] ? ' <b>safety</b>' : '' ?></td><td><?= h((string)($r['owner_name'] ?? '—')) ?></td><td><?= $late ? '<b>Overdue</b> ' : '' ?><?= h((string)$r['target_close']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="muted">No cases here.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'new' || $view === 'edit'):
    $fm = $_SESSION['qc_form'] ?? []; unset($_SESSION['qc_form']); $e = $fm ?: ($view === 'edit' ? $c : []); $myB = array_intersect_key($bn, array_flip(qc_branches_for($u)));
    $custs = module_on('crm') ? $db->query("SELECT id, name FROM crm_customer WHERE status = 'active' AND merged_into IS NULL ORDER BY name LIMIT 2000")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $prods = module_on('crm') ? $db->query('SELECT id, name FROM crm_product WHERE active = 1 AND merged_into IS NULL ORDER BY name LIMIT 2000')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $isDraft = $view === 'new' || $c['status'] === 'Draft'; $hx = bin2hex(random_bytes(16));
    $rid = !empty($e['request_id']) ? (string)$e['request_id'] : substr($hx, 0, 8) . '-' . substr($hx, 8, 4) . '-' . substr($hx, 12, 4) . '-' . substr($hx, 16, 4) . '-' . substr($hx, 20, 12); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= (int)($view === 'edit' ? $c['id'] : 0) ?>"><input type="hidden" name="request_id" value="<?= h($rid) ?>"><input type="hidden" name="version" value="<?= (int)($c['version'] ?? 0) ?>">
<h2><?= $view === 'edit' ? 'Change ' . h($c['case_no']) : 'New complaint or problem' ?></h2>
<div class="grid"><label>Kind <select name="kind"><?= $opts(QC_KINDS, (string)($e['kind'] ?? 'customer')) ?></select></label><?php if (!$isDraft): ?><input type="hidden" name="branch_id" value="<?= (int)$c['branch_id'] ?>"><?php endif; ?><label>Branch * <select name="branch_id"<?= $isDraft ? '' : ' disabled' ?>><?= $opts($myB, (string)($e['branch_id'] ?? (array_key_first($myB) ?? ''))) ?></select></label>
<label>Severity <select name="severity"><?= $opts($list(QC_SEVERITY), (string)($e['severity'] ?? 'Medium')) ?></select></label><label><input type="checkbox" name="safety" value="1"<?= !empty($e['safety']) ? ' checked' : '' ?>> Safety risk (makes it Critical)</label></div>
<h3>Customer</h3><div class="grid"><?php if ($custs): ?><label>CRM customer <select name="customer_id"><?= $opts($custs, (string)($e['customer_id'] ?? ''), '— or type below —') ?></select></label><?php endif; ?>
<label>Customer (as told) <input name="customer_text" value="<?= h((string)($e['customer_text'] ?? '')) ?>"></label><label>Contact person <input name="contact_name" value="<?= h((string)($e['contact_name'] ?? '')) ?>"></label><label>Contact phone <input name="contact_phone" value="<?= h((string)($e['contact_phone'] ?? '')) ?>" inputmode="tel"></label></div>
<h3>Product</h3><div class="grid"><?php if ($prods): ?><label>CRM product <select name="product_id"><?= $opts($prods, (string)($e['product_id'] ?? ''), '— or type below —') ?></select></label><?php endif; ?>
<label>Product (as told) <input name="product_text" value="<?= h((string)($e['product_text'] ?? '')) ?>"></label><label>Serial no. <input name="serial_no" value="<?= h((string)($e['serial_no'] ?? '')) ?>"></label><label>Batch <input name="batch" value="<?= h((string)($e['batch'] ?? '')) ?>"></label>
<label>Invoice no. <input name="invoice_no" value="<?= h((string)($e['invoice_no'] ?? '')) ?>"></label><label>How much is faulty <select name="qty_scope"><?= $opts(QC_QTY_SCOPES, (string)($e['qty_scope'] ?? ''), 'Not known yet') ?></select></label>
<label>Faulty pieces <input name="qty" value="<?= h((string)($e['qty'] ?? '')) ?>" inputmode="decimal"></label><label>Lot size <input name="lot_qty" value="<?= h((string)($e['lot_qty'] ?? '')) ?>" inputmode="decimal"></label></div>
<h3>What happened</h3><div class="grid"><label>Category <select name="category"><?= $opts($list(qc_cats()), (string)($e['category'] ?? ''), '—') ?></select></label><label>Short title <input name="title" value="<?= h((string)($e['title'] ?? '')) ?>" maxlength="160"></label>
<label>First seen <input type="date" name="first_seen" value="<?= h((string)($e['first_seen'] ?? '')) ?>" max="<?= $today ?>"></label><label>Reported on <input type="date" name="reported_on" value="<?= h((string)($e['reported_on'] ?? $today)) ?>" max="<?= $today ?>"></label></div>
<label>Description <textarea name="description" rows="4"><?= h((string)($e['description'] ?? '')) ?></textarea></label><label>What was done straight away (containment) <textarea name="containment" rows="2"><?= h((string)($e['containment'] ?? '')) ?></textarea></label>
<button class="btn">Save</button><?php if ($isDraft): ?> <button class="btn ghost" name="submit" value="1">Save and send to quality</button><?php endif; ?></form>

<?php elseif ($view === 'c'):
    $k = qc_capa($db, (int)$c['id']); $moves = qc_moves($db, $u, $c); $files = $db->prepare('SELECT id, name, mime, size, active, off_reason, by_user, at FROM qc_file WHERE case_id = ? ORDER BY id'); $files->execute([(int)$c['id']]);
    $ev = $db->prepare('SELECT e.*, u.name AS who FROM qc_event e LEFT JOIN users u ON u.id = e.by_user WHERE e.case_id = ? ORDER BY e.id DESC'); $ev->execute([(int)$c['id']]);
    $hdl = $R['handle'] && emp_in_scope($u, ['branch_id' => $c['branch_id']]); $apr = $R['approve'] && emp_in_scope($u, ['branch_id' => $c['branch_id']]); $locked = in_array($c['status'], QC_LOCKED, true);
    $canEdit = !$locked && ($c['status'] === 'Draft' ? (int)$c['created_by'] === $uid : $hdl); ?>
<div class="card"><div class="row" style="justify-content:space-between"><h2><?= h($c['title'] ?: ($c['product_text'] ?: QC_KINDS[$c['kind']])) ?> <span class="muted"><?= h($c['case_no']) ?></span></h2><?php if ($canEdit): ?><a class="btn sm" href="?view=edit&id=<?= (int)$c['id'] ?>">Change</a><?php endif; ?></div>
<p><b><?= h($c['status']) ?></b> · <?= h($c['severity']) ?><?= $c['safety'] ? ' · <b>safety risk</b>' : '' ?> · <?= h(QC_KINDS[$c['kind']]) ?> · <?= h($bn[$c['branch_id']] ?? '') ?> · owner <?= h((string)($people[$c['owner_id']] ?? 'nobody yet')) ?><?= $c['dept'] !== '' ? ' (' . h($c['dept']) . ')' : '' ?> · target <?= h((string)($c['target_close'] ?? '—')) ?></p>
<p><?= h(implode(' · ', array_filter([$c['customer_text'], $c['contact_name'], $c['contact_phone'] ? '+' . $c['contact_phone'] : '', $c['product_text'], $c['serial_no'] ? 'serial ' . $c['serial_no'] : '', $c['batch'] ? 'batch ' . $c['batch'] : '', $c['invoice_no'] ? 'invoice ' . $c['invoice_no'] : '',
    $c['qty_scope'] === 'lot' ? 'whole lot' . ($c['lot_qty'] !== null ? ' of ' . (float)$c['lot_qty'] : '') : ($c['qty_scope'] === 'pieces' ? (float)$c['qty'] . ' faulty' . ($c['lot_qty'] !== null ? ' of ' . (float)$c['lot_qty'] : '') : ''), $c['category'], 'reported ' . $c['reported_on']]))) ?></p>
<p><?= nl2br(h($c['description'])) ?></p><?php if ($c['containment'] !== ''): ?><p class="muted">Done straight away: <?= nl2br(h($c['containment'])) ?></p><?php endif; ?>
<?php if ($c['root_id'] && (int)$c['root_id'] !== (int)$c['id']): $rt = qc_case($db, (int)$c['root_id']); ?><p>Repeat — the first case is <a href="?view=c&id=<?= (int)$rt['id'] ?>"><?= h($rt['case_no']) ?></a>.</p><?php endif; ?>
<?php if ($moves): ?><form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="move"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input name="note" placeholder="Reason / how it was solved (needed for some moves)" size="40">
<?php foreach ($moves as $to => $need): ?><button class="btn sm<?= $to === 'Rejected' ? ' ghost' : '' ?>" name="to" value="<?= h($to) ?>">→ <?= h($to) ?><?= $need ? ' *' : '' ?></button><?php endforeach; ?></form><?php endif; ?></div>

<?php if ($hdl && !$locked && $c['status'] !== 'Draft'): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="assign"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><h3>Owner and target</h3>
<div class="grid"><label>Owner <select name="owner_id"><?= $opts($people, (string)($c['owner_id'] ?? ''), 'Nobody yet') ?></select></label><label>Department <select name="dept"><?= $opts($list(crm_lines($set['depts'])), $c['dept'], '—') ?></select></label>
<label>Severity <select name="severity"><?= $opts($list(QC_SEVERITY), $c['severity']) ?></select></label><label><input type="checkbox" name="safety" value="1"<?= $c['safety'] ? ' checked' : '' ?>> Safety risk</label>
<label>Target close <input type="date" name="target_close" value="<?= h((string)$c['target_close']) ?>"></label><label>Reason (when the date moves) <input name="note"></label></div><button class="btn sm">Save</button></form><?php endif; ?>

<?php if ($c['status'] !== 'Draft'): $links = qc_links($db, (int)$c['id']); $sug = $hdl ? qc_matches($db, $c) : []; ?><div class="card"><h3>Same problem before?</h3>
<?php foreach ($links as $l): ?><div><?= h(QC_LINK_TYPES[$l['type']]) ?>: <a href="?view=c&id=<?= (int)$l['other_id'] ?>"><?= h($l['case_no']) ?></a> <span class="muted"><?= h($l['reported_on'] . ' · ' . $l['status']) ?><?= $l['note'] !== '' ? ' · ' . h($l['note']) : '' ?></span></div><?php endforeach; ?>
<?php foreach ($sug as $m): ?><form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="link"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="other" value="<?= (int)$m['case']['id'] ?>">
<span><a href="?view=c&id=<?= (int)$m['case']['id'] ?>"><?= h($m['case']['case_no']) ?></a> — <?= h($m['why']) ?> <span class="muted"><?= h($m['case']['reported_on'] . ' · ' . mb_substr($m['case']['description'], 0, 80)) ?></span></span>
<?php foreach (QC_LINK_TYPES as $t => $l): ?><button class="btn sm ghost" name="type" value="<?= $t ?>"><?= h($l) ?></button><?php endforeach; ?></form><?php endforeach; ?>
<?php if (!$links && !$sug): ?><p class="muted">No similar case found.</p><?php endif; ?></div><?php endif; ?>

<?php if ($c['status'] !== 'Draft'): ?><div class="card"><h3>CAPA</h3>
<?php if (!$k): ?><?php if ($hdl && in_array($c['status'], ['Submitted', 'Under review', 'Investigation', 'Reopened'], true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="capa"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sm">Start the CAPA</button></form><?php else: ?><p class="muted">No CAPA yet.</p><?php endif; ?>
<?php else: $whys = qc_whys($db, (int)$k['id']); $acts = $db->prepare('SELECT * FROM qc_action WHERE capa_id = ? ORDER BY id'); $acts->execute([(int)$k['id']]); $acts = $acts->fetchAll(); $rcLock = (bool)$k['rc_at']; $kEdit = $hdl && !$locked; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="capasave"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="kid" value="<?= (int)$k['id'] ?>">
<div class="grid"><label>Method <select name="method"<?= $kEdit && !$rcLock ? '' : ' disabled' ?>><?= $opts(QC_METHODS, $k['method']) ?></select></label><label>Effectiveness review on <input type="date" name="eff_review_on" value="<?= h((string)$k['eff_review_on']) ?>"<?= $kEdit ? '' : ' disabled' ?>></label></div>
<label>Problem statement <textarea name="statement" rows="2"<?= $kEdit ? '' : ' disabled' ?>><?= h($k['statement']) ?></textarea></label>
<?php for ($i = 1; $i <= 5; $i++): ?><label>Why <?= $i ?> <input name="whys[<?= $i ?>]" value="<?= h((string)($whys[$i] ?? '')) ?>"<?= $kEdit && !$rcLock ? '' : ' disabled' ?>></label><?php endfor; ?>
<label>Contributing causes <textarea name="contributing" rows="2"<?= $kEdit ? '' : ' disabled' ?>><?= h($k['contributing']) ?></textarea></label>
<label>Root cause <?= $rcLock ? '<b>(confirmed ' . h(substr((string)$k['rc_at'], 0, 10)) . ' by ' . h((string)($people[$k['rc_by']] ?? '')) . ')</b>' : '' ?><textarea name="root_cause" rows="2"<?= $kEdit && !$rcLock ? '' : ' disabled' ?>><?= h($k['root_cause']) ?></textarea></label>
<label>How effectiveness will be proven <textarea name="eff_criteria" rows="2"<?= $kEdit ? '' : ' disabled' ?>><?= h($k['eff_criteria']) ?></textarea></label><?php if ($kEdit): ?><button class="btn sm">Save CAPA</button><?php endif; ?></form>
<?php if ($apr && !$locked): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="rc"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="kid" value="<?= (int)$k['id'] ?>"><input type="hidden" name="on" value="<?= $rcLock ? '0' : '1' ?>">
<?php if ($rcLock): ?><input name="note" placeholder="Why open the root cause again" required><button class="btn sm ghost">Open the root cause again</button><?php else: ?><button class="btn sm">Confirm the root cause</button><?php endif; ?></form><?php endif; ?>
<h3>Actions</h3><table><tr><th>Type</th><th>Action</th><th>Owner</th><th>Due</th><th>State</th><th></th></tr>
<?php foreach ($acts as $x): $myAct = (int)$x['owner_id'] === $uid; ?><tr><td><?= h(ucfirst($x['type'])) ?></td><td><?= h($x['text']) ?><?= $x['done_note'] !== '' ? '<div class="muted">' . h($x['done_note']) . '</div>' : '' ?></td><td><?= h((string)($people[$x['owner_id']] ?? '')) ?></td><td><?= $x['status'] === 'open' && $x['due'] < $today ? '<b>Overdue</b> ' : '' ?><?= h($x['due']) ?></td><td><?= h($x['status']) ?></td>
<td><?php if (!$locked && $k['eff_result'] !== 'passed' && ($myAct || $hdl)): ?><form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="actup"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="aid" value="<?= (int)$x['id'] ?>"><input name="note" placeholder="How / why" size="14"><input type="date" name="due" min="<?= $today ?>">
<?php if ($x['status'] === 'open'): ?><button class="btn sm" name="what" value="done">Done</button><button class="btn sm ghost" name="what" value="due">Move date</button><?php if ($hdl): ?><button class="btn sm ghost" name="what" value="drop">Drop</button><?php endif; ?><?php elseif ($hdl): ?><button class="btn sm ghost" name="what" value="reopen">Open again</button><?php endif; ?></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$acts): ?><tr><td colspan="6" class="muted">No actions yet.</td></tr><?php endif; ?></table>
<?php if ($hdl && !$locked && $k['eff_result'] !== 'passed'): ?><form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="action"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="kid" value="<?= (int)$k['id'] ?>">
<select name="type"><?= $opts(QC_ACTION_TYPES) ?></select><input name="text" placeholder="What will be done" required size="30"><select name="owner_id"><?= $opts($people, '', 'Owner') ?></select><input type="date" name="due" min="<?= $today ?>" required><select name="priority"><?= $opts($list(QC_PRIORITY), 'Medium') ?></select><button class="btn sm">Add action</button></form><?php endif; ?>
<h3>Effectiveness check</h3><?php if ($k['eff_result'] !== ''): ?><p><b><?= h(ucfirst($k['eff_result'])) ?></b> — <?= h($k['eff_note']) ?> <span class="muted">(<?= h((string)($people[$k['eff_by']] ?? '')) ?>, <?= h(substr((string)$k['eff_at'], 0, 10)) ?>)</span></p><?php endif; ?>
<?php if ($apr && !$locked && $k['eff_result'] !== 'passed'): ?><form method="post" class="row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="a" value="verify"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="kid" value="<?= (int)$k['id'] ?>"><input name="note" placeholder="How it was proven (or why it failed)" required size="40">
<button class="btn sm" name="result" value="passed">Passed</button><button class="btn sm ghost" name="result" value="failed">Failed</button></form><p class="muted">Made by someone who did not own an action or confirm the root cause.</p><?php endif; ?>
<?php endif; ?></div><?php endif; ?>

<div class="card"><h3>Evidence</h3><?php foreach ($files->fetchAll() as $fl): ?><div<?= $fl['active'] ? '' : ' class="muted"' ?>><?php if ($fl['active']): ?><a href="?view=file&id=<?= (int)$fl['id'] ?>" target="_blank" rel="noopener"><?= h($fl['name']) ?></a><?php else: ?><?= h($fl['name']) ?> — taken off: <?= h($fl['off_reason']) ?><?php endif; ?> <span class="muted"><?= h(round($fl['size'] / 1024) . ' KB · ' . substr($fl['at'], 0, 16)) ?></span>
<?php if ($fl['active'] && !$locked && ((int)$fl['by_user'] === $uid || $hdl)): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="a" value="fileoff"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="fid" value="<?= (int)$fl['id'] ?>"><input name="reason" placeholder="Why" size="10" required><button class="btn sm ghost">Take off</button></form><?php endif; ?></div><?php endforeach; ?>
<?php if (!$locked): ?><form method="post" enctype="multipart/form-data" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="file"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="file" name="f" accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,video/quicktime" required><button class="btn sm">Add</button></form><p class="muted">Photos, PDF up to 10 MB, video up to 15 MB; <?= QC_FILES_MAX ?> a case.</p><?php endif; ?></div>

<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="note"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><h3>Timeline</h3><textarea name="note" rows="2" placeholder="Add a note"></textarea><button class="btn sm">Add note</button>
<?php foreach ($ev->fetchAll() as $x): ?><div<?= in_array($x['kind'], ['note', 'status'], true) ? '' : ' class="muted"' ?>><?= h(substr($x['at'], 0, 16)) ?> · <?= h((string)($x['who'] ?? 'system')) ?> — <?= $x['kind'] === 'status' ? h($x['from_status'] . ' → ' . $x['to_status']) . ($x['note'] ? ': ' : '') : '' ?><?= nl2br(h((string)$x['note'])) ?>
<?php if ($x['before_text'] !== null): ?><details><summary>Before this change</summary><?= nl2br(h($x['before_text'])) ?></details><?php endif; ?></div><?php endforeach; ?></form>

<?php elseif ($view === 'actions'): ?>
<div class="card"><h2>My open actions</h2><table><tr><th>Case</th><th>Action</th><th>Due</th><th></th></tr>
<?php foreach ($mine as $x): ?><tr><td><a href="?view=c&id=<?= (int)$x['case_id'] ?>"><?= h($x['case_no']) ?></a></td><td><?= h(ucfirst($x['type']) . ': ' . $x['text']) ?></td><td><?= $x['due'] < $today ? '<b>Overdue</b> ' : '' ?><?= h($x['due']) ?></td>
<td><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="actup"><input type="hidden" name="back" value="1"><input type="hidden" name="id" value="<?= (int)$x['case_id'] ?>"><input type="hidden" name="aid" value="<?= (int)$x['id'] ?>"><input name="note" placeholder="How it was done" required size="20"><button class="btn sm" name="what" value="done">Done</button></form></td></tr><?php endforeach; ?>
<?php if (!$mine): ?><tr><td colspan="4" class="muted">Nothing waiting for you.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'dash' && $R['view']): $from = $f['from'] ?: date('Y-m-d', strtotime('-1 year')); $to = $f['to'] ?: $today; $S = qc_summary(qc_list($db, $u, ['status' => 'all', 'from' => $from, 'to' => $to, 'limit' => 100000]), $set, date('Y-m-d H:i:s')); ?>
<form class="card row" style="flex-wrap:wrap"><input type="hidden" name="view" value="dash">From <input type="date" name="from" value="<?= h($from) ?>"> to <input type="date" name="to" value="<?= h($to) ?>"><button class="btn sm">Show</button></form>
<div class="card row" style="flex-wrap:wrap"><span>Cases <b><?= $S['total'] ?></b></span> · <a href="?view=list"><b><?= $S['open'] ?></b> open</a> · <span><b><?= $S['overdue'] ?></b> past target</span> · <span><b><?= $S['stale'] ?></b> with no update</span> · <span><b><?= $S['repeats'] ?></b> repeats</span> · <span><b><?= $S['safety'] ?></b> safety</span>
 · <span>Closed <b><?= $S['closed'] ?></b><?= $S['closeDays'] !== null ? ' in ' . $S['closeDays'] . ' days on average' : '' ?></span></div>
<div class="grid"><div class="card"><h3>Open by severity</h3><table><?php foreach (QC_SEVERITY as $s): ?><tr><td><?= h($s) ?></td><td><?= (int)($S['bySeverity'][$s] ?? 0) ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h3>By status</h3><table><?php foreach ($S['byStatus'] as $s => $n): ?><tr><td><?= h($s) ?></td><td><?= $n ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h3>By category</h3><table><?php arsort($S['byCategory']); foreach ($S['byCategory'] as $s => $n): ?><tr><td><?= h($s) ?></td><td><?= $n ?></td></tr><?php endforeach; ?></table></div></div>
<p class="muted">Drafts and rejected cases are left out. Closing days count from sending to closing.</p>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['handle_roles' => 'Handle cases: review, assign, investigate, CAPA, actions, repeats', 'approve_roles' => 'Approve: reject, close, reopen, confirm root cause, effectiveness check', 'view_roles' => 'See every case in their branches'] as $key => $l): ?><h3><?= h($l) ?></h3>
<div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $code => $n): ?><label><input type="checkbox" name="<?= $key ?>[]" value="<?= h($code) ?>"<?= in_array($code, $set[$key], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div><?php endforeach; ?>
<h3>Severities that may close without a CAPA (with a note)</h3><div class="row"><?php foreach (QC_SEVERITY as $s): ?><label><input type="checkbox" name="close_no_capa[]" value="<?= $s ?>"<?= in_array($s, $set['close_no_capa'], true) ? ' checked' : '' ?>> <?= $s ?></label><?php endforeach; ?></div>
<table><tr><th></th><?php foreach (QC_SEVERITY as $s): ?><th><?= $s ?></th><?php endforeach; ?></tr><tr><td>Days to close (target)</td><?php foreach (QC_SEVERITY as $s): ?><td><input type="number" name="target[<?= $s ?>]" value="<?= (int)$set['target'][$s] ?>" min="1" max="365" style="width:5em"></td><?php endforeach; ?></tr>
<tr><td>Days without an update before a reminder</td><?php foreach (QC_SEVERITY as $s): ?><td><input type="number" name="sla[<?= $s ?>]" value="<?= (int)$set['sla'][$s] ?>" min="1" max="90" style="width:5em"></td><?php endforeach; ?></tr></table>
<div class="grid"><label>Categories (one a line) <textarea name="categories" rows="8"><?= h($set['categories']) ?></textarea></label><label>Departments (one a line) <textarea name="depts" rows="8"><?= h($set['depts']) ?></textarea></label>
<label>Words alike to suggest a repeat (%) <input type="number" name="match_min" value="<?= (int)$set['match_min'] ?>" min="40" max="100"></label><label>Case number starts with <input name="prefix" value="<?= h($set['prefix']) ?>" maxlength="6"></label></div><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not found.</div><?php endif;
ui_foot();
