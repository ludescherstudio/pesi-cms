# pesi CMS — Agent Integration Guide

You are integrating **pesi**, a minimal inline CMS, into a finished PHP website.

## What pesi cms does

pesi cms lets clients edit text content via a web dashboard at `/pesi`. Edits are written directly into the PHP source files — no database, no JSON, no sync issues. The PHP file is always the single source of truth.

Editable fields are marked in PHP with the `pesi()` function. The dashboard reads these markers, shows them as form fields, and writes changes back into the same PHP file.

## File structure

```
site/
├── pesi-core.php   # Config + helper function (included by every editable page)
├── pesi.php        # Dashboard (domain.at/pesi)
├── .htaccess       # Protects pesi-core.php and backup files (add to existing)
├── robots.txt      # Must disallow /pesi
└── [page].php      # Editable pages
```

## The pesi() function

```php
<?= pesi('field_id', 'Default content', 'type', 'Label') ?>
```

| Param | Required | Description |
|-------|----------|-------------|
| `$id` | yes | Unique field ID per file. snake_case, `[a-z0-9_]` only |
| `$default` | yes | Content shown on the page. This IS the content — no separate storage |
| `$type` | yes | `text`, `textarea`, or `richtext` |
| `$label` | yes | Human-readable label shown in dashboard. **Always German.** |

### Types

| Type | Dashboard renders | Use for |
|------|-------------------|---------|
| `text` | Single-line input | Headings, names, phone, email, address, button text, short strings |
| `textarea` | Multi-line textarea | Plain-text paragraphs, disclaimers, descriptions without formatting |
| `richtext` | Quill WYSIWYG editor | Formatted HTML blocks with `<p>`, `<strong>`, `<ul>`, `<a>` etc. |
| `image` | Image preview + file upload + path field | Photos that the client must be able to swap (team members, gallery, hero image) |

### Image type

`image` stores the **image path** in the PHP file — exactly like a static `src`, just editable. The `pesi()` call wraps the path string; the actual file lives in the upload folder (`PESI_UPLOAD_DIR`, default `uploads/`).

```php
<img src="<?= pesi('team_anna_foto', '/uploads/anna-2026.jpg', 'image', 'Foto Anna Muster') ?>"
     alt="Anna Muster">
```

Wrap **only the path** in `pesi()` — never the `<img>` tag, `alt`, `class` or `width`/`height`. Use the existing image path as `$default`. On upload, pesi validates the file (extension + real MIME + size), stores it under a collision-free name, writes the new path back into the PHP, and **deletes the replaced file** if it lives in the upload folder and is no longer referenced by any registered page (DSGVO: no orphaned team photos lingering on the webspace). The stored path is escaped on output and rejects script/data-style schemes; clients may paste normal `http(s)` URLs instead of uploading.

### Heredoc syntax for richtext

Richtext content always contains HTML. **Always use heredoc syntax:**

```php
<?= pesi('about', <<<'PESI'
<p>First paragraph with <strong>bold</strong>.</p>
<p>Second paragraph.</p>
PESI, 'richtext', 'Über-uns-Text') ?>
```

**CRITICAL:** The closing `PESI` must start at column 0 (no indentation). It will visually break out of surrounding HTML indentation. This is correct and required by PHP.

```php
<!-- WRONG — indented closing tag causes PHP parse error -->
    <div>
        <?= pesi('x', <<<'PESI'
<p>Content</p>
        PESI, 'richtext', 'Label') ?>
    </div>

<!-- RIGHT — closing PESI at column 0 -->
    <div>
        <?= pesi('x', <<<'PESI'
<p>Content</p>
PESI, 'richtext', 'Label') ?>
    </div>
```

For `text` and `textarea`, use normal string syntax:

```php
<?= pesi('phone', '+43 5522 12345', 'text', 'Telefonnummer') ?>
```

---

## Integration procedure

Execute these steps in order.

### Step 1 — Verify pesi files exist

Check that these files are present:
- `pesi-core.php` (root)
- `pesi.php` (root)

If any are missing: **stop and report.** Do not generate these files — they come from the pesi package.

### Step 2 — Configure pesi-core.php

Open `pesi-core.php` and update the relevant `define()` calls. Do **not** rewrite the whole file — edit the specific lines:

