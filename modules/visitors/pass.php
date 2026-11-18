<?php
// Visitor pass — printed at the desk. Name, company, whom they meet, branch, time in, visit number, privacy line.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/visitors.php';
$u = module_require('visitors');
$db = pdo();
if (!vis_rights($u)['desk']) { http_response_code(403); exit('Not allowed'); }
$v = vis_get($db, (int)($_GET['id'] ?? 0), vis_scope($u));
if (!$v) { http_response_code(404); exit('Not found'); }
$b = ''; foreach (branches(false) as $x) if ((int)$x['id'] === (int)$v['branch_id']) $b = $x['name'];
$set = vis_settings();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Visitor pass <?= h((string)$v['visit_no']) ?></title>
<style>body{font:14px system-ui,sans-serif;margin:0;padding:24px}.p{border:2px solid #222;border-radius:10px;padding:16px;width:320px}h1{font-size:18px;margin:0 0 4px}.n{font-size:22px;font-weight:700;margin:10px 0}.m{color:#555;font-size:12px}@media print{button{display:none}body{padding:0}}</style></head>
<body><div class="p"><h1><?= h(org('name')) ?></h1><div class="m">VISITOR · <?= h($b) ?></div><div class="n"><?= h($v['name']) ?></div>
<div><?= h(implode(' · ', array_filter([$v['company'], $v['category']]))) ?></div><div>Meeting: <b><?= h($v['host_name']) ?></b></div>
<div><?= h((string)$v['visit_no']) ?> · <?= h($v['check_in_at'] ? date('j M Y, H:i', strtotime((string)$v['check_in_at'])) : date('j M Y')) ?></div>
<p class="m"><?= h($set['privacy']) ?></p></div><button onclick="window.print()">Print</button></body></html>
