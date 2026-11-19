<?php
// Help desk — rules. Answers on the product's screens and the organisation's policies, kept by the help desk editors
// in any language; team contacts (official numbers only), office locations with maps and the organisation's social
// links. Every signed-in login with the module reads them; nothing is shown before sign-in.
// Questions are matched in the browser (same rules as hd_score() here), so nothing typed is sent; without scripts the
// page matches on the server. Answers link to a module only when that login may open it.
// Nothing is deleted: rows are switched off, every change is a line in hd_log (field names only).
// Pure functions first, then the database half.
declare(strict_types=1);

const HD_MIN_SCORE = 2;
const HD_KINDS = ['head' => 'Head office', 'branch' => 'Branch office', 'factory' => 'Factory / plant', 'site' => 'Site office', 'warehouse' => 'Warehouse'];
const HD_REVIEW_RE = '~^https://(g\.page/r/[\w-]+(/review)?/?|search\.google\.com/local/writereview\?placeid=[\w-]+|maps\.app\.goo\.gl/\w+|g\.co/kgs/\w+|(www\.)?google\.[a-z.]{2,8}/maps\S*)$~i';
const HD_SOCIAL = [
    'google'    => ['Google review', HD_REVIEW_RE],
    'linkedin'  => ['LinkedIn',  '~^https://([a-z]{2,3}\.)?linkedin\.com/(company|school|showcase|in)/[^\s/?#]+~i'],
    'instagram' => ['Instagram', '~^https://(www\.)?instagram\.com/[^\s/?#]+~i'],
    'facebook'  => ['Facebook',  '~^https://((www|m)\.)?(facebook|fb)\.com/[^\s?#]+~i'],
    'youtube'   => ['YouTube',   '~^https://((www|m)\.)?(youtube\.com/(@|c/|channel/|user/)[^\s?#]+|youtu\.be/[^\s?#]+)~i'],
    'x'         => ['X',         '~^https://(www\.)?(x|twitter)\.com/[^\s/?#]+~i'],
    'website'   => ['Website',   '~^https://[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$~i'],
];
const HD_DEFAULTS = ['edit_roles' => ['SUPERADMIN', 'HRMANAGER'], 'langs' => "en English", 'dial_code' => ''];
/** Starting answers about this product's own screens (never one organisation's policy). Editors change or switch them off. */
const HD_SEED = [
    ['signin', 'Signing in and your password', 'sign in login password forgot reset change password locked', 'Sign in with the login ID and password HR gave you. Change it any time on Account → Password. Forgot it? Ask HR to reset it; every other browser is signed out after a reset.', ''],
    ['twostep', 'The sign-in code (2-step)', 'code otp 2-step two step verification email code not received', 'When 2-step sign-in is on, a 6-digit code is emailed after your password. It works for 10 minutes and only the newest code counts. Tick "Trust this browser" on your own device so it is not asked every time. No code? Check spam, then ask HR to check your email ID.', ''],
    ['punch', 'Marking attendance', 'punch check in check out attendance mark selfie location gps', 'Open Attendance → Punch on your phone. Allow the camera and location, wait for the location to be precise enough, then Check in or Check out. The time is the server\'s, not your phone\'s.', 'attendance'],
    ['missed', 'A missed or wrong punch', 'missed punch forgot correction request wrong time attendance correction', 'Send a correction request from Attendance with the right times and a reason. It goes to your reporting manager, then HR. You can see where it stands on the same screen.', 'attendance'],
    ['leave', 'Applying for leave', 'leave apply holiday off day sick casual half day', 'Open Leave, pick the dates and the part of the day, say who covers your work and send. It goes to your reporting manager, then HR. You can withdraw a waiting application yourself.', 'leave'],
    ['leavewd', 'Cancelling approved leave', 'cancel leave withdraw approved leave take back', 'For leave already approved, send "Request to withdraw" with a reason. It stays approved until HR agrees.', 'leave'],
    ['holidays', 'Holidays', 'holiday list calendar festival', 'The holiday calendar shows the holidays of your branch.', 'holidays'],
    ['payslip', 'Payslips', 'payslip salary slip pay slip download', 'Released payslips are under Payslips, when HR has opened them to you.', 'payslips'],
    ['claims', 'Expense claims', 'expense claim reimbursement bill travel', 'Add each expense with its bill photo under Expense claims and send. HR checks it, it is approved, and Accounts pays and records the reference.', 'claims'],
    ['letters', 'Letters', 'letter certificate experience letter salary certificate appointment offer', 'Letters issued to you are under My letters, where you can also ask HR for a letter.', 'letters'],
    ['profile', 'Updating your details', 'profile address phone emergency contact nominee update details', 'Change your personal details on My profile and send them; HR checks them before they change. Employment details (code, designation, joining date) are changed only by HR.', 'profile'],
    ['kyc', 'ID and bank details', 'pan aadhaar bank account kyc uan', 'ID numbers and bank details go on KYC & bank with the document; HR verifies them. They are stored sealed and shown masked.', 'kyc'],
    ['tasks', 'Tasks', 'task to do pending reminder', 'Your tasks and the ones given to you are under Tasks, with due dates and reminders.', 'tasks'],
    ['talk', 'Team chat', 'chat message talk group', 'Team chat has direct chats and groups. Only the people in a chat can read it.', 'talk'],
    ['meet', 'Video meetings', 'meeting video call meet join code', 'Start a meeting or join with its code under Video meetings. Allow the camera and microphone when asked.', 'meet'],
    ['assets', 'Company items with you', 'asset laptop phone sim my assets report issue', 'The company items given to you are under the asset register; report a problem or loss there.', 'assets'],
];

