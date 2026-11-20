<?php
// Team chat — my chats, one chat (messages, send with files, group info), new direct chat / group, admin overview, settings.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/talk.php';
$u = module_require('talk');
$db = pdo();
$me = (int)$u['id'];
$set = talk_settings(); $R = talk_rights($db, $me);
$view = (string)($_GET['view'] ?? 'chat');
$cid = (int)($_GET['c'] ?? $_POST['c'] ?? 0);
$isAdmin = has_role($u, 'SUPERADMIN');
if (($_GET['people'] ?? '') === '1') {   // the Ctrl + K palette's people list: the same projection as the picker
    header('Content-Type: application/json'); header('Cache-Control: no-store, private');
    echo json_encode(['people' => talk_people($db, $me)]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $to = 'index.php?c=' . $cid;
    try {
        switch ($a) {
            case 'dm': $cid = talk_open_dm($db, $me, (int)$_POST['user']); $to = 'index.php?c=' . $cid; break;
            case 'group': $cid = talk_create_group($db, $me, $_POST); $to = 'index.php?c=' . $cid; break;
            case 'send':
                $files = [];
                foreach ((array)($_FILES['files']['tmp_name'] ?? []) as $i => $tmp) if ((int)($_FILES['files']['error'][$i] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string)$tmp)) $files[] = [(string)file_get_contents((string)$tmp), (string)$_FILES['files']['name'][$i]];
                talk_post($db, $me, $cid, (string)($_POST['text'] ?? ''), $files);
                if (!empty($_SERVER['HTTP_X_CSRF'])) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
                break;
            case 'remove': talk_remove_msg($db, $me, (int)$_POST['msg']); flash('Removed.'); break;
            case 'grp': talk_group_op($db, $me, $cid, (string)$_POST['op'], $_POST); flash('Saved.'); if ($_POST['op'] === 'leave') $to = 'index.php'; break;
            case 'delete': talk_delete_chat($db, $me, $cid, (string)($_POST['reason'] ?? '')); flash('Deleted — it is in the log.'); $to = 'index.php?view=admin'; break;
            case 'settings':
                if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = talk_settings_check($_POST, role_labels());
                if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['talk'] ?? ['roles' => module_roles('talk')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['talk', 1, implode(',', $st['roles'] ?: module_roles('talk')), json_encode($new), $me]);
                audit($me, 'module_settings', 'talk'); flash('Settings saved.'); $to = 'index.php?view=settings'; break;
        }
    } catch (Throwable $e) {
        if (!empty($_SERVER['HTTP_X_CSRF'])) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error' => $e->getMessage()]); exit; }
        flash($e->getMessage(), 'err');
    }
    redirect($to);
}

if ($view === 'file') {
    $f = talk_file_for($db, $me, (string)($_GET['f'] ?? ''));
    if (!$f) { http_response_code(404); exit('Not found'); }
    header('Content-Type: ' . $f['type']); header('Content-Disposition: ' . ((int)$f['is_image'] ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $f['name']) . '"');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox"); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    echo is_resource($f['data']) ? stream_get_contents($f['data']) : $f['data']; exit;
}

$chat = $cid ? talk_chat($db, $cid) : null;
if ($chat && !talk_member($db, $cid, $me)) $chat = null;
$render = function (array $msgs, array $c) use ($db, $me): string {
    $names = talk_names($db, array_column($msgs, 'user_id')); $rm = talk_can_remove($db, $c, $me); $o = '';
    foreach ($msgs as $m) {
        if ((int)$m['sys']) { $o .= '<p class="muted" style="text-align:center" data-id="' . (int)$m['id'] . '"><small>' . h($m['body']) . '</small></p>'; continue; }
        $mine = (int)$m['user_id'] === $me;
        $o .= '<div class="card" data-id="' . (int)$m['id'] . '" style="max-width:80%;' . ($mine ? 'margin-left:auto' : '') . '"><small class="muted">' . ($mine ? 'You' : h($names[(int)$m['user_id']] ?? 'Former colleague')) . ' · ' . h(date('j M H:i', strtotime($m['created_at']))) . '</small><br>';
        if ($m['deleted_at']) { $o .= '<i class="muted">Removed by an admin</i></div>'; continue; }
        $o .= nl2br(h($m['body']));
        foreach ($m['files'] as $f) $o .= '<br>' . ((int)$f['gone'] ? '<i class="muted">File removed</i>' : ((int)$f['is_image'] ? '<a href="?view=file&f=' . h($f['id']) . '" target="_blank"><img src="?view=file&f=' . h($f['id']) . '" alt="' . h($f['name']) . '" style="max-width:240px;max-height:240px"></a>' : '<a href="?view=file&f=' . h($f['id']) . '">' . h($f['name']) . '</a> <small class="muted">' . round($f['size'] / 1024) . ' KB</small>'));
        if (isset($m['tick'])) $o .= ' <small class="muted" title="' . h($m['tick']) . '">' . ['sent' => '✓', 'delivered' => '✓✓', 'read' => '<b style="color:#2a7ade">✓✓</b>'][$m['tick']] . '</small>';
        if ($rm) $o .= '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="a" value="remove"><input type="hidden" name="c" value="' . (int)$c['id'] . '"><input type="hidden" name="msg" value="' . (int)$m['id'] . '"><button class="btn sm ghost" onclick="return confirm(\'Remove this message for everyone?\')">Remove</button></form>';
        $o .= '</div>';
    }
    return $o;
};

