<?php
// Public: the applicant's private link (no password). Shows only their own application — stage in candidate wording,
// HR's message, interviews (I will attend / ask to reschedule), issued letters sent to their email (answer them),
// their details and CV. Throttled, noindex, no-store. Never pay, panel feedback or anyone else.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/candidates.php';
module_public('candidates');
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex'); header('Referrer-Policy: no-referrer');
$db = pdo(); let_housekeeping($db);
$tok = (string)($_GET['t'] ?? $_POST['t'] ?? ''); $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$x = null; $err = ''; $msg = '';
if (ip_throttled($db, $ip)) $err = 'Too many tries. Please wait a few minutes.';
elseif (!($x = cand_by_token($db, $tok, $_SERVER['REQUEST_METHOD'] !== 'POST'))) { ip_fail($db, $ip); $err = 'This link is not valid any more. Please contact the HR team for a new one.'; }
if ($x && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch ((string)($_POST['a'] ?? '')) {
            case 'iv': cand_iv_answer($db, $x, (int)$_POST['iv'], (string)$_POST['answer'], (string)($_POST['note'] ?? '')); $msg = 'Thank you — HR has your answer.'; break;
            case 'details': cand_detail_send($db, $x, $_POST); $msg = 'Thank you — your details are sent to HR.'; break;
            case 'cv': $f = $_FILES['cv'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Pick your CV file (up to 5 MB).');
                cand_cv_send($db, $x, (string)file_get_contents($f['tmp_name']), (string)$f['name']); @unlink($f['tmp_name']); $msg = 'Thank you — your CV is sent to HR.'; break;
            case 'letter': $l = cand_letter($db, $x, (int)$_POST['lid']); if (!$l) throw new RuntimeException('Letter not found.');
                $msg = 'Recorded: ' . let_answer($db, $l, (string)$_POST['answer'], (string)($_POST['typed'] ?? ''), $ip) . '. Thank you.'; cand_log($db, (int)$x['id'], null, 'letter', '#' . (int)$l['id']); break;
        }
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
$dt = fn($v) => $v ? date('l, j M Y · H:i', strtotime((string)$v)) : '';
ui_head('Your application');
if ($err !== ''): ?><section class="card"><p><?= h($err) ?></p></section><?php endif;
if ($msg !== ''): ?><div class="flash ok"><?= h($msg) ?></div><?php endif;
if ($x): [$stage, $step] = cand_stage((string)$x['stage']); $ivs = cand_ivs($db, $x); $lets = cand_letters($db, $x); $now = date('Y-m-d H:i:s');
  $hid = fn(string $a) => csrf_field() . '<input type="hidden" name="t" value="' . h($tok) . '"><input type="hidden" name="a" value="' . $a . '">';
  $open = isset($_GET['letter']) ? cand_letter($db, $x, (int)$_GET['letter']) : null; ?>
<section class="card"><h2>Hello <?= h($x['name']) ?></h2><p>Your application<?= $x['req_title'] ? ' for <b>' . h($x['req_title']) . '</b>' : '' ?> with <?= h(org('name')) ?>: <b><?= h($stage) ?></b></p>
<?php if ($step >= 0): ?><ol class="row" style="flex-wrap:wrap;list-style:none;padding:0;gap:6px"><?php foreach (CAND_STEPS as $i => $s): ?><li class="pill<?= $i <= $step ? ' on' : '' ?>"><?= h($s) ?></li><?php endforeach; ?></ol><?php endif; ?>
<?= $x['hr_message'] !== '' ? '<p><b>From HR:</b> ' . h($x['hr_message']) . '</p>' : '' ?></section>
<?php if ($open): $ls = let_answer_state($open, date('Y-m-d')); ?>
<section class="card"><h2><?= h($open['tname']) ?> · <?= h((string)$open['ref']) ?></h2><div class="letter"><?= $open['body_html'] /* escaped at issue */ ?></div><p><b><?= h($open['signer_name']) ?></b><br><?= h($open['signer_title']) ?></p>
<?php if ($ls === 'ok'): ?><form method="post"><?= $hid('letter') ?><input type="hidden" name="lid" value="<?= (int)$open['id'] ?>"><label>Type your full name as it is on the letter <input name="typed" required maxlength="160" autocomplete="name"></label>
<p class="row"><?php foreach (LET_ANSWER[$open['resp']] as $k => $v): ?><button class="btn<?= $k === 'no' ? ' ghost' : '' ?>" name="answer" value="<?= $k ?>"><?= h($v === 'Accepted' ? 'I accept' : ($v === 'Declined' ? 'I decline' : 'I acknowledge')) ?></button><?php endforeach; ?></p><p class="muted">Your name, the time and your internet address are kept with the letter.</p></form>
<?php elseif ($ls !== ''): ?><p class="muted"><?= h($ls) ?></p><?php else: ?><p>Your answer: <b><?= h(LET_ANSWER[$open['resp']][$open['response']] ?? '') ?></b> (<?= h((string)$open['resp_at']) ?>)</p><?php endif; ?>
<p><a href="?t=<?= h($tok) ?>">Back</a></p></section><?php endif; ?>
<section class="card"><h3>Interviews</h3><?php if (!$ivs): ?><p class="muted">None scheduled yet.</p><?php endif; ?>
<?php foreach ($ivs as $i): $isLink = preg_match('#^https://#i', (string)$i['place']); ?><div style="border-top:1px solid #ddd;padding:6px 0"><b>Round <?= (int)$i['round'] ?></b> · <?= h($dt($i['at'])) ?> · <?= h(RC_MODES[$i['mode']] ?? $i['mode']) ?>
<?= $i['place'] !== '' ? ' · ' . ($isLink ? '<a href="' . h($i['place']) . '" target="_blank" rel="noopener">Join link</a>' : h($i['place'])) : '' ?><?= $i['panel_names'] ? '<br><span class="muted">With ' . h(implode(', ', $i['panel_names'])) . '</span>' : '' ?>
<?php if ($i['status'] !== 'scheduled'): ?><br><span class="muted"><?= h(['done' => 'Done', 'noshow' => 'Missed'][$i['status']] ?? $i['status']) ?></span>
<?php elseif ($i['answer'] === 'attend'): ?><br>You confirmed you will attend.
<?php elseif (cand_iv_open($i, $now)): ?><form method="post" class="row" style="flex-wrap:wrap"><?= $hid('iv') ?><input type="hidden" name="iv" value="<?= (int)$i['id'] ?>"><?= $i['answer'] === 'resched' ? '<span class="muted">You asked to reschedule.</span>' : '' ?><button class="btn sm" name="answer" value="attend">I will attend</button> <input name="note" placeholder="Times that suit you" maxlength="400"> <button class="btn sm ghost" name="answer" value="resched">Ask to reschedule</button></form><?php endif; ?></div><?php endforeach; ?></section>
<?php if ($lets): ?><section class="card"><h3>Letters</h3><?php foreach ($lets as $l): $s = let_answer_state($l, date('Y-m-d')); ?><p><a href="?t=<?= h($tok) ?>&amp;letter=<?= (int)$l['id'] ?>"><?= h($l['tname']) ?> · <?= h((string)$l['ref']) ?></a> · <?= h($s === 'ok' ? 'Waiting for your answer' : ($l['response'] ? (LET_ANSWER[$l['resp']][$l['response']] ?? '') : ($l['resp'] === 'none' ? 'For your records' : $s))) ?></p><?php endforeach; ?></section><?php endif; ?>
<form method="post" class="card"><?= $hid('details') ?><h3>Your details</h3><p class="muted">Sending again replaces what you sent before.</p><div class="grid"><?php foreach (CAND_FIELDS as $k => [$l, $max]): ?><label><?= h($l) ?> <input name="<?= $k ?>" maxlength="<?= $max ?>"></label><?php endforeach; ?></div><button class="btn sm">Send to HR</button></form>
<form method="post" enctype="multipart/form-data" class="card"><?= $hid('cv') ?><h3>Your CV</h3><p class="muted">PDF, Word, ODT, RTF or text, up to 5 MB. Every version you send is kept.</p><input type="file" name="cv" required accept=".pdf,.doc,.docx,.odt,.rtf,.txt"> <button class="btn sm">Send</button></form>
<p class="muted">Keep this link private. It stops working when your application closes.</p>
<?php endif;
ui_foot();
