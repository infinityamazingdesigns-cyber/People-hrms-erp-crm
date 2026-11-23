<?php
/** Office registers — counted property per branch and the office library (lib/office_reg.php). */
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/office_reg.php';
$u = module_require('officereg');
$db = pdo();
$tab = in_array($_GET['t'] ?? '', ['property', 'books', 'import', 'off'], true) ? (string)$_GET['t'] : 'property';
$bIds = or_branch_ids($u);
$brs = array_values(array_filter(branches(false), fn($b) => in_array((int)$b['id'], $bIds, true)));
$bName = array_column($brs, 'name', 'id');
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $back = 'index.php?t=' . $tab;
    try {
        switch ($a) {
            case 'prop': or_save($db, $u, 'prop', $_POST, $id); flash('Saved.'); $back = 'index.php?t=property'; break;
            case 'book': or_save($db, $u, 'book', $_POST, $id); flash('Saved.'); $back = 'index.php?t=books'; break;
            case 'off': case 'on': or_switch($db, $u, (string)$_POST['what'], $id, $a === 'on', (string)($_POST['reason'] ?? '')); flash($a === 'on' ? 'Back on the register.' : 'Taken off the register (kept).'); break;
            case 'lend': or_lend($db, $u, $id, (string)($_POST['code'] ?? ''), (string)($_POST['person'] ?? ''), (string)($_POST['date'] ?? $today), (string)($_POST['due'] ?? '')); flash('Book given.'); $back = 'index.php?t=books#b' . $id; break;
            case 'back': or_take_back($db, $u, (int)$_POST['issue'], (string)($_POST['date'] ?? $today)); flash('Book back on the shelf.'); $back = 'index.php?t=books'; break;
            case 'check': case 'save_import':
                $kind = ($_POST['kind'] ?? '') === 'book' ? 'book' : 'prop';
                if ($a === 'check') {
                    $f = $_FILES['file'] ?? null;
                    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Choose a CSV or Excel file.');
                    $rows = tbl_read((string)$f['tmp_name'], (string)$f['name'], 5000); @unlink((string)$f['tmp_name']);
                    $def = (int)($_POST['branch_id'] ?? 0) ?: null;
                    $of = function (string $s) use ($brs) { $l = branch_label($s, $brs); foreach ($brs as $b) if ($b['name'] === $l) return (int)$b['id']; return -1; };
                    $plan = or_import_plan($kind, $rows, or_have($db, $kind), $of, $def, $bIds, emp_scope($u) !== null);
                    $_SESSION['or_plan'] = ['kind' => $kind, 'plan' => $plan, 'uid' => (int)$u['id'], 'at' => time()];
                    if (!empty($plan['error'])) flash($plan['error'], 'err');
                } else {
                    $P = $_SESSION['or_plan'] ?? null; unset($_SESSION['or_plan']);
                    if (!$P || $P['uid'] !== (int)$u['id'] || time() - $P['at'] > 3600 || $P['kind'] !== $kind) throw new RuntimeException('Check the file again.');
                    $n = or_import_save($db, $u, $kind, $P['plan']); flash($n . ' added.');
                    $back = 'index.php?t=' . ($kind === 'book' ? 'books' : 'property');
                }
                if ($a === 'check') $back = 'index.php?t=import'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e instanceof PDOException ? 'Could not save. Please try again.' : $e->getMessage(), 'err'); }
    redirect($back);
}

