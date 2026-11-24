<?php
// My payslips — own released payslips behind a 4-digit PIN. HR with pay data may preview anyone's (?code=&ym=).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/payroll.php';
$u = module_require_self('payslips');
$db = pdo();
$uid = (int)$u['id'];
$code = (string)($u['emp_code'] ?? '');
$hrCode = strtoupper(trim((string)($_GET['code'] ?? '')));
$hr = $hrCode !== '' && pay_can($u) && strcasecmp($hrCode, $code) !== 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    if ($a === 'lock') { ps_pin_close(); if (!empty($_SERVER['HTTP_X_CSRF'])) { http_response_code(204); exit; } redirect('index.php'); }
    try {
        if ($a === 'setpin') { ps_pin_set($db, $uid, (string)$_POST['pin'], (string)$_POST['again']); flash('PIN set.'); }
        elseif ($a === 'pin') ps_pin_check($db, $uid, (string)$_POST['pin']);
        elseif ($a === 'reset') { $t = (int)$_POST['uid']; ps_pin_reset($db, $u, $t); flash('PIN reset. The person sets a new one on their next visit.'); }
        elseif ($a === 'who') {
            if (!is_superadmin($u)) throw new RuntimeException('Only the Super Admin.');
            $who = ($_POST['who'] ?? '') === 'chosen' ? 'chosen' : 'everyone';
            $st = module_states()['payslips'] ?? ['roles' => module_roles('payslips')];
            $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
               ->execute(['payslips', 1, implode(',', $st['roles'] ?: module_roles('payslips')), json_encode(['who' => $who]), $uid]);
            audit($uid, 'module_settings', 'payslips who=' . $who); flash('Saved.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php' . (isset($_POST['ym']) ? '?ym=' . urlencode((string)$_POST['ym']) : ''));
}

header('Cache-Control: no-store');
ui_head('My payslips', $u, 'payslips:self');
echo '<style>' . PS_CSS . '.gone .slip{display:none}</style>';

if ($hr):   // HR preview of someone else's slip (pay data), from the locked month only
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['ym'] ?? '')) ? (string)$_GET['ym'] : '';
    $m = pay_month_row($db, $ym); $e = emp_by_code($db, $hrCode);
    $s = $db->prepare('SELECT data FROM pay_snapshot WHERE ym = ? AND ver = ? AND emp_code = ?'); $s->execute([$ym, (int)$m['ver'], $hrCode]);
    $line = ($d = $s->fetchColumn()) ? json_decode($d, true) : null;
    if (!$line || !$e || !emp_in_scope($u, $e) || !in_array($m['status'], ['confirmed', 'released'], true)) { echo '<section class="card"><p>No locked payslip for that person and month.</p></section>'; ui_foot(); exit; }
    audit($uid, 'payslip_preview', $hrCode . ' ' . $ym);
    echo '<p class="note">Preview for HR — ' . h(PAY_STATUS[$m['status']]) . '. <button class="btn sm ghost" onclick="print()">Print / save as PDF</button></p>' . ps_render($line, $ym);
    ui_foot(); exit;
endif;

if (!ps_own_open($u)): ?><section class="card"><p><?= $code === '' ? 'Your login is not linked to an employee record yet. Ask HR.' : 'Payslips are not shown here for your login. Ask HR.' ?></p></section>
<?php elseif (!ps_pin_row($db, $uid)): ?>
<form method="post" class="card narrow"><?= csrf_field() ?><input type="hidden" name="a" value="setpin"><h2>Set your payslip PIN</h2>
  <label>4-digit PIN <input name="pin" type="password" inputmode="numeric" maxlength="4" pattern="\d{4}" autocomplete="new-password" required></label>
  <label>Again <input name="again" type="password" inputmode="numeric" maxlength="4" pattern="\d{4}" autocomplete="new-password" required></label><button class="btn">Set PIN</button></form>
<?php elseif (!ps_pin_is_open()): ?>
<form method="post" class="card narrow"><?= csrf_field() ?><input type="hidden" name="a" value="pin"><?php if (isset($_GET['ym'])): ?><input type="hidden" name="ym" value="<?= h((string)$_GET['ym']) ?>"><?php endif; ?><h2>Your payslips are locked</h2>
  <label>PIN <input name="pin" type="password" inputmode="numeric" maxlength="4" pattern="\d{4}" autocomplete="off" required autofocus></label><button class="btn">Open</button></form>
<?php else:
    $months = ps_own_months($db, $code); $ym = (string)($_GET['ym'] ?? array_key_first($months) ?? '');
    if (isset($months[$ym])) audit($uid, 'payslip_view', $ym); ?>
<nav class="row"><?php foreach ($months as $k => $_): ?><a class="btn sm<?= $k === $ym ? '' : ' ghost' ?>" href="?ym=<?= h($k) ?>"><?= h(date('M Y', strtotime($k . '-01'))) ?></a><?php endforeach; ?>
  <?php if (isset($months[$ym])): ?><button class="btn sm ghost" onclick="print()">Print / save as PDF</button><?php endif; ?>
  <form method="post"><?= csrf_field() ?><button class="btn sm ghost" name="a" value="lock">Lock payslips</button></form></nav>
<?= isset($months[$ym]) ? ps_render($months[$ym], $ym) : '<section class="card"><p class="muted">No payslips released yet.</p></section>' ?>
<script>
(() => { const t = <?= json_encode(csrf_token()) ?>; let gone = false;
  const lock = () => { if (gone) return; gone = true; document.documentElement.classList.add('gone'); navigator.sendBeacon?.('index.php', new Blob(['a=lock&csrf=' + encodeURIComponent(t)], { type: 'application/x-www-form-urlencoded' })); };
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') lock(); });
  addEventListener('pagehide', lock); addEventListener('pageshow', e => { if (e.persisted) location.reload(); });
  addEventListener('beforeprint', () => { gone = true; setTimeout(() => gone = false, 1500); }); })();
</script>
<?php endif;
if (is_superadmin($u)): $set = ps_settings(); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="who"><h2>Who sees their own payslips</h2>
  <p><label class="tick"><input type="radio" name="who" value="everyone"<?= $set['who'] === 'everyone' ? ' checked' : '' ?>> Everyone linked to an employee record</label>
  <label class="tick"><input type="radio" name="who" value="chosen"<?= $set['who'] === 'chosen' ? ' checked' : '' ?>> Only people allowed on Admin → Modules → Payslips</label></p><button class="btn sm">Save</button></form>
<form method="post" class="card row"><?= csrf_field() ?><input type="hidden" name="a" value="reset"><select name="uid" required aria-label="Login"><option value="">Reset a forgotten PIN for…</option>
  <?php foreach ($db->query('SELECT u.id, u.name FROM users u JOIN pay_pin p ON p.user_id = u.id ORDER BY u.name') as $r) if ((int)$r['id'] !== $uid): ?><option value="<?= (int)$r['id'] ?>"><?= h($r['name']) ?></option><?php endif; ?></select><button class="btn sm ghost">Reset PIN</button></form>
<?php endif;
ui_foot();
