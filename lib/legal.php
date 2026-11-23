<?php
/**
 * Privacy notice and terms — public pages (privacy.php, terms.php) written by the organisation on
 * Admin → Organisation → Privacy & terms. Until a version is saved the page shows a neutral starting text that the
 * organisation should read and adapt; it carries a "draft" line saying so. Text placeholders: {org} {legal} {address}
 * {contact} {date}. Plain text with "## " headings, "- " list lines and blank lines between paragraphs — no HTML, so
 * nothing typed can run in a visitor's browser. Every save is a new version; earlier ones are kept.
 */
declare(strict_types=1);

const LG_KINDS = ['privacy' => 'Privacy notice', 'terms' => 'Terms and conditions'];
const LG_MAX = 60000;

const LG_DEFAULT = [
'privacy' => <<<'TXT'
## Who we are
{legal} ("we") uses this site to run its people, customer and office work. Address: {address}. Questions about this notice: {contact}.

## Whom this notice covers
- People who work with us, apply to us or join us.
- Customers and people who ask us about our products or services.
- Visitors to our offices.

## What we keep
- Name and contact details (mobile number, email address).
- What you tell us on a form, an application, a message or at the front desk.
- For people who work with us: the employment details the law and the job need. Identity and bank details are kept sealed and shown masked.
- For visitors: who you came to meet and when you came and left.

## Why
To answer you, to run the work you asked for, to keep our premises safe and to meet our legal duties. We do not sell your details.

## Who helps us
The hosting, email and messaging services we choose process details only to run this site for us.

## How long
Only as long as the purpose needs, or the law asks. Visitor details are removed some months after the last visit.

## Your choices
You may ask for a copy of your details, a correction, or deletion where the law allows. Write to {contact}. You may stop marketing messages at any time by replying STOP or by writing to us.

## Changes
We may update this notice; the date at the top shows the current version ({date}).
TXT,
'terms' => <<<'TXT'
## About these terms
These terms cover the public pages of {org} on this site — enquiry and application forms, order tracking links and similar pages. Questions: {contact}.

## Using the pages
Give true details. Do not try to reach pages or data that are not meant for you, or disturb the site.

## Links sent to you
A private link (for example an order tracking or applicant link) is for you only. Do not share it; ask us for a new one if it was shared by mistake.

## Quotations and orders
A quotation, order confirmation or agreement we send you prevails over these pages for that order.

## People who work with us
Their use of the site is covered by the organisation's own policies.

## Liability
We take care to keep the pages correct and available but cannot promise they are always free of errors or interruptions.

## Changes
We may update these terms; the date at the top shows the current version ({date}). Our privacy notice explains how we handle details.
TXT,
];

/** Pure: put the organisation's values into the text. */
function lg_fill(string $body, array $v): string {
    return strtr($body, ['{org}' => $v['org'] ?? '', '{legal}' => ($v['legal'] ?? '') !== '' ? $v['legal'] : ($v['org'] ?? ''),
        '{address}' => ($v['address'] ?? '') !== '' ? $v['address'] : '(address not set)', '{contact}' => ($v['contact'] ?? '') !== '' ? $v['contact'] : '(contact not set)',
        '{date}' => $v['date'] ?? '']);
}

/** Pure: the plain-text format as safe HTML — "## " headings, "- " lists, paragraphs; email addresses become mail links. */
function lg_html(string $body): string {
    $out = ''; $list = false;
    $inline = function (string $s): string {
        $s = h($s);
        return preg_replace('/\b([A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', '<a href="mailto:$1">$1</a>', $s) ?? $s;
    };
    foreach (preg_split('/\R\s*\R/', trim(str_replace("\r", '', $body))) ?: [] as $block) {
        foreach (explode("\n", $block) as $line) {
            $t = trim($line);
            if ($t === '') continue;
            if (str_starts_with($t, '## ')) { if ($list) { $out .= '</ul>'; $list = false; } $out .= '<h2>' . $inline(substr($t, 3)) . '</h2>'; continue; }
            if (str_starts_with($t, '- ')) { if (!$list) { $out .= '<ul>'; $list = true; } $out .= '<li>' . $inline(substr($t, 2)) . '</li>'; continue; }
            if ($list) { $out .= '</ul>'; $list = false; }
            $out .= '<p>' . $inline($t) . '</p>';
        }
        if ($list) { $out .= '</ul>'; $list = false; }
    }
    return $out;
}

/** Pure: a body is saveable? Returns the reason, or ''. */
function lg_check(string $body): string {
    $b = trim($body);
    if ($b === '') return 'The text cannot be empty.';
    if (mb_strlen($b) > LG_MAX) return 'The text is too long.';
    return '';
}

/** The current version of a page: [body, date, draft?, id]. */
function lg_current(PDO $db, string $kind): array {
    try {
        $s = $db->prepare('SELECT id, body, created_at FROM legal_version WHERE kind = ? ORDER BY id DESC LIMIT 1'); $s->execute([$kind]);
        if ($r = $s->fetch()) return [(string)$r['body'], substr((string)$r['created_at'], 0, 10), false, (int)$r['id']];
    } catch (Throwable $e) {}
    return [LG_DEFAULT[$kind], '', true, 0];
}
function lg_save(PDO $db, string $kind, string $body, string $note, int $by): int {
    if (!isset(LG_KINDS[$kind])) throw new InvalidArgumentException('Unknown page.');
    $why = lg_check($body); if ($why !== '') throw new InvalidArgumentException($why);
    $db->prepare('INSERT INTO legal_version (kind, body, note, created_by) VALUES (?,?,?,?)')->execute([$kind, trim(str_replace("\r", '', $body)), mb_substr(trim($note), 0, 200), $by]);
    $id = (int)$db->lastInsertId();
    audit($by, 'org_legal_save', $kind . ' #' . $id);
    return $id;
}
function lg_history(PDO $db, string $kind): array {
    try { $s = $db->prepare('SELECT v.id, v.note, v.created_at, u.name FROM legal_version v LEFT JOIN users u ON u.id = v.created_by WHERE v.kind = ? ORDER BY v.id DESC LIMIT 20'); $s->execute([$kind]); return $s->fetchAll(); }
    catch (Throwable $e) { return []; }
}
/** The values for the placeholders, from organisation settings. */
function lg_vars(string $date): array {
    return ['org' => org('name'), 'legal' => org('legal_name'), 'address' => org('address'), 'contact' => org('privacy_contact') ?: org('support_email'), 'date' => $date];
}

/** The whole public page. */
function lg_page(string $kind): void {
    $db = wn_installed() ? pdo() : null;
    [$body, $date, $draft] = $db ? lg_current($db, $kind) : [LG_DEFAULT[$kind], '', true];
    $shown = $date !== '' ? date('j F Y', strtotime($date)) : '';
    $other = $kind === 'privacy' ? ['terms.php', LG_KINDS['terms']] : ['privacy.php', LG_KINDS['privacy']];
    header('Cache-Control: public, max-age=600');
    header('Referrer-Policy: no-referrer');
    ui_head(LG_KINDS[$kind]);
    echo '<article class="card legal">';
    if ($shown !== '') echo '<p class="note">In force from ' . h($shown) . '.</p>';
    if ($draft) echo '<p class="note">Starting text — the organisation has not adapted this page yet.</p>';
    echo lg_html(lg_fill($body, lg_vars($shown)));
    echo '<p><a href="' . h($other[0]) . '">' . h($other[1]) . '</a></p></article>';
    ui_foot();
}
