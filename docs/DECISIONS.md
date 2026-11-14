# Decisions

Institutional memory for significant architecture, technology, product, security, and UX
decisions. Newest first within each section is not enforced — entries are grouped by topic.
Never silently reverse a decision recorded here; if circumstances change, add a new entry
explaining the change and why.

---

## No PHP Framework

**Context:** Target hosting is shared cPanel — no guaranteed shell access, no guaranteed
Composer, unpredictable PHP-FPM configuration limits.

**Decision:** Build on plain PHP 8.1+ with a manual `require_once` chain
(`app/bootstrap.php`) instead of Laravel/Symfony/CodeIgniter.

**Alternatives considered:** A micro-framework (Slim) was considered and rejected — even a
thin framework adds a Composer dependency at runtime, which shared hosts can't always
support without SSH.

**Consequences:** No DI container, no router, no ORM — every public entry point is its own
file and calls services directly. Trades developer convenience for maximum host
compatibility. See [ARCHITECTURE.md](ARCHITECTURE.md) §1–2.

---

## SQLite as the Default Database

**Context:** Zero-setup requirement — a fresh cPanel account may not have a MySQL database
provisioned yet, and provisioning one is manual, cPanel-UI-dependent work.

**Decision:** Default to SQLite (`storage/deployer.db`); support MySQL/MariaDB as an
explicit opt-in via `DB_DRIVER`.

**Consequences:** All queries must avoid engine-specific SQL (`PRAGMA`, `AUTOINCREMENT`,
`DATETIME('now')`) so the DSN string in `Database.php` is the *only* thing that changes
when switching engines. This is enforced as a hard constraint (CON-04/REQ-NF-030 in
[REQUIREMENTS.md](REQUIREMENTS.md)).

---

## Additive-Only Migrations, Re-run on Every Request

**Context:** No SSH on many target hosts means there's no guarantee an admin can run
`php tera migrate` after an update — but the app must still pick up new columns/tables.

**Decision:** Every migration file is idempotent (safe to run more than once) and
`app_boot()` runs the full migration set on every single request, wrapped in output
buffering so stray `echo` output can't corrupt AJAX responses.

**Alternatives considered:** A tracked `schema_migrations` table (skip already-applied
versions) is the "correct" long-term answer and is tracked in [TODO.md](TODO.md) P1 — not
done yet because re-running idempotent `ALTER TABLE`/`CREATE TABLE IF NOT EXISTS` statements
is cheap enough that it hasn't mattered in practice yet.

**Consequences:** Migrations must *never* be destructive (`DROP`/`RENAME`/retype) — this is
also a standing rule in the root [CLAUDE.md](../CLAUDE.md). A destructive migration run
automatically on every request would be catastrophic. -- see github: tumbujohn/dotagent for my CLAUDE.md because I use AI for coding... HaHaHA!.

---

## Web Terminal: Keep and Harden, Don't Remove

**Context:** Early in the project the terminal's risk profile (arbitrary shell access from
a browser) was questioned.

**Decision:** Keep it — it's a deliberate feature for operators who need an in-browser
shell scoped to one project's directory when SSH isn't available, not an accidental
liability.

**How it's hardened instead of removed:** CWD is always locked to `target_path`; every
command is written to `terminal_logs` (user, IP, command, exit code) with a flat-file
fallback; a site-wide `TERMINAL_ENABLED` kill switch and a per-project `terminal_enabled`
column both exist; commands are subject to `TERMINAL_TIMEOUT`.

**Consequences:** Operators who don't want this attack surface at all should set
`TERMINAL_ENABLED = false` — see [SECURITY.md](SECURITY.md).

---

## Deploy Strategy: Copy-in-Place First, Symlink Deferred

**Context:** Atomic symlink-based releases (`releases/{ts}/` + `current` symlink swap) are
the "correct" way to get zero-downtime, instantly-reversible deploys — but not all shared
hosts support `symlink()`, and the webroot may not tolerate a symlinked document root.