function hd_settings(): array {
    $s = module_states()['helpdesk']['settings'] ?? [];
    return array_replace(HD_DEFAULTS, array_intersect_key($s, HD_DEFAULTS));
}
function hd_settings_check(array $in, array $roles): array {
    $o = ['edit_roles' => array_values(array_intersect(array_keys($roles), (array)($in['edit_roles'] ?? [])))];
    $e = $o['edit_roles'] ? [] : ['Pick at least one role that keeps the help desk.'];
    $l = []; foreach (preg_split('/\R/', (string)($in['langs'] ?? '')) ?: [] as $line) if (preg_match('/^\s*([a-z]{2})\s+(.{1,30})$/u', $line, $m)) $l[$m[1]] = trim($m[2]);
    if (!isset($l['en'])) $l = ['en' => 'English'] + $l;
    $o['langs'] = implode("\n", array_map(fn($k, $v) => "$k $v", array_keys($l), $l));
    $d = preg_replace('/\D/', '', (string)($in['dial_code'] ?? ''));
    $o['dial_code'] = $d !== '' && strlen($d) <= 4 ? $d : '';
    return [$o, $e];
}
function hd_langs(array $set): array {
    $o = []; foreach (explode("\n", (string)$set['langs']) as $line) if (preg_match('/^([a-z]{2})\s+(.+)$/u', trim($line), $m)) $o[$m[1]] = $m[2];
    return $o ?: ['en' => 'English'];
}