$fb = isset($_GET['b']) && in_array((int)$_GET['b'], $bIds, true) ? (int)$_GET['b'] : 0;
$inB = fn($r) => !$fb || (int)$r['branch_id'] === $fb;
$props = array_values(array_filter(or_props($db, $u), $inB));
$books = array_values(array_filter(or_books($db, $u), $inB));
$late = []; foreach ($books as $b) foreach (or_overdue($b['issues'], $today) as $i) $late[] = $i + ['title' => $b['title']];
$roll = emp_on_roll($db, $u);
$brSel = function (string $name, $cur, ?bool $blank = null) use ($brs, $u) {
    $blank ??= emp_scope($u) === null;
    $o = '<select name="' . $name . '">' . ($blank ? '<option value="">No branch</option>' : '');
    foreach ($brs as $b) $o .= '<option value="' . (int)$b['id'] . '"' . ((int)$cur === (int)$b['id'] ? ' selected' : '') . '>' . h($b['name']) . '</option>';
    return $o . '</select>';
};
$fmt = fn(?string $d) => $d ? date('j M Y', strtotime($d)) : '';
ui_head('Office registers', $u, 'officereg');
?>
<nav class="row" style="margin-bottom:10px">
  <?php foreach (['property' => 'Property', 'books' => 'Library', 'import' => 'Import', 'off' => 'Taken off'] as $k => $l): ?><a class="btn sm<?= $tab === $k ? '' : ' ghost' ?>" href="?t=<?= $k ?>"><?= $l ?></a><?php endforeach; ?>
  <?php if (count($brs) > 1 && in_array($tab, ['property', 'books'], true)): ?>
  <form method="get" class="row" style="margin-left:auto"><input type="hidden" name="t" value="<?= h($tab) ?>"><select name="b" onchange="this.form.submit()"><option value="">Every branch</option><?php foreach ($brs as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $fb === (int)$b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?></select></form>
  <?php endif; ?>
</nav>

<?php if ($tab === 'property'): ?>
<div class="card"><h2>Property <small><?= count($props) ?> lines · <?= array_sum(array_column($props, 'qty')) ?> pieces</small></h2>
  <p class="note">Things a branch holds and counts. Tagged things given to a person (laptops, phones, SIMs) belong in the Asset register.</p>
  <table class="tbl"><thead><tr><th>No</th><th>Item</th><th>Labels</th><th>Qty</th><th>Where / with whom</th><th>Branch</th><th>Notes</th><th></th></tr></thead><tbody>
  <?php foreach ($props as $p): ?>
    <tr><td><?= h($p['no']) ?></td><td><?= h($p['name']) ?></td><td><?= h($p['labels']) ?></td><td><?= (int)$p['qty'] ?></td><td><?= h($p['place']) ?></td><td><?= h($bName[$p['branch_id']] ?? '') ?></td><td><?= h($p['notes']) ?></td>
      <td><details><summary>Change</summary>
        <form method="post" class="grid"><?= csrf_field() ?><input type="hidden" name="a" value="prop"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <input name="no" value="<?= h($p['no']) ?>" placeholder="No" maxlength="10"><input name="name" value="<?= h($p['name']) ?>" required maxlength="160"><input name="labels" value="<?= h($p['labels']) ?>" placeholder="Labels" maxlength="120">
          <input name="qty" type="number" min="1" value="<?= (int)$p['qty'] ?>"><input name="place" value="<?= h($p['place']) ?>" placeholder="Where / with whom" maxlength="160"><?= $brSel('branch_id', $p['branch_id']) ?>
          <input name="notes" value="<?= h($p['notes']) ?>" placeholder="Notes" maxlength="500"><button class="btn sm">Save</button></form>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="off"><input type="hidden" name="what" value="prop"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input name="reason" placeholder="Why off the register" required maxlength="200"><button class="btn sm ghost">Take off</button></form>
      </details></td></tr>
  <?php endforeach; ?>
  <?php if (!$props): ?><tr><td colspan="8" class="note">Nothing on the register yet.</td></tr><?php endif; ?>
  </tbody></table>
  <h3>Add a line</h3>
  <form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))"><?= csrf_field() ?><input type="hidden" name="a" value="prop">
    <input name="no" placeholder="No" maxlength="10"><input name="name" placeholder="Item" required maxlength="160"><input name="labels" placeholder="Labels" maxlength="120"><input name="qty" type="number" min="1" value="1" aria-label="Quantity">
    <input name="place" placeholder="Where / with whom" maxlength="160"><?= $brSel('branch_id', $fb ?: (count($brs) === 1 ? $brs[0]['id'] : 0)) ?><input name="notes" placeholder="Notes" maxlength="500"><button class="btn">Add</button></form>
