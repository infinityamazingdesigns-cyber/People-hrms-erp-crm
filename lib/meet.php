<?php
// Video meetings — rules. Camera, microphone and screen go browser to browser (WebRTC); this site keeps the meeting
// record and passes connection messages (each handed over once, then deleted). Every open meeting page asks the API
// what changed (people, chat, transcript lines, connection messages).
//  - Every login with the module may start a meeting and becomes its host. Logins only — no outside guests.
//  - A meeting is seen only by its members: nobody reads its chat, transcript or minutes unless they were in it.
//  - The host's invitees (and people rung) come straight in; anyone else with the code waits until a host / co-host
//    admits them, unless the host chose "anyone with the code joins directly". Locked = nobody new; removed = never
//    back to that meeting. The host is never removed or demoted; with no host / co-host left in the room the person
//    in longest becomes co-host.
//  - Ringing: a moderator rings colleagues; any open page shows a ringing card while it lasts.
//  - Live transcript: each browser writes down its own speaker's voice and sends finished lines; a muted microphone
//    sends nothing.
//  - Minutes: summary, decisions, action items, open questions, attendees — worked out from the lines by rules, when
//    the meeting ends (or on a moderator's request); emailed to attendees when Email is on and the host left it on.
// Pure functions first, then the database half.
declare(strict_types=1);

const MEET_REACTIONS = ['👍', '👏', '🎉', '😂', '❤️', '🙏'];
const MEET_TEXT_MAX = 2000;
const MEET_CAP_MAX = 1000;
const MEET_SIGNAL_BYTES = 65536;
const MEET_GONE_SECS = 30;
const MEET_DEFAULTS = ['max_in' => 12, 'ring_secs' => 60, 'stun' => "stun:stun.l.google.com:19302\nstun:stun1.l.google.com:19302", 'langs' => "en-US English (US)\nen-GB English (UK)\nen-IN English (India)\nhi-IN Hindi\nes-ES Spanish\nfr-FR French\nde-DE German\nar-AE Arabic"];
const MEET_STOP = ['the', 'a', 'an', 'and', 'or', 'but', 'if', 'then', 'so', 'to', 'of', 'in', 'on', 'at', 'for', 'with', 'from', 'by', 'is', 'are', 'was', 'were',
    'be', 'been', 'it', 'this', 'that', 'these', 'those', 'we', 'you', 'they', 'he', 'she', 'i', 'me', 'my', 'our', 'your', 'their', 'will', 'would', 'can',
    'could', 'should', 'have', 'has', 'had', 'do', 'does', 'did', 'not', 'no', 'yes', 'okay', 'ok', 'just', 'also', 'there', 'here', 'what', 'when', 'which',
    'about', 'into', 'than', 'very', 'some', 'more', 'like', 'know', 'think', 'yeah', 'right', 'going', 'want', 'need', 'please', 'thank', 'thanks'];

function meet_settings(): array {
    $s = module_states()['meet']['settings'] ?? [];
    return array_replace(MEET_DEFAULTS, array_intersect_key($s, MEET_DEFAULTS));
}
function meet_settings_check(array $in): array {
    $o = ['max_in' => max(2, min(16, (int)($in['max_in'] ?? 12))), 'ring_secs' => max(15, min(300, (int)($in['ring_secs'] ?? 60)))];
    $l = []; foreach (preg_split('/\R/', (string)($in['langs'] ?? '')) ?: [] as $line) if (preg_match('/^\s*([a-z]{2}(?:-[A-Z]{2})?)\s+(.{1,40})$/u', $line, $m)) $l[$m[1]] = trim($m[2]);
    $e = $l ? [] : ['Keep at least one transcript language ("code Name" per line, e.g. en-US English).'];
    $o['stun'] = implode("\n", meet_ice_list((string)($in['stun'] ?? '')));
    $o['langs'] = implode("\n", array_map(fn($k, $v) => "$k $v", array_keys($l), $l));
    return [$o, $e];
}
/** STUN / TURN addresses from the setting (stun: / turn: / turns: only), at most 4. Pure. */
function meet_ice_list(string $text): array {
    $o = []; foreach (preg_split('/[\s,]+/', $text) ?: [] as $x) if (preg_match('/^(stun|turns?):[A-Za-z0-9.\-]+(:\d{1,5})?(\?transport=(udp|tcp))?$/', $x)) $o[] = $x;
    return array_slice(array_values(array_unique($o)), 0, 4);
}
/** code => label from the setting. Pure. */
function meet_langs(array $set): array {
    $o = []; foreach (explode("\n", (string)$set['langs']) as $line) if (preg_match('/^([a-z]{2}(?:-[A-Z]{2})?)\s+(.+)$/u', trim($line), $m)) $o[$m[1]] = $m[2];
    return $o ?: ['en-US' => 'English'];
}

function meet_clean(?string $s, int $max): string { return mb_substr(trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$s)), 0, $max); }
/** A fresh code like abc-defg-hjk (no i, l, o). */
function meet_code_make(): string {
    $a = 'abcdefghjkmnpqrstuvwxyz';
    $p = function (int $n) use ($a) { $s = ''; for ($i = 0; $i < $n; $i++) $s .= $a[random_int(0, strlen($a) - 1)]; return $s; };
    return $p(3) . '-' . $p(4) . '-' . $p(3);
}
/** The code inside whatever was pasted (a code, or an address with ?r=); '' when none. Pure. */
function meet_code_parse(string $in): string {
    $in = strtolower(trim($in));
    if (preg_match('/[?&]r=([a-z]{3}-[a-z]{4}-[a-z]{3})/', $in, $m)) return $m[1];
    return preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $in) ? $in : '';
}
function meet_is_mod(?array $m): bool { return $m && $m['state'] === 'in' && in_array($m['role'], ['host', 'cohost'], true); }

