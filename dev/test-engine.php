<?php
/**
 * pesi CMS — Regressionstests für die reinen Engine-Funktionen.
 *
 *   php dev/test-engine.php
 *
 * NUR ÜBER DIE KOMMANDOZEILE. Dieses Skript legt Dateien an, ruft shell_exec()
 * und eval() auf und gibt Interna aus — über HTTP erreichbar wäre es ein
 * Einfallstor. `dev/` gehört nicht in eine Kundeninstallation (dorthin kommen
 * nur pesi.php und pesi-core.php); die Sperre unten ist die Absicherung für
 * den Fall, dass doch einmal der ganze Ordner auf den Webspace synchronisiert
 * wird. .gitattributes hält den Ordner zusätzlich aus ZIP-Downloads heraus.
 *
 * `pesi.php` lässt sich nicht einbinden (Session + Header laufen beim Include
 * los), darum schneidet dieses Skript den Funktionsblock heraus — dieselbe
 * Technik, die CLAUDE.md beschreibt — und testet ihn gegen ein Scratch-File.
 * Keine Abhängigkeiten, kein Build, passend zum Rest des Projekts.
 *
 * Abgedeckt sind die vier historischen Traps aus dem 2026-07-Audit plus die
 * drei Befunde aus dem 2026-07-31-Audit (Linter-Erkennung, PI/Kommentare im
 * Sanitizer, Struktur-Marker in Feldwerten) und die beiden Befunde der ersten
 * Kundenintegration (2026-09-04: Quill-2-Listen, Host-Upload-Limits) und die Befunde des
 * Login-Reviews vom 2026-09-20 (Bremse parallel, bei Registerausfall, per CSRF;
 * Sitzungsdauer; Array-Parameter) — Letztere teils end-to-end mit echten
 * PHP-Prozessen gegen den Controller — sowie die des Speicher-Reviews vom selben
 * Tag (Rotation mit Rücknahme, fremder Schreiber während der Sicherung, Linter
 * nicht verfügbar, Bild-Cleanup bei Lesefehler und paralleler Referenz) und die
 * des Dashboard-Reviews vom 2026-09-21 (Entwurf nach abgelehntem Speichern,
 * Enter als Speichern, eine Strukturaktion pro Anfrage, verschachtelte
 * Einträge, Tastaturzugang zur Bildauswahl) und die des Parser-Reviews vom
 * 2026-09-22 (Tokenizer statt Regex, doppelte IDs, unbekannte Typen,
 * Richtext-Fallback ohne DOM, Backslashes in URLs). Wer
 * Saver, Sanitizer oder die
 * strukturellen Features anfasst, lässt das hier vorher und nachher laufen.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Zu prüfende Arbeitskopie. Ohne Argument die eigene — mit Argument eine
// beliebige andere. Das ist der Grund, warum die Trennung main/dev nicht weh
// tut: die Suite liegt auf dem dev-Branch (oder in einem Worktree davon) und
// prüft trotzdem genau das pesi.php, an dem gerade gearbeitet wird, ohne
// Branch-Wechsel und ohne Merge.
//
//   php dev/test-engine.php                    # diese Arbeitskopie
//   php ../pesi-cms-dev/dev/test-engine.php .  # aus einem Worktree heraus
$root = isset($argv[1]) ? rtrim($argv[1], "/\\") : dirname(__DIR__);
if (!is_file($root . '/pesi.php') || !is_file($root . '/pesi-core.php')) {
    fwrite(STDERR, "Kein pesi.php/pesi-core.php in: $root\n");
    fwrite(STDERR, "Aufruf: php test-engine.php [pfad-zur-arbeitskopie]\n");
    exit(2);
}
echo "Prüfe: $root\n";
$scratch = sys_get_temp_dir() . '/pesi-test-' . getmypid();
@mkdir($scratch, 0777, true);

// Aufräumen als Shutdown-Handler, nicht am Skriptende: bricht ein Lauf mit
// einem Fatal Error ab — etwa weil eine PHP-Erweiterung fehlt —, bliebe das
// Verzeichnis sonst liegen. Hier hatten sich so sieben Ruinen angesammelt.
register_shutdown_function(function () use ($scratch) {
    foreach (['/site/uploads/*', '/site/*', '/*'] as $g) {
        foreach (glob($scratch . $g) as $f) { if (is_file($f)) @unlink($f); }
    }
    foreach (glob($scratch . '/.pesi-throttle*') as $f) @unlink($f); // glob() übergeht führende Punkte nur ohne exaktes Präfix
    @rmdir($scratch . '/site/uploads');
    @rmdir($scratch . '/site');
    @rmdir($scratch);
});

// ── Engine extrahieren: alles ab _pesi_parse() bis zum Ende von _pesi_strings()
$src   = file_get_contents($root . '/pesi.php');
$start = strpos($src, 'function _pesi_parse');
$end   = strpos($src, '// ── Render');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "Konnte den Funktionsblock in pesi.php nicht finden.\n");
    exit(2);
}
copy($root . '/pesi-core.php', $scratch . '/core.php');
file_put_contents(
    $scratch . '/engine.php',
    "<?php\nrequire __DIR__ . '/core.php';\n" . substr($src, $start, $end - $start)
);
require $scratch . '/engine.php';
$GLOBALS['t'] = _pesi_strings()['de'];

// ── Mini-Harness ─────────────────────────────────────────────
$pass = 0; $fail = 0; $group = '';
function grp(string $g) { global $group; $group = $g; echo "\n== $g ==\n"; }
function ok(string $what, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok    $what\n"; return; }
    $fail++;
    echo "  FAIL  $what" . ($detail !== '' ? "\n        → $detail" : '') . "\n";
}
function page(string $body): string {
    global $scratch;
    $p = $scratch . '/page.php';
    foreach (['', '.pesi-backup.1', '.pesi-backup.2'] as $s) @unlink($p . $s);
    file_put_contents($p, $body);
    return $p;
}
function render(string $file): string {
    return (string)shell_exec(PHP_BINARY . ' ' . escapeshellarg($file) . ' 2>&1');
}

// ── Trap 1: eingerückter Heredoc-Closing-Marker (RCE-Klasse) ──
grp('Trap 1 — Heredoc-Ausbruch');
foreach ([
    "zeile\nPESI\nsystem('id');",
    "zeile\n  PESI . system('id');",
    "zeile\n\tPESI;",
    "PESI",
] as $v) {
    ok('kein Nowdoc für ' . json_encode($v), _pesi_hd($v) === false);
}
ok('Nowdoc weiterhin für harmlosen Mehrzeiler', _pesi_hd("a\nb") === true);

// ── Trap 2: Backreferences im Nutzertext ─────────────────────
grp('Trap 2 — Backreferences im Replacement');
foreach (['Preis $1.000', 'backref \\1', 'dollar ${1} brace'] as $v) {
    $p   = page("<?php\n\$x = pesi('f', 'ALT', 'text', 'L');\n");
    $mod = _pesi_replace(file_get_contents($p), 'f', $v);
    file_put_contents($p, $mod);
    $got = _pesi_parse($p)['f']['value'] ?? null;
    ok('Round-Trip ' . json_encode($v), $got === $v, 'gelesen: ' . json_encode($got));
    ok('lint ' . json_encode($v), _pesi_lint($p) === true);
}

// ── Trap 3: verschachteltes verbotenes Element ───────────────
grp('Trap 3 — Sanitizer, verschachtelt');
foreach ([
    '<div><script>alert(1)</script></div>',
    '<section><div><script>alert(1)</script></div></section>',
    '<blockquote><div><img src=x onerror=alert(1)></div></blockquote>',
] as $v) {
    $out = _pesi_sanitize_html($v);
    ok('entschärft ' . $v, !preg_match('/<script|<img|onerror/i', $out), 'raus: ' . $out);
}

// ── Quill 2: jede Liste kommt als <ol> mit li[data-list], nie als <ul> ──
// Ohne die Aufteilung wurde aus jeder Aufzählung beim Speichern 1., 2., 3.
grp('Sanitizer — Quill-2-Listen');
$ql = '<span class="ql-ui" contenteditable="false"></span>';
foreach ([
    "<ol><li data-list=\"bullet\">{$ql}a</li><li data-list=\"bullet\">{$ql}b</li></ol>"
        => '<ul><li>a</li><li>b</li></ul>',
    "<ol><li data-list=\"ordered\">{$ql}a</li></ol>"
        => '<ol><li>a</li></ol>',
    "<p>x</p><ol><li data-list=\"bullet\">a</li><li data-list=\"bullet\">b</li><li data-list=\"ordered\">c</li></ol>"
        => '<p>x</p><ul><li>a</li><li>b</li></ul><ol><li>c</li></ol>',
    "<div><ol><li data-list=\"bullet\">{$ql}a</li></ol></div>"
        => '<ul><li>a</li></ul>',
    "<blockquote><ol><li data-list=\"bullet\">a<ol><li data-list=\"bullet\">{$ql}b</li></ol></li></ol></blockquote>"
        => '<blockquote><ul><li>a<ul><li>b</li></ul></li></ul></blockquote>',
    '<ul><li>bleibt</li></ul>' => '<ul><li>bleibt</li></ul>',
    '<ol><li>bleibt</li></ol>' => '<ol><li>bleibt</li></ol>',
] as $in => $want) {
    $out = _pesi_sanitize_html($in);
    ok('Quill-Liste ' . substr($in, 0, 48) . '…', $out === $want, 'raus: ' . $out);
}
ok('keine Quill-Reste im Ergebnis',
    !preg_match('/data-list|ql-ui|contenteditable/', _pesi_sanitize_html("<ol><li data-list=\"bullet\">{$ql}a</li></ol>")));

// ── Trap 4: Steuerzeichen im href-Schema ─────────────────────
grp('Trap 4 — Steuerzeichen vor dem Schema');
ok('href \x01javascript:',
    !preg_match('/javascript/i', _pesi_sanitize_html("<a href=\"\x01javascript:alert(1)\">x</a>")));
ok('asset-url \x01javascript:', _pesi_safe_asset_url("\x01javascript:alert(1)") === '');
ok('asset-url protokollrelativ', _pesi_safe_asset_url('//evil.example/x.jpg') === '');

$fallback = _pesi_sanitize_html_fallback('<p onclick=alert(1)>Hallo <a href=javascript:alert(1)>Welt</a></p>');
ok('No-DOM-Fallback entfernt unquotiertes onclick', stripos($fallback, 'onclick') === false, $fallback);
ok('No-DOM-Fallback entfernt javascript-Link', stripos($fallback, 'javascript:') === false, $fallback);
ok('No-DOM-Fallback behält Textinhalt', strpos($fallback, 'Hallo Welt') !== false, $fallback);

grp('Kontext-Feldtypen — url / email / tel');
ok('https-URL erlaubt', _pesi_safe_link_url('https://example.org/termin') === 'https://example.org/termin');
ok('interner Link erlaubt', _pesi_safe_link_url('/kontakt') === '/kontakt');
ok('javascript-URL abgelehnt', _pesi_safe_link_url('javascript:alert(1)') === '');
ok('protokollrelative URL abgelehnt', _pesi_safe_link_url('//evil.example') === '');
ok('gültige E-Mail erlaubt', _pesi_safe_email('praxis@example.org') === 'praxis@example.org');
ok('ungültige E-Mail abgelehnt', _pesi_safe_email('nicht @ gültig') === '');
ok('gültiges Telefon erlaubt', _pesi_safe_tel('+43 (0) 123 45-67') === '+43 (0) 123 45-67');
ok('Telefon mit Schema abgelehnt', _pesi_safe_tel('javascript:1') === '');
$p = page("<?php\n\$u=pesi('u', '/kontakt', 'url', 'Link');\n\$e=pesi('e', 'a@example.org', 'email', 'E-Mail');\n");
$beforeTyped = (string)file_get_contents($p);
$typedFields = _pesi_parse($p);
$typedBad = _pesi_save($p, $typedFields, ['pesi_field_u' => 'javascript:alert(1)', 'pesi_field_e' => 'kaputt']);
ok('ungültige Kontextfelder lehnen gesamten Save ab', $typedBad['type'] === 'error');
ok('ungültige Kontextfelder lassen Datei unverändert', file_get_contents($p) === $beforeTyped);
$typedGood = _pesi_save($p, $typedFields, ['pesi_field_u' => 'https://example.org/termin', 'pesi_field_e' => 'neu@example.org']);
ok('gültige Kontextfelder gemeinsam gespeichert', $typedGood['type'] === 'success');
ok('globale Inhaltsdatei enthält Stammdaten', count(_pesi_parse($root . '/pesi-content.php')) >= 5);

// ── 2026-07-31 #1: Linter-Verfügbarkeit vs. echter Syntaxfehler
grp('Linter — Exitcode != 0 ist kein Beweis für einen Syntaxfehler');
$good = page("<?php\n\$x = 1;\n");
ok('gültige Datei → true', _pesi_lint($good) === true);
$bad = page("<?php\n\$x = ;;;\n");
ok('kaputte Datei → false', _pesi_lint($bad) === false);
ok('fehlendes Binary → null (nicht false)',
    (function () {
        $o = []; $e = 0;
        @exec('pesi-definitiv-kein-binary -l x 2>&1', $o, $e);
        // Shell meldet 127 (bzw. 1 unter Windows) ohne Lint-Diagnose auf stdout.
        return $e !== 0 && !preg_match('/(parse|fatal) error|errors parsing/i', implode("\n", $o));
    })(),
    'sonst würde jeder Save auf solchen Hosts zurückgerollt');

// Ein echter Syntaxfehler wird vor dem Live-Austausch abgelehnt.
$p = page("<?php\n\$x = pesi('f', 'GUT', 'text', 'L');\n");
_pesi_backup($p);
$r = _pesi_commit($p, "<?php\n\$x = ;;; kaputt\n");
ok('Syntaxfehler wird vor Live-Austausch abgelehnt', $r !== null && $r['type'] === 'error');
ok('Live-Datei bleibt gültig', _pesi_lint($p) === true);
ok('Live-Inhalt bleibt unverändert', strpos(file_get_contents($p), 'GUT') !== false);

// ── 2026-07-31 #2: PI und Kommentare im Sanitizer ────────────
grp('Sanitizer — Processing-Instructions und Kommentare');
foreach ([
    '<p>a<?php system("id"); ?>b</p>',
    '<p>a<?= system("id") ?>b</p>',
    '<p>a<?php endif; ?>b</p>',
    '<p>a<!-- /pesi:toggle -->b</p>',
    '<p>a<!-- pesi:item team:9 -->b</p>',
    '<div><p>a<!-- pesi:item x:1 --></p></div>',
] as $v) {
    $out = _pesi_sanitize_html($v);
    ok('entfernt aus ' . $v, !preg_match('/<\?|<!--/', $out), 'raus: ' . $out);
}
ok('Textinhalt bleibt erhalten', _pesi_sanitize_html('<p>a<!-- x -->b</p>') === '<p>ab</p>',
    'raus: ' . _pesi_sanitize_html('<p>a<!-- x -->b</p>'));

// ── 2026-07-31 #3: Struktur-Marker in Feldwerten ─────────────
grp('Saver — Struktur-Marker werden abgelehnt');
foreach ([
    'Anna<!-- /pesi:item -->GIFT<!-- pesi:item team:1 -->',
    '<!-- pesi:toggle urlaub -->',
    '<!--pesi:item x:2-->',
    'text <?php endif; ?> mehr',
    'text <?php if (false): ?> mehr',
] as $v) {
    ok('erkannt: ' . json_encode($v), _pesi_has_marker($v) === true);
}
foreach ([
    'Ganz normaler Text.',
    'Preis $1.000 — pesi ist super',
    '<p>HTML ohne Marker</p>',
    'Doppelpunkt: hier, aber kein Marker',
] as $v) {
    ok('kein Fehlalarm: ' . json_encode($v), _pesi_has_marker($v) === false);
}

// Ende-zu-Ende: der Angriff aus dem Audit korrumpiert die Seite nicht mehr
$p = page("<?php ?>\n<!-- pesi:item team:1 -->\n<article><?= pesi('team_1_name', 'Anna', 'text', 'Name') ?></article>\n<!-- /pesi:item -->\n<p>FOOTER</p>\n");
$before = file_get_contents($p);
$fields = _pesi_parse($p);
$r = _pesi_save($p, $fields, ['pesi_field_team_1_name' => 'Anna<!-- /pesi:item -->GIFT<!-- pesi:item team:1 -->']);
ok('Save abgelehnt', $r['type'] === 'error', 'bekam: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
ok('Datei unverändert', file_get_contents($p) === $before);
ok('Blockstruktur intakt', count(_pesi_block_parse($p)) === 1);
$r2 = _pesi_save($p, _pesi_parse($p), ['pesi_field_team_1_name' => 'Berta']);
ok('normaler Save funktioniert weiterhin', $r2['type'] === 'success', json_encode($r2, JSON_UNESCAPED_UNICODE));
ok('neuer Wert gelesen', (_pesi_parse($p)['team_1_name']['value'] ?? null) === 'Berta');

// ── Stale-Check: fopen-Modus muss lesbar sein ────────────────
grp('Commit — Stale-Check');
$p = page("<?php\n\$x = pesi('f', 'A', 'text', 'L');\n");
$hash = hash('sha256', file_get_contents($p));
ok('passender Hash → Commit läuft durch',
    _pesi_commit($p, "<?php\n\$x = pesi('f', 'B', 'text', 'L');\n", $hash) === null,
    'der Hash wird innerhalb des stabilen Sidecar-Locks erneut geprüft');
ok('Wert wurde geschrieben', (_pesi_parse($p)['f']['value'] ?? null) === 'B');
$stale = _pesi_commit($p, "<?php\n\$x = pesi('f', 'C', 'text', 'L');\n", hash('sha256', 'ganz andere Datei'));
ok('falscher Hash → abgelehnt', $stale !== null && $stale['type'] === 'error');
ok('Datei nach Ablehnung unverändert', (_pesi_parse($p)['f']['value'] ?? null) === 'B');

$p = page("<?php ?>\n<!-- pesi:item team:1 -->\n<article><?= pesi('team_1_name', 'Anna', 'text', 'Name') ?></article>\n<!-- /pesi:item -->\n");
$opened = hash('sha256', (string)file_get_contents($p));
file_put_contents($p, (string)file_get_contents($p) . "<!-- extern geändert -->\n");
$stale = _pesi_block_op($p, 'team', 1, 'add', $opened);
ok('veraltete Blockaktion wird abgelehnt', $stale['type'] === 'error');
ok('veraltete Blockaktion verändert keine Struktur', count(_pesi_block_parse($p)) === 1);

$p = page("<?php ?>\n<!-- pesi:toggle urlaub -->Hinweis<!-- /pesi:toggle -->\n");
$opened = hash('sha256', (string)file_get_contents($p));
file_put_contents($p, (string)file_get_contents($p) . "<!-- extern geändert -->\n");
$stale = _pesi_toggle_op($p, 'urlaub', $opened);
ok('veralteter Sichtbarkeitsschalter wird abgelehnt', $stale['type'] === 'error');
ok('Sichtbarkeit bleibt unverändert', _pesi_toggle_parse($p) === ['urlaub' => true]);

$p = page("<?php\n\$x = pesi('f', 'A', 'text', 'L');\n");
_pesi_backup($p);
$opened = hash('sha256', (string)file_get_contents($p));
file_put_contents($p, "<?php\n\$x = pesi('f', 'B', 'text', 'L');\n");
$stale = _pesi_restore($p, $opened);
ok('veraltete Wiederherstellung wird abgelehnt', $stale['type'] === 'error');
ok('neuerer Live-Stand bleibt erhalten', (_pesi_parse($p)['f']['value'] ?? null) === 'B');

// ── Strukturelle Round-Trips (Blöcke) ────────────────────────
grp('Blöcke — add / duplicate / reorder / delete');
$p = page("<?php ?>\n<!-- pesi:item team:1 -->\n<article><?= pesi('team_1_name', 'Anna', 'text', 'Name') ?></article>\n<!-- /pesi:item -->\n");
_pesi_block_op($p, 'team', 1, 'add');
ok('add → 2 Blöcke', count(_pesi_block_parse($p)) === 2);
ok('Feld-ID-Präfix umgeschrieben', isset(_pesi_parse($p)['team_2_name']));
ok('lint nach add', _pesi_lint($p) === true);
_pesi_block_op($p, 'team', 2, 'up');
$order = array_column(_pesi_block_parse($p), 'inst');
ok('up → Reihenfolge 2,1', $order === [2, 1], json_encode($order));
_pesi_block_op($p, 'team', 2, 'del');
ok('del → 1 Block', count(_pesi_block_parse($p)) === 1);
ok('letzten Block löschen verweigert',
    _pesi_block_op($p, 'team', 1, 'del')['type'] === 'error');
ok('lint am Ende', _pesi_lint($p) === true);

// ── Toggle-Round-Trip inkl. gerendertem Ergebnis ─────────────
grp('Toggle — verstecken / zeigen');
$p = page("<?php ?>\n<!-- pesi:toggle urlaub -->\n<p>URLAUBSHINWEIS</p>\n<!-- /pesi:toggle -->\n<p>IMMER</p>\n");
ok('sichtbar erkannt', _pesi_toggle_parse($p) === ['urlaub' => true]);
ok('gerendert sichtbar', strpos(render($p), 'URLAUBSHINWEIS') !== false);
_pesi_toggle_op($p, 'urlaub');
ok('versteckt erkannt', _pesi_toggle_parse($p) === ['urlaub' => false]);
$out = render($p);
ok('gerendert versteckt', strpos($out, 'URLAUBSHINWEIS') === false, $out);
ok('Nachbarinhalt bleibt sichtbar', strpos($out, 'IMMER') !== false, $out);
ok('lint versteckt', _pesi_lint($p) === true);
_pesi_toggle_op($p, 'urlaub');
ok('wieder sichtbar', _pesi_toggle_parse($p) === ['urlaub' => true]);
ok('gerendert wieder sichtbar', strpos(render($p), 'URLAUBSHINWEIS') !== false);

// ── Verschachtelte Einträge: ablehnen statt halb duplizieren ─
// Der non-greedy Block-Parser beendet den äusseren Eintrag am ersten inneren
// End-Marker. Duplizieren schrieb halbe <section>-Blöcke und doppelte IDs —
// `php -l`-sauber. Jede Blockoperation muss so eine Vorlage unverändert lassen.
grp('Blöcke — verschachtelte Einträge');
$nestedTpl = "<?php ?>\n<!-- pesi:item outer:1 -->\n<section>\n<h2><?= pesi('outer_1_title', 'Aussen', 'text', 'T') ?></h2>\n"
    . "<!-- pesi:item inner:1 -->\n<p><?= pesi('inner_1_name', 'Innen', 'text', 'N') ?></p>\n<!-- /pesi:item -->\n"
    . "<footer>Fuss</footer>\n</section>\n<!-- /pesi:item -->\n";
foreach (['dup:outer:1', 'add:outer:0', 'del:outer:1', 'up:outer:1', 'down:outer:1', 'dup:inner:1'] as $op) {
    [$a, $g, $i] = explode(':', $op);
    $p = page($nestedTpl);
    _pesi_backup($p);
    $b1 = (string)file_get_contents($p . '.pesi-backup.1');
    $r = _pesi_block_op($p, $g, (int)$i, $a, hash_file('sha256', $p));
    ok("$op → abgelehnt mit S6", $r['type'] === 'error' && strpos($r['msg'], '(Code S6)') !== false, $r['msg']);
    ok("$op → Datei und Sicherung unverändert", file_get_contents($p) === $nestedTpl
        && file_get_contents($p . '.pesi-backup.1') === $b1 && !is_file($p . '.pesi-backup.2'));
}
ok('flache Geschwister gelten nicht als verschachtelt', !_pesi_block_nested(page(
    "<?php ?>\n<!-- pesi:item a:1 -->x<!-- /pesi:item -->\n<!-- pesi:item a:2 -->y<!-- /pesi:item -->\n")));
// Dokumentierte Kombination: ein Toggle umschliesst Einträge
$p = page("<?php ?>\n<!-- pesi:toggle aktion -->\n<!-- pesi:item team:1 -->\n<p><?= pesi('team_1_n', 'A', 'text', 'N') ?></p>\n<!-- /pesi:item -->\n<!-- /pesi:toggle -->\n");
ok('Toggle mit Einträgen gilt nicht als verschachtelt', !_pesi_block_nested($p));
$r = _pesi_block_op($p, 'team', 1, 'dup', hash_file('sha256', $p));
ok('… Duplizieren darin funktioniert', $r['type'] === 'success' && count(_pesi_block_parse($p)) === 2, $r['msg']);

// ── Entwurf nach abgelehntem Speichern ───────────────────────
grp('Entwurf — _pesi_draft');
$p = page("<?php\n\$a = pesi('titel', 'Alt', 'text', 'Titel');\n\$b = pesi('link', '/kontakt', 'url', 'Link');\n"
    . "\$c = pesi('rt', <<<'PESI'\n<p>Alt</p>\nPESI, 'richtext', 'RT');\n");
$f = _pesi_parse($p);
$d = _pesi_draft($f, ['pesi_field_titel' => 'Neu', 'pesi_field_link' => '/kontakt',
    'pesi_field_rt' => "<p>Neu</p><script>alert(1)</script>"]);
ok('geänderter Wert wird behalten', ($d['titel'] ?? null) === 'Neu');
ok('unveränderter Wert fehlt im Entwurf', !array_key_exists('link', $d));
ok('Richtext-Entwurf ist bereinigt', isset($d['rt']) && stripos($d['rt'], 'script') === false, $d['rt'] ?? '');
ok('Array-Wert wird übergangen', _pesi_draft($f, ['pesi_field_titel' => ['x']]) === []);
$r = _pesi_save($p, $f, ['pesi_field_titel' => 'Neu', 'pesi_field_link' => 'kein link']);
ok('_pesi_save nennt das ungültige Feld', ($r['invalid'] ?? []) === ['link'], json_encode($r));

// ── Parser: nur echte globale pesi()-Aufrufe ─────────────────
// Der frühere Regex-Parser las den Rohtext. Methoden, notpesi(), Kommentare,
// Strings, HTML und Code-Beispiele im gespeicherten Richtext wurden zu Feldern
// — und liessen sich `php -l`-sauber umschreiben.
grp('Parser — Quelltextkontext');
$ctxSrc = "<?php\nclass X { function pesi(\$a, \$b) {} static function s() {} }\nfunction notpesi(\$a, \$b) {}\n\$o = new X;\n"
    . "notpesi('from_function', 'F');\n\$o->pesi('from_method', 'M');\n\$o?->pesi('from_nullsafe', 'N');\nX::pesi('from_static', 'S');\n"
    . "// pesi('from_comment', 'C');\n/* pesi('from_block', 'B'); */\n\$s = \"pesi('from_string', 'S')\";\n?>\n"
    . "<p>pesi('from_html', 'H')</p>\n"
    . "<h1><?= pesi('real', 'R', 'text', 'Echt') ?></h1>\n"
    . "<?= pesi('rt', <<<'PESI'\n<p>Beispiel: pesi('phantom', 'ORIGINAL', 'text')</p>\nPESI, 'richtext', 'RT') ?>\n"
    . "<?= \\pesi('fq', 'Q') ?>\n"
    . "<?= pesi( /* Kommentar */ \"dq\" , 'D' , \"text\" , \"Label\" , ) ?>\n";
