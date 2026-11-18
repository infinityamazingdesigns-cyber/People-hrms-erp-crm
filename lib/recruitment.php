<?php
// Recruitment pipeline — rules. Requisitions (approved → open positions), candidates (one per person), applications
// through stages, interviews and panel feedback, offers, ATS match score, hand-over to Onboarding.
// Pure functions first (testable without a database), then the database half.
declare(strict_types=1);

const RC_STAGES = ['new' => 'New application', 'screening' => 'Screening', 'shortlisted' => 'Shortlisted', 'interview' => 'Interview',
                   'offer' => 'Offer made', 'accepted' => 'Offer accepted', 'joined' => 'Joined',
                   'hold' => 'On hold', 'rejected' => 'Not taken forward', 'withdrawn' => 'Withdrew'];
const RC_FLOW = ['new', 'screening', 'shortlisted', 'interview', 'offer', 'accepted', 'joined'];
const RC_END = ['joined', 'rejected', 'withdrawn'];
const RC_REQ_STATUS = ['draft' => 'Draft', 'approval' => 'Waiting for approval', 'open' => 'Open', 'hold' => 'On hold', 'filled' => 'Filled', 'closed' => 'Closed', 'rejected' => 'Not approved'];
const RC_EMP_TYPES = ['permanent' => 'Permanent', 'contract' => 'Contract', 'intern' => 'Intern / trainee', 'parttime' => 'Part time'];
const RC_SOURCES = ['Website', 'Job portal', 'Referral', 'Walk-in', 'Consultant', 'Campus', 'Social media', 'Email', 'Other'];
const RC_LEVELS = [1 => '10th / school', 2 => '12th / vocational', 3 => 'Diploma', 4 => 'Graduate', 5 => 'Post-graduate / professional'];
const RC_WEIGHTS = ['skills' => 30, 'exp' => 25, 'qual' => 15, 'desig' => 15, 'loc' => 5, 'sal' => 5, 'notice' => 5];
const RC_WLABEL = ['skills' => 'Key skills', 'exp' => 'Experience', 'qual' => 'Qualification', 'desig' => 'Designation fit', 'loc' => 'Location', 'sal' => 'Salary fit', 'notice' => 'Notice period'];
const RC_MODES = ['person' => 'In person', 'video' => 'Video call', 'phone' => 'Phone'];
const RC_VERDICTS = ['yes' => 'Take forward', 'hold' => 'Hold', 'no' => 'Do not take forward'];
const RC_CV_BYTES = 5 * 1024 * 1024;
const RC_DEFAULTS = ['manage_roles' => ['SUPERADMIN', 'HRMANAGER', 'HRRECRUIT'], 'approve_roles' => ['SUPERADMIN', 'HRMANAGER', 'CEO'],
                     'raise_roles' => ['SUPERADMIN', 'HRMANAGER', 'HRRECRUIT', 'MANAGER'], 'approval' => true, 'ref_prefix' => 'RQ'];

function rc_settings(): array {
    $s = module_states()['recruitment']['settings'] ?? [];
    return array_replace(RC_DEFAULTS, array_intersect_key($s, RC_DEFAULTS));
}

function rc_settings_check(array $in, array $roles): array {
    $o = []; $e = [];
    foreach (['manage_roles' => 'running the pipeline', 'approve_roles' => 'approving requisitions', 'raise_roles' => 'raising requisitions'] as $k => $l) {
        $o[$k] = array_values(array_intersect(array_keys($roles), (array)($in[$k] ?? [])));
        if (!$o[$k]) $e[] = "Pick at least one role for $l.";
    }
    $o['approval'] = !empty($in['approval']);
    $p = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($in['ref_prefix'] ?? 'RQ')));
    $o['ref_prefix'] = $p !== '' ? substr($p, 0, 6) : 'RQ';
    return [$o, $e];
}

/** Rights from roles. Pure. */
function rc_rights_for(array $roles, array $set): array {
    $has = fn(array $l) => (bool)array_intersect($roles, $l);
    $m = $has($set['manage_roles']);
    return ['manage' => $m, 'approve' => $has($set['approve_roles']), 'raise' => $m || $has($set['raise_roles'])];
}

/** Last 10 digits of a mobile number (the part that identifies it). Pure. */
function rc_phone_key(string $p): string { $d = preg_replace('/\D/', '', $p); return strlen($d) >= 10 ? substr($d, -10) : $d; }

function rc_text($v, int $max): string { return mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$v))), 0, $max); }
function rc_num($v, float $max): ?float {
    $s = trim(str_replace(',', '', (string)$v));
    if ($s === '') return null;
    if (!is_numeric($s) || (float)$s < 0 || (float)$s > $max) throw new InvalidArgumentException('Check the number "' . mb_substr($s, 0, 20) . '".');
    return round((float)$s, 2);
}

/** Clean a requisition. Pure. $branches = ids. */
function rc_req_check(array $in, array $branches, bool $money): array {
    $r = ['title' => rc_text($in['title'] ?? '', 100), 'department' => rc_text($in['department'] ?? '', 80)];
    if ($r['title'] === '') throw new InvalidArgumentException('Give the position a title.');
    $br = (int)($in['branch_id'] ?? 0);
    if ($br && !in_array($br, $branches, true)) throw new InvalidArgumentException('Pick the branch from the list.');
    $r['branch_id'] = $br ?: null;
    $r['openings'] = max(1, min(500, (int)($in['openings'] ?? 1)));
    $r['emp_type'] = isset(RC_EMP_TYPES[$in['emp_type'] ?? '']) ? (string)$in['emp_type'] : 'permanent';
    $r['reason'] = ($in['reason'] ?? '') === 'replacement' ? 'replacement' : 'new';
    $r['replaces'] = $r['reason'] === 'replacement' ? rc_text($in['replaces'] ?? '', 120) : '';
    $r['qual_level'] = isset(RC_LEVELS[(int)($in['qual_level'] ?? 0)]) ? (int)$in['qual_level'] : 0;
    $r['disciplines'] = rc_text($in['disciplines'] ?? '', 200);
    $r['exp_min'] = rc_num($in['exp_min'] ?? '', 45); $r['exp_max'] = rc_num($in['exp_max'] ?? '', 45);
    if ($r['exp_min'] !== null && $r['exp_max'] !== null && $r['exp_max'] < $r['exp_min']) throw new InvalidArgumentException('Experience: the most is below the least.');
    $r['skills'] = rc_text($in['skills'] ?? '', 600);
    $r['titles'] = rc_text($in['titles'] ?? '', 200) ?: $r['title'];
    $r['location'] = rc_text($in['location'] ?? '', 80);
    $r['notice_max'] = ($n = rc_num($in['notice_max'] ?? '', 365)) === null ? null : (int)$n;
    $r['hire_by'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['hire_by'] ?? '')) ? (string)$in['hire_by'] : null;
    $r['jd'] = mb_substr(trim(str_replace("\r", '', strip_tags((string)($in['jd'] ?? '')))), 0, 8000);
    $w = [];
    foreach (RC_WEIGHTS as $k => $d) $w[$k] = isset($in['w_' . $k]) && $in['w_' . $k] !== '' ? max(0, min(100, (int)$in['w_' . $k])) : $d;
    $r['weights'] = json_encode($w);
    $r['missing_zero'] = empty($in['_form']) || !empty($in['missing_zero']) ? 1 : 0;
    if ($money) $r['ctc_max'] = rc_num($in['ctc_max'] ?? '', 1e9);
    return $r;
}

