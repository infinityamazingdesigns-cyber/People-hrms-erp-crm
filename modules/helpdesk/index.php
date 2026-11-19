<?php
// Help desk — ask a question (matched in the browser; nothing typed is sent), answers, team contacts, locations,
// social links; editors keep them (answers in any language, contacts, locations, links); Super Admin settings.
// Rules in lib/helpdesk.php.
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/helpdesk.php';
$u = module_require('helpdesk');
$db = pdo(); $uid = (int)$u['id']; $set = hd_settings(); $isAdmin = has_role($u, 'SUPERADMIN'); $can = hd_can_edit($u);
hd_seed($db);
$langs = hd_langs($set);
$lang = isset($langs[$_GET['lang'] ?? '']) ? (string)$_GET['lang'] : (isset($langs[$_COOKIE['wn_hd_lang'] ?? '']) ? (string)$_COOKIE['wn_hd_lang'] : 'en');
$bn = []; foreach (branches(false) as $b) $bn[(int)$b['id']] = $b['name'];
$view = (string)($_GET['view'] ?? 'ask');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = (string)($_POST['a'] ?? ''); $id = (int)($_POST['id'] ?? 0); $to = 'index.php?view=' . ($a === 'settings' ? 'settings' : 'edit');
    try {
        if ($a === 'settings') {
            if (!$isAdmin) throw new RuntimeException('Only the Super Admin changes these.');
            [$new, $err] = hd_settings_check($_POST, role_labels());
            if ($err) throw new RuntimeException(implode(' ', $err));
            $st = module_states()['helpdesk'] ?? ['roles' => module_roles('helpdesk')];
            $db->prepare('INSERT INTO module_state (module_key, enabled, roles, settings, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), updated_by = VALUES(updated_by)')
               ->execute(['helpdesk', 1, implode(',', $st['roles'] ?: module_roles('helpdesk')), json_encode($new), $uid]);
            audit($uid, 'module_settings', 'helpdesk');
        } else {
            if (!$can) throw new RuntimeException('Your role does not keep the help desk.');
            switch ($a) {
                case 'topic': [$row, $e] = hd_clean_topic($_POST, $langs, array_keys(module_catalog())); if ($e) throw new RuntimeException($e); hd_topic_save($db, $uid, $id, $row); $to .= '#t'; break;
                case 'contact': [$row, $e] = hd_clean_contact($_POST); if ($e) throw new RuntimeException($e); hd_save($db, $uid, 'contact', $id, $row); $to .= '#c'; break;
                case 'office': [$row, $e] = hd_clean_office($_POST, array_keys($bn)); if ($e) throw new RuntimeException($e); hd_save($db, $uid, 'office', $id, $row); $to .= '#o'; break;
                case 'social': hd_social_save($db, $uid, $_POST); $to .= '#s'; break;
                case 'off': case 'on': hd_off($db, $uid, (string)$_POST['what'], $id, $a === 'on'); break;
                default: throw new RuntimeException('Unknown request.');
            }
        }
        flash('Saved.');
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect($to);
}
if (isset($_GET['lang']) && isset($langs[$_GET['lang']])) setcookie('wn_hd_lang', $lang, ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax', 'secure' => function_exists('cookie_secure') ? cookie_secure() : !empty($_SERVER['HTTPS']), 'httponly' => true]);

$topics = hd_topics($db, $u, $lang);
$offices = hd_offices($db); $offBy = array_column($offices, null, 'id');
$contacts = hd_contacts($db); $social = hd_social($db);
$first = trim((string)preg_replace('/^\s*TEST\s*[–-]\s*/u', '', (string)$u['name'])); $first = explode(' ', $first)[0] ?? '';
$tab = fn(string $k, string $l) => ' <a class="btn sm' . ($view === $k ? '' : ' ghost') . '" href="?view=' . $k . '">' . h($l) . '</a>';
$wa = fn(string $m) => hd_whatsapp_link($m, (string)$set['dial_code']);
ui_head('Help desk', $u, 'helpdesk'); ?>
<nav class="row" style="flex-wrap:wrap"><?= $tab('ask', 'Ask') ?><?= $tab('people', 'Contacts & locations') ?><?= $can ? $tab('edit', 'Keep the help desk') : '' ?><?= $isAdmin ? $tab('settings', 'Settings') : '' ?>
<?php if (count($langs) > 1): ?><form class="row" style="margin-left:auto"><input type="hidden" name="view" value="<?= h($view) ?>"><select name="lang" onchange="this.form.submit()" aria-label="Language"><?php foreach ($langs as $k => $l): ?><option value="<?= h($k) ?>"<?= $k === $lang ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></form><?php endif; ?></nav>

<?php if ($view === 'ask'): $q = trim((string)($_GET['q'] ?? '')); $hit = $q !== '' ? hd_best($topics, $q) : null; ?>
<div class="card"><h2>Hello<?= $first !== '' ? ', ' . h($first) : '' ?>. What do you need help with?</h2>
<form class="row" id="hdForm"><input type="hidden" name="view" value="ask"><input name="q" id="hdQ" value="<?= h($q) ?>" placeholder="Ask in your own words, e.g. how do I apply for leave" style="flex:1;min-width:220px" autocomplete="off"><button class="btn">Ask</button></form>
<div id="hdAns" aria-live="polite"><?php if ($q !== ''): if ($hit): ?><div class="card"><h3><?= h($hit['title']) ?></h3><p><?= nl2br(h($hit['answer'])) ?></p><?= $hit['link'] ? '<a class="btn sm" href="' . h($hit['link']) . '">Open ' . h($hit['linkName']) . '</a>' : '' ?></div>
<?php else: ?><p class="muted">No answer matched. Try other words, pick a topic below, or ask a contact on Contacts &amp; locations.</p><?php endif; endif; ?></div></div>
<div class="card"><h2>Topics</h2><div class="grid" id="hdTopics"><?php foreach ($topics as $t): ?><details><summary><b><?= h($t['title']) ?></b></summary><p><?= nl2br(h($t['answer'])) ?></p><?= $t['link'] ? '<a class="btn sm" href="' . h($t['link']) . '">Open ' . h($t['linkName']) . '</a>' : '' ?></details><?php endforeach; ?></div>
<?php if (!$topics): ?><p class="muted">No answers yet.</p><?php endif; ?></div>
<script>(function(){var T=<?= str_replace('</', '<\/', (string)json_encode($topics, JSON_UNESCAPED_UNICODE)) ?>,MIN=<?= HD_MIN_SCORE ?>;
function norm(s){return (s||"").toLowerCase().replace(/[^\p{L}\p{M}\p{N}]+/gu," ").trim();}
function score(t,q){var nq=" "+norm(q)+" ",toks=nq.trim().split(" ").filter(function(x){return x.length>=2;});if(!toks.length)return 0;var s=0;
 (t.keywords||"").split(/\s*,\s*|\s+/).forEach(function(kw){var k=norm(kw);if(!k)return;for(var i=0;i<toks.length;i++){var x=toks[i];if(x===k){s+=3;break;}if(k.length>=4&&x.length>=4&&(x.indexOf(k)>=0||k.indexOf(x)>=0)){s+=2;break;}}});
 var tt=" "+norm(t.title)+" ";toks.forEach(function(x){if(x.length>=3&&tt.indexOf(" "+x+" ")>=0)s+=1;});return s;}
var f=document.getElementById("hdForm"),box=document.getElementById("hdAns");
f.addEventListener("submit",function(e){e.preventDefault();var q=document.getElementById("hdQ").value,best=null,top=0;T.forEach(function(t){var s=score(t,q);if(s>top){top=s;best=t;}});box.innerHTML="";
 if(!q.trim())return;var c=document.createElement("div");c.className="card";
 if(best&&top>=MIN){var h3=document.createElement("h3");h3.textContent=best.title;var p=document.createElement("p");p.style.whiteSpace="pre-line";p.textContent=best.answer;c.append(h3,p);
  if(best.link){var a=document.createElement("a");a.className="btn sm";a.href=best.link;a.textContent="Open "+best.linkName;c.append(a);}}
 else{var m=document.createElement("p");m.className="muted";m.textContent="No answer matched. Try other words, pick a topic below, or ask a contact on Contacts & locations.";c.append(m);}
 box.append(c);});})();</script>

<?php elseif ($view === 'people'): ?>
<div class="card"><h2>Team contacts</h2><?php if (!$contacts): ?><p class="muted">No contacts yet.</p><?php endif; ?>
<table><?php foreach ($contacts as $c): ?><tr><td><b><?= h($c['name']) ?></b><div class="muted"><?= h(implode(' · ', array_filter([$c['role'], $c['dept'], implode(', ', array_map(fn($i) => $offBy[(int)$i]['name'] ?? '', hd_ids($c['office_ids'])))]))) ?></div></td>
<td><?= $c['mobile'] !== '' ? '<a href="tel:' . h($c['mobile']) . '">' . h($c['mobile']) . '</a>' : '' ?><?= $c['whatsapp'] && $c['mobile'] !== '' ? ' · <a href="' . h($wa($c['mobile'])) . '" target="_blank" rel="noopener">WhatsApp</a>' : '' ?></td>
<td><?= $c['email'] !== '' ? '<a href="mailto:' . h($c['email']) . '">' . h($c['email']) . '</a>' : '' ?><?= $c['linkedin'] !== '' ? ' · <a href="' . h($c['linkedin']) . '" target="_blank" rel="noopener">LinkedIn</a>' : '' ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Our locations</h2><?php if (!$offices): ?><p class="muted">No locations yet.</p><?php endif; ?>
<div class="grid"><?php foreach ($offices as $o): ?><div><b><?= h($o['name']) ?></b> <span class="muted"><?= h(HD_KINDS[$o['kind']] ?? '') ?></span><p><?= nl2br(h($o['address'])) ?></p>
<?= $o['phone'] !== '' ? '<a href="tel:' . h($o['phone']) . '">' . h($o['phone']) . '</a> ' : '' ?><?= $o['map_url'] !== '' ? '<a class="btn sm ghost" href="' . h($o['map_url']) . '" target="_blank" rel="noopener">Map</a> ' : '' ?><?= $o['review_url'] !== '' ? '<a class="btn sm ghost" href="' . h($o['review_url']) . '" target="_blank" rel="noopener">Review us</a>' : '' ?></div><?php endforeach; ?></div></div>
<?php if ($social): ?><div class="card"><h2>Follow <?= h(org('name')) ?></h2><div class="row"><?php foreach ($social as $p => $url): ?><a class="btn sm ghost" href="<?= h($url) ?>" target="_blank" rel="noopener"><?= h(HD_SOCIAL[$p][0]) ?></a><?php endforeach; ?></div></div><?php endif; ?>

<?php elseif ($view === 'edit' && $can): $all = $db->query('SELECT * FROM hd_topic ORDER BY active DESC, sort, slug, lang')->fetchAll(); $ed = (int)($_GET['id'] ?? 0); $et = (string)($_GET['what'] ?? '');
    $cur = null; if ($ed && $et !== '') { $t = ['topic' => 'hd_topic', 'contact' => 'hd_contact', 'office' => 'hd_office'][$et] ?? ''; if ($t) { $s = $db->prepare("SELECT * FROM $t WHERE id = ?"); $s->execute([$ed]); $cur = $s->fetch() ?: null; } }
    $v = fn(string $k) => h((string)($cur[$k] ?? ''));
    $off = fn(string $what, array $r) => '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="a" value="' . ((int)$r['active'] ? 'off' : 'on') . '"><input type="hidden" name="what" value="' . $what . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sm ghost">' . ((int)$r['active'] ? 'Switch off' : 'Switch on') . '</button></form>';
    $mods = module_catalog(); ?>
<div class="card" id="t"><h2>Answers</h2><table><?php foreach ($all as $r): ?><tr<?= (int)$r['active'] ? '' : ' class="muted"' ?>><td><?= h($r['title']) ?> <span class="muted"><?= h($r['slug']) ?> · <?= h($langs[$r['lang']] ?? $r['lang']) ?><?= $r['module_key'] ? ' · ' . h($mods[$r['module_key']]['name'] ?? $r['module_key']) : '' ?></span></td>
<td><a class="btn sm ghost" href="?view=edit&what=topic&id=<?= (int)$r['id'] ?>#tf">Edit</a> <?= $off('topic', $r) ?></td></tr><?php endforeach; ?></table>
<form method="post" class="card" id="tf"><?= csrf_field() ?><input type="hidden" name="a" value="topic"><input type="hidden" name="id" value="<?= $et === 'topic' ? $ed : 0 ?>"><h3><?= $et === 'topic' && $cur ? 'Change answer' : 'Add an answer (or a translation: same short name, another language)' ?></h3>
<div class="grid"><label>Title <input name="title" value="<?= $et === 'topic' ? $v('title') : '' ?>" required></label><label>Short name <input name="slug" value="<?= $et === 'topic' ? $v('slug') : '' ?>" placeholder="leave"></label>
<label>Language <select name="lang"><?php foreach ($langs as $k => $l): ?><option value="<?= h($k) ?>"<?= ($et === 'topic' ? (string)($cur['lang'] ?? 'en') : 'en') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
<label>Opens screen <select name="module_key"><option value="">—</option><?php foreach ($mods as $k => $m): ?><option value="<?= h($k) ?>"<?= $et === 'topic' && ($cur['module_key'] ?? '') === $k ? ' selected' : '' ?>><?= h($m['name']) ?></option><?php endforeach; ?></select></label>
<label>Order <input type="number" name="sort" value="<?= $et === 'topic' ? (int)($cur['sort'] ?? 0) : 500 ?>"></label></div>
<label>Words people may use (spaces or commas) <input name="keywords" value="<?= $et === 'topic' ? $v('keywords') : '' ?>"></label>
<label>Answer <textarea name="answer" rows="5" required><?= $et === 'topic' ? $v('answer') : '' ?></textarea></label><button class="btn">Save</button></form></div>

<div class="card" id="c"><h2>Team contacts</h2><p class="muted">Official numbers and email IDs only — never a personal number.</p><table><?php foreach ($db->query('SELECT * FROM hd_contact ORDER BY active DESC, sort, name') as $r): ?><tr<?= (int)$r['active'] ? '' : ' class="muted"' ?>><td><?= h($r['name']) ?> <span class="muted"><?= h($r['dept']) ?> · <?= h($r['mobile']) ?> · <?= h($r['email']) ?></span></td><td><a class="btn sm ghost" href="?view=edit&what=contact&id=<?= (int)$r['id'] ?>#cf">Edit</a> <?= $off('contact', $r) ?></td></tr><?php endforeach; ?></table>
<form method="post" class="card" id="cf"><?= csrf_field() ?><input type="hidden" name="a" value="contact"><input type="hidden" name="id" value="<?= $et === 'contact' ? $ed : 0 ?>"><h3><?= $et === 'contact' && $cur ? 'Change contact' : 'Add a contact' ?></h3>
<label>Fill from the employee list <input list="hdPeople" id="hdPick" placeholder="Type a name"></label><datalist id="hdPeople"><?php foreach (hd_pick_people($db) as $p): ?><option value="<?= h($p['name']) ?>" data-dept="<?= h($p['dept']) ?>" data-role="<?= h($p['role']) ?>" data-email="<?= h($p['email']) ?>"><?= h(trim($p['dept'] . ' · ' . ($bn[(int)$p['branch_id']] ?? ''), ' ·')) ?></option><?php endforeach; ?></datalist>
<div class="grid"><label>Name <input name="name" id="cName" value="<?= $et === 'contact' ? $v('name') : '' ?>" required></label><label>Department <input name="dept" id="cDept" value="<?= $et === 'contact' ? $v('dept') : '' ?>"></label><label>Role <input name="role" id="cRole" value="<?= $et === 'contact' ? $v('role') : '' ?>"></label>
<label>Official mobile <input name="mobile" value="<?= $et === 'contact' ? $v('mobile') : '' ?>"></label><label>Official email <input name="email" id="cEmail" type="email" value="<?= $et === 'contact' ? $v('email') : '' ?>"></label><label>LinkedIn profile <input name="linkedin" value="<?= $et === 'contact' ? $v('linkedin') : '' ?>"></label>
<label>Order <input type="number" name="sort" value="<?= $et === 'contact' ? (int)($cur['sort'] ?? 0) : 0 ?>"></label></div>
<label class="row"><input type="checkbox" name="whatsapp" value="1"<?= $et === 'contact' && !empty($cur['whatsapp']) ? ' checked' : '' ?>> Show a WhatsApp button</label>
<div class="row" style="flex-wrap:wrap">Locations: <?php $sel = $et === 'contact' ? hd_ids((string)($cur['office_ids'] ?? '')) : []; foreach ($offices as $o): ?><label><input type="checkbox" name="office_ids[]" value="<?= (int)$o['id'] ?>"<?= in_array((int)$o['id'], $sel, true) ? ' checked' : '' ?>> <?= h($o['name']) ?></label><?php endforeach; ?></div><button class="btn">Save</button></form>
<script>(function(){var i=document.getElementById("hdPick");i&&i.addEventListener("change",function(){var o=[].find.call(document.querySelectorAll("#hdPeople option"),function(x){return x.value===i.value;});if(!o)return;
 document.getElementById("cName").value=o.value;["Dept","Role","Email"].forEach(function(k){var v=o.dataset[k.toLowerCase()];if(v)document.getElementById("c"+k).value=v;});});})();</script></div>

<div class="card" id="o"><h2>Locations</h2><table><?php foreach ($db->query('SELECT * FROM hd_office ORDER BY active DESC, sort, name') as $r): ?><tr<?= (int)$r['active'] ? '' : ' class="muted"' ?>><td><?= h($r['name']) ?> <span class="muted"><?= h(HD_KINDS[$r['kind']] ?? '') ?></span></td><td><a class="btn sm ghost" href="?view=edit&what=office&id=<?= (int)$r['id'] ?>#of">Edit</a> <?= $off('office', $r) ?></td></tr><?php endforeach; ?></table>
<form method="post" class="card" id="of"><?= csrf_field() ?><input type="hidden" name="a" value="office"><input type="hidden" name="id" value="<?= $et === 'office' ? $ed : 0 ?>"><h3><?= $et === 'office' && $cur ? 'Change location' : 'Add a location' ?></h3>
<div class="grid"><label>Name <input name="name" value="<?= $et === 'office' ? $v('name') : '' ?>" required></label><label>Type <select name="kind"><?php foreach (HD_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= $et === 'office' && ($cur['kind'] ?? '') === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
<label>Branch <select name="branch_id"><option value="0">—</option><?php foreach ($bn as $i => $n): ?><option value="<?= $i ?>"<?= $et === 'office' && (int)($cur['branch_id'] ?? 0) === $i ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
<label>Phone <input name="phone" value="<?= $et === 'office' ? $v('phone') : '' ?>"></label><label>Map link <input name="map_url" value="<?= $et === 'office' ? $v('map_url') : '' ?>"></label><label>Google review link <input name="review_url" value="<?= $et === 'office' ? $v('review_url') : '' ?>"></label>
<label>Order <input type="number" name="sort" value="<?= $et === 'office' ? (int)($cur['sort'] ?? 0) : 0 ?>"></label></div>
<label>Address <textarea name="address" rows="2"><?= $et === 'office' ? $v('address') : '' ?></textarea></label><button class="btn">Save</button></form></div>

<form method="post" class="card" id="s"><?= csrf_field() ?><input type="hidden" name="a" value="social"><h2>Follow us links</h2><p class="muted">Each link must be on that site's own address.</p>
<div class="grid"><?php foreach (HD_SOCIAL as $p => [$l]): ?><label><?= h($l) ?> <input name="<?= $p ?>" value="<?= h((string)($social[$p] ?? '')) ?>" placeholder="https://"></label><?php endforeach; ?></div><button class="btn">Save</button></form>

<?php elseif ($view === 'settings' && $isAdmin): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="a" value="settings"><h2>Settings</h2>
<h3>Roles that keep the help desk</h3><div class="row" style="flex-wrap:wrap"><?php foreach (role_labels() as $code => $n): ?><label><input type="checkbox" name="edit_roles[]" value="<?= h($code) ?>"<?= in_array($code, (array)$set['edit_roles'], true) ? ' checked' : '' ?>> <?= h($n) ?></label><?php endforeach; ?></div>
<label>Languages (one per line: two-letter code and name; English always stays) <textarea name="langs" rows="5"><?= h($set['langs']) ?></textarea></label>
<label>Country dialling code for WhatsApp buttons on numbers written without one (digits only, e.g. 1) <input name="dial_code" value="<?= h($set['dial_code']) ?>" inputmode="numeric"></label>
<button class="btn">Save</button></form>
<?php else: ?><div class="card">Not available.</div><?php endif;
ui_foot();
