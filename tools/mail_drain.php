<?php
// Cron (every 5 minutes): send queued email that is due and retry failures. CLI only.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/mail.php';
$db = pdo();
$r = mail_drain($db, null, null, 100);
kv_set($db, 'mail_job_last', json_encode(['at' => time()] + $r));
echo "sent {$r['sent']}, failed {$r['failed']}, waiting {$r['waiting']}\n";