1. **`PESI_PASSWORD`** — set a strong password for the client.

   **Prefer a hash over plaintext.** pesi treats any value starting with `$` as a
   `password_hash()` result and verifies it with `password_verify()`. Generate it with
   `php -r "echo password_hash('THE-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"` and paste the
   output verbatim — the `$` characters are part of the hash and must be preserved.
   Report the **plaintext** password to the user in Step 11 (they need it to log in), but
   store only the hash in the file.

   If you store plaintext anyway, avoid `'` and `\` — the value sits inside a
   single-quoted PHP string. (Do **not** apply that restriction to a hash; a hash always
   contains `$`, which is safe in single quotes.)
2. **`BRAND_NAME`** — the client/project name shown in the dashboard header and login screen.
3. **`BRAND_COLOR`** — try to match the site's primary color. Scan the main CSS file for a dominant `--primary`, `--accent`, or hex value used in headings and buttons. If unclear, leave the default `#c47a2a`.
4. **`BRAND_LOGO`** — if the site has a logo at a predictable path (e.g. `/assets/logo.svg`, `/img/logo.png`), set it. Otherwise leave empty — the dashboard shows the pesi logo.
5. **`LANG`** — `'de'` for German-speaking clients (default), `'en'` if the site is clearly English-only.

### Step 3 — Inventory pages

List all `.php` files in the root directory. Exclude:
- `pesi-core.php`
- `pesi.php`
- files inside any subdirectory

These are candidates for editable pages.

### Step 4 — Scan each page for editable content

Open each PHP file. Identify all visible text that a client would typically want to change.

**REPLACE with pesi():**
- Business/practice name
- Address, phone number, email
- Opening hours
- Headings (h1, h2, h3 text content)
- Body text, descriptions, introductions
- Legal texts (Impressum, Datenschutz, Haftungshinweis)
- Team/person descriptions
- Service descriptions, offerings
- Prices and price descriptions
- Quotes, testimonials
- Button labels (text only, not href)
- Footer text content
- Swappable photos — team/person portraits, gallery images, hero/section background images (wrap the path with type `image`)

**Special rule — pure-text pages (Impressum, Datenschutz and similar):**

These pages consist entirely of flowing text with no interactive elements. Do not wrap every heading and paragraph individually. Instead, use a single `richtext` field that covers the entire content below the `<h1>`. The `<h1>` itself stays static in the HTML.

```php
<h1>Impressum</h1>
<?= pesi('impressum_inhalt', <<<'PESI'
<h2>Angaben gemäß § 5 ECG</h2>
<p>Musterstraße 1, 6800 Feldkirch</p>
...
PESI, 'richtext', 'Impressum Inhalt') ?>
```

One field per pure-text page. The client edits the entire body in one Quill editor.

**DO NOT REPLACE — leave static:**
- Navigation links and menu structure
- HTML attributes: `class`, `id`, `href`, `alt`, `style` (and `src` — **except** when intentionally making a photo swappable via type `image`, see below)
- PHP logic, loops, conditions, variables
- CSS and JavaScript (inline or external)
- `<meta>` tags (title, description) — unless explicitly requested
- Decorative/structural images (logos, icons, CSS background images) — only wrap content photos a client would realistically swap
- Dynamic year from `date('Y')`
- Structural HTML tags themselves (nav, header, footer, section)
- Existing `require` / `include` statements

### Step 5 — Add require to each page

At the very top of each page that will have editable fields:

If file starts with `<!DOCTYPE` or `<html>`:
```php
<?php require_once 'pesi-core.php'; ?>
<!DOCTYPE html>
...
```

If file already starts with `<?php`:
```php
<?php
require_once 'pesi-core.php';
// ... existing code
```

### Step 6 — Insert pesi fields

Replace each identified text with a `pesi()` call.

**ID rules:**
- snake_case, only `[a-z0-9_]`
- Prefix with section: `hero_titel`, `about_text`, `footer_firma`
- Unique per file — no duplicate IDs within the same file
- Same text in two places = two different IDs (`firma_header`, `firma_footer`)

**Label rules:**
- Always German
- Client-friendly — a non-technical person must immediately understand what to edit
- Add context: "Firmenname im Footer" not just "Firmenname"
- Examples: "Hauptüberschrift", "Begrüßungstext", "Öffnungszeiten", "Haftungshinweis"

