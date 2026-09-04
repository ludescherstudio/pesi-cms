<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/pesi_dark_logo.svg">
    <img src="assets/pesi_light_logo.svg" height="52" alt="pesi CMS">
  </picture>
</p>

> *Pesi* (Swahili) — lightweight, effortless.
> **pesi CMS** is exactly that: a featherweight way to let clients edit their own website. No database. No admin sprawl. The PHP file stays the single source of truth.

**An inline CMS for PHP websites — three PHP files, one password, done.**

No MySQL. No Node.js. No Docker. No build step. Edits are written straight back into the page's PHP source, so what you see via FTP is exactly what the client last saved.

Built for developers who maintain small client websites — practices, studios, artists — and want the client to change texts and photos without a WordPress stack. Works on shared hosting with PHP 8.2+.

---

## Why pesi CMS?

| | pesi CMS | WordPress | Statamic | Kirby |
|---|---|---|---|---|
| No database | ✅ | ❌ MySQL | ✅ | ✅ |
| No dependencies | ✅ | ❌ | ❌ Composer | ❌ Composer |
| Edits written to source | ✅ | ❌ | ❌ | ❌ |
| Shared hosting | ✅ | ✅ | ⚠️ | ✅ |
| Install time | ~5 min | 30+ min | 30+ min | 20+ min |
| Code size | 3 PHP files | 2,000+ files | 1,000+ files | 500+ files |

### What pesi refuses to be

pesi's value is what it leaves out. **It is not a small WordPress**, and it will not grow into one. Four rules hold, and features that break them are rejected:

- **No database, no external store.** Content lives in the page's PHP source.
- **You design the markup; the client only edits content.** pesi never generates layout and never lets a client create pages, menus or structure through a UI.
- **The safety net is not optional.** Every write is completed and checked with `php -l` in a temporary file before a rotating backup is made and the live page is atomically replaced.
- **Self-contained.** No CDN, no Composer, no build step. The Quill editor is vendored inline.

If something needs its own management UI, its own URLs, or has to scale to hundreds of entries, it does not belong in pesi — that is what a real CMS is for. pesi handles small, page-bound content, and stops there.

---

## What's in this repository

Only the three files you actually need:

```
pesi.php             ← Dashboard (served at /pesi and /cms)
pesi-core.php        ← Configuration (password, branding, page list) + the pesi() helper
pesi-content.php     ← Shared details (address, phone, email, booking link), editable as "Stammdaten"
```

No `.htaccess` in the project root, no `robots.txt`. You almost certainly already have both — the instructions below show exactly which lines to add to your existing files.