/** Clean a candidate. Pure. Needs a name and a mobile or email. */
function rc_cand_check(array $in, bool $money): array {
    $c = ['name' => rc_text($in['name'] ?? '', 120)];
    if ($c['name'] === '') throw new InvalidArgumentException('Give the candidate\'s name.');
    $c['phone'] = rc_text($in['phone'] ?? '', 20);
    if ($c['phone'] !== '' && !preg_match('/^\+?[0-9 ()-]{6,20}$/', $c['phone'])) throw new InvalidArgumentException('Mobile: digits only.');
    $c['phone_key'] = rc_phone_key($c['phone']);
    $c['email'] = strtolower(rc_text($in['email'] ?? '', 160));
    if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email: not an email address.');
    if ($c['phone'] === '' && $c['email'] === '') throw new InvalidArgumentException('Give a mobile number or an email.');
    foreach (['city' => 80, 'cur_title' => 100, 'cur_company' => 120, 'qualification' => 160, 'skills' => 600] as $k => $n) $c[$k] = rc_text($in[$k] ?? '', $n);
    $c['relocate'] = in_array($in['relocate'] ?? '', ['yes', 'no', 'travel'], true) ? (string)$in['relocate'] : '';
    $c['total_exp'] = rc_num($in['total_exp'] ?? '', 50); $c['rel_exp'] = rc_num($in['rel_exp'] ?? '', 50);
    $c['notice_days'] = ($n = rc_num($in['notice_days'] ?? '', 365)) === null ? null : (int)$n;
    $c['notes'] = mb_substr(trim(str_replace("\r", '', strip_tags((string)($in['notes'] ?? '')))), 0, 4000);
    $c['cv_text'] = mb_substr(trim(str_replace("\r", '', strip_tags((string)($in['cv_text'] ?? '')))), 0, 40000);
    $c['source'] = in_array($in['source'] ?? '', RC_SOURCES, true) ? (string)$in['source'] : 'Other';
    $c['consent'] = !empty($in['consent']) ? 1 : 0;
    if ($money) { $c['cur_ctc'] = rc_num($in['cur_ctc'] ?? '', 1e9); $c['exp_ctc'] = rc_num($in['exp_ctc'] ?? '', 1e9); }
    return $c;
}

/** A candidate already on file with this mobile or email: the first matching row, or null. Pure. */
function rc_same_person(array $c, array $rows, int $except = 0): ?array {
    foreach ($rows as $r) {
        if ((int)$r['id'] === $except) continue;
        if (($c['phone_key'] !== '' && strlen($c['phone_key']) >= 10 && $c['phone_key'] === (string)$r['phone_key'])
            || ($c['email'] !== '' && strcasecmp($c['email'], (string)$r['email']) === 0)) return $r;
    }
    return null;
}

/** Can an application move from one stage to another? '' = yes, else the reason. Pure. */
function rc_move_problem(string $from, string $to, string $note): string {
    if (!isset(RC_STAGES[$to])) return 'Unknown stage.';
    if ($from === $to) return 'It is already there.';
    if ($from === 'joined') return 'This person has joined — nothing to move.';
    if (in_array($to, ['rejected', 'withdrawn', 'hold'], true) && trim($note) === '') return 'Say why.';
    if (in_array($from, ['rejected', 'withdrawn'], true) && trim($note) === '') return 'Say why it is being opened again.';
    if ($to === 'joined' && $from !== 'accepted') return 'Only an accepted offer becomes Joined.';
    if ($to === 'accepted' && $from !== 'offer') return 'Make the offer first.';
    return '';
}

// ---------- ATS match ----------

const RC_STOP = ['a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'in', 'into', 'is', 'it', 'its', 'of', 'on', 'or', 'per', 'the', 'their', 'them', 'to', 'with',
    'will', 'should', 'shall', 'can', 'may', 'must', 'etc', 'also', 'other', 'others', 'including', 'include', 'such', 'all', 'any', 'each', 'both', 'well', 'able', 'ability',
    'knowledge', 'good', 'basic', 'strong', 'sound', 'working', 'work', 'understanding', 'skill', 'skills', 'experience', 'experienced', 'year', 'years', 'yr', 'yrs',
    'min', 'minimum', 'max', 'maximum', 'preferred', 'prefer', 'preferably', 'plus', 'required', 'requirement', 'requirements', 'relevant', 'related', 'field', 'fields',
    'area', 'areas', 'level', 'levels', 'candidate', 'candidates', 'person', 'job', 'role', 'position', 'company', 'ltd', 'pvt', 'new', 'time', 'timely', 'proper',
    'properly', 'various', 'regular', 'regularly', 'daily', 'ensure', 'maintain', 'maintaining', 'handle', 'handling', 'make', 'making', 'done', 'do', 'perform',
    'performing', 'carry', 'out', 'up', 'according', 'using', 'use', 'used', 'given'];
const RC_SYN = ['exec' => 'executive', 'engg' => 'engineer', 'engr' => 'engineer', 'eng' => 'engineer', 'mgr' => 'manager', 'sr' => 'senior', 'jr' => 'junior',
    'asst' => 'assistant', 'dy' => 'deputy', 'dept' => 'department', 'acct' => 'account', 'accts' => 'account', 'accounts' => 'account', 'mktg' => 'marketing',
    'qc' => 'quality', 'qa' => 'quality', 'admin' => 'administration', 'bde' => 'business', 'bdm' => 'business', 'dev' => 'development'];
const RC_DISC = ['electrical', 'electronic', 'mechanical', 'civil', 'computer', 'information technology', 'instrument', 'automobile', 'production', 'industrial',
    'commerce', 'account', 'finance', 'marketing', 'sales', 'human resource', 'export', 'import', 'logistic', 'supply chain', 'design', 'metallurg', 'mechatronic'];
const RC_TITLE_SENIORITY = '/^(senior|junior|assistant|deputy|trainee|head|lead|chief|general|executive|officer|manager|associate|incharge)$/';

