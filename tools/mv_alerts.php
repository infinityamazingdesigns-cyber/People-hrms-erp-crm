<?php
// Cron (every 5 minutes): raise staff movement late alerts (out longer than the limit, still out at shift end). CLI only.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/movement.php';
if (!module_on('movement')) exit;
echo mv_alert_tick(pdo()) . " alert(s)\n";
