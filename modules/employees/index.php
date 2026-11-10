<?php
// Employee master — list, record, add / edit, leaver, code change, import (check first), export, history.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/employees.php';
$u = module_require('employees');
$db = pdo();
$today = date('Y-m-d');
$brs = branches(false);
$brName = array_column($brs, 'name', 'id');
$canEdit = emp_can_edit($u);
$seePersonal = data_allowed($u, 'personal');
$fmt = org('date_format') ?: 'd M Y';
$df = fn($d) => $d ? date($fmt, strtotime((string)$d)) : '';

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($a === 'save') {
        $in = array_intersect_key($_POST, EMP_FIELDS);
        if ($id) { unset($in['code']); $s = $db->prepare('SELECT doj FROM employee WHERE id = ?'); $s->execute([$id]); $in['_doj'] = (string)$s->fetchColumn(); }
        [$clean, $err] = emp_check($in, $brs);
        if ($err) { flash(implode(' ', $err), 'err'); redirect('index.php?' . ($id ? 'e=' . $id : 'new=1')); }
        [$nid, $e] = emp_save($db, $u, $id ?: null, $clean);
        flash($e ?? 'Saved.', $e ? 'err' : 'ok');
        redirect($nid ? 'index.php?e=' . $nid : 'index.php?new=1');
    }
    if ($a === 'recode') { $e = emp_recode($db, $u, $id, (string)($_POST['new_code'] ?? ''), (string)($_POST['reason'] ?? '')); flash($e ?? 'Code changed.', $e ? 'err' : 'ok'); redirect('index.php?e=' . $id); }
    if ($a === 'switch') { $e = emp_switch($db, $u, $id, ($_POST['on'] ?? '') === '1', (string)($_POST['reason'] ?? '')); flash($e ?? 'Saved.', $e ? 'err' : 'ok'); redirect('index.php?e=' . $id); }
    if ($a === 'check' && $canEdit) {
        $f = $_FILES['file'] ?? null;
        if (!$f || !is_uploaded_file($f['tmp_name'])) { flash('Choose a CSV file.', 'err'); redirect('index.php?import=1'); }
        [$rows, $unmatched] = emp_csv_rows($f['tmp_name']);
        @unlink($f['tmp_name']);   // the file is never kept
        $_SESSION['emp_import'] = ['at' => time(), 'uid' => $u['id'], 'plan' => emp_import_plan($rows, emp_all($db), $brs), 'unmatched' => $unmatched];
        redirect('index.php?import=1');
    }
    if ($a === 'import' && $canEdit) {
        $imp = $_SESSION['emp_import'] ?? null;
        if (!$imp || $imp['uid'] !== $u['id'] || time() - $imp['at'] > 3600) { flash('Check the file again.', 'err'); redirect('index.php?import=1'); }
        $ticked = array_map('intval', (array)($_POST['row'] ?? []));
        $added = $updated = 0; $errs = [];
        foreach ($imp['plan'] as $i => [$st, $row, , $old]) {
            if (!in_array($i, $ticked, true) || !in_array($st, ['New', 'Update'], true)) continue;
            $clean = array_filter($row, fn($v) => $v !== '' && $v !== null);
            [$nid, $e] = emp_save($db, $u, $st === 'Update' ? (int)$old['id'] : null, $clean, 'import', 'file import');
            if ($e) $errs[] = ($row['code'] ?? '?') . ': ' . $e; elseif ($st === 'New') $added++; else $updated++;
        }
        unset($_SESSION['emp_import']);
        audit((int)$u['id'], 'emp_import', "added $added, updated $updated");
        flash("Added $added, updated $updated." . ($errs ? ' Not saved: ' . implode('; ', array_slice($errs, 0, 5)) : ''), $errs ? 'err' : 'ok');
        redirect('index.php');
    }
    redirect('index.php');
}

