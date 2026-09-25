<?php
// pesi CMS — Einstellungen · ludescher.studio
// Diese Datei gehört Ihnen. Updates ersetzen nur pesi.php und pesi-lib.php,
// pesi-core.php bleibt, wie sie ist. Jede editierbare Seite lädt sie mit
// require_once 'pesi-core.php'. Dokumentation: README.md

// ── Config ───────────────────────────────────────────────────

// Admin-Passwort (password_hash() empfohlen, Plaintext unterstützt).
// Der Demo-Wert sperrt die Anmeldung. Vor Production ein starkes Passwort setzen.
define('PESI_PASSWORD', 'demo1234');
define('BRAND_NAME',  'Meine Website');
define('BRAND_COLOR', '#a3611b');          // beliebiger Hex-Wert; weisse Schrift braucht 4,5:1 (Diagnose T12)
define('BRAND_LOGO',  '');                 // z. B. '/assets/logo.svg' — leer = pesi-Logo
define('LANG',        'de');               // 'de' oder 'en'
define('PESI_BACKUP_ENABLED', true);
define('PESI_BACKUP_COUNT',   5);           // frühere Stände pro Seite, 1–20 (Versionsliste im Dashboard)
define('PESI_SYNTAX_CHECK', true);
define('PESI_SESSION_IDLE',  30 * 60);      // 30 Minuten ohne Aktivität
define('PESI_SESSION_MAX',   12 * 60 * 60); // spätestens nach 12 Stunden neu anmelden
define('PESI_GLOBALS_FILE',  'pesi-content.php');

// Bild-Upload (Typ 'image'). Ordner liegt relativ zum Root, ohne Slash.
// Muss vom Browser erreichbar bleiben — in .htaccess NICHT sperren.
define('PESI_UPLOAD_DIR',       'uploads');
define('PESI_UPLOAD_MAX_BYTES', 5 * 1024 * 1024);          // 5 MB
define('PESI_UPLOAD_TYPES',     'jpg,jpeg,png,webp,avif,gif'); // SVG bewusst nicht erlaubt
define('PESI_IMAGE_MAX_EDGE',   2560);   // längere Kante in px, größere Bilder werden verkleinert (braucht gd); 0 = nie

$PESI_PAGES = [
    PESI_GLOBALS_FILE => 'Stammdaten',
    'index.php'       => 'Startseite',
    'impressum.php'   => 'Impressum',
    'datenschutz.php' => 'Datenschutz',
];

// Eigene Formulierungen (optional): überschreibt einzelne Dashboard-Texte,
// ohne pesi.php anzufassen. Das mitgelieferte Deutsch siezt. Vollständige
// Liste der Keys in _pesi_strings() in pesi.php.
//
// $PESI_STRINGS = [
//     'welcome_hint' => 'Wähl links eine Seite aus, dann kannst du loslegen.',
//     'save_btn'     => 'Übernehmen',
//     'blk_entry'    => 'Behandlung',
// ];

// ── Ab hier nichts ändern ────────────────────────────────────
require_once __DIR__ . '/pesi-lib.php';