/** Lower case, letters / marks / digits only, single spaces. Pure. */
function hd_norm(string $s): string { return trim((string)preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', mb_strtolower($s, 'UTF-8'))); }

/** How well a topic (title + keywords) matches a question — the same rules as the page's script. Pure. */
function hd_score(string $title, string $keywords, string $q): int {
    $nq = ' ' . hd_norm($q) . ' ';
    $toks = array_values(array_filter(explode(' ', trim($nq)), fn($t) => mb_strlen($t) >= 2));
    if (!$toks) return 0;
    $score = 0;
    foreach (preg_split('/\s*,\s*|\s+/u', $keywords) ?: [] as $kw) {
        $k = hd_norm($kw); if ($k === '') continue;
        foreach ($toks as $t) {
            if ($t === $k) { $score += 3; break; }
            if (mb_strlen($k) >= 4 && mb_strlen($t) >= 4 && (str_contains($t, $k) || str_contains($k, $t))) { $score += 2; break; }
        }
    }
    $tt = ' ' . hd_norm($title) . ' ';
    foreach ($toks as $t) if (mb_strlen($t) >= 3 && str_contains($tt, ' ' . $t . ' ')) $score += 1;
    return $score;
}
/** Best topic for a question among [{title, keywords, …}], or null. Pure. */
function hd_best(array $topics, string $q): ?array {
    $best = null; $top = 0;
    foreach ($topics as $t) { $s = hd_score((string)$t['title'], (string)$t['keywords'], $q); if ($s > $top) { $top = $s; $best = $t; } }
    return $top >= HD_MIN_SCORE ? $best : null;
}

function hd_clean_phone(string $m): ?string {
    $m = trim($m); if ($m === '') return '';
    $d = (string)preg_replace('/[\s\-().]/', '', $m);
    return preg_match('/^\+?\d{7,15}$/', $d) ? $d : null;
}
/** WhatsApp chat link; a number without a country code gets the setting's dial code. Pure. */
function hd_whatsapp_link(string $mobile, string $dial = ''): string {
    $plus = str_starts_with(trim($mobile), '+'); $d = (string)preg_replace('/\D/', '', $mobile);
    if (!$plus && $dial !== '' && !str_starts_with($d, $dial)) $d = $dial . ltrim($d, '0');
    return 'https://wa.me/' . $d;
}
function hd_clean_map(string $u): ?string {
    $u = trim($u); if ($u === '') return '';
    return preg_match('~^https://(maps\.google\.[a-z.]{2,8}/|(www\.)?google\.[a-z.]{2,8}/maps|maps\.app\.goo\.gl/|goo\.gl/maps/|(www\.)?openstreetmap\.org/)\S*$~i', $u) && strlen($u) <= 500 ? $u : null;
}
function hd_clean_social(string $platform, string $u): ?string {
    $u = trim($u); if ($u === '') return '';
    if (!isset(HD_SOCIAL[$platform]) || strlen($u) > 500 || preg_match('/\s/', $u)) return null;
    return preg_match(HD_SOCIAL[$platform][1], $u) ? $u : null;
}
function hd_clean_review(string $u): ?string { $u = trim($u); if ($u === '') return ''; return strlen($u) <= 500 && preg_match(HD_REVIEW_RE, $u) ? $u : null; }
function hd_clean_linkedin(string $u): ?string { $u = trim($u); if ($u === '') return ''; return preg_match('~^https://([a-z]{2,3}\.)?linkedin\.com/in/[^\s/?#]+/?$~i', $u) && strlen($u) <= 300 ? $u : null; }
function hd_ids($v): array {
    $a = is_array($v) ? $v : explode(',', (string)$v);
    return array_slice(array_values(array_unique(array_filter(array_map('intval', $a), fn($i) => $i > 0))), 0, 12);
}

/** One contact form → [row, error]. Official numbers only. Pure. */
function hd_clean_contact(array $in): array {
    $s = fn(string $k, int $n) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $n);
    $row = ['name' => $s('name', 80), 'dept' => $s('dept', 60), 'role' => $s('role', 60), 'email' => mb_strtolower($s('email', 120)),
            'office_ids' => implode(',', hd_ids($in['office_ids'] ?? [])), 'sort' => (int)($in['sort'] ?? 0), 'whatsapp' => !empty($in['whatsapp']) ? 1 : 0];
    if ($row['name'] === '') return [$row, 'Enter the name.'];
    $m = hd_clean_phone((string)($in['mobile'] ?? ''));
    if ($m === null) return [$row, 'The official mobile should have 7 to 15 digits, e.g. +1 555 010 0100.'];
    $row['mobile'] = $m; if ($m === '') $row['whatsapp'] = 0;
    if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) return [$row, 'That email ID does not look right.'];
    if ($row['mobile'] === '' && $row['email'] === '') return [$row, 'Give an official mobile number or email ID.'];
    $li = hd_clean_linkedin((string)($in['linkedin'] ?? ''));
    if ($li === null) return [$row, 'The LinkedIn link should look like https://www.linkedin.com/in/name.'];
    $row['linkedin'] = $li;
    return [$row, null];
}
/** One location form → [row, error]. Pure. */
function hd_clean_office(array $in, array $branchIds): array {
    $s = fn(string $k, int $n) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $n);
    $kind = (string)($in['kind'] ?? 'branch'); $b = (int)($in['branch_id'] ?? 0);
    $row = ['name' => $s('name', 60), 'address' => $s('address', 300), 'sort' => (int)($in['sort'] ?? 0), 'kind' => isset(HD_KINDS[$kind]) ? $kind : 'branch', 'branch_id' => in_array($b, $branchIds, true) ? $b : null];
    if ($row['name'] === '') return [$row, 'Enter the location name.'];
    foreach (['map_url' => ['hd_clean_map', 'Paste a Google Maps or OpenStreetMap link (Share → Copy link).'], 'review_url' => ['hd_clean_review', 'Paste the Google review link (Business Profile → Ask for reviews → Copy link).']] as $k => [$fn, $msg]) {
        $v = $fn((string)($in[$k] ?? '')); if ($v === null) return [$row, $msg]; $row[$k] = $v;
    }
    $p = hd_clean_phone((string)($in['phone'] ?? ''));
    if ($p === null) return [$row, 'The phone should have 7 to 15 digits.'];
    $row['phone'] = $p;
    return [$row, null];
}
/** One answer form → [row, error]. Pure. */
function hd_clean_topic(array $in, array $langs, array $modules): array {
    $s = fn(string $k, int $n) => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $n);
    $slug = strtolower((string)preg_replace('/[^a-z0-9-]+/i', '-', $s('slug', 40)));
    $row = ['slug' => trim($slug, '-'), 'lang' => isset($langs[$in['lang'] ?? '']) ? (string)$in['lang'] : 'en', 'title' => $s('title', 160), 'keywords' => $s('keywords', 600),
            'answer' => mb_substr(trim(strip_tags((string)($in['answer'] ?? ''))), 0, 4000), 'module_key' => in_array($in['module_key'] ?? '', $modules, true) ? (string)$in['module_key'] : '', 'sort' => (int)($in['sort'] ?? 0)];
    if ($row['slug'] === '') $row['slug'] = trim(substr(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $row['title'])), 0, 40), '-');
    if ($row['title'] === '' || $row['slug'] === '') return [$row, 'Give the answer a title.'];
    if ($row['answer'] === '') return [$row, 'Write the answer.'];
    return [$row, null];
}

