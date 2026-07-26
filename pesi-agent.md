# pesi CMS — Agent Integration Guide

You are integrating **pesi**, a minimal inline CMS, into a finished PHP website.

> **This is the installation guide.** It tells an agent how to add pesi *to a
> customer site*. If you were sent here to change pesi's own source code, you
> want `AGENTS.md` in the pesi repository instead.
>
> You do **not** need to copy this file into the customer project. Read it while
> integrating, then leave it behind — only `pesi-core.php` and `pesi.php` belong
> in the customer site (see the file structure below). If you do keep a copy
> there, never rename it to `AGENTS.md`: most projects already have one of their
> own, and it would collide with or overwrite it.

## What pesi cms does

pesi cms lets clients edit text content via a web dashboard at `/pesi`. Edits are written directly into the PHP source files — no database, no JSON, no sync issues. The PHP file is always the single source of truth.

Editable fields are marked in PHP with the `pesi()` function. The dashboard reads these markers, shows them as form fields, and writes changes back into the same PHP file.

Three things you place in the markup, and what the client gets from each:

| You write | Client can |
|---|---|
| `pesi('id', …)` | edit that text or swap that image |
| `<!-- pesi:item team:1 -->…` | add / duplicate / reorder / delete entries in a list |
| `<!-- pesi:toggle urlaub -->…` | show or hide that whole section |

The client can never create pages, menu entries or layout — that stays your job.

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
| `$type` | defaults to `text` — **always pass it** | `text`, `textarea`, `richtext`, or `image` |
| `$label` | optional in code — **always pass it** | Human-readable label shown in dashboard. **Always German.** Omit it and the client sees the raw field ID (`footer_firma`) instead of a readable name. |

Duplicate IDs within one file are **not** an error — the parser silently keeps
only the first occurrence, and the second location becomes uneditable. Keep them
unique.

The parser scans the **raw source text**, not executed code. A `pesi()` call
inside an HTML comment or a commented-out PHP block is still picked up and shown
in the dashboard. Delete dead code instead of commenting it out.

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

**CRITICAL — two things break this silently:**

1. **The closing `PESI` must start at column 0** (no indentation). It will
   visually break out of surrounding HTML indentation. This is correct and
   required by PHP.
2. **The opening marker must be `<<<'PESI'` — with the quotes.** `<<<PESI`
   (without quotes) is valid PHP but pesi does **not** recognise it: the field
   never appears in the dashboard, and there is no error message anywhere. The
   quotes make it a *nowdoc*, which is what keeps `$` and `\` in client content
   from being interpreted.

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

### What richtext does to your markup — read before choosing it

`richtext` content is **sanitised on every render**, not just on save. Only these
tags survive:

```
p  br  strong  b  em  i  u  s  a  ul  ol  li  blockquote  h2  h3
```

Everything else is **unwrapped** — the tag disappears, its text content stays.
`<script>`, `<style>`, `<iframe>`, `<object>` and `<embed>` are removed entirely,
along with all `on*` handlers and any non-`http(s)`/`mailto:`/`tel:`/`/`/`#` link.
On `<a>` only `href`, `title`, `target` and `rel` survive — **no `class`**.

Consequences you must design around:

- `<h1>`, `<div class="card">`, `<span>`, `<img>`, `<table>`, `<section>` inside a
  richtext field are **destroyed on output**. Never put layout inside richtext.
- Output is wrapped in `<div class="pesi-richtext">…</div>`, and a small `<style>`
  block is injected once per page. A CSS rule like `.about > p` will no longer
  match — write `.about p` or style `.pesi-richtext p`.
- Need a styled box, a grid or an image? Build it in **static HTML** and put
  `pesi()` fields *inside* it. Use `image` for pictures, never an `<img>` typed
  into a richtext editor.

This matters most for the pure-text pages below: an Impressum containing a
`<table>` or `<h1>` will lose it. Convert those to `<h2>`/`<p>` first, or keep the
table static outside the richtext field.

For `text` and `textarea` the value is **HTML-escaped** on output — `<b>bold</b>`
in such a field renders as visible `&lt;b&gt;` text, it does not format. That is
intentional: use `richtext` when formatting is needed.

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

Leave these alone unless the site needs it — the defaults are sane:

