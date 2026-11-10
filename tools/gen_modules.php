<?php
/**
 * Writes modules/<key>/index.php (gated entry page) and modules/<key>/README.md (spec) for every catalog module
 * whose entry is under modules/. Existing index.php files are left alone once a module has real code
 * (status "built"); README.md is regenerated so the spec follows the catalog.
 *
 *   php tools/gen_modules.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
const WN_ROOT = __DIR__ . '/..';
$cat = require WN_ROOT . '/config/modules.php';
$roles = (require WN_ROOT . '/config/rbac.php')['roles'];

$n = 0;
foreach ($cat as $key => $m) {
    if (!str_starts_with($m['entry'], 'modules/')) continue;
    $dir = WN_ROOT . '/modules/' . $key;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $idx = $dir . '/index.php';
    if ($m['status'] !== 'built' || !is_file($idx)) {
        file_put_contents($idx, <<<PHP
<?php
// {$m['name']} — entry page. Gated by the module switch and role access (Admin → Modules).
declare(strict_types=1);
require __DIR__ . '/../../lib/bootstrap.php';
\$u = module_require('$key');
ui_head('{$m['name']}', \$u, '$key');
?>
<section class="card">
  <h2>{$m['name']}</h2>
  <p><?= h(module_catalog()['$key']['about']) ?></p>
  <p class="note">This module is being prepared. See <code>modules/$key/README.md</code> for its specification and porting status.</p>
</section>
<?php ui_foot();

PHP);
    }
    $needs = ($m['needs'] ?? []) ? implode(', ', array_map(fn($k) => $cat[$k]['name'] . " (`$k`)", $m['needs'])) : 'nothing';
    $rl = implode(', ', array_map(fn($r) => $roles[$r]['label'] ?? $r, $m['roles']));
    $refs = implode("\n", array_map(fn($r) => "- `$r`", $m['ref'] ?? []));
    $status = ['built' => 'Ready', 'port' => 'Porting from the reference build', 'planned' => 'Planned — specification only'][$m['status']];
    file_put_contents($dir . '/README.md', <<<MD
# {$m['name']}

**Key:** `$key` · **Group:** {$m['group']} · **Status:** $status

{$m['about']}

## Access
- Switched on by the Super Admin on **Admin → Modules**; needs $needs.
- Default roles when first switched on: $rl. The Super Admin can change this and add per-person exceptions.
- Every page starts with `module_require('$key')`; every API with `module_require('$key', true)`.

## Reference build (port source)
$refs

## Porting rules
1. Take the logic, never the data: no names, employee codes, phone numbers, emails, addresses, amounts, GSTIN / PAN / ID numbers, bank details or company wording.
2. Every organisation value (company name, branches, states, shifts, holidays, rates, thresholds) comes from `org()`, `branches()` or the module's own settings — never a constant.
3. Rules that were decided for one organisation (named people, one-off seeds, fixed lists) become settings with neutral defaults.
4. Country-specific statutory rules go into a rule pack (`docs/RULE_PACKS.md`), not into module code.
5. Tests use synthetic data only (`TEST –` names); add them under `tests/cases/`.
6. `python3 tools/guard.py` must pass before the commit.
7. Record what was ported and what became a setting in `modules/$key/NOTES.md` (this README is regenerated).

MD);
    $n++;
}
echo "Wrote $n module folders\n";
