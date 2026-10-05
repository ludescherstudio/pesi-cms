<?php
// pesi CMS — library · ludescher.studio
// Replaced by every update. Do not change anything here: settings live
// in pesi-core.php, which an update never touches. Documentation: README.md

// pesi.php checks on start that it comes from the same release.
if (!defined('PESI_VERSION')) define('PESI_VERSION', '0.5.0');

// ── Defaults ─────────────────────────────────────────────────
// Everything pesi-core.php does not set. New settings of later releases
// thus work without changing pesi-core.php. PESI_PASSWORD deliberately has no
// default: without a password of your own, sign-in stays locked (T8).
foreach ([
    'BRAND_NAME'            => 'pesi',
    'BRAND_COLOR'           => '#a3611b',
    'BRAND_LOGO'            => '',
    'LANG'                  => 'de',
    'PESI_BACKUP_ENABLED'   => true,
    'PESI_BACKUP_COUNT'     => 5,
    'PESI_PASSWORD_CHANGE'  => true,
    'PESI_SYNTAX_CHECK'     => true,
    'PESI_PHP_CLI'          => '',
    'PESI_SESSION_IDLE'     => 30 * 60,
    'PESI_SESSION_MAX'      => 12 * 60 * 60,
    'PESI_GLOBALS_FILE'     => 'pesi-content.php',
    'PESI_TRUSTED_PROXY_IPS' => [],
    'PESI_UPLOAD_DIR'       => 'uploads',
    'PESI_UPLOAD_MAX_BYTES' => 5 * 1024 * 1024,
    'PESI_UPLOAD_TYPES'     => 'jpg,jpeg,png,webp,avif,gif',
    'PESI_IMAGE_MAX_EDGE'   => 2560,
] as $pesiKey => $pesiDefault) {
    if (!defined($pesiKey)) define($pesiKey, $pesiDefault);
}
unset($pesiKey, $pesiDefault);

// ── Helpers for the pages ────────────────────────────────────

if (!function_exists('pesi')) {
    function _pesi_e(string $v): string {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // The field types that parser, dashboard and saver know. A typo
    // like 'urll' is not a field but a diagnostic (T13).
    function _pesi_types(): array {
        return ['text', 'textarea', 'richtext', 'image', 'url', 'email', 'tel'];
    }

    // BRAND_COLOR ends up in a <style>. Hex values only, otherwise the default.
    function _pesi_brand_color(): string {
        $c = defined('BRAND_COLOR') ? (string)BRAND_COLOR : '';
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $c) ? $c : '#a3611b';
    }

    function _pesi_safe_asset_url(string $url): string {
        $url = trim($url);
        // Backslash: browsers read "\\host/x.jpg" as a protocol-relative URL.
        if ($url === '' || preg_match('/[\x00-\x1F\x7F<>"\'\\\\]/', $url)) return '';
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
        // Internal links and anchors. Other schemes are deliberately forbidden;
        // email and phone have their own, stricter field types.
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
        // Without DOM there is no reliable HTML parser. Regex filters
        // miss unquoted attributes (onclick=…) in particular and
        // must therefore not return markup. The content survives as
        // safe plain text; formatting needs ext/dom.
        // Block and line boundaries become line breaks first, otherwise
        // <p>Alpha</p><p>Beta</p> turns into "AlphaBeta".
        $text = preg_replace('#<br\b[^>]*>#i', "\n", $html);
        $text = preg_replace('#</?(p|div|h[1-6]|li|ul|ol|blockquote|pre|tr)\b[^>]*>#i', "\n", (string)$text);
        $text = trim((string)preg_replace("/\n{3,}/", "\n\n", strip_tags((string)$text)));
        return nl2br(_pesi_e($text), false);
    }

    /**
     * Quill 2 serialises every list as <ol> and keeps the kind in li[data-list]
     * ("bullet" | "ordered"). The sanitizer allowlist strips that
     * attribute, leaving a numbered list — every bullet list
     * would turn into 1., 2., 3. on save. So split by kind into real <ul>/<ol>
     * first. Returns the newly inserted lists (empty = nothing to do).
     * The caller must clean them itself: its child list is a snapshot
     * from before and would never see the new nodes.
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
                /* Remove processing instructions (PHP tags) and HTML comments.
                   Both survive DOMDocument as their own node type,
                   not as elements — the allowlist below never sees them and
                   saveHTML() writes them back verbatim. In the page source
                   they would become real pesi:item/pesi:toggle markers or an
                   if(false) endif that misleads the block and toggle parser.
                   (No // comment here: a closing PHP tag in the text
                   would end PHP even inside a line comment.) */
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
                    // Unwrap a disallowed element (keep its content).
                    // IMPORTANT: clean the subtree first, then lift it. The
                    // child list of this loop is a snapshot from before —
                    // lifted nodes would otherwise never be checked and e.g.
                    // <div><script>…</script></div> would pass unfiltered.
                    $clean($child);
                    while ($child->firstChild) {
                        $child->parentNode->insertBefore($child->firstChild, $child);
                    }
                    $child->parentNode->removeChild($child);
                    continue;
                }

                // Split Quill lists by kind first, then clean the new lists
                // — they are not in this loop's snapshot.
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
                        // Browsers ignore control characters/whitespace inside the
                        // scheme ("\x01javascript:", "java\nscript:" → javascript:).
                        // So for the scheme check strip every character ≤ 0x20,
                        // otherwise such an href wrongly counts as "schemeless/relative".
                        $probe = preg_replace('/[\x00-\x20]+/', '', $href);
                        $ok = $probe === ''
                            || preg_match('/^(https?:|mailto:|tel:|\/|#)/i', $probe)
                            || !preg_match('/^[a-z][a-z0-9+.-]*:/i', $probe);
                        // Browsers read backslashes as slashes: "\\host"
                        // becomes protocol-relative. Reject like _pesi_safe_link_url().
                        if (!$ok || substr($probe, 0, 2) === '//' || strpos($probe, '\\') !== false) {
                            $child->removeAttribute('href');
                        }
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
                . '.pesi-richtext a{color:' . _pesi_brand_color() . ';text-decoration:underline;text-underline-offset:2px}'
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

if (!function_exists('pesi_text')) {
    /**
     * Plain text from a pesi() value, for places without HTML: JSON-LD, meta,
     * title. Removes the once-injected richtext style, the wrapper and
     * all tags, and decodes HTML entities. The result is raw: in JSON
     * use json_encode(…, JSON_HEX_TAG), in HTML htmlspecialchars() again.
     */
    function pesi_text(string $html): string {
        $s = (string)preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
        // Block boundaries become spaces, otherwise "…sentence.</p><p>Next" sticks together.
        $s = (string)preg_replace('#<(?:br|/?(?:p|div|li|ul|ol|h[1-6]|blockquote))\b[^>]*>#i', ' ', $s);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $s));
    }
}

$PESI_GLOBALS = [];
$pesiGlobalsPath = __DIR__ . '/' . PESI_GLOBALS_FILE;
if (is_file($pesiGlobalsPath)) require_once $pesiGlobalsPath;
