<?php
/** Privacy notice & terms — the organisation writes its own public pages; each save is a new version, earlier kept. */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require_once WN_ROOT . '/lib/legal.php';
$u = module_require('org');
$db = pdo();
$kind = isset(LG_KINDS[$_GET['k'] ?? '']) ? (string)$_GET['k'] : 'privacy';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $kind = isset(LG_KINDS[$_POST['k'] ?? '']) ? (string)$_POST['k'] : 'privacy';
    try { lg_save($db, $kind, (string)($_POST['body'] ?? ''), (string)($_POST['note'] ?? ''), (int)$u['id']); flash(LG_KINDS[$kind] . ' saved — the public page shows it now.'); }
    catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('legal.php?k=' . $kind);
}
[$body, $date, $draft] = lg_current($db, $kind);
ui_head('Privacy & terms', $u, 'org');
?>
<nav class="row" style="margin-bottom:12px"><?php foreach (LG_KINDS as $k => $l): ?><a class="btn sm<?= $k === $kind ? '' : ' ghost' ?>" href="?k=<?= h($k) ?>"><?= h($l) ?></a><?php endforeach; ?>
  <a class="btn sm ghost" href="../<?= $kind ?>.php" target="_blank" rel="noopener">Open the public page</a></nav>
<div class="grid" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:start">
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="k" value="<?= h($kind) ?>">
  <h2><?= h(LG_KINDS[$kind]) ?> <small><?= $draft ? 'starting text — not saved yet' : 'in force from ' . h($date) ?></small></h2>
  <p class="note">Plain text: a line starting with "## " is a heading, "- " a list line, a blank line starts a new paragraph. {org}, {legal}, {address}, {contact} and {date} are filled from Organisation settings. Read the starting text and adapt it to your organisation and your country's law — it is not legal advice.</p>
  <textarea name="body" rows="26" style="width:100%;font:13px/1.5 ui-monospace,monospace" required><?= h($body) ?></textarea>
  <label>What changed (optional) <input name="note" maxlength="200"></label>
  <button class="btn">Save a new version</button>
</form>
<section class="card legal"><h2>Preview</h2><?= lg_html(lg_fill($body, lg_vars($date ? date('j F Y', strtotime($date)) : date('j F Y')))) ?>
  <h3>Versions</h3><?php $hist = lg_history($db, $kind); if (!$hist): ?><p class="note">None saved yet.</p><?php endif; ?>
  <ul><?php foreach ($hist as $v): ?><li>#<?= (int)$v['id'] ?> · <?= h(substr($v['created_at'], 0, 16)) ?> · <?= h($v['name'] ?? '') ?><?= $v['note'] !== '' ? ' — ' . h($v['note']) : '' ?></li><?php endforeach; ?></ul></section>
</div>
<?php ui_foot();