/** Where a person goes when they ask to join: 'in' or 'waiting', or a refusal. Pure. */
function meet_join_decision(?array $m, array $room, int $inNow, int $max): string {
    if ($room['ended_at']) throw new RuntimeException('This meeting has ended.');
    if ($m && $m['state'] === 'removed') throw new RuntimeException('The host removed you from this meeting.');
    $here = $m && $m['state'] === 'in';
    if ($room['locked'] && !($m && $m['role'] === 'host') && !$here) throw new RuntimeException('The host has locked this meeting.');
    if (!$here && $inNow >= $max) throw new RuntimeException("This meeting is full ($max people).");
    $known = $m && $m['role'] !== 'denied' && (in_array($m['role'], ['host', 'cohost'], true) || in_array($m['state'], ['invited', 'ringing', 'declined', 'left', 'in'], true));
    return ($known || $room['open_join']) ? 'in' : 'waiting';
}

/** Is a connection message well formed? Pure. */
function meet_signal_ok($data): bool {
    if (!is_array($data) || !(isset($data['sdp']) || isset($data['cands']))) return false;
    $j = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $j !== false && strlen($j) <= MEET_SIGNAL_BYTES;
}

/** Text of a chat line / transcript line / reaction, or throws. Pure. */
function meet_say_text(string $kind, string $text, bool $micOn): string {
    if ($kind === 'cap' && !$micOn) throw new RuntimeException('Your microphone is muted.');
    $t = match ($kind) {
        'chat' => meet_clean($text, MEET_TEXT_MAX),
        'cap' => meet_clean($text, MEET_CAP_MAX),
        'react' => in_array($text, MEET_REACTIONS, true) ? $text : '',
        default => throw new RuntimeException('Unknown message type.'),
    };
    if ($t === '') throw new RuntimeException($kind === 'react' ? 'Pick one of the reactions.' : 'Type a message first.');
    return $t;
}

/** Minutes from the chat and transcript lines by rules (no outside service). Pure. */
function meet_minutes_auto(array $talk, int $people, int $mins, string $agenda = ''): array {
    $sent = [];
    foreach ($talk as $l) foreach (preg_split('/(?<=[.!?।])\s+/u', (string)$l['text']) ?: [] as $x) { $x = trim($x); if (mb_strlen($x) > 3) $sent[] = ['who' => $l['name'] ?: 'Someone', 's' => $x]; }
    $due = '/\b(today|tomorrow|tonight|eod|by (mon|tues|wednes|thurs|fri|satur|sun)day|next week|this week|by \d{1,2}(st|nd|rd|th)?\b[^,.]*)/i';
    $decisions = []; $actions = []; $questions = []; $freq = [];
    foreach ($sent as $x) {
        if (preg_match('/\b(decided|agreed|approved?|finali[sz]ed|confirmed|resolved|go ahead)\b/i', $x['s'])) $decisions[] = $x['s'] . ' (' . $x['who'] . ')';
        if (preg_match("/\b(i will|i'll|we will|we'll|will send|will share|will check|action|to do|todo|follow up|assign(ed)?|deadline|need to|must)\b/i", $x['s'])
            || (preg_match('/\bwill\b/i', $x['s']) && preg_match($due, $x['s']))) { preg_match($due, $x['s'], $dm); $actions[] = ['owner' => $x['who'], 'task' => $x['s'], 'due' => $dm[0] ?? '']; }
        if (str_ends_with($x['s'], '?')) $questions[] = $x['s'] . ' (' . $x['who'] . ')';
        preg_match_all('/[\p{L}]{4,}/u', mb_strtolower($x['s']), $w);
        foreach ($w[0] as $t) if (!in_array($t, MEET_STOP, true)) $freq[$t] = ($freq[$t] ?? 0) + 1;
    }
    arsort($freq);
    $topics = array_slice(array_keys($freq), 0, 6);
    $summary = $people . ' ' . ($people === 1 ? 'person' : 'people') . ' met for about ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.'
        . ($topics ? ' Main topics: ' . implode(', ', $topics) . '.' : ' Nothing was said in the transcript or chat.') . ($agenda !== '' ? ' Agenda: ' . strtok($agenda, "\n") : '');
    return ['summary' => $summary, 'decisions' => array_slice($decisions, 0, 12), 'actions' => array_slice($actions, 0, 15), 'questions' => array_slice($questions, 0, 8)];
}

