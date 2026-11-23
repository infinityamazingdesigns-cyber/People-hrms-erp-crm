<?php
/** Sign-in pictures — up to 5 background pictures for the sign-in page and where the sign-in card sits. */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require_once WN_ROOT . '/lib/login_slides.php';
$u = module_require('org');
$db = pdo(); $uid = (int)$u['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (string)($_POST['id'] ?? '');
    try {
        switch ($a) {
            case 'add':
                $f = $_FILES['pic'] ?? null;
                if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('No picture was received. Choose a file and try again.');
                ls_add($db, (string)$f['tmp_name'], $_POST, $uid); flash('Picture added.'); break;
            case 'edit': ls_update($db, $id, $_POST, $uid); flash('Saved.'); break;
            case 'up': case 'down': ls_move($db, $id, $a === 'up' ? -1 : 1, $uid); break;
            case 'remove': ls_remove($db, $id, $uid); flash('Taken off the sign-in page.'); break;
            case 'layout':
                $l = ls_layout_of((string)($_POST['layout'] ?? '')); org_set('login_layout', $l, $uid); audit($uid, 'org_login_layout', $l);
                flash('Layout saved.'); break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('login_pictures.php');
}
$list = ls_list($db); $lay = ls_layout();
ui_head('Sign-in pictures', $u, 'org');
?>
<div class="card">
  <h2>Layout</h2>
  <form method="post" class="ls-lay"><?= csrf_field() ?><input type="hidden" name="a" value="layout">
    <?php foreach (LS_LAYOUTS as $k => [$l, $d]): ?>
      <label class="tick"><input type="radio" name="layout" value="<?= h($k) ?>"<?= $lay === $k ? ' checked' : '' ?>> <b><?= h($l) ?></b> — <?= h($d) ?></label>
    <?php endforeach; ?>
    <button class="btn sm">Save layout</button>
    <a class="btn sm ghost" href="../login.php?preview=1" target="_blank" rel="noopener">See the sign-in page</a>
  </form>
  <p class="note">Anyone who opens the sign-in page sees these pictures. Each one is re-drawn on the server, which drops camera details such as location. With no picture the layout still works on the organisation's colours.</p>
</div>
<div class="card">
  <h2>Pictures <small><?= count($list) ?> of <?= LS_MAX ?></small></h2>
  <?php foreach ($list as $i => $e): ?>
  <div class="ls-row">
    <img src="../login_slide.php?id=<?= h($e['id']) ?>&amp;v=<?= (int)$e['v'] ?>" alt="" width="180" height="101" loading="lazy">
    <form method="post" class="ls-meta"><?= csrf_field() ?><input type="hidden" name="a" value="edit"><input type="hidden" name="id" value="<?= h($e['id']) ?>">
      <input name="tag" value="<?= h($e['tag']) ?>" maxlength="40" placeholder="Tag">
      <input name="title" value="<?= h($e['title']) ?>" maxlength="90" placeholder="Headline">
      <input name="text" value="<?= h($e['text']) ?>" maxlength="160" placeholder="Line of text">
      <button class="btn sm ghost">Save</button></form>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= h($e['id']) ?>">
      <button class="btn sm ghost" name="a" value="up"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Earlier">↑</button>
      <button class="btn sm ghost" name="a" value="down"<?= $i === count($list) - 1 ? ' disabled' : '' ?> aria-label="Later">↓</button>
      <button class="btn sm ghost" name="a" value="remove">Remove</button></form>
    <small><?= (int)$e['w'] ?>×<?= (int)$e['h'] ?></small>
  </div>
  <?php endforeach; ?>
  <?php if (count($list) < LS_MAX): ?>
  <form method="post" enctype="multipart/form-data" class="ls-add"><?= csrf_field() ?><input type="hidden" name="a" value="add">
    <label>Picture (JPG, PNG or WebP, at least 1600 px wide, up to 12 MB) <input type="file" name="pic" accept="image/jpeg,image/png,image/webp" required></label>
    <input name="tag" maxlength="40" placeholder="Tag (optional)"><input name="title" maxlength="90" placeholder="Headline (optional)"><input name="text" maxlength="160" placeholder="Line of text (optional)">
    <button class="btn sm">Add picture</button></form>
  <?php endif; ?>
</div>
<style>.ls-row{display:grid;grid-template-columns:180px 1fr auto auto;gap:12px;align-items:center;padding:10px 0;border-top:1px solid var(--line)}
.ls-row img{width:180px;height:101px;object-fit:cover;border-radius:8px}.ls-meta{display:grid;gap:6px}.ls-add{display:grid;gap:8px;margin-top:12px;max-width:560px}
.ls-lay{display:grid;gap:6px}@media (max-width:760px){.ls-row{grid-template-columns:1fr}}</style>
<?php ui_foot();
