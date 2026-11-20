<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
start_session();
if (!empty($_SESSION['view_as'])) { va_stop(); redirect('admin/view_as.php'); }   // sign-out ends a preview only
if (!empty($_SESSION['uid'])) audit((int)$_SESSION['uid'], 'logout');
$_SESSION = [];
session_destroy();
redirect('login.php');
