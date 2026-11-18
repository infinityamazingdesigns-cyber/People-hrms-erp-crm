<?php
/**
 * Team chat — ported from the reference build as rules; the policy is a setting.
 *  - Direct chats and groups. Nobody reads a chat they are not in — HR and admins included; admin lists show names,
 *    members and counts only.
 *  - Policy: direct messages two-way / employees to leads only / leads start and employees reply; who creates groups
 *    (everyone / leads / manage roles); files on or off; read receipts on or off.
 *  - A group is two-way or one-way (only its admins send). Its admins (and the manage roles) change members, admins,
 *    name, description and mode; the last admin cannot leave while others remain.
 *  - Only admins remove messages: a group's admins in their group, the manage roles in chats they are in; never the
 *    sender. Files go with the message.
 *  - Ticks for the sender: sent, delivered to everyone, read by everyone (delivered only when receipts are off).
 *  - A whole chat is deleted only by the delete roles, with a reason, into an append-only log (names and counts only).
 *  - Files by their own bytes (photos, PDF, Office, text), 10 MB, in the database, opened only by current members.
 */
declare(strict_types=1);

const TALK_TEXT_MAX = 4000;
const TALK_FILE_BYTES = 10 * 1024 * 1024;
const TALK_MAX_FILES = 6;
const TALK_DEFAULTS = ['dm' => 'two', 'create' => 'lead', 'files' => true, 'receipts' => true,
    'lead_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO', 'MANAGER'], 'manage_roles' => ['SUPERADMIN', 'HRMANAGER'], 'delete_roles' => ['SUPERADMIN']];

/* ---------------- settings and rights ---------------- */

function talk_settings(): array {
    $s = module_states()['talk']['settings'] ?? [];
    return array_replace(TALK_DEFAULTS, array_intersect_key($s, TALK_DEFAULTS));
}

function talk_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    $o['dm'] = in_array($in['dm'] ?? '', ['two', 'lead', 'oneway'], true) ? $in['dm'] : 'two';
    $o['create'] = in_array($in['create'] ?? '', ['all', 'lead', 'manage'], true) ? $in['create'] : 'lead';
    $o['files'] = !empty($in['files']); $o['receipts'] = !empty($in['receipts']);
    foreach (['lead_roles', 'manage_roles', 'delete_roles'] as $k) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k] && $k !== 'lead_roles') $e[] = "Pick at least one role for $k.";
    }
    return [$o, $e];
}

/** Rights of a login given its roles. Pure. */
function talk_rights_for(array $roles, array $set): array {
    $has = fn(array $list) => (bool)array_intersect($roles, $list);
    $manage = $has($set['manage_roles']);
    return ['lead' => $manage || $has($set['lead_roles']), 'manage' => $manage, 'delete' => $has($set['delete_roles'])];
}

function talk_roles_of(PDO $db, int $uid): array {
    static $memo = [];
    if (!isset($memo[$uid])) { $q = $db->prepare('SELECT role_code FROM user_role WHERE user_id = ?'); $q->execute([$uid]); $memo[$uid] = $q->fetchAll(PDO::FETCH_COLUMN);
        $o = $db->prepare('SELECT is_owner FROM users WHERE id = ?'); $o->execute([$uid]); if ((int)$o->fetchColumn()) $memo[$uid][] = 'SUPERADMIN'; }
    return $memo[$uid];
}
function talk_rights(PDO $db, int $uid): array { return talk_rights_for(talk_roles_of($db, $uid), talk_settings()); }

/** May $me start a direct chat with $other? Pure given rights. */
function talk_dm_allowed(string $dm, array $meR, array $otherR): bool {
    if ($meR['lead'] || $dm === 'two') return true;
    return $dm === 'lead' && $otherR['lead'];
}

/** Why someone may not send ('' = may). $isAdmin = group admin; $creatorLead = the DM was started by a lead. Pure. */
function talk_block(array $chat, bool $member, bool $isAdmin, string $dm, array $meR, array $otherR, bool $otherActive, bool $creatorLead): string {
    if (!$member) return 'You are not in this chat.';
    if ($chat['kind'] === 'group') return $chat['mode'] === 'one' && !$isAdmin ? 'Only the group\'s admins send messages here.' : '';
    if (!$otherActive) return 'This person no longer has a login.';
    if ($meR['lead'] || $dm === 'two') return '';
    if ($dm === 'lead') return $otherR['lead'] ? '' : 'Messages between employees are off. You can message HR and managers.';
    return $creatorLead ? '' : 'Only HR and managers start chats. You can reply when they message you.';
}

