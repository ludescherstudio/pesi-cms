# CLAUDE.md — working on pesi CMS itself

This file orients a fresh session working **on the pesi source code**.
(For installing pesi into a customer site, see `pesi-agent.md` — that is an
installation guide, not a dev guide.)

## Branches and public development

Work on `dev`; `main` is the release branch and should be the GitHub default.
Both branches and their complete histories are public when this repository
is public. Keep customer content, credentials, local paths and unpublished
project details out of every commit, message and development guide.

`dev` also tracks this guide and `dev/test-engine.php`. They are excluded
from source archives with `export-ignore`; this is packaging, not privacy.
`.claude/`, `.codex/` and other local tool configuration stay untracked.
Never force-add local tool settings.

Publish by copying the public files from `dev` to a clean `main` worktree.
Review the explicit file list, diff and archive before committing. Do not
merge development-only files onto `main`. Add new public files deliberately.
Run the test suite against the release worktree before publishing.

Use your GitHub noreply email for new commits. After a history cleanup,
replace old clones or worktrees before pushing again; merging an old branch
can restore the removed history.

## What pesi is

A minimal inline CMS for small professional sites (therapists, practices,
artists). Clients edit content via a dashboard at `/pesi`. Edits are written
**directly back into the PHP source files** — there is no database, no JSON
store, no build step. The PHP file is always the single source of truth.

## Philosophy — read before adding anything

pesi's value is what it *refuses* to be. **We are not rebuilding WordPress.**
Every feature must stay lightweight and keep these invariants:

- **No database, no external store.** Content lives in the page's PHP source.
- **The developer designs the markup; the client only edits content.** pesi
  never generates layout or lets the client create pages/structure via UI.
- **Safety net is sacred.** Every source-rewriting operation must route through
  `_pesi_commit()`: stable sidecar lock, complete same-directory candidate,
  `php -l`, rotating `.pesi-backup.1/.2`, then atomic `rename()`. Never write
  the live page file directly.
- **Self-contained.** No CDN, no Composer deps. Quill is vendored inline.
- If a feature wants a real management UI / its own URLs / scales to
  hundreds of items, it does **not** belong in core — it is a separate
  module. Core handles small, page-bound content.

When unsure, prefer doing less. "Stay clean" beats "more features".

## Files

| File | Role |
|------|------|
| `pesi-core.php` | Config (`define`s) + the `pesi()` helper included by every page. ~tiny. |
| `pesi-content.php` | Editable shared practice details, registered as “Stammdaten”. |
| `pesi.php` | The whole dashboard: auth, parser, saver, block/toggle/restore engine, all HTML/CSS/JS, vendored Quill (one ~210 KB minified line — do not try to Read the whole file; use offset/limit or grep). |
| `pesi-agent.md` | Installation guide for an agent integrating pesi into a site. |
| `README.md` | End-user/developer documentation. (May lag behind features — verify against code.) |
| `LICENSE` / `THIRD-PARTY.md` | MIT for pesi itself; the bundled Quill is BSD-3-Clause and its notice **must** ship with every copy. Update `THIRD-PARTY.md` whenever the vendored Quill version changes. |
| `SECURITY.md` | Disclosure policy + what counts as in scope. |
| `CLAUDE.md` | This file. Tracked on `dev` only; public development guidance, excluded from release archives. |
| `*.old` | Manual pre-change backups. Not part of the product; git-ignored. |

**Two guides, two audiences — do not add a third.** This file is for working *on*
pesi and is public on `dev`. `pesi-agent.md` is the guide for installing pesi *into* a
customer site and ships publicly. They share no content.

Never rename `pesi-agent.md` to `AGENTS.md`: it gets read inside customer
projects, which frequently have an `AGENTS.md` of their own that it would
collide with or overwrite. For the same reason this file is `CLAUDE.md`, not
`AGENTS.md` — the public repo deliberately contains no agent-guide filename that
a host project could clash with.

## Architecture in four sentences

`_pesi_parse()` regex-scans a page for `pesi('id', 'default', 'type', 'label')`
calls (string + heredoc forms) and returns the fields. The dashboard renders
them as inputs. `_pesi_save()` replaces only changed values in the source via
`_pesi_replace()`, then commits through the shared safety net. Structural
features (repeatable blocks, visibility toggles, restore) manipulate spans of
the same source and commit the same way.

## Feature surface (all in `pesi.php`)

- Field types: `text`, `textarea`, `richtext` (Quill, heredoc), `image`,
  `url`, `email`, `tel`
  (upload + MIME/size validation + orphan cleanup; config in `pesi-core.php`).