The repository also includes [`pesi-agent.md`](pesi-agent.md) — an optional integration guide for AI coding agents (see [Installation](#installation)).

> **Downloaded the whole repository?** Only `pesi.php`, `pesi-core.php` and `pesi-content.php` are served from your web root. `README.md`, `SECURITY.md`, `pesi-agent.md`, `.gitignore` and the `assets/` folder are just for GitHub. Keep `LICENSE` and `THIRD-PARTY.md` with every copy you hand over — for example in the delivery package outside the public web root — because the bundled Quill editor requires its notice to travel with the code. The dashboard logo is embedded in `pesi.php`, so `assets/` is not needed at runtime.

---

## Installation

> **Using an AI coding agent?** If you work with an AI agent that can edit your project (Claude Code, Cursor, Copilot, …), you don't have to follow the steps manually. Copy the three pesi files into your project, then prompt:
>
> > Read `pesi-agent.md` and integrate pesi into this site.
>
> The agent configures `pesi-core.php`, wraps the editable texts and images in `pesi()` fields, marks repeatable lists and seasonal sections, and updates your `.htaccess` and `robots.txt` — following the included [`pesi-agent.md`](pesi-agent.md) guide. Prefer to do it yourself? Just follow the manual steps below.

### Step 1 — Upload the files

Upload `pesi.php`, `pesi-core.php` and `pesi-content.php` via FTP into your web root — the folder that contains `index.php`:

```
your-webroot/
├── pesi.php
├── pesi-core.php
├── pesi-content.php
├── index.php
├── impressum.php
└── ...
```

PHP needs write permission on every page you make editable, on the web root itself (backups and locks are created next to each page) and on the upload folder. On virtually every shared host (World4You, Hetzner, Hosteurope, All-Inkl, SiteGround, …) this is already the case because the FTP user and the PHP user are the same.

### Step 2 — Set your password, branding and page list

Open `pesi-core.php` and edit these lines:

```php
// Password
define('PESI_PASSWORD', 'your-secure-password');

// Branding
define('BRAND_NAME',  'Dr. Müller Practice'); // shown in the sidebar and on the login screen
define('BRAND_COLOR', '#a3611b');             // any hex color — white text sits on it, so keep 4.5:1 contrast
define('BRAND_LOGO',  '');                    // e.g. '/assets/logo.svg' — leave empty for the pesi logo

// Language
define('LANG', 'de'); // 'de' = German, 'en' = English
```

**Store a password hash, not the plaintext.** Any value starting with `$` is verified with `password_verify()`, so the password never sits readable in the file. Generate one on your machine and paste the result verbatim — the `$` characters are part of the hash:

```bash
php -r "echo password_hash('your-secure-password', PASSWORD_DEFAULT), PHP_EOL;"
```

```php
define('PESI_PASSWORD', '$2y$12$K1p2....rest-of-the-hash');
```

Plaintext still works for a quick test. The shipped value `demo1234` fails closed: nobody can sign in until it is changed.

Then register the pages that should appear in the dashboard sidebar:

```php
$PESI_PAGES = [
    PESI_GLOBALS_FILE => 'Stammdaten',   // keep this line — the shared details
    'index.php'       => 'Startseite',
    'impressum.php'   => 'Impressum',
    'datenschutz.php' => 'Datenschutz',
    'kontakt.php'     => 'Kontakt',
];
```

Only pages listed here appear in the dashboard. The values on the right are the names the client sees — keep them plain. Keep the `PESI_GLOBALS_FILE` entry: without it the shared details in `pesi-content.php` can no longer be edited.

### Step 3 — Add the pesi rules to your existing `.htaccess`

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

| Line | Purpose | Safe to skip if… |
|---|---|---|
| `RewriteEngine On` | Enables the rewrite rules below | …it already appears earlier in your file |
| `RewriteRule ^pesi$ pesi.php [L]` | Maps `/pesi` → `pesi.php` | Never — required for the dashboard URL |
| `RewriteRule ^cms$ pesi.php [L]` | Maps `/cms` → `pesi.php` | …you don't want the alternate URL |
| `<Files "pesi-core.php">…</Files>` | Blocks web access to your password | Only if the same file is already denied elsewhere |
| `<FilesMatch "\.pesi-">…</FilesMatch>` | Blocks pesi's internal files: rotating backups, locks, temporary candidates and the login throttle | Never — backups are full copies of your page source |

> **Keep the pattern unanchored.** `<FilesMatch>` matches the *file name*, and a backup is called `index.php.pesi-backup.1` — it does not start with `.pesi-`. A pattern of `^\.pesi-` blocks only the throttle file and leaves every backup publicly downloadable. If your `.htaccess` still carries `^\.pesi-`, drop the `^`.

> **Older Apache (2.2)?** Replace `Require all denied` with:
> ```apache
> Order Allow,Deny
> Deny from all
> ```

> **No `.htaccess` at all?** Create one in your web root with exactly the block above.

> **Nginx?** `.htaccess` is ignored, and the backup files would be served as plain-text source. Add equivalent server rules and test them:
> ```nginx
> location ~ \.pesi-         { deny all; }
> location = /pesi-core.php  { deny all; }
> location = /pesi           { try_files $uri /pesi.php?$query_string; }
> location = /cms            { try_files $uri /pesi.php?$query_string; }
> ```
> Same trap here: `location ~ /\.pesi-` does not match `/index.php.pesi-backup.1`, because that path contains no `/.pesi-` sequence.

**Also create `uploads/.htaccess`** (or the folder you set as `PESI_UPLOAD_DIR`) so that nothing in the upload folder can ever run as code:

```apache
php_flag engine off
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps
RemoveType .php .phtml .php3 .php4 .php5 .php7 .phps
<FilesMatch "\.(php|phtml|php[3-7]|phps)$">
    Require all denied
</FilesMatch>
```

Do **not** deny access to the upload folder itself — uploaded photos must stay publicly reachable, otherwise images break on the live site.

> **A known pitfall.** Many templates ship with a rule that strips `.php` from URLs:
> ```apache
> RewriteCond %{THE_REQUEST} \s/+(.+?)\.php[\s?] [NC]
> RewriteRule ^ /%1 [R=301,L,NE]
> ```
> It also matches inside query strings and breaks dashboard links like `?page=index.php`. Replace `(.+?)` with `([^?]+)` — the rule then stops at the path and the dashboard works. Keep `%{THE_REQUEST}` as it is; switching to `%{REQUEST_URI}` causes a redirect loop.

### Step 4 — Add two lines to your existing `robots.txt`

Open the existing `robots.txt` in your web root and — inside the `User-agent: *` section — append:

```
Disallow: /pesi
Disallow: /cms
```

> **No `robots.txt` at all?** Create one in your web root with:
> ```
> User-agent: *
> Disallow: /pesi
> Disallow: /cms
> ```

`robots.txt` only asks compliant crawlers not to index the dashboard. It is not access control; the password and the rules in Step 3 provide the actual protection.

### Step 5 — Add `require_once` at the top of each page

In every PHP file that should have editable fields, add this as the very first line:

```php
<?php require_once 'pesi-core.php'; ?>
```

If your file starts with HTML:

```php
<?php require_once 'pesi-core.php'; ?>
<!DOCTYPE html>
<html lang="de">
...
```

If your file already starts with `<?php`, add the `require_once` as the first line inside that block.

### Step 6 — Replace static text with `pesi()` fields

Anywhere the client should be able to edit text, replace the hard-coded text with a `pesi()` call. The syntax is always the same:

```php
<?= pesi('field_id', 'Default text', 'type', 'Dashboard label') ?>
```

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

Repeatable lists and seasonal sections need one HTML comment each — see [Field reference](#field-reference).

### Step 7 — Test

- Open your website in the browser → everything looks exactly as before.
- Visit `yourdomain.com/pesi` → enter your password → pick a page from the sidebar → edit a field → save.
- Open the PHP file via FTP → the new text sits **directly in the code** as the new default value.

### Done.

Hand over the dashboard URL and the password. All the explanation the client needs: *"Pick a page on the left, edit text, hit Save."*

### Step 8 — Make it yours (optional)

Open `pesi-core.php` and adapt the dashboard to match your site:

```php
define('BRAND_COLOR', '#a3611b'); // any hex color — white text on it must reach 4.5:1
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

Supported formats: SVG, PNG, JPG, WebP. The logo appears on the login screen and in the sidebar. Leave empty to show the pesi logo instead. If white text on your brand colour falls below 4.5:1 contrast, the dashboard reports it as code `T12` — pick a darker shade.

---

## Language

pesi CMS ships in German and English. Set your language in `pesi-core.php`:

```php
define('LANG', 'de'); // 'de' = German, 'en' = English
```

**Adding your own language** takes about 5 minutes — open `pesi.php`, find the `_pesi_strings()` function, copy the `'en'` block, give it a new key (e.g. `'fr'`), translate the strings, and set `LANG` to `'fr'` in your config. All dashboard labels and messages will follow.

**Rewording single strings** — tone, form of address, industry vocabulary — does not need a fork. Override individual keys from `pesi-core.php`; unknown keys are ignored, so a typo cannot blank out a label:

```php
$PESI_STRINGS = [
    'welcome_hint' => 'Wähl links eine Seite aus, dann kannst du loslegen.',
    'save_btn'     => 'Übernehmen',
    'blk_entry'    => 'Behandlung',
];
```

Shipped German uses formal address (*Sie*), which suits practices and firms; the example above switches it to informal.

---

## Field reference

### Signature

```php
<?= pesi(string $id, string $default, string $type, string $label) ?>
```

| # | Parameter | Description |
|---|---|---|
| 1 | `$id` | Unique field ID per file. snake_case, `[a-z0-9_]` only |
| 2 | `$default` | The content. Until the client saves something, this text is shown. Once saved, the client's text replaces it directly in the source file |
| 3 | `$type` | `text`, `textarea`, `richtext`, `image`, `url`, `email` or `tel` |
| 4 | `$label` | Display name in the dashboard. Keep it human — this is what the client sees. No `'` or `"` inside |

**Single quotes around the value — never double.** `pesi("id", "Text", …)` renders normally but is invisible to the dashboard; the diagnostics panel reports it as code `T13`. Escape apostrophes as `\'`.

### Field types

**`text`** — Single-line input. Headings, names, button labels, short strings.

```php
<h1><?= pesi('practice_name', 'Dr. Müller Practice', 'text', 'Practice name') ?></h1>
```

**`textarea`** — Multi-line plain text without formatting. Line breaks are stored, but HTML collapses them: output the value with `nl2br()` or style the element with `white-space: pre-line` if the breaks matter.

```php
<p><?= nl2br(pesi('disclaimer', 'The content of this website has been prepared with care.', 'textarea', 'Liability notice')) ?></p>
```

**`url`, `email`, `tel`** — Validated link and contact values for `href`. `url` accepts `http(s)://`, root-relative paths such as `/kontakt`, query links and `#anchors`; other schemes and protocol-relative URLs are rejected. An invalid value rejects the complete save, so another field is never published while a contact value failed validation. Never put a plain `text` field into `href` — HTML escaping alone does not make a URL safe.

```php
<a href="<?= pesi('booking_url', '/kontakt', 'url', 'Booking link') ?>">Book appointment</a>
<a href="mailto:<?= pesi('email', 'praxis@example.com', 'email', 'Email') ?>">Email us</a>
<a href="tel:<?= pesi('phone', '+43 123 456789', 'tel', 'Phone') ?>">Call us</a>
```

**`image`** — Page-bound image upload with real MIME and size validation. The path is stored in the PHP source and is safe to use in `src`. Wrap only the path, never the whole `<img>` tag.

```php
<img src="<?= pesi('portrait', '/uploads/portrait.jpg', 'image', 'Portrait') ?>" alt="">
```

**`richtext`** — WYSIWYG editor (Quill, bundled inline). Bold, italic, lists, links, headings h2/h3, blockquotes, multiple paragraphs. The output is wrapped in `<div class="pesi-richtext">` with default styles for lists and links. Only these tags survive the sanitizer, on save and on every render:

```
p  br  strong  b  em  i  u  s  a  ul  ol  li  blockquote  h2  h3
```

Everything else is unwrapped, so never put layout — `div`, `span`, `img`, `table`, `h1` — inside a richtext field. Build the layout in static HTML and place `pesi()` fields inside it.

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

The closing `PESI` marker must sit **at the start of the line**, with zero indentation. It will visually break your HTML indentation. That looks wrong but is required by PHP itself. The opening marker must be `<<<'PESI'` **with quotes** — `<<<PESI` is valid PHP but invisible to the dashboard.

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

### Repeatable blocks — `pesi:item`

For any list the client should be able to grow or shrink: team members, services, testimonials, FAQ entries. Wrap **one** entry in two HTML comments; every field ID inside starts with `GROUP_N_`:

```php
<section class="team">
<!-- pesi:item team:1 -->
  <article class="member">
    <img src="<?= pesi('team_1_foto', '/uploads/anna.jpg', 'image', 'Photo') ?>" alt="">
    <h3><?= pesi('team_1_name', 'Anna Muster', 'text', 'Name') ?></h3>
    <p><?= pesi('team_1_rolle', 'Psychologist', 'text', 'Role') ?></p>
  </article>
<!-- /pesi:item -->
</section>
```

The dashboard shows each entry as its own card with ↑ ↓ · Duplicate · Delete, plus one "+ Add entry" button under the group. Adding clones entry `:1` as the template; the last remaining entry cannot be deleted. Instance numbers are stable and never renumbered — after deleting `:2` you legitimately have `team:1` and `team:3`.

### Visibility toggles — `pesi:toggle`

For content that is seasonally on and off: holiday notices, a temporary banner, "currently accepting new clients".

```php
<!-- pesi:toggle urlaub -->
<div class="notice">
  <p><?= pesi('urlaub_text', 'Closed 1–15 August.', 'text', 'Holiday notice') ?></p>
</div>
<!-- /pesi:toggle -->
```

The dashboard shows a "Visibility" panel with a Show/Hide button per group. Hiding wraps the body in `<?php if (false): ?> … <?php endif; ?>`, so the content stays in the file and stays editable — it is only not rendered. Ship the section visible and let the client hide it. A toggle may contain `pesi:item` blocks; a toggle inside another toggle is not supported and disables switching for that page (code `S5`).

Group names are shown with underscores turned into spaces and the first letter capitalised: `team` → "Team", `neue_klienten` → "Neue klienten". Pick single words that read well.

### Shared details — `pesi-content.php`

`pesi-content.php` is an ordinary editable pesi file, registered as **Stammdaten**. Its values load with `pesi-core.php` and can be reused on any page, so address, phone or booking details never drift between header, footer, contact page and imprint:

```php
<h1><?= pesi_global('practice_name') ?></h1>
<address><?= nl2br(pesi_global('address')) ?></address>
<a href="mailto:<?= pesi_global('email') ?>"><?= pesi_global('email') ?></a>
<a href="<?= pesi_global('booking_url') ?>">Book appointment</a>
```

Edit the field list in `pesi-content.php` to match the site, then reuse the same key everywhere.

### What to replace — and what not

**Replace:** practice name, contact person, tagline · address, phone, email · opening hours · headings · body text, descriptions · legal texts · team and service descriptions · prices · quotes · button labels (the text, not the link) · footer text · swappable photos.

**Leave static:** navigation and menu structure · HTML attributes such as `class`, `id`, `alt`, `style` (for deliberately editable `href`/`src` use `url`, `email`, `tel` or `image`) · PHP logic · CSS and JavaScript · `<meta>` tags · decorative images · dynamic values like `date('Y')` · structural tags themselves · existing `require`/`include` statements.

**Pure-text pages** (imprint, privacy policy) get **one** `richtext` field for the whole body under the `<h1>`, not one field per paragraph:

```php
<?php require_once 'pesi-core.php'; ?>
<h1>Legal Notice</h1>
<?= pesi('imprint_body', <<<'PESI'
<h2>Information under § 5 ECG</h2>
<p>Musterstraße 1, 6800 Feldkirch</p>
PESI, 'richtext', 'Legal notice content') ?>
```

**Rules that bite later:**
- Every ID must be unique per file — a duplicate is silently ignored from the second occurrence onward
- The same text in two places gets two IDs: `company_header` and `company_footer`
- Never rename an ID once the client has saved content — the saved value becomes invisible to the dashboard
- The parser reads the raw source, so a `pesi()` call in a commented-out block still shows up — delete dead code instead of commenting it out

---

## Dashboard

The dashboard is built for the client — a therapist, a practice, an artist; rarely technical; opens it every few weeks. You use it twice, during setup.

- **Pages** in the sidebar, one card per field, labels only — no IDs, no types
- **Save** at the bottom, with a live count of unsaved changes and a warning before leaving the page or running a structural action
- **Entries** with ↑ ↓ · Duplicate · Delete and "+ Add entry" for every `pesi:item` group
- **Visibility** panel with Show/Hide for every `pesi:toggle` group; fields of a hidden section say so on their card
- **↩ Last version** restores the state before the last save. The current state is backed up first, so clicking again goes forward
- **Preview** of the saved page next to the form on screens from 1280 px, sandboxed
- **Technical view** toggle in the top bar shows field IDs and type badges; remembered per browser
- **Diagnostics** for whoever looks after the site — default password, missing `php -l`, brand contrast, unparsable fields, upload limits — sit in one collapsed line, never in a red banner

Messages name the consequence, never the mechanism. Anything the client can fix alone (image too large, wrong format) says exactly that. Anything she cannot fix carries a short code and asks her to pass it on:

### Error codes

`S…` means the page structure needs attention, `T…` means the server or the install does. Both are your job, not the client's.

| Code | What happened | What to do |
|---|---|---|
| `S1` | A candidate version did not parse as valid PHP and was rejected before publishing | Check the field content; the live page itself is intact |
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
| `T7` | `php -l` cannot run, so pesi cannot verify PHP syntax before publishing | Enable `exec()` or make the PHP CLI reachable; otherwise set `PESI_SYNTAX_CHECK` to false knowingly |
| `T8` | The shipped default password is still active | Set a real `PESI_PASSWORD`, ideally a `password_hash()` value |
| `T9` | The temporary candidate could not be written completely — almost always a full disk or exhausted quota. The live page was not touched | Free up space or raise the quota, then save again |
| `T12` | `BRAND_COLOR` carries white text below the 4.5:1 WCAG AA needs, which affects the Save button and the dashboard links | Pick a darker shade. The message states the measured ratio |
| `T13` | A page contains `pesi()` calls the parser cannot read, so those fields never appear for the client. Almost always double quotes around the value, or a quote character inside the label | Use single quotes: `pesi('id', 'Text', …)`. Escape apostrophes in the value as `\'`; keep labels free of `'` and `"` |
| `T14` | `PESI_UPLOAD_MAX_BYTES` is higher than the hosting accepts (`upload_max_filesize` / `post_max_size`). Images between the two limits are rejected; the client is shown the smaller, effective limit | Raise the limits in the hosting (php.ini, `.user.ini` or `.htaccess`), or lower `PESI_UPLOAD_MAX_BYTES` to match |

---

## Security

pesi writes into live PHP source and ships an authenticated dashboard. What is protected after completing the installation steps:

- `pesi-core.php` is blocked from web access via `.htaccess` — no one can read the password from the browser
- `.pesi-*` files — rotated backups, write locks, short-lived candidates and the login throttle — are blocked via `.htaccess`. Backups are full copies of page source; on Nginx you add the equivalent `deny` rules yourself
- `PESI_PASSWORD` accepts a `password_hash()` value; the shipped default fails closed
- Failed logins are slowed down twice: per session, and per client in a small `.pesi-throttle` register, so discarding cookies does not reset the delay. The backoff is exponential and capped at five minutes
- Sessions use secure cookie flags (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS), an inactivity timeout and an absolute lifetime. The session ID is regenerated on login; changing `PESI_PASSWORD` revokes existing sessions
- CSRF tokens protect every form submission; the dashboard sends `X-Frame-Options: DENY` and a `frame-ancestors 'none'` policy
- `text`, `textarea`, `url`, `email`, `tel` and `image` output is HTML-escaped; the typed fields additionally validate their value before the complete save
- `richtext` is sanitized through a server-side allowlist before it is saved and on every render; PHP tags and HTML comments inside it are removed
- Field values containing pesi structure markers are rejected before writing (code `S2`)
- Image uploads are checked by real MIME type (`finfo`, falling back to `getimagesize()`), size and extension; SVG is deliberately not allowed; the upload folder must stay inside the project root
- Every write goes to a same-directory temporary file, is flushed and linted with `php -l` there, then atomically replaces the live page; a failed write never truncates the live page
- A stable sidecar lock plus the full file hash from the opened form prevents stale overwrites — including a parallel FTP upload

**Important:** always run pesi over HTTPS. The login password is sent via POST — over plain HTTP it would be readable in transit.

Found a vulnerability? Please report it privately — see [SECURITY.md](SECURITY.md). Do not open a public issue.

---

## What pesi writes to disk

| File | Created | Purpose |
|---|---|---|
| `page.php.pesi-backup.1` | on every successful save | Last saved state — what "↩ Last version" restores |
| `page.php.pesi-backup.2` | on the second save | The state before that; reachable only via FTP |
| `page.php.pesi-lock` | on first save | Stable sidecar lock for the write; stays, empty |
| `page.php.pesi-tmp-*` | during a save | The candidate that is linted and then renamed over the live page; removed on failure |
| `.pesi-throttle`, `.pesi-throttle-lock` | on the first failed login | Login throttle register: SHA-256 of the client IP and a counter, entries dropped an hour after they expire |
| `uploads/…` | on image upload | Uploaded images under a collision-free name; replaced images are deleted once neither the page nor its two backups reference them |

All of these are covered by the `.pesi-` rule in Step 3 except the upload folder, which must stay public.

---

## Configuration reference

All settings in `pesi-core.php`:

```php
define('PESI_PASSWORD',       'your-password');    // Dashboard password or password_hash() value
define('BRAND_NAME',          'My Project');       // Shown in the sidebar and the browser tab
define('BRAND_COLOR',         '#a3611b');          // Any CSS hex color — dashboard accent (4.5:1 against white)
define('BRAND_LOGO',          '');                 // Path to logo image — empty = pesi logo
define('LANG',                'de');               // 'de' or 'en'
define('PESI_BACKUP_ENABLED', true);               // Two rotating recovery copies per page
define('PESI_SYNTAX_CHECK',   true);               // php -l on the candidate before publishing
define('PESI_SESSION_IDLE',   30 * 60);            // Sign out after inactivity
define('PESI_SESSION_MAX',    12 * 60 * 60);       // Absolute session lifetime
define('PESI_GLOBALS_FILE',   'pesi-content.php'); // The shared-details file

define('PESI_UPLOAD_DIR',       'uploads');                       // Relative to the web root, no leading slash, no ..
define('PESI_UPLOAD_MAX_BYTES', 5 * 1024 * 1024);                 // Capped by the host's upload_max_filesize / post_max_size
define('PESI_UPLOAD_TYPES',     'jpg,jpeg,png,webp,avif,gif');    // SVG is excluded on purpose

$PESI_PAGES = [
    PESI_GLOBALS_FILE => 'Stammdaten',
    'index.php'       => 'Startseite',             // file => sidebar label
];

// Optional: override single dashboard strings — see Language
// $PESI_STRINGS = ['save_btn' => 'Übernehmen'];
```

---

## Privacy & GDPR

- pesi stores nothing about website visitors. No cookies, no logging, no analytics on the public site
- The dashboard sets one session cookie for the signed-in editor only
- The login throttle keeps a **SHA-256 hash** of the client IP plus a counter, never the address, and drops entries an hour after they expire
- Replaced images are deleted once the page and both technical backups no longer reference them — no orphaned portraits on the web space
- No external requests at any time: no CDN, no fonts, no update check. Quill is bundled inside `pesi.php`

---

## Working with the files

The PHP file remains the single source of truth, so developer changes and dashboard content never need to be synchronized. Do not edit the same file via FTP and in the dashboard at the same time: pesi detects a changed file hash and asks the editor to reload instead of overwriting the newer version.

- **Add a field:** insert a `pesi()` call where you want it — it shows up in the dashboard immediately
- **Remove a field:** delete the `pesi()` call
- **Change a default or a label:** edit the second or fourth parameter
- **Add a page:** create the PHP file, add `require_once 'pesi-core.php'` at the top, set your fields, register it in `$PESI_PAGES`
- **Remove a page:** delete its entry from `$PESI_PAGES`
- **Update pesi:** replace `pesi.php`. Your configuration lives in `pesi-core.php`, your wording in `$PESI_STRINGS`

---

## Troubleshooting

### `/pesi` returns 404

`mod_rewrite` is probably disabled, or `.htaccess` files aren't being read. Check with your host that `AllowOverride All` is active. As a workaround, call `/pesi.php` directly — if that works, the issue is with rewriting.

### "Permission denied" when saving

The web server has no write permission on the PHP file or the folder. Set permissions via FTP to `0644` (file) and `0755` (folder). On some hosts the file owner needs to be switched as well — ask their support.

### Richtext editor shows only an empty box, no toolbar

Quill is bundled inside `pesi.php` — no CDN requests are made. The most likely cause is a strict **Content-Security-Policy** that disallows inline scripts (`script-src` without `'unsafe-inline'`). Look in your `.htaccess` or for a `<meta http-equiv="Content-Security-Policy">` tag. Add `'unsafe-inline'` or exclude the `/pesi` path from the policy.

### Uploads fail as "too large" well below 5 MB

The hosting caps uploads (`upload_max_filesize`, often 2 MB) below `PESI_UPLOAD_MAX_BYTES`. pesi shows the smaller, effective limit in the message and reports the mismatch as `T14` in the diagnostics panel. Raise the limit in the hosting or lower `PESI_UPLOAD_MAX_BYTES`. An upload above `post_max_size` is dropped by PHP before pesi runs; pesi detects the empty request and tells the client that nothing, including text changes, was saved.

### Dashboard links go nowhere, `?page=index.php` doesn't work

Your `.htaccess` has an over-aggressive PHP-stripping rule. See Step 3 — replace `(.+?)` with `([^?]+)`.

### Saved text is gone, page shows the old default again

Check whether a `.pesi-backup.1` file exists next to the PHP file. Before publishing, pesi runs `php -l` against a temporary candidate; invalid PHP is rejected while the live page stays unchanged. `.pesi-backup.1` and `.2` remain as technical recovery copies.

### A field doesn't show up in the dashboard

- Check that `require_once 'pesi-core.php'` is at the top of the file
- Check that the file is listed in `$PESI_PAGES`
- Check that the ID isn't already used in the same file — duplicates are invisible from the second occurrence onward
- Check the diagnostics panel for `T13` — double quotes around the value, or a quote inside the label

### Lists or links in richtext look unstyled on the frontend

Your site's CSS reset removes default list and link styles. pesi wraps richtext output in `<div class="pesi-richtext">` and injects a small `<style>` block once per page. If it still looks wrong, check that your CSS doesn't override the `.pesi-richtext` rules.

### The diagnostics line says `php -l` cannot run

The syntax check needs `exec()` and a PHP CLI in the `PATH`. Ask your host to enable both, or set `PESI_SYNTAX_CHECK` to `false` knowingly — the atomic write and the backups still protect the live page, but invalid PHP would then reach it.

---

## Honest limitations

- **No media library** — image upload only swaps page-bound `image` fields; decorative assets stay an FTP job
- **Replaced images are deleted only after the page and both backups no longer reference them.** pesi checks the files listed in `$PESI_PAGES`, their backups and their raw markup, but cannot see includes, partials or templates that are not registered pages. Register such files too, or keep their assets outside `PESI_UPLOAD_DIR`
- **Toggles must not be nested** — a `pesi:toggle` inside another one disables switching for that page, and the dashboard says so
- **No multi-user system** — one password for everyone
- **No version history.** pesi keeps two rotating recovery copies per page and the dashboard reaches exactly one of them. That covers *"the previous text was better"* and nothing beyond it. If someone notices on Friday that something broke on Monday, pesi cannot help — that is what your host's backups are for. Check that they are enabled before go-live, and tell the client where the boundary runs
- **Don't rename field IDs after go-live** — doing so orphans the client's saved content
- **Not meant for simultaneous heavy editing** — dashboard writes are lock-protected, but editing the same file via FTP and dashboard at the same time should be avoided

---

## Requirements

- PHP 8.2+ with `ext/dom` (richtext falls back to escaped plain text without it)
- Apache with `.htaccess` support, or the equivalent Nginx rules from Step 3
- Write access for the web server on the editable pages, the web root and the upload folder
- A PHP CLI reachable via `exec()` for the syntax check (optional; reported as `T7` if missing)
- HTTPS (strongly recommended)

---

## License

pesi is **MIT** — free to use, modify, and self-host. See [LICENSE](LICENSE).

pesi bundles the **Quill editor** (BSD-3-Clause) directly inside `pesi.php`, so its copyright notice and license must ship with every copy you redistribute. Those notices are in [THIRD-PARTY.md](THIRD-PARTY.md) — keep the file alongside `pesi.php` when you fork, repackage or hand pesi over to a client.

---

*pesi CMS — write more. worry less.*

---

## Support

pesi CMS is free and open-source. If it saves you time, consider buying me a coffee. ☕

<a href="https://ko-fi.com/ludescherstudio" target="_blank"><img src="https://ko-fi.com/img/githubbutton_sm.svg" alt="ko-fi"></a>
