<?php
// Cron (hourly): make this month's drafts of repeating vendor bills and warn the pay roles of bills falling due. CLI only.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/vendorbills.php';
if (!module_on('vendorbills')) exit;
[$made, $told] = vb_repeat_tick(pdo());
echo "$made draft(s), $told due warning(s)\n";