/** The minutes as plain text. Pure. */
function meet_minutes_text(array $m, string $org = ''): string {
    $t = fn($ts) => date('H:i', (int)$ts);
    $L = ['MINUTES OF MEETING — ' . $m['title'], trim($org . ' · Video meetings', ' ·'), 'Date: ' . date('d M Y', (int)$m['start']) . '   Time: ' . $t($m['start']) . '–' . $t($m['end']) . ' (' . $m['minutesLong'] . ' min)   Host: ' . $m['host'], ''];
    $sec = function (string $head, array $rows) use (&$L) { if (!$rows) return; $L[] = $head; foreach ($rows as $r) $L[] = $r; $L[] = ''; };
    if ($m['agenda'] !== '') $sec('AGENDA', [$m['agenda']]);
    $sec('ATTENDEES', array_map(fn($a) => '- ' . $a['name'] . ' (' . $t($a['joined']) . ($a['left'] ? '–' . $t($a['left']) : '') . ')', $m['attendees']));
    $sec('SUMMARY', [$m['summary']]);
    $sec('DECISIONS', array_map(fn($d) => '- ' . $d, $m['decisions']));
    $sec('ACTION ITEMS', array_map(fn($a) => '- [' . ($a['owner'] ?: 'Unassigned') . '] ' . $a['task'] . ($a['due'] ? ' — due ' . $a['due'] : ''), $m['actions']));
    $sec('OPEN QUESTIONS', array_map(fn($q) => '- ' . $q, $m['questions']));
    $L[] = 'Automatic summary from ' . $m['capCount'] . ' transcript lines and ' . $m['chatCount'] . ' chat messages.';
    return implode("\n", $L);
}

// ---------- database ----------

function meet_user_name(PDO $db, int $id): string { $s = $db->prepare('SELECT name FROM users WHERE id = ?'); $s->execute([$id]); return (string)($s->fetchColumn() ?: 'Someone'); }
function meet_user_active(PDO $db, int $id): bool { $s = $db->prepare('SELECT active FROM users WHERE id = ?'); $s->execute([$id]); return (int)$s->fetchColumn() === 1; }
function meet_room(PDO $db, string $code): array {
    $s = $db->prepare('SELECT * FROM meet_room WHERE code = ?'); $s->execute([meet_code_parse($code)]);
    $r = $s->fetch();
    if (!$r) throw new RuntimeException('No meeting with this code. Check the code or ask the host for the link.');
    return $r;
}
function meet_member(PDO $db, int $roomId, int $uid): ?array { $s = $db->prepare('SELECT * FROM meet_member WHERE room_id = ? AND user_id = ?'); $s->execute([$roomId, $uid]); return $s->fetch() ?: null; }
function meet_need_mod(PDO $db, array $room, int $me): array {
    $m = meet_member($db, (int)$room['id'], $me);
    if (!meet_is_mod($m)) throw new RuntimeException('Only the host or a co-host can do that.');
    if ($room['ended_at']) throw new RuntimeException('This meeting has ended.');
    return $m;
}
function meet_need_in(PDO $db, array $room, int $me): array {
    $m = meet_member($db, (int)$room['id'], $me);
    if (!$m || $m['state'] !== 'in') throw new RuntimeException('Join the meeting first.');
    if ($room['ended_at']) throw new RuntimeException('This meeting has ended.');
    return $m;
}
function meet_bump(PDO $db, int $roomId): void { $db->prepare('UPDATE meet_room SET ver = ver + 1 WHERE id = ?')->execute([$roomId]); }
function meet_sys(PDO $db, int $roomId, string $text, int $now): void { $db->prepare("INSERT INTO meet_line (room_id, user_id, kind, body, created_at) VALUES (?,0,'sys',?,?)")->execute([$roomId, mb_substr($text, 0, 300), $now]); }
function meet_mail(PDO $db, int $uid, string $subject, string $text): void {
    if (!module_on('email') || !function_exists('mail_queue')) return;
    try { $s = $db->prepare('SELECT email FROM users WHERE id = ? AND active = 1'); $s->execute([$uid]); $to = (string)$s->fetchColumn();
          if (filter_var($to, FILTER_VALIDATE_EMAIL)) mail_queue($db, ['to' => $to, 'subject' => $subject, 'text' => $text], 'notice', 'meet'); } catch (Throwable $e) {}
}
function meet_link(string $code): string { return wn_site_url() . 'modules/meet/index.php?r=' . $code; }

/** Start a meeting. $in: title, agenda, open_join, auto_minutes, invite (user ids), ring. Returns the code. */
function meet_create(PDO $db, int $me, array $in, ?int $now = null): string {
    $now ??= time();
    if (!meet_user_active($db, $me)) throw new RuntimeException('Your login is not active.');
    $title = meet_clean($in['title'] ?? '', 120);
    if ($title === '') $title = meet_user_name($db, $me) . '’s meeting';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code = meet_code_make();
        try { $db->prepare('INSERT INTO meet_room (code, title, agenda, host_id, open_join, auto_minutes, created_at) VALUES (?,?,?,?,?,?,?)')
                 ->execute([$code, $title, meet_clean($in['agenda'] ?? '', 2000), $me, empty($in['open_join']) ? 0 : 1, array_key_exists('auto_minutes', $in) && !$in['auto_minutes'] ? 0 : 1, $now]); break; }
        catch (PDOException $e) { if ($i === 7) throw $e; }
    }
    $rid = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO meet_member (room_id, user_id, role, state) VALUES (?,?,'host','invited')")->execute([$rid, $me]);
    $users = array_values(array_unique(array_filter(array_map('intval', (array)($in['invite'] ?? [])), fn($x) => $x > 0 && $x !== $me)));
    if ($users) meet_invite($db, meet_room($db, $code), $me, $users, !empty($in['ring']), $now);
    return $code;
}

