<p align="center">
  <strong>pesi CMS</strong><br>
  <em>The minimal inline CMS for static PHP websites.</em>
</p>

> *Pesi* (Swahili) — lightweight, effortless.
> **pesi CMS** is exactly that: a featherweight system to edit your website. No database. No bloated menus. Just your content, incredibly easy to manage.

**An inline CMS for PHP — three files, one password, done.**

No MySQL. No Node.js. No Docker. No external database. No state-sync headaches between CMS and FTP. The PHP file is always the single source of truth.

Built for developers who maintain static client websites and want the client to edit their own text — without spinning up a full WordPress stack. Works on any shared host with PHP 7.4+.

---

## Why pesi CMS?

|                             | pesi CMS       | WordPress       | Statamic        | Kirby           |
|-----------------------------|----------------|-----------------|-----------------|-----------------|
| No database                 | ✅             | ❌ MySQL        | ✅              | ✅              |
| No dependencies             | ✅             | ❌              | ❌ (Composer)   | ❌ (Composer)   |
| Edits written to source     | ✅             | ❌              | ❌              | ❌              |
| Shared hosting              | ✅             | ✅              | ⚠️              | ✅              |
| Install time                | ~5 min         | 30+ min         | 30+ min         | 20+ min         |
| Code size                   | ~3 files       | 2,000+ files    | 1,000+ files    | 500+ files      |

The difference from everything else: **no sync problems.** What you see in the PHP file via FTP is exactly what the client last saved. No parallel database, no JSON files alongside, no "which version is current?"

### What pesi refuses to be

pesi's value is what it leaves out. **It is not a small WordPress**, and it will
not grow into one. Four rules hold, and features that break them are rejected:

- **No database, no external store.** Content lives in the page's PHP source.
- **You design the markup; the client only edits content.** pesi never generates
  layout and never lets a client create pages, menus or structure through a UI.
- **The safety net is not optional.** Every write is preceded by a rotating
  backup and followed by `php -l`, with automatic rollback on a syntax error.
- **Self-contained.** No CDN, no Composer, no build step. Quill is vendored.

If something needs its own management UI, its own URLs, or has to scale to
hundreds of entries, it does not belong in pesi — that is what a real CMS is for.
pesi handles small, page-bound content, and stops there.

---

## What's in this repository

Only the files you actually need:

```
your-webroot/
├── pesi-core.php   → Config + inline helper (included by every editable page)
├── pesi.php        → Dashboard (served at /pesi and /cms)
└── [pages].php     → Your existing pages
```

Plus two small additions to your existing `.htaccess` and `robots.txt` — which you almost certainly already have. The instructions below show exactly which lines to add.

---

## Installation

### Step 1 — Upload the files

Upload `pesi-core.php` and `pesi.php` via FTP into your web root (the same folder that contains `index.php`):

```
your-webroot/
├── pesi-core.php
├── pesi.php
├── index.php
├── impressum.php
└── ...
```

The web server (PHP user) needs write permissions on every PHP file you want to make editable. On virtually every shared host (World4You, Hetzner, Hosteurope, All-Inkl, SiteGround, …), this is the case by default because the FTP user and the PHP user are the same.

### Step 2 — Configure pesi-core.php

Open `pesi-core.php` and update the relevant `define()` calls:

```php
// Password
define('PESI_PASSWORD', 'your-secure-password');

// Branding
define('BRAND_NAME',  'Dr. Müller Practice'); // shown in dashboard header and login screen
define('BRAND_COLOR', '#c47a2a');             // any hex color — try to match the site's primary color
define('BRAND_LOGO',  '');                    // path to your logo, e.g. '/assets/logo.svg' — leave empty for the pesi logo

// Language
define('LANG', 'de'); // 'de' = German, 'en' = English
```

**Recommended — store a password hash instead of plaintext.** pesi detects a
hash automatically (any value starting with `$` is verified via
`password_verify()`), so the password never sits readable in the file. Generate
one on your machine:

