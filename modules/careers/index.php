<?php
// Careers page — HR side: applications from the page, which positions are on the job board, page settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/careers.php';
$u = module_require('careers');
$db = pdo(); $set = car_settings(); $uid = (int)$u['id'];
$isAdmin = has_role($u, 'SUPERADMIN', 'HRMANAGER');
$view = (string)($_GET['view'] ?? 'apps');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $to = 'index.php?view=' . urlencode($view);
    try {
        if ($a === 'board') {
            rc_need($u, 'manage');
            $on = array_map('intval', (array)($_POST['on'] ?? []));
            foreach ($db->query("SELECT id, careers FROM rc_requisition WHERE status IN ('open','hold')") as $r) {
                $want = in_array((int)$r['id'], $on, true) ? 1 : 0;
                if ((int)$r['careers'] !== $want) { $db->prepare('UPDATE rc_requisition SET careers = ? WHERE id = ?')->execute([$want, (int)$r['id']]); rc_log($db, 'req', (int)$r['id'], $want ? 'careers:on' : 'careers:off', $uid); }
            }
            flash('Job board saved.');
        } elseif ($a === 'settings') {
            if (!$isAdmin) throw new RuntimeException('Only the Super Admin or HR Manager changes these.');
            [$new, $err] = car_settings_check($_POST);
            if ($err) throw new RuntimeException(implode(' ', $err));
            $st = module_states()['careers'] ?? ['roles' => module_roles('careers')];
            $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
               ->execute(['careers', 1, implode(',', $st['roles'] ?: module_roles('careers')), json_encode($new), $uid]);
            audit($uid, 'module_settings', 'careers'); flash('Settings saved.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}
$pub = wn_base() . 'modules/careers/apply.php';
ui_head('Careers page', $u, 'careers'); ?>
<nav class="row"><a class="btn sm<?= $view === 'apps' ? '' : ' ghost' ?>" href="index.php">Applications</a> <a class="btn sm<?= $view === 'board' ? '' : ' ghost' ?>" href="?view=board">Job board</a>
<?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?> <a class="btn sm ghost" href="<?= h($pub) ?>" target="_blank" rel="noopener">Open the public page</a></nav>

<?php if ($view === 'apps'): $rows = car_recent($db); ?>
<div class="card"><h2>From the careers page · last 60 days</h2><p class="muted">Each one is already in the recruitment pipeline (source Website). A person who applied before is kept as one record.</p>
<table><tr><th>Reference</th><th>When</th><th>Name</th><th>Position</th><th>Stage</th></tr>
<?php foreach ($rows as $r): ?><tr><td><?= h($r['ref']) ?></td><td><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
<td><?= $r['removed_at'] === null ? '<a href="../recruitment/index.php?view=cand&id=' . (int)$r['candidate_id'] . '">' . h($r['name']) . '</a>' : '<span class="muted">' . h($r['name']) . ' (switched off)</span>' ?><div class="muted"><?= h(implode(' · ', array_filter([$r['cur_title'], $r['city']]))) ?></div></td>
<td><?= h($r['req_title'] ?: 'Talent pool') ?></td><td><?= h(RC_STAGES[$r['stage']] ?? '') ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="muted">No applications yet. Share the public page: <?= h($pub) ?></td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'board'): $reqs = $db->query("SELECT id, ref, title, status, careers FROM rc_requisition WHERE status IN ('open','hold') ORDER BY title")->fetchAll(); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="board"><h2>On the job board</h2>
<p class="muted">Open positions show on the public page while ticked. Pay ranges, notes and approvals are never shown there.</p>
<?php foreach ($reqs as $r): ?><label><input type="checkbox" name="on[]" value="<?= (int)$r['id'] ?>"<?= (int)$r['careers'] ? ' checked' : '' ?><?= $r['status'] !== 'open' ? ' disabled' : '' ?>> <?= h($r['ref'] . ' · ' . $r['title']) ?><?= $r['status'] !== 'open' ? ' <span class="muted">(on hold — not shown)</span>' : '' ?></label><br><?php endforeach; ?>
<?php if (!$reqs): ?><p class="muted">No open positions. Raise one in Recruitment.</p><?php endif; ?><button class="btn">Save</button></form>

<?php elseif ($view === 'settings' && $isAdmin): $rt = $set['ratings']; $rt[] = ['source' => '', 'rating' => '', 'count' => '', 'url' => '']; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Page settings</h2>
<div class="grid"><label>Heading <input name="title" value="<?= h($set['title']) ?>" maxlength="80"></label><label>Contact email <input type="email" name="contact_email" value="<?= h($set['contact_email']) ?>"></label>
<label>Contact phone <input name="contact_phone" value="<?= h($set['contact_phone']) ?>"></label><label>Minimum age <input type="number" name="min_age" min="14" max="25" value="<?= (int)$set['min_age'] ?>"></label></div>
<label>Introduction <textarea name="intro" rows="3"><?= h($set['intro']) ?></textarea></label>
<label>Consent wording (kept with every application) <textarea name="consent" rows="3" required><?= h($set['consent']) ?></textarea></label>
<label>Thank-you message <textarea name="thanks" rows="2"><?= h($set['thanks']) ?></textarea></label>
<label>Cities the CV reader recognises, besides your branches (comma separated) <textarea name="cities" rows="2"><?= h($set['cities']) ?></textarea></label>
<?php foreach (['show_jd' => 'Show the job description', 'other' => 'Let people send a CV when no opening fits', 'need_cv' => 'A CV is required', 'ask_dob' => 'Ask the date of birth (checks the minimum age)'] as $k => $l): ?>
<label><input type="checkbox" name="<?= $k ?>" value="1"<?= $set[$k] ? ' checked' : '' ?>> <?= h($l) ?></label><br><?php endforeach; ?>
<h3>Employer ratings</h3><p class="muted">Typed by you from the review site (never read from it by machine). Shown with at least one review.</p>
<label><input type="checkbox" name="show_ratings" value="1"<?= $set['show_ratings'] ? ' checked' : '' ?>> Show ratings on the page</label>
<table><tr><th>Source</th><th>Rating (1–5)</th><th>Reviews</th><th>Link (https)</th></tr><?php foreach ($rt as $r): ?><tr><td><input name="rt_source[]" value="<?= h((string)$r['source']) ?>"></td><td><input name="rt_rating[]" value="<?= h((string)$r['rating']) ?>"></td><td><input name="rt_count[]" value="<?= h((string)$r['count']) ?>"></td><td><input name="rt_url[]" value="<?= h((string)$r['url']) ?>"></td></tr><?php endforeach; ?></table>
<h3>Spam checks</h3><div class="grid"><label>Applications per hour per address <input type="number" name="sends_hour" min="1" max="100" value="<?= (int)$set['sends_hour'] ?>"></label>
<label>CV reads per hour per address <input type="number" name="reads_hour" min="1" max="200" value="<?= (int)$set['reads_hour'] ?>"></label><label>Least seconds on the form <input type="number" name="min_secs" min="0" max="30" value="<?= (int)$set['min_secs'] ?>"></label></div>
<button class="btn">Save</button></form>
<?php endif;
ui_foot();