/* ---------------- CSV export / template ---------------- */
if (isset($_GET['csv']) || isset($_GET['template'])) {
    $cols = array_filter(EMP_FIELDS, fn($f) => $f[2] !== 'personal' || $seePersonal);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="employees-' . (isset($_GET['template']) ? 'template' : $today) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [...array_map(fn($f) => $f[0], $cols), ...(isset($_GET['csv']) ? ['Status'] : [])], ',', '"', '\\');
    if (isset($_GET['csv'])) {
        $n = 0;
        foreach ($db->query('SELECT * FROM employee WHERE active = 1 ORDER BY code') as $e) {
            if (!emp_in_scope($u, $e)) continue;
            $row = [];
            foreach (array_keys($cols) as $k) $row[] = emp_csv_cell($k === 'branch_id' ? (string)($brName[$e[$k]] ?? '') : (string)$e[$k]);
            $row[] = emp_status_label($e, $today, $fmt);
            fputcsv($out, $row, ',', '"', '\\'); $n++;
        }
        audit((int)$u['id'], 'emp_export', $n . ' rows');
    }
    exit;
}

/* ---------------- views ---------------- */
$edit = isset($_GET['e']) ? (int)$_GET['e'] : null;
$new = isset($_GET['new']);
$roll = emp_on_roll($db, $u, $today);
$mgrOpts = fn(string $cur, string $self) => implode('', array_map(fn($p) => $p['code'] === $self ? '' :
    '<option value="' . h($p['code']) . '"' . ($p['code'] === $cur ? ' selected' : '') . '>' . h($p['name'] . ' · ' . $p['code']) . '</option>', $roll));

function emp_form(array $e, bool $isNew, array $brs, string $mgr, bool $canEdit, bool $seePersonal): void {
    $dis = $canEdit ? '' : ' disabled';
    $val = fn($k) => h((string)($e[$k] ?? ''));
    $depts = array_column(pdo()->query('SELECT name FROM department WHERE active = 1 ORDER BY name')->fetchAll(), 'name');
    $desigs = array_column(pdo()->query('SELECT name FROM designation WHERE active = 1 ORDER BY name')->fetchAll(), 'name');
    ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
  <h2><?= $isNew ? 'New employee' : h($e['name']) ?></h2>
  <datalist id="dl-dept"><?php foreach ($depts as $d): ?><option value="<?= h($d) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl-desig"><?php foreach ($desigs as $d): ?><option value="<?= h($d) ?>"><?php endforeach; ?></datalist>
  <div class="grid">
    <label>Employee code <input name="code" value="<?= $val('code') ?>" <?= $isNew ? 'required' : 'disabled' ?> autocomplete="off"></label>
    <label>Full name <input name="name" value="<?= $val('name') ?>" required<?= $dis ?>></label>
    <label>Department <input name="department" list="dl-dept" value="<?= $val('department') ?>"<?= $dis ?>></label>
    <label>Designation <input name="designation" list="dl-desig" value="<?= $val('designation') ?>"<?= $dis ?>></label>
    <label>Grade <input name="grade" value="<?= $val('grade') ?>"<?= $dis ?>></label>
    <label>Category <input name="category" value="<?= $val('category') ?>" placeholder="Staff, Worker, Contract…"<?= $dis ?>></label>
    <label>Branch <select name="branch_id"<?= $dis ?>><option value="">—</option><?php foreach ($brs as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)($e['branch_id'] ?? 0) === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?></select></label>
    <label>Reports to <select name="manager_code"<?= $dis ?>><option value="">—</option><?= $mgr ?></select></label>
    <label>Date of joining <input type="date" name="doj" value="<?= $val('doj') ?>"<?= $dis ?>></label>
    <label>Confirmation date <input type="date" name="confirm_date" value="<?= $val('confirm_date') ?>"<?= $dis ?>></label>
    <label>Work email <input type="email" name="work_email" value="<?= $val('work_email') ?>"<?= $dis ?>></label>
    <label>Work phone <input name="work_phone" value="<?= $val('work_phone') ?>"<?= $dis ?>></label>
    <?php if ($seePersonal): ?>
    <label>Gender <select name="gender"<?= $dis ?>><option value="">—</option><?php foreach (EMP_GENDERS as $g): ?><option<?= ($e['gender'] ?? '') === $g ? ' selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></label>
    <label>Date of birth <input type="date" name="dob" value="<?= $val('dob') ?>"<?= $dis ?>></label>
    <?php endif; ?>
  </div>
  <?php if (!$isNew): ?>
  <fieldset><legend>Resignation and exit</legend><div class="grid">
    <label>Resignation date <input type="date" name="resign_date" value="<?= $val('resign_date') ?>"<?= $dis ?>></label>
    <label>Last working day <input type="date" name="last_day" value="<?= $val('last_day') ?>"<?= $dis ?>></label>
    <label>Exit type <select name="exit_type"<?= $dis ?>><option value="">—</option><?php foreach (EMP_EXIT_TYPES as $t): ?><option<?= ($e['exit_type'] ?? '') === $t ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
    <label>Exit reason <input name="exit_reason" value="<?= $val('exit_reason') ?>"<?= $dis ?>></label>
  </div><p class="muted">Clear both dates to withdraw a resignation. Leavers stay on record; they drop off every picker after their last day.</p></fieldset>
  <?php endif; ?>
  <?php if ($canEdit): ?><button class="btn"><?= $isNew ? 'Add employee' : 'Save changes' ?></button><?php endif; ?>
</form>
<?php }

