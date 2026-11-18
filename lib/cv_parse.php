<?php
/**
 * CV / resume reader for the careers form and the recruitment pipeline. Free and on this server, nothing sent out:
 *   1. Text out of the file: PDF (pdftotext when the host has it, else the pure-PHP reader below — Flate streams,
 *      object streams, ToUnicode maps, Tj / TJ / ' / " with line and word breaks) and Word .docx (ZipArchive).
 *      Photos and scanned PDFs have no text: nothing is read, the candidate fills the form as usual.
 *   2. Rules on that text (cvp_fields()): name, mobile, email, date of birth, gender, city (from the places the caller
 *      gives — the organisation's branches and a settings list), total experience, current / last employer and
 *      designation, highest qualification, skills, languages, LinkedIn, current / expected salary, notice period.
 *      Every value is only a suggestion: the form puts it in empty boxes and the candidate checks it.
 * Pure functions (no database). Tests CV*.
 */
declare(strict_types=1);

const CVP_MAX_BYTES = 5 * 1048576;
const CVP_MAX_TEXT = 60000;   // characters kept from a CV

/* ======================================================================= text out of a file */

/** Kind of file by its own bytes: 'pdf' | 'docx' | 'jpg' | 'png' | '' (anything else is refused by the form). */
function cvp_kind(string $bytes): string {
    if (str_starts_with($bytes, '%PDF-')) return 'pdf';
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) return 'jpg';
    if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) return 'png';
    if (str_starts_with($bytes, "PK\x03\x04") && str_contains(substr($bytes, 0, 4000) . substr($bytes, -22000), 'word/document.xml')) return 'docx';
    return '';
}

/** @return array{text:string, method:string} */
function cvp_text(string $bytes): array {
    $kind = cvp_kind($bytes);
    $t = ''; $m = 'none';
    try {
        if ($kind === 'docx') { $t = cvp_docx_text($bytes); $m = 'docx'; }
        elseif ($kind === 'pdf') {
            $t = cvp_pdftotext($bytes); $m = 'pdftotext';
            if (cvp_letters($t) < 40) { $t = cvp_pdf_text($bytes); $m = 'pdf'; }
        }
    } catch (Throwable $e) { $t = ''; }
    $t = cvp_clean($t);
    if (cvp_letters($t) < 40) return ['text' => '', 'method' => $kind === '' ? 'none' : $kind . '-no-text'];
    return ['text' => mb_substr($t, 0, CVP_MAX_TEXT), 'method' => $m];
}

function cvp_letters(string $t): int { return (int)preg_match_all('/\p{L}/u', $t); }

function cvp_clean(string $t): string {
    if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
    $t = str_replace(["\r\n", "\r", "\f", "\u{00A0}", "\u{2002}", "\u{2003}", "\u{2009}", "\u{200B}", "\u{FEFF}"], ["\n", "\n", "\n", ' ', ' ', ' ', ' ', '', ''], $t);
    $t = str_replace(["\u{2013}", "\u{2014}", "\u{2012}", "\u{2212}"], '-', $t);
    $t = str_replace(["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}"], ["'", "'", '"', '"'], $t);
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $t) ?? '';
    $t = preg_replace('/[ \t]+/u', ' ', $t) ?? '';
    $t = preg_replace('/ *\n */', "\n", $t) ?? '';
    return trim(preg_replace('/\n{3,}/', "\n\n", $t) ?? '');
}