if ($view === 'poll') {
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $out = ['unread' => talk_unread_total($db, $me)];
    if ($chat) { $msgs = talk_messages($db, $me, $cid, max(0, (int)($_GET['after'] ?? 0))); if (($_GET['seen'] ?? '') === '1') talk_mark_read($db, $me, $cid); $out['html'] = $render($msgs, $chat); $out['last'] = $msgs ? (int)end($msgs)['id'] : (int)($_GET['after'] ?? 0); }
    echo json_encode($out); exit;
}

$list = talk_list($db, $me);
ui_head('Team chat', $u, 'talk'); ?>
<nav class="row"><a class="btn sm<?= $view === 'chat' ? '' : ' ghost' ?>" href="index.php">Chats</a><?php if ($R['manage']): ?> <a class="btn sm<?= $view === 'admin' ? '' : ' ghost' ?>" href="?view=admin">All chats</a><?php endif; ?><?php if ($isAdmin): ?> <a class="btn sm<?= $view === 'settings' ? '' : ' ghost' ?>" href="?view=settings">Settings</a><?php endif; ?></nav>

<?php if ($view === 'chat'): ?>
<div class="grid2" style="grid-template-columns:minmax(220px,1fr) 3fr">
<aside><section class="card"><h3>Chats</h3><ul style="list-style:none;padding:0"><?php foreach ($list as $c): ?><li><a href="?c=<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $cid ? ' style="font-weight:700"' : '' ?>><?= $c['kind'] === 'group' ? '# ' : '' ?><?= h($c['title']) ?></a><?= (int)$c['unread'] ? ' <span class="pill port">' . (int)$c['unread'] . '</span>' : '' ?></li><?php endforeach; ?><?= $list ? '' : '<li class="muted">No chats yet.</li>' ?></ul></section>
  <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="dm"><h3>Message someone</h3><select name="user" required><option value="">Choose</option><?php foreach (talk_people($db, $me) as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['name'] . ($p['title'] !== '' ? ' · ' . $p['title'] : '') . ($p['dept'] !== '' ? ' · ' . $p['dept'] : '')) ?></option><?php endforeach; ?></select><button class="btn sm">Open</button></form>
  <?php if (talk_can_create($db, $me)): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="group"><h3>New group</h3><input name="name" placeholder="Group name" required maxlength="60"><input name="descr" placeholder="What it is for" maxlength="140">
    <select name="members[]" multiple size="5"><?php foreach (talk_people($db, $me) as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select><label class="tick"><input type="checkbox" name="mode" value="one"> Only admins send (announcements)</label><button class="btn sm">Create</button></form><?php endif; ?></aside>
<main><?php if (!$chat): ?><section class="card"><p class="muted">Pick a chat, or message someone.</p></section>
<?php else: $msgs = talk_messages($db, $me, $cid); talk_mark_read($db, $me, $cid); $block = talk_send_block($db, $chat, $me); $other = $chat['kind'] === 'dm' ? talk_other($db, $cid, $me) : null; ?>
  <section class="card"><h2><?= $chat['kind'] === 'group' ? '# ' . h($chat['name']) : h(talk_names($db, [$other])[$other] ?? 'Former colleague') ?></h2><?= $chat['descr'] !== '' ? '<p class="muted">' . h($chat['descr']) . '</p>' : '' ?><?= $chat['mode'] === 'one' ? '<p class="muted">Only admins send in this group.</p>' : '' ?>
    <div id="msgs" style="max-height:60vh;overflow:auto"><?= $render($msgs, $chat) ?></div>
    <?php if ($block === ''): ?><form method="post" enctype="multipart/form-data" id="sendf" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="send"><input type="hidden" name="c" value="<?= $cid ?>"><textarea name="text" rows="2" maxlength="<?= TALK_TEXT_MAX ?>" placeholder="Message" style="flex:1"></textarea>
      <?php if ($set['files']): ?><input type="file" name="files[]" multiple accept="image/*,application/pdf,.docx,.xlsx,.pptx,.txt,.csv"><?php endif; ?><button class="btn sm">Send</button></form><?php else: ?><p class="muted"><?= h($block) ?></p><?php endif; ?></section>
  <?php if ($chat['kind'] === 'group'): $mem = $db->prepare('SELECT m.user_id, m.is_admin, u.name FROM talk_member m JOIN users u ON u.id = m.user_id WHERE m.chat_id = ? AND m.left_at IS NULL ORDER BY m.is_admin DESC, u.name'); $mem->execute([$cid]); $mem = $mem->fetchAll(); $mg = talk_can_manage($db, $chat, $me); ?>
  <details class="card"><summary>Group info · <?= count($mem) ?> members</summary><ul><?php foreach ($mem as $m): ?><li><?= h($m['name']) ?><?= (int)$m['is_admin'] ? ' <span class="pill">Admin</span>' : '' ?>
    <?php if ($mg && (int)$m['user_id'] !== $me): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="a" value="grp"><input type="hidden" name="c" value="<?= $cid ?>"><input type="hidden" name="user" value="<?= (int)$m['user_id'] ?>"><button class="btn sm ghost" name="op" value="<?= (int)$m['is_admin'] ? 'unadmin' : 'admin' ?>"><?= (int)$m['is_admin'] ? 'Drop admin' : 'Make admin' ?></button><button class="btn sm ghost" name="op" value="remove">Remove</button></form><?php endif; ?></li><?php endforeach; ?></ul>
    <?php if ($mg): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="grp"><input type="hidden" name="c" value="<?= $cid ?>"><input type="hidden" name="op" value="add"><select name="user"><?php foreach (talk_people($db, $me) as $p) if (!in_array($p['id'], array_map('intval', array_column($mem, 'user_id')), true)): ?><option value="<?= $p['id'] ?>"><?= h($p['name']) ?></option><?php endif; ?></select><button class="btn sm ghost">Add</button></form>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="grp"><input type="hidden" name="c" value="<?= $cid ?>"><input type="hidden" name="op" value="info"><input name="name" value="<?= h($chat['name']) ?>"><input name="descr" value="<?= h($chat['descr']) ?>"><label class="tick"><input type="checkbox" name="mode" value="one"<?= $chat['mode'] === 'one' ? ' checked' : '' ?>> Only admins send</label><button class="btn sm ghost">Save</button></form><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="grp"><input type="hidden" name="c" value="<?= $cid ?>"><input type="hidden" name="op" value="leave"><button class="btn sm ghost">Leave the group</button></form></details><?php endif; ?>
  <?php if ($R['delete']): ?><details class="card"><summary>Delete this chat for everyone</summary><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="c" value="<?= $cid ?>"><input name="reason" placeholder="Reason (kept in the log)" required><button class="btn sm ghost" onclick="return confirm('Delete the whole chat for everyone?')">Delete</button></form></details><?php endif; ?>
<script>
(() => { const box = document.getElementById('msgs'), f = document.getElementById('sendf'); let last = <?= $msgs ? (int)end($msgs)['id'] : 0 ?>; box.scrollTop = box.scrollHeight;
  const poll = async () => { if (document.hidden) return; try { const r = await fetch('?view=poll&c=<?= $cid ?>&seen=1&after=' + last, {credentials: 'same-origin'}); const j = await r.json(); if (j.html) { const near = box.scrollHeight - box.scrollTop - box.clientHeight < 80; box.insertAdjacentHTML('beforeend', j.html); if (near) box.scrollTop = box.scrollHeight; } last = j.last || last; } catch (e) {} };
  setInterval(poll, 4000); document.addEventListener('visibilitychange', poll);
  if (f) f.addEventListener('submit', async e => { if (f.querySelector('[type=file]')?.files.length) return; e.preventDefault(); const d = new FormData(f);
    const r = await fetch('index.php', {method: 'POST', body: d, headers: {'X-CSRF': d.get('csrf')}, credentials: 'same-origin'}); if (r.ok) { f.text.value = ''; poll(); } else { const j = await r.json().catch(() => ({})); alert(j.error || 'Not sent'); } });
  f?.text.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); f.requestSubmit(); } }); })();