// ---------- database ----------

function hd_can_edit(array $u): bool { return (bool)array_intersect(user_roles((int)$u['id'], !empty($u['is_owner'])), (array)hd_settings()['edit_roles']); }
function hd_log(PDO $db, int $by, string $what, string $id, string $act, array $fields = []): void {
    $db->prepare('INSERT INTO hd_log (by_id, what, item_id, act, fields) VALUES (?,?,?,?,?)')->execute([$by, $what, $id, $act, mb_substr(implode(',', $fields), 0, 300)]);
}

/** Put the starting answers in once (by slug); an answer an editor changed or switched off is left alone. */
function hd_seed(PDO $db): void {
    $ins = $db->prepare("INSERT IGNORE INTO hd_topic (slug, lang, title, keywords, answer, module_key, sort, seeded) VALUES (?,'en',?,?,?,?,?,1)");
    foreach (HD_SEED as $i => [$slug, $t, $k, $a, $m]) $ins->execute([$slug, $t, $k, $a, $m, ($i + 1) * 10]);
}

/** Answers for one language (English where that language has none), each with a link the login may open. */
function hd_topics(PDO $db, array $u, string $lang, bool $all = false): array {
    $rows = $db->query('SELECT * FROM hd_topic' . ($all ? '' : ' WHERE active = 1') . ' ORDER BY sort, title')->fetchAll();
    $by = [];
    foreach ($rows as $r) if ($r['lang'] === 'en' || $r['lang'] === $lang) { if (!isset($by[$r['slug']]) || $r['lang'] === $lang) $by[$r['slug']] = $r; }
    if ($all) return array_values($by);
    $cat = module_catalog(); $base = wn_base();
    $o = [];
    foreach ($by as $r) {
        $k = (string)$r['module_key'];
        if ($k !== '' && !(module_on($k) && module_allowed($u, $k))) { if (!array_key_exists($k, $cat)) $k = ''; else continue; }   // a screen this login has no access to: leave the answer out
        $o[] = ['slug' => $r['slug'], 'title' => $r['title'], 'keywords' => $r['keywords'], 'answer' => $r['answer'], 'link' => $k !== '' ? $base . $cat[$k]['entry'] : '', 'linkName' => $k !== '' ? $cat[$k]['name'] : ''];
    }
    return $o;
}