if ($new && $canEdit) {
    ui_head('Employee master', $u, 'employees');
    emp_form([], true, $brs, $mgrOpts('', ''), true, $seePersonal);
    ui_foot(); exit;
}

if ($edit) {
    $s = $db->prepare('SELECT * FROM employee WHERE id = ?'); $s->execute([$edit]);
    $e = $s->fetch();
    if (!$e || !emp_in_scope($u, $e)) { flash('Record not found.', 'err'); redirect('index.php'); }
    $hist = $db->prepare('SELECT l.*, us.name AS by_name FROM employee_log l LEFT JOIN users us ON us.id = l.by_id WHERE emp_id = ? ORDER BY l.id DESC LIMIT 200');
    $hist->execute([$edit]);
    $team = $db->prepare('SELECT code, name, designation FROM employee WHERE manager_code = ? AND active = 1 ORDER BY name');
    $team->execute([$e['code']]);
    $team = array_filter($team->fetchAll(), fn($t) => true);
    ui_head('Employee master', $u, 'employees');
    ?>
    <p><a href="index.php">← All employees</a> · <span class="pill <?= emp_status($e, $today) === 'left' ? 'off' : 'on' ?>"><?= h(emp_status_label($e, $today, $fmt)) ?></span>
      <?php if (!$e['active']): ?><span class="pill off">Switched off · <?= h($e['off_reason']) ?></span><?php endif; ?></p>
    <?php emp_form($e, false, $brs, $mgrOpts((string)$e['manager_code'], (string)$e['code']), $canEdit && (bool)$e['active'], $seePersonal); ?>
    <div class="grid2">
      <section class="card"><h2>Reports to them</h2>
        <?php if (!$team): ?><p class="muted">Nobody.</p><?php else: ?><ul><?php foreach ($team as $t): ?><li><?= h($t['name'] . ' · ' . $t['code'] . ' · ' . $t['designation']) ?></li><?php endforeach; ?></ul><?php endif; ?></section>
      <?php if ($canEdit): ?><section class="card"><h2>Code and record</h2>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="recode"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
          <input name="new_code" placeholder="New code" required autocomplete="off"><input name="reason" placeholder="Reason" required><button class="btn sm ghost">Change code</button></form>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="switch"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="on" value="<?= $e['active'] ? '0' : '1' ?>">
          <?php if ($e['active']): ?><input name="reason" placeholder="Why is this record wrong?" required><?php endif; ?>
          <button class="btn sm ghost"><?= $e['active'] ? 'Switch record off' : 'Switch record back on' ?></button></form>
        <p class="muted">Records are never deleted. Use "switch off" only for a record made by mistake — a leaver gets a last working day.</p></section><?php endif; ?>
    </div>
    <section class="card"><h2>History</h2><table><thead><tr><th>When</th><th>Who</th><th>What</th><th>From</th><th>To</th><th>Note</th></tr></thead><tbody>
    <?php foreach ($hist as $hrow): ?><tr><td><?= h($hrow['at']) ?></td><td><?= h($hrow['by_name'] ?? '—') ?></td><td><?= h((EMP_FIELDS[$hrow['field']][0] ?? $hrow['field']) . ' · ' . $hrow['kind']) ?></td><td><?= h($hrow['old_value']) ?></td><td><?= h($hrow['new_value']) ?></td><td><?= h($hrow['note']) ?></td></tr><?php endforeach; ?>
    </tbody></table></section>
    <?php ui_foot(); exit;
}

