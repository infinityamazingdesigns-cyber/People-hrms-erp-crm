<?php
// Lite mode — small server-built pages for slow internet: home, punch, own attendance, leave, attendance corrections,
// letters. One tiny inline stylesheet, no web fonts or frameworks; script only on the punch page (camera + GPS). Every
// rule stays in its own module's library — Lite only calls it (att_record via the punch screen, lv_create, atr_create).
// A browser chooses Lite at sign-in (cookie wn_lite); "Open the full site" clears it.
declare(strict_types=1);

require_once __DIR__ . '/employees.php';

const LITE_COOKIE = 'wn_lite';

/* ---------------- pure ---------------- */

/** Should the sign-in form tick "Use Lite" by itself? The browser's Save-Data hint, or the choice already made. */
function lite_suggest(array $server, array $cookie): bool { return strtolower(trim((string)($server['HTTP_SAVE_DATA'] ?? ''))) === 'on' || ($cookie[LITE_COOKIE] ?? '') === '1'; }
/** Where the home page sends this browser: 'lite', 'full' (clear the choice) or '' (stay). */
function lite_route(bool $on, array $cookie, array $get): string {
    if (!$on) return '';
    if (!empty($get['full'])) return 'full';
    return ($cookie[LITE_COOKIE] ?? '') === '1' ? 'lite' : '';
}
/** Day line → short status for the month list. */
function lite_day(array $l): string {
    $t = fn($x) => $x ? substr((string)$x, 0, 5) : '';
    $s = (string)$l['status'];
    if (!empty($l['leave'])) $s = 'Leave' . (!empty($l['leave']['type']) ? ' (' . $l['leave']['type'] . ')' : '') . ($s !== '' && $s !== 'Absent' ? ' · ' . $s : '');
    return trim($s . ($l['in'] ? ' ' . $t($l['in']) . ($l['out'] ? '–' . $t($l['out']) : '') : ''));
}

/* ---------------- page ---------------- */

function lite_pref(bool $on): void {
    if (headers_sent()) return;
    setcookie(LITE_COOKIE, $on ? '1' : '', ['expires' => $on ? time() + 365 * 86400 : time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => cookie_secure()]);
    if ($on) $_COOKIE[LITE_COOKIE] = '1'; else unset($_COOKIE[LITE_COOKIE]);
}
function lite_head(string $title, ?array $u): void {
    $p = ui_color('color_primary', '#13265C'); $f = flash(); $b = wn_base(); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($title) ?></title>
<style>body{font:15px/1.45 system-ui,sans-serif;margin:0;color:#111;background:#f5f6f8}header{background:<?= h($p) ?>;color:#fff;padding:10px 12px}header a{color:#fff}main{padding:10px;max-width:640px;margin:0 auto}
.c{background:#fff;border:1px solid #ddd;border-radius:8px;padding:10px;margin:0 0 10px}a{color:<?= h($p) ?>}.b{display:inline-block;background:<?= h($p) ?>;color:#fff;border:0;border-radius:6px;padding:9px 14px;font:inherit;text-decoration:none}
.m{color:#666;font-size:13px}.e{background:#fde8e8;padding:8px;border-radius:6px}.o{background:#e7f6ea;padding:8px;border-radius:6px}input,select{font:inherit;width:100%;box-sizing:border-box;padding:7px;margin:2px 0 8px}
table{width:100%;border-collapse:collapse}td{padding:4px 2px;border-bottom:1px solid #eee;vertical-align:top}nav{display:flex;flex-wrap:wrap;gap:2px 12px}body{overflow-wrap:anywhere}@media(prefers-color-scheme:dark){body{background:#111;color:#eee}.c{background:#1c1c1c;border-color:#333}a{color:#9cc3ff}td{border-color:#333}.m{color:#aaa}}</style>
</head><body><header><b><?= h(org('name')) ?></b> · <?= h($title) ?><?php if ($u): ?><br><nav><a href="<?= $b ?>modules/lite/">Home</a><?php foreach (lite_links($u) as [$l, $href]): ?><a href="<?= $b . $href ?>"><?= h($l) ?></a><?php endforeach; ?></nav><?php endif; ?></header><main>
<?php if ($f): ?><p class="<?= $f[0] === 'err' ? 'e' : 'o' ?>"><?= h($f[1]) ?></p><?php endif;
}
function lite_foot(?array $u): void { $b = wn_base(); ?>
<p class="m"><?php if ($u): ?><a href="<?= $b ?>index.php?full=1">Open the full site</a> · <a href="<?= $b ?>logout.php">Sign out</a><?php endif; ?></p></main></body></html>
<?php }
/** Lite screens this login may open (each behind the full module's own gate). */
function lite_links(array $u): array {
    $o = [];
    if (module_allowed($u, 'attendance')) { $o[] = ['Punch', 'modules/lite/punch.php']; $o[] = ['Attendance', 'modules/lite/att.php']; $o[] = ['Requests', 'modules/lite/req.php']; }
    if (module_allowed($u, 'leave')) $o[] = ['Leave', 'modules/lite/leave.php'];
    if (module_on('letters') && module_person((int)$u['id'], 'letters') !== 'deny') $o[] = ['Letters', 'modules/lite/letters.php'];
    return $o;
}
/** The login's own employee record, or null. */
function lite_emp(PDO $db, array $u): ?array { return !empty($u['emp_code']) ? emp_by_code($db, (string)$u['emp_code']) : null; }