$p = page($ctxSrc);
ok('Fixture ist gültiges PHP', _pesi_lint($p) === true);
$ids = array_keys(_pesi_parse($p));
ok('nur echte Aufrufe werden Felder', $ids === ['real', 'rt', 'fq', 'dq'], implode(', ', $ids));
ok('Richtext-Wert bleibt vollständig', strpos(_pesi_parse($p)['rt']['value'] ?? '', "pesi('phantom'") !== false);
ok('Label und Typ in doppelten Anführungszeichen werden gelesen',
    (_pesi_parse($p)['dq']['label'] ?? '') === 'Label' && (_pesi_parse($p)['dq']['type'] ?? '') === 'text');
ok('Nicht-Felder sind auch keine T13-Diagnose', _pesi_unparsed_fields($p) === [], implode(', ', _pesi_unparsed_fields($p)));
ok('Phantom im Richtext lässt sich nicht ersetzen', _pesi_replace($ctxSrc, 'phantom', 'X') === $ctxSrc);
ok('Methodenargument lässt sich nicht ersetzen', _pesi_replace($ctxSrc, 'from_method', 'X') === $ctxSrc);
$r = _pesi_save($p, _pesi_parse($p), ['pesi_field_real' => 'NEU', 'pesi_field_from_method' => 'BOESE', 'pesi_field_phantom' => 'BOESE']);
$after = (string)file_get_contents($p);
ok('Speichern ändert genau den echten Aufruf', $r['type'] === 'success' && strpos($after, "pesi('real', 'NEU', 'text', 'Echt')") !== false, $r['msg']);
ok('… und sonst kein Byte', $after === str_replace("pesi('real', 'R',", "pesi('real', 'NEU',", $ctxSrc));