</div>

<?php elseif ($tab === 'books'): ?>
<?php if ($late): ?><div class="card"><h2>Past the return date</h2><ul><?php foreach ($late as $i): ?><li><?= h($i['title']) ?> — <?= h($i['person']) ?>, due <?= h($fmt($i['due'])) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card"><h2>Library <small><?= count($books) ?> books · <?= array_sum(array_map(fn($b) => (int)$b['copies'] - $b['free'], $books)) ?> lent out</small></h2>
  <table class="tbl"><thead><tr><th>Book</th><th>Type</th><th>Author</th><th>Language</th><th>Branch</th><th>On the shelf</th><th></th></tr></thead><tbody>
  <?php foreach ($books as $b): $out = array_values(array_filter($b['issues'], fn($i) => empty($i['returned']))); ?>
    <tr id="b<?= (int)$b['id'] ?>"><td><?= h($b['title']) ?><?= $b['no'] !== '' ? ' <small>#' . h($b['no']) . '</small>' : '' ?></td><td><?= h($b['type']) ?></td><td><?= h($b['author']) ?></td><td><?= h($b['language']) ?></td><td><?= h($bName[$b['branch_id']] ?? '') ?></td>
      <td><?= (int)$b['free'] ?> of <?= (int)$b['copies'] ?><?php foreach ($out as $i): ?><br><small>With <?= h($i['person']) ?> since <?= h($fmt($i['issued'])) ?><?= $i['due'] && $i['due'] < $today ? ' · <b>late</b>' : '' ?></small>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="back"><input type="hidden" name="issue" value="<?= (int)$i['id'] ?>"><input type="date" name="date" value="<?= $today ?>" max="<?= $today ?>"><button class="btn sm ghost">Back</button></form><?php endforeach; ?></td>
      <td><details><summary>More</summary>
        <?php if ($b['free'] > 0): ?><form method="post" class="grid"><?= csrf_field() ?><input type="hidden" name="a" value="lend"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <select name="code"><option value="">Someone on the roll…</option><?php foreach ($roll as $e): ?><option value="<?= h($e['code']) ?>"><?= h($e['name']) ?></option><?php endforeach; ?></select>
          <input name="person" placeholder="…or type a name" maxlength="120"><label>Given on <input type="date" name="date" value="<?= $today ?>" max="<?= $today ?>"></label><label>Back by <input type="date" name="due"></label><button class="btn sm">Give</button></form><?php endif; ?>
        <form method="post" class="grid"><?= csrf_field() ?><input type="hidden" name="a" value="book"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <input name="title" value="<?= h($b['title']) ?>" required maxlength="160"><input name="type" value="<?= h($b['type']) ?>" placeholder="Type" maxlength="40"><input name="author" value="<?= h($b['author']) ?>" placeholder="Author" maxlength="120">
          <input name="language" value="<?= h($b['language']) ?>" placeholder="Language" maxlength="40"><input name="copies" type="number" min="1" value="<?= (int)$b['copies'] ?>" aria-label="Copies"><input name="no" value="<?= h($b['no']) ?>" placeholder="No" maxlength="10"><?= $brSel('branch_id', $b['branch_id']) ?><button class="btn sm ghost">Save</button></form>
        <?php if ($b['issues']): ?><p><small>History: <?= h(implode(' · ', array_map(fn($i) => $i['person'] . ' ' . $fmt($i['issued']) . ($i['returned'] ? '–' . $fmt($i['returned']) : ''), $b['issues']))) ?></small></p><?php endif; ?>
        <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="off"><input type="hidden" name="what" value="book"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input name="reason" placeholder="Why off the register" required maxlength="200"><button class="btn sm ghost">Take off</button></form>
      </details></td></tr>
  <?php endforeach; ?>
  <?php if (!$books): ?><tr><td colspan="7" class="note">No books yet.</td></tr><?php endif; ?>
  </tbody></table>
  <h3>Add a book</h3>
  <form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))"><?= csrf_field() ?><input type="hidden" name="a" value="book">
    <input name="title" placeholder="Title" required maxlength="160"><input name="type" placeholder="Type" maxlength="40"><input name="author" placeholder="Author" maxlength="120"><input name="language" placeholder="Language" maxlength="40">
    <input name="copies" type="number" min="1" value="1" aria-label="Copies"><input name="no" placeholder="No" maxlength="10"><?= $brSel('branch_id', $fb ?: (count($brs) === 1 ? $brs[0]['id'] : 0)) ?><button class="btn">Add</button></form>
