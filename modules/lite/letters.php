<?php
// Lite letters: letters issued to the login's own employee code; they open on My letters (same download rules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/lite.php';
$u = module_require_self('lite');
$db = pdo(); $emp = lite_emp($db, $u);
lite_head('Letters', $u);
if (!module_on('letters') || module_person((int)$u['id'], 'letters') === 'deny' || !$emp) { echo '<p class="e">' . (!$emp ? 'Your login is not linked to an employee record yet — ask HR.' : 'Letters are not open for you.') . '</p>'; lite_foot($u); exit; }
$s = $db->prepare("SELECT l.ref, l.issued_at, t.name FROM letter l LEFT JOIN letter_template t ON t.id = l.template_id WHERE l.emp_code = ? AND l.status = 'issued' ORDER BY l.issued_at DESC LIMIT 30");
$s->execute([(string)$emp['code']]); $rows = $s->fetchAll(); ?>
<div class="c"><table><?php foreach ($rows as $r): ?><tr><td><?= h((string)($r['name'] ?? 'Letter')) ?></td><td class="m"><?= h((string)$r['ref']) ?> · <?= h(substr((string)$r['issued_at'], 0, 10)) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td class="m">No letters yet.</td></tr><?php endif; ?></table><p><a href="<?= h(wn_base()) ?>modules/letters/mine.php">Open or download on My letters</a></p></div>
<?php lite_foot($u);
