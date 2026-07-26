<?php
// pesi CMS v0.3 — Core · ludescher.studio
// Dokumentation: SETUP.md

// ── Config ───────────────────────────────────────────────────

// Admin-Passwort (Plaintext). Vor Production ein starkes Passwort setzen.
define('PESI_PASSWORD', 'demo1234');
define('BRAND_NAME',  'Meine Website');
define('BRAND_COLOR', '#c47a2a');          // beliebiger Hex-Wert
define('BRAND_LOGO',  '');                 // z. B. '/assets/logo.svg' — leer = pesi-Logo
define('LANG',        'de');               // 'de' oder 'en'
define('PESI_BACKUP_ENABLED', true);
define('PESI_SYNTAX_CHECK', true);

// Bild-Upload (Typ 'image'). Ordner liegt relativ zum Root, ohne Slash.
// Muss vom Browser erreichbar bleiben — in .htaccess NICHT sperren.
define('PESI_UPLOAD_DIR',       'uploads');
define('PESI_UPLOAD_MAX_BYTES', 5 * 1024 * 1024);          // 5 MB
define('PESI_UPLOAD_TYPES',     'jpg,jpeg,png,webp,avif,gif'); // SVG bewusst nicht erlaubt

$PESI_PAGES = [
    'index.php'       => 'Startseite',
    'impressum.php'   => 'Impressum',
    'datenschutz.php' => 'Datenschutz',
];

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

    function _pesi_sanitize_html(string $html): string {
        if ($html === '') return '';
        if (!class_exists('DOMDocument')) {
            $html = strip_tags($html, '<p><br><strong><b><em><i><u><s><a><ul><ol><li><blockquote><h2><h3>');
            $html = preg_replace('/\s+on[a-z]+\s*=\s*(["\']).*?\1/is', '', $html);
            $html = preg_replace('/\s+(href)\s*=\s*(["\'])\s*(?!https?:|mailto:|tel:|\/|#)[^"\']*\2/is', '', $html);
            return $html ?? '';
        }

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
