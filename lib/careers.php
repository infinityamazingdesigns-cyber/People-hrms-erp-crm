<?php
// Careers page — rules. Public job board from the open requisitions (public details only, never pay), a guided
// application form with CV reading, consent, spam checks, and the hand-over into the recruitment pipeline.
declare(strict_types=1);
require_once __DIR__ . '/recruitment.php';
require_once __DIR__ . '/cv_parse.php';

const CAR_DEFAULTS = ['title' => 'Join our team', 'intro' => '', 'contact_email' => '', 'contact_phone' => '', 'show_jd' => true, 'other' => true,
    'need_cv' => false, 'ask_dob' => true, 'min_age' => 18, 'cities' => '', 'ratings' => [], 'show_ratings' => false,
    'consent' => 'I agree that my details and CV are kept and used only to consider me for jobs here, and that I can ask for them to be removed at any time.',
    'sends_hour' => 8, 'reads_hour' => 30, 'min_secs' => 4, 'thanks' => 'Thank you — we have your application. We will contact you if your profile fits an opening.'];
const CAR_GENDERS = ['Female', 'Male', 'Other', 'Prefer not to say'];
const CAR_NOTICE = ['0' => 'Can join at once', '15' => '15 days', '30' => '30 days', '60' => '60 days', '90' => '90 days', '120' => 'More than 90 days'];

function car_settings(): array {
    $s = module_states()['careers']['settings'] ?? [];
    return array_replace(CAR_DEFAULTS, array_intersect_key($s, CAR_DEFAULTS));
}

function car_settings_check(array $in): array {
    $o = []; $e = [];
    $t = fn($k, $n) => mb_substr(trim(strip_tags(str_replace("\r", '', (string)($in[$k] ?? '')))), 0, $n);
    $o['title'] = $t('title', 80) ?: CAR_DEFAULTS['title'];
    $o['intro'] = $t('intro', 1500); $o['thanks'] = $t('thanks', 500) ?: CAR_DEFAULTS['thanks'];
    $o['consent'] = $t('consent', 1000);
    if ($o['consent'] === '') $e[] = 'The consent wording cannot be empty.';
    $o['contact_email'] = strtolower($t('contact_email', 160));
    if ($o['contact_email'] !== '' && !filter_var($o['contact_email'], FILTER_VALIDATE_EMAIL)) $e[] = 'Contact email: not an email address.';
    $o['contact_phone'] = $t('contact_phone', 30);
    foreach (['show_jd', 'other', 'need_cv', 'ask_dob', 'show_ratings'] as $k) $o[$k] = !empty($in[$k]);
    $o['min_age'] = max(14, min(25, (int)($in['min_age'] ?? 18)));
    $o['cities'] = implode(', ', array_slice(array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 40), explode(',', $t('cities', 2000)))))), 0, 200));
    $o['sends_hour'] = max(1, min(100, (int)($in['sends_hour'] ?? 8))); $o['reads_hour'] = max(1, min(200, (int)($in['reads_hour'] ?? 30)));
    $o['min_secs'] = max(0, min(30, (int)($in['min_secs'] ?? 4)));
    $o['ratings'] = [];
    foreach ((array)($in['rt_source'] ?? []) as $i => $src) {
        $src = mb_substr(trim(strip_tags((string)$src)), 0, 40); if ($src === '') continue;
        $r = (float)str_replace(',', '.', (string)($in['rt_rating'][$i] ?? '')); $n = (int)($in['rt_count'][$i] ?? 0); $u = trim((string)($in['rt_url'][$i] ?? ''));
        if ($r < 1 || $r > 5) { $e[] = "$src: rating must be 1 to 5."; continue; }
        if ($u !== '' && !preg_match('#^https://[^\s"<>]+$#i', $u)) { $e[] = "$src: the link must start with https://"; $u = ''; }
        $o['ratings'][] = ['source' => $src, 'rating' => round($r, 1), 'count' => max(0, $n), 'url' => $u];
    }
    return [$o, $e];
}

/** Ratings shown on the page: only with Show ticked, a rating and at least one review. Pure. */
function car_ratings(array $set): array {
    if (!$set['show_ratings']) return [];
    return array_values(array_filter($set['ratings'], fn($r) => $r['rating'] >= 1 && $r['count'] > 0));
}