**Decision:** Ship copy-in-place (today's only implemented strategy) as the universally
compatible default; build the auto-detection plumbing (`DEPLOY_STRATEGY`,
`detect_deploy_strategy()`) ahead of the full symlink pipeline so the config surface and
caching behavior are already in place when it's implemented.

**Consequences:** `DEPLOY_STRATEGY=symlink`/`auto`-detected-as-symlink currently only
affects what gets logged, not pipeline behavior — this is intentionally documented as a gap
in [ARCHITECTURE.md](ARCHITECTURE.md) §6 and [TODO.md](TODO.md) P0.1 rather than left
implicit.

---

## Self-Protection Instead of Documentation-Only Warnings

**Context:** A common cPanel layout is `public_html/{app}` with the deployer as a sibling
or, worse, nested inside a target being fully redeployed — which would delete the deployer
itself mid-deploy.

**Decision:** Detect this automatically via `realpath()` containment check
(`is_deployer_inside_target()`) and auto-inject the deployer's relative path into the skip
list for clear/copy/backup, rather than relying on the operator to remember to configure it
manually.

**Consequences:** A visible dashboard badge ("⚠ Deployer inside target") tells the operator
this auto-protection is active, so it's not a silent safety net they're unaware of.

---

## .env Encryption Key Falls Back to APP_PASSWORD_HASH

**Context:** The .env manager needs an encryption key immediately, even on installs that
haven't set a dedicated `SECRET_KEY`.

**Decision:** `env_encryption_key()` uses `SECRET_KEY` if set, otherwise derives a key from
`APP_PASSWORD_HASH` — so the feature works out of the box without a required extra setup
step.

**Consequences (accepted risk):** Changing the admin password without first setting a
dedicated `SECRET_KEY` permanently breaks decryption of all stored `.env` values. The UI
surfaces a persistent warning banner on the .env Manager page whenever `SECRET_KEY` is
unset — this is treated as a known, surfaced tradeoff, not a bug. See
[SECURITY.md](SECURITY.md).

---

## Symlinks Are Unlinked by Path, Never Resolved Through

**Context:** `FileManager::clear()` and `deleteDirectory()` walk the target directory
recursively. A naive implementation that calls `unlink()`/`rmdir()` on `getRealPath()` for
a symlinked entry would resolve *through* the symlink and potentially delete files outside
the webroot entirely.

**Decision:** When an iterated item `isLink()`, delete it via its own path
(`getPathname()`), never via the path it resolves to (`getRealPath()`).

**Consequences:** This was a deliberate correctness fix (see git history — "Improve file
deletion handling to correctly unlink symlinks and enhance safety"). Any future filesystem
code added to `FileManager` must preserve this distinction.

---

## Project Deletion Retains Deployment History

**Context:** Deleting a misconfigured or retired project shouldn't erase the audit trail of
what was deployed and when.

**Decision:** `Project::delete()` removes the project row and its `project_env_vars` (env
values have no meaning without the project and may contain sensitive data worth clearing),
but explicitly leaves `deployments` and `deployment_logs` rows in place.

**Consequences:** `deployments.project_id` becomes an orphaned foreign key after deletion —
any UI/reporting that lists deployment history must handle a missing project gracefully
(display the project name captured at deploy time / handle a null join) rather than
assuming the join always resolves.

---

## Single Admin Role, No Multi-User (For Now)

**Context:** The original SRS scoped a Viewer role for Phase 2; it has not been built.

**Decision:** Ship and continue operating as single-admin-only until there's real demand for
shared access, rather than building role infrastructure speculatively.

**Consequences:** Every authenticated session has full access to every function — there is
no read-only mode. See [REQUIREMENTS.md](REQUIREMENTS.md) §5 and [TODO.md](TODO.md) P2.

---

## Values Containing `${` Are Written Single-Quoted

**Context:** The managed `.env` is read by whatever the deployed app uses — typically
vlucas/phpdotenv (Laravel and others) or CodeIgniter 4's `DotEnv`. Both expand `${NAME}`
references to already-defined variables, so a stored password like `pa${HOME}ss` can be
silently rewritten when the app reads it. A bare `$` (as in `cost$5`) is not expanded by
either parser; only the exact `${NAME}` form is. Parser behavior was checked against the
phpdotenv 5.x and CI4 `DotEnv` source.

**Decision:** `env_quote_value()` writes a value in single quotes when it contains `${` and
no `'`, backslash or newline. phpdotenv reads single-quoted values literally, so these are
protected. All other values keep the previous rules (bare, or double-quoted with `"` and `\`
escaped), which both parsers read identically.

**Alternatives considered:** Single-quoting every value with `$` — rejected, it adds no
protection (a lone `$` is never expanded) and changes output for no reason. Escaping as
`\$` in double quotes — rejected: phpdotenv accepts it but CI4's regex fails to match a
lone backslash and leaves the surrounding quotes in the value, which is worse than the
expansion it prevents.

**Consequences:** Two known gaps remain. (1) CI4 expands `${NAME}` inside any quoting and
has no escape, so a value referencing a variable that is defined there cannot be written
safely for CI4. (2) A `${` value that also contains `'`, a backslash or a newline can't be
single-quoted and phpdotenv may still expand it. Both are rare; avoid `${` in secrets for
CI4 projects. Because the writer cannot make these safe, it detects them instead: the .env
Manager shows a warning and deploy/apply log one (key names only; a reference to another
managed key is marked certain to change). It never blocks a deploy.

---

## The Stored Env Template Holds Structure, Not Values

**Context:** Pasting a full `.env` into the template field saved the raw text — secrets included —
in `projects.env_template` (plaintext), while the values were also stored encrypted. The textarea
then displayed the old values, and re-saving it overwrote newer stored values with stale ones.
"Pull from live" would have copied a whole production `.env` into that column.

**Decision:** `EnvVar::setTemplate()` always scrubs values (`KEY=secret` → `KEY=`). The template is
only the key order, comments and `export` prefixes; values come from `project_env_vars`.

**Alternatives considered:** Encrypting the whole template — rejected, the structure isn't secret
and the editor needs it readable. Scrubbing only in the UI — rejected, every write path
(import, pull, copy, bundle) must be safe.

**Consequences:** Defaults in a pasted `.env.example` are still imported as values, but no longer
visible in the template textarea. Existing templates are scrubbed by migration 006 on the next
page load. The scrub is deliberately behavior-preserving: a stored value always beats the
template when rendering (even an empty one), so only values for keys with *no* stored row are
saved first — filling empty rows would resurrect values someone cleared on purpose. The manual
banner remains as a fallback if the migration ever fails.

---

## "Apply Now" Is a Recorded, Locked Action

**Context:** Changing one env value shouldn't need a full redeploy, but writing files into a live
site is exactly what the deploy lock and audit trail exist for.

**Decision:** Apply now goes through `EnvApplyService`, which records a `deployments` row
(`mode = 'env_apply'`), takes the same per-project lock, logs each step, and shares its preflight
and write code with the deploy pipeline.

**Alternatives considered:** A bare "write file" button — rejected, no audit trail and it could race
a running deploy. Reusing the full deploy with a flag — rejected, it re-downloads and re-copies.

**Consequences:** An apply appears in the deployment history and logs, but the dashboard's status
badge and "last deployed" time skip `env_apply` rows (`Project::all()`): a project must not look
redeployed — or failed — because only its environment file was rewritten. A restore, which does
change the deployed code, still counts.

---

*Record new significant decisions here as they're made — architecture, technology,
product, security, or UX. Include context, the decision, alternatives considered, and
consequences.*