/** Ticks for one of my messages: members who were in the chat when it went (not me) with their marks. Pure. */
function talk_tick(int $msgId, array $marks, bool $receipts): string {
    if (!$marks) return 'sent';
    $read = true; $deliv = true;
    foreach ($marks as [$r, $d]) { if ($r < $msgId) $read = false; if (max($r, $d) < $msgId) $deliv = false; }
    if ($read && $receipts) return 'read';
    return $deliv ? 'delivered' : 'sent';
}

/** A file's type from its own bytes; null = not allowed. Pure. */
function talk_file_type(string $b, string $name): ?array {
    if (str_starts_with($b, "\xFF\xD8\xFF")) return ['image/jpeg', true];
    if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) return ['image/png', true];
    if (str_starts_with($b, 'GIF87a') || str_starts_with($b, 'GIF89a')) return ['image/gif', true];
    if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') return ['image/webp', true];
    if (str_starts_with($b, '%PDF-')) return ['application/pdf', false];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $office = ['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
               'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    if (str_starts_with($b, "PK\x03\x04") && isset($office[$ext])) return [$office[$ext], false];
    if (in_array($ext, ['txt', 'csv'], true) && !preg_match('/[\x00-\x08\x0E-\x1F]/', substr($b, 0, 4096)) && mb_check_encoding($b, 'UTF-8')) return [$ext === 'csv' ? 'text/csv' : 'text/plain', false];
    return null;
}

/* ---------------- database ---------------- */

function talk_chat(PDO $db, int $id): ?array { $q = $db->prepare('SELECT * FROM talk_chat WHERE id = ?'); $q->execute([$id]); return $q->fetch() ?: null; }
function talk_member(PDO $db, int $chat, int $uid): ?array { $q = $db->prepare('SELECT * FROM talk_member WHERE chat_id = ? AND user_id = ? AND left_at IS NULL'); $q->execute([$chat, $uid]); return $q->fetch() ?: null; }
function talk_active(PDO $db, int $uid): bool { $q = $db->prepare('SELECT active FROM users WHERE id = ?'); $q->execute([$uid]); return (int)$q->fetchColumn() === 1; }
function talk_other(PDO $db, int $chat, int $me): ?int { $q = $db->prepare('SELECT user_id FROM talk_member WHERE chat_id = ? AND user_id <> ? LIMIT 1'); $q->execute([$chat, $me]); $v = $q->fetchColumn(); return $v === false ? null : (int)$v; }

function talk_names(PDO $db, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (!$ids) return [];
    $q = $db->prepare('SELECT id, name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $q->execute($ids);
    return array_column($q->fetchAll(), 'name', 'id');
}

/** The chat if $me is in it, else an error. */
function talk_need(PDO $db, int $chatId, int $me): array {
    $c = talk_chat($db, $chatId);
    if (!$c || !talk_member($db, $chatId, $me)) throw new RuntimeException('Chat not found.');
    return $c;
}

function talk_send_block(PDO $db, array $c, int $me): string {
    $m = talk_member($db, (int)$c['id'], $me); $set = talk_settings();
    $other = $c['kind'] === 'dm' ? talk_other($db, (int)$c['id'], $me) : null;
    return talk_block($c, (bool)$m, $m && (int)$m['is_admin'], $set['dm'], talk_rights($db, $me), $other ? talk_rights($db, $other) : ['lead' => false],
        $other ? talk_active($db, $other) : false, talk_rights($db, (int)$c['created_by'])['lead']);
}

/** Directory: active logins; department from the employee record; designations only for leads. */
function talk_people(PDO $db, int $me): array {
    $lead = talk_rights($db, $me)['lead'];
    $rows = $db->query('SELECT u.id, u.name, e.department, e.designation FROM users u LEFT JOIN employee e ON e.code = u.emp_code WHERE u.active = 1 ORDER BY u.name')->fetchAll();
    return array_values(array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'dept' => (string)($r['department'] ?? ''), 'title' => $lead ? (string)($r['designation'] ?? '') : ''],
        array_filter($rows, fn($r) => (int)$r['id'] !== $me && trim((string)$r['name']) !== '')));
}

