<?php
/** Public: one sign-in background picture. Only pictures on the current list are served; anything else is 404. */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/login_slides.php';
$id = (string)($_GET['id'] ?? '');
$row = null;
if (preg_match('/^[0-9a-f]{32}$/', $id) && wn_installed()) {
    $s = pdo()->prepare('SELECT data FROM login_slide WHERE id = ? AND removed_at IS NULL'); $s->execute([$id]);
    $row = $s->fetchColumn();
}
if (!is_string($row) || $row === '') { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($row));
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: public, max-age=604800');   // the page links with ?v=<upload time>
echo $row;
