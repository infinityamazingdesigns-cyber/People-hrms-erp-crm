<?php
// Every module page calls a gate before anything else is shown.
$miss = [];
foreach (glob(WN_ROOT . '/modules/*/*.php') ?: [] as $f) {
    $src = (string)file_get_contents($f);
    if (!preg_match('/\bmodule_(require|require_self|public)\s*\(/', $src)) $miss[] = substr($f, strlen(WN_ROOT) + 1);
}
t('GT01 every module page calls module_require / module_require_self / module_public' . ($miss ? ' — missing: ' . implode(', ', $miss) : ''), !$miss);
