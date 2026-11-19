<?php
// Complaints & CAPA reminders (overdue actions, cases with no update, cases nobody took). Run hourly from cron:
//   php tools/qc_alerts.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once WN_ROOT . '/lib/quality.php';
if (!module_on('quality')) { echo "Complaints & CAPA is off.\n"; exit; }
echo 'Reminders sent: ' . qc_alert_tick(pdo()) . "\n";
