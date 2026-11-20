<?php
/**
 * Module map — Super Admin only, read-only: modules by group with their links, roles × modules, and data classes.
 */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require_once WN_ROOT . '/lib/module_map.php';
$u = module_require('modules');
if (!is_superadmin($u)) { http_response_code(403); ui_head('Module map', $u); echo '<div class="card">Only a Super Admin opens the module map.</div>'; ui_foot(); exit; }
$map = mm_build(module_catalog(), module_states(), role_labels());
$mods = $map['mods'];
$view = in_array($_GET['v'] ?? '', ['map', 'access', 'data'], true) ? $_GET['v'] : 'map';
$data = rbac_config()['data'] ?? [];
$labels = role_labels();
ui_head('Module map', $u, 'modules');
?>
<style>
.mm-tabs{display:flex;gap:6px;margin-bottom:12px}.mm-tabs a{padding:6px 12px;border-radius:8px;border:1px solid #DDE1E8;text-decoration:none;color:inherit}.mm-tabs a.on{background:var(--brand);color:#fff;border-color:var(--brand)}
.mm-grp{margin-bottom:14px}.mm-grp h3{margin:0 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#6B7486}
.mm-chips{display:flex;flex-wrap:wrap;gap:6px}
.mm-c{border:1px solid #DDE1E8;border-radius:8px;padding:6px 10px;background:#fff;cursor:pointer;font:inherit;text-align:left}
.mm-c small{display:block;color:#7A8394;font-size:11px}.mm-c.off{opacity:.55}.mm-c.sel{outline:2px solid var(--brand)}
.mm-c.need{background:#EAF1FF;border-color:#7FA3E8}.mm-c.dep{background:#FFF5DB;border-color:#E5B53B}
.mm-key span{display:inline-block;width:12px;height:12px;border-radius:3px;vertical-align:middle;margin:0 4px 0 12px;border:1px solid #ccc}
.mm-grid{overflow:auto;max-height:70vh}.mm-grid table{border-collapse:collapse;font-size:13px}.mm-grid th,.mm-grid td{border:1px solid #E3E6EC;padding:4px 6px;text-align:center}
.mm-grid thead th{position:sticky;top:0;background:#F6F7FA;writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap;height:130px}
.mm-grid tbody th{position:sticky;left:0;background:#fff;text-align:left;white-space:nowrap}
.mm-grid td.y{background:#E7F5EC;color:#1E7A43}.mm-grid tr.off th{color:#9AA1AE}
#mmInfo{position:sticky;top:10px}
</style>
<nav class="mm-tabs"><a class="<?= $view === 'map' ? 'on' : '' ?>" href="?v=map">Map</a><a class="<?= $view === 'access' ? 'on' : '' ?>" href="?v=access">Roles × modules</a><a class="<?= $view === 'data' ? 'on' : '' ?>" href="?v=data">Data classes</a></nav>
<p><?= count($mods) ?> modules · <?= $map['on'] ?> on · <?= $map['edges'] ?> links · <?= count($labels) ?> roles. Read from the catalog, the module settings and the role list as they stand now — a new module or role shows by itself.</p>

<?php if ($view === 'map'): ?>
<div class="grid" style="grid-template-columns:minmax(0,2fr) minmax(260px,1fr);align-items:start">
<div>
  <p class="mm-key">Pick a module:<span style="background:#EAF1FF;border-color:#7FA3E8"></span>it needs<span style="background:#FFF5DB;border-color:#E5B53B"></span>needs it<span style="opacity:.55;background:#fff"></span>switched off</p>
  <?php foreach ($map['groups'] as $g => $keys): ?>
  <div class="mm-grp"><h3><?= h(MODULE_GROUPS[$g] ?? $g) ?></h3><div class="mm-chips">
    <?php foreach ($keys as $k): $m = $mods[$k]; ?>
    <button type="button" class="mm-c<?= $m['on'] ? '' : ' off' ?>" data-k="<?= h($k) ?>" data-needs="<?= h(implode(' ', mm_needs_all($mods, $k))) ?>" data-deps="<?= h(implode(' ', $m['needed_by'])) ?>"><?= h($m['name']) ?><small><?= $m['core'] ? 'core' : ($m['on'] ? 'on' : 'off') ?><?= $m['self'] ? ' · for me' : '' ?></small></button>
    <?php endforeach; ?>
  </div></div>
  <?php endforeach; ?>
</div>
<aside class="card" id="mmInfo"><p>Pick a module to see what it needs, what needs it and who may open it.</p></aside>
</div>
<script type="application/json" id="mmData"><?= json_encode(array_map(fn($m) => ['name' => $m['name'], 'about' => $m['about'], 'on' => $m['on'], 'core' => $m['core'], 'needs' => $m['needs'], 'deps' => $m['needed_by'], 'roles' => array_map(fn($r) => $labels[$r] ?? $r, $m['roles']), 'self' => $m['self']], $mods), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function(){
  var D = JSON.parse(document.getElementById('mmData').textContent);
  var info = document.getElementById('mmInfo');
  function names(a){ return a.length ? a.map(function(k){ return D[k] ? D[k].name : k; }).join(', ') : 'none'; }
  function line(t, v){ var p = document.createElement('p'); var b = document.createElement('b'); b.textContent = t + ': '; p.appendChild(b); p.appendChild(document.createTextNode(v)); return p; }
  document.querySelectorAll('.mm-c').forEach(function(c){
    c.addEventListener('click', function(){
      var k = c.dataset.k, m = D[k], needs = (c.dataset.needs || '').split(' ').filter(Boolean), deps = (c.dataset.deps || '').split(' ').filter(Boolean);
      document.querySelectorAll('.mm-c').forEach(function(x){ x.classList.toggle('sel', x === c); x.classList.toggle('need', needs.indexOf(x.dataset.k) >= 0); x.classList.toggle('dep', deps.indexOf(x.dataset.k) >= 0); });
      info.textContent = '';
      var h = document.createElement('h3'); h.textContent = m.name; info.appendChild(h);
      if (m.about) { var a = document.createElement('p'); a.textContent = m.about; info.appendChild(a); }
      info.appendChild(line('State', m.core ? 'core — always on' : (m.on ? 'on' : 'off')));
      info.appendChild(line('Needs (switching on pulls these in)', names(needs)));
      info.appendChild(line('Needed by (cannot switch off while these are on)', names(deps)));
      info.appendChild(line('Roles that open it', m.roles.length ? m.roles.join(', ') : 'none'));
      if (m.self) info.appendChild(line('For me', 'every signed-in login sees its own records while it is on'));
    });
  });
})();
</script>

<?php elseif ($view === 'access'): ?>
<div class="card mm-grid"><table>
<thead><tr><th style="writing-mode:horizontal-tb;transform:none;height:auto">Module</th><?php foreach ($labels as $code => $l): ?><th><?= h($l) ?></th><?php endforeach; ?></tr></thead>
<tbody><?php foreach ($mods as $k => $m): ?><tr class="<?= $m['on'] ? '' : 'off' ?>"><th><?= h($m['name']) ?><?= $m['on'] ? '' : ' <small>(off)</small>' ?></th><?php foreach ($labels as $code => $l): $y = in_array($code, $m['roles'], true); ?><td class="<?= $y ? 'y' : '' ?>"><?= $y ? '✓' : '' ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
</table></div>
<p><small>Per-person allows and denies on Admin → Modules add to or take from this grid for one login. The owner always opens the core modules.</small></p>

<?php else: ?>
<div class="card"><table class="tbl"><thead><tr><th>Data class</th><th>Roles that see it</th></tr></thead><tbody>
<?php foreach ($data as $cls => $who): ?><tr><td><?= h(ucfirst($cls)) ?></td><td><?= in_array('*', $who, true) ? 'Every login' : (h(implode(', ', array_map(fn($r) => $labels[$r] ?? $r, $who))) ?: 'Nobody') ?></td></tr><?php endforeach; ?>
</tbody></table><p><small>ID numbers and bank details are sealed at rest and shown masked; pay is widened only by the Super Admin, person by person.</small></p></div>
<?php endif; ?>
<?php ui_foot();
