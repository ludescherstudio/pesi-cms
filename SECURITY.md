# Security policy

pesi writes directly into the PHP source files of a live website and ships an
authenticated dashboard. Security reports are genuinely welcome.

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Use GitHub's **private vulnerability reporting** (Security → Report a
vulnerability) on this repository. If that is unavailable, reach the maintainer
via https://ludescher.studio.

Please include: affected file/function, PHP version, and a minimal
reproduction. A short proof-of-concept is worth more than a description.

Expect a first response within a few days. This is a small project maintained
alongside client work — timelines are best-effort, not contractual.

## Scope

**In scope**

- Bypassing the dashboard login or CSRF protection
- Escaping the `pesi()` value into executable PHP (heredoc/string breakout)
- XSS that survives `_pesi_sanitize_html()`
- Path traversal or arbitrary file write via the upload handler
- Anything that lets a *content editor* reach *code execution* — pesi's core
  boundary is "the client edits content, never code"

**Out of scope**

- A site whose operator has deliberately replaced the shipped password with a
  weak password. The literal shipped value itself fails closed: nobody can sign
  in until it is changed.
- Missing `.htaccess` hardening. Blocking `pesi-core.php` and `.pesi-*` files is
  a documented install step, and on Nginx an equivalent `deny` rule is required
  (see README). A server that skips it is a deployment issue, not a pesi bug.
  **In scope, however, is a shipped rule that does not do what it claims** — if
  the documented pattern fails to block a file the docs say it blocks, that is a
  pesi bug, not a deployment issue.
- Anything requiring an already-compromised dashboard password, *unless* it
  crosses the content→code boundary described above.
- Self-XSS, missing security headers on the customer's own site, or issues in
  the bundled Quill that are already fixed upstream (report those to Quill).

## Hardening checklist for operators

- Set a strong `PESI_PASSWORD`. `password_hash()` output is supported — see README.
- Verify `.htaccess` (Apache) or the equivalent `deny` rules (Nginx) actually
  block `pesi-core.php` and every `.pesi-*` file — by requesting
  `/<page>.php.pesi-backup.1` in a browser and confirming a 403. Backups are
  byte-for-byte copies of that page's source. Note that the `<FilesMatch>`
  pattern must be **unanchored** (`\.pesi-`, not `^\.pesi-`): a backup is named
  `index.php.pesi-backup.1` and does not start with `.pesi-`.
- Keep `PESI_SYNTAX_CHECK` enabled so invalid PHP is rejected in the temporary
  candidate before the live page is atomically replaced.
- Serve the dashboard over HTTPS — session cookies only get the `secure` flag
  when the request is HTTPS.
