<?php
// Biometric machines — Super Admin: machines and API-key sources, how to point a machine here, machines asking to
// connect, codes machines send that match nobody, recent punches.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/biometric.php';
$u = module_require('biometric');
$db = pdo(); $uid = (int)$u['id']; $admin = is_superadmin($u);
$view = (string)($_GET['view'] ?? 'list'); $id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $pid = (int)($_POST['id'] ?? 0); $to = '?view=list';
    try {
        switch ($a) {
            case 'save': $k = bio_source_save($db, $u, $pid, $_POST); if ($k) $_SESSION['bio_key'] = $k; flash('Saved.' . ($k ? ' Copy the key below — it is shown only now.' : '')); $to = '?view=list'; break;
            case 'key': $_SESSION['bio_key'] = bio_key_rotate($db, $u, $pid); flash('New key made; the old one stopped. Copy it below — it is shown only now.'); break;
            case 'active': bio_source_active($db, $u, $pid, ($_POST['on'] ?? '') === '1', (string)($_POST['why'] ?? '')); flash('Saved.'); break;
            default: throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); $_SESSION['bio_form'] = $_POST; $to = '?view=edit&id=' . $pid . (isset($_POST['serial']) ? '&sn=' . rawurlencode((string)$_POST['serial']) : ''); }
    redirect('index.php' . $to);
}
$opts = fn(array $o, string $c = '', string $none = '') => ($none !== '' ? '<option value="">' . h($none) . '</option>' : '') . implode('', array_map(fn($k, $l) => '<option value="' . h((string)$k) . '"' . ((string)$k === $c ? ' selected' : '') . '>' . h((string)$l) . '</option>', array_keys($o), $o));
$key = $_SESSION['bio_key'] ?? null; unset($_SESSION['bio_key']);
$base = wn_site_url(); $host = (string)parse_url($base, PHP_URL_HOST);
ui_head('Biometric machines', $u, 'biometric'); ?>
<nav class="row"><a class="btn sm<?= $view === 'list' ? '' : ' ghost' ?>" href="?view=list">Machines</a><?php if ($admin): ?> <a class="btn sm<?= $view === 'edit' && !$id ? '' : ' ghost' ?>" href="?view=edit">Add a machine or source</a><?php endif; ?> <a class="btn sm<?= $view === 'punches' ? '' : ' ghost' ?>" href="?view=punches">Recent punches</a></nav>
<?php if ($key): ?><div class="card"><b>API key (shown once):</b><br><input readonly style="width:100%" value="<?= h($key) ?>" onclick="this.select()"><p class="muted">Send it as <code>Authorization: Bearer …</code> to <code><?= h($base) ?>modules/biometric/punch.php</code>. Only a hash is kept.</p></div><?php endif; ?>

<?php if ($view === 'list'): $ss = bio_sources($db); $seen = bio_seen($db); $unk = bio_unknown_codes($db); ?>
<div class="card"><table><tr><th>Machine</th><th>Make</th><th>Way in</th><th>Branch</th><th>Punches</th><th>Last punch</th><th>Last contact</th><th></th></tr>
<?php foreach ($ss as $s): ?><tr><td><?= $admin ? '<a href="?view=edit&id=' . (int)$s['id'] . '">' . h($s['name']) . '</a>' : h($s['name']) ?><?= $s['model'] !== '' ? ' <span class="muted">' . h($s['model']) . '</span>' : '' ?></td><td><?= h(BIO_MAKES[$s['make']][0] ?? $s['make']) ?></td><td><?= $s['way'] === 'adms' ? 'Machine direct · ' . h((string)$s['serial']) : 'API key …' . h($s['key_hint']) ?></td>
<td><?= h($s['branch_id'] ? branch_label((string)(array_column(branches(false), 'name', 'id')[$s['branch_id']] ?? '')) : '') ?></td><td><?= (int)$s['n'] ?></td><td><?= h((string)$s['last_punch']) ?></td><td><?= h((string)$s['last_seen']) ?><?= $s['last_ip'] !== '' ? ' <span class="muted">' . h($s['last_ip']) . '</span>' : '' ?></td><td><?= (int)$s['active'] ? '' : 'Paused — ' . h($s['off_reason']) ?></td></tr><?php endforeach; ?>
<?php if (!$ss): ?><tr><td colspan="8" class="muted">No machines yet.</td></tr><?php endif; ?></table></div>
<?php if ($seen && $admin): ?><div class="card"><h3>Machines asking to connect</h3><table><tr><th>Serial</th><th>Address</th><th>Times</th><th>Last</th><th></th></tr><?php foreach ($seen as $x): ?><tr><td><?= h($x['serial']) ?></td><td><?= h($x['ip']) ?></td><td><?= (int)$x['hits'] ?></td><td><?= h($x['last_seen']) ?></td><td><a class="btn sm" href="?view=edit&sn=<?= h(rawurlencode($x['serial'])) ?>">Add this machine</a></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<?php if ($unk): ?><div class="card"><h3>Codes from machines that match nobody this month</h3><p class="muted">Fix the person's enrolment on the machine, or add "machine ID = employee code" lines to the machine.</p><table><?php foreach ($unk as $x): ?><tr><td><?= h($x['emp_code']) ?></td><td><?= (int)$x['n'] ?> punch(es)</td><td><?= h($x['machine']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
<div class="card"><h3>Pointing a machine here</h3><p><b>Machine direct</b> (ZKTeco, eSSL, Realtime, Identix, BioMax …): in the machine's Comm / Cloud server settings, server address <code><?= h($host) ?></code>, port 80 (or 443 with HTTPS), "Domain name" on. The machine calls <code>/iclock/cdata</code>; add its serial number here first. Only attendance times are kept.</p>
<p><b>API key</b> (Matrix COSEC, Suprema BioStar, Hikvision, a script): POST to <code><?= h($base) ?>modules/biometric/punch.php</code> with <code>Authorization: Bearer &lt;key&gt;</code> and JSON <code>{"punches":[{"code":"E001","time":"2026-11-19 09:31:05"}]}</code> — time in the organisation's time zone, or ISO 8601 with an offset. Up to <?= BIO_MAX_PUNCHES ?> a request; older than <?= BIO_BACK_DAYS ?> days or more than <?= BIO_AHEAD_MIN ?> minutes ahead is refused; repeats are kept once.</p></div>

