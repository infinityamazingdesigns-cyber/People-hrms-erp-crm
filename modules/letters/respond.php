<?php
// Public: the person opens their letter from the private link and accepts / declines / acknowledges with their name.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/letters.php';
module_public('letters');
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex');
$db = pdo();
let_housekeeping($db);
$tok = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$l = null; $msg = ''; $err = '';
if (ip_throttled($db, $ip)) $err = 'Too many tries. Please wait a few minutes.';
else {
    $l = let_by_token($db, $tok);
    if (!$l) { ip_fail($db, $ip); $err = 'This link is not valid any more. Please contact HR for a new one.'; }
}
if ($l && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try { $msg = 'Recorded: ' . let_answer($db, $l, (string)$_POST['answer'], (string)$_POST['typed'], $ip) . '. Thank you.'; $l = let_get($db, (int)$l['id']); }
    catch (Throwable $e) { $err = $e->getMessage(); }
}
ui_head('Your letter');
if ($err !== ''): ?><section class="card"><p><?= h($err) ?></p></section><?php endif;
if ($msg !== ''): ?><div class="flash ok"><?= h($msg) ?></div><?php endif;
if ($l): $t = let_template($db, (int)$l['template_id']); $state = let_answer_state($l, date('Y-m-d')); ?>
<section class="card"><h2><?= h($t['name']) ?> · <?= h($l['ref']) ?></h2><div class="letter"><?= $l['body_html'] /* escaped at issue */ ?></div>
  <p><b><?= h($l['signer_name']) ?></b><br><?= h($l['signer_title']) ?></p></section>
<?php if ($state === 'ok'): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="t" value="<?= h($tok) ?>"><h2>Your answer</h2>
  <label>Type your full name as it is on the letter <input name="typed" required maxlength="160" autocomplete="name"></label>
  <p class="row"><?php foreach (LET_ANSWER[$l['resp']] as $k => $v): ?><button class="btn<?= $k === 'no' ? ' ghost' : '' ?>" name="answer" value="<?= $k ?>"><?= h($v === 'Accepted' ? 'I accept' : ($v === 'Declined' ? 'I decline' : 'I acknowledge')) ?></button><?php endforeach; ?></p>
  <p class="muted">Your name, the time and your internet address are kept with the letter.</p></form>
<?php elseif ($state !== ''): ?><section class="card"><p><?= h($state) ?></p></section><?php endif; endif;
ui_foot();
