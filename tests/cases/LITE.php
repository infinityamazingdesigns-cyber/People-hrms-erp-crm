<?php
// Lite mode rules (lib/lite.php) — synthetic data only.
require_once WN_ROOT . '/lib/lite.php';
t('LI01 sign-in ticks Lite by itself on the browser\'s Save-Data hint or an earlier choice', lite_suggest(['HTTP_SAVE_DATA' => 'on'], []) && lite_suggest([], [LITE_COOKIE => '1']) && !lite_suggest(['HTTP_SAVE_DATA' => 'off'], []));
t('LI02 home: Lite when chosen and the module is on; ?full=1 clears; module off = full site', lite_route(true, [LITE_COOKIE => '1'], []) === 'lite' && lite_route(true, [LITE_COOKIE => '1'], ['full' => '1']) === 'full'
    && lite_route(false, [LITE_COOKIE => '1'], []) === '' && lite_route(true, [], []) === '');
t('LI03 a day line in a few words', lite_day(['status' => 'Present', 'in' => '09:31:00', 'out' => '18:02:00']) === 'Present 09:31–18:02' && lite_day(['status' => 'Absent', 'in' => null, 'out' => null, 'leave' => ['type' => 'CL']]) === 'Leave (CL)'
    && lite_day(['status' => 'Checked in', 'in' => '09:00:00', 'out' => null]) === 'Checked in 09:00');
$src = implode('', array_map('file_get_contents', glob(WN_ROOT . '/modules/lite/*.php')));
t('LI04 Lite keeps no rule of its own: punches go to the full handler, leave and corrections through their libraries; no other script', str_contains($src, 'modules/attendance/punch.php') && str_contains($src, 'lv_create(') && str_contains($src, 'atr_create(')
    && !preg_match('/INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM/i', $src) && substr_count($src, '<script') === 1);
$sizes = array_map(fn($f) => strlen((string)file_get_contents($f)), glob(WN_ROOT . '/modules/lite/*.php'));
t('LI05 every Lite page stays small (under 6 KB of source) and the camera is allowed on the Lite punch page only', max($sizes) < 6144 && str_contains((string)file_get_contents(WN_ROOT . '/.htaccess'), 'modules/lite/punch\.php$" WN_DEVICE_PAGE'));
