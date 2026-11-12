<?php
// Public: check that a letter is genuine by its verification code. Shows type, reference, name, designation, date only.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/letters.php';
module_public('letters');
header('X-Robots-Tag: noindex');
$db = pdo();
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$code = strtoupper(trim((string)($_GET['c'] ?? '')));
$r = null; $err = '';
if ($code !== '') {
    if (ip_throttled($db, $ip)) $err = 'Too many tries. Please wait a few minutes.';
    elseif (!($r = let_verify($db, $code))) { ip_fail($db, $ip); $err = 'No letter has this code. Check the letters and digits.'; }
}
ui_head('Verify a letter');
?>
<form class="card row"><label>Verification code <input name="c" value="<?= h($code) ?>" maxlength="10" required autocomplete="off"></label><button class="btn sm">Check</button></form>
<?php if ($err !== ''): ?><section class="card"><p><?= h($err) ?></p></section><?php endif; ?>
<?php if ($r): ?><section class="card"><h2><?= $r['state'] === 'valid' ? '✓ Genuine letter' : 'This letter was withdrawn' ?></h2>
  <table><tbody><tr><td>Letter</td><td><?= h($r['type']) ?></td></tr><tr><td>Reference</td><td><?= h($r['ref']) ?></td></tr><tr><td>Issued to</td><td><?= h($r['person']) ?></td></tr>
  <?php if ($r['designation'] !== ''): ?><tr><td>Designation</td><td><?= h($r['designation']) ?></td></tr><?php endif; ?><tr><td>Issued on</td><td><?= h(date('j F Y', strtotime($r['issued']))) ?></td></tr>
  <tr><td>Issued by</td><td><?= h(org('legal_name') ?: org('name')) ?></td></tr></tbody></table></section><?php endif;
ui_foot();