| Define | Default | Change it when |
|---|---|---|
| `PESI_UPLOAD_DIR` | `'uploads'` | the site already has a media folder you want to reuse. Relative to the root, no leading slash, no `..` |
| `PESI_UPLOAD_MAX_BYTES` | 5 MB | the client uploads large photos. Must stay ≤ the host's `upload_max_filesize`/`post_max_size` |
| `PESI_UPLOAD_TYPES` | `jpg,jpeg,png,webp,avif,gif` | rarely. **Never add `svg`** — it is excluded deliberately, an SVG can carry script |
| `PESI_BACKUP_ENABLED` | `true` | never in production. This is the rollback safety net |
| `PESI_SYNTAX_CHECK` | `true` | never in production. Without it a broken save is not rolled back |

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

### Step 6b — Repeatable blocks and visibility toggles

**Do not skip this step.** Both features exist only if *you* write the markers
into the markup — the client can never create them from the dashboard. Skip it
and the client simply never gets "add another team member" or "hide the holiday
notice", even though pesi fully supports both.

Both markers are plain HTML comments and are invisible in the rendered page.

#### Repeatable blocks — `pesi:item`

Use for any list the client should be able to grow or shrink: team members,
services, testimonials, gallery entries, opening-hour rows, FAQ entries.

Wrap **one** entry. pesi does the rest:

```php
<section class="team">
<!-- pesi:item team:1 -->
  <article class="member">
    <img src="<?= pesi('team_1_foto', '/uploads/anna.jpg', 'image', 'Foto (Teammitglied)') ?>" alt="">
    <h3><?= pesi('team_1_name', 'Anna Muster', 'text', 'Name') ?></h3>
    <p><?= pesi('team_1_rolle', 'Psychologin', 'text', 'Rolle') ?></p>
  </article>
<!-- /pesi:item -->
</section>
```

Three rules — break any one of them and the feature fails silently:

1. **Marker format** is `<!-- pesi:item GROUP:N -->` … `<!-- /pesi:item -->`.
   `GROUP` matches `[a-z0-9_]+`, `N` is a number. Write the first entry as `:1`.
2. **Every field ID inside the block must start with `GROUP_N_`** —
   `team_1_name`, `team_1_rolle`, `team_1_foto`. Duplicating rewrites exactly
   this prefix (`team_1_` → `team_2_`). A field without the prefix gets cloned
   with an *identical* ID, and the parser then keeps only the first copy — the
   duplicated entry appears half-empty and uneditable.
3. **Write exactly one entry into the source, not three.** Entry `:1` is the
   template that "+ Eintrag hinzufügen" clones. The client creates the rest.

The dashboard then renders each entry as its own card with ↑ ↓ · Duplizieren ·
Löschen, plus one "+ Eintrag hinzufügen" button under the group. pesi refuses to
delete the last remaining entry, so the template can never be lost.

Instance numbers are **stable and never renumbered**. After deleting `:2` you
legitimately have `team:1` and `team:3`; new entries continue at `max + 1`. Do
not renumber them by hand — the numbers only need to be unique within the group.

#### Visibility toggle — `pesi:toggle`

Use for content that is seasonally on and off: holiday notices, a temporary
banner, "currently accepting new clients", a Christmas opening-hours box.

```php
<!-- pesi:toggle urlaub -->
<div class="notice">
  <p><?= pesi('urlaub_text', 'Vom 1.–15. August im Urlaub.', 'text', 'Urlaubshinweis') ?></p>
</div>
<!-- /pesi:toggle -->
```

The dashboard shows a "Sichtbarkeit" panel with an Einblenden/Ausblenden button
per group. Hiding wraps the body in `<?php if (false): ?> … <?php endif; ?>`, so
the content stays in the file and stays editable — it is only not rendered.

- Group name matches `[a-z0-9_]+` and is shown to the client with underscores
  turned into spaces and **only the first letter capitalised** — `urlaub` →
  "Urlaub", `neue_klienten` → "Neue klienten". Pick single words that read well.
- **Never hand-write the `if (false)` wrapper.** pesi matches those two strings
  literally, including the exact spacing. Always ship the section *visible* and
  let the client hide it.
