<?php
// Tasks — my tasks, tasks I gave (and to approve), my team, notices, add / change, calendar file, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/tasks.php';
$u = module_require('tasks');
$db = pdo();
$set = tk_settings();
$view = (string)($_GET['view'] ?? 'mine');
$me = (int)$u['id'];
$today = date('Y-m-d');
$isAdmin = has_role($u, 'SUPERADMIN');
try { tk_remind_tick($db); } catch (Throwable $e) { error_log('task remind: ' . $e->getMessage()); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $to = 'index.php?view=' . urlencode((string)($_POST['view'] ?? $view)) . ($id ? '#t' . $id : '');
    try {
        switch ($a) {
            case 'add': $ids = tk_add($db, $u, $_POST); flash(count($ids) > 1 ? count($ids) . ' tasks given.' : 'Added.'); break;
            case 'edit': tk_edit($db, $u, $id, $_POST); flash('Saved.'); break;
            case 'done': tk_done($db, $u, $id, true, (string)($_POST['reply'] ?? '')); $t = tk_get($db, $id); flash($t && $t['review'] === 'waiting' ? 'Sent for approval.' : 'Done.'); break;
            case 'undo': tk_done($db, $u, $id, false); flash('Opened again.'); break;
            case 'review': tk_review($db, $u, $id, (string)$_POST['act'], (string)($_POST['note'] ?? '')); flash(TK_REVIEW[$_POST['act']] ?? 'Saved.'); break;
            case 'decline': tk_decline($db, $u, $id, (string)($_POST['note'] ?? '')); flash('Declined — it went back to the person who gave it.'); break;
            case 'remove': tk_remove($db, $u, $id); flash('Removed.'); break;
            case 'seen': $db->prepare('UPDATE task_notice SET seen_at = NOW() WHERE user_id = ? AND seen_at IS NULL')->execute([$me]); break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = tk_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['tasks'] ?? ['roles' => module_roles('tasks')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['tasks', 1, implode(',', $st['roles'] ?: module_roles('tasks')), json_encode($new), $me]);
                audit($me, 'module_settings', 'tasks'); flash('Settings saved.'); break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}

if ($view === 'ics') {
    $t = tk_get($db, (int)($_GET['id'] ?? 0));
    if (!$t || !$t['due_date'] || !tk_can_see($db, $u, $t, $set)) { http_response_code(404); exit('Not found'); }
    header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="task-' . (int)$t['id'] . '.ics"');
    echo tk_ics($t, org('timezone') ?: 'UTC', preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))); exit;
}

