<?php
// pesi CMS v0.3 — Core · ludescher.studio
// Dokumentation: README.md

// ── Config ───────────────────────────────────────────────────

// Admin-Passwort (Plaintext). Vor Production ein starkes Passwort setzen.
define('PESI_PASSWORD', 'demo1234');
define('BRAND_NAME',  'Meine Website');
define('BRAND_COLOR', '#a3611b');          // beliebiger Hex-Wert; weisse Schrift braucht 4,5:1 (Diagnose T12)
define('BRAND_LOGO',  '');                 // z. B. '/assets/logo.svg' — leer = pesi-Logo
define('LANG',        'de');               // 'de' oder 'en'
define('PESI_BACKUP_ENABLED', true);
define('PESI_SYNTAX_CHECK', true);
define('PESI_SESSION_IDLE',  30 * 60);      // 30 Minuten ohne Aktivität
define('PESI_SESSION_MAX',   12 * 60 * 60); // spätestens nach 12 Stunden neu anmelden
define('PESI_GLOBALS_FILE',  'pesi-content.php');

// Bild-Upload (Typ 'image'). Ordner liegt relativ zum Root, ohne Slash.
// Muss vom Browser erreichbar bleiben — in .htaccess NICHT sperren.
define('PESI_UPLOAD_DIR',       'uploads');
define('PESI_UPLOAD_MAX_BYTES', 5 * 1024 * 1024);          // 5 MB
define('PESI_UPLOAD_TYPES',     'jpg,jpeg,png,webp,avif,gif'); // SVG bewusst nicht erlaubt

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

// ── Inline Helper ────────────────────────────────────────────