</script>
<?php endif; ?></main></div>

<?php elseif ($view === 'admin' && $R['manage']): ?>
<section class="card"><h2>All chats</h2><p class="muted">Names, members and counts only — nobody reads a chat they are not in.</p><table><thead><tr><th>Chat</th><th>Members</th><th>Messages</th><th>Last</th><th></th></tr></thead><tbody>
<?php foreach (talk_overview($db) as $c): $nm = $c['kind'] === 'group' ? '# ' . $c['name'] : implode(' ↔ ', talk_names($db, $db->query('SELECT user_id FROM talk_member WHERE chat_id = ' . (int)$c['id'])->fetchAll(PDO::FETCH_COLUMN))); ?>
  <tr><td><?= h($nm) ?></td><td><?= (int)$c['members'] ?></td><td><?= (int)$c['msgs'] ?></td><td><?= $c['last_at'] ? h(date('j M Y', strtotime($c['last_at']))) : '' ?></td>
  <td><?php if ($R['delete']): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="delete"><input type="hidden" name="c" value="<?= (int)$c['id'] ?>"><input name="reason" placeholder="Reason" required size="12"><button class="btn sm ghost" onclick="return confirm('Delete for everyone?')">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php if ($R['delete']): $log = $db->query('SELECT l.*, u.name AS who FROM talk_delete_log l LEFT JOIN users u ON u.id = l.deleted_by ORDER BY l.id DESC LIMIT 100')->fetchAll(); ?>
