<?php
// Video meetings API — routes only; every rule is in lib/meet.php.
//  GET  ?op=ring (rings for this login; a background check that never keeps an idle sign-in alive) · home · info&r · people[&r]
//       · poll&r&l&v · minutes&r[&txt=1]
//  POST {op: create | join | leave | ringanswer | invite | admit | remove | role | mute | hand | settings | state | signal | say | minutes | end, r, …}
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
require_once WN_ROOT . '/lib/meet.php';
$json = function ($v, int $code = 200) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($v, JSON_UNESCAPED_UNICODE); exit; };

if (($_GET['op'] ?? '') === 'ring' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $bu = current_user(false);
    if (!$bu) $json(['error' => 'signed_out'], 401);
    if (!module_allowed($bu, 'meet')) $json(['rings' => []]);
    session_write_close();
    try { $json(['rings' => meet_rings_for(pdo(), (int)$bu['id'])]); } catch (Throwable $e) { $json(['rings' => []]); }
}

$u = module_require('meet', true);
$me = (int)$u['id'];
$db = pdo();
$fail = fn(string $m, int $hc = 400) => $json(['error' => $m], $hc);
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        session_write_close();
        $op = (string)($_GET['op'] ?? 'home'); $code = (string)($_GET['r'] ?? '');
        if ($op === 'home') $json(meet_my_list($db, $me));
        if ($op === 'info') $json(meet_info($db, $me, $code));
        if ($op === 'people') $json(['people' => meet_people($db, $me, $code !== '' ? meet_room($db, $code) : null)]);
        if ($op === 'poll') $json(meet_poll($db, $me, $code, (int)($_GET['l'] ?? 0), (int)($_GET['v'] ?? 0)));
        if ($op === 'minutes') {
            $m = meet_minutes_get($db, $me, $code);
            if (!empty($_GET['txt'])) {
                if (!$m) { http_response_code(404); exit; }
                header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: private, no-store');
                header('Content-Disposition: attachment; filename="Minutes-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $m['title']) . '-' . date('Y-m-d', (int)$m['start']) . '.txt"');
                echo meet_minutes_text($m, org('name')); exit;
            }
            $json(['minutes' => $m, 'text' => $m ? meet_minutes_text($m, org('name')) : '']);
        }
        $fail('Unknown request');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail('Method not allowed', 405);
    if (!hash_equals(csrf_token(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) $fail('Session token mismatch – reload the page', 403);
    session_write_close();
    $in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $code = (string)($in['r'] ?? ''); $user = (int)($in['user'] ?? 0);
    switch ((string)($in['op'] ?? '')) {
        case 'create': $c = meet_create($db, $me, $in); audit($me, 'meet_create', $c); $json(['ok' => true, 'code' => $c]);
        case 'join': $json(['ok' => true, 'state' => meet_join($db, $me, $code)]);
        case 'leave': meet_leave($db, $me, $code); $json(['ok' => true]);
        case 'ringanswer': meet_ring_answer($db, $me, $code, !empty($in['accept'])); $json(['ok' => true]);
        case 'invite': [$rung, $added] = meet_invite($db, meet_room($db, $code), $me, (array)($in['users'] ?? []), !empty($in['ring'])); $json(['ok' => true, 'rung' => $rung, 'invited' => $added]);
        case 'admit': meet_admit($db, $me, $code, $user, !empty($in['yes'])); $json(['ok' => true]);
        case 'remove': meet_remove($db, $me, $code, $user); audit($me, 'meet_remove', "$code #$user"); $json(['ok' => true]);
        case 'role': meet_set_role($db, $me, $code, $user, (string)($in['role'] ?? '')); $json(['ok' => true]);
        case 'mute': meet_mute($db, $me, $code, $user); $json(['ok' => true]);
        case 'hand': meet_lower_hand($db, $me, $code, $user); $json(['ok' => true]);
        case 'settings': meet_room_settings($db, $me, $code, $in); $json(['ok' => true]);
        case 'state': meet_state($db, $me, $code, $in); $json(['ok' => true]);
        case 'signal': meet_signal($db, $me, $code, (int)($in['to'] ?? 0), $in['data'] ?? null); $json(['ok' => true]);
        case 'say': $json(['ok' => true, 'id' => meet_say($db, $me, $code, (string)($in['kind'] ?? ''), (string)($in['text'] ?? ''), (string)($in['lang'] ?? ''))]);
        case 'minutes':
            $room = meet_room($db, $code); meet_need_mod($db, $room, $me);
            $m = meet_minutes_build($db, $room);
            $sent = !empty($in['send']) ? meet_minutes_send($db, $room, $m) : 0;
            $json(['ok' => true, 'minutes' => $m, 'text' => meet_minutes_text($m, org('name')), 'sent' => $sent]);
        case 'end': $m = meet_end($db, $me, $code); audit($me, 'meet_end', $code); $json(['ok' => true, 'minutes' => $m, 'text' => meet_minutes_text($m, org('name'))]);
    }
    $fail('Unknown request');
} catch (PDOException $e) { error_log('meet: ' . $e->getMessage()); $fail('Could not save – try again', 500); }
catch (RuntimeException $e) { $fail($e->getMessage()); }
catch (Throwable $e) { error_log('meet: ' . $e->getMessage()); $fail('Something went wrong – try again', 500); }