function rc_stem(string $w): string {
    $l = strlen($w);
    if ($l > 5 && str_ends_with($w, 'ing')) return substr($w, 0, -3);
    if ($l > 5 && str_ends_with($w, 'ies')) return substr($w, 0, -3) . 'y';
    if ($l > 4 && preg_match('/(ed|es)$/', $w) && !str_ends_with($w, 'ss')) return substr($w, 0, -2);
    if ($l > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) return substr($w, 0, -1);
    return $w;
}
/** Words of a text, lower case, without filler words, stemmed. Pure. */
function rc_tokens(string $t): array {
    static $stop = null; $stop ??= array_flip(RC_STOP);
    $out = [];
    foreach (explode(' ', (string)preg_replace('/[^a-z0-9+#]+/', ' ', str_replace(['’', "'"], '', strtolower($t)))) as $w) {
        if ($w === '' || ctype_digit($w)) continue;
        $w = RC_SYN[$w] ?? $w;
        if (strlen($w) < 2 || isset($stop[$w])) continue;
        $out[] = rc_stem($w);
    }
    return $out;
}
function rc_tset(string $t): array { return array_fill_keys(rc_tokens($t), 1); }
/** Which listed skills a text shows (half its words or more). Pure. */
function rc_kw_match(array $list, array $set): array {
    $hit = []; $miss = [];
    foreach ($list as $s) {
        $tk = rc_tokens($s); if (!$tk) continue;
        $n = count(array_filter($tk, fn($w) => isset($set[$w])));
        if ($n * 2 >= count($tk)) $hit[] = trim($s); else $miss[] = trim($s);
    }
    return ['hit' => $hit, 'miss' => $miss];
}
/** Education levels found in a text (1 school … 5 post-graduate). Pure. */
function rc_edu_levels(string $t): array {
    $t = strtolower($t); $out = [];
    $re = [5 => '/\b(m\.?\s?tech|m\.?\s?e\.|mba|pgdm|mca|m\.?\s?sc|m\.?\s?com|m\.?\s?a\.|post[\s-]*grad\w*|pg\b|ph\.?\s?d|chartered accountant|cma|master\w*)/',
           4 => '/\b(b\.?\s?tech|b\.?\s?e\b|b\.?\s?e\.|b\.?\s?sc|b\.?\s?com|bba|bca|b\.?\s?a\b|b\.?\s?a\.|graduat\w*|degree|bachelor\w*)/',
           3 => '/\b(diploma|polytechnic)/', 2 => '/\b(iti|12th|xii|hsc|higher secondary|intermediate)\b/', 1 => '/\b(10th|ssc|matric\w*|sslc)\b/'];
    foreach ($re as $lv => $r) if (preg_match($r, $t)) $out[] = $lv;
    return $out;
}
function rc_disciplines(string $t): array {
    $t = strtolower($t);
    $out = array_values(array_filter(RC_DISC, fn($d) => str_contains($t, $d)));
    if (preg_match('/\bb\.?\s?com|\bm\.?\s?com/', $t) && !in_array('commerce', $out, true)) $out[] = 'commerce';
    return $out;
}
/** Years of experience written in a text ("11+ years of experience"). Pure. */
function rc_years_in(string $t): ?float {
    if (preg_match('/(\d{1,2}(?:\.\d+)?)\s*\+?\s*(?:years?|yrs?)\s*(?:of\s+)?(?:total\s+|overall\s+|work\s+|professional\s+|relevant\s+)?(?:experience|exp\b)/i', $t, $m)
        || preg_match('/(?:total\s+|overall\s+|work\s+)?experience\s*[:\-–]?\s*(?:of\s+|about\s+|over\s+|around\s+)?(\d{1,2}(?:\.\d+)?)\s*\+?\s*(?:years?|yrs?)/i', $t, $m)) {
        $n = (float)$m[1];
        return $n <= 45 ? $n : null;
    }
    return null;
}

/**
 * ATS match: how far a candidate fits a position, 0–100 and 0–5 stars (half stars). Only requirements the position
 * sets are checked; with missing_zero a requirement the application does not show counts as 0 (skills, experience,
 * qualification, designation), while location, salary and notice are left out when not filled. A screening aid only.
 * $c candidate row, $r requisition row. Pure.
 */
