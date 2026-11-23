<?php
/* Privacy notice & terms (lib/legal.php, privacy.php, terms.php) */
require_once WN_ROOT . '/lib/legal.php';
$h = lg_html("## TEST – Head\nLine <b>one</b>\n\n- a\n- b <script>x</script>\n\nWrite to t@example.com.");
t('LG01 headings, lists and paragraphs; typed HTML never runs', str_contains($h, '<h2>TEST – Head</h2>') && str_contains($h, '<p>Line &lt;b&gt;one&lt;/b&gt;</p>') && str_contains($h, '<ul><li>a</li><li>b &lt;script&gt;x&lt;/script&gt;</li></ul>') && !str_contains($h, '<script'));
t('LG02 email addresses become mail links', str_contains($h, '<a href="mailto:t@example.com">t@example.com</a>'));
$f = lg_fill('{legal} · {org} · {address} · {contact} · {date}', ['org' => 'TEST – Org', 'legal' => '', 'address' => '', 'contact' => 'x@example.com', 'date' => '1 Jan 2027']);
t('LG03 placeholders from settings; legal name falls back to the display name; blanks say so', $f === 'TEST – Org · TEST – Org · (address not set) · x@example.com · 1 Jan 2027');
t('LG04 empty or overlong text refused', lg_check('  ') !== '' && lg_check(str_repeat('x', LG_MAX + 1)) !== '' && lg_check('## ok') === '');
t('LG05 starting texts name no organisation, country or law', !preg_match('/\b(India|DPDP|GDPR|Gujarat|Pvt|Ltd)\b/i', LG_DEFAULT['privacy'] . LG_DEFAULT['terms']) && str_contains(LG_DEFAULT['privacy'], '{contact}'));
$lib = file_get_contents(WN_ROOT . '/lib/legal.php');
t('LG06 every save is a new version; nothing updated or deleted', str_contains($lib, 'INSERT INTO legal_version') && !preg_match('/UPDATE legal_version|DELETE\s+FROM/i', $lib));
t('LG07 editor behind organisation settings; public pages read only', str_contains(file_get_contents(WN_ROOT . '/admin/legal.php'), "module_require('org')") && !str_contains(file_get_contents(WN_ROOT . '/privacy.php') . file_get_contents(WN_ROOT . '/terms.php'), 'POST'));