**Type decision:**
- No HTML, single line → `text`
- No HTML, multiple lines or long → `textarea`
- Contains HTML tags or needs formatting → `richtext` (always with heredoc)
- Swappable photo (path is an image file) → `image`

### Step 7 — Register pages in pesi-core.php

Open `pesi-core.php`, find `$PESI_PAGES`, replace with all pages that have pesi fields:

```php
$PESI_PAGES = [
    'index.php'       => 'Startseite',
    'impressum.php'   => 'Impressum',
    'datenschutz.php' => 'Datenschutz',
];
```

Values (right side) are German display names for the dashboard sidebar.

### Step 8 — Update .htaccess

**Append** these rules to the existing root `.htaccess`. Do NOT overwrite existing rules:

```apache
# pesi CMS
RewriteRule ^pesi$  pesi.php [L]
RewriteRule ^cms$   pesi.php [L]
<Files "pesi-core.php">
    Require all denied
</Files>
<FilesMatch "^\.pesi-">
    Require all denied
</FilesMatch>
```

The `^\.pesi-` pattern covers every internal pesi file: the rotated backups
(`page.php.pesi-backup.1`/`.2`) **and** the login-throttle register
(`.pesi-throttle`). Backups are byte-for-byte copies of the page source, so a
leak would expose the full PHP source (incl. the password) — blocking them is
security-critical, not cosmetic.

> **⚠ Nginx (or any server that does not read `.htaccess`).** The block above is
> inert on Nginx — it never reads `.htaccess`. The `.pesi-backup.*` files would
> then be served as **plain-text source code**. Add this to the `server {}` block
> instead:
> ```nginx
> location ~ /\.pesi-        { deny all; }
> location = /pesi-core.php  { deny all; }
> ```

**Do NOT** add any rule that denies access to the upload folder (`uploads/`, or whatever `PESI_UPLOAD_DIR` is set to) — uploaded photos must stay publicly reachable, otherwise images break on the live site.

**Create `uploads/.htaccess`** (path = `PESI_UPLOAD_DIR`) to block script execution inside the upload folder, so a disguised upload can never run as code:

```apache
php_flag engine off
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps
RemoveType .php .phtml .php3 .php4 .php5 .php7 .phps
<FilesMatch "\.(php|phtml|php[3-7]|phps)$">
    Require all denied
</FilesMatch>
```

If `uploads/` does not exist yet, create it (pesi also creates it on first upload).

**While reading the file, also check for these two known bugs and fix them in-place if found:**

**Bug 1 — PHP extension stripping regex matches `.php` in query string (causes broken pesi admin links)**

If this pattern exists:
```apache
RewriteCond %{THE_REQUEST} \s/+(.+?)\.php[\s?] [NC]
RewriteRule ^ /%1 [R=301,L,NE]
```

Replace with:
```apache
# Entfernt .php (nur im URL-Pfad, nicht im Query-String)
RewriteCond %{THE_REQUEST} \s/+([^?]+)\.php[\s?] [NC]
RewriteRule ^ /%1 [R=301,L,NE]
```

The only change is `(.+?)` → `([^?]+)`. `[^?]+` matches one or more characters that are not `?`, so the match stops at the end of the URL path before the query string begins — `?page=index.php` no longer triggers the rule.

`%{THE_REQUEST}` must be kept as-is. It always reflects the original browser request, not the internally rewritten URL. Switching to `%{REQUEST_URI}` would cause an infinite rewrite loop: after an internal rewrite (`/impressum` → `impressum.php`) Apache restarts the ruleset, hits the stripping rule again on the rewritten URL, and redirects back to `/impressum` indefinitely.

**Bug 2 — HTTPS redirect + ErrorDocument combination (not a pesi bug, no fix needed)**

If the site uses both `ErrorDocument 403` and an HTTPS redirect rule, Apache may produce a double 403 on sub-requests. This is a website template issue, not caused by pesi. Document it in the report (Step 11) but do not modify the rules.

**Bug 3 — Strict Content Security Policy with `script-src` blocks inline Quill**

Quill is bundled directly inside `pesi.php` — no CDN requests are made. However, if the site has a `Content-Security-Policy` header with `script-src` that does **not** include `'unsafe-inline'`, the inline `<script>` block containing Quill will be blocked. The result: richtext fields render as a blank box with no toolbar.

