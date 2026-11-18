<?php
// Cron (every minute): send task reminders that are due as notices (and emails when set). CLI only.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/tasks.php';
if (!module_on('tasks')) exit;
echo tk_remind_tick(pdo()) . " reminder(s)\n";