// Beim Duplizieren werden nur IDs echter Aufrufe umgeschrieben
$p = page("<?php ?>\n<!-- pesi:item team:1 -->\n<p><?= pesi('team_1_name', 'Anna', 'text', 'Name') ?></p>\n"
    . "<?php \$o = null; if (\$o) \$o->pesi('team_1_meth', 'M'); ?>\n"
    . "<?= pesi('team_1_bio', <<<'PESI'\n<p>Siehe pesi('team_1_fake', 'x')</p>\nPESI, 'richtext', 'Bio') ?>\n<!-- /pesi:item -->\n");
$r = _pesi_block_op($p, 'team', 1, 'dup', hash_file('sha256', $p));
$after = (string)file_get_contents($p);
ok('Duplizieren gelingt', $r['type'] === 'success', $r['msg']);
ok('echte IDs → team_2_', isset(_pesi_parse($p)['team_2_name'], _pesi_parse($p)['team_2_bio']), implode(', ', array_keys(_pesi_parse($p))));
ok('Methodenargument und Richtext-Text bleiben unverändert',
    substr_count($after, "->pesi('team_1_meth'") === 2 && substr_count($after, "pesi('team_1_fake'") === 2
    && strpos($after, 'team_2_meth') === false && strpos($after, 'team_2_fake') === false);

// ── Doppelte IDs: Diagnose und keine Speicherung ─────────────
grp('Parser — doppelte IDs');
$nd = "<<<'PESI'\n<p>N</p>\nPESI";
foreach ([
    'String/String' => ["'A'", "'B'"],
    'String/Nowdoc' => ["'A'", $nd],
    'Nowdoc/String' => [$nd, "'B'"],
] as $name => [$v1, $v2]) {
    $src0 = "<?php\n\$a = pesi('d', $v1, 'text', 'Eins');\n\$b = pesi('d', $v2, 'text', 'Zwei');\n\$c = pesi('ok', 'O', 'text', 'Ok');\n";
    $p = page($src0);
    ok("$name → doppelte ID erkannt", _pesi_duplicate_ids($src0) === ['d']);
    $r = _pesi_save($p, _pesi_parse($p), ['pesi_field_d' => 'X', 'pesi_field_ok' => 'NEU']);
    ok("$name → Speichern mit S7 abgelehnt", $r['type'] === 'error' && strpos($r['msg'], '(Code S7)') !== false, $r['msg']);
    ok("$name → Datei unverändert", file_get_contents($p) === $src0);
}
ok('eindeutige IDs → keine Meldung', _pesi_duplicate_ids("<?php pesi('a', 'x'); pesi('b', 'y');") === []);

// ── Feldtypen: eine Allowlist für Parser und Dashboard ───────
grp('Parser — Feldtypen');
$p = page("<?php\n\$a = pesi('mystery', 'V', 'urll', 'M');\n\$b = pesi('link', '/k', 'url', 'L');\n\$c = pesi('dqval', \"x\", 'text', 'D');\n");
ok('unbekannter Typ ist kein Feld', !isset(_pesi_parse($p)['mystery']) && isset(_pesi_parse($p)['link']));
ok('… sondern eine T13-Diagnose, wie ein doppelt zitierter Wert',
    _pesi_unparsed_fields($p) === ['mystery', 'dqval'], implode(', ', _pesi_unparsed_fields($p)));
foreach (_pesi_types() as $ty) {
    ok("Dashboard hat ein Eingabefeld für '$ty'", strpos($src, "\$fld['type'] === '$ty'") !== false);
}

// ── Richtext ohne DOM: Text bleibt lesbar ────────────────────
grp('Richtext — Fallback ohne DOM');
foreach ([
    '<p>Alpha</p><p>Beta</p>'                          => ['Alpha', 'Beta'],
    '<h2>Titel</h2><ul><li>eins</li><li>zwei</li></ul>' => ['Titel', 'eins', 'zwei'],
    '<blockquote>Erste<br>Zweite</blockquote>'         => ['Erste', 'Zweite'],
] as $in => $words) {
    $out = _pesi_sanitize_html_fallback($in);
    $plain = preg_split('/\s*<br>\s*/', $out);
    ok('Grenzen bleiben: ' . implode('/', $words), array_values(array_filter($plain, 'strlen')) === $words, $out);
}
$out = _pesi_sanitize_html_fallback('<p onclick=alert(1)>x</p><script>y()</script><a href="javascript:z">l</a>');
ok('Fallback gibt weiterhin kein Markup zurück', !preg_match('/<(?!br>)/', $out) && stripos($out, 'onclick') === false, $out);

// ── Backslashes in URLs ──────────────────────────────────────
grp('URLs — Backslashes');
foreach (['\\\\evil.example/x.jpg', '/\\evil.example/x.jpg', '\\x.jpg'] as $u) {
    ok('Bild-URL ' . json_encode($u) . ' → leer', _pesi_safe_asset_url($u) === '');
}
ok('normaler Bildpfad bleibt', _pesi_safe_asset_url('/uploads/a.jpg') === '/uploads/a.jpg');
$h = _pesi_sanitize_html('<p><a href="\\\\evil.example/x">a</a> <a href="/\\evil.example">b</a> <a href="/ok">c</a></p>');
ok('Richtext-Links mit Backslash verlieren das Ziel', substr_count($h, 'href=') === 1 && strpos($h, 'href="/ok"') !== false, $h);

// ── Verschachtelte Toggles: erkennen statt still falsch schalten
grp('Toggle — Verschachtelung');
$p = page("<?php ?>\n<!-- pesi:toggle aussen -->\nA\n<!-- pesi:toggle innen -->\nB\n<!-- /pesi:toggle -->\nC\n<!-- /pesi:toggle -->\nD\n");
ok('Verschachtelung erkannt', _pesi_toggle_nested($p) === true);
$before = file_get_contents($p);
$r = _pesi_toggle_op($p, 'aussen');
ok('Operation verweigert', $r['type'] === 'error', json_encode($r, JSON_UNESCAPED_UNICODE));
ok('Datei unangetastet', file_get_contents($p) === $before);
$out = render($p);
ok('C bleibt sichtbar (nichts halb versteckt)', strpos($out, 'C') !== false, $out);

$p = page("<?php ?>\n<!-- pesi:toggle eins -->\nA\n<!-- /pesi:toggle -->\n<!-- pesi:toggle zwei -->\nB\n<!-- /pesi:toggle -->\n");
ok('zwei Geschwister sind nicht verschachtelt', _pesi_toggle_nested($p) === false);
ok('Geschwister lassen sich schalten', _pesi_toggle_op($p, 'zwei')['type'] === 'success');
ok('nur der zweite versteckt', _pesi_toggle_parse($p) === ['eins' => true, 'zwei' => false]);

// ── Feld → Sichtbarkeits-Bereich ─────────────────────────────
// Ohne diese Zuordnung stehen die Felder eines versteckten Bereichs ohne
// jeden Hinweis in der Liste: die Kundin schreibt einen Text, der gar nicht
// auf der Website landet, und erfährt es nicht.
grp('Felder im Sichtbarkeits-Bereich');
$p = page("<?php ?>\n"
    . "<?= pesi('titel', 'Praxis', 'text', 'Titel') ?>\n"
    . "<!-- pesi:toggle urlaubshinweis -->\n"
    . "<?= pesi('urlaub_titel', 'Sommerpause', 'text', 'Urlaub') ?>\n"
    . "<?= pesi('urlaub_text', 'Geschlossen.', 'textarea', 'Text') ?>\n"
    . "<!-- /pesi:toggle -->\n"
    . "<?= pesi('kontakt', 'Anrufen', 'text', 'Kontakt') ?>\n");
$map = _pesi_field_toggles($p);
ok('Feld ausserhalb ist nicht zugeordnet', !isset($map['titel']) && !isset($map['kontakt']),
    json_encode(array_keys($map)));
ok('beide Felder im Bereich erkannt', isset($map['urlaub_titel'], $map['urlaub_text']));
ok('Gruppenname stimmt', ($map['urlaub_titel']['group'] ?? '') === 'urlaubshinweis');
ok('sichtbar solange nicht geschaltet', ($map['urlaub_titel']['visible'] ?? null) === true);

_pesi_toggle_op($p, 'urlaubshinweis');
$map = _pesi_field_toggles($p);
ok('nach Ausblenden als versteckt gemeldet', ($map['urlaub_titel']['visible'] ?? null) === false);
ok('auch das zweite Feld', ($map['urlaub_text']['visible'] ?? null) === false);
ok('Felder ausserhalb bleiben unberührt', !isset($map['titel']) && !isset($map['kontakt']));

_pesi_toggle_op($p, 'urlaubshinweis');
ok('nach Einblenden wieder sichtbar', (_pesi_field_toggles($p)['urlaub_titel']['visible'] ?? null) === true);

// Block innerhalb eines Toggles — laut pesi-agent.md ausdrücklich erlaubt
$p = page("<?php ?>\n"
    . "<!-- pesi:toggle aktion -->\n"
    . "<!-- pesi:item angebot:1 -->\n"
    . "<?= pesi('angebot_1_titel', 'Rabatt', 'text', 'Titel') ?>\n"
    . "<!-- /pesi:item -->\n"
    . "<!-- /pesi:toggle -->\n");
$map = _pesi_field_toggles($p);
ok('Blockfeld im Toggle wird zugeordnet', ($map['angebot_1_titel']['group'] ?? '') === 'aktion',
    json_encode($map));
_pesi_toggle_op($p, 'aktion');
ok('Blockfeld erbt den versteckten Zustand',
    (_pesi_field_toggles($p)['angebot_1_titel']['visible'] ?? null) === false);

ok('Seite ohne Toggles liefert leere Zuordnung',
    _pesi_field_toggles(page("<?php ?>\n<?= pesi('a', 'x', 'text', 'A') ?>\n")) === []);

// ── Bild-Cleanup: fest verdrahtete Referenzen überleben ──────
grp('Bild-Cleanup');
$site = $scratch . '/site';
@mkdir($site . '/uploads', 0777, true);
file_put_contents($site . '/uploads/benutzt.jpg', 'x');
file_put_contents($site . '/uploads/verwaist.jpg', 'x');
file_put_contents(
    $site . '/index.php',
    "<?php ?>\n<meta property=\"og:image\" content=\"/uploads/benutzt.jpg\">\n"
    . "<?= pesi('bild', '/uploads/neu.jpg', 'image', 'Bild') ?>\n"
);
_pesi_cleanup_old($site, [
    'pesi_field_a' => '/uploads/benutzt.jpg',
    'pesi_field_b' => '/uploads/verwaist.jpg',
], ['index.php' => 'Start']);
ok('fest im Markup referenziertes Bild bleibt', is_file($site . '/uploads/benutzt.jpg'));
ok('wirklich verwaistes Bild gelöscht', !is_file($site . '/uploads/verwaist.jpg'));