```bash
php -r "echo password_hash('your-secure-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Paste the result verbatim — the `$` characters are part of the hash and must be
kept:

```php
define('PESI_PASSWORD', '$2y$12$K1p2....rest-of-the-hash');
```

Plaintext still works (handy for a quick test), but the hash is one command away
and survives a leaked backup file.

Then register the pages you want to appear in the dashboard sidebar:

```php
$PESI_PAGES = [
    'index.php'       => 'Startseite',
    'impressum.php'   => 'Impressum',
    'datenschutz.php' => 'Datenschutz',
    'kontakt.php'     => 'Kontakt',
];
```

Only pages listed here appear in the dashboard. The values on the right are the names shown to the client — keep them plain and human-friendly.

### Step 3 — Add a few lines to your existing `.htaccess`

Your project root almost certainly already has a `.htaccess`. Open it and append this block at the end:

```apache
# pesi CMS
RewriteEngine On
RewriteRule ^pesi$  pesi.php [L]
RewriteRule ^cms$   pesi.php [L]
<Files "pesi-core.php">
    Require all denied
</Files>
<FilesMatch "\.pesi-">
    Require all denied
</FilesMatch>
```

What each line does:

| Line                                              | Purpose                                                              |
|---------------------------------------------------|----------------------------------------------------------------------|
| `RewriteEngine On`                                | Enables rewrite rules (can be omitted if already enabled above)      |
| `RewriteRule ^pesi$ pesi.php [L]`                 | Maps `/pesi` → `pesi.php` (the dashboard URL)                        |
| `RewriteRule ^cms$ pesi.php [L]`                  | Second, optional URL `/cms`                                          |
| `<Files "pesi-core.php">…`                        | Blocks web access to your password                                   |
| `<FilesMatch "\.pesi-">…`                         | Blocks pesi's internal files (`.pesi-backup.1/.2`, `.pesi-throttle`) |

> **⚠ Using an older copy of these instructions?** Earlier versions anchored the
> pattern as `^\.pesi-`. `<FilesMatch>` matches the *file name*, and a backup is
> called `index.php.pesi-backup.1` — it does not start with `.pesi-`, so the
> anchored pattern blocked only `.pesi-throttle` and left every backup publicly
> downloadable. If your `.htaccess` still carries `^\.pesi-`, drop the `^`.

> **Older Apache (2.2)?** Replace `Require all denied` with:
> ```apache
> Order Allow,Deny
> Deny from all
> ```

> **No `.htaccess` at all?** Create one in your web root with exactly the block above.

> **⚠ Nginx / no `.htaccess` support?** Nginx **ignores `.htaccess` entirely**, so the
> rules above do nothing there. The backup files (`page.php.pesi-backup.1`) would then be
> served as **plain text — leaking the full source of that page**. On Nginx
> add this to your `server {}` block instead:
> ```nginx
> location ~ \.pesi-  { deny all; }
> location = /pesi-core.php { deny all; }
> ```
> The pattern must not be anchored to a leading `/` either — `/index.php.pesi-backup.1`
> contains no `/.pesi-` sequence.

**Important — a known pitfall:**

Many website templates ship with a rule that strips `.php` from URLs. If your `.htaccess` contains this block:

```apache
RewriteCond %{THE_REQUEST} \s/+(.+?)\.php[\s?] [NC]
RewriteRule ^ /%1 [R=301,L,NE]
```

…then replace `(.+?)` with `([^?]+)`:

```apache
RewriteCond %{THE_REQUEST} \s/+([^?]+)\.php[\s?] [NC]
RewriteRule ^ /%1 [R=301,L,NE]
```

Why: the original rule also matches inside query strings, which breaks pesi dashboard links like `?page=index.php`. With `[^?]+`, the rule only applies to the path portion, and the dashboard works as expected.

### Step 4 — Add two lines to your existing `robots.txt`

Open the existing `robots.txt` in your web root and append:

```
Disallow: /pesi
Disallow: /cms
```

> **No `robots.txt` at all?** Create one in your web root:
> ```
> User-agent: *
> Disallow: /pesi
> Disallow: /cms
> ```

### Step 5 — Add `require_once` at the top of each page

In every PHP file that should have editable fields, add this as the very first line:

```php
<?php require_once 'pesi-core.php'; ?>
```

If your file starts with HTML, it looks like this:

```php
<?php require_once 'pesi-core.php'; ?>
<!DOCTYPE html>
<html lang="de">
...
```

If your file already starts with `<?php`, just add `require_once` as the first line inside that block:

```php
<?php
require_once 'pesi-core.php';
// ... your existing code
```

### Step 6 — Replace static text with `pesi()` fields

Anywhere you want the client to be able to edit text, replace the hard-coded text with a `pesi()` call. The syntax is always the same:

```php
<?= pesi('field_id', 'Default text', 'type', 'Dashboard label') ?>
```

**Example — a heading and a paragraph:**

Before:
```html
<h1>Psychotherapy Practice</h1>
<p>Welcome to my practice in Feldkirch.</p>
```

After:
```php
<h1><?= pesi('hero_title', 'Psychotherapy Practice', 'text', 'Main heading') ?></h1>
<p><?= pesi('hero_text', 'Welcome to my practice in Feldkirch.', 'text', 'Welcome text') ?></p>
```

For the full field reference, see [Field syntax](#field-syntax) below.

### Step 7 — Test

- Open your website in the browser → everything looks exactly as before.
- Visit `yourdomain.com/pesi` → enter your password → pick a page from the sidebar → edit a field → save.
- Open the PHP file via FTP → the new text sits **directly in the code** as the new default value.

### Done.

You can now hand over the dashboard URL and password to your client. All the explanation they need is: *"Pick a page on the left, edit text, hit Save."*

### Step 8 — Make it yours (optional)

Open `pesi-core.php` and adapt the dashboard to match your site:

```php
define('BRAND_COLOR', '#c47a2a'); // any hex color
define('BRAND_LOGO',  '');        // path or URL to your logo
define('BRAND_NAME',  'My Project');
```

**Adding your logo:**

```php
// Option A — file on your server (recommended)
define('BRAND_LOGO', '/assets/logo.svg');

