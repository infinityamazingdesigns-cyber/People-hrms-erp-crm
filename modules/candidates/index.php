<?php
// Candidate access — HR makes a private link for an applicant (no password), shares it (copy / email / WhatsApp from
// their own device), sees what the applicant sent (interview answers, details, CVs), ends the access.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/candidates.php';
$u = module_require('candidates');
$db = pdo(); $S = cand_settings(); $can = cand_can($u); $uid = (int)$u['id'];
$view = (string)($_GET['view'] ?? 'list'); $id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $pid = (int)($_POST['id'] ?? 0); $to = '?view=one&id=' . $pid;
    try {
        switch ($a) {
            case 'create': [$pid, $tok] = cand_create($db, $u, (int)($_POST['app'] ?? 0), (string)($_POST['email'] ?? ''), (string)($_POST['message'] ?? '')); $_SESSION['cand_tok'] = [$pid, $tok]; flash('Access made — share the link below. It is shown only now.'); $to = '?view=one&id=' . $pid; break;
            case 'link': $_SESSION['cand_tok'] = [$pid, cand_new_link($db, $u, $pid)]; flash('New link made; the old one stopped.'); break;
            case 'message': cand_message($db, $u, $pid, (string)($_POST['message'] ?? '')); flash('Message saved.'); break;
            case 'use': $f = cand_use_details($db, $u, $pid); flash($f ? 'Filled: ' . implode(', ', array_map(fn($k) => CAND_FIELDS[$k][0], $f)) . '.' : 'Nothing new to fill — the record already has those details.'); break;
            case 'end': cand_end($db, $u, $pid, (string)($_POST['reason'] ?? '')); flash('Access ended.'); break;
            case 'settings':
                if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin changes these.');
                [$new, $err] = cand_settings_check($_POST, role_labels()); if ($err) throw new RuntimeException(implode(' ', $err));
                $st = module_states()['candidates'] ?? ['roles' => module_roles('candidates')];
                $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
                   ->execute(['candidates', 1, implode(',', $st['roles'] ?: module_roles('candidates')), json_encode($new), $uid]);
                audit($uid, 'module_settings', 'candidates'); flash('Settings saved.'); $to = '?view=settings'; break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); if ($a === 'create') $to = '?view=new'; if ($a === 'settings') $to = '?view=settings'; }
    redirect('index.php' . $to);
}
$dt = fn($v) => $v ? date('j M Y, H:i', strtotime((string)$v)) : '';
$tab = fn(string $v, string $l) => ' <a class="btn sm' . ($view === $v ? '' : ' ghost') . '" href="?view=' . $v . '">' . h($l) . '</a>';
ui_head('Candidate access', $u, 'candidates'); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('list', 'Live access') . $tab('all', 'All, with ended') ?><?php if ($can): ?><?= $tab('new', 'Give access') ?><?php endif; ?><?php if (is_superadmin($u)): ?><?= $tab('settings', 'Settings') ?><?php endif; ?></nav>
<?php if (in_array($view, ['list', 'all'], true)): $xs = cand_list($db, $view === 'all'); ?>
<div class="card"><table><tr><th>Candidate</th><th>Position</th><th>Stage</th><th>Email</th><th>Link till</th><th>Last opened</th><th></th></tr>
<?php foreach ($xs as $x): ?><tr><td><a href="?view=one&id=<?= (int)$x['id'] ?>"><?= h($x['name']) ?></a></td><td><?= h((string)($x['req_title'] ?: 'Talent pool')) ?></td><td><?= h(RC_STAGES[$x['stage']] ?? $x['stage']) ?></td><td><?= h($x['email']) ?></td><td><?= h((string)$x['link_until']) ?></td><td><?= h($dt($x['last_seen'])) ?></td><td><?= $x['ended_at'] ? 'Ended — ' . h($x['end_reason']) : '' ?></td></tr><?php endforeach; ?>
<?php if (!$xs): ?><tr><td colspan="7" class="muted">No candidate access yet.</td></tr><?php endif; ?></table></div>

