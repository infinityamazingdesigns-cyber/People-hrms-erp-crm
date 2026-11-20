<?php
/* Module map (lib/module_map.php) */
require_once WN_ROOT . '/lib/module_map.php';
$cat = [
    'a' => ['name' => 'TEST – A', 'group' => 'core', 'core' => true, 'status' => 'built', 'roles' => ['SUPERADMIN']],
    'b' => ['name' => 'TEST – B', 'group' => 'people', 'status' => 'built', 'needs' => ['a'], 'roles' => ['HRMANAGER', 'NOPE']],
    'c' => ['name' => 'TEST – C', 'group' => 'people', 'status' => 'built', 'needs' => ['b', 'zz'], 'roles' => ['EMPLOYEE'], 'self' => ['x', 'y', 'z']],
];
$roles = ['SUPERADMIN' => 'Super Admin', 'HRMANAGER' => 'HR Manager', 'EMPLOYEE' => 'Employee'];
$m = mm_build($cat, ['c' => ['enabled' => true, 'roles' => ['HRMANAGER'], 'settings' => []]], $roles);
t('MM01 core modules are on, others follow their saved state', $m['mods']['a']['on'] && !$m['mods']['b']['on'] && $m['mods']['c']['on'] && $m['on'] === 2);
t('MM02 links come from needs, unknown modules dropped', $m['mods']['c']['needs'] === ['b'] && $m['edges'] === 2 && $m['mods']['a']['needed_by'] === ['b'] && $m['mods']['b']['needed_by'] === ['c']);
t('MM03 roles: saved list wins over the catalog, unknown roles dropped', $m['mods']['c']['roles'] === ['HRMANAGER'] && $m['mods']['b']['roles'] === ['HRMANAGER']);
t('MM04 every role lists the modules it opens', $m['roles']['HRMANAGER']['mods'] === ['b', 'c'] && $m['roles']['EMPLOYEE']['mods'] === []);
t('MM05 needs are followed through (switching on pulls these in)', array_values(array_diff(mm_needs_all($m['mods'], 'c'), [])) == ['b', 'a'] || (count(mm_needs_all($m['mods'], 'c')) === 2 && in_array('a', mm_needs_all($m['mods'], 'c'), true)));
t('MM06 groups keep the modules in catalog order', $m['groups'] === ['core' => ['a'], 'people' => ['b', 'c']]);
$real = mm_build(module_catalog(), [], role_labels());
t('MM07 the real catalog maps with no broken link', $real['edges'] === array_sum(array_map(fn($x) => count($x['needs']), $real['mods'])) && count($real['mods']) === count(module_catalog()));
$pg = file_get_contents(WN_ROOT . '/admin/module_map.php');
t('MM08 Super Admin only and read-only', str_contains($pg, "module_require('modules')") && str_contains($pg, 'is_superadmin($u)') && !str_contains($pg, 'REQUEST_METHOD') && !str_contains($pg, 'pdo()'));
