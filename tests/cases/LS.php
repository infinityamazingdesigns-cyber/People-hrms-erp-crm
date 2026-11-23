<?php
/* Sign-in pictures (lib/login_slides.php, login_slide.php, login.php) */
require_once WN_ROOT . '/lib/login_slides.php';
t('LS01 text kept to one capped line', ls_text("TEST\x07 – a\n\nb   c", 40) === 'TEST – a b c' && mb_strlen(ls_text(str_repeat('x', 99), 40)) === 40);
t('LS02 caption fields capped', ls_meta(['tag' => str_repeat('t', 60), 'title' => 'TEST', 'text' => str_repeat('y', 300), 'evil' => 1]) === ['tag' => str_repeat('t', 40), 'title' => 'TEST', 'text' => str_repeat('y', 160)]);
t('LS03 unknown layout = no picture', ls_layout_of('right') === 'right' && ls_layout_of('') === 'plain' && ls_layout_of('x') === 'plain');
t('LS04 pictures checked: size, type, pixels', ls_check(0, null) !== '' && ls_check(LS_MAX_BYTES + 1, [2000, 1200, IMAGETYPE_JPEG]) !== ''
    && ls_check(1000, [2000, 1200, IMAGETYPE_GIF]) !== '' && ls_check(1000, [700, 400, IMAGETYPE_PNG]) !== '' && ls_check(1000, [1600, 900, IMAGETYPE_WEBP]) === '');
t('LS05 reorder moves one step, stays put at the ends', ls_reorder(['a', 'b', 'c'], 'b', -1) === ['b', 'a', 'c'] && ls_reorder(['a', 'b', 'c'], 'c', 1) === ['a', 'b', 'c'] && ls_reorder(['a'], 'z', 1) === ['a']);
$srv = file_get_contents(WN_ROOT . '/login_slide.php'); $lib = file_get_contents(WN_ROOT . '/lib/login_slides.php');
t('LS06 only pictures on the list are served, sandboxed', str_contains($srv, 'WHERE id = ? AND removed_at IS NULL') && str_contains($srv, "sandbox") && str_contains($srv, "preg_match('/^[0-9a-f]{32}$/', \$id)"));
t('LS07 pictures re-drawn and kept in the database; removing keeps the row', str_contains($lib, 'imagecopyresampled') && str_contains($lib, 'INSERT INTO login_slide') && !preg_match('/DELETE\s+FROM|unlink\(/i', $lib) && str_contains($lib, 'SET removed_at = NOW()'));
t('LS08 the screen is behind organisation settings', str_contains(file_get_contents(WN_ROOT . '/admin/login_pictures.php'), "module_require('org')"));
t('LS09 captions drawn as text on the sign-in page', str_contains(file_get_contents(WN_ROOT . '/assets/login.js'), "title.textContent = s.title") && !str_contains(file_get_contents(WN_ROOT . '/assets/login.js'), 'innerHTML'));