// Option B — full URL
define('BRAND_LOGO', 'https://yourdomain.com/assets/logo.png');
```

Supported formats: SVG, PNG, JPG, WebP. The logo appears in the login screen and sidebar header. Leave empty to show the pesi logo instead.

---

## Language

pesi CMS ships in German and English. Set your language in `pesi-core.php`:

```php
define('LANG', 'de'); // 'de' = German, 'en' = English
```

**Adding your own language** takes about 5 minutes — open `pesi.php`, find the `$strings` array, copy the `'en'` block, give it a new key (e.g. `'fr'`), translate the strings, and set `LANG` to `'fr'` in your config. All dashboard labels and messages will follow.

---

## Field syntax

### Signature

```php
<?= pesi(string $id, string $default, string $type, string $label) ?>
```

### Parameters

| # | Parameter  | Description                                                                                             |
|---|------------|---------------------------------------------------------------------------------------------------------|
| 1 | `$id`      | Unique field ID per file. snake_case, `[a-z0-9_]` only                                                  |
| 2 | `$default` | The content. Until the client saves something, this text is shown. Once saved, the client's text replaces the default directly in the source file. |
| 3 | `$type`    | Field type: `text`, `textarea`, or `richtext`                                                           |
| 4 | `$label`   | Display name in the dashboard. **Keep it human-friendly** — this is what the client sees.               |

### Field types

**`text`** — Single-line input. For headings, names, phone numbers, button labels, short strings.

```php
<h1><?= pesi('practice_name', 'Dr. Müller Practice', 'text', 'Practice name') ?></h1>
<p><?= pesi('phone', '+43 5522 12345', 'text', 'Phone number') ?></p>
<a class="btn"><?= pesi('hero_button', 'Book appointment', 'text', 'Button label') ?></a>
```

**`textarea`** — Multi-line plain text. For paragraphs without formatting, disclaimers, simple descriptions.

```php
<p><?= pesi('disclaimer', 'The content of this website has been prepared with care.', 'textarea', 'Liability notice') ?></p>
```

**`richtext`** — WYSIWYG editor (Quill, bundled inline — no CDN). For formatted blocks with bold, lists, links, multiple paragraphs. Output is automatically wrapped in `<div class="pesi-richtext">` with sensible default styles for lists and links.

Simple richtext on a single line:

```php
<div><?= pesi('intro', '<p>Welcome to our practice.</p>', 'richtext', 'Intro text') ?></div>
```

Richtext with multiple paragraphs — **use heredoc syntax:**

```php
<?= pesi('about', <<<'PESI'
<p>I am <strong>Dr. Maria Müller</strong>, clinical psychologist.</p>
<p>Running my own practice in Feldkirch since 2015.</p>
PESI, 'richtext', 'About-me text') ?>
```

### Heredoc — the one rule you need to know

The closing `PESI` marker must sit **at the start of the line**, with zero indentation. It will visually break your HTML indentation. That looks wrong but is required by PHP itself.

```php
<!-- WRONG — indented closing tag causes a PHP parse error -->
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

### Important rules