<?php elseif ($view === 'new' && $can): $apps = cand_open_apps($db); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="create"><h2>Give a candidate access</h2>
<p><label>Application <select name="app" required><option value="">Pick…</option><?php foreach ($apps as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name'] . ' · ' . ($p['req_title'] ?: 'Talent pool') . ' · ' . (RC_STAGES[$p['stage']] ?? $p['stage']) . ($p['email'] !== '' ? ' · ' . $p['email'] : '')) ?></option><?php endforeach; ?></select></label></p>
<p><label>Candidate's email (blank = the one on the record) <input type="email" name="email"></label></p><p><label>Message on their page <input name="message" style="width:100%" maxlength="600"></label></p>
<p class="muted">No password: the candidate opens a private link that works for <?= (int)$S['link_days'] ?> days and only while the application is open. You share the link yourself.</p><button class="btn">Make the link</button></form>

<?php elseif ($view === 'one'): $x = cand_get($db, $id); if (!$x): ?><div class="card">Not found.</div><?php else:
    $tok = ($_SESSION['cand_tok'][0] ?? 0) === (int)$x['id'] ? $_SESSION['cand_tok'][1] : null; unset($_SESSION['cand_tok']);
    $url = $tok ? wn_site_url() . 'modules/candidates/portal.php?t=' . $tok : ''; $ivs = cand_ivs($db, $x); $ds = cand_details($db, (int)$x['id']);
    $cvs = $db->prepare('SELECT id, name, created_at FROM rc_file WHERE candidate_id = ? AND uploaded_by = 0 AND size > 0 ORDER BY created_at DESC'); $cvs->execute([(int)$x['candidate_id']]); $cvs = $cvs->fetchAll(); ?>
<div class="card"><h2><?= h($x['name']) ?> · <?= h((string)($x['req_title'] ?: 'Talent pool')) ?></h2>
<p><?= h(RC_STAGES[$x['stage']] ?? $x['stage']) ?> · they see "<?= h(cand_stage((string)$x['stage'])[0]) ?>" · <?= h($x['email']) ?> · link till <?= h((string)$x['link_until']) ?><?= $x['last_seen'] ? ' · last opened ' . h($dt($x['last_seen'])) : '' ?></p>
<?php if ($x['ended_at']): ?><p class="muted">Ended <?= h($dt($x['ended_at'])) ?> — <?= h($x['end_reason']) ?></p><?php endif; ?>
<?php if ($url): ?><p><b>Link (shown once):</b><br><input readonly style="width:100%" value="<?= h($url) ?>" onclick="this.select()"></p>
<p class="row"><a class="btn sm ghost" href="mailto:<?= h($x['email']) ?>?subject=<?= rawurlencode('Your application with ' . org('name')) ?>&body=<?= rawurlencode("Dear {$x['name']},\n\nYou can follow your application here:\n$url\n\nPlease keep this link private.") ?>">Email from my mail app</a>
<a class="btn sm ghost" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode("Your application with " . org('name') . ": $url") ?>">WhatsApp</a></p><?php endif; ?></div>
<?php if ($can && !$x['ended_at']): ?><form method="post" class="card row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="a" value="message"><input name="message" style="flex:1" value="<?= h($x['hr_message']) ?>" placeholder="Message on their page"><button class="btn sm ghost">Save message</button></form>
<form method="post" class="card row" style="flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn sm ghost" name="a" value="link">New link (stops the old one)</button></form>
<form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="a" value="end"><input name="reason" placeholder="Why end the access" style="flex:1"><button class="btn sm ghost">End access</button></form><?php endif; ?>
<div class="card"><h3>Interviews</h3><?php if (!$ivs): ?><p class="muted">None scheduled.</p><?php endif; ?><?php foreach ($ivs as $i): ?><p>Round <?= (int)$i['round'] ?> · <?= h($dt($i['at'])) ?> · <?= h(RC_MODES[$i['mode']] ?? $i['mode']) ?> · <?= h($i['status']) ?> · <b><?= h(['attend' => 'Will attend', 'resched' => 'Asked to reschedule', '' => 'No answer yet'][$i['answer']]) ?></b></p><?php endforeach; ?>
<?php $na = $db->prepare("SELECT note, at FROM cand_iv_answer WHERE access_id = ? AND answer = 'resched' ORDER BY id DESC LIMIT 3"); $na->execute([(int)$x['id']]); foreach ($na->fetchAll() as $r): ?><p class="muted">Reschedule note <?= h($dt($r['at'])) ?>: <?= h($r['note']) ?></p><?php endforeach; ?></div>
<div class="card"><h3>Details sent</h3><?php if (!$ds): ?><p class="muted">Nothing sent yet.</p><?php else: $v = json_decode((string)$ds[0]['data'], true) ?: []; ?>
<p class="muted">Sent <?= h($dt($ds[0]['at'])) ?><?= $ds[0]['used_at'] ? ' · used ' . h($dt($ds[0]['used_at'])) : '' ?> · <?= count($ds) ?> version(s)</p><table><?php foreach (CAND_FIELDS as $k => [$l]): if (($v[$k] ?? '') === '') continue; ?><tr><td><?= h($l) ?></td><td><?= h((string)$v[$k]) ?></td></tr><?php endforeach; ?></table>
<?php if ($can && rc_rights($u)['manage']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="a" value="use"><button class="btn sm">Fill the blanks of the candidate record</button></form><?php endif; ?><?php endif; ?></div>
<div class="card"><h3>CVs sent by the candidate</h3><?php if (!$cvs): ?><p class="muted">None.</p><?php endif; ?><?php foreach ($cvs as $c): ?><p><?= h($c['name']) ?> · <?= h($dt($c['created_at'])) ?> <span class="muted">(open from the candidate's page in Recruitment)</span></p><?php endforeach; ?></div>
<div class="card"><h3>History</h3><table><?php foreach (cand_history($db, (int)$x['id']) as $l): ?><tr><td><?= h($dt($l['at'])) ?></td><td><?= h((string)($l['by_name'] ?? 'Candidate')) ?></td><td><?= h($l['what']) ?></td><td><?= h($l['detail']) ?></td></tr><?php endforeach; ?></table></div>
<?php endif; ?>
<?php elseif ($view === 'settings' && is_superadmin($u)): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Candidate access settings</h2><p><b>Who gives access</b><br><?php foreach (role_labels() as $c => $n): ?><label><input type="checkbox" name="manage_roles[]" value="<?= h($c) ?>"<?= in_array($c, $S['manage_roles'], true) ? ' checked' : '' ?>> <?= h($n) ?></label> <?php endforeach; ?></p>
<p><label>A link works for this many days <input name="link_days" size="4" value="<?= (int)$S['link_days'] ?>"></label></p><button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif; ?>
<?php ui_foot();