/** The host's pdftotext, when it has one and may run it ('' otherwise). */
function cvp_pdftotext(string $bytes): string {
    if (!function_exists('proc_open') || in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) return '';
    $bin = '';
    foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/bin/pdftotext'] as $p) if (@is_executable($p)) { $bin = $p; break; }
    if ($bin === '') return '';
    $in = tempnam(sys_get_temp_dir(), 'cvp');
    if ($in === false) return '';
    try {
        file_put_contents($in, $bytes);
        $p = @proc_open([$bin, '-layout', '-enc', 'UTF-8', '-q', $in, '-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) return '';
        stream_set_timeout($pipes[1], 8);
        $out = (string)stream_get_contents($pipes[1], 2 * 1048576);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        // -layout keeps columns apart with runs of spaces: two-column CVs read better as separate lines
        return preg_replace('/ {4,}/', "\n", $out) ?? $out;
    } finally { @unlink($in); }
}

/* ---------- Word .docx ---------- */

function cvp_docx_text(string $bytes): string {
    $f = tempnam(sys_get_temp_dir(), 'cvd');
    if ($f === false) return '';
    try {
        file_put_contents($f, $bytes);
        $z = new ZipArchive();
        if ($z->open($f) !== true) return '';
        $out = '';
        foreach (['word/document.xml', 'word/header1.xml', 'word/header2.xml'] as $part) {
            $x = $z->getFromName($part);
            if (!is_string($x) || strlen($x) > 20 * 1048576) continue;
            $x = preg_replace('#<w:tab[^>]*/>#', "\t", $x) ?? $x;
            $x = preg_replace('#<w:(?:br|cr)[^>]*/>#', "\n", $x) ?? $x;
            $x = preg_replace('#</w:p>#', "\n", $x) ?? $x;
            $x = preg_replace('#</w:tc>#', ' | ', $x) ?? $x;
            $x = preg_replace('#<w:instrText[^>]*>.*?</w:instrText>#s', '', $x) ?? $x;   // field codes (HYPERLINK …)
            $part_ = $part === 'word/document.xml' ? 'body' : 'head';
            $txt = html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, 'UTF-8');
            $out = $part_ === 'head' ? $txt . "\n" . $out : $out . $txt;
        }
        $z->close();
        return str_replace(' | ', ' ', preg_replace('/( \| )+\n/', "\n", $out) ?? $out);
    } finally { @unlink($f); }
}

/* ---------- PDF (pure PHP) ---------- */

/** Decoded stream bytes ('' when the filter is not one we read). */
function cvp_pdf_decode(string $dict, string $raw): string {
    if (!preg_match('#/Filter\s*(\[[^\]]*\]|/\w+)#', $dict, $m)) return $raw;
    $filters = preg_match_all('#/(\w+)#', $m[1], $f) ? $f[1] : [];
    $data = $raw;
    foreach ($filters as $flt) {
        if ($flt === 'FlateDecode' || $flt === 'Fl') {
            $d = @gzuncompress($data);
            if ($d === false) $d = @gzinflate(substr($data, 2));
            if ($d === false) $d = @gzinflate($data);
            if ($d === false) return '';
            $data = $d;
        } elseif ($flt === 'ASCIIHexDecode' || $flt === 'AHx') {
            $h = preg_replace('/[^0-9A-Fa-f]/', '', explode('>', $data)[0]) ?? '';
            if (strlen($h) % 2) $h .= '0';
            $data = (string)hex2bin($h);
        } else return '';   // DCT (images), LZW, JBIG2 … carry no text we can read
    }
    if (preg_match('#/DecodeParms\s*<<[^>]*/Predictor\s+(\d+)#', $dict, $p) && (int)$p[1] >= 10) {
        $cols = preg_match('#/Columns\s+(\d+)#', $dict, $c) ? (int)$c[1] : 1;
        $data = cvp_png_unpredict($data, $cols);
    }
    return $data;
}

/** PNG predictors (used by cross-reference streams; harmless elsewhere). */
function cvp_png_unpredict(string $data, int $cols): string {
    $row = $cols + 1; $out = ''; $prev = str_repeat("\0", $cols);
    for ($i = 0; $i + $row <= strlen($data); $i += $row) {
        $type = ord($data[$i]); $line = substr($data, $i + 1, $cols); $cur = '';
        for ($j = 0; $j < $cols; $j++) {
            $x = ord($line[$j]); $a = $j ? ord($cur[$j - 1]) : 0; $b = ord($prev[$j]); $c = $j ? ord($prev[$j - 1]) : 0;
            if ($type === 1) $x += $a; elseif ($type === 2) $x += $b; elseif ($type === 3) $x += intdiv($a + $b, 2);
            elseif ($type === 4) { $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c); $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c); }
            $cur .= chr($x & 255);
        }
        $out .= $cur; $prev = $cur;
    }
    return $out;
}

/** All objects: id => ['dict' => string, 'stream' => decoded string|null]; object streams are opened too. */
function cvp_pdf_objects(string $pdf): array {
    $objs = [];
    if (!preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $pdf, $mm, PREG_OFFSET_CAPTURE)) return [];
    $n = count($mm[0]);
    for ($i = 0; $i < $n; $i++) {
        $id = (int)$mm[1][$i][0];
        $start = $mm[0][$i][1] + strlen($mm[0][$i][0]);
        $end = $i + 1 < $n ? $mm[0][$i + 1][1] : strlen($pdf);
        $body = substr($pdf, $start, $end - $start);
        $sp = strpos($body, 'stream');
        $eo = strpos($body, 'endobj');
        if ($sp !== false && ($eo === false || $sp < $eo)) {
            $dict = substr($body, 0, $sp);
            $s = $sp + 6;
            if (($body[$s] ?? '') === "\r") $s++;
            if (($body[$s] ?? '') === "\n") $s++;
            $len = preg_match('#/Length\s+(\d+)(?!\s+\d+\s+R)#', $dict, $l) ? (int)$l[1] : -1;
            $es = strrpos($body, 'endstream');
            $raw = $len > 0 && $s + $len <= strlen($body) ? substr($body, $s, $len) : substr($body, $s, ($es !== false ? $es : strlen($body)) - $s);
            $objs[$id] = ['dict' => $dict, 'stream' => cvp_pdf_decode($dict, $raw)];
        } else {
            $objs[$id] = ['dict' => $eo !== false ? substr($body, 0, $eo) : $body, 'stream' => null];
        }
        if (count($objs) > 20000) break;
    }
    foreach ($objs as $o) {   // compressed object streams (/Type /ObjStm)
        if ($o['stream'] === null || !preg_match('#/Type\s*/ObjStm#', $o['dict'])) continue;
        $first = preg_match('#/First\s+(\d+)#', $o['dict'], $f) ? (int)$f[1] : 0;
        $head = preg_split('/\s+/', trim(substr($o['stream'], 0, $first))) ?: [];
        $pairs = [];
        for ($k = 0; $k + 1 < count($head); $k += 2) $pairs[] = [(int)$head[$k], (int)$head[$k + 1]];
        foreach ($pairs as $k => [$oid, $off]) {
            $next = $pairs[$k + 1][1] ?? (strlen($o['stream']) - $first);
            if (!isset($objs[$oid])) $objs[$oid] = ['dict' => substr($o['stream'], $first + $off, $next - $off), 'stream' => null];
        }
    }
    return $objs;
}

