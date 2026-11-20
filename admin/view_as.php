<?php
/**
 * View as · access preview — Super Admin only. Lists every role with what it gets and opens the site as that role,
 * read-only, through a synthetic preview login (lib/view_as.php).
 */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$u = require_login();
$previewing = !empty($u['preview']);          // va_apply() already checked the real login is a Super Admin
if (!$previewing) {
    $u = module_require('users');
    if (!is_superadmin($u)) { http_response_code(403); ui_head('Access preview', $u); echo '<div class="card">Only a Super Admin can preview access.</div>'; ui_foot(); exit; }
}
$db = pdo();
$labels = role_labels();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? '');
    $code = (string)($_POST['role'] ?? '');
    if ($a === 'stop') { va_stop(); flash('Back to your own view.'); redirect('view_as.php'); }
    if ($a === 'start' && isset($labels[$code])) {
        try {
            $realId = $previewing ? (int)$u['preview']['by'] : (int)$u['id'];
            if ($previewing) { va_login($db, $code); $_SESSION['view_as'] = $code; audit($realId, 'view_as_start', $code); }
            else va_start($db, $u, $code);
            redirect('../index.php');
        } catch (Throwable $e) { flash($e->getMessage(), 'err'); redirect('view_as.php'); }
    }
    flash('Pick a role.', 'err'); redirect('view_as.php');
}

ui_head('Access preview', $u, 'users');
?>
<div class="card">
  <p>Open the site as a role to check what it gets. The preview uses a test login for that role — never a real person's — and is <b>read-only</b>: nothing can be saved while it runs. Per-person allows and denies are not part of the preview.</p>
  <?php if ($previewing): ?><p>Now viewing as <b><?= h($labels[$u['preview']['role']] ?? '') ?></b>.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="stop"><button>Back to my view</button></form><?php endif; ?>
</div>
<?php foreach ($labels as $code => $label): $menu = va_role_menu($code); $n = 0; foreach ($menu as $g => $m) $n += count($m); ?>
<details class="card">
  <summary><b><?= h($label) ?></b> <small><?= h($code) ?> · <?= $n ?> screen<?= $n === 1 ? '' : 's' ?></small></summary>
  <?php if (!$n): ?><p>No screens.</p><?php endif; ?>
  <?php foreach ($menu as $g => $mods): ?>
    <p><small><?= h(MODULE_GROUPS[$g] ?? $g) ?></small><br><?= h(implode(' · ', array_map(fn($m) => $m['name'], $mods))) ?></p>
  <?php endforeach; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="a" value="start"><input type="hidden" name="role" value="<?= h($code) ?>"><button>Open the site as <?= h($label) ?></button></form>
</details>
<?php endforeach; ?>
<?php ui_foot();
