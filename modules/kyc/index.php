<?php
// KYC & bank — the person's own ID and bank details (sealed, masked), documents, and the HR verify desk.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/kyc.php';
$u = module_require('kyc');
$db = pdo();
$isHr = kyc_is_hr($u);
$country = kyc_country();
$pack = kyc_pack($country);
$emp = !empty($u['emp_code']) ? emp_by_code($db, (string)$u['emp_code']) : null;
$view = ($_GET['view'] ?? '') === 'hr' && $isHr ? 'hr' : 'me';

if (isset($_GET['doc'])) kyc_doc_send($db, $u, (int)$_GET['doc']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    try {
        if ($a === 'send' && $emp) {
            [$vals, $err] = kyc_clean($_POST, $country);
            [$ups, $uerr] = kyc_uploads($_FILES['doc'] ?? [], (array)($_POST['doc_kind'] ?? []));
            if ($err || $uerr) throw new RuntimeException(implode(' · ', array_merge($err, $uerr)));
            if (isset($vals['bank_acct']) && $vals['bank_acct'] !== strtoupper(preg_replace('/[\s\/.-]+/', '', (string)($_POST['bank_acct2'] ?? ''))))
                throw new RuntimeException('The two bank account numbers differ.');
            kyc_submit($db, $u, $emp, $vals, $ups);
            flash('Sent to HR to verify.');
        } elseif ($a === 'withdraw') {
            flash(kyc_withdraw($db, $u, (int)($_POST['id'] ?? 0)) ? 'Taken back.' : 'Nothing to take back.');
        } elseif ($a === 'decide' && $isHr) {
            flash('Saved: ' . strtolower(KYC_STATUS[kyc_decide($db, $u, (int)($_POST['id'] ?? 0), (array)($_POST['accept'] ?? []), (string)($_POST['hr_note'] ?? ''))]) . '.');
            redirect('index.php?view=hr');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php' . ($view === 'hr' ? '?view=hr' : ''));
}

ui_head('KYC & bank', $u, 'kyc');
if ($isHr): $waiting = kyc_waiting($db, $u); ?>
<nav class="row"><a class="btn sm<?= $view === 'me' ? '' : ' ghost' ?>" href="index.php">My KYC</a>
  <a class="btn sm<?= $view === 'hr' ? '' : ' ghost' ?>" href="?view=hr">KYC to verify<?= $waiting ? ' · ' . count($waiting) : '' ?></a></nav>
<?php endif;

if ($view === 'hr'):
    if (!$waiting): ?><section class="card"><p>Nothing waiting.</p></section><?php endif;
    foreach ($waiting as $r): $vals = kyc_change_full($db, $u, $r); $rec = kyc_record($db, $r['emp_code']); $docs = kyc_docs($db, $r['emp_code']); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="decide"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
  <h2><?= h(($r['name'] ?? '') . ' · ' . $r['emp_code']) ?> <small class="muted">sent <?= h($r['created_at']) ?></small></h2>
  <?php if ($vals): ?><table><thead><tr><th>Verify</th><th>Detail</th><th>On file</th><th>New (full)</th></tr></thead><tbody>
  <?php foreach ($vals as $k => $v): ?><tr><td><input type="checkbox" name="accept[]" value="<?= h($k) ?>" checked></td><td><?= h(kyc_label($k, $country)) ?></td>
    <td><?= h($rec['hints'][$k] ?? '—') ?><?= isset($rec['verified'][$k]) ? ' <span class="pill on">verified</span>' : '' ?></td><td><b><code><?= h($v) ?></code></b></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
  <p>Documents: <?php foreach ($docs as $d): ?><a target="_blank" href="?doc=<?= (int)$d['id'] ?>"><?= h(KYC_DOCS[$d['kind']] ?? $d['kind']) ?></a><?= (int)$d['change_id'] === (int)$r['id'] ? ' (new)' : '' ?> · <?php endforeach; ?><?= $docs ? '' : '<em>none</em>' ?></p>
  <label>Note to them (needed when sending everything back) <input name="hr_note" maxlength="500"></label>
  <button class="btn sm">Save</button> <span class="muted">Check each number against its document. Unticked details are sent back.</span>
</form>
<?php endforeach;

else:
    if (!$emp): ?><section class="card"><p>Your login is not linked to an employee record yet. Ask HR to link it on Users & access.</p></section><?php ui_foot(); exit; endif;
    $rec = kyc_record($db, $emp['code'], true);
    $open = kyc_open($db, (int)$u['id']);
    $docs = kyc_docs($db, $emp['code']);
    if ($open): ?><div class="flash ok">Your changes are with HR (sent <?= h($open['created_at']) ?>).
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="a" value="withdraw"><input type="hidden" name="id" value="<?= (int)$open['id'] ?>"><button class="btn sm ghost">Take them back</button></form></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="a" value="send">
  <h2>Your KYC & bank</h2>
  <p class="muted">Stored encrypted and shown masked. Leave a box empty to keep what is on file. Changing a tax ID, national ID or bank detail needs its document.</p>
  <?php foreach (KYC_SECTIONS as $sk => $sl): ?><fieldset><legend><?= h($sl) ?></legend><div class="grid">
  <?php foreach (KYC_FIELDS as $k => [$sec]): if ($sec !== $sk || !isset($pack[$k])) continue; [$label, , $hint] = $pack[$k];
      $onFile = in_array($k, KYC_OWN_FULL, true) ? ($rec['values'][$k] ?? '') : ($rec['hints'][$k] ?? ''); ?>
    <label><?= h($label) ?> <?php if ($onFile !== ''): ?><small>on file: <code><?= h($onFile) ?></code><?= isset($rec['verified'][$k]) ? ' <span class="pill on">verified</span>' : ' <span class="pill port">not verified</span>' ?></small><?php endif; ?>
      <?php if (isset($open['hints'][$k])): ?><small><span class="pill port">with HR: <?= h($open['hints'][$k]) ?></span></small><?php endif; ?>
      <input name="<?= $k ?>" <?= $k === 'bank_acct' ? 'inputmode="numeric"' : '' ?>><?php if ($hint): ?><small class="muted"><?= h($hint) ?></small><?php endif; ?></label>
    <?php if ($k === 'bank_acct'): ?><label>Account number again <input name="bank_acct2"></label><?php endif; ?>
  <?php endforeach; ?></div></fieldset><?php endforeach; ?>
  <fieldset><legend>Documents</legend>
    <?php for ($i = 0; $i < 4; $i++): ?><div class="row"><select name="doc_kind[]"><option value="">What is it?</option><?php foreach (KYC_DOCS as $dk => $dl): ?><option value="<?= $dk ?>"><?= h($dl) ?></option><?php endforeach; ?></select>
      <input type="file" name="doc[]" accept="application/pdf,image/jpeg,image/png,image/webp" capture="environment"></div><?php endfor; ?>
    <p class="muted">PDF or photo, up to 5 MB each. On file: <?php foreach ($docs as $d): ?><a target="_blank" href="?doc=<?= (int)$d['id'] ?>"><?= h(KYC_DOCS[$d['kind']] ?? $d['kind']) ?></a> · <?php endforeach; ?><?= $docs ? '' : 'none' ?></p>
  </fieldset>
  <button class="btn">Send to HR</button>
</form>
<?php endif;
ui_foot();