$L = tk_lists($db, $u);
$names = tk_names($db, array_merge(...array_map(fn($x) => array_merge(array_column($x, 'owner_id'), array_column($x, 'assignee_id')), array_values($L))));
$notes = $db->prepare('SELECT * FROM task_notice WHERE user_id = ? AND seen_at IS NULL ORDER BY id DESC LIMIT 20'); $notes->execute([$me]); $notes = $notes->fetchAll();
$people = tk_people($db, $u, $set);
$sel = fn(array $o, string $n, string $c) => '<select name="' . $n . '">' . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h($l) . '</option>', array_keys($o), $o)) . '</select>';
$fields = function (?array $t) use ($sel): string {
    $t ??= ['title' => '', 'notes' => '', 'label' => '', 'prio' => 2, 'due_date' => '', 'due_time' => '', 'remind' => 'auto', 'rep' => '', 'nag' => ''];
    return '<div class="grid"><input name="title" placeholder="What needs doing" value="' . h($t['title']) . '" required maxlength="200">' . $sel(TK_PRIOS, 'prio', (string)$t['prio'])
         . '<input type="date" name="due_date" value="' . h((string)$t['due_date']) . '"><input type="time" name="due_time" value="' . h(substr((string)$t['due_time'], 0, 5)) . '">'
         . $sel(['auto' => 'Remind: suggested'] + TK_REMINDS, 'remind', (string)$t['remind']) . $sel(TK_NAGS, 'nag', (string)$t['nag']) . $sel(TK_REPEATS, 'rep', (string)$t['rep'])
         . '<input name="label" placeholder="List (Work, Personal …)" value="' . h($t['label']) . '"></div><input name="notes" placeholder="Notes" value="' . h((string)$t['notes']) . '">';
};
$row = function (array $t, string $where) use ($me, $names, $today, $fields, $view): void {
    $st = tk_state($t, $today); $own = (int)$t['owner_id'] === $me; $for = (int)$t['assignee_id'] === $me; ?>
  <tr id="t<?= (int)$t['id'] ?>" class="<?= $st === 'done' ? 'muted' : '' ?>"><td><?= (int)$t['prio'] === 1 ? '<span class="pill off">High</span> ' : '' ?><b><?= h($t['title']) ?></b>
    <?= $t['label'] !== '' ? ' <span class="pill">' . h($t['label']) . '</span>' : '' ?><?= $t['rep'] !== '' ? ' <small class="muted">' . h(TK_REPEATS[$t['rep']]) . '</small>' : '' ?>
    <br><small class="muted"><?= !$for ? 'For ' . h($names[(int)$t['assignee_id']] ?? '') : (!$own ? 'From ' . h($names[(int)$t['owner_id']] ?? '') : '') ?><?= $t['notes'] ? ' · ' . h(mb_substr((string)$t['notes'], 0, 120)) : '' ?><?= $t['reply'] !== '' ? ' · Reply: ' . h($t['reply']) : '' ?><?= in_array($t['review'], ['redo', 'correct'], true) ? ' · ' . h(TK_REVIEW[$t['review']] . ': ' . $t['review_note']) : '' ?></small></td>
    <td><?= $t['due_date'] ? h(date('j M', strtotime($t['due_date']))) . ($t['due_time'] ? ' ' . h(substr($t['due_time'], 0, 5)) : '') : '' ?><?= ['late' => ' <span class="pill off">Late</span>', 'today' => ' <span class="pill port">Today</span>', 'waiting' => ' <span class="pill port">With giver</span>', 'done' => ' <span class="pill on">Done</span>'][$st] ?? '' ?></td>
    <td><?php if ($where !== 'team'): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="view" value="<?= h($view) ?>">
      <?php if ($st !== 'done' && $st !== 'waiting' && ($for || $own)): ?><?php if ($for && !$own): ?><input name="reply" placeholder="Reply (optional)" size="14"><?php endif; ?><button class="btn sm" name="a" value="done"><?= $for && !$own ? 'Done — send for approval' : 'Done' ?></button><?php endif; ?>
      <?php if ($st === 'waiting' && $own): ?><input type="hidden" name="a" value="review"><input name="note" placeholder="Note (needed to send back)" size="16"><?php foreach (TK_REVIEW as $k => $l): ?><button class="btn sm<?= $k === 'approve' ? '' : ' ghost' ?>" name="act" value="<?= $k ?>"><?= $k === 'approve' ? 'Approve' : ($k === 'redo' ? 'Redo' : 'Correction') ?></button><?php endforeach; ?><?php endif; ?>
      <?php if (($st === 'done' && ($own && $for)) || ($st === 'waiting' && $for && !$own)): ?><button class="btn sm ghost" name="a" value="undo"><?= $st === 'waiting' ? 'Take back' : 'Open again' ?></button><?php endif; ?>
      <?php if ($for && !$own && (int)$t['peer'] && !in_array($st, ['done', 'waiting'], true)): ?><input name="note" placeholder="Why decline" size="12"><button class="btn sm ghost" name="a" value="decline">Decline</button><?php endif; ?>
      <?php if ($own && $st !== 'done'): ?><button class="btn sm ghost" name="a" value="remove" onclick="return confirm('Remove this task?')">Remove</button><?php endif; ?>
      <?= $t['due_date'] ? '<a href="?view=ics&id=' . (int)$t['id'] . '">Calendar</a>' : '' ?></form>
      <?php if ($own && $st !== 'done'): ?><details><summary>Change</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="edit"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="view" value="<?= h($view) ?>"><?= $fields($t) ?><button class="btn sm">Save</button></form></details><?php endif; endif; ?></td></tr>
<?php };