file_put_contents($site . '/uploads/aus-backup.jpg', 'x');
file_put_contents($site . '/index.php.pesi-backup.1',
    "<?php ?>\n<?= pesi('altbild', '/uploads/aus-backup.jpg', 'image', 'Altes Bild') ?>\n");
_pesi_cleanup_old($site, ['pesi_field_altbild' => '/uploads/aus-backup.jpg'], ['index.php' => 'Start']);
ok('von Sicherung referenziertes Bild bleibt wiederherstellbar', is_file($site . '/uploads/aus-backup.jpg'));

// Nach einem gescheiterten Save muss das frisch hochgeladene Bild wieder weg —
// sonst bliebe es unreferenziert im Upload-Ordner liegen, und bei Personenfotos
// ist genau das der verwaiste Bestand, den pesi zu vermeiden verspricht.
file_put_contents($site . '/uploads/frisch-hochgeladen.jpg', 'x');
_pesi_cleanup_old($site, ['pesi_field_bild' => '/uploads/frisch-hochgeladen.jpg'], ['index.php' => 'Start']);
ok('unreferenziertes Bild nach Fehlschlag entfernt', !is_file($site . '/uploads/frisch-hochgeladen.jpg'));

// Traversal-Schutz: ein Pfad ausserhalb des Upload-Ordners wird nie gelöscht
file_put_contents($site . '/wichtig.php', 'BLEIBT');
_pesi_cleanup_old($site, ['pesi_field_c' => '/uploads/../wichtig.php'], ['index.php' => 'Start']);
ok('kein Löschen ausserhalb des Upload-Ordners', is_file($site . '/wichtig.php'));

// ── Redirect-Ziel: kein Open Redirect über REQUEST_URI ───────
// Top-Level-Code, den der Engine-Slice nicht erfasst — darum den echten Block
// aus pesi.php herausschneiden und ausführen, statt ihn hier nachzubauen.
grp('$selfUrl — Redirect-Ziel');
if (!preg_match('/\$selfUrl = strtok.*?\n\}\n/s', $src, $sm)) {
    ok('Guard-Block in pesi.php gefunden', false, 'Muster passt nicht mehr — Test anpassen');
} else {
    $guard = $sm[0];
    $cases = [
        '/pesi'                 => '/pesi',   // normal
        '/cms?x=1'              => '/cms',    // Query wird abgeschnitten
        '//evil.example/pesi'   => '/pesi',   // protokollrelativ → verworfen
        '/\\evil.example'       => '/pesi',   // Backslash-Variante → verworfen
        "/pesi\r\nX-Inject: 1"  => '/pesi',   // Steuerzeichen → verworfen
        'pesi'                  => '/pesi',   // ohne führenden Slash → verworfen
        ''                      => '/pesi',   // leer → Fallback
    ];
    foreach ($cases as $in => $want) {
        $_SERVER['REQUEST_URI'] = $in;
        $selfUrl = null;
        eval($guard);
        ok('REQUEST_URI ' . json_encode($in) . ' → ' . $want, $selfUrl === $want,
            'bekam: ' . json_encode($selfUrl));
    }
}

// ── Upload: Dateiname-Erzeugung ──────────────────────────────
// _pesi_slug() bestimmt, wie der Name auf dem Webspace landet. Alles, was
// nicht [a-z0-9-] ist, muss verschwinden — sonst wandern Pfadtrenner,
// Nullbytes oder eine zweite Endung in den Zielnamen.
grp('Upload — _pesi_slug');
foreach ([
    'Urlaubsfoto.JPG'          => 'urlaubsfoto',
    '../../etc/passwd'         => 'passwd',
    'shell.php'                => 'shell',
    'bild.php.jpg'             => 'bild-php',
    "null\x00byte.png"         => 'null-byte',
    'Ärztin Müller.jpeg'       => 'rztin-m-ller',
    '....'                     => 'bild',
    ''                         => 'bild',
    '   '                      => 'bild',
    str_repeat('a', 200) . '.png' => str_repeat('a', 50),
] as $in => $want) {
    $got = _pesi_slug($in);
    ok('slug(' . json_encode($in) . ')', $got === $want, 'bekam: ' . json_encode($got));
}
ok('Slug enthält nie einen Pfadtrenner',
    !preg_match('#[/\\\\]#', _pesi_slug('a/b\\c.png')));

// ── Upload: Zielordner-Prüfung ───────────────────────────────
grp('Upload — _pesi_upload_dir');
foreach ([
    'uploads'        => 'uploads',
    '/uploads/'      => 'uploads',
    'assets/bilder'  => 'assets/bilder',
    '../geheim'      => '',
    'uploads/../..'  => '',
    './uploads'      => '',
    ''               => '',
    '/'              => '',
    'uploads;rm -rf' => '',
    'C:/windows'     => '',
    "uploads\x00"    => '',
] as $in => $want) {
    $got = _pesi_upload_dir($in);
    ok('dir(' . json_encode($in) . ')', $got === $want, 'bekam: ' . json_encode($got));
}

