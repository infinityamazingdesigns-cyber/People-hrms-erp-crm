<?php
/**
 * Sign-in pictures — up to 5 background pictures (with an optional tag, headline and line) on the sign-in page, and
 * where the sign-in card sits. Kept on Admin → Sign-in pictures (organisation settings rights).
 *  - Every picture is re-drawn on the server (camera details such as GPS dropped, at most 2400 px wide, JPEG) and kept
 *    in the database — anyone who opens the sign-in page can see these pictures.
 *  - Served by login_slide.php only while on the list; a removed picture is switched off (kept) and no longer served.
 */
declare(strict_types=1);

const LS_MAX = 5;
const LS_MAX_BYTES = 12 * 1024 * 1024;
const LS_MAX_WIDTH = 2400;
const LS_LAYOUTS = [
    'plain'  => ['No picture', 'The sign-in card on its own, as before.'],
    'right'  => ['Card on the right', 'Full-screen picture, the sign-in card on the right, captions bottom left.'],
    'centre' => ['Card in the centre', 'Full-screen picture, the sign-in card in the middle.'],
    'panel'  => ['Glass side panel', 'Full-screen picture, a frosted full-height panel on the right holds the sign-in.'],
];

/** Pure: one line of text, no control characters, capped. */
function ls_text($s, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s) ?? '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), 0, $max);
}
/** Pure: the caption fields as kept. */
function ls_meta(array $in): array { return ['tag' => ls_text($in['tag'] ?? '', 40), 'title' => ls_text($in['title'] ?? '', 90), 'text' => ls_text($in['text'] ?? '', 160)]; }
/** Pure: the layout, 'plain' unless a known one. */
function ls_layout_of(string $v): string { return isset(LS_LAYOUTS[$v]) ? $v : 'plain'; }
/** Pure: is a picture of this size and type usable? Returns the reason it is not, or ''. */
function ls_check(int $bytes, ?array $info): string {
    if ($bytes <= 0) return 'No picture was received. Choose a file and try again.';
    if ($bytes > LS_MAX_BYTES) return 'That picture is larger than 12 MB. Use a smaller one.';
    if (!$info || !in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return 'Only JPG, PNG or WebP pictures can be used.';
    if ($info[0] < 800 || $info[1] < 450) return "That picture is only {$info[0]}×{$info[1]}. Use one at least 1600 pixels wide so it stays sharp on big screens.";
    return '';
}

function ls_layout(): string { return ls_layout_of(org('login_layout')); }

function ls_list(PDO $db): array {
    try { return $db->query('SELECT id, pos, tag, title, text, w, h, v FROM login_slide WHERE removed_at IS NULL ORDER BY pos, created_at LIMIT ' . LS_MAX)->fetchAll(); }
    catch (Throwable $e) { return []; }
}

/** Re-draw an upload as a clean JPEG (no camera details), at most LS_MAX_WIDTH wide. @return array{0:string,1:int,2:int} */
function ls_redraw(string $tmp): array {
    $info = @getimagesize($tmp) ?: null;
    $why = ls_check(is_file($tmp) ? (int)filesize($tmp) : 0, $info);
    if ($why !== '') throw new InvalidArgumentException($why);
    if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('The server cannot re-draw pictures (PHP GD is missing).');
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
    };
    if (!$src) throw new InvalidArgumentException('That picture could not be read. Save it again as JPG and retry.');
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {   // keep the phone's rotation
        $rot = [3 => 180, 6 => -90, 8 => 90][(int)(@exif_read_data($tmp)['Orientation'] ?? 1)] ?? 0;
        if ($rot && ($r = imagerotate($src, $rot, 0))) { imagedestroy($src); $src = $r; }
    }
    $sw = imagesx($src); $sh = imagesy($src);
    $nw = min($sw, LS_MAX_WIDTH); $nh = (int)round($sh * $nw / $sw);
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 11, 13, 28));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
    imageinterlace($dst, true);
    ob_start(); imagejpeg($dst, null, 82); $bytes = (string)ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    return [$bytes, $nw, $nh];
}

function ls_add(PDO $db, string $tmp, array $meta, int $by): string {
    if (count(ls_list($db)) >= LS_MAX) throw new InvalidArgumentException('There are already ' . LS_MAX . ' pictures. Remove one first.');
    [$bytes, $w, $h] = ls_redraw($tmp);
    $m = ls_meta($meta); $id = bin2hex(random_bytes(16));
    $pos = (int)$db->query('SELECT COALESCE(MAX(pos), 0) + 1 FROM login_slide WHERE removed_at IS NULL')->fetchColumn();
    $db->prepare('INSERT INTO login_slide (id, pos, tag, title, text, data, w, h, v, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
       ->execute([$id, $pos, $m['tag'], $m['title'], $m['text'], $bytes, $w, $h, time(), $by]);
    audit($by, 'org_login_slide_add', $id);
    return $id;
}
function ls_update(PDO $db, string $id, array $meta, int $by): bool {
    $m = ls_meta($meta);
    $s = $db->prepare('UPDATE login_slide SET tag = ?, title = ?, text = ? WHERE id = ? AND removed_at IS NULL'); $s->execute([$m['tag'], $m['title'], $m['text'], $id]);
    if ($s->rowCount()) audit($by, 'org_login_slide_edit', $id);
    return $s->rowCount() > 0;
}
/** Pure: the order after moving one id a step earlier (-1) or later (+1). */
function ls_reorder(array $ids, string $id, int $step): array {
    $i = array_search($id, $ids, true); $j = $i === false ? -1 : $i + ($step < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($ids)) return $ids;
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    return $ids;
}
function ls_move(PDO $db, string $id, int $step, int $by): void {
    $ids = ls_reorder(array_column(ls_list($db), 'id'), $id, $step);
    $u = $db->prepare('UPDATE login_slide SET pos = ? WHERE id = ?');
    foreach ($ids as $n => $x) $u->execute([$n + 1, $x]);
    audit($by, 'org_login_slide_move', $id);
}
/** Taken off the sign-in page (row kept, no longer served). */
function ls_remove(PDO $db, string $id, int $by): bool {
    $s = $db->prepare('UPDATE login_slide SET removed_at = NOW(), removed_by = ? WHERE id = ? AND removed_at IS NULL'); $s->execute([$by, $id]);
    if ($s->rowCount()) audit($by, 'org_login_slide_remove', $id);
    return $s->rowCount() > 0;
}
/** What the sign-in page needs. */
function ls_public(PDO $db, string $base = ''): array {
    return array_map(fn($e) => ['src' => $base . 'login_slide.php?id=' . $e['id'] . '&v=' . (int)$e['v'], 'tag' => $e['tag'], 'title' => $e['title'], 'text' => $e['text']], ls_list($db));
}
