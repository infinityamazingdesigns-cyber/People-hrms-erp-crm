<?php
// Holiday calendar — year view by branch; HR adds (single day or range), edits, switches off, imports, copies a year,
// and pins people to another branch's calendar.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/holidays.php';
$u = module_require('holidays');
$db = pdo();
$canEdit = hol_can_edit($u);
$brs = branches();
$brName = array_column(branches(false), 'name', 'id');
$startM = (int)(org('fy_start_month') ?: 1);
$fmt = org('date_format') ?: 'd M Y';
$anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['y'] ?? '')) ? $_GET['y'] : date('Y-m-d');
[$yFrom, $yTo, $yLabel] = hol_year($anchor, $startM);
$ticked = fn() => array_values(array_filter(array_map('intval', (array)($_POST['branches'] ?? []))));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    try {
        if ($a === 'add') { [$ad, $tk] = hol_add($db, $u, (string)$_POST['from'], (string)($_POST['to'] ?? ''), (string)$_POST['name'], (string)$_POST['kind'], $ticked()); flash("Added $ad day(s)" . ($tk ? ", ticked branches on $tk existing" : '') . '.'); }
        elseif ($a === 'edit') { hol_edit($db, $u, (int)$_POST['id'], (string)$_POST['day'], (string)$_POST['name'], (string)$_POST['kind'], $ticked()); flash('Saved.'); }
        elseif ($a === 'off') { hol_off($db, $u, (int)$_POST['id'], (string)($_POST['reason'] ?? '')); flash('Switched off.'); }
        elseif ($a === 'copy') { flash('Copied ' . hol_copy_year($db, $u, $yFrom, $yTo) . ' day(s) to next year — check the dates of festivals that move.'); }
        elseif ($a === 'pin') { hol_override_set($db, $u, (string)$_POST['code'], ((int)($_POST['branch'] ?? 0)) ?: null, (string)($_POST['until'] ?? ''), (string)($_POST['reason'] ?? '')); flash('Saved.'); }
        elseif ($a === 'check') {
            $f = $_FILES['file'] ?? null;
            if (!$f || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Choose a CSV file.');
            $fh = fopen($f['tmp_name'], 'r'); $head = fgetcsv($fh, 0, ',', '"', '\\') ?: []; $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)($head[0] ?? ''));
            $map = hol_header_map($head); $plan = [];
            while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false && count($plan) < 500) {
                $row = []; foreach ($map as $i => $k) $row[$k] = trim((string)($r[$i] ?? ''));
                if (!array_filter($row)) continue;
                $from = emp_date($row['day'] ?? ''); $to = isset($row['to']) && $row['to'] !== '' ? emp_date($row['to']) : $from;
                [$ids, $bad] = hol_branch_cell($row['branches'] ?? '', branches(false));
                $err = [];
                if (!$from || $from === 'bad' || $to === 'bad' || !hol_range((string)$from, (string)$to)) $err[] = 'date';
                if (($row['name'] ?? '') === '') $err[] = 'name';
                if ($bad) $err[] = 'unknown branch: ' . implode(', ', $bad);
                $plan[] = ['from' => $from, 'to' => $to, 'name' => $row['name'] ?? '', 'kind' => stripos($row['kind'] ?? '', 'opt') === 0 ? 'optional' : 'holiday', 'branches' => $ids, 'err' => $err];
            }
            fclose($fh); @unlink($f['tmp_name']);
            $_SESSION['hol_import'] = ['uid' => $u['id'], 'at' => time(), 'plan' => $plan];
            redirect('index.php?import=1');
        } elseif ($a === 'import') {
            $imp = $_SESSION['hol_import'] ?? null;
            if (!$imp || $imp['uid'] !== $u['id'] || time() - $imp['at'] > 3600) throw new RuntimeException('Check the file again.');
            $tot = [0, 0];
            foreach ($imp['plan'] as $p) if (!$p['err']) { [$ad, $tk] = hol_add($db, $u, $p['from'], $p['to'], $p['name'], $p['kind'], $p['branches']); $tot[0] += $ad; $tot[1] += $tk; }
            unset($_SESSION['hol_import']);
            flash("Imported: added {$tot[0]} day(s), ticked branches on {$tot[1]}.");
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php?y=' . $anchor);
}

