<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$u = module_require('org');
$db = pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    if ($a === 'profile') {
        $errs = [];
        foreach (array_keys(ORG_FIELDS) as $k) {
            [$val, $e] = org_check($k, (string)($_POST[$k] ?? ''));
            if ($e) $errs[] = $e; else org_set($k, $val, (int)$u['id']);
        }
        if (!empty($_FILES['logo']['tmp_name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $bytes = (string)file_get_contents($_FILES['logo']['tmp_name']);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) $errs[] = 'Logo: PNG, JPEG or WebP only.';
            elseif (strlen($bytes) > 300 * 1024) $errs[] = 'Logo: 300 KB at most.';
            else org_set('logo', 'data:' . $mime . ';base64,' . base64_encode($bytes), (int)$u['id']);
        }
        if (!empty($_POST['logo_clear'])) org_set('logo', '', (int)$u['id']);
        audit((int)$u['id'], 'org_profile', $errs ? 'with errors' : 'saved');
        flash($errs ? implode(' ', $errs) : 'Organisation saved.', $errs ? 'err' : 'ok');
    } elseif ($a === 'branch') {
        $id = (int)($_POST['id'] ?? 0);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $wo = implode(',', array_intersect((array)($_POST['weekoffs'] ?? []), ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']));
        $t = fn($k) => preg_match('/^\d{2}:\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
        if ($code === '' || $name === '') flash('A branch needs a code and a name.', 'err');
        else {
            $row = [$code, $name, trim((string)($_POST['aliases'] ?? '')), trim((string)($_POST['state'] ?? '')),
                    trim((string)($_POST['city'] ?? '')), trim((string)($_POST['address'] ?? '')), $t('shift_start'), $t('shift_end'), $wo,
                    empty($_POST['active']) ? 0 : 1];
            try {
                if ($id) $db->prepare('UPDATE branch SET code=?, name=?, aliases=?, state=?, city=?, address=?, shift_start=?, shift_end=?, weekoffs=?, active=? WHERE id=?')->execute([...$row, $id]);
                else $db->prepare('INSERT INTO branch (code, name, aliases, state, city, address, shift_start, shift_end, weekoffs, active) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($row);
                audit((int)$u['id'], 'org_branch', ($id ? 'edit #' . $id : 'add') . ' ' . $code);
                flash('Branch saved.');
            } catch (PDOException $e) { flash('That code is already used by another branch.', 'err'); }
        }
    } elseif ($a === 'list' && in_array($_POST['t'] ?? '', ['department', 'designation'], true)) {
        $t = $_POST['t'];
        foreach (preg_split('/\R/', (string)($_POST['names'] ?? '')) as $n)
            if (($n = trim($n)) !== '') $db->prepare("INSERT IGNORE INTO $t (name) VALUES (?)")->execute([mb_substr($n, 0, 80)]);
        audit((int)$u['id'], 'org_' . $t, 'added');
        flash(ucfirst($t) . 's added.');
    }
    redirect('organization.php');
}

ui_head('Organisation', $u, 'org');
$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
?>
<form method="post" enctype="multipart/form-data" class="card">
  <h2>Profile</h2><?= csrf_field() ?><input type="hidden" name="a" value="profile">
  <div class="grid">
  <?php foreach (ORG_FIELDS as $k => $f): ?>
    <label><?= h($f['label']) ?>
    <?php if (isset($f['choices'])): ?><select name="<?= $k ?>"><?php foreach ($f['choices'] as $cv => $cl): ?><option value="<?= h($cv) ?>"<?= org($k) === (string)$cv ? ' selected' : '' ?>><?= h($cl) ?></option><?php endforeach; ?></select>
    <?php elseif (!empty($f['color'])): ?><input type="color" name="<?= $k ?>" value="<?= h(org($k)) ?>">
    <?php else: ?><input name="<?= $k ?>" value="<?= h(org($k)) ?>"<?= !empty($f['required']) ? ' required' : '' ?>><?php endif; ?>
    </label>
  <?php endforeach; ?>
    <label>Logo (PNG / JPEG / WebP, ≤ 300 KB) <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
    <?php if (org('logo') !== ''): ?><label class="tick"><input type="checkbox" name="logo_clear" value="1"> Remove the logo</label><?php endif; ?>
  </div>
  <button class="btn">Save profile</button>
</form>

<section class="card"><h2>Branches</h2>
<table><thead><tr><th>Code</th><th>Name</th><th>Other spellings</th><th>State</th><th>Shift</th><th>Weekly off</th><th>Active</th><th></th></tr></thead><tbody>
<?php foreach (array_merge(branches(false), [['id' => 0, 'code' => '', 'name' => '', 'aliases' => '', 'state' => '', 'city' => '', 'address' => '', 'shift_start' => '', 'shift_end' => '', 'weekoffs' => 'Sun', 'active' => 1]]) as $b): $fid = 'b' . $b['id']; ?>
<tr><td><input form="<?= $fid ?>" name="code" value="<?= h($b['code']) ?>" size="6" placeholder="New"></td>
<td><input form="<?= $fid ?>" name="name" value="<?= h($b['name']) ?>"></td>
<td><input form="<?= $fid ?>" name="aliases" value="<?= h($b['aliases']) ?>" placeholder="comma separated"></td>
<td><input form="<?= $fid ?>" name="state" value="<?= h($b['state']) ?>" size="10"></td>
<td><input form="<?= $fid ?>" name="shift_start" type="time" value="<?= h(substr((string)$b['shift_start'], 0, 5)) ?>"> – <input form="<?= $fid ?>" name="shift_end" type="time" value="<?= h(substr((string)$b['shift_end'], 0, 5)) ?>"></td>
<td class="days"><?php foreach ($days as $d): ?><label><input form="<?= $fid ?>" type="checkbox" name="weekoffs[]" value="<?= $d ?>"<?= in_array($d, explode(',', (string)$b['weekoffs']), true) ? ' checked' : '' ?>><?= $d[0] ?></label><?php endforeach; ?></td>
<td><input form="<?= $fid ?>" type="checkbox" name="active" value="1"<?= $b['active'] ? ' checked' : '' ?>></td>
<td><form id="<?= $fid ?>" method="post"><?= csrf_field() ?><input type="hidden" name="a" value="branch"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="city" value="<?= h($b['city']) ?>"><input type="hidden" name="address" value="<?= h($b['address']) ?>"><button class="btn sm"><?= $b['id'] ? 'Save' : 'Add' ?></button></form></td></tr>
<?php endforeach; ?>
</tbody></table></section>

<div class="grid2">
<?php foreach (['department' => 'Departments', 'designation' => 'Designations'] as $t => $label):
  $rows = []; try { $rows = $db->query("SELECT name FROM $t WHERE active = 1 ORDER BY name")->fetchAll(); } catch (Throwable $e) {} ?>
<form method="post" class="card"><h2><?= $label ?></h2><?= csrf_field() ?><input type="hidden" name="a" value="list"><input type="hidden" name="t" value="<?= $t ?>">
  <p class="chips"><?php foreach ($rows as $r): ?><span><?= h($r['name']) ?></span><?php endforeach; ?><?= $rows ? '' : '<em>None yet</em>' ?></p>
  <label>Add (one per line) <textarea name="names" rows="3"></textarea></label><button class="btn sm">Add</button></form>
<?php endforeach; ?>
</div>
<?php ui_foot();