/** ToUnicode CMap → [map code(hex string) => utf8, bytes per code]. */
function cvp_pdf_cmap(string $cm): array {
    $map = []; $w = 1;
    if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $cm, $c)) $w = max(1, intdiv(strlen($c[1]), 2));
    $u = function (string $hex): string {
        $hex = preg_replace('/\s+/', '', $hex) ?? '';
        if ($hex === '' || strlen($hex) % 4) return strlen($hex) === 2 ? mb_chr(hexdec($hex), 'UTF-8') : '';
        $s = (string)@mb_convert_encoding((string)hex2bin($hex), 'UTF-8', 'UTF-16BE');
        return $s;
    };
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cm, $blocks))
        foreach ($blocks[1] as $b) if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f\s]*)>/', $b, $p, PREG_SET_ORDER))
            foreach ($p as $x) $map[strtolower($x[1])] = $u($x[2]);
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cm, $blocks))
        foreach ($blocks[1] as $b) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f\s]*>|\[[^\]]*\])/', $b, $p, PREG_SET_ORDER))
                foreach ($p as $x) {
                    $lo = hexdec($x[1]); $hi = hexdec($x[2]); $len = strlen($x[1]);
                    if ($hi - $lo > 5000 || $hi < $lo) continue;
                    if ($x[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f\s]*)>/', $x[3], $arr);
                        foreach ($arr[1] as $k => $dst) $map[str_pad(dechex($lo + $k), $len, '0', STR_PAD_LEFT)] = $u($dst);
                    } else {
                        $dst = preg_replace('/[<>\s]/', '', $x[3]) ?? '';
                        $base = hexdec(substr($dst, -4)); $pre = substr($dst, 0, -4);
                        for ($c2 = $lo; $c2 <= $hi; $c2++)
                            $map[str_pad(dechex($c2), $len, '0', STR_PAD_LEFT)] = $u($pre . str_pad(dechex($base + $c2 - $lo), 4, '0', STR_PAD_LEFT));
                    }
                }
        }
    return [$map, $w];
}

/** The literal string that starts at $i (just after "("); returns [bytes, index after ")"]. */
function cvp_pdf_literal(string $s, int $i): array {
    $out = ''; $depth = 1; $n = strlen($s);
    while ($i < $n) {
        $ch = $s[$i];
        if ($ch === '\\') {
            $nx = $s[$i + 1] ?? '';
            $esc = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\'];
            if (isset($esc[$nx])) { $out .= $esc[$nx]; $i += 2; continue; }
            if (ctype_digit($nx)) { preg_match('/^[0-7]{1,3}/', substr($s, $i + 1, 3), $o); $out .= chr(octdec($o[0]) & 255); $i += 1 + strlen($o[0]); continue; }
            if ($nx === "\r" || $nx === "\n") { $i += 2; if ($nx === "\r" && ($s[$i] ?? '') === "\n") $i++; continue; }
            $i++; continue;
        }
        if ($ch === '(') $depth++;
        elseif ($ch === ')') { if (--$depth === 0) return [$out, $i + 1]; }
        $out .= $ch; $i++;
    }
    return [$out, $i];
}

/** Bytes shown with a font → text. */
function cvp_pdf_show(string $bytes, ?array $font): string {
    if ($font && $font[0]) {
        [$map, $w] = $font; $out = '';
        for ($i = 0; $i + $w <= strlen($bytes); $i += $w) $out .= $map[strtolower(bin2hex(substr($bytes, $i, $w)))] ?? '';
        return $out;
    }
    if ($font && $font[1] === 2) return '';   // two-byte font without a map: glyph numbers, not letters
    $o = '';
    for ($i = 0; $i < strlen($bytes); $i++) { $c = ord($bytes[$i]); $o .= $c >= 32 || $c === 9 ? mb_convert_encoding($bytes[$i], 'UTF-8', 'Windows-1252') : ''; }
    return $o;
}

/** One content stream → text, with line breaks where the text moves down and spaces where it jumps right. */
function cvp_pdf_content(string $s, array $fonts): string {
    $out = ''; $font = null; $ops = []; $n = strlen($s); $i = 0;
    $lastY = null; $y = 0.0; $tx = 0.0; $lineStart = true;
    $nl = function () use (&$out, &$lineStart) { if (!$lineStart) { $out .= "\n"; $lineStart = true; } };
    $put = function (string $t) use (&$out, &$lineStart) { if ($t === '') return; $out .= $t; $lineStart = false; };
    while ($i < $n) {
        $ch = $s[$i];
        if (ctype_space($ch)) { $i++; continue; }
        if ($ch === '%') { $e = strpos($s, "\n", $i); $i = $e === false ? $n : $e + 1; continue; }
        if ($ch === '(') { [$str, $i] = cvp_pdf_literal($s, $i + 1); $ops[] = ['s', $str]; continue; }
        if ($ch === '<' && ($s[$i + 1] ?? '') === '<') { $e = strpos($s, '>>', $i); $i = $e === false ? $n : $e + 2; continue; }
        if ($ch === '<') { $e = strpos($s, '>', $i); $h = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, ($e === false ? $n : $e) - $i - 1)) ?? '';
            if (strlen($h) % 2) $h .= '0'; $ops[] = ['s', (string)hex2bin($h)]; $i = $e === false ? $n : $e + 1; continue; }
        if ($ch === '[') { $ops[] = ['['] ; $i++; continue; }
        if ($ch === ']') { $arr = []; while ($ops && end($ops)[0] !== '[') array_unshift($arr, array_pop($ops)); array_pop($ops); $ops[] = ['a', $arr]; $i++; continue; }
        if ($ch === '/') { preg_match('/^\/[^\s\/\[\]\(\)<>{}%]*/', substr($s, $i, 128), $m); $ops[] = ['n', substr($m[0], 1)]; $i += strlen($m[0]); continue; }
        if (preg_match('/^[+\-]?(?:\d+\.?\d*|\.\d+)/', substr($s, $i, 40), $m)) { $ops[] = ['f', (float)$m[0]]; $i += strlen($m[0]); continue; }
        preg_match('/^[^\s\/\[\]\(\)<>{}%]+/', substr($s, $i, 40), $m);
        $op = $m[0] ?? $ch; $i += max(1, strlen($op));
        $num = fn(int $k) => (float)($ops[count($ops) - $k][1] ?? 0);
        switch ($op) {
            case 'BI': $e = strpos($s, 'EI', $i); $i = $e === false ? $n : $e + 2; break;   // inline image
            case 'BT': $tx = 0.0; break;
            case 'Tf': $nm = $ops[count($ops) - 2][1] ?? ''; $font = $fonts[$nm] ?? null; break;
            case 'Td': case 'TD':
                $dx = $num(2); $dy = $num(1);
                if (abs($dy) > 0.5) { $y += $dy; $nl(); } elseif ($dx > 8 && !$lineStart) $put(' ');
                $tx += $dx; break;
            case 'Tm':
                $ny = $num(1); $nx = $num(2);
                if ($lastY !== null && abs($ny - $lastY) > 0.5) $nl(); elseif ($nx > $tx + 8 && !$lineStart) $put(' ');
                $lastY = $ny; $tx = $nx; break;
            case 'T*': $nl(); break;
            case 'Tj': $put(cvp_pdf_show((string)(end($ops)[1] ?? ''), $font)); break;
            case "'": case '"': $nl(); $put(cvp_pdf_show((string)(end($ops)[1] ?? ''), $font)); break;
            case 'TJ':
                foreach ((array)(end($ops)[1] ?? []) as $el) {
                    if ($el[0] === 's') $put(cvp_pdf_show((string)$el[1], $font));
                    elseif ($el[0] === 'f' && $el[1] < -180 && !$lineStart && !str_ends_with($out, ' ')) $put(' ');
                }
                break;
            case 'ET': if (!$lineStart && !str_ends_with($out, ' ')) $out .= ' '; break;
        }
        if (!in_array($op, ['[', ']'], true) && !preg_match('/^[+\-]?[\d.]/', $op)) $ops = [];
        if (strlen($out) > CVP_MAX_TEXT * 2) break;
    }
    return $out;
}