if (!function_exists('pesi')) {
    function _pesi_e(string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function _pesi_safe_asset_url(string $url): string {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F<>"\']/', $url)) return '';
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return preg_match('/^https?:\/\//i', $url) ? $url : '';
        }
        return substr($url, 0, 2) === '//' ? '' : $url;
    }

    function _pesi_safe_link_url(string $url): string {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7F<>"\']/', $url)) return '';
        if (substr($url, 0, 2) === '//' || strpos($url, '\\') !== false) return '';
        if (preg_match('/^https?:\/\//i', $url)) {
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
        }
        // Interne Links und Sprungmarken. Andere Schemas sind bewusst verboten;
        // E-Mail und Telefon haben eigene, strengere Feldtypen.
        return in_array($url[0], ['/', '#', '?'], true) ? $url : '';
    }

    function _pesi_safe_email(string $email): string {
        $email = trim($email);
        return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    function _pesi_safe_tel(string $tel): string {
        $tel = trim($tel);
        if ($tel === '') return '';
        return preg_match('/^(?=.*\d)[0-9+()\.\-\/ ]{3,40}$/', $tel) ? $tel : '';
    }

    function _pesi_safe_typed_value(string $value, string $type): ?string {
        if ($type === 'url') {
            $safe = _pesi_safe_link_url($value);
        } elseif ($type === 'email') {
            $safe = _pesi_safe_email($value);
        } elseif ($type === 'tel') {
            $safe = _pesi_safe_tel($value);
        } else {
            return $value;
        }
        return trim($value) === '' || $safe !== '' ? $safe : null;
    }

    function _pesi_sanitize_html_fallback(string $html): string {
        // Ohne DOM gibt es keinen belastbaren HTML-Parser. Regex-Filter
        // übersehen insbesondere unquotierte Attribute (onclick=…) und
        // dürfen deshalb kein Markup zurückgeben. Der Inhalt bleibt als
        // sicherer Klartext erhalten; Formatierung gibt es erst mit ext/dom.
        return nl2br(_pesi_e(strip_tags($html)), false);
    }

    /**
     * Quill 2 serialisiert jede Liste als <ol> und legt die Art in li[data-list]
     * ab ("bullet" | "ordered"). Die Allowlist im Sanitizer streift das
     * Attribut, übrig bliebe eine nummerierte Liste — aus jeder Aufzählung
     * würde beim Speichern 1., 2., 3. Darum vorher nach Art in echte <ul>/<ol>
     * aufteilen. Rückgabe: die neu eingefügten Listen (leer = nichts zu tun).
     * Der Aufrufer muss sie selbst säubern: seine Kindliste ist ein Snapshot
     * von vorher, die neuen Knoten sähe er sonst nie (Trap 3 in CLAUDE.md).
     */
    function _pesi_split_quill_list(DOMElement $list): array {
        $own  = strtolower($list->nodeName);
        $runs = [];
        foreach (iterator_to_array($list->childNodes) as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') continue;
            $kind = strtolower($li->getAttribute('data-list'));
            $kind = $kind === 'ordered' ? 'ol'
                  : (in_array($kind, ['bullet', 'checked', 'unchecked'], true) ? 'ul' : $own);
            $n = count($runs);
            if ($n === 0 || $runs[$n - 1]['kind'] !== $kind) { $runs[] = ['kind' => $kind, 'items' => []]; $n++; }
            $runs[$n - 1]['items'][] = $li;
        }
        if (count($runs) <= 1 && ($runs[0]['kind'] ?? $own) === $own) return [];
        $out = [];
        foreach ($runs as $run) {
            $el = $list->ownerDocument->createElement($run['kind']);
            foreach ($run['items'] as $li) $el->appendChild($li);
            $list->parentNode->insertBefore($el, $list);
            $out[] = $el;
        }
        $list->parentNode->removeChild($list);
        return $out;
    }

    function _pesi_sanitize_html(string $html): string {
        if ($html === '') return '';
        if (!class_exists('DOMDocument')) return _pesi_sanitize_html_fallback($html);

        $allowed = [
            'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
            'u' => [], 's' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
            'h2' => [], 'h3' => [], 'a' => ['href', 'title', 'target', 'rel'],
        ];

        $doc = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="pesi-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($old);

        $clean = function ($node) use (&$clean, $allowed): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                /* Processing-Instructions (PHP-Tags) und HTML-Kommentare
                   entfernen. Beide überleben DOMDocument als eigener Knotentyp,
                   nicht als Element — die Allowlist unten sieht sie also nie und
                   saveHTML() schreibt sie wortwörtlich zurück. Im Seitenquelltext
                   wären das dann echte pesi:item-/pesi:toggle-Marker bzw. ein
                   if(false)-Endif, die den Block- und Toggle-Parser fehlleiten.
                   (Kein //-Kommentar hier: ein schließendes PHP-Tag im Text
                   würde PHP selbst in einem Zeilenkommentar beenden.) */
                if ($child->nodeType === XML_PI_NODE || $child->nodeType === XML_COMMENT_NODE) {
                    $child->parentNode->removeChild($child);
                    continue;
                }
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                $name = strtolower($child->nodeName);
                if (!isset($allowed[$name])) {
                    if (in_array($name, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                        $child->parentNode->removeChild($child);
                        continue;
                    }
                    // Nicht erlaubtes Element auspacken (Inhalt behalten).
                    // WICHTIG: erst den Teilbaum säubern, dann hochziehen. Die
                    // Kindliste dieser Schleife ist ein Snapshot von vorher —
                    // hochgezogene Knoten würden sonst nie geprüft und z. B.
                    // <div><script>…</script></div> käme ungefiltert durch.
                    $clean($child);
                    while ($child->firstChild) {
                        $child->parentNode->insertBefore($child->firstChild, $child);
                    }
                    $child->parentNode->removeChild($child);
                    continue;
                }

                // Quill-Listen erst nach Art aufteilen, dann die neuen Listen
                // säubern — sie stehen nicht im Snapshot dieser Schleife.
                if ($name === 'ol' || $name === 'ul') {
                    $split = _pesi_split_quill_list($child);
                    if ($split) {
                        foreach ($split as $el) $clean($el);
                        continue;
                    }
                }

                foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                    $attrName = strtolower($attr->nodeName);
                    if (!in_array($attrName, $allowed[$name], true)) {
                        $child->removeAttributeNode($attr);
                        continue;
                    }
                    if ($name === 'a' && $attrName === 'href') {
                        $href = trim(html_entity_decode($attr->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        // Browser ignorieren Steuerzeichen/Whitespace innerhalb des
                        // Schemas ("\x01javascript:", "java\nscript:" → javascript:).
                        // Für die Scheme-Prüfung darum alle Zeichen ≤ 0x20 entfernen,
                        // sonst gilt ein solcher href fälschlich als „schemenlos/relativ".
                        $probe = preg_replace('/[\x00-\x20]+/', '', $href);
                        $ok = $probe === ''
                            || preg_match('/^(https?:|mailto:|tel:|\/|#)/i', $probe)
                            || !preg_match('/^[a-z][a-z0-9+.-]*:/i', $probe);
                        if (!$ok || substr($probe, 0, 2) === '//') $child->removeAttribute('href');
                    }
                    if ($attrName === 'target' && !in_array($attr->nodeValue, ['_blank', '_self'], true)) {
                        $child->removeAttribute('target');
                    }
                }
                if ($name === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                $clean($child);
            }
        };

        $root = $doc->getElementById('pesi-root');
        if (!$root) return '';
        $clean($root);

        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return $out;
    }
    function pesi(string $id, string $default, string $type = 'text', string $label = ''): string {
        if ($type === 'image') {
            return _pesi_e(_pesi_safe_asset_url($default));
        }
        if (in_array($type, ['url', 'email', 'tel'], true)) {
            return _pesi_e(_pesi_safe_typed_value($default, $type) ?? '');
        }
        if ($type !== 'richtext') {
            return _pesi_e($default);
        }
        static $styleInjected = false;
        $style = '';
        if (!$styleInjected) {
            $styleInjected = true;
            $style = '<style>'
                . '.pesi-richtext ul{padding-left:1.5em;margin-bottom:1em}'
                . '.pesi-richtext ol{padding-left:1.5em;margin-bottom:1em}'
                . '.pesi-richtext ul li{list-style:disc;margin-bottom:.25em;line-height:1.7}'
                . '.pesi-richtext ol li{list-style:decimal;margin-bottom:.25em;line-height:1.7}'
                . '.pesi-richtext a{color:' . BRAND_COLOR . ';text-decoration:underline;text-underline-offset:2px}'
                . '.pesi-richtext a:hover{opacity:.75}'
                . '</style>';
        }
        return $style . '<div class="pesi-richtext">' . _pesi_sanitize_html($default) . '</div>';
    }
}

if (!function_exists('pesi_global')) {
    function pesi_global(string $id): string {
        global $PESI_GLOBALS;
        return isset($PESI_GLOBALS[$id]) && is_string($PESI_GLOBALS[$id])
            ? $PESI_GLOBALS[$id]
            : '';
    }
}

$PESI_GLOBALS = [];
$pesiGlobalsPath = __DIR__ . '/' . PESI_GLOBALS_FILE;
if (is_file($pesiGlobalsPath)) require_once $pesiGlobalsPath;
