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
 * Sanitizer, Struktur-Marker in Feldwerten). Wer Saver, Sanitizer oder die
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

// ── Brute-Force-Bremse ───────────────────────────────────────
grp('Login-Bremse');
$thr = _pesi_throttle_file();   // liegt neben der Engine, also im Scratch
@unlink($thr);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
ok('frischer Client ist nicht gesperrt', _pesi_throttle_check() === 0);
_pesi_throttle_fail();
$w1 = _pesi_throttle_check();
ok('nach einem Fehlversuch gesperrt', $w1 > 0, "wait=$w1");
_pesi_throttle_fail();
_pesi_throttle_fail();
$w3 = _pesi_throttle_check();
ok('Sperre wächst exponentiell', $w3 > $w1, "1.: $w1 s, 3.: $w3 s");
ok('Sperre ist gedeckelt', $w3 <= 300, "wait=$w3");

$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
ok('andere IP bleibt frei', _pesi_throttle_check() === 0);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
ok('erste IP weiterhin gesperrt', _pesi_throttle_check() > 0);
_pesi_throttle_reset();
ok('erfolgreicher Login löscht den Zähler', _pesi_throttle_check() === 0);

$_SESSION = [];
ok('frische Session ist nicht gesperrt', _pesi_session_throttle_check() === 0);
_pesi_session_throttle_fail();
ok('Session-Bremse greift auch ohne Registerdatei', _pesi_session_throttle_check() > 0);
ok('Session-Bremse zählt Fehlversuche', ($_SESSION['pesi_fail'] ?? 0) === 1);
_pesi_session_throttle_reset();
ok('erfolgreicher Login setzt auch Session-Bremse zurück', _pesi_session_throttle_check() === 0);

$raw = (string)file_get_contents($thr);
ok('Register enthält keine Klartext-IP', strpos($raw, '203.0.113.7') === false, $raw);
ok('Register ist gültiges JSON', is_array(json_decode($raw, true)), $raw);
@unlink($thr);

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

// ── Eine Anfrage, eine Aktion ────────────────────────────────
// Die Block-/Toggle-/Wiederherstellen-Buttons liegen im selben Formular wie
// die Felder, und 'pesi_save' ist ein verstecktes Feld — es kommt also bei
// jedem Absenden mit. Ohne die Unterscheidung lief nach jeder Strukturaktion
// zusätzlich der Save-Handler und überschrieb die Erfolgsmeldung mit
// „Seite wurde zwischenzeitlich geändert" (live gefunden, nicht im Code).
grp('POST — Struktur- vs. Speicheraktion');
if (!preg_match('/\$structural = isset\(.*?\);/s', $src, $stm)) {
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
          'blk_notfound', 'tgl_notfound', 'tgl_nested', 'warn_default_pw',
          'warn_no_exec'] as $k) {
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