/** Pure-PHP PDF → text: every page's content streams in page order, each font through its ToUnicode map. */
function cvp_pdf_text(string $pdf): string {
    $objs = cvp_pdf_objects($pdf);
    if (!$objs) return '';
    $ref = function (string $txt, string $key): ?int { return preg_match('#/' . $key . '\s+(\d+)\s+\d+\s+R#', $txt, $m) ? (int)$m[1] : null; };
    $cmaps = []; $fontObj = [];
    foreach ($objs as $id => $o) {
        if (!preg_match('#/Type\s*/Font\b#', $o['dict'])) continue;
        $tu = $ref($o['dict'], 'ToUnicode');
        $two = preg_match('#/Subtype\s*/Type0#', $o['dict']) ? 2 : 1;
        if ($tu !== null && isset($objs[$tu]) && is_string($objs[$tu]['stream'])) { [$map, $w] = cvp_pdf_cmap($objs[$tu]['stream']); $fontObj[$id] = [$map ?: null, $map ? $w : $two]; }
        else $fontObj[$id] = [null, $two];
    }
    // font resource name → font, from every /Font dictionary (inline or referenced)
    $names = [];
    $readFontDict = function (string $d) use (&$names, $fontObj) {
        if (preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', $d, $p, PREG_SET_ORDER))
            foreach ($p as $x) if (isset($fontObj[(int)$x[2]])) $names[$x[1]] = $fontObj[(int)$x[2]];
    };
    foreach ($objs as $o) {
        if (preg_match('#/Font\s*<<(.*?)>>#s', $o['dict'], $fd)) $readFontDict($fd[1]);
        elseif (($fr = $ref($o['dict'], 'Font')) !== null && isset($objs[$fr])) $readFontDict($objs[$fr]['dict']);
    }
    // pages in order (walk the page tree from /Root /Pages), then their /Contents
    $order = [];
    $walk = function (int $id, int $depth) use (&$walk, &$order, $objs) {
        if ($depth > 30 || !isset($objs[$id])) return;
        $d = $objs[$id]['dict'];
        if (preg_match('#/Kids\s*\[([^\]]*)\]#', $d, $k)) { preg_match_all('/(\d+)\s+\d+\s+R/', $k[1], $kk); foreach ($kk[1] as $kid) $walk((int)$kid, $depth + 1); }
        elseif (preg_match('#/Type\s*/Page\b#', $d)) $order[] = $id;
    };
    $root = null;
    foreach ($objs as $id => $o) if (preg_match('#/Type\s*/Catalog#', $o['dict'])) { $root = $id; break; }
    if ($root !== null && ($pages = $ref($objs[$root]['dict'], 'Pages')) !== null) $walk($pages, 0);
    if (!$order) foreach ($objs as $id => $o) if (preg_match('#/Type\s*/Page\b#', $o['dict'])) $order[] = $id;
    $out = [];
    foreach ($order as $pid) {
        $d = $objs[$pid]['dict']; $ids = [];
        if (preg_match('#/Contents\s*\[([^\]]*)\]#', $d, $c)) { preg_match_all('/(\d+)\s+\d+\s+R/', $c[1], $cc); $ids = array_map('intval', $cc[1]); }
        elseif (($one = $ref($d, 'Contents')) !== null) {
            $ids = [$one];
            if (isset($objs[$one]) && $objs[$one]['stream'] === null && preg_match_all('/(\d+)\s+\d+\s+R/', $objs[$one]['dict'], $cc)) $ids = array_map('intval', $cc[1]);
        }
        $page = '';
        foreach ($ids as $cid) if (isset($objs[$cid]) && is_string($objs[$cid]['stream'])) $page .= $objs[$cid]['stream'] . "\n";
        // XObject forms on the page (some generators put the text there)
        if (preg_match_all('#/(Fm\d+|X\d+|Xf\d+)\s+(\d+)\s+\d+\s+R#', $d . (($r = $ref($d, 'Resources')) !== null && isset($objs[$r]) ? $objs[$r]['dict'] : ''), $xo, PREG_SET_ORDER))
            foreach ($xo as $x) { $o = $objs[(int)$x[2]] ?? null; if ($o && is_string($o['stream']) && preg_match('#/Subtype\s*/Form#', $o['dict'])) $page .= $o['stream'] . "\n"; }
        $out[] = cvp_pdf_content($page, $names);
        if (strlen(implode("\n", $out)) > CVP_MAX_TEXT * 2) break;
    }
    return implode("\n", $out);
}

/* ======================================================================= rules on the text */

const CVP_HEADINGS = [
    'experience' => '/^(work|professional|employment|career|job)?\s*(experience|history|details)s?\b|^experience\b|^employment\b|^work history\b|^career (summary|history)\b/i',
    'education' => '/^(educational|academic)?\s*(qualifications?|education|credentials)\b|^academic\b|^scholastic\b/i',
    'skills' => '/^(key|technical|core|professional|it|computer|soft)?\s*(skills?|competenc(y|ies)|expertise|proficienc(y|ies)|strengths)\b|^(software|tools)\s*(known|skills)?\s*:?$/i',
    'other' => '/^(personal|declaration|hobbies|interests|languages|projects?|achievements?|certifications?|objective|career objective|profile|summary|references?|extra|strengths?|trainings?|awards?)\b/i',
];

/** The heading a line is, or ''. */
function cvp_heading(string $line): string {
    $l = trim(rtrim(trim($line), ':'));
    if ($l === '' || mb_strlen($l) > 40 || substr_count($l, ' ') > 4) return '';
    foreach (CVP_HEADINGS as $k => $re) if (preg_match($re, $l)) return $k;
    return '';
}

/** Lines grouped by section: ['top' => [...], 'experience' => [...], 'education' => [...], 'skills' => [...], 'other' => [...]] */
function cvp_sections(array $lines): array {
    $sec = ['top' => [], 'experience' => [], 'education' => [], 'skills' => [], 'other' => []]; $cur = 'top';
    foreach ($lines as $l) { $h = cvp_heading($l); if ($h !== '') { $cur = $h; continue; } $sec[$cur][] = $l; }
    return $sec;
}

const CVP_DEGREES = [   // highest first: label => pattern
    'Ph.D.' => '/\b(ph\.?\s?d|doctorate)\b/i',
    'M.Tech / M.E.' => '/\b(m\.?\s?tech|m\.\s?e\.|master of (engineering|technology))\b/i',
    'MBA' => '/\b(m\.?b\.?a|pgdm|master of business administration)\b/i',
    'MCA' => '/\b(m\.?c\.?a|master of computer applications?)\b/i',
    'M.Com' => '/\b(m\.?\s?com|master of commerce)\b/i',
    'M.Sc' => '/\b(m\.?\s?sc|master of science)\b/i',
    'M.A.' => '/\b(m\.a\.|master of arts)\b/i',
    'B.E. / B.Tech' => '/\b(b\.?\s?tech|b\.\s?e\.?(?=\s|\(|,|$)|be\s*\((mech|elec|civil|comp|it|ec)|bachelor of (engineering|technology))/i',
    'BCA' => '/\b(b\.?c\.?a|bachelor of computer applications?)\b/i',
    'B.Com' => '/\b(b\.?\s?com|bachelor of commerce)\b/i',
    'B.Sc' => '/\b(b\.?\s?sc|bachelor of science)\b/i',
    'BBA' => '/\b(b\.?b\.?a|bachelor of business administration)\b/i',
    'B.A.' => '/\b(b\.a\.|bachelor of arts)\b/i',
    'Diploma' => '/\bdiploma\b/i',
    'ITI' => '/\b(i\.?t\.?i|industrial training institute)\b/i',
    '12th / HSC' => '/\b(h\.?s\.?c|12th|xii|higher secondary|intermediate)\b/i',
    '10th / SSC' => '/\b(s\.?s\.?c|10th|matriculation|secondary school)\b/i',
];

const CVP_ROLE_WORDS = '(engineer|executive|manager|supervisor|officer|operator|technician|accountant|assistant|analyst|associate|trainee|intern|head|lead|coordinator|co-ordinator|in-?charge|incharge|clerk|receptionist|draftsman|designer|developer|consultant|representative|foreman|fitter|welder|electrician|helper|administrator|admin|specialist|controller|director|storekeeper|store keeper|purchaser|buyer|planner|inspector|auditor|recruiter|telecaller|tele-caller|sales|marketing|hr\b|machinist|cad|cnc)';
const CVP_COMPANY_WORDS = '/\b(pvt\.?|private|ltd\.?|limited|llp|inc\.?|industries|industry|engineering|engineers|enterprises?|corporation|corp\.?|co\.|company|technologies|technology|solutions|systems|automation|motors|works|group|associates|traders|infra|projects|international|global|hospital|bank|school|college)\b/i';

const CVP_MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

/** "12/03/1995", "12-Mar-1995", "12th March 1995", "March 12, 1995", "1995-03-12" → yyyy-mm-dd ('' if none / not a real date). */
function cvp_date(string $s): string {
    $s = strtolower(trim($s)); $mo = implode('|', array_keys(CVP_MONTHS));
    $ok = fn(int $y, int $m, int $d) => $y >= 1940 && $y <= (int)date('Y') && checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : '';
    if (preg_match('/\b(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})\b/', $s, $m)) return $ok((int)$m[1], (int)$m[2], (int)$m[3]);
    if (preg_match('/\b(\d{1,2})[\-\/. ](\d{1,2})[\-\/. ](\d{2,4})\b/', $s, $m)) { $y = (int)$m[3]; if ($y < 100) $y += $y > (int)date('y') ? 1900 : 2000; return $ok($y, (int)$m[2], (int)$m[1]); }
    if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?[\s\-\/.,]*(' . $mo . ')[a-z]*\.?[\s\-\/.,]*(\d{4})\b/', $s, $m)) return $ok((int)$m[3], CVP_MONTHS[$m[2]], (int)$m[1]);
    if (preg_match('/\b(' . $mo . ')[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b/', $s, $m)) return $ok((int)$m[3], CVP_MONTHS[$m[1]], (int)$m[2]);
    return '';
}

/** A month in a CV date range → months since year 0 (null if not readable). "Present / Till date / Current" = this month. */
function cvp_ym(string $s, ?int $now = null): ?int {
    $s = strtolower(trim($s)); $now ??= (int)date('Y') * 12 + (int)date('n') - 1;
    if (preg_match('/present|till|current|now|date|ongoing|continuing/', $s)) return $now;
    $mo = implode('|', array_keys(CVP_MONTHS));
    if (preg_match('/(' . $mo . ')[a-z]*\.?[\s\-\/,\']*(\d{2,4})/', $s, $m)) { $y = (int)$m[2]; if ($y < 100) $y += 2000; return $y * 12 + CVP_MONTHS[$m[1]] - 1; }
    if (preg_match('/\b(\d{1,2})[\-\/.](\d{4})\b/', $s, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 12) return (int)$m[2] * 12 + (int)$m[1] - 1;
    if (preg_match('/\b(19[5-9]\d|20\d\d)\b/', $s, $m)) return (int)$m[1] * 12;
    return null;
}

/** Date ranges in lines → list of [from, to] in months (only sensible ones). */
function cvp_ranges(array $lines, ?int $now = null): array {
    $now ??= (int)date('Y') * 12 + (int)date('n') - 1;
    $mo = '(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?';
    $pt = "(?:$mo" . "[\\s\\-\\/,']*\\d{2,4}|\\d{1,2}[\\/.\\-]\\d{4}|(?:19|20)\\d\\d)";
    $re = '/(' . $pt . ')\s*(?:-|to|till|until|–)\s*(' . $pt . '|present|till\s*date|current(?:ly)?|now|ongoing|date)/i';
    $out = [];
    foreach ($lines as $l) if (preg_match_all($re, $l, $m, PREG_SET_ORDER)) foreach ($m as $x) {
        $a = cvp_ym($x[1], $now); $b = cvp_ym($x[2], $now);
        if ($a === null || $b === null || $b < $a || $b > $now + 1 || $b - $a > 45 * 12) continue;
        $out[] = [$a, $b];
    }
    return $out;
}

/** Total months covered by ranges, overlaps counted once. */
function cvp_months(array $ranges): int {
    usort($ranges, fn($x, $y) => $x[0] <=> $y[0]);
    $sum = 0; $cur = null;
    foreach ($ranges as [$a, $b]) {
        if ($cur === null) { $cur = [$a, $b]; continue; }
        if ($a <= $cur[1]) $cur[1] = max($cur[1], $b); else { $sum += $cur[1] - $cur[0] + 1; $cur = [$a, $b]; }
    }
    if ($cur) $sum += $cur[1] - $cur[0] + 1;
    return $sum;
}

function cvp_title(string $n): string {
    $n = preg_replace('/\s+/u', ' ', trim($n)) ?? '';
    if ($n === mb_strtoupper($n) || $n === mb_strtolower($n)) $n = mb_convert_case(mb_strtolower($n), MB_CASE_TITLE, 'UTF-8');
    return $n;
}

/** A value after a label anywhere in the text ("Date of Birth : 12/03/1995"), '' when absent. */
function cvp_label(string $text, string $labels, int $max = 80): string {
    if (preg_match('/(?:^|\n|\|)\s*(?:' . $labels . ')\s*[:\-–=]?\s*[:\-–]?\s*([^\n|]{1,' . $max . '})/iu', $text, $m)) return trim($m[1], " \t:.-");
    return '';
}

/**
 * Suggested form values from CV text. Keys (all strings, '' when not found): name, phone, email, dob, gender, city,
 * totalExp (e.g. "5.5 years" or "Fresher"), org, designation, qualification, skills, languages, linkedin, curCtc,
 * expCtc, notice.
 */
function cvp_fields(string $text, ?int $nowYm = null, array $cities = []): array {
    $f = array_fill_keys(['name', 'phone', 'email', 'dob', 'gender', 'city', 'totalExp', 'org', 'designation', 'qualification',
                          'skills', 'languages', 'linkedin', 'curCtc', 'expCtc', 'notice'], '');
    $text = cvp_clean($text);
    if ($text === '') return $f;
    $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
    $sec = cvp_sections($lines);

    if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m)) $f['email'] = strtolower(rtrim($m[0], '.'));
    if (preg_match('/(?:mobile|phone|contact|tel|cell)[^\d+]{0,12}(\+?\d[\d\s\-.()]{7,18}\d)/i', $text, $m) || preg_match('/(?<![\d\/])(\+\d[\d\s\-.]{8,16}\d|\d{10,12})(?![\d\/])/', $text, $m))
        $f['phone'] = (str_starts_with(trim($m[1]), '+') ? '+' : '') . (preg_replace('/\D/', '', $m[1]) ?? '');
    if (preg_match('#https?://(?:[a-z]{2,3}\.)?linkedin\.com/in/[A-Za-z0-9_\-%]+/?|(?<![/\w])(?:www\.)?linkedin\.com/in/[A-Za-z0-9_\-%]+/?#i', $text, $m))
        $f['linkedin'] = (str_starts_with(strtolower($m[0]), 'http') ? '' : 'https://') . rtrim($m[0], '/');

    // name: "Name : X", else the first short line of letters at the top
    $nm = cvp_label($text, 'full name|name of (?:the )?(?:candidate|applicant)|candidate name|name', 60);
    if ($nm !== '' && preg_match('/^[\p{L} .\']{3,60}$/u', $nm) && str_word_count($nm) <= 5) $f['name'] = cvp_title(preg_replace('/^(mr|mrs|ms|miss|dr)\.?\s+/i', '', $nm) ?? $nm);
    if ($f['name'] === '') foreach (array_slice($lines, 0, 8) as $l) {
        $c = trim(preg_replace('/^(mr|mrs|ms|miss|dr)\.?\s+/i', '', $l) ?? $l);
        if (preg_match('/resume|r[ée]sum[ée]|curriculum|vitae|\bcv\b|bio-?data|profile|e-?mail|phone|mobile|address|objective|@|\d/i', $c)) continue;
        if (preg_match('/^[\p{L}][\p{L} .\']{2,50}$/u', $c) && ($w = count(preg_split('/\s+/', $c) ?: [])) >= 2 && $w <= 4) { $f['name'] = cvp_title($c); break; }
    }

    $dob = cvp_label($text, 'date of birth|d\.?\s?o\.?\s?b\.?|birth ?date|born on', 40);
    if ($dob !== '') $f['dob'] = cvp_date($dob);
    $g = strtolower(cvp_label($text, 'gender|sex', 20));
    if (preg_match('/^(male|m)\b/', $g)) $f['gender'] = 'Male'; elseif (preg_match('/^(female|f)\b/', $g)) $f['gender'] = 'Female';

    // city: a labelled address / location first, else the first known city near the top
    $place = cvp_label($text, 'present address|current address|correspondence address|address|current location|location|city|residence', 160);
    $findCity = function (string $s) use ($cities): string {
        foreach ($cities as $c) if ($c !== '' && preg_match('/\b' . preg_quote($c, '/') . '\b/i', $s)) return $c;
        return '';
    };
    $f['city'] = $findCity($place) ?: $findCity(implode(' ', array_slice($lines, 0, 15)));

    // experience: a stated total, else the job date ranges, else "fresher"
    $exp = '';
    if (preg_match('/(\d{1,2}(?:\.\d)?)\s*\+?\s*(?:years?|yrs?)(?:\s*(?:and|&)?\s*(\d{1,2})\s*(?:months?|mos?))?\s*(?:of\s+)?(?:total\s+|rich\s+|overall\s+|professional\s+|industrial\s+|work\s+|relevant\s+)*(?:experience|exp\b)/i', $text, $m)
        || preg_match('/(?:(?:total|overall|work|professional)\s+)?(?:experience|exp)\.?\s*[:\-]\s*(\d{1,2}(?:\.\d)?)\s*\+?\s*(?:years?|yrs?)(?:\s*(?:and|&)?\s*(\d{1,2})\s*(?:months?|mos?))?/i', $text, $m)) {
        $v = (float)$m[1] + (isset($m[2]) && $m[2] !== '' ? (int)$m[2] / 12 : 0);
        if ($v > 0 && $v < 45) $exp = rtrim(rtrim(number_format(round($v * 2) / 2, 1, '.', ''), '0'), '.');
    }
    if ($exp === '' && $sec['experience']) {
        $mths = cvp_months(cvp_ranges($sec['experience'], $nowYm));
        if ($mths >= 3) $exp = rtrim(rtrim(number_format(round($mths / 12 * 2) / 2, 1, '.', ''), '0'), '.');
    }
    if ($exp !== '') $f['totalExp'] = $exp . ' year' . ($exp === '1' ? '' : 's');
    elseif (preg_match('/\bfresher\b/i', $text) && !$sec['experience']) $f['totalExp'] = 'Fresher';

    // current / last employer and designation: labelled first, else the first job block under Experience
    $f['org'] = cvp_label($text, 'current (?:company|employer|organi[sz]ation)|present (?:company|employer|organi[sz]ation)|(?:company|organi[sz]ation|employer)(?: name)?', 80);
    $f['designation'] = cvp_label($text, 'current (?:designation|role|position)|present (?:designation|position)|designation|position held|job title', 60);
    if (mb_strlen($f['org']) > 70 || preg_match('/@|\d{6}/', $f['org'])) $f['org'] = '';
    if (mb_strlen($f['designation']) > 60 || !preg_match('/\p{L}/u', $f['designation'])) $f['designation'] = '';
    $block = array_slice($sec['experience'], 0, 12);
    if ($f['org'] === '' || $f['designation'] === '') foreach ($block as $i => $l) {
        $plain = trim(preg_replace('/\(?\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?[\s\-\/,\']*\d{2,4}.*$|\(?\b(19|20)\d\d\b.*$/i', '', $l) ?? $l, " \t,|-–:(");
        if ($plain === '') continue;
        // "Sales Engineer at ABC Pvt Ltd" / "ABC Pvt Ltd – Sales Engineer"
        if (preg_match('/^(.{3,60}?)\s+(?:at|@|with)\s+(.{3,80})$/i', $plain, $m) && preg_match('/' . CVP_ROLE_WORDS . '/i', $m[1])) {
            if ($f['designation'] === '') $f['designation'] = trim($m[1]); if ($f['org'] === '') $f['org'] = trim($m[2], ' .,');
            break;
        }
        if (preg_match('/^(.{3,80}?)\s*[\-|,–]\s*(.{3,60})$/', $plain, $m) && preg_match(CVP_COMPANY_WORDS, $m[1]) && preg_match('/' . CVP_ROLE_WORDS . '/i', $m[2])) {
            if ($f['org'] === '') $f['org'] = trim($m[1]); if ($f['designation'] === '') $f['designation'] = trim($m[2]);
            break;
        }
        if ($f['org'] === '' && preg_match(CVP_COMPANY_WORDS, $plain) && mb_strlen($plain) <= 80 && !preg_match('/^(responsib|duties|role|key|worked|working|handl|manag|maintain|prepar)/i', $plain)) {
            $f['org'] = preg_replace('/^(?:company|organi[sz]ation|employer)\s*[:\-]\s*/i', '', $plain) ?? $plain;
            foreach ([$i - 1, $i + 1, $i + 2] as $j) {
                $c = trim(preg_replace('/\(?\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?[\s\-\/,\']*\d{2,4}.*$|\(?\b(19|20)\d\d\b.*$/i', '', $block[$j] ?? '') ?? '', " \t,|-–:(");
                $c = preg_replace('/^(?:designation|position|role)\s*[:\-]\s*/i', '', $c) ?? $c;
                if ($f['designation'] === '' && $c !== '' && mb_strlen($c) <= 60 && str_word_count($c) <= 7 && preg_match('/' . CVP_ROLE_WORDS . '/i', $c) && !preg_match(CVP_COMPANY_WORDS, $c)) { $f['designation'] = $c; break; }
            }
            break;
        }
    }
    $f['org'] = mb_substr(trim($f['org'], ' ,;:'), 0, 80); $f['designation'] = mb_substr(cvp_title(trim($f['designation'], ' .,;:')), 0, 60);

    // highest qualification (education section first, else the whole CV)
    $edu = implode("\n", $sec['education']) ?: $text;
    foreach (CVP_DEGREES as $label => $re) if (preg_match($re, $edu)) { $f['qualification'] = $label; break; }
    if ($f['qualification'] === '' && $sec['education']) foreach (CVP_DEGREES as $label => $re) if (preg_match($re, $text)) { $f['qualification'] = $label; break; }

    // skills: the Skills section, split on bullets / commas
    $items = [];
    foreach (array_slice($sec['skills'], 0, 25) as $l) {
        if (preg_match('/^(notice|current ctc|expected|present ctc|date of birth|d\.?o\.?b|gender|sex|nationality|marital|address|languages?|hobbies|declaration|place|date|e-?mail|mobile|phone|father)\b[^:]{0,25}:/i', $l)) break;
        $l = preg_replace('/^[\-•●▪■◦*·>\x{2022}\x{25CF}\x{25AA}\x{27A2}\x{2713}\x{F0B7}\s]+/u', '', $l) ?? $l;
        $l = preg_replace('/^[^:]{2,30}:\s*/', '', $l) ?? $l;   // "Software: AutoCAD, SolidWorks"
        foreach (preg_split('/\s*[,;|•●▪\x{2022}]\s*|\s{2,}/u', $l) ?: [] as $s) {
            $s = trim($s, " .-\t");
            if ($s !== '' && mb_strlen($s) <= 40 && preg_match('/\p{L}/u', $s) && !isset($items[mb_strtolower($s)])) $items[mb_strtolower($s)] = $s;
            if (count($items) >= 15) break 2;
        }
    }
    $f['skills'] = implode(', ', array_values($items));

    $lang = cvp_label($text, 'languages? known|languages?|linguistic proficiency', 120);
    if ($lang !== '' && preg_match_all('/\b(english|spanish|french|german|portuguese|italian|arabic|chinese|mandarin|japanese|russian|hindi|bengali|urdu|tamil|telugu|marathi|gujarati|kannada|malayalam|punjabi|swahili|indonesian|malay|turkish|dutch)\b/i', $lang, $lm))
        $f['languages'] = implode(', ', array_values(array_unique(array_map('ucfirst', array_map('strtolower', $lm[1])))));

    $f['curCtc'] = cvp_label($text, 'current ctc|current salary|present ctc|present salary|last drawn(?: salary| ctc)?|current package|monthly ctc|ctc', 40);
    $f['expCtc'] = cvp_label($text, 'expected ctc|expected salary|salary expected|expected package', 40);
    $f['notice'] = cvp_label($text, 'notice period|notice', 40);
    foreach (['curCtc', 'expCtc'] as $k) if (!preg_match('/\d/', $f[$k])) $f[$k] = '';
    if ($f['notice'] !== '' && !preg_match('/\d|immediate|serving|negotiable/i', $f[$k = 'notice'])) $f['notice'] = '';

    foreach ($f as $k => $v) $f[$k] = mb_substr(trim((string)$v), 0, $k === 'skills' ? 400 : 160);
    return $f;
}