- **Every ID must be unique per file.** Two fields with the same ID in the same file — only the first one will be editable.
- **Same text in two places** (e.g. company name in header and footer)? Use two different IDs: `company_header` and `company_footer`.
- **The default text IS the content.** There is no separate database. When the client saves, the default is overwritten directly in the PHP file.
- **Keep labels human.** Not `hero_cta_text`, but *"Main button label"*.
- **Never rename IDs once the client has edited content.** A renamed ID makes the previously saved content invisible to the dashboard.

---

## What should I replace with `pesi()`?

**Replace (things clients typically want to change):**
- Company/practice name, contact person, tagline
- Address, phone, email
- Opening hours
- All headings (`<h1>`, `<h2>`, `<h3>`)
- Body text, descriptions, intros
- Legal texts (imprint, privacy policy, disclaimer)
- Team/person descriptions
- Services, offerings
- Prices and price descriptions
- Quotes, testimonials
- Button labels (the text, not the link)
- Footer text

**Do not replace (structure, not content):**
- Navigation / menu structure
- HTML attributes (`class`, `id`, `href`, `src`, `alt`, `style`)
- PHP logic, loops, conditions, variables
- CSS and JavaScript
- `<meta>` tags
- Image paths (pesi has no image upload — images stay an FTP job)
- Dynamic values like `date('Y')`
- Structural HTML tags themselves (`<nav>`, `<header>`, `<footer>`)
- Existing `require` / `include` statements

### Special case — pure text pages (Imprint, Privacy Policy)

Pages that consist entirely of running text shouldn't be wrapped paragraph by paragraph. Use **one single `richtext` field** for the whole body under the `<h1>`:

```php
<?php require_once 'pesi-core.php'; ?>
<h1>Legal Notice</h1>
<?= pesi('imprint_body', <<<'PESI'
<h2>Information under § 5 ECG</h2>
<p>Musterstraße 1, 6800 Feldkirch</p>
<p>Phone: +43 5522 12345</p>
...
PESI, 'richtext', 'Legal notice content') ?>
```

The client then edits the whole body in a single Quill editor, rather than dealing with dozens of individual fields.

---

## The dashboard belongs to your client, not to you

pesi has two audiences and one interface. Your client — often a therapist, a
practice, an artist, rarely technical — uses it every few weeks. You use it
twice, during setup. The interface is designed for the first group, and the
second group gets what it needs one click away:

- **Messages name the consequence, never the mechanism.** Not "PHP error
  detected — rolled back", but "Your page is unchanged — nothing is broken".
- **Anything the client can fix herself** (image too large, wrong format) says
  exactly that, with no error code — she does not need anyone.
- **Anything she cannot fix** carries a short code and asks her to pass it on.
  She stays in control, reads you four characters, and never sees a stack of
  jargon. Codes are listed below.
- **Setup problems are not her problem.** A default password or a missing
  `php -l` shows up as one calm collapsed line ("nothing for you to do"), with
  the technical detail inside for whoever reads it.
- **Field IDs and types are hidden** behind the *Technical view* toggle in the
  top bar. It remembers its state per browser, so switch it on once and it
  stays on for you.

### Error codes

`S…` means the page structure needs attention, `T…` means the server or the
install does. Both are your job, not the client's.

| Code | What happened | What to do |
|------|---------------|------------|
| `S1` | A save produced a page PHP refused to parse; pesi rolled it back | Check the field content that was being saved; the page itself is intact |
| `S2` | A field value contained a pesi structure marker (`pesi:item`, `pesi:toggle`, `if (false):`) and was rejected before writing | Remove that text from the field, or escape it in the template |
| `S3` | A repeatable entry referenced by the dashboard was not found in the source | Usually a stale browser tab — reload. If it persists, the `pesi:item` markers were edited by hand |
| `S4` | A visibility section was not found in the source | Same as `S3`, for `pesi:toggle` markers |
| `S5` | Two `pesi:toggle` sections are nested; switching is disabled for the page | Unnest them — a toggle must not contain another toggle |
| `T1` | The page file could not be read | Check file permissions and that the file still exists |
| `T2` | The rotated backup could not be written | The web server needs write access to the project directory |
| `T3` | The page file was locked by another request | Transient; if permanent, a stale lock or a hung process |
| `T4` | A page listed in `$PESI_PAGES` does not exist | Fix the path in `pesi-core.php` or restore the file |
| `T5` | The upload folder is not writable | `chmod` the folder named in the message |
| `T6` | `PESI_UPLOAD_DIR` is invalid (empty, absolute, or contains `..`) | Set a plain relative folder name |
| `T7` | `php -l` cannot run, so broken saves are not rolled back automatically | Enable `exec()` or make the PHP CLI reachable; otherwise set `PESI_SYNTAX_CHECK` to false knowingly |
| `T8` | The shipped default password is still active | Set a real `PESI_PASSWORD`, ideally a `password_hash()` value |