function rc_ats(array $c, array $r, string $today): array {
    $w = array_replace(RC_WEIGHTS, array_intersect_key((array)json_decode((string)($r['weights'] ?? ''), true), RC_WEIGHTS));
    $zero = (int)($r['missing_zero'] ?? 1) === 1;
    $cv = (string)($c['cv_text'] ?? '');
    $text = implode(' ', [$c['skills'] ?? '', $c['cur_title'] ?? '', $c['qualification'] ?? '', $c['notes'] ?? '', $cv]);
    $set = rc_tset($text); $P = [];
    $miss = function (string $k, string $what) use (&$P, $zero, $cv) { if ($zero) $P[$k] = ['v' => 0.0, 'd' => 'Not in the application' . ($cv !== '' ? ' or the CV' : '') . " — add $what", 'missing' => true]; };
    // 1 key skills
    $skills = array_values(array_filter(array_map('trim', preg_split('/[,;\n|]+/', (string)($r['skills'] ?? '')) ?: []), fn($x) => $x !== ''));
    if ($skills && count(rc_tokens($text)) >= 2) {
        $m = rc_kw_match($skills, $set); $n = count($m['hit']) + count($m['miss']);
        if ($n) $P['skills'] = ['v' => count($m['hit']) / $n, 'd' => count($m['hit']) . " of $n key skills found", 'hit' => $m['hit'], 'miss' => $m['miss']];
    } elseif ($skills) $miss('skills', 'key skills or a short profile');
    // 2 experience
    $rel = $c['rel_exp'] ?? null; $tot = $c['total_exp'] ?? null; $from = '';
    if ($rel === null && $tot === null) { $y = rc_years_in((string)($c['notes'] ?? '')); if ($y === null && $cv !== '') { $y = rc_years_in($cv); $from = $y !== null ? ' (from the CV)' : ''; } else if ($y !== null) $from = ' (from notes)'; $tot = $y; }
    $yrs = $rel !== null ? (float)$rel : ($tot !== null ? (float)$tot * 0.85 : null);
    $emin = $r['exp_min'] ?? null; $emax = $r['exp_max'] ?? null;
    if ($emin !== null && $yrs !== null) {
        $emin = (float)$emin;
        $e = $yrs >= $emin ? ($emax !== null && $yrs > (float)$emax + 5 ? 0.8 : 1.0) : ($emin > 0 ? max(0.0, $yrs / $emin) : 1.0);
        $P['exp'] = ['v' => $e, 'd' => ($rel !== null ? (float)$rel . ' yrs relevant' : (float)$tot . ' yrs total' . $from) . ' · asks ' . ($emax !== null ? $emin + 0 . '–' . ((float)$emax + 0) : $emin + 0 . '+') . ' yrs'];
    } elseif ($emin !== null && (float)$emin > 0) $miss('exp', 'total / relevant experience');
    // 3 qualification
    $need = (int)($r['qual_level'] ?? 0);
    $qtext = trim((string)($c['qualification'] ?? '')) !== '' ? (string)$c['qualification'] : $text;
    $lv = rc_edu_levels($qtext);
    if ($need && $lv) {
        $have = max($lv);
        $q = $have >= $need ? 1.0 : ($have === $need - 1 ? 0.6 : 0.25);
        $want = array_values(array_filter(array_map(fn($x) => strtolower(trim($x)), explode(',', (string)($r['disciplines'] ?? '')))));
        $got = rc_disciplines($qtext);
        $same = !$want || (bool)array_filter($want, fn($d) => str_contains(strtolower($qtext), $d) || in_array($d, $got, true));
        if ($want && $got && !$same) $q *= 0.75;
        $P['qual'] = ['v' => $q, 'd' => RC_LEVELS[$have] . ($have >= $need ? ' meets ' : ' below ') . RC_LEVELS[$need] . ($want && $got && !$same ? ' · different discipline' : '')];
    } elseif ($need) $miss('qual', 'the highest qualification');
    // 4 designation fit
    $want = rc_tset((string)($r['titles'] ?? $r['title'] ?? ''));
    $cur = rc_tokens((string)($c['cur_title'] ?? '')); $fromCv = false;
    if (!$cur && $cv !== '') { $cur = rc_tokens(mb_substr($cv, 0, 500)); $fromCv = true; }
    $core = array_filter(array_keys($want), fn($x) => !preg_match(RC_TITLE_SENIORITY, $x));
    if ($want && $cur) {
        $cs = array_fill_keys($cur, 1);
        $cf = $core ? count(array_filter($core, fn($x) => isset($cs[$x]))) / count($core) : 0;
        $al = count(array_filter(array_keys($want), fn($x) => isset($cs[$x]))) / count($want);
        $d4 = min(1.0, 0.75 * $cf + 0.25 * $al);
        if ($d4 < 1 && trim((string)($r['jd'] ?? '')) !== '') { $jd = rc_tset((string)$r['jd']); $d4 = max($d4, 0.5 * count(array_filter($cur, fn($x) => isset($jd[$x]))) / count($cur)); }
        if ($fromCv) $d4 = min($d4, $cf >= 0.5 ? 1.0 : ($cf > 0 ? 0.6 : 0.0));
        $P['desig'] = ['v' => $d4, 'd' => ($fromCv ? ($cf > 0 ? 'Title words found in the CV' : 'No matching title in the CV') : (string)$c['cur_title']) . ' → ' . (string)($r['titles'] ?? $r['title'])];
    } elseif ($want) $miss('desig', 'the current / last designation');
    // 5 location
    $vl = rc_tset((string)($r['location'] ?? '')); $cl = rc_tset((string)($c['city'] ?? '')); $rl = (string)($c['relocate'] ?? '');
    if ($vl && ($cl || $rl !== '')) {
        $near = (bool)array_intersect_key($vl, $cl);
        $P['loc'] = ['v' => $near ? 1.0 : ($rl === 'yes' ? 0.9 : ($rl === 'travel' ? 0.5 : 0.2)), 'd' => $near ? 'Same place as the position' : ($rl === 'yes' ? 'Willing to relocate' : 'Based elsewhere')];
    }
    // 6 salary fit (per month)
    $cap = isset($r['ctc_max']) ? (float)$r['ctc_max'] : 0.0; $exp = isset($c['exp_ctc']) ? (float)$c['exp_ctc'] : 0.0;
    if ($cap > 0 && $exp > 0) { $x = $exp / $cap; $P['sal'] = ['v' => $x <= 1 ? 1.0 : ($x <= 1.1 ? 0.6 : ($x <= 1.25 ? 0.3 : 0.0)), 'd' => $x <= 1 ? 'Expected pay within the range' : 'Expected pay ' . round(($x - 1) * 100) . '% above the range']; }
    // 7 notice
    if (($c['notice_days'] ?? null) !== null) {
        $nd = (int)$c['notice_days']; $nmax = $r['notice_max'] ?? null; $left = null;
        if ($nmax !== null) $s = $nd <= (int)$nmax ? 1.0 : max(0.2, (int)$nmax / max(1, $nd));
        else {
            if (!empty($r['hire_by'])) $left = (int)round((strtotime((string)$r['hire_by']) - strtotime($today)) / 86400);
            $s = $left !== null && $left > 0 ? ($nd <= $left ? 1.0 : max(0.2, $left / max(1, $nd))) : ($nd <= 30 ? 1.0 : ($nd <= 60 ? 0.7 : ($nd <= 90 ? 0.4 : 0.2)));
        }
        $P['notice'] = ['v' => $s, 'd' => "$nd days notice" . ($nmax !== null ? " · up to $nmax allowed" : ($left !== null && $left > 0 ? " · hire-by in $left days" : ''))];
    }
    $got = 0.0; $tw = 0; $rows = [];
    foreach (RC_WEIGHTS as $k => $_) {
        $on = isset($P[$k]) && (int)$w[$k] > 0;
        if ($on) { $got += (int)$w[$k] * max(0.0, min(1.0, (float)$P[$k]['v'])); $tw += (int)$w[$k]; }
        $rows[] = ['k' => $k, 'label' => RC_WLABEL[$k], 'w' => (int)$w[$k], 'on' => $on, 'v' => $P[$k]['v'] ?? null, 'd' => $P[$k]['d'] ?? ''];
    }
    $all = array_sum(array_map('intval', $w)) ?: 1;
    $pct = $tw ? $got / $tw : null;
    return ['pct' => $pct === null ? null : (int)round($pct * 100), 'stars' => $pct === null ? null : round($pct * 10) / 2, 'rows' => $rows,
            'low' => $tw / $all < 0.4, 'missing' => array_values(array_map(fn($k) => RC_WLABEL[$k], array_keys(array_filter($P, fn($p) => !empty($p['missing']))))),
            'hit' => $P['skills']['hit'] ?? [], 'miss' => $P['skills']['miss'] ?? ($skills && !isset($P['skills']) ? $skills : [])];
}

/** "★★★½☆" for a 0–5 half-star figure. Pure. */
function rc_stars(?float $s): string {
    if ($s === null) return '—';
    $f = (int)floor($s); $h = $s - $f >= 0.5;
    return str_repeat('★', $f) . ($h ? '½' : '') . str_repeat('☆', 5 - $f - ($h ? 1 : 0));
}

/** Next requisition reference: PREFIX-YYYY-0001. Pure. */
function rc_next_ref(string $prefix, string $year, ?string $last): string {
    $n = $last && preg_match('/-(\d+)$/', $last, $m) ? (int)$m[1] + 1 : 1;
    return sprintf('%s-%s-%04d', $prefix, $year, $n);
}

// ---------- database ----------

function rc_rights(array $u): array { return rc_rights_for(user_roles((int)$u['id'], !empty($u['is_owner'])), rc_settings()); }
function rc_log(PDO $db, string $kind, int $id, string $action, int $uid, string $detail = ''): void {
    $db->prepare('INSERT INTO rc_log (kind, ref_id, action, detail, user_id) VALUES (?,?,?,?,?)')->execute([$kind, $id, $action, mb_substr($detail, 0, 300), $uid]);
}
function rc_need(array $u, string $right): void { if (!rc_rights($u)[$right]) throw new RuntimeException('Your role cannot do this.'); }

function rc_req_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM rc_requisition WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