ui_head('Tasks', $u, 'tasks');
$tabs = ['mine' => 'My tasks', 'given' => 'Given by me' . ($L['approve'] ? ' (' . count($L['approve']) . ' to approve)' : '')] + ($L['team'] ? ['team' => 'My team'] : []) + ($isAdmin ? ['settings' => 'Settings'] : []); ?>
<nav class="row"><?php foreach ($tabs as $k => $l): ?><a class="btn sm<?= $view === $k ? '' : ' ghost' ?>" href="?view=<?= $k ?>"><?= h($l) ?></a><?php endforeach; ?></nav>
<?php if ($notes): ?><section class="card"><b>New for you</b><ul><?php foreach ($notes as $n): ?><li><a href="#t<?= (int)$n['task_id'] ?>"><?= h($n['title']) ?></a> <small class="muted"><?= h(date('j M H:i', strtotime($n['at']))) ?></small></li><?php endforeach; ?></ul>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="seen"><input type="hidden" name="view" value="<?= h($view) ?>"><button class="btn sm ghost">Mark all seen</button></form></section><?php endif; ?>

<?php if ($view === 'mine' || $view === 'given'): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="add"><input type="hidden" name="view" value="<?= h($view) ?>"><h2>Add a task</h2><?= $fields(null) ?>
  <?php if ($people): ?><label>For <select name="to[]" multiple size="4"><option value="<?= $me ?>" selected>Myself</option><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></label><p class="muted">Pick several people to give each their own copy. A task you give comes back to you for approval when it is done.</p><?php endif; ?>
  <button class="btn sm">Add</button></form>
<?php $list = $view === 'mine' ? $L['mine'] : $L['given']; ?>
<section class="card"><h2><?= $view === 'mine' ? 'My tasks' : 'Given by me' ?></h2><table><tbody><?php foreach ($list as $t) $row($t, $view); ?><?= $list ? '' : '<tr><td class="muted">Nothing here.</td></tr>' ?></tbody></table></section>

<?php elseif ($view === 'team' && $L['team']): $by = []; foreach ($L['team'] as $t) $by[(int)$t['assignee_id']][] = $t; ?>
<?php foreach ($by as $pid => $ts): $open = array_filter($ts, fn($t) => !$t['done_at']); $late = array_filter($open, fn($t) => tk_state($t, $today) === 'late'); ?>
<section class="card"><h3><?= h($names[$pid] ?? '') ?> <span class="pill"><?= count($open) ?> open</span><?= $late ? ' <span class="pill off">' . count($late) . ' late</span>' : '' ?></h3><table><tbody><?php foreach ($ts as $t) $row($t, 'team'); ?></tbody></table></section>
<?php endforeach; ?><p class="muted">Read only, except tasks you gave.</p>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><input type="hidden" name="view" value="settings"><h2>Settings</h2>
  <p>Tasks can be given to: <label class="tick"><input type="radio" name="give" value="all"<?= $set['give'] === 'all' ? ' checked' : '' ?>> anyone</label> <label class="tick"><input type="radio" name="give" value="team"<?= $set['give'] === 'team' ? ' checked' : '' ?>> people below on the reporting lines</label></p>
  <p>See everyone's tasks: <?php foreach (role_labels() as $rc => $rl): ?><label class="tick"><input type="checkbox" name="lead_roles[]" value="<?= h($rc) ?>"<?= in_array($rc, $set['lead_roles'], true) ? ' checked' : '' ?>> <?= h($rl) ?></label> <?php endforeach; ?></p>
  <div class="grid"><label>Morning reminder hour <input type="number" name="morning" min="5" max="12" value="<?= (int)$set['morning'] ?>"></label><label>No hourly reminders after <input type="number" name="quiet_from" min="17" max="23" value="<?= (int)$set['quiet_from'] ?>"></label></div>
  <p>Working days: <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $d): ?><label class="tick"><input type="checkbox" name="workdays[]" value="<?= $i ?>"<?= in_array($i, $set['workdays'], true) ? ' checked' : '' ?>> <?= $d ?></label> <?php endforeach; ?></p>
  <p><label class="tick"><input type="checkbox" name="email" value="1"<?= $set['email'] ? ' checked' : '' ?>> Also email reminders (when Email is on)</label></p>
  <p class="muted">People see only their own tasks and the ones they gave; managers see the tasks of the people below them; the roles above see everyone's. Reminders also go out from <code>php tools/task_remind.php</code> run every minute.</p>
  <button class="btn sm">Save</button></form>
<?php endif;
ui_foot();