// ── Upload: Typ wird aus dem Inhalt bestimmt, nicht aus dem Namen
grp('Upload — MIME statt Dateiendung');
$map = _pesi_upload_map();
$gif = $scratch . '/probe.gif';
file_put_contents($gif, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
$png = $scratch . '/probe.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
// PHP-Datei mit Bild-Endung. Inhalt bewusst harmlos — eine echte Webshell hier
// holt nur den Virenscanner auf den Plan; für „Typ kommt aus dem Inhalt"
// genügt irgendein Nicht-Bild.
$fake = $scratch . '/kein-bild.jpg';
file_put_contents($fake, "<?php\n// PHP-Quelltext, aber kein Bild.\n\$x = 1;\n");
echo '  (finfo ' . (class_exists('finfo') ? 'vorhanden' : 'FEHLT → getimagesize-Fallback') . ")\n";
foreach ([$gif => 'image/gif', $png => 'image/png'] as $f => $wantMime) {
    $m = _pesi_image_mime($f);
    ok(basename($f) . ' → ' . $wantMime, $m === $wantMime, 'erkannt: ' . json_encode($m));
    ok(basename($f) . ' ist in der Allowlist', isset($map[$m]));
    ok(basename($f) . ' bekommt Endung aus dem MIME', in_array($map[$m][0] ?? '', ['gif', 'png'], true));
}
$fm = _pesi_image_mime($fake);
ok('als .jpg getarntes PHP wird nicht als Bild erkannt', !isset($map[$fm]), 'erkannt: ' . json_encode($fm));
ok('unlesbare Datei liefert leeren Typ, nicht geraten', _pesi_image_mime($fake) === '' || !isset($map[$fm]));
ok('SVG ist bewusst nicht erlaubt', !isset($map['image/svg+xml']));
ok('nicht existierende Datei stürzt nicht ab', _pesi_image_mime($scratch . '/gibtsnicht.png') === '');

// ── Upload-Handler: Ablehnungen vor dem Schreiben ────────────
grp('Upload — Handler lehnt ab');
$imgField = ['bild' => ['id' => 'bild', 'value' => '/uploads/alt.jpg', 'type' => 'image', 'label' => 'Titelbild']];
$maxCfg   = defined('PESI_UPLOAD_MAX_BYTES') ? (int)PESI_UPLOAD_MAX_BYTES : 5242880;

$r = _pesi_handle_uploads($imgField, ['pesi_upload_bild' => ['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'name' => '', 'tmp_name' => '']], [], $scratch);
ok('kein neues Bild → keine Meldung, kein Eingriff', !$r['errors'] && !$r['old']);

$r = _pesi_handle_uploads($imgField, ['pesi_upload_bild' => ['error' => UPLOAD_ERR_OK, 'size' => $maxCfg + 1, 'name' => 'gross.jpg', 'tmp_name' => $png]], [], $scratch);
ok('zu großes Bild wird abgelehnt', count($r['errors']) === 1, json_encode($r['errors'], JSON_UNESCAPED_UNICODE));
ok('Ablehnung nennt die Beschriftung, nicht die Feld-ID',
    strpos($r['errors'][0] ?? '', 'Titelbild') !== false, $r['errors'][0] ?? '');

$r = _pesi_handle_uploads($imgField, ['pesi_upload_bild' => ['error' => UPLOAD_ERR_PARTIAL, 'size' => 10, 'name' => 'x.jpg', 'tmp_name' => $png]], [], $scratch);
ok('abgebrochener Upload wird abgelehnt', count($r['errors']) === 1);

// Der entscheidende Schutz: eine Datei, die nicht per HTTP hochgeladen wurde,
// darf der Handler niemals verschieben — sonst wäre jeder Pfad auf dem Server
// als „Upload" ausgebbar.
$r = _pesi_handle_uploads($imgField, ['pesi_upload_bild' => ['error' => UPLOAD_ERR_OK, 'size' => 68, 'name' => 'echt.png', 'tmp_name' => $png]], [], $scratch);
ok('nicht-hochgeladene Datei wird verweigert (is_uploaded_file)',
    count($r['errors']) === 1 && !isset($r['post']['pesi_field_bild']),
    json_encode($r, JSON_UNESCAPED_UNICODE));
ok('dabei wird nichts verschoben', is_file($png));

// ── Host-Limits: das Hosting deckelt oft unter PESI_UPLOAD_MAX_BYTES ──
grp('Upload — Host-Limits');
foreach (['2M' => 2097152, '512K' => 524288, '1G' => 1073741824, '8388608' => 8388608,
          '1.5M' => 1572864, '0' => 0, '-1' => 0, '' => 0] as $in => $want) {
    ok('ini_bytes(' . json_encode($in) . ')', _pesi_ini_bytes((string)$in) === $want, 'bekam: ' . _pesi_ini_bytes((string)$in));
}
$hostCaps = array_filter([_pesi_ini_bytes((string)ini_get('upload_max_filesize')), _pesi_ini_bytes((string)ini_get('post_max_size'))]);
$hostMin  = $hostCaps ? min($hostCaps) : PHP_INT_MAX;
ok('wirksames Limit = min(Konfiguration, Hosting)', _pesi_upload_limit() === min($maxCfg, $hostMin),
    _pesi_upload_limit() . ' vs ' . min($maxCfg, $hostMin));
ok('MB-Format ganzzahlig', _pesi_mb(5242880) === '5', _pesi_mb(5242880));
ok('MB-Format mit Nachkommastelle (DE)', _pesi_mb(1572864) === '1,5', _pesi_mb(1572864));
$r = _pesi_handle_uploads($imgField, ['pesi_upload_bild' => ['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'name' => 'foto.jpg', 'tmp_name' => '']], [], $scratch);
ok('vom Hosting abgewiesen → „zu groß", nicht „noch einmal versuchen"',
    count($r['errors']) === 1 && strpos($r['errors'][0], 'zu groß') !== false, $r['errors'][0] ?? '');
ok('Meldung nennt das wirksame Limit',
    strpos($r['errors'][0] ?? '', _pesi_mb(_pesi_upload_limit()) . ' MB') !== false, $r['errors'][0] ?? '');
ok('POST über post_max_size (verworfen) wird erkannt',
    _pesi_post_dropped(['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '9000000'], [], []));
ok('normaler POST ist kein Fehlalarm',
    !_pesi_post_dropped(['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '300'], ['pesi_csrf' => 'x'], []));
ok('GET ist kein Fehlalarm', !_pesi_post_dropped(['REQUEST_METHOD' => 'GET'], [], []));
ok('Meldung dazu trägt keinen Code — die Kundin kann selbst ein kleineres Bild wählen',
    !preg_match('/\(Code/', $GLOBALS['t']['up_err_post_size']));

// ── Zeilenenden: Browser senden CRLF, der Quelltext hat LF ──
// Sonst ist ein mehrzeiliges Feld beim ersten Speichern „geändert", ohne dass
// jemand tippte, und CR-Bytes landen in der PHP-Datei.
grp('Saver — Zeilenenden');
$p = page("<?php\n\$x = pesi('t', <<<'PESI'\nZeile 1\nZeile 2\nPESI, 'textarea', 'T');\n\$r = pesi('r', <<<'PESI'\n<p>a</p>\n<ul>\n<li>b</li>\n</ul>\nPESI, 'richtext', 'R');\n");
$r = _pesi_save($p, _pesi_parse($p), ['pesi_field_t' => "Zeile 1\r\nZeile 2", 'pesi_field_r' => "<p>a</p>\r\n<ul>\r\n<li>b</li>\r\n</ul>"]);
ok('CRLF-Echo eines unberührten Felds ist keine Änderung', $r['type'] === 'info', $r['msg']);
$r = _pesi_save($p, _pesi_parse($p), ['pesi_field_t' => "Neu 1\r\nNeu 2\rNeu 3"]);
ok('neuer Wert wird gespeichert', $r['type'] === 'success', $r['msg']);
ok('und landet mit LF im Quelltext', (_pesi_parse($p)['t']['value'] ?? '') === "Neu 1\nNeu 2\nNeu 3"
    && strpos((string)file_get_contents($p), "\r") === false);

// ── Brute-Force-Bremse ───────────────────────────────────────
grp('Login-Bremse');
$thr = _pesi_throttle_file();   // liegt neben der Engine, also im Scratch
@unlink($thr);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$thrKey = hash('sha256', '203.0.113.7');
$reg = function () use ($thr): array {
    $d = json_decode((string)@file_get_contents($thr), true);
    return is_array($d) ? $d : [];
};
// Simuliert den Ablauf der Sperrfrist, ohne zu warten.
$release = function () use ($thr, $thrKey, $reg): void {
    $d = $reg();
    $d[$thrKey]['until'] = time() - 1;
    file_put_contents($thr, json_encode($d));
};
$a = _pesi_throttle_acquire();
ok('frischer Client bekommt einen Versuch', $a === 0, var_export($a, true));
ok('der Versuch ist vorab als Fehlversuch verbucht', ($reg()[$thrKey]['n'] ?? 0) === 1);
$w1 = _pesi_throttle_acquire();
ok('solange er läuft, kommt kein zweiter Versuch durch', $w1 > 0, "wait=$w1");
ok('abgewiesener Versuch zählt nicht mit', ($reg()[$thrKey]['n'] ?? 0) === 1);

$waits = [];
for ($i = 0; $i < 20; $i++) {
    $release();
    _pesi_throttle_acquire();
    $waits[] = (int)($reg()[$thrKey]['until'] ?? 0) - time();
}
ok('Sperre wächst exponentiell', $waits[0] >= 3 && $waits[1] >= 7 && $waits[2] >= 15,
    implode(', ', array_slice($waits, 0, 4)));
ok('Sperre ist bei 256 s gedeckelt (nach 21 Versuchen)',
    max($waits) <= 256 && end($waits) >= 255, implode(', ', $waits));
ok('Zähler ist gedeckelt', ($reg()[$thrKey]['n'] ?? 0) === 20, (string)($reg()[$thrKey]['n'] ?? '?'));

$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
ok('andere IP bleibt frei', _pesi_throttle_acquire() === 0);
_pesi_throttle_reset();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
ok('erste IP weiterhin gesperrt', _pesi_throttle_acquire() > 0);
_pesi_throttle_reset();
ok('erfolgreicher Login löscht den Zähler', !isset($reg()[$thrKey]));
ok('danach ist wieder ein Versuch frei', _pesi_throttle_acquire() === 0);

$raw = (string)file_get_contents($thr);
ok('Register enthält keine Klartext-IP', strpos($raw, '203.0.113.7') === false, $raw);
ok('Register ist gültiges JSON', is_array(json_decode($raw, true)), $raw);
@unlink($thr);

// Registerausfall darf nicht wie „frei“ aussehen: Sonst bliebe nur die
// Session-Bremse, und die umgeht jedes neue Cookie.
$lockf = _pesi_throttle_lock_file();
@unlink($lockf);
mkdir($lockf);
ok('Lock nicht zu öffnen → null statt frei', _pesi_throttle_acquire() === null);
rmdir($lockf);
mkdir($thr);
ok('Register nicht schreibbar → null statt frei', _pesi_throttle_acquire() === null);
rmdir($thr);
ok('danach wieder nutzbar', _pesi_throttle_acquire() === 0);
@unlink($thr);

$_SESSION = [];
ok('frische Session ist nicht gesperrt', _pesi_session_throttle_check() === 0);
_pesi_session_throttle_fail();
ok('Session-Bremse greift pro Session', _pesi_session_throttle_check() > 0);
ok('Session-Bremse zählt Fehlversuche', ($_SESSION['pesi_fail'] ?? 0) === 1);
for ($i = 0; $i < 25; $i++) _pesi_session_throttle_fail();
$sw = _pesi_session_throttle_check();
ok('Session-Bremse ist bei 256 s gedeckelt', $sw <= 256 && $sw >= 255 && $_SESSION['pesi_fail'] === 20, "wait=$sw");
_pesi_session_throttle_reset();
ok('erfolgreicher Login setzt auch Session-Bremse zurück', _pesi_session_throttle_check() === 0);

// ── Request-Parameter: nur Strings ───────────────────────────
// `name[]=x` liefert PHP als Array. Ein (string)-Cast warnt, ein Array als
// Schlüssel wirft — beides zeigt bei display_errors den Installationspfad.
grp('Request-Parameter — Typen');
ok('String bleibt String', _pesi_param(['a' => 'x'], 'a') === 'x');
ok('fehlt → leer', _pesi_param([], 'a') === '');
ok('Array → leer', _pesi_param(['a' => ['x']], 'a') === '');
ok('verschachteltes Array → leer', _pesi_param(['a' => ['x' => ['y']]], 'a') === '');
$p = page("<?php\n\$x = pesi('f', 'ALT', 'text', 'L');\n");
$warned = [];
set_error_handler(function ($no, $str) use (&$warned) { $warned[] = $str; return true; });
$r = _pesi_save($p, _pesi_parse($p), ['pesi_field_f' => ['NEU']]);
restore_error_handler();
ok('Array als Feldwert löst keine PHP-Warnung aus', !$warned, implode(' | ', $warned));
ok('und wird nicht gespeichert', strpos((string)file_get_contents($p), "'ALT'") !== false
    && strpos((string)file_get_contents($p), 'Array') === false, $r['msg']);

// ── Sitzungsdauer ────────────────────────────────────────────
grp('Sitzung — Idle und absolute Grenze');
$pwS = 'geheim';
$sess = function (int $loginAgo, int $lastAgo) use ($pwS): array {
    return ['pesi_login_at' => time() - $loginAgo, 'pesi_last' => time() - $lastAgo,
            'pesi_pw_fingerprint' => hash('sha256', $pwS)];
};
ok('aktive Sitzung innerhalb beider Grenzen gilt',
    !_pesi_session_expired($sess(100, 10), time(), $pwS, 1800, 43200));
ok('Inaktivität beendet die Sitzung',
    _pesi_session_expired($sess(2000, 1900), time(), $pwS, 1800, 43200));
ok('absolute Grenze beendet auch eine aktive Sitzung',
    _pesi_session_expired($sess(43300, 5), time(), $pwS, 1800, 43200));
ok('absolute Grenze unter der Idle-Grenze wird nicht verlängert',
    _pesi_session_expired($sess(120, 0), time(), $pwS, 1800, 60));
ok('Mindestdauer eine Minute', !_pesi_session_expired($sess(30, 0), time(), $pwS, 1, 1));
ok('Passwortwechsel beendet die Sitzung',
    _pesi_session_expired($sess(10, 0), time(), 'neu', 1800, 43200));
ok('ohne Konfiguration gelten die Standardwerte',
    !_pesi_session_expired($sess(40000, 1700), time(), $pwS, null, null)
    && _pesi_session_expired($sess(44000, 10), time(), $pwS, null, null));

// ── Teilschreibung: Rollback statt halber Seite ──────────────
grp('Commit — atomarer Live-Austausch');
$p = page("<?php\n\$x = pesi('f', 'ORIGINAL', 'text', 'L');\n");
_pesi_backup($p);
ok('Backup wurde vollständig angelegt',
    is_file($p . '.pesi-backup.1') && filesize($p . '.pesi-backup.1') === filesize($p));
// Echten Syntaxfehler committen → Ablehnung inkl. Meldung prüfen
$r = _pesi_commit($p, "<?php\n\$x = ;;;\n");
ok('Temp-Prüfung meldet Fehler', $r !== null && $r['type'] === 'error');
ok('Meldung ist die sichere Syntax-Meldung',
    $r['msg'] === $GLOBALS['t']['err_php_rollback'], $r['msg']);
ok('Originalinhalt war durchgehend live', strpos((string)file_get_contents($p), 'ORIGINAL') !== false);
// Ungültige Inhalte werden bereits in der Temp-Datei erkannt. Die Live-Datei
// bleibt dabei unverändert und ein abgelehnter Versuch verbraucht kein Backup.
@unlink($p . '.pesi-backup.1');
@unlink($p . '.pesi-backup.2');
$r = _pesi_commit($p, "<?php\n\$x = ;;;\n");
ok('commit lehnt ungültige Temp-Datei vor Live-Austausch ab',
    $r !== null && $r['msg'] === $GLOBALS['t']['err_php_rollback'], $r['msg'] ?? '');
ok('abgelehnter Stand rotiert keine Sicherung', !is_file($p . '.pesi-backup.1'));
ok('guter Stand ist wieder da', strpos((string)file_get_contents($p), 'ORIGINAL') !== false);
ok('keine Temp-Datei bleibt liegen', count(glob($p . '.pesi-tmp-*')) === 0);

// ── Sicherung rotiert nur bei echtem Schreibvorgang ──────────
// Sonst schoben zwei folgenlose Klicks auf „Speichern" die echte Vorversion
// aus der Zweier-Rotation und „↩ Letzte Version" gab den Stand zurück, der
// ohnehin schon dastand. Genau das tut eine unsichere Nutzerin.
grp('Sicherung — rotiert nicht ohne Schreibvorgang');
function versionOf(string $f): string {
    preg_match("/'([A-Z0-9]+)'/", is_file($f) ? (string)file_get_contents($f) : '', $m);
    return $m[1] ?? '—';
}
$p = page("<?php\n\$x = pesi('f', 'V0', 'text', 'L');\n");
_pesi_backup($p);
file_put_contents($p, "<?php\n\$x = pesi('f', 'V1', 'text', 'L');\n");
_pesi_backup($p);
file_put_contents($p, "<?php\n\$x = pesi('f', 'V2', 'text', 'L');\n");
ok('Ausgangslage V2/V1/V0',
    versionOf($p) . versionOf($p . '.pesi-backup.1') . versionOf($p . '.pesi-backup.2') === 'V2V1V0');
ok('atomare Backup-Rotation hinterlässt keine Temp-Datei',
    count(glob(dirname($p) . '/*.pesi-tmp-*')) === 0);

$r = _pesi_save($p, _pesi_parse($p), ['pesi_field_f' => 'V2']);   // nichts geändert
ok('Save ohne Änderung meldet „nichts zu speichern"', $r['type'] === 'info', $r['msg']);
ok('Rotation unangetastet',
    versionOf($p . '.pesi-backup.1') === 'V1' && versionOf($p . '.pesi-backup.2') === 'V0',
    versionOf($p . '.pesi-backup.1') . '/' . versionOf($p . '.pesi-backup.2'));
ok('„Letzte Version" führt noch zurück', _pesi_restore($p)['type'] === 'success');
ok('nämlich auf V1', versionOf($p) === 'V1');

// Abgelehnter Wert darf ebenfalls nicht rotieren
$p = page("<?php\n\$x = pesi('f', 'V2', 'text', 'L');\n");
_pesi_backup($p);
$before = versionOf($p . '.pesi-backup.1');
$r = _pesi_save($p, _pesi_parse($p), ['pesi_field_f' => 'x<!-- pesi:item a:1 -->']);
ok('abgelehnter Wert meldet Fehler', $r['type'] === 'error');
ok('und rotiert die Sicherung nicht', versionOf($p . '.pesi-backup.1') === $before);

// ── Hilfsprozesse für Nebenläufigkeit ────────────────────────
// Startet PHP-Code als eigenen Prozess. Er meldet Bereitschaft über eine
// Datei, damit der Test erst weiterläuft, wenn Lock/Watcher wirklich stehen.
function bgStart(string $code): array {
    global $scratch;
    static $n = 0;
    $n++;
    $script = "$scratch/bg$n.php";
    $ready  = "$scratch/bg$n.ready";
    @unlink($ready);
    file_put_contents($script, "<?php\n\$READY = " . var_export($ready, true) . ";\n" . $code);
    $proc = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $until = microtime(true) + 10;
    while (!is_file($ready) && microtime(true) < $until) usleep(2000);
    return [$proc, $pipes, $script, $ready];
}
function bgWait(array $h): string {
    $out = stream_get_contents($h[1][1]) . stream_get_contents($h[1][2]);
    fclose($h[1][1]); fclose($h[1][2]);
    proc_close($h[0]);
    @unlink($h[2]); @unlink($h[3]);
    return $out;
}
function gens(string $p): string {
    return versionOf($p) . '/' . versionOf($p . '.pesi-backup.1') . '/' . versionOf($p . '.pesi-backup.2');
}
function v210(): string {
    $p = page("<?php\n\$x = pesi('f', 'V0', 'text', 'L');\n");
    _pesi_backup($p);
    file_put_contents($p, "<?php\n\$x = pesi('f', 'V1', 'text', 'L');\n");
    _pesi_backup($p);
    file_put_contents($p, "<?php\n\$x = pesi('f', 'V2', 'text', 'L');\n");
    return $p;
}
$V3 = "<?php\n\$x = pesi('f', 'V3', 'text', 'L');\n";
$leftovers = function (string $p): array { return glob(dirname($p) . '/*.pesi-tmp-*') ?: []; };

// ── Sicherung und Live-Austausch als ein Vorgang ─────────────
// Einzelne rename()-Aufrufe sind atomar, die Folge aus zwei Sicherungen und
// Live-Datei nicht. Ein gemeldeter Fehlschlag darf die Historie nicht
// verschieben: Sonst zeigt „Letzte Version“ auf den unveränderten Live-Stand
// und die älteste Generation ist weg.
grp('Commit — Sicherung wird bei Fehlschlag zurückgenommen');
$p = v210();
ok('Erfolg rotiert V3/V2/V1', _pesi_commit($p, $V3) === null && gens($p) === 'V3/V2/V1', gens($p));
ok('keine Zwischendateien', !$leftovers($p), implode(', ', $leftovers($p)));

if (PHP_OS_FAMILY === 'Windows') {
    // Ein offenes Handle verhindert unter Windows das Ersetzen des Ziels —
    // genau so hat das Review die späten Fehlschläge erzwungen.
    foreach (['' => 'Live-Datei', '.pesi-backup.1' => 'Sicherung 1'] as $sfx => $label) {
        $p = v210();
        $h = fopen($p . $sfx, 'r');
        $r = _pesi_commit($p, $V3);
        fclose($h);
        ok("$label nicht ersetzbar → Fehler gemeldet", $r !== null && $r['type'] === 'error');
        ok("… und Historie unverändert V2/V1/V0", gens($p) === 'V2/V1/V0', gens($p));
        ok("… ohne Zwischendateien", !$leftovers($p), implode(', ', $leftovers($p)));
    }
    // Sicherung 2 wird beiseitegelegt statt überschrieben. Umbenennen ist trotz
    // offenem Handle erlaubt, Ersetzen nicht — die frühere Rotation scheiterte
    // hier mit T2 und hatte Sicherung 2 schon verloren.
    $p = v210();
    $h = fopen($p . '.pesi-backup.2', 'r');
    $r = _pesi_commit($p, $V3);
    fclose($h);
    ok('offene Sicherung 2 blockiert den Commit nicht', $r === null && gens($p) === 'V3/V2/V1', gens($p));
    ok('… ohne Zwischendateien', !$leftovers($p), implode(', ', $leftovers($p)));
} else {
    echo "  (übersprungen: späte rename()-Fehler lassen sich nur unter Windows per offenem Handle erzwingen)\n";
}

// ── Externe Änderung während des Commits ─────────────────────
// Ein FTP-Server kennt den pesi-Lock nicht. Schreibt er, während pesi die
// Sicherungen anlegt, muss der Commit abbrechen statt seinen Stand zu
// überschreiben. Eine große Sicherung 1 macht das Kopierfenster sicher treffbar.
grp('Commit — fremder Schreibvorgang während der Sicherung');
$p = v210();
file_put_contents($p . '.pesi-backup.1', "<?php\n\$x = pesi('f', 'V1', 'text', 'L');\n//" . str_repeat('x', 32 * 1024 * 1024) . "\n");
$w = bgStart('$p = ' . var_export($p, true) . ";\n" . <<<'BG'
touch($READY);
$until = microtime(true) + 20;
while (microtime(true) < $until) {
    if (glob($p . '.pesi-backup.2.pesi-tmp-backup-*')) {
        file_put_contents($p, "<?php\n\$x = pesi('f', 'EXTERN', 'text', 'L');\n");
        echo 'geschrieben';
        exit;
    }
    usleep(500);
}
echo 'kein Fenster';
BG
);
$r = _pesi_commit($p, $V3, hash_file('sha256', $p));
$wout = bgWait($w);
ok('Hilfsprozess hat während der Sicherung geschrieben', $wout === 'geschrieben', $wout);
ok('Commit bricht als veraltet ab', $r !== null && $r['msg'] === $GLOBALS['t']['err_stale'], $r['msg'] ?? 'Erfolg gemeldet');
ok('fremder Stand bleibt live', versionOf($p) === 'EXTERN', versionOf($p));
ok('Sicherungen unverändert', versionOf($p . '.pesi-backup.1') === 'V1' && versionOf($p . '.pesi-backup.2') === 'V0',
    gens($p));
ok('keine Zwischendateien', !$leftovers($p), implode(', ', $leftovers($p)));
@unlink($p . '.pesi-backup.1');

// ── Eingeschaltete Syntaxprüfung ist verbindlich ─────────────
// Ohne exec() liefert _pesi_lint() null. Früher galt das als bestanden, und
// ungültiges PHP ging live. Geprüft wird in einem eigenen Prozess, weil sich
// exec() nur beim Start abschalten lässt.
grp('Commit — Linter nicht verfügbar');
$nolint = function (bool $check, string $candidate) use ($scratch): array {
    $dir = $scratch . '/nolint';
    @mkdir($dir);
    copy($scratch . '/engine.php', $dir . '/engine.php');
    $core = (string)file_get_contents($scratch . '/core.php');
    if (!$check) {
        $core = preg_replace_callback("/define\('PESI_SYNTAX_CHECK',[^\n]*\n/",
            function () { return "define('PESI_SYNTAX_CHECK', false);\n"; }, $core);
    }
    file_put_contents($dir . '/core.php', $core);
    $p = $dir . '/page.php';
    foreach (['', '.pesi-backup.1', '.pesi-backup.2'] as $s) @unlink($p . $s);
    file_put_contents($p, "<?php\n\$x = pesi('f', 'V1', 'text', 'L');\n");
    file_put_contents($p . '.pesi-backup.1', "<?php\n\$x = pesi('f', 'V0', 'text', 'L');\n");
    file_put_contents($dir . '/run.php', "<?php\nrequire __DIR__ . '/engine.php';\n\$GLOBALS['t'] = _pesi_strings()['de'];\n"
        . "\$r = _pesi_commit(__DIR__ . '/page.php', " . var_export($candidate, true) . ");\n"
        . "echo json_encode(['r' => \$r, 'exec' => function_exists('exec')]);\n");
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=exec ' . escapeshellarg($dir . '/run.php') . ' 2>&1');
    $res = (array)json_decode($out, true) + ['out' => $out];
    $res['live'] = versionOf($p);
    $res['b1']   = versionOf($p . '.pesi-backup.1');
    foreach (glob($dir . '/*') as $f) @unlink($f);
    @rmdir($dir);
    return $res;
};
$res = $nolint(true, "<?php\n\$x = ;;;\n");
ok('Prozess läuft ohne exec()', ($res['exec'] ?? true) === false, $res['out']);
ok('ungültiger Kandidat wird nicht veröffentlicht', $res['live'] === 'V1' && $res['b1'] === 'V0', $res['live'] . '/' . $res['b1']);
ok('Meldung nennt T7, nicht „Syntaxfehler“', ($res['r']['msg'] ?? '') === $GLOBALS['t']['err_no_lint'], json_encode($res['r'] ?? null));
$res = $nolint(true, "<?php\n\$x = pesi('f', 'V2', 'text', 'L');\n");
ok('auch ein gültiger Kandidat geht ungeprüft nicht live', $res['live'] === 'V1' && $res['r'] !== null);
$res = $nolint(false, "<?php\n\$x = pesi('f', 'V2', 'text', 'L');\n");
ok('mit PESI_SYNTAX_CHECK = false wird bewusst ungeprüft gespeichert',
    $res['live'] === 'V2' && $res['b1'] === 'V1' && array_key_exists('r', $res) && $res['r'] === null, json_encode($res));
foreach (['de', 'en'] as $l) {
    ok("$l/err_no_lint nennt keinen Syntaxfehler",
        stripos(_pesi_strings()[$l]['err_no_lint'], 'syntax') === false);
}

// ── Bild-Cleanup: unsicherer Bestand löscht nichts ───────────
grp('Bild-Cleanup — Lesefehler und parallele Referenz');
$site = $scratch . '/site';
@mkdir($site . '/uploads', 0777, true);
file_put_contents($site . '/index.php', "<?php ?>\n<p>ohne Bild</p>\n");
file_put_contents($site . '/uploads/geteilt.jpg', 'x');
file_put_contents($site . '/uploads/verwaist2.jpg', 'x');
// Ein vorhandener, aber nicht lesbarer Referenzträger
@mkdir($site . '/andere.php');
$pagesCU = ['index.php' => 'Start', 'andere.php' => 'Andere'];
_pesi_cleanup_old($site, ['/uploads/geteilt.jpg', '/uploads/verwaist2.jpg'], $pagesCU);
ok('unlesbare registrierte Seite → nichts gelöscht',
    is_file($site . '/uploads/geteilt.jpg') && is_file($site . '/uploads/verwaist2.jpg'));
rmdir($site . '/andere.php');
file_put_contents($site . '/andere.php', "<?php ?>\n<img src=\"/uploads/geteilt.jpg\">\n");
_pesi_cleanup_old($site, ['/uploads/geteilt.jpg', '/uploads/verwaist2.jpg'], $pagesCU);
ok('wieder lesbar: referenziertes Bild bleibt', is_file($site . '/uploads/geteilt.jpg'));
ok('… und wirklich verwaistes wird bereinigt', !is_file($site . '/uploads/verwaist2.jpg'));

// Ein Commit auf index.php setzt das Bild wieder ein, während die Bereinigung
// läuft. Der Hilfsprozess hält den Seiten-Lock wie _pesi_commit() und schreibt
// die Referenz erst nach einer Sekunde. Ohne gemeinsamen Lock hätte der Scan
// index.php längst ohne Referenz gelesen und das Bild gelöscht.
file_put_contents($site . '/uploads/zurueck.jpg', 'x');
$w = bgStart('$f = ' . var_export($site . '/index.php', true) . ";\n" . <<<'BG'
$l = fopen($f . '.pesi-lock', 'c+');
flock($l, LOCK_EX);
touch($READY);
usleep(1000000);
file_put_contents($f, "<?php ?>\n<?= pesi('bild', '/uploads/zurueck.jpg', 'image', 'Bild') ?>\n");
flock($l, LOCK_UN);
echo 'fertig';
BG
);
_pesi_cleanup_old($site, ['/uploads/zurueck.jpg'], $pagesCU);
$wout = bgWait($w);
ok('Hilfsprozess hat den Lock gehalten und geschrieben', $wout === 'fertig', $wout);
ok('parallel wieder eingesetztes Bild bleibt', is_file($site . '/uploads/zurueck.jpg'));
foreach (['/andere.php', '/index.php.pesi-lock', '/andere.php.pesi-lock', '/uploads/geteilt.jpg', '/uploads/zurueck.jpg'] as $f) @unlink($site . $f);

// ── Eine Anfrage, eine Aktion ────────────────────────────────
// Die Block-/Toggle-/Wiederherstellen-Buttons liegen im selben Formular wie
// die Felder, und 'pesi_save' ist ein verstecktes Feld — es kommt also bei
// jedem Absenden mit. Ohne die Unterscheidung lief nach jeder Strukturaktion
// zusätzlich der Save-Handler und überschrieb die Erfolgsmeldung mit
// „Seite wurde zwischenzeitlich geändert" (live gefunden, nicht im Code).
grp('POST — Struktur- vs. Speicheraktion');
if (!preg_match('/\$structCount = .*?\$structural = [^;]*;/s', $src, $stm)) {
    ok('Guard in pesi.php gefunden', false, 'Muster passt nicht mehr — Test anpassen');
} else {
    foreach ([
        'nur Speichern'            => [['pesi_save' => '1'], false],
        'Block + Speichern'        => [['pesi_save' => '1', 'pesi_block' => 'dup:team:1'], true],
        'Toggle + Speichern'       => [['pesi_save' => '1', 'pesi_toggle' => 'urlaub'], true],
        'Wiederherstellen + Save'  => [['pesi_save' => '1', 'pesi_restore' => '1'], true],
        'leeres POST'              => [[], false],
    ] as $name => [$post, $want]) {
        $_POST = $post;
        $structural = null;
        eval($stm[0]);
        ok($name . ' → ' . ($want ? 'strukturell' : 'speichern'), $structural === $want,
            'bekam: ' . var_export($structural, true));
    }
    $_POST = [];
}

// Das hidden-Attribut wirkt nur, solange keine eigene display-Regel gewinnt.
// .ob hat display:flex — ohne diese Zeile liess sich der Onboarding-Kasten
// nicht wegklicken.
ok('CSS neutralisiert [hidden] global',
    strpos($src, '[hidden]{display:none!important}') !== false,
    'Regel fehlt — hidden-Elemente können sichtbar bleiben');

// Quill serialisiert das DOM in eigener Form (Listen als <ol> mit data-list,
// ohne Zeilenumbrüche). Schreibt der Submit-Handler alle Editoren zurück, zählt
// ein unberührtes Feld bei jedem Speichern als Änderung.
ok('Richtext wird nur nach Bearbeitung neu serialisiert',
    strpos($src, "if(qt.has(id)) document.getElementById('h_'+id).value=q.root.innerHTML;") !== false,
    'Submit-Handler schreibt alle Editoren zurück');

// ── Standard-Markenfarbe muss die eigene Kontrastprüfung bestehen ──
// Sonst zeigt jede frische Installation ab Tag eins die T12-Diagnose.
grp('Markenfarbe — Standardwert');
$ratio = _pesi_contrast('#ffffff', BRAND_COLOR);
ok('BRAND_COLOR ' . BRAND_COLOR . ' erreicht 4,5:1 auf Weiss', $ratio >= 4.5, sprintf('%.2f:1', $ratio));
$fb = preg_match_all("/'(#[0-9a-f]{6})'/i", substr($src, strpos($src, '// ── Render'), 600), $fbm) ? array_unique($fbm[1]) : [];
ok('Fallback-Farbe in pesi.php ist dieselbe', $fb === [BRAND_COLOR] || $fb === [0 => BRAND_COLOR], json_encode($fb));

// ── i18n-Parität ─────────────────────────────────────────────
grp('i18n — DE/EN');
$s = _pesi_strings();
$onlyDe = array_diff(array_keys($s['de']), array_keys($s['en']));
$onlyEn = array_diff(array_keys($s['en']), array_keys($s['de']));
ok('keine DE-only Keys', !$onlyDe, implode(', ', $onlyDe));
ok('keine EN-only Keys', !$onlyEn, implode(', ', $onlyEn));

// Platzhalter müssen übereinstimmen — sonst wirft sprintf() zur Laufzeit,
// und zwar nur in der Sprache, die gerade niemand testet.
$mismatch = [];
foreach ($s['de'] as $k => $v) {
    if (!isset($s['en'][$k])) continue;
    preg_match_all('/%[a-z]/i', $v, $a);
    preg_match_all('/%[a-z]/i', $s['en'][$k], $b);
    if ($a[0] !== $b[0]) $mismatch[] = $k . ' (de: ' . implode('', $a[0]) . ' / en: ' . implode('', $b[0]) . ')';
}
ok('gleiche %-Platzhalter in beiden Sprachen', !$mismatch, implode('; ', $mismatch));

// Tote Keys: gepflegt, übersetzt — und nirgends verwendet. Der Fehler, der bei
// zweisprachigen Strings am leichtesten unbemerkt bleibt.
$strBlock = '';
if (preg_match('/function _pesi_strings.*?\n\]; \}/s', $src, $sb)) $strBlock = $sb[0];
$outside = str_replace($strBlock, '', $src);
$dead = [];
foreach (array_keys($s['de']) as $k) {
    if (strpos($outside, "'" . $k . "'") === false) $dead[] = $k;
}
ok('keine ungenutzten Keys', !$dead, implode(', ', $dead));

// Jede Meldung, die die Kundin nicht selbst beheben kann, trägt einen Code.
grp('i18n — Fehlercodes');
foreach (['err_not_readable', 'err_backup', 'err_locked', 'err_php_rollback',
          'err_file_missing', 'err_marker', 'err_write',
          'up_err_dir', 'up_err_dir_invalid',
          'blk_notfound', 'tgl_notfound', 'tgl_nested', 'blk_nested', 'err_no_lint', 'err_dup_ids', 'rt_no_dom',
          'warn_dup_ids', 'warn_no_tokenizer', 'warn_no_dom', 'warn_unparsed', 'warn_default_pw',
          'warn_no_exec', 'warn_upload_limit', 'login_unavailable'] as $k) {
    foreach (['de', 'en'] as $l) {
        ok("$l/$k trägt einen Code", (bool)preg_match('/\(Code [ST]\d+/', $s[$l][$k]),
            $s[$l][$k]);
    }
}

// ── $PESI_STRINGS-Override ───────────────────────────────────
// Öffentliche Oberfläche für Integratorinnen: einzelne Texte umformulieren,
// ohne pesi.php zu forken. Der echte Block aus pesi.php, nicht nachgebaut.
grp('$PESI_STRINGS — Override');
if (!preg_match('/if \(isset\(\$PESI_STRINGS\).*?\n\}\n/s', $src, $om)) {
    ok('Override-Block in pesi.php gefunden', false, 'Muster passt nicht mehr — Test anpassen');
} else {
    $t = _pesi_strings()['de'];
    $PESI_STRINGS = [
        'save_btn'   => 'Übernehmen',
        'gibtsnicht' => 'wird ignoriert',
        'blk_entry'  => 42,          // kein String → ignorieren
    ];
    eval($om[0]);
    ok('bekannter Key wird überschrieben', $t['save_btn'] === 'Übernehmen', $t['save_btn']);
    ok('unbekannter Key wird ignoriert', !array_key_exists('gibtsnicht', $t));
    ok('Nicht-String wird ignoriert', $t['blk_entry'] === 'Eintrag', var_export($t['blk_entry'], true));
    $t = _pesi_strings()['de'];   // Harness-Zustand zurücksetzen
    $GLOBALS['t'] = $t;
}

// ── Nicht erkannte Felder melden ─────────────────────────────
// Ein Wert in doppelten Anführungszeichen rendert normal, wird aber nie
// erfasst — das Feld fehlt lautlos im Dashboard.
grp('_pesi_unparsed_fields');
$p = page("<?php\n"
    . "\$a = pesi('gut', 'Wert', 'text', 'Gut');\n"
    . "\$b = pesi(\"schlecht\", \"Wert\", \"text\", \"Schlecht\");\n");
ok('einfach zitiertes Feld wird erkannt', isset(_pesi_parse($p)['gut']));
ok('doppelt zitiertes Feld fehlt im Parser', !isset(_pesi_parse($p)['schlecht']));
ok('und wird als nicht erkannt gemeldet', _pesi_unparsed_fields($p) === ['schlecht'],
    json_encode(_pesi_unparsed_fields($p)));
$p = page("<?php\n\$a = pesi('nur_gut', 'Wert', 'text', 'Gut');\n");
ok('saubere Seite meldet nichts', _pesi_unparsed_fields($p) === []);
$p = page("<?php\n\$a = pesi('x', 'A', 'text', 'X');\n\$b = pesi('x', 'B', 'text', 'X');\n");
ok('doppelte ID ist kein Fehlalarm', _pesi_unparsed_fields($p) === [], json_encode(_pesi_unparsed_fields($p)));

// ── $PESI_STRINGS end-to-end ─────────────────────────────────
// Der Test oben prüft nur die Merge-Regeln im herausgeschnittenen Block. Dass
// der Override die Ausgabe auch wirklich erreicht, sagt er nicht — und genau
// da klemmte es: eine zweite $t-Zuweisung weiter unten in pesi.php setzte die
// Sprachtabelle neu und warf den Override weg. Darum pesi.php wirklich starten.
grp('$PESI_STRINGS — wirkt bis in die Ausgabe');
$e2e = $scratch . '/e2e';
@mkdir($e2e, 0777, true);
copy($root . '/pesi.php', $e2e . '/pesi.php');
copy($root . '/pesi-core.php', $e2e . '/pesi-core.php');
$render = function (string $dir): string {
    $cmd = 'cd ' . escapeshellarg($dir) . ' && '
         . escapeshellarg(PHP_BINARY) . ' pesi.php';
    return (string)shell_exec($cmd . ' 2>&1');
};
$vorher = $render($e2e);
ok('unverändert erscheint der Standardtext', strpos($vorher, '>Anmelden<') !== false);

file_put_contents($e2e . '/pesi-core.php',
    "\n\$PESI_STRINGS = ['login_btn' => 'EINLOGGEN-XYZ'];\n", FILE_APPEND);
$nachher = $render($e2e);
ok('überschriebener Text erscheint', strpos($nachher, 'EINLOGGEN-XYZ') !== false,
    'Login-Button: ' . (preg_match('/class="L-b">([^<]*)/', $nachher, $mm) ? $mm[1] : '?'));
ok('der Standardtext ist verschwunden', strpos($nachher, '>Anmelden<') === false);
foreach (glob($e2e . '/*') as $f) @unlink($f);
@rmdir($e2e);

// ── Login-Handler end-to-end ─────────────────────────────────
// Die Bremse im Engine-Slice zu prüfen reicht nicht: Reihenfolge von CSRF,
// Sperre und Passwortprüfung steht im Controller, und die Race zwischen
// Prüfen und Verbuchen zeigt sich nur mit echten parallelen Prozessen. Jeder
// Request ist ein eigener PHP-Prozess mit eigener Session-Datei, wie hinter
// PHP-FPM; ein Runner setzt $_SERVER/$_POST und meldet den Session-Stand.
grp('Login — Controller');
$ctl = $scratch . '/ctl';
@mkdir($ctl . '/site', 0777, true);
@mkdir($ctl . '/sess', 0777, true);
$ctlClean = function () use ($ctl) {
    foreach (['/site/.pesi-throttle*', '/site/*', '/sess/*', '/*'] as $g) {
        foreach (glob($ctl . $g) as $f) { if (is_file($f)) @unlink($f); }
    }
    @rmdir($ctl . '/site/.pesi-throttle-lock');
    foreach (['/site', '/sess', ''] as $d) @rmdir($ctl . $d);
};
register_shutdown_function($ctlClean);
copy($root . '/pesi.php', $ctl . '/site/pesi.php');
$ctlPw = 'richtig-Passwort-42';
// Callback statt Ersetzungsstring: `$2y$10$…` wäre dort ein Rückverweis (Trap 2).
$ctlHash = password_hash($ctlPw, PASSWORD_BCRYPT, ['cost' => 10]);
file_put_contents($ctl . '/site/pesi-core.php', preg_replace_callback(
    "/define\('PESI_PASSWORD',[^\n]*\n/",
    function () use ($ctlHash) { return "define('PESI_PASSWORD', " . var_export($ctlHash, true) . ");\n"; },
    (string)file_get_contents($root . '/pesi-core.php')));
file_put_contents($ctl . '/runner.php', <<<'RUN'
<?php
$req = json_decode((string)file_get_contents($argv[1]), true);
ini_set('session.save_path', $req['sess']);
if ($req['sid'] !== '') session_id($req['sid']);
$_SERVER['REQUEST_METHOD'] = $req['method'];
$_SERVER['REMOTE_ADDR']    = $req['ip'];
$_SERVER['REQUEST_URI']    = '/pesi';
$_POST = $req['post'];
$_GET  = $req['get'];
register_shutdown_function(function () {
    echo "\n@@RESULT@@" . json_encode([
        'sid'  => session_id(),
        'auth' => !empty($_SESSION['pesi_auth']),
        'csrf' => $_SESSION['pesi_csrf'] ?? '',
        'fail' => $_SESSION['pesi_fail'] ?? 0,
    ]);
});
// Startbarriere für die parallelen Anfragen
if (!empty($req['at'])) { while (microtime(true) < $req['at']) usleep(200); }
require $req['dir'] . '/pesi.php';
RUN
);
$reqN = 0;
// Startet einen Request und gibt ein Handle zurück; $ctlWait holt das Ergebnis.
$ctlStart = function (array $r) use ($ctl, &$reqN): array {
    $r += ['sid' => '', 'method' => 'POST', 'ip' => '192.0.2.10', 'post' => [], 'get' => [], 'at' => 0,
           'sess' => $ctl . '/sess', 'dir' => $ctl . '/site'];
    $n = ++$reqN;
    file_put_contents("$ctl/req$n.json", json_encode($r));
    $proc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1',
                       '-d', 'opcache.enable_cli=0', "$ctl/runner.php", "$ctl/req$n.json"],
        [0 => ['pipe', 'r'], 1 => ['file', "$ctl/out$n.txt", 'w'], 2 => ['file', "$ctl/err$n.txt", 'w']], $pipes);
    fclose($pipes[0]);
    return [$proc, "$ctl/out$n.txt", "$ctl/err$n.txt"];
};
$ctlWait = function (array $h): array {
    proc_close($h[0]);
    $out = (string)file_get_contents($h[1]) . (string)file_get_contents($h[2]);
    $pos = strrpos($out, '@@RESULT@@');
    $res = $pos === false ? [] : (array)json_decode(substr($out, $pos + 10), true);
    return $res + ['out' => $pos === false ? $out : substr($out, 0, $pos), 'sid' => '', 'auth' => false, 'csrf' => '', 'fail' => 0];
};
$ctlRun = function (array $r) use ($ctlStart, $ctlWait): array { return $ctlWait($ctlStart($r)); };
$ctlOpen = function (string $ip) use ($ctlRun): array {   // Loginseite laden: Session + Token
    return $ctlRun(['method' => 'GET', 'ip' => $ip]);
};
$thrFile = $ctl . '/site/.pesi-throttle';
$thrEntry = function (string $ip) use ($thrFile) {
    $d = json_decode((string)@file_get_contents($thrFile), true);
    return is_array($d) ? ($d[hash('sha256', $ip)] ?? null) : null;
};
// error_log() aus dem T15-Pfad landet auf stderr und ist erwünscht.
$noDiag = function (string $out): bool {
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught|Stack trace)/i', $out);
};

// Positive Kontrolle — sonst bestünden die Negativfälle auch mit kaputter Fixture
$v = $ctlOpen('192.0.2.10');
ok('Loginseite liefert Session und Token', $v['sid'] !== '' && strlen($v['csrf']) === 32, $v['out']);
$r = $ctlRun(['sid' => $v['sid'], 'post' => ['pesi_login' => '1', 'pesi_csrf' => $v['csrf'], 'pesi_password' => $ctlPw]]);
ok('richtiges Passwort mit Token meldet an', $r['auth'], substr($r['out'], 0, 300));
ok('Session-ID erneuert, IP-Zähler gelöscht', $r['sid'] !== $v['sid'] && $thrEntry('192.0.2.10') === null);

// Fremdes Formular ohne Cookie und Token von derselben IP
$v = $ctlOpen('192.0.2.20');
$x = $ctlRun(['ip' => '192.0.2.20', 'post' => ['pesi_login' => '1', 'pesi_password' => 'falsch']]);
ok('Login-POST ohne Token prüft kein Passwort', !$x['auth'] && $x['fail'] === 0);
ok('… und setzt keine IP-Sperre', $thrEntry('192.0.2.20') === null, json_encode($thrEntry('192.0.2.20')));
ok('… und nennt den Grund', strpos($x['out'], htmlspecialchars($GLOBALS['t']['login_expired'])) !== false);
$x = $ctlRun(['ip' => '192.0.2.20', 'post' => ['pesi_login' => '1', 'pesi_csrf' => str_repeat('0', 32), 'pesi_password' => 'falsch']]);
ok('falsches Token setzt ebenfalls keine Sperre', $thrEntry('192.0.2.20') === null);
$r = $ctlRun(['sid' => $v['sid'], 'ip' => '192.0.2.20', 'post' => ['pesi_login' => '1', 'pesi_csrf' => $v['csrf'], 'pesi_password' => $ctlPw]]);
ok('direkt danach gelingt der echte Login', $r['auth'], substr($r['out'], 0, 300));

// Zwölf parallele Logins: gleiche IP, verschiedene Sessions, gültige Tokens
$sessions = [];
for ($i = 0; $i < 12; $i++) $sessions[] = $ctlOpen('192.0.2.30');
$at = microtime(true) + 1.5;
$hs = [];
foreach ($sessions as $s) {
    $hs[] = $ctlStart(['sid' => $s['sid'], 'ip' => '192.0.2.30', 'at' => $at,
        'post' => ['pesi_login' => '1', 'pesi_csrf' => $s['csrf'], 'pesi_password' => 'falsch']]);
}
$checked = 0;
foreach ($hs as $h) { if ($ctlWait($h)['fail'] > 0) $checked++; }
ok('von 12 parallelen Versuchen wird genau einer geprüft', $checked === 1, "geprüft: $checked");
ok('IP-Zähler steht auf 1', ($thrEntry('192.0.2.30')['n'] ?? 0) === 1, json_encode($thrEntry('192.0.2.30')));

// Register nicht nutzbar → keine Anmeldung, auch nicht mit frischem Cookie
@unlink($thrFile);
@unlink($ctl . '/site/.pesi-throttle-lock');
mkdir($ctl . '/site/.pesi-throttle-lock');
$v = $ctlOpen('192.0.2.40');
$r = $ctlRun(['sid' => $v['sid'], 'ip' => '192.0.2.40', 'post' => ['pesi_login' => '1', 'pesi_csrf' => $v['csrf'], 'pesi_password' => $ctlPw]]);
ok('ohne nutzbares Register meldet niemand an', !$r['auth'], substr($r['out'], 0, 300));
ok('… mit Code T15 statt „falsches Passwort“', strpos($r['out'], '(Code T15)') !== false
    && strpos($r['out'], htmlspecialchars($GLOBALS['t']['wrong_password'])) === false);
rmdir($ctl . '/site/.pesi-throttle-lock');

// Arrays statt Strings — kontrolliert abweisen, keine PHP-Diagnose
$v = $ctlOpen('192.0.2.50');
$x = $ctlRun(['sid' => $v['sid'], 'ip' => '192.0.2.50', 'post' => ['pesi_login' => '1', 'pesi_csrf' => ['x'], 'pesi_password' => 'x']]);
ok('pesi_csrf[] → keine PHP-Diagnose', $noDiag($x['out']) && !$x['auth'], substr($x['out'], 0, 300));
$x = $ctlRun(['sid' => $v['sid'], 'ip' => '192.0.2.50', 'post' => ['pesi_login' => '1', 'pesi_csrf' => $v['csrf'], 'pesi_password' => [['x']]]]);
ok('pesi_password[][] → keine PHP-Diagnose, keine Anmeldung', $noDiag($x['out']) && !$x['auth'], substr($x['out'], 0, 300));
$v = $ctlOpen('192.0.2.51');
$r = $ctlRun(['sid' => $v['sid'], 'ip' => '192.0.2.51', 'post' => ['pesi_login' => '1', 'pesi_csrf' => $v['csrf'], 'pesi_password' => $ctlPw]]);
$x = $ctlRun(['sid' => $r['sid'], 'ip' => '192.0.2.51', 'method' => 'GET', 'get' => ['page' => ['index.php']]]);
ok('page[] im Dashboard → keine PHP-Diagnose', $r['auth'] && $x['auth'] && $noDiag($x['out']), substr($x['out'], 0, 300));
$x = $ctlRun(['sid' => $r['sid'], 'ip' => '192.0.2.51', 'get' => ['page' => 'index.php'],
    'post' => ['pesi_csrf' => [$r['csrf']], 'pesi_block' => ['dup:x'], 'pesi_toggle' => ['x'], 'pesi_hash' => ['h']]]);
ok('Aktionsparameter als Arrays → keine PHP-Diagnose', $noDiag($x['out']),
    preg_match('/.{0,120}(Warning|Notice|Deprecated|Fatal error|Uncaught).{0,200}/is', $x['out'], $dm) ? $dm[0] : '');
// ── Dashboard-Controller: Aktionen, Entwurf, Standardknopf ───
// Echte Requests gegen pesi.php, angemeldet über denselben Runner.
grp('Dashboard — Controller');
$cp = $ctl . '/site/index.php';
$cpSrc = "<?php require_once 'pesi-core.php'; ?>\n"
    . "<h1><?= pesi('titel', 'Original', 'text', 'Titel') ?></h1>\n"
    . "<a href=\"<?= pesi('link', '/kontakt', 'url', 'Link') ?>\">x</a>\n"
    . "<!-- pesi:toggle hinweis -->\n<p>Hinweis</p>\n<!-- /pesi:toggle -->\n"
    . "<!-- pesi:item team:1 -->\n<p><?= pesi('team_1_name', 'Anna', 'text', 'Name') ?></p>\n<!-- /pesi:item -->\n"
    . "<!-- pesi:item team:2 -->\n<p><?= pesi('team_2_name', 'Bert', 'text', 'Name') ?></p>\n<!-- /pesi:item -->\n"
    . "<img src=\"<?= pesi('bild', '/uploads/a.jpg', 'image', 'Bild') ?>\" alt=\"\">\n";
$cpReset = function () use ($cp, $cpSrc) {
    foreach (['', '.pesi-backup.1', '.pesi-backup.2'] as $s) @unlink($cp . $s);
    file_put_contents($cp, $cpSrc);
};
$cpReset();
$v = $ctlOpen('192.0.2.60');
$a = $ctlRun(['sid' => $v['sid'], 'ip' => '192.0.2.60', 'post' => ['pesi_login' => '1', 'pesi_csrf' => $v['csrf'], 'pesi_password' => $ctlPw]]);
ok('angemeldet', $a['auth'], substr($a['out'], 0, 200));
$act = function (array $post) use ($ctlRun, $a, $cp) {
    return $ctlRun(['sid' => $a['sid'], 'ip' => '192.0.2.60', 'get' => ['page' => 'index.php'],
        'post' => $post + ['pesi_save' => '1', 'pesi_csrf' => $a['csrf'], 'pesi_hash' => hash_file('sha256', $cp)]]);
};

// Mehrere Strukturaktionen in einer Anfrage
foreach ([
    'Block + Toggle'           => ['pesi_block' => 'dup:team:1', 'pesi_toggle' => 'hinweis'],
    'Block + Restore'          => ['pesi_block' => 'dup:team:1', 'pesi_restore' => '1'],
    'Toggle + Restore'         => ['pesi_toggle' => 'hinweis', 'pesi_restore' => '1'],
    'alle drei'                => ['pesi_block' => 'dup:team:1', 'pesi_toggle' => 'hinweis', 'pesi_restore' => '1'],
] as $name => $post) {
    $cpReset();
    $x = $act($post);
    ok("$name → als mehrdeutig abgelehnt", strpos($x['out'], htmlspecialchars($GLOBALS['t']['err_ambiguous'])) !== false);
    ok("$name → Datei unverändert, keine Sicherung", file_get_contents($cp) === $cpSrc && !is_file($cp . '.pesi-backup.1'));
}
$cpReset();
$x = $act(['pesi_block' => 'dup:team:1']);
ok('einzelne Blockaktion mit verstecktem pesi_save läuft', count(_pesi_block_parse($cp)) === 3, substr($x['out'], 0, 200));

// Validierungsfehler: Entwurf bleibt, Fehler am Feld
$cpReset();
$hash0 = hash_file('sha256', $cp);
$x = $act(['pesi_field_titel' => 'ENTWURF-TITEL', 'pesi_field_link' => 'kein link']);
ok('ungültiger Link → Datei unverändert', file_get_contents($cp) === $cpSrc);
ok('… eingegebener Titel steht weiter im Feld', (bool)preg_match('/name="pesi_field_titel" value="ENTWURF-TITEL" data-saved="Original"/', $x['out']));
ok('… ungültiger Link ebenfalls, als fehlerhaft markiert',
    (bool)preg_match('/name="pesi_field_link" value="kein link" data-saved="\/kontakt" aria-invalid="true"/', $x['out']));
ok('… Hinweis, dass nichts gespeichert ist', strpos($x['out'], htmlspecialchars($GLOBALS['t']['draft_kept'])) !== false);
ok('… Formular trägt den Ausgangs-Hash', strpos($x['out'], 'name="pesi_hash" value="' . $hash0 . '"') !== false);
$x = $act(['pesi_field_titel' => 'ENTWURF-TITEL', 'pesi_field_link' => '/neu']);
ok('nach Korrektur werden beide Änderungen gespeichert', strpos((string)file_get_contents($cp), "'ENTWURF-TITEL'") !== false
    && strpos((string)file_get_contents($cp), "'/neu'") !== false, substr($x['out'], 0, 200));

// Veralteter Stand: Entwurf bleibt, aber unter dem alten Hash
$cpReset();
$old = hash_file('sha256', $cp);
file_put_contents($cp, str_replace('Anna', 'Anna (FTP)', $cpSrc));
$x = $ctlRun(['sid' => $a['sid'], 'ip' => '192.0.2.60', 'get' => ['page' => 'index.php'],
    'post' => ['pesi_save' => '1', 'pesi_csrf' => $a['csrf'], 'pesi_hash' => $old, 'pesi_field_titel' => 'ENTWURF-2']]);
ok('veraltet → nichts geschrieben', strpos((string)file_get_contents($cp), 'ENTWURF-2') === false);
ok('… Entwurf sichtbar', strpos($x['out'], 'value="ENTWURF-2"') !== false);
ok('… mit altem Hash, damit erneutes Speichern nicht die fremde Änderung überschreibt',
    strpos($x['out'], 'name="pesi_hash" value="' . $old . '"') !== false);

// Enter in einem Textfeld: erster Submit-Button im Formular ist Speichern
$cpReset();
$x = $ctlRun(['sid' => $a['sid'], 'ip' => '192.0.2.60', 'method' => 'GET', 'get' => ['page' => 'index.php']]);
$formPos = strpos($x['out'], 'id="pf"');
preg_match('/<button\b[^>]*>/', $x['out'], $fb, 0, $formPos === false ? 0 : $formPos);
ok('erster Button im Formular ist der Standard-Speichern-Knopf',
    $formPos !== false && isset($fb[0]) && strpos($fb[0], 'name=') === false && strpos($fb[0], 'type="submit"') !== false,
    $fb[0] ?? '(keiner)');
ok('… er liegt vor dem ersten Ein-/Ausblenden-Knopf',
    strpos($x['out'], $fb[0] ?? "\0", (int)$formPos) < strpos($x['out'], 'name="pesi_toggle"'));
ok('Dateiauswahl ist per Tastatur erreichbar (nicht hidden)',
    (bool)preg_match('/<input type="file"[^>]*data-img-input class="sr">/', $x['out'])
    && !preg_match('/<input type="file"[^>]*\bhidden\b/', $x['out']));
ok('URL-Platzhalter kommt aus der Sprachtabelle',
    strpos($x['out'], 'placeholder="' . htmlspecialchars($GLOBALS['t']['url_ph']) . '"') !== false);
$cpReset();

$ctlClean();

// ── Zeitangabe in der Wiederherstellen-Rückfrage ─────────────
grp('_pesi_when');
ok('heute',    _pesi_when(mktime(14, 23, 0)) === 'heute 14:23 Uhr', _pesi_when(mktime(14, 23, 0)));
ok('gestern',  _pesi_when(strtotime('-1 day 09:05')) === 'gestern 09:05 Uhr', _pesi_when(strtotime('-1 day 09:05')));
$alt = strtotime('-9 days 16:40');
ok('älter → Datum', _pesi_when($alt) === date('d.m.Y', $alt) . ' 16:40', _pesi_when($alt));
ok('Rückfrage nennt den Zeitpunkt',
    strpos(sprintf($GLOBALS['t']['rst_confirm'], _pesi_when(mktime(14, 23, 0))), 'heute 14:23') !== false);

// ── Anzeigenamen ─────────────────────────────────────────────
grp('_pesi_human');
foreach ([
    'team'             => 'Team',
    'team_mitglieder'  => 'Team mitglieder',
    'sommer-aktion'    => 'Sommer aktion',
    'urlaub'           => 'Urlaub',
    'öffnungszeiten'   => 'Öffnungszeiten',
    ''                 => '',
] as $in => $want) {
    ok('human(' . json_encode($in) . ')', _pesi_human($in) === $want, 'bekam: ' . _pesi_human($in));
}

// Aufräumen erledigt der Shutdown-Handler ganz oben — auch bei einem Abbruch.

echo "\n" . str_repeat('─', 46) . "\n";
echo ($fail === 0 ? "ALLE TESTS BESTANDEN" : "FEHLGESCHLAGEN") . " — $pass ok, $fail Fehler\n";
exit($fail === 0 ? 0 : 1);