### Rewording the interface

Every string the client reads lives in `_pesi_strings()` in `pesi.php`, in
German and English. To adjust tone, form of address or industry vocabulary,
**do not fork `pesi.php`** — override individual strings from `pesi-core.php`:

```php
$PESI_STRINGS = [
    'welcome_hint' => 'Wähl links eine Seite aus, dann kannst du loslegen.',
    'save_btn'     => 'Übernehmen',
    'blk_entry'    => 'Behandlung',
];
```

Shipped German uses formal address (*Sie*), which suits practices and firms;
the example above switches it to informal. Unknown keys are ignored, so a typo
cannot silently blank out a label. Updating pesi stays a file swap.

## Security

- `pesi-core.php` is blocked from web access via `.htaccess` — no one can read the password from the browser
- `.pesi-*` files (rotated backups and the login-throttle register) are blocked via `.htaccess`. **On Nginx you must add the equivalent `deny` rules yourself** — it never reads `.htaccess`, and a backup file is a byte-for-byte copy of that page's source. (The password itself lives in `pesi-core.php`, which pages only `require`, so a page backup does not contain it — but the source disclosure alone is reason enough to block these files.)
- The dashboard is password-protected (session-based); `PESI_PASSWORD` accepts a `password_hash()` value, which is what you should use
- Failed logins are slowed down twice: per session, and per client in a small `.pesi-throttle` file so that discarding cookies does not reset the delay
- Sessions use secure cookie flags (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS)
- Session ID is regenerated on login to prevent session fixation
- CSRF tokens protect every form submission
- `text`, `textarea`, and `image` output is HTML-escaped; image URLs reject script/data-style schemes
- `richtext` output is sanitized through a small server-side HTML allowlist before it is saved or rendered
- Image uploads are checked by real MIME type, size, and extension; upload directories must stay inside the project root
- Before every save, a rotated backup is created (`page.php.pesi-backup.1` = last save, `.2` = previous)
- After saving, `php -l` runs automatically — on a syntax error, pesi rolls back from `.pesi-backup.1`
- File locking plus a file mtime/hash check prevents stale overwrites

**Important:** always run pesi over HTTPS. The login password is sent via POST — over plain HTTP it would be readable in transit. Most hosts offer free SSL certificates; make sure yours is active.

**What pesi stores about visitors:** nothing. The only exception is the login
throttle, which keeps a **SHA-256 hash** of the client IP (never the address
itself) plus a counter in `.pesi-throttle`, and drops entries an hour after they
expire. Nothing is written for ordinary page visitors, and there is no logging,
tracking or analytics anywhere in pesi.

Found a vulnerability? Please report it privately — see [SECURITY.md](SECURITY.md).
Do not open a public issue.

---

## Configuration reference

All settings in `pesi-core.php`:

```php
define('PESI_PASSWORD',     'your-password');  // Dashboard password
define('BRAND_NAME',        'My Project');     // Shown in dashboard header and browser tab
define('BRAND_COLOR',       '#c47a2a');        // Any CSS hex color — dashboard accent
define('BRAND_LOGO',        '');               // Path to logo image — empty = pesi logo
define('LANG',              'de');             // 'de' or 'en'
define('PESI_BACKUP_ENABLED', true);           // Backup before every save
define('PESI_SYNTAX_CHECK',   true);           // PHP lint check after save

$PESI_PAGES = [
    'index.php'  => 'Startseite',              // file => sidebar label
];
```

---

## FTP workflow

The nice thing about pesi: you can keep editing via FTP in parallel without anything going out of sync:

- **Add a new field:** insert a `pesi()` call where you want it — shows up in the dashboard immediately.
- **Remove a field:** delete the `pesi()` call — disappears from the dashboard.
- **Change the default text:** just edit the second parameter.
- **Change a label:** edit the fourth parameter.
- **Add a new page:** create the PHP file, add `require_once 'pesi-core.php'` at the top, set your fields, and register it in `$PESI_PAGES`.
- **Remove a page:** delete its entry from `$PESI_PAGES`.