function hd_topic_save(PDO $db, int $by, int $id, array $row): int {
    if ($id) {
        $s = $db->prepare('SELECT * FROM hd_topic WHERE id = ?'); $s->execute([$id]); $old = $s->fetch();
        if (!$old) throw new RuntimeException('Not found.');
        try { $db->prepare('UPDATE hd_topic SET slug=?, lang=?, title=?, keywords=?, answer=?, module_key=?, sort=?, seeded=0, updated_by=? WHERE id=?')->execute([$row['slug'], $row['lang'], $row['title'], $row['keywords'], $row['answer'], $row['module_key'], $row['sort'], $by, $id]); }
        catch (PDOException $e) { throw new RuntimeException('There is already an answer with that short name in that language.'); }
        hd_log($db, $by, 'topic', (string)$id, 'edit', array_keys(array_diff_assoc(array_map('strval', $row), array_map('strval', array_intersect_key($old, $row)))));
        return $id;
    }
    try { $db->prepare('INSERT INTO hd_topic (slug, lang, title, keywords, answer, module_key, sort, updated_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$row['slug'], $row['lang'], $row['title'], $row['keywords'], $row['answer'], $row['module_key'], $row['sort'], $by]); }
    catch (PDOException $e) { throw new RuntimeException('There is already an answer with that short name in that language — edit that one, or pick another language.'); }
    $id = (int)$db->lastInsertId(); hd_log($db, $by, 'topic', (string)$id, 'add');
    return $id;
}

function hd_offices(PDO $db): array { return $db->query('SELECT * FROM hd_office WHERE active = 1 ORDER BY sort, name')->fetchAll(); }
function hd_contacts(PDO $db): array { return $db->query('SELECT * FROM hd_contact WHERE active = 1 ORDER BY sort, name')->fetchAll(); }
function hd_save(PDO $db, int $by, string $what, int $id, array $row): int {
    $t = ['contact' => 'hd_contact', 'office' => 'hd_office'][$what] ?? null;
    if (!$t) throw new RuntimeException('Unknown.');
    $cols = array_keys($row);
    if ($id) { $db->prepare("UPDATE $t SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ', updated_by = ? WHERE id = ? AND active = 1')->execute([...array_values($row), $by, $id]); hd_log($db, $by, $what, (string)$id, 'edit', $cols); return $id; }
    $db->prepare("INSERT INTO $t (" . implode(', ', $cols) . ', updated_by) VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')->execute([...array_values($row), $by]);
    $id = (int)$db->lastInsertId(); hd_log($db, $by, $what, (string)$id, 'add');
    return $id;
}
/** Switch off (never deleted); 'topic' too. */
function hd_off(PDO $db, int $by, string $what, int $id, bool $on = false): void {
    $t = ['contact' => 'hd_contact', 'office' => 'hd_office', 'topic' => 'hd_topic'][$what] ?? null;
    if (!$t) throw new RuntimeException('Unknown.');
    $db->prepare("UPDATE $t SET active = ?, updated_by = ? WHERE id = ?")->execute([$on ? 1 : 0, $by, $id]);
    hd_log($db, $by, $what, (string)$id, $on ? 'on' : 'off');
}
function hd_social(PDO $db): array {
    $o = []; foreach ($db->query("SELECT platform, url FROM hd_social WHERE url <> ''") as $r) if (isset(HD_SOCIAL[$r['platform']])) $o[$r['platform']] = $r['url'];
    return array_intersect_key(array_replace(array_fill_keys(array_keys(HD_SOCIAL), null), $o), $o);
}
/** All or nothing: a link on the wrong site refuses the whole form. */
function hd_social_save(PDO $db, int $by, array $in): void {
    $clean = [];
    foreach (HD_SOCIAL as $p => [$label]) { $v = hd_clean_social($p, (string)($in[$p] ?? '')); if ($v === null) throw new RuntimeException("The $label link is not a $label address."); $clean[$p] = $v; }
    $s = $db->prepare('INSERT INTO hd_social (platform, url, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE url = VALUES(url), updated_by = VALUES(updated_by)');
    foreach ($clean as $p => $v) $s->execute([$p, $v, $by]);
    hd_log($db, $by, 'social', '-', 'edit', array_keys(array_filter($clean)));
}
/** People on the roll for the contact form's "fill from the employee list": name, department, designation, branch, login email. */
function hd_pick_people(PDO $db): array {
    return $db->query("SELECT e.name, e.department AS dept, e.designation AS role, e.branch_id, COALESCE(u.email, '') AS email FROM employee e LEFT JOIN users u ON u.emp_code = e.code AND u.active = 1
                       WHERE e.active = 1 AND (e.last_day IS NULL OR e.last_day >= CURDATE()) ORDER BY e.name LIMIT 3000")->fetchAll();
}