<?php elseif ($view === 'edit' && $admin): $s = $id ? bio_source_get($db, $id) : null; $f = $_SESSION['bio_form'] ?? []; unset($_SESSION['bio_form']); $x = $f + ($s ?? []) + ['serial' => (string)($_GET['sn'] ?? '')];
  $map = ''; foreach (json_decode((string)($x['code_map'] ?? ''), true) ?: [] as $k => $v) $map .= "$k = $v\n"; if (is_string($f['code_map'] ?? null)) $map = $f['code_map']; ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?= $id ?>"><h2><?= $s ? h($s['name']) : 'Add a machine or source' ?></h2>
<p class="row" style="flex-wrap:wrap"><label>Name <input name="name" required value="<?= h((string)($x['name'] ?? '')) ?>" placeholder="e.g. Main gate"></label> <label>Make <select name="make"><?= $opts(array_map(fn($m) => $m[0], BIO_MAKES), (string)($x['make'] ?? 'zkteco')) ?></select></label> <label>Model <input name="model" value="<?= h((string)($x['model'] ?? '')) ?>"></label>
<label>Way in <select name="way"><?= $opts(BIO_WAYS, (string)($x['way'] ?? 'adms')) ?></select></label> <label>Serial number (machine direct) <input name="serial" value="<?= h((string)($x['serial'] ?? '')) ?>"></label> <label>Branch <select name="branch_id"><?= $opts(array_column(branches(false), 'name', 'id'), (string)($x['branch_id'] ?? ''), '—') ?></select></label></p>
<p class="row" style="flex-wrap:wrap"><label>Code prefix <input name="code_prefix" size="8" value="<?= h((string)($x['code_prefix'] ?? '')) ?>" placeholder="added to machine IDs"></label> <label style="flex:1">Allowed addresses (blank = any) <input name="allowed_ips" style="width:100%" value="<?= h(str_replace("\n", ', ', (string)($x['allowed_ips'] ?? ''))) ?>"></label></p>
<p><label>Machine ID = employee code (one per line, for IDs that differ from the code)<br><textarea name="code_map" rows="4" style="width:100%"><?= h($map) ?></textarea></label></p><button class="btn">Save</button></form>
<?php if ($s): ?><?php if ($s['way'] === 'key'): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="key"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn sm ghost">New key (stops the old one)</button> <span class="muted">Current key ends …<?= h($s['key_hint']) ?></span></form><?php endif; ?>
<form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="active"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="on" value="<?= (int)$s['active'] ? '0' : '1' ?>"><?php if ((int)$s['active']): ?><input name="why" placeholder="Why pause (punches already in stay)" style="flex:1"><?php endif; ?><button class="btn sm ghost"><?= (int)$s['active'] ? 'Pause' : 'Start again' ?></button></form><?php endif; ?>

<?php elseif ($view === 'punches'): $rows = $db->query('SELECT p.emp_code, p.punch_at, p.device, s.name, e.name AS person, e.branch_id FROM bio_punch p JOIN bio_source s ON s.id = p.source_id LEFT JOIN employee e ON e.code = p.emp_code ORDER BY p.id DESC LIMIT 200')->fetchAll(); $rows = array_filter($rows, fn($r) => emp_in_scope($u, ['branch_id' => $r['branch_id']])); ?>
<div class="card"><table><tr><th>When</th><th>Code</th><th>Person</th><th>Machine</th></tr><?php foreach ($rows as $r): ?><tr><td><?= h($r['punch_at']) ?></td><td><?= h($r['emp_code']) ?></td><td><?= h((string)($r['person'] ?? '—')) ?></td><td><?= h($r['name']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4" class="muted">No machine punches yet.</td></tr><?php endif; ?></table></div>
<?php else: ?><div class="card">Not available.</div><?php endif; ?>
<?php ui_foot();