if (isset($_GET['import']) && $canEdit) {
    $imp = $_SESSION['emp_import'] ?? null;
    ui_head('Employee master · import', $u, 'employees');
    ?>
    <p><a href="index.php">← All employees</a></p>
    <form method="post" enctype="multipart/form-data" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="check">
      <h2>Import from a file</h2>
      <p class="muted">CSV with a header row; columns are found by their titles (<a href="index.php?template=1">download the template</a>). Check writes nothing. Blank cells never wipe what is on record.</p>
      <input type="file" name="file" accept=".csv,text/csv" required> <button class="btn sm">Check file</button></form>
    <?php if ($imp && $imp['uid'] === $u['id']): $cnt = array_count_values(array_column($imp['plan'], 0)); ?>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="import">
      <h2>Check result</h2>
      <p><?php foreach ($cnt as $k => $v): ?><span class="pill"><?= h($k) ?>: <?= $v ?></span> <?php endforeach; ?>
        <?php if ($imp['unmatched']): ?><br><span class="muted">Columns not read: <?= h(implode(', ', $imp['unmatched'])) ?></span><?php endif; ?></p>
      <table><thead><tr><th></th><th>Status</th><th>Code</th><th>Name</th><th>Details</th></tr></thead><tbody>
      <?php foreach ($imp['plan'] as $i => [$st, $row, $errs, $old, $ch]): $tick = in_array($st, ['New', 'Update'], true); ?>
        <tr><td><?php if ($tick): ?><input type="checkbox" name="row[]" value="<?= $i ?>" checked><?php endif; ?></td>
        <td><span class="pill <?= $st === 'Error' ? 'off' : ($tick ? 'on' : '') ?>"><?= h($st) ?></span></td>
        <td><?= h($row['code'] ?? '') ?></td><td><?= h($row['name'] ?? '') ?></td>
        <td><?= h(implode(' ', $errs)) ?><?php foreach ($ch as $f => [$o, $n]): if ((EMP_FIELDS[$f][2] ?? '') === 'personal') { echo h((EMP_FIELDS[$f][0]) . ' changes · '); continue; } ?><?= h((EMP_FIELDS[$f][0] ?? $f) . ': ' . ($o === '' ? '—' : $o) . ' → ' . $n) ?> · <?php endforeach; ?></td></tr>
      <?php endforeach; ?></tbody></table>
      <button class="btn">Save ticked rows</button></form>
    <?php endif; ui_foot(); exit;
}