ui_head('Holiday calendar', $u, 'holidays');
$emp = !empty($u['emp_code']) ? emp_by_code($db, (string)$u['emp_code']) : null;
$myBranch = $emp ? hol_branch_of($db, $emp['code'], $emp['branch_id'] ? (int)$emp['branch_id'] : null) : null;
$fb = $canEdit ? (int)($_GET['b'] ?? 0) : 0;
$rows = hol_rows($db, $yFrom, $yTo, false);
$rows = array_filter($rows, fn($h) => $h['active'] || $canEdit);
if (!$canEdit) $rows = array_filter($rows, fn($h) => hol_applies($h, $myBranch));
elseif ($fb) $rows = array_filter($rows, fn($h) => hol_applies($h, $fb));
$prev = date('Y-m-d', strtotime($yFrom . ' -1 day')); $next = date('Y-m-d', strtotime($yTo . ' +1 day'));
$brCheck = fn(array $sel) => implode('', array_map(fn($b) => '<label class="tick"><input type="checkbox" name="branches[]" value="' . (int)$b['id'] . '"' . (in_array((int)$b['id'], $sel, true) ? ' checked' : '') . '>' . h($b['name']) . '</label>', $brs));
?>
<section class="card">
  <div class="row"><a class="btn sm ghost" href="?y=<?= $prev ?>">‹</a><h2 style="margin:0"><?= h($yLabel) ?></h2><a class="btn sm ghost" href="?y=<?= $next ?>">›</a>
  <?php if ($canEdit): ?><form class="row"><input type="hidden" name="y" value="<?= h($anchor) ?>"><select name="b" onchange="this.form.submit()"><option value="0">All branches</option><?php foreach ($brs as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $fb === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?></select></form><?php elseif ($myBranch): ?><span class="muted">Holidays of <?= h($brName[$myBranch] ?? '') ?></span><?php endif; ?></div>
  <table><thead><tr><th>Date</th><th>Day</th><th>Holiday</th><?php if ($canEdit): ?><th>Branches</th><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rows as $h): $ids = hol_branch_ids((string)$h['branches']); ?>
    <tr<?= $h['active'] ? '' : ' style="opacity:.5"' ?>><td><?= h(date($fmt, strtotime($h['day']))) ?></td><td><?= h(date('D', strtotime($h['day']))) ?></td>
    <td><?= h($h['name']) ?><?= $h['kind'] === 'optional' ? ' <span class="pill">optional</span>' : '' ?><?= $h['active'] ? '' : ' <span class="pill off">off · ' . h($h['off_reason']) . '</span>' ?></td>
    <?php if ($canEdit): ?><td><?= $ids ? h(implode(', ', array_map(fn($i) => $brName[$i] ?? '#' . $i, $ids))) : 'Every branch' ?></td>
    <td><?php if ($h['active']): ?><details><summary class="btn sm ghost">Edit</summary>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="edit"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
        <div class="row"><input type="date" name="day" value="<?= h($h['day']) ?>"><input name="name" value="<?= h($h['name']) ?>"><select name="kind"><?php foreach (HOL_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= $h['kind'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <p><?= $brCheck($ids) ?></p><button class="btn sm">Save</button></form>
      <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="off"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>"><input name="reason" placeholder="Reason" required><button class="btn sm ghost">Switch off</button></form></details><?php endif; ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5"><em>No holidays this year yet.</em></td></tr><?php endif; ?>
  </tbody></table>
</section>

<?php if ($canEdit): ?>
<?php if (isset($_GET['import']) && ($imp = $_SESSION['hol_import'] ?? null)): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="import"><h2>Check result</h2>
  <table><thead><tr><th>From</th><th>To</th><th>Holiday</th><th>Branches</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($imp['plan'] as $p): ?><tr><td><?= h((string)$p['from']) ?></td><td><?= h((string)$p['to']) ?></td><td><?= h($p['name']) ?></td><td><?= $p['branches'] ? h(implode(', ', array_map(fn($i) => $brName[$i] ?? '', $p['branches']))) : 'Every branch' ?></td>
    <td><?= $p['err'] ? '<span class="pill off">' . h(implode('; ', $p['err'])) . '</span>' : '<span class="pill on">OK</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table><button class="btn">Save the OK rows</button> <span class="muted">A date already there with the same holiday only gets its branches ticked.</span></form>
<?php endif; ?>
<div class="grid2">
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="add"><h2>Add a holiday</h2>
  <div class="grid"><label>From <input type="date" name="from" required></label><label>To (for several days) <input type="date" name="to"></label>
  <label>Name <input name="name" required maxlength="80"></label><label>Kind <select name="kind"><?php foreach (HOL_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></label></div>
  <p>Branches (none ticked = every branch):<br><?= $brCheck([]) ?></p><button class="btn sm">Add</button></form>
<section class="card"><h2>Import & copy</h2>
  <form method="post" enctype="multipart/form-data" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="check">
    <input type="file" name="file" accept=".csv" required><button class="btn sm">Check file</button></form>
  <p class="muted">CSV columns: Date, To (optional), Holiday, Branch (names, comma separated, or "All"), Type. Check writes nothing.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="copy"><button class="btn sm ghost">Copy <?= h($yLabel) ?> to next year</button></form>
</section>
</div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="pin"><h2>Follow another branch's holidays</h2>
  <p class="muted">For someone working from another office. Only holidays change — shift and weekly off stay with their own branch.</p>
  <div class="row"><select name="code" required><option value="">Person…</option><?php foreach (emp_on_roll($db, $u) as $p): ?><option value="<?= h($p['code']) ?>"><?= h($p['name'] . ' · ' . $p['code']) ?></option><?php endforeach; ?></select>
  <select name="branch"><option value="0">Their own branch (clear)</option><?php foreach ($brs as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?></select>
  <label class="tick">until <input type="date" name="until"></label><input name="reason" placeholder="Reason"><button class="btn sm">Save</button></div>
  <?php $pins = $db->query('SELECT o.*, e.name FROM holiday_branch_override o LEFT JOIN employee e ON e.code = o.emp_code ORDER BY e.name')->fetchAll(); if ($pins): ?>
  <table><tbody><?php foreach ($pins as $p): ?><tr><td><?= h($p['name'] ?? $p['emp_code']) ?></td><td>→ <?= h($brName[$p['branch_id']] ?? '') ?></td><td><?= $p['until_day'] ? 'until ' . h($p['until_day']) : 'until further notice' ?></td><td class="muted"><?= h($p['reason']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</form>
<?php endif;
ui_foot();