<section class="card"><h2>Deleted chats log</h2><table><tbody><?php foreach ($log as $l): ?><tr><td><?= h(date('j M Y H:i', strtotime($l['deleted_at']))) ?></td><td><?= h($l['name']) ?> · <?= (int)$l['msg_count'] ?> messages · <?= (int)$l['file_count'] ?> files</td><td><?= h($l['reason']) ?></td><td><?= h((string)$l['who']) ?></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
  <p>Direct messages: <?php foreach (['two' => 'anyone with anyone', 'lead' => 'employees with leads only', 'oneway' => 'leads start, employees reply'] as $k => $l): ?><label class="tick"><input type="radio" name="dm" value="<?= $k ?>"<?= $set['dm'] === $k ? ' checked' : '' ?>> <?= $l ?></label> <?php endforeach; ?></p>
  <p>Who creates groups: <?php foreach (['all' => 'everyone', 'lead' => 'leads', 'manage' => 'manage roles'] as $k => $l): ?><label class="tick"><input type="radio" name="create" value="<?= $k ?>"<?= $set['create'] === $k ? ' checked' : '' ?>> <?= $l ?></label> <?php endforeach; ?></p>
  <p><label class="tick"><input type="checkbox" name="files" value="1"<?= $set['files'] ? ' checked' : '' ?>> Files and photos</label> <label class="tick"><input type="checkbox" name="receipts" value="1"<?= $set['receipts'] ? ' checked' : '' ?>> Read receipts</label></p>
  <?php foreach (['lead_roles' => 'Leads (HR & managers)', 'manage_roles' => 'Manage (all groups, remove messages)', 'delete_roles' => 'Delete whole chats'] as $k => $lab): ?><p><?= h($lab) ?>: <?php foreach (role_labels() as $rc => $rl): ?><label class="tick"><input type="checkbox" name="<?= $k ?>[]" value="<?= h($rc) ?>"<?= in_array($rc, $set[$k], true) ? ' checked' : '' ?>> <?= h($rl) ?></label> <?php endforeach; ?></p><?php endforeach; ?>
  <button class="btn sm">Save</button></form>
<?php endif;
ui_foot();