</div>

<?php elseif ($tab === 'import'): $P = $_SESSION['or_plan'] ?? null; ?>
<div class="card"><h2>Import a list</h2>
  <p class="note">CSV or Excel (.xlsx). Columns are found by their titles — property: Name / Item, Labels, Quantity, Place, Notes, Branch; books: Title / Book name, Type, Author, Language, Copies, Branch. Check writes nothing; a line already on the register (same branch and name) is skipped, never overwritten. The file is not kept.</p>
  <form method="post" enctype="multipart/form-data" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="check">
    <select name="kind"><option value="prop">Property list</option><option value="book">Book list</option></select>
    <?= $brSel('branch_id', 0) ?><span class="note">(branch when the file has none)</span>
    <input type="file" name="file" accept=".csv,.xlsx" required><button class="btn">Check</button></form>
</div>
<?php if ($P && $P['uid'] === (int)$u['id'] && empty($P['plan']['error'])): $pl = $P['plan']; $kk = $P['kind'] === 'book' ? 'title' : 'name'; ?>
<div class="card"><h2>Checked <small><?= $pl['new'] ?> new · <?= $pl['same'] ?> already there · <?= $pl['bad'] ?> not readable</small></h2>
  <table class="tbl"><thead><tr><th>Line</th><th></th><th><?= $kk === 'title' ? 'Book' : 'Item' ?></th><th>Branch</th><th><?= $kk === 'title' ? 'Copies' : 'Qty' ?></th></tr></thead><tbody>
  <?php foreach (array_slice($pl['rows'], 0, 300) as $r): ?><tr><td><?= (int)$r['line'] ?></td><td><?= ['new' => 'New', 'same' => 'Already there', 'bad' => h($r['why'])][$r['status']] ?></td><td><?= h($r['v'][$kk]) ?></td><td><?= h($bName[$r['v']['branch_id']] ?? '') ?></td><td><?= (int)($r['v']['copies'] ?? $r['v']['qty'] ?? 1) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($pl['new']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="save_import"><input type="hidden" name="kind" value="<?= h($P['kind']) ?>"><button class="btn">Add the <?= $pl['new'] ?> new lines</button></form><?php endif; ?>
</div>
<?php endif; ?>

<?php else: $offP = or_props($db, $u, true); $offB = or_books($db, $u, true); ?>
<div class="card"><h2>Taken off the register</h2>
  <table class="tbl"><thead><tr><th>What</th><th>Name</th><th>Branch</th><th>Why</th><th></th></tr></thead><tbody>
  <?php foreach ([['prop', $offP, 'name'], ['book', $offB, 'title']] as [$w, $list, $col]) foreach ($list as $r): ?>
    <tr><td><?= $w === 'book' ? 'Book' : 'Property' ?></td><td><?= h($r[$col]) ?></td><td><?= h($bName[$r['branch_id']] ?? '') ?></td><td><?= h($r['off_reason']) ?></td>
      <td><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="on"><input type="hidden" name="what" value="<?= $w ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost">Put back</button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$offP && !$offB): ?><tr><td colspan="5" class="note">Nothing taken off.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php endif; ?>
<?php ui_foot();