/** Add colleagues (they skip the waiting room); $ring = ring them now. Returns [rung names, invited names]. */
function meet_invite(PDO $db, array $room, int $me, array $users, bool $ring, ?int $now = null): array {
    $now ??= time();
    if ($room['ended_at']) throw new RuntimeException('This meeting has ended.');
    $mine = meet_member($db, (int)$room['id'], $me);
    if (!($mine && $mine['role'] === 'host') && !meet_is_mod($mine)) throw new RuntimeException('Only the host or a co-host can invite or ring.');
    $from = meet_user_name($db, $me);
    $rung = []; $added = [];
    foreach (array_slice(array_unique(array_map('intval', $users)), 0, 100) as $x) {
        if ($x <= 0 || $x === $me || !meet_user_active($db, $x)) continue;
        $m = meet_member($db, (int)$room['id'], $x);
        if ($m && in_array($m['state'], ['removed', 'in'], true)) continue;
        $state = $ring ? 'ringing' : 'invited';
        if ($m) $db->prepare('UPDATE meet_member SET state = ?, ring_at = ?, rung_by = ? WHERE room_id = ? AND user_id = ?')->execute([$state, $ring ? $now : null, $ring ? $me : null, $room['id'], $x]);
        else $db->prepare('INSERT INTO meet_member (room_id, user_id, state, ring_at, rung_by) VALUES (?,?,?,?,?)')->execute([$room['id'], $x, $state, $ring ? $now : null, $ring ? $me : null]);
        $name = meet_user_name($db, $x);
        if ($ring && module_on('notify')) { require_once __DIR__ . '/push.php'; notify_user($db, $x, 'meet', "$from is calling you", (string)$room['title'], 'modules/meet/index.php?r=' . $room['code']); }
        if ($ring) $rung[] = $name; else { $added[] = $name; meet_mail($db, $x, "$from invited you to a video meeting", $room['title'] . "\n\nJoin: " . meet_link((string)$room['code'])); }
    }
    meet_bump($db, (int)$room['id']);
    return [$rung, $added];
}

/** Join with the code. Returns 'in' or 'waiting'. */
function meet_join(PDO $db, int $me, string $code, ?int $now = null): string {
    $now ??= time();
    $room = meet_room($db, $code);
    if (!meet_user_active($db, $me)) throw new RuntimeException('Your login is not active.');
    meet_reap($db, $room, $now);
    $m = meet_member($db, (int)$room['id'], $me);
    $n = $db->prepare("SELECT COUNT(*) FROM meet_member WHERE room_id = ? AND state = 'in'"); $n->execute([(int)$room['id']]);
    $state = meet_join_decision($m, $room, (int)$n->fetchColumn(), (int)meet_settings()['max_in']);
    if ($m) $db->prepare("UPDATE meet_member SET state = ?, seen_at = ?, ring_at = NULL, in_at = IF(? = 'in', ?, in_at), joined_at = IF(? = 'in' AND joined_at IS NULL, ?, joined_at), left_at = NULL, mute_req = 0 WHERE room_id = ? AND user_id = ?")
               ->execute([$state, $now, $state, $now, $state, $now, $room['id'], $me]);
    else $db->prepare('INSERT INTO meet_member (room_id, user_id, state, seen_at, joined_at, in_at) VALUES (?,?,?,?,?,?)')->execute([$room['id'], $me, $state, $now, $state === 'in' ? $now : null, $state === 'in' ? $now : null]);
    if ($state === 'in') {
        if (!$room['started_at']) $db->prepare('UPDATE meet_room SET started_at = ? WHERE id = ?')->execute([$now, $room['id']]);
        if (!($m && $m['state'] === 'in')) meet_sys($db, (int)$room['id'], meet_user_name($db, $me) . ' joined', $now);
        meet_keep_mod($db, (int)$room['id'], $now);
    }
    meet_bump($db, (int)$room['id']);
    return $state;
}

function meet_drop_signals(PDO $db, int $roomId, int $uid): void { $db->prepare('DELETE FROM meet_signal WHERE room_id = ? AND (to_id = ? OR from_id = ?)')->execute([$roomId, $uid, $uid]); }

function meet_leave(PDO $db, int $me, string $code, ?int $now = null): void {
    $now ??= time();
    $room = meet_room($db, $code);
    $m = meet_member($db, (int)$room['id'], $me);
    if (!$m || !in_array($m['state'], ['in', 'waiting'], true)) return;
    $db->prepare('UPDATE meet_member SET state = ?, left_at = ?, hand = 0, sharing = 0 WHERE room_id = ? AND user_id = ?')->execute([$m['state'] === 'in' ? 'left' : 'invited', $now, $room['id'], $me]);
    if ($m['state'] === 'in') { meet_sys($db, (int)$room['id'], meet_user_name($db, $me) . ' left', $now); meet_keep_mod($db, (int)$room['id'], $now); }
    meet_drop_signals($db, (int)$room['id'], $me);
    meet_bump($db, (int)$room['id']);
}

