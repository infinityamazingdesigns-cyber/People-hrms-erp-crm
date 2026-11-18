<?php
// Careers — public job board and application form (no sign-in). Public details of open positions only; spam checks
// (hidden field, minimum time, signed form time, hourly limit per hashed address); a repeat within 15 minutes gets
// the same reference. The CV can be read on this server to fill empty boxes; nothing is sent anywhere else.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/careers.php';
module_public('careers');
header('X-Robots-Tag: noindex');
$db = pdo(); $set = car_settings(); $today = date('Y-m-d'); $key = seal_key();
$pos = car_positions($db, $set); $open = array_column($pos, 'id');
$upload = function (): ?array { $t = $_FILES['cv']['tmp_name'] ?? ''; return is_string($t) && $t !== '' && (int)($_FILES['cv']['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file($t) ? [(string)file_get_contents($t), (string)$_FILES['cv']['name']] : null; };

if (($_GET['read'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    if (!car_hit($db, 'read', (int)$set['reads_hour'])) { echo json_encode(['ok' => false, 'msg' => 'Please wait a little and try again.']); exit; }
    $f = $upload();
    if (!$f || strlen($f[0]) > RC_CV_BYTES || !rc_cv_type($f[0], $f[1])) { echo json_encode(['ok' => false, 'msg' => 'Pick a PDF or Word file up to 5 MB.']); exit; }
    $r = car_read_cv($f[0], $set);
    echo json_encode($r['ok'] ? ['ok' => true, 'fields' => $r['fields']] : ['ok' => false, 'msg' => 'We could not read text from this file — please fill the boxes yourself.']); exit;
}

$err = []; $v = $_POST; $ref = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    [$clean, $err] = car_check($_POST, $open, $set, $today, car_stamp_age((string)($_POST['t'] ?? ''), time(), $key));
    $cv = $upload();
    if (!$cv && $set['need_cv']) $err['cv'] = 'Attach your CV.';
    if (!$err && !car_hit($db, 'send', (int)$set['sends_hour'])) $err['_'] = 'Too many applications from here just now. Please try again in an hour.';
    if (!$err) {
        try { $ref = car_apply($db, $clean, $cv, $set); redirect('apply.php?ok=' . urlencode($ref)); }
        catch (Throwable $e) { $err['cv'] = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong — please try again.'; if (!$e instanceof RuntimeException) error_log('careers: ' . $e->getMessage()); }
    }
}
$okRef = preg_match('/^APP-\d{4}-\d{4,}$/', (string)($_GET['ok'] ?? '')) ? (string)$_GET['ok'] : '';
$val = fn(string $k) => h((string)($v[$k] ?? ''));
$e = fn(string $k) => isset($err[$k]) ? '<div class="err" style="color:#b42318">' . h($err[$k]) . '</div>' : '';
$preset = (int)($_GET['p'] ?? ($v['position'] ?? 0));
ui_head(($set['title'] ?: 'Careers') . ' · ' . org('name')); ?>
<div class="card"><h1 style="margin:0"><?= h($set['title']) ?></h1><p class="muted"><?= h(org('name')) ?></p>
<?php if ($set['intro'] !== ''): ?><p style="white-space:pre-wrap"><?= h($set['intro']) ?></p><?php endif; ?>
<?php if ($rt = car_ratings($set)): ?><p><?php foreach ($rt as $r): ?><span style="margin-right:14px"><?= $r['url'] !== '' ? '<a href="' . h($r['url']) . '" rel="noopener" target="_blank">' : '' ?><b><?= h(number_format($r['rating'], 1)) ?> ★</b> <?= h($r['source']) ?> · <?= (int)$r['count'] ?> reviews<?= $r['url'] !== '' ? '</a>' : '' ?></span><?php endforeach; ?></p><?php endif; ?>
<?php if ($set['contact_email'] !== '' || $set['contact_phone'] !== ''): ?><p class="muted">Questions: <?= h(implode(' · ', array_filter([$set['contact_email'], $set['contact_phone']]))) ?></p><?php endif; ?></div>

<?php if ($okRef !== ''): ?>
<div class="card"><h2>Application sent</h2><p><?= h($set['thanks']) ?></p><p>Your reference: <b><?= h($okRef) ?></b></p><a class="btn ghost" href="apply.php">Back to openings</a></div>
<?php else: ?>
<div class="card"><h2>Open positions</h2>
<?php if ($pos): ?><input id="jobq" placeholder="Search by title, skill or place" style="max-width:360px">
<table id="jobs"><tr><th>Position</th><th>Place</th><th>Experience</th><th>Openings</th><th></th></tr>
<?php foreach ($pos as $p): ?><tr data-q="<?= h(mb_strtolower($p['title'] . ' ' . $p['department'] . ' ' . $p['place'] . ' ' . $p['skills'])) ?>"><td><b><?= h($p['title']) ?></b><div class="muted"><?= h(implode(' · ', array_filter([$p['department'], $p['type'], $p['qual']]))) ?></div>
<?php if ($p['jd'] !== '' || $p['skills'] !== ''): ?><details><summary class="muted">About the role</summary><?= $p['skills'] !== '' ? '<p>Skills: ' . h($p['skills']) . '</p>' : '' ?><p style="white-space:pre-wrap"><?= h($p['jd']) ?></p></details><?php endif; ?></td>
<td><?= h($p['place']) ?></td><td><?= h($p['exp']) ?></td><td><?= $p['openings'] ?></td><td><a class="btn sm" href="?p=<?= $p['id'] ?>#apply">Apply</a></td></tr><?php endforeach; ?></table>
<?php else: ?><p class="muted">No openings right now.<?= $set['other'] ? ' You can still send your CV below.' : '' ?></p><?php endif; ?></div>

<?php if ($pos || $set['other']): ?>
<form method="post" enctype="multipart/form-data" class="card" id="apply" novalidate><?= csrf_field() ?><input type="hidden" name="t" value="<?= h(car_stamp(time(), $key)) ?>">
<div style="position:absolute;left:-9999px" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
<h2>Apply</h2><?= $e('_') ?>
<h3>1 · The opening</h3>
<label>Position <select name="position" required><?php if (!$pos || $set['other']): ?><option value="0"<?= !$preset ? ' selected' : '' ?>><?= $pos ? 'No opening fits — send my CV' : 'Send my CV for future openings' ?></option><?php endif; ?>
<?php foreach ($pos as $p): ?><option value="<?= $p['id'] ?>"<?= $preset === $p['id'] ? ' selected' : '' ?>><?= h($p['title'] . ($p['place'] !== '' ? ' · ' . $p['place'] : '')) ?></option><?php endforeach; ?></select></label><?= $e('position') ?>
<h3>2 · Your CV</h3>
<label>CV (PDF or Word, up to 5 MB)<?= $set['need_cv'] ? ' *' : '' ?> <input type="file" name="cv" id="cv" accept=".pdf,.doc,.docx,.odt,.rtf,.txt"<?= $set['need_cv'] ? ' required' : '' ?>></label><?= $e('cv') ?>
<button type="button" class="btn sm ghost" id="readcv">Fill the form from my CV</button> <span id="readmsg" class="muted"></span>
<h3>3 · About you</h3>
<div class="grid"><label>Full name * <input name="name" value="<?= $val('name') ?>" required maxlength="120" autocomplete="name"></label><?= $e('name') ?>
<label>Mobile * <input name="phone" value="<?= $val('phone') ?>" required inputmode="tel" autocomplete="tel"></label><?= $e('phone') ?>
<label>Email * <input type="email" name="email" value="<?= $val('email') ?>" required autocomplete="email"></label><?= $e('email') ?>
<?php if ($set['ask_dob']): ?><label>Date of birth * <input type="date" name="dob" value="<?= $val('dob') ?>" required max="<?= date('Y-m-d', strtotime('-' . (int)$set['min_age'] . ' years')) ?>"></label><?= $e('dob') ?><?php endif; ?>
<label>Gender <select name="gender"><option value="">—</option><?php foreach (CAR_GENDERS as $g): ?><option<?= ($v['gender'] ?? '') === $g ? ' selected' : '' ?>><?= h($g) ?></option><?php endforeach; ?></select></label>
<label>City you live in * <input name="city" value="<?= $val('city') ?>" required></label><?= $e('city') ?>
<label>Open to moving? <select name="relocate"><?php foreach (['' => '—', 'yes' => 'Yes', 'travel' => 'Can travel', 'no' => 'No'] as $k => $l): ?><option value="<?= $k ?>"<?= ($v['relocate'] ?? '') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label></div>
<h3>4 · Your work</h3>
<div class="grid"><label>Highest qualification * <input name="qualification" value="<?= $val('qualification') ?>" required></label><?= $e('qualification') ?>
<label>Total experience (years) <input name="total_exp" value="<?= $val('total_exp') ?>" inputmode="decimal" placeholder="0 if a fresher"></label><?= $e('total_exp') ?>
<label>Current / last job title <input name="cur_title" value="<?= $val('cur_title') ?>"></label>
<label>Current / last employer <input name="cur_company" value="<?= $val('cur_company') ?>"></label>
<label>Notice period <select name="notice"><option value="">—</option><?php foreach (CAR_NOTICE as $k => $l): ?><option value="<?= $k ?>"<?= ($v['notice'] ?? '') === (string)$k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label></div>
<label>Key skills <input name="skills" value="<?= $val('skills') ?>" placeholder="Comma separated"></label>
<label>Anything you want us to know <textarea name="about" rows="3" maxlength="2000"><?= $val('about') ?></textarea></label>
<label><input type="checkbox" name="consent" value="1" required<?= !empty($v['consent']) ? ' checked' : '' ?>> <?= h($set['consent']) ?></label><?= $e('consent') ?>
<button class="btn">Send application</button></form>
<script>
(function () {
  var q = document.getElementById('jobq');
  if (q) q.addEventListener('input', function () { var t = q.value.toLowerCase().trim(); document.querySelectorAll('#jobs tr[data-q]').forEach(function (r) { r.style.display = !t || r.getAttribute('data-q').indexOf(t) >= 0 ? '' : 'none'; }); });
  var f = document.getElementById('apply'), b = document.getElementById('readcv'), m = document.getElementById('readmsg'), cv = document.getElementById('cv');
  b.addEventListener('click', function () {
    if (!cv.files.length) { m.textContent = 'Choose your CV first.'; return; }
    var d = new FormData(); d.append('cv', cv.files[0]); d.append('csrf', f.querySelector('[name=csrf]').value);
    m.textContent = 'Reading…';
    fetch('apply.php?read=1', { method: 'POST', body: d, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { m.textContent = j.msg; return; }
      var n = 0; Object.keys(j.fields).forEach(function (k) { var el = f.querySelector('[name="' + k + '"]'); if (el && !el.value) { el.value = j.fields[k]; el.style.outline = '2px solid #f0b429'; n++; } });
      m.textContent = n ? n + ' boxes filled from your CV — please check them.' : 'Nothing new to fill.';
    }).catch(function () { m.textContent = 'Could not read the file just now.'; });
  });
  f.addEventListener('submit', function (ev) {
    var bad = null; f.querySelectorAll('[required]').forEach(function (el) { var ok = el.type === 'checkbox' ? el.checked : el.value.trim() !== ''; el.style.outline = ok ? '' : '2px solid #b42318'; if (!ok && !bad) bad = el; });
    if (bad) { ev.preventDefault(); bad.focus(); m.textContent = 'Please fill the boxes marked in red — your CV stays attached.'; }
  });
})();
</script>
<?php endif; endif;
ui_foot();