Whatever the client last saved in the dashboard is **always** sitting there as the default value in the source. There's no "FTP version" vs. "dashboard version" — they're the same file.

---

## For the client

What you need to hand over:

- URL: `www.their-domain.com/pesi`
- Password
- One-sentence briefing: *"Pick a page on the left, edit text, hit Save."*

Per field, the client sees:
1. A **label** ("Company name", "Liability notice", …) — big and clear
2. The **field type** as a small badge — informational
3. The matching input — text, textarea, or Quill richtext editor

The client **can't break anything**: no access to code, no HTML in `text`/`textarea` fields, and even in `richtext`, broken HTML only affects that single field's rendering.

---

## Troubleshooting

### `/pesi` returns 404

`mod_rewrite` is probably disabled, or `.htaccess` files aren't being read. Check with your host that `AllowOverride All` is active. As a workaround, call `/pesi.php` directly — if that works, you know the issue is with rewriting.

### "Permission denied" when saving

The web server doesn't have write permissions on the PHP file. Set permissions via FTP to `0644` (file) and `0755` (folder). On some hosts the file owner needs to be switched as well — ask their support.

### Richtext editor shows only an empty box, no toolbar

Quill is bundled directly inside `pesi.php` — no CDN requests are made. The most likely cause is a strict **Content-Security-Policy** that disallows inline scripts (`script-src` without `'unsafe-inline'`). Look in your `.htaccess` or for a `<meta http-equiv="Content-Security-Policy">` tag. If a restrictive `script-src` is found, add `'unsafe-inline'` or exclude the `/pesi` path from the policy.

### Dashboard links go nowhere, `?page=index.php` doesn't work

Your `.htaccess` has an over-aggressive PHP-stripping rule. See Step 3 — replace `(.+?)` with `([^?]+)`.

### Saved text is gone, page shows the old default again

Check if a `.pesi-backup.1` file exists next to the PHP file. After every save, pesi runs `php -l` — on a syntax error in the saved content, it rolls back automatically from `.pesi-backup.1`. This typically happens with broken HTML in a `richtext` field. The previous backup is kept as `.pesi-backup.2`.

### A field doesn't show up in the dashboard

- Check that `require_once 'pesi-core.php'` is at the top of the file
- Check that the file is listed in `$PESI_PAGES`
- Check that the ID isn't already used in the same file (duplicate IDs are invisible from the second occurrence onward)

### Lists or links in richtext look unstyled on the frontend

Your site's CSS reset removes default list and link styles. pesi automatically wraps richtext output in `<div class="pesi-richtext">` and injects a small `<style>` block with the correct styles. If it still looks wrong, check that your CSS doesn't override `.pesi-richtext` rules.

---

## Honest limitations

- **No media library** — image upload only swaps page-bound `image` fields; decorative assets stay an FTP job
- **Replaced images are deleted**, and pesi only looks at the pages listed in `$PESI_PAGES` to decide whether a file is still in use. It checks both the `pesi()` field values and the raw markup of those pages, but it cannot see includes, partials or templates that are not registered pages. If you reference an uploaded file from an unregistered file, register that file too or keep the asset outside `PESI_UPLOAD_DIR`
- **Toggles must not be nested** — a `pesi:toggle` inside another one is rejected; the dashboard disables switching and says so
- **No multi-user system** — one password for everyone
- **No version history** beyond the last two backups (`.pesi-backup.1` / `.2`)
- **Don't rename field IDs after go-live** — doing so orphans the client's saved content
- **Not meant for simultaneous heavy editing** — dashboard access is file-lock protected, but editing the same file via FTP and dashboard at the same time should be avoided

---

## Requirements

- PHP 7.4+
- Apache with `.htaccess` support — or, on Nginx, the equivalent `deny` rules from Step 3
- Web server write access to your editable PHP files (standard on all major hosts)
- HTTPS (strongly recommended)

---

## License

pesi is **MIT** — free to use, modify, and self-host. See [LICENSE](LICENSE).

pesi bundles the **Quill editor** (BSD-3-Clause) directly inside `pesi.php`, so
its copyright notice and license must ship with every copy you redistribute.
Those notices are in [THIRD-PARTY.md](THIRD-PARTY.md) — keep the file alongside
`pesi.php` when you fork or repackage.

---

*pesi CMS — write more. worry less.*

Built by **[ludescher.studio](https://ludescher.studio)** for MindSites, VitaSites, and custom client projects.