/** People whose page stopped checking in have left. */
function meet_reap(PDO $db, array $room, int $now): void {
    $s = $db->prepare("SELECT user_id, state FROM meet_member WHERE room_id = ? AND state IN ('in','waiting') AND seen_at < ?");
    $s->execute([$room['id'], $now - MEET_GONE_SECS]);
    $gone = $s->fetchAll();
    if (!$gone) return;
    foreach ($gone as $g) {
        $db->prepare('UPDATE meet_member SET state = ?, left_at = ?, hand = 0, sharing = 0 WHERE room_id = ? AND user_id = ?')->execute([$g['state'] === 'in' ? 'left' : 'invited', $now, $room['id'], $g['user_id']]);
        if ($g['state'] === 'in') meet_sys($db, (int)$room['id'], meet_user_name($db, (int)$g['user_id']) . ' left', $now);
        meet_drop_signals($db, (int)$room['id'], (int)$g['user_id']);
    }
    meet_keep_mod($db, (int)$room['id'], $now);
    meet_bump($db, (int)$room['id']);
}

/** With no host / co-host in the room, the longest-present member becomes co-host. */
function meet_keep_mod(PDO $db, int $roomId, int $now): void {
    $s = $db->prepare("SELECT user_id, role FROM meet_member WHERE room_id = ? AND state = 'in' ORDER BY in_at, user_id"); $s->execute([$roomId]);
    $in = $s->fetchAll();
    if (!$in) return;
    foreach ($in as $x) if (in_array($x['role'], ['host', 'cohost'], true)) return;
    $db->prepare("UPDATE meet_member SET role = 'cohost' WHERE room_id = ? AND user_id = ?")->execute([$roomId, $in[0]['user_id']]);
    meet_sys($db, $roomId, meet_user_name($db, (int)$in[0]['user_id']) . ' is now co-host', $now);
}

function meet_admit(PDO $db, int $me, string $code, int $user, bool $yes, ?int $now = null): void {
    $now ??= time();
    $room = meet_room($db, $code); meet_need_mod($db, $room, $me);
    $m = meet_member($db, (int)$room['id'], $user);
    if (!$m || $m['state'] !== 'waiting') throw new RuntimeException('That person is no longer waiting.');
    if ($yes) {
        $db->prepare("UPDATE meet_member SET state = 'in', role = IF(role = 'denied', 'member', role), seen_at = ?, in_at = ?, joined_at = COALESCE(joined_at, ?) WHERE room_id = ? AND user_id = ?")->execute([$now, $now, $now, $room['id'], $user]);
        meet_sys($db, (int)$room['id'], meet_user_name($db, $user) . ' joined', $now);
    } else {
        $db->prepare("UPDATE meet_member SET state = 'declined' WHERE room_id = ? AND user_id = ?")->execute([$room['id'], $user]);
        $db->prepare("UPDATE meet_member SET role = 'denied' WHERE room_id = ? AND user_id = ? AND role = 'member' AND joined_at IS NULL")->execute([$room['id'], $user]);
    }
    meet_bump($db, (int)$room['id']);
}
function meet_remove(PDO $db, int $me, string $code, int $user, ?int $now = null): void {
    $now ??= time();
    $room = meet_room($db, $code); meet_need_mod($db, $room, $me);
    $m = meet_member($db, (int)$room['id'], $user);
    if (!$m || $user === $me) throw new RuntimeException('Choose someone else to remove.');
    if ($m['role'] === 'host') throw new RuntimeException('The host cannot be removed.');
    $db->prepare("UPDATE meet_member SET state = 'removed', role = 'member', left_at = ?, hand = 0, sharing = 0 WHERE room_id = ? AND user_id = ?")->execute([$now, $room['id'], $user]);
    meet_drop_signals($db, (int)$room['id'], $user);
    meet_sys($db, (int)$room['id'], meet_user_name($db, $user) . ' was removed by ' . meet_user_name($db, $me), $now);
    meet_bump($db, (int)$room['id']);
}
function meet_set_role(PDO $db, int $me, string $code, int $user, string $role): void {
    $room = meet_room($db, $code); meet_need_mod($db, $room, $me);
    $m = meet_member($db, (int)$room['id'], $user);
    if (!$m || $m['state'] !== 'in' || $m['role'] === 'host' || !in_array($role, ['cohost', 'member'], true)) throw new RuntimeException('That role cannot be changed.');
    $db->prepare('UPDATE meet_member SET role = ? WHERE room_id = ? AND user_id = ?')->execute([$role, $room['id'], $user]);
    meet_sys($db, (int)$room['id'], meet_user_name($db, $user) . ($role === 'cohost' ? ' is now co-host' : ' is no longer co-host'), time());
    meet_keep_mod($db, (int)$room['id'], time());
    meet_bump($db, (int)$room['id']);
}
/** Ask one person (or everyone else with 0) to mute; their page mutes itself on its next check. */
function meet_mute(PDO $db, int $me, string $code, int $user): void {
    $room = meet_room($db, $code); meet_need_mod($db, $room, $me);
    if ($user > 0) $db->prepare("UPDATE meet_member SET mute_req = 1 WHERE room_id = ? AND user_id = ? AND state = 'in'")->execute([$room['id'], $user]);
    else $db->prepare("UPDATE meet_member SET mute_req = 1 WHERE room_id = ? AND user_id <> ? AND state = 'in'")->execute([$room['id'], $me]);
    meet_bump($db, (int)$room['id']);
}
function meet_lower_hand(PDO $db, int $me, string $code, int $user): void {
    $room = meet_room($db, $code); meet_need_mod($db, $room, $me);
    $db->prepare('UPDATE meet_member SET hand = 0 WHERE room_id = ? AND user_id = ?')->execute([$room['id'], $user]);
    meet_bump($db, (int)$room['id']);
}
function meet_room_settings(PDO $db, int $me, string $code, array $in): void {
    $room = meet_room($db, $code); meet_need_mod($db, $room, $me);
    $set = []; $v = [];
    foreach (['open_join', 'locked', 'auto_minutes'] as $k) if (array_key_exists($k, $in)) { $set[] = "$k = ?"; $v[] = $in[$k] ? 1 : 0; }
    if (!$set) return;
    $v[] = $room['id'];
    $db->prepare('UPDATE meet_room SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($v);
    meet_bump($db, (int)$room['id']);
}

/** Rings for this login right now (any open page shows them). Also marks the login online. */
function meet_rings_for(PDO $db, int $me, ?int $now = null): array {
    $now ??= time(); $secs = (int)meet_settings()['ring_secs'];
    $db->prepare('INSERT INTO meet_presence (user_id, seen_at) VALUES (?,?) ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)')->execute([$me, $now]);
    $s = $db->prepare("SELECT r.code, r.title, m.rung_by, m.ring_at FROM meet_member m JOIN meet_room r ON r.id = m.room_id WHERE m.user_id = ? AND m.state = 'ringing' AND m.ring_at >= ? AND r.ended_at IS NULL ORDER BY m.ring_at DESC LIMIT 3");
    $s->execute([$me, $now - $secs]);
    return array_map(fn($r) => ['code' => $r['code'], 'title' => $r['title'], 'from' => meet_user_name($db, (int)$r['rung_by']), 'until' => (int)$r['ring_at'] + $secs], $s->fetchAll());
}
function meet_ring_answer(PDO $db, int $me, string $code, bool $accept, ?int $now = null): void {
    $now ??= time();
    $room = meet_room($db, $code);
    $m = meet_member($db, (int)$room['id'], $me);
    if (!$m || $m['state'] !== 'ringing') return;
    $db->prepare('UPDATE meet_member SET state = ?, ring_at = NULL WHERE room_id = ? AND user_id = ?')->execute([$accept ? 'invited' : 'declined', $room['id'], $me]);
    meet_sys($db, (int)$room['id'], meet_user_name($db, $me) . ($accept ? ' is joining' : ' declined the call'), $now);
    meet_bump($db, (int)$room['id']);
}

/** Colleagues for the invite / ring picker: name + department (+ title for leads, from Talk's rule), online, state here. */
function meet_people(PDO $db, int $me, ?array $room = null, ?int $now = null): array {
    $now ??= time();
    require_once __DIR__ . '/talk.php';
    $people = talk_people($db, $me);
    $on = array_flip(array_map('intval', $db->query('SELECT user_id FROM meet_presence WHERE seen_at >= ' . ($now - 40))->fetchAll(PDO::FETCH_COLUMN)));
    $st = [];
    if ($room) { $q = $db->prepare('SELECT user_id, state FROM meet_member WHERE room_id = ?'); $q->execute([(int)$room['id']]); foreach ($q->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $st[(int)$k] = $v; }
    return array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'title' => $p['title'] ?? '', 'dept' => $p['dept'] ?? '', 'online' => isset($on[(int)$p['id']]), 'state' => $st[(int)$p['id']] ?? ''], $people);
}

