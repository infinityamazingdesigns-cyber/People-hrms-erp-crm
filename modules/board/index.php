<?php
// Announcement board — announcements and pictures, celebrations, who is on leave today, own holidays, challenges, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/board.php';
$u = module_require('board');
$db = pdo();
$set = board_settings(); $R = board_rights($u);
$me = board_me($db, $u); $uid = (int)$u['id'];
$today = date('Y-m-d');
$view = (string)($_GET['view'] ?? 'board');
$isAdmin = has_role($u, 'SUPERADMIN');
$up = function (string $field): array {
    $out = [];
    foreach ((array)($_FILES[$field]['tmp_name'] ?? []) as $i => $tmp)
        if ((int)($_FILES[$field]['error'][$i] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string)$tmp)) $out[] = [(string)file_get_contents((string)$tmp), (string)$_FILES[$field]['name'][$i]];
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $to = 'index.php' . ($view !== 'board' ? '?view=' . urlencode($view) : '');
    try {
        switch ($a) {
            case 'post': board_create($db, $u, $_POST, $up('files')); flash('Posted.'); break;
            case 'remove': board_remove($db, $u, (int)$_POST['id']); flash('Removed.'); break;
            case 'pin': board_pin($db, $u, (int)$_POST['id'], !empty($_POST['on'])); flash('Saved.'); break;
            case 'chal': board_challenge_add($db, $u, $_POST); flash('Challenge set.'); $to = 'index.php?view=challenges'; break;
            case 'chal_off': board_challenge_off($db, $u, (int)$_POST['id']); flash('Challenge taken off.'); $to = 'index.php?view=challenges'; break;
            case 'done': board_challenge_done($db, $u, $me, (int)$_POST['id'], $up('photos'), $today); flash('Marked done.'); break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = board_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['board'] ?? ['roles' => module_roles('board')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['board', 1, implode(',', $st['roles'] ?: module_roles('board')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'board'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

if ($view === 'file') {
    $f = board_file_for($db, $u, (string)($_GET['f'] ?? ''));
    if (!$f) { http_response_code(404); exit('Not found'); }
    header('Content-Type: ' . $f['type']); header('Content-Disposition: ' . ((int)$f['is_image'] ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $f['name']) . '"');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    echo is_resource($f['data']) ? stream_get_contents($f['data']) : $f['data']; exit;
}

$fileUrl = fn(string $id) => 'index.php?view=file&f=' . urlencode($id);
ui_head('Announcement board', $u, 'board'); ?>
<nav class="row"><a class="btn sm<?= $view === 'board' ? '' : ' ghost' ?>" href="index.php">Board</a>
<?php if ($R['post']): ?> <a class="btn sm<?= $view === 'challenges' ? '' : ' ghost' ?>" href="?view=challenges">Challenges</a><?php endif; ?>
<?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php if ($view === 'board'):
    $posts = board_list($db, $u, $set);
    $cel = $set['birthdays'] || $set['anniversaries'] ? board_celebrations(array_map(fn($p) => $set['birthdays'] ? $p : ['dob' => null] + $p, board_people($db)), $today, (int)$set['days_ahead'], (bool)$set['anniversaries']) : [];
    $leave = $set['leave'] ? board_on_leave($db, $today) : [];
    $hols = board_holidays($db, $me, $today);
    $chal = array_values(array_filter(board_challenges($db, $u, $me, $today), fn($c) => $c['mine'] && $c['open']));
?>
<div class="grid2" style="grid-template-columns:2fr minmax(240px,1fr)">
<div>
<?php if ($R['post']): ?>
<details class="card"><summary><b>New post</b></summary>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="post">
<div class="grid"><select name="kind" aria-label="Kind"><?php foreach (BOARD_KINDS as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select>
<input name="title" placeholder="Title" maxlength="140" required></div>
<textarea name="body" rows="3" placeholder="Write the announcement" maxlength="4000"></textarea>
<div class="row"><input type="file" name="files[]" multiple> <label><input type="checkbox" name="pinned" value="1"> Pin it</label> <button class="btn">Post</button></div>
<p class="muted">Announcements take up to <?= BOARD_MAX_FILES['news'] ?> files, pictures up to <?= BOARD_MAX_FILES['photos'] ?> photos; 15 MB each.</p>
</form></details>
<?php endif; ?>
<?php if (!$posts): ?><div class="card muted">Nothing posted yet.</div><?php endif; ?>
<?php foreach ($posts as $p): ?>
<article class="card" id="p<?= (int)$p['id'] ?>">
<div class="row" style="justify-content:space-between"><h2 style="margin:0"><?= $p['pinned'] ? '📌 ' : '' ?><?= h($p['title']) ?></h2>
<span class="muted"><?= h(BOARD_KINDS[$p['kind']] ?? '') ?> · <?= h((string)$p['by_name']) ?> · <?= h(date('j M Y', strtotime((string)$p['created_at']))) ?></span></div>
<?php if ($p['body'] !== ''): ?><p style="white-space:pre-wrap"><?= h($p['body']) ?></p><?php endif; ?>
<?php $imgs = array_filter($p['files'], fn($f) => (int)$f['is_image'] && $f['type'] !== 'image/heic'); $docs = array_filter($p['files'], fn($f) => !((int)$f['is_image'] && $f['type'] !== 'image/heic')); ?>
<?php if ($imgs): ?><div class="row" style="flex-wrap:wrap;gap:6px"><?php foreach ($imgs as $f): ?><a href="<?= h($fileUrl($f['id'])) ?>" target="_blank" rel="noopener"><img src="<?= h($fileUrl($f['id'])) ?>" alt="<?= h($f['name']) ?>" style="height:140px;border-radius:8px;object-fit:cover"></a><?php endforeach; ?></div><?php endif; ?>
<?php foreach ($docs as $f): ?><div><a href="<?= h($fileUrl($f['id'])) ?>"><?= h($f['name']) ?></a> <span class="muted"><?= round((int)$f['size'] / 1024) ?> KB</span></div><?php endforeach; ?>
<?php if ($p['can_remove']): ?><div class="row">
<?php if ($p['kind'] === 'news'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="pin"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="on" value="<?= $p['pinned'] ? '' : '1' ?>"><button class="btn sm ghost"><?= $p['pinned'] ? 'Unpin' : 'Pin' ?></button></form><?php endif; ?>
<form method="post" onsubmit="return confirm('Remove this post?')"><?= csrf_field() ?><input type="hidden" name="a" value="remove"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm ghost">Remove</button></form>
</div><?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<aside>
<?php foreach ($chal as $c): ?>
<div class="card"><h3>Today's challenge</h3><b><?= h($c['title']) ?></b> <span class="muted"><?= h($c['label'] === 'Everyone' ? 'Everyone' : 'For you') ?></span>
<?php if ($c['note'] !== ''): ?><p><?= h($c['note']) ?></p><?php endif; ?>
<?php if ($c['done']): ?><p>✓ Done <?= h(date('j M, H:i', strtotime((string)$c['done']['done_at']))) ?></p>
<?php foreach ($c['photos'] ?? [] as $f): ?><a href="<?= h($fileUrl($f['id'])) ?>" target="_blank" rel="noopener"><?= h($f['name']) ?></a> <?php endforeach; ?><?php endif; ?>
<?php if (!$c['done'] || ($c['photo'] !== 'none' && count($c['photos'] ?? []) < BOARD_PHOTO_FILES)): ?>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="a" value="done"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
<?php if ($c['photo'] !== 'none'): ?><input type="file" name="photos[]" accept="image/*" multiple <?= $c['photo'] === 'required' && !$c['done'] ? 'required' : '' ?>><div class="muted"><?= h(BOARD_PHOTO_MODES[$c['photo']]) ?> · seen only by you and the people who set challenges</div><?php endif; ?>
<button class="btn sm"><?= $c['done'] ? 'Add photos' : 'Mark done' ?></button></form>
<?php endif; ?></div>
<?php endforeach; ?>
<?php if ($set['birthdays'] || $set['anniversaries']): ?>
<div class="card"><h3>Celebrations</h3>
<?php if (!$cel): ?><p class="muted">None in the next <?= (int)$set['days_ahead'] ?> days.</p><?php endif; ?>
<?php foreach ($cel as $c): ?><div><?= $c['kind'] === 'birthday' ? '🎂' : '💍' ?> <b><?= h($c['name']) ?></b> <span class="muted"><?= h(implode(' · ', array_filter([$c['title'], $c['dept'], $c['branch']]))) ?></span>
<div class="muted"><?= $c['in'] === 0 ? 'Today' : h(board_md_label($c['md'])) ?></div></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($set['leave']): ?>
<div class="card"><h3>On leave today</h3>
<?php if (!$leave): ?><p class="muted">Nobody.</p><?php endif; ?>
<?php foreach ($leave as $l): ?><div><?= h($l['name']) ?> <span class="muted"><?= h(implode(' · ', array_filter([$l['branch'], $l['part'] === 'Full day' ? '' : $l['part']]))) ?></span></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($hols): ?>
<div class="card"><h3>Your holidays</h3><?php foreach (array_slice($hols, 0, 6, true) as $d => $n): ?><div><?= h($n) ?> <span class="muted"><?= $d === $today ? 'Today' : h(date('D j M', strtotime($d))) ?></span></div><?php endforeach; ?></div>
<?php endif; ?>
</aside>
</div>

<?php elseif ($view === 'challenges' && $R['post']):
    $list = board_challenges($db, $u, $me, $today);
    $depts = $db->query("SELECT DISTINCT department FROM employee WHERE department <> '' AND active = 1 ORDER BY department")->fetchAll(PDO::FETCH_COLUMN); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="chal"><h2>New challenge</h2>
<div class="grid"><input name="title" placeholder="Challenge" maxlength="90" required><input type="date" name="from_day" value="<?= $today ?>" required aria-label="From"><input type="date" name="to_day" value="<?= $today ?>" aria-label="To">
<select name="photo" aria-label="Photo"><?php foreach (BOARD_PHOTO_MODES as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
<input name="note" placeholder="Note (optional)" maxlength="300">
<p class="muted">For: leave everything blank for everyone.</p>
<div class="grid"><select name="branches[]" multiple size="4" aria-label="Branches"><?php foreach (branches() as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?></select>
<select name="depts[]" multiple size="4" aria-label="Departments"><?php foreach ($depts as $d): ?><option><?= h($d) ?></option><?php endforeach; ?></select>
<input name="people" placeholder="Employee codes, comma separated"></div>
<button class="btn">Set challenge</button></form>
<div class="card"><h2>Challenges</h2><table><tr><th>Challenge</th><th>Days</th><th>For</th><th>Photo</th><th>Done by</th><th></th></tr>
<?php foreach ($list as $c): ?><tr><td><b><?= h($c['title']) ?></b><div class="muted"><?= h($c['note']) ?></div></td><td><?= h($c['from_day'] . ($c['to_day'] !== $c['from_day'] ? ' – ' . $c['to_day'] : '')) ?></td>
<td><?= h($c['label']) ?></td><td><?= h(BOARD_PHOTO_MODES[$c['photo']] ?? '') ?></td>
<td><?= count($c['who'] ?? []) ?><?php if (!empty($c['who'])): ?><div class="muted"><?= h(implode(', ', array_map(fn($w) => $w['name'] . ((int)$w['photos'] ? ' (' . (int)$w['photos'] . ' photo' . ((int)$w['photos'] > 1 ? 's' : '') . ')' : ''), $c['who']))) ?></div><?php endif; ?></td>
<td><form method="post" onsubmit="return confirm('Take this challenge off?')"><?= csrf_field() ?><input type="hidden" name="a" value="chal_off"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sm ghost">Take off</button></form></td></tr><?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="6" class="muted">No challenges yet.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'settings' && $isAdmin): $roles = role_labels(); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<?php foreach (['post_roles' => 'Who posts and sets challenges', 'moderate_roles' => 'Who removes anyone\'s post'] as $k => $l): ?>
<h3><?= h($l) ?></h3><div class="row" style="flex-wrap:wrap"><?php foreach ($roles as $c => $n): ?><label><input type="checkbox" name="<?= $k ?>[]" value="<?= h($c) ?>"<?= in_array($c, $set[$k], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div>
<?php endforeach; ?>
<h3>Show</h3>
<label><input type="checkbox" name="birthdays" value="1"<?= $set['birthdays'] ? ' checked' : '' ?>> Birthdays (day and month only)</label><br>
<label><input type="checkbox" name="anniversaries" value="1"<?= $set['anniversaries'] ? ' checked' : '' ?>> Wedding anniversaries (from approved profiles)</label><br>
<label><input type="checkbox" name="leave" value="1"<?= $set['leave'] ? ' checked' : '' ?>> Who is on approved leave today (names only)</label>
<div class="grid"><label>Celebrations ahead (days) <input type="number" name="days_ahead" min="0" max="31" value="<?= (int)$set['days_ahead'] ?>"></label>
<label>Posts shown for (days) <input type="number" name="keep_days" min="14" max="730" value="<?= (int)$set['keep_days'] ?>"></label></div>
<button class="btn">Save</button></form>
<?php endif;
ui_foot();
