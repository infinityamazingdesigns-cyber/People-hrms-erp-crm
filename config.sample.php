<?php
// Copy to config.php (or run install.php, which writes it for you). Never commit config.php.
return [
    'db_host' => 'localhost',
    'db_name' => 'worknest',
    'db_user' => 'worknest',
    'db_pass' => 'CHANGE_ME',

    // The permanent owner (always Super Admin, cannot be removed). Set once by install.php.
    'owner_email' => 'owner@example.com',

    // Long random string: seals ID numbers, bank details and integration secrets at rest.
    // php -r "echo bin2hex(random_bytes(32));"   — install.php writes one for you. Never change it after data is entered.
    'seal_key' => '',

    // true ONLY on a local dev host served over plain HTTP (cookies then go without Secure).
    'dev' => false,

    // true ONLY on a staging copy: no email, WhatsApp or push leaves the site; a Staging bar shows on every page.
    'stage' => false,
];
