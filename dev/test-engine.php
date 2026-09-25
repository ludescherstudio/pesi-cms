<?php
/**
 * pesi CMS — Regressionstests für die reinen Engine-Funktionen.
 *
 *   php dev/test-engine.php
 *
 * NUR ÜBER DIE KOMMANDOZEILE. Dieses Skript legt Dateien an, ruft shell_exec()
 * und eval() auf und gibt Interna aus — über HTTP erreichbar wäre es ein
 * Einfallstor. `dev/` gehört nicht in eine Kundeninstallation (dorthin kommen
 * nur pesi.php, pesi-lib.php und pesi-core.php); die Sperre unten ist die Absicherung für
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
 * Richtext-Fallback ohne DOM, Backslashes in URLs) und die Funktionen aus 0.4
 * (Bild-Metadaten und Verkleinern, N Sicherungen mit Versionsliste,
 * Einstellungen getrennt vom Code, Passwort im Dashboard, Bildbeschreibung,
 * Bildauswahl). Wer
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
if (!is_file($root . '/pesi.php') || !is_file($root . '/pesi-core.php') || !is_file($root . '/pesi-lib.php')) {
    fwrite(STDERR, "Kein pesi.php/pesi-core.php/pesi-lib.php in: $root\n");
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
// pesi-core.php lädt pesi-lib.php aus ihrem eigenen Ordner: beide nebeneinander.
copy($root . '/pesi-core.php', $scratch . '/core.php');
copy($root . '/pesi-lib.php', $scratch . '/pesi-lib.php');
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
    @unlink($p);
    foreach (glob($p . '.pesi-*') ?: [] as $f) @unlink($f);
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
$stale = _pesi_restore($p, 1, hash_file('sha256', $p . '.pesi-backup.1'), $opened);
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
ok('„Letzte Version" führt noch zurück', _pesi_restore($p, 1, hash_file('sha256', $p . '.pesi-backup.1'))['type'] === 'success');
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
    // Ein offenes Handle verhindert unter Windows das Ersetzen des Ziels, nicht
    // das Umbenennen. Ersetzt wird nur noch die Live-Datei; die Sicherungen
    // rücken per rename() auf freie Namen weiter.
    $p = v210();
    $h = fopen($p, 'r');
    $r = _pesi_commit($p, $V3);
    fclose($h);
    ok('Live-Datei nicht ersetzbar → Fehler gemeldet', $r !== null && $r['type'] === 'error');
    ok('… und Historie unverändert V2/V1/V0', gens($p) === 'V2/V1/V0', gens($p));
    ok('… ohne Zwischendateien', !$leftovers($p), implode(', ', $leftovers($p)));
    foreach (['.pesi-backup.1' => 'Sicherung 1', '.pesi-backup.2' => 'Sicherung 2'] as $sfx => $label) {
        $p = v210();
        $h = fopen($p . $sfx, 'r');
        $r = _pesi_commit($p, $V3);
        fclose($h);
        ok("offene $label blockiert den Commit nicht", $r === null && gens($p) === 'V3/V2/V1', gens($p));
        ok('… ohne Zwischendateien', !$leftovers($p), implode(', ', $leftovers($p)));
    }
} else {
    echo "  (übersprungen: späte rename()-Fehler lassen sich nur unter Windows per offenem Handle erzwingen)\n";
}

// ── Externe Änderung während des Commits ─────────────────────
// Ein FTP-Server kennt den pesi-Lock nicht. Schreibt er, während pesi die
// Sicherungen anlegt, muss der Commit abbrechen statt seinen Stand zu
// überschreiben. Kopiert wird nur noch die Live-Datei; danach prüft pesi die
// Kopie, verschiebt die Generationen und hasht die Live-Datei vor dem Austausch
// noch einmal. In dieses Fenster schreibt der Hilfsprozess. Während der Kopie
// selbst geht es unter Windows nicht: Dort sperrt copy() die Quelle. Darum
// versucht er es so lange, bis die Kopie vollständig und das Schreiben
// gelungen ist, und meldet nur einen Erfolg als „geschrieben“. Eine große
// Live-Datei macht das Fenster (Hash der Kopie) sicher treffbar.
grp('Commit — fremder Schreibvorgang während der Sicherung');
$p = v210();
file_put_contents($p, "<?php\n\$x = pesi('f', 'V2', 'text', 'L');\n//" . str_repeat('x', 32 * 1024 * 1024) . "\n");
$w = bgStart('$p = ' . var_export($p, true) . ";\n\$size = " . filesize($p) . ";\n" . <<<'BG'
touch($READY);
$until = microtime(true) + 20;
while (microtime(true) < $until) {
    $tmp = glob($p . '.pesi-backup.1.pesi-tmp-backup-*');
    clearstatcache();
    if ($tmp && @filesize($tmp[0]) === $size
        && @file_put_contents($p, "<?php\n\$x = pesi('f', 'EXTERN', 'text', 'L');\n") !== false) {
        echo 'geschrieben';
        exit;
    }
    usleep(200);
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
    copy($scratch . '/pesi-lib.php', $dir . '/pesi-lib.php');
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

// ── Funktionen ab 0.4: Bilder, Versionen, Release, Passwort ──
// Diese Gruppen legen eigene Unterordner im Scratch an; ein eigener
// Shutdown-Handler räumt sie rekursiv weg, auch nach einem Fatal Error.
register_shutdown_function(function () use ($scratch) {
    if (!is_dir($scratch)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($scratch);
});

// ── Bild-Bereinigung: Metadaten raus, Größe runter ───────────
// Das Entfernen der Metadaten braucht kein gd, darum stammen die Testbilder aus
// festen Daten und diese Tests laufen überall. Verkleinern, Drehen und
// Pixelvergleiche brauchen gd, EXIF-Auslesen ext/exif; ohne sie werden genau
// diese Prüfungen sichtbar übersprungen.
grp('Bild — Metadaten und Verkleinern');
$hasGd   = function_exists('imagecreatetruecolor') && function_exists('imagecreatefromstring');
$hasExif = function_exists('exif_read_data');
if (!$hasGd)   echo "  (gd fehlt: Verkleinern, Drehen und Pixelvergleich werden übersprungen)\n";
if (!$hasExif) echo "  (exif fehlt: GPS/Orientierung werden über pesis eigenen Leser geprüft)\n";
$FX = [
    'jpg' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcg'
        . 'SlBFRyB2ODApLCBxdWFsaXR5ID0gNjAK/9sAQwANCQoLCggNCwoLDg4NDxMgFRMSEhMnHB4XIC4p'
        . 'MTAuKS0sMzpKPjM2RjcsLUBXQUZMTlJTUjI+WmFaUGBKUVJP/9sAQwEODg4TERMmFRUmTzUtNU9P'
        . 'T09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09P/8AAEQgAyAEs'
        . 'AwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMF'
        . 'BQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkq'
        . 'NDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqi'
        . 'o6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/E'
        . 'AB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMR'
        . 'BAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVG'
        . 'R0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKz'
        . 'tLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A'
        . '5+iiivGP0kKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigC3pX/IRi/H+Rro65zSv+QjF+'
        . 'P8jXR1w4r416H5Xxx/yMIf4F/wClSCiiiuY+NCiiigAooooAKKKKACiiigAooooAKKKKACiiigAo'
        . 'oooA5GiiivXP6GCiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAt6V/wAhGL8f5GujrnNK'
        . '/wCQjF+P8jXR1w4r416H5Xxx/wAjCH+Bf+lSCiiiuY+NCiiigAooooAKKKKACiiigAooooAKKKKA'
        . 'CiiigAooooA5GiiivXP6GCiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAt6V/yEYvx/ka'
        . '6Ouc0r/kIxfj/I10dcOK+Neh+V8cf8jCH+Bf+lSCiiiuY+NCiiigAooooAKKKKACiiigAooooAKK'
        . 'KKACiiigAooooA5GiiivXP6GCiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAt6V/yEYvx'
        . '/ka6Ouc0r/kIxfj/ACNdHXDivjXoflfHH/Iwh/gX/pUgooormPjQooooAKKKKACiiigAooooAKKK'
        . 'KACiiigAooooAKKKKAORooor1z+hgooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKALelf8'
        . 'hGL8f5GujrnNK/5CMX4/yNdHXDivjXoflfHH/Iwh/gX/AKVIKKKK5j40KKKKACiiigAooooAKKKK'
        . 'ACiiigAooooAKKKKACiiigDkaKKK9c/oYKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigC'
        . '3pX/ACEYvx/ka6Ouc0r/AJCMX4/yNdHXDivjXoflfHH/ACMIf4F/6VIKKKK5j40KKKKACiiigAoo'
        . 'ooAKKKKACiiigAooooAKKKKACiiigDg/7R/6Zf8Aj3/1qP7R/wCmX/j3/wBaqFFfsH9gZd/z7/GX'
        . '+Z+jf23jv+fn4L/Iv/2j/wBMv/Hv/rUf2j/0y/8AHv8A61UKKP7Ay7/n3+Mv8w/tvHf8/PwX+Rf/'
        . 'ALR/6Zf+Pf8A1qP7R/6Zf+Pf/WqhRR/YGXf8+/xl/mH9t47/AJ+fgv8AIv8A9o/9Mv8Ax7/61H9o'
        . '/wDTL/x7/wCtVCij+wMu/wCff4y/zD+28d/z8/Bf5F/+0f8Apl/49/8AWo/tH/pl/wCPf/WqhRR/'
        . 'YGXf8+/xl/mH9t47/n5+C/yL/wDaP/TL/wAe/wDrUf2j/wBMv/Hv/rVQoo/sDLv+ff4y/wAw/tvH'
        . 'f8/PwX+Rf/tH/pl/49/9aj+0f+mX/j3/ANaqFFH9gZd/z7/GX+Yf23jv+fn4L/Iv/wBo/wDTL/x7'
        . '/wCtR/aP/TL/AMe/+tVCij+wMu/59/jL/MP7bx3/AD8/Bf5F/wDtH/pl/wCPf/Wo/tH/AKZf+Pf/'
        . 'AFqoUUf2Bl3/AD7/ABl/mH9t47/n5+C/yOj8MTfbtftrbb5e/f8ANnOMIT0/Cu//ALD/AOnn/wAc'
        . '/wDr1534H/5G6x/7af8Aotq9br834woU8DjoU8OuVOCffW8u9+xy1qccxl7bFLmktL7ab9Ld2ZH9'
        . 'h/8ATz/45/8AXo/sP/p5/wDHP/r1r0V8p9YqdzL+yMH/ACfi/wDMyP7D/wCnn/xz/wCvR/Yf/Tz/'
        . 'AOOf/XrXoo+sVO4f2Rg/5Pxf+Zkf2H/08/8Ajn/16P7D/wCnn/xz/wCvWvRR9Yqdw/sjB/yfi/8A'
        . 'MyP7D/6ef/HP/r0f2H/08/8Ajn/1616KPrFTuH9kYP8Ak/F/5mR/Yf8A08/+Of8A16P7D/6ef/HP'
        . '/r1r0UfWKncP7Iwf8n4v/MyP7D/6ef8Axz/69H9h/wDTz/45/wDXrXoo+sVO4f2Rg/5Pxf8AmZH9'
        . 'h/8ATz/45/8AXo/sP/p5/wDHP/r1r0UfWKncP7Iwf8n4v/MyP7D/AOnn/wAc/wDr0f2H/wBPP/jn'
        . '/wBeteij6xU7h/ZGD/k/F/5mR/Yf/Tz/AOOf/Xo/sP8A6ef/ABz/AOvWvRR9Yqdw/sjB/wAn4v8A'
        . 'zPBqKKK/owxCiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooA3vA/8AyN1j/wBtP/RbV63X'
        . 'kngf/kbrH/tp/wCi2r1uvyPj3/kZQ/wL/wBKkd+F+B+oUUUV8SdIUUUUAFFFFABRRRQAUUUUAFFF'
        . 'FABRRRQAUUUUAFFFFAHg1FFFf0keOFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQBveB/'
        . '+Rusf+2n/otq9bryTwP/AMjdY/8AbT/0W1et1+R8e/8AIyh/gX/pUjvwvwP1CiiiviTpCiiigAoo'
        . 'ooAKKKKACiiigAooooAKKKKACiiigAooooA8Gooor+kjxwooooAKKKKACiiigAooooAKKKKACiii'
        . 'gAooooAKKKKAN7wP/wAjdY/9tP8A0W1et15J4H/5G6x/7af+i2r1uvyPj3/kZQ/wL/0qR34X4H6h'
        . 'RRRXxJ0hRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAeDUUUV/SR44UUUUAFFFFABRRRQ'
        . 'AUUUUAFFFFABRRRQAUUUUAFFFFAG94H/AORusf8Atp/6LavW68k8D/8AI3WP/bT/ANFtXrdfkfHv'
        . '/Iyh/gX/AKVI78L8D9Qooor4k6QooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKAPBqKKK/'
        . 'pI8cKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigDe8D/8jdY/9tP/AEW1et15J4H/AORu'
        . 'sf8Atp/6LavW6/I+Pf8AkZQ/wL/0qR34X4H6hRRRXxJ0hRRRQAUUUUAFFFFABRRRQAUUUUAFFFFA'
        . 'BRRRQAUUUUAeDUUUV/SR44UUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFAG94H/wCRusf+'
        . '2n/otq9boor8j49/5GUP8C/9Kkd+F+B+oUUUV8SdIUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUU'
        . 'UAFFFFAH/9k=',
    'prog' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcg'
        . 'SlBFRyB2ODApLCBxdWFsaXR5ID0gNjAK/9sAQwANCQoLCggNCwoLDg4NDxMgFRMSEhMnHB4XIC4p'
        . 'MTAuKS0sMzpKPjM2RjcsLUBXQUZMTlJTUjI+WmFaUGBKUVJP/9sAQwEODg4TERMmFRUmTzUtNU9P'
        . 'T09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09P/8IAEQgAyAEs'
        . 'AwEiAAIRAQMRAf/EABgAAQEBAQEAAAAAAAAAAAAAAAAEBQYD/8QAGgEBAAIDAQAAAAAAAAAAAAAA'
        . 'AAMGBAUHAv/aAAwDAQACEAMQAAABzxhWQAAAAAAAD10s3SgqoR6YAAAAAAADIGX0MAAAAAAAD10s'
        . '3SgqoR6YAAAAAAADIGX0MAAAAAAAD10s3SgqoR6YAAAAAAADIGX0MAAAAAAAD10s3SgqoR6YAAAA'
        . 'AAADIGX0MAAAAAAAD10s3SgqoR6YAAAAAAADIGX0MAAAAAAAD10s3SgqoR6YAAAAAAADBTrhY6E4'
        . 'oTihOKE4oTihOKE4oTjS3+d66txRrGp8xrBGsEawRrBGsEawRrBGsHBDo3gAAAAAAAC/ruR66ozh'
        . 'pJQAAAAAAAOCHScMAAAAAAAC/ruR66ozhpJQAAAAAAAOCHScMAAAAAAAC/ruR66ozhpJQAAAAAAA'
        . 'OCHScMAAAAAAAC/ruR66ozhpJQAAAAAAAOCHScMAAAAAAAC/ruR66ozhpJQAAAAAAAOCHScMAAAA'
        . 'AAAC/riozhpJQAAAAAAAP//EAB0QAAICAwEBAQAAAAAAAAAAAAATA1AEMzRAARL/2gAIAQEAAQUC'
        . '8UWyli2UsWyli2UsWyli2UsWyli2UsWyli2UsWyli2eNgwYMGDBgwYMGDBgwYMGDDG+/udAgQIEC'
        . 'BAgQIECBAgQIECBHjweulweulweulweulweulweulweulweulweulweulweulwevx//EACQRAAAE'
        . 'BQUAAwAAAAAAAAAAAAABAxQFMUBRUgIwNHHBBBIh/9oACAEDAQE/AdmOcguvTo45yC69OjjnILr0'
        . '6OOcguvTo45yC69OjjnILr09p6vkHq+Qer5B6vkHq+Qer5B6vkHq+Qer5DXpL5B/dX9MNEbBojYN'
        . 'EbBojYNEbBojYNEbBojYNEbbSUqNKVGlKjSlRpSo0pbX/8QAJREAAAIJBQEBAAAAAAAAAAAAAAEC'
        . 'AwQFExRAUrEwMTRxwSHR/9oACAECAQE/AdFbvRrd6NbvRrd6NbvRrd9KQZ7ciQZ7ciQZ7ciQZ7ci'
        . 'QZ7ciQZ7ciQZ7ciQZ7ciQZ7ch8IIqF5Iq/nz9ERIREhESERIREhESERIREhES0n9yS69Ojf3JLr0'
        . '6N/ckuvTo39yS69Ojf3JLr06N/ckuvT0v//EABwQAAEEAwEAAAAAAAAAAAAAAAACMTJQAYGxQP/a'
        . 'AAgBAQAGPwLxYpsU2KbFNimxTYpsU2KbFNimx5GGGGGGGGGGGGGGGGGGEpYkSJEiRIkSJEiRIkSJ'
        . 'EiRIl40b5TI3ymRvlMjfKZG+UyN8pkb5TI3ymRvlMjfKZG+UyN88n//EABwQAAMBAQEBAQEAAAAA'
        . 'AAAAAAAR8GFQIUCAgf/aAAgBAQABPyH9kAAAAAAQyGQyGQyGQyGQyGQyGQyGQyGQyGeeen7/AAhE'
        . 'IhEIhEIhEIhEIhEIhEIhEIhEIhfHNrjTa402uNNrjTa402uNNrjTa402uNNrjTa402vk/9oADAMB'
        . 'AAIAAwAAABAMMMMMMMMOsEEEEEEEEEMMMMMMMMOsEEEEEEEEEMMMMMMMMOsEEEEEEEEEMMMMMMMM'
        . 'OsEEEEEEEEEMMMMMMMMOsEEEEEEEEEMMMMMMMMOsEEEEEEEEE000000001nLLLLLLLLL77777777'
        . '6sIIIIIIIIL777777776sIIIIIIIIL777777776sIIIIIIIIL777777776sIIIIIIIIL77777777'
        . '6sIIIIIIIIL777777776MIIIIIIIIL//xAAgEQABAwQDAQEAAAAAAAAAAAABANHwMUBxoREhMEFR'
        . '/9oACAEDAQE/EPHR2Zo7M0dmaOzNHZmj8iQBlIAykAZSAMpAGUgDKQBlIAykAZcCODrmnVfnH6VK'
        . 'S6lJdSkupSXUpLqUl1KS6lJdSkv5Vc2dXNnVzZ1c2dXNnVz5f//EACARAAECBgMBAAAAAAAAAAAA'
        . 'AAABQDFhcaHR8BEwUSH/2gAIAQIBAT8Q6YNGcGjODRnBozg0ZwadW5cjcuRuXI3LkblyNy5G5cjc'
        . 'uRuXIRE4KlffvPrnwmEwmEwmEwmEwmdVrZlrZlrZlrZlrZlr6j//xAAeEAADAQEBAAMBAQAAAAAA'
        . 'AAAA8PGxAVARQIExgP/aAAgBAQABPxD6W/O+Nvzvjb8742/O+Nvzvjb8742/O+Nvzvjb8742/O+N'
        . 'vzvjb879R4HgeB4HgeB4HgeB4HgeB4HgeB4HgeD9nN8/Hx33+fgti2LYti2LYti2LYti2LYti2LY'
        . 'ti2LYt/7JAAAAAAAAAAAA//Z',
    'big' => '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcg'
        . 'SlBFRyB2ODApLCBxdWFsaXR5ID0gNjAK/9sAQwANCQoLCggNCwoLDg4NDxMgFRMSEhMnHB4XIC4p'
        . 'MTAuKS0sMzpKPjM2RjcsLUBXQUZMTlJTUjI+WmFaUGBKUVJP/9sAQwEODg4TERMmFRUmTzUtNU9P'
        . 'T09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09P/8AAEQgBkAJY'
        . 'AwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMF'
        . 'BQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkq'
        . 'NDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqi'
        . 'o6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/E'
        . 'AB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMR'
        . 'BAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVG'
        . 'R0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKz'
        . 'tLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A'
        . '5+iiivGP0kKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKK'
        . 'KKACiiigAooooAKKKKACiiigArX0H/lv/wAB/rWRWvoP/Lf/AID/AFrLEfw2fO8V/wDIorf9u/8A'
        . 'pUTXooorzT8bCiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKA'
        . 'CiiigAooooAKKKKACiiigAooooAKKKKAORooor1z+hgooooAKKKKACiiigAooooAKKKKACiiigAo'
        . 'oooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACtfQf8Alv8A8B/rWRWv'
        . 'oP8Ay3/4D/WssR/DZ87xX/yKK3/bv/pUTXooorzT8bCiiigAooooAKKKKACiiigAooooAKKKKACi'
        . 'iigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKAORooor1z+hgooo'
        . 'oAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiig'
        . 'AooooAKKKKACtfQf+W//AAH+tZFa+g/8t/8AgP8AWssR/DZ87xX/AMiit/27/wClRNeiiivNPxsK'
        . 'KKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAoo'
        . 'ooAKKKKACiiigAooooA5GiiivXP6GCiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKK'
        . 'ACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAK19B/wCW/wDwH+tZFa+g/wDLf/gP9ayx'
        . 'H8NnzvFf/Iorf9u/+lRNeiiivNPxsKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAoooo'
        . 'AKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooA5GiiivXP6GCiiigAooooAKKKKAC'
        . 'iiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAK1'
        . '9B/5b/8AAf61kVr6D/y3/wCA/wBayxH8NnzvFf8AyKK3/bv/AKVE16KKK80/GwooooAKKKKACiii'
        . 'gAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKA'
        . 'CiiigDkaKKK9c/oYKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAo'
        . 'oooAKKKKACiiigAooooAKKKKACiiigArX0H/AJb/APAf61kVr6D/AMt/+A/1rLEfw2fO8V/8iit/'
        . '27/6VE16KKK80/GwooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACi'
        . 'iigAooooAKKKKACiiigAooooAKKKKACiiigDkaKKK9c/oYKKKKACiiigAooooAKKKKACiiigAooo'
        . 'oAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigArX0H/lv/wAB/rWR'
        . 'WvoP/Lf/AID/AFrLEfw2fO8V/wDIorf9u/8ApUTXooorzT8bCiiigAooooAKKKKACiiigAooooAK'
        . 'KKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKAORooor1z'
        . '+hgooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKK'
        . 'ACiiigAooooAKKKKACtfQf8Alv8A8B/rWRWvoP8Ay3/4D/WssR/DZ87xX/yKK3/bv/pUTXooorzT'
        . '8bCiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAoooo'
        . 'AKKKKACiiigAooooAKKKKAORooor1z+hgooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKAC'
        . 'iiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACtfQf+W//AAH+tZFa+g/8t/8AgP8A'
        . 'WssR/DZ87xX/AMiit/27/wClRNeiiivNPxsKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiii'
        . 'gAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooA5GiiivXP6GCiiigAooooA'
        . 'KKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAo'
        . 'oooAK19B/wCW/wDwH+tZFa+g/wDLf/gP9ayxH8NnzvFf/Iorf9u/+lRNeiiivNPxsKKKKACiiigA'
        . 'ooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACi'
        . 'iigAooooA5GiiivXP6GCiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooo'
        . 'oAKKKKACiiigAooooAKKKKACiiigAooooAK19B/5b/8AAf61kVr6D/y3/wCA/wBayxH8NnzvFf8A'
        . 'yKK3/bv/AKVE16KKK80/GwooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAK'
        . 'KKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigDkaKKK9c/oYKKKKACiiigAooooAKKKKACii'
        . 'igAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigArX0H/AJb/'
        . 'APAf61kVr6D/AMt/+A/1rLEfw2fO8V/8iit/27/6VE16KKK80/GwooooAKKKKACiiigAooooAKKK'
        . 'KACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigDkaKw'
        . 'qK/SP9U/+n3/AJL/APbH63/rN/06/wDJv+AbtFYVFH+qf/T7/wAl/wDtg/1m/wCnX/k3/AN2isKi'
        . 'j/VP/p9/5L/9sH+s3/Tr/wAm/wCAbtFYVFH+qf8A0+/8l/8Atg/1m/6df+Tf8A3aKwqKP9U/+n3/'
        . 'AJL/APbB/rN/06/8m/4Bu0VhUUf6p/8AT7/yX/7YP9Zv+nX/AJN/wDdorCoo/wBU/wDp9/5L/wDb'
        . 'B/rN/wBOv/Jv+AbtFYVFH+qf/T7/AMl/+2D/AFm/6df+Tf8AAN2isKij/VP/AKff+S//AGwf6zf9'
        . 'Ov8Ayb/gG7RWFRR/qn/0+/8AJf8A7YP9Zv8Ap1/5N/wDdorCoo/1T/6ff+S//bB/rN/06/8AJv8A'
        . 'gG7RWFRR/qn/ANPv/Jf/ALYP9Zv+nX/k3/AN2isKij/VP/p9/wCS/wD2wf6zf9Ov/Jv+AbtFYVFH'
        . '+qf/AE+/8l/+2D/Wb/p1/wCTf8A3aKwqKP8AVP8A6ff+S/8A2wf6zf8ATr/yb/gG7RWFRR/qn/0+'
        . '/wDJf/tg/wBZv+nX/k3/AADdorCoo/1T/wCn3/kv/wBsH+s3/Tr/AMm/4Bu0VhUUf6p/9Pv/ACX/'
        . 'AO2D/Wb/AKdf+Tf8A3a19B/5b/8AAf61xdd78MP+Yn/2y/8AZ68rO8h+oYGpifac3LbS1t2lvd9z'
        . 'izLNP7Tw0sJycvNbW97Wae1l27l6iuuor8/+t+R8r/q9/wBPPw/4JyNFddRR9b8g/wBXv+nn4f8A'
        . 'BORorrqKPrfkH+r3/Tz8P+CcjRXXUUfW/IP9Xv8Ap5+H/BORorrqKPrfkH+r3/Tz8P8AgnI0V11F'
        . 'H1vyD/V7/p5+H/BORorrqKPrfkH+r3/Tz8P+CcjRXXUUfW/IP9Xv+nn4f8E5Giuuoo+t+Qf6vf8A'
        . 'Tz8P+CcjRXXUUfW/IP8AV7/p5+H/AATkaK66ij635B/q9/08/D/gnI0V11FH1vyD/V7/AKefh/wT'
        . 'kaK66ij635B/q9/08/D/AIJyNFddRR9b8g/1e/6efh/wTkaK66ij635B/q9/08/D/gnI0V11FH1v'
        . 'yD/V7/p5+H/BORorrqKPrfkH+r3/AE8/D/gnI0V11FH1vyD/AFe/6efh/wAE5Giuuoo+t+Qf6vf9'
        . 'PPw/4J4NRRRX9DHaFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAU'
        . 'UUUAFFFFABRRRQAUUUUAFFFFABRRRQAV3vww/wCYn/2y/wDZ64Ku9+GH/MT/AO2X/s9fNcX/APIm'
        . 'rf8Abv8A6VE2w/8AER3lFFFfiR6QUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQA'
        . 'UUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQB4NRRRX9JHjhRRRQAUUUUAFFFFABR'
        . 'RRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFd7'
        . '8MP+Yn/2y/8AZ64Ku9+GH/MT/wC2X/s9fNcX/wDImrf9u/8ApUTbD/xEd5RRRX4kekFFFFABRRRQ'
        . 'AUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFAB'
        . 'RRRQAUUUUAeDUUUV/SR44UUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFF'
        . 'FFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABXe/DD/mJ/wDbL/2euCrvfhh/zE/+2X/s9fNcX/8A'
        . 'Imrf9u/+lRNsP/ER3lFFFfiR6QUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUU'
        . 'UUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQB4NRRRX9JHjhRRRQAUUUUAFFFFABRRR'
        . 'QAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFd78M'
        . 'P+Yn/wBsv/Z64Ku9+GH/ADE/+2X/ALPXzXF//Imrf9u/+lRNsP8AxEd5RRRX4kekFFFFABRRRQAU'
        . 'UUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRR'
        . 'RQAUUUUAeDUUUV/SR44UUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFF'
        . 'ABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABXe/DD/AJif/bL/ANnrgq734Yf8xP8A7Zf+z181xf8A'
        . '8iat/wBu/wDpUTbD/wARHeUUUV+JHpBRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFF'
        . 'FABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFAHg1FFFf0keOFFFFABRRRQAUUUU'
        . 'AFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQA'
        . 'V3vww/5if/bL/wBnrgq734Yf8xP/ALZf+z181xf/AMiat/27/wClRNsP/ER3lFFFfiR6QUUUUAFF'
        . 'FFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUU'
        . 'UAFFFFABRRRQB4NRRRX9JHjhRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQ'
        . 'AUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFd78MP+Yn/ANsv/Z64Ku9+GH/MT/7Zf+z181xf'
        . '/wAiat/27/6VE2w/8RHeUUUV+JHpBRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFA'
        . 'BRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFAHg1FFFf0keOFFFFABRRRQAUUUUAF'
        . 'FFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAV3'
        . 'vww/5if/AGy/9nrgq734Yf8AMT/7Zf8As9fNcX/8iat/27/6VE2w/wDER3lFFFfiR6QUUUUAFFFF'
        . 'ABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUA'
        . 'FFFFABRRRQB4NRRRX9JHjhRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAU'
        . 'UUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFd78MP8AmJ/9sv8A2euCrvfhh/zE/wDtl/7PXzXF'
        . '/wDyJq3/AG7/AOlRNsP/ABEd5RRRX4kekFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQA'
        . 'UUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAeDUUUV/SR44UUUUAFFFFABR'
        . 'RRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFF'
        . 'FABXe/DD/mJ/9sv/AGeuCrvfhh/zE/8Atl/7PXzXF/8AyJq3/bv/AKVE2w/8RHeUUUV+JHpBRRRQ'
        . 'AUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFAB'
        . 'RRRQAUUUUAFFFFAHg1FFFf0keOFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFF'
        . 'FFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAV3vww/5if8A2y/9nrgq734Yf8xP/tl/7PXz'
        . 'XF//ACJq3/bv/pUTbD/xEd5RRRX4kekFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUU'
        . 'UUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAeDUUUV/SR44UUUUAFFFFABRRR'
        . 'QAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFA'
        . 'BXe/DD/mJ/8AbL/2euCrvfhh/wAxP/tl/wCz181xf/yJq3/bv/pUTbD/AMRHeUUUV+JHpBRRRQAU'
        . 'UUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRR'
        . 'RQAUUUUAFFFFAH//2Q==',
    'png' => 'iVBORw0KGgoAAAANSUhEUgAAAlgAAAGQCAYAAAByNR6YAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAE'
        . '2ElEQVR42u3WsQ0AMAzDMLv//5zc0DEAeYImdZIJwAFNqgJwwZMAAMBgAQAYLAAAgwUAgMECADBY'
        . 'AAAGCwAAgwUAYLAAAAwWAAAGCwDAYAEAGCwAAIMFAIDBAgAwWAAABgsAAIMFAGCwAAAMFgAABgsA'
        . 'wGABABgsAAAMFgCAwQIAMFgAAAYLAACDBQBgsAAADBYAAAYLAMBgAQAYLAAADBYAgMECADBYAAAY'
        . 'LAAAgwUAYLAAAAwWAAAGCwDAYAEAGCwAAAwWAIDBAgAwWAAAGCwAAIMFAGCwAAAwWAAABgsAwGAB'
        . 'ABgsAAAMFgCAwQIAMFgAABgsAACDBQBgsAAAMFgAAAYLAMBgAQAYLAAADBYAgMECADBYAAAYLAAA'
        . 'gwUAYLAAADBYAAAGCwDAYAEAYLAAAAwWAIDBAgAwWAAAGCwAAIMFAGCwAAAwWAAABgsAwGABAGCw'
        . 'AAAMFgCAwQIAwGABABgsAACDBQBgsAAAMFgAAAYLAMBgAQBgsAAADBYAgMECAMBgAQAYLAAAgwUA'
        . 'gMECADBYAAAGCwDAYAEAYLAAAAwWAIDBAgDAYAEAGCwAAIMFAIDBAgAwWAAABgsAwGABAGCwAAAM'
        . 'FgCAwQIAwGABABgsAACDBQCAwQIAMFgAAAYLAACDBQBgsAAADBYAgMECAMBgAQAYLAAAgwUAgMEC'
        . 'ADBYAAAGCwAAgwUAYLAAAAwWAAAGCwDAYAEAGCwAAIMFAIDBAgAwWAAABgsAAIMFAGCwAAAMFgAA'
        . 'BgsAwGABABgsAAAMFgCAwQIAMFgAAAYLAACDBQBgsAAADBYAAAYLAMBgAQAYLAAADBYAgMECADBY'
        . 'AAAGCwAAgwUAYLAAAAwWAAAGCwDAYAEAGCwAAAwWAIDBAgAwWAAAGCwAAIMFAGCwAAAMFgAABgsA'
        . 'wGABABgsAAAMFgCAwQIAMFgAABgsAACDBQBgsAAAMFgAAAYLAMBgAQAYLAAADBYAgMECADBYAAAY'
        . 'LAAAgwUAYLAAADBYAAAGCwDAYAEAYLAAAAwWAIDBAgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'
        . 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACAHwt78wOQylEy/gAAAABJ'
        . 'RU5ErkJggg==',
    'gif' => 'R0lGODdhKAAoAJEAAP//AP8AAAD/AAAA/ywAAAAAKAAoAAACgoyPmcLt/5SUsNaJj90tZ855GLiJ'
        . 'E2mZFAqpCtu6CBzJGt3ZBp7rPKPb/YKBn4BoRA6DSebS97QZB9Sq9XoFaLfcbhcLBnvH47C5Sk5v'
        . 'z2e1mm12p+FhOZkutnvxWP2eb+X3BYgmuEZIZXiIqKiFmNj4ONAIIElpGfl4qZnJ2ElIWQAAOw==',
    'webp' => 'UklGRrgBAABXRUJQVlA4IKwBAAAQGQCdASosAcgAPrVaqE8nJSQiJCgA4BaJZ27hdVD5wS7GpzD7'
        . 'AP0zoUD2Afyu3AP8BlAH6wegn/sf7l1gACWWQUNK2nK/f89YFknLAsgR+fMxWwLIKGyMZTO2BmyR'
        . 'csgoRsEvC/6zMVsCxxLqGjywM2SLljiXUNHlgR4F3M1QSCQSCQSCQR8HlV/5JJJJJJJJJJJKKA6b'
        . 'EvoRKvVZNl5p7skXIePELorYFkFDZGMuAR6zMVsCyCaWXXLIKGyRcsgR/Y4rYFkFDZItUAFtki5A'
        . 'AAD+/pkv+iZVpY//rgPF5zZfsubNCsWgQKRhPp3VfeF64QTNOK/8NfS5Hmb/+a7V9iJEof/81fLc'
        . 'h97kShPX4l+GL4EBFQ27+IF/IAXFKBT65bbLtH0CsWOk1oaxY6TYeLz9XX99ZZX2zQxD/rEbzmP9'
        . 'b3TBFIQdbb3TBFIQdbMHqzNvPCIR//4ueX6Hz1UguFeD8qK/k2OzqfT3Zf8OHge2KmzbvcJnQ8Jo'
        . 'N38neGNJSRcPjVws9oYb0wrLkS4OlL8BgCz88IJ4cT16iABthBGIG0oFUgEscAAAAA==',
];
$FX = array_map('base64_decode', $FX);

// Testbild für gd-Tests: vier Quadranten R G / B Y, damit sich Drehungen prüfen lassen.
function quad(int $w, int $h) {
    $im = imagecreatetruecolor($w, $h);
    $c = [imagecolorallocate($im, 255, 0, 0), imagecolorallocate($im, 0, 255, 0),
          imagecolorallocate($im, 0, 0, 255), imagecolorallocate($im, 255, 255, 0)];
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, intdiv($h, 2) - 1, $c[0]);
    imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, intdiv($h, 2) - 1, $c[1]);
    imagefilledrectangle($im, 0, intdiv($h, 2), intdiv($w, 2) - 1, $h - 1, $c[2]);
    imagefilledrectangle($im, intdiv($w, 2), intdiv($h, 2), $w - 1, $h - 1, $c[3]);
    return $im;
}
function enc($im, string $fn): string { ob_start(); $fn($im); return (string)ob_get_clean(); }
// Farbe an relativer Position → R, G, B oder Y
function col($im, float $fx, float $fy): string {
    $rgb = imagecolorat($im, (int)(imagesx($im) * $fx), (int)(imagesy($im) * $fy));
    $r = ($rgb >> 16) & 255; $g = ($rgb >> 8) & 255; $b = $rgb & 255;
    if ($r > 128 && $g > 128) return 'Y';
    if ($r > 128) return 'R';
    if ($g > 128) return 'G';
    return $b > 128 ? 'B' : '?';
}
function corners($im): string {
    return col($im, .1, .1) . col($im, .9, .1) . col($im, .1, .9) . col($im, .9, .9);
}
// EXIF wie aus dem Handy: Beschreibung, Orientierung und GPS-Block (big endian)
function exifApp1(int $orient): string {
    $desc = "SECRET-DESC\0";
    $ifd0 = 8; $n0 = 3; $gpsOff = $ifd0 + 2 + $n0 * 12 + 4; $descOff = $gpsOff + 2 + 12 + 4;
    $t  = "MM\x00\x2A" . pack('N', $ifd0) . pack('n', $n0);
    $t .= pack('nnNN', 0x010E, 2, strlen($desc), $descOff);
    $t .= pack('nnNnn', 0x0112, 3, 1, $orient, 0);
    $t .= pack('nnNN', 0x8825, 4, 1, $gpsOff);
    $t .= pack('N', 0);
    $t .= pack('n', 1) . pack('nnN', 0x0001, 2, 2) . "N\0\0\0" . pack('N', 0);   // GPSLatitudeRef
    $t .= $desc;
    return "\xFF\xE1" . pack('n', 8 + strlen($t)) . "Exif\0\0" . $t;
}
function seg(int $m, string $p): string { return "\xFF" . chr($m) . pack('n', 2 + strlen($p)) . $p; }
// Metadaten hinter APP0 einfügen, Müll hinter das Bildende hängen
function dirtyJpeg(string $jpg, int $orient): string {
    $app0 = strncmp(substr($jpg, 2), "\xFF\xE0", 2) === 0 ? 4 + unpack('n', $jpg, 4)[1] : 2;
    $meta = exifApp1($orient)
          . seg(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>SECRET-XMP</x:xmpmeta>")
          . seg(0xE2, "ICC_PROFILE\0\x01\x01FAKE-ICC")
          . seg(0xED, "Photoshop 3.0\0SECRET-IPTC")
          . seg(0xFE, 'SECRET-COMMENT');
    return substr($jpg, 0, $app0) . $meta . substr($jpg, $app0) . 'SECRET-TRAILER';
}
function tmpImg(string $bytes, string $ext = 'jpg'): string {
    global $scratch;
    $f = $scratch . '/img-' . bin2hex(random_bytes(4)) . '.' . $ext;
    file_put_contents($f, $bytes);
    return $f;
}
// Orientierung einer Datei: ext/exif, sonst pesis eigener Leser.
function orientOf(string $f): int {
    if (function_exists('exif_read_data')) return (int)(@exif_read_data($f)['Orientation'] ?? 1);
    return _pesi_jpeg_orientation((string)file_get_contents($f));
}

$d = dirtyJpeg($FX['jpg'], 6);
ok('Testaufbau: alle Geheimnisse stecken im Bild',
    preg_match_all('/SECRET-[A-Z]+/', $d) === 5 && _pesi_jpeg_orientation($d) === 6);
$f = tmpImg($d);
ok('JPEG unter der Grenze wird bereinigt', _pesi_prepare_image($f, 'image/jpeg', 2560));
$out = (string)file_get_contents($f);
ok('kein SECRET mehr in der Datei', strpos($out, 'SECRET') === false,
    implode(',', array_unique(preg_match_all('/SECRET-[A-Z]+/', $out, $mm) ? $mm[0] : [])));
ok('Farbprofil (ICC) bleibt', strpos($out, 'FAKE-ICC') !== false);
if ($hasExif) {
    $ex = @exif_read_data($f);
    ok('keine GPS-Daten mehr', is_array($ex) && !isset($ex['GPSLatitudeRef']) && !isset($ex['GPSVersion']), json_encode(array_keys($ex ?: [])));
}
ok('Orientierung 6 bleibt erhalten', orientOf($f) === 6, (string)orientOf($f));
ok('Bilddaten Byte für Byte unverändert (nicht neu kodiert)',
    substr($out, -strlen(substr($FX['jpg'], strpos($FX['jpg'], "\xFF\xDA")))) === substr($FX['jpg'], strpos($FX['jpg'], "\xFF\xDA")));
ok('gleiche Größe', getimagesize($f)[0] === 300 && getimagesize($f)[1] === 200);
if ($hasGd) {
    $im = @imagecreatefromstring($out);
    ok('bereinigtes JPEG ist lesbar, Pixel unverändert', $im && corners($im) === 'RGBY');
}

$f = tmpImg(dirtyJpeg($FX['jpg'], 1));
_pesi_prepare_image($f, 'image/jpeg', 2560);
ok('Orientierung 1: gar kein EXIF mehr', strpos((string)file_get_contents($f), 'Exif') === false);

// Progressives JPEG: mehrere Scans mit Tabellen dazwischen
$f = tmpImg(dirtyJpeg($FX['prog'], 3));
ok('progressives JPEG wird bereinigt', _pesi_prepare_image($f, 'image/jpeg', 2560));
$out = (string)file_get_contents($f);
ok('progressiv: kein SECRET, Bildende vorhanden', strpos($out, 'SECRET') === false && substr($out, -2) === "\xFF\xD9");
if ($hasGd) ok('progressiv: lesbar', ($im = @imagecreatefromstring($out)) && corners($im) === 'RGBY');

if ($hasGd) {
    // Verkleinern + Drehung einbrennen, alle acht EXIF-Orientierungen
    $expect = [1 => 'RGBY', 2 => 'GRYB', 3 => 'YBGR', 4 => 'BYRG',
               5 => 'RBGY', 6 => 'BRYG', 7 => 'YGBR', 8 => 'GYRB'];
    foreach ($expect as $o => $want) {
        $f = tmpImg(dirtyJpeg($FX['big'], $o));
        $okp = _pesi_prepare_image($f, 'image/jpeg', 300);
        $out = (string)file_get_contents($f);
        $im  = @imagecreatefromstring($out);
        $dims = $im ? imagesx($im) . 'x' . imagesy($im) : '-';
        $wantDims = $o >= 5 ? '200x300' : '300x200';
        ok("Orientierung $o: verkleinert, richtig gedreht, ohne EXIF",
            $okp && $im && $dims === $wantDims && corners($im) === $want
                && strpos($out, 'Exif') === false && strpos($out, 'SECRET') === false,
            "$dims, Ecken " . ($im ? corners($im) : '-') . ", erwartet $wantDims $want");
    }
    ok('GD-Kommentar (CREATOR: gd-jpeg) entfernt', strpos($out, 'CREATOR') === false);
}
ok('PESI_IMAGE_MAX_EDGE 0 verkleinert nie',
    _pesi_prepare_image($f = tmpImg($FX['big']), 'image/jpeg', 0) && getimagesize($f)[0] === 600);
ok('ohne Verkleinern verschwindet auch der gd-Kommentar', strpos((string)file_get_contents($f), 'CREATOR') === false);

// PNG: Textblöcke und eXIf raus, Transparenz bleibt
function pngChunk(string $type, string $data): string {
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}
$iend = strrpos($FX['png'], 'IEND') - 4;
$dirtyPng = substr($FX['png'], 0, $iend) . pngChunk('tEXt', "Comment\0SECRET-PNG")
          . pngChunk('eXIf', 'MM' . 'SECRET-EXIF') . pngChunk('tIME', "\x07\xEA\x01\x01\0\0\0")
          . substr($FX['png'], $iend) . 'SECRET-TRAILER';
$f = tmpImg($dirtyPng, 'png');
ok('PNG wird bereinigt', _pesi_prepare_image($f, 'image/png', 2560));
$out = (string)file_get_contents($f);
ok('PNG: kein SECRET, kein tIME', strpos($out, 'SECRET') === false && strpos($out, 'tIME') === false);
ok('PNG: Bilddaten unverändert, endet mit IEND', strpos($out, substr($FX['png'], 8, $iend - 8)) === 8 && substr($out, -8, 4) === 'IEND');
if ($hasGd) {
    ok('PNG: lesbar', (bool)@imagecreatefromstring($out));
    $f = tmpImg($dirtyPng, 'png');
    _pesi_prepare_image($f, 'image/png', 300);
    $im = @imagecreatefromstring((string)file_get_contents($f));
    ok('PNG verkleinert', $im && imagesx($im) === 300 && imagesy($im) === 200);
    ok('PNG: Transparenz bleibt beim Verkleinern',
        $im && ((imagecolorat($im, 250, 150) >> 24) & 127) === 127 && col($im, .2, .2) === 'R');
}

// WebP mit VP8X-Kopf und EXIF-Chunk
$vp8 = substr($FX['webp'], 12);                                  // der VP8-Chunk
$x   = 'VP8X' . pack('V', 10) . chr(0x08) . "\0\0\0" . substr(pack('V', 299), 0, 3) . substr(pack('V', 199), 0, 3);
$exc = 'EXIF' . pack('V', 11) . 'SECRET-WEBP' . "\0";
$body = $x . $vp8 . $exc;
$f = tmpImg('RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body, 'webp');
ok('WebP wird bereinigt', _pesi_prepare_image($f, 'image/webp', 2560));
$out = (string)file_get_contents($f);
ok('WebP: kein SECRET, EXIF-Flag gelöscht',
    strpos($out, 'SECRET') === false && (ord($out[20]) & 0x08) === 0);
ok('WebP: RIFF-Länge stimmt', unpack('V', $out, 4)[1] === strlen($out) - 8);
if ($hasGd && function_exists('imagecreatefromwebp')) ok('WebP: lesbar', (bool)@imagecreatefromstring($out));

// Ablehnen statt ungeprüft veröffentlichen
$f = tmpImg(substr(dirtyJpeg($FX['jpg'], 6), 0, 40));
ok('abgeschnittenes JPEG wird abgelehnt', _pesi_prepare_image($f, 'image/jpeg', 2560) === false);
$f = tmpImg(substr($FX['jpg'], 0, (int)(strlen($FX['jpg']) * .6)));
ok('JPEG ohne Bildende wird abgelehnt', _pesi_prepare_image($f, 'image/jpeg', 2560) === false);
$f = tmpImg(substr($FX['big'], 0, 2000));
ok('abgeschnittenes großes JPEG wird abgelehnt, nicht von gd aufgefüllt',
    _pesi_prepare_image($f, 'image/jpeg', 300) === false);
$f = tmpImg('keinbild', 'png');
ok('kaputtes PNG wird abgelehnt', _pesi_prepare_image($f, 'image/png', 2560) === false);

// GIF: bleibt Byte für Byte
$f = tmpImg($FX['gif'], 'gif');
ok('GIF bleibt unverändert', _pesi_prepare_image($f, 'image/gif', 10) && file_get_contents($f) === $FX['gif']);

if ($hasGd) {
    // Zu wenig Speicher: unverkleinert, aber bereinigt, kein Fatal Error
    $oldLimit = ini_get('memory_limit');
    ini_set('memory_limit', (string)(memory_get_usage() + 8 * 1048576));
    $f = tmpImg(dirtyJpeg(enc(quad(3000, 3000), 'imagejpeg'), 6));
    $okp = _pesi_prepare_image($f, 'image/jpeg', 300);
    ini_set('memory_limit', $oldLimit);
    $out = (string)file_get_contents($f);
    ok('knapper Speicher: bleibt groß, aber ohne Metadaten',
        $okp && getimagesize($f)[0] === 3000 && strpos($out, 'SECRET') === false);
}

// ── pesi() hat genau vier Argumente ──────────────────────────
grp('Signatur — kein fünftes Argument');
$c = _pesi_scan("<?php\npesi('t', 'Hallo', 'text', 'Titel', ['hint' => 'x']);\n")['calls'];
ok('fünftes Argument → Feld nicht lesbar (T13)', !$c || $c[0]['error'] !== '');

// ── Bildbeschreibung: <bild-id>_alt ──────────────────────────
grp('Bildbeschreibung — Zuordnung');
$fx = [
    'portrait'     => ['id' => 'portrait', 'value' => '/uploads/a.jpg', 'type' => 'image', 'label' => 'Porträt'],
    'portrait_alt' => ['id' => 'portrait_alt', 'value' => 'Anna Muster', 'type' => 'text', 'label' => 'Bildbeschreibung'],
    'logo'         => ['id' => 'logo', 'value' => '/uploads/l.png', 'type' => 'image', 'label' => 'Logo'],
    'titel_alt'    => ['id' => 'titel_alt', 'value' => 'x', 'type' => 'text', 'label' => ''],
    'hero'         => ['id' => 'hero', 'value' => '/uploads/h.jpg', 'type' => 'image', 'label' => 'Hero'],
    'hero_alt'     => ['id' => 'hero_alt', 'value' => 'x', 'type' => 'textarea', 'label' => ''],
];
ok('Bild mit _alt-Textfeld wird gepaart, sonst nichts', _pesi_alt_pairs($fx) === ['portrait' => 'portrait_alt'], json_encode(_pesi_alt_pairs($fx)));

grp('Bildbeschreibung — Erinnerung nach Bildtausch');
ok('neues Bild, gleiche Beschreibung → erinnern',
    _pesi_alt_unchecked($fx, ['pesi_field_portrait' => '/uploads/b.jpg', 'pesi_field_portrait_alt' => 'Anna Muster']) === ['Porträt']);
ok('neues Bild, neue Beschreibung → nichts',
    _pesi_alt_unchecked($fx, ['pesi_field_portrait' => '/uploads/b.jpg', 'pesi_field_portrait_alt' => 'Anna Muster im Garten']) === []);
ok('gleiches Bild → nichts', _pesi_alt_unchecked($fx, ['pesi_field_portrait' => '/uploads/a.jpg', 'pesi_field_portrait_alt' => 'Anna Muster']) === []);
ok('Beschreibung nicht mitgesendet zählt als unverändert',
    _pesi_alt_unchecked($fx, ['pesi_field_portrait' => '/uploads/b.jpg']) === ['Porträt']);

grp('Bildbeschreibung — Einträge duplizieren');
$ap = page("<?php ?>\n<!-- pesi:item team:1 -->\n<img src=\"<?= pesi('team_1_foto', '/uploads/a.jpg', 'image', 'Foto') ?>\" alt=\"<?= pesi('team_1_foto_alt', 'Anna', 'text', 'Bildbeschreibung') ?>\">\n<!-- /pesi:item -->\n");
$r = _pesi_block_op($ap, 'team', 1, 'dup');
$pairs = _pesi_alt_pairs(_pesi_parse($ap));
ok('Duplikat bringt seine eigene Beschreibung mit', $r['type'] === 'success' && ($pairs['team_2_foto'] ?? '') === 'team_2_foto_alt', json_encode($pairs) . ' ' . $r['msg']);
file_put_contents($scratch . '/alt-page.php', "<?php require '" . $scratch . "/core.php'; ?><img alt=\"<?= pesi('p_alt', 'Anna \"Anni\" <Muster>', 'text', 'B') ?>\">");
ok('Beschreibung ist im alt-Attribut sicher escaped',
    render($scratch . '/alt-page.php') === '<img alt="Anna &quot;Anni&quot; &lt;Muster&gt;">', render($scratch . '/alt-page.php'));

// ── Vorhandenes Bild wiederverwenden ─────────────────────────
grp('Bildauswahl — Upload-Ordner');
$ls = $scratch . '/libsite';
@mkdir($ls . '/uploads/sub', 0777, true);
$mk = function (string $n, int $t) use ($ls) { file_put_contents("$ls/uploads/$n", 'x'); touch("$ls/uploads/$n", $t); };
$mk('alt.jpg', 1000); $mk('neu.png', 3000); $mk('mitte.webp', 2000); $mk('GROSS.JPG', 1500);
$mk('logo.svg', 4000); $mk('shell.php', 4000); $mk('.versteckt.jpg', 4000); $mk('notiz.txt', 4000);
$mk("anf'uehrung.jpg", 4000); $mk('sub/tief.jpg', 4000);
@symlink("$ls/uploads/alt.jpg", "$ls/uploads/link.jpg");
$list = _pesi_upload_list($ls);
$names = array_column($list, 'name');
ok('nur erlaubte Bilder, neueste zuerst', $names === ['neu.png', 'mitte.webp', 'GROSS.JPG', 'alt.jpg'], json_encode($names));
ok('Pfade sind Web-Pfade im Upload-Ordner', ($list[0]['path'] ?? '') === '/uploads/neu.png');
ok('Obergrenze greift', count(_pesi_upload_list($ls, 2)) === 2);
ok('fehlender Ordner → leere Liste', _pesi_upload_list($scratch . '/gibtsnicht') === []);

// ── Versionsliste: N Generationen, Wiederherstellen, Diff ────
grp('Versionen — Rotation über PESI_BACKUP_COUNT');
$vp = page("<?php\n\$x = pesi('titel', 'v0', 'text', 'Titel');\n");
$gen = fn(int $n) => (string)@file_get_contents(_pesi_backup_path($vp, $n));
ok('PESI_BACKUP_COUNT ist 5', _pesi_backup_count() === 5);
for ($i = 1; $i <= 7; $i++) {
    $r = _pesi_commit($vp, "<?php\n\$x = pesi('titel', 'v$i', 'text', 'Titel');\n");
    if ($r !== null) break;
}
ok('7 Speichervorgänge ohne Fehler', $r === null, json_encode($r, JSON_UNESCAPED_UNICODE));
ok('live ist v7', strpos((string)file_get_contents($vp), "'v7'") !== false);
$order = [];
foreach (_pesi_backup_files($vp) as $n => $p) $order[] = $n . '=' . (preg_match("/'(v\d)'/", $gen($n), $m) ? $m[1] : '?');
ok('Generationen 1–5 = v6 … v2, jüngste zuerst', implode(' ', $order) === '1=v6 2=v5 3=v4 4=v3 5=v2', implode(' ', $order));
ok('keine Zwischendateien übrig', !glob($vp . '*pesi-tmp*'), implode(', ', glob($vp . '*pesi-tmp*') ?: []));

// Die Sicherung trägt das Datum, seit dem ihr Stand galt, nicht die Kopierzeit
touch($vp, 1700000000);
clearstatcache();
_pesi_commit($vp, "<?php\n\$x = pesi('titel', 'v8', 'text', 'Titel');\n");
clearstatcache();
ok('Sicherung 1 behält das Datum der Live-Datei', filemtime(_pesi_backup_path($vp, 1)) === 1700000000,
    (string)filemtime(_pesi_backup_path($vp, 1)));
ok('ältere Generation behält ihr Datum beim Weiterrücken', filemtime(_pesi_backup_path($vp, 2)) !== 1700000000);

// Rücknahme stellt die ganze Historie exakt wieder her
$before = [];
foreach (_pesi_backup_files($vp) as $n => $p) $before[$n] = hash_file('sha256', $p);
$st = _pesi_backup_begin($vp);
ok('Rotation begonnen', is_array($st));
_pesi_backup_rollback($st);
clearstatcache();
$after = [];
foreach (_pesi_backup_files($vp) as $n => $p) $after[$n] = hash_file('sha256', $p);
ok('Rollback: alle Generationen wie vorher', $before === $after);
ok('Rollback: keine Zwischendateien', !glob($vp . '*pesi-tmp*'));

// Nach einer Verkleinerung von PESI_BACKUP_COUNT liegen alte Generationen
// jenseits der Anzahl. Die nächste Rotation räumt sie weg.
foreach ([6, 7, 9] as $n) file_put_contents(_pesi_backup_path($vp, $n), "<?php\n\$x = pesi('bild', '/uploads/alt$n.jpg', 'image', 'Bild');\n");
file_put_contents(_pesi_backup_path($vp, 5), "<?php\n\$x = pesi('bild', '/uploads/gen5.jpg', 'image', 'Bild');\n");
$exp = _pesi_expiring_images($vp);
sort($exp);
ok('herausfallende Bilder: Generation 5 und alles dahinter',
    $exp === ['/uploads/alt6.jpg', '/uploads/alt7.jpg', '/uploads/alt9.jpg', '/uploads/gen5.jpg'], json_encode($exp));
_pesi_commit($vp, "<?php\n\$x = pesi('titel', 'v9', 'text', 'Titel');\n");
ok('Generationen jenseits der Anzahl sind weg', array_keys(_pesi_backup_files($vp)) === [1, 2, 3, 4, 5],
    json_encode(array_keys(_pesi_backup_files($vp))));

grp('Versionen — Bild-Aufräumen sieht alle Generationen');
$vs = $scratch . '/vsite';
@mkdir($vs . '/uploads', 0777, true);
foreach (['live', 'gen4', 'weg'] as $b) file_put_contents("$vs/uploads/$b.jpg", 'x');
file_put_contents("$vs/index.php", "<?php\n\$x = pesi('bild', '/uploads/live.jpg', 'image', 'Bild');\n");
file_put_contents(_pesi_backup_path("$vs/index.php", 4), "<?php\n\$x = pesi('bild', '/uploads/gen4.jpg', 'image', 'Bild');\n");
_pesi_cleanup_old($vs, ['/uploads/gen4.jpg', '/uploads/weg.jpg'], ['index.php' => 'Start']);
ok('Bild, das nur Sicherung 4 noch nutzt, bleibt', is_file("$vs/uploads/gen4.jpg"));
ok('Bild, das kein Stand mehr nutzt, ist gelöscht', !is_file("$vs/uploads/weg.jpg"));

grp('Versionen — Wiederherstellen');
$vp = page("<?php\n\$x = pesi('titel', 'A', 'text', 'Titel');\n");
foreach (['B', 'C', 'D'] as $v) _pesi_commit($vp, "<?php\n\$x = pesi('titel', '$v', 'text', 'Titel');\n");
// live D, 1=C, 2=B, 3=A
$h3 = hash('sha256', $gen(3));
$live = hash_file('sha256', $vp);
$r = _pesi_restore($vp, 3, str_repeat('0', 64), $live);
ok('falscher Versions-Hash wird abgelehnt', $r['msg'] === $GLOBALS['t']['err_stale'], $r['msg']);
ok('dabei bleibt die Seite unverändert', hash_file('sha256', $vp) === $live);
$r = _pesi_restore($vp, 9, $h3, $live);
ok('nicht vorhandene Generation → rst_none', $r['msg'] === $GLOBALS['t']['rst_none'], $r['msg']);
$r = _pesi_restore($vp, 3, $h3, str_repeat('f', 64));
ok('veraltete Seite (Live-Hash) wird abgelehnt', $r['msg'] === $GLOBALS['t']['err_stale'], $r['msg']);
$r = _pesi_restore($vp, 3, $h3, $live);
ok('Generation 3 wiederhergestellt', $r['type'] === 'success', $r['msg']);
ok('live ist jetzt A', strpos((string)file_get_contents($vp), "'A'") !== false);
ok('der Stand davor (D) ist jetzt Generation 1', strpos($gen(1), "'D'") !== false);
ok('A bleibt zusätzlich in der Historie', strpos($gen(4), "'A'") !== false, $gen(4));
$r = _pesi_restore($vp, 4, hash('sha256', $gen(4)), hash_file('sha256', $vp));
ok('Wiederherstellen des gleichen Stands → rst_same', $r['msg'] === $GLOBALS['t']['rst_same'], $r['msg']);

grp('Versionen — Unterschiede');
$now  = ['a' => ['id' => 'a', 'value' => 'neu', 'type' => 'text', 'label' => 'Titel'],
         'b' => ['id' => 'b', 'value' => 'gleich', 'type' => 'text', 'label' => 'B'],
         'n' => ['id' => 'n', 'value' => 'x', 'type' => 'text', 'label' => '']];
$then = ['a' => ['id' => 'a', 'value' => 'alt', 'type' => 'text', 'label' => 'Titel'],
         'b' => ['id' => 'b', 'value' => 'gleich', 'type' => 'text', 'label' => 'B'],
         'g' => ['id' => 'g', 'value' => 'weg', 'type' => 'text', 'label' => 'Gelöscht']];
$d = _pesi_version_diff($now, $then);
ok('Diff: geändert, neu, entfernt; gleiches fehlt',
    $d === [['Titel', 'text', 'alt', 'neu'], [_pesi_human('n'), 'text', null, 'x'], ['Gelöscht', 'text', 'weg', null]],
    json_encode($d, JSON_UNESCAPED_UNICODE));
ok('Text: nicht vorhanden', _pesi_version_text(null, 'text') === $GLOBALS['t']['vh_absent']);
ok('Text: leer', _pesi_version_text("  \n", 'text') === $GLOBALS['t']['vh_empty']);
ok('Text: Bild zeigt den Dateinamen', _pesi_version_text('/uploads/foto-abc.jpg', 'image') === 'foto-abc.jpg');
ok('Text: Richtext ohne Tags, Absätze getrennt',
    _pesi_version_text('<p>Eins &amp; <b>zwei</b></p><p>drei</p>', 'richtext') === 'Eins & zwei drei',
    _pesi_version_text('<p>Eins &amp; <b>zwei</b></p><p>drei</p>', 'richtext'));
$long = str_repeat('ä', 100);
$cut  = _pesi_version_text($long, 'text');
ok('Text: nach 80 Zeichen gekürzt, UTF-8 bleibt ganz', $cut === str_repeat('ä', 80) . '…' && preg_match('//u', $cut) === 1, $cut);

// ── Release: Einstellungen und Code getrennt ─────────────────
grp('Release — pesi-core.php ist nur Einstellung');
$coreSrc = (string)file_get_contents($root . '/pesi-core.php');
$fnTokens = array_filter(token_get_all($coreSrc), fn($tk) => is_array($tk) && $tk[0] === T_FUNCTION);
ok('pesi-core.php definiert keine Funktionen', !$fnTokens);
ok('pesi-core.php lädt pesi-lib.php am Ende',
    (bool)preg_match("#require_once __DIR__ \. '/pesi-lib\.php';\s*$#", $coreSrc));
preg_match("/\\\$pesiVersion = '([^']+)'/", $src, $vm);
ok('pesi.php und pesi-lib.php tragen dieselbe Version', ($vm[1] ?? '') === PESI_VERSION,
    ($vm[1] ?? '?') . ' / ' . PESI_VERSION);

// Minimale pesi-core.php: nur das Passwort. Alles andere kommt aus den
// Standardwerten, damit neue Einstellungen kein Update der Konfiguration brauchen.
$mini = $scratch . '/mini';
@mkdir($mini);
copy($root . '/pesi-lib.php', $mini . '/pesi-lib.php');
file_put_contents($mini . '/pesi-core.php',
    "<?php\ndefine('PESI_PASSWORD', 'x');\nrequire_once __DIR__ . '/pesi-lib.php';\n");
$probe = 'require "' . $mini . '/pesi-core.php"; $m = [];'
       . ' foreach (["BRAND_NAME","BRAND_COLOR","BRAND_LOGO","LANG","PESI_BACKUP_ENABLED","PESI_BACKUP_COUNT","PESI_PASSWORD_CHANGE","PESI_SYNTAX_CHECK",'
       . '"PESI_SESSION_IDLE","PESI_SESSION_MAX","PESI_GLOBALS_FILE","PESI_UPLOAD_DIR","PESI_UPLOAD_MAX_BYTES",'
       . '"PESI_UPLOAD_TYPES","PESI_IMAGE_MAX_EDGE"] as $c) if (!defined($c)) $m[] = $c;'
       . ' echo implode(",", $m), "|", pesi("x", "<b>", "text"), "|", isset($pesiKey) ? "leak" : "clean";';
$res = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>&1');
ok('fehlende Einstellungen bekommen Standardwerte', strpos($res, '|') === 0, $res);
ok('pesi() funktioniert mit minimaler Konfiguration', strpos($res, '|&lt;b&gt;|') !== false, $res);
ok('Schleifenvariablen landen nicht im Seiten-Scope', substr($res, -5) === 'clean', $res);
file_put_contents($mini . '/pesi-core.php',
    "<?php\ndefine('BRAND_NAME', 'Eigene');\nrequire_once __DIR__ . '/pesi-lib.php';\n");
$res = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . $mini . '/pesi-core.php"; echo BRAND_NAME, "|", defined("PESI_PASSWORD") ? "pw" : "nopw";') . ' 2>&1');
ok('eigene Werte haben Vorrang vor den Standardwerten', strpos($res, 'Eigene|') === 0, $res);
ok('PESI_PASSWORD hat keinen Standardwert', substr($res, -4) === 'nopw', $res);

// ── Passwort im Dashboard ändern ─────────────────────────────
grp('Passwort — Prüfen und Speichern');
$pwf = _pesi_password_file();
@unlink($pwf);
ok('Datei liegt neben pesi.php und fällt unter die .pesi--Regel', basename($pwf) === '.pesi-password');
ok('ohne Datei gilt pesi-core.php', _pesi_password_override() === null);
ok('Klartext aus pesi-core.php', _pesi_password_verify('geheim', 'geheim') && !_pesi_password_verify('falsch', 'geheim'));
ok('Hash', _pesi_password_verify('geheim', password_hash('geheim', PASSWORD_DEFAULT)));
ok('leerer gespeicherter Wert lässt nichts durch', !_pesi_password_verify('', ''));
$chk = fn($c, $n, $r) => _pesi_password_check($c, $n, $r, 'alt-passwort-123');
ok('falsches aktuelles Passwort', $chk('x', 'neues-passwort', 'neues-passwort') === 'pw_err_current');
ok('falsches aktuelles hat Vorrang vor allen anderen Regeln', $chk('x', 'kurz', 'anders') === 'pw_err_current');
ok('Wiederholung stimmt nicht', $chk('alt-passwort-123', 'neues-passwort', 'neues-passwortt') === 'pw_err_repeat');
ok('zu kurz', $chk('alt-passwort-123', 'kurz', 'kurz') === 'pw_err_short');
ok('10 Zeichen zählen Umlaute einzeln', $chk('alt-passwort-123', 'ääääääääää', 'ääääääääää') === '');
ok('nur Leerzeichen gilt nicht', $chk('alt-passwort-123', str_repeat(' ', 12), str_repeat(' ', 12)) === 'pw_err_short');
ok('gleich wie bisher', $chk('alt-passwort-123', 'alt-passwort-123', 'alt-passwort-123') === 'pw_err_same');
ok('gültiger Wechsel', $chk('alt-passwort-123', 'Sonne über dem See', 'Sonne über dem See') === '');

$h = _pesi_password_write('Sonne über dem See');
ok('Hash geschrieben und zurückgelesen', is_string($h) && _pesi_password_override() === $h);
ok('Datei enthält keinen Klartext', strpos((string)file_get_contents($pwf), 'Sonne') === false);
ok('neues Passwort passt zum Hash', _pesi_password_verify('Sonne über dem See', (string)$h));
ok('keine Temp-Datei übrig', !glob($pwf . '-tmp-*'));
ok('Datei nur für den Besitzer lesbar', (fileperms($pwf) & 0077) === 0, decoct(fileperms($pwf) & 0777));
file_put_contents($pwf, "kaputt\n");
ok('beschädigte Datei sperrt, statt auf pesi-core.php zurückzufallen', _pesi_password_override() === '');
file_put_contents($pwf, "  " . $h . "\n");
ok('Leerraum um den Hash wird toleriert', _pesi_password_override() === $h);
@unlink($pwf);

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
          'warn_no_exec', 'warn_upload_limit', 'login_unavailable',
          'warn_no_gd', 'pw_err_write', 'pw_err_file'] as $k) {
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
copy($root . '/pesi-lib.php', $e2e . '/pesi-lib.php');
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
copy($root . '/pesi-lib.php', $ctl . '/site/pesi-lib.php');
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
