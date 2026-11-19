<?php
// Induction academy — HR side: levels (reading, video link, department, pass mark), their quiz questions, and each
// person's progress. The learner's own page is mine.php.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/induction.php';
$u = module_require('induction');
$db = pdo(); $S = ind_settings(); $can = ind_can($u); $uid = (int)$u['id'];
$view = (string)($_GET['view'] ?? 'levels'); $id = (int)($_GET['id'] ?? 0);
if (!$can && $view !== 'settings') { redirect('mine.php'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $pid = (int)($_POST['id'] ?? 0); $to = '?view=level&id=' . $pid;
    try {
        switch ($a) {
            case 'level': $pid = ind_level_save($db, $u, $pid, $_POST); flash('Level saved.'); $to = '?view=level&id=' . $pid; break;
            case 'active': ind_level_active($db, $u, $pid, ($_POST['on'] ?? '') === '1', (string)($_POST['why'] ?? '')); flash('Saved.'); break;
            case 'q': ind_question_add($db, $u, $pid, $_POST); flash('Question added.'); break;
            case 'qoff': ind_question_off($db, $u, (int)$_POST['qid']); flash('Question switched off.'); break;
            case 'settings':
                if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = ind_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['induction'] ?? ['roles' => module_roles('induction')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['induction', 1, implode(',', $st['roles'] ?: module_roles('induction')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'induction'); flash('Settings saved.'); $to = '?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'level' && !$pid) { $_SESSION['ind_form'] = $_POST; $to = '?view=level'; } if ($a === 'settings') $to = '?view=settings'; if ($a === 'q') $_SESSION['ind_q'] = $_POST; }
    redirect('index.php' . $to);
}
$tab = fn(string $v, string $l) => ' <a class="btn sm' . ($view === $v ? '' : ' ghost') . '" href="?view=' . $v . '">' . h($l) . '</a>';
ui_head('Induction academy', $u, 'induction'); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('levels', 'Levels') . $tab('level', 'Add a level') . $tab('progress', 'Progress') ?> <a class="btn sm ghost" href="mine.php">My academy</a><?php if (is_superadmin($u)): ?><?= $tab('settings', 'Settings') ?><?php endif; ?></nav>

<?php if ($view === 'levels'): $ls = ind_levels($db, true); ?>
<div class="card"><table><tr><th>Order</th><th>Level</th><th>For</th><th>Questions</th><th>Pass mark</th><th></th></tr>
<?php foreach ($ls as $l): ?><tr><td><?= (int)$l['sort'] ?></td><td><a href="?view=level&id=<?= (int)$l['id'] ?>"><?= h($l['title']) ?></a><?= $l['summary'] !== '' ? '<br><span class="muted">' . h($l['summary']) . '</span>' : '' ?></td><td><?= h($l['department'] ?: 'Everyone') ?></td><td><?= (int)$l['nq'] ?: '<span class="muted">none — finished by reading</span>' ?></td><td><?= (int)($l['pass_pct'] ?? $S['pass_pct']) ?>%</td><td><?= (int)$l['active'] ? '' : 'Off — ' . h($l['off_reason']) ?></td></tr><?php endforeach; ?>
<?php if (!$ls): ?><tr><td colspan="6" class="muted">No levels yet — add the first one.</td></tr><?php endif; ?></table><p class="muted">People take the levels for everyone and for their own department, in this order; a level opens once the earlier ones are passed.</p></div>

<?php elseif ($view === 'level'): $l = $id ? ind_level_get($db, $id) : null; $x = $l ?: ($_SESSION['ind_form'] ?? []); unset($_SESSION['ind_form']); $fq = $_SESSION['ind_q'] ?? []; unset($_SESSION['ind_q']); $v = fn($k) => h((string)($x[$k] ?? '')); ?>
<?php if ($id && !$l): ?><div class="card">Not found.</div><?php else: ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="level"><input type="hidden" name="id" value="<?= $id ?>"><h2><?= $l ? h($l['title']) : 'Add a level' ?></h2>
<p class="row" style="flex-wrap:wrap"><label>Title <input name="title" required value="<?= $v('title') ?>"></label> <label>Order <input name="sort" size="4" value="<?= $v('sort') ?: '0' ?>"></label> <label>Only for department <input name="department" value="<?= $v('department') ?>" placeholder="blank = everyone"></label> <label>Pass mark % <input name="pass_pct" size="4" value="<?= $v('pass_pct') ?>" placeholder="<?= (int)$S['pass_pct'] ?>"></label></p>
<p><label>Summary <input name="summary" style="width:100%" value="<?= $v('summary') ?>"></label></p><p><label>Video link (https) <input name="video_url" style="width:100%" value="<?= $v('video_url') ?>"></label></p>
<p><label>What to read (plain text; a blank line starts a new paragraph)<br><textarea name="body" rows="12" style="width:100%"><?= $v('body') ?></textarea></label></p><button class="btn">Save</button></form>
<?php if ($l): $qs = ind_questions($db, $id); ?>
<form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="active"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="on" value="<?= (int)$l['active'] ? '0' : '1' ?>"><?php if ((int)$l['active']): ?><input name="why" placeholder="Why switch off" style="flex:1"><?php endif; ?><button class="btn sm ghost"><?= (int)$l['active'] ? 'Switch off' : 'Switch on again' ?></button></form>
<div class="card"><h3>Quiz · <?= count($qs) ?> question(s)</h3><?php if (!$qs): ?><p class="muted">No questions: people finish this level by reading it.</p><?php endif; ?>
<?php foreach ($qs as $i => $q): $o = json_decode((string)$q['opts'], true) ?: []; ?><div style="border-top:1px solid #ddd;padding:6px 0"><b><?= $i + 1 ?>. <?= h($q['q']) ?></b><ol type="A"><?php foreach ($o as $k => $t): ?><li><?= h($t) ?><?= $k === (int)$q['correct'] ? ' ✓' : '' ?></li><?php endforeach; ?></ol>
<form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="qoff"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="qid" value="<?= (int)$q['id'] ?>"><button class="btn sm ghost">Switch off</button></form></div><?php endforeach; ?></div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="q"><input type="hidden" name="id" value="<?= $id ?>"><h3>Add a question</h3><p><input name="q" style="width:100%" placeholder="Question" value="<?= h((string)($fq['q'] ?? '')) ?>"></p>
<?php for ($i = 0; $i < IND_OPTS_MAX; $i++): ?><p class="row"><label><input type="radio" name="correct" value="<?= $i ?>"<?= (string)($fq['correct'] ?? '') === (string)$i ? ' checked' : '' ?>> right</label> <input name="opt[<?= $i ?>]" style="flex:1" placeholder="Option <?= chr(65 + $i) ?>" value="<?= h((string)($fq['opt'][$i] ?? '')) ?>"></p><?php endfor; ?>
<p class="muted">A question people have answered is never changed — switch it off and add a new one.</p><button class="btn sm">Add</button></form>
<?php endif; endif; ?>

<?php elseif ($view === 'progress'): $pr = ind_progress($db, $u); ?>
<div class="card"><table><tr><th>Person</th><th>Department</th><th>Levels passed</th><th>Last activity</th></tr>
<?php foreach ($pr as $p): ?><tr><td><?= h($p['name']) ?> <span class="muted"><?= h($p['code']) ?></span></td><td><?= h($p['department']) ?></td><td><?= $p['of'] ? $p['done'] . ' of ' . $p['of'] . ($p['done'] === $p['of'] ? ' · done' : '') : '<span class="muted">no levels for them</span>' ?></td><td><?= h($p['last']) ?></td></tr><?php endforeach; ?>
<?php if (!$pr): ?><tr><td colspan="4" class="muted">Nobody on the roll in your access.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'settings' && is_superadmin($u)): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Induction academy settings</h2><p><b>Keep the academy</b><br><?php foreach (role_labels() as $c => $n): ?><label><input type="checkbox" name="manage_roles[]" value="<?= h($c) ?>"<?= in_array($c, $S['manage_roles'], true) ? ' checked' : '' ?>> <?= h($n) ?></label> <?php endforeach; ?></p>
<p class="row"><label>Pass mark % <input name="pass_pct" size="4" value="<?= (int)$S['pass_pct'] ?>"></label> <label>Wait before a retry (minutes) <input name="retry_minutes" size="5" value="<?= (int)$S['retry_minutes'] ?>"></label></p><button class="btn">Save</button></form>
<?php endif; ?>
<?php ui_foot();
