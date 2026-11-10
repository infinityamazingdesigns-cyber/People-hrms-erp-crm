<?php
/**
 * WorkNest HR — core tests (no database needed: the rules are pure functions).
 *   php tests/run.php
 * New tests go in tests/cases/<ID>.php, each calling t('<ID> what it checks', bool).
 */
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
$GLOBALS['WN_CFG'] = ['seal_key' => str_repeat('a', 64), 'db_pass' => 'x', 'db_name' => 'x'];

$fail = 0; $n = 0; $ids = [];
function t(string $name, bool $ok): void {
    global $fail, $n, $ids;
    $n++;
    $id = strtok($name, ' ');
    if (isset($ids[$id])) { echo "DUPLICATE TEST ID $id\n"; $fail++; }
    $ids[$id] = true;
    if (!$ok) { $fail++; echo "FAIL  $name\n"; }
}

$cat = module_catalog();

/* C01 catalog is well formed */
$okCat = true;
foreach ($cat as $k => $m) {
    if (!preg_match('/^[a-z]{2,20}$/', $k) || !isset(MODULE_GROUPS[$m['group']]) || !in_array($m['status'], ['built', 'port', 'planned'], true)) $okCat = false;
    foreach ($m['needs'] ?? [] as $d) if (!isset($cat[$d])) $okCat = false;
    foreach ($m['roles'] as $r) if (!isset(rbac_config()['roles'][$r])) $okCat = false;
}
t('C01 every module has a known group, status, roles and needs', $okCat);
t('C02 core modules include org, modules, users, audit, guide', !array_diff(['org', 'modules', 'users', 'audit', 'guide'], array_keys(array_filter($cat, fn($m) => !empty($m['core'])))));

/* C03 dependency closure */
t('C03 payroll pulls in attendance, leave and employees', !array_diff(['attendance', 'leave', 'employees'], module_needs_all('payroll', $cat)));
$circ = false; foreach (array_keys($cat) as $k) if (in_array($k, module_needs_all($k, $cat), true)) $circ = true;
t('C04 no module needs itself (no circles)', !$circ);

/* M01–M06 module gate */
$pay = $cat['payroll'];
t('M01 module off → refused even for allowed role', !module_decide($pay, false, ['CEO'], ['CEO'], false, null));
t('M02 module on + role allowed → open', module_decide($pay, true, ['CEO'], ['CEO'], false, null));
t('M03 module on + role not allowed → refused', !module_decide($pay, true, ['CEO'], ['EMPLOYEE'], false, null));
t('M04 per-person allow opens it', module_decide($pay, true, ['CEO'], ['EMPLOYEE'], false, 'allow'));
t('M05 per-person deny closes it', !module_decide($pay, true, ['CEO'], ['CEO'], false, 'deny'));
t('M06 owner always opens core modules', module_decide($cat['users'], false, [], [], true, 'deny'));

/* O01–O04 owner lock */
$owner = ['id' => 1, 'is_owner' => 1, 'roles' => ['SUPERADMIN']];
$sa = ['id' => 2, 'is_owner' => 0, 'roles' => ['SUPERADMIN']];
t('O01 another Super Admin cannot switch the owner off', owner_guard($sa, $owner, 'deactivate') !== null);
t('O02 nobody removes the owner\'s Super Admin', owner_guard($sa, $owner, 'remove_superadmin') !== null);
t('O03 only the owner resets the owner\'s password', owner_guard($sa, $owner, 'reset_password') !== null && owner_guard($owner, $owner, 'reset_password') === null);
t('O04 owner passes SUPERADMIN checks even with no stored role', has_role(['id' => 1, 'is_owner' => 1, 'roles' => []], 'SUPERADMIN'));

/* R01–R03 roles */
$hr = ['id' => 3, 'is_owner' => 0, 'roles' => ['HRMANAGER']];
t('R01 HR Manager can give Employee', can_assign($hr, 'EMPLOYEE'));
t('R02 HR Manager cannot give Super Admin or CEO', !can_assign($hr, 'SUPERADMIN') && !can_assign($hr, 'CEO'));
t('R03 Employee-only login is self-only; owner never is', self_only(['roles' => ['EMPLOYEE']]) && !self_only($owner));
t('R04 pay data only for listed roles', data_allowed(['roles' => ['CEO']], 'pay') && !data_allowed($hr, 'pay'));

/* G01–G04 organisation checks */
t('G01 colour must be #RRGGBB', org_check('color_primary', 'red')[1] !== null && org_check('color_primary', '#13265C')[1] === null);
t('G02 country from the list only', org_check('country', 'ZZ')[1] !== null);
t('G03 bad time zone refused', org_check('timezone', 'Mars/Base')[1] !== null && org_check('timezone', 'Asia/Dubai')[1] === null);
$brs = [['name' => 'Head Office', 'code' => 'HO', 'aliases' => 'Main office, HQ']];
t('G04 branch spellings resolve to one name', branch_label('hq', $brs) === 'Head Office' && branch_label('Elsewhere', $brs) === 'Elsewhere');

/* S01–S03 security */
$box = seal('secret-123');
t('S01 seal → unseal round trip, ciphertext differs', $box !== 'secret-123' && unseal($box) === 'secret-123');
t('S02 tampered box opens to nothing', unseal(substr($box, 0, -2) . 'AA') === '');
t('S03 mask shows last 4 only', mask('123456789012') === 'XXXXXXXX9012');
t('S04 weak passwords refused', pw_problem('short') !== null && pw_problem('onlyletters') !== null && pw_problem('Good-pass-2026') === null);

/* A01 audit chain hash links rows */
$h1 = audit_row_hash(null, 1, 'login', '', '1.2.3.4', '2026-01-01 00:00:00');
$h2 = audit_row_hash($h1, 1, 'logout', '', '1.2.3.4', '2026-01-01 01:00:00');
t('A01 chain hash depends on the previous row', $h2 !== audit_row_hash(null, 1, 'logout', '', '1.2.3.4', '2026-01-01 01:00:00'));
t('A02 sensitive families are chained', audit_is_sensitive('login') && audit_is_sensitive('module_on') && !audit_is_sensitive('page_view'));

/* D01 migrations split */
t('D01 migration splits into statements', count(migrate_split((string)file_get_contents(WN_ROOT . '/db/migrations/0001_core.sql'))) >= 12);

foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $f) require $f;

echo ($fail ? "FAILED $fail of $n\n" : "OK $n tests\n");
exit($fail ? 1 : 0);
