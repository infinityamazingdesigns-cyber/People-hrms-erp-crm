<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
start_session();
if (!empty($_SESSION['uid'])) audit((int)$_SESSION['uid'], 'logout');
$_SESSION = [];
session_destroy();
redirect('login.php');