/** What the public may see of a requisition — never pay, notes, who raised it or the weights. Pure. */
function car_public(array $r, array $branchNames, bool $jd): array {
    $exp = $r['exp_min'] === null ? '' : ($r['exp_max'] !== null ? (float)$r['exp_min'] . '–' . (float)$r['exp_max'] . ' years' : (float)$r['exp_min'] . '+ years');
    if ($r['exp_min'] !== null && (float)$r['exp_min'] == 0.0 && $r['exp_max'] === null) $exp = 'Freshers welcome';
    return ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'department' => (string)$r['department'],
            'place' => (string)($r['location'] ?: ($branchNames[(int)$r['branch_id']] ?? '')), 'type' => RC_EMP_TYPES[$r['emp_type']] ?? '',
            'exp' => $exp, 'qual' => (int)$r['qual_level'] ? RC_LEVELS[(int)$r['qual_level']] : '', 'skills' => (string)$r['skills'],
            'openings' => (int)$r['openings'], 'jd' => $jd ? (string)$r['jd'] : ''];
}

/** Notice text or a number → days. Pure. */
function car_notice_days(string $s): ?int {
    $t = strtolower(trim($s)); if ($t === '') return null;
    if (preg_match('/immediate|at once|serving|no notice/', $t)) return 0;
    if (preg_match('/(\d+)\s*(month|mon)/', $t, $m)) return (int)$m[1] * 30;
    if (preg_match('/(\d+)\s*(week|wk)/', $t, $m)) return (int)$m[1] * 7;
    return preg_match('/(\d{1,3})/', $t, $m) ? (int)$m[1] : null;
}

/** Exact age in years on a day. Pure. */
function car_age(string $dob, string $today): int { return (new DateTime($dob))->diff(new DateTime($today))->y; }

/** A signed time for the form, so a bot that posts at once (or a forged time) is refused. */
function car_stamp(int $now, string $key): string { return $now . '.' . substr(hash_hmac('sha256', 'car|' . $now, $key), 0, 24); }
function car_stamp_age(string $s, int $now, string $key): ?int {
    if (!preg_match('/^(\d{10})\.([0-9a-f]{24})$/', $s, $m) || !hash_equals(substr(hash_hmac('sha256', 'car|' . $m[1], $key), 0, 24), $m[2])) return null;
    return $now - (int)$m[1];
}

/**
 * Check an application. $open = public position ids. Returns [clean, errors (field => message)]. Pure.
 * The honeypot field `website` must be empty; `age` = seconds since the form was drawn (null = forged / missing).
 */
function car_check(array $in, array $open, array $set, string $today, ?int $age): array {
    $err = [];
    if (trim((string)($in['website'] ?? '')) !== '' || $age === null || $age < (int)$set['min_secs'] || $age > 86400) $err['_'] = 'Please fill the form again and send it.';
    $pos = (int)($in['position'] ?? 0);
    if ($pos && !in_array($pos, $open, true)) $err['position'] = 'That opening is no longer open — pick another.';
    if (!$pos && !$set['other']) $err['position'] = 'Pick the opening you are applying for.';
    $c = ['name' => rc_text($in['name'] ?? '', 120), 'phone' => rc_text($in['phone'] ?? '', 20), 'email' => strtolower(rc_text($in['email'] ?? '', 160))];
    if ($c['name'] === '') $err['name'] = 'Your full name, please.';
    if (!preg_match('/^\+?[0-9 ()-]{8,20}$/', $c['phone']) || strlen(rc_phone_key($c['phone'])) < 8) $err['phone'] = 'Your mobile number, digits only.';
    if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $err['email'] = 'Your email address.';
    $dob = (string)($in['dob'] ?? '');
    if ($set['ask_dob']) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || !checkdate((int)substr($dob, 5, 2), (int)substr($dob, 8, 2), (int)substr($dob, 0, 4)) || $dob >= $today) $err['dob'] = 'Your date of birth.';
        elseif (car_age($dob, $today) < (int)$set['min_age']) $err['dob'] = 'You must be at least ' . (int)$set['min_age'] . ' years old to apply.';
        elseif (car_age($dob, $today) > 75) $err['dob'] = 'Check the year of birth.';
    } else $dob = '';
    $gender = in_array($in['gender'] ?? '', CAR_GENDERS, true) ? (string)$in['gender'] : '';
    foreach (['city' => 80, 'cur_title' => 100, 'cur_company' => 120, 'qualification' => 160, 'skills' => 600] as $k => $n) $c[$k] = rc_text($in[$k] ?? '', $n);
    if ($c['city'] === '') $err['city'] = 'Where you live now.';
    if ($c['qualification'] === '') $err['qualification'] = 'Your highest qualification.';
    $c['relocate'] = in_array($in['relocate'] ?? '', ['yes', 'no', 'travel'], true) ? (string)$in['relocate'] : '';
    $exp = trim((string)($in['total_exp'] ?? ''));
    if ($exp !== '' && (!is_numeric($exp) || (float)$exp < 0 || (float)$exp > 50)) $err['total_exp'] = 'Years of experience as a number (0 for a fresher).';
    $c['total_exp'] = $exp === '' ? null : round((float)$exp, 1);
    $nd = (string)($in['notice'] ?? '');
    $c['notice_days'] = isset(CAR_NOTICE[$nd]) ? (int)$nd : null;
    $c['notes'] = mb_substr(trim(strip_tags(str_replace("\r", '', (string)($in['about'] ?? '')))), 0, 2000);
    if (empty($in['consent'])) $err['consent'] = 'Please tick to agree before sending.';
    $c['consent'] = 1; $c['source'] = 'Website';
    $c['phone_key'] = rc_phone_key($c['phone']);
    return [['cand' => $c, 'position' => $pos ?: null, 'extra' => array_filter(['dob' => $dob, 'gender' => $gender])], $err];
}