function meet_state(PDO $db, int $me, string $code, array $in): void {
    $room = meet_room($db, $code); meet_need_in($db, $room, $me);
    $set = []; $v = [];
    foreach (['mic', 'cam', 'hand', 'sharing'] as $k) if (array_key_exists($k, $in)) { $set[] = "$k = ?"; $v[] = $in[$k] ? 1 : 0; }
    if (array_key_exists('mic', $in)) $set[] = 'mute_req = 0';
    if (!$set) return;
    $v[] = $room['id']; $v[] = $me;
    $db->prepare('UPDATE meet_member SET ' . implode(', ', $set) . ' WHERE room_id = ? AND user_id = ?')->execute($v);
    meet_bump($db, (int)$room['id']);
}

function meet_signal(PDO $db, int $me, string $code, int $to, $data, ?int $now = null): void {
    $now ??= time();
    $room = meet_room($db, $code); meet_need_in($db, $room, $me);
    $t = meet_member($db, (int)$room['id'], $to);
    if (!$t || $t['state'] !== 'in' || $to === $me) throw new RuntimeException('That person is not in the meeting.');
    if (!meet_signal_ok($data)) throw new RuntimeException('Bad connection message.');
    $db->prepare('INSERT INTO meet_signal (room_id, from_id, to_id, data, created_at) VALUES (?,?,?,?,?)')->execute([$room['id'], $me, $to, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now]);
}

function meet_say(PDO $db, int $me, string $code, string $kind, string $text, string $lang = '', ?int $now = null): int {
    $now ??= time();
    $room = meet_room($db, $code); $m = meet_need_in($db, $room, $me);
    $text = meet_say_text($kind, $text, (bool)$m['mic']);
    $db->prepare('INSERT INTO meet_line (room_id, user_id, kind, body, lang, created_at) VALUES (?,?,?,?,?,?)')
       ->execute([$room['id'], $me, $kind, $text, isset(meet_langs(meet_settings())[$lang]) ? $lang : '', $now]);
    return (int)$db->lastInsertId();
}

