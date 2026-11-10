<?php
/**
 * Page shell: side menu built only from the modules this login may open, header with the organisation's own
 * name, logo and colours (Admin → Organisation), light / dark, phone-friendly. No organisation name is ever
 * written in code.
 */
declare(strict_types=1);

function ui_color(string $k, string $fallback): string {
    $c = function_exists('org') && wn_installed() ? org($k) : '';
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? $c : $fallback;
}

function ui_head(string $title, ?array $u = null, string $active = ''): void {
    $b = wn_base();
    $name = wn_installed() ? org('name') : 'WorkNest HR';
    $logo = wn_installed() ? org('logo') : '';
    $p = ui_color('color_primary', '#13265C');
    $a = ui_color('color_accent', '#F2B705');
    $f = flash();
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title . ' · ' . $name) ?></title>
<link rel="stylesheet" href="<?= $b ?>assets/app.css?v=<?= h(WN_VERSION) ?>">
<style>:root{--brand:<?= h($p) ?>;--accent:<?= h($a) ?>}</style>
</head><body>
<?php if (wn_installed() && is_stage()): ?><div class="stage-bar">Staging copy — no email, WhatsApp or notifications leave this site.</div><?php endif; ?>
<div class="shell<?= $u ? '' : ' no-side' ?>">
<?php if ($u): ?>
<aside class="side" id="side">
  <a class="brand" href="<?= $b ?>index.php">
    <?php if ($logo !== '' && str_starts_with($logo, 'data:image/')): ?><img src="<?= h($logo) ?>" alt=""><?php else: ?><span class="mark"><?= h(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><?php endif; ?>
    <span><b><?= h($name) ?></b><small>WorkNest HR</small></span>
  </a>
  <nav>
  <?php foreach (module_menu($u) as $g => $mods): ?>
    <div class="grp"><?= h(MODULE_GROUPS[$g] ?? $g) ?></div>
    <?php foreach ($mods as $k => $m): ?>
      <a class="item<?= $active === $k ? ' on' : '' ?><?= $m['status'] !== 'built' ? ' soon' : '' ?>" href="<?= $b . h($m['entry']) ?>"><?= h($m['name']) ?><?php if ($m['status'] !== 'built'): ?><em><?= $m['status'] === 'port' ? 'porting' : 'planned' ?></em><?php endif; ?></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </nav>
</aside>
<?php endif; ?>
<main class="main">
  <header class="top">
    <?php if ($u): ?><button class="menu-btn" type="button" onclick="document.body.classList.toggle('side-open')" aria-label="Menu">☰</button><?php endif; ?>
    <h1><?= h($title) ?></h1>
    <?php if ($u): ?><div class="who"><span><?= h($u['name']) ?><small><?= h(implode(' · ', array_map(fn($r) => role_labels()[$r] ?? $r, $u['roles'])) ?: 'No role yet') ?><?= is_owner($u) ? ' · Owner' : '' ?></small></span>
      <a href="<?= $b ?>account.php">Account</a><a href="<?= $b ?>logout.php">Sign out</a></div><?php endif; ?>
  </header>
  <?php if ($f): ?><div class="flash <?= h($f[0]) ?>"><?= h($f[1]) ?></div><?php endif; ?>
<?php
}

function ui_foot(): void { ?>
  <footer class="foot">© <?= date('Y') ?> <?= h(wn_installed() ? (org('legal_name') ?: org('name')) : '') ?> · WorkNest HR <?= h(WN_VERSION) ?></footer>
</main></div></body></html>
<?php }
