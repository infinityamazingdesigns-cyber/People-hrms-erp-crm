<?php
/* Ctrl + K palette (assets/cmdk.js, lib/ui.php, modules/talk/index.php?people=1) */
$js = file_get_contents(WN_ROOT . '/assets/cmdk.js');
$ui = file_get_contents(WN_ROOT . '/lib/ui.php');
$tk = file_get_contents(WN_ROOT . '/modules/talk/index.php');
t('CK01 the palette loads only for a signed-in page', str_contains($ui, '<?php if ($u): ?><link rel="stylesheet" href="<?= $b ?>assets/cmdk.css'));
t('CK02 screens come only from the menu drawn for this login', str_contains($js, "aside.side nav a.item") && substr_count($js, 'fetch(') === 1 && str_contains($js, "fetch(base + 'modules/talk/index.php?people=1'"));
t('CK03 people come from Team chat behind its gate, and never while previewing', str_contains($ui, "module_allowed(\$u, 'talk') && empty(\$u['preview'])") && strpos($tk, "people'] ?? '') === '1'") > strpos($tk, "module_require('talk')"));
t('CK04 the people list is the picker\'s own projection', str_contains($tk, "json_encode(['people' => talk_people(\$db, \$me)])"));
t('CK05 nothing typed is sent and names are drawn as text', !str_contains($js, 'innerHTML = it') && !preg_match('/fetch\([^)]*inp\.value/', $js) && str_contains($js, 'textContent = it.t'));
t('CK06 opening a chat goes through Team chat\'s own POST with the session token', str_contains($js, "['a', 'dm']") && str_contains($js, "['csrf', csrf]"));
