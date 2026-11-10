<?php
/** Applies db/migrations/*.sql once each, in name order, recorded in schema_migrations. */
declare(strict_types=1);

/** Split a migration file into statements (no procedures in our migrations, so ';' at line end is enough). Pure. */
function migrate_split(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', preg_split('/;\s*$/m', $sql)), fn($s) => $s !== ''));
}

/** @return list<string> names applied now */
function migrate(PDO $db): array {
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(120) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = array_column($db->query('SELECT name FROM schema_migrations')->fetchAll(), 'name');
    $files = glob(WN_ROOT . '/db/migrations/*.sql') ?: [];
    sort($files);
    $now = [];
    foreach ($files as $f) {
        $name = basename($f);
        if (in_array($name, $done, true)) continue;
        foreach (migrate_split((string)file_get_contents($f)) as $stmt) $db->exec($stmt);   // DDL commits by itself in MySQL
        $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
        $now[] = $name;
    }
    return $now;
}
