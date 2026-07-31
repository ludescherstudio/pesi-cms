<?php
/**
 * pesi CMS — Regressionstests für die reinen Engine-Funktionen.
 *
 *   php dev/test-engine.php
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

$root    = dirname(__DIR__);
$scratch = sys_get_temp_dir() . '/pesi-test-' . getmypid();
@mkdir($scratch, 0777, true);

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

// Rollback greift bei echtem Syntaxfehler, aber nicht bei fehlendem Linter
$p = page("<?php\n\$x = pesi('f', 'GUT', 'text', 'L');\n");
_pesi_backup($p);
$r = _pesi_commit($p, "<?php\n\$x = ;;; kaputt\n");
ok('Rollback bei echtem Syntaxfehler', $r !== null && $r['type'] === 'error');
ok('Datei nach Rollback wieder gültig', _pesi_lint($p) === true);
ok('Inhalt nach Rollback wiederhergestellt', strpos(file_get_contents($p), 'GUT') !== false);

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
    'fopen-Modus muss c+ sein, nicht c — c ist write-only und der Hash-Vergleich '
    . 'liest dann immer den leeren String');
ok('Wert wurde geschrieben', (_pesi_parse($p)['f']['value'] ?? null) === 'B');
$stale = _pesi_commit($p, "<?php\n\$x = pesi('f', 'C', 'text', 'L');\n", hash('sha256', 'ganz andere Datei'));
ok('falscher Hash → abgelehnt', $stale !== null && $stale['type'] === 'error');
ok('Datei nach Ablehnung unverändert', (_pesi_parse($p)['f']['value'] ?? null) === 'B');

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
          'err_file_missing', 'err_marker', 'up_err_dir', 'up_err_dir_invalid',
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

// ── Aufräumen ────────────────────────────────────────────────
foreach (['/site/uploads/*', '/site/*', '/*'] as $g) {
    foreach (glob($scratch . $g) as $f) { if (is_file($f)) @unlink($f); }
}
@rmdir($scratch . '/site/uploads');
@rmdir($scratch . '/site');
@rmdir($scratch);

echo "\n" . str_repeat('─', 46) . "\n";
echo ($fail === 0 ? "ALLE TESTS BESTANDEN" : "FEHLGESCHLAGEN") . " — $pass ok, $fail Fehler\n";
exit($fail === 0 ? 0 : 1);
