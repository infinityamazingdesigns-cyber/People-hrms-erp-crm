<?php
// Read a table file (CSV or Excel .xlsx) into rows of cells, on the server, without keeping the file. Shared by
// imports. Columns are then found by their titles (tbl_map()). Pure apart from reading the given path.
declare(strict_types=1);

/** Rows of a CSV / .xlsx file (first sheet). Throws on a file it cannot read. */
function tbl_read(string $path, string $name, int $maxRows = 20000): array {
    $b = (string)file_get_contents($path, false, null, 0, 4);
    if ($b === "PK\x03\x04") return tbl_xlsx($path, $maxRows);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true)) throw new RuntimeException('Use a CSV or Excel (.xlsx) file.');
    $t = (string)file_get_contents($path);
    if (str_starts_with($t, "\xEF\xBB\xBF")) $t = substr($t, 3);
    if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
    $first = strtok($t, "\n") ?: '';
    $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $rows = []; $fh = fopen('php://memory', 'r+'); fwrite($fh, $t); rewind($fh);
    while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) { if (count($rows) >= $maxRows) break; $rows[] = array_map(fn($x) => trim((string)$x), $r); }
    fclose($fh);
    return $rows;
}

function tbl_xlsx(string $path, int $maxRows): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Excel files need the zip extension on this server; save the file as CSV instead.');
    $z = new ZipArchive();
    if ($z->open($path) !== true) throw new RuntimeException('That Excel file could not be opened.');
    $ss = [];
    if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $d = @simplexml_load_string($x, 'SimpleXMLElement', LIBXML_NONET);
        if ($d) foreach ($d->si as $si) { $s = ''; if (isset($si->t)) $s = (string)$si->t; else foreach ($si->r as $r) $s .= (string)$r->t; $ss[] = $s; }
    }
    $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
    $z->close();
    if ($sheet === false) throw new RuntimeException('That Excel file has no first sheet.');
    $d = @simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET);
    if (!$d) throw new RuntimeException('That Excel file could not be read.');
    $rows = [];
    foreach ($d->sheetData->row as $row) {
        if (count($rows) >= $maxRows) break;
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r']; $col = 0;
            for ($i = 0; $i < strlen($ref) && ctype_alpha($ref[$i]); $i++) $col = $col * 26 + (ord(strtoupper($ref[$i])) - 64);
            $t = (string)$c['t']; $v = (string)$c->v;
            if ($t === 's') $v = $ss[(int)$v] ?? '';
            elseif ($t === 'inlineStr') $v = (string)$c->is->t;
            $cells[max(0, $col - 1)] = trim($v);
        }
        if (!$cells) { $rows[] = []; continue; }
        $line = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $i => $v) $line[$i] = $v;
        $rows[] = $line;
    }
    return $rows;
}

/** A column title made comparable: lower case, letters and digits only. Pure. */
function tbl_key(string $s): string { return (string)preg_replace('/[^a-z0-9]/', '', strtolower($s)); }

/**
 * Find the title row (the first row matching at least $need fields) and map each field to its column.
 * $cols = field => [accepted titles]. Returns [header row index, field => column index]. Pure.
 */
function tbl_map(array $rows, array $cols, int $need = 1): array {
    foreach (array_slice($rows, 0, 15, true) as $i => $r) {
        $m = [];
        foreach ($r as $j => $cell) { $k = tbl_key((string)$cell); foreach ($cols as $f => $names) if (!isset($m[$f]) && in_array($k, $names, true)) { $m[$f] = $j; break; } }
        if (count($m) >= $need) return [$i, $m];
    }
    return [-1, []];
}
