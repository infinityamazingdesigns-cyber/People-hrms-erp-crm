<?php
/* View as · access preview (lib/view_as.php) */
t('VA01 preview logins are recognised by their prefix', va_is_preview('preview.ceo') && va_is_preview('Preview.HR') && !va_is_preview('previewer') && !va_is_preview('test.admin'));
t('VA02 preview username is the role code in lower case, cleaned', va_username('HRMANAGER') === 'preview.hrmanager' && va_username('A-B c') === 'preview.abc');
t('VA03 reads pass while previewing', va_method_ok('GET', '/x/modules/leave/index.php') && va_method_ok('head', '/a.php'));
t('VA04 writes are refused except on the preview page', !va_method_ok('POST', '/modules/leave/index.php') && !va_method_ok('DELETE', '/api.php') && va_method_ok('POST', '/site/admin/view_as.php') && !va_method_ok('POST', '/site/admin/view_as.php.evil'));
$GLOBALS['__va_s'] = $_SESSION ?? null;
$_SESSION = [];
$real = ['id' => 9, 'name' => 'TEST – Real', 'roles' => ['EMPLOYEE'], 'is_owner' => 0];
t('VA05 no preview running → the real login is kept', va_apply($real) === $real);
$_SESSION = ['view_as' => 'CEO'];
t('VA06 a login that is not a Super Admin never previews, and the request is cleared', va_apply($real) === $real && !isset($_SESSION['view_as']));
$_SESSION = ['view_as' => 'NOPE'];
$sa = ['id' => 1, 'name' => 'TEST – SA', 'roles' => ['SUPERADMIN'], 'is_owner' => 0];
t('VA07 an unknown role is never previewed', va_apply($sa) === $sa && !isset($_SESSION['view_as']));
$_SESSION = $GLOBALS['__va_s'] ?? [];
t('VA08 banner only while previewing', va_banner(['id' => 1, 'roles' => []]) === '');
$src = file_get_contents(WN_ROOT . '/lib/bootstrap.php');
t('VA09 current_user swaps then guards every request', str_contains($src, 'va_apply($u)') && str_contains($src, 'va_guard($u)'));
t('VA10 sign-in refuses preview logins', str_contains(file_get_contents(WN_ROOT . '/login.php'), 'va_is_preview('));
