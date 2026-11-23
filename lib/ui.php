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
<?php if ($u): ?><link rel="stylesheet" href="<?= $b ?>assets/cmdk.css?v=<?= h(WN_VERSION) ?>"><script src="<?= $b ?>assets/cmdk.js?v=<?= h(WN_VERSION) ?>" data-base="<?= h($b) ?>" data-csrf="<?= h(csrf_token()) ?>" data-talk="<?= wn_installed() && module_allowed($u, 'talk') && empty($u['preview']) ? '1' : '0' ?>" defer></script><?php endif; ?>
<style>:root{--brand:<?= h($p) ?>;--accent:<?= h($a) ?>}</style>
</head><body>
<?php if ($u && wn_installed() && module_allowed($u, 'meet') && !str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), 'modules/meet/')): ?><link rel="stylesheet" href="<?= $b ?>assets/meet.css?v=<?= h(WN_VERSION) ?>"><script src="<?= $b ?>assets/meet-ring.js?v=<?= h(WN_VERSION) ?>" data-base="<?= h($b) ?>" data-csrf="<?= h(csrf_token()) ?>" defer></script><?php endif; ?>
<?php if (wn_installed() && is_stage()): ?><div class="stage-bar">Staging copy — no email, WhatsApp or notifications leave this site.</div><?php endif; ?>
<?php if ($u) echo va_banner($u); ?>
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
    <?php if ($u): ?><button class="ck-open" type="button" data-ck-open aria-label="Search screens and people">Search <kbd>Ctrl K</kbd></button><?php endif; ?>
    <?php if ($u): ?><div class="who"><span><?= h($u['name']) ?><small><?= h(implode(' · ', array_map(fn($r) => role_labels()[$r] ?? $r, $u['roles'])) ?: 'No role yet') ?><?= is_owner($u) ? ' · Owner' : '' ?></small></span>
      <a href="<?= $b ?>account.php">Account</a><a href="<?= $b ?>logout.php">Sign out</a></div><?php endif; ?>
  </header>
  <?php if ($f): ?><div class="flash <?= h($f[0]) ?>"><?= h($f[1]) ?></div><?php endif; ?>
<?php
}

function ui_foot(): void { ?>
  <script>(function(){   // every wide table scrolls inside its own box (first column stays in view); module tabs keep the open one in view
    function wrap(){document.querySelectorAll('main table').forEach(function(t){var p=t.parentElement;if(!p||p.classList.contains('tbl-wrap'))return;
      var cs=getComputedStyle(p);if(/(auto|scroll)/.test(cs.overflowX)){if(p.children.length===1)p.classList.add('tbl-wrap');return;}var w=document.createElement('div');w.className='tbl-wrap';p.insertBefore(w,t);w.appendChild(t);});
      document.querySelectorAll('.tbl-wrap').forEach(function(w){w.classList.toggle('scrolls',w.scrollWidth>w.clientWidth+2);});}
    function names(){document.querySelectorAll('main td input:not([type=hidden]),main td select,main td textarea').forEach(function(i){   // a field in a table cell is named by its column and row
      if((i.labels&&i.labels.length)||i.getAttribute('aria-label')||i.getAttribute('title'))return;var td=i.closest('td'),tr=td&&td.parentElement,tb=tr&&tr.closest('table');if(!tb)return;
      var hr=tb.querySelector('thead tr')||tb.rows[0],col=hr&&hr!==tr?hr.children[td.cellIndex]:null,row=tr.children[0]!==td?tr.children[0]:null,t=function(e){return e?(e.innerText||e.textContent).replace(/\s+/g,' ').trim():'';};
      var n=[t(col),t(row),i.getAttribute('placeholder')||''].filter(Boolean).join(' · ');if(n)i.setAttribute('aria-label',n.slice(0,80));});}
    var L={"b":"Branch","branch":"Branch","branch_id":"Branch","from":"From","to":"To","day":"Day","ym":"Month","kind":"Kind","status":"Status","st":"Status","sort":"Order","file":"File","f":"File","doc":"Document","uid":"Login","owner":"Owner","owner_id":"Owner","category":"Category","cat":"Category","type":"Type","priority":"Priority","severity":"Severity","due":"Due date","due_date":"Due date","due_time":"Due time","prio":"Priority","remind":"Reminder","rep":"Repeat","nag":"Remind again","label":"Label","value":"Value","field":"Field","reason":"Reason","scheme":"Scheme","state":"State","who":"Who","audience":"For","valid_till":"Valid till","valid_from":"Valid from","expires_on":"Expires on","manager":"Reports to","assigned":"Assigned to","attn":"Needs attention","by":"By","source":"Source","stage":"Stage","item":"Item","campaign":"Campaign","campaign_id":"Campaign","user":"Person","text":"Message","nom_relation":"Nominee relation","what":"What","from_day":"From","to_day":"To","photo":"Photo","key":"Module","mode":"Allow or deny","lvl_max":"Up to level","sec":"Section","name":"Name","only":"Show","w":"Which","ans":"Host answer","exp_on":"Expected on","exp_time":"Expected at","code":"Person","members":"Members","email":"Email","branches":"Branches","depts":"Departments","lines":"Pick this line","ship":"Pick this dispatch"};   // a field with no label at all is named from its field name (filters and short setting rows)
    function byName(){document.querySelectorAll('main input:not([type=hidden]):not([type=submit]):not([type=button]),main select,main textarea').forEach(function(i){
      if((i.labels&&i.labels.length)||i.getAttribute('aria-label')||i.getAttribute('title')||i.getAttribute('placeholder'))return;var n=(i.name||'').replace(/\[.*$/,'');if(L[n])i.setAttribute('aria-label',L[n]);});}
    wrap();names();byName();addEventListener('resize',wrap);document.addEventListener('toggle',wrap,true);
    var on=document.querySelector('.main>nav.row:first-of-type .btn:not(.ghost)');if(on&&on.scrollIntoView)on.scrollIntoView({block:'nearest',inline:'center'});
  })();</script>
  <footer class="foot">© <?= date('Y') ?> <?= h(wn_installed() ? (org('legal_name') ?: org('name')) : '') ?> · WorkNest HR <?= h(WN_VERSION) ?><?php if (wn_installed()): ?> · <a href="<?= wn_base() ?>privacy.php">Privacy</a> · <a href="<?= wn_base() ?>terms.php">Terms</a><?php endif; ?></footer>
</main></div></body></html>
<?php }
