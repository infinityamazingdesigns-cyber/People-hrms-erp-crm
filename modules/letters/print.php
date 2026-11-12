<?php
// One letter on letterhead, ready to print / save as PDF. HR and the approver see any; a person sees their own issued letter.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/letters.php';
if (is_file(WN_ROOT . '/lib/probation.php')) require_once WN_ROOT . '/lib/probation.php';
$u = module_require_self('letters');
$db = pdo();
$l = let_get($db, (int)($_GET['id'] ?? 0));
$staff = $l && module_allowed($u, 'letters') && (let_is_hr($u) || let_can_approve($u, $l));
$own = $l && let_own($u, $l) && in_array($l['status'], ['issued', 'lapsed'], true);
if (!$l || !($staff || $own)) { http_response_code(404); exit('Not found'); }
$t = let_template($db, (int)$l['template_id']);
$issued = $l['status'] === 'issued' || ($l['status'] === 'lapsed' && $l['body_html']);
$html = $issued ? (string)$l['body_html'] : let_render($t['body'], let_vars($db, $l));
$sg = $issued ? ['name' => $l['signer_name'], 'title' => $l['signer_title']] : let_signer_on(let_signers($db), $l['signer_role'], date('Y-m-d'));
$verify = rtrim((is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''), '/') . wn_base() . 'modules/letters/verify.php';
if ($own && !$staff) audit((int)$u['id'], 'letter_view_own', '#' . $l['id']);
$logo = org('logo');
header('Cache-Control: no-store');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($t['name'] . ' · ' . $l['person']) ?></title>
<style>
@page{size:A4;margin:18mm 18mm 22mm}body{font:15px/1.6 Georgia,'Times New Roman',serif;color:#111;max-width:760px;margin:24px auto;padding:0 18px;background:#fff}
.lh{display:flex;gap:14px;align-items:center;border-bottom:2px solid <?= h(org('color_primary') ?: '#13265C') ?>;padding-bottom:10px;margin-bottom:22px}.lh img{max-height:56px}.lh b{font-size:20px;display:block}.lh small{color:#555}
.meta{display:flex;justify-content:space-between;color:#333;margin-bottom:18px}.sig{margin-top:40px}.foot{margin-top:40px;border-top:1px solid #ccc;padding-top:8px;font:12px/1.4 Arial,sans-serif;color:#555}
.stamp{position:fixed;top:40%;left:0;right:0;text-align:center;font:bold 90px Arial;color:rgba(200,0,0,.12);transform:rotate(-20deg);pointer-events:none}mark{background:#ffe08a}
.bar{font:14px Arial;margin-bottom:16px}@media print{.bar{display:none}}
</style></head><body>
<div class="bar"><button onclick="print()">Print / save as PDF</button></div>
<?php if (!$issued): ?><div class="stamp">DRAFT</div><?php endif; ?>
<div class="lh"><?php if ($logo !== '' && str_starts_with($logo, 'data:image/')): ?><img src="<?= h($logo) ?>" alt=""><?php endif; ?><div><b><?= h(org('legal_name') ?: org('name')) ?></b><small><?= h(org('address')) ?></small></div></div>
<div class="meta"><span><?= $l['ref'] ? 'Ref: ' . h($l['ref']) : '' ?></span><span><?= h(date('j F Y', strtotime($l['issued_at'] ?? 'now'))) ?></span></div>
<?= $html /* escaped by let_render */ ?>
<div class="sig"><p>For <?= h(org('legal_name') ?: org('name')) ?></p><br><p><b><?= h($sg['name'] ?? '') ?></b><br><?= h($sg['title'] ?? '') ?></p></div>
<?php if ($l['response']): ?><p><i><?= h(LET_ANSWER[$l['resp']][$l['response']] ?? '') ?> by <?= h($l['resp_name']) ?> on <?= h(date('j M Y H:i', strtotime($l['resp_at']))) ?></i></p><?php endif; ?>
<?php if ($issued && $l['verify_code']): ?><div class="foot">Verification code <b><?= h($l['verify_code']) ?></b> — check this letter at <?= h($verify) ?></div><?php endif; ?>
</body></html>
