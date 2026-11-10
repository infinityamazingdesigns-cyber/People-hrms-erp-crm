<?php
/** Writes docs/MODULES.md and docs/ROADMAP.md from config/modules.php.  php tools/gen_docs.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$cat = require __DIR__ . '/../config/modules.php';
$roles = (require __DIR__ . '/../config/rbac.php')['roles'];
$st = ['built' => 'Ready', 'port' => 'Porting', 'planned' => 'Planned'];
$md = "# Modules\n\nGenerated from `config/modules.php` by `php tools/gen_docs.php`.\n";
$count = array_count_values(array_column($cat, 'status'));
$md .= "\n**" . count($cat) . " modules** — " . implode(' · ', array_map(fn($k) => ($count[$k] ?? 0) . ' ' . strtolower($st[$k]), array_keys($st))) . "\n";
foreach (MODULE_GROUPS as $g => $gl) {
    $md .= "\n## $gl\n\n| Module | What it does | Needs | Default roles | Status |\n|---|---|---|---|---|\n";
    foreach ($cat as $k => $m) if ($m['group'] === $g)
        $md .= "| **{$m['name']}** `$k` | {$m['about']} | " . (implode(', ', $m['needs'] ?? []) ?: '—') . ' | '
            . implode(', ', array_map(fn($r) => $roles[$r]['label'], $m['roles'])) . " | {$st[$m['status']]} |\n";
}
file_put_contents(__DIR__ . '/../docs/MODULES.md', $md);
$rm = "# Roadmap\n\nGenerated from `config/modules.php`. Order of porting: see `PORTING.md`.\n";
foreach (['built' => 'Ready', 'port' => 'Next — porting', 'planned' => 'Later — planned'] as $s => $label) {
    $rm .= "\n## $label\n\n";
    foreach ($cat as $k => $m) if ($m['status'] === $s) $rm .= "- [" . ($s === 'built' ? 'x' : ' ') . "] {$m['name']} (`$k`)\n";
}
file_put_contents(__DIR__ . '/../docs/ROADMAP.md', $rm);
echo "docs written\n";