- **Repeatable blocks**: `<!-- pesi:item GROUP:N -->…<!-- /pesi:item -->`,
  field IDs prefixed `GROUP_N_`. Dashboard offers duplicate / delete /
  reorder (↑↓) / add. See `_pesi_block_*`.
- **Visibility toggle**: `<!-- pesi:toggle G -->…<!-- /pesi:toggle -->`;
  hidden state wraps the body in `<?php if (false): ?> … <?php endif; ?>`.
  See `_pesi_toggle_*`.
- **Restore**: `_pesi_restore()` rolls the page back to `.pesi-backup.1`
  (reversible — current state rotates into backup first).
- Unsaved-changes guard (JS) warns before structural actions / unload.

## Traps — verified the hard way, do not re-introduce

These were real bugs found in the 2026-07 audit. Each one is a single line that
looks harmless and silently reopens a hole. If you touch the saver or the
sanitizer, re-check all four.

- **`_pesi_hd()` must match `/^[ \t]*PESI\b/m`, not `/^PESI\b/m`.** PHP 7.3+
  accepts *indented* heredoc closing markers, so field content containing a line
  like `  PESI . system(...)` closes the nowdoc early and executes as PHP.
  `php -l` does not catch it — the result is syntactically valid.
- **Never put user content into a `preg_replace` replacement string.**
  `$1`/`${1}`/`\1` in client text get expanded as backreferences. Use
  `preg_replace_callback` (as `_pesi_replace()` now does). Symptom without it: a
  price like `$1.000` corrupts the file, and `\1` corrupts it *silently*.
- **In `_pesi_sanitize_html()`, clean a disallowed element's subtree *before*
  unwrapping it.** The child list is an `iterator_to_array` snapshot taken before
  the loop, so nodes hoisted out of a removed wrapper are never visited —
  `<div><script>alert(1)</script></div>` came through raw.
- **Strip `[\x00-\x20]` before checking an href scheme.** Browsers ignore control
  characters inside a scheme, so `\x01javascript:` must not be treated as a
  relative URL. `_pesi_safe_asset_url()` and `_pesi_sanitize_html()` must stay
  consistent here.

Sanitizer changes are only proven by *nested* test cases. Flat ones pass while
the nested bypass above is wide open.

Four more from the 2026-07-31 audit. The first is the one that matters most:

- **`_pesi_commit()` must open the page with `fopen($file, 'c+')`, not `'c'`.**
  `'c'` is write-only, so the `stream_get_contents()` in the stale-check reads
  `''`, the sha256 comparison can never match, and every call that passes a hash
  fails with "page changed since you opened it". `_pesi_save()` is the only
  caller that passes one — which is why saving a field was completely broken
  while blocks and toggles kept working. Nothing about this shows up in `php -l`
  or in a quick manual click-through of the structural features.
- **A non-zero exit from `php -l` does not prove a syntax error.** A missing CLI
  binary makes the shell answer 127 (1 on Windows). Treating that as a failure
  rolls back *every* save on such hosts and blames the client's content.
  `_pesi_lint()` returns `true`/`false`/`null` and only counts a real lint
  diagnostic as `false`.
- **`_pesi_sanitize_html()` must delete `XML_PI_NODE` and `XML_COMMENT_NODE`.**
  DOMDocument keeps PHP tags and HTML comments as their own node type, so the
  element allowlist never sees them and `saveHTML()` writes them back verbatim.
  A client could land `<!-- /pesi:toggle -->` or an `endif` in the page source.
- **Field values must be checked for pesi structure markers before writing.**
  A `pesi:item`/`pesi:toggle` marker or an `if (false):` in ordinary text creates
  phantom structure; a following block operation then produces garbage that
  still passes `php -l`, so the safety net stays silent. `_pesi_has_marker()`
  rejects the save instead.

Watch the comment style in `pesi-core.php` and `pesi.php`: a `?>` inside a `//`
comment ends PHP mode. Use `/* … */` when a comment needs to mention PHP tags.

Two more, both about assuming the host is like yours:

- **Check what you write.** `fwrite()` reports a short write only through its
  return value. On a full disk or an exhausted quota an unchecked `fwrite()`
  leaves the client's live page half written, and the syntax check does not
  save you — it is exactly the situation where it may also be unavailable.
  Same for the throttle register: a truncated JSON file silently reads back as
  empty, which turns the brute-force brake off without a trace.
- **Never construct an optional extension unguarded.** `new finfo()` fatals on
  hosts without ext/fileinfo, which killed every image upload with a white
  page. `_pesi_image_mime()` falls back to `getimagesize()` (PHP core) and
  returns `''` when neither works, so the caller rejects instead of trusting a
  filename. Applies to any extension-provided class you add later.