/** Notice text → one of the form's options ('' when unclear). */
function cvp_notice_option(string $s): string {
    $t = strtolower($s);
    if ($t === '') return '';
    if (preg_match('/immediate|serving|(?<!\d)0 ?days?\b|no notice/', $t)) return 'Immediate Joining';
    $d = preg_match('/(\d+)\s*(month|mon)/', $t, $m) ? (int)$m[1] * 30 : (preg_match('/(\d+)\s*(week|wk)/', $t, $m) ? (int)$m[1] * 7 : (preg_match('/(\d+)/', $t, $m) ? (int)$m[1] : -1));
    if ($d < 0) return '';
    foreach ([0 => 'Immediate Joining', 15 => '15 Days', 30 => '30 Days', 60 => '60 Days', 90 => '90 Days'] as $lim => $o) if ($d <= $lim) return $o;
    return 'Others';
}

/** File bytes → suggested values (+ how they were read). @return array{fields:array, method:string, chars:int} */
function cvp_parse(string $bytes, array $cities = []): array {
    if (strlen($bytes) > CVP_MAX_BYTES) return ['fields' => cvp_fields(''), 'method' => 'too-large', 'chars' => 0];
    $t = cvp_text($bytes);
    return ['fields' => cvp_fields($t['text'], null, $cities), 'method' => $t['method'], 'chars' => mb_strlen($t['text'])];
}
