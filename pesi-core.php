<?php
// pesi CMS — settings · ludescher.studio
// This file is yours. Updates replace only pesi.php and pesi-lib.php;
// pesi-core.php stays as it is. Every editable page loads it with
// require_once 'pesi-core.php'. Documentation: README.md

// ── Config ───────────────────────────────────────────────────

// Dashboard password (password_hash() recommended, plaintext supported).
// The shipped value locks sign-in. Set a strong password before going live.
define('PESI_PASSWORD', 'demo1234');
define('PESI_PASSWORD_CHANGE', true);   // client may change it in the dashboard; delete .pesi-password to reset
define('BRAND_NAME',  'My Website');
define('BRAND_COLOR', '#a3611b');          // any hex value; white text needs 4.5:1 (diagnostic T12)
define('BRAND_LOGO',  '');                 // e.g. '/assets/logo.svg' — empty = pesi logo
// Dashboard language: 'en' = English, 'de' = Deutsch (formal "Sie").
define('LANG',        'en');
define('PESI_BACKUP_ENABLED', true);
define('PESI_BACKUP_COUNT',   5);           // earlier versions per page, 1–20 (version list in the dashboard)
define('PESI_SYNTAX_CHECK', true);
define('PESI_PHP_CLI',      '');            // PHP CLI for the syntax check; empty = automatic. On T7 e.g. '/usr/local/php82/bin/php'
define('PESI_SESSION_IDLE',  30 * 60);      // 30 minutes without activity
define('PESI_SESSION_MAX',   12 * 60 * 60); // sign in again after 12 hours at the latest
define('PESI_GLOBALS_FILE',  'pesi-content.php');
// Only behind a reverse proxy or CDN that terminates HTTPS: its addresses.
// X-Forwarded-Proto is trusted from these only. Empty = no proxy.
define('PESI_TRUSTED_PROXY_IPS', []);

// Image upload (type 'image'). Folder relative to the web root, no slash.
// Must stay reachable from the browser — do NOT block it in .htaccess.
define('PESI_UPLOAD_DIR',       'uploads');
define('PESI_UPLOAD_MAX_BYTES', 5 * 1024 * 1024);          // 5 MB
define('PESI_UPLOAD_TYPES',     'jpg,jpeg,png,webp,avif,gif'); // SVG deliberately not allowed
define('PESI_IMAGE_MAX_EDGE',   2560);   // longer edge in px, larger images are scaled down (needs gd); 0 = never

// Pages in the dashboard: file => sidebar label. Write the labels in the
// dashboard language, e.g. 'Startseite' with LANG 'de'.
$PESI_PAGES = [
    PESI_GLOBALS_FILE => 'Shared details',
    'index.php'       => 'Home',
    'imprint.php'     => 'Imprint',
    'privacy.php'     => 'Privacy policy',
];

// Own wording (optional): overrides single dashboard texts without touching
// pesi.php. The full list of keys is in _pesi_strings() in pesi.php.
//
// $PESI_STRINGS = [
//     'welcome_hint' => 'Pick a page on the left to get started.',
//     'save_btn'     => 'Publish',
//     'blk_entry'    => 'Treatment',
// ];

// ── Do not change anything below ─────────────────────────────
require_once __DIR__ . '/pesi-lib.php';
