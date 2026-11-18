<?php
// Announcement board — rules. Announcements and pictures, birthdays (day and month only), who is on leave today
// (names only), the viewer's own holidays, daily challenges. Pure functions first, then the database half.
declare(strict_types=1);
require_once __DIR__ . '/holidays.php';

const BOARD_KINDS = ['news' => 'Announcement', 'photos' => 'Pictures'];
const BOARD_FILE_BYTES = 15 * 1024 * 1024;
const BOARD_MAX_FILES = ['news' => 6, 'photos' => 24];
const BOARD_PHOTO_FILES = 3;
const BOARD_PHOTO_MODES = ['none' => 'Photo not needed', 'optional' => 'Photo optional', 'required' => 'Photo needed'];
const BOARD_DEFAULTS = ['post_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO'], 'moderate_roles' => ['SUPERADMIN', 'HRMANAGER'],
                        'birthdays' => true, 'anniversaries' => false, 'leave' => true, 'days_ahead' => 7, 'keep_days' => 120];

function board_settings(): array {
    $s = module_states()['board']['settings'] ?? [];
    return array_replace(BOARD_DEFAULTS, array_intersect_key($s, BOARD_DEFAULTS));
}

function board_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['post_roles', 'moderate_roles'] as $k) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = 'Pick at least one role for ' . ($k === 'post_roles' ? 'posting' : 'removing posts') . '.';
    }
    foreach (['birthdays', 'anniversaries', 'leave'] as $k) $o[$k] = !empty($in[$k]);
    $o['days_ahead'] = max(0, min(31, (int)($in['days_ahead'] ?? 7)));
    $o['keep_days'] = max(14, min(730, (int)($in['keep_days'] ?? 120)));
    return [$o, $e];
}

/** Rights from roles. Pure. Moderators can always post. */
function board_rights_for(array $roles, array $set): array {
    $mod = (bool)array_intersect($roles, $set['moderate_roles']);
    return ['post' => $mod || (bool)array_intersect($roles, $set['post_roles']), 'moderate' => $mod];
}
function board_rights(array $u): array { return board_rights_for(user_roles((int)$u['id'], !empty($u['is_owner'])), board_settings()); }

