<?php
// My insurance — only the login's own cover (by its linked employee code): card, sum insured, nominee, how to claim,
// and a request for the policy copy. Opens the copy once HR has shared it.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/insurance.php';
$u = module_require_self('insurance');
$db = pdo(); $code = strtoupper((string)($u['emp_code'] ?? ''));
if (isset($_GET['doc'])) {   // the policy copy, once HR has shared it with this login
    $pid = (int)$_GET['doc'];
    if (!ins_may_open_doc($db, $u, $pid) || !($d = ins_policy_doc($db, $pid))) { http_response_code(404); exit('Not found'); }
    audit((int)$u['id'], 'ins_doc_view', '#' . $pid);
    header('Content-Type: ' . $d['doc_mime']); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $d['doc_name']) . '"');
    header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'"); header('Cache-Control: no-store');
    echo $d['doc']; exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        if (($_POST['a'] ?? '') === 'ask') { ins_request_add($db, $u, (int)$_POST['pid'], (string)($_POST['note'] ?? '')); flash('Sent to HR.'); }
        if (($_POST['a'] ?? '') === 'withdraw') { ins_request_withdraw($db, $u, (int)$_POST['rid']); flash('Withdrawn.'); }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('mine.php');
}
$dt = fn($v) => $v ? date('j M Y', strtotime((string)$v)) : ''; $m = fn($v) => $v === null || $v === '' ? '' : number_format((float)$v, 2);
$mine = ins_mine($db, $code); $reqs = ins_my_requests($db, (int)$u['id']);
$open = []; $shared = []; foreach ($reqs as $r) { if ($r['status'] === 'hr') $open[(int)$r['policy_id']] = $r; if ($r['status'] === 'shared') $shared[(int)$r['policy_id']] = true; }
ui_head('My insurance', $u, 'insurance'); ?>
<?php if ($code === ''): ?><section class="card"><p>Your login is not linked to an employee record yet — ask HR.</p></section><?php ui_foot(); exit; endif; ?>
<?php if (!$mine): ?><section class="card"><p class="muted">No group insurance is recorded yet.</p></section><?php endif; ?>
<?php foreach ($mine as $x): $p = $x['policy']; $l = $x['line']; $cov = $l && (int)$l['covered']; ?>
<section class="card"><h2><?= h($p['name']) ?> <span class="pill <?= $cov ? 'on' : '' ?>"><?= $cov ? 'Covered' : 'Not covered' ?></span></h2>
<p class="muted"><?= h(INS_KINDS[$p['kind']] ?? '') ?> · <?= h($p['insurer']) ?><?= $p['policy_no'] !== '' ? ' · policy ' . h($p['policy_no']) : '' ?><?= $p['cover_till'] ? ' · ' . h(trim($dt($p['cover_from']) . ' – ' . $dt($p['cover_till']), ' –')) : '' ?></p>
<?php if ($cov): ?><p>Card <b><?= h($l['card_no'] ?: '—') ?></b> · sum insured <?= $m($l['sum_insured'] ?? $p['sum_insured']) ?: $m($p['sum_insured']) ?><?= $l['since'] ? ' · from ' . h($dt($l['since'])) : '' ?><?= $l['nom_name'] !== '' ? '<br>Nominee: ' . h($l['nom_name']) . ($l['nom_relation'] !== '' ? ' (' . h($l['nom_relation']) . ')' : '') : '' ?></p>
<p class="muted"><?= h(implode(' · ', array_filter([$p['basis'], $p['helpline'] !== '' ? 'Helpline ' . $p['helpline'] : '', $p['claims_email'] !== '' ? 'Claims ' . $p['claims_email'] : '', $p['cashless_email'] !== '' ? 'Cashless ' . $p['cashless_email'] : '']))) ?></p>
<?php elseif ($l === null): ?><p class="muted">HR has not recorded you under this policy. Ask them if you think you should be covered.</p><?php endif; ?>
<?php if (!empty($shared[(int)$p['id']]) && $p['doc_at']): ?><p><a class="btn sm" href="mine.php?doc=<?= (int)$p['id'] ?>" target="_blank">Open the policy copy</a></p>
<?php elseif (isset($open[(int)$p['id']])): ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="withdraw"><input type="hidden" name="rid" value="<?= (int)$open[(int)$p['id']]['id'] ?>"><span class="muted">Your request for the copy is with HR.</span> <button class="btn sm ghost">Withdraw</button></form>
<?php else: ?><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="a" value="ask"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><input name="note" placeholder="Note (optional)" style="flex:1"><button class="btn sm ghost"><?= $cov ? 'Ask HR for the policy copy' : 'Ask HR about this cover' ?></button></form><?php endif; ?>
</section><?php endforeach; ?>
<?php if ($reqs): ?><section class="card"><h3>Your requests</h3><?php foreach ($reqs as $r): ?><p><?= h($dt($r['created_at'])) ?> · <?= h((string)$r['policy']) ?> · <?= h(INS_REQ_STATUS[$r['status']] ?? $r['status']) ?><?= $r['hr_note'] !== '' ? ' — HR: ' . h($r['hr_note']) : '' ?></p><?php endforeach; ?></section><?php endif; ?>
<?php ui_foot();
