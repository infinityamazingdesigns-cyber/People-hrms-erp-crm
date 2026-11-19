<?php
// Third parties & contracts reminders: executed agreements and current documents ending soon. Run daily from cron:
//   php tools/ct_alerts.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once WN_ROOT . '/lib/contracts.php';
if (!module_on('contracts')) { echo "Third parties & contracts is off.\n"; exit; }
echo 'Reminders sent: ' . ct_alert_tick(pdo(), date('Y-m-d')) . "\n";