/** What a file really is, from its own bytes (+ the ending for Office). [type, is_image] or null. Pure. */
function board_file_type(string $b, string $name): ?array {
    if (str_starts_with($b, "\xFF\xD8\xFF")) return ['image/jpeg', true];
    if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) return ['image/png', true];
    if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') return ['image/webp', true];
    if (substr($b, 4, 4) === 'ftyp' && preg_match('/^(heic|heix|hevc|mif1|msf1)$/', substr($b, 8, 4))) return ['image/heic', true];
    if (str_starts_with($b, '%PDF-')) return ['application/pdf', false];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ox = ['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
           'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    if (str_starts_with($b, "PK\x03\x04") && isset($ox[$ext])) return [$ox[$ext], false];
    $ole = ['doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint'];
    if (str_starts_with($b, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") && isset($ole[$ext])) return [$ole[$ext], false];
    if (in_array($ext, ['txt', 'csv'], true) && !preg_match('/[\x00-\x08\x0E-\x1F]/', substr($b, 0, 4096)) && mb_check_encoding($b, 'UTF-8')) return [$ext === 'csv' ? 'text/csv' : 'text/plain', false];
    return null;
}

/** Clean a post. Returns [post, error]. $files = list of [bytes, name]. Pure. */
function board_post_check(array $in, array $files): array {
    $kind = isset(BOARD_KINDS[$in['kind'] ?? '']) ? (string)$in['kind'] : 'news';
    $p = ['kind' => $kind, 'title' => mb_substr(trim((string)preg_replace('/\s+/u', ' ', (string)($in['title'] ?? ''))), 0, 140),
          'body' => mb_substr(trim(str_replace("\r", '', (string)($in['body'] ?? ''))), 0, 4000),
          'pinned' => $kind === 'news' && !empty($in['pinned']), 'files' => []];
    if ($p['title'] === '') return [$p, $kind === 'photos' ? 'Give the pictures a title.' : 'Give the announcement a title.'];
    if (count($files) > BOARD_MAX_FILES[$kind]) return [$p, 'Up to ' . BOARD_MAX_FILES[$kind] . ' files in one post.'];
    foreach ($files as [$bytes, $name]) {
        if ($bytes === '' ) continue;
        if (strlen($bytes) > BOARD_FILE_BYTES) return [$p, 'Files can be up to 15 MB.'];
        $name = mb_substr((string)preg_replace('/[^\pL\pN ._()-]/u', '_', (string)$name) ?: 'file', 0, 180);
        $t = board_file_type($bytes, $name);
        if (!$t) return [$p, 'Only photos, PDF, Word, Excel, PowerPoint, text or CSV files.'];
        if ($kind === 'photos' && !$t[1]) return [$p, 'Pictures posts take photos only — attach documents to an announcement.'];
        $p['files'][] = [$bytes, $name, $t];
    }
    if ($kind === 'photos' && !$p['files']) return [$p, 'Add at least one picture.'];
    return [$p, ''];
}

/** "MM-DD" from a date, or null. Pure. */
function board_md(?string $d): ?string {
    if (!$d || !preg_match('/^\d{4}-(\d{2})-(\d{2})/', $d, $m) || !checkdate((int)$m[1], (int)$m[2], 2024)) return null;
    return $m[1] . '-' . $m[2];
}

/** Days from today until this MM-DD comes round (0 = today). 29 Feb counts on 28 Feb in other years. Pure. */
function board_days_until(string $md, string $today): int {
    $y = (int)substr($today, 0, 4);
    for ($i = 0; $i < 2; $i++) {
        [$mo, $d] = array_map('intval', explode('-', $md));
        if ($mo === 2 && $d === 29 && !checkdate(2, 29, $y + $i)) $d = 28;
        $day = sprintf('%04d-%02d-%02d', $y + $i, $mo, $d);
        if ($day >= $today) return (int)((strtotime($day) - strtotime($today)) / 86400);
    }
    return 999;
}

/**
 * Birthdays (and anniversaries) within $ahead days — name, department, designation, branch, day-month. Never years,
 * ages or codes. $people = list of [name, dept, title, branch, dob, anniversary]. Pure.
 */
function board_celebrations(array $people, string $today, int $ahead, bool $anniv): array {
    $out = [];
    foreach ($people as $p) {
        foreach (['birthday' => $p['dob'] ?? null, 'anniversary' => $anniv ? ($p['anniversary'] ?? null) : null] as $kind => $d) {
            $md = board_md($d);
            if ($md === null) continue;
            $in = board_days_until($md, $today);
            if ($in > $ahead) continue;
            $out[] = ['kind' => $kind, 'name' => (string)$p['name'], 'dept' => (string)($p['dept'] ?? ''), 'title' => (string)($p['title'] ?? ''),
                      'branch' => (string)($p['branch'] ?? ''), 'md' => $md, 'in' => $in];
        }
    }
    usort($out, fn($a, $b) => [$a['in'], $a['name']] <=> [$b['in'], $b['name']]);
    return $out;
}

/** "18 Nov" from MM-DD. Pure. */
function board_md_label(string $md): string { return (int)substr($md, 3) . ' ' . date('M', mktime(0, 0, 0, (int)substr($md, 0, 2), 1, 2024)); }

/** Clean a challenge. Returns [challenge, error]. Pure. */
function board_challenge_check(array $in, array $branchIds, array $depts, string $today): array {
    $c = ['title' => mb_substr(trim((string)($in['title'] ?? '')), 0, 90), 'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 300),
          'from_day' => (string)($in['from_day'] ?? ''), 'to_day' => (string)($in['to_day'] ?? ''),
          'photo' => isset(BOARD_PHOTO_MODES[$in['photo'] ?? '']) ? (string)$in['photo'] : 'none'];
    $c['branches'] = array_values(array_intersect(array_map('intval', (array)($in['branches'] ?? [])), $branchIds));
    $c['depts'] = array_values(array_intersect((array)($in['depts'] ?? []), $depts));
    $c['people'] = array_values(array_unique(array_filter(array_map(fn($x) => strtoupper(trim($x)), preg_split('/[\s,;]+/', (string)($in['people'] ?? '')) ?: []), fn($x) => preg_match('/^[A-Z0-9_\/-]{1,32}$/', $x))));
    if ($c['title'] === '') return [$c, 'Give the challenge a title.'];
    $ok = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
    if (!$ok($c['from_day'])) return [$c, 'Pick the day it starts.'];
    if (!$ok($c['to_day']) || $c['to_day'] < $c['from_day']) $c['to_day'] = $c['from_day'];
    if ($c['to_day'] < $today) return [$c, 'That challenge would already be over.'];
    return [$c, ''];
}

/** Is a challenge for this person? $p = [code, branch_id, dept]. Pure. */
function board_challenge_for(array $c, array $p): bool {
    $b = array_filter(array_map('intval', explode(',', (string)$c['aud_branches'])));
    $d = array_filter(explode('|', (string)$c['aud_depts']), fn($x) => $x !== '');
    $pp = array_filter(explode(',', (string)$c['aud_people']), fn($x) => $x !== '');
    if (!$b && !$d && !$pp) return true;
    $code = strtoupper((string)($p['code'] ?? ''));
    return ($code !== '' && in_array($code, $pp, true)) || (($p['branch_id'] ?? null) !== null && in_array((int)$p['branch_id'], $b, true))
        || (($p['dept'] ?? '') !== '' && in_array(mb_strtolower((string)$p['dept']), array_map('mb_strtolower', $d), true));
}

/** "Who it is for" — employees never see other people's codes. Pure. */
function board_challenge_label(array $c, bool $full, array $branchNames = []): string {
    $b = array_filter(array_map('intval', explode(',', (string)$c['aud_branches'])));
    $d = array_filter(explode('|', (string)$c['aud_depts']), fn($x) => $x !== '');
    $pp = array_filter(explode(',', (string)$c['aud_people']), fn($x) => $x !== '');
    if (!$b && !$d && !$pp) return 'Everyone';
    $bits = [];
    if ($b) $bits[] = implode(', ', array_map(fn($i) => $branchNames[$i] ?? ('#' . $i), $b));
    if ($d) $bits[] = implode(', ', $d);
    if ($pp) $bits[] = $full ? implode(', ', $pp) : 'chosen people';
    return implode(' · ', $bits);
}

// ---------- database ----------

function board_me(PDO $db, array $u): array {
    $code = (string)($u['emp_code'] ?? '');
    $e = $code !== '' ? emp_by_code($db, $code) : null;
    return ['code' => $code, 'branch_id' => $e && $e['branch_id'] !== null ? (int)$e['branch_id'] : null, 'dept' => (string)($e['department'] ?? ''), 'emp' => $e];
}

function board_create(PDO $db, array $u, array $in, array $files): int {
    if (!board_rights($u)['post']) throw new RuntimeException('Your role cannot post on the board.');
    [$p, $err] = board_post_check($in, $files);
    if ($err !== '') throw new RuntimeException($err);
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO board_post (kind, title, body, pinned, author_id) VALUES (?,?,?,?,?)')->execute([$p['kind'], $p['title'], $p['body'], $p['pinned'] ? 1 : 0, (int)$u['id']]);
        $id = (int)$db->lastInsertId();
        foreach ($p['files'] as $i => [$bytes, $name, $t]) board_file_put($db, $u, $bytes, $name, $t, $id, null, $i);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'board_post', '#' . $id . ' files ' . count($p['files']));
    return $id;
}

function board_file_put(PDO $db, array $u, string $bytes, string $name, array $t, ?int $post, ?int $done, int $ord): string {
    $fid = bin2hex(random_bytes(16));
    $s = $db->prepare('INSERT INTO board_file (id, post_id, challenge_done_id, uploader_id, name, type, size, is_image, ord, data) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ([$fid, $post, $done, (int)$u['id'], $name, $t[0], strlen($bytes), $t[1] ? 1 : 0, $ord] as $i => $v) $s->bindValue($i + 1, $v);
    $s->bindValue(10, $bytes, PDO::PARAM_LOB);
    $s->execute();
    return $fid;
}

function board_post_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT * FROM board_post WHERE id = ? AND removed_at IS NULL'); $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** Remove (author or moderator). Kept, never deleted; its files stop opening. */
function board_remove(PDO $db, array $u, int $id): void {
    $p = board_post_get($db, $id);
    if (!$p) throw new RuntimeException('Post not found.');
    if ((int)$p['author_id'] !== (int)$u['id'] && !board_rights($u)['moderate']) throw new RuntimeException('Only the author or a moderator can remove this post.');
    $db->prepare('UPDATE board_post SET removed_at = NOW(), removed_by = ? WHERE id = ?')->execute([(int)$u['id'], $id]);
    audit((int)$u['id'], 'board_remove', '#' . $id);
}

function board_pin(PDO $db, array $u, int $id, bool $on): void {
    $p = board_post_get($db, $id);
    if (!$p || $p['kind'] !== 'news') throw new RuntimeException('Announcement not found.');
    if ((int)$p['author_id'] !== (int)$u['id'] && !board_rights($u)['moderate']) throw new RuntimeException('Only the author or a moderator can pin this.');
    $db->prepare('UPDATE board_post SET pinned = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
}

/** Posts for the page: pinned ones, plus the last keep_days days, newest first (at most 60). */
function board_list(PDO $db, array $u, array $set): array {
    $s = $db->prepare('SELECT p.*, us.name AS by_name FROM board_post p LEFT JOIN users us ON us.id = p.author_id
                       WHERE p.removed_at IS NULL AND (p.pinned = 1 OR p.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY))
                       ORDER BY p.pinned DESC, p.created_at DESC, p.id DESC LIMIT 60');
    $s->execute([(int)$set['keep_days']]);
    $rows = $s->fetchAll();
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $f = $db->query('SELECT id, post_id, name, type, size, is_image FROM board_file WHERE post_id IN (' . implode(',', $ids) . ') ORDER BY post_id, ord')->fetchAll();
    $files = [];
    foreach ($f as $x) $files[(int)$x['post_id']][] = $x;
    $mod = board_rights($u)['moderate'];
    foreach ($rows as &$r) { $r['files'] = $files[(int)$r['id']] ?? []; $r['can_remove'] = $mod || (int)$r['author_id'] === (int)$u['id']; }
    return $rows;
}

/**
 * A file the viewer may open: a live post's file (any signed-in login while the module is on), or a challenge photo
 * (its owner, or a moderator / poster).
 */
function board_file_for(PDO $db, array $u, string $id): ?array {
    if (!preg_match('/^[0-9a-f]{32}$/', $id)) return null;
    $s = $db->prepare('SELECT f.*, p.removed_at AS p_removed, d.user_id AS d_user FROM board_file f LEFT JOIN board_post p ON p.id = f.post_id
                       LEFT JOIN board_challenge_done d ON d.id = f.challenge_done_id WHERE f.id = ?');
    $s->execute([$id]);
    $f = $s->fetch();
    if (!$f) return null;
    if ($f['post_id'] !== null) return $f['p_removed'] === null ? $f : null;
    if ($f['challenge_done_id'] !== null) {
        $r = board_rights($u);
        return ((int)$f['d_user'] === (int)$u['id'] || $r['post']) ? $f : null;
    }
    return null;
}

/** Everyone on the roll for the celebrations list. Never codes or years leave this function. */
function board_people(PDO $db): array {
    $names = []; foreach (branches(false) as $b) $names[(int)$b['id']] = (string)$b['name'];
    $prof = [];
    try { foreach ($db->query('SELECT emp_code, data FROM employee_profile') as $p) { $d = json_decode((string)$p['data'], true); if (is_array($d)) $prof[strtoupper($p['emp_code'])] = $d; } } catch (Throwable $e) {}
    $out = [];
    $today = date('Y-m-d');
    foreach ($db->query('SELECT code, name, department, designation, branch_id, dob, last_day FROM employee WHERE active = 1') as $e) {
        if ($e['last_day'] !== null && (string)$e['last_day'] < $today) continue;
        $pr = $prof[strtoupper($e['code'])] ?? [];
        $out[] = ['name' => $e['name'], 'dept' => $e['department'], 'title' => $e['designation'], 'branch' => $names[(int)$e['branch_id']] ?? '',
                  'dob' => $e['dob'] ?: ($pr['dob'] ?? null), 'anniversary' => ($pr['marital'] ?? '') === 'Married' ? ($pr['anniversary'] ?? null) : null];
    }
    return $out;
}

/**
 * Approved leave today: name, branch and part of the day only — never the reason, type or notes.
 * @return list<array{name:string,branch:string,part:string}>
 */
function board_on_leave(PDO $db, string $today): array {
    $names = []; foreach (branches(false) as $b) $names[(int)$b['id']] = (string)$b['name'];
    try {
        $s = $db->prepare("SELECT l.from_date, l.from_session, l.to_date, l.to_session, l.time_from, e.name, e.branch_id FROM leave_request l
                           JOIN employee e ON e.code = l.emp_code WHERE l.status = 'approved' AND ? BETWEEN l.from_date AND l.to_date ORDER BY e.name");
        $s->execute([$today]);
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($s as $r) {
        $part = 'Full day';
        if ($r['time_from'] !== null) $part = 'Part of the day';
        elseif ($r['from_date'] === $today && $r['from_session'] === 'second') $part = 'Second half';
        elseif ($r['to_date'] === $today && $r['to_session'] === 'first') $part = 'First half';
        $out[] = ['name' => (string)$r['name'], 'branch' => $names[(int)$r['branch_id']] ?? '', 'part' => $part];
    }
    return $out;
}

/** The viewer's own holidays from today for 60 days (their branch's calendar). */
function board_holidays(PDO $db, array $me, string $today): array {
    if (!module_on('holidays')) return [];
    return hol_days($db, $me['code'] ?: '-', $me['branch_id'], $today, date('Y-m-d', strtotime($today . ' +60 days')));
}

function board_challenge_add(PDO $db, array $u, array $in): int {
    if (!board_rights($u)['post']) throw new RuntimeException('Your role cannot set challenges.');
    $bids = array_map(fn($b) => (int)$b['id'], branches());
    $depts = $db->query("SELECT DISTINCT department FROM employee WHERE department <> ''")->fetchAll(PDO::FETCH_COLUMN);
    [$c, $err] = board_challenge_check($in, $bids, $depts, date('Y-m-d'));
    if ($err !== '') throw new RuntimeException($err);
    $db->prepare('INSERT INTO board_challenge (title, note, from_day, to_day, aud_branches, aud_depts, aud_people, photo, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$c['title'], $c['note'], $c['from_day'], $c['to_day'], implode(',', $c['branches']), implode('|', $c['depts']), implode(',', $c['people']), $c['photo'], (int)$u['id']]);
    $id = (int)$db->lastInsertId();
    audit((int)$u['id'], 'board_challenge', '#' . $id);
    return $id;
}

function board_challenge_off(PDO $db, array $u, int $id): void {
    if (!board_rights($u)['post']) throw new RuntimeException('Your role cannot change challenges.');
    $db->prepare('UPDATE board_challenge SET removed_at = NOW(), removed_by = ? WHERE id = ? AND removed_at IS NULL')->execute([(int)$u['id'], $id]);
}

/** Challenges: for everyone, those open from 7 days ago meant for them; for posters, all from 30 days ago with counts. */
function board_challenges(PDO $db, array $u, array $me, string $today): array {
    $full = board_rights($u)['post'];
    $s = $db->prepare('SELECT * FROM board_challenge WHERE removed_at IS NULL AND to_day >= ? ORDER BY from_day DESC, id DESC LIMIT 60');
    $s->execute([date('Y-m-d', strtotime($today . ($full ? ' -30 days' : ' -7 days')))]);
    $rows = $s->fetchAll();
    $names = []; foreach (branches(false) as $b) $names[(int)$b['id']] = (string)$b['name'];
    $d = $db->prepare('SELECT id, done_at FROM board_challenge_done WHERE challenge_id = ? AND user_id = ?');
    $out = [];
    foreach ($rows as $c) {
        $mine = board_challenge_for($c, $me);
        if (!$mine && !$full) continue;
        if (!$full && (string)$c['from_day'] > $today) continue;
        $d->execute([(int)$c['id'], (int)$u['id']]); $done = $d->fetch() ?: null;
        $c['mine'] = $mine; $c['done'] = $done; $c['label'] = board_challenge_label($c, $full, $names);
        $c['open'] = (string)$c['from_day'] <= $today && (string)$c['to_day'] >= $today;
        if ($full) {
            $q = $db->prepare('SELECT u.name, d.done_at, (SELECT COUNT(*) FROM board_file f WHERE f.challenge_done_id = d.id) AS photos FROM board_challenge_done d JOIN users u ON u.id = d.user_id WHERE d.challenge_id = ? ORDER BY d.done_at');
            $q->execute([(int)$c['id']]); $c['who'] = $q->fetchAll();
        }
        if ($done) { $q = $db->prepare('SELECT id, name FROM board_file WHERE challenge_done_id = ? ORDER BY ord'); $q->execute([(int)$done['id']]); $c['photos'] = $q->fetchAll(); }
        $out[] = $c;
    }
    return $out;
}

/** Mark a challenge done (own record), with up to 3 photos. Only one meant for them and open today. */
function board_challenge_done(PDO $db, array $u, array $me, int $id, array $files, string $today): void {
    $s = $db->prepare('SELECT * FROM board_challenge WHERE id = ? AND removed_at IS NULL'); $s->execute([$id]);
    $c = $s->fetch();
    if (!$c || !board_challenge_for($c, $me)) throw new RuntimeException('Challenge not found.');
    if ((string)$c['from_day'] > $today || (string)$c['to_day'] < $today) throw new RuntimeException('This challenge is not open today.');
    $typed = [];
    foreach ($files as [$bytes, $name]) {
        if ($bytes === '') continue;
        if (strlen($bytes) > BOARD_FILE_BYTES) throw new RuntimeException('Photos can be up to 15 MB.');
        $t = board_file_type($bytes, (string)$name);
        if (!$t || !$t[1]) throw new RuntimeException('Only photos (JPEG, PNG, WebP, HEIC).');
        $typed[] = [$bytes, mb_substr((string)$name, 0, 180) ?: 'photo', $t];
    }
    if ($c['photo'] === 'none') $typed = [];
    $db->beginTransaction();
    try {
        $db->prepare('INSERT IGNORE INTO board_challenge_done (challenge_id, user_id) VALUES (?,?)')->execute([$id, (int)$u['id']]);
        $q = $db->prepare('SELECT id FROM board_challenge_done WHERE challenge_id = ? AND user_id = ?'); $q->execute([$id, (int)$u['id']]);
        $did = (int)$q->fetchColumn();
        $n = $db->prepare('SELECT COUNT(*) FROM board_file WHERE challenge_done_id = ?'); $n->execute([$did]);
        $have = (int)$n->fetchColumn();
        if ($have + count($typed) > BOARD_PHOTO_FILES) throw new RuntimeException('Up to ' . BOARD_PHOTO_FILES . ' photos for a challenge.');
        if ($c['photo'] === 'required' && $have + count($typed) === 0) throw new RuntimeException('This challenge needs a photo.');
        foreach ($typed as $i => [$bytes, $name, $t]) board_file_put($db, $u, $bytes, $name, $t, null, $did, $have + $i);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