If a CSP header is found (via `.htaccess` `Header set Content-Security-Policy` or a `<meta http-equiv="Content-Security-Policy">` tag), and it restricts `script-src`, add a nonce or `'unsafe-inline'` — or simply exclude the dashboard path from the CSP rule. Do not add a CSP header if none exists.

### Step 9 — Update robots.txt


A `robots.txt` always already exists. **Never overwrite or recreate it.** Append this line if not already present:

```
Disallow: /pesi
Disallow: /cms
```

### Step 10 — Validate


Run through this checklist. Report any failures:

- Every file in `$PESI_PAGES` exists in root
- Every registered file has `require_once 'pesi-core.php'` at the top
- All field IDs are unique per file
- All field IDs match `[a-z0-9_]+`
- All labels are German and non-technical
- All `richtext` fields use heredoc syntax
- All heredoc closing `PESI` tags are at column 0
- `BRAND_NAME` is set in `pesi-core.php`
- `BRAND_COLOR` is set in `pesi-core.php` (matched to site's primary color)
- `.htaccess` contains rewrite rules for both `pesi` and `cms`
- `.htaccess` denies direct access to `pesi-core.php` and all `.pesi-*` files (`^\.pesi-`: backups **and** `.pesi-throttle`)
- On Nginx/non-Apache: equivalent `deny` rules exist in the server config (`.htaccess` is ignored there)
- `.htaccess` does **not** block the upload folder; `uploads/.htaccess` exists and disables script execution
- `PESI_UPLOAD_DIR` is a relative folder inside the project root, without `..` segments
- All `image` fields wrap only the path, with a valid existing image path as `$default`
- Richtext is sanitized by pesi; do not use it as a place for custom scripts or embeds
- `robots.txt` contains `Disallow: /pesi` and `Disallow: /cms`
- No client-editable text was missed

### Step 11 — Report


Output a summary:

```
pesi integration complete.

Pages: X registered
Fields: Y total (Z text, W textarea, V richtext, U image)
robots.txt: updated
.htaccess: updated

Next steps:
- Password is already set (reported above)
- Verify file write permissions
- Verify file write permissions
- Test dashboard: domain.at/pesi
```

---

## Full example

**Before:**

```html
<section class="hero">
    <h1>Praxis für Psychotherapie</h1>
    <p>Willkommen in meiner Praxis in Feldkirch.</p>
    <a href="/kontakt" class="btn">Termin vereinbaren</a>
</section>

<section class="about">
    <h2>Über mich</h2>
    <p>Ich bin <strong>Dr. Maria Müller</strong>, klinische Psychologin.</p>
    <p>Seit 2015 in eigener Praxis.</p>
</section>

<footer>
    <p>Dr. Müller · Musterstr. 12, 6800 Feldkirch · +43 5522 12345</p>
</footer>
```

**After:**

```php
<?php require_once 'pesi-core.php'; ?>
<!-- head etc. -->

<section class="hero">
    <h1><?= pesi('hero_titel', 'Praxis für Psychotherapie', 'text', 'Hauptüberschrift') ?></h1>
    <p><?= pesi('hero_text', 'Willkommen in meiner Praxis in Feldkirch.', 'text', 'Begrüßungstext') ?></p>
    <a href="/kontakt" class="btn"><?= pesi('hero_button', 'Termin vereinbaren', 'text', 'Button-Text') ?></a>
</section>

<section class="about">
    <h2><?= pesi('about_titel', 'Über mich', 'text', 'Überschrift Über-mich') ?></h2>
    <?= pesi('about_text', <<<'PESI'
<p>Ich bin <strong>Dr. Maria Müller</strong>, klinische Psychologin.</p>
<p>Seit 2015 in eigener Praxis.</p>
PESI, 'richtext', 'Über-mich-Text') ?>
</section>

<footer>
    <p><?= pesi('footer_kontakt', 'Dr. Müller · Musterstr. 12, 6800 Feldkirch · +43 5522 12345', 'text', 'Kontaktzeile im Footer') ?></p>
</footer>
```

Key decisions:
- `hero_text` → `text` (single line, no HTML)
- `about_text` → `richtext` (contains `<strong>`, multiple `<p>`)
- `href="/kontakt"` → untouched (structure, not content)
- `class="btn"` → untouched (attribute, not content)