function talk_open_dm(PDO $db, int $me, int $other): int {
    if ($other === $me || !talk_active($db, $other)) throw new RuntimeException('Choose a colleague.');
    $key = min($me, $other) . ':' . max($me, $other);
    $q = $db->prepare('SELECT id FROM talk_chat WHERE dm_key = ?'); $q->execute([$key]);
    if (($id = $q->fetchColumn()) !== false) return (int)$id;
    if (!talk_dm_allowed(talk_settings()['dm'], talk_rights($db, $me), talk_rights($db, $other))) throw new RuntimeException('You can start chats with HR and managers only.');
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO talk_chat (kind, dm_key, created_by) VALUES ('dm', ?, ?)")->execute([$key, $me]);
        $id = (int)$db->lastInsertId();
        $i = $db->prepare('INSERT INTO talk_member (chat_id, user_id) VALUES (?,?)'); $i->execute([$id, $me]); $i->execute([$id, $other]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $id;
}

function talk_can_create(PDO $db, int $me): bool {
    $r = talk_rights($db, $me);
    return match (talk_settings()['create']) { 'all' => true, 'lead' => $r['lead'], default => $r['manage'] };
}

function talk_sys(PDO $db, int $chat, int $by, string $text): void {
    $db->prepare('INSERT INTO talk_msg (chat_id, user_id, sys, body) VALUES (?,?,1,?)')->execute([$chat, $by, mb_substr($text, 0, 300)]);
}

function talk_line(string $s, int $max): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? ''), 0, $max); }

function talk_create_group(PDO $db, int $me, array $in): int {
    if (!talk_can_create($db, $me)) throw new RuntimeException('Groups are created by the people HR chose.');
    $name = talk_line((string)($in['name'] ?? ''), 60); if (mb_strlen($name) < 2) throw new InvalidArgumentException('Name the group.');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['members'] ?? [])), fn($x) => $x !== $me && talk_active($db, $x))));
    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO talk_chat (kind, name, descr, mode, created_by) VALUES ('group', ?, ?, ?, ?)")->execute([$name, talk_line((string)($in['descr'] ?? ''), 140), ($in['mode'] ?? '') === 'one' ? 'one' : 'two', $me]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO talk_member (chat_id, user_id, is_admin) VALUES (?,?,1)')->execute([$id, $me]);
        $i = $db->prepare('INSERT INTO talk_member (chat_id, user_id) VALUES (?,?)'); foreach ($ids as $x) $i->execute([$id, $x]);
        talk_sys($db, $id, $me, 'Group created');
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $id;
}

function talk_can_manage(PDO $db, array $c, int $me): bool {
    if ($c['kind'] !== 'group') return false;
    if (talk_rights($db, $me)['manage']) return true;
    $m = talk_member($db, (int)$c['id'], $me); return $m && (int)$m['is_admin'];
}

