<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$u = module_require('audit');
$db = pdo();

$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT a.*, us.name FROM audit a LEFT JOIN users us ON us.id = a.user_id';
$args = [];
if ($q !== '') { $sql .= ' WHERE a.action LIKE ? OR a.detail LIKE ? OR us.name LIKE ?'; $args = array_fill(0, 3, '%' . $q . '%'); }
$s = $db->prepare($sql . ' ORDER BY a.id DESC LIMIT 300');
$s->execute($args);
$chain = audit_verify($db);

ui_head('Audit log', $u, 'audit');
?>
<section class="card">
  <p><?= $chain['ok'] ? '<span class="pill on">Chain intact</span>' : '<span class="pill off">Chain broken at #' . (int)$chain['broken_at'] . '</span>' ?>
     <?= (int)$chain['rows'] ?> sensitive events on the hash chain.</p>
  <form class="row"><input name="q" value="<?= h($q) ?>" placeholder="Search action, detail or person"><button class="btn sm">Search</button></form>
  <table><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th><th>Address</th></tr></thead><tbody>
  <?php foreach ($s as $r): ?><tr><td><?= h($r['at']) ?></td><td><?= h($r['name'] ?? '—') ?></td><td><?= h($r['action']) ?><?= $r['chained'] ? ' 🔗' : '' ?></td><td><?= h($r['detail']) ?></td><td><?= h($r['ip']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php ui_foot();