/** Requisitions the login sees: managers of the pipeline and approvers see all in their branch scope; a raiser their own. */
function rc_req_list(PDO $db, array $u, ?string $status = null): array {
    $R = rc_rights($u);
    $rows = $db->query('SELECT r.*, (SELECT COUNT(*) FROM rc_application a WHERE a.req_id = r.id AND a.stage NOT IN (\'rejected\',\'withdrawn\')) AS active,
                        (SELECT COUNT(*) FROM rc_application a WHERE a.req_id = r.id AND a.stage = \'joined\') AS joined FROM rc_requisition r ORDER BY FIELD(r.status,\'approval\',\'open\',\'hold\',\'draft\',\'filled\',\'closed\',\'rejected\'), r.id DESC')->fetchAll();
    return array_values(array_filter($rows, fn($r) => ($status === null || $r['status'] === $status)
        && (($R['manage'] || $R['approve']) ? ($r['branch_id'] === null || emp_in_scope($u, $r)) : (int)$r['raised_by'] === (int)$u['id'])));
}

function rc_req_save(PDO $db, array $u, int $id, array $in, bool $send): int {
    rc_need($u, 'raise');
    $set = rc_settings(); $money = data_allowed($u, 'pay');
    $r = rc_req_check($in + ['_form' => 1], array_map(fn($b) => (int)$b['id'], branches()), $money);
    if ($r['branch_id'] && !emp_in_scope($u, $r)) throw new RuntimeException('That branch is outside your scope.');
    if ($id) {
        $old = rc_req_get($db, $id);
        if (!$old) throw new RuntimeException('Requisition not found.');
        if ((int)$old['raised_by'] !== (int)$u['id'] && !rc_rights($u)['manage']) throw new RuntimeException('Only the person who raised it or HR changes it.');
        if (!in_array($old['status'], ['draft', 'rejected', 'open', 'hold'], true)) throw new RuntimeException('It cannot be changed now.');
        $cols = array_keys($r);
        $db->prepare('UPDATE rc_requisition SET ' . implode(', ', array_map(fn($k) => "$k = ?", $cols)) . ' WHERE id = ?')->execute([...array_values($r), $id]);
        rc_log($db, 'req', $id, 'edit', (int)$u['id'], implode(',', $cols));
        $status = $old['status'];
    } else {
        $year = date('Y');
        $last = $db->prepare('SELECT ref FROM rc_requisition WHERE ref LIKE ? ORDER BY id DESC LIMIT 1'); $last->execute([$set['ref_prefix'] . '-' . $year . '-%']);
        $r['ref'] = rc_next_ref($set['ref_prefix'], $year, $last->fetchColumn() ?: null);
        $r['raised_by'] = (int)$u['id'];
        $cols = array_keys($r);
        $db->prepare('INSERT INTO rc_requisition (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
        $id = (int)$db->lastInsertId();
        rc_log($db, 'req', $id, 'raise', (int)$u['id']);
        $status = 'draft';
    }
    if ($send && in_array($status, ['draft', 'rejected'], true)) {
        $next = $set['approval'] ? 'approval' : 'open';
        $db->prepare('UPDATE rc_requisition SET status = ? WHERE id = ?')->execute([$next, $id]);
        rc_log($db, 'req', $id, $next === 'open' ? 'open' : 'send', (int)$u['id']);
    }
    audit((int)$u['id'], 'rc_req', '#' . $id);
    return $id;
}

/** Approve or turn down a requisition. Never one's own. */
function rc_req_decide(PDO $db, array $u, int $id, bool $ok, string $note): void {
    rc_need($u, 'approve');
    $r = rc_req_get($db, $id);
    if (!$r || $r['status'] !== 'approval') throw new RuntimeException('Nothing waiting for approval.');
    if ((int)$r['raised_by'] === (int)$u['id']) throw new RuntimeException('Someone else approves what you raised.');
    if (!$ok && trim($note) === '') throw new RuntimeException('Say why it is not approved.');
    $db->prepare('UPDATE rc_requisition SET status = ?, approved_by = ?, approved_at = NOW(), approve_note = ? WHERE id = ?')->execute([$ok ? 'open' : 'rejected', (int)$u['id'], rc_text($note, 300), $id]);
    rc_log($db, 'req', $id, $ok ? 'approve' : 'reject', (int)$u['id']);
    audit((int)$u['id'], $ok ? 'rc_req_approve' : 'rc_req_reject', '#' . $id);
}

/** Hold, reopen, fill or close an open requisition (HR). Closing / holding needs a note. */
function rc_req_status(PDO $db, array $u, int $id, string $to, string $note): void {
    rc_need($u, 'manage');
    $r = rc_req_get($db, $id);
    $ok = ['open' => ['hold', 'filled', 'closed'], 'hold' => ['open', 'closed'], 'filled' => ['open'], 'closed' => ['open'], 'draft' => ['closed']];
    if (!$r || !in_array($to, $ok[$r['status']] ?? [], true)) throw new RuntimeException('That change is not possible now.');
    if (in_array($to, ['hold', 'closed'], true) && trim($note) === '') throw new RuntimeException('Say why.');
    $db->prepare('UPDATE rc_requisition SET status = ?, status_note = ? WHERE id = ?')->execute([$to, rc_text($note, 300), $id]);
    rc_log($db, 'req', $id, 'status:' . $to, (int)$u['id']);
}

function rc_cand_get(PDO $db, int $id): ?array { $s = $db->prepare('SELECT * FROM rc_candidate WHERE id = ?'); $s->execute([$id]); return $s->fetch() ?: null; }

/** Add a candidate (or find the same person) and put them on a position or the talent pool. Returns [candidate id, is new]. */
function rc_cand_add(PDO $db, array $u, array $in, ?int $reqId, ?array $cv): array {
    rc_need($u, 'manage');
    $c = rc_cand_check($in, data_allowed($u, 'pay'));
    if ($reqId) { $r = rc_req_get($db, $reqId); if (!$r || !in_array($r['status'], ['open', 'hold'], true)) throw new RuntimeException('That position is not open.'); }
    $q = $db->prepare('SELECT id, name, phone_key, email FROM rc_candidate WHERE removed_at IS NULL AND ((phone_key <> \'\' AND phone_key = ?) OR (email <> \'\' AND email = ?))');
    $q->execute([$c['phone_key'], $c['email']]);
    $same = rc_same_person($c, $q->fetchAll());
    $db->beginTransaction();
    try {
        if ($same) { $id = (int)$same['id']; $new = false; rc_cand_fill($db, $id, $c); }
        else {
            $c['created_by'] = (int)$u['id']; $c['notes'] ??= '';
            $cols = array_keys($c);
            $db->prepare('INSERT INTO rc_candidate (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($c));
            $id = (int)$db->lastInsertId(); $new = true;
            rc_log($db, 'cand', $id, 'add', (int)$u['id'], $c['source']);
        }
        if ($cv) rc_cv_add($db, $u, $id, $cv[0], $cv[1]);
        $db->prepare('INSERT IGNORE INTO rc_application (candidate_id, req_id, req_key, created_by) VALUES (?,?,?,?)')->execute([$id, $reqId, (int)$reqId, (int)$u['id']]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'rc_cand', '#' . $id . ($new ? ' new' : ' same person'));
    return [$id, $new];
}

/** Fill only the blanks of an existing candidate (another application, a repeat). */
function rc_cand_fill(PDO $db, int $id, array $c): void {
    $old = rc_cand_get($db, $id); if (!$old) return;
    $set = [];
    foreach ($c as $k => $v) if (array_key_exists($k, $old) && ($old[$k] === null || $old[$k] === '') && $v !== null && $v !== '' && !in_array($k, ['source', 'consent'], true)) $set[$k] = $v;
    if (!$set) return;
    $db->prepare('UPDATE rc_candidate SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $id]);
}

function rc_cand_edit(PDO $db, array $u, int $id, array $in): void {
    rc_need($u, 'manage');
    $old = rc_cand_get($db, $id);
    if (!$old || $old['removed_at'] !== null) throw new RuntimeException('Candidate not found.');
    $money = data_allowed($u, 'pay');
    $c = rc_cand_check($in, $money);
    $q = $db->prepare('SELECT id, name, phone_key, email FROM rc_candidate WHERE removed_at IS NULL AND id <> ? AND ((phone_key <> \'\' AND phone_key = ?) OR (email <> \'\' AND email = ?))');
    $q->execute([$id, $c['phone_key'], $c['email']]);
    if ($o = rc_same_person($c, $q->fetchAll(), $id)) throw new RuntimeException('Another candidate (' . $o['name'] . ') already has that mobile or email.');
    $changed = array_keys(array_filter($c, fn($v, $k) => (string)$v !== (string)($old[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
    if (!$changed) return;
    $db->prepare('UPDATE rc_candidate SET ' . implode(', ', array_map(fn($k) => "$k = ?", $changed)) . ' WHERE id = ?')->execute([...array_map(fn($k) => $c[$k], $changed), $id]);
    rc_log($db, 'cand', $id, 'edit', (int)$u['id'], implode(',', $changed));
}

/** Switch a candidate off (kept; e.g. an erasure request is handled by blanking — see rc_cand_erase). */
function rc_cand_remove(PDO $db, array $u, int $id, string $reason): void {
    rc_need($u, 'manage');
    if (trim($reason) === '') throw new RuntimeException('Say why.');
    $db->prepare('UPDATE rc_candidate SET removed_at = NOW(), removed_by = ?, removed_reason = ? WHERE id = ? AND removed_at IS NULL')->execute([(int)$u['id'], rc_text($reason, 300), $id]);
    rc_log($db, 'cand', $id, 'remove', (int)$u['id']);
}

/** A data-protection erasure request: personal details and CV files blanked, the record and its history stay. */
function rc_cand_erase(PDO $db, array $u, int $id, string $reason): void {
    rc_need($u, 'manage');
    if (!has_role($u, 'SUPERADMIN', 'HRMANAGER')) throw new RuntimeException('Only the Super Admin or HR Manager erases a candidate.');
    if (trim($reason) === '') throw new RuntimeException('Say what the request was.');
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE rc_candidate SET name = 'Erased candidate', phone = '', phone_key = '', email = '', city = '', cur_title = '', cur_company = '', qualification = '',
                      skills = '', cv_text = NULL, notes = '', cur_ctc = NULL, exp_ctc = NULL, removed_at = COALESCE(removed_at, NOW()), removed_by = ?, removed_reason = ? WHERE id = ?")
           ->execute([(int)$u['id'], 'Erased on request: ' . rc_text($reason, 260), $id]);
        $db->prepare("UPDATE rc_file SET data = '', name = 'erased', size = 0 WHERE candidate_id = ?")->execute([$id]);
        $db->prepare("UPDATE rc_feedback f JOIN rc_interview i ON i.id = f.interview_id JOIN rc_application a ON a.id = i.application_id SET f.comments = '' WHERE a.candidate_id = ?")->execute([$id]);
        rc_log($db, 'cand', $id, 'erase', (int)$u['id']);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    audit((int)$u['id'], 'rc_cand_erase', '#' . $id);
}

/** CV file by its own bytes: PDF, Word, ODT, RTF, text; 5 MB. Pure. */
function rc_cv_type(string $b, string $name): ?string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (str_starts_with($b, '%PDF-')) return 'application/pdf';
    if (str_starts_with($b, "PK\x03\x04") && $ext === 'docx') return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    if (str_starts_with($b, "PK\x03\x04") && $ext === 'odt') return 'application/vnd.oasis.opendocument.text';
    if (str_starts_with($b, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") && $ext === 'doc') return 'application/msword';
    if (str_starts_with($b, '{\\rtf')) return 'application/rtf';
    if ($ext === 'txt' && !preg_match('/[\x00-\x08\x0E-\x1F]/', substr($b, 0, 4096)) && mb_check_encoding($b, 'UTF-8')) return 'text/plain';
    return null;
}

function rc_cv_add(PDO $db, array $u, int $cid, string $bytes, string $name): string {
    if ($bytes === '') throw new RuntimeException('The CV file is empty.');
    if (strlen($bytes) > RC_CV_BYTES) throw new RuntimeException('A CV can be up to 5 MB.');
    $name = mb_substr((string)preg_replace('/[^\pL\pN ._()-]/u', '_', $name) ?: 'cv', 0, 180);
    $t = rc_cv_type($bytes, $name);
    if (!$t) throw new RuntimeException('A CV must be PDF, Word, ODT, RTF or a text file.');
    $id = bin2hex(random_bytes(16));
    $s = $db->prepare('INSERT INTO rc_file (id, candidate_id, name, type, size, data, uploaded_by) VALUES (?,?,?,?,?,?,?)');
    $s->bindValue(1, $id); $s->bindValue(2, $cid); $s->bindValue(3, $name); $s->bindValue(4, $t); $s->bindValue(5, strlen($bytes)); $s->bindValue(6, $bytes, PDO::PARAM_LOB); $s->bindValue(7, (int)$u['id']);
    $s->execute();
    if ($t === 'text/plain') $db->prepare('UPDATE rc_candidate SET cv_text = COALESCE(NULLIF(cv_text, \'\'), ?) WHERE id = ?')->execute([mb_substr($bytes, 0, 40000), $cid]);
    elseif ($t === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' && ($txt = rc_docx_text($bytes)) !== '')
        $db->prepare('UPDATE rc_candidate SET cv_text = COALESCE(NULLIF(cv_text, \'\'), ?) WHERE id = ?')->execute([mb_substr($txt, 0, 40000), $cid]);
    rc_log($db, 'cand', $cid, 'cv', (int)$u['id']);
    return $id;
}

/** Text of a .docx (for the ATS). Empty when it cannot be read. */
function rc_docx_text(string $bytes): string {
    if (!class_exists('ZipArchive')) return '';
    $tmp = tempnam(sys_get_temp_dir(), 'cv'); file_put_contents($tmp, $bytes);
    $z = new ZipArchive(); $x = '';
    if ($z->open($tmp) === true) { $x = (string)$z->getFromName('word/document.xml'); $z->close(); }
    @unlink($tmp);
    if ($x === '') return '';
    $x = preg_replace('/<\/w:p>/', "\n", $x);
    return trim(html_entity_decode(strip_tags((string)$x), ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

function rc_app_get(PDO $db, int $id): ?array {
    $s = $db->prepare('SELECT a.*, c.name, r.title AS req_title, r.ref, r.branch_id, r.department FROM rc_application a JOIN rc_candidate c ON c.id = a.candidate_id LEFT JOIN rc_requisition r ON r.id = a.req_id WHERE a.id = ?');
    $s->execute([$id]); return $s->fetch() ?: null;
}

/** Move an application to another stage. */
function rc_move(PDO $db, array $u, int $id, string $to, string $note): void {
    rc_need($u, 'manage');
    $a = rc_app_get($db, $id);
    if (!$a) throw new RuntimeException('Application not found.');
    if ($a['branch_id'] !== null && !emp_in_scope($u, $a)) throw new RuntimeException('That position is outside your scope.');
    if (($p = rc_move_problem((string)$a['stage'], $to, $note)) !== '') throw new RuntimeException($p);
    if ($to === 'offer') { $o = $db->prepare("SELECT COUNT(*) FROM rc_offer WHERE application_id = ? AND status = 'made'"); $o->execute([$id]); if (!(int)$o->fetchColumn()) throw new RuntimeException('Record the offer — it moves the application.'); }
    $db->prepare('UPDATE rc_application SET stage = ?, stage_at = NOW(), stage_note = ? WHERE id = ?')->execute([$to, rc_text($note, 300), $id]);
    rc_log($db, 'app', $id, 'stage:' . $a['stage'] . '>' . $to, (int)$u['id']);
    if ($to === 'joined' && $a['req_id']) rc_req_fill_check($db, (int)$a['req_id'], (int)$u['id']);
}

/** When as many people joined as openings, the requisition becomes Filled. */
function rc_req_fill_check(PDO $db, int $reqId, int $uid): void {
    $r = rc_req_get($db, $reqId);
    if (!$r || $r['status'] !== 'open') return;
    $n = $db->prepare("SELECT COUNT(*) FROM rc_application WHERE req_id = ? AND stage = 'joined'"); $n->execute([$reqId]);
    if ((int)$n->fetchColumn() >= (int)$r['openings']) { $db->prepare("UPDATE rc_requisition SET status = 'filled' WHERE id = ?")->execute([$reqId]); rc_log($db, 'req', $reqId, 'status:filled', $uid); }
}

/** Put a candidate on another position (or the pool). */
function rc_app_add(PDO $db, array $u, int $cid, ?int $reqId): void {
    rc_need($u, 'manage');
    if ($reqId) { $r = rc_req_get($db, $reqId); if (!$r || !in_array($r['status'], ['open', 'hold'], true)) throw new RuntimeException('That position is not open.'); }
    $db->prepare('INSERT IGNORE INTO rc_application (candidate_id, req_id, req_key, created_by) VALUES (?,?,?,?)')->execute([$cid, $reqId, (int)$reqId, (int)$u['id']]);
}

function rc_rate(PDO $db, array $u, int $id, int $n): void {
    rc_need($u, 'manage');
    $db->prepare('UPDATE rc_application SET rating = ? WHERE id = ?')->execute([$n >= 1 && $n <= 5 ? $n : null, $id]);
}

/** Schedule an interview: moves the application to Interview. */
function rc_interview_add(PDO $db, array $u, int $appId, array $in): int {
    rc_need($u, 'manage');
    $a = rc_app_get($db, $appId);
    if (!$a || in_array($a['stage'], RC_END, true)) throw new RuntimeException('This application is closed.');
    $at = str_replace('T', ' ', (string)($in['at'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $at)) throw new RuntimeException('Pick the date and time.');
    $mode = isset(RC_MODES[$in['mode'] ?? '']) ? (string)$in['mode'] : 'person';
    $place = rc_text($in['place'] ?? '', 300);
    if ($mode === 'video' && $place !== '' && !preg_match('#^https://#i', $place)) throw new RuntimeException('A video link must start with https://');
    $panel = array_values(array_unique(array_filter(array_map('intval', (array)($in['panel'] ?? [])))));
    $round = 1 + (int)$db->query('SELECT COUNT(*) FROM rc_interview WHERE application_id = ' . $appId . " AND status <> 'cancelled'")->fetchColumn();
    $db->prepare('INSERT INTO rc_interview (application_id, round, at, mode, place, panel, created_by) VALUES (?,?,?,?,?,?,?)')->execute([$appId, $round, $at . ':00', $mode, $place, implode(',', $panel), (int)$u['id']]);
    $iid = (int)$db->lastInsertId();
    if (in_array($a['stage'], ['new', 'screening', 'shortlisted', 'hold'], true)) $db->prepare("UPDATE rc_application SET stage = 'interview', stage_at = NOW() WHERE id = ?")->execute([$appId]);
    rc_log($db, 'app', $appId, 'interview', (int)$u['id'], 'round ' . $round);
    if ($panel && function_exists('mail_queue')) {
        $e = $db->prepare('SELECT email FROM users WHERE id = ? AND active = 1');
        foreach ($panel as $pid) { $e->execute([$pid]); $to = (string)$e->fetchColumn(); if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL))
            try { mail_queue($db, ['to' => $to, 'subject' => 'Interview round ' . $round . ' — ' . $a['name'], 'text' => 'You are on the panel for ' . $a['name'] . ' (' . ($a['req_title'] ?: 'talent pool') . ') on ' . $at . ".\nOpen My interviews to see the details and give feedback."], 'notice', 'rc_iv:' . $iid); } catch (Throwable $ex) {} }
    }
    return $iid;
}

function rc_interview_status(PDO $db, array $u, int $id, string $to): void {
    rc_need($u, 'manage');
    if (!in_array($to, ['done', 'cancelled', 'noshow', 'scheduled'], true)) throw new RuntimeException('Unknown.');
    $db->prepare('UPDATE rc_interview SET status = ? WHERE id = ?')->execute([$to, $id]);
}

/** Interviews where this login is on the panel (from 30 days ago). */
function rc_my_interviews(PDO $db, int $uid): array {
    $s = $db->prepare("SELECT i.*, c.name, c.cur_title, c.total_exp, c.qualification, c.skills, r.title AS req_title, a.candidate_id,
                       (SELECT COUNT(*) FROM rc_feedback f WHERE f.interview_id = i.id AND f.user_id = ?) AS mine
                       FROM rc_interview i JOIN rc_application a ON a.id = i.application_id JOIN rc_candidate c ON c.id = a.candidate_id LEFT JOIN rc_requisition r ON r.id = a.req_id
                       WHERE FIND_IN_SET(?, i.panel) AND i.at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND i.status <> 'cancelled' AND c.removed_at IS NULL ORDER BY i.at");
    $s->execute([$uid, (string)$uid]);
    return $s->fetchAll();
}

/** Panel feedback: only someone on that interview's panel (or HR), once each (changing it replaces it). */
function rc_feedback(PDO $db, array $u, int $iid, array $in): void {
    $s = $db->prepare('SELECT * FROM rc_interview WHERE id = ?'); $s->execute([$iid]); $i = $s->fetch();
    if (!$i || $i['status'] === 'cancelled') throw new RuntimeException('Interview not found.');
    $panel = array_map('intval', array_filter(explode(',', (string)$i['panel'])));
    if (!in_array((int)$u['id'], $panel, true) && !rc_rights($u)['manage']) throw new RuntimeException('Only the interview panel gives feedback.');
    $r = (int)($in['rating'] ?? 0);
    if ($r < 1 || $r > 5) throw new RuntimeException('Give a rating from 1 to 5.');
    $v = isset(RC_VERDICTS[$in['verdict'] ?? '']) ? (string)$in['verdict'] : throw new RuntimeException('Pick take forward, hold or not.');
    $db->prepare('INSERT INTO rc_feedback (interview_id, user_id, rating, verdict, comments) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), verdict = VALUES(verdict), comments = VALUES(comments), created_at = NOW()')
       ->execute([$iid, (int)$u['id'], $r, $v, mb_substr(trim(strip_tags((string)($in['comments'] ?? ''))), 0, 3000)]);
    rc_log($db, 'app', (int)$i['application_id'], 'feedback', (int)$u['id'], 'round ' . $i['round']);
}

/** Record an offer (moves to Offer made). The amount only with pay data rights. */
function rc_offer_add(PDO $db, array $u, int $appId, array $in): void {
    rc_need($u, 'manage');
    $a = rc_app_get($db, $appId);
    if (!$a || in_array($a['stage'], RC_END, true) || $a['stage'] === 'accepted') throw new RuntimeException('An offer cannot be made now.');
    $o = $db->prepare("SELECT COUNT(*) FROM rc_offer WHERE application_id = ? AND status = 'made'"); $o->execute([$appId]);
    if ((int)$o->fetchColumn()) throw new RuntimeException('There is already an open offer.');
    $join = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['join_date'] ?? '')) ? (string)$in['join_date'] : null;
    $till = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['valid_till'] ?? '')) ? (string)$in['valid_till'] : null;
    $ctc = data_allowed($u, 'pay') ? rc_num($in['ctc'] ?? '', 1e9) : null;
    $db->prepare('INSERT INTO rc_offer (application_id, ctc, join_date, valid_till, note, created_by) VALUES (?,?,?,?,?,?)')->execute([$appId, $ctc, $join, $till, rc_text($in['note'] ?? '', 300), (int)$u['id']]);
    $db->prepare("UPDATE rc_application SET stage = 'offer', stage_at = NOW() WHERE id = ?")->execute([$appId]);
    rc_log($db, 'app', $appId, 'offer', (int)$u['id']);
}

/** The candidate's answer to the offer, or HR withdraws it. */
function rc_offer_decide(PDO $db, array $u, int $offerId, string $to, string $note): void {
    rc_need($u, 'manage');
    if (!in_array($to, ['accepted', 'declined', 'withdrawn'], true)) throw new RuntimeException('Unknown.');
    $s = $db->prepare("SELECT * FROM rc_offer WHERE id = ? AND status = 'made'"); $s->execute([$offerId]); $o = $s->fetch();
    if (!$o) throw new RuntimeException('Offer not found.');
    if ($to !== 'accepted' && trim($note) === '') throw new RuntimeException('Say why.');
    $db->prepare('UPDATE rc_offer SET status = ?, decided_at = NOW(), note = CONCAT(note, ?) WHERE id = ?')->execute([$to, trim($note) !== '' ? ' · ' . rc_text($note, 150) : '', $offerId]);
    $stage = ['accepted' => 'accepted', 'declined' => 'withdrawn', 'withdrawn' => 'rejected'][$to];
    $db->prepare('UPDATE rc_application SET stage = ?, stage_at = NOW(), stage_note = ? WHERE id = ?')->execute([$stage, rc_text($note, 300), (int)$o['application_id']]);
    rc_log($db, 'app', (int)$o['application_id'], 'offer:' . $to, (int)$u['id']);
}

/** Start onboarding for an accepted offer (Onboarding module): one checklist, once. */
function rc_to_onboarding(PDO $db, array $u, int $appId): int {
    rc_need($u, 'manage');
    if (!module_on('onboarding')) throw new RuntimeException('Switch on Onboarding first.');
    require_once __DIR__ . '/onboarding.php';
    $a = rc_app_get($db, $appId);
    if (!$a || $a['stage'] !== 'accepted') throw new RuntimeException('Only an accepted offer starts onboarding.');
    if ($a['onb_id']) return (int)$a['onb_id'];
    $c = rc_cand_get($db, (int)$a['candidate_id']);
    $o = $db->prepare("SELECT join_date FROM rc_offer WHERE application_id = ? AND status = 'accepted' ORDER BY id DESC LIMIT 1"); $o->execute([$appId]);
    $oid = onb_add($db, $u, ['name' => $c['name'], 'designation' => (string)$a['req_title'], 'department' => (string)$a['department'], 'branch_id' => $a['branch_id'] ?? 0,
                             'join_date' => (string)($o->fetchColumn() ?: ''), 'email' => $c['email'], 'phone' => $c['phone'], 'note' => 'From recruitment ' . ($a['ref'] ?? 'talent pool')], false);
    $db->prepare('UPDATE rc_application SET onb_id = ? WHERE id = ?')->execute([$oid, $appId]);
    rc_log($db, 'app', $appId, 'onboarding', (int)$u['id'], '#' . $oid);
    return $oid;
}

/** Applications of a position (or the pool), with the candidate and the ATS match. */
function rc_board(PDO $db, ?int $reqId, string $today): array {
    $s = $db->prepare('SELECT a.id AS app_id, a.stage, a.stage_at, a.rating, a.onb_id, c.* FROM rc_application a JOIN rc_candidate c ON c.id = a.candidate_id
                       WHERE a.req_key = ? AND c.removed_at IS NULL ORDER BY a.stage_at DESC');
    $s->execute([(int)$reqId]);
    $r = $reqId ? rc_req_get($db, $reqId) : null;
    $out = [];
    foreach ($s as $x) { $x['ats'] = $r ? rc_ats($x, $r, $today) : null; $out[] = $x; }
    return $out;
}

/** Find candidates by name / mobile / email / skill. */
function rc_search(PDO $db, string $q): array {
    $q = trim($q); if ($q === '') return [];
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%'; $pk = rc_phone_key($q);
    $s = $db->prepare('SELECT c.*, (SELECT GROUP_CONCAT(CONCAT(COALESCE(r.title,\'Talent pool\'),\': \',a.stage) SEPARATOR \' · \') FROM rc_application a LEFT JOIN rc_requisition r ON r.id = a.req_id WHERE a.candidate_id = c.id) AS apps
                       FROM rc_candidate c WHERE c.removed_at IS NULL AND (c.name LIKE ? OR c.email LIKE ? OR c.skills LIKE ? OR c.cur_title LIKE ?' . (strlen($pk) >= 6 ? ' OR c.phone_key LIKE ?' : '') . ') ORDER BY c.id DESC LIMIT 100');
    $p = [$like, $like, $like, $like]; if (strlen($pk) >= 6) $p[] = '%' . $pk . '%';
    $s->execute($p);
    return $s->fetchAll();
}

/** Counts for the overview: open positions, applications by stage, interviews this week, offers out. */
function rc_overview(PDO $db, array $u): array {
    $reqs = rc_req_list($db, $u);
    $stage = array_fill_keys(array_keys(RC_STAGES), 0);
    foreach ($db->query('SELECT a.stage, COUNT(*) n FROM rc_application a JOIN rc_candidate c ON c.id = a.candidate_id WHERE c.removed_at IS NULL GROUP BY a.stage') as $r) $stage[$r['stage']] = (int)$r['n'];
    $iv = (int)$db->query("SELECT COUNT(*) FROM rc_interview WHERE status = 'scheduled' AND at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    return ['open' => count(array_filter($reqs, fn($r) => $r['status'] === 'open')), 'approval' => count(array_filter($reqs, fn($r) => $r['status'] === 'approval')),
            'stage' => $stage, 'interviews' => $iv, 'offers' => (int)$db->query("SELECT COUNT(*) FROM rc_offer WHERE status = 'made'")->fetchColumn()];
}