function meet_lines(PDO $db, int $roomId, int $after = 0, int $limit = 400): array {
    $s = $db->prepare("SELECT l.id, l.user_id, l.kind, l.body, l.created_at, COALESCE(u.name, '') AS name FROM meet_line l LEFT JOIN users u ON u.id = l.user_id WHERE l.room_id = ? AND l.id > ? ORDER BY l.id LIMIT " . max(1, min(5000, $limit)));
    $s->execute([$roomId, $after]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'uid' => (int)$r['user_id'], 'kind' => $r['kind'], 'text' => $r['body'], 'name' => $r['name'], 'ts' => (int)$r['created_at']], $s->fetchAll());
}

function meet_members_view(PDO $db, int $roomId): array {
    $s = $db->prepare("SELECT m.user_id, m.role, m.state, m.mic, m.cam, m.hand, m.sharing, m.in_at, u.name FROM meet_member m JOIN users u ON u.id = m.user_id
                       WHERE m.room_id = ? AND m.state IN ('in','waiting','ringing','invited','declined') ORDER BY FIELD(m.role,'host','cohost','member'), m.in_at, u.name");
    $s->execute([$roomId]);
    return array_map(fn($r) => ['id' => (int)$r['user_id'], 'name' => $r['name'], 'role' => $r['role'] === 'denied' ? 'member' : $r['role'], 'state' => $r['state'],
        'mic' => (bool)$r['mic'], 'cam' => (bool)$r['cam'], 'hand' => (bool)$r['hand'], 'sharing' => (bool)$r['sharing'], 'since' => $r['in_at'] ? (int)$r['in_at'] : null], $s->fetchAll());
}
function meet_room_view(array $r): array {
    return ['code' => $r['code'], 'title' => $r['title'], 'agenda' => (string)$r['agenda'], 'host' => (int)$r['host_id'], 'openJoin' => (bool)$r['open_join'], 'locked' => (bool)$r['locked'],
            'autoMinutes' => (bool)$r['auto_minutes'], 'started' => $r['started_at'] ? (int)$r['started_at'] : null, 'ended' => $r['ended_at'] ? (int)$r['ended_at'] : null];
}

/** One check from an open meeting page: heartbeat, then what changed. Connection messages are handed over once. */
function meet_poll(PDO $db, int $me, string $code, int $after = 0, int $ver = 0, ?int $now = null): array {
    $now ??= time();
    $room = meet_room($db, $code);
    $m = meet_member($db, (int)$room['id'], $me);
    if (!$m) throw new RuntimeException('You are not in this meeting.');
    if ($m['state'] === 'removed') return ['state' => 'removed'];
    if ($room['ended_at']) return ['state' => 'ended', 'minutes' => (bool)$room['minutes']];
    if (in_array($m['state'], ['in', 'waiting'], true)) $db->prepare('UPDATE meet_member SET seen_at = ? WHERE room_id = ? AND user_id = ?')->execute([$now, $room['id'], $me]);
    $db->prepare('INSERT INTO meet_presence (user_id, seen_at) VALUES (?,?) ON DUPLICATE KEY UPDATE seen_at = VALUES(seen_at)')->execute([$me, $now]);
    meet_reap($db, $room, $now);
    $room = meet_room($db, $code);
    $m = meet_member($db, (int)$room['id'], $me);
    if ($m['state'] !== 'in') return ['state' => $m['state'], 'title' => $room['title']];
    $out = ['state' => 'in', 'ver' => (int)$room['ver'], 'now' => $now, 'muteReq' => (bool)$m['mute_req'], 'role' => $m['role']];
    if ((int)$room['ver'] !== $ver) { $out['room'] = meet_room_view($room); $out['members'] = meet_members_view($db, (int)$room['id']); }
    $out['lines'] = meet_lines($db, (int)$room['id'], $after);
    $db->beginTransaction();
    $s = $db->prepare('SELECT id, from_id, data FROM meet_signal WHERE room_id = ? AND to_id = ? ORDER BY id FOR UPDATE'); $s->execute([$room['id'], $me]);
    $sig = $s->fetchAll();
    if ($sig) $db->prepare('DELETE FROM meet_signal WHERE room_id = ? AND to_id = ? AND id <= ?')->execute([$room['id'], $me, end($sig)['id']]);
    $db->commit();
    $out['signals'] = array_map(fn($x) => ['from' => (int)$x['from_id'], 'data' => json_decode($x['data'], true)], $sig);
    return $out;
}

/** Before joining: title, host and whether it is still on. Anyone signed in with the code may see this much. */
function meet_info(PDO $db, int $me, string $code): array {
    $room = meet_room($db, $code);
    $m = meet_member($db, (int)$room['id'], $me);
    return ['code' => $room['code'], 'title' => $room['title'], 'host' => meet_user_name($db, (int)$room['host_id']), 'ended' => (bool)$room['ended_at'],
            'removed' => $m && $m['state'] === 'removed', 'minutes' => (bool)$room['minutes'] && meet_minutes_access($db, $room, $me), 'invited' => $m !== null];
}

/** The login's open meetings and the last 20 ended ones they attended. */
function meet_my_list(PDO $db, int $me): array {
    $s = $db->prepare("SELECT r.code, r.title, r.host_id, r.started_at, r.ended_at, r.created_at, r.minutes IS NOT NULL AS has_min, m.state, m.role,
                       (SELECT COUNT(*) FROM meet_member x WHERE x.room_id = r.id AND x.state = 'in') AS n_in
                       FROM meet_member m JOIN meet_room r ON r.id = m.room_id WHERE m.user_id = ? AND m.state <> 'removed' AND (r.ended_at IS NULL OR m.joined_at IS NOT NULL)
                       ORDER BY r.ended_at IS NULL DESC, COALESCE(r.ended_at, r.created_at) DESC LIMIT 40");
    $s->execute([$me]);
    $open = []; $past = [];
    foreach ($s->fetchAll() as $r) {
        $row = ['code' => $r['code'], 'title' => $r['title'], 'host' => meet_user_name($db, (int)$r['host_id']), 'role' => $r['role'], 'state' => $r['state'], 'in' => (int)$r['n_in'],
                'started' => $r['started_at'] ? (int)$r['started_at'] : null, 'ended' => $r['ended_at'] ? (int)$r['ended_at'] : null, 'created' => (int)$r['created_at'], 'minutes' => (bool)$r['has_min']];
        if ($r['ended_at']) { if (count($past) < 20) $past[] = $row; }
        elseif ((int)$r['created_at'] >= time() - 86400 * 2 || (int)$r['n_in'] > 0) $open[] = $row;
    }
    return ['open' => $open, 'past' => $past];
}

/** End for everyone (moderator): minutes made and, if left on, emailed to attendees. */
function meet_end(PDO $db, int $me, string $code, ?int $now = null): array {
    $now ??= time();
    $room = meet_room($db, $code); meet_need_mod($db, $room, $me);
    $db->prepare('UPDATE meet_room SET ended_at = ? WHERE id = ?')->execute([$now, $room['id']]);
    $db->prepare("UPDATE meet_member SET state = 'left', left_at = ? WHERE room_id = ? AND state = 'in'")->execute([$now, $room['id']]);
    $db->prepare("UPDATE meet_member SET state = 'invited', ring_at = NULL WHERE room_id = ? AND state IN ('ringing','waiting')")->execute([$room['id']]);
    $db->prepare('DELETE FROM meet_signal WHERE room_id = ?')->execute([$room['id']]);
    meet_sys($db, (int)$room['id'], 'Meeting ended by ' . meet_user_name($db, $me), $now);
    meet_bump($db, (int)$room['id']);
    $room = meet_room($db, $code);
    $min = meet_minutes_build($db, $room, $now);
    if ($room['auto_minutes']) meet_minutes_send($db, $room, $min);
    return $min;
}

function meet_minutes_access(PDO $db, array $room, int $me): bool {
    $m = meet_member($db, (int)$room['id'], $me);
    return $m && $m['state'] !== 'removed' && ($m['joined_at'] !== null || $m['role'] === 'host');
}
function meet_minutes_get(PDO $db, int $me, string $code): ?array {
    $room = meet_room($db, $code);
    if (!meet_minutes_access($db, $room, $me)) throw new RuntimeException('Only people who were in this meeting can read its minutes.');
    $m = json_decode((string)$room['minutes'], true);
    return is_array($m) ? $m : null;
}
function meet_minutes_build(PDO $db, array $room, ?int $now = null): array {
    $now ??= time();
    $talk = array_values(array_filter(meet_lines($db, (int)$room['id'], 0, 5000), fn($l) => in_array($l['kind'], ['chat', 'cap'], true)));
    $a = $db->prepare('SELECT u.name, m.role, m.joined_at, m.left_at FROM meet_member m JOIN users u ON u.id = m.user_id WHERE m.room_id = ? AND m.joined_at IS NOT NULL ORDER BY m.joined_at');
    $a->execute([$room['id']]);
    $att = array_map(fn($x) => ['name' => $x['name'], 'role' => $x['role'], 'joined' => (int)$x['joined_at'], 'left' => $x['left_at'] ? (int)$x['left_at'] : null], $a->fetchAll());
    $start = (int)($room['started_at'] ?: $room['created_at']); $end = (int)($room['ended_at'] ?: $now); $mins = max(1, (int)round(($end - $start) / 60));
    $min = meet_minutes_auto($talk, count($att), $mins, (string)$room['agenda']) + ['title' => $room['title'], 'agenda' => (string)$room['agenda'], 'host' => meet_user_name($db, (int)$room['host_id']),
        'start' => $start, 'end' => $end, 'minutesLong' => $mins, 'attendees' => $att, 'chatCount' => count(array_filter($talk, fn($l) => $l['kind'] === 'chat')),
        'capCount' => count(array_filter($talk, fn($l) => $l['kind'] === 'cap')), 'made' => $now, 'final' => (bool)$room['ended_at']];
    $db->prepare('UPDATE meet_room SET minutes = ?, minutes_at = ? WHERE id = ?')->execute([json_encode($min, JSON_UNESCAPED_UNICODE), $now, $room['id']]);
    return $min;
}
/** Email the minutes to everyone who attended (when Email is on). Returns how many were sent. */
function meet_minutes_send(PDO $db, array $room, array $min): int {
    if (!module_on('email')) return 0;
    $s = $db->prepare("SELECT user_id FROM meet_member WHERE room_id = ? AND state <> 'removed' AND (joined_at IS NOT NULL OR role = 'host')"); $s->execute([$room['id']]);
    $n = 0; $txt = meet_minutes_text($min, org('name'));
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $x) { meet_mail($db, (int)$x, 'Minutes: ' . $room['title'], $txt); $n++; }
    return $n;
}