function car_ip_hash(): string { return hash('sha256', 'car|' . (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . seal_key()); }

/** Count one hit; true while under the hourly limit. */
function car_hit(PDO $db, string $kind, int $max): bool {
    $h = date('Y-m-d H:00:00'); $ip = car_ip_hash();
    $s = $db->prepare('SELECT n FROM careers_hit WHERE ip_hash = ? AND kind = ? AND hour_start = ?'); $s->execute([$ip, $kind, $h]);
    if ((int)$s->fetchColumn() >= $max) return false;
    $db->prepare('INSERT INTO careers_hit (ip_hash, kind, hour_start, n) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$ip, $kind, $h]);
    if (random_int(1, 50) === 1) $db->exec('DELETE FROM careers_hit WHERE hour_start < DATE_SUB(NOW(), INTERVAL 2 DAY)');   // only the throttle counter is tidied
    return true;
}

/** Open positions on the job board. */
function car_positions(PDO $db, array $set): array {
    $names = []; foreach (branches(false) as $b) $names[(int)$b['id']] = (string)$b['name'];
    $rows = $db->query("SELECT * FROM rc_requisition WHERE status = 'open' AND careers = 1 ORDER BY title")->fetchAll();
    return array_map(fn($r) => car_public($r, $names, (bool)$set['show_jd']), $rows);
}

/** Places the CV reader recognises: the branches (and their other spellings) plus the settings list. */
function car_cities(array $set): array {
    $out = [];
    foreach (branches() as $b) { $out[] = (string)$b['name']; foreach (explode(',', (string)($b['aliases'] ?? '')) as $a) if (trim($a) !== '') $out[] = trim($a); }
    foreach (explode(',', (string)$set['cities']) as $c) if (trim($c) !== '') $out[] = trim($c);
    return array_values(array_unique($out));
}

/** Suggested values from a CV, mapped to the form's fields (only filled into empty boxes on the page). */
function car_read_cv(string $bytes, array $set): array {
    $p = cvp_parse($bytes, car_cities($set));
    $f = $p['fields'];
    $exp = $f['totalExp'] === 'Fresher' ? '0' : (preg_match('/^(\d+(?:\.\d)?)/', $f['totalExp'], $m) ? $m[1] : '');
    $nd = car_notice_days($f['notice']); $opt = '';
    if ($nd !== null) foreach (array_keys(CAR_NOTICE) as $k) if ($nd <= (int)$k) { $opt = (string)$k; break; }
    if ($nd !== null && $opt === '') $opt = '120';
    return ['ok' => $p['chars'] > 0, 'fields' => array_filter(['name' => $f['name'], 'phone' => $f['phone'], 'email' => $f['email'], 'dob' => $f['dob'], 'gender' => $f['gender'],
            'city' => $f['city'], 'total_exp' => $exp, 'cur_company' => $f['org'], 'cur_title' => $f['designation'], 'qualification' => $f['qualification'],
            'skills' => $f['skills'], 'notice' => $opt], fn($v) => $v !== '')];
}

/**
 * Take an application into the pipeline: one candidate per person (same mobile / email → blanks filled), an
 * application on the position (or the talent pool), the CV, and a reference. A repeat within 15 minutes returns the
 * first reference. Returns the reference.
 */
function car_apply(PDO $db, array $clean, ?array $cv, array $set): string {
    $c = $clean['cand']; $pos = $clean['position'];
    $dedupe = hash('sha256', ($c['phone_key'] ?: $c['email']) . '|' . (int)$pos);
    $s = $db->prepare('SELECT ref FROM careers_app WHERE dedupe = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY id DESC LIMIT 1'); $s->execute([$dedupe]);
    if ($ref = $s->fetchColumn()) return (string)$ref;
    if ($cv) {
        if (strlen($cv[0]) > RC_CV_BYTES) throw new RuntimeException('A CV can be up to 5 MB.');
        if (!rc_cv_type($cv[0], $cv[1])) throw new RuntimeException('Your CV must be a PDF, Word, ODT, RTF or text file.');
    }
    $sys = ['id' => 0];
    $db->beginTransaction();
    try {
        $q = $db->prepare("SELECT id, name, phone_key, email FROM rc_candidate WHERE removed_at IS NULL AND ((phone_key <> '' AND phone_key = ?) OR (email <> '' AND email = ?)) FOR UPDATE");
        $q->execute([$c['phone_key'], $c['email']]);
        if ($same = rc_same_person($c, $q->fetchAll())) { $cid = (int)$same['id']; rc_cand_fill($db, $cid, $c); $db->prepare('UPDATE rc_candidate SET consent = 1 WHERE id = ?')->execute([$cid]); }
        else {
            $c['created_by'] = 0;
            $cols = array_keys($c);
            $db->prepare('INSERT INTO rc_candidate (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($c));
            $cid = (int)$db->lastInsertId();
            rc_log($db, 'cand', $cid, 'add', 0, 'Website');
        }
        if ($cv) rc_cv_add($db, $sys, $cid, $cv[0], $cv[1]);
        $db->prepare('INSERT IGNORE INTO rc_application (candidate_id, req_id, req_key, created_by) VALUES (?,?,?,0)')->execute([$cid, $pos, (int)$pos]);
        $a = $db->prepare('SELECT id FROM rc_application WHERE candidate_id = ? AND req_key = ?'); $a->execute([$cid, (int)$pos]);
        $aid = (int)$a->fetchColumn();
        $year = date('Y');
        $db->prepare('INSERT INTO careers_app (ref, candidate_id, application_id, req_id, dedupe, ip_hash, extra, consent_text) VALUES (?,?,?,?,?,?,?,?)')
           ->execute(['tmp-' . bin2hex(random_bytes(6)), $cid, $aid, $pos, $dedupe, car_ip_hash(), json_encode($clean['extra']), $set['consent']]);
        $id = (int)$db->lastInsertId();
        $ref = sprintf('APP-%s-%04d', $year, $id);
        $db->prepare('UPDATE careers_app SET ref = ? WHERE id = ?')->execute([$ref, $id]);
        rc_log($db, 'app', $aid, 'website', 0, $ref);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit(null, 'careers_apply', $ref);
    return $ref;
}

/** Applications that came from the page (newest first) for the HR list. */
function car_recent(PDO $db, int $days = 60): array {
    $s = $db->prepare('SELECT ca.ref, ca.created_at, ca.candidate_id, c.name, c.city, c.cur_title, c.removed_at, r.title AS req_title, a.stage FROM careers_app ca JOIN rc_candidate c ON c.id = ca.candidate_id
                       LEFT JOIN rc_requisition r ON r.id = ca.req_id LEFT JOIN rc_application a ON a.id = ca.application_id WHERE ca.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY ca.id DESC LIMIT 300');
    $s->execute([$days]);
    return $s->fetchAll();
}