/** Group changes by its admins: info, mode, add, remove, make / drop admin, and anyone leaving. */
function talk_group_op(PDO $db, int $me, int $chatId, string $op, array $in): void {
    $c = talk_chat($db, $chatId);
    if (!$c || $c['kind'] !== 'group') throw new RuntimeException('Group not found.');
    $mine = talk_member($db, $chatId, $me);
    if ($op === 'leave') {
        if (!$mine) throw new RuntimeException('You are not in this group.');
        $adm = $db->prepare('SELECT COUNT(*) FROM talk_member WHERE chat_id = ? AND left_at IS NULL AND is_admin = 1 AND user_id <> ?'); $adm->execute([$chatId, $me]);
        $oth = $db->prepare('SELECT COUNT(*) FROM talk_member WHERE chat_id = ? AND left_at IS NULL AND user_id <> ?'); $oth->execute([$chatId, $me]);
        if ((int)$mine['is_admin'] && !(int)$adm->fetchColumn() && (int)$oth->fetchColumn()) throw new RuntimeException('Make someone else an admin before you leave.');
        $db->prepare('UPDATE talk_member SET left_at = NOW(), is_admin = 0 WHERE chat_id = ? AND user_id = ?')->execute([$chatId, $me]);
        talk_sys($db, $chatId, $me, (talk_names($db, [$me])[$me] ?? 'Someone') . ' left'); return;
    }
    if (!talk_can_manage($db, $c, $me)) throw new RuntimeException('Only the group\'s admins change it.');
    $who = (int)($in['user'] ?? 0); $nm = $who ? (talk_names($db, [$who])[$who] ?? 'someone') : '';
    switch ($op) {
        case 'info':
            $name = talk_line((string)($in['name'] ?? ''), 60); if (mb_strlen($name) < 2) throw new InvalidArgumentException('Name the group.');
            $db->prepare('UPDATE talk_chat SET name = ?, descr = ?, mode = ? WHERE id = ?')->execute([$name, talk_line((string)($in['descr'] ?? ''), 140), ($in['mode'] ?? '') === 'one' ? 'one' : 'two', $chatId]);
            talk_sys($db, $chatId, $me, 'Group details changed'); break;
        case 'add':
            if (!$who || !talk_active($db, $who)) throw new InvalidArgumentException('Choose a colleague.');
            $db->prepare('INSERT INTO talk_member (chat_id, user_id) VALUES (?,?) ON DUPLICATE KEY UPDATE left_at = NULL, is_admin = 0, joined_at = NOW()')->execute([$chatId, $who]);
            talk_sys($db, $chatId, $me, $nm . ' added'); break;
        case 'remove': case 'admin': case 'unadmin':
            $m = talk_member($db, $chatId, $who); if (!$m) throw new RuntimeException('Not in the group.');
            if ($op === 'remove') { if ($who === $me) throw new RuntimeException('Use Leave.'); $db->prepare('UPDATE talk_member SET left_at = NOW(), is_admin = 0 WHERE chat_id = ? AND user_id = ?')->execute([$chatId, $who]); talk_sys($db, $chatId, $me, $nm . ' removed'); }
            else { $db->prepare('UPDATE talk_member SET is_admin = ? WHERE chat_id = ? AND user_id = ?')->execute([$op === 'admin' ? 1 : 0, $chatId, $who]); talk_sys($db, $chatId, $me, $nm . ($op === 'admin' ? ' is now an admin' : ' is no longer an admin')); }
            break;
        default: throw new RuntimeException('Unknown change.');
    }
}