Two from the 2026-09-04 review before the first customer integration:

- **Quill 2 serialises every list as `<ol>` with `li[data-list="bullet"]`.**
  `q.root.innerHTML` never contains a `<ul>`; the sanitizer's attribute
  allowlist stripped `data-list`, and every bullet list became numbered on
  save — even when the editor was never touched, because the submit handler
  re-serialised all editors. `_pesi_split_quill_list()` in `pesi-core.php`
  splits such lists by kind *before* the allowlist runs (and the new nodes are
  cleaned explicitly — the snapshot rule from Trap 3), and the submit handler
  only writes back editors the client edited (`qt` set). Do not switch to
  `getSemanticHTML()` without checking: it turns the space before an inline
  element into `&nbsp;`.
- **The host's `upload_max_filesize` (often 2 MB) is below pesi's 5 MB.** PHP
  then reports `UPLOAD_ERR_INI_SIZE`, which means "too large", not "try again";
  above `post_max_size` PHP drops the *whole* POST and the dashboard reloaded
  with no message at all. `_pesi_upload_limit()` is the effective cap for the
  size check and every message, `_pesi_post_dropped()` catches the empty POST,
  and diagnostic T14 tells the integrator about the mismatch.

## Two audiences, one interface — the rule for every string

The dashboard belongs to the **client** (therapist, practice, artist; not
technical; opens it every few weeks). The **integrator** — you, or whoever
installed pesi from the public repo — opens it twice during setup. Design for
the first, keep the second one click away. Before writing any user-facing text,
decide which of the three it is:

| Anlass | Beispiel | Wording |
|---|---|---|
| She just did something | image too large, nothing changed | plain consequence, **no code** — she can fix it alone |
| Setup was already broken | default password, no `php -l` | goes in the collapsed diagnostics panel, never a red banner |
| She acted legitimately, pesi refuses | nested toggles, marker in text | plain consequence **+ short code**, ask her to pass it on |

Codes: `S…` structure of the page, `T…` technical/server. Every code must exist
in the README table — `dev/test-engine.php` asserts that the messages carry one,
but it cannot check that the table documents it. Add both together.

Never name the mechanism in a client-facing string. "PHP-Fehler erkannt —
Commit abgelehnt!" suggests the site is broken; "Ihre Seite ist unverändert geblieben"
tells her the truth. Field IDs and type badges belong behind the *Technische
Ansicht* toggle, not on the card.

## Conventions

- All user-facing strings go through the `$strings` DE/EN i18n arrays in
  `pesi.php`. German is the default and **siezt**; both languages must be kept
  in sync — same keys, same `%` placeholders. `dev/test-engine.php` fails on
  drift, on dead keys, and on a missing error code.
- German typography: `„…“`, not `„…"`. The straight ASCII quote renders as a
  mismatched pair and shows up as `&quot;` in the HTML.
- Integrators reword via `$PESI_STRINGS` in `pesi-core.php`, which overrides
  individual keys. Never remove a key without checking it is unused — the
  override is a documented public surface now.
- New CSS goes in the existing `<style>` block; new JS in the always-present
  small `<script>` (the Quill-init script only renders when richtext exists).
- Before changing files on request, the user often wants `.old` backups
  first — ask only if unclear.

## Testing

On `dev` the suite is simply there. Run it before and after touching the saver,
the sanitizer or the structural features:

```bash
php dev/test-engine.php
```

It takes an optional path to the working copy under test
(`php dev/test-engine.php ../elsewhere`), which is what makes it usable from a
`main` checkout or a second worktree. Default is its own parent, so the plain
call above is the normal case.

It is absent on `main` by design. If you ever find yourself on `main` needing
it, fetch it without committing it — `.gitignore` keeps it from being staged
by accident:

```bash
git checkout dev -- dev/test-engine.php && git reset
```

It covers all eight traps above, the block round-trip (add → duplicate →
reorder → delete incl. the `GROUP_N_` ID rewrite), toggle hide/show including
the *rendered* output, the stale-check, and DE/EN key parity. Exit code 0/1, no
dependencies. `dev/` is not part of a customer install.

The suite works because `pesi.php` is not includable standalone (session and
headers fire on include), so it slices the function span — `_pesi_parse()` down
to `_pesi_strings()`, all pure functions — into a temp file, requires
`pesi-core.php` alongside it, and sets `$GLOBALS['t'] = _pesi_strings()['de']`
(the ops read `$t` via `global`). If you add a function outside that span, the
suite will not see it; extend the slice bounds in the script rather than
loosening what the span contains.

Work in a scratch directory, never against the real repo files.

Always finish with `php -l pesi.php && php -l pesi-core.php`.