/* list */
$q = trim((string)($_GET['q'] ?? ''));
$fb = (int)($_GET['b'] ?? 0);
$fd = trim((string)($_GET['d'] ?? ''));
$fs = in_array($_GET['s'] ?? '', ['roll', 'notice', 'left', 'all', 'off'], true) ? $_GET['s'] : 'roll';
$rows = []; $counts = ['active' => 0, 'notice' => 0, 'left' => 0, 'off' => 0];
foreach ($db->query('SELECT * FROM employee ORDER BY name') as $e) {
    if (!emp_in_scope($u, $e)) continue;
    $st = $e['active'] ? emp_status($e, $today) : 'off';
    $counts[$st]++;
    if ($fs === 'roll' && !in_array($st, ['active', 'notice'], true)) continue;
    if (in_array($fs, ['notice', 'left', 'off'], true) && $st !== $fs) continue;
    if ($fs === 'all' && $st === 'off') continue;
    if ($fb && (int)$e['branch_id'] !== $fb) continue;
    if ($fd !== '' && strcasecmp($e['department'], $fd) !== 0) continue;
    if ($q !== '' && stripos($e['name'] . ' ' . $e['code'] . ' ' . $e['designation'], $q) === false) continue;
    $rows[] = $e;
}
$depts = array_values(array_unique(array_filter(array_column($db->query('SELECT department FROM employee')->fetchAll(), 'department'))));
sort($depts);
ui_head('Employee master', $u, 'employees');
?>
<section class="tiles">
  <a class="tile" href="?s=roll"><b><?= $counts['active'] + $counts['notice'] ?></b><span>On the roll</span></a>
  <a class="tile" href="?s=notice"><b><?= $counts['notice'] ?></b><span>On notice</span></a>
  <a class="tile" href="?s=left"><b><?= $counts['left'] ?></b><span>Left</span></a>
  <?php if ($canEdit): ?><a class="tile" href="?s=off"><b><?= $counts['off'] ?></b><span>Switched off</span></a><?php endif; ?>
</section>
<section class="card">
  <form class="row">
    <input name="q" value="<?= h($q) ?>" placeholder="Name, code or designation">
    <select name="b"><option value="0">All branches</option><?php foreach ($brs as $b): if (!emp_in_scope($u, ['branch_id' => $b['id']])) continue; ?><option value="<?= (int)$b['id'] ?>"<?= $fb === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?></select>
    <select name="d"><option value="">All departments</option><?php foreach ($depts as $d): ?><option<?= $fd === $d ? ' selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?></select>
    <select name="s"><?php foreach (['roll' => 'On the roll', 'notice' => 'On notice', 'left' => 'Left', 'all' => 'Everyone'] + ($canEdit ? ['off' => 'Switched off'] : []) as $k => $l): ?><option value="<?= $k ?>"<?= $fs === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <button class="btn sm">Show</button>
    <?php if ($canEdit): ?><a class="btn sm" href="?new=1">+ New employee</a><a class="btn sm ghost" href="?import=1">Import</a><?php endif; ?>
    <a class="btn sm ghost" href="?csv=1">Download CSV</a>
  </form>
  <table><thead><tr><th>Code</th><th>Name</th><th>Designation</th><th>Department</th><th>Branch</th><th>Joined</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $e): ?><tr><td><a href="?e=<?= (int)$e['id'] ?>"><?= h($e['code']) ?></a></td><td><a href="?e=<?= (int)$e['id'] ?>"><?= h($e['name']) ?></a></td>
    <td><?= h($e['designation']) ?></td><td><?= h($e['department']) ?></td><td><?= h($brName[$e['branch_id']] ?? '') ?></td><td><?= h($df($e['doj'])) ?></td>
    <td><?= $e['active'] ? h(emp_status_label($e, $today, $fmt)) : 'Switched off' ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7"><em>Nobody here yet.</em><?= $canEdit ? ' Add people one by one or import a file.' : '' ?></td></tr><?php endif; ?>
  </tbody></table>
  <p class="muted"><?= count($rows) ?> shown.</p>
</section>
<?php ui_foot();
