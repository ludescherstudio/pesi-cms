<?php
/**
 * pesi CMS v0.3 — Admin Dashboard
 * URL: domain.at/pesi
 */

// ── Schutz-Header (nur fürs Dashboard, nicht für die Kundenseite) ──
// Das Dashboard trägt destruktive Ein-Klick-Aktionen (Eintrag löschen,
// Wiederherstellen). CSRF-Token schützen davor nicht: beim Clickjacking klickt
// das Opfer den echten Button in einem unsichtbaren Frame, das Token wird
// gültig mitgeschickt. 'frame-ancestors' ist die moderne Form, X-Frame-Options
// die für ältere Browser — beide kosten nichts und brechen die Vorschau nicht,
// denn die rahmt die Seite ein, nicht umgekehrt.
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

// ── Session: sichere Cookie-Flags ────────────────────────────
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? 0) == 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// CSRF-Token (persistent pro Session)
if (empty($_SESSION['pesi_csrf'])) {
    $_SESSION['pesi_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['pesi_csrf'];
$csrfOk = isset($_POST['pesi_csrf']) && hash_equals($csrf, (string)$_POST['pesi_csrf']);

$corePath = __DIR__ . '/pesi-core.php';
if (!file_exists($corePath)) {
    die('pesi-core.php nicht gefunden. Bitte im Root-Verzeichnis anlegen.');
}
require_once $corePath;

// i18n früh initialisieren — die POST-Verarbeitung unten ruft Funktionen
// auf, die per `global $t` auf diese Übersetzungen zugreifen.
$lang = defined('LANG') ? LANG : 'de';
$t    = _pesi_strings()[$lang] ?? _pesi_strings()['de'];

// Projektspezifische Formulierungen: pesi-core.php darf einzelne Texte per
// $PESI_STRINGS überschreiben. Damit lassen sich Anrede, Tonfall und
// Fachbegriffe an die jeweilige Kundschaft anpassen, ohne pesi.php zu forken —
// ein Update bleibt so ein reiner Dateitausch. Unbekannte Keys werden
// ignoriert, damit ein Tippfehler nicht stillschweigend Text verschluckt.
if (isset($PESI_STRINGS) && is_array($PESI_STRINGS)) {
    foreach ($PESI_STRINGS as $k => $v) {
        if (is_string($v) && array_key_exists($k, $t)) $t[$k] = $v;
    }
}

$basePath = realpath(__DIR__);
$sk = 'pesi_auth';

// Eigener Pfad für Redirects (z. B. /pesi oder /cms). Ein relatives
// 'Location: ./' wäre bei extensionsloser URL falsch: relativ zu '/pesi'
// (ohne Slash) löst './' zu '/' auf → Redirect auf die Mutterseite.
$selfUrl = strtok($_SERVER['REQUEST_URI'] ?? '/pesi', '?');
// Muss mit genau EINEM Slash beginnen. '//host' und '/\host' liest der Browser
// als protokollrelative URL — ein 'Location:' darauf wäre ein Open Redirect auf
// eine fremde Domain. Steuerzeichen fliegen gleich mit raus.
if (!is_string($selfUrl) || $selfUrl === '' || $selfUrl[0] !== '/'
    || (isset($selfUrl[1]) && ($selfUrl[1] === '/' || $selfUrl[1] === '\\'))
    || preg_match('/[\x00-\x1F\x7F]/', $selfUrl)) {
    $selfUrl = '/pesi';
}

// pesi Logo (dunkler Hintergrund) — "pesi" in hellgrau, "cms" in Markenfarbe #c47a2a
$PESI_LOGO_B64 = 'PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyBpZD0iRWJlbmVfMiIgZGF0YS1uYW1lPSJFYmVuZSAyIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNDIuNDYgNTYuNDYiPjxkZWZzPjxzdHlsZT4uY2xzLTF7ZmlsbDojZjNmM2YzfS5jbHMtMntmaWxsOiNjNDdhMmF9PC9zdHlsZT48L2RlZnM+PGcgaWQ9IkViZW5lXzEtMiIgZGF0YS1uYW1lPSJFYmVuZSAxIj48Zz48cGF0aCBjbGFzcz0iY2xzLTEiIGQ9Ik0wLDEyLjEyaDUuNDZ2NC41YzEuMjgtMS42NCwyLjg5LTIuOTIsNC44My0zLjg0LDEuOTQtLjkyLDQuMTEtMS4zOCw2LjUxLTEuMzgsMywwLDUuNzIuNzQsOC4xNiwyLjIyLDIuNDQsMS40OCw0LjM2LDMuNTEsNS43Niw2LjA5LDEuNCwyLjU4LDIuMSw1LjQ1LDIuMSw4LjYxcy0uNyw2LjAyLTIuMSw4LjU4Yy0xLjQsMi41Ni0zLjMyLDQuNTgtNS43Niw2LjA2LTIuNDQsMS40OC01LjE4LDIuMjItOC4yMiwyLjIyLTIuMzIsMC00LjQ1LS40NS02LjM5LTEuMzVzLTMuNTMtMi4xOS00Ljc3LTMuODd2MTYuNUgwVjEyLjEyWk02Ljk5LDM0LjE3Yy45NCwxLjc0LDIuMjMsMy4xMSwzLjg3LDQuMTEsMS42NCwxLDMuNDYsMS41LDUuNDYsMS41czMuODEtLjUsNS40My0xLjVjMS42Mi0xLDIuODgtMi4zNywzLjc4LTQuMTEuOS0xLjc0LDEuMzUtMy42OSwxLjM1LTUuODVzLS40Ni00LjEyLTEuMzgtNS44OGMtLjkyLTEuNzYtMi4xOC0zLjE0LTMuNzgtNC4xNC0xLjYtMS0zLjQtMS41LTUuNC0xLjVzLTMuODIuNS01LjQ2LDEuNWMtMS42NCwxLTIuOTMsMi4zOC0zLjg3LDQuMTQtLjk0LDEuNzYtMS40MSwzLjcyLTEuNDEsNS44OHMuNDcsNC4xMSwxLjQxLDUuODVaIi8+PHBhdGggY2xhc3M9ImNscy0xIiBkPSJNNDYuMTQsNDIuOTZjLTIuNDgtMS40OC00LjQyLTMuNTEtNS44Mi02LjA5LTEuNC0yLjU4LTIuMS01LjQ3LTIuMS04LjY3cy42OS02LjA4LDIuMDctOC42NGMxLjM4LTIuNTYsMy4yNi00LjU2LDUuNjQtNiwyLjM4LTEuNDQsNS4wNS0yLjE2LDguMDEtMi4xNnM1Ljc3LjcyLDguMDcsMi4xNmMyLjMsMS40NCw0LjA2LDMuMzUsNS4yOCw1LjczLDEuMjIsMi4zOCwxLjgzLDQuOTMsMS44Myw3LjY1LDAsLjkyLS4xLDEuOTItLjMsM2gtMjQuNzJjLjA4LDIuMDQuNiwzLjgyLDEuNTYsNS4zNC45NiwxLjUyLDIuMjEsMi43LDMuNzUsMy41NCwxLjU0Ljg0LDMuMjMsMS4yNiw1LjA3LDEuMjYsMy45MiwwLDYuOTQtMS43OCw5LjA2LTUuMzRsNC42OCwyLjRjLTEuMDQsMi4zMi0yLjgsNC4yNC01LjI4LDUuNzYtMi40OCwxLjUyLTUuMzIsMi4yOC04LjUyLDIuMjhzLTUuOC0uNzQtOC4yOC0yLjIyWk02My4wNiwyNS4xNGMtLjA4LTIuNTItLjk5LTQuNjEtMi43My02LjI3LTEuNzQtMS42Ni0zLjg3LTIuNDktNi4zOS0yLjQ5cy00LjYyLjc5LTYuNDIsMi4zN2MtMS44LDEuNTgtMi45LDMuNzEtMy4zLDYuMzloMTguODRaIi8+PHBhdGggY2xhc3M9ImNscy0xIiBkPSJNNzkuMDgsNDIuODdjLTIuNC0xLjU0LTQuMTItMy42My01LjE2LTYuMjdsNC41LTIuMjhjLjkyLDEuODgsMi4xOCwzLjM2LDMuNzgsNC40NCwxLjYsMS4wOCwzLjM0LDEuNjIsNS4yMiwxLjYyczMuMjgtLjQyLDQuNDQtMS4yNmMxLjE2LS44NCwxLjc0LTEuOTQsMS43NC0zLjNzLS41Mi0yLjM5LTEuNTYtMy4yMWMtMS4wNC0uODItMi4xNi0xLjMxLTMuMzYtMS40N2wtNC44Ni0uNjZjLTIuODgtLjcyLTUuMDQtMS45Mi02LjQ4LTMuNi0xLjQ0LTEuNjgtMi4xNi0zLjY2LTIuMTYtNS45NCwwLTEuODguNDktMy41NCwxLjQ3LTQuOTguOTgtMS40NCwyLjM0LTIuNTYsNC4wOC0zLjM2LDEuNzQtLjgsMy42Ny0xLjIsNS43OS0xLjIsMi44LDAsNS4zMS43LDcuNTMsMi4xLDIuMjIsMS40LDMuODEsMy4zNCw0Ljc3LDUuODJsLTQuNDQsMi4yOGMtLjgtMS42NC0xLjkxLTIuOTQtMy4zMy0zLjktMS40Mi0uOTYtMi45OS0xLjQ0LTQuNzEtMS40NHMtMi45Ni40LTMuOTYsMS4yYy0xLC44LTEuNSwxLjg0LTEuNSwzLjEycy40NywyLjM3LDEuNDEsMy4xNWMuOTQuNzgsMS45NywxLjI3LDMuMDksMS40N2w1LjM0LjcyYzIuNjguNzIsNC43NywxLjk1LDYuMjcsMy42OSwxLjUsMS43NCwyLjI1LDMuNzUsMi4yNSw2LjAzLDAsMS44NC0uNSwzLjQ4LTEuNSw0LjkyLTEsMS40NC0yLjQsMi41Ny00LjIsMy4zOS0xLjguODItMy44NiwxLjIzLTYuMTgsMS4yMy0zLjEyLDAtNS44OC0uNzctOC4yOC0yLjMxWiIvPjxwYXRoIGNsYXNzPSJjbHMtMSIgZD0iTTEwNi4xNC45NmMuNjQtLjY0LDEuNDgtLjk2LDIuNTItLjk2czEuODguMzIsMi41Mi45NmMuNjQuNjQuOTYsMS40OC45NiwyLjUycy0uMzIsMS44OC0uOTYsMi41MmMtLjY0LjY0LTEuNDguOTYtMi41Mi45NnMtMS44OC0uMzItMi41Mi0uOTZjLS42NC0uNjQtLjk2LTEuNDgtLjk2LTIuNTJzLjMyLTEuODguOTYtMi41MlpNMTA1Ljg0LDE3LjQ2aDUuNTh2MjdoLTUuNTh2LTI3WiIvPjxwYXRoIGNsYXNzPSJjbHMtMiIgZD0iTTEzNS43Miw0Mi45NmMtMi41Mi0xLjQ4LTQuNDgtMy41MS01Ljg4LTYuMDktMS40LTIuNTgtMi4xLTUuNDUtMi4xLTguNjFzLjctNi4wOCwyLjEtOC42NGMxLjQtMi41NiwzLjM1LTQuNTcsNS44NS02LjAzLDIuNS0xLjQ2LDUuMzUtMi4xOSw4LjU1LTIuMTlzNS45Ny44MSw4LjU1LDIuNDMsNC40MSwzLjcxLDUuNDksNi4yN2wtNS4wNCwyLjRjLS43Ni0xLjcyLTEuOTUtMy4xLTMuNTctNC4xNC0xLjYyLTEuMDQtMy40My0xLjU2LTUuNDMtMS41NnMtMy43NS41LTUuMzcsMS41Yy0xLjYyLDEtMi44OSwyLjM3LTMuODEsNC4xMS0uOTIsMS43NC0xLjM4LDMuNzEtMS4zOCw1Ljkxcy40Nyw0LjExLDEuNDEsNS44NWMuOTQsMS43NCwyLjIxLDMuMTEsMy44MSw0LjExLDEuNiwxLDMuMzgsMS41LDUuMzQsMS41czMuODYtLjUyLDUuNDYtMS41NmMxLjYtMS4wNCwyLjc4LTIuNDYsMy41NC00LjI2bDUuMDQsMi41MmMtMS4wNCwyLjUyLTIuODYsNC42LTUuNDYsNi4yNC0yLjYsMS42NC01LjQ2LDIuNDYtOC41OCwyLjQ2cy02LS43NC04LjUyLTIuMjJaIi8+PHBhdGggY2xhc3M9ImNscy0yIiBkPSJNMTY0LjgyLDEyLjEyaDUuNDZ2NC4wMmMuOTItMS41MiwyLjE3LTIuNjksMy43NS0zLjUxLDEuNTgtLjgyLDMuMzMtMS4yMyw1LjI1LTEuMjMsMi4yLDAsNC4yMS41Myw2LjAzLDEuNTksMS44MiwxLjA2LDMuMjEsMi40OSw0LjE3LDQuMjksMS4wNC0xLjg4LDIuNDUtMy4zMyw0LjIzLTQuMzUsMS43OC0xLjAyLDMuNzUtMS41Myw1LjkxLTEuNTNzNC4yMi41Myw2LjA2LDEuNTljMS44NCwxLjA2LDMuMywyLjUxLDQuMzgsNC4zNSwxLjA4LDEuODQsMS42MiwzLjksMS42Miw2LjE4djIwLjk0aC01LjY0di0xOS4xNGMwLTIuNjQtLjY5LTQuNzItMi4wNy02LjI0LTEuMzgtMS41Mi0zLjE3LTIuMjgtNS4zNy0yLjI4cy00LjA2Ljc3LTUuNDYsMi4zMWMtMS40LDEuNTQtMi4xLDMuNjEtMi4xLDYuMjF2MTkuMTRoLTUuNjR2LTE5LjE0YzAtMi42NC0uNjgtNC43Mi0yLjA0LTYuMjQtMS4zNi0xLjUyLTMuMTYtMi4yOC01LjQtMi4yOHMtNC4wMS43Ny01LjQzLDIuMzFjLTEuNDIsMS41NC0yLjEzLDMuNjEtMi4xMyw2LjIxdjE5LjE0aC01LjU4VjEyLjEyWiIvPjxwYXRoIGNsYXNzPSJjbHMtMiIgZD0iTTIyMi4zLDQyLjg3Yy0yLjQtMS41NC00LjEyLTMuNjMtNS4xNi02LjI3bDQuNS0yLjI4Yy45MiwxLjg4LDIuMTgsMy4zNiwzLjc4LDQuNDQsMS42LDEuMDgsMy4zNCwxLjYyLDUuMjIsMS42MnMzLjI4LS40Miw0LjQ0LTEuMjZjMS4xNi0uODQsMS43NC0xLjk0LDEuNzQtMy4zcy0uNTItMi4zOS0xLjU2LTMuMjFjLTEuMDQtLjgyLTIuMTYtMS4zMS0zLjM2LTEuNDdsLTQuODYtLjY2Yy0yLjg4LS43Mi01LjA0LTEuOTItNi40OC0zLjYtMS40NC0xLjY4LTIuMTYtMy42Ni0yLjE2LTUuOTQsMC0xLjg4LjQ5LTMuNTQsMS40Ny00Ljk4czIuMzQtMi41Niw0LjA4LTMuMzZjMS43NC0uOCwzLjY3LTEuMiw1Ljc5LTEuMiwyLjgsMCw1LjMxLjcsNy41MywyLjEsMi4yMiwxLjQsMy44MSwzLjM0LDQuNzcsNS44MmwtNC40NCwyLjI4Yy0uOC0xLjY0LTEuOTEtMi45NC0zLjMzLTMuOS0xLjQyLS45Ni0yLjk5LTEuNDQtNC43MS0xLjQ0cy0yLjk2LjQtMy45NiwxLjJjLTEsLjgtMS41LDEuODQtMS41LDMuMTJzLjQ3LDIuMzcsMS40MSwzLjE1Yy45NC43OCwxLjk3LDEuMjcsMy4wOSwxLjQ3bDUuMzQuNzJjMi42OC43Miw0Ljc3LDEuOTUsNi4yNywzLjY5LDEuNSwxLjc0LDIuMjUsMy43NSwyLjI1LDYuMDMsMCwxLjg0LS41LDMuNDgtMS41LDQuOTJzLTIuNCwyLjU3LTQuMiwzLjM5Yy0xLjguODItMy44NiwxLjIzLTYuMTgsMS4yMy0zLjEyLDAtNS44OC0uNzctOC4yOC0yLjMxWiIvPjwvZz48L2c+PC9zdmc+';
$PESI_LOGO = 'data:image/svg+xml;base64,' . $PESI_LOGO_B64;

// ── Auth ─────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    unset($_SESSION[$sk]);
    header('Location: ' . $selfUrl);
    exit;
}

$loginError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pesi_login'])) {
    // IP-gebundene Bremse zuerst — greift auch, wenn ein Angreifer die
    // Session-Cookies verwirft (reines Session-Backoff wäre so umgehbar).
    $wait = _pesi_throttle_check();
    if ($wait > 0) {
        sleep(min(3, $wait)); // laufende Anfrage kurz halten, Passwort gar nicht erst prüfen
        $loginError = true;
    } else {
        $pw = (string)($_POST['pesi_password'] ?? '');
        $stored = (string)PESI_PASSWORD;
        // Akzeptiert sowohl Plaintext als auch password_hash() ($2y$, $argon, …)
        $ok = (strlen($stored) > 3 && $stored[0] === '$')
            ? password_verify($pw, $stored)
            : hash_equals($stored, $pw);
        if ($csrfOk && $ok) {
            // Session-Fixation verhindern
            session_regenerate_id(true);
            $_SESSION['pesi_csrf'] = bin2hex(random_bytes(16));
            $_SESSION[$sk] = true;
            // Throttle bei Erfolg zurücksetzen
            _pesi_throttle_reset();
            unset($_SESSION['pesi_fail'], $_SESSION['pesi_fail_until']);
            header('Location: ' . $selfUrl);
            exit;
        }
        // Brute-Force-Bremse: exponentielles Backoff je Session UND je IP
        _pesi_throttle_fail();
        $_SESSION['pesi_fail'] = ($_SESSION['pesi_fail'] ?? 0) + 1;
        $delay = min(8, $_SESSION['pesi_fail']); // 1s, 2s, 3s … max 8s
        sleep($delay);
        $loginError = true;
    }
}

$auth = !empty($_SESSION[$sk]);

// ── Page & Fields ────────────────────────────────────────────
$page = null;
$pageLabel = null;
$fields = [];
$msg = '';
$msgType = '';

if ($auth) {
    if (isset($_GET['page'], $PESI_PAGES[$_GET['page']])) {
        $page = $_GET['page'];
        $pageLabel = $PESI_PAGES[$page];
    }

    if ($page) {
        $fp = $basePath . '/' . $page;
        if (file_exists($fp)) {
            $fields = _pesi_parse($fp);
        } else {
            $msg = $t['err_file_missing'] . $page;
            $msgType = 'error';
        }
    }

    // Block-Operation (Duplizieren / Löschen / Sortieren)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pesi_block']) && $page) {
        if (!$csrfOk) {
            $msg = $t['err_session'];
            $msgType = 'error';
        } elseif (preg_match('/^(dup|add|del|up|down):([a-z0-9_]+)(?::(\d+))?$/', (string)$_POST['pesi_block'], $bm)) {
            $fp = $basePath . '/' . $page;
            $r = _pesi_block_op($fp, $bm[2], (int)($bm[3] ?? 0), $bm[1]);
            $msg = $r['msg'];
            $msgType = $r['type'];
            if ($msgType === 'success') $fields = _pesi_parse($fp);
        }
    }

    // Sichtbarkeit umschalten (pesi:toggle)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pesi_toggle']) && $page) {
        if (!$csrfOk) {
            $msg = $t['err_session'];
            $msgType = 'error';
        } elseif (preg_match('/^[a-z0-9_]+$/', (string)$_POST['pesi_toggle'])) {
            $fp = $basePath . '/' . $page;
            $r = _pesi_toggle_op($fp, (string)$_POST['pesi_toggle']);
            $msg = $r['msg'];
            $msgType = $r['type'];
            if ($msgType === 'success') $fields = _pesi_parse($fp);
        }
    }

    // Letzte Sicherung wiederherstellen
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pesi_restore']) && $page) {
        if (!$csrfOk) {
            $msg = $t['err_session'];
            $msgType = 'error';
        } else {
            $fp = $basePath . '/' . $page;
            $r = _pesi_restore($fp);
            $msg = $r['msg'];
            $msgType = $r['type'];
            if ($msgType === 'success') $fields = _pesi_parse($fp);
        }
    }

    // Save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pesi_save']) && $page) {
        if (!$csrfOk) {
            $msg = $t['err_session'];
            $msgType = 'error';
        } else {
            $fp = $basePath . '/' . $page;
            $postedMtime = isset($_POST['pesi_mtime']) ? (int)$_POST['pesi_mtime'] : 0;
            $currentMtime = is_file($fp) ? (int)filemtime($fp) : 0;
            if ($postedMtime > 0 && $currentMtime > 0 && $postedMtime !== $currentMtime) {
                $msg = $t['err_stale'];
                $msgType = 'error';
            } else {
                $up = _pesi_handle_uploads($fields, $_FILES, $_POST, $basePath);
                $r = _pesi_save($fp, $fields, $up['post']);
                $msg = $r['msg'];
                $msgType = $r['type'];
                if ($msgType === 'success') {
                    if (!empty($up['old'])) _pesi_cleanup_old($basePath, $up['old'], $PESI_PAGES);
                    $fields = _pesi_parse($fp);
                }
                if (!empty($up['errors'])) {
                    $msg = trim(($msgType === 'success' ? $msg . ' ' : '') . implode(' ', $up['errors']));
                    $msgType = 'error';
                }
            }
        }
    }
}

// ── Parser ───────────────────────────────────────────────────
function _pesi_parse(string $file): array {
    $src = file_get_contents($file);
    if (!$src) return [];
    $f = [];

    // Heredoc: pesi('id', <<<'PESI' ... PESI, 'type', 'label')
    preg_match_all(
        '/pesi\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*,\s*<<<[ \t]*\'PESI\'[ \t]*\r?\n(.*?)\r?\nPESI\s*,\s*[\'"]([a-z]+)[\'"](?:\s*,\s*[\'"]([^\'"]*)[\'"]\s*)?\)/s',
        $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    foreach ($m as $x) {
        $id = $x[1][0];
        if (isset($f[$id])) continue;
        $f[$id] = [
            'id'     => $id,
            'value'  => $x[2][0],
            'type'   => $x[3][0],
            'label'  => $x[4][0] ?? '',
            '_pos'   => $x[0][1],
        ];
    }

    // String: pesi('id', 'val', 'type', 'label') — label optional
    preg_match_all(
        '/pesi\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'(?:\s*,\s*[\'"]([a-z]+)[\'"])?(?:\s*,\s*[\'"]([^\'"]*)[\'"]\s*)?\s*\)/s',
        $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    foreach ($m as $x) {
        $id = $x[1][0];
        if (isset($f[$id])) continue;
        $val = str_replace(["\\'", "\\\\"], ["'", "\\"], $x[2][0]);
        $f[$id] = [
            'id'    => $id,
            'value' => $val,
            'type'  => !empty($x[3][0]) ? $x[3][0] : 'text',
            'label' => $x[4][0] ?? '',
            '_pos'  => $x[0][1],
        ];
    }

    // Sortieren nach Byte-Offset → Reihenfolge wie im File
    uasort($f, fn($a, $b) => $a['_pos'] <=> $b['_pos']);
    foreach ($f as &$fld) unset($fld['_pos']);

    return $f;
}

// ── Saver ────────────────────────────────────────────────────

// Backup-Rotation (.pesi-backup.1 → .2). Gemeinsam für Feld-Save und Block-Op.
function _pesi_backup(string $file): bool {
    if (!PESI_BACKUP_ENABLED) return true;
    $b1 = $file . '.pesi-backup.1';
    $b2 = $file . '.pesi-backup.2';
    if (file_exists($b1)) @rename($b1, $b2);
    return copy($file, $b1);
}

// Schreibt $mod atomar und rollt bei PHP-Syntaxfehler aufs Backup zurück.
// Rückgabe: null bei Erfolg, sonst Fehler-Array.
function _pesi_commit(string $file, string $mod, ?string $expectedHash = null): ?array {
    global $t;
    // 'c+' (lesen UND schreiben), nicht 'c': 'c' ist write-only, das
    // stream_get_contents() im Stale-Check unten läse dann immer '' und der
    // Hash-Vergleich schlüge grundsätzlich fehl — jeder Feld-Save endete mit
    // „Seite wurde geändert". Beide Modi legen an und truncaten nicht.
    $fp = fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return ['msg' => $t['err_locked'], 'type' => 'error'];
    }
    if ($expectedHash !== null) {
        rewind($fp);
        $current = stream_get_contents($fp);
        if ($current === false || !hash_equals($expectedHash, hash('sha256', $current))) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['msg' => $t['err_stale'], 'type' => 'error'];
        }
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $mod);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (PESI_SYNTAX_CHECK && _pesi_lint($file) === false) {
        $b1 = $file . '.pesi-backup.1';
        if (PESI_BACKUP_ENABLED && is_file($b1)) copy($b1, $file);
        return ['msg' => $t['err_php_rollback'], 'type' => 'error'];
    }
    return null;
}

/**
 * Führt `php -l` aus. true = syntaktisch ok, false = echter Syntaxfehler,
 * null = Linter nicht verfügbar (exec gesperrt oder kein PHP-CLI im PATH).
 *
 * WICHTIG: Ein Exitcode != 0 allein beweist keinen Syntaxfehler. Fehlt das
 * CLI-Binary, antwortet die Shell mit 127 („command not found", unter Windows
 * auch 1). Wer das als Syntaxfehler wertet, rollt auf solchen Hosts *jeden*
 * Speichervorgang zurück und meldet dem Kunden einen PHP-Fehler, den es nie
 * gab. Als Fehler zählt darum nur eine echte Lint-Diagnose auf stdout/stderr.
 */
function _pesi_lint(string $file): ?bool {
    if (!function_exists('exec')) return null;
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) return null;

    $out = [];
    $ex  = 0;
    @exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $ex);
    if ($ex === 0) return true;
    if (preg_match('/(parse|fatal) error|errors parsing/i', implode("\n", $out))) return false;
    return null;
}

/**
 * Erkennt pesi-Struktur-Markierungen in einem Feldwert.
 *
 * Ein gespeicherter Wert landet als Literal im Seitenquelltext. Enthielte er
 * einen pesi:item-/pesi:toggle-Marker oder den if(false)-Wrapper, läsen Block-
 * und Toggle-Parser ihn als echte Struktur und schnitten ihre Spannen falsch.
 * Das Ergebnis bleibt dabei oft `php -l`-sauber — das Sicherheitsnetz greift
 * also nicht, der Kunde sieht nur noch Müll auf der Seite. Lieber die
 * Speicherung sauber ablehnen als still korrumpieren.
 */
function _pesi_has_marker(string $v): bool {
    return (bool)preg_match('#<!--\s*/?\s*pesi:(item|toggle)\b#i', $v)
        || (bool)preg_match('#<\?php\s+(if\s*\(\s*false\s*\)\s*:|endif\s*;)#i', $v);
}

function _pesi_save(string $file, array $fields, array $post): array {
    global $t;
    $src = file_get_contents($file);
    if ($src === false) return ['msg' => $t['err_not_readable'], 'type' => 'error'];
    if (!_pesi_backup($file)) return ['msg' => $t['err_backup'], 'type' => 'error'];
    $srcHash = hash('sha256', $src);

    $mod = $src;
    $n = 0;
    $blocked = [];
    foreach ($fields as $id => $fld) {
        $k = 'pesi_field_' . $id;
        if (!isset($post[$k])) continue;
        $nv = (string)$post[$k];
        if (($fld['type'] ?? '') === 'richtext' && function_exists('_pesi_sanitize_html')) {
            $nv = _pesi_sanitize_html($nv);
        }
        if (($fld['type'] ?? '') === 'image' && function_exists('_pesi_safe_asset_url')) {
            $nv = _pesi_safe_asset_url($nv);
        }
        if ($nv === $fld['value']) continue;
        if (_pesi_has_marker($nv)) { $blocked[] = $fld['label'] ?: $id; continue; }
        $mod = _pesi_replace($mod, $id, $nv);
        $n++;
    }
    // Vor dem ersten Schreibzugriff abbrechen — ein Save ist ganz oder gar nicht.
    if ($blocked) return ['msg' => sprintf($t['err_marker'], implode(', ', $blocked)), 'type' => 'error'];
    if ($n === 0) return ['msg' => $t['err_no_changes'], 'type' => 'info'];

    if ($c = _pesi_commit($file, $mod, $srcHash)) return $c;
    return ['msg' => sprintf($t[$n === 1 ? 'saved_one' : 'saved_many'], $n), 'type' => 'success'];
}

// ── Wiederholbare Blöcke ─────────────────────────────────────
// Marker im Seiten-Quelltext (HTML-Kommentare, im Output unsichtbar):
//
//   <!-- pesi:item team:1 -->
//   <article> … pesi('team_1_name', …) … </article>
//   <!-- /pesi:item -->
//
// 'team' = Gruppe, '1' = Instanz. Feld-IDs im Block tragen das Präfix
// '<gruppe>_<instanz>_'. Duplizieren klont die Spanne und vergibt eine
// neue Instanz; Löschen/Sortieren operieren auf den Spannen.

function _pesi_block_re(): string {
    return '/(?P<lead>[ \t]*)<!--\s*pesi:item\s+(?P<g>[a-z0-9_]+):(?P<i>\d+)\s*-->'
         . '(?P<body>.*?)<!--\s*\/pesi:item\s*-->[ \t]*(?P<tail>\r?\n)?/s';
}

// Liefert alle Blöcke in Datei-Reihenfolge: [group,inst,offset,len,text].
function _pesi_block_parse(string $file): array {
    $src = (string)file_get_contents($file);
    if ($src === '') return [];
    if (!preg_match_all(_pesi_block_re(), $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $blocks = [];
    foreach ($m as $x) {
        $blocks[] = [
            'group'  => $x['g'][0],
            'inst'   => (int)$x['i'][0],
            'offset' => $x[0][1],
            'len'    => strlen($x[0][0]),
            'text'   => $x[0][0],
        ];
    }
    return $blocks;
}

/**
 * Block-Operation: 'dup' | 'add' | 'del' | 'up' | 'down'.
 * 'add' klont den letzten Block der Gruppe als Vorlage.
 */
function _pesi_block_op(string $file, string $group, int $inst, string $action): array {
    global $t;
    $src = (string)file_get_contents($file);
    if ($src === '') return ['msg' => $t['err_not_readable'], 'type' => 'error'];
    // Wie beim Feld-Save: gegen den Stand committen, den wir gelesen haben.
    // Sonst überschreibt eine Blockoperation stillschweigend, was zwischen
    // Lesen und Schreiben von einer zweiten Sitzung gespeichert wurde — die
    // Offsets unten stammen aus genau diesem Snapshot.
    $srcHash = hash('sha256', $src);

    $all = _pesi_block_parse($file);
    $grp = array_values(array_filter($all, fn($b) => $b['group'] === $group));
    if (!$grp) return ['msg' => $t['blk_notfound'], 'type' => 'error'];

    // Zielindex innerhalb der Gruppe
    $ti = null;
    foreach ($grp as $idx => $b) {
        if ($action === 'add' ? $idx === count($grp) - 1 : $b['inst'] === $inst) { $ti = $idx; break; }
    }
    if ($ti === null) return ['msg' => $t['blk_notfound'], 'type' => 'error'];
    $target = $grp[$ti];

    if ($action === 'dup' || $action === 'add') {
        // Quelle: 'dup' = der angeklickte Eintrag (Inhalt mitkopieren).
        // 'add'  = die Vorlage = Instanz :1; falls die gelöscht wurde,
        //          der erste Eintrag der Gruppe in Datei-Reihenfolge.
        if ($action === 'add') {
            $srcBlk = null;
            foreach ($grp as $b) { if ($b['inst'] === 1) { $srcBlk = $b; break; } }
            if ($srcBlk === null) $srcBlk = $grp[0];
        } else {
            $srcBlk = $target;
        }
        $newInst = max(array_column($grp, 'inst')) + 1;
        $clone   = $srcBlk['text'];
        if (substr($clone, -1) !== "\n") $clone .= "\n";
        // Marker-Instanz umschreiben
        $clone = preg_replace(
            '/(<!--\s*pesi:item\s+' . preg_quote($group, '/') . ':)' . $srcBlk['inst'] . '(\s*-->)/',
            '${1}' . $newInst . '$2', $clone, 1
        );
        // Feld-ID-Präfix '<group>_<inst>_' innerhalb der pesi()-Aufrufe umschreiben
        $clone = preg_replace(
            '/(pesi\s*\(\s*[\'"])' . preg_quote($group . '_' . $srcBlk['inst'] . '_', '/') . '/',
            '${1}' . $group . '_' . $newInst . '_', $clone
        );
        // Einfügeposition: 'dup' direkt hinter dem duplizierten Eintrag,
        // 'add' ans Ende der Gruppe.
        if ($action === 'dup') {
            $at = $srcBlk['offset'] + $srcBlk['len'];
        } else {
            $last = $grp[count($grp) - 1];
            $at   = $last['offset'] + $last['len'];
        }
        $mod  = substr($src, 0, $at) . $clone . substr($src, $at);
        if (!_pesi_backup($file)) return ['msg' => $t['err_backup'], 'type' => 'error'];
        if ($c = _pesi_commit($file, $mod, $srcHash)) return $c;
        return ['msg' => $t[$action === 'add' ? 'blk_added' : 'blk_duplicated'], 'type' => 'success'];
    }

    if ($action === 'del') {
        if (count($grp) <= 1) return ['msg' => $t['blk_min_one'], 'type' => 'error'];
        $mod = substr($src, 0, $target['offset']) . substr($src, $target['offset'] + $target['len']);
        if (!_pesi_backup($file)) return ['msg' => $t['err_backup'], 'type' => 'error'];
        if ($c = _pesi_commit($file, $mod, $srcHash)) return $c;
        return ['msg' => $t['blk_deleted'], 'type' => 'success'];
    }

    if ($action === 'up' || $action === 'down') {
        $ni = $action === 'up' ? $ti - 1 : $ti + 1;
        if ($ni < 0 || $ni >= count($grp)) return ['msg' => $t['blk_edge'], 'type' => 'info'];
        // a kommt im File vor b (a.offset < b.offset)
        [$a, $b] = $target['offset'] < $grp[$ni]['offset'] ? [$target, $grp[$ni]] : [$grp[$ni], $target];
        $mod = substr($src, 0, $a['offset'])
             . $b['text']
             . substr($src, $a['offset'] + $a['len'], $b['offset'] - ($a['offset'] + $a['len']))
             . $a['text']
             . substr($src, $b['offset'] + $b['len']);
        if (!_pesi_backup($file)) return ['msg' => $t['err_backup'], 'type' => 'error'];
        if ($c = _pesi_commit($file, $mod, $srcHash)) return $c;
        return ['msg' => $t['blk_moved'], 'type' => 'success'];
    }

    return ['msg' => $t['blk_notfound'], 'type' => 'error'];
}

// ── Wiederherstellen ─────────────────────────────────────────
// Setzt die Datei auf .pesi-backup.1 zurück. Der aktuelle Stand
// wandert vorher in die Backup-Rotation → erneutes Klicken kehrt
// die Wiederherstellung wieder um.
function _pesi_restore(string $file): array {
    global $t;
    $b1 = $file . '.pesi-backup.1';
    if (!is_file($b1)) return ['msg' => $t['rst_none'], 'type' => 'info'];
    $restore = file_get_contents($b1);
    if ($restore === false || $restore === '') return ['msg' => $t['rst_none'], 'type' => 'info'];
    $current = (string)file_get_contents($file);
    if ($current === $restore) return ['msg' => $t['rst_same'], 'type' => 'info'];
    if (!_pesi_backup($file)) return ['msg' => $t['err_backup'], 'type' => 'error'];
    if ($c = _pesi_commit($file, $restore, hash('sha256', $current))) return $c;
    return ['msg' => $t['rst_done'], 'type' => 'success'];
}

/*
 * ── Sichtbarkeits-Schalter (pesi:toggle) ─────────────────────
 * <!-- pesi:toggle urlaub --> … <!-- /pesi:toggle -->
 * Versteckt = direkt nach dem Start-Marker steht ein if(false)-PHP-Block
 * und vor dem End-Marker dessen endif. PHP unterdrückt dann die Ausgabe
 * des Inhalts (inkl. der pesi()-Felder), ohne ihn zu löschen — der Kunde
 * kann ihn weiter bearbeiten.
 */
function _pesi_toggle_re(): string {
    return '/<!--\s*pesi:toggle\s+(?P<g>[a-z0-9_]+)\s*-->'
         . '(?P<off>\s*<\?php if \(false\): \?>)?'
         . '(?P<body>.*?)'
         . '(?:<\?php endif; \?>\s*)?<!--\s*\/pesi:toggle\s*-->/s';
}

/**
 * Meldet true, sobald sich zwei pesi:toggle-Spannen schachteln.
 *
 * Schachtelung ist in pesi-agent.md verboten, scheiterte bisher aber lautlos:
 * der Parser ist non-greedy und liest den *inneren* End-Marker als Ende der
 * äußeren Spanne. Der if(false)-Wrapper landet dann mitten im Dokument, alles
 * dahinter bleibt trotz „versteckt" sichtbar, und `php -l` findet daran nichts
 * auszusetzen — das Sicherheitsnetz greift also nicht. Lieber die Operation
 * verweigern und es im Dashboard anzeigen, als falsch zu schalten.
 */
function _pesi_toggle_nested(string $file): bool {
    $src = (string)file_get_contents($file);
    if (!preg_match_all('/<!--\s*(\/?)\s*pesi:toggle(?:\s+[a-z0-9_]+)?\s*-->/i', $src, $m, PREG_SET_ORDER)) {
        return false;
    }
    $depth = 0;
    foreach ($m as $x) {
        if ($x[1] === '/') { $depth = max(0, $depth - 1); continue; }
        if ($depth > 0) return true;
        $depth++;
    }
    return false;
}

// group => bool (true = sichtbar)
function _pesi_toggle_parse(string $file): array {
    $src = (string)file_get_contents($file);
    if ($src === '') return [];
    if (!preg_match_all(_pesi_toggle_re(), $src, $m, PREG_SET_ORDER)) return [];
    $out = [];
    foreach ($m as $x) $out[$x['g']] = ($x['off'] ?? '') === '';
    return $out;
}

function _pesi_toggle_op(string $file, string $group): array {
    global $t;
    $src = (string)file_get_contents($file);
    if ($src === '') return ['msg' => $t['err_not_readable'], 'type' => 'error'];
    if (_pesi_toggle_nested($file)) return ['msg' => $t['tgl_nested'], 'type' => 'error'];
    $srcHash = hash('sha256', $src);

    $eg = preg_quote($group, '/');
    $re = '/(<!--\s*pesi:toggle\s+' . $eg . '\s*-->)'
        . '(\s*<\?php if \(false\): \?>)?'
        . '(.*?)'
        . '(<\?php endif; \?>\s*)?(<!--\s*\/pesi:toggle\s*-->)/s';
    if (!preg_match($re, $src)) return ['msg' => $t['tgl_notfound'], 'type' => 'error'];

    $mod = preg_replace_callback($re, function ($mm) {
        $isOff = ($mm[2] ?? '') !== '';
        $body  = $mm[3];
        if ($isOff) {
            // sichtbar machen: Wrapper entfernen
            return $mm[1] . $body . $mm[5];
        }
        // verstecken: Wrapper setzen
        return $mm[1] . '<?php if (false): ?>' . $body . '<?php endif; ?>' . $mm[5];
    }, $src, 1);

    if (!_pesi_backup($file)) return ['msg' => $t['err_backup'], 'type' => 'error'];
    if ($c = _pesi_commit($file, $mod, $srcHash)) return $c;
    return ['msg' => $t['tgl_done'], 'type' => 'success'];
}

function _pesi_replace(string $src, string $id, string $nv): string {
    $eid = preg_quote($id, '/');
    $hd = _pesi_hd($nv);

    // Ersetzungswert einmal aufbauen und per Callback einsetzen. NIE über den
    // Replacement-String von preg_replace(): dort würden $1/${1}/\1 im
    // Nutzertext als Backreferences interpretiert (kaputtes PHP → Rollback
    // bzw. stille Content-Korruption bei z. B. Preisen wie „$1.000").
    $repl = $hd
        ? "<<<'PESI'\n" . $nv . "\nPESI"
        : "'" . _pesi_esc($nv) . "'";

    // Heredoc pattern
    $hp = '/(pesi\s*\(\s*[\'"]' . $eid . '[\'"]\s*,\s*)<<<[ \t]*\'PESI\'[ \t]*\r?\n.*?\r?\nPESI(\s*,\s*[\'"][a-z]+[\'"](?:\s*,\s*[\'"][^"\']*[\'"])?\s*\))/s';
    if (preg_match($hp, $src)) {
        return preg_replace_callback($hp, fn($m) => $m[1] . $repl . $m[2], $src, 1);
    }

    // String pattern
    $sp = '/(pesi\s*\(\s*[\'"]' . $eid . '[\'"]\s*,\s*)(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(\s*(?:,\s*[\'"][a-z]+[\'"])?(?:\s*,\s*[\'"][^"\']*[\'"])?\s*\))/s';
    if (preg_match($sp, $src)) {
        return preg_replace_callback($sp, fn($m) => $m[1] . $repl . $m[3], $src, 1);
    }

    return $src;
}

function _pesi_hd(string $v): bool {
    // Kollision mit Heredoc-Endmarker → erzwinge String-Modus.
    // WICHTIG: PHP 7.3+ akzeptiert *eingerückte* Closing-Marker. Ein Wert mit
    // einer Zeile wie "  PESI . <code>" würde das Nowdoc sonst vorzeitig
    // schließen und beliebigen PHP-Code ausführbar machen (RCE). Darum jede
    // Zeile prüfen, die nach optionalem Whitespace mit PESI + Wortgrenze beginnt.
    if (preg_match('/^[ \t]*PESI\b/m', $v)) return false;
    return strpos($v, "\n") !== false || strpos($v, '<') !== false || strpos($v, "'") !== false;
}

function _pesi_esc(string $v): string {
    return str_replace(["\\", "'"], ["\\\\", "\\'"], $v);
}

/**
 * Slug einer Gruppe (Block oder Toggle) als Anzeigename für die Kundin.
 * 'team_mitglieder' → 'Team mitglieder'. Eine einzige Stelle dafür, weil die
 * Block-Überschrift und die Sichtbarkeits-Liste sonst denselben Namen
 * unterschiedlich formatieren — das ist im Dashboard direkt nebeneinander
 * sichtbar und wirkt schlampig.
 */
function _pesi_human(string $slug): string {
    $s = trim(str_replace(['_', '-'], ' ', $slug));
    return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

// ── Brute-Force-Bremse (dateibasiert, IP-gebunden) ───────────
// Das reine Session-Backoff im Login-Handler ist umgehbar: Wer die Cookies
// verwirft, bekommt pro Versuch eine frische Session → das Backoff akkumuliert
// nie. Ein winziges Register (JSON, IP-Hash → Zähler + Sperrzeit) bremst darum
// zusätzlich pro IP. Es speichert nur Content-fremden Sicherheits-State und
// fällt lautlos auf das Session-Backoff zurück, wenn die Datei nicht schreibbar
// ist — passt damit zu pesis „kein Content-Store"-Prinzip.
function _pesi_throttle_file(): string { return __DIR__ . '/.pesi-throttle'; }

function _pesi_throttle_key(): string {
    return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

// Verbleibende Sperrsekunden für den aktuellen Client (0 = frei).
function _pesi_throttle_check(): int {
    $f = _pesi_throttle_file();
    if (!is_file($f)) return 0;
    $raw  = @file_get_contents($f);
    $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($data)) return 0;
    $until = (int)($data[_pesi_throttle_key()]['until'] ?? 0);
    return max(0, $until - time());
}

// Schreibt den Register-Stand unter Exklusiv-Lock (c+ = anlegen falls nötig).
function _pesi_throttle_write(callable $mutate): void {
    $fp = @fopen(_pesi_throttle_file(), 'c+');
    if (!$fp) return;                       // nicht schreibbar → Session-Backoff bleibt
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    $raw  = stream_get_contents($fp);
    $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
    if (!is_array($data)) $data = [];
    $now = time();
    // Abgelaufene Einträge entfernen und die Map gegen unbegrenztes Wachstum
    // (DoS durch viele Quell-IPs) deckeln.
    foreach ($data as $k => $v) {
        if ((int)($v['until'] ?? 0) < $now - 3600) unset($data[$k]);
    }
    if (count($data) > 500) $data = array_slice($data, -500, null, true);
    $data = $mutate($data, $now);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

// Fehlversuch verbuchen → exponentielles Backoff je IP: 2, 4, 8 … Sekunden,
// bei 2^8 = 256 s (~4 min) eingefroren; die 300 ist eine harte Obergrenze.
function _pesi_throttle_fail(): void {
    _pesi_throttle_write(function (array $data, int $now): array {
        $key = _pesi_throttle_key();
        $n   = (int)($data[$key]['n'] ?? 0) + 1;
        $data[$key] = ['n' => $n, 'until' => $now + min(300, 2 ** min($n, 8))];
        return $data;
    });
}

// Nach erfolgreichem Login den Zähler des Clients löschen.
function _pesi_throttle_reset(): void {
    _pesi_throttle_write(function (array $data): array {
        unset($data[_pesi_throttle_key()]);
        return $data;
    });
}

// ── Bild-Upload ──────────────────────────────────────────────

// Erlaubte MIME-Typen → zulässige Datei-Endungen. is_uploaded_file +
// finfo-MIME-Prüfung verhindern getarnte Skripte; die Endung wird
// zusätzlich gegen PESI_UPLOAD_TYPES geprüft.
function _pesi_upload_map(): array {
    return [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
        'image/gif'  => ['gif'],
    ];
}

function _pesi_upload_dir(): string {
    $dir = str_replace('\\', '/', (string)(defined('PESI_UPLOAD_DIR') ? PESI_UPLOAD_DIR : 'uploads'));
    $dir = trim($dir, "/\\");
    if ($dir === '') return '';
    if (preg_match('#(^|/)\.\.?($|/)#', $dir)) return '';
    if (!preg_match('#^[a-zA-Z0-9._/-]+$#', $dir)) return '';
    return $dir;
}

function _pesi_slug(string $name): string {
    $name = strtolower(pathinfo($name, PATHINFO_FILENAME));
    $name = preg_replace('/[^a-z0-9]+/', '-', $name);
    $name = trim((string)$name, '-');
    return $name !== '' ? substr($name, 0, 50) : 'bild';
}

/**
 * Verarbeitet hochgeladene Bilder (Feld 'pesi_upload_<id>').
 * Bei Erfolg wird der neue Web-Pfad in $post['pesi_field_<id>'] geschrieben,
 * sodass der bestehende String-Replacer ihn ins PHP übernimmt.
 *
 * @return array{post:array,errors:string[],old:array<string,string>}
 */
function _pesi_handle_uploads(array $fields, array $files, array $post, string $basePath): array {
    global $t;
    $errors = [];
    $old    = [];
    $map    = _pesi_upload_map();
    $cfgExt = array_filter(array_map(
        'trim',
        explode(',', strtolower(defined('PESI_UPLOAD_TYPES') ? PESI_UPLOAD_TYPES : 'jpg,jpeg,png,webp,avif,gif'))
    ));
    $maxBytes = defined('PESI_UPLOAD_MAX_BYTES') ? (int)PESI_UPLOAD_MAX_BYTES : 5 * 1024 * 1024;

    foreach ($fields as $id => $fld) {
        if ($fld['type'] !== 'image') continue;
        $fk = 'pesi_upload_' . $id;
        if (!isset($files[$fk]) || !is_array($files[$fk])) continue;
        $f = $files[$fk];

        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue; // kein neues Bild
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = sprintf($t['up_err_failed'], $fld['label'] ?: $id);
            continue;
        }
        if (($f['size'] ?? 0) <= 0 || $f['size'] > $maxBytes) {
            $errors[] = sprintf($t['up_err_size'], $fld['label'] ?: $id, round($maxBytes / 1048576, 1));
            continue;
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            $errors[] = sprintf($t['up_err_failed'], $fld['label'] ?: $id);
            continue;
        }

        $fi   = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$fi->file($f['tmp_name']);
        if (!isset($map[$mime])) {
            $errors[] = sprintf($t['up_err_type'], $fld['label'] ?: $id);
            continue;
        }
        // Endung aus dem erkannten MIME ableiten (nicht aus dem Dateinamen)
        $ext = $map[$mime][0];
        if (!in_array($ext, $cfgExt, true) && !array_intersect($map[$mime], $cfgExt)) {
            $errors[] = sprintf($t['up_err_type'], $fld['label'] ?: $id);
            continue;
        }

        $dir = _pesi_upload_dir();
        if ($dir === '') {
            $errors[] = $t['up_err_dir_invalid'];
            continue;
        }
        $absDir = $basePath . '/' . $dir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true)) {
            $errors[] = sprintf($t['up_err_dir'], $dir);
            continue;
        }
        $realBase = realpath($basePath);
        $realDir  = realpath($absDir);
        if ($realBase === false || $realDir === false || strncmp($realDir, $realBase . DIRECTORY_SEPARATOR, strlen($realBase) + 1) !== 0) {
            $errors[] = $t['up_err_dir_invalid'];
            continue;
        }

        $fname = _pesi_slug($f['name']) . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $dest  = $realDir . '/' . $fname;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            $errors[] = sprintf($t['up_err_failed'], $fld['label'] ?: $id);
            continue;
        }
        @chmod($dest, 0644);

        $old['pesi_field_' . $id] = $fld['value'];
        $post['pesi_field_' . $id] = '/' . $dir . '/' . $fname;
    }

    return ['post' => $post, 'errors' => $errors, 'old' => $old];
}

/**
 * Löscht ersetzte Bilder — aber nur, wenn sie im Upload-Ordner liegen
 * und von keiner registrierten Seite mehr referenziert werden
 * (DSGVO: keine verwaisten Personenfotos auf dem Webspace).
 */
function _pesi_cleanup_old(string $basePath, array $old, array $pages): void {
    $dir     = _pesi_upload_dir();
    if ($dir === '') return;
    $absDir  = realpath($basePath . '/' . $dir);
    if ($absDir === false) return;

    // Alle aktuell referenzierten Pfade über sämtliche Seiten sammeln
    $inUse = [];
    $raw   = '';
    foreach (array_keys($pages) as $pg) {
        $pf = $basePath . '/' . $pg;
        if (!file_exists($pf)) continue;
        $raw .= (string)file_get_contents($pf);
        foreach (_pesi_parse($pf) as $fld) {
            $inUse[ltrim($fld['value'], '/')] = true;
        }
    }

    foreach ($old as $path) {
        $rel = ltrim($path, '/');
        if ($rel === '' || strncmp($rel, $dir . '/', strlen($dir) + 1) !== 0) continue; // nur eigener Ordner
        if (isset($inUse[$rel])) continue;                                                // noch in Verwendung
        // Auch von Hand ins Markup geschriebene Referenzen zählen — ein
        // og:image oder ein festes <img> steht in keinem pesi()-Feld, das Bild
        // ist aber trotzdem in Gebrauch. Deckt nur registrierte Seiten ab;
        // Includes/Partials sieht pesi nicht (siehe README, Limitations).
        if (strpos($raw, basename($rel)) !== false) continue;
        $target = realpath($basePath . '/' . $rel);
        if ($target === false) continue;
        if (strncmp($target, $absDir . DIRECTORY_SEPARATOR, strlen($absDir) + 1) !== 0) continue; // kein Traversal
        @unlink($target);
    }
}

// ── i18n ─────────────────────────────────────────────────────
// Funktion (wird gehoisted) → bereits vor der POST-Verarbeitung nutzbar.
//
// TONFALL — das Dashboard gehört der Kundin, nicht der Entwicklerin.
// Sie ist der einzige Mensch, der diese Texte täglich liest, und sie ist
// nicht technisch. Darum:
//
//   1. Meldungen benennen die FOLGE, nie den Mechanismus. Nicht „PHP-Fehler
//      erkannt — Rollback!", sondern „Ihre Seite ist unverändert geblieben."
//   2. Was sie selbst beheben kann (Bild zu groß, falsches Format), sagt ihr
//      genau das — ohne Code, denn sie braucht niemanden dafür.
//   3. Was sie NICHT beheben kann, bekommt einen kurzen Code (S… = Struktur
//      der Seite, T… = Technik/Server) und die Bitte, ihn weiterzugeben. Sie
//      liest vier Zeichen vor, die Betreuung schlägt sie in der README nach.
//      Nur so bleibt sie handlungsfähig, ohne den Mechanismus zu sehen.
//   4. Deutsch siezt. Die Zielgruppe sind Praxen, Kanzleien, Therapeutinnen.
//      Wer duzen will, überschreibt einzelne Texte per $PESI_STRINGS in
//      pesi-core.php — ohne diese Datei anzufassen.
//
// Beide Sprachen müssen dieselben Keys und dieselben %-Platzhalter tragen;
// dev/test-engine.php prüft das.
function _pesi_strings(): array { return [
    'de' => [
        'err_not_readable'  => 'Diese Seite lässt sich gerade nicht lesen. Ihre Inhalte sind unverändert. Bitte melden Sie sich bei Ihrer Website-Betreuung. (Code T1)',
        'err_backup'        => 'Die Sicherheitskopie ließ sich nicht anlegen, deshalb wurde nichts geändert. Bitte melden Sie sich bei Ihrer Website-Betreuung. (Code T2)',
        'err_no_changes'    => 'Es gab nichts zu speichern — Sie haben nichts geändert.',
        'err_locked'        => 'Die Seite wird gerade von jemand anderem bearbeitet. Bitte versuchen Sie es in einem Moment noch einmal. (Code T3)',
        'err_php_rollback'  => 'Diese Änderung konnte nicht übernommen werden. Ihre Seite ist unverändert geblieben — es ist nichts kaputtgegangen. Bitte melden Sie sich bei Ihrer Website-Betreuung. (Code S1)',
        'err_session'       => 'Sie waren zu lange angemeldet. Bitte laden Sie die Seite neu und melden Sie sich erneut an.',
        'err_file_missing'  => 'Diese Seite ist nicht mehr auffindbar. Bitte melden Sie sich bei Ihrer Website-Betreuung. (Code T4) Betroffen: ',
        'err_stale'         => 'Diese Seite wurde zwischenzeitlich an anderer Stelle geändert. Bitte laden Sie sie neu und speichern Sie danach noch einmal.',
        'err_marker'        => 'Nicht gespeichert: %s enthält Text, den pesi nicht verarbeiten kann. Ihre Seite ist unverändert. Bitte entfernen Sie zuletzt eingefügte Sonderzeichen oder melden Sie sich bei Ihrer Website-Betreuung. (Code S2)',
        'saved_one'         => '1 Änderung gespeichert.',
        'saved_many'        => '%d Änderungen gespeichert.',
        'wrong_password'    => 'Das Passwort stimmt nicht.',
        'password_ph'       => 'Passwort',
        'login_btn'         => 'Anmelden',
        'login_help'        => 'Passwort vergessen? Ihre Website-Betreuung kann es neu setzen.',
        'nav_pages'         => 'Seiten',
        'to_website'        => '↗ Zur Website',
        'logout'            => 'Abmelden',
        'no_fields'         => 'Auf dieser Seite gibt es nichts zum Bearbeiten.',
        'save_hint'         => 'Ihre Änderungen werden erst mit „Speichern“ übernommen. „↩ Letzte Version“ stellt den Stand davor wieder her.',
        'save_btn'          => 'Speichern',
        'welcome_title'     => 'Willkommen',
        'welcome_hint'      => 'Wählen Sie links eine Seite aus, um deren Inhalte zu bearbeiten.',
        'diag_summary'      => '⚙ Technischer Hinweis für Ihre Website-Betreuung — für Sie ist nichts zu tun',
        'diag_intro'        => 'Diese Punkte betreffen die Einrichtung, nicht Ihre Inhalte. Bitte leiten Sie sie weiter:',
        'warn_default_pw'   => 'Es ist noch das Auslieferungs-Passwort gesetzt. In pesi-core.php ein eigenes PESI_PASSWORD eintragen, idealerweise als password_hash(). (Code T8)',
        'warn_no_exec'      => 'Syntax-Check ist aktiv, aber php -l lässt sich nicht ausführen (exec() gesperrt oder kein PHP-CLI im PATH). Damit entfällt das automatische Zurückrollen bei fehlerhaften Seiten. (Code T7)',
        'up_err_failed'     => 'Das Bild für „%s“ konnte nicht hochgeladen werden. Bitte versuchen Sie es noch einmal.',
        'up_err_size'       => 'Das Bild für „%s“ ist zu groß (höchstens %s MB). Bitte wählen Sie ein kleineres.',
        'up_err_type'       => 'Das Bild für „%s“ hat ein Format, das nicht unterstützt wird. Möglich sind JPG, PNG, WebP, AVIF und GIF.',
        'up_err_dir'        => 'Bilder lassen sich gerade nicht speichern. Bitte melden Sie sich bei Ihrer Website-Betreuung. (Code T5, Ordner „%s“)',
        'up_err_dir_invalid'=> 'Der Ordner für Bilder ist nicht richtig eingerichtet. Bitte melden Sie sich bei Ihrer Website-Betreuung. (Code T6)',
        'img_hint'          => 'Neues Bild auswählen oder einen Pfad eintragen. Das bisherige Bild wird dabei ersetzt.',
        'blk_entry'         => 'Eintrag',
        'blk_up'            => '↑',
        'blk_down'          => '↓',
        'blk_dup'           => 'Duplizieren',
        'blk_del'           => 'Löschen',
        'blk_add'           => '+ Eintrag hinzufügen',
        'blk_confirm_del'   => 'Diesen Eintrag wirklich löschen?',
        'blk_duplicated'    => 'Eintrag dupliziert.',
        'blk_added'         => 'Eintrag hinzugefügt.',
        'blk_deleted'       => 'Eintrag gelöscht.',
        'blk_moved'         => 'Reihenfolge geändert.',
        'blk_min_one'       => 'Der letzte Eintrag lässt sich nicht löschen — es muss einer bestehen bleiben.',
        'blk_edge'          => 'Dieser Eintrag steht bereits ganz oben bzw. ganz unten.',
        'blk_notfound'      => 'Dieser Eintrag ließ sich nicht finden. Bitte laden Sie die Seite neu. Bleibt es dabei, melden Sie sich bitte bei Ihrer Website-Betreuung. (Code S3)',
        'last_mod'          => 'zuletzt geändert: %s',
        'view_live'         => '↗ Seite ansehen',
        'saved_view'        => 'Gespeichert — Seite ansehen ↗',
        'pv_btn'            => 'Vorschau',
        'pv_label'          => 'Vorschau – verkleinert, zeigt gespeicherten Stand',
        'pv_title'          => 'Seitenvorschau',
        'tech_btn'          => 'Technische Ansicht',
        'dirty_one'         => '● %d ungespeicherte Änderung',
        'dirty_many'        => '● %d ungespeicherte Änderungen',
        'ob_title'          => 'So funktioniert es',
        'ob_step1'          => 'Links eine Seite auswählen',
        'ob_step2'          => 'Texte und Bilder direkt ändern',
        'ob_step3'          => 'Unten auf „Speichern“ — fertig',
        'ob_dismiss'        => 'Verstanden',
        'img_drop'          => 'Bild hierher ziehen oder klicken zum Auswählen',
        'img_current'       => 'Aktuelles Bild:',
        'img_advanced'      => 'Erweitert: Pfad / externe URL',
        'rst_btn'           => '↩ Letzte Version',
        'rst_confirm'       => 'Diese Seite auf die letzte gespeicherte Version zurücksetzen?',
        'rst_done'          => 'Die letzte Version wurde wiederhergestellt.',
        'rst_none'          => 'Es gibt noch keine frühere Version.',
        'rst_same'          => 'Der aktuelle Stand ist bereits die letzte Version.',
        'tgl_section'       => 'Sichtbarkeit',
        'tgl_visible'       => 'sichtbar',
        'tgl_hidden'        => 'versteckt',
        'tgl_show'          => 'Einblenden',
        'tgl_hide'          => 'Ausblenden',
        'tgl_done'          => 'Sichtbarkeit geändert.',
        'tgl_notfound'      => 'Dieser Bereich ließ sich nicht finden. Bitte laden Sie die Seite neu. Bleibt es dabei, melden Sie sich bitte bei Ihrer Website-Betreuung. (Code S4)',
        'tgl_nested'        => 'Diese Bereiche lassen sich hier nicht ein- und ausblenden, weil sie ineinander verschachtelt sind. Bitte melden Sie sich bei Ihrer Website-Betreuung. (Code S5)',
        'unsaved_warn'      => 'Es gibt ungespeicherte Änderungen. Möchten Sie diese verwerfen?',
    ],
    'en' => [
        'err_not_readable'  => 'This page cannot be read right now. Your content is unchanged. Please contact whoever looks after your website. (Code T1)',
        'err_backup'        => 'The safety copy could not be created, so nothing was changed. Please contact whoever looks after your website. (Code T2)',
        'err_no_changes'    => 'There was nothing to save — you did not change anything.',
        'err_locked'        => 'Someone else is editing this page right now. Please try again in a moment. (Code T3)',
        'err_php_rollback'  => 'This change could not be applied. Your page is unchanged — nothing is broken. Please contact whoever looks after your website. (Code S1)',
        'err_session'       => 'You were signed in for too long. Please reload the page and sign in again.',
        'err_file_missing'  => 'This page can no longer be found. Please contact whoever looks after your website. (Code T4) Affected: ',
        'err_stale'         => 'This page was changed elsewhere in the meantime. Please reload it and save again.',
        'err_marker'        => 'Not saved: %s contains text pesi cannot process. Your page is unchanged. Please remove any special characters you pasted recently, or contact whoever looks after your website. (Code S2)',
        'saved_one'         => '1 change saved.',
        'saved_many'        => '%d changes saved.',
        'wrong_password'    => 'That password is not correct.',
        'password_ph'       => 'Password',
        'login_btn'         => 'Sign in',
        'login_help'        => 'Forgot your password? Whoever looks after your website can reset it.',
        'nav_pages'         => 'Pages',
        'to_website'        => '↗ Visit Website',
        'logout'            => 'Sign out',
        'no_fields'         => 'There is nothing to edit on this page.',
        'save_hint'         => 'Your changes only take effect once you click "Save". "↩ Last version" restores the state before that.',
        'save_btn'          => 'Save',
        'welcome_title'     => 'Welcome',
        'welcome_hint'      => 'Select a page on the left to edit its content.',
        'diag_summary'      => '⚙ Technical note for whoever looks after your website — nothing for you to do',
        'diag_intro'        => 'These points concern the setup, not your content. Please pass them on:',
        'warn_default_pw'   => 'The shipped default password is still in use. Set your own PESI_PASSWORD in pesi-core.php, ideally as a password_hash(). (Code T8)',
        'warn_no_exec'      => 'Syntax check is enabled, but php -l cannot run (exec() disabled or no PHP CLI in PATH). Automatic rollback of a broken page is therefore unavailable. (Code T7)',
        'up_err_failed'     => 'The image for "%s" could not be uploaded. Please try again.',
        'up_err_size'       => 'The image for "%s" is too large (%s MB at most). Please choose a smaller one.',
        'up_err_type'       => 'The image for "%s" is in a format that is not supported. JPG, PNG, WebP, AVIF and GIF work.',
        'up_err_dir'        => 'Images cannot be saved right now. Please contact whoever looks after your website. (Code T5, folder "%s")',
        'up_err_dir_invalid'=> 'The folder for images is not set up correctly. Please contact whoever looks after your website. (Code T6)',
        'img_hint'          => 'Choose a new image or enter a path. This replaces the current image.',
        'blk_entry'         => 'Entry',
        'blk_up'            => '↑',
        'blk_down'          => '↓',
        'blk_dup'           => 'Duplicate',
        'blk_del'           => 'Delete',
        'blk_add'           => '+ Add entry',
        'blk_confirm_del'   => 'Really delete this entry?',
        'blk_duplicated'    => 'Entry duplicated.',
        'blk_added'         => 'Entry added.',
        'blk_deleted'       => 'Entry deleted.',
        'blk_moved'         => 'Order changed.',
        'blk_min_one'       => 'The last entry cannot be deleted — one has to remain.',
        'blk_edge'          => 'This entry is already at the top or the bottom.',
        'blk_notfound'      => 'This entry could not be found. Please reload the page. If it persists, contact whoever looks after your website. (Code S3)',
        'last_mod'          => 'last edited: %s',
        'view_live'         => '↗ View page',
        'saved_view'        => 'Saved — view page ↗',
        'pv_btn'            => 'Preview',
        'pv_label'          => 'Preview – scaled, shows the saved state',
        'pv_title'          => 'Page preview',
        'tech_btn'          => 'Technical view',
        'dirty_one'         => '● %d unsaved change',
        'dirty_many'        => '● %d unsaved changes',
        'ob_title'          => 'How it works',
        'ob_step1'          => 'Pick a page on the left',
        'ob_step2'          => 'Edit texts and images directly',
        'ob_step3'          => 'Click "Save" at the bottom — done',
        'ob_dismiss'        => 'Got it',
        'img_drop'          => 'Drag an image here or click to choose',
        'img_current'       => 'Current image:',
        'img_advanced'      => 'Advanced: path / external URL',
        'rst_btn'           => '↩ Last version',
        'rst_confirm'       => 'Reset this page to the last saved version?',
        'rst_done'          => 'The last version has been restored.',
        'rst_none'          => 'There is no earlier version yet.',
        'rst_same'          => 'The current state is already the last version.',
        'tgl_section'       => 'Visibility',
        'tgl_visible'       => 'visible',
        'tgl_hidden'        => 'hidden',
        'tgl_show'          => 'Show',
        'tgl_hide'          => 'Hide',
        'tgl_done'          => 'Visibility changed.',
        'tgl_notfound'      => 'This section could not be found. Please reload the page. If it persists, contact whoever looks after your website. (Code S4)',
        'tgl_nested'        => 'These sections cannot be shown or hidden here because they are nested inside one another. Please contact whoever looks after your website. (Code S5)',
        'unsaved_warn'      => 'There are unsaved changes. Do you want to discard them?',
    ],
]; }
$lang = defined('LANG') ? LANG : 'de';
$t    = _pesi_strings()[$lang] ?? _pesi_strings()['de'];

// ── Render ───────────────────────────────────────────────────
$bc   = defined('BRAND_COLOR') ? BRAND_COLOR : '#c47a2a';
// 3-stelliges Hex (#abc) auf 6-stellig normalisieren — Alpha-Suffixe (…22, …0a) brauchen 6 Stellen
if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $bc, $m)) {
    $bc = '#' . $m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
}
if (!preg_match('/^#[0-9a-f]{6}$/i', $bc)) $bc = '#c47a2a';
$sn   = defined('BRAND_NAME')  ? BRAND_NAME  : 'pesi CMS';
$logo = defined('BRAND_LOGO') && BRAND_LOGO ? BRAND_LOGO : null;
$hasRt = false;
foreach ($fields as $f) { if ($f['type'] === 'richtext') { $hasRt = true; break; } }
?>
<!DOCTYPE html>
<html lang="<?=$lang?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>pesi · <?= htmlspecialchars($sn) ?></title>
<?php if ($auth && $hasRt): ?>
<style>/*!
 * Quill Editor v2.0.3
 * https://quilljs.com
 * Copyright (c) 2017-2024, Slab
 * Copyright (c) 2014, Jason Chen
 * Copyright (c) 2013, salesforce.com
 */
.ql-container{box-sizing:border-box;font-family:Helvetica,Arial,sans-serif;font-size:13px;height:100%;margin:0;position:relative}.ql-container.ql-disabled .ql-tooltip{visibility:hidden}.ql-container:not(.ql-disabled) li[data-list=checked] > .ql-ui,.ql-container:not(.ql-disabled) li[data-list=unchecked] > .ql-ui{cursor:pointer}.ql-clipboard{left:-100000px;height:1px;overflow-y:hidden;position:absolute;top:50%}.ql-clipboard p{margin:0;padding:0}.ql-editor{box-sizing:border-box;counter-reset:list-0 list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9;line-height:1.42;height:100%;outline:none;overflow-y:auto;padding:12px 15px;tab-size:4;-moz-tab-size:4;text-align:left;white-space:pre-wrap;word-wrap:break-word}.ql-editor > *{cursor:text}.ql-editor p,.ql-editor ol,.ql-editor pre,.ql-editor blockquote,.ql-editor h1,.ql-editor h2,.ql-editor h3,.ql-editor h4,.ql-editor h5,.ql-editor h6{margin:0;padding:0}@supports (counter-set:none){.ql-editor p,.ql-editor h1,.ql-editor h2,.ql-editor h3,.ql-editor h4,.ql-editor h5,.ql-editor h6{counter-set:list-0 list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9}}@supports not (counter-set:none){.ql-editor p,.ql-editor h1,.ql-editor h2,.ql-editor h3,.ql-editor h4,.ql-editor h5,.ql-editor h6{counter-reset:list-0 list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9}}.ql-editor table{border-collapse:collapse}.ql-editor td{border:1px solid #000;padding:2px 5px}.ql-editor ol{padding-left:1.5em}.ql-editor li{list-style-type:none;padding-left:1.5em;position:relative}.ql-editor li > .ql-ui:before{display:inline-block;margin-left:-1.5em;margin-right:.3em;text-align:right;white-space:nowrap;width:1.2em}.ql-editor li[data-list=checked] > .ql-ui,.ql-editor li[data-list=unchecked] > .ql-ui{color:#777}.ql-editor li[data-list=bullet] > .ql-ui:before{content:'\2022'}.ql-editor li[data-list=checked] > .ql-ui:before{content:'\2611'}.ql-editor li[data-list=unchecked] > .ql-ui:before{content:'\2610'}@supports (counter-set:none){.ql-editor li[data-list]{counter-set:list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9}}@supports not (counter-set:none){.ql-editor li[data-list]{counter-reset:list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9}}.ql-editor li[data-list=ordered]{counter-increment:list-0}.ql-editor li[data-list=ordered] > .ql-ui:before{content:counter(list-0, decimal) '. '}.ql-editor li[data-list=ordered].ql-indent-1{counter-increment:list-1}.ql-editor li[data-list=ordered].ql-indent-1 > .ql-ui:before{content:counter(list-1, lower-alpha) '. '}@supports (counter-set:none){.ql-editor li[data-list].ql-indent-1{counter-set:list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9}}@supports not (counter-set:none){.ql-editor li[data-list].ql-indent-1{counter-reset:list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9}}.ql-editor li[data-list=ordered].ql-indent-2{counter-increment:list-2}.ql-editor li[data-list=ordered].ql-indent-2 > .ql-ui:before{content:counter(list-2, lower-roman) '. '}@supports (counter-set:none){.ql-editor li[data-list].ql-indent-2{counter-set:list-3 list-4 list-5 list-6 list-7 list-8 list-9}}@supports not (counter-set:none){.ql-editor li[data-list].ql-indent-2{counter-reset:list-3 list-4 list-5 list-6 list-7 list-8 list-9}}.ql-editor li[data-list=ordered].ql-indent-3{counter-increment:list-3}.ql-editor li[data-list=ordered].ql-indent-3 > .ql-ui:before{content:counter(list-3, decimal) '. '}@supports (counter-set:none){.ql-editor li[data-list].ql-indent-3{counter-set:list-4 list-5 list-6 list-7 list-8 list-9}}@supports not (counter-set:none){.ql-editor li[data-list].ql-indent-3{counter-reset:list-4 list-5 list-6 list-7 list-8 list-9}}.ql-editor li[data-list=ordered].ql-indent-4{counter-increment:list-4}.ql-editor li[data-list=ordered].ql-indent-4 > .ql-ui:before{content:counter(list-4, lower-alpha) '. '}@supports (counter-set:none){.ql-editor li[data-list].ql-indent-4{counter-set:list-5 list-6 list-7 list-8 list-9}}@supports not (counter-set:none){.ql-editor li[data-list].ql-indent-4{counter-reset:list-5 list-6 list-7 list-8 list-9}}.ql-editor li[data-list=ordered].ql-indent-5{counter-increment:list-5}.ql-editor li[data-list=ordered].ql-indent-5 > .ql-ui:before{content:counter(list-5, lower-roman) '. '}@supports (counter-set:none){.ql-editor li[data-list].ql-indent-5{counter-set:list-6 list-7 list-8 list-9}}@supports not (counter-set:none){.ql-editor li[data-list].ql-indent-5{counter-reset:list-6 list-7 list-8 list-9}}.ql-editor li[data-list=ordered].ql-indent-6{counter-increment:list-6}.ql-editor li[data-list=ordered].ql-indent-6 > .ql-ui:before{content:counter(list-6, decimal) '. '}@supports (counter-set:none){.ql-editor li[data-list].ql-indent-6{counter-set:list-7 list-8 list-9}}@supports not (counter-set:none){.ql-editor li[data-list].ql-indent-6{counter-reset:list-7 list-8 list-9}}.ql-editor li[data-list=ordered].ql-indent-7{counter-increment:list-7}.ql-editor li[data-list=ordered].ql-indent-7 > .ql-ui:before{content:counter(list-7, lower-alpha) '. '}@supports (counter-set:none){.ql-editor li[data-list].ql-indent-7{counter-set:list-8 list-9}}@supports not (counter-set:none){.ql-editor li[data-list].ql-indent-7{counter-reset:list-8 list-9}}.ql-editor li[data-list=ordered].ql-indent-8{counter-increment:list-8}.ql-editor li[data-list=ordered].ql-indent-8 > .ql-ui:before{content:counter(list-8, lower-roman) '. '}@supports (counter-set:none){.ql-editor li[data-list].ql-indent-8{counter-set:list-9}}@supports not (counter-set:none){.ql-editor li[data-list].ql-indent-8{counter-reset:list-9}}.ql-editor li[data-list=ordered].ql-indent-9{counter-increment:list-9}.ql-editor li[data-list=ordered].ql-indent-9 > .ql-ui:before{content:counter(list-9, decimal) '. '}.ql-editor .ql-indent-1:not(.ql-direction-rtl){padding-left:3em}.ql-editor li.ql-indent-1:not(.ql-direction-rtl){padding-left:4.5em}.ql-editor .ql-indent-1.ql-direction-rtl.ql-align-right{padding-right:3em}.ql-editor li.ql-indent-1.ql-direction-rtl.ql-align-right{padding-right:4.5em}.ql-editor .ql-indent-2:not(.ql-direction-rtl){padding-left:6em}.ql-editor li.ql-indent-2:not(.ql-direction-rtl){padding-left:7.5em}.ql-editor .ql-indent-2.ql-direction-rtl.ql-align-right{padding-right:6em}.ql-editor li.ql-indent-2.ql-direction-rtl.ql-align-right{padding-right:7.5em}.ql-editor .ql-indent-3:not(.ql-direction-rtl){padding-left:9em}.ql-editor li.ql-indent-3:not(.ql-direction-rtl){padding-left:10.5em}.ql-editor .ql-indent-3.ql-direction-rtl.ql-align-right{padding-right:9em}.ql-editor li.ql-indent-3.ql-direction-rtl.ql-align-right{padding-right:10.5em}.ql-editor .ql-indent-4:not(.ql-direction-rtl){padding-left:12em}.ql-editor li.ql-indent-4:not(.ql-direction-rtl){padding-left:13.5em}.ql-editor .ql-indent-4.ql-direction-rtl.ql-align-right{padding-right:12em}.ql-editor li.ql-indent-4.ql-direction-rtl.ql-align-right{padding-right:13.5em}.ql-editor .ql-indent-5:not(.ql-direction-rtl){padding-left:15em}.ql-editor li.ql-indent-5:not(.ql-direction-rtl){padding-left:16.5em}.ql-editor .ql-indent-5.ql-direction-rtl.ql-align-right{padding-right:15em}.ql-editor li.ql-indent-5.ql-direction-rtl.ql-align-right{padding-right:16.5em}.ql-editor .ql-indent-6:not(.ql-direction-rtl){padding-left:18em}.ql-editor li.ql-indent-6:not(.ql-direction-rtl){padding-left:19.5em}.ql-editor .ql-indent-6.ql-direction-rtl.ql-align-right{padding-right:18em}.ql-editor li.ql-indent-6.ql-direction-rtl.ql-align-right{padding-right:19.5em}.ql-editor .ql-indent-7:not(.ql-direction-rtl){padding-left:21em}.ql-editor li.ql-indent-7:not(.ql-direction-rtl){padding-left:22.5em}.ql-editor .ql-indent-7.ql-direction-rtl.ql-align-right{padding-right:21em}.ql-editor li.ql-indent-7.ql-direction-rtl.ql-align-right{padding-right:22.5em}.ql-editor .ql-indent-8:not(.ql-direction-rtl){padding-left:24em}.ql-editor li.ql-indent-8:not(.ql-direction-rtl){padding-left:25.5em}.ql-editor .ql-indent-8.ql-direction-rtl.ql-align-right{padding-right:24em}.ql-editor li.ql-indent-8.ql-direction-rtl.ql-align-right{padding-right:25.5em}.ql-editor .ql-indent-9:not(.ql-direction-rtl){padding-left:27em}.ql-editor li.ql-indent-9:not(.ql-direction-rtl){padding-left:28.5em}.ql-editor .ql-indent-9.ql-direction-rtl.ql-align-right{padding-right:27em}.ql-editor li.ql-indent-9.ql-direction-rtl.ql-align-right{padding-right:28.5em}.ql-editor li.ql-direction-rtl{padding-right:1.5em}.ql-editor li.ql-direction-rtl > .ql-ui:before{margin-left:.3em;margin-right:-1.5em;text-align:left}.ql-editor table{table-layout:fixed;width:100%}.ql-editor table td{outline:none}.ql-editor .ql-code-block-container{font-family:monospace}.ql-editor .ql-video{display:block;max-width:100%}.ql-editor .ql-video.ql-align-center{margin:0 auto}.ql-editor .ql-video.ql-align-right{margin:0 0 0 auto}.ql-editor .ql-bg-black{background-color:#000}.ql-editor .ql-bg-red{background-color:#e60000}.ql-editor .ql-bg-orange{background-color:#f90}.ql-editor .ql-bg-yellow{background-color:#ff0}.ql-editor .ql-bg-green{background-color:#008a00}.ql-editor .ql-bg-blue{background-color:#06c}.ql-editor .ql-bg-purple{background-color:#93f}.ql-editor .ql-color-white{color:#fff}.ql-editor .ql-color-red{color:#e60000}.ql-editor .ql-color-orange{color:#f90}.ql-editor .ql-color-yellow{color:#ff0}.ql-editor .ql-color-green{color:#008a00}.ql-editor .ql-color-blue{color:#06c}.ql-editor .ql-color-purple{color:#93f}.ql-editor .ql-font-serif{font-family:Georgia,Times New Roman,serif}.ql-editor .ql-font-monospace{font-family:Monaco,Courier New,monospace}.ql-editor .ql-size-small{font-size:.75em}.ql-editor .ql-size-large{font-size:1.5em}.ql-editor .ql-size-huge{font-size:2.5em}.ql-editor .ql-direction-rtl{direction:rtl;text-align:inherit}.ql-editor .ql-align-center{text-align:center}.ql-editor .ql-align-justify{text-align:justify}.ql-editor .ql-align-right{text-align:right}.ql-editor .ql-ui{position:absolute}.ql-editor.ql-blank::before{color:rgba(0,0,0,0.6);content:attr(data-placeholder);font-style:italic;left:15px;pointer-events:none;position:absolute;right:15px}.ql-snow.ql-toolbar:after,.ql-snow .ql-toolbar:after{clear:both;content:'';display:table}.ql-snow.ql-toolbar button,.ql-snow .ql-toolbar button{background:none;border:none;cursor:pointer;display:inline-block;float:left;height:24px;padding:3px 5px;width:28px}.ql-snow.ql-toolbar button svg,.ql-snow .ql-toolbar button svg{float:left;height:100%}.ql-snow.ql-toolbar button:active:hover,.ql-snow .ql-toolbar button:active:hover{outline:none}.ql-snow.ql-toolbar input.ql-image[type=file],.ql-snow .ql-toolbar input.ql-image[type=file]{display:none}.ql-snow.ql-toolbar button:hover,.ql-snow .ql-toolbar button:hover,.ql-snow.ql-toolbar button:focus,.ql-snow .ql-toolbar button:focus,.ql-snow.ql-toolbar button.ql-active,.ql-snow .ql-toolbar button.ql-active,.ql-snow.ql-toolbar .ql-picker-label:hover,.ql-snow .ql-toolbar .ql-picker-label:hover,.ql-snow.ql-toolbar .ql-picker-label.ql-active,.ql-snow .ql-toolbar .ql-picker-label.ql-active,.ql-snow.ql-toolbar .ql-picker-item:hover,.ql-snow .ql-toolbar .ql-picker-item:hover,.ql-snow.ql-toolbar .ql-picker-item.ql-selected,.ql-snow .ql-toolbar .ql-picker-item.ql-selected{color:#06c}.ql-snow.ql-toolbar button:hover .ql-fill,.ql-snow .ql-toolbar button:hover .ql-fill,.ql-snow.ql-toolbar button:focus .ql-fill,.ql-snow .ql-toolbar button:focus .ql-fill,.ql-snow.ql-toolbar button.ql-active .ql-fill,.ql-snow .ql-toolbar button.ql-active .ql-fill,.ql-snow.ql-toolbar .ql-picker-label:hover .ql-fill,.ql-snow .ql-toolbar .ql-picker-label:hover .ql-fill,.ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-fill,.ql-snow .ql-toolbar .ql-picker-label.ql-active .ql-fill,.ql-snow.ql-toolbar .ql-picker-item:hover .ql-fill,.ql-snow .ql-toolbar .ql-picker-item:hover .ql-fill,.ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-fill,.ql-snow .ql-toolbar .ql-picker-item.ql-selected .ql-fill,.ql-snow.ql-toolbar button:hover .ql-stroke.ql-fill,.ql-snow .ql-toolbar button:hover .ql-stroke.ql-fill,.ql-snow.ql-toolbar button:focus .ql-stroke.ql-fill,.ql-snow .ql-toolbar button:focus .ql-stroke.ql-fill,.ql-snow.ql-toolbar button.ql-active .ql-stroke.ql-fill,.ql-snow .ql-toolbar button.ql-active .ql-stroke.ql-fill,.ql-snow.ql-toolbar .ql-picker-label:hover .ql-stroke.ql-fill,.ql-snow .ql-toolbar .ql-picker-label:hover .ql-stroke.ql-fill,.ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-stroke.ql-fill,.ql-snow .ql-toolbar .ql-picker-label.ql-active .ql-stroke.ql-fill,.ql-snow.ql-toolbar .ql-picker-item:hover .ql-stroke.ql-fill,.ql-snow .ql-toolbar .ql-picker-item:hover .ql-stroke.ql-fill,.ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-stroke.ql-fill,.ql-snow .ql-toolbar .ql-picker-item.ql-selected .ql-stroke.ql-fill{fill:#06c}.ql-snow.ql-toolbar button:hover .ql-stroke,.ql-snow .ql-toolbar button:hover .ql-stroke,.ql-snow.ql-toolbar button:focus .ql-stroke,.ql-snow .ql-toolbar button:focus .ql-stroke,.ql-snow.ql-toolbar button.ql-active .ql-stroke,.ql-snow .ql-toolbar button.ql-active .ql-stroke,.ql-snow.ql-toolbar .ql-picker-label:hover .ql-stroke,.ql-snow .ql-toolbar .ql-picker-label:hover .ql-stroke,.ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-stroke,.ql-snow .ql-toolbar .ql-picker-label.ql-active .ql-stroke,.ql-snow.ql-toolbar .ql-picker-item:hover .ql-stroke,.ql-snow .ql-toolbar .ql-picker-item:hover .ql-stroke,.ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-stroke,.ql-snow .ql-toolbar .ql-picker-item.ql-selected .ql-stroke,.ql-snow.ql-toolbar button:hover .ql-stroke-miter,.ql-snow .ql-toolbar button:hover .ql-stroke-miter,.ql-snow.ql-toolbar button:focus .ql-stroke-miter,.ql-snow .ql-toolbar button:focus .ql-stroke-miter,.ql-snow.ql-toolbar button.ql-active .ql-stroke-miter,.ql-snow .ql-toolbar button.ql-active .ql-stroke-miter,.ql-snow.ql-toolbar .ql-picker-label:hover .ql-stroke-miter,.ql-snow .ql-toolbar .ql-picker-label:hover .ql-stroke-miter,.ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-stroke-miter,.ql-snow .ql-toolbar .ql-picker-label.ql-active .ql-stroke-miter,.ql-snow.ql-toolbar .ql-picker-item:hover .ql-stroke-miter,.ql-snow .ql-toolbar .ql-picker-item:hover .ql-stroke-miter,.ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-stroke-miter,.ql-snow .ql-toolbar .ql-picker-item.ql-selected .ql-stroke-miter{stroke:#06c}@media (pointer:coarse){.ql-snow.ql-toolbar button:hover:not(.ql-active),.ql-snow .ql-toolbar button:hover:not(.ql-active){color:#444}.ql-snow.ql-toolbar button:hover:not(.ql-active) .ql-fill,.ql-snow .ql-toolbar button:hover:not(.ql-active) .ql-fill,.ql-snow.ql-toolbar button:hover:not(.ql-active) .ql-stroke.ql-fill,.ql-snow .ql-toolbar button:hover:not(.ql-active) .ql-stroke.ql-fill{fill:#444}.ql-snow.ql-toolbar button:hover:not(.ql-active) .ql-stroke,.ql-snow .ql-toolbar button:hover:not(.ql-active) .ql-stroke,.ql-snow.ql-toolbar button:hover:not(.ql-active) .ql-stroke-miter,.ql-snow .ql-toolbar button:hover:not(.ql-active) .ql-stroke-miter{stroke:#444}}.ql-snow{box-sizing:border-box}.ql-snow *{box-sizing:border-box}.ql-snow .ql-hidden{display:none}.ql-snow .ql-out-bottom,.ql-snow .ql-out-top{visibility:hidden}.ql-snow .ql-tooltip{position:absolute;transform:translateY(10px)}.ql-snow .ql-tooltip a{cursor:pointer;text-decoration:none}.ql-snow .ql-tooltip.ql-flip{transform:translateY(-10px)}.ql-snow .ql-formats{display:inline-block;vertical-align:middle}.ql-snow .ql-formats:after{clear:both;content:'';display:table}.ql-snow .ql-stroke{fill:none;stroke:#444;stroke-linecap:round;stroke-linejoin:round;stroke-width:2}.ql-snow .ql-stroke-miter{fill:none;stroke:#444;stroke-miterlimit:10;stroke-width:2}.ql-snow .ql-fill,.ql-snow .ql-stroke.ql-fill{fill:#444}.ql-snow .ql-empty{fill:none}.ql-snow .ql-even{fill-rule:evenodd}.ql-snow .ql-thin,.ql-snow .ql-stroke.ql-thin{stroke-width:1}.ql-snow .ql-transparent{opacity:.4}.ql-snow .ql-direction svg:last-child{display:none}.ql-snow .ql-direction.ql-active svg:last-child{display:inline}.ql-snow .ql-direction.ql-active svg:first-child{display:none}.ql-snow .ql-editor h1{font-size:2em}.ql-snow .ql-editor h2{font-size:1.5em}.ql-snow .ql-editor h3{font-size:1.17em}.ql-snow .ql-editor h4{font-size:1em}.ql-snow .ql-editor h5{font-size:.83em}.ql-snow .ql-editor h6{font-size:.67em}.ql-snow .ql-editor a{text-decoration:underline}.ql-snow .ql-editor blockquote{border-left:4px solid #ccc;margin-bottom:5px;margin-top:5px;padding-left:16px}.ql-snow .ql-editor code,.ql-snow .ql-editor .ql-code-block-container{background-color:#f0f0f0;border-radius:3px}.ql-snow .ql-editor .ql-code-block-container{margin-bottom:5px;margin-top:5px;padding:5px 10px}.ql-snow .ql-editor code{font-size:85%;padding:2px 4px}.ql-snow .ql-editor .ql-code-block-container{background-color:#23241f;color:#f8f8f2;overflow:visible}.ql-snow .ql-editor img{max-width:100%}.ql-snow .ql-picker{color:#444;display:inline-block;float:left;font-size:14px;font-weight:500;height:24px;position:relative;vertical-align:middle}.ql-snow .ql-picker-label{cursor:pointer;display:inline-block;height:100%;padding-left:8px;padding-right:2px;position:relative;width:100%}.ql-snow .ql-picker-label::before{display:inline-block;line-height:22px}.ql-snow .ql-picker-options{background-color:#fff;display:none;min-width:100%;padding:4px 8px;position:absolute;white-space:nowrap}.ql-snow .ql-picker-options .ql-picker-item{cursor:pointer;display:block;padding-bottom:5px;padding-top:5px}.ql-snow .ql-picker.ql-expanded .ql-picker-label{color:#ccc;z-index:2}.ql-snow .ql-picker.ql-expanded .ql-picker-label .ql-fill{fill:#ccc}.ql-snow .ql-picker.ql-expanded .ql-picker-label .ql-stroke{stroke:#ccc}.ql-snow .ql-picker.ql-expanded .ql-picker-options{display:block;margin-top:-1px;top:100%;z-index:1}.ql-snow .ql-color-picker,.ql-snow .ql-icon-picker{width:28px}.ql-snow .ql-color-picker .ql-picker-label,.ql-snow .ql-icon-picker .ql-picker-label{padding:2px 4px}.ql-snow .ql-color-picker .ql-picker-label svg,.ql-snow .ql-icon-picker .ql-picker-label svg{right:4px}.ql-snow .ql-icon-picker .ql-picker-options{padding:4px 0}.ql-snow .ql-icon-picker .ql-picker-item{height:24px;width:24px;padding:2px 4px}.ql-snow .ql-color-picker .ql-picker-options{padding:3px 5px;width:152px}.ql-snow .ql-color-picker .ql-picker-item{border:1px solid transparent;float:left;height:16px;margin:2px;padding:0;width:16px}.ql-snow .ql-picker:not(.ql-color-picker):not(.ql-icon-picker) svg{position:absolute;margin-top:-9px;right:0;top:50%;width:18px}.ql-snow .ql-picker.ql-header .ql-picker-label[data-label]:not([data-label=''])::before,.ql-snow .ql-picker.ql-font .ql-picker-label[data-label]:not([data-label=''])::before,.ql-snow .ql-picker.ql-size .ql-picker-label[data-label]:not([data-label=''])::before,.ql-snow .ql-picker.ql-header .ql-picker-item[data-label]:not([data-label=''])::before,.ql-snow .ql-picker.ql-font .ql-picker-item[data-label]:not([data-label=''])::before,.ql-snow .ql-picker.ql-size .ql-picker-item[data-label]:not([data-label=''])::before{content:attr(data-label)}.ql-snow .ql-picker.ql-header{width:98px}.ql-snow .ql-picker.ql-header .ql-picker-label::before,.ql-snow .ql-picker.ql-header .ql-picker-item::before{content:'Normal'}.ql-snow .ql-picker.ql-header .ql-picker-label[data-value="1"]::before,.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="1"]::before{content:'Heading 1'}.ql-snow .ql-picker.ql-header .ql-picker-label[data-value="2"]::before,.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="2"]::before{content:'Heading 2'}.ql-snow .ql-picker.ql-header .ql-picker-label[data-value="3"]::before,.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="3"]::before{content:'Heading 3'}.ql-snow .ql-picker.ql-header .ql-picker-label[data-value="4"]::before,.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="4"]::before{content:'Heading 4'}.ql-snow .ql-picker.ql-header .ql-picker-label[data-value="5"]::before,.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="5"]::before{content:'Heading 5'}.ql-snow .ql-picker.ql-header .ql-picker-label[data-value="6"]::before,.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="6"]::before{content:'Heading 6'}.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="1"]::before{font-size:2em}.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="2"]::before{font-size:1.5em}.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="3"]::before{font-size:1.17em}.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="4"]::before{font-size:1em}.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="5"]::before{font-size:.83em}.ql-snow .ql-picker.ql-header .ql-picker-item[data-value="6"]::before{font-size:.67em}.ql-snow .ql-picker.ql-font{width:108px}.ql-snow .ql-picker.ql-font .ql-picker-label::before,.ql-snow .ql-picker.ql-font .ql-picker-item::before{content:'Sans Serif'}.ql-snow .ql-picker.ql-font .ql-picker-label[data-value=serif]::before,.ql-snow .ql-picker.ql-font .ql-picker-item[data-value=serif]::before{content:'Serif'}.ql-snow .ql-picker.ql-font .ql-picker-label[data-value=monospace]::before,.ql-snow .ql-picker.ql-font .ql-picker-item[data-value=monospace]::before{content:'Monospace'}.ql-snow .ql-picker.ql-font .ql-picker-item[data-value=serif]::before{font-family:Georgia,Times New Roman,serif}.ql-snow .ql-picker.ql-font .ql-picker-item[data-value=monospace]::before{font-family:Monaco,Courier New,monospace}.ql-snow .ql-picker.ql-size{width:98px}.ql-snow .ql-picker.ql-size .ql-picker-label::before,.ql-snow .ql-picker.ql-size .ql-picker-item::before{content:'Normal'}.ql-snow .ql-picker.ql-size .ql-picker-label[data-value=small]::before,.ql-snow .ql-picker.ql-size .ql-picker-item[data-value=small]::before{content:'Small'}.ql-snow .ql-picker.ql-size .ql-picker-label[data-value=large]::before,.ql-snow .ql-picker.ql-size .ql-picker-item[data-value=large]::before{content:'Large'}.ql-snow .ql-picker.ql-size .ql-picker-label[data-value=huge]::before,.ql-snow .ql-picker.ql-size .ql-picker-item[data-value=huge]::before{content:'Huge'}.ql-snow .ql-picker.ql-size .ql-picker-item[data-value=small]::before{font-size:10px}.ql-snow .ql-picker.ql-size .ql-picker-item[data-value=large]::before{font-size:18px}.ql-snow .ql-picker.ql-size .ql-picker-item[data-value=huge]::before{font-size:32px}.ql-snow .ql-color-picker.ql-background .ql-picker-item{background-color:#fff}.ql-snow .ql-color-picker.ql-color .ql-picker-item{background-color:#000}.ql-code-block-container{position:relative}.ql-code-block-container .ql-ui{right:5px;top:5px}.ql-toolbar.ql-snow{border:1px solid #ccc;box-sizing:border-box;font-family:'Helvetica Neue','Helvetica','Arial',sans-serif;padding:8px}.ql-toolbar.ql-snow .ql-formats{margin-right:15px}.ql-toolbar.ql-snow .ql-picker-label{border:1px solid transparent}.ql-toolbar.ql-snow .ql-picker-options{border:1px solid transparent;box-shadow:rgba(0,0,0,0.2) 0 2px 8px}.ql-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-label{border-color:#ccc}.ql-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-options{border-color:#ccc}.ql-toolbar.ql-snow .ql-color-picker .ql-picker-item.ql-selected,.ql-toolbar.ql-snow .ql-color-picker .ql-picker-item:hover{border-color:#000}.ql-toolbar.ql-snow + .ql-container.ql-snow{border-top:0}.ql-snow .ql-tooltip{background-color:#fff;border:1px solid #ccc;box-shadow:0 0 5px #ddd;color:#444;padding:5px 12px;white-space:nowrap}.ql-snow .ql-tooltip::before{content:"Visit URL:";line-height:26px;margin-right:8px}.ql-snow .ql-tooltip input[type=text]{display:none;border:1px solid #ccc;font-size:13px;height:26px;margin:0;padding:3px 5px;width:170px}.ql-snow .ql-tooltip a.ql-preview{display:inline-block;max-width:200px;overflow-x:hidden;text-overflow:ellipsis;vertical-align:top}.ql-snow .ql-tooltip a.ql-action::after{border-right:1px solid #ccc;content:'Edit';margin-left:16px;padding-right:8px}.ql-snow .ql-tooltip a.ql-remove::before{content:'Remove';margin-left:8px}.ql-snow .ql-tooltip a{line-height:26px}.ql-snow .ql-tooltip.ql-editing a.ql-preview,.ql-snow .ql-tooltip.ql-editing a.ql-remove{display:none}.ql-snow .ql-tooltip.ql-editing input[type=text]{display:inline-block}.ql-snow .ql-tooltip.ql-editing a.ql-action::after{border-right:0;content:'Save';padding-right:0}.ql-snow .ql-tooltip[data-mode=link]::before{content:"Enter link:"}.ql-snow .ql-tooltip[data-mode=formula]::before{content:"Enter formula:"}.ql-snow .ql-tooltip[data-mode=video]::before{content:"Enter video:"}.ql-snow a{color:#06c}.ql-container.ql-snow{border:1px solid #ccc}
</style>
<?php endif; ?>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}

:root{
  --b: <?=$bc?>;
  --b-g: <?=$bc?>22;
  --b-s: <?=$bc?>10;
  --bg: #0e0e0e;
  --bg2: #171717;
  --bg3: #121212;
  --sf: #1e1e1e;
  --bd: #262626;
  --bd2: #333;
  --tx: #e5e0da;
  --tx2: #807a74;
  --tx3: #4a4540;
  --ok: #4ade80; --ok-b: #4ade800f;
  --er: #f87171; --er-b: #f871710f;
  --in: #60a5fa; --in-b: #60a5fa0f;
  --r: 10px;
  --r2: 7px;
}

body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--tx);min-height:100vh;-webkit-font-smoothing:antialiased}

@keyframes up{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* ───── LOGIN ───── */

.L{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;background:radial-gradient(ellipse at 30% 40%,<?=$bc?>0a,transparent 60%),var(--bg)}
.L-c{width:100%;max-width:340px;animation:up .45s ease-out}
.L-logo{display:block;height:42px;width:auto;margin-bottom:.15rem}
.L-c .sub{font-size:.8rem;color:var(--tx2);margin:.25rem 0 2rem}
.L-c .err{padding:.6rem .85rem;background:var(--er-b);border:1px solid #f8717118;border-radius:var(--r2);color:var(--er);font-size:.84rem;margin-bottom:1rem}
.L-i{width:100%;padding:.8rem 1rem;background:var(--bg2);border:1px solid var(--bd);border-radius:var(--r);color:var(--tx);font-family:inherit;font-size:.93rem;outline:0;transition:border .2s,box-shadow .2s}
.L-i:focus{border-color:var(--b);box-shadow:0 0 0 3px var(--b-g)}
.L-i::placeholder{color:var(--tx3)}
.L-b{width:100%;padding:.8rem;margin-top:.75rem;background:var(--b);color:#fff;border:0;border-radius:var(--r);font-family:inherit;font-size:.93rem;font-weight:600;cursor:pointer;transition:opacity .15s,transform .1s}
.L-b:hover{opacity:.88}
.L-b:active{transform:scale(.98)}
.L-help{margin-top:1rem;font-size:.78rem;color:var(--tx3);line-height:1.5}
.sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}

/* ───── DIAGNOSE (Einrichtung — Adressat ist die Betreuung) ───── */
.dg{margin:1rem 2rem 0;border:1px solid var(--bd);border-radius:var(--r2);background:var(--bg2);font-size:.82rem}
.dg summary{cursor:pointer;padding:.6rem .9rem;color:var(--tx2);user-select:none;list-style:none}
.dg summary::-webkit-details-marker{display:none}
.dg summary::before{content:'▸ ';color:var(--tx3)}
.dg[open] summary::before{content:'▾ '}
.dg summary:hover{color:var(--tx)}
.dg-in{padding:0 .9rem;color:var(--tx3);font-size:.78rem}
.dg-l{margin:.5rem 0 .3rem;padding:0 .9rem .7rem 2rem;color:var(--tx2);line-height:1.55}
.dg-l li{list-style:disc;margin-bottom:.35rem}

/* ───── APP SHELL ───── */

.A{display:flex;min-height:100vh}

/* Sidebar */
.S{width:250px;background:var(--bg2);border-right:1px solid var(--bd);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:20;transition:transform .3s ease}
.S-head{padding:1.3rem 1.3rem .25rem}
.S-logo{display:block;height:28px;width:auto}
.S-site{padding:0 1.3rem;font-size:.75rem;color:var(--tx2);margin-bottom:1.8rem}
.S-lbl{padding:0 1.3rem;font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--tx3);margin-bottom:.4rem}

.S-nav{list-style:none;flex:1;overflow-y:auto}
.S-nav a{display:flex;align-items:center;gap:.65rem;padding:.55rem 1.3rem;color:var(--tx2);text-decoration:none;font-size:.86rem;font-weight:500;border-left:2px solid transparent;transition:all .12s}
.S-nav a:hover{color:var(--tx);background:var(--b-s)}
.S-nav a.on{color:var(--b);background:var(--b-s);border-left-color:var(--b)}
.S-nav .ic{width:26px;height:26px;border-radius:6px;background:var(--sf);border:1px solid var(--bd);display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:600;color:var(--tx3);flex-shrink:0}
.S-nav a.on .ic{background:var(--b);border-color:var(--b);color:#fff}
.S-nav .cnt{font-size:.65rem;color:var(--tx3);margin-left:auto;background:var(--sf);padding:.1rem .4rem;border-radius:4px}

.S-ft{padding:.9rem 1.3rem;border-top:1px solid var(--bd);margin-top:auto;display:flex;flex-direction:column;gap:.45rem}
.S-ft a{font-size:.78rem;color:var(--tx3);text-decoration:none;transition:color .12s}
.S-ft a:hover{color:var(--tx2)}

/* Mobile hamburger */
.ham{display:none;position:fixed;top:.9rem;left:.9rem;z-index:30;width:40px;height:40px;background:var(--bg2);border:1px solid var(--bd);border-radius:var(--r2);cursor:pointer;flex-direction:column;align-items:center;justify-content:center;gap:4px}
.ham span{width:18px;height:2px;background:var(--tx2);border-radius:1px;transition:all .2s}
.ham.open span:nth-child(1){transform:rotate(45deg) translate(4px,4px)}
.ham.open span:nth-child(2){opacity:0}
.ham.open span:nth-child(3){transform:rotate(-45deg) translate(4px,-4px)}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:15}

/* Main */
.M{flex:1;margin-left:250px;min-height:100vh;display:flex;flex-direction:column}

.top{padding:1rem 2rem;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;background:var(--bg);position:sticky;top:0;z-index:5}
.top-t{font-size:1rem;font-weight:600}
.top-f{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.74rem;color:var(--tx3);background:var(--bg2);padding:.25rem .6rem;border-radius:5px;border:1px solid var(--bd)}

.cnt-wrap{flex:1;padding:1.8rem 2rem;max-width:740px}

/* Messages */
.ms{padding:.7rem .9rem;border-radius:var(--r2);font-size:.86rem;font-weight:500;margin-bottom:1.3rem;animation:up .3s ease-out}
.ms.success{background:var(--ok-b);color:var(--ok);border:1px solid #4ade8018}
.ms.error{background:var(--er-b);color:var(--er);border:1px solid #f8717118}
.ms.info{background:var(--in-b);color:var(--in);border:1px solid #60a5fa18}

/* Welcome */
.W{display:flex;align-items:center;justify-content:center;flex:1;padding:3rem 1.5rem}
.W-in{text-align:center;max-width:320px;animation:up .4s ease-out}
.W-ic{width:52px;height:52px;border-radius:13px;background:var(--b-s);border:1px solid <?=$bc?>18;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem}
.W-ic svg{width:22px;height:22px;stroke:var(--b);fill:none;stroke-width:1.5;stroke-linecap:round}
.W h2{font-size:1.08rem;font-weight:600;margin-bottom:.4rem}
.W p{font-size:.86rem;color:var(--tx2);line-height:1.6}

/* ───── FIELD CARDS ───── */

.fc{background:var(--bg2);border:1px solid var(--bd);border-radius:var(--r);padding:1.2rem 1.3rem;margin-bottom:.65rem;transition:border-color .2s,box-shadow .2s;animation:up .3s ease-out both}
.fc:nth-child(2){animation-delay:.03s}
.fc:nth-child(3){animation-delay:.06s}
.fc:nth-child(4){animation-delay:.09s}
.fc:nth-child(5){animation-delay:.12s}
.fc:nth-child(6){animation-delay:.15s}
.fc:nth-child(7){animation-delay:.18s}
.fc:nth-child(8){animation-delay:.21s}
.fc:focus-within{border-color:var(--b);box-shadow:0 0 0 3px var(--b-g)}

.fc-label{display:block;font-size:.92rem;font-weight:600;color:var(--tx);margin-bottom:.15rem}
label.fc-label{cursor:pointer}
/* Technische Metadaten (Feld-ID, Typ) gehören der Betreuung, nicht der Kundin —
   nur sichtbar, wenn die technische Ansicht eingeschaltet ist. */
.fc-meta{display:none;align-items:center;gap:.5rem;margin-bottom:.7rem}
body.tech .fc-meta{display:flex}
.fc-label{margin-bottom:.7rem}
body.tech .fc-label{margin-bottom:.15rem}
.fc-id{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.72rem;color:var(--tx3)}
.fc-badge{font-size:.6rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:.12rem .4rem;border-radius:3px;background:var(--sf);color:var(--tx3);border:1px solid var(--bd)}

.fi{width:100%;padding:.7rem .85rem;background:var(--bg3);border:1px solid var(--bd);border-radius:var(--r2);color:var(--tx);font-family:inherit;font-size:.9rem;outline:0;transition:border .2s}
.fi:focus{border-color:var(--b)}
.fi::placeholder{color:var(--tx3)}
textarea.fi{resize:vertical;min-height:85px;line-height:1.65}

/* Quill dark overrides */
.fc .ql-toolbar{background:var(--sf)!important;border-color:var(--bd)!important;border-radius:var(--r2) var(--r2) 0 0}
.fc .ql-toolbar .ql-stroke{stroke:var(--tx2)!important}
.fc .ql-toolbar .ql-fill{fill:var(--tx2)!important}
.fc .ql-toolbar .ql-picker-label{color:var(--tx2)!important}
.fc .ql-toolbar .ql-picker-options{background:var(--sf)!important;border-color:var(--bd)!important}
.fc .ql-toolbar .ql-picker-item{color:var(--tx2)!important}
.fc .ql-toolbar button:hover .ql-stroke,
.fc .ql-toolbar .ql-active .ql-stroke{stroke:var(--b)!important}
.fc .ql-toolbar button:hover .ql-fill,
.fc .ql-toolbar .ql-active .ql-fill{fill:var(--b)!important}
.fc .ql-container{background:var(--bg3)!important;border-color:var(--bd)!important;border-radius:0 0 var(--r2) var(--r2);color:var(--tx);font-family:system-ui,-apple-system,sans-serif;font-size:.9rem}
.fc .ql-editor{min-height:110px;line-height:1.7}
.fc .ql-editor.ql-blank::before{color:var(--tx3)!important;font-style:normal!important}
.fc .ql-editor a{color:var(--b)}
.img-field{display:flex;flex-direction:column;gap:8px}
.img-prev{max-width:220px;max-height:160px;width:auto;border-radius:6px;border:1px solid var(--bd);object-fit:cover;background:var(--b-s)}
.img-path{font-size:12px;opacity:.8;margin-top:6px}
.img-hint{font-size:12px;color:var(--tx3)}
.img-cur{margin:0}
.img-name{font-size:12px;color:var(--tx3);margin-top:5px}
.img-name span{color:var(--tx2);font-weight:500}
.img-drop{display:flex;align-items:center;justify-content:center;text-align:center;padding:1.1rem;border:1.5px dashed var(--bd);border-radius:var(--r2);color:var(--tx2);font-size:.84rem;cursor:pointer;background:var(--bg3);transition:border-color .15s,background .15s}
.img-drop:hover,.img-drop.drag{border-color:var(--b);background:var(--b-s);color:var(--tx)}
.img-adv{font-size:.8rem}
.img-adv summary{cursor:pointer;color:var(--tx3);font-size:.78rem;padding:.2rem 0;user-select:none}
.img-adv summary:hover{color:var(--b)}
.img-adv[open] summary{margin-bottom:6px}
.ms{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.ms-l{font-weight:600;color:inherit;text-decoration:underline;text-underline-offset:2px;white-space:nowrap}
.top-l{display:inline-flex;align-items:center;gap:.3rem;padding:.4rem .8rem;background:var(--b-s);border:1px solid <?=$bc?>30;border-radius:var(--r2);font-weight:600}
.top-l:hover{background:var(--b);color:#fff}
.sv-info{display:flex;flex-direction:column;gap:.2rem;min-width:0}
.sv-safe{font-size:.74rem;color:var(--tx3)}
.sv-dirty{font-size:.78rem;font-weight:600;color:var(--b)}
.sv-act{display:flex;align-items:center;flex:0 0 auto}
.sv-b.has-changes{box-shadow:0 0 0 3px <?=$bc?>40;animation:pulse 1.6s ease-in-out infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 3px <?=$bc?>30}50%{box-shadow:0 0 0 5px <?=$bc?>10}}
.ob{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin:1.2rem 2rem 0;padding:.9rem 1.1rem;background:var(--b-s);border:1px solid <?=$bc?>30;border-radius:var(--r2)}
.ob-t{display:block;font-size:.9rem;margin-bottom:.35rem}
.ob-steps{margin:0;padding-left:1.1rem;display:flex;gap:1.4rem;flex-wrap:wrap;font-size:.84rem;color:var(--tx2)}
.ob-steps li{list-style:decimal}
.ob-x{font:inherit;font-size:.8rem;font-weight:600;padding:.45rem 1rem;background:var(--b);color:#fff;border:0;border-radius:var(--r2);cursor:pointer;flex:0 0 auto}
.ob-x:hover{opacity:.9}
.top-tech{display:inline-flex;align-items:center;gap:.3rem;margin-left:.4rem;padding:.4rem .8rem;font:inherit;font-size:.74rem;font-weight:600;background:var(--bg2);color:var(--tx2);border:1px solid var(--bd);border-radius:var(--r2);cursor:pointer;opacity:.6}
.top-tech:hover{border-color:var(--b);color:var(--b)}
.top-tech[aria-pressed="true"]{opacity:1;border-color:var(--b);color:var(--b)}
/* ── Vorschau-Split (nur Desktop) ── */
.top-pv{display:none}
.ws{display:block}
.ws-edit{min-width:0}
.pv{display:none}
@media(min-width:1280px){
  .top-pv{display:inline-flex;align-items:center;gap:.3rem;margin-left:.7rem;padding:.4rem .8rem;font:inherit;font-size:.74rem;font-weight:600;background:var(--bg2);color:var(--tx2);border:1px solid var(--bd);border-radius:var(--r2);cursor:pointer}
  .top-pv:hover{border-color:var(--b);color:var(--b)}
  .top-pv[aria-pressed="false"]{opacity:.6}
  .ws{display:flex;align-items:flex-start;flex:1;min-height:0}
  .ws-edit{flex:1;min-width:0}
  .pv{display:block;position:sticky;top:56px;flex:0 0 480px;width:480px;height:calc(100vh - 56px);border-left:1px solid var(--bd);background:var(--bg2)}
  .pv-bar{height:26px;display:flex;align-items:center;padding:0 .8rem;font-size:.7rem;color:var(--tx3);border-bottom:1px solid var(--bd);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pv-frame{width:480px;height:calc(100vh - 56px - 27px);overflow:hidden;background:#fff}
  .pv-frame iframe{width:1280px;height:calc((100vh - 83px) / .375);border:0;transform:scale(.375);transform-origin:top left;background:#fff}
  .M.pv-off .ws{display:block}
  .M.pv-off .pv{display:none}
}
.blk{border:1px solid var(--bd);border-radius:8px;margin:18px 0;background:var(--b-s)}
.blk-head{font-weight:600;font-size:13px;padding:10px 14px;border-bottom:1px solid var(--bd);color:var(--tx)}
.blk-fields{padding:14px}
.blk-foot{display:flex;gap:8px;flex-wrap:wrap;padding:10px 14px;border-top:1px solid var(--bd)}
.blk-b{font:inherit;font-size:12px;padding:5px 10px;border:1px solid var(--bd);border-radius:5px;background:var(--sf);color:var(--tx);cursor:pointer}
.blk-b:hover{border-color:var(--b);color:var(--b)}
.blk-del:hover{border-color:#c0392b;color:#c0392b}
.blk-add-wrap{margin:-6px 0 18px}
.blk-add{font:inherit;font-size:13px;padding:8px 14px;border:1px dashed var(--bd);border-radius:6px;background:transparent;color:var(--tx2);cursor:pointer;width:100%}
.blk-add:hover{border-color:var(--b);color:var(--b)}
.top-m{font-size:.74rem;color:var(--tx3);margin-left:auto}
.top-l{font-size:.74rem;color:var(--b);text-decoration:none;margin-left:1rem}
.top-l:hover{text-decoration:underline}
.rst-b{font:inherit;font-size:.82rem;padding:.55rem 1rem;background:transparent;color:var(--tx2);border:1px solid var(--bd);border-radius:var(--r2);cursor:pointer;margin-right:.6rem}
.rst-b:hover{border-color:var(--b);color:var(--b)}
.tgl-sec{border:1px solid var(--bd);border-radius:8px;margin:0 0 18px;background:var(--b-s)}
.tgl-sec-h{font-weight:600;font-size:13px;padding:10px 14px;border-bottom:1px solid var(--bd)}
.tgl{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--bd)}
.tgl:last-child{border-bottom:0}
.tgl-dot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}
.tgl.is-on .tgl-dot{background:#1f9d55}
.tgl.is-off .tgl-dot{background:#9aa0a6}
.tgl-name{font-weight:600;font-size:13px}
.tgl-state{font-size:12px;color:var(--tx3)}
.tgl-b{font:inherit;font-size:12px;padding:5px 12px;margin-left:auto;border:1px solid var(--bd);border-radius:5px;background:var(--sf);color:var(--tx);cursor:pointer}
.tgl-b:hover{border-color:var(--b);color:var(--b)}
.fc .ql-snow .ql-tooltip{background:var(--sf);border-color:var(--bd);color:var(--tx);box-shadow:0 4px 12px rgba(0,0,0,.3)}
.fc .ql-snow .ql-tooltip input[type=text]{background:var(--bg3);border-color:var(--bd);color:var(--tx)}

/* ───── SAVE BAR ───── */

.sv{position:sticky;bottom:0;background:var(--bg2);border-top:1px solid var(--bd);padding:.9rem 2rem;display:flex;justify-content:space-between;align-items:center}
.sv-h{font-size:.78rem;color:var(--tx3)}
.sv-b{padding:.65rem 1.8rem;background:var(--b);color:#fff;border:0;border-radius:var(--r2);font-family:inherit;font-size:.9rem;font-weight:600;cursor:pointer;transition:opacity .15s,transform .1s}
.sv-b:hover{opacity:.88}
.sv-b:active{transform:scale(.97)}

/* Footer */
.ft{padding:1.3rem 2rem;font-size:.7rem;color:var(--tx3)}
.ft a{color:var(--b);text-decoration:none}

/* ───── MOBILE ───── */

@media(max-width:768px){
  .ham{display:flex}
  .S{transform:translateX(-100%)}
  .S.open{transform:translateX(0)}
  .overlay.open{display:block}
  .M{margin-left:0}
  .top{padding:.9rem 1.2rem;padding-left:3.5rem}
  .cnt-wrap{padding:1.3rem 1.2rem}
  .sv{padding:.8rem 1.2rem}
  .fc{padding:1rem 1.1rem}
  .ft{padding:1rem 1.2rem}
}

@media(max-width:400px){
  .top-f{display:none}
  .sv-h{display:none}
}

/* ═══ DASHBOARD: LIGHT MODE ═══ */
body.dash{
  --bg:  #f0f2f5;
  --bg2: #ffffff;
  --bg3: #f5f6f7;
  --sf:  #ebebeb;
  --bd:  #dde1e7;
  --bd2: #ccc;
  --tx:  #1a1a1a;
  --tx2: #555555;
  --tx3: #999999;
}

/* Sidebar: #333 vibe */
body.dash .S{background:#333333;border-right:1px solid #222}
body.dash .S-site{color:#aaa}
body.dash .S-lbl{color:#777}
body.dash .S-nav a{color:#bbb}
body.dash .S-nav a:hover{color:#f0f0f0;background:rgba(255,255,255,.07)}
body.dash .S-nav a.on{color:var(--b);background:rgba(255,255,255,.1);border-left-color:var(--b)}
body.dash .S-nav .ic{background:#444;border-color:#555;color:#999}
body.dash .S-nav a.on .ic{background:var(--b);border-color:var(--b);color:#fff}
body.dash .S-nav .cnt{background:#444;color:#999}
body.dash .S-ft{border-top-color:#444}
body.dash .S-ft a{color:#888}
body.dash .S-ft a:hover{color:#ccc}

/* Mobile: hamburger auf weißem Grund */
body.dash .ham{background:#fff;border-color:#dde1e7}
body.dash .ham span{background:#555}

/* Quill: Dark-Overrides für Light-Dashboard zurücksetzen */
body.dash .fc .ql-toolbar{background:#f5f5f5!important;border-color:#ddd!important}
body.dash .fc .ql-toolbar .ql-stroke{stroke:#555!important}
body.dash .fc .ql-toolbar .ql-fill{fill:#555!important}
body.dash .fc .ql-toolbar .ql-picker-label{color:#555!important}
body.dash .fc .ql-toolbar .ql-picker-options{background:#fff!important;border-color:#ddd!important}
body.dash .fc .ql-toolbar .ql-picker-item{color:#333!important}
body.dash .fc .ql-container{background:#fff!important;border-color:#ddd!important;color:#1a1a1a}
body.dash .fc .ql-editor.ql-blank::before{color:#999!important}
body.dash .fc .ql-snow .ql-tooltip{background:#fff;border-color:#ddd;color:#333;box-shadow:0 4px 12px rgba(0,0,0,.1)}
body.dash .fc .ql-snow .ql-tooltip input[type=text]{background:#f5f5f5;border-color:#ddd;color:#333}
</style>
</head>
<body<?= $auth ? ' class="dash"' : '' ?>>

<?php if (!$auth): ?>
<!-- ═══════ LOGIN ═══════ -->
<div class="L">
  <div class="L-c">
    <?php if ($logo): ?>
    <img src="<?=htmlspecialchars($logo)?>" alt="<?=htmlspecialchars($sn)?>" class="L-logo">
    <?php else: ?>
    <img src="<?=$PESI_LOGO?>" alt="pesi cms" class="L-logo">
    <?php endif; ?>
    <p class="sub"><?=htmlspecialchars($sn)?></p>
    <?php if ($loginError): ?><div class="err"><?=$t['wrong_password']?></div><?php endif; ?>
    <form method="POST">
      <input type="hidden" name="pesi_login" value="1">
      <input type="hidden" name="pesi_csrf" value="<?=htmlspecialchars($csrf)?>">
      <label for="pesi_pw" class="sr"><?=htmlspecialchars($t['password_ph'])?></label>
      <input type="password" id="pesi_pw" name="pesi_password" class="L-i" placeholder="<?=$t['password_ph']?>" autocomplete="current-password" autofocus required>
      <button type="submit" class="L-b"><?=$t['login_btn']?></button>
    </form>
    <p class="L-help"><?=htmlspecialchars($t['login_help'])?></p>
  </div>
</div>

<?php else: ?>
<!-- ═══════ DASHBOARD ═══════ -->

<button class="ham" id="ham" onclick="toggleNav()">
  <span></span><span></span><span></span>
</button>
<div class="overlay" id="overlay" onclick="toggleNav()"></div>

<div class="A">
  <nav class="S" id="sidebar">
    <div class="S-head">
      <?php if ($logo): ?>
      <img src="<?=htmlspecialchars($logo)?>" alt="<?=htmlspecialchars($sn)?>" class="S-logo">
      <?php else: ?>
      <img src="<?=$PESI_LOGO?>" alt="pesi cms" class="S-logo">
      <?php endif; ?>
    </div>
    <div class="S-site"><?=htmlspecialchars($sn)?></div>
    <div class="S-lbl"><?=$t['nav_pages']?></div>
    <ul class="S-nav">
      <?php foreach ($PESI_PAGES as $file => $label):
        $isActive = $page === $file;
        // Count fields for this page
        $pf = $basePath . '/' . $file;
        $fc = file_exists($pf) ? count(_pesi_parse($pf)) : 0;
      ?>
        <li>
          <a href="?page=<?=urlencode($file)?>" class="<?=$isActive?'on':''?>" <?php if(true): ?>onclick="closeMobileNav()"<?php endif; ?>>
            <span class="ic"><?=mb_strtoupper(mb_substr($label,0,1))?></span>
            <?=htmlspecialchars($label)?>
            <?php if ($fc > 0): ?><span class="cnt"><?=$fc?></span><?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="S-ft">
      <a href="/" target="_blank" rel="noopener noreferrer"><?=$t['to_website']?></a>
      <a href="?logout"><?=$t['logout']?></a>
    </div>
  </nav>

  <div class="M">
    <?php
      // ── Diagnose ──────────────────────────────────────────────
      // Einrichtungsprobleme betreffen die Betreuung, nicht die Kundin. Ein
      // roter Vollbreiten-Alarm mit einer Anweisung, die sie gar nicht
      // ausführen kann ("bitte in pesi-core.php ändern"), erzeugt nur Angst
      // und einen Anruf. Darum eine ruhige, zugeklappte Zeile, die ihr sagt,
      // dass für sie nichts zu tun ist — die Details stehen einen Klick tief
      // für die Person, die sie beheben kann.
      //
      // Probe gegen eine garantiert gültige Datei: schlägt sie fehl, ist der
      // Linter nicht nutzbar (exec gesperrt ODER kein PHP-CLI im PATH). Nur
      // auf exec() zu prüfen hätte den zweiten, häufigeren Fall verschwiegen.
      $diag = [];
      if (in_array((string)PESI_PASSWORD, ['demo123', 'demo1234'], true)) {
          $diag[] = $t['warn_default_pw'];
      }
      if (PESI_SYNTAX_CHECK && _pesi_lint($corePath) === null) {
          $diag[] = $t['warn_no_exec'];
      }
      if ($diag):
    ?>
      <details class="dg">
        <summary><?=htmlspecialchars($t['diag_summary'])?></summary>
        <p class="dg-in"><?=htmlspecialchars($t['diag_intro'])?></p>
        <ul class="dg-l">
          <?php foreach ($diag as $d): ?><li><?=htmlspecialchars($d)?></li><?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
    <div class="ob" id="ob" hidden>
      <div class="ob-in">
        <strong class="ob-t"><?=htmlspecialchars($t['ob_title'])?></strong>
        <ol class="ob-steps">
          <li><?=htmlspecialchars($t['ob_step1'])?></li>
          <li><?=htmlspecialchars($t['ob_step2'])?></li>
          <li><?=htmlspecialchars($t['ob_step3'])?></li>
        </ol>
      </div>
      <button type="button" class="ob-x" id="obX"><?=htmlspecialchars($t['ob_dismiss'])?></button>
    </div>
    <?php if ($page): ?>
      <?php
        $pageAbs = $basePath . '/' . $page;
        $mtime   = is_file($pageAbs) ? filemtime($pageAbs) : 0;
        $liveUrl = '/' . ltrim($page, '/');
      ?>
      <div class="top">
        <span class="top-t"><?=htmlspecialchars($pageLabel)?></span>
        <span class="top-f"><?=htmlspecialchars($page)?></span>
        <?php if ($mtime): ?><span class="top-m"><?=sprintf($t['last_mod'], date('d.m.Y H:i', $mtime))?></span><?php endif; ?>
        <a class="top-l" href="<?=htmlspecialchars($liveUrl)?>" target="_blank" rel="noopener noreferrer"><?=$t['view_live']?></a>
        <button type="button" class="top-pv" id="pvToggle" aria-pressed="true" title="<?=htmlspecialchars($t['pv_btn'])?>"><?=htmlspecialchars($t['pv_btn'])?></button>
        <button type="button" class="top-tech" id="techToggle" aria-pressed="false" title="<?=htmlspecialchars($t['tech_btn'])?>"><?=htmlspecialchars($t['tech_btn'])?></button>
      </div>

      <div class="ws" id="ws">
      <form method="POST" id="pf" enctype="multipart/form-data" class="ws-edit">
        <input type="hidden" name="pesi_save" value="1">
        <input type="hidden" name="pesi_csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="pesi_mtime" value="<?=htmlspecialchars((string)$mtime)?>">

        <div class="cnt-wrap">
          <?php if ($msg): ?><div class="ms <?=$msgType?>"><span><?=htmlspecialchars($msg)?></span><?php if ($msgType === 'success'): ?><a class="ms-l" href="<?=htmlspecialchars($liveUrl)?>" target="_blank" rel="noopener noreferrer"><?=htmlspecialchars($t['saved_view'])?></a><?php endif; ?></div><?php endif; ?>

          <?php $toggles = _pesi_toggle_parse($pageAbs); $tglNested = $toggles && _pesi_toggle_nested($pageAbs); ?>
          <?php if ($toggles): ?>
            <div class="tgl-sec">
              <div class="tgl-sec-h"><?=$t['tgl_section']?></div>
              <?php if ($tglNested): ?>
                <div class="ms error" style="margin:10px 14px"><span><?=htmlspecialchars($t['tgl_nested'])?></span></div>
              <?php endif; ?>
              <?php foreach ($toggles as $g => $vis): ?>
                <div class="tgl <?=$vis?'is-on':'is-off'?>">
                  <span class="tgl-dot"></span>
                  <span class="tgl-name"><?=htmlspecialchars(_pesi_human($g))?></span>
                  <span class="tgl-state"><?=$vis?$t['tgl_visible']:$t['tgl_hidden']?></span>
                  <button class="tgl-b" formnovalidate name="pesi_toggle" value="<?=htmlspecialchars($g)?>"<?=$tglNested?' disabled':''?>><?=$vis?htmlspecialchars($t['tgl_hide']):htmlspecialchars($t['tgl_show'])?></button>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (empty($fields) && empty($toggles)): ?>
            <div class="W">
              <div class="W-in">
                <p style="color:var(--tx2)"><?=htmlspecialchars($t['no_fields'])?></p>
              </div>
            </div>
          <?php else: ?>
            <?php
              // Blöcke der Seite einlesen und Felder ihren Block-Instanzen zuordnen
              $blocks  = _pesi_block_parse($basePath . '/' . $page);
              $blockOf = [];   // Feld-ID  → "group:inst"
              $blkMeta = [];   // "group:inst" → block
              $grpLast = [];   // group → letzte Instanz (Datei-Reihenfolge)
              foreach ($blocks as $b) {
                  $key = $b['group'] . ':' . $b['inst'];
                  $blkMeta[$key] = $b;
                  $grpLast[$b['group']] = $key;
                  $pre = $b['group'] . '_' . $b['inst'] . '_';
                  foreach ($fields as $fid => $_f) {
                      if (strncmp($fid, $pre, strlen($pre)) === 0) $blockOf[$fid] = $key;
                  }
              }
              $closeBlk = function (string $key) use ($blkMeta, $grpLast, $t) {
                  $b = $blkMeta[$key];
                  echo '</div><div class="blk-foot">';
                  printf('<button class="blk-b" formnovalidate name="pesi_block" value="up:%1$s:%2$d">%3$s</button>', $b['group'], $b['inst'], $t['blk_up']);
                  printf('<button class="blk-b" formnovalidate name="pesi_block" value="down:%1$s:%2$d">%3$s</button>', $b['group'], $b['inst'], $t['blk_down']);
                  printf('<button class="blk-b" formnovalidate name="pesi_block" value="dup:%1$s:%2$d">%3$s</button>', $b['group'], $b['inst'], htmlspecialchars($t['blk_dup']));
                  printf('<button class="blk-b blk-del" formnovalidate name="pesi_block" value="del:%1$s:%2$d" data-confirm="%3$s">%4$s</button>',
                      $b['group'], $b['inst'], htmlspecialchars($t['blk_confirm_del'], ENT_QUOTES), htmlspecialchars($t['blk_del']));
                  echo '</div></div>';
                  if (($grpLast[$b['group']] ?? null) === $key) {
                      printf('<div class="blk-add-wrap"><button class="blk-add" formnovalidate name="pesi_block" value="add:%1$s">%2$s</button></div>',
                          $b['group'], htmlspecialchars($t['blk_add']));
                  }
              };
              $curBlk = null;
            ?>
            <?php foreach ($fields as $id => $fld):
              $label = !empty($fld['label']) ? $fld['label'] : $id;
              $bk = $blockOf[$id] ?? null;
              if ($bk !== $curBlk):
                if ($curBlk !== null) $closeBlk($curBlk);
                if ($bk !== null):
                  $bm = $blkMeta[$bk];
            ?>
              <div class="blk">
                <div class="blk-head"><?=htmlspecialchars(_pesi_human($bm['group']))?> · <?=$t['blk_entry']?> <?=$bm['inst']?></div>
                <div class="blk-fields">
            <?php endif; $curBlk = $bk; endif; ?>
              <?php $fid = 'f_' . preg_replace('/[^A-Za-z0-9_]/', '', $id); ?>
              <div class="fc">
                <?php if ($fld['type'] === 'richtext'): ?>
                  <div class="fc-label"><?=htmlspecialchars($label)?></div>
                <?php else: ?>
                  <label class="fc-label" for="<?=$fid?>"><?=htmlspecialchars($label)?></label>
                <?php endif; ?>
                <div class="fc-meta">
                  <span class="fc-id"><?=htmlspecialchars($id)?></span>
                  <span class="fc-badge"><?=htmlspecialchars($fld['type'])?></span>
                </div>

                <?php if ($fld['type'] === 'text'): ?>
                  <input type="text" id="<?=$fid?>" name="pesi_field_<?=htmlspecialchars($id)?>" value="<?=htmlspecialchars($fld['value'])?>" class="fi">

                <?php elseif ($fld['type'] === 'textarea'): ?>
                  <textarea id="<?=$fid?>" name="pesi_field_<?=htmlspecialchars($id)?>" class="fi" rows="4"><?=htmlspecialchars($fld['value'])?></textarea>

                <?php elseif ($fld['type'] === 'image'): ?>
                  <?php $hasImg = trim($fld['value']) !== ''; ?>
                  <div class="img-field" data-img>
                    <figure class="img-cur"<?=$hasImg?'':' hidden'?> data-img-fig>
                      <img class="img-prev" src="<?=$hasImg?htmlspecialchars($fld['value']):''?>" alt="" data-img-prev>
                      <figcaption class="img-name"><?=htmlspecialchars($t['img_current'])?> <span data-img-name><?=$hasImg?htmlspecialchars(basename($fld['value'])):''?></span></figcaption>
                    </figure>
                    <label class="img-drop" data-img-drop>
                      <input type="file" id="<?=$fid?>" name="pesi_upload_<?=htmlspecialchars($id)?>" accept="image/png,image/jpeg,image/webp,image/avif,image/gif" data-img-input hidden>
                      <span><?=htmlspecialchars($t['img_drop'])?></span>
                    </label>
                    <details class="img-adv">
                      <summary><?=htmlspecialchars($t['img_advanced'])?></summary>
                      <input type="text" name="pesi_field_<?=htmlspecialchars($id)?>" value="<?=htmlspecialchars($fld['value'])?>" class="fi img-path" placeholder="/uploads/…">
                      <span class="img-hint"><?=$t['img_hint']?></span>
                    </details>
                  </div>

                <?php elseif ($fld['type'] === 'richtext'): ?>
                  <div id="q_<?=htmlspecialchars($id)?>"><?=_pesi_sanitize_html($fld['value'])?></div>
                  <textarea name="pesi_field_<?=htmlspecialchars($id)?>" id="h_<?=htmlspecialchars($id)?>" style="display:none"><?=htmlspecialchars($fld['value'])?></textarea>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if ($curBlk !== null) $closeBlk($curBlk); ?>
          <?php endif; ?>
        </div>

        <?php if (!empty($fields) || !empty($toggles)): ?>
        <div class="sv">
          <span class="sv-info">
            <span class="sv-dirty" id="svDirty" hidden></span>
            <span class="sv-h"><?=htmlspecialchars($t['save_hint'])?></span>
          </span>
          <span class="sv-act">
          <?php if (is_file($pageAbs . '.pesi-backup.1')): ?>
          <button type="submit" class="rst-b" formnovalidate name="pesi_restore" value="1" data-confirm="<?=htmlspecialchars($t['rst_confirm'])?>"><?=htmlspecialchars($t['rst_btn'])?></button>
          <?php endif; ?>
          <?php if (!empty($fields)): ?><button type="submit" class="sv-b"><?=$t['save_btn']?></button><?php endif; ?>
          </span>
        </div>
        <?php endif; ?>
      </form>
      <aside class="pv" id="pv" aria-label="<?=htmlspecialchars($t['pv_title'])?>">
        <div class="pv-bar"><?=htmlspecialchars($t['pv_label'])?></div>
        <div class="pv-frame">
          <iframe title="<?=htmlspecialchars($t['pv_title'])?>" src="<?=htmlspecialchars($liveUrl . (strpos($liveUrl, '?') !== false ? '&' : '?') . 'pesi_pv=' . $mtime)?>" loading="lazy"></iframe>
        </div>
      </aside>
      </div>

    <?php else: ?>
      <div class="W">
        <div class="W-in">
          <div class="W-ic"><svg viewBox="0 0 24 24"><path d="M3 12h18M12 3v18"/></svg></div>
          <h2><?=$t['welcome_title']?></h2>
          <p><?=$t['welcome_hint']?></p>
        </div>
      </div>
    <?php endif; ?>

    <div class="ft">pesi CMS · <a href="https://ludescher.studio" target="_blank" rel="noopener noreferrer">ludescher.studio</a></div>
  </div>
</div>

<script>
function toggleNav(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('open');
  document.getElementById('ham').classList.toggle('open');
}
function closeMobileNav(){
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('open');
  document.getElementById('ham').classList.remove('open');
}
// ── Onboarding (clientseitig, einmalig) ──
(function(){
  var ob=document.getElementById('ob'); if(!ob) return;
  try{ if(localStorage.getItem('pesi_ob_done')==='1') return; }catch(e){}
  ob.hidden=false;
  var x=document.getElementById('obX');
  if(x) x.addEventListener('click',function(){
    ob.hidden=true;
    try{ localStorage.setItem('pesi_ob_done','1'); }catch(e){}
  });
})();

// ── Vorschau-Panel ein/aus (Zustand merken) ──
(function(){
  var btn=document.getElementById('pvToggle'),
      M=document.querySelector('.M');
  if(!btn||!M) return;
  var off=false;
  try{ off=localStorage.getItem('pesi_pv_off')==='1'; }catch(e){}
  function apply(){
    M.classList.toggle('pv-off',off);
    btn.setAttribute('aria-pressed', off?'false':'true');
  }
  apply();
  btn.addEventListener('click',function(){
    off=!off;
    try{ localStorage.setItem('pesi_pv_off', off?'1':'0'); }catch(e){}
    apply();
  });
})();

// ── Technische Ansicht (Feld-IDs + Typen) ein/aus ──
// Standard: aus. Die Kundin sieht nur Beschriftung und Eingabefeld; wer
// Feld-IDs braucht, schaltet sie einmal ein und der Zustand bleibt.
(function(){
  var btn=document.getElementById('techToggle');
  if(!btn) return;
  var on=false;
  try{ on=localStorage.getItem('pesi_tech')==='1'; }catch(e){}
  function apply(){
    document.body.classList.toggle('tech',on);
    btn.setAttribute('aria-pressed', on?'true':'false');
  }
  apply();
  btn.addEventListener('click',function(){
    on=!on;
    try{ localStorage.setItem('pesi_tech', on?'1':'0'); }catch(e){}
    apply();
  });
})();

// ── Bild-Felder: Vorschau + Drag&Drop ──
(function(){
  document.querySelectorAll('[data-img]').forEach(function(w){
    var inp=w.querySelector('[data-img-input]'),
        drop=w.querySelector('[data-img-drop]'),
        fig=w.querySelector('[data-img-fig]'),
        pv=w.querySelector('[data-img-prev]'),
        nm=w.querySelector('[data-img-name]');
    if(!inp) return;
    function show(file){
      if(!file) return;
      var url=URL.createObjectURL(file);
      if(pv) pv.src=url;
      if(nm) nm.textContent=file.name;
      if(fig) fig.hidden=false;
    }
    inp.addEventListener('change',function(){ if(inp.files&&inp.files[0]) show(inp.files[0]); });
    if(drop){
      ['dragenter','dragover'].forEach(function(ev){
        drop.addEventListener(ev,function(e){ e.preventDefault(); drop.classList.add('drag'); });
      });
      ['dragleave','drop'].forEach(function(ev){
        drop.addEventListener(ev,function(e){ e.preventDefault(); drop.classList.remove('drag'); });
      });
      drop.addEventListener('drop',function(e){
        var f=e.dataTransfer&&e.dataTransfer.files;
        if(f&&f.length){ try{ inp.files=f; }catch(_){ } show(f[0]); }
      });
    }
  });
})();

// ── Speichern: ungespeicherte Änderungen zählen + warnen ──
(function(){
  var form=document.getElementById('pf'); if(!form) return;
  var leaving=false;
  var DISCARD=<?=json_encode($t['unsaved_warn'], JSON_UNESCAPED_UNICODE)?>;
  var D1=<?=json_encode($t['dirty_one'], JSON_UNESCAPED_UNICODE)?>;
  var DN=<?=json_encode($t['dirty_many'], JSON_UNESCAPED_UNICODE)?>;
  var badge=document.getElementById('svDirty');
  var saveBtn=form.querySelector('.sv-b');
  var snap=new Map();
  form.querySelectorAll('input,textarea,select').forEach(function(el){
    snap.set(el, el.type==='file' ? 0 : el.value);
  });
  var rtDirty=new Set();
  window.pesiRtDirty=function(id){ rtDirty.add(id); refresh(); };
  function count(){
    var n=0;
    snap.forEach(function(init,el){
      if(el.type==='file'){ if(el.files&&el.files.length) n++; }
      else if(el.value!==init){ n++; }
    });
    return n+rtDirty.size;
  }
  function refresh(){
    var n=count();
    if(badge){
      if(n>0){ badge.hidden=false; badge.textContent=(n===1?D1:DN).replace('%d',n); }
      else { badge.hidden=true; }
    }
    if(saveBtn) saveBtn.classList.toggle('has-changes',n>0);
  }
  document.addEventListener('input',refresh);
  document.addEventListener('change',refresh);
  form.addEventListener('submit',function(){leaving=true;});
  form.querySelectorAll('button[name="pesi_block"],button[name="pesi_toggle"],button[name="pesi_restore"]').forEach(function(b){
    b.addEventListener('click',function(e){
      var c=b.getAttribute('data-confirm');
      if(c && !confirm(c)){e.preventDefault();leaving=false;return;}
      if(count()>0 && !confirm(DISCARD)){e.preventDefault();leaving=false;}
    });
  });
  window.addEventListener('beforeunload',function(e){
    if(count()>0 && !leaving){e.preventDefault();e.returnValue='';}
  });
})();
</script>

<?php if ($hasRt): ?>
<script>
!function(t,e){"object"==typeof exports&&"object"==typeof module?module.exports=e():"function"==typeof define&&define.amd?define([],e):"object"==typeof exports?exports.Quill=e():t.Quill=e()}(self,(function(){return function(){var t={9698:function(t,e,n){"use strict";n.d(e,{Ay:function(){return c},Ji:function(){return d},mG:function(){return h},zo:function(){return u}});var r=n(6003),i=n(5232),s=n.n(i),o=n(3036),l=n(4850),a=n(5508);class c extends r.BlockBlot{cache={};delta(){return null==this.cache.delta&&(this.cache.delta=h(this)),this.cache.delta}deleteAt(t,e){super.deleteAt(t,e),this.cache={}}formatAt(t,e,n,i){e<=0||(this.scroll.query(n,r.Scope.BLOCK)?t+e===this.length()&&this.format(n,i):super.formatAt(t,Math.min(e,this.length()-t-1),n,i),this.cache={})}insertAt(t,e,n){if(null!=n)return super.insertAt(t,e,n),void(this.cache={});if(0===e.length)return;const r=e.split("\n"),i=r.shift();i.length>0&&(t<this.length()-1||null==this.children.tail?super.insertAt(Math.min(t,this.length()-1),i):this.children.tail.insertAt(this.children.tail.length(),i),this.cache={});let s=this;r.reduce(((t,e)=>(s=s.split(t,!0),s.insertAt(0,e),e.length)),t+i.length)}insertBefore(t,e){const{head:n}=this.children;super.insertBefore(t,e),n instanceof o.A&&n.remove(),this.cache={}}length(){return null==this.cache.length&&(this.cache.length=super.length()+1),this.cache.length}moveChildren(t,e){super.moveChildren(t,e),this.cache={}}optimize(t){super.optimize(t),this.cache={}}path(t){return super.path(t,!0)}removeChild(t){super.removeChild(t),this.cache={}}split(t){let e=arguments.length>1&&void 0!==arguments[1]&&arguments[1];if(e&&(0===t||t>=this.length()-1)){const e=this.clone();return 0===t?(this.parent.insertBefore(e,this),this):(this.parent.insertBefore(e,this.next),e)}const n=super.split(t,e);return this.cache={},n}}c.blotName="block",c.tagName="P",c.defaultChild=o.A,c.allowedChildren=[o.A,l.A,r.EmbedBlot,a.A];class u extends r.EmbedBlot{attach(){super.attach(),this.attributes=new r.AttributorStore(this.domNode)}delta(){return(new(s())).insert(this.value(),{...this.formats(),...this.attributes.values()})}format(t,e){const n=this.scroll.query(t,r.Scope.BLOCK_ATTRIBUTE);null!=n&&this.attributes.attribute(n,e)}formatAt(t,e,n,r){this.format(n,r)}insertAt(t,e,n){if(null!=n)return void super.insertAt(t,e,n);const r=e.split("\n"),i=r.pop(),s=r.map((t=>{const e=this.scroll.create(c.blotName);return e.insertAt(0,t),e})),o=this.split(t);s.forEach((t=>{this.parent.insertBefore(t,o)})),i&&this.parent.insertBefore(this.scroll.create("text",i),o)}}function h(t){let e=!(arguments.length>1&&void 0!==arguments[1])||arguments[1];return t.descendants(r.LeafBlot).reduce(((t,n)=>0===n.length()?t:t.insert(n.value(),d(n,{},e))),new(s())).insert("\n",d(t))}function d(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:{},n=!(arguments.length>2&&void 0!==arguments[2])||arguments[2];return null==t?e:("formats"in t&&"function"==typeof t.formats&&(e={...e,...t.formats()},n&&delete e["code-token"]),null==t.parent||"scroll"===t.parent.statics.blotName||t.parent.statics.scope!==t.statics.scope?e:d(t.parent,e,n))}u.scope=r.Scope.BLOCK_BLOT},3036:function(t,e,n){"use strict";var r=n(6003);class i extends r.EmbedBlot{static value(){}optimize(){(this.prev||this.next)&&this.remove()}length(){return 0}value(){return""}}i.blotName="break",i.tagName="BR",e.A=i},580:function(t,e,n){"use strict";var r=n(6003);class i extends r.ContainerBlot{}e.A=i},4541:function(t,e,n){"use strict";var r=n(6003),i=n(5508);class s extends r.EmbedBlot{static blotName="cursor";static className="ql-cursor";static tagName="span";static CONTENTS="\ufeff";static value(){}constructor(t,e,n){super(t,e),this.selection=n,this.textNode=document.createTextNode(s.CONTENTS),this.domNode.appendChild(this.textNode),this.savedLength=0}detach(){null!=this.parent&&this.parent.removeChild(this)}format(t,e){if(0!==this.savedLength)return void super.format(t,e);let n=this,i=0;for(;null!=n&&n.statics.scope!==r.Scope.BLOCK_BLOT;)i+=n.offset(n.parent),n=n.parent;null!=n&&(this.savedLength=s.CONTENTS.length,n.optimize(),n.formatAt(i,s.CONTENTS.length,t,e),this.savedLength=0)}index(t,e){return t===this.textNode?0:super.index(t,e)}length(){return this.savedLength}position(){return[this.textNode,this.textNode.data.length]}remove(){super.remove(),this.parent=null}restore(){if(this.selection.composing||null==this.parent)return null;const t=this.selection.getNativeRange();for(;null!=this.domNode.lastChild&&this.domNode.lastChild!==this.textNode;)this.domNode.parentNode.insertBefore(this.domNode.lastChild,this.domNode);const e=this.prev instanceof i.A?this.prev:null,n=e?e.length():0,r=this.next instanceof i.A?this.next:null,o=r?r.text:"",{textNode:l}=this,a=l.data.split(s.CONTENTS).join("");let c;if(l.data=s.CONTENTS,e)c=e,(a||r)&&(e.insertAt(e.length(),a+o),r&&r.remove());else if(r)c=r,r.insertAt(0,a);else{const t=document.createTextNode(a);c=this.scroll.create(t),this.parent.insertBefore(c,this)}if(this.remove(),t){const i=(t,i)=>e&&t===e.domNode?i:t===l?n+i-1:r&&t===r.domNode?n+a.length+i:null,s=i(t.start.node,t.start.offset),o=i(t.end.node,t.end.offset);if(null!==s&&null!==o)return{startNode:c.domNode,startOffset:s,endNode:c.domNode,endOffset:o}}return null}update(t,e){if(t.some((t=>"characterData"===t.type&&t.target===this.textNode))){const t=this.restore();t&&(e.range=t)}}optimize(t){super.optimize(t);let{parent:e}=this;for(;e;){if("A"===e.domNode.tagName){this.savedLength=s.CONTENTS.length,e.isolate(this.offset(e),this.length()).unwrap(),this.savedLength=0;break}e=e.parent}}value(){return""}}e.A=s},746:function(t,e,n){"use strict";var r=n(6003),i=n(5508);const s="\ufeff";class o extends r.EmbedBlot{constructor(t,e){super(t,e),this.contentNode=document.createElement("span"),this.contentNode.setAttribute("contenteditable","false"),Array.from(this.domNode.childNodes).forEach((t=>{this.contentNode.appendChild(t)})),this.leftGuard=document.createTextNode(s),this.rightGuard=document.createTextNode(s),this.domNode.appendChild(this.leftGuard),this.domNode.appendChild(this.contentNode),this.domNode.appendChild(this.rightGuard)}index(t,e){return t===this.leftGuard?0:t===this.rightGuard?1:super.index(t,e)}restore(t){let e,n=null;const r=t.data.split(s).join("");if(t===this.leftGuard)if(this.prev instanceof i.A){const t=this.prev.length();this.prev.insertAt(t,r),n={startNode:this.prev.domNode,startOffset:t+r.length}}else e=document.createTextNode(r),this.parent.insertBefore(this.scroll.create(e),this),n={startNode:e,startOffset:r.length};else t===this.rightGuard&&(this.next instanceof i.A?(this.next.insertAt(0,r),n={startNode:this.next.domNode,startOffset:r.length}):(e=document.createTextNode(r),this.parent.insertBefore(this.scroll.create(e),this.next),n={startNode:e,startOffset:r.length}));return t.data=s,n}update(t,e){t.forEach((t=>{if("characterData"===t.type&&(t.target===this.leftGuard||t.target===this.rightGuard)){const n=this.restore(t.target);n&&(e.range=n)}}))}}e.A=o},4850:function(t,e,n){"use strict";var r=n(6003),i=n(3036),s=n(5508);class o extends r.InlineBlot{static allowedChildren=[o,i.A,r.EmbedBlot,s.A];static order=["cursor","inline","link","underline","strike","italic","bold","script","code"];static compare(t,e){const n=o.order.indexOf(t),r=o.order.indexOf(e);return n>=0||r>=0?n-r:t===e?0:t<e?-1:1}formatAt(t,e,n,i){if(o.compare(this.statics.blotName,n)<0&&this.scroll.query(n,r.Scope.BLOT)){const r=this.isolate(t,e);i&&r.wrap(n,i)}else super.formatAt(t,e,n,i)}optimize(t){if(super.optimize(t),this.parent instanceof o&&o.compare(this.statics.blotName,this.parent.statics.blotName)>0){const t=this.parent.isolate(this.offset(),this.length());this.moveChildren(t),t.wrap(this)}}}e.A=o},5508:function(t,e,n){"use strict";n.d(e,{A:function(){return i},X:function(){return o}});var r=n(6003);class i extends r.TextBlot{}const s={"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"};function o(t){return t.replace(/[&<>"']/g,(t=>s[t]))}},3729:function(t,e,n){"use strict";n.d(e,{default:function(){return R}});var r=n(6142),i=n(9698),s=n(3036),o=n(580),l=n(4541),a=n(746),c=n(4850),u=n(6003),h=n(5232),d=n.n(h),f=n(5374);function p(t){return t instanceof i.Ay||t instanceof i.zo}function g(t){return"function"==typeof t.updateContent}class m extends u.ScrollBlot{static blotName="scroll";static className="ql-editor";static tagName="DIV";static defaultChild=i.Ay;static allowedChildren=[i.Ay,i.zo,o.A];constructor(t,e,n){let{emitter:r}=n;super(t,e),this.emitter=r,this.batch=!1,this.optimize(),this.enable(),this.domNode.addEventListener("dragstart",(t=>this.handleDragStart(t)))}batchStart(){Array.isArray(this.batch)||(this.batch=[])}batchEnd(){if(!this.batch)return;const t=this.batch;this.batch=!1,this.update(t)}emitMount(t){this.emitter.emit(f.A.events.SCROLL_BLOT_MOUNT,t)}emitUnmount(t){this.emitter.emit(f.A.events.SCROLL_BLOT_UNMOUNT,t)}emitEmbedUpdate(t,e){this.emitter.emit(f.A.events.SCROLL_EMBED_UPDATE,t,e)}deleteAt(t,e){const[n,r]=this.line(t),[o]=this.line(t+e);if(super.deleteAt(t,e),null!=o&&n!==o&&r>0){if(n instanceof i.zo||o instanceof i.zo)return void this.optimize();const t=o.children.head instanceof s.A?null:o.children.head;n.moveChildren(o,t),n.remove()}this.optimize()}enable(){let t=!(arguments.length>0&&void 0!==arguments[0])||arguments[0];this.domNode.setAttribute("contenteditable",t?"true":"false")}formatAt(t,e,n,r){super.formatAt(t,e,n,r),this.optimize()}insertAt(t,e,n){if(t>=this.length())if(null==n||null==this.scroll.query(e,u.Scope.BLOCK)){const t=this.scroll.create(this.statics.defaultChild.blotName);this.appendChild(t),null==n&&e.endsWith("\n")?t.insertAt(0,e.slice(0,-1),n):t.insertAt(0,e,n)}else{const t=this.scroll.create(e,n);this.appendChild(t)}else super.insertAt(t,e,n);this.optimize()}insertBefore(t,e){if(t.statics.scope===u.Scope.INLINE_BLOT){const n=this.scroll.create(this.statics.defaultChild.blotName);n.appendChild(t),super.insertBefore(n,e)}else super.insertBefore(t,e)}insertContents(t,e){const n=this.deltaToRenderBlocks(e.concat((new(d())).insert("\n"))),r=n.pop();if(null==r)return;this.batchStart();const s=n.shift();if(s){const e="block"===s.type&&(0===s.delta.length()||!this.descendant(i.zo,t)[0]&&t<this.length()),n="block"===s.type?s.delta:(new(d())).insert({[s.key]:s.value});b(this,t,n);const r="block"===s.type?1:0,o=t+n.length()+r;e&&this.insertAt(o-1,"\n");const l=(0,i.Ji)(this.line(t)[0]),a=h.AttributeMap.diff(l,s.attributes)||{};Object.keys(a).forEach((t=>{this.formatAt(o-1,1,t,a[t])})),t=o}let[o,l]=this.children.find(t);n.length&&(o&&(o=o.split(l),l=0),n.forEach((t=>{if("block"===t.type)b(this.createBlock(t.attributes,o||void 0),0,t.delta);else{const e=this.create(t.key,t.value);this.insertBefore(e,o||void 0),Object.keys(t.attributes).forEach((n=>{e.format(n,t.attributes[n])}))}}))),"block"===r.type&&r.delta.length()&&b(this,o?o.offset(o.scroll)+l:this.length(),r.delta),this.batchEnd(),this.optimize()}isEnabled(){return"true"===this.domNode.getAttribute("contenteditable")}leaf(t){const e=this.path(t).pop();if(!e)return[null,-1];const[n,r]=e;return n instanceof u.LeafBlot?[n,r]:[null,-1]}line(t){return t===this.length()?this.line(t-1):this.descendant(p,t)}lines(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:0,e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:Number.MAX_VALUE;const n=(t,e,r)=>{let i=[],s=r;return t.children.forEachAt(e,r,((t,e,r)=>{p(t)?i.push(t):t instanceof u.ContainerBlot&&(i=i.concat(n(t,e,s))),s-=r})),i};return n(this,t,e)}optimize(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:[],e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:{};this.batch||(super.optimize(t,e),t.length>0&&this.emitter.emit(f.A.events.SCROLL_OPTIMIZE,t,e))}path(t){return super.path(t).slice(1)}remove(){}update(t){if(this.batch)return void(Array.isArray(t)&&(this.batch=this.batch.concat(t)));let e=f.A.sources.USER;"string"==typeof t&&(e=t),Array.isArray(t)||(t=this.observer.takeRecords()),(t=t.filter((t=>{let{target:e}=t;const n=this.find(e,!0);return n&&!g(n)}))).length>0&&this.emitter.emit(f.A.events.SCROLL_BEFORE_UPDATE,e,t),super.update(t.concat([])),t.length>0&&this.emitter.emit(f.A.events.SCROLL_UPDATE,e,t)}updateEmbedAt(t,e,n){const[r]=this.descendant((t=>t instanceof i.zo),t);r&&r.statics.blotName===e&&g(r)&&r.updateContent(n)}handleDragStart(t){t.preventDefault()}deltaToRenderBlocks(t){const e=[];let n=new(d());return t.forEach((t=>{const r=t?.insert;if(r)if("string"==typeof r){const i=r.split("\n");i.slice(0,-1).forEach((r=>{n.insert(r,t.attributes),e.push({type:"block",delta:n,attributes:t.attributes??{}}),n=new(d())}));const s=i[i.length-1];s&&n.insert(s,t.attributes)}else{const i=Object.keys(r)[0];if(!i)return;this.query(i,u.Scope.INLINE)?n.push(t):(n.length()&&e.push({type:"block",delta:n,attributes:{}}),n=new(d()),e.push({type:"blockEmbed",key:i,value:r[i],attributes:t.attributes??{}}))}})),n.length()&&e.push({type:"block",delta:n,attributes:{}}),e}createBlock(t,e){let n;const r={};Object.entries(t).forEach((t=>{let[e,i]=t;null!=this.query(e,u.Scope.BLOCK&u.Scope.BLOT)?n=e:r[e]=i}));const i=this.create(n||this.statics.defaultChild.blotName,n?t[n]:void 0);this.insertBefore(i,e||void 0);const s=i.length();return Object.entries(r).forEach((t=>{let[e,n]=t;i.formatAt(0,s,e,n)})),i}}function b(t,e,n){n.reduce(((e,n)=>{const r=h.Op.length(n);let s=n.attributes||{};if(null!=n.insert)if("string"==typeof n.insert){const r=n.insert;t.insertAt(e,r);const[o]=t.descendant(u.LeafBlot,e),l=(0,i.Ji)(o);s=h.AttributeMap.diff(l,s)||{}}else if("object"==typeof n.insert){const r=Object.keys(n.insert)[0];if(null==r)return e;if(t.insertAt(e,r,n.insert[r]),null!=t.scroll.query(r,u.Scope.INLINE)){const[n]=t.descendant(u.LeafBlot,e),r=(0,i.Ji)(n);s=h.AttributeMap.diff(r,s)||{}}}return Object.keys(s).forEach((n=>{t.formatAt(e,r,n,s[n])})),e+r}),e)}var y=m,v=n(5508),A=n(584),x=n(4266);class N extends x.A{static DEFAULTS={delay:1e3,maxStack:100,userOnly:!1};lastRecorded=0;ignoreChange=!1;stack={undo:[],redo:[]};currentRange=null;constructor(t,e){super(t,e),this.quill.on(r.Ay.events.EDITOR_CHANGE,((t,e,n,i)=>{t===r.Ay.events.SELECTION_CHANGE?e&&i!==r.Ay.sources.SILENT&&(this.currentRange=e):t===r.Ay.events.TEXT_CHANGE&&(this.ignoreChange||(this.options.userOnly&&i!==r.Ay.sources.USER?this.transform(e):this.record(e,n)),this.currentRange=w(this.currentRange,e))})),this.quill.keyboard.addBinding({key:"z",shortKey:!0},this.undo.bind(this)),this.quill.keyboard.addBinding({key:["z","Z"],shortKey:!0,shiftKey:!0},this.redo.bind(this)),/Win/i.test(navigator.platform)&&this.quill.keyboard.addBinding({key:"y",shortKey:!0},this.redo.bind(this)),this.quill.root.addEventListener("beforeinput",(t=>{"historyUndo"===t.inputType?(this.undo(),t.preventDefault()):"historyRedo"===t.inputType&&(this.redo(),t.preventDefault())}))}change(t,e){if(0===this.stack[t].length)return;const n=this.stack[t].pop();if(!n)return;const i=this.quill.getContents(),s=n.delta.invert(i);this.stack[e].push({delta:s,range:w(n.range,s)}),this.lastRecorded=0,this.ignoreChange=!0,this.quill.updateContents(n.delta,r.Ay.sources.USER),this.ignoreChange=!1,this.restoreSelection(n)}clear(){this.stack={undo:[],redo:[]}}cutoff(){this.lastRecorded=0}record(t,e){if(0===t.ops.length)return;this.stack.redo=[];let n=t.invert(e),r=this.currentRange;const i=Date.now();if(this.lastRecorded+this.options.delay>i&&this.stack.undo.length>0){const t=this.stack.undo.pop();t&&(n=n.compose(t.delta),r=t.range)}else this.lastRecorded=i;0!==n.length()&&(this.stack.undo.push({delta:n,range:r}),this.stack.undo.length>this.options.maxStack&&this.stack.undo.shift())}redo(){this.change("redo","undo")}transform(t){E(this.stack.undo,t),E(this.stack.redo,t)}undo(){this.change("undo","redo")}restoreSelection(t){if(t.range)this.quill.setSelection(t.range,r.Ay.sources.USER);else{const e=function(t,e){const n=e.reduce(((t,e)=>t+(e.delete||0)),0);let r=e.length()-n;return function(t,e){const n=e.ops[e.ops.length-1];return null!=n&&(null!=n.insert?"string"==typeof n.insert&&n.insert.endsWith("\n"):null!=n.attributes&&Object.keys(n.attributes).some((e=>null!=t.query(e,u.Scope.BLOCK))))}(t,e)&&(r-=1),r}(this.quill.scroll,t.delta);this.quill.setSelection(e,r.Ay.sources.USER)}}}function E(t,e){let n=e;for(let e=t.length-1;e>=0;e-=1){const r=t[e];t[e]={delta:n.transform(r.delta,!0),range:r.range&&w(r.range,n)},n=r.delta.transform(n),0===t[e].delta.length()&&t.splice(e,1)}}function w(t,e){if(!t)return t;const n=e.transformPosition(t.index);return{index:n,length:e.transformPosition(t.index+t.length)-n}}var q=n(8123);class k extends x.A{constructor(t,e){super(t,e),t.root.addEventListener("drop",(e=>{e.preventDefault();let n=null;if(document.caretRangeFromPoint)n=document.caretRangeFromPoint(e.clientX,e.clientY);else if(document.caretPositionFromPoint){const t=document.caretPositionFromPoint(e.clientX,e.clientY);n=document.createRange(),n.setStart(t.offsetNode,t.offset),n.setEnd(t.offsetNode,t.offset)}const r=n&&t.selection.normalizeNative(n);if(r){const n=t.selection.normalizedToRange(r);e.dataTransfer?.files&&this.upload(n,e.dataTransfer.files)}}))}upload(t,e){const n=[];Array.from(e).forEach((t=>{t&&this.options.mimetypes?.includes(t.type)&&n.push(t)})),n.length>0&&this.options.handler.call(this,t,n)}}k.DEFAULTS={mimetypes:["image/png","image/jpeg"],handler(t,e){if(!this.quill.scroll.query("image"))return;const n=e.map((t=>new Promise((e=>{const n=new FileReader;n.onload=()=>{e(n.result)},n.readAsDataURL(t)}))));Promise.all(n).then((e=>{const n=e.reduce(((t,e)=>t.insert({image:e})),(new(d())).retain(t.index).delete(t.length));this.quill.updateContents(n,f.A.sources.USER),this.quill.setSelection(t.index+e.length,f.A.sources.SILENT)}))}};var _=k;const L=["insertText","insertReplacementText"];class S extends x.A{constructor(t,e){super(t,e),t.root.addEventListener("beforeinput",(t=>{this.handleBeforeInput(t)})),/Android/i.test(navigator.userAgent)||t.on(r.Ay.events.COMPOSITION_BEFORE_START,(()=>{this.handleCompositionStart()}))}deleteRange(t){(0,q.Xo)({range:t,quill:this.quill})}replaceText(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:"";if(0===t.length)return!1;if(e){const n=this.quill.getFormat(t.index,1);this.deleteRange(t),this.quill.updateContents((new(d())).retain(t.index).insert(e,n),r.Ay.sources.USER)}else this.deleteRange(t);return this.quill.setSelection(t.index+e.length,0,r.Ay.sources.SILENT),!0}handleBeforeInput(t){if(this.quill.composition.isComposing||t.defaultPrevented||!L.includes(t.inputType))return;const e=t.getTargetRanges?t.getTargetRanges()[0]:null;if(!e||!0===e.collapsed)return;const n=function(t){return"string"==typeof t.data?t.data:t.dataTransfer?.types.includes("text/plain")?t.dataTransfer.getData("text/plain"):null}(t);if(null==n)return;const r=this.quill.selection.normalizeNative(e),i=r?this.quill.selection.normalizedToRange(r):null;i&&this.replaceText(i,n)&&t.preventDefault()}handleCompositionStart(){const t=this.quill.getSelection();t&&this.replaceText(t)}}var O=S;const T=/Mac/i.test(navigator.platform);class j extends x.A{isListening=!1;selectionChangeDeadline=0;constructor(t,e){super(t,e),this.handleArrowKeys(),this.handleNavigationShortcuts()}handleArrowKeys(){this.quill.keyboard.addBinding({key:["ArrowLeft","ArrowRight"],offset:0,shiftKey:null,handler(t,e){let{line:n,event:i}=e;if(!(n instanceof u.ParentBlot&&n.uiNode))return!0;const s="rtl"===getComputedStyle(n.domNode).direction;return!!(s&&"ArrowRight"!==i.key||!s&&"ArrowLeft"!==i.key)||(this.quill.setSelection(t.index-1,t.length+(i.shiftKey?1:0),r.Ay.sources.USER),!1)}})}handleNavigationShortcuts(){this.quill.root.addEventListener("keydown",(t=>{!t.defaultPrevented&&(t=>"ArrowLeft"===t.key||"ArrowRight"===t.key||"ArrowUp"===t.key||"ArrowDown"===t.key||"Home"===t.key||!(!T||"a"!==t.key||!0!==t.ctrlKey))(t)&&this.ensureListeningToSelectionChange()}))}ensureListeningToSelectionChange(){this.selectionChangeDeadline=Date.now()+100,this.isListening||(this.isListening=!0,document.addEventListener("selectionchange",(()=>{this.isListening=!1,Date.now()<=this.selectionChangeDeadline&&this.handleSelectionChange()}),{once:!0}))}handleSelectionChange(){const t=document.getSelection();if(!t)return;const e=t.getRangeAt(0);if(!0!==e.collapsed||0!==e.startOffset)return;const n=this.quill.scroll.find(e.startContainer);if(!(n instanceof u.ParentBlot&&n.uiNode))return;const r=document.createRange();r.setStartAfter(n.uiNode),r.setEndAfter(n.uiNode),t.removeAllRanges(),t.addRange(r)}}var C=j;r.Ay.register({"blots/block":i.Ay,"blots/block/embed":i.zo,"blots/break":s.A,"blots/container":o.A,"blots/cursor":l.A,"blots/embed":a.A,"blots/inline":c.A,"blots/scroll":y,"blots/text":v.A,"modules/clipboard":A.Ay,"modules/history":N,"modules/keyboard":q.Ay,"modules/uploader":_,"modules/input":O,"modules/uiNode":C});var R=r.Ay},5374:function(t,e,n){"use strict";n.d(e,{A:function(){return o}});var r=n(8920),i=n(7356);const s=(0,n(6078).A)("quill:events");["selectionchange","mousedown","mouseup","click"].forEach((t=>{document.addEventListener(t,(function(){for(var t=arguments.length,e=new Array(t),n=0;n<t;n++)e[n]=arguments[n];Array.from(document.querySelectorAll(".ql-container")).forEach((t=>{const n=i.A.get(t);n&&n.emitter&&n.emitter.handleDOM(...e)}))}))}));var o=class extends r{static events={EDITOR_CHANGE:"editor-change",SCROLL_BEFORE_UPDATE:"scroll-before-update",SCROLL_BLOT_MOUNT:"scroll-blot-mount",SCROLL_BLOT_UNMOUNT:"scroll-blot-unmount",SCROLL_OPTIMIZE:"scroll-optimize",SCROLL_UPDATE:"scroll-update",SCROLL_EMBED_UPDATE:"scroll-embed-update",SELECTION_CHANGE:"selection-change",TEXT_CHANGE:"text-change",COMPOSITION_BEFORE_START:"composition-before-start",COMPOSITION_START:"composition-start",COMPOSITION_BEFORE_END:"composition-before-end",COMPOSITION_END:"composition-end"};static sources={API:"api",SILENT:"silent",USER:"user"};constructor(){super(),this.domListeners={},this.on("error",s.error)}emit(){for(var t=arguments.length,e=new Array(t),n=0;n<t;n++)e[n]=arguments[n];return s.log.call(s,...e),super.emit(...e)}handleDOM(t){for(var e=arguments.length,n=new Array(e>1?e-1:0),r=1;r<e;r++)n[r-1]=arguments[r];(this.domListeners[t.type]||[]).forEach((e=>{let{node:r,handler:i}=e;(t.target===r||r.contains(t.target))&&i(t,...n)}))}listenDOM(t,e,n){this.domListeners[t]||(this.domListeners[t]=[]),this.domListeners[t].push({node:e,handler:n})}}},7356:function(t,e){"use strict";e.A=new WeakMap},6078:function(t,e){"use strict";const n=["error","warn","log","info"];let r="warn";function i(t){if(r&&n.indexOf(t)<=n.indexOf(r)){for(var e=arguments.length,i=new Array(e>1?e-1:0),s=1;s<e;s++)i[s-1]=arguments[s];console[t](...i)}}function s(t){return n.reduce(((e,n)=>(e[n]=i.bind(console,n,t),e)),{})}s.level=t=>{r=t},i.level=s.level,e.A=s},4266:function(t,e){"use strict";e.A=class{static DEFAULTS={};constructor(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:{};this.quill=t,this.options=e}}},6142:function(t,e,n){"use strict";n.d(e,{Ay:function(){return I}});var r=n(8347),i=n(6003),s=n(5232),o=n.n(s),l=n(3707),a=n(5123),c=n(9698),u=n(3036),h=n(4541),d=n(5508),f=n(8298);const p=/^[ -~]*$/;function g(t,e,n){if(0===t.length){const[t]=y(n.pop());return e<=0?`</li></${t}>`:`</li></${t}>${g([],e-1,n)}`}const[{child:r,offset:i,length:s,indent:o,type:l},...a]=t,[c,u]=y(l);if(o>e)return n.push(l),o===e+1?`<${c}><li${u}>${m(r,i,s)}${g(a,o,n)}`:`<${c}><li>${g(t,e+1,n)}`;const h=n[n.length-1];if(o===e&&l===h)return`</li><li${u}>${m(r,i,s)}${g(a,o,n)}`;const[d]=y(n.pop());return`</li></${d}>${g(t,e-1,n)}`}function m(t,e,n){let r=arguments.length>3&&void 0!==arguments[3]&&arguments[3];if("html"in t&&"function"==typeof t.html)return t.html(e,n);if(t instanceof d.A)return(0,d.X)(t.value().slice(e,e+n)).replaceAll(" ","&nbsp;");if(t instanceof i.ParentBlot){if("list-container"===t.statics.blotName){const r=[];return t.children.forEachAt(e,n,((t,e,n)=>{const i="formats"in t&&"function"==typeof t.formats?t.formats():{};r.push({child:t,offset:e,length:n,indent:i.indent||0,type:i.list})})),g(r,-1,[])}const i=[];if(t.children.forEachAt(e,n,((t,e,n)=>{i.push(m(t,e,n))})),r||"list"===t.statics.blotName)return i.join("");const{outerHTML:s,innerHTML:o}=t.domNode,[l,a]=s.split(`>${o}<`);return"<table"===l?`<table style="border: 1px solid #000;">${i.join("")}<${a}`:`${l}>${i.join("")}<${a}`}return t.domNode instanceof Element?t.domNode.outerHTML:""}function b(t,e){return Object.keys(e).reduce(((n,r)=>{if(null==t[r])return n;const i=e[r];return i===t[r]?n[r]=i:Array.isArray(i)?i.indexOf(t[r])<0?n[r]=i.concat([t[r]]):n[r]=i:n[r]=[i,t[r]],n}),{})}function y(t){const e="ordered"===t?"ol":"ul";switch(t){case"checked":return[e,' data-list="checked"'];case"unchecked":return[e,' data-list="unchecked"'];default:return[e,""]}}function v(t){return t.reduce(((t,e)=>{if("string"==typeof e.insert){const n=e.insert.replace(/\r\n/g,"\n").replace(/\r/g,"\n");return t.insert(n,e.attributes)}return t.push(e)}),new(o()))}function A(t,e){let{index:n,length:r}=t;return new f.Q(n+e,r)}var x=class{constructor(t){this.scroll=t,this.delta=this.getDelta()}applyDelta(t){this.scroll.update();let e=this.scroll.length();this.scroll.batchStart();const n=v(t),l=new(o());return function(t){const e=[];return t.forEach((t=>{"string"==typeof t.insert?t.insert.split("\n").forEach(((n,r)=>{r&&e.push({insert:"\n",attributes:t.attributes}),n&&e.push({insert:n,attributes:t.attributes})})):e.push(t)})),e}(n.ops.slice()).reduce(((t,n)=>{const o=s.Op.length(n);let a=n.attributes||{},u=!1,h=!1;if(null!=n.insert){if(l.retain(o),"string"==typeof n.insert){const o=n.insert;h=!o.endsWith("\n")&&(e<=t||!!this.scroll.descendant(c.zo,t)[0]),this.scroll.insertAt(t,o);const[l,u]=this.scroll.line(t);let d=(0,r.A)({},(0,c.Ji)(l));if(l instanceof c.Ay){const[t]=l.descendant(i.LeafBlot,u);t&&(d=(0,r.A)(d,(0,c.Ji)(t)))}a=s.AttributeMap.diff(d,a)||{}}else if("object"==typeof n.insert){const o=Object.keys(n.insert)[0];if(null==o)return t;const l=null!=this.scroll.query(o,i.Scope.INLINE);if(l)(e<=t||this.scroll.descendant(c.zo,t)[0])&&(h=!0);else if(t>0){const[e,n]=this.scroll.descendant(i.LeafBlot,t-1);e instanceof d.A?"\n"!==e.value()[n]&&(u=!0):e instanceof i.EmbedBlot&&e.statics.scope===i.Scope.INLINE_BLOT&&(u=!0)}if(this.scroll.insertAt(t,o,n.insert[o]),l){const[e]=this.scroll.descendant(i.LeafBlot,t);if(e){const t=(0,r.A)({},(0,c.Ji)(e));a=s.AttributeMap.diff(t,a)||{}}}}e+=o}else if(l.push(n),null!==n.retain&&"object"==typeof n.retain){const e=Object.keys(n.retain)[0];if(null==e)return t;this.scroll.updateEmbedAt(t,e,n.retain[e])}Object.keys(a).forEach((e=>{this.scroll.formatAt(t,o,e,a[e])}));const f=u?1:0,p=h?1:0;return e+=f+p,l.retain(f),l.delete(p),t+o+f+p}),0),l.reduce(((t,e)=>"number"==typeof e.delete?(this.scroll.deleteAt(t,e.delete),t):t+s.Op.length(e)),0),this.scroll.batchEnd(),this.scroll.optimize(),this.update(n)}deleteText(t,e){return this.scroll.deleteAt(t,e),this.update((new(o())).retain(t).delete(e))}formatLine(t,e){let n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:{};this.scroll.update(),Object.keys(n).forEach((r=>{this.scroll.lines(t,Math.max(e,1)).forEach((t=>{t.format(r,n[r])}))})),this.scroll.optimize();const r=(new(o())).retain(t).retain(e,(0,l.A)(n));return this.update(r)}formatText(t,e){let n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:{};Object.keys(n).forEach((r=>{this.scroll.formatAt(t,e,r,n[r])}));const r=(new(o())).retain(t).retain(e,(0,l.A)(n));return this.update(r)}getContents(t,e){return this.delta.slice(t,t+e)}getDelta(){return this.scroll.lines().reduce(((t,e)=>t.concat(e.delta())),new(o()))}getFormat(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:0,n=[],r=[];0===e?this.scroll.path(t).forEach((t=>{const[e]=t;e instanceof c.Ay?n.push(e):e instanceof i.LeafBlot&&r.push(e)})):(n=this.scroll.lines(t,e),r=this.scroll.descendants(i.LeafBlot,t,e));const[s,o]=[n,r].map((t=>{const e=t.shift();if(null==e)return{};let n=(0,c.Ji)(e);for(;Object.keys(n).length>0;){const e=t.shift();if(null==e)return n;n=b((0,c.Ji)(e),n)}return n}));return{...s,...o}}getHTML(t,e){const[n,r]=this.scroll.line(t);if(n){const i=n.length();return n.length()>=r+e&&(0!==r||e!==i)?m(n,r,e,!0):m(this.scroll,t,e,!0)}return""}getText(t,e){return this.getContents(t,e).filter((t=>"string"==typeof t.insert)).map((t=>t.insert)).join("")}insertContents(t,e){const n=v(e),r=(new(o())).retain(t).concat(n);return this.scroll.insertContents(t,n),this.update(r)}insertEmbed(t,e,n){return this.scroll.insertAt(t,e,n),this.update((new(o())).retain(t).insert({[e]:n}))}insertText(t,e){let n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:{};return e=e.replace(/\r\n/g,"\n").replace(/\r/g,"\n"),this.scroll.insertAt(t,e),Object.keys(n).forEach((r=>{this.scroll.formatAt(t,e.length,r,n[r])})),this.update((new(o())).retain(t).insert(e,(0,l.A)(n)))}isBlank(){if(0===this.scroll.children.length)return!0;if(this.scroll.children.length>1)return!1;const t=this.scroll.children.head;if(t?.statics.blotName!==c.Ay.blotName)return!1;const e=t;return!(e.children.length>1)&&e.children.head instanceof u.A}removeFormat(t,e){const n=this.getText(t,e),[r,i]=this.scroll.line(t+e);let s=0,l=new(o());null!=r&&(s=r.length()-i,l=r.delta().slice(i,i+s-1).insert("\n"));const a=this.getContents(t,e+s).diff((new(o())).insert(n).concat(l)),c=(new(o())).retain(t).concat(a);return this.applyDelta(c)}update(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:[],n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:void 0;const r=this.delta;if(1===e.length&&"characterData"===e[0].type&&e[0].target.data.match(p)&&this.scroll.find(e[0].target)){const i=this.scroll.find(e[0].target),s=(0,c.Ji)(i),l=i.offset(this.scroll),a=e[0].oldValue.replace(h.A.CONTENTS,""),u=(new(o())).insert(a),d=(new(o())).insert(i.value()),f=n&&{oldRange:A(n.oldRange,-l),newRange:A(n.newRange,-l)};t=(new(o())).retain(l).concat(u.diff(d,f)).reduce(((t,e)=>e.insert?t.insert(e.insert,s):t.push(e)),new(o())),this.delta=r.compose(t)}else this.delta=this.getDelta(),t&&(0,a.A)(r.compose(t),this.delta)||(t=r.diff(this.delta,n));return t}},N=n(5374),E=n(7356),w=n(6078),q=n(4266),k=n(746),_=class{isComposing=!1;constructor(t,e){this.scroll=t,this.emitter=e,this.setupListeners()}setupListeners(){this.scroll.domNode.addEventListener("compositionstart",(t=>{this.isComposing||this.handleCompositionStart(t)})),this.scroll.domNode.addEventListener("compositionend",(t=>{this.isComposing&&queueMicrotask((()=>{this.handleCompositionEnd(t)}))}))}handleCompositionStart(t){const e=t.target instanceof Node?this.scroll.find(t.target,!0):null;!e||e instanceof k.A||(this.emitter.emit(N.A.events.COMPOSITION_BEFORE_START,t),this.scroll.batchStart(),this.emitter.emit(N.A.events.COMPOSITION_START,t),this.isComposing=!0)}handleCompositionEnd(t){this.emitter.emit(N.A.events.COMPOSITION_BEFORE_END,t),this.scroll.batchEnd(),this.emitter.emit(N.A.events.COMPOSITION_END,t),this.isComposing=!1}},L=n(9609);const S=t=>{const e=t.getBoundingClientRect(),n="offsetWidth"in t&&Math.abs(e.width)/t.offsetWidth||1,r="offsetHeight"in t&&Math.abs(e.height)/t.offsetHeight||1;return{top:e.top,right:e.left+t.clientWidth*n,bottom:e.top+t.clientHeight*r,left:e.left}},O=t=>{const e=parseInt(t,10);return Number.isNaN(e)?0:e},T=(t,e,n,r,i,s)=>t<n&&e>r?0:t<n?-(n-t+i):e>r?e-t>r-n?t+i-n:e-r+s:0;const j=["block","break","cursor","inline","scroll","text"];const C=(0,w.A)("quill"),R=new i.Registry;i.ParentBlot.uiClass="ql-ui";class I{static DEFAULTS={bounds:null,modules:{clipboard:!0,keyboard:!0,history:!0,uploader:!0},placeholder:"",readOnly:!1,registry:R,theme:"default"};static events=N.A.events;static sources=N.A.sources;static version="2.0.3";static imports={delta:o(),parchment:i,"core/module":q.A,"core/theme":L.A};static debug(t){!0===t&&(t="log"),w.A.level(t)}static find(t){let e=arguments.length>1&&void 0!==arguments[1]&&arguments[1];return E.A.get(t)||R.find(t,e)}static import(t){return null==this.imports[t]&&C.error(`Cannot import ${t}. Are you sure it was registered?`),this.imports[t]}static register(){if("string"!=typeof(arguments.length<=0?void 0:arguments[0])){const t=arguments.length<=0?void 0:arguments[0],e=!!(arguments.length<=1?void 0:arguments[1]),n="attrName"in t?t.attrName:t.blotName;"string"==typeof n?this.register(`formats/${n}`,t,e):Object.keys(t).forEach((n=>{this.register(n,t[n],e)}))}else{const t=arguments.length<=0?void 0:arguments[0],e=arguments.length<=1?void 0:arguments[1],n=!!(arguments.length<=2?void 0:arguments[2]);null==this.imports[t]||n||C.warn(`Overwriting ${t} with`,e),this.imports[t]=e,(t.startsWith("blots/")||t.startsWith("formats/"))&&e&&"boolean"!=typeof e&&"abstract"!==e.blotName&&R.register(e),"function"==typeof e.register&&e.register(R)}}constructor(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:{};if(this.options=function(t,e){const n=B(t);if(!n)throw new Error("Invalid Quill container");const s=!e.theme||e.theme===I.DEFAULTS.theme?L.A:I.import(`themes/${e.theme}`);if(!s)throw new Error(`Invalid theme ${e.theme}. Did you register it?`);const{modules:o,...l}=I.DEFAULTS,{modules:a,...c}=s.DEFAULTS;let u=M(e.modules);null!=u&&u.toolbar&&u.toolbar.constructor!==Object&&(u={...u,toolbar:{container:u.toolbar}});const h=(0,r.A)({},M(o),M(a),u),d={...l,...U(c),...U(e)};let f=e.registry;return f?e.formats&&C.warn('Ignoring "formats" option because "registry" is specified'):f=e.formats?((t,e,n)=>{const r=new i.Registry;return j.forEach((t=>{const n=e.query(t);n&&r.register(n)})),t.forEach((t=>{let i=e.query(t);i||n.error(`Cannot register "${t}" specified in "formats" config. Are you sure it was registered?`);let s=0;for(;i;)if(r.register(i),i="blotName"in i?i.requiredContainer??null:null,s+=1,s>100){n.error(`Cycle detected in registering blot requiredContainer: "${t}"`);break}})),r})(e.formats,d.registry,C):d.registry,{...d,registry:f,container:n,theme:s,modules:Object.entries(h).reduce(((t,e)=>{let[n,i]=e;if(!i)return t;const s=I.import(`modules/${n}`);return null==s?(C.error(`Cannot load ${n} module. Are you sure you registered it?`),t):{...t,[n]:(0,r.A)({},s.DEFAULTS||{},i)}}),{}),bounds:B(d.bounds)}}(t,e),this.container=this.options.container,null==this.container)return void C.error("Invalid Quill container",t);this.options.debug&&I.debug(this.options.debug);const n=this.container.innerHTML.trim();this.container.classList.add("ql-container"),this.container.innerHTML="",E.A.set(this.container,this),this.root=this.addContainer("ql-editor"),this.root.classList.add("ql-blank"),this.emitter=new N.A;const s=i.ScrollBlot.blotName,l=this.options.registry.query(s);if(!l||!("blotName"in l))throw new Error(`Cannot initialize Quill without "${s}" blot`);if(this.scroll=new l(this.options.registry,this.root,{emitter:this.emitter}),this.editor=new x(this.scroll),this.selection=new f.A(this.scroll,this.emitter),this.composition=new _(this.scroll,this.emitter),this.theme=new this.options.theme(this,this.options),this.keyboard=this.theme.addModule("keyboard"),this.clipboard=this.theme.addModule("clipboard"),this.history=this.theme.addModule("history"),this.uploader=this.theme.addModule("uploader"),this.theme.addModule("input"),this.theme.addModule("uiNode"),this.theme.init(),this.emitter.on(N.A.events.EDITOR_CHANGE,(t=>{t===N.A.events.TEXT_CHANGE&&this.root.classList.toggle("ql-blank",this.editor.isBlank())})),this.emitter.on(N.A.events.SCROLL_UPDATE,((t,e)=>{const n=this.selection.lastRange,[r]=this.selection.getRange(),i=n&&r?{oldRange:n,newRange:r}:void 0;D.call(this,(()=>this.editor.update(null,e,i)),t)})),this.emitter.on(N.A.events.SCROLL_EMBED_UPDATE,((t,e)=>{const n=this.selection.lastRange,[r]=this.selection.getRange(),i=n&&r?{oldRange:n,newRange:r}:void 0;D.call(this,(()=>{const n=(new(o())).retain(t.offset(this)).retain({[t.statics.blotName]:e});return this.editor.update(n,[],i)}),I.sources.USER)})),n){const t=this.clipboard.convert({html:`${n}<p><br></p>`,text:"\n"});this.setContents(t)}this.history.clear(),this.options.placeholder&&this.root.setAttribute("data-placeholder",this.options.placeholder),this.options.readOnly&&this.disable(),this.allowReadOnlyEdits=!1}addContainer(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:null;if("string"==typeof t){const e=t;(t=document.createElement("div")).classList.add(e)}return this.container.insertBefore(t,e),t}blur(){this.selection.setRange(null)}deleteText(t,e,n){return[t,e,,n]=P(t,e,n),D.call(this,(()=>this.editor.deleteText(t,e)),n,t,-1*e)}disable(){this.enable(!1)}editReadOnly(t){this.allowReadOnlyEdits=!0;const e=t();return this.allowReadOnlyEdits=!1,e}enable(){let t=!(arguments.length>0&&void 0!==arguments[0])||arguments[0];this.scroll.enable(t),this.container.classList.toggle("ql-disabled",!t)}focus(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:{};this.selection.focus(),t.preventScroll||this.scrollSelectionIntoView()}format(t,e){let n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:N.A.sources.API;return D.call(this,(()=>{const n=this.getSelection(!0);let r=new(o());if(null==n)return r;if(this.scroll.query(t,i.Scope.BLOCK))r=this.editor.formatLine(n.index,n.length,{[t]:e});else{if(0===n.length)return this.selection.format(t,e),r;r=this.editor.formatText(n.index,n.length,{[t]:e})}return this.setSelection(n,N.A.sources.SILENT),r}),n)}formatLine(t,e,n,r,i){let s;return[t,e,s,i]=P(t,e,n,r,i),D.call(this,(()=>this.editor.formatLine(t,e,s)),i,t,0)}formatText(t,e,n,r,i){let s;return[t,e,s,i]=P(t,e,n,r,i),D.call(this,(()=>this.editor.formatText(t,e,s)),i,t,0)}getBounds(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:0,n=null;if(n="number"==typeof t?this.selection.getBounds(t,e):this.selection.getBounds(t.index,t.length),!n)return null;const r=this.container.getBoundingClientRect();return{bottom:n.bottom-r.top,height:n.height,left:n.left-r.left,right:n.right-r.left,top:n.top-r.top,width:n.width}}getContents(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:0,e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:this.getLength()-t;return[t,e]=P(t,e),this.editor.getContents(t,e)}getFormat(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:this.getSelection(!0),e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:0;return"number"==typeof t?this.editor.getFormat(t,e):this.editor.getFormat(t.index,t.length)}getIndex(t){return t.offset(this.scroll)}getLength(){return this.scroll.length()}getLeaf(t){return this.scroll.leaf(t)}getLine(t){return this.scroll.line(t)}getLines(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:0,e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:Number.MAX_VALUE;return"number"!=typeof t?this.scroll.lines(t.index,t.length):this.scroll.lines(t,e)}getModule(t){return this.theme.modules[t]}getSelection(){return arguments.length>0&&void 0!==arguments[0]&&arguments[0]&&this.focus(),this.update(),this.selection.getRange()[0]}getSemanticHTML(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:0,e=arguments.length>1?arguments[1]:void 0;return"number"==typeof t&&(e=e??this.getLength()-t),[t,e]=P(t,e),this.editor.getHTML(t,e)}getText(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:0,e=arguments.length>1?arguments[1]:void 0;return"number"==typeof t&&(e=e??this.getLength()-t),[t,e]=P(t,e),this.editor.getText(t,e)}hasFocus(){return this.selection.hasFocus()}insertEmbed(t,e,n){let r=arguments.length>3&&void 0!==arguments[3]?arguments[3]:I.sources.API;return D.call(this,(()=>this.editor.insertEmbed(t,e,n)),r,t)}insertText(t,e,n,r,i){let s;return[t,,s,i]=P(t,0,n,r,i),D.call(this,(()=>this.editor.insertText(t,e,s)),i,t,e.length)}isEnabled(){return this.scroll.isEnabled()}off(){return this.emitter.off(...arguments)}on(){return this.emitter.on(...arguments)}once(){return this.emitter.once(...arguments)}removeFormat(t,e,n){return[t,e,,n]=P(t,e,n),D.call(this,(()=>this.editor.removeFormat(t,e)),n,t)}scrollRectIntoView(t){((t,e)=>{const n=t.ownerDocument;let r=e,i=t;for(;i;){const t=i===n.body,e=t?{top:0,right:window.visualViewport?.width??n.documentElement.clientWidth,bottom:window.visualViewport?.height??n.documentElement.clientHeight,left:0}:S(i),o=getComputedStyle(i),l=T(r.left,r.right,e.left,e.right,O(o.scrollPaddingLeft),O(o.scrollPaddingRight)),a=T(r.top,r.bottom,e.top,e.bottom,O(o.scrollPaddingTop),O(o.scrollPaddingBottom));if(l||a)if(t)n.defaultView?.scrollBy(l,a);else{const{scrollLeft:t,scrollTop:e}=i;a&&(i.scrollTop+=a),l&&(i.scrollLeft+=l);const n=i.scrollLeft-t,s=i.scrollTop-e;r={left:r.left-n,top:r.top-s,right:r.right-n,bottom:r.bottom-s}}i=t||"fixed"===o.position?null:(s=i).parentElement||s.getRootNode().host||null}var s})(this.root,t)}scrollIntoView(){console.warn("Quill#scrollIntoView() has been deprecated and will be removed in the near future. Please use Quill#scrollSelectionIntoView() instead."),this.scrollSelectionIntoView()}scrollSelectionIntoView(){const t=this.selection.lastRange,e=t&&this.selection.getBounds(t.index,t.length);e&&this.scrollRectIntoView(e)}setContents(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:N.A.sources.API;return D.call(this,(()=>{t=new(o())(t);const e=this.getLength(),n=this.editor.deleteText(0,e),r=this.editor.insertContents(0,t),i=this.editor.deleteText(this.getLength()-1,1);return n.compose(r).compose(i)}),e)}setSelection(t,e,n){null==t?this.selection.setRange(null,e||I.sources.API):([t,e,,n]=P(t,e,n),this.selection.setRange(new f.Q(Math.max(0,t),e),n),n!==N.A.sources.SILENT&&this.scrollSelectionIntoView())}setText(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:N.A.sources.API;const n=(new(o())).insert(t);return this.setContents(n,e)}update(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:N.A.sources.USER;const e=this.scroll.update(t);return this.selection.update(t),e}updateContents(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:N.A.sources.API;return D.call(this,(()=>(t=new(o())(t),this.editor.applyDelta(t))),e,!0)}}function B(t){return"string"==typeof t?document.querySelector(t):t}function M(t){return Object.entries(t??{}).reduce(((t,e)=>{let[n,r]=e;return{...t,[n]:!0===r?{}:r}}),{})}function U(t){return Object.fromEntries(Object.entries(t).filter((t=>void 0!==t[1])))}function D(t,e,n,r){if(!this.isEnabled()&&e===N.A.sources.USER&&!this.allowReadOnlyEdits)return new(o());let i=null==n?null:this.getSelection();const s=this.editor.delta,l=t();if(null!=i&&(!0===n&&(n=i.index),null==r?i=z(i,l,e):0!==r&&(i=z(i,n,r,e)),this.setSelection(i,N.A.sources.SILENT)),l.length()>0){const t=[N.A.events.TEXT_CHANGE,l,s,e];this.emitter.emit(N.A.events.EDITOR_CHANGE,...t),e!==N.A.sources.SILENT&&this.emitter.emit(...t)}return l}function P(t,e,n,r,i){let s={};return"number"==typeof t.index&&"number"==typeof t.length?"number"!=typeof e?(i=r,r=n,n=e,e=t.length,t=t.index):(e=t.length,t=t.index):"number"!=typeof e&&(i=r,r=n,n=e,e=0),"object"==typeof n?(s=n,i=r):"string"==typeof n&&(null!=r?s[n]=r:i=n),[t,e,s,i=i||N.A.sources.API]}function z(t,e,n,r){const i="number"==typeof n?n:0;if(null==t)return null;let s,o;return e&&"function"==typeof e.transformPosition?[s,o]=[t.index,t.index+t.length].map((t=>e.transformPosition(t,r!==N.A.sources.USER))):[s,o]=[t.index,t.index+t.length].map((t=>t<e||t===e&&r===N.A.sources.USER?t:i>=0?t+i:Math.max(e,t+i))),new f.Q(s,o-s)}},8298:function(t,e,n){"use strict";n.d(e,{Q:function(){return a}});var r=n(6003),i=n(5123),s=n(3707),o=n(5374);const l=(0,n(6078).A)("quill:selection");class a{constructor(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:0;this.index=t,this.length=e}}function c(t,e){try{e.parentNode}catch(t){return!1}return t.contains(e)}e.A=class{constructor(t,e){this.emitter=e,this.scroll=t,this.composing=!1,this.mouseDown=!1,this.root=this.scroll.domNode,this.cursor=this.scroll.create("cursor",this),this.savedRange=new a(0,0),this.lastRange=this.savedRange,this.lastNative=null,this.handleComposition(),this.handleDragging(),this.emitter.listenDOM("selectionchange",document,(()=>{this.mouseDown||this.composing||setTimeout(this.update.bind(this,o.A.sources.USER),1)})),this.emitter.on(o.A.events.SCROLL_BEFORE_UPDATE,(()=>{if(!this.hasFocus())return;const t=this.getNativeRange();null!=t&&t.start.node!==this.cursor.textNode&&this.emitter.once(o.A.events.SCROLL_UPDATE,((e,n)=>{try{this.root.contains(t.start.node)&&this.root.contains(t.end.node)&&this.setNativeRange(t.start.node,t.start.offset,t.end.node,t.end.offset);const r=n.some((t=>"characterData"===t.type||"childList"===t.type||"attributes"===t.type&&t.target===this.root));this.update(r?o.A.sources.SILENT:e)}catch(t){}}))})),this.emitter.on(o.A.events.SCROLL_OPTIMIZE,((t,e)=>{if(e.range){const{startNode:t,startOffset:n,endNode:r,endOffset:i}=e.range;this.setNativeRange(t,n,r,i),this.update(o.A.sources.SILENT)}})),this.update(o.A.sources.SILENT)}handleComposition(){this.emitter.on(o.A.events.COMPOSITION_BEFORE_START,(()=>{this.composing=!0})),this.emitter.on(o.A.events.COMPOSITION_END,(()=>{if(this.composing=!1,this.cursor.parent){const t=this.cursor.restore();if(!t)return;setTimeout((()=>{this.setNativeRange(t.startNode,t.startOffset,t.endNode,t.endOffset)}),1)}}))}handleDragging(){this.emitter.listenDOM("mousedown",document.body,(()=>{this.mouseDown=!0})),this.emitter.listenDOM("mouseup",document.body,(()=>{this.mouseDown=!1,this.update(o.A.sources.USER)}))}focus(){this.hasFocus()||(this.root.focus({preventScroll:!0}),this.setRange(this.savedRange))}format(t,e){this.scroll.update();const n=this.getNativeRange();if(null!=n&&n.native.collapsed&&!this.scroll.query(t,r.Scope.BLOCK)){if(n.start.node!==this.cursor.textNode){const t=this.scroll.find(n.start.node,!1);if(null==t)return;if(t instanceof r.LeafBlot){const e=t.split(n.start.offset);t.parent.insertBefore(this.cursor,e)}else t.insertBefore(this.cursor,n.start.node);this.cursor.attach()}this.cursor.format(t,e),this.scroll.optimize(),this.setNativeRange(this.cursor.textNode,this.cursor.textNode.data.length),this.update()}}getBounds(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:0;const n=this.scroll.length();let r;t=Math.min(t,n-1),e=Math.min(t+e,n-1)-t;let[i,s]=this.scroll.leaf(t);if(null==i)return null;if(e>0&&s===i.length()){const[e]=this.scroll.leaf(t+1);if(e){const[n]=this.scroll.line(t),[r]=this.scroll.line(t+1);n===r&&(i=e,s=0)}}[r,s]=i.position(s,!0);const o=document.createRange();if(e>0)return o.setStart(r,s),[i,s]=this.scroll.leaf(t+e),null==i?null:([r,s]=i.position(s,!0),o.setEnd(r,s),o.getBoundingClientRect());let l,a="left";if(r instanceof Text){if(!r.data.length)return null;s<r.data.length?(o.setStart(r,s),o.setEnd(r,s+1)):(o.setStart(r,s-1),o.setEnd(r,s),a="right"),l=o.getBoundingClientRect()}else{if(!(i.domNode instanceof Element))return null;l=i.domNode.getBoundingClientRect(),s>0&&(a="right")}return{bottom:l.top+l.height,height:l.height,left:l[a],right:l[a],top:l.top,width:0}}getNativeRange(){const t=document.getSelection();if(null==t||t.rangeCount<=0)return null;const e=t.getRangeAt(0);if(null==e)return null;const n=this.normalizeNative(e);return l.info("getNativeRange",n),n}getRange(){const t=this.scroll.domNode;if("isConnected"in t&&!t.isConnected)return[null,null];const e=this.getNativeRange();return null==e?[null,null]:[this.normalizedToRange(e),e]}hasFocus(){return document.activeElement===this.root||null!=document.activeElement&&c(this.root,document.activeElement)}normalizedToRange(t){const e=[[t.start.node,t.start.offset]];t.native.collapsed||e.push([t.end.node,t.end.offset]);const n=e.map((t=>{const[e,n]=t,i=this.scroll.find(e,!0),s=i.offset(this.scroll);return 0===n?s:i instanceof r.LeafBlot?s+i.index(e,n):s+i.length()})),i=Math.min(Math.max(...n),this.scroll.length()-1),s=Math.min(i,...n);return new a(s,i-s)}normalizeNative(t){if(!c(this.root,t.startContainer)||!t.collapsed&&!c(this.root,t.endContainer))return null;const e={start:{node:t.startContainer,offset:t.startOffset},end:{node:t.endContainer,offset:t.endOffset},native:t};return[e.start,e.end].forEach((t=>{let{node:e,offset:n}=t;for(;!(e instanceof Text)&&e.childNodes.length>0;)if(e.childNodes.length>n)e=e.childNodes[n],n=0;else{if(e.childNodes.length!==n)break;e=e.lastChild,n=e instanceof Text?e.data.length:e.childNodes.length>0?e.childNodes.length:e.childNodes.length+1}t.node=e,t.offset=n})),e}rangeToNative(t){const e=this.scroll.length(),n=(t,n)=>{t=Math.min(e-1,t);const[r,i]=this.scroll.leaf(t);return r?r.position(i,n):[null,-1]};return[...n(t.index,!1),...n(t.index+t.length,!0)]}setNativeRange(t,e){let n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:t,r=arguments.length>3&&void 0!==arguments[3]?arguments[3]:e,i=arguments.length>4&&void 0!==arguments[4]&&arguments[4];if(l.info("setNativeRange",t,e,n,r),null!=t&&(null==this.root.parentNode||null==t.parentNode||null==n.parentNode))return;const s=document.getSelection();if(null!=s)if(null!=t){this.hasFocus()||this.root.focus({preventScroll:!0});const{native:o}=this.getNativeRange()||{};if(null==o||i||t!==o.startContainer||e!==o.startOffset||n!==o.endContainer||r!==o.endOffset){t instanceof Element&&"BR"===t.tagName&&(e=Array.from(t.parentNode.childNodes).indexOf(t),t=t.parentNode),n instanceof Element&&"BR"===n.tagName&&(r=Array.from(n.parentNode.childNodes).indexOf(n),n=n.parentNode);const i=document.createRange();i.setStart(t,e),i.setEnd(n,r),s.removeAllRanges(),s.addRange(i)}}else s.removeAllRanges(),this.root.blur()}setRange(t){let e=arguments.length>1&&void 0!==arguments[1]&&arguments[1],n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:o.A.sources.API;if("string"==typeof e&&(n=e,e=!1),l.info("setRange",t),null!=t){const n=this.rangeToNative(t);this.setNativeRange(...n,e)}else this.setNativeRange(null);this.update(n)}update(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:o.A.sources.USER;const e=this.lastRange,[n,r]=this.getRange();if(this.lastRange=n,this.lastNative=r,null!=this.lastRange&&(this.savedRange=this.lastRange),!(0,i.A)(e,this.lastRange)){if(!this.composing&&null!=r&&r.native.collapsed&&r.start.node!==this.cursor.textNode){const t=this.cursor.restore();t&&this.setNativeRange(t.startNode,t.startOffset,t.endNode,t.endOffset)}const n=[o.A.events.SELECTION_CHANGE,(0,s.A)(this.lastRange),(0,s.A)(e),t];this.emitter.emit(o.A.events.EDITOR_CHANGE,...n),t!==o.A.sources.SILENT&&this.emitter.emit(...n)}}}},9609:function(t,e){"use strict";class n{static DEFAULTS={modules:{}};static themes={default:n};modules={};constructor(t,e){this.quill=t,this.options=e}init(){Object.keys(this.options.modules).forEach((t=>{null==this.modules[t]&&this.addModule(t)}))}addModule(t){const e=this.quill.constructor.import(`modules/${t}`);return this.modules[t]=new e(this.quill,this.options.modules[t]||{}),this.modules[t]}}e.A=n},8276:function(t,e,n){"use strict";n.d(e,{Hu:function(){return l},gS:function(){return s},qh:function(){return o}});var r=n(6003);const i={scope:r.Scope.BLOCK,whitelist:["right","center","justify"]},s=new r.Attributor("align","align",i),o=new r.ClassAttributor("align","ql-align",i),l=new r.StyleAttributor("align","text-align",i)},9541:function(t,e,n){"use strict";n.d(e,{l:function(){return s},s:function(){return o}});var r=n(6003),i=n(8638);const s=new r.ClassAttributor("background","ql-bg",{scope:r.Scope.INLINE}),o=new i.a2("background","background-color",{scope:r.Scope.INLINE})},9404:function(t,e,n){"use strict";n.d(e,{Ay:function(){return h},Cy:function(){return d},EJ:function(){return u}});var r=n(9698),i=n(3036),s=n(4541),o=n(4850),l=n(5508),a=n(580),c=n(6142);class u extends a.A{static create(t){const e=super.create(t);return e.setAttribute("spellcheck","false"),e}code(t,e){return this.children.map((t=>t.length()<=1?"":t.domNode.innerText)).join("\n").slice(t,t+e)}html(t,e){return`<pre>\n${(0,l.X)(this.code(t,e))}\n</pre>`}}class h extends r.Ay{static TAB="  ";static register(){c.Ay.register(u)}}class d extends o.A{}d.blotName="code",d.tagName="CODE",h.blotName="code-block",h.className="ql-code-block",h.tagName="DIV",u.blotName="code-block-container",u.className="ql-code-block-container",u.tagName="DIV",u.allowedChildren=[h],h.allowedChildren=[l.A,i.A,s.A],h.requiredContainer=u},8638:function(t,e,n){"use strict";n.d(e,{JM:function(){return o},a2:function(){return i},g3:function(){return s}});var r=n(6003);class i extends r.StyleAttributor{value(t){let e=super.value(t);return e.startsWith("rgb(")?(e=e.replace(/^[^\d]+/,"").replace(/[^\d]+$/,""),`#${e.split(",").map((t=>`00${parseInt(t,10).toString(16)}`.slice(-2))).join("")}`):e}}const s=new r.ClassAttributor("color","ql-color",{scope:r.Scope.INLINE}),o=new i("color","color",{scope:r.Scope.INLINE})},7912:function(t,e,n){"use strict";n.d(e,{Mc:function(){return s},VL:function(){return l},sY:function(){return o}});var r=n(6003);const i={scope:r.Scope.BLOCK,whitelist:["rtl"]},s=new r.Attributor("direction","dir",i),o=new r.ClassAttributor("direction","ql-direction",i),l=new r.StyleAttributor("direction","direction",i)},6772:function(t,e,n){"use strict";n.d(e,{q:function(){return s},z:function(){return l}});var r=n(6003);const i={scope:r.Scope.INLINE,whitelist:["serif","monospace"]},s=new r.ClassAttributor("font","ql-font",i);class o extends r.StyleAttributor{value(t){return super.value(t).replace(/["']/g,"")}}const l=new o("font","font-family",i)},664:function(t,e,n){"use strict";n.d(e,{U:function(){return i},r:function(){return s}});var r=n(6003);const i=new r.ClassAttributor("size","ql-size",{scope:r.Scope.INLINE,whitelist:["small","large","huge"]}),s=new r.StyleAttributor("size","font-size",{scope:r.Scope.INLINE,whitelist:["10px","18px","32px"]})},584:function(t,e,n){"use strict";n.d(e,{Ay:function(){return S},hV:function(){return I}});var r=n(6003),i=n(5232),s=n.n(i),o=n(9698),l=n(6078),a=n(4266),c=n(6142),u=n(8276),h=n(9541),d=n(9404),f=n(8638),p=n(7912),g=n(6772),m=n(664),b=n(8123);const y=/font-weight:\s*normal/,v=["P","OL","UL"],A=t=>t&&v.includes(t.tagName),x=/\bmso-list:[^;]*ignore/i,N=/\bmso-list:[^;]*\bl(\d+)/i,E=/\bmso-list:[^;]*\blevel(\d+)/i,w=[function(t){"urn:schemas-microsoft-com:office:word"===t.documentElement.getAttribute("xmlns:w")&&(t=>{const e=Array.from(t.querySelectorAll("[style*=mso-list]")),n=[],r=[];e.forEach((t=>{(t.getAttribute("style")||"").match(x)?n.push(t):r.push(t)})),n.forEach((t=>t.parentNode?.removeChild(t)));const i=t.documentElement.innerHTML,s=r.map((t=>((t,e)=>{const n=t.getAttribute("style"),r=n?.match(N);if(!r)return null;const i=Number(r[1]),s=n?.match(E),o=s?Number(s[1]):1,l=new RegExp(`@list l${i}:level${o}\\s*\\{[^\\}]*mso-level-number-format:\\s*([\\w-]+)`,"i"),a=e.match(l);return{id:i,indent:o,type:a&&"bullet"===a[1]?"bullet":"ordered",element:t}})(t,i))).filter((t=>t));for(;s.length;){const t=[];let e=s.shift();for(;e;)t.push(e),e=s.length&&s[0]?.element===e.element.nextElementSibling&&s[0].id===e.id?s.shift():null;const n=document.createElement("ul");t.forEach((t=>{const e=document.createElement("li");e.setAttribute("data-list",t.type),t.indent>1&&e.setAttribute("class","ql-indent-"+(t.indent-1)),e.innerHTML=t.element.innerHTML,n.appendChild(e)}));const r=t[0]?.element,{parentNode:i}=r??{};r&&i?.replaceChild(n,r),t.slice(1).forEach((t=>{let{element:e}=t;i?.removeChild(e)}))}})(t)},function(t){t.querySelector('[id^="docs-internal-guid-"]')&&((t=>{Array.from(t.querySelectorAll('b[style*="font-weight"]')).filter((t=>t.getAttribute("style")?.match(y))).forEach((e=>{const n=t.createDocumentFragment();n.append(...e.childNodes),e.parentNode?.replaceChild(n,e)}))})(t),(t=>{Array.from(t.querySelectorAll("br")).filter((t=>A(t.previousElementSibling)&&A(t.nextElementSibling))).forEach((t=>{t.parentNode?.removeChild(t)}))})(t))}];const q=(0,l.A)("quill:clipboard"),k=[[Node.TEXT_NODE,function(t,e,n){let r=t.data;if("O:P"===t.parentElement?.tagName)return e.insert(r.trim());if(!R(t)){if(0===r.trim().length&&r.includes("\n")&&!function(t,e){return t.previousElementSibling&&t.nextElementSibling&&!j(t.previousElementSibling,e)&&!j(t.nextElementSibling,e)}(t,n))return e;r=r.replace(/[^\S\u00a0]/g," "),r=r.replace(/ {2,}/g," "),(null==t.previousSibling&&null!=t.parentElement&&j(t.parentElement,n)||t.previousSibling instanceof Element&&j(t.previousSibling,n))&&(r=r.replace(/^ /,"")),(null==t.nextSibling&&null!=t.parentElement&&j(t.parentElement,n)||t.nextSibling instanceof Element&&j(t.nextSibling,n))&&(r=r.replace(/ $/,"")),r=r.replaceAll(" "," ")}return e.insert(r)}],[Node.TEXT_NODE,M],["br",function(t,e){return T(e,"\n")||e.insert("\n"),e}],[Node.ELEMENT_NODE,M],[Node.ELEMENT_NODE,function(t,e,n){const i=n.query(t);if(null==i)return e;if(i.prototype instanceof r.EmbedBlot){const e={},r=i.value(t);if(null!=r)return e[i.blotName]=r,(new(s())).insert(e,i.formats(t,n))}else if(i.prototype instanceof r.BlockBlot&&!T(e,"\n")&&e.insert("\n"),"blotName"in i&&"formats"in i&&"function"==typeof i.formats)return O(e,i.blotName,i.formats(t,n),n);return e}],[Node.ELEMENT_NODE,function(t,e,n){const i=r.Attributor.keys(t),s=r.ClassAttributor.keys(t),o=r.StyleAttributor.keys(t),l={};return i.concat(s).concat(o).forEach((e=>{let i=n.query(e,r.Scope.ATTRIBUTE);null!=i&&(l[i.attrName]=i.value(t),l[i.attrName])||(i=_[e],null==i||i.attrName!==e&&i.keyName!==e||(l[i.attrName]=i.value(t)||void 0),i=L[e],null==i||i.attrName!==e&&i.keyName!==e||(i=L[e],l[i.attrName]=i.value(t)||void 0))})),Object.entries(l).reduce(((t,e)=>{let[r,i]=e;return O(t,r,i,n)}),e)}],[Node.ELEMENT_NODE,function(t,e,n){const r={},i=t.style||{};return"italic"===i.fontStyle&&(r.italic=!0),"underline"===i.textDecoration&&(r.underline=!0),"line-through"===i.textDecoration&&(r.strike=!0),(i.fontWeight?.startsWith("bold")||parseInt(i.fontWeight,10)>=700)&&(r.bold=!0),e=Object.entries(r).reduce(((t,e)=>{let[r,i]=e;return O(t,r,i,n)}),e),parseFloat(i.textIndent||0)>0?(new(s())).insert("\t").concat(e):e}],["li",function(t,e,n){const r=n.query(t);if(null==r||"list"!==r.blotName||!T(e,"\n"))return e;let i=-1,o=t.parentNode;for(;null!=o;)["OL","UL"].includes(o.tagName)&&(i+=1),o=o.parentNode;return i<=0?e:e.reduce(((t,e)=>e.insert?e.attributes&&"number"==typeof e.attributes.indent?t.push(e):t.insert(e.insert,{indent:i,...e.attributes||{}}):t),new(s()))}],["ol, ul",function(t,e,n){const r=t;let i="OL"===r.tagName?"ordered":"bullet";const s=r.getAttribute("data-checked");return s&&(i="true"===s?"checked":"unchecked"),O(e,"list",i,n)}],["pre",function(t,e,n){const r=n.query("code-block");return O(e,"code-block",!r||!("formats"in r)||"function"!=typeof r.formats||r.formats(t,n),n)}],["tr",function(t,e,n){const r="TABLE"===t.parentElement?.tagName?t.parentElement:t.parentElement?.parentElement;return null!=r?O(e,"table",Array.from(r.querySelectorAll("tr")).indexOf(t)+1,n):e}],["b",B("bold")],["i",B("italic")],["strike",B("strike")],["style",function(){return new(s())}]],_=[u.gS,p.Mc].reduce(((t,e)=>(t[e.keyName]=e,t)),{}),L=[u.Hu,h.s,f.JM,p.VL,g.z,m.r].reduce(((t,e)=>(t[e.keyName]=e,t)),{});class S extends a.A{static DEFAULTS={matchers:[]};constructor(t,e){super(t,e),this.quill.root.addEventListener("copy",(t=>this.onCaptureCopy(t,!1))),this.quill.root.addEventListener("cut",(t=>this.onCaptureCopy(t,!0))),this.quill.root.addEventListener("paste",this.onCapturePaste.bind(this)),this.matchers=[],k.concat(this.options.matchers??[]).forEach((t=>{let[e,n]=t;this.addMatcher(e,n)}))}addMatcher(t,e){this.matchers.push([t,e])}convert(t){let{html:e,text:n}=t,r=arguments.length>1&&void 0!==arguments[1]?arguments[1]:{};if(r[d.Ay.blotName])return(new(s())).insert(n||"",{[d.Ay.blotName]:r[d.Ay.blotName]});if(!e)return(new(s())).insert(n||"",r);const i=this.convertHTML(e);return T(i,"\n")&&(null==i.ops[i.ops.length-1].attributes||r.table)?i.compose((new(s())).retain(i.length()-1).delete(1)):i}normalizeHTML(t){(t=>{t.documentElement&&w.forEach((e=>{e(t)}))})(t)}convertHTML(t){const e=(new DOMParser).parseFromString(t,"text/html");this.normalizeHTML(e);const n=e.body,r=new WeakMap,[i,s]=this.prepareMatching(n,r);return I(this.quill.scroll,n,i,s,r)}dangerouslyPasteHTML(t,e){let n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:c.Ay.sources.API;if("string"==typeof t){const n=this.convert({html:t,text:""});this.quill.setContents(n,e),this.quill.setSelection(0,c.Ay.sources.SILENT)}else{const r=this.convert({html:e,text:""});this.quill.updateContents((new(s())).retain(t).concat(r),n),this.quill.setSelection(t+r.length(),c.Ay.sources.SILENT)}}onCaptureCopy(t){let e=arguments.length>1&&void 0!==arguments[1]&&arguments[1];if(t.defaultPrevented)return;t.preventDefault();const[n]=this.quill.selection.getRange();if(null==n)return;const{html:r,text:i}=this.onCopy(n,e);t.clipboardData?.setData("text/plain",i),t.clipboardData?.setData("text/html",r),e&&(0,b.Xo)({range:n,quill:this.quill})}normalizeURIList(t){return t.split(/\r?\n/).filter((t=>"#"!==t[0])).join("\n")}onCapturePaste(t){if(t.defaultPrevented||!this.quill.isEnabled())return;t.preventDefault();const e=this.quill.getSelection(!0);if(null==e)return;const n=t.clipboardData?.getData("text/html");let r=t.clipboardData?.getData("text/plain");if(!n&&!r){const e=t.clipboardData?.getData("text/uri-list");e&&(r=this.normalizeURIList(e))}const i=Array.from(t.clipboardData?.files||[]);if(!n&&i.length>0)this.quill.uploader.upload(e,i);else{if(n&&i.length>0){const t=(new DOMParser).parseFromString(n,"text/html");if(1===t.body.childElementCount&&"IMG"===t.body.firstElementChild?.tagName)return void this.quill.uploader.upload(e,i)}this.onPaste(e,{html:n,text:r})}}onCopy(t){const e=this.quill.getText(t);return{html:this.quill.getSemanticHTML(t),text:e}}onPaste(t,e){let{text:n,html:r}=e;const i=this.quill.getFormat(t.index),o=this.convert({text:n,html:r},i);q.log("onPaste",o,{text:n,html:r});const l=(new(s())).retain(t.index).delete(t.length).concat(o);this.quill.updateContents(l,c.Ay.sources.USER),this.quill.setSelection(l.length()-t.length,c.Ay.sources.SILENT),this.quill.scrollSelectionIntoView()}prepareMatching(t,e){const n=[],r=[];return this.matchers.forEach((i=>{const[s,o]=i;switch(s){case Node.TEXT_NODE:r.push(o);break;case Node.ELEMENT_NODE:n.push(o);break;default:Array.from(t.querySelectorAll(s)).forEach((t=>{if(e.has(t)){const n=e.get(t);n?.push(o)}else e.set(t,[o])}))}})),[n,r]}}function O(t,e,n,r){return r.query(e)?t.reduce(((t,r)=>{if(!r.insert)return t;if(r.attributes&&r.attributes[e])return t.push(r);const i=n?{[e]:n}:{};return t.insert(r.insert,{...i,...r.attributes})}),new(s())):t}function T(t,e){let n="";for(let r=t.ops.length-1;r>=0&&n.length<e.length;--r){const e=t.ops[r];if("string"!=typeof e.insert)break;n=e.insert+n}return n.slice(-1*e.length)===e}function j(t,e){if(!(t instanceof Element))return!1;const n=e.query(t);return!(n&&n.prototype instanceof r.EmbedBlot)&&["address","article","blockquote","canvas","dd","div","dl","dt","fieldset","figcaption","figure","footer","form","h1","h2","h3","h4","h5","h6","header","iframe","li","main","nav","ol","output","p","pre","section","table","td","tr","ul","video"].includes(t.tagName.toLowerCase())}const C=new WeakMap;function R(t){return null!=t&&(C.has(t)||("PRE"===t.tagName?C.set(t,!0):C.set(t,R(t.parentNode))),C.get(t))}function I(t,e,n,r,i){return e.nodeType===e.TEXT_NODE?r.reduce(((n,r)=>r(e,n,t)),new(s())):e.nodeType===e.ELEMENT_NODE?Array.from(e.childNodes||[]).reduce(((s,o)=>{let l=I(t,o,n,r,i);return o.nodeType===e.ELEMENT_NODE&&(l=n.reduce(((e,n)=>n(o,e,t)),l),l=(i.get(o)||[]).reduce(((e,n)=>n(o,e,t)),l)),s.concat(l)}),new(s())):new(s())}function B(t){return(e,n,r)=>O(n,t,!0,r)}function M(t,e,n){if(!T(e,"\n")){if(j(t,n)&&(t.childNodes.length>0||t instanceof HTMLParagraphElement))return e.insert("\n");if(e.length()>0&&t.nextSibling){let r=t.nextSibling;for(;null!=r;){if(j(r,n))return e.insert("\n");const t=n.query(r);if(t&&t.prototype instanceof o.zo)return e.insert("\n");r=r.firstChild}}}return e}},8123:function(t,e,n){"use strict";n.d(e,{Ay:function(){return f},Xo:function(){return v}});var r=n(5123),i=n(3707),s=n(5232),o=n.n(s),l=n(6003),a=n(6142),c=n(6078),u=n(4266);const h=(0,c.A)("quill:keyboard"),d=/Mac/i.test(navigator.platform)?"metaKey":"ctrlKey";class f extends u.A{static match(t,e){return!["altKey","ctrlKey","metaKey","shiftKey"].some((n=>!!e[n]!==t[n]&&null!==e[n]))&&(e.key===t.key||e.key===t.which)}constructor(t,e){super(t,e),this.bindings={},Object.keys(this.options.bindings).forEach((t=>{this.options.bindings[t]&&this.addBinding(this.options.bindings[t])})),this.addBinding({key:"Enter",shiftKey:null},this.handleEnter),this.addBinding({key:"Enter",metaKey:null,ctrlKey:null,altKey:null},(()=>{})),/Firefox/i.test(navigator.userAgent)?(this.addBinding({key:"Backspace"},{collapsed:!0},this.handleBackspace),this.addBinding({key:"Delete"},{collapsed:!0},this.handleDelete)):(this.addBinding({key:"Backspace"},{collapsed:!0,prefix:/^.?$/},this.handleBackspace),this.addBinding({key:"Delete"},{collapsed:!0,suffix:/^.?$/},this.handleDelete)),this.addBinding({key:"Backspace"},{collapsed:!1},this.handleDeleteRange),this.addBinding({key:"Delete"},{collapsed:!1},this.handleDeleteRange),this.addBinding({key:"Backspace",altKey:null,ctrlKey:null,metaKey:null,shiftKey:null},{collapsed:!0,offset:0},this.handleBackspace),this.listen()}addBinding(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:{},n=arguments.length>2&&void 0!==arguments[2]?arguments[2]:{};const r=function(t){if("string"==typeof t||"number"==typeof t)t={key:t};else{if("object"!=typeof t)return null;t=(0,i.A)(t)}return t.shortKey&&(t[d]=t.shortKey,delete t.shortKey),t}(t);null!=r?("function"==typeof e&&(e={handler:e}),"function"==typeof n&&(n={handler:n}),(Array.isArray(r.key)?r.key:[r.key]).forEach((t=>{const i={...r,key:t,...e,...n};this.bindings[i.key]=this.bindings[i.key]||[],this.bindings[i.key].push(i)}))):h.warn("Attempted to add invalid keyboard binding",r)}listen(){this.quill.root.addEventListener("keydown",(t=>{if(t.defaultPrevented||t.isComposing)return;if(229===t.keyCode&&("Enter"===t.key||"Backspace"===t.key))return;const e=(this.bindings[t.key]||[]).concat(this.bindings[t.which]||[]).filter((e=>f.match(t,e)));if(0===e.length)return;const n=a.Ay.find(t.target,!0);if(n&&n.scroll!==this.quill.scroll)return;const i=this.quill.getSelection();if(null==i||!this.quill.hasFocus())return;const[s,o]=this.quill.getLine(i.index),[c,u]=this.quill.getLeaf(i.index),[h,d]=0===i.length?[c,u]:this.quill.getLeaf(i.index+i.length),p=c instanceof l.TextBlot?c.value().slice(0,u):"",g=h instanceof l.TextBlot?h.value().slice(d):"",m={collapsed:0===i.length,empty:0===i.length&&s.length()<=1,format:this.quill.getFormat(i),line:s,offset:o,prefix:p,suffix:g,event:t};e.some((t=>{if(null!=t.collapsed&&t.collapsed!==m.collapsed)return!1;if(null!=t.empty&&t.empty!==m.empty)return!1;if(null!=t.offset&&t.offset!==m.offset)return!1;if(Array.isArray(t.format)){if(t.format.every((t=>null==m.format[t])))return!1}else if("object"==typeof t.format&&!Object.keys(t.format).every((e=>!0===t.format[e]?null!=m.format[e]:!1===t.format[e]?null==m.format[e]:(0,r.A)(t.format[e],m.format[e]))))return!1;return!(null!=t.prefix&&!t.prefix.test(m.prefix)||null!=t.suffix&&!t.suffix.test(m.suffix)||!0===t.handler.call(this,i,m,t))}))&&t.preventDefault()}))}handleBackspace(t,e){const n=/[\uD800-\uDBFF][\uDC00-\uDFFF]$/.test(e.prefix)?2:1;if(0===t.index||this.quill.getLength()<=1)return;let r={};const[i]=this.quill.getLine(t.index);let l=(new(o())).retain(t.index-n).delete(n);if(0===e.offset){const[e]=this.quill.getLine(t.index-1);if(e&&!("block"===e.statics.blotName&&e.length()<=1)){const e=i.formats(),n=this.quill.getFormat(t.index-1,1);if(r=s.AttributeMap.diff(e,n)||{},Object.keys(r).length>0){const e=(new(o())).retain(t.index+i.length()-2).retain(1,r);l=l.compose(e)}}}this.quill.updateContents(l,a.Ay.sources.USER),this.quill.focus()}handleDelete(t,e){const n=/^[\uD800-\uDBFF][\uDC00-\uDFFF]/.test(e.suffix)?2:1;if(t.index>=this.quill.getLength()-n)return;let r={};const[i]=this.quill.getLine(t.index);let l=(new(o())).retain(t.index).delete(n);if(e.offset>=i.length()-1){const[e]=this.quill.getLine(t.index+1);if(e){const n=i.formats(),o=this.quill.getFormat(t.index,1);r=s.AttributeMap.diff(n,o)||{},Object.keys(r).length>0&&(l=l.retain(e.length()-1).retain(1,r))}}this.quill.updateContents(l,a.Ay.sources.USER),this.quill.focus()}handleDeleteRange(t){v({range:t,quill:this.quill}),this.quill.focus()}handleEnter(t,e){const n=Object.keys(e.format).reduce(((t,n)=>(this.quill.scroll.query(n,l.Scope.BLOCK)&&!Array.isArray(e.format[n])&&(t[n]=e.format[n]),t)),{}),r=(new(o())).retain(t.index).delete(t.length).insert("\n",n);this.quill.updateContents(r,a.Ay.sources.USER),this.quill.setSelection(t.index+1,a.Ay.sources.SILENT),this.quill.focus()}}const p={bindings:{bold:b("bold"),italic:b("italic"),underline:b("underline"),indent:{key:"Tab",format:["blockquote","indent","list"],handler(t,e){return!(!e.collapsed||0===e.offset)||(this.quill.format("indent","+1",a.Ay.sources.USER),!1)}},outdent:{key:"Tab",shiftKey:!0,format:["blockquote","indent","list"],handler(t,e){return!(!e.collapsed||0===e.offset)||(this.quill.format("indent","-1",a.Ay.sources.USER),!1)}},"outdent backspace":{key:"Backspace",collapsed:!0,shiftKey:null,metaKey:null,ctrlKey:null,altKey:null,format:["indent","list"],offset:0,handler(t,e){null!=e.format.indent?this.quill.format("indent","-1",a.Ay.sources.USER):null!=e.format.list&&this.quill.format("list",!1,a.Ay.sources.USER)}},"indent code-block":g(!0),"outdent code-block":g(!1),"remove tab":{key:"Tab",shiftKey:!0,collapsed:!0,prefix:/\t$/,handler(t){this.quill.deleteText(t.index-1,1,a.Ay.sources.USER)}},tab:{key:"Tab",handler(t,e){if(e.format.table)return!0;this.quill.history.cutoff();const n=(new(o())).retain(t.index).delete(t.length).insert("\t");return this.quill.updateContents(n,a.Ay.sources.USER),this.quill.history.cutoff(),this.quill.setSelection(t.index+1,a.Ay.sources.SILENT),!1}},"blockquote empty enter":{key:"Enter",collapsed:!0,format:["blockquote"],empty:!0,handler(){this.quill.format("blockquote",!1,a.Ay.sources.USER)}},"list empty enter":{key:"Enter",collapsed:!0,format:["list"],empty:!0,handler(t,e){const n={list:!1};e.format.indent&&(n.indent=!1),this.quill.formatLine(t.index,t.length,n,a.Ay.sources.USER)}},"checklist enter":{key:"Enter",collapsed:!0,format:{list:"checked"},handler(t){const[e,n]=this.quill.getLine(t.index),r={...e.formats(),list:"checked"},i=(new(o())).retain(t.index).insert("\n",r).retain(e.length()-n-1).retain(1,{list:"unchecked"});this.quill.updateContents(i,a.Ay.sources.USER),this.quill.setSelection(t.index+1,a.Ay.sources.SILENT),this.quill.scrollSelectionIntoView()}},"header enter":{key:"Enter",collapsed:!0,format:["header"],suffix:/^$/,handler(t,e){const[n,r]=this.quill.getLine(t.index),i=(new(o())).retain(t.index).insert("\n",e.format).retain(n.length()-r-1).retain(1,{header:null});this.quill.updateContents(i,a.Ay.sources.USER),this.quill.setSelection(t.index+1,a.Ay.sources.SILENT),this.quill.scrollSelectionIntoView()}},"table backspace":{key:"Backspace",format:["table"],collapsed:!0,offset:0,handler(){}},"table delete":{key:"Delete",format:["table"],collapsed:!0,suffix:/^$/,handler(){}},"table enter":{key:"Enter",shiftKey:null,format:["table"],handler(t){const e=this.quill.getModule("table");if(e){const[n,r,i,s]=e.getTable(t),l=function(t,e,n,r){return null==e.prev&&null==e.next?null==n.prev&&null==n.next?0===r?-1:1:null==n.prev?-1:1:null==e.prev?-1:null==e.next?1:null}(0,r,i,s);if(null==l)return;let c=n.offset();if(l<0){const e=(new(o())).retain(c).insert("\n");this.quill.updateContents(e,a.Ay.sources.USER),this.quill.setSelection(t.index+1,t.length,a.Ay.sources.SILENT)}else if(l>0){c+=n.length();const t=(new(o())).retain(c).insert("\n");this.quill.updateContents(t,a.Ay.sources.USER),this.quill.setSelection(c,a.Ay.sources.USER)}}}},"table tab":{key:"Tab",shiftKey:null,format:["table"],handler(t,e){const{event:n,line:r}=e,i=r.offset(this.quill.scroll);n.shiftKey?this.quill.setSelection(i-1,a.Ay.sources.USER):this.quill.setSelection(i+r.length(),a.Ay.sources.USER)}},"list autofill":{key:" ",shiftKey:null,collapsed:!0,format:{"code-block":!1,blockquote:!1,table:!1},prefix:/^\s*?(\d+\.|-|\*|\[ ?\]|\[x\])$/,handler(t,e){if(null==this.quill.scroll.query("list"))return!0;const{length:n}=e.prefix,[r,i]=this.quill.getLine(t.index);if(i>n)return!0;let s;switch(e.prefix.trim()){case"[]":case"[ ]":s="unchecked";break;case"[x]":s="checked";break;case"-":case"*":s="bullet";break;default:s="ordered"}this.quill.insertText(t.index," ",a.Ay.sources.USER),this.quill.history.cutoff();const l=(new(o())).retain(t.index-i).delete(n+1).retain(r.length()-2-i).retain(1,{list:s});return this.quill.updateContents(l,a.Ay.sources.USER),this.quill.history.cutoff(),this.quill.setSelection(t.index-n,a.Ay.sources.SILENT),!1}},"code exit":{key:"Enter",collapsed:!0,format:["code-block"],prefix:/^$/,suffix:/^\s*$/,handler(t){const[e,n]=this.quill.getLine(t.index);let r=2,i=e;for(;null!=i&&i.length()<=1&&i.formats()["code-block"];)if(i=i.prev,r-=1,r<=0){const r=(new(o())).retain(t.index+e.length()-n-2).retain(1,{"code-block":null}).delete(1);return this.quill.updateContents(r,a.Ay.sources.USER),this.quill.setSelection(t.index-1,a.Ay.sources.SILENT),!1}return!0}},"embed left":m("ArrowLeft",!1),"embed left shift":m("ArrowLeft",!0),"embed right":m("ArrowRight",!1),"embed right shift":m("ArrowRight",!0),"table down":y(!1),"table up":y(!0)}};function g(t){return{key:"Tab",shiftKey:!t,format:{"code-block":!0},handler(e,n){let{event:r}=n;const i=this.quill.scroll.query("code-block"),{TAB:s}=i;if(0===e.length&&!r.shiftKey)return this.quill.insertText(e.index,s,a.Ay.sources.USER),void this.quill.setSelection(e.index+s.length,a.Ay.sources.SILENT);const o=0===e.length?this.quill.getLines(e.index,1):this.quill.getLines(e);let{index:l,length:c}=e;o.forEach(((e,n)=>{t?(e.insertAt(0,s),0===n?l+=s.length:c+=s.length):e.domNode.textContent.startsWith(s)&&(e.deleteAt(0,s.length),0===n?l-=s.length:c-=s.length)})),this.quill.update(a.Ay.sources.USER),this.quill.setSelection(l,c,a.Ay.sources.SILENT)}}}function m(t,e){return{key:t,shiftKey:e,altKey:null,["ArrowLeft"===t?"prefix":"suffix"]:/^$/,handler(n){let{index:r}=n;"ArrowRight"===t&&(r+=n.length+1);const[i]=this.quill.getLeaf(r);return!(i instanceof l.EmbedBlot&&("ArrowLeft"===t?e?this.quill.setSelection(n.index-1,n.length+1,a.Ay.sources.USER):this.quill.setSelection(n.index-1,a.Ay.sources.USER):e?this.quill.setSelection(n.index,n.length+1,a.Ay.sources.USER):this.quill.setSelection(n.index+n.length+1,a.Ay.sources.USER),1))}}}function b(t){return{key:t[0],shortKey:!0,handler(e,n){this.quill.format(t,!n.format[t],a.Ay.sources.USER)}}}function y(t){return{key:t?"ArrowUp":"ArrowDown",collapsed:!0,format:["table"],handler(e,n){const r=t?"prev":"next",i=n.line,s=i.parent[r];if(null!=s){if("table-row"===s.statics.blotName){let t=s.children.head,e=i;for(;null!=e.prev;)e=e.prev,t=t.next;const r=t.offset(this.quill.scroll)+Math.min(n.offset,t.length()-1);this.quill.setSelection(r,0,a.Ay.sources.USER)}}else{const e=i.table()[r];null!=e&&(t?this.quill.setSelection(e.offset(this.quill.scroll)+e.length()-1,0,a.Ay.sources.USER):this.quill.setSelection(e.offset(this.quill.scroll),0,a.Ay.sources.USER))}return!1}}}function v(t){let{quill:e,range:n}=t;const r=e.getLines(n);let i={};if(r.length>1){const t=r[0].formats(),e=r[r.length-1].formats();i=s.AttributeMap.diff(e,t)||{}}e.deleteText(n,a.Ay.sources.USER),Object.keys(i).length>0&&e.formatLine(n.index,1,i,a.Ay.sources.USER),e.setSelection(n.index,a.Ay.sources.SILENT)}f.DEFAULTS=p},8920:function(t){"use strict";var e=Object.prototype.hasOwnProperty,n="~";function r(){}function i(t,e,n){this.fn=t,this.context=e,this.once=n||!1}function s(t,e,r,s,o){if("function"!=typeof r)throw new TypeError("The listener must be a function");var l=new i(r,s||t,o),a=n?n+e:e;return t._events[a]?t._events[a].fn?t._events[a]=[t._events[a],l]:t._events[a].push(l):(t._events[a]=l,t._eventsCount++),t}function o(t,e){0==--t._eventsCount?t._events=new r:delete t._events[e]}function l(){this._events=new r,this._eventsCount=0}Object.create&&(r.prototype=Object.create(null),(new r).__proto__||(n=!1)),l.prototype.eventNames=function(){var t,r,i=[];if(0===this._eventsCount)return i;for(r in t=this._events)e.call(t,r)&&i.push(n?r.slice(1):r);return Object.getOwnPropertySymbols?i.concat(Object.getOwnPropertySymbols(t)):i},l.prototype.listeners=function(t){var e=n?n+t:t,r=this._events[e];if(!r)return[];if(r.fn)return[r.fn];for(var i=0,s=r.length,o=new Array(s);i<s;i++)o[i]=r[i].fn;return o},l.prototype.listenerCount=function(t){var e=n?n+t:t,r=this._events[e];return r?r.fn?1:r.length:0},l.prototype.emit=function(t,e,r,i,s,o){var l=n?n+t:t;if(!this._events[l])return!1;var a,c,u=this._events[l],h=arguments.length;if(u.fn){switch(u.once&&this.removeListener(t,u.fn,void 0,!0),h){case 1:return u.fn.call(u.context),!0;case 2:return u.fn.call(u.context,e),!0;case 3:return u.fn.call(u.context,e,r),!0;case 4:return u.fn.call(u.context,e,r,i),!0;case 5:return u.fn.call(u.context,e,r,i,s),!0;case 6:return u.fn.call(u.context,e,r,i,s,o),!0}for(c=1,a=new Array(h-1);c<h;c++)a[c-1]=arguments[c];u.fn.apply(u.context,a)}else{var d,f=u.length;for(c=0;c<f;c++)switch(u[c].once&&this.removeListener(t,u[c].fn,void 0,!0),h){case 1:u[c].fn.call(u[c].context);break;case 2:u[c].fn.call(u[c].context,e);break;case 3:u[c].fn.call(u[c].context,e,r);break;case 4:u[c].fn.call(u[c].context,e,r,i);break;default:if(!a)for(d=1,a=new Array(h-1);d<h;d++)a[d-1]=arguments[d];u[c].fn.apply(u[c].context,a)}}return!0},l.prototype.on=function(t,e,n){return s(this,t,e,n,!1)},l.prototype.once=function(t,e,n){return s(this,t,e,n,!0)},l.prototype.removeListener=function(t,e,r,i){var s=n?n+t:t;if(!this._events[s])return this;if(!e)return o(this,s),this;var l=this._events[s];if(l.fn)l.fn!==e||i&&!l.once||r&&l.context!==r||o(this,s);else{for(var a=0,c=[],u=l.length;a<u;a++)(l[a].fn!==e||i&&!l[a].once||r&&l[a].context!==r)&&c.push(l[a]);c.length?this._events[s]=1===c.length?c[0]:c:o(this,s)}return this},l.prototype.removeAllListeners=function(t){var e;return t?(e=n?n+t:t,this._events[e]&&o(this,e)):(this._events=new r,this._eventsCount=0),this},l.prototype.off=l.prototype.removeListener,l.prototype.addListener=l.prototype.on,l.prefixed=n,l.EventEmitter=l,t.exports=l},5090:function(t){var e=-1,n=1,r=0;function i(t,g,m,b,y){if(t===g)return t?[[r,t]]:[];if(null!=m){var A=function(t,e,n){var r="number"==typeof n?{index:n,length:0}:n.oldRange,i="number"==typeof n?null:n.newRange,s=t.length,o=e.length;if(0===r.length&&(null===i||0===i.length)){var l=r.index,a=t.slice(0,l),c=t.slice(l),u=i?i.index:null,h=l+o-s;if((null===u||u===h)&&!(h<0||h>o)){var d=e.slice(0,h);if((g=e.slice(h))===c){var f=Math.min(l,h);if((b=a.slice(0,f))===(A=d.slice(0,f)))return v(b,a.slice(f),d.slice(f),c)}}if(null===u||u===l){var p=l,g=(d=e.slice(0,p),e.slice(p));if(d===a){var m=Math.min(s-p,o-p);if((y=c.slice(c.length-m))===(x=g.slice(g.length-m)))return v(a,c.slice(0,c.length-m),g.slice(0,g.length-m),y)}}}if(r.length>0&&i&&0===i.length){var b=t.slice(0,r.index),y=t.slice(r.index+r.length);if(!(o<(f=b.length)+(m=y.length))){var A=e.slice(0,f),x=e.slice(o-m);if(b===A&&y===x)return v(b,t.slice(f,s-m),e.slice(f,o-m),y)}}return null}(t,g,m);if(A)return A}var x=o(t,g),N=t.substring(0,x);x=a(t=t.substring(x),g=g.substring(x));var E=t.substring(t.length-x),w=function(t,l){var c;if(!t)return[[n,l]];if(!l)return[[e,t]];var u=t.length>l.length?t:l,h=t.length>l.length?l:t,d=u.indexOf(h);if(-1!==d)return c=[[n,u.substring(0,d)],[r,h],[n,u.substring(d+h.length)]],t.length>l.length&&(c[0][0]=c[2][0]=e),c;if(1===h.length)return[[e,t],[n,l]];var f=function(t,e){var n=t.length>e.length?t:e,r=t.length>e.length?e:t;if(n.length<4||2*r.length<n.length)return null;function i(t,e,n){for(var r,i,s,l,c=t.substring(n,n+Math.floor(t.length/4)),u=-1,h="";-1!==(u=e.indexOf(c,u+1));){var d=o(t.substring(n),e.substring(u)),f=a(t.substring(0,n),e.substring(0,u));h.length<f+d&&(h=e.substring(u-f,u)+e.substring(u,u+d),r=t.substring(0,n-f),i=t.substring(n+d),s=e.substring(0,u-f),l=e.substring(u+d))}return 2*h.length>=t.length?[r,i,s,l,h]:null}var s,l,c,u,h,d=i(n,r,Math.ceil(n.length/4)),f=i(n,r,Math.ceil(n.length/2));return d||f?(s=f?d&&d[4].length>f[4].length?d:f:d,t.length>e.length?(l=s[0],c=s[1],u=s[2],h=s[3]):(u=s[0],h=s[1],l=s[2],c=s[3]),[l,c,u,h,s[4]]):null}(t,l);if(f){var p=f[0],g=f[1],m=f[2],b=f[3],y=f[4],v=i(p,m),A=i(g,b);return v.concat([[r,y]],A)}return function(t,r){for(var i=t.length,o=r.length,l=Math.ceil((i+o)/2),a=l,c=2*l,u=new Array(c),h=new Array(c),d=0;d<c;d++)u[d]=-1,h[d]=-1;u[a+1]=0,h[a+1]=0;for(var f=i-o,p=f%2!=0,g=0,m=0,b=0,y=0,v=0;v<l;v++){for(var A=-v+g;A<=v-m;A+=2){for(var x=a+A,N=(_=A===-v||A!==v&&u[x-1]<u[x+1]?u[x+1]:u[x-1]+1)-A;_<i&&N<o&&t.charAt(_)===r.charAt(N);)_++,N++;if(u[x]=_,_>i)m+=2;else if(N>o)g+=2;else if(p&&(q=a+f-A)>=0&&q<c&&-1!==h[q]&&_>=(w=i-h[q]))return s(t,r,_,N)}for(var E=-v+b;E<=v-y;E+=2){for(var w,q=a+E,k=(w=E===-v||E!==v&&h[q-1]<h[q+1]?h[q+1]:h[q-1]+1)-E;w<i&&k<o&&t.charAt(i-w-1)===r.charAt(o-k-1);)w++,k++;if(h[q]=w,w>i)y+=2;else if(k>o)b+=2;else if(!p){var _;if((x=a+f-E)>=0&&x<c&&-1!==u[x])if(N=a+(_=u[x])-x,_>=(w=i-w))return s(t,r,_,N)}}}return[[e,t],[n,r]]}(t,l)}(t=t.substring(0,t.length-x),g=g.substring(0,g.length-x));return N&&w.unshift([r,N]),E&&w.push([r,E]),p(w,y),b&&function(t){for(var i=!1,s=[],o=0,g=null,m=0,b=0,y=0,v=0,A=0;m<t.length;)t[m][0]==r?(s[o++]=m,b=v,y=A,v=0,A=0,g=t[m][1]):(t[m][0]==n?v+=t[m][1].length:A+=t[m][1].length,g&&g.length<=Math.max(b,y)&&g.length<=Math.max(v,A)&&(t.splice(s[o-1],0,[e,g]),t[s[o-1]+1][0]=n,o--,m=--o>0?s[o-1]:-1,b=0,y=0,v=0,A=0,g=null,i=!0)),m++;for(i&&p(t),function(t){function e(t,e){if(!t||!e)return 6;var n=t.charAt(t.length-1),r=e.charAt(0),i=n.match(c),s=r.match(c),o=i&&n.match(u),l=s&&r.match(u),a=o&&n.match(h),p=l&&r.match(h),g=a&&t.match(d),m=p&&e.match(f);return g||m?5:a||p?4:i&&!o&&l?3:o||l?2:i||s?1:0}for(var n=1;n<t.length-1;){if(t[n-1][0]==r&&t[n+1][0]==r){var i=t[n-1][1],s=t[n][1],o=t[n+1][1],l=a(i,s);if(l){var p=s.substring(s.length-l);i=i.substring(0,i.length-l),s=p+s.substring(0,s.length-l),o=p+o}for(var g=i,m=s,b=o,y=e(i,s)+e(s,o);s.charAt(0)===o.charAt(0);){i+=s.charAt(0),s=s.substring(1)+o.charAt(0),o=o.substring(1);var v=e(i,s)+e(s,o);v>=y&&(y=v,g=i,m=s,b=o)}t[n-1][1]!=g&&(g?t[n-1][1]=g:(t.splice(n-1,1),n--),t[n][1]=m,b?t[n+1][1]=b:(t.splice(n+1,1),n--))}n++}}(t),m=1;m<t.length;){if(t[m-1][0]==e&&t[m][0]==n){var x=t[m-1][1],N=t[m][1],E=l(x,N),w=l(N,x);E>=w?(E>=x.length/2||E>=N.length/2)&&(t.splice(m,0,[r,N.substring(0,E)]),t[m-1][1]=x.substring(0,x.length-E),t[m+1][1]=N.substring(E),m++):(w>=x.length/2||w>=N.length/2)&&(t.splice(m,0,[r,x.substring(0,w)]),t[m-1][0]=n,t[m-1][1]=N.substring(0,N.length-w),t[m+1][0]=e,t[m+1][1]=x.substring(w),m++),m++}m++}}(w),w}function s(t,e,n,r){var s=t.substring(0,n),o=e.substring(0,r),l=t.substring(n),a=e.substring(r),c=i(s,o),u=i(l,a);return c.concat(u)}function o(t,e){if(!t||!e||t.charAt(0)!==e.charAt(0))return 0;for(var n=0,r=Math.min(t.length,e.length),i=r,s=0;n<i;)t.substring(s,i)==e.substring(s,i)?s=n=i:r=i,i=Math.floor((r-n)/2+n);return g(t.charCodeAt(i-1))&&i--,i}function l(t,e){var n=t.length,r=e.length;if(0==n||0==r)return 0;n>r?t=t.substring(n-r):n<r&&(e=e.substring(0,n));var i=Math.min(n,r);if(t==e)return i;for(var s=0,o=1;;){var l=t.substring(i-o),a=e.indexOf(l);if(-1==a)return s;o+=a,0!=a&&t.substring(i-o)!=e.substring(0,o)||(s=o,o++)}}function a(t,e){if(!t||!e||t.slice(-1)!==e.slice(-1))return 0;for(var n=0,r=Math.min(t.length,e.length),i=r,s=0;n<i;)t.substring(t.length-i,t.length-s)==e.substring(e.length-i,e.length-s)?s=n=i:r=i,i=Math.floor((r-n)/2+n);return m(t.charCodeAt(t.length-i))&&i--,i}var c=/[^a-zA-Z0-9]/,u=/\s/,h=/[\r\n]/,d=/\n\r?\n$/,f=/^\r?\n\r?\n/;function p(t,i){t.push([r,""]);for(var s,l=0,c=0,u=0,h="",d="";l<t.length;)if(l<t.length-1&&!t[l][1])t.splice(l,1);else switch(t[l][0]){case n:u++,d+=t[l][1],l++;break;case e:c++,h+=t[l][1],l++;break;case r:var f=l-u-c-1;if(i){if(f>=0&&y(t[f][1])){var g=t[f][1].slice(-1);if(t[f][1]=t[f][1].slice(0,-1),h=g+h,d=g+d,!t[f][1]){t.splice(f,1),l--;var m=f-1;t[m]&&t[m][0]===n&&(u++,d=t[m][1]+d,m--),t[m]&&t[m][0]===e&&(c++,h=t[m][1]+h,m--),f=m}}b(t[l][1])&&(g=t[l][1].charAt(0),t[l][1]=t[l][1].slice(1),h+=g,d+=g)}if(l<t.length-1&&!t[l][1]){t.splice(l,1);break}if(h.length>0||d.length>0){h.length>0&&d.length>0&&(0!==(s=o(d,h))&&(f>=0?t[f][1]+=d.substring(0,s):(t.splice(0,0,[r,d.substring(0,s)]),l++),d=d.substring(s),h=h.substring(s)),0!==(s=a(d,h))&&(t[l][1]=d.substring(d.length-s)+t[l][1],d=d.substring(0,d.length-s),h=h.substring(0,h.length-s)));var v=u+c;0===h.length&&0===d.length?(t.splice(l-v,v),l-=v):0===h.length?(t.splice(l-v,v,[n,d]),l=l-v+1):0===d.length?(t.splice(l-v,v,[e,h]),l=l-v+1):(t.splice(l-v,v,[e,h],[n,d]),l=l-v+2)}0!==l&&t[l-1][0]===r?(t[l-1][1]+=t[l][1],t.splice(l,1)):l++,u=0,c=0,h="",d=""}""===t[t.length-1][1]&&t.pop();var A=!1;for(l=1;l<t.length-1;)t[l-1][0]===r&&t[l+1][0]===r&&(t[l][1].substring(t[l][1].length-t[l-1][1].length)===t[l-1][1]?(t[l][1]=t[l-1][1]+t[l][1].substring(0,t[l][1].length-t[l-1][1].length),t[l+1][1]=t[l-1][1]+t[l+1][1],t.splice(l-1,1),A=!0):t[l][1].substring(0,t[l+1][1].length)==t[l+1][1]&&(t[l-1][1]+=t[l+1][1],t[l][1]=t[l][1].substring(t[l+1][1].length)+t[l+1][1],t.splice(l+1,1),A=!0)),l++;A&&p(t,i)}function g(t){return t>=55296&&t<=56319}function m(t){return t>=56320&&t<=57343}function b(t){return m(t.charCodeAt(0))}function y(t){return g(t.charCodeAt(t.length-1))}function v(t,i,s,o){return y(t)||b(o)?null:function(t){for(var e=[],n=0;n<t.length;n++)t[n][1].length>0&&e.push(t[n]);return e}([[r,t],[e,i],[n,s],[r,o]])}function A(t,e,n,r){return i(t,e,n,r,!0)}A.INSERT=n,A.DELETE=e,A.EQUAL=r,t.exports=A},9629:function(t,e,n){t=n.nmd(t);var r="__lodash_hash_undefined__",i=9007199254740991,s="[object Arguments]",o="[object Boolean]",l="[object Date]",a="[object Function]",c="[object GeneratorFunction]",u="[object Map]",h="[object Number]",d="[object Object]",f="[object Promise]",p="[object RegExp]",g="[object Set]",m="[object String]",b="[object Symbol]",y="[object WeakMap]",v="[object ArrayBuffer]",A="[object DataView]",x="[object Float32Array]",N="[object Float64Array]",E="[object Int8Array]",w="[object Int16Array]",q="[object Int32Array]",k="[object Uint8Array]",_="[object Uint8ClampedArray]",L="[object Uint16Array]",S="[object Uint32Array]",O=/\w*$/,T=/^\[object .+?Constructor\]$/,j=/^(?:0|[1-9]\d*)$/,C={};C[s]=C["[object Array]"]=C[v]=C[A]=C[o]=C[l]=C[x]=C[N]=C[E]=C[w]=C[q]=C[u]=C[h]=C[d]=C[p]=C[g]=C[m]=C[b]=C[k]=C[_]=C[L]=C[S]=!0,C["[object Error]"]=C[a]=C[y]=!1;var R="object"==typeof n.g&&n.g&&n.g.Object===Object&&n.g,I="object"==typeof self&&self&&self.Object===Object&&self,B=R||I||Function("return this")(),M=e&&!e.nodeType&&e,U=M&&t&&!t.nodeType&&t,D=U&&U.exports===M;function P(t,e){return t.set(e[0],e[1]),t}function z(t,e){return t.add(e),t}function F(t,e,n,r){var i=-1,s=t?t.length:0;for(r&&s&&(n=t[++i]);++i<s;)n=e(n,t[i],i,t);return n}function H(t){var e=!1;if(null!=t&&"function"!=typeof t.toString)try{e=!!(t+"")}catch(t){}return e}function $(t){var e=-1,n=Array(t.size);return t.forEach((function(t,r){n[++e]=[r,t]})),n}function V(t,e){return function(n){return t(e(n))}}function K(t){var e=-1,n=Array(t.size);return t.forEach((function(t){n[++e]=t})),n}var W,Z=Array.prototype,G=Function.prototype,X=Object.prototype,Q=B["__core-js_shared__"],J=(W=/[^.]+$/.exec(Q&&Q.keys&&Q.keys.IE_PROTO||""))?"Symbol(src)_1."+W:"",Y=G.toString,tt=X.hasOwnProperty,et=X.toString,nt=RegExp("^"+Y.call(tt).replace(/[\\^$.*+?()[\]{}|]/g,"\\$&").replace(/hasOwnProperty|(function).*?(?=\\\()| for .+?(?=\\\])/g,"$1.*?")+"$"),rt=D?B.Buffer:void 0,it=B.Symbol,st=B.Uint8Array,ot=V(Object.getPrototypeOf,Object),lt=Object.create,at=X.propertyIsEnumerable,ct=Z.splice,ut=Object.getOwnPropertySymbols,ht=rt?rt.isBuffer:void 0,dt=V(Object.keys,Object),ft=Bt(B,"DataView"),pt=Bt(B,"Map"),gt=Bt(B,"Promise"),mt=Bt(B,"Set"),bt=Bt(B,"WeakMap"),yt=Bt(Object,"create"),vt=zt(ft),At=zt(pt),xt=zt(gt),Nt=zt(mt),Et=zt(bt),wt=it?it.prototype:void 0,qt=wt?wt.valueOf:void 0;function kt(t){var e=-1,n=t?t.length:0;for(this.clear();++e<n;){var r=t[e];this.set(r[0],r[1])}}function _t(t){var e=-1,n=t?t.length:0;for(this.clear();++e<n;){var r=t[e];this.set(r[0],r[1])}}function Lt(t){var e=-1,n=t?t.length:0;for(this.clear();++e<n;){var r=t[e];this.set(r[0],r[1])}}function St(t){this.__data__=new _t(t)}function Ot(t,e,n){var r=t[e];tt.call(t,e)&&Ft(r,n)&&(void 0!==n||e in t)||(t[e]=n)}function Tt(t,e){for(var n=t.length;n--;)if(Ft(t[n][0],e))return n;return-1}function jt(t,e,n,r,i,f,y){var T;if(r&&(T=f?r(t,i,f,y):r(t)),void 0!==T)return T;if(!Wt(t))return t;var j=Ht(t);if(j){if(T=function(t){var e=t.length,n=t.constructor(e);return e&&"string"==typeof t[0]&&tt.call(t,"index")&&(n.index=t.index,n.input=t.input),n}(t),!e)return function(t,e){var n=-1,r=t.length;for(e||(e=Array(r));++n<r;)e[n]=t[n];return e}(t,T)}else{var R=Ut(t),I=R==a||R==c;if(Vt(t))return function(t,e){if(e)return t.slice();var n=new t.constructor(t.length);return t.copy(n),n}(t,e);if(R==d||R==s||I&&!f){if(H(t))return f?t:{};if(T=function(t){return"function"!=typeof t.constructor||Pt(t)?{}:Wt(e=ot(t))?lt(e):{};var e}(I?{}:t),!e)return function(t,e){return Rt(t,Mt(t),e)}(t,function(t,e){return t&&Rt(e,Zt(e),t)}(T,t))}else{if(!C[R])return f?t:{};T=function(t,e,n,r){var i,s=t.constructor;switch(e){case v:return Ct(t);case o:case l:return new s(+t);case A:return function(t,e){var n=e?Ct(t.buffer):t.buffer;return new t.constructor(n,t.byteOffset,t.byteLength)}(t,r);case x:case N:case E:case w:case q:case k:case _:case L:case S:return function(t,e){var n=e?Ct(t.buffer):t.buffer;return new t.constructor(n,t.byteOffset,t.length)}(t,r);case u:return function(t,e,n){return F(e?n($(t),!0):$(t),P,new t.constructor)}(t,r,n);case h:case m:return new s(t);case p:return function(t){var e=new t.constructor(t.source,O.exec(t));return e.lastIndex=t.lastIndex,e}(t);case g:return function(t,e,n){return F(e?n(K(t),!0):K(t),z,new t.constructor)}(t,r,n);case b:return i=t,qt?Object(qt.call(i)):{}}}(t,R,jt,e)}}y||(y=new St);var B=y.get(t);if(B)return B;if(y.set(t,T),!j)var M=n?function(t){return function(t,e,n){var r=e(t);return Ht(t)?r:function(t,e){for(var n=-1,r=e.length,i=t.length;++n<r;)t[i+n]=e[n];return t}(r,n(t))}(t,Zt,Mt)}(t):Zt(t);return function(t,e){for(var n=-1,r=t?t.length:0;++n<r&&!1!==e(t[n],n););}(M||t,(function(i,s){M&&(i=t[s=i]),Ot(T,s,jt(i,e,n,r,s,t,y))})),T}function Ct(t){var e=new t.constructor(t.byteLength);return new st(e).set(new st(t)),e}function Rt(t,e,n,r){n||(n={});for(var i=-1,s=e.length;++i<s;){var o=e[i],l=r?r(n[o],t[o],o,n,t):void 0;Ot(n,o,void 0===l?t[o]:l)}return n}function It(t,e){var n,r,i=t.__data__;return("string"==(r=typeof(n=e))||"number"==r||"symbol"==r||"boolean"==r?"__proto__"!==n:null===n)?i["string"==typeof e?"string":"hash"]:i.map}function Bt(t,e){var n=function(t,e){return null==t?void 0:t[e]}(t,e);return function(t){return!(!Wt(t)||(e=t,J&&J in e))&&(Kt(t)||H(t)?nt:T).test(zt(t));var e}(n)?n:void 0}kt.prototype.clear=function(){this.__data__=yt?yt(null):{}},kt.prototype.delete=function(t){return this.has(t)&&delete this.__data__[t]},kt.prototype.get=function(t){var e=this.__data__;if(yt){var n=e[t];return n===r?void 0:n}return tt.call(e,t)?e[t]:void 0},kt.prototype.has=function(t){var e=this.__data__;return yt?void 0!==e[t]:tt.call(e,t)},kt.prototype.set=function(t,e){return this.__data__[t]=yt&&void 0===e?r:e,this},_t.prototype.clear=function(){this.__data__=[]},_t.prototype.delete=function(t){var e=this.__data__,n=Tt(e,t);return!(n<0||(n==e.length-1?e.pop():ct.call(e,n,1),0))},_t.prototype.get=function(t){var e=this.__data__,n=Tt(e,t);return n<0?void 0:e[n][1]},_t.prototype.has=function(t){return Tt(this.__data__,t)>-1},_t.prototype.set=function(t,e){var n=this.__data__,r=Tt(n,t);return r<0?n.push([t,e]):n[r][1]=e,this},Lt.prototype.clear=function(){this.__data__={hash:new kt,map:new(pt||_t),string:new kt}},Lt.prototype.delete=function(t){return It(this,t).delete(t)},Lt.prototype.get=function(t){return It(this,t).get(t)},Lt.prototype.has=function(t){return It(this,t).has(t)},Lt.prototype.set=function(t,e){return It(this,t).set(t,e),this},St.prototype.clear=function(){this.__data__=new _t},St.prototype.delete=function(t){return this.__data__.delete(t)},St.prototype.get=function(t){return this.__data__.get(t)},St.prototype.has=function(t){return this.__data__.has(t)},St.prototype.set=function(t,e){var n=this.__data__;if(n instanceof _t){var r=n.__data__;if(!pt||r.length<199)return r.push([t,e]),this;n=this.__data__=new Lt(r)}return n.set(t,e),this};var Mt=ut?V(ut,Object):function(){return[]},Ut=function(t){return et.call(t)};function Dt(t,e){return!!(e=null==e?i:e)&&("number"==typeof t||j.test(t))&&t>-1&&t%1==0&&t<e}function Pt(t){var e=t&&t.constructor;return t===("function"==typeof e&&e.prototype||X)}function zt(t){if(null!=t){try{return Y.call(t)}catch(t){}try{return t+""}catch(t){}}return""}function Ft(t,e){return t===e||t!=t&&e!=e}(ft&&Ut(new ft(new ArrayBuffer(1)))!=A||pt&&Ut(new pt)!=u||gt&&Ut(gt.resolve())!=f||mt&&Ut(new mt)!=g||bt&&Ut(new bt)!=y)&&(Ut=function(t){var e=et.call(t),n=e==d?t.constructor:void 0,r=n?zt(n):void 0;if(r)switch(r){case vt:return A;case At:return u;case xt:return f;case Nt:return g;case Et:return y}return e});var Ht=Array.isArray;function $t(t){return null!=t&&function(t){return"number"==typeof t&&t>-1&&t%1==0&&t<=i}(t.length)&&!Kt(t)}var Vt=ht||function(){return!1};function Kt(t){var e=Wt(t)?et.call(t):"";return e==a||e==c}function Wt(t){var e=typeof t;return!!t&&("object"==e||"function"==e)}function Zt(t){return $t(t)?function(t,e){var n=Ht(t)||function(t){return function(t){return function(t){return!!t&&"object"==typeof t}(t)&&$t(t)}(t)&&tt.call(t,"callee")&&(!at.call(t,"callee")||et.call(t)==s)}(t)?function(t,e){for(var n=-1,r=Array(t);++n<t;)r[n]=e(n);return r}(t.length,String):[],r=n.length,i=!!r;for(var o in t)!e&&!tt.call(t,o)||i&&("length"==o||Dt(o,r))||n.push(o);return n}(t):function(t){if(!Pt(t))return dt(t);var e=[];for(var n in Object(t))tt.call(t,n)&&"constructor"!=n&&e.push(n);return e}(t)}t.exports=function(t){return jt(t,!0,!0)}},4162:function(t,e,n){t=n.nmd(t);var r="__lodash_hash_undefined__",i=1,s=2,o=9007199254740991,l="[object Arguments]",a="[object Array]",c="[object AsyncFunction]",u="[object Boolean]",h="[object Date]",d="[object Error]",f="[object Function]",p="[object GeneratorFunction]",g="[object Map]",m="[object Number]",b="[object Null]",y="[object Object]",v="[object Promise]",A="[object Proxy]",x="[object RegExp]",N="[object Set]",E="[object String]",w="[object Undefined]",q="[object WeakMap]",k="[object ArrayBuffer]",_="[object DataView]",L=/^\[object .+?Constructor\]$/,S=/^(?:0|[1-9]\d*)$/,O={};O["[object Float32Array]"]=O["[object Float64Array]"]=O["[object Int8Array]"]=O["[object Int16Array]"]=O["[object Int32Array]"]=O["[object Uint8Array]"]=O["[object Uint8ClampedArray]"]=O["[object Uint16Array]"]=O["[object Uint32Array]"]=!0,O[l]=O[a]=O[k]=O[u]=O[_]=O[h]=O[d]=O[f]=O[g]=O[m]=O[y]=O[x]=O[N]=O[E]=O[q]=!1;var T="object"==typeof n.g&&n.g&&n.g.Object===Object&&n.g,j="object"==typeof self&&self&&self.Object===Object&&self,C=T||j||Function("return this")(),R=e&&!e.nodeType&&e,I=R&&t&&!t.nodeType&&t,B=I&&I.exports===R,M=B&&T.process,U=function(){try{return M&&M.binding&&M.binding("util")}catch(t){}}(),D=U&&U.isTypedArray;function P(t,e){for(var n=-1,r=null==t?0:t.length;++n<r;)if(e(t[n],n,t))return!0;return!1}function z(t){var e=-1,n=Array(t.size);return t.forEach((function(t,r){n[++e]=[r,t]})),n}function F(t){var e=-1,n=Array(t.size);return t.forEach((function(t){n[++e]=t})),n}var H,$,V,K=Array.prototype,W=Function.prototype,Z=Object.prototype,G=C["__core-js_shared__"],X=W.toString,Q=Z.hasOwnProperty,J=(H=/[^.]+$/.exec(G&&G.keys&&G.keys.IE_PROTO||""))?"Symbol(src)_1."+H:"",Y=Z.toString,tt=RegExp("^"+X.call(Q).replace(/[\\^$.*+?()[\]{}|]/g,"\\$&").replace(/hasOwnProperty|(function).*?(?=\\\()| for .+?(?=\\\])/g,"$1.*?")+"$"),et=B?C.Buffer:void 0,nt=C.Symbol,rt=C.Uint8Array,it=Z.propertyIsEnumerable,st=K.splice,ot=nt?nt.toStringTag:void 0,lt=Object.getOwnPropertySymbols,at=et?et.isBuffer:void 0,ct=($=Object.keys,V=Object,function(t){return $(V(t))}),ut=It(C,"DataView"),ht=It(C,"Map"),dt=It(C,"Promise"),ft=It(C,"Set"),pt=It(C,"WeakMap"),gt=It(Object,"create"),mt=Dt(ut),bt=Dt(ht),yt=Dt(dt),vt=Dt(ft),At=Dt(pt),xt=nt?nt.prototype:void 0,Nt=xt?xt.valueOf:void 0;function Et(t){var e=-1,n=null==t?0:t.length;for(this.clear();++e<n;){var r=t[e];this.set(r[0],r[1])}}function wt(t){var e=-1,n=null==t?0:t.length;for(this.clear();++e<n;){var r=t[e];this.set(r[0],r[1])}}function qt(t){var e=-1,n=null==t?0:t.length;for(this.clear();++e<n;){var r=t[e];this.set(r[0],r[1])}}function kt(t){var e=-1,n=null==t?0:t.length;for(this.__data__=new qt;++e<n;)this.add(t[e])}function _t(t){var e=this.__data__=new wt(t);this.size=e.size}function Lt(t,e){for(var n=t.length;n--;)if(Pt(t[n][0],e))return n;return-1}function St(t){return null==t?void 0===t?w:b:ot&&ot in Object(t)?function(t){var e=Q.call(t,ot),n=t[ot];try{t[ot]=void 0;var r=!0}catch(t){}var i=Y.call(t);return r&&(e?t[ot]=n:delete t[ot]),i}(t):function(t){return Y.call(t)}(t)}function Ot(t){return Wt(t)&&St(t)==l}function Tt(t,e,n,r,o){return t===e||(null==t||null==e||!Wt(t)&&!Wt(e)?t!=t&&e!=e:function(t,e,n,r,o,c){var f=Ft(t),p=Ft(e),b=f?a:Mt(t),v=p?a:Mt(e),A=(b=b==l?y:b)==y,w=(v=v==l?y:v)==y,q=b==v;if(q&&Ht(t)){if(!Ht(e))return!1;f=!0,A=!1}if(q&&!A)return c||(c=new _t),f||Zt(t)?jt(t,e,n,r,o,c):function(t,e,n,r,o,l,a){switch(n){case _:if(t.byteLength!=e.byteLength||t.byteOffset!=e.byteOffset)return!1;t=t.buffer,e=e.buffer;case k:return!(t.byteLength!=e.byteLength||!l(new rt(t),new rt(e)));case u:case h:case m:return Pt(+t,+e);case d:return t.name==e.name&&t.message==e.message;case x:case E:return t==e+"";case g:var c=z;case N:var f=r&i;if(c||(c=F),t.size!=e.size&&!f)return!1;var p=a.get(t);if(p)return p==e;r|=s,a.set(t,e);var b=jt(c(t),c(e),r,o,l,a);return a.delete(t),b;case"[object Symbol]":if(Nt)return Nt.call(t)==Nt.call(e)}return!1}(t,e,b,n,r,o,c);if(!(n&i)){var L=A&&Q.call(t,"__wrapped__"),S=w&&Q.call(e,"__wrapped__");if(L||S){var O=L?t.value():t,T=S?e.value():e;return c||(c=new _t),o(O,T,n,r,c)}}return!!q&&(c||(c=new _t),function(t,e,n,r,s,o){var l=n&i,a=Ct(t),c=a.length;if(c!=Ct(e).length&&!l)return!1;for(var u=c;u--;){var h=a[u];if(!(l?h in e:Q.call(e,h)))return!1}var d=o.get(t);if(d&&o.get(e))return d==e;var f=!0;o.set(t,e),o.set(e,t);for(var p=l;++u<c;){var g=t[h=a[u]],m=e[h];if(r)var b=l?r(m,g,h,e,t,o):r(g,m,h,t,e,o);if(!(void 0===b?g===m||s(g,m,n,r,o):b)){f=!1;break}p||(p="constructor"==h)}if(f&&!p){var y=t.constructor,v=e.constructor;y==v||!("constructor"in t)||!("constructor"in e)||"function"==typeof y&&y instanceof y&&"function"==typeof v&&v instanceof v||(f=!1)}return o.delete(t),o.delete(e),f}(t,e,n,r,o,c))}(t,e,n,r,Tt,o))}function jt(t,e,n,r,o,l){var a=n&i,c=t.length,u=e.length;if(c!=u&&!(a&&u>c))return!1;var h=l.get(t);if(h&&l.get(e))return h==e;var d=-1,f=!0,p=n&s?new kt:void 0;for(l.set(t,e),l.set(e,t);++d<c;){var g=t[d],m=e[d];if(r)var b=a?r(m,g,d,e,t,l):r(g,m,d,t,e,l);if(void 0!==b){if(b)continue;f=!1;break}if(p){if(!P(e,(function(t,e){if(i=e,!p.has(i)&&(g===t||o(g,t,n,r,l)))return p.push(e);var i}))){f=!1;break}}else if(g!==m&&!o(g,m,n,r,l)){f=!1;break}}return l.delete(t),l.delete(e),f}function Ct(t){return function(t,e,n){var r=e(t);return Ft(t)?r:function(t,e){for(var n=-1,r=e.length,i=t.length;++n<r;)t[i+n]=e[n];return t}(r,n(t))}(t,Gt,Bt)}function Rt(t,e){var n,r,i=t.__data__;return("string"==(r=typeof(n=e))||"number"==r||"symbol"==r||"boolean"==r?"__proto__"!==n:null===n)?i["string"==typeof e?"string":"hash"]:i.map}function It(t,e){var n=function(t,e){return null==t?void 0:t[e]}(t,e);return function(t){return!(!Kt(t)||function(t){return!!J&&J in t}(t))&&($t(t)?tt:L).test(Dt(t))}(n)?n:void 0}Et.prototype.clear=function(){this.__data__=gt?gt(null):{},this.size=0},Et.prototype.delete=function(t){var e=this.has(t)&&delete this.__data__[t];return this.size-=e?1:0,e},Et.prototype.get=function(t){var e=this.__data__;if(gt){var n=e[t];return n===r?void 0:n}return Q.call(e,t)?e[t]:void 0},Et.prototype.has=function(t){var e=this.__data__;return gt?void 0!==e[t]:Q.call(e,t)},Et.prototype.set=function(t,e){var n=this.__data__;return this.size+=this.has(t)?0:1,n[t]=gt&&void 0===e?r:e,this},wt.prototype.clear=function(){this.__data__=[],this.size=0},wt.prototype.delete=function(t){var e=this.__data__,n=Lt(e,t);return!(n<0||(n==e.length-1?e.pop():st.call(e,n,1),--this.size,0))},wt.prototype.get=function(t){var e=this.__data__,n=Lt(e,t);return n<0?void 0:e[n][1]},wt.prototype.has=function(t){return Lt(this.__data__,t)>-1},wt.prototype.set=function(t,e){var n=this.__data__,r=Lt(n,t);return r<0?(++this.size,n.push([t,e])):n[r][1]=e,this},qt.prototype.clear=function(){this.size=0,this.__data__={hash:new Et,map:new(ht||wt),string:new Et}},qt.prototype.delete=function(t){var e=Rt(this,t).delete(t);return this.size-=e?1:0,e},qt.prototype.get=function(t){return Rt(this,t).get(t)},qt.prototype.has=function(t){return Rt(this,t).has(t)},qt.prototype.set=function(t,e){var n=Rt(this,t),r=n.size;return n.set(t,e),this.size+=n.size==r?0:1,this},kt.prototype.add=kt.prototype.push=function(t){return this.__data__.set(t,r),this},kt.prototype.has=function(t){return this.__data__.has(t)},_t.prototype.clear=function(){this.__data__=new wt,this.size=0},_t.prototype.delete=function(t){var e=this.__data__,n=e.delete(t);return this.size=e.size,n},_t.prototype.get=function(t){return this.__data__.get(t)},_t.prototype.has=function(t){return this.__data__.has(t)},_t.prototype.set=function(t,e){var n=this.__data__;if(n instanceof wt){var r=n.__data__;if(!ht||r.length<199)return r.push([t,e]),this.size=++n.size,this;n=this.__data__=new qt(r)}return n.set(t,e),this.size=n.size,this};var Bt=lt?function(t){return null==t?[]:(t=Object(t),function(e,n){for(var r=-1,i=null==e?0:e.length,s=0,o=[];++r<i;){var l=e[r];a=l,it.call(t,a)&&(o[s++]=l)}var a;return o}(lt(t)))}:function(){return[]},Mt=St;function Ut(t,e){return!!(e=null==e?o:e)&&("number"==typeof t||S.test(t))&&t>-1&&t%1==0&&t<e}function Dt(t){if(null!=t){try{return X.call(t)}catch(t){}try{return t+""}catch(t){}}return""}function Pt(t,e){return t===e||t!=t&&e!=e}(ut&&Mt(new ut(new ArrayBuffer(1)))!=_||ht&&Mt(new ht)!=g||dt&&Mt(dt.resolve())!=v||ft&&Mt(new ft)!=N||pt&&Mt(new pt)!=q)&&(Mt=function(t){var e=St(t),n=e==y?t.constructor:void 0,r=n?Dt(n):"";if(r)switch(r){case mt:return _;case bt:return g;case yt:return v;case vt:return N;case At:return q}return e});var zt=Ot(function(){return arguments}())?Ot:function(t){return Wt(t)&&Q.call(t,"callee")&&!it.call(t,"callee")},Ft=Array.isArray,Ht=at||function(){return!1};function $t(t){if(!Kt(t))return!1;var e=St(t);return e==f||e==p||e==c||e==A}function Vt(t){return"number"==typeof t&&t>-1&&t%1==0&&t<=o}function Kt(t){var e=typeof t;return null!=t&&("object"==e||"function"==e)}function Wt(t){return null!=t&&"object"==typeof t}var Zt=D?function(t){return function(e){return t(e)}}(D):function(t){return Wt(t)&&Vt(t.length)&&!!O[St(t)]};function Gt(t){return null!=(e=t)&&Vt(e.length)&&!$t(e)?function(t,e){var n=Ft(t),r=!n&&zt(t),i=!n&&!r&&Ht(t),s=!n&&!r&&!i&&Zt(t),o=n||r||i||s,l=o?function(t,e){for(var n=-1,r=Array(t);++n<t;)r[n]=e(n);return r}(t.length,String):[],a=l.length;for(var c in t)!e&&!Q.call(t,c)||o&&("length"==c||i&&("offset"==c||"parent"==c)||s&&("buffer"==c||"byteLength"==c||"byteOffset"==c)||Ut(c,a))||l.push(c);return l}(t):function(t){if(n=(e=t)&&e.constructor,e!==("function"==typeof n&&n.prototype||Z))return ct(t);var e,n,r=[];for(var i in Object(t))Q.call(t,i)&&"constructor"!=i&&r.push(i);return r}(t);var e}t.exports=function(t,e){return Tt(t,e)}},1270:function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});const r=n(9629),i=n(4162);var s;!function(t){t.compose=function(t={},e={},n=!1){"object"!=typeof t&&(t={}),"object"!=typeof e&&(e={});let i=r(e);n||(i=Object.keys(i).reduce(((t,e)=>(null!=i[e]&&(t[e]=i[e]),t)),{}));for(const n in t)void 0!==t[n]&&void 0===e[n]&&(i[n]=t[n]);return Object.keys(i).length>0?i:void 0},t.diff=function(t={},e={}){"object"!=typeof t&&(t={}),"object"!=typeof e&&(e={});const n=Object.keys(t).concat(Object.keys(e)).reduce(((n,r)=>(i(t[r],e[r])||(n[r]=void 0===e[r]?null:e[r]),n)),{});return Object.keys(n).length>0?n:void 0},t.invert=function(t={},e={}){t=t||{};const n=Object.keys(e).reduce(((n,r)=>(e[r]!==t[r]&&void 0!==t[r]&&(n[r]=e[r]),n)),{});return Object.keys(t).reduce(((n,r)=>(t[r]!==e[r]&&void 0===e[r]&&(n[r]=null),n)),n)},t.transform=function(t,e,n=!1){if("object"!=typeof t)return e;if("object"!=typeof e)return;if(!n)return e;const r=Object.keys(e).reduce(((n,r)=>(void 0===t[r]&&(n[r]=e[r]),n)),{});return Object.keys(r).length>0?r:void 0}}(s||(s={})),e.default=s},5232:function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0}),e.AttributeMap=e.OpIterator=e.Op=void 0;const r=n(5090),i=n(9629),s=n(4162),o=n(1270);e.AttributeMap=o.default;const l=n(4123);e.Op=l.default;const a=n(7033);e.OpIterator=a.default;const c=String.fromCharCode(0),u=(t,e)=>{if("object"!=typeof t||null===t)throw new Error("cannot retain a "+typeof t);if("object"!=typeof e||null===e)throw new Error("cannot retain a "+typeof e);const n=Object.keys(t)[0];if(!n||n!==Object.keys(e)[0])throw new Error(`embed types not matched: ${n} != ${Object.keys(e)[0]}`);return[n,t[n],e[n]]};class h{constructor(t){Array.isArray(t)?this.ops=t:null!=t&&Array.isArray(t.ops)?this.ops=t.ops:this.ops=[]}static registerEmbed(t,e){this.handlers[t]=e}static unregisterEmbed(t){delete this.handlers[t]}static getHandler(t){const e=this.handlers[t];if(!e)throw new Error(`no handlers for embed type "${t}"`);return e}insert(t,e){const n={};return"string"==typeof t&&0===t.length?this:(n.insert=t,null!=e&&"object"==typeof e&&Object.keys(e).length>0&&(n.attributes=e),this.push(n))}delete(t){return t<=0?this:this.push({delete:t})}retain(t,e){if("number"==typeof t&&t<=0)return this;const n={retain:t};return null!=e&&"object"==typeof e&&Object.keys(e).length>0&&(n.attributes=e),this.push(n)}push(t){let e=this.ops.length,n=this.ops[e-1];if(t=i(t),"object"==typeof n){if("number"==typeof t.delete&&"number"==typeof n.delete)return this.ops[e-1]={delete:n.delete+t.delete},this;if("number"==typeof n.delete&&null!=t.insert&&(e-=1,n=this.ops[e-1],"object"!=typeof n))return this.ops.unshift(t),this;if(s(t.attributes,n.attributes)){if("string"==typeof t.insert&&"string"==typeof n.insert)return this.ops[e-1]={insert:n.insert+t.insert},"object"==typeof t.attributes&&(this.ops[e-1].attributes=t.attributes),this;if("number"==typeof t.retain&&"number"==typeof n.retain)return this.ops[e-1]={retain:n.retain+t.retain},"object"==typeof t.attributes&&(this.ops[e-1].attributes=t.attributes),this}}return e===this.ops.length?this.ops.push(t):this.ops.splice(e,0,t),this}chop(){const t=this.ops[this.ops.length-1];return t&&"number"==typeof t.retain&&!t.attributes&&this.ops.pop(),this}filter(t){return this.ops.filter(t)}forEach(t){this.ops.forEach(t)}map(t){return this.ops.map(t)}partition(t){const e=[],n=[];return this.forEach((r=>{(t(r)?e:n).push(r)})),[e,n]}reduce(t,e){return this.ops.reduce(t,e)}changeLength(){return this.reduce(((t,e)=>e.insert?t+l.default.length(e):e.delete?t-e.delete:t),0)}length(){return this.reduce(((t,e)=>t+l.default.length(e)),0)}slice(t=0,e=1/0){const n=[],r=new a.default(this.ops);let i=0;for(;i<e&&r.hasNext();){let s;i<t?s=r.next(t-i):(s=r.next(e-i),n.push(s)),i+=l.default.length(s)}return new h(n)}compose(t){const e=new a.default(this.ops),n=new a.default(t.ops),r=[],i=n.peek();if(null!=i&&"number"==typeof i.retain&&null==i.attributes){let t=i.retain;for(;"insert"===e.peekType()&&e.peekLength()<=t;)t-=e.peekLength(),r.push(e.next());i.retain-t>0&&n.next(i.retain-t)}const l=new h(r);for(;e.hasNext()||n.hasNext();)if("insert"===n.peekType())l.push(n.next());else if("delete"===e.peekType())l.push(e.next());else{const t=Math.min(e.peekLength(),n.peekLength()),r=e.next(t),i=n.next(t);if(i.retain){const a={};if("number"==typeof r.retain)a.retain="number"==typeof i.retain?t:i.retain;else if("number"==typeof i.retain)null==r.retain?a.insert=r.insert:a.retain=r.retain;else{const t=null==r.retain?"insert":"retain",[e,n,s]=u(r[t],i.retain),o=h.getHandler(e);a[t]={[e]:o.compose(n,s,"retain"===t)}}const c=o.default.compose(r.attributes,i.attributes,"number"==typeof r.retain);if(c&&(a.attributes=c),l.push(a),!n.hasNext()&&s(l.ops[l.ops.length-1],a)){const t=new h(e.rest());return l.concat(t).chop()}}else"number"==typeof i.delete&&("number"==typeof r.retain||"object"==typeof r.retain&&null!==r.retain)&&l.push(i)}return l.chop()}concat(t){const e=new h(this.ops.slice());return t.ops.length>0&&(e.push(t.ops[0]),e.ops=e.ops.concat(t.ops.slice(1))),e}diff(t,e){if(this.ops===t.ops)return new h;const n=[this,t].map((e=>e.map((n=>{if(null!=n.insert)return"string"==typeof n.insert?n.insert:c;throw new Error("diff() called "+(e===t?"on":"with")+" non-document")})).join(""))),i=new h,l=r(n[0],n[1],e,!0),u=new a.default(this.ops),d=new a.default(t.ops);return l.forEach((t=>{let e=t[1].length;for(;e>0;){let n=0;switch(t[0]){case r.INSERT:n=Math.min(d.peekLength(),e),i.push(d.next(n));break;case r.DELETE:n=Math.min(e,u.peekLength()),u.next(n),i.delete(n);break;case r.EQUAL:n=Math.min(u.peekLength(),d.peekLength(),e);const t=u.next(n),l=d.next(n);s(t.insert,l.insert)?i.retain(n,o.default.diff(t.attributes,l.attributes)):i.push(l).delete(n)}e-=n}})),i.chop()}eachLine(t,e="\n"){const n=new a.default(this.ops);let r=new h,i=0;for(;n.hasNext();){if("insert"!==n.peekType())return;const s=n.peek(),o=l.default.length(s)-n.peekLength(),a="string"==typeof s.insert?s.insert.indexOf(e,o)-o:-1;if(a<0)r.push(n.next());else if(a>0)r.push(n.next(a));else{if(!1===t(r,n.next(1).attributes||{},i))return;i+=1,r=new h}}r.length()>0&&t(r,{},i)}invert(t){const e=new h;return this.reduce(((n,r)=>{if(r.insert)e.delete(l.default.length(r));else{if("number"==typeof r.retain&&null==r.attributes)return e.retain(r.retain),n+r.retain;if(r.delete||"number"==typeof r.retain){const i=r.delete||r.retain;return t.slice(n,n+i).forEach((t=>{r.delete?e.push(t):r.retain&&r.attributes&&e.retain(l.default.length(t),o.default.invert(r.attributes,t.attributes))})),n+i}if("object"==typeof r.retain&&null!==r.retain){const i=t.slice(n,n+1),s=new a.default(i.ops).next(),[l,c,d]=u(r.retain,s.insert),f=h.getHandler(l);return e.retain({[l]:f.invert(c,d)},o.default.invert(r.attributes,s.attributes)),n+1}}return n}),0),e.chop()}transform(t,e=!1){if(e=!!e,"number"==typeof t)return this.transformPosition(t,e);const n=t,r=new a.default(this.ops),i=new a.default(n.ops),s=new h;for(;r.hasNext()||i.hasNext();)if("insert"!==r.peekType()||!e&&"insert"===i.peekType())if("insert"===i.peekType())s.push(i.next());else{const t=Math.min(r.peekLength(),i.peekLength()),n=r.next(t),l=i.next(t);if(n.delete)continue;if(l.delete)s.push(l);else{const r=n.retain,i=l.retain;let a="object"==typeof i&&null!==i?i:t;if("object"==typeof r&&null!==r&&"object"==typeof i&&null!==i){const t=Object.keys(r)[0];if(t===Object.keys(i)[0]){const n=h.getHandler(t);n&&(a={[t]:n.transform(r[t],i[t],e)})}}s.retain(a,o.default.transform(n.attributes,l.attributes,e))}}else s.retain(l.default.length(r.next()));return s.chop()}transformPosition(t,e=!1){e=!!e;const n=new a.default(this.ops);let r=0;for(;n.hasNext()&&r<=t;){const i=n.peekLength(),s=n.peekType();n.next(),"delete"!==s?("insert"===s&&(r<t||!e)&&(t+=i),r+=i):t-=Math.min(i,t-r)}return t}}h.Op=l.default,h.OpIterator=a.default,h.AttributeMap=o.default,h.handlers={},e.default=h,t.exports=h,t.exports.default=h},4123:function(t,e){"use strict";var n;Object.defineProperty(e,"__esModule",{value:!0}),function(t){t.length=function(t){return"number"==typeof t.delete?t.delete:"number"==typeof t.retain?t.retain:"object"==typeof t.retain&&null!==t.retain?1:"string"==typeof t.insert?t.insert.length:1}}(n||(n={})),e.default=n},7033:function(t,e,n){"use strict";Object.defineProperty(e,"__esModule",{value:!0});const r=n(4123);e.default=class{constructor(t){this.ops=t,this.index=0,this.offset=0}hasNext(){return this.peekLength()<1/0}next(t){t||(t=1/0);const e=this.ops[this.index];if(e){const n=this.offset,i=r.default.length(e);if(t>=i-n?(t=i-n,this.index+=1,this.offset=0):this.offset+=t,"number"==typeof e.delete)return{delete:t};{const r={};return e.attributes&&(r.attributes=e.attributes),"number"==typeof e.retain?r.retain=t:"object"==typeof e.retain&&null!==e.retain?r.retain=e.retain:"string"==typeof e.insert?r.insert=e.insert.substr(n,t):r.insert=e.insert,r}}return{retain:1/0}}peek(){return this.ops[this.index]}peekLength(){return this.ops[this.index]?r.default.length(this.ops[this.index])-this.offset:1/0}peekType(){const t=this.ops[this.index];return t?"number"==typeof t.delete?"delete":"number"==typeof t.retain||"object"==typeof t.retain&&null!==t.retain?"retain":"insert":"retain"}rest(){if(this.hasNext()){if(0===this.offset)return this.ops.slice(this.index);{const t=this.offset,e=this.index,n=this.next(),r=this.ops.slice(this.index);return this.offset=t,this.index=e,[n].concat(r)}}return[]}}},8820:function(t,e,n){"use strict";n.d(e,{A:function(){return l}});var r=n(8138),i=function(t,e){for(var n=t.length;n--;)if((0,r.A)(t[n][0],e))return n;return-1},s=Array.prototype.splice;function o(t){var e=-1,n=null==t?0:t.length;for(this.clear();++e<n;){var r=t[e];this.set(r[0],r[1])}}o.prototype.clear=function(){this.__data__=[],this.size=0},o.prototype.delete=function(t){var e=this.__data__,n=i(e,t);return!(n<0||(n==e.length-1?e.pop():s.call(e,n,1),--this.size,0))},o.prototype.get=function(t){var e=this.__data__,n=i(e,t);return n<0?void 0:e[n][1]},o.prototype.has=function(t){return i(this.__data__,t)>-1},o.prototype.set=function(t,e){var n=this.__data__,r=i(n,t);return r<0?(++this.size,n.push([t,e])):n[r][1]=e,this};var l=o},2461:function(t,e,n){"use strict";var r=n(2281),i=n(5507),s=(0,r.A)(i.A,"Map");e.A=s},3558:function(t,e,n){"use strict";n.d(e,{A:function(){return d}});var r=(0,n(2281).A)(Object,"create"),i=Object.prototype.hasOwnProperty,s=Object.prototype.hasOwnProperty;function o(t){var e=-1,n=null==t?0:t.length;for(this.clear();++e<n;){var r=t[e];this.set(r[0],r[1])}}o.prototype.clear=function(){this.__data__=r?r(null):{},this.size=0},o.prototype.delete=function(t){var e=this.has(t)&&delete this.__data__[t];return this.size-=e?1:0,e},o.prototype.get=function(t){var e=this.__data__;if(r){var n=e[t];return"__lodash_hash_undefined__"===n?void 0:n}return i.call(e,t)?e[t]:void 0},o.prototype.has=function(t){var e=this.__data__;return r?void 0!==e[t]:s.call(e,t)},o.prototype.set=function(t,e){var n=this.__data__;return this.size+=this.has(t)?0:1,n[t]=r&&void 0===e?"__lodash_hash_undefined__":e,this};var l=o,a=n(8820),c=n(2461),u=function(t,e){var n,r,i=t.__data__;return("string"==(r=typeof(n=e))||"number"==r||"symbol"==r||"boolean"==r?"__proto__"!==n:null===n)?i["string"==typeof e?"string":"hash"]:i.map};function h(t){var e=-1,n=null==t?0:t.length;for(this.clear();++e<n;){var r=t[e];this.set(r[0],r[1])}}h.prototype.clear=function(){this.size=0,this.__data__={hash:new l,map:new(c.A||a.A),string:new l}},h.prototype.delete=function(t){var e=u(this,t).delete(t);return this.size-=e?1:0,e},h.prototype.get=function(t){return u(this,t).get(t)},h.prototype.has=function(t){return u(this,t).has(t)},h.prototype.set=function(t,e){var n=u(this,t),r=n.size;return n.set(t,e),this.size+=n.size==r?0:1,this};var d=h},2673:function(t,e,n){"use strict";n.d(e,{A:function(){return l}});var r=n(8820),i=n(2461),s=n(3558);function o(t){var e=this.__data__=new r.A(t);this.size=e.size}o.prototype.clear=function(){this.__data__=new r.A,this.size=0},o.prototype.delete=function(t){var e=this.__data__,n=e.delete(t);return this.size=e.size,n},o.prototype.get=function(t){return this.__data__.get(t)},o.prototype.has=function(t){return this.__data__.has(t)},o.prototype.set=function(t,e){var n=this.__data__;if(n instanceof r.A){var o=n.__data__;if(!i.A||o.length<199)return o.push([t,e]),this.size=++n.size,this;n=this.__data__=new s.A(o)}return n.set(t,e),this.size=n.size,this};var l=o},439:function(t,e,n){"use strict";var r=n(5507).A.Symbol;e.A=r},7218:function(t,e,n){"use strict";var r=n(5507).A.Uint8Array;e.A=r},6753:function(t,e,n){"use strict";n.d(e,{A:function(){return c}});var r=n(8412),i=n(723),s=n(776),o=n(3767),l=n(5755),a=Object.prototype.hasOwnProperty,c=function(t,e){var n=(0,i.A)(t),c=!n&&(0,r.A)(t),u=!n&&!c&&(0,s.A)(t),h=!n&&!c&&!u&&(0,l.A)(t),d=n||c||u||h,f=d?function(t,e){for(var n=-1,r=Array(t);++n<t;)r[n]=e(n);return r}(t.length,String):[],p=f.length;for(var g in t)!e&&!a.call(t,g)||d&&("length"==g||u&&("offset"==g||"parent"==g)||h&&("buffer"==g||"byteLength"==g||"byteOffset"==g)||(0,o.A)(g,p))||f.push(g);return f}},802:function(t,e){"use strict";e.A=function(t,e){for(var n=-1,r=e.length,i=t.length;++n<r;)t[i+n]=e[n];return t}},6437:function(t,e,n){"use strict";var r=n(6770),i=n(8138),s=Object.prototype.hasOwnProperty;e.A=function(t,e,n){var o=t[e];s.call(t,e)&&(0,i.A)(o,n)&&(void 0!==n||e in t)||(0,r.A)(t,e,n)}},6770:function(t,e,n){"use strict";var r=n(7889);e.A=function(t,e,n){"__proto__"==e&&r.A?(0,r.A)(t,e,{configurable:!0,enumerable:!0,value:n,writable:!0}):t[e]=n}},1381:function(t,e,n){"use strict";var r=n(802),i=n(723);e.A=function(t,e,n){var s=e(t);return(0,i.A)(t)?s:(0,r.A)(s,n(t))}},2159:function(t,e,n){"use strict";n.d(e,{A:function(){return u}});var r=n(439),i=Object.prototype,s=i.hasOwnProperty,o=i.toString,l=r.A?r.A.toStringTag:void 0,a=Object.prototype.toString,c=r.A?r.A.toStringTag:void 0,u=function(t){return null==t?void 0===t?"[object Undefined]":"[object Null]":c&&c in Object(t)?function(t){var e=s.call(t,l),n=t[l];try{t[l]=void 0;var r=!0}catch(t){}var i=o.call(t);return r&&(e?t[l]=n:delete t[l]),i}(t):function(t){return a.call(t)}(t)}},5771:function(t,e){"use strict";e.A=function(t){return function(e){return t(e)}}},2899:function(t,e,n){"use strict";var r=n(7218);e.A=function(t){var e=new t.constructor(t.byteLength);return new r.A(e).set(new r.A(t)),e}},3812:function(t,e,n){"use strict";var r=n(5507),i="object"==typeof exports&&exports&&!exports.nodeType&&exports,s=i&&"object"==typeof module&&module&&!module.nodeType&&module,o=s&&s.exports===i?r.A.Buffer:void 0,l=o?o.allocUnsafe:void 0;e.A=function(t,e){if(e)return t.slice();var n=t.length,r=l?l(n):new t.constructor(n);return t.copy(r),r}},1827:function(t,e,n){"use strict";var r=n(2899);e.A=function(t,e){var n=e?(0,r.A)(t.buffer):t.buffer;return new t.constructor(n,t.byteOffset,t.length)}},4405:function(t,e){"use strict";e.A=function(t,e){var n=-1,r=t.length;for(e||(e=Array(r));++n<r;)e[n]=t[n];return e}},9601:function(t,e,n){"use strict";var r=n(6437),i=n(6770);e.A=function(t,e,n,s){var o=!n;n||(n={});for(var l=-1,a=e.length;++l<a;){var c=e[l],u=s?s(n[c],t[c],c,n,t):void 0;void 0===u&&(u=t[c]),o?(0,i.A)(n,c,u):(0,r.A)(n,c,u)}return n}},7889:function(t,e,n){"use strict";var r=n(2281),i=function(){try{var t=(0,r.A)(Object,"defineProperty");return t({},"",{}),t}catch(t){}}();e.A=i},9646:function(t,e){"use strict";var n="object"==typeof global&&global&&global.Object===Object&&global;e.A=n},2816:function(t,e,n){"use strict";var r=n(1381),i=n(9844),s=n(3169);e.A=function(t){return(0,r.A)(t,s.A,i.A)}},2281:function(t,e,n){"use strict";n.d(e,{A:function(){return m}});var r,i=n(7572),s=n(5507).A["__core-js_shared__"],o=(r=/[^.]+$/.exec(s&&s.keys&&s.keys.IE_PROTO||""))?"Symbol(src)_1."+r:"",l=n(659),a=n(1543),c=/^\[object .+?Constructor\]$/,u=Function.prototype,h=Object.prototype,d=u.toString,f=h.hasOwnProperty,p=RegExp("^"+d.call(f).replace(/[\\^$.*+?()[\]{}|]/g,"\\$&").replace(/hasOwnProperty|(function).*?(?=\\\()| for .+?(?=\\\])/g,"$1.*?")+"$"),g=function(t){return!(!(0,l.A)(t)||(e=t,o&&o in e))&&((0,i.A)(t)?p:c).test((0,a.A)(t));var e},m=function(t,e){var n=function(t,e){return null==t?void 0:t[e]}(t,e);return g(n)?n:void 0}},8769:function(t,e,n){"use strict";var r=(0,n(2217).A)(Object.getPrototypeOf,Object);e.A=r},9844:function(t,e,n){"use strict";n.d(e,{A:function(){return o}});var r=n(6935),i=Object.prototype.propertyIsEnumerable,s=Object.getOwnPropertySymbols,o=s?function(t){return null==t?[]:(t=Object(t),function(t,e){for(var n=-1,r=null==t?0:t.length,i=0,s=[];++n<r;){var o=t[n];e(o,n,t)&&(s[i++]=o)}return s}(s(t),(function(e){return i.call(t,e)})))}:r.A},7995:function(t,e,n){"use strict";n.d(e,{A:function(){return E}});var r=n(2281),i=n(5507),s=(0,r.A)(i.A,"DataView"),o=n(2461),l=(0,r.A)(i.A,"Promise"),a=(0,r.A)(i.A,"Set"),c=(0,r.A)(i.A,"WeakMap"),u=n(2159),h=n(1543),d="[object Map]",f="[object Promise]",p="[object Set]",g="[object WeakMap]",m="[object DataView]",b=(0,h.A)(s),y=(0,h.A)(o.A),v=(0,h.A)(l),A=(0,h.A)(a),x=(0,h.A)(c),N=u.A;(s&&N(new s(new ArrayBuffer(1)))!=m||o.A&&N(new o.A)!=d||l&&N(l.resolve())!=f||a&&N(new a)!=p||c&&N(new c)!=g)&&(N=function(t){var e=(0,u.A)(t),n="[object Object]"==e?t.constructor:void 0,r=n?(0,h.A)(n):"";if(r)switch(r){case b:return m;case y:return d;case v:return f;case A:return p;case x:return g}return e});var E=N},1683:function(t,e,n){"use strict";n.d(e,{A:function(){return a}});var r=n(659),i=Object.create,s=function(){function t(){}return function(e){if(!(0,r.A)(e))return{};if(i)return i(e);t.prototype=e;var n=new t;return t.prototype=void 0,n}}(),o=n(8769),l=n(501),a=function(t){return"function"!=typeof t.constructor||(0,l.A)(t)?{}:s((0,o.A)(t))}},3767:function(t,e){"use strict";var n=/^(?:0|[1-9]\d*)$/;e.A=function(t,e){var r=typeof t;return!!(e=null==e?9007199254740991:e)&&("number"==r||"symbol"!=r&&n.test(t))&&t>-1&&t%1==0&&t<e}},501:function(t,e){"use strict";var n=Object.prototype;e.A=function(t){var e=t&&t.constructor;return t===("function"==typeof e&&e.prototype||n)}},8795:function(t,e,n){"use strict";var r=n(9646),i="object"==typeof exports&&exports&&!exports.nodeType&&exports,s=i&&"object"==typeof module&&module&&!module.nodeType&&module,o=s&&s.exports===i&&r.A.process,l=function(){try{return s&&s.require&&s.require("util").types||o&&o.binding&&o.binding("util")}catch(t){}}();e.A=l},2217:function(t,e){"use strict";e.A=function(t,e){return function(n){return t(e(n))}}},5507:function(t,e,n){"use strict";var r=n(9646),i="object"==typeof self&&self&&self.Object===Object&&self,s=r.A||i||Function("return this")();e.A=s},1543:function(t,e){"use strict";var n=Function.prototype.toString;e.A=function(t){if(null!=t){try{return n.call(t)}catch(t){}try{return t+""}catch(t){}}return""}},3707:function(t,e,n){"use strict";n.d(e,{A:function(){return H}});var r=n(2673),i=n(6437),s=n(9601),o=n(3169),l=n(2624),a=n(3812),c=n(4405),u=n(9844),h=n(802),d=n(8769),f=n(6935),p=Object.getOwnPropertySymbols?function(t){for(var e=[];t;)(0,h.A)(e,(0,u.A)(t)),t=(0,d.A)(t);return e}:f.A,g=n(2816),m=n(1381),b=function(t){return(0,m.A)(t,l.A,p)},y=n(7995),v=Object.prototype.hasOwnProperty,A=n(2899),x=/\w*$/,N=n(439),E=N.A?N.A.prototype:void 0,w=E?E.valueOf:void 0,q=n(1827),k=function(t,e,n){var r,i,s,o=t.constructor;switch(e){case"[object ArrayBuffer]":return(0,A.A)(t);case"[object Boolean]":case"[object Date]":return new o(+t);case"[object DataView]":return function(t,e){var n=e?(0,A.A)(t.buffer):t.buffer;return new t.constructor(n,t.byteOffset,t.byteLength)}(t,n);case"[object Float32Array]":case"[object Float64Array]":case"[object Int8Array]":case"[object Int16Array]":case"[object Int32Array]":case"[object Uint8Array]":case"[object Uint8ClampedArray]":case"[object Uint16Array]":case"[object Uint32Array]":return(0,q.A)(t,n);case"[object Map]":case"[object Set]":return new o;case"[object Number]":case"[object String]":return new o(t);case"[object RegExp]":return(s=new(i=t).constructor(i.source,x.exec(i))).lastIndex=i.lastIndex,s;case"[object Symbol]":return r=t,w?Object(w.call(r)):{}}},_=n(1683),L=n(723),S=n(776),O=n(7948),T=n(5771),j=n(8795),C=j.A&&j.A.isMap,R=C?(0,T.A)(C):function(t){return(0,O.A)(t)&&"[object Map]"==(0,y.A)(t)},I=n(659),B=j.A&&j.A.isSet,M=B?(0,T.A)(B):function(t){return(0,O.A)(t)&&"[object Set]"==(0,y.A)(t)},U="[object Arguments]",D="[object Function]",P="[object Object]",z={};z[U]=z["[object Array]"]=z["[object ArrayBuffer]"]=z["[object DataView]"]=z["[object Boolean]"]=z["[object Date]"]=z["[object Float32Array]"]=z["[object Float64Array]"]=z["[object Int8Array]"]=z["[object Int16Array]"]=z["[object Int32Array]"]=z["[object Map]"]=z["[object Number]"]=z[P]=z["[object RegExp]"]=z["[object Set]"]=z["[object String]"]=z["[object Symbol]"]=z["[object Uint8Array]"]=z["[object Uint8ClampedArray]"]=z["[object Uint16Array]"]=z["[object Uint32Array]"]=!0,z["[object Error]"]=z[D]=z["[object WeakMap]"]=!1;var F=function t(e,n,h,d,f,m){var A,x=1&n,N=2&n,E=4&n;if(h&&(A=f?h(e,d,f,m):h(e)),void 0!==A)return A;if(!(0,I.A)(e))return e;var w=(0,L.A)(e);if(w){if(A=function(t){var e=t.length,n=new t.constructor(e);return e&&"string"==typeof t[0]&&v.call(t,"index")&&(n.index=t.index,n.input=t.input),n}(e),!x)return(0,c.A)(e,A)}else{var q=(0,y.A)(e),O=q==D||"[object GeneratorFunction]"==q;if((0,S.A)(e))return(0,a.A)(e,x);if(q==P||q==U||O&&!f){if(A=N||O?{}:(0,_.A)(e),!x)return N?function(t,e){return(0,s.A)(t,p(t),e)}(e,function(t,e){return t&&(0,s.A)(e,(0,l.A)(e),t)}(A,e)):function(t,e){return(0,s.A)(t,(0,u.A)(t),e)}(e,function(t,e){return t&&(0,s.A)(e,(0,o.A)(e),t)}(A,e))}else{if(!z[q])return f?e:{};A=k(e,q,x)}}m||(m=new r.A);var T=m.get(e);if(T)return T;m.set(e,A),M(e)?e.forEach((function(r){A.add(t(r,n,h,r,e,m))})):R(e)&&e.forEach((function(r,i){A.set(i,t(r,n,h,i,e,m))}));var j=E?N?b:g.A:N?l.A:o.A,C=w?void 0:j(e);return function(t,e){for(var n=-1,r=null==t?0:t.length;++n<r&&!1!==e(t[n],n,t););}(C||e,(function(r,s){C&&(r=e[s=r]),(0,i.A)(A,s,t(r,n,h,s,e,m))})),A},H=function(t){return F(t,5)}},8138:function(t,e){"use strict";e.A=function(t,e){return t===e||t!=t&&e!=e}},8412:function(t,e,n){"use strict";n.d(e,{A:function(){return u}});var r=n(2159),i=n(7948),s=function(t){return(0,i.A)(t)&&"[object Arguments]"==(0,r.A)(t)},o=Object.prototype,l=o.hasOwnProperty,a=o.propertyIsEnumerable,c=s(function(){return arguments}())?s:function(t){return(0,i.A)(t)&&l.call(t,"callee")&&!a.call(t,"callee")},u=c},723:function(t,e){"use strict";var n=Array.isArray;e.A=n},3628:function(t,e,n){"use strict";var r=n(7572),i=n(1628);e.A=function(t){return null!=t&&(0,i.A)(t.length)&&!(0,r.A)(t)}},776:function(t,e,n){"use strict";n.d(e,{A:function(){return l}});var r=n(5507),i="object"==typeof exports&&exports&&!exports.nodeType&&exports,s=i&&"object"==typeof module&&module&&!module.nodeType&&module,o=s&&s.exports===i?r.A.Buffer:void 0,l=(o?o.isBuffer:void 0)||function(){return!1}},5123:function(t,e,n){"use strict";n.d(e,{A:function(){return S}});var r=n(2673),i=n(3558);function s(t){var e=-1,n=null==t?0:t.length;for(this.__data__=new i.A;++e<n;)this.add(t[e])}s.prototype.add=s.prototype.push=function(t){return this.__data__.set(t,"__lodash_hash_undefined__"),this},s.prototype.has=function(t){return this.__data__.has(t)};var o=s,l=function(t,e){for(var n=-1,r=null==t?0:t.length;++n<r;)if(e(t[n],n,t))return!0;return!1},a=function(t,e,n,r,i,s){var a=1&n,c=t.length,u=e.length;if(c!=u&&!(a&&u>c))return!1;var h=s.get(t),d=s.get(e);if(h&&d)return h==e&&d==t;var f=-1,p=!0,g=2&n?new o:void 0;for(s.set(t,e),s.set(e,t);++f<c;){var m=t[f],b=e[f];if(r)var y=a?r(b,m,f,e,t,s):r(m,b,f,t,e,s);if(void 0!==y){if(y)continue;p=!1;break}if(g){if(!l(e,(function(t,e){if(o=e,!g.has(o)&&(m===t||i(m,t,n,r,s)))return g.push(e);var o}))){p=!1;break}}else if(m!==b&&!i(m,b,n,r,s)){p=!1;break}}return s.delete(t),s.delete(e),p},c=n(439),u=n(7218),h=n(8138),d=function(t){var e=-1,n=Array(t.size);return t.forEach((function(t,r){n[++e]=[r,t]})),n},f=function(t){var e=-1,n=Array(t.size);return t.forEach((function(t){n[++e]=t})),n},p=c.A?c.A.prototype:void 0,g=p?p.valueOf:void 0,m=n(2816),b=Object.prototype.hasOwnProperty,y=n(7995),v=n(723),A=n(776),x=n(5755),N="[object Arguments]",E="[object Array]",w="[object Object]",q=Object.prototype.hasOwnProperty,k=function(t,e,n,i,s,o){var l=(0,v.A)(t),c=(0,v.A)(e),p=l?E:(0,y.A)(t),k=c?E:(0,y.A)(e),_=(p=p==N?w:p)==w,L=(k=k==N?w:k)==w,S=p==k;if(S&&(0,A.A)(t)){if(!(0,A.A)(e))return!1;l=!0,_=!1}if(S&&!_)return o||(o=new r.A),l||(0,x.A)(t)?a(t,e,n,i,s,o):function(t,e,n,r,i,s,o){switch(n){case"[object DataView]":if(t.byteLength!=e.byteLength||t.byteOffset!=e.byteOffset)return!1;t=t.buffer,e=e.buffer;case"[object ArrayBuffer]":return!(t.byteLength!=e.byteLength||!s(new u.A(t),new u.A(e)));case"[object Boolean]":case"[object Date]":case"[object Number]":return(0,h.A)(+t,+e);case"[object Error]":return t.name==e.name&&t.message==e.message;case"[object RegExp]":case"[object String]":return t==e+"";case"[object Map]":var l=d;case"[object Set]":var c=1&r;if(l||(l=f),t.size!=e.size&&!c)return!1;var p=o.get(t);if(p)return p==e;r|=2,o.set(t,e);var m=a(l(t),l(e),r,i,s,o);return o.delete(t),m;case"[object Symbol]":if(g)return g.call(t)==g.call(e)}return!1}(t,e,p,n,i,s,o);if(!(1&n)){var O=_&&q.call(t,"__wrapped__"),T=L&&q.call(e,"__wrapped__");if(O||T){var j=O?t.value():t,C=T?e.value():e;return o||(o=new r.A),s(j,C,n,i,o)}}return!!S&&(o||(o=new r.A),function(t,e,n,r,i,s){var o=1&n,l=(0,m.A)(t),a=l.length;if(a!=(0,m.A)(e).length&&!o)return!1;for(var c=a;c--;){var u=l[c];if(!(o?u in e:b.call(e,u)))return!1}var h=s.get(t),d=s.get(e);if(h&&d)return h==e&&d==t;var f=!0;s.set(t,e),s.set(e,t);for(var p=o;++c<a;){var g=t[u=l[c]],y=e[u];if(r)var v=o?r(y,g,u,e,t,s):r(g,y,u,t,e,s);if(!(void 0===v?g===y||i(g,y,n,r,s):v)){f=!1;break}p||(p="constructor"==u)}if(f&&!p){var A=t.constructor,x=e.constructor;A==x||!("constructor"in t)||!("constructor"in e)||"function"==typeof A&&A instanceof A&&"function"==typeof x&&x instanceof x||(f=!1)}return s.delete(t),s.delete(e),f}(t,e,n,i,s,o))},_=n(7948),L=function t(e,n,r,i,s){return e===n||(null==e||null==n||!(0,_.A)(e)&&!(0,_.A)(n)?e!=e&&n!=n:k(e,n,r,i,t,s))},S=function(t,e){return L(t,e)}},7572:function(t,e,n){"use strict";var r=n(2159),i=n(659);e.A=function(t){if(!(0,i.A)(t))return!1;var e=(0,r.A)(t);return"[object Function]"==e||"[object GeneratorFunction]"==e||"[object AsyncFunction]"==e||"[object Proxy]"==e}},1628:function(t,e){"use strict";e.A=function(t){return"number"==typeof t&&t>-1&&t%1==0&&t<=9007199254740991}},659:function(t,e){"use strict";e.A=function(t){var e=typeof t;return null!=t&&("object"==e||"function"==e)}},7948:function(t,e){"use strict";e.A=function(t){return null!=t&&"object"==typeof t}},5755:function(t,e,n){"use strict";n.d(e,{A:function(){return u}});var r=n(2159),i=n(1628),s=n(7948),o={};o["[object Float32Array]"]=o["[object Float64Array]"]=o["[object Int8Array]"]=o["[object Int16Array]"]=o["[object Int32Array]"]=o["[object Uint8Array]"]=o["[object Uint8ClampedArray]"]=o["[object Uint16Array]"]=o["[object Uint32Array]"]=!0,o["[object Arguments]"]=o["[object Array]"]=o["[object ArrayBuffer]"]=o["[object Boolean]"]=o["[object DataView]"]=o["[object Date]"]=o["[object Error]"]=o["[object Function]"]=o["[object Map]"]=o["[object Number]"]=o["[object Object]"]=o["[object RegExp]"]=o["[object Set]"]=o["[object String]"]=o["[object WeakMap]"]=!1;var l=n(5771),a=n(8795),c=a.A&&a.A.isTypedArray,u=c?(0,l.A)(c):function(t){return(0,s.A)(t)&&(0,i.A)(t.length)&&!!o[(0,r.A)(t)]}},3169:function(t,e,n){"use strict";n.d(e,{A:function(){return a}});var r=n(6753),i=n(501),s=(0,n(2217).A)(Object.keys,Object),o=Object.prototype.hasOwnProperty,l=n(3628),a=function(t){return(0,l.A)(t)?(0,r.A)(t):function(t){if(!(0,i.A)(t))return s(t);var e=[];for(var n in Object(t))o.call(t,n)&&"constructor"!=n&&e.push(n);return e}(t)}},2624:function(t,e,n){"use strict";n.d(e,{A:function(){return c}});var r=n(6753),i=n(659),s=n(501),o=Object.prototype.hasOwnProperty,l=function(t){if(!(0,i.A)(t))return function(t){var e=[];if(null!=t)for(var n in Object(t))e.push(n);return e}(t);var e=(0,s.A)(t),n=[];for(var r in t)("constructor"!=r||!e&&o.call(t,r))&&n.push(r);return n},a=n(3628),c=function(t){return(0,a.A)(t)?(0,r.A)(t,!0):l(t)}},8347:function(t,e,n){"use strict";n.d(e,{A:function(){return $}});var r,i,s,o,l=n(2673),a=n(6770),c=n(8138),u=function(t,e,n){(void 0!==n&&!(0,c.A)(t[e],n)||void 0===n&&!(e in t))&&(0,a.A)(t,e,n)},h=function(t,e,n){for(var r=-1,i=Object(t),s=n(t),o=s.length;o--;){var l=s[++r];if(!1===e(i[l],l,i))break}return t},d=n(3812),f=n(1827),p=n(4405),g=n(1683),m=n(8412),b=n(723),y=n(3628),v=n(7948),A=n(776),x=n(7572),N=n(659),E=n(2159),w=n(8769),q=Function.prototype,k=Object.prototype,_=q.toString,L=k.hasOwnProperty,S=_.call(Object),O=n(5755),T=function(t,e){if(("constructor"!==e||"function"!=typeof t[e])&&"__proto__"!=e)return t[e]},j=n(9601),C=n(2624),R=function(t,e,n,r,i,s,o){var l,a=T(t,n),c=T(e,n),h=o.get(c);if(h)u(t,n,h);else{var q=s?s(a,c,n+"",t,e,o):void 0,k=void 0===q;if(k){var R=(0,b.A)(c),I=!R&&(0,A.A)(c),B=!R&&!I&&(0,O.A)(c);q=c,R||I||B?(0,b.A)(a)?q=a:(l=a,(0,v.A)(l)&&(0,y.A)(l)?q=(0,p.A)(a):I?(k=!1,q=(0,d.A)(c,!0)):B?(k=!1,q=(0,f.A)(c,!0)):q=[]):function(t){if(!(0,v.A)(t)||"[object Object]"!=(0,E.A)(t))return!1;var e=(0,w.A)(t);if(null===e)return!0;var n=L.call(e,"constructor")&&e.constructor;return"function"==typeof n&&n instanceof n&&_.call(n)==S}(c)||(0,m.A)(c)?(q=a,(0,m.A)(a)?q=function(t){return(0,j.A)(t,(0,C.A)(t))}(a):(0,N.A)(a)&&!(0,x.A)(a)||(q=(0,g.A)(c))):k=!1}k&&(o.set(c,q),i(q,c,r,s,o),o.delete(c)),u(t,n,q)}},I=function t(e,n,r,i,s){e!==n&&h(n,(function(o,a){if(s||(s=new l.A),(0,N.A)(o))R(e,n,a,r,t,i,s);else{var c=i?i(T(e,a),o,a+"",e,n,s):void 0;void 0===c&&(c=o),u(e,a,c)}}),C.A)},B=function(t){return t},M=Math.max,U=n(7889),D=U.A?function(t,e){return(0,U.A)(t,"toString",{configurable:!0,enumerable:!1,value:(n=e,function(){return n}),writable:!0});var n}:B,P=Date.now,z=(r=D,i=0,s=0,function(){var t=P(),e=16-(t-s);if(s=t,e>0){if(++i>=800)return arguments[0]}else i=0;return r.apply(void 0,arguments)}),F=function(t,e){return z(function(t,e,n){return e=M(void 0===e?t.length-1:e,0),function(){for(var r=arguments,i=-1,s=M(r.length-e,0),o=Array(s);++i<s;)o[i]=r[e+i];i=-1;for(var l=Array(e+1);++i<e;)l[i]=r[i];return l[e]=n(o),function(t,e,n){switch(n.length){case 0:return t.call(e);case 1:return t.call(e,n[0]);case 2:return t.call(e,n[0],n[1]);case 3:return t.call(e,n[0],n[1],n[2])}return t.apply(e,n)}(t,this,l)}}(t,e,B),t+"")},H=n(3767),$=(o=function(t,e,n){I(t,e,n)},F((function(t,e){var n=-1,r=e.length,i=r>1?e[r-1]:void 0,s=r>2?e[2]:void 0;for(i=o.length>3&&"function"==typeof i?(r--,i):void 0,s&&function(t,e,n){if(!(0,N.A)(n))return!1;var r=typeof e;return!!("number"==r?(0,y.A)(n)&&(0,H.A)(e,n.length):"string"==r&&e in n)&&(0,c.A)(n[e],t)}(e[0],e[1],s)&&(i=r<3?void 0:i,r=1),t=Object(t);++n<r;){var l=e[n];l&&o(t,l,n)}return t})))},6935:function(t,e){"use strict";e.A=function(){return[]}},6003:function(t,e,n){"use strict";n.r(e),n.d(e,{Attributor:function(){return i},AttributorStore:function(){return d},BlockBlot:function(){return w},ClassAttributor:function(){return c},ContainerBlot:function(){return k},EmbedBlot:function(){return _},InlineBlot:function(){return N},LeafBlot:function(){return m},ParentBlot:function(){return A},Registry:function(){return l},Scope:function(){return r},ScrollBlot:function(){return O},StyleAttributor:function(){return h},TextBlot:function(){return j}});var r=(t=>(t[t.TYPE=3]="TYPE",t[t.LEVEL=12]="LEVEL",t[t.ATTRIBUTE=13]="ATTRIBUTE",t[t.BLOT=14]="BLOT",t[t.INLINE=7]="INLINE",t[t.BLOCK=11]="BLOCK",t[t.BLOCK_BLOT=10]="BLOCK_BLOT",t[t.INLINE_BLOT=6]="INLINE_BLOT",t[t.BLOCK_ATTRIBUTE=9]="BLOCK_ATTRIBUTE",t[t.INLINE_ATTRIBUTE=5]="INLINE_ATTRIBUTE",t[t.ANY=15]="ANY",t))(r||{});class i{constructor(t,e,n={}){this.attrName=t,this.keyName=e;const i=r.TYPE&r.ATTRIBUTE;this.scope=null!=n.scope?n.scope&r.LEVEL|i:r.ATTRIBUTE,null!=n.whitelist&&(this.whitelist=n.whitelist)}static keys(t){return Array.from(t.attributes).map((t=>t.name))}add(t,e){return!!this.canAdd(t,e)&&(t.setAttribute(this.keyName,e),!0)}canAdd(t,e){return null==this.whitelist||("string"==typeof e?this.whitelist.indexOf(e.replace(/["']/g,""))>-1:this.whitelist.indexOf(e)>-1)}remove(t){t.removeAttribute(this.keyName)}value(t){const e=t.getAttribute(this.keyName);return this.canAdd(t,e)&&e?e:""}}class s extends Error{constructor(t){super(t="[Parchment] "+t),this.message=t,this.name=this.constructor.name}}const o=class t{constructor(){this.attributes={},this.classes={},this.tags={},this.types={}}static find(t,e=!1){if(null==t)return null;if(this.blots.has(t))return this.blots.get(t)||null;if(e){let n=null;try{n=t.parentNode}catch{return null}return this.find(n,e)}return null}create(e,n,r){const i=this.query(n);if(null==i)throw new s(`Unable to create ${n} blot`);const o=i,l=n instanceof Node||n.nodeType===Node.TEXT_NODE?n:o.create(r),a=new o(e,l,r);return t.blots.set(a.domNode,a),a}find(e,n=!1){return t.find(e,n)}query(t,e=r.ANY){let n;return"string"==typeof t?n=this.types[t]||this.attributes[t]:t instanceof Text||t.nodeType===Node.TEXT_NODE?n=this.types.text:"number"==typeof t?t&r.LEVEL&r.BLOCK?n=this.types.block:t&r.LEVEL&r.INLINE&&(n=this.types.inline):t instanceof Element&&((t.getAttribute("class")||"").split(/\s+/).some((t=>(n=this.classes[t],!!n))),n=n||this.tags[t.tagName]),null==n?null:"scope"in n&&e&r.LEVEL&n.scope&&e&r.TYPE&n.scope?n:null}register(...t){return t.map((t=>{const e="blotName"in t,n="attrName"in t;if(!e&&!n)throw new s("Invalid definition");if(e&&"abstract"===t.blotName)throw new s("Cannot register abstract class");const r=e?t.blotName:n?t.attrName:void 0;return this.types[r]=t,n?"string"==typeof t.keyName&&(this.attributes[t.keyName]=t):e&&(t.className&&(this.classes[t.className]=t),t.tagName&&(Array.isArray(t.tagName)?t.tagName=t.tagName.map((t=>t.toUpperCase())):t.tagName=t.tagName.toUpperCase(),(Array.isArray(t.tagName)?t.tagName:[t.tagName]).forEach((e=>{(null==this.tags[e]||null==t.className)&&(this.tags[e]=t)})))),t}))}};o.blots=new WeakMap;let l=o;function a(t,e){return(t.getAttribute("class")||"").split(/\s+/).filter((t=>0===t.indexOf(`${e}-`)))}const c=class extends i{static keys(t){return(t.getAttribute("class")||"").split(/\s+/).map((t=>t.split("-").slice(0,-1).join("-")))}add(t,e){return!!this.canAdd(t,e)&&(this.remove(t),t.classList.add(`${this.keyName}-${e}`),!0)}remove(t){a(t,this.keyName).forEach((e=>{t.classList.remove(e)})),0===t.classList.length&&t.removeAttribute("class")}value(t){const e=(a(t,this.keyName)[0]||"").slice(this.keyName.length+1);return this.canAdd(t,e)?e:""}};function u(t){const e=t.split("-"),n=e.slice(1).map((t=>t[0].toUpperCase()+t.slice(1))).join("");return e[0]+n}const h=class extends i{static keys(t){return(t.getAttribute("style")||"").split(";").map((t=>t.split(":")[0].trim()))}add(t,e){return!!this.canAdd(t,e)&&(t.style[u(this.keyName)]=e,!0)}remove(t){t.style[u(this.keyName)]="",t.getAttribute("style")||t.removeAttribute("style")}value(t){const e=t.style[u(this.keyName)];return this.canAdd(t,e)?e:""}},d=class{constructor(t){this.attributes={},this.domNode=t,this.build()}attribute(t,e){e?t.add(this.domNode,e)&&(null!=t.value(this.domNode)?this.attributes[t.attrName]=t:delete this.attributes[t.attrName]):(t.remove(this.domNode),delete this.attributes[t.attrName])}build(){this.attributes={};const t=l.find(this.domNode);if(null==t)return;const e=i.keys(this.domNode),n=c.keys(this.domNode),s=h.keys(this.domNode);e.concat(n).concat(s).forEach((e=>{const n=t.scroll.query(e,r.ATTRIBUTE);n instanceof i&&(this.attributes[n.attrName]=n)}))}copy(t){Object.keys(this.attributes).forEach((e=>{const n=this.attributes[e].value(this.domNode);t.format(e,n)}))}move(t){this.copy(t),Object.keys(this.attributes).forEach((t=>{this.attributes[t].remove(this.domNode)})),this.attributes={}}values(){return Object.keys(this.attributes).reduce(((t,e)=>(t[e]=this.attributes[e].value(this.domNode),t)),{})}},f=class{constructor(t,e){this.scroll=t,this.domNode=e,l.blots.set(e,this),this.prev=null,this.next=null}static create(t){if(null==this.tagName)throw new s("Blot definition missing tagName");let e,n;return Array.isArray(this.tagName)?("string"==typeof t?(n=t.toUpperCase(),parseInt(n,10).toString()===n&&(n=parseInt(n,10))):"number"==typeof t&&(n=t),e="number"==typeof n?document.createElement(this.tagName[n-1]):n&&this.tagName.indexOf(n)>-1?document.createElement(n):document.createElement(this.tagName[0])):e=document.createElement(this.tagName),this.className&&e.classList.add(this.className),e}get statics(){return this.constructor}attach(){}clone(){const t=this.domNode.cloneNode(!1);return this.scroll.create(t)}detach(){null!=this.parent&&this.parent.removeChild(this),l.blots.delete(this.domNode)}deleteAt(t,e){this.isolate(t,e).remove()}formatAt(t,e,n,i){const s=this.isolate(t,e);if(null!=this.scroll.query(n,r.BLOT)&&i)s.wrap(n,i);else if(null!=this.scroll.query(n,r.ATTRIBUTE)){const t=this.scroll.create(this.statics.scope);s.wrap(t),t.format(n,i)}}insertAt(t,e,n){const r=null==n?this.scroll.create("text",e):this.scroll.create(e,n),i=this.split(t);this.parent.insertBefore(r,i||void 0)}isolate(t,e){const n=this.split(t);if(null==n)throw new Error("Attempt to isolate at end");return n.split(e),n}length(){return 1}offset(t=this.parent){return null==this.parent||this===t?0:this.parent.children.offset(this)+this.parent.offset(t)}optimize(t){this.statics.requiredContainer&&!(this.parent instanceof this.statics.requiredContainer)&&this.wrap(this.statics.requiredContainer.blotName)}remove(){null!=this.domNode.parentNode&&this.domNode.parentNode.removeChild(this.domNode),this.detach()}replaceWith(t,e){const n="string"==typeof t?this.scroll.create(t,e):t;return null!=this.parent&&(this.parent.insertBefore(n,this.next||void 0),this.remove()),n}split(t,e){return 0===t?this:this.next}update(t,e){}wrap(t,e){const n="string"==typeof t?this.scroll.create(t,e):t;if(null!=this.parent&&this.parent.insertBefore(n,this.next||void 0),"function"!=typeof n.appendChild)throw new s(`Cannot wrap ${t}`);return n.appendChild(this),n}};f.blotName="abstract";let p=f;const g=class extends p{static value(t){return!0}index(t,e){return this.domNode===t||this.domNode.compareDocumentPosition(t)&Node.DOCUMENT_POSITION_CONTAINED_BY?Math.min(e,1):-1}position(t,e){let n=Array.from(this.parent.domNode.childNodes).indexOf(this.domNode);return t>0&&(n+=1),[this.parent.domNode,n]}value(){return{[this.statics.blotName]:this.statics.value(this.domNode)||!0}}};g.scope=r.INLINE_BLOT;const m=g;class b{constructor(){this.head=null,this.tail=null,this.length=0}append(...t){if(this.insertBefore(t[0],null),t.length>1){const e=t.slice(1);this.append(...e)}}at(t){const e=this.iterator();let n=e();for(;n&&t>0;)t-=1,n=e();return n}contains(t){const e=this.iterator();let n=e();for(;n;){if(n===t)return!0;n=e()}return!1}indexOf(t){const e=this.iterator();let n=e(),r=0;for(;n;){if(n===t)return r;r+=1,n=e()}return-1}insertBefore(t,e){null!=t&&(this.remove(t),t.next=e,null!=e?(t.prev=e.prev,null!=e.prev&&(e.prev.next=t),e.prev=t,e===this.head&&(this.head=t)):null!=this.tail?(this.tail.next=t,t.prev=this.tail,this.tail=t):(t.prev=null,this.head=this.tail=t),this.length+=1)}offset(t){let e=0,n=this.head;for(;null!=n;){if(n===t)return e;e+=n.length(),n=n.next}return-1}remove(t){this.contains(t)&&(null!=t.prev&&(t.prev.next=t.next),null!=t.next&&(t.next.prev=t.prev),t===this.head&&(this.head=t.next),t===this.tail&&(this.tail=t.prev),this.length-=1)}iterator(t=this.head){return()=>{const e=t;return null!=t&&(t=t.next),e}}find(t,e=!1){const n=this.iterator();let r=n();for(;r;){const i=r.length();if(t<i||e&&t===i&&(null==r.next||0!==r.next.length()))return[r,t];t-=i,r=n()}return[null,0]}forEach(t){const e=this.iterator();let n=e();for(;n;)t(n),n=e()}forEachAt(t,e,n){if(e<=0)return;const[r,i]=this.find(t);let s=t-i;const o=this.iterator(r);let l=o();for(;l&&s<t+e;){const r=l.length();t>s?n(l,t-s,Math.min(e,s+r-t)):n(l,0,Math.min(r,t+e-s)),s+=r,l=o()}}map(t){return this.reduce(((e,n)=>(e.push(t(n)),e)),[])}reduce(t,e){const n=this.iterator();let r=n();for(;r;)e=t(e,r),r=n();return e}}function y(t,e){const n=e.find(t);if(n)return n;try{return e.create(t)}catch{const n=e.create(r.INLINE);return Array.from(t.childNodes).forEach((t=>{n.domNode.appendChild(t)})),t.parentNode&&t.parentNode.replaceChild(n.domNode,t),n.attach(),n}}const v=class t extends p{constructor(t,e){super(t,e),this.uiNode=null,this.build()}appendChild(t){this.insertBefore(t)}attach(){super.attach(),this.children.forEach((t=>{t.attach()}))}attachUI(e){null!=this.uiNode&&this.uiNode.remove(),this.uiNode=e,t.uiClass&&this.uiNode.classList.add(t.uiClass),this.uiNode.setAttribute("contenteditable","false"),this.domNode.insertBefore(this.uiNode,this.domNode.firstChild)}build(){this.children=new b,Array.from(this.domNode.childNodes).filter((t=>t!==this.uiNode)).reverse().forEach((t=>{try{const e=y(t,this.scroll);this.insertBefore(e,this.children.head||void 0)}catch(t){if(t instanceof s)return;throw t}}))}deleteAt(t,e){if(0===t&&e===this.length())return this.remove();this.children.forEachAt(t,e,((t,e,n)=>{t.deleteAt(e,n)}))}descendant(e,n=0){const[r,i]=this.children.find(n);return null==e.blotName&&e(r)||null!=e.blotName&&r instanceof e?[r,i]:r instanceof t?r.descendant(e,i):[null,-1]}descendants(e,n=0,r=Number.MAX_VALUE){let i=[],s=r;return this.children.forEachAt(n,r,((n,r,o)=>{(null==e.blotName&&e(n)||null!=e.blotName&&n instanceof e)&&i.push(n),n instanceof t&&(i=i.concat(n.descendants(e,r,s))),s-=o})),i}detach(){this.children.forEach((t=>{t.detach()})),super.detach()}enforceAllowedChildren(){let e=!1;this.children.forEach((n=>{e||this.statics.allowedChildren.some((t=>n instanceof t))||(n.statics.scope===r.BLOCK_BLOT?(null!=n.next&&this.splitAfter(n),null!=n.prev&&this.splitAfter(n.prev),n.parent.unwrap(),e=!0):n instanceof t?n.unwrap():n.remove())}))}formatAt(t,e,n,r){this.children.forEachAt(t,e,((t,e,i)=>{t.formatAt(e,i,n,r)}))}insertAt(t,e,n){const[r,i]=this.children.find(t);if(r)r.insertAt(i,e,n);else{const t=null==n?this.scroll.create("text",e):this.scroll.create(e,n);this.appendChild(t)}}insertBefore(t,e){null!=t.parent&&t.parent.children.remove(t);let n=null;this.children.insertBefore(t,e||null),t.parent=this,null!=e&&(n=e.domNode),(this.domNode.parentNode!==t.domNode||this.domNode.nextSibling!==n)&&this.domNode.insertBefore(t.domNode,n),t.attach()}length(){return this.children.reduce(((t,e)=>t+e.length()),0)}moveChildren(t,e){this.children.forEach((n=>{t.insertBefore(n,e)}))}optimize(t){if(super.optimize(t),this.enforceAllowedChildren(),null!=this.uiNode&&this.uiNode!==this.domNode.firstChild&&this.domNode.insertBefore(this.uiNode,this.domNode.firstChild),0===this.children.length)if(null!=this.statics.defaultChild){const t=this.scroll.create(this.statics.defaultChild.blotName);this.appendChild(t)}else this.remove()}path(e,n=!1){const[r,i]=this.children.find(e,n),s=[[this,e]];return r instanceof t?s.concat(r.path(i,n)):(null!=r&&s.push([r,i]),s)}removeChild(t){this.children.remove(t)}replaceWith(e,n){const r="string"==typeof e?this.scroll.create(e,n):e;return r instanceof t&&this.moveChildren(r),super.replaceWith(r)}split(t,e=!1){if(!e){if(0===t)return this;if(t===this.length())return this.next}const n=this.clone();return this.parent&&this.parent.insertBefore(n,this.next||void 0),this.children.forEachAt(t,this.length(),((t,r,i)=>{const s=t.split(r,e);null!=s&&n.appendChild(s)})),n}splitAfter(t){const e=this.clone();for(;null!=t.next;)e.appendChild(t.next);return this.parent&&this.parent.insertBefore(e,this.next||void 0),e}unwrap(){this.parent&&this.moveChildren(this.parent,this.next||void 0),this.remove()}update(t,e){const n=[],r=[];t.forEach((t=>{t.target===this.domNode&&"childList"===t.type&&(n.push(...t.addedNodes),r.push(...t.removedNodes))})),r.forEach((t=>{if(null!=t.parentNode&&"IFRAME"!==t.tagName&&document.body.compareDocumentPosition(t)&Node.DOCUMENT_POSITION_CONTAINED_BY)return;const e=this.scroll.find(t);null!=e&&(null==e.domNode.parentNode||e.domNode.parentNode===this.domNode)&&e.detach()})),n.filter((t=>t.parentNode===this.domNode&&t!==this.uiNode)).sort(((t,e)=>t===e?0:t.compareDocumentPosition(e)&Node.DOCUMENT_POSITION_FOLLOWING?1:-1)).forEach((t=>{let e=null;null!=t.nextSibling&&(e=this.scroll.find(t.nextSibling));const n=y(t,this.scroll);(n.next!==e||null==n.next)&&(null!=n.parent&&n.parent.removeChild(this),this.insertBefore(n,e||void 0))})),this.enforceAllowedChildren()}};v.uiClass="";const A=v,x=class t extends A{static create(t){return super.create(t)}static formats(e,n){const r=n.query(t.blotName);if(null==r||e.tagName!==r.tagName){if("string"==typeof this.tagName)return!0;if(Array.isArray(this.tagName))return e.tagName.toLowerCase()}}constructor(t,e){super(t,e),this.attributes=new d(this.domNode)}format(e,n){if(e!==this.statics.blotName||n){const t=this.scroll.query(e,r.INLINE);if(null==t)return;t instanceof i?this.attributes.attribute(t,n):n&&(e!==this.statics.blotName||this.formats()[e]!==n)&&this.replaceWith(e,n)}else this.children.forEach((e=>{e instanceof t||(e=e.wrap(t.blotName,!0)),this.attributes.copy(e)})),this.unwrap()}formats(){const t=this.attributes.values(),e=this.statics.formats(this.domNode,this.scroll);return null!=e&&(t[this.statics.blotName]=e),t}formatAt(t,e,n,i){null!=this.formats()[n]||this.scroll.query(n,r.ATTRIBUTE)?this.isolate(t,e).format(n,i):super.formatAt(t,e,n,i)}optimize(e){super.optimize(e);const n=this.formats();if(0===Object.keys(n).length)return this.unwrap();const r=this.next;r instanceof t&&r.prev===this&&function(t,e){if(Object.keys(t).length!==Object.keys(e).length)return!1;for(const n in t)if(t[n]!==e[n])return!1;return!0}(n,r.formats())&&(r.moveChildren(this),r.remove())}replaceWith(t,e){const n=super.replaceWith(t,e);return this.attributes.copy(n),n}update(t,e){super.update(t,e),t.some((t=>t.target===this.domNode&&"attributes"===t.type))&&this.attributes.build()}wrap(e,n){const r=super.wrap(e,n);return r instanceof t&&this.attributes.move(r),r}};x.allowedChildren=[x,m],x.blotName="inline",x.scope=r.INLINE_BLOT,x.tagName="SPAN";const N=x,E=class t extends A{static create(t){return super.create(t)}static formats(e,n){const r=n.query(t.blotName);if(null==r||e.tagName!==r.tagName){if("string"==typeof this.tagName)return!0;if(Array.isArray(this.tagName))return e.tagName.toLowerCase()}}constructor(t,e){super(t,e),this.attributes=new d(this.domNode)}format(e,n){const s=this.scroll.query(e,r.BLOCK);null!=s&&(s instanceof i?this.attributes.attribute(s,n):e!==this.statics.blotName||n?n&&(e!==this.statics.blotName||this.formats()[e]!==n)&&this.replaceWith(e,n):this.replaceWith(t.blotName))}formats(){const t=this.attributes.values(),e=this.statics.formats(this.domNode,this.scroll);return null!=e&&(t[this.statics.blotName]=e),t}formatAt(t,e,n,i){null!=this.scroll.query(n,r.BLOCK)?this.format(n,i):super.formatAt(t,e,n,i)}insertAt(t,e,n){if(null==n||null!=this.scroll.query(e,r.INLINE))super.insertAt(t,e,n);else{const r=this.split(t);if(null==r)throw new Error("Attempt to insertAt after block boundaries");{const t=this.scroll.create(e,n);r.parent.insertBefore(t,r)}}}replaceWith(t,e){const n=super.replaceWith(t,e);return this.attributes.copy(n),n}update(t,e){super.update(t,e),t.some((t=>t.target===this.domNode&&"attributes"===t.type))&&this.attributes.build()}};E.blotName="block",E.scope=r.BLOCK_BLOT,E.tagName="P",E.allowedChildren=[N,E,m];const w=E,q=class extends A{checkMerge(){return null!==this.next&&this.next.statics.blotName===this.statics.blotName}deleteAt(t,e){super.deleteAt(t,e),this.enforceAllowedChildren()}formatAt(t,e,n,r){super.formatAt(t,e,n,r),this.enforceAllowedChildren()}insertAt(t,e,n){super.insertAt(t,e,n),this.enforceAllowedChildren()}optimize(t){super.optimize(t),this.children.length>0&&null!=this.next&&this.checkMerge()&&(this.next.moveChildren(this),this.next.remove())}};q.blotName="container",q.scope=r.BLOCK_BLOT;const k=q,_=class extends m{static formats(t,e){}format(t,e){super.formatAt(0,this.length(),t,e)}formatAt(t,e,n,r){0===t&&e===this.length()?this.format(n,r):super.formatAt(t,e,n,r)}formats(){return this.statics.formats(this.domNode,this.scroll)}},L={attributes:!0,characterData:!0,characterDataOldValue:!0,childList:!0,subtree:!0},S=class extends A{constructor(t,e){super(null,e),this.registry=t,this.scroll=this,this.build(),this.observer=new MutationObserver((t=>{this.update(t)})),this.observer.observe(this.domNode,L),this.attach()}create(t,e){return this.registry.create(this,t,e)}find(t,e=!1){const n=this.registry.find(t,e);return n?n.scroll===this?n:e?this.find(n.scroll.domNode.parentNode,!0):null:null}query(t,e=r.ANY){return this.registry.query(t,e)}register(...t){return this.registry.register(...t)}build(){null!=this.scroll&&super.build()}detach(){super.detach(),this.observer.disconnect()}deleteAt(t,e){this.update(),0===t&&e===this.length()?this.children.forEach((t=>{t.remove()})):super.deleteAt(t,e)}formatAt(t,e,n,r){this.update(),super.formatAt(t,e,n,r)}insertAt(t,e,n){this.update(),super.insertAt(t,e,n)}optimize(t=[],e={}){super.optimize(e);const n=e.mutationsMap||new WeakMap;let r=Array.from(this.observer.takeRecords());for(;r.length>0;)t.push(r.pop());const i=(t,e=!0)=>{null==t||t===this||null!=t.domNode.parentNode&&(n.has(t.domNode)||n.set(t.domNode,[]),e&&i(t.parent))},s=t=>{n.has(t.domNode)&&(t instanceof A&&t.children.forEach(s),n.delete(t.domNode),t.optimize(e))};let o=t;for(let e=0;o.length>0;e+=1){if(e>=100)throw new Error("[Parchment] Maximum optimize iterations reached");for(o.forEach((t=>{const e=this.find(t.target,!0);null!=e&&(e.domNode===t.target&&("childList"===t.type?(i(this.find(t.previousSibling,!1)),Array.from(t.addedNodes).forEach((t=>{const e=this.find(t,!1);i(e,!1),e instanceof A&&e.children.forEach((t=>{i(t,!1)}))}))):"attributes"===t.type&&i(e.prev)),i(e))})),this.children.forEach(s),o=Array.from(this.observer.takeRecords()),r=o.slice();r.length>0;)t.push(r.pop())}}update(t,e={}){t=t||this.observer.takeRecords();const n=new WeakMap;t.map((t=>{const e=this.find(t.target,!0);return null==e?null:n.has(e.domNode)?(n.get(e.domNode).push(t),null):(n.set(e.domNode,[t]),e)})).forEach((t=>{null!=t&&t!==this&&n.has(t.domNode)&&t.update(n.get(t.domNode)||[],e)})),e.mutationsMap=n,n.has(this.domNode)&&super.update(n.get(this.domNode),e),this.optimize(t,e)}};S.blotName="scroll",S.defaultChild=w,S.allowedChildren=[w,k],S.scope=r.BLOCK_BLOT,S.tagName="DIV";const O=S,T=class t extends m{static create(t){return document.createTextNode(t)}static value(t){return t.data}constructor(t,e){super(t,e),this.text=this.statics.value(this.domNode)}deleteAt(t,e){this.domNode.data=this.text=this.text.slice(0,t)+this.text.slice(t+e)}index(t,e){return this.domNode===t?e:-1}insertAt(t,e,n){null==n?(this.text=this.text.slice(0,t)+e+this.text.slice(t),this.domNode.data=this.text):super.insertAt(t,e,n)}length(){return this.text.length}optimize(e){super.optimize(e),this.text=this.statics.value(this.domNode),0===this.text.length?this.remove():this.next instanceof t&&this.next.prev===this&&(this.insertAt(this.length(),this.next.value()),this.next.remove())}position(t,e=!1){return[this.domNode,t]}split(t,e=!1){if(!e){if(0===t)return this;if(t===this.length())return this.next}const n=this.scroll.create(this.domNode.splitText(t));return this.parent.insertBefore(n,this.next||void 0),this.text=this.statics.value(this.domNode),n}update(t,e){t.some((t=>"characterData"===t.type&&t.target===this.domNode))&&(this.text=this.statics.value(this.domNode))}value(){return this.text}};T.blotName="text",T.scope=r.INLINE_BLOT;const j=T}},e={};function n(r){var i=e[r];if(void 0!==i)return i.exports;var s=e[r]={id:r,loaded:!1,exports:{}};return t[r](s,s.exports,n),s.loaded=!0,s.exports}n.n=function(t){var e=t&&t.__esModule?function(){return t.default}:function(){return t};return n.d(e,{a:e}),e},n.d=function(t,e){for(var r in e)n.o(e,r)&&!n.o(t,r)&&Object.defineProperty(t,r,{enumerable:!0,get:e[r]})},n.g=function(){if("object"==typeof globalThis)return globalThis;try{return this||new Function("return this")()}catch(t){if("object"==typeof window)return window}}(),n.o=function(t,e){return Object.prototype.hasOwnProperty.call(t,e)},n.r=function(t){"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(t,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(t,"__esModule",{value:!0})},n.nmd=function(t){return t.paths=[],t.children||(t.children=[]),t};var r={};return function(){"use strict";n.d(r,{default:function(){return It}});var t=n(3729),e=n(8276),i=n(7912),s=n(6003);class o extends s.ClassAttributor{add(t,e){let n=0;if("+1"===e||"-1"===e){const r=this.value(t)||0;n="+1"===e?r+1:r-1}else"number"==typeof e&&(n=e);return 0===n?(this.remove(t),!0):super.add(t,n.toString())}canAdd(t,e){return super.canAdd(t,e)||super.canAdd(t,parseInt(e,10))}value(t){return parseInt(super.value(t),10)||void 0}}var l=new o("indent","ql-indent",{scope:s.Scope.BLOCK,whitelist:[1,2,3,4,5,6,7,8]}),a=n(9698);class c extends a.Ay{static blotName="blockquote";static tagName="blockquote"}var u=c;class h extends a.Ay{static blotName="header";static tagName=["H1","H2","H3","H4","H5","H6"];static formats(t){return this.tagName.indexOf(t.tagName)+1}}var d=h,f=n(580),p=n(6142);class g extends f.A{}g.blotName="list-container",g.tagName="OL";class m extends a.Ay{static create(t){const e=super.create();return e.setAttribute("data-list",t),e}static formats(t){return t.getAttribute("data-list")||void 0}static register(){p.Ay.register(g)}constructor(t,e){super(t,e);const n=e.ownerDocument.createElement("span"),r=n=>{if(!t.isEnabled())return;const r=this.statics.formats(e,t);"checked"===r?(this.format("list","unchecked"),n.preventDefault()):"unchecked"===r&&(this.format("list","checked"),n.preventDefault())};n.addEventListener("mousedown",r),n.addEventListener("touchstart",r),this.attachUI(n)}format(t,e){t===this.statics.blotName&&e?this.domNode.setAttribute("data-list",e):super.format(t,e)}}m.blotName="list",m.tagName="LI",g.allowedChildren=[m],m.requiredContainer=g;var b=n(9541),y=n(8638),v=n(6772),A=n(664),x=n(4850);class N extends x.A{static blotName="bold";static tagName=["STRONG","B"];static create(){return super.create()}static formats(){return!0}optimize(t){super.optimize(t),this.domNode.tagName!==this.statics.tagName[0]&&this.replaceWith(this.statics.blotName)}}var E=N;class w extends x.A{static blotName="link";static tagName="A";static SANITIZED_URL="about:blank";static PROTOCOL_WHITELIST=["http","https","mailto","tel","sms"];static create(t){const e=super.create(t);return e.setAttribute("href",this.sanitize(t)),e.setAttribute("rel","noopener noreferrer"),e.setAttribute("target","_blank"),e}static formats(t){return t.getAttribute("href")}static sanitize(t){return q(t,this.PROTOCOL_WHITELIST)?t:this.SANITIZED_URL}format(t,e){t===this.statics.blotName&&e?this.domNode.setAttribute("href",this.constructor.sanitize(e)):super.format(t,e)}}function q(t,e){const n=document.createElement("a");n.href=t;const r=n.href.slice(0,n.href.indexOf(":"));return e.indexOf(r)>-1}class k extends x.A{static blotName="script";static tagName=["SUB","SUP"];static create(t){return"super"===t?document.createElement("sup"):"sub"===t?document.createElement("sub"):super.create(t)}static formats(t){return"SUB"===t.tagName?"sub":"SUP"===t.tagName?"super":void 0}}var _=k;class L extends x.A{static blotName="underline";static tagName="U"}var S=L,O=n(746);class T extends O.A{static blotName="formula";static className="ql-formula";static tagName="SPAN";static create(t){if(null==window.katex)throw new Error("Formula module requires KaTeX.");const e=super.create(t);return"string"==typeof t&&(window.katex.render(t,e,{throwOnError:!1,errorColor:"#f00"}),e.setAttribute("data-value",t)),e}static value(t){return t.getAttribute("data-value")}html(){const{formula:t}=this.value();return`<span>${t}</span>`}}var j=T;const C=["alt","height","width"];class R extends s.EmbedBlot{static blotName="image";static tagName="IMG";static create(t){const e=super.create(t);return"string"==typeof t&&e.setAttribute("src",this.sanitize(t)),e}static formats(t){return C.reduce(((e,n)=>(t.hasAttribute(n)&&(e[n]=t.getAttribute(n)),e)),{})}static match(t){return/\.(jpe?g|gif|png)$/.test(t)||/^data:image\/.+;base64/.test(t)}static sanitize(t){return q(t,["http","https","data"])?t:"//:0"}static value(t){return t.getAttribute("src")}format(t,e){C.indexOf(t)>-1?e?this.domNode.setAttribute(t,e):this.domNode.removeAttribute(t):super.format(t,e)}}var I=R;const B=["height","width"];class M extends a.zo{static blotName="video";static className="ql-video";static tagName="IFRAME";static create(t){const e=super.create(t);return e.setAttribute("frameborder","0"),e.setAttribute("allowfullscreen","true"),e.setAttribute("src",this.sanitize(t)),e}static formats(t){return B.reduce(((e,n)=>(t.hasAttribute(n)&&(e[n]=t.getAttribute(n)),e)),{})}static sanitize(t){return w.sanitize(t)}static value(t){return t.getAttribute("src")}format(t,e){B.indexOf(t)>-1?e?this.domNode.setAttribute(t,e):this.domNode.removeAttribute(t):super.format(t,e)}html(){const{video:t}=this.value();return`<a href="${t}">${t}</a>`}}var U=M,D=n(9404),P=n(5232),z=n.n(P),F=n(4266),H=n(3036),$=n(4541),V=n(5508),K=n(584);const W=new s.ClassAttributor("code-token","hljs",{scope:s.Scope.INLINE});class Z extends x.A{static formats(t,e){for(;null!=t&&t!==e.domNode;){if(t.classList&&t.classList.contains(D.Ay.className))return super.formats(t,e);t=t.parentNode}}constructor(t,e,n){super(t,e,n),W.add(this.domNode,n)}format(t,e){t!==Z.blotName?super.format(t,e):e?W.add(this.domNode,e):(W.remove(this.domNode),this.domNode.classList.remove(this.statics.className))}optimize(){super.optimize(...arguments),W.value(this.domNode)||this.unwrap()}}Z.blotName="code-token",Z.className="ql-token";class G extends D.Ay{static create(t){const e=super.create(t);return"string"==typeof t&&e.setAttribute("data-language",t),e}static formats(t){return t.getAttribute("data-language")||"plain"}static register(){}format(t,e){t===this.statics.blotName&&e?this.domNode.setAttribute("data-language",e):super.format(t,e)}replaceWith(t,e){return this.formatAt(0,this.length(),Z.blotName,!1),super.replaceWith(t,e)}}class X extends D.EJ{attach(){super.attach(),this.forceNext=!1,this.scroll.emitMount(this)}format(t,e){t===G.blotName&&(this.forceNext=!0,this.children.forEach((n=>{n.format(t,e)})))}formatAt(t,e,n,r){n===G.blotName&&(this.forceNext=!0),super.formatAt(t,e,n,r)}highlight(t){let e=arguments.length>1&&void 0!==arguments[1]&&arguments[1];if(null==this.children.head)return;const n=`${Array.from(this.domNode.childNodes).filter((t=>t!==this.uiNode)).map((t=>t.textContent)).join("\n")}\n`,r=G.formats(this.children.head.domNode);if(e||this.forceNext||this.cachedText!==n){if(n.trim().length>0||null==this.cachedText){const e=this.children.reduce(((t,e)=>t.concat((0,a.mG)(e,!1))),new(z())),i=t(n,r);e.diff(i).reduce(((t,e)=>{let{retain:n,attributes:r}=e;return n?(r&&Object.keys(r).forEach((e=>{[G.blotName,Z.blotName].includes(e)&&this.formatAt(t,n,e,r[e])})),t+n):t}),0)}this.cachedText=n,this.forceNext=!1}}html(t,e){const[n]=this.children.find(t);return`<pre data-language="${n?G.formats(n.domNode):"plain"}">\n${(0,V.X)(this.code(t,e))}\n</pre>`}optimize(t){if(super.optimize(t),null!=this.parent&&null!=this.children.head&&null!=this.uiNode){const t=G.formats(this.children.head.domNode);t!==this.uiNode.value&&(this.uiNode.value=t)}}}X.allowedChildren=[G],G.requiredContainer=X,G.allowedChildren=[Z,$.A,V.A,H.A];class Q extends F.A{static register(){p.Ay.register(Z,!0),p.Ay.register(G,!0),p.Ay.register(X,!0)}constructor(t,e){if(super(t,e),null==this.options.hljs)throw new Error("Syntax module requires highlight.js. Please include the library on the page before Quill.");this.languages=this.options.languages.reduce(((t,e)=>{let{key:n}=e;return t[n]=!0,t}),{}),this.highlightBlot=this.highlightBlot.bind(this),this.initListener(),this.initTimer()}initListener(){this.quill.on(p.Ay.events.SCROLL_BLOT_MOUNT,(t=>{if(!(t instanceof X))return;const e=this.quill.root.ownerDocument.createElement("select");this.options.languages.forEach((t=>{let{key:n,label:r}=t;const i=e.ownerDocument.createElement("option");i.textContent=r,i.setAttribute("value",n),e.appendChild(i)})),e.addEventListener("change",(()=>{t.format(G.blotName,e.value),this.quill.root.focus(),this.highlight(t,!0)})),null==t.uiNode&&(t.attachUI(e),t.children.head&&(e.value=G.formats(t.children.head.domNode)))}))}initTimer(){let t=null;this.quill.on(p.Ay.events.SCROLL_OPTIMIZE,(()=>{t&&clearTimeout(t),t=setTimeout((()=>{this.highlight(),t=null}),this.options.interval)}))}highlight(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:null,e=arguments.length>1&&void 0!==arguments[1]&&arguments[1];if(this.quill.selection.composing)return;this.quill.update(p.Ay.sources.USER);const n=this.quill.getSelection();(null==t?this.quill.scroll.descendants(X):[t]).forEach((t=>{t.highlight(this.highlightBlot,e)})),this.quill.update(p.Ay.sources.SILENT),null!=n&&this.quill.setSelection(n,p.Ay.sources.SILENT)}highlightBlot(t){let e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:"plain";if(e=this.languages[e]?e:"plain","plain"===e)return(0,V.X)(t).split("\n").reduce(((t,n,r)=>(0!==r&&t.insert("\n",{[D.Ay.blotName]:e}),t.insert(n))),new(z()));const n=this.quill.root.ownerDocument.createElement("div");return n.classList.add(D.Ay.className),n.innerHTML=((t,e,n)=>{if("string"==typeof t.versionString){const r=t.versionString.split(".")[0];if(parseInt(r,10)>=11)return t.highlight(n,{language:e}).value}return t.highlight(e,n).value})(this.options.hljs,e,t),(0,K.hV)(this.quill.scroll,n,[(t,e)=>{const n=W.value(t);return n?e.compose((new(z())).retain(e.length(),{[Z.blotName]:n})):e}],[(t,n)=>t.data.split("\n").reduce(((t,n,r)=>(0!==r&&t.insert("\n",{[D.Ay.blotName]:e}),t.insert(n))),n)],new WeakMap)}}Q.DEFAULTS={hljs:window.hljs,interval:1e3,languages:[{key:"plain",label:"Plain"},{key:"bash",label:"Bash"},{key:"cpp",label:"C++"},{key:"cs",label:"C#"},{key:"css",label:"CSS"},{key:"diff",label:"Diff"},{key:"xml",label:"HTML/XML"},{key:"java",label:"Java"},{key:"javascript",label:"JavaScript"},{key:"markdown",label:"Markdown"},{key:"php",label:"PHP"},{key:"python",label:"Python"},{key:"ruby",label:"Ruby"},{key:"sql",label:"SQL"}]};class J extends a.Ay{static blotName="table";static tagName="TD";static create(t){const e=super.create();return t?e.setAttribute("data-row",t):e.setAttribute("data-row",nt()),e}static formats(t){if(t.hasAttribute("data-row"))return t.getAttribute("data-row")}cellOffset(){return this.parent?this.parent.children.indexOf(this):-1}format(t,e){t===J.blotName&&e?this.domNode.setAttribute("data-row",e):super.format(t,e)}row(){return this.parent}rowOffset(){return this.row()?this.row().rowOffset():-1}table(){return this.row()&&this.row().table()}}class Y extends f.A{static blotName="table-row";static tagName="TR";checkMerge(){if(super.checkMerge()&&null!=this.next.children.head){const t=this.children.head.formats(),e=this.children.tail.formats(),n=this.next.children.head.formats(),r=this.next.children.tail.formats();return t.table===e.table&&t.table===n.table&&t.table===r.table}return!1}optimize(t){super.optimize(t),this.children.forEach((t=>{if(null==t.next)return;const e=t.formats(),n=t.next.formats();if(e.table!==n.table){const e=this.splitAfter(t);e&&e.optimize(),this.prev&&this.prev.optimize()}}))}rowOffset(){return this.parent?this.parent.children.indexOf(this):-1}table(){return this.parent&&this.parent.parent}}class tt extends f.A{static blotName="table-body";static tagName="TBODY"}class et extends f.A{static blotName="table-container";static tagName="TABLE";balanceCells(){const t=this.descendants(Y),e=t.reduce(((t,e)=>Math.max(e.children.length,t)),0);t.forEach((t=>{new Array(e-t.children.length).fill(0).forEach((()=>{let e;null!=t.children.head&&(e=J.formats(t.children.head.domNode));const n=this.scroll.create(J.blotName,e);t.appendChild(n),n.optimize()}))}))}cells(t){return this.rows().map((e=>e.children.at(t)))}deleteColumn(t){const[e]=this.descendant(tt);null!=e&&null!=e.children.head&&e.children.forEach((e=>{const n=e.children.at(t);null!=n&&n.remove()}))}insertColumn(t){const[e]=this.descendant(tt);null!=e&&null!=e.children.head&&e.children.forEach((e=>{const n=e.children.at(t),r=J.formats(e.children.head.domNode),i=this.scroll.create(J.blotName,r);e.insertBefore(i,n)}))}insertRow(t){const[e]=this.descendant(tt);if(null==e||null==e.children.head)return;const n=nt(),r=this.scroll.create(Y.blotName);e.children.head.children.forEach((()=>{const t=this.scroll.create(J.blotName,n);r.appendChild(t)}));const i=e.children.at(t);e.insertBefore(r,i)}rows(){const t=this.children.head;return null==t?[]:t.children.map((t=>t))}}function nt(){return`row-${Math.random().toString(36).slice(2,6)}`}et.allowedChildren=[tt],tt.requiredContainer=et,tt.allowedChildren=[Y],Y.requiredContainer=tt,Y.allowedChildren=[J],J.requiredContainer=Y;class rt extends F.A{static register(){p.Ay.register(J),p.Ay.register(Y),p.Ay.register(tt),p.Ay.register(et)}constructor(){super(...arguments),this.listenBalanceCells()}balanceTables(){this.quill.scroll.descendants(et).forEach((t=>{t.balanceCells()}))}deleteColumn(){const[t,,e]=this.getTable();null!=e&&(t.deleteColumn(e.cellOffset()),this.quill.update(p.Ay.sources.USER))}deleteRow(){const[,t]=this.getTable();null!=t&&(t.remove(),this.quill.update(p.Ay.sources.USER))}deleteTable(){const[t]=this.getTable();if(null==t)return;const e=t.offset();t.remove(),this.quill.update(p.Ay.sources.USER),this.quill.setSelection(e,p.Ay.sources.SILENT)}getTable(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:this.quill.getSelection();if(null==t)return[null,null,null,-1];const[e,n]=this.quill.getLine(t.index);if(null==e||e.statics.blotName!==J.blotName)return[null,null,null,-1];const r=e.parent;return[r.parent.parent,r,e,n]}insertColumn(t){const e=this.quill.getSelection();if(!e)return;const[n,r,i]=this.getTable(e);if(null==i)return;const s=i.cellOffset();n.insertColumn(s+t),this.quill.update(p.Ay.sources.USER);let o=r.rowOffset();0===t&&(o+=1),this.quill.setSelection(e.index+o,e.length,p.Ay.sources.SILENT)}insertColumnLeft(){this.insertColumn(0)}insertColumnRight(){this.insertColumn(1)}insertRow(t){const e=this.quill.getSelection();if(!e)return;const[n,r,i]=this.getTable(e);if(null==i)return;const s=r.rowOffset();n.insertRow(s+t),this.quill.update(p.Ay.sources.USER),t>0?this.quill.setSelection(e,p.Ay.sources.SILENT):this.quill.setSelection(e.index+r.children.length,e.length,p.Ay.sources.SILENT)}insertRowAbove(){this.insertRow(0)}insertRowBelow(){this.insertRow(1)}insertTable(t,e){const n=this.quill.getSelection();if(null==n)return;const r=new Array(t).fill(0).reduce((t=>{const n=new Array(e).fill("\n").join("");return t.insert(n,{table:nt()})}),(new(z())).retain(n.index));this.quill.updateContents(r,p.Ay.sources.USER),this.quill.setSelection(n.index,p.Ay.sources.SILENT),this.balanceTables()}listenBalanceCells(){this.quill.on(p.Ay.events.SCROLL_OPTIMIZE,(t=>{t.some((t=>!!["TD","TR","TBODY","TABLE"].includes(t.target.tagName)&&(this.quill.once(p.Ay.events.TEXT_CHANGE,((t,e,n)=>{n===p.Ay.sources.USER&&this.balanceTables()})),!0)))}))}}var it=rt;const st=(0,n(6078).A)("quill:toolbar");class ot extends F.A{constructor(t,e){if(super(t,e),Array.isArray(this.options.container)){const e=document.createElement("div");e.setAttribute("role","toolbar"),function(t,e){Array.isArray(e[0])||(e=[e]),e.forEach((e=>{const n=document.createElement("span");n.classList.add("ql-formats"),e.forEach((t=>{if("string"==typeof t)lt(n,t);else{const e=Object.keys(t)[0],r=t[e];Array.isArray(r)?function(t,e,n){const r=document.createElement("select");r.classList.add(`ql-${e}`),n.forEach((t=>{const e=document.createElement("option");!1!==t?e.setAttribute("value",String(t)):e.setAttribute("selected","selected"),r.appendChild(e)})),t.appendChild(r)}(n,e,r):lt(n,e,r)}})),t.appendChild(n)}))}(e,this.options.container),t.container?.parentNode?.insertBefore(e,t.container),this.container=e}else"string"==typeof this.options.container?this.container=document.querySelector(this.options.container):this.container=this.options.container;this.container instanceof HTMLElement?(this.container.classList.add("ql-toolbar"),this.controls=[],this.handlers={},this.options.handlers&&Object.keys(this.options.handlers).forEach((t=>{const e=this.options.handlers?.[t];e&&this.addHandler(t,e)})),Array.from(this.container.querySelectorAll("button, select")).forEach((t=>{this.attach(t)})),this.quill.on(p.Ay.events.EDITOR_CHANGE,(()=>{const[t]=this.quill.selection.getRange();this.update(t)}))):st.error("Container required for toolbar",this.options)}addHandler(t,e){this.handlers[t]=e}attach(t){let e=Array.from(t.classList).find((t=>0===t.indexOf("ql-")));if(!e)return;if(e=e.slice(3),"BUTTON"===t.tagName&&t.setAttribute("type","button"),null==this.handlers[e]&&null==this.quill.scroll.query(e))return void st.warn("ignoring attaching to nonexistent format",e,t);const n="SELECT"===t.tagName?"change":"click";t.addEventListener(n,(n=>{let r;if("SELECT"===t.tagName){if(t.selectedIndex<0)return;const e=t.options[t.selectedIndex];r=!e.hasAttribute("selected")&&(e.value||!1)}else r=!t.classList.contains("ql-active")&&(t.value||!t.hasAttribute("value")),n.preventDefault();this.quill.focus();const[i]=this.quill.selection.getRange();if(null!=this.handlers[e])this.handlers[e].call(this,r);else if(this.quill.scroll.query(e).prototype instanceof s.EmbedBlot){if(r=prompt(`Enter ${e}`),!r)return;this.quill.updateContents((new(z())).retain(i.index).delete(i.length).insert({[e]:r}),p.Ay.sources.USER)}else this.quill.format(e,r,p.Ay.sources.USER);this.update(i)})),this.controls.push([e,t])}update(t){const e=null==t?{}:this.quill.getFormat(t);this.controls.forEach((n=>{const[r,i]=n;if("SELECT"===i.tagName){let n=null;if(null==t)n=null;else if(null==e[r])n=i.querySelector("option[selected]");else if(!Array.isArray(e[r])){let t=e[r];"string"==typeof t&&(t=t.replace(/"/g,'\\"')),n=i.querySelector(`option[value="${t}"]`)}null==n?(i.value="",i.selectedIndex=-1):n.selected=!0}else if(null==t)i.classList.remove("ql-active"),i.setAttribute("aria-pressed","false");else if(i.hasAttribute("value")){const t=e[r],n=t===i.getAttribute("value")||null!=t&&t.toString()===i.getAttribute("value")||null==t&&!i.getAttribute("value");i.classList.toggle("ql-active",n),i.setAttribute("aria-pressed",n.toString())}else{const t=null!=e[r];i.classList.toggle("ql-active",t),i.setAttribute("aria-pressed",t.toString())}}))}}function lt(t,e,n){const r=document.createElement("button");r.setAttribute("type","button"),r.classList.add(`ql-${e}`),r.setAttribute("aria-pressed","false"),null!=n?(r.value=n,r.setAttribute("aria-label",`${e}: ${n}`)):r.setAttribute("aria-label",e),t.appendChild(r)}ot.DEFAULTS={},ot.DEFAULTS={container:null,handlers:{clean(){const t=this.quill.getSelection();if(null!=t)if(0===t.length){const t=this.quill.getFormat();Object.keys(t).forEach((t=>{null!=this.quill.scroll.query(t,s.Scope.INLINE)&&this.quill.format(t,!1,p.Ay.sources.USER)}))}else this.quill.removeFormat(t.index,t.length,p.Ay.sources.USER)},direction(t){const{align:e}=this.quill.getFormat();"rtl"===t&&null==e?this.quill.format("align","right",p.Ay.sources.USER):t||"right"!==e||this.quill.format("align",!1,p.Ay.sources.USER),this.quill.format("direction",t,p.Ay.sources.USER)},indent(t){const e=this.quill.getSelection(),n=this.quill.getFormat(e),r=parseInt(n.indent||0,10);if("+1"===t||"-1"===t){let e="+1"===t?1:-1;"rtl"===n.direction&&(e*=-1),this.quill.format("indent",r+e,p.Ay.sources.USER)}},link(t){!0===t&&(t=prompt("Enter link URL:")),this.quill.format("link",t,p.Ay.sources.USER)},list(t){const e=this.quill.getSelection(),n=this.quill.getFormat(e);"check"===t?"checked"===n.list||"unchecked"===n.list?this.quill.format("list",!1,p.Ay.sources.USER):this.quill.format("list","unchecked",p.Ay.sources.USER):this.quill.format("list",t,p.Ay.sources.USER)}}};const at='<svg viewbox="0 0 18 18"><polyline class="ql-even ql-stroke" points="5 7 3 9 5 11"/><polyline class="ql-even ql-stroke" points="13 7 15 9 13 11"/><line class="ql-stroke" x1="10" x2="8" y1="5" y2="13"/></svg>';var ct={align:{"":'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="3" x2="15" y1="9" y2="9"/><line class="ql-stroke" x1="3" x2="13" y1="14" y2="14"/><line class="ql-stroke" x1="3" x2="9" y1="4" y2="4"/></svg>',center:'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="15" x2="3" y1="9" y2="9"/><line class="ql-stroke" x1="14" x2="4" y1="14" y2="14"/><line class="ql-stroke" x1="12" x2="6" y1="4" y2="4"/></svg>',right:'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="15" x2="3" y1="9" y2="9"/><line class="ql-stroke" x1="15" x2="5" y1="14" y2="14"/><line class="ql-stroke" x1="15" x2="9" y1="4" y2="4"/></svg>',justify:'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="15" x2="3" y1="9" y2="9"/><line class="ql-stroke" x1="15" x2="3" y1="14" y2="14"/><line class="ql-stroke" x1="15" x2="3" y1="4" y2="4"/></svg>'},background:'<svg viewbox="0 0 18 18"><g class="ql-fill ql-color-label"><polygon points="6 6.868 6 6 5 6 5 7 5.942 7 6 6.868"/><rect height="1" width="1" x="4" y="4"/><polygon points="6.817 5 6 5 6 6 6.38 6 6.817 5"/><rect height="1" width="1" x="2" y="6"/><rect height="1" width="1" x="3" y="5"/><rect height="1" width="1" x="4" y="7"/><polygon points="4 11.439 4 11 3 11 3 12 3.755 12 4 11.439"/><rect height="1" width="1" x="2" y="12"/><rect height="1" width="1" x="2" y="9"/><rect height="1" width="1" x="2" y="15"/><polygon points="4.63 10 4 10 4 11 4.192 11 4.63 10"/><rect height="1" width="1" x="3" y="8"/><path d="M10.832,4.2L11,4.582V4H10.708A1.948,1.948,0,0,1,10.832,4.2Z"/><path d="M7,4.582L7.168,4.2A1.929,1.929,0,0,1,7.292,4H7V4.582Z"/><path d="M8,13H7.683l-0.351.8a1.933,1.933,0,0,1-.124.2H8V13Z"/><rect height="1" width="1" x="12" y="2"/><rect height="1" width="1" x="11" y="3"/><path d="M9,3H8V3.282A1.985,1.985,0,0,1,9,3Z"/><rect height="1" width="1" x="2" y="3"/><rect height="1" width="1" x="6" y="2"/><rect height="1" width="1" x="3" y="2"/><rect height="1" width="1" x="5" y="3"/><rect height="1" width="1" x="9" y="2"/><rect height="1" width="1" x="15" y="14"/><polygon points="13.447 10.174 13.469 10.225 13.472 10.232 13.808 11 14 11 14 10 13.37 10 13.447 10.174"/><rect height="1" width="1" x="13" y="7"/><rect height="1" width="1" x="15" y="5"/><rect height="1" width="1" x="14" y="6"/><rect height="1" width="1" x="15" y="8"/><rect height="1" width="1" x="14" y="9"/><path d="M3.775,14H3v1H4V14.314A1.97,1.97,0,0,1,3.775,14Z"/><rect height="1" width="1" x="14" y="3"/><polygon points="12 6.868 12 6 11.62 6 12 6.868"/><rect height="1" width="1" x="15" y="2"/><rect height="1" width="1" x="12" y="5"/><rect height="1" width="1" x="13" y="4"/><polygon points="12.933 9 13 9 13 8 12.495 8 12.933 9"/><rect height="1" width="1" x="9" y="14"/><rect height="1" width="1" x="8" y="15"/><path d="M6,14.926V15H7V14.316A1.993,1.993,0,0,1,6,14.926Z"/><rect height="1" width="1" x="5" y="15"/><path d="M10.668,13.8L10.317,13H10v1h0.792A1.947,1.947,0,0,1,10.668,13.8Z"/><rect height="1" width="1" x="11" y="15"/><path d="M14.332,12.2a1.99,1.99,0,0,1,.166.8H15V12H14.245Z"/><rect height="1" width="1" x="14" y="15"/><rect height="1" width="1" x="15" y="11"/></g><polyline class="ql-stroke" points="5.5 13 9 5 12.5 13"/><line class="ql-stroke" x1="11.63" x2="6.38" y1="11" y2="11"/></svg>',blockquote:'<svg viewbox="0 0 18 18"><rect class="ql-fill ql-stroke" height="3" width="3" x="4" y="5"/><rect class="ql-fill ql-stroke" height="3" width="3" x="11" y="5"/><path class="ql-even ql-fill ql-stroke" d="M7,8c0,4.031-3,5-3,5"/><path class="ql-even ql-fill ql-stroke" d="M14,8c0,4.031-3,5-3,5"/></svg>',bold:'<svg viewbox="0 0 18 18"><path class="ql-stroke" d="M5,4H9.5A2.5,2.5,0,0,1,12,6.5v0A2.5,2.5,0,0,1,9.5,9H5A0,0,0,0,1,5,9V4A0,0,0,0,1,5,4Z"/><path class="ql-stroke" d="M5,9h5.5A2.5,2.5,0,0,1,13,11.5v0A2.5,2.5,0,0,1,10.5,14H5a0,0,0,0,1,0,0V9A0,0,0,0,1,5,9Z"/></svg>',clean:'<svg class="" viewbox="0 0 18 18"><line class="ql-stroke" x1="5" x2="13" y1="3" y2="3"/><line class="ql-stroke" x1="6" x2="9.35" y1="12" y2="3"/><line class="ql-stroke" x1="11" x2="15" y1="11" y2="15"/><line class="ql-stroke" x1="15" x2="11" y1="11" y2="15"/><rect class="ql-fill" height="1" rx="0.5" ry="0.5" width="7" x="2" y="14"/></svg>',code:at,"code-block":at,color:'<svg viewbox="0 0 18 18"><line class="ql-color-label ql-stroke ql-transparent" x1="3" x2="15" y1="15" y2="15"/><polyline class="ql-stroke" points="5.5 11 9 3 12.5 11"/><line class="ql-stroke" x1="11.63" x2="6.38" y1="9" y2="9"/></svg>',direction:{"":'<svg viewbox="0 0 18 18"><polygon class="ql-stroke ql-fill" points="3 11 5 9 3 7 3 11"/><line class="ql-stroke ql-fill" x1="15" x2="11" y1="4" y2="4"/><path class="ql-fill" d="M11,3a3,3,0,0,0,0,6h1V3H11Z"/><rect class="ql-fill" height="11" width="1" x="11" y="4"/><rect class="ql-fill" height="11" width="1" x="13" y="4"/></svg>',rtl:'<svg viewbox="0 0 18 18"><polygon class="ql-stroke ql-fill" points="15 12 13 10 15 8 15 12"/><line class="ql-stroke ql-fill" x1="9" x2="5" y1="4" y2="4"/><path class="ql-fill" d="M5,3A3,3,0,0,0,5,9H6V3H5Z"/><rect class="ql-fill" height="11" width="1" x="5" y="4"/><rect class="ql-fill" height="11" width="1" x="7" y="4"/></svg>'},formula:'<svg viewbox="0 0 18 18"><path class="ql-fill" d="M11.759,2.482a2.561,2.561,0,0,0-3.53.607A7.656,7.656,0,0,0,6.8,6.2C6.109,9.188,5.275,14.677,4.15,14.927a1.545,1.545,0,0,0-1.3-.933A0.922,0.922,0,0,0,2,15.036S1.954,16,4.119,16s3.091-2.691,3.7-5.553c0.177-.826.36-1.726,0.554-2.6L8.775,6.2c0.381-1.421.807-2.521,1.306-2.676a1.014,1.014,0,0,0,1.02.56A0.966,0.966,0,0,0,11.759,2.482Z"/><rect class="ql-fill" height="1.6" rx="0.8" ry="0.8" width="5" x="5.15" y="6.2"/><path class="ql-fill" d="M13.663,12.027a1.662,1.662,0,0,1,.266-0.276q0.193,0.069.456,0.138a2.1,2.1,0,0,0,.535.069,1.075,1.075,0,0,0,.767-0.3,1.044,1.044,0,0,0,.314-0.8,0.84,0.84,0,0,0-.238-0.619,0.8,0.8,0,0,0-.594-0.239,1.154,1.154,0,0,0-.781.3,4.607,4.607,0,0,0-.781,1q-0.091.15-.218,0.346l-0.246.38c-0.068-.288-0.137-0.582-0.212-0.885-0.459-1.847-2.494-.984-2.941-0.8-0.482.2-.353,0.647-0.094,0.529a0.869,0.869,0,0,1,1.281.585c0.217,0.751.377,1.436,0.527,2.038a5.688,5.688,0,0,1-.362.467,2.69,2.69,0,0,1-.264.271q-0.221-.08-0.471-0.147a2.029,2.029,0,0,0-.522-0.066,1.079,1.079,0,0,0-.768.3A1.058,1.058,0,0,0,9,15.131a0.82,0.82,0,0,0,.832.852,1.134,1.134,0,0,0,.787-0.3,5.11,5.11,0,0,0,.776-0.993q0.141-.219.215-0.34c0.046-.076.122-0.194,0.223-0.346a2.786,2.786,0,0,0,.918,1.726,2.582,2.582,0,0,0,2.376-.185c0.317-.181.212-0.565,0-0.494A0.807,0.807,0,0,1,14.176,15a5.159,5.159,0,0,1-.913-2.446l0,0Q13.487,12.24,13.663,12.027Z"/></svg>',header:{1:'<svg viewBox="0 0 18 18"><path class="ql-fill" d="M10,4V14a1,1,0,0,1-2,0V10H3v4a1,1,0,0,1-2,0V4A1,1,0,0,1,3,4V8H8V4a1,1,0,0,1,2,0Zm6.06787,9.209H14.98975V7.59863a.54085.54085,0,0,0-.605-.60547h-.62744a1.01119,1.01119,0,0,0-.748.29688L11.645,8.56641a.5435.5435,0,0,0-.022.8584l.28613.30762a.53861.53861,0,0,0,.84717.0332l.09912-.08789a1.2137,1.2137,0,0,0,.2417-.35254h.02246s-.01123.30859-.01123.60547V13.209H12.041a.54085.54085,0,0,0-.605.60547v.43945a.54085.54085,0,0,0,.605.60547h4.02686a.54085.54085,0,0,0,.605-.60547v-.43945A.54085.54085,0,0,0,16.06787,13.209Z"/></svg>',2:'<svg viewBox="0 0 18 18"><path class="ql-fill" d="M16.73975,13.81445v.43945a.54085.54085,0,0,1-.605.60547H11.855a.58392.58392,0,0,1-.64893-.60547V14.0127c0-2.90527,3.39941-3.42187,3.39941-4.55469a.77675.77675,0,0,0-.84717-.78125,1.17684,1.17684,0,0,0-.83594.38477c-.2749.26367-.561.374-.85791.13184l-.4292-.34082c-.30811-.24219-.38525-.51758-.1543-.81445a2.97155,2.97155,0,0,1,2.45361-1.17676,2.45393,2.45393,0,0,1,2.68408,2.40918c0,2.45312-3.1792,2.92676-3.27832,3.93848h2.79443A.54085.54085,0,0,1,16.73975,13.81445ZM9,3A.99974.99974,0,0,0,8,4V8H3V4A1,1,0,0,0,1,4V14a1,1,0,0,0,2,0V10H8v4a1,1,0,0,0,2,0V4A.99974.99974,0,0,0,9,3Z"/></svg>',3:'<svg viewBox="0 0 18 18"><path class="ql-fill" d="M16.65186,12.30664a2.6742,2.6742,0,0,1-2.915,2.68457,3.96592,3.96592,0,0,1-2.25537-.6709.56007.56007,0,0,1-.13232-.83594L11.64648,13c.209-.34082.48389-.36328.82471-.1543a2.32654,2.32654,0,0,0,1.12256.33008c.71484,0,1.12207-.35156,1.12207-.78125,0-.61523-.61621-.86816-1.46338-.86816H13.2085a.65159.65159,0,0,1-.68213-.41895l-.05518-.10937a.67114.67114,0,0,1,.14307-.78125l.71533-.86914a8.55289,8.55289,0,0,1,.68213-.7373V8.58887a3.93913,3.93913,0,0,1-.748.05469H11.9873a.54085.54085,0,0,1-.605-.60547V7.59863a.54085.54085,0,0,1,.605-.60547h3.75146a.53773.53773,0,0,1,.60547.59375v.17676a1.03723,1.03723,0,0,1-.27539.748L14.74854,10.0293A2.31132,2.31132,0,0,1,16.65186,12.30664ZM9,3A.99974.99974,0,0,0,8,4V8H3V4A1,1,0,0,0,1,4V14a1,1,0,0,0,2,0V10H8v4a1,1,0,0,0,2,0V4A.99974.99974,0,0,0,9,3Z"/></svg>',4:'<svg viewBox="0 0 18 18"><path class="ql-fill" d="M10,4V14a1,1,0,0,1-2,0V10H3v4a1,1,0,0,1-2,0V4A1,1,0,0,1,3,4V8H8V4a1,1,0,0,1,2,0Zm7.05371,7.96582v.38477c0,.39648-.165.60547-.46191.60547h-.47314v1.29785a.54085.54085,0,0,1-.605.60547h-.69336a.54085.54085,0,0,1-.605-.60547V12.95605H11.333a.5412.5412,0,0,1-.60547-.60547v-.15332a1.199,1.199,0,0,1,.22021-.748l2.56348-4.05957a.7819.7819,0,0,1,.72607-.39648h1.27637a.54085.54085,0,0,1,.605.60547v3.7627h.33008A.54055.54055,0,0,1,17.05371,11.96582ZM14.28125,8.7207h-.022a4.18969,4.18969,0,0,1-.38525.81348l-1.188,1.80469v.02246h1.5293V9.60059A7.04058,7.04058,0,0,1,14.28125,8.7207Z"/></svg>',5:'<svg viewBox="0 0 18 18"><path class="ql-fill" d="M16.74023,12.18555a2.75131,2.75131,0,0,1-2.91553,2.80566,3.908,3.908,0,0,1-2.25537-.68164.54809.54809,0,0,1-.13184-.8252L11.73438,13c.209-.34082.48389-.36328.8252-.1543a2.23757,2.23757,0,0,0,1.1001.33008,1.01827,1.01827,0,0,0,1.1001-.96777c0-.61621-.53906-.97949-1.25439-.97949a2.15554,2.15554,0,0,0-.64893.09961,1.15209,1.15209,0,0,1-.814.01074l-.12109-.04395a.64116.64116,0,0,1-.45117-.71484l.231-3.00391a.56666.56666,0,0,1,.62744-.583H15.541a.54085.54085,0,0,1,.605.60547v.43945a.54085.54085,0,0,1-.605.60547H13.41748l-.04395.72559a1.29306,1.29306,0,0,1-.04395.30859h.022a2.39776,2.39776,0,0,1,.57227-.07715A2.53266,2.53266,0,0,1,16.74023,12.18555ZM9,3A.99974.99974,0,0,0,8,4V8H3V4A1,1,0,0,0,1,4V14a1,1,0,0,0,2,0V10H8v4a1,1,0,0,0,2,0V4A.99974.99974,0,0,0,9,3Z"/></svg>',6:'<svg viewBox="0 0 18 18"><path class="ql-fill" d="M14.51758,9.64453a1.85627,1.85627,0,0,0-1.24316.38477H13.252a1.73532,1.73532,0,0,1,1.72754-1.4082,2.66491,2.66491,0,0,1,.5498.06641c.35254.05469.57227.01074.70508-.40723l.16406-.5166a.53393.53393,0,0,0-.373-.75977,4.83723,4.83723,0,0,0-1.17773-.14258c-2.43164,0-3.7627,2.17773-3.7627,4.43359,0,2.47559,1.60645,3.69629,3.19043,3.69629A2.70585,2.70585,0,0,0,16.96,12.19727,2.43861,2.43861,0,0,0,14.51758,9.64453Zm-.23047,3.58691c-.67187,0-1.22168-.81445-1.22168-1.45215,0-.47363.30762-.583.72559-.583.96875,0,1.27734.59375,1.27734,1.12207A.82182.82182,0,0,1,14.28711,13.23145ZM10,4V14a1,1,0,0,1-2,0V10H3v4a1,1,0,0,1-2,0V4A1,1,0,0,1,3,4V8H8V4a1,1,0,0,1,2,0Z"/></svg>'},italic:'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="7" x2="13" y1="4" y2="4"/><line class="ql-stroke" x1="5" x2="11" y1="14" y2="14"/><line class="ql-stroke" x1="8" x2="10" y1="14" y2="4"/></svg>',image:'<svg viewbox="0 0 18 18"><rect class="ql-stroke" height="10" width="12" x="3" y="4"/><circle class="ql-fill" cx="6" cy="7" r="1"/><polyline class="ql-even ql-fill" points="5 12 5 11 7 9 8 10 11 7 13 9 13 12 5 12"/></svg>',indent:{"+1":'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="3" x2="15" y1="14" y2="14"/><line class="ql-stroke" x1="3" x2="15" y1="4" y2="4"/><line class="ql-stroke" x1="9" x2="15" y1="9" y2="9"/><polyline class="ql-fill ql-stroke" points="3 7 3 11 5 9 3 7"/></svg>',"-1":'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="3" x2="15" y1="14" y2="14"/><line class="ql-stroke" x1="3" x2="15" y1="4" y2="4"/><line class="ql-stroke" x1="9" x2="15" y1="9" y2="9"/><polyline class="ql-stroke" points="5 7 5 11 3 9 5 7"/></svg>'},link:'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="7" x2="11" y1="7" y2="11"/><path class="ql-even ql-stroke" d="M8.9,4.577a3.476,3.476,0,0,1,.36,4.679A3.476,3.476,0,0,1,4.577,8.9C3.185,7.5,2.035,6.4,4.217,4.217S7.5,3.185,8.9,4.577Z"/><path class="ql-even ql-stroke" d="M13.423,9.1a3.476,3.476,0,0,0-4.679-.36,3.476,3.476,0,0,0,.36,4.679c1.392,1.392,2.5,2.542,4.679.36S14.815,10.5,13.423,9.1Z"/></svg>',list:{bullet:'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="6" x2="15" y1="4" y2="4"/><line class="ql-stroke" x1="6" x2="15" y1="9" y2="9"/><line class="ql-stroke" x1="6" x2="15" y1="14" y2="14"/><line class="ql-stroke" x1="3" x2="3" y1="4" y2="4"/><line class="ql-stroke" x1="3" x2="3" y1="9" y2="9"/><line class="ql-stroke" x1="3" x2="3" y1="14" y2="14"/></svg>',check:'<svg class="" viewbox="0 0 18 18"><line class="ql-stroke" x1="9" x2="15" y1="4" y2="4"/><polyline class="ql-stroke" points="3 4 4 5 6 3"/><line class="ql-stroke" x1="9" x2="15" y1="14" y2="14"/><polyline class="ql-stroke" points="3 14 4 15 6 13"/><line class="ql-stroke" x1="9" x2="15" y1="9" y2="9"/><polyline class="ql-stroke" points="3 9 4 10 6 8"/></svg>',ordered:'<svg viewbox="0 0 18 18"><line class="ql-stroke" x1="7" x2="15" y1="4" y2="4"/><line class="ql-stroke" x1="7" x2="15" y1="9" y2="9"/><line class="ql-stroke" x1="7" x2="15" y1="14" y2="14"/><line class="ql-stroke ql-thin" x1="2.5" x2="4.5" y1="5.5" y2="5.5"/><path class="ql-fill" d="M3.5,6A0.5,0.5,0,0,1,3,5.5V3.085l-0.276.138A0.5,0.5,0,0,1,2.053,3c-0.124-.247-0.023-0.324.224-0.447l1-.5A0.5,0.5,0,0,1,4,2.5v3A0.5,0.5,0,0,1,3.5,6Z"/><path class="ql-stroke ql-thin" d="M4.5,10.5h-2c0-.234,1.85-1.076,1.85-2.234A0.959,0.959,0,0,0,2.5,8.156"/><path class="ql-stroke ql-thin" d="M2.5,14.846a0.959,0.959,0,0,0,1.85-.109A0.7,0.7,0,0,0,3.75,14a0.688,0.688,0,0,0,.6-0.736,0.959,0.959,0,0,0-1.85-.109"/></svg>'},script:{sub:'<svg viewbox="0 0 18 18"><path class="ql-fill" d="M15.5,15H13.861a3.858,3.858,0,0,0,1.914-2.975,1.8,1.8,0,0,0-1.6-1.751A1.921,1.921,0,0,0,12.021,11.7a0.50013,0.50013,0,1,0,.957.291h0a0.914,0.914,0,0,1,1.053-.725,0.81,0.81,0,0,1,.744.762c0,1.076-1.16971,1.86982-1.93971,2.43082A1.45639,1.45639,0,0,0,12,15.5a0.5,0.5,0,0,0,.5.5h3A0.5,0.5,0,0,0,15.5,15Z"/><path class="ql-fill" d="M9.65,5.241a1,1,0,0,0-1.409.108L6,7.964,3.759,5.349A1,1,0,0,0,2.192,6.59178Q2.21541,6.6213,2.241,6.649L4.684,9.5,2.241,12.35A1,1,0,0,0,3.71,13.70722q0.02557-.02768.049-0.05722L6,11.036,8.241,13.65a1,1,0,1,0,1.567-1.24277Q9.78459,12.3777,9.759,12.35L7.316,9.5,9.759,6.651A1,1,0,0,0,9.65,5.241Z"/></svg>',super:'<svg viewbox="0 0 18 18"><path class="ql-fill" d="M15.5,7H13.861a4.015,4.015,0,0,0,1.914-2.975,1.8,1.8,0,0,0-1.6-1.751A1.922,1.922,0,0,0,12.021,3.7a0.5,0.5,0,1,0,.957.291,0.917,0.917,0,0,1,1.053-.725,0.81,0.81,0,0,1,.744.762c0,1.077-1.164,1.925-1.934,2.486A1.423,1.423,0,0,0,12,7.5a0.5,0.5,0,0,0,.5.5h3A0.5,0.5,0,0,0,15.5,7Z"/><path class="ql-fill" d="M9.651,5.241a1,1,0,0,0-1.41.108L6,7.964,3.759,5.349a1,1,0,1,0-1.519,1.3L4.683,9.5,2.241,12.35a1,1,0,1,0,1.519,1.3L6,11.036,8.241,13.65a1,1,0,0,0,1.519-1.3L7.317,9.5,9.759,6.651A1,1,0,0,0,9.651,5.241Z"/></svg>'},strike:'<svg viewbox="0 0 18 18"><line class="ql-stroke ql-thin" x1="15.5" x2="2.5" y1="8.5" y2="9.5"/><path class="ql-fill" d="M9.007,8C6.542,7.791,6,7.519,6,6.5,6,5.792,7.283,5,9,5c1.571,0,2.765.679,2.969,1.309a1,1,0,0,0,1.9-.617C13.356,4.106,11.354,3,9,3,6.2,3,4,4.538,4,6.5a3.2,3.2,0,0,0,.5,1.843Z"/><path class="ql-fill" d="M8.984,10C11.457,10.208,12,10.479,12,11.5c0,0.708-1.283,1.5-3,1.5-1.571,0-2.765-.679-2.969-1.309a1,1,0,1,0-1.9.617C4.644,13.894,6.646,15,9,15c2.8,0,5-1.538,5-3.5a3.2,3.2,0,0,0-.5-1.843Z"/></svg>',table:'<svg viewbox="0 0 18 18"><rect class="ql-stroke" height="12" width="12" x="3" y="3"/><rect class="ql-fill" height="2" width="3" x="5" y="5"/><rect class="ql-fill" height="2" width="4" x="9" y="5"/><g class="ql-fill ql-transparent"><rect height="2" width="3" x="5" y="8"/><rect height="2" width="4" x="9" y="8"/><rect height="2" width="3" x="5" y="11"/><rect height="2" width="4" x="9" y="11"/></g></svg>',underline:'<svg viewbox="0 0 18 18"><path class="ql-stroke" d="M5,3V9a4.012,4.012,0,0,0,4,4H9a4.012,4.012,0,0,0,4-4V3"/><rect class="ql-fill" height="1" rx="0.5" ry="0.5" width="12" x="3" y="15"/></svg>',video:'<svg viewbox="0 0 18 18"><rect class="ql-stroke" height="12" width="12" x="3" y="3"/><rect class="ql-fill" height="12" width="1" x="5" y="3"/><rect class="ql-fill" height="12" width="1" x="12" y="3"/><rect class="ql-fill" height="2" width="8" x="5" y="8"/><rect class="ql-fill" height="1" width="3" x="3" y="5"/><rect class="ql-fill" height="1" width="3" x="3" y="7"/><rect class="ql-fill" height="1" width="3" x="3" y="10"/><rect class="ql-fill" height="1" width="3" x="3" y="12"/><rect class="ql-fill" height="1" width="3" x="12" y="5"/><rect class="ql-fill" height="1" width="3" x="12" y="7"/><rect class="ql-fill" height="1" width="3" x="12" y="10"/><rect class="ql-fill" height="1" width="3" x="12" y="12"/></svg>'};let ut=0;function ht(t,e){t.setAttribute(e,`${!("true"===t.getAttribute(e))}`)}var dt=class{constructor(t){this.select=t,this.container=document.createElement("span"),this.buildPicker(),this.select.style.display="none",this.select.parentNode.insertBefore(this.container,this.select),this.label.addEventListener("mousedown",(()=>{this.togglePicker()})),this.label.addEventListener("keydown",(t=>{switch(t.key){case"Enter":this.togglePicker();break;case"Escape":this.escape(),t.preventDefault()}})),this.select.addEventListener("change",this.update.bind(this))}togglePicker(){this.container.classList.toggle("ql-expanded"),ht(this.label,"aria-expanded"),ht(this.options,"aria-hidden")}buildItem(t){const e=document.createElement("span");e.tabIndex="0",e.setAttribute("role","button"),e.classList.add("ql-picker-item");const n=t.getAttribute("value");return n&&e.setAttribute("data-value",n),t.textContent&&e.setAttribute("data-label",t.textContent),e.addEventListener("click",(()=>{this.selectItem(e,!0)})),e.addEventListener("keydown",(t=>{switch(t.key){case"Enter":this.selectItem(e,!0),t.preventDefault();break;case"Escape":this.escape(),t.preventDefault()}})),e}buildLabel(){const t=document.createElement("span");return t.classList.add("ql-picker-label"),t.innerHTML='<svg viewbox="0 0 18 18"><polygon class="ql-stroke" points="7 11 9 13 11 11 7 11"/><polygon class="ql-stroke" points="7 7 9 5 11 7 7 7"/></svg>',t.tabIndex="0",t.setAttribute("role","button"),t.setAttribute("aria-expanded","false"),this.container.appendChild(t),t}buildOptions(){const t=document.createElement("span");t.classList.add("ql-picker-options"),t.setAttribute("aria-hidden","true"),t.tabIndex="-1",t.id=`ql-picker-options-${ut}`,ut+=1,this.label.setAttribute("aria-controls",t.id),this.options=t,Array.from(this.select.options).forEach((e=>{const n=this.buildItem(e);t.appendChild(n),!0===e.selected&&this.selectItem(n)})),this.container.appendChild(t)}buildPicker(){Array.from(this.select.attributes).forEach((t=>{this.container.setAttribute(t.name,t.value)})),this.container.classList.add("ql-picker"),this.label=this.buildLabel(),this.buildOptions()}escape(){this.close(),setTimeout((()=>this.label.focus()),1)}close(){this.container.classList.remove("ql-expanded"),this.label.setAttribute("aria-expanded","false"),this.options.setAttribute("aria-hidden","true")}selectItem(t){let e=arguments.length>1&&void 0!==arguments[1]&&arguments[1];const n=this.container.querySelector(".ql-selected");t!==n&&(null!=n&&n.classList.remove("ql-selected"),null!=t&&(t.classList.add("ql-selected"),this.select.selectedIndex=Array.from(t.parentNode.children).indexOf(t),t.hasAttribute("data-value")?this.label.setAttribute("data-value",t.getAttribute("data-value")):this.label.removeAttribute("data-value"),t.hasAttribute("data-label")?this.label.setAttribute("data-label",t.getAttribute("data-label")):this.label.removeAttribute("data-label"),e&&(this.select.dispatchEvent(new Event("change")),this.close())))}update(){let t;if(this.select.selectedIndex>-1){const e=this.container.querySelector(".ql-picker-options").children[this.select.selectedIndex];t=this.select.options[this.select.selectedIndex],this.selectItem(e)}else this.selectItem(null);const e=null!=t&&t!==this.select.querySelector("option[selected]");this.label.classList.toggle("ql-active",e)}},ft=class extends dt{constructor(t,e){super(t),this.label.innerHTML=e,this.container.classList.add("ql-color-picker"),Array.from(this.container.querySelectorAll(".ql-picker-item")).slice(0,7).forEach((t=>{t.classList.add("ql-primary")}))}buildItem(t){const e=super.buildItem(t);return e.style.backgroundColor=t.getAttribute("value")||"",e}selectItem(t,e){super.selectItem(t,e);const n=this.label.querySelector(".ql-color-label"),r=t&&t.getAttribute("data-value")||"";n&&("line"===n.tagName?n.style.stroke=r:n.style.fill=r)}},pt=class extends dt{constructor(t,e){super(t),this.container.classList.add("ql-icon-picker"),Array.from(this.container.querySelectorAll(".ql-picker-item")).forEach((t=>{t.innerHTML=e[t.getAttribute("data-value")||""]})),this.defaultItem=this.container.querySelector(".ql-selected"),this.selectItem(this.defaultItem)}selectItem(t,e){super.selectItem(t,e);const n=t||this.defaultItem;if(null!=n){if(this.label.innerHTML===n.innerHTML)return;this.label.innerHTML=n.innerHTML}}},gt=class{constructor(t,e){this.quill=t,this.boundsContainer=e||document.body,this.root=t.addContainer("ql-tooltip"),this.root.innerHTML=this.constructor.TEMPLATE,(t=>{const{overflowY:e}=getComputedStyle(t,null);return"visible"!==e&&"clip"!==e})(this.quill.root)&&this.quill.root.addEventListener("scroll",(()=>{this.root.style.marginTop=-1*this.quill.root.scrollTop+"px"})),this.hide()}hide(){this.root.classList.add("ql-hidden")}position(t){const e=t.left+t.width/2-this.root.offsetWidth/2,n=t.bottom+this.quill.root.scrollTop;this.root.style.left=`${e}px`,this.root.style.top=`${n}px`,this.root.classList.remove("ql-flip");const r=this.boundsContainer.getBoundingClientRect(),i=this.root.getBoundingClientRect();let s=0;if(i.right>r.right&&(s=r.right-i.right,this.root.style.left=`${e+s}px`),i.left<r.left&&(s=r.left-i.left,this.root.style.left=`${e+s}px`),i.bottom>r.bottom){const e=i.bottom-i.top,r=t.bottom-t.top+e;this.root.style.top=n-r+"px",this.root.classList.add("ql-flip")}return s}show(){this.root.classList.remove("ql-editing"),this.root.classList.remove("ql-hidden")}},mt=n(8347),bt=n(5374),yt=n(9609);const vt=[!1,"center","right","justify"],At=["#000000","#e60000","#ff9900","#ffff00","#008a00","#0066cc","#9933ff","#ffffff","#facccc","#ffebcc","#ffffcc","#cce8cc","#cce0f5","#ebd6ff","#bbbbbb","#f06666","#ffc266","#ffff66","#66b966","#66a3e0","#c285ff","#888888","#a10000","#b26b00","#b2b200","#006100","#0047b2","#6b24b2","#444444","#5c0000","#663d00","#666600","#003700","#002966","#3d1466"],xt=[!1,"serif","monospace"],Nt=["1","2","3",!1],Et=["small",!1,"large","huge"];class wt extends yt.A{constructor(t,e){super(t,e);const n=e=>{document.body.contains(t.root)?(null==this.tooltip||this.tooltip.root.contains(e.target)||document.activeElement===this.tooltip.textbox||this.quill.hasFocus()||this.tooltip.hide(),null!=this.pickers&&this.pickers.forEach((t=>{t.container.contains(e.target)||t.close()}))):document.body.removeEventListener("click",n)};t.emitter.listenDOM("click",document.body,n)}addModule(t){const e=super.addModule(t);return"toolbar"===t&&this.extendToolbar(e),e}buildButtons(t,e){Array.from(t).forEach((t=>{(t.getAttribute("class")||"").split(/\s+/).forEach((n=>{if(n.startsWith("ql-")&&(n=n.slice(3),null!=e[n]))if("direction"===n)t.innerHTML=e[n][""]+e[n].rtl;else if("string"==typeof e[n])t.innerHTML=e[n];else{const r=t.value||"";null!=r&&e[n][r]&&(t.innerHTML=e[n][r])}}))}))}buildPickers(t,e){this.pickers=Array.from(t).map((t=>{if(t.classList.contains("ql-align")&&(null==t.querySelector("option")&&kt(t,vt),"object"==typeof e.align))return new pt(t,e.align);if(t.classList.contains("ql-background")||t.classList.contains("ql-color")){const n=t.classList.contains("ql-background")?"background":"color";return null==t.querySelector("option")&&kt(t,At,"background"===n?"#ffffff":"#000000"),new ft(t,e[n])}return null==t.querySelector("option")&&(t.classList.contains("ql-font")?kt(t,xt):t.classList.contains("ql-header")?kt(t,Nt):t.classList.contains("ql-size")&&kt(t,Et)),new dt(t)})),this.quill.on(bt.A.events.EDITOR_CHANGE,(()=>{this.pickers.forEach((t=>{t.update()}))}))}}wt.DEFAULTS=(0,mt.A)({},yt.A.DEFAULTS,{modules:{toolbar:{handlers:{formula(){this.quill.theme.tooltip.edit("formula")},image(){let t=this.container.querySelector("input.ql-image[type=file]");null==t&&(t=document.createElement("input"),t.setAttribute("type","file"),t.setAttribute("accept",this.quill.uploader.options.mimetypes.join(", ")),t.classList.add("ql-image"),t.addEventListener("change",(()=>{const e=this.quill.getSelection(!0);this.quill.uploader.upload(e,t.files),t.value=""})),this.container.appendChild(t)),t.click()},video(){this.quill.theme.tooltip.edit("video")}}}}});class qt extends gt{constructor(t,e){super(t,e),this.textbox=this.root.querySelector('input[type="text"]'),this.listen()}listen(){this.textbox.addEventListener("keydown",(t=>{"Enter"===t.key?(this.save(),t.preventDefault()):"Escape"===t.key&&(this.cancel(),t.preventDefault())}))}cancel(){this.hide(),this.restoreFocus()}edit(){let t=arguments.length>0&&void 0!==arguments[0]?arguments[0]:"link",e=arguments.length>1&&void 0!==arguments[1]?arguments[1]:null;if(this.root.classList.remove("ql-hidden"),this.root.classList.add("ql-editing"),null==this.textbox)return;null!=e?this.textbox.value=e:t!==this.root.getAttribute("data-mode")&&(this.textbox.value="");const n=this.quill.getBounds(this.quill.selection.savedRange);null!=n&&this.position(n),this.textbox.select(),this.textbox.setAttribute("placeholder",this.textbox.getAttribute(`data-${t}`)||""),this.root.setAttribute("data-mode",t)}restoreFocus(){this.quill.focus({preventScroll:!0})}save(){let{value:t}=this.textbox;switch(this.root.getAttribute("data-mode")){case"link":{const{scrollTop:e}=this.quill.root;this.linkRange?(this.quill.formatText(this.linkRange,"link",t,bt.A.sources.USER),delete this.linkRange):(this.restoreFocus(),this.quill.format("link",t,bt.A.sources.USER)),this.quill.root.scrollTop=e;break}case"video":t=function(t){let e=t.match(/^(?:(https?):\/\/)?(?:(?:www|m)\.)?youtube\.com\/watch.*v=([a-zA-Z0-9_-]+)/)||t.match(/^(?:(https?):\/\/)?(?:(?:www|m)\.)?youtu\.be\/([a-zA-Z0-9_-]+)/);return e?`${e[1]||"https"}://www.youtube.com/embed/${e[2]}?showinfo=0`:(e=t.match(/^(?:(https?):\/\/)?(?:www\.)?vimeo\.com\/(\d+)/))?`${e[1]||"https"}://player.vimeo.com/video/${e[2]}/`:t}(t);case"formula":{if(!t)break;const e=this.quill.getSelection(!0);if(null!=e){const n=e.index+e.length;this.quill.insertEmbed(n,this.root.getAttribute("data-mode"),t,bt.A.sources.USER),"formula"===this.root.getAttribute("data-mode")&&this.quill.insertText(n+1," ",bt.A.sources.USER),this.quill.setSelection(n+2,bt.A.sources.USER)}break}}this.textbox.value="",this.hide()}}function kt(t,e){let n=arguments.length>2&&void 0!==arguments[2]&&arguments[2];e.forEach((e=>{const r=document.createElement("option");e===n?r.setAttribute("selected","selected"):r.setAttribute("value",String(e)),t.appendChild(r)}))}var _t=n(8298);const Lt=[["bold","italic","link"],[{header:1},{header:2},"blockquote"]];class St extends qt{static TEMPLATE=['<span class="ql-tooltip-arrow"></span>','<div class="ql-tooltip-editor">','<input type="text" data-formula="e=mc^2" data-link="https://quilljs.com" data-video="Embed URL">','<a class="ql-close"></a>',"</div>"].join("");constructor(t,e){super(t,e),this.quill.on(bt.A.events.EDITOR_CHANGE,((t,e,n,r)=>{if(t===bt.A.events.SELECTION_CHANGE)if(null!=e&&e.length>0&&r===bt.A.sources.USER){this.show(),this.root.style.left="0px",this.root.style.width="",this.root.style.width=`${this.root.offsetWidth}px`;const t=this.quill.getLines(e.index,e.length);if(1===t.length){const t=this.quill.getBounds(e);null!=t&&this.position(t)}else{const n=t[t.length-1],r=this.quill.getIndex(n),i=Math.min(n.length()-1,e.index+e.length-r),s=this.quill.getBounds(new _t.Q(r,i));null!=s&&this.position(s)}}else document.activeElement!==this.textbox&&this.quill.hasFocus()&&this.hide()}))}listen(){super.listen(),this.root.querySelector(".ql-close").addEventListener("click",(()=>{this.root.classList.remove("ql-editing")})),this.quill.on(bt.A.events.SCROLL_OPTIMIZE,(()=>{setTimeout((()=>{if(this.root.classList.contains("ql-hidden"))return;const t=this.quill.getSelection();if(null!=t){const e=this.quill.getBounds(t);null!=e&&this.position(e)}}),1)}))}cancel(){this.show()}position(t){const e=super.position(t),n=this.root.querySelector(".ql-tooltip-arrow");return n.style.marginLeft="",0!==e&&(n.style.marginLeft=-1*e-n.offsetWidth/2+"px"),e}}class Ot extends wt{constructor(t,e){null!=e.modules.toolbar&&null==e.modules.toolbar.container&&(e.modules.toolbar.container=Lt),super(t,e),this.quill.container.classList.add("ql-bubble")}extendToolbar(t){this.tooltip=new St(this.quill,this.options.bounds),null!=t.container&&(this.tooltip.root.appendChild(t.container),this.buildButtons(t.container.querySelectorAll("button"),ct),this.buildPickers(t.container.querySelectorAll("select"),ct))}}Ot.DEFAULTS=(0,mt.A)({},wt.DEFAULTS,{modules:{toolbar:{handlers:{link(t){t?this.quill.theme.tooltip.edit():this.quill.format("link",!1,p.Ay.sources.USER)}}}}});const Tt=[[{header:["1","2","3",!1]}],["bold","italic","underline","link"],[{list:"ordered"},{list:"bullet"}],["clean"]];class jt extends qt{static TEMPLATE=['<a class="ql-preview" rel="noopener noreferrer" target="_blank" href="about:blank"></a>','<input type="text" data-formula="e=mc^2" data-link="https://quilljs.com" data-video="Embed URL">','<a class="ql-action"></a>','<a class="ql-remove"></a>'].join("");preview=this.root.querySelector("a.ql-preview");listen(){super.listen(),this.root.querySelector("a.ql-action").addEventListener("click",(t=>{this.root.classList.contains("ql-editing")?this.save():this.edit("link",this.preview.textContent),t.preventDefault()})),this.root.querySelector("a.ql-remove").addEventListener("click",(t=>{if(null!=this.linkRange){const t=this.linkRange;this.restoreFocus(),this.quill.formatText(t,"link",!1,bt.A.sources.USER),delete this.linkRange}t.preventDefault(),this.hide()})),this.quill.on(bt.A.events.SELECTION_CHANGE,((t,e,n)=>{if(null!=t){if(0===t.length&&n===bt.A.sources.USER){const[e,n]=this.quill.scroll.descendant(w,t.index);if(null!=e){this.linkRange=new _t.Q(t.index-n,e.length());const r=w.formats(e.domNode);this.preview.textContent=r,this.preview.setAttribute("href",r),this.show();const i=this.quill.getBounds(this.linkRange);return void(null!=i&&this.position(i))}}else delete this.linkRange;this.hide()}}))}show(){super.show(),this.root.removeAttribute("data-mode")}}class Ct extends wt{constructor(t,e){null!=e.modules.toolbar&&null==e.modules.toolbar.container&&(e.modules.toolbar.container=Tt),super(t,e),this.quill.container.classList.add("ql-snow")}extendToolbar(t){null!=t.container&&(t.container.classList.add("ql-snow"),this.buildButtons(t.container.querySelectorAll("button"),ct),this.buildPickers(t.container.querySelectorAll("select"),ct),this.tooltip=new jt(this.quill,this.options.bounds),t.container.querySelector(".ql-link")&&this.quill.keyboard.addBinding({key:"k",shortKey:!0},((e,n)=>{t.handlers.link.call(t,!n.format.link)})))}}Ct.DEFAULTS=(0,mt.A)({},wt.DEFAULTS,{modules:{toolbar:{handlers:{link(t){if(t){const t=this.quill.getSelection();if(null==t||0===t.length)return;let e=this.quill.getText(t);/^\S+@\S+\.\S+$/.test(e)&&0!==e.indexOf("mailto:")&&(e=`mailto:${e}`);const{tooltip:n}=this.quill.theme;n.edit("link",e)}else this.quill.format("link",!1,p.Ay.sources.USER)}}}}});var Rt=Ct;t.default.register({"attributors/attribute/direction":i.Mc,"attributors/class/align":e.qh,"attributors/class/background":b.l,"attributors/class/color":y.g3,"attributors/class/direction":i.sY,"attributors/class/font":v.q,"attributors/class/size":A.U,"attributors/style/align":e.Hu,"attributors/style/background":b.s,"attributors/style/color":y.JM,"attributors/style/direction":i.VL,"attributors/style/font":v.z,"attributors/style/size":A.r},!0),t.default.register({"formats/align":e.qh,"formats/direction":i.sY,"formats/indent":l,"formats/background":b.s,"formats/color":y.JM,"formats/font":v.q,"formats/size":A.U,"formats/blockquote":u,"formats/code-block":D.Ay,"formats/header":d,"formats/list":m,"formats/bold":E,"formats/code":D.Cy,"formats/italic":class extends E{static blotName="italic";static tagName=["EM","I"]},"formats/link":w,"formats/script":_,"formats/strike":class extends E{static blotName="strike";static tagName=["S","STRIKE"]},"formats/underline":S,"formats/formula":j,"formats/image":I,"formats/video":U,"modules/syntax":Q,"modules/table":it,"modules/toolbar":ot,"themes/bubble":Ot,"themes/snow":Rt,"ui/icons":ct,"ui/picker":dt,"ui/icon-picker":pt,"ui/color-picker":ft,"ui/tooltip":gt},!0);var It=t.default}(),r.default}()}));
</script>
<script>
const qs={};
<?php foreach ($fields as $id => $fld): ?>
<?php if ($fld['type'] === 'richtext'): ?>
qs['<?=$id?>']=new Quill('#q_<?=$id?>',{theme:'snow',modules:{toolbar:[['bold','italic','underline','strike'],[{header:[2,3,false]}],[{list:'ordered'},{list:'bullet'}],['blockquote'],['link'],['clean']]}});
qs['<?=$id?>'].on('text-change',function(d,o,src){ if(src==='user'&&window.pesiRtDirty) window.pesiRtDirty('<?=$id?>'); });
<?php endif; ?>
<?php endforeach; ?>
document.getElementById('pf').addEventListener('submit',function(){
  for(const[id,q]of Object.entries(qs)){
    document.getElementById('h_'+id).value=q.root.innerHTML;
  }
});
</script>
<?php endif; ?>

<?php endif; ?>
</body>
</html>