- Do not nest a toggle inside another toggle.
- Fields inside a hidden section still appear in the dashboard. That is
  intentional: the client prepares next year's notice while it stays invisible.

A toggle **may** contain `pesi:item` blocks — that combination works in both
states. The reverse (a toggle inside a block) is not supported.

#### When to use which

| Client need | Solution |
|---|---|
| "Sometimes I have 3 team members, sometimes 5" | `pesi:item` block |
| "This notice should only show in summer" | `pesi:toggle` |
| "The text changes, the structure never does" | plain `pesi()` field |
| "I want a new page / new menu entry" | **none** — that is developer work |

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
RewriteEngine On
RewriteRule ^pesi$  pesi.php [L]
RewriteRule ^cms$   pesi.php [L]
<Files "pesi-core.php">
    Require all denied
</Files>
<FilesMatch "^\.pesi-">
    Require all denied
</FilesMatch>
```

Keep `RewriteEngine On` unless an earlier block in the same file already enables
it. Without it the two `RewriteRule` lines are inert and `/pesi` returns 404.

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

**First, check write permissions.** pesi rewrites the page files in place; this
is the single most common reason a fresh install "saves nothing". Verify that
the PHP user can write:

- every file listed in `$PESI_PAGES`
- the project root (pesi creates `page.php.pesi-backup.1/.2` next to each page,
  plus a `.pesi-throttle` register)
- the upload folder (`PESI_UPLOAD_DIR`), if any `image` field exists

On a typical shared host the FTP user and the PHP user are the same and this is
already true. If you cannot verify it from your environment, say so explicitly in
the report instead of assuming it works.

Then run through this checklist. Report any failures:

- Every file in `$PESI_PAGES` exists in root
- Every registered file has `require_once 'pesi-core.php'` at the top
- All field IDs are unique per file
- All field IDs match `[a-z0-9_]+`
- All labels are German and non-technical
- All `richtext` fields use heredoc syntax
- All heredoc openers are `<<<'PESI'` **with quotes**, closing `PESI` at column 0
- No `richtext` field contains layout tags (`div`, `span`, `img`, `table`, `h1`, `section`) — they are stripped on render
- Repeatable lists (team, services, testimonials, FAQ) are wrapped in `pesi:item` blocks, with exactly one entry in the source
- Every field ID inside a block carries the `GROUP_N_` prefix
- Seasonal/temporary sections are wrapped in `pesi:toggle` and shipped **visible**
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

Pages:    X registered
Fields:   Y total (Z text, W textarea, V richtext, U image)
Blocks:   N repeatable groups (team, leistungen, …)
Toggles:  M switchable sections (urlaub, …)
robots.txt: updated
.htaccess:  updated

Dashboard password: <the plaintext password — stored hashed in pesi-core.php>

Next steps:
- Test dashboard: domain.at/pesi
- Write permissions verified: yes / no / could not check
- Known issues found in the existing site: …
```

Always report the **plaintext** password here even when you stored a hash — it is
the only copy the client has. Also state honestly whether write permissions were
verified or merely assumed.

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

### Example — a repeated structure and a seasonal section

Whenever the original markup contains the **same structure more than once**, do
not turn each copy into its own fields. Wrap **one** copy in a block:

```php
<!-- pesi:toggle urlaub -->
<div class="notice">
  <p><?= pesi('urlaub_text', 'Vom 1.–15. August im Urlaub.', 'text', 'Urlaubshinweis') ?></p>
</div>
<!-- /pesi:toggle -->

<section class="team">
<!-- pesi:item team:1 -->
  <article class="member">
    <img src="<?= pesi('team_1_foto', '/uploads/anna.jpg', 'image', 'Foto (Teammitglied)') ?>" alt="">
    <h3><?= pesi('team_1_name', 'Anna Muster', 'text', 'Name') ?></h3>
    <p><?= pesi('team_1_rolle', 'Psychologin', 'text', 'Rolle') ?></p>
  </article>
<!-- /pesi:item -->
</section>
```

The original site may show three team members — you still write **one** entry.
The client adds the other two in the dashboard and can reorder or delete them
later without calling you. The holiday notice ships **visible**; the client hides
it in August and shows it again next year, with the text preserved in between.

This is the difference between a site the client can actually maintain and one
where they call you for every change.