/** Send a message with optional files ([[bytes, name]]). */
function talk_post(PDO $db, int $me, int $chatId, string $text, array $files = []): int {
    $c = talk_need($db, $chatId, $me);
    if (($b = talk_send_block($db, $c, $me)) !== '') throw new RuntimeException($b);
    $text = mb_substr(trim(str_replace("\r", '', $text)), 0, TALK_TEXT_MAX);
    if ($files && !talk_settings()['files']) throw new RuntimeException('Sharing files is turned off.');
    if (count($files) > TALK_MAX_FILES) throw new RuntimeException('Up to ' . TALK_MAX_FILES . ' files in one message.');
    $typed = [];
    foreach ($files as [$bytes, $name]) {
        if (strlen($bytes) > TALK_FILE_BYTES) throw new RuntimeException('Files can be up to 10 MB.');
        $name = mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '_', basename($name)) ?: 'file', 0, 180);
        $t = talk_file_type($bytes, $name); if (!$t) throw new RuntimeException('Only photos, PDF, Word, Excel, PowerPoint, text or CSV files.');
        $typed[] = [$bytes, $name, $t];
    }
    if ($text === '' && !$typed) throw new InvalidArgumentException('Type a message.');
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO talk_msg (chat_id, user_id, body) VALUES (?,?,?)')->execute([$chatId, $me, $text]);
        $id = (int)$db->lastInsertId();
        $fi = $db->prepare('INSERT INTO talk_file (id, chat_id, msg_id, uploader_id, name, type, size, is_image, data) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($typed as [$bytes, $name, $t]) { $fi->bindValue(1, bin2hex(random_bytes(16))); $fi->bindValue(2, $chatId); $fi->bindValue(3, $id); $fi->bindValue(4, $me); $fi->bindValue(5, $name); $fi->bindValue(6, $t[0]); $fi->bindValue(7, strlen($bytes)); $fi->bindValue(8, $t[1] ? 1 : 0); $fi->bindValue(9, $bytes, PDO::PARAM_LOB); $fi->execute(); }
        $db->prepare('UPDATE talk_member SET last_read = ?, last_delivered = ? WHERE chat_id = ? AND user_id = ?')->execute([$id, $id, $chatId, $me]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return $id;
}

function talk_can_remove(PDO $db, array $c, int $me): bool {
    if (!talk_member($db, (int)$c['id'], $me)) return false;
    return talk_rights($db, $me)['manage'] || ($c['kind'] === 'group' && talk_can_manage($db, $c, $me));
}

function talk_remove_msg(PDO $db, int $me, int $msgId): void {
    $q = $db->prepare('SELECT * FROM talk_msg WHERE id = ? AND deleted_at IS NULL AND sys = 0'); $q->execute([$msgId]); $m = $q->fetch();
    if (!$m) throw new RuntimeException('Message not found.');
    $c = talk_need($db, (int)$m['chat_id'], $me);
    if (!talk_can_remove($db, $c, $me)) throw new RuntimeException('Only admins remove messages.');
    $db->prepare("UPDATE talk_msg SET deleted_at = NOW(), deleted_by = ?, body = '' WHERE id = ?")->execute([$me, $msgId]);
    $db->prepare('UPDATE talk_file SET data = NULL WHERE msg_id = ?')->execute([$msgId]);
    audit($me, 'talk_message_remove', '#' . $msgId);
}

/** The chat was on screen: read (and delivered) up to the newest message. */
function talk_mark_read(PDO $db, int $me, int $chatId): void {
    $q = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM talk_msg WHERE chat_id = ?'); $q->execute([$chatId]); $top = (int)$q->fetchColumn();
    $db->prepare('UPDATE talk_member SET last_read = GREATEST(last_read, ?), last_delivered = GREATEST(last_delivered, ?) WHERE chat_id = ? AND user_id = ?')->execute([$top, $top, $chatId, $me]);
}

/** The person fetched their chats: everything so far is delivered to them. */
function talk_mark_delivered(PDO $db, int $me): void {
    $db->prepare('UPDATE talk_member m SET last_delivered = GREATEST(last_delivered, (SELECT COALESCE(MAX(x.id), 0) FROM talk_msg x WHERE x.chat_id = m.chat_id)) WHERE m.user_id = ? AND m.left_at IS NULL')->execute([$me]);
}

/** Messages after $after (newest 100 when 0), with files and my ticks. */
function talk_messages(PDO $db, int $me, int $chatId, int $after = 0): array {
    talk_need($db, $chatId, $me);
    $q = $after ? $db->prepare('SELECT * FROM talk_msg WHERE chat_id = ? AND id > ? ORDER BY id LIMIT 200') : $db->prepare('SELECT * FROM (SELECT * FROM talk_msg WHERE chat_id = ? AND id > ? ORDER BY id DESC LIMIT 100) x ORDER BY id');
    $q->execute([$chatId, $after]); $rows = $q->fetchAll();
    $ids = array_column($rows, 'id'); $files = [];
    if ($ids) { $f = $db->query('SELECT id, msg_id, name, type, size, is_image, data IS NULL AS gone FROM talk_file WHERE msg_id IN (' . implode(',', array_map('intval', $ids)) . ')'); foreach ($f as $x) $files[(int)$x['msg_id']][] = $x; }
    $mem = $db->prepare('SELECT user_id, last_read, last_delivered, joined_at FROM talk_member WHERE chat_id = ? AND user_id <> ?'); $mem->execute([$chatId, $me]); $mem = $mem->fetchAll();
    $rc = talk_settings()['receipts'];
    foreach ($rows as &$r) {
        $r['files'] = $files[(int)$r['id']] ?? [];
        if ((int)$r['user_id'] === $me && !(int)$r['sys']) $r['tick'] = talk_tick((int)$r['id'], array_map(fn($m) => [(int)$m['last_read'], (int)$m['last_delivered']], array_filter($mem, fn($m) => $m['joined_at'] <= $r['created_at'])), $rc);
    }
    return $rows;
}

/** My chats with name, last message, unread count. */
function talk_list(PDO $db, int $me): array {
    talk_mark_delivered($db, $me);
    $q = $db->prepare("SELECT c.*, m.last_read, m.is_admin,
        (SELECT MAX(x.id) FROM talk_msg x WHERE x.chat_id = c.id) AS top,
        (SELECT COUNT(*) FROM talk_msg x WHERE x.chat_id = c.id AND x.id > m.last_read AND x.user_id <> ? AND x.sys = 0 AND x.deleted_at IS NULL) AS unread
        FROM talk_chat c JOIN talk_member m ON m.chat_id = c.id AND m.user_id = ? AND m.left_at IS NULL ORDER BY top DESC, c.id DESC");
    $q->execute([$me, $me]); $rows = $q->fetchAll();
    $others = []; foreach ($rows as $r) if ($r['kind'] === 'dm') $others[(int)$r['id']] = talk_other($db, (int)$r['id'], $me);
    $names = talk_names($db, array_values(array_filter($others)));
    foreach ($rows as &$r) $r['title'] = $r['kind'] === 'dm' ? ($names[$others[(int)$r['id']] ?? 0] ?? 'Former colleague') : $r['name'];
    return $rows;
}

function talk_unread_total(PDO $db, int $me): int {
    $q = $db->prepare('SELECT COUNT(*) FROM talk_msg x JOIN talk_member m ON m.chat_id = x.chat_id AND m.user_id = ? AND m.left_at IS NULL JOIN talk_chat c ON c.id = x.chat_id WHERE x.id > m.last_read AND x.user_id <> ? AND x.sys = 0 AND x.deleted_at IS NULL');
    $q->execute([$me, $me]); return (int)$q->fetchColumn();
}

/** A file this login may open: attached to a live message of a chat they are in now. */
function talk_file_for(PDO $db, int $me, string $id): ?array {
    if (!preg_match('/^[0-9a-f]{32}$/', $id)) return null;
    $q = $db->prepare('SELECT f.*, m.deleted_at AS mdel FROM talk_file f JOIN talk_msg m ON m.id = f.msg_id WHERE f.id = ?'); $q->execute([$id]); $f = $q->fetch();
    if (!$f || $f['data'] === null || $f['mdel'] !== null || !talk_member($db, (int)$f['chat_id'], $me)) return null;
    return $f;
}

/** Admin overview of groups (names, members, counts — never messages). */
function talk_overview(PDO $db): array {
    return $db->query("SELECT c.id, c.kind, c.name, c.created_at, (SELECT COUNT(*) FROM talk_member m WHERE m.chat_id = c.id AND m.left_at IS NULL) AS members,
        (SELECT COUNT(*) FROM talk_msg x WHERE x.chat_id = c.id AND x.sys = 0) AS msgs, (SELECT MAX(created_at) FROM talk_msg x WHERE x.chat_id = c.id) AS last_at FROM talk_chat c ORDER BY last_at DESC LIMIT 500")->fetchAll();
}

/** Delete a whole chat (delete roles, reason), logging names and counts first. */
function talk_delete_chat(PDO $db, int $me, int $chatId, string $reason): void {
    if (!talk_rights($db, $me)['delete']) throw new RuntimeException('Only the Super Admin deletes a chat.');
    $reason = talk_line($reason, 300); if (mb_strlen($reason) < 3) throw new InvalidArgumentException('Give a reason — it goes into the log.');
    $c = talk_chat($db, $chatId); if (!$c) throw new RuntimeException('Chat not found.');
    $mem = $db->prepare('SELECT user_id FROM talk_member WHERE chat_id = ?'); $mem->execute([$chatId]);
    $names = array_values(talk_names($db, $mem->fetchAll(PDO::FETCH_COLUMN)));
    $st = $db->prepare('SELECT COUNT(*), MAX(created_at) FROM talk_msg WHERE chat_id = ? AND sys = 0'); $st->execute([$chatId]); [$n, $last] = $st->fetch(PDO::FETCH_NUM);
    $fc = $db->prepare('SELECT COUNT(*) FROM talk_file WHERE chat_id = ?'); $fc->execute([$chatId]);
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO talk_delete_log (chat_id, kind, name, members, msg_count, file_count, chat_created_at, last_msg_at, reason, deleted_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$chatId, $c['kind'], mb_substr($c['kind'] === 'group' ? $c['name'] : implode(' ↔ ', $names), 0, 190), json_encode($names, JSON_UNESCAPED_UNICODE), (int)$n, (int)$fc->fetchColumn(), $c['created_at'], $last, $reason, $me]);
        foreach (['talk_file', 'talk_msg', 'talk_member'] as $t) $db->prepare("DELETE FROM $t WHERE chat_id = ?")->execute([$chatId]);
        $db->prepare('DELETE FROM talk_chat WHERE id = ?')->execute([$chatId]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit($me, 'talk_chat_delete', '#' . $chatId);
}
