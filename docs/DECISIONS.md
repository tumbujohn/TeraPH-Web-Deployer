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

*Record new significant decisions here as they're made — architecture, technology,
product, security, or UX. Include context, the decision, alternatives considered, and
consequences.*
