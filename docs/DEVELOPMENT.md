# Development Guide

For developers (human or AI agent) working on the TeraPH Web Deployer codebase itself —
not on projects being deployed *by* it. For architecture context, read
[ARCHITECTURE.md](ARCHITECTURE.md) first.

---

## 1. Environment Setup

```bash
cp config.php.example config.php
php tera make:password yourpassword   # copy the hash into config.php
```

Edit `config.php`:

```php
define('APP_PASSWORD_HASH', 'paste-hash-here');
define('DEV_MODE', true);   // disables cURL SSL verification — Windows dev only
```

Run the dev server:

```bash
php tera serve         # http://localhost:8000
php tera serve 8080    # custom port
```

The SQLite database (`storage/deployer.db`) and all `storage/` subdirectories are created
automatically on first login (migrations run on every `app_boot()` call).

Requirements: PHP 8.1+, extensions `pdo`, `pdo_sqlite` or `pdo_mysql`, `zip`, `curl`,
`openssl`, `mbstring`. Verify with `php scripts/probe.php` (CLI-only) or check
`/settings.php` once logged in.

---

## 2. Code Conventions

These are enforced by project convention, not by a linter — follow them by hand:

- **No framework, no Composer at runtime.** New classes go under `app/Models/` or
  `app/Services/` and are wired into `app/bootstrap.php`'s `require_once` chain — there is
  no autoloader.
- **Service layer only for business logic.** Public entry points (`public/*.php`)
  coordinate: `app_boot()` → `Auth::require()` → `csrf_verify()` (if POST) → call a
  Model/Service → respond (`json_ok`/`json_error`/render). No SQL or file operations
  directly in a `public/*.php` file.
- **PDO named parameters only.** No string-interpolated SQL, ever. No SQLite-specific
  syntax (`PRAGMA`, `AUTOINCREMENT`, `DATETIME('now')`) — see REQ-NF-030 in
  [REQUIREMENTS.md](REQUIREMENTS.md).
- **Migrations are additive and idempotent.** Every migration file re-runs on every request
  via `app_boot()`. Wrap `ALTER TABLE ADD COLUMN` in a `try/catch` that swallows the
  "duplicate column" error (see any file in `migrations/` for the pattern). Never `DROP`,
  `RENAME`, or retype a column on a live table — this is also a hard rule in the root
  [CLAUDE.md](../CLAUDE.md).
- **Escape all output.** Use `h()` for anything rendered into HTML.
- **CSRF on every state-changing POST.** Call `csrf_verify()` before acting on the request;
  render `csrf_field()` inside every form.
- **Filesystem operations go through `FileManager`.** Don't hand-roll recursive
  copy/delete/chmod elsewhere — reuse `extract()`, `copyContents()`, `clear()`,
  `setPermissions()`, `deleteDirectory()` so skip-path and symlink-safety behavior stays
  consistent everywhere.
- **Shell commands go through `HookRunner`.** Don't call `proc_open`/`shell_exec` directly
  elsewhere; reuse `run()`/`runAll()`/`resolveComposer()`/`buildEnv()`.

---

## 3. Git Workflow

- Small, focused commits; one logical change per commit (per the Engineering Constitution
  in [CLAUDE.md](../CLAUDE.md)) -- see github: tumbujohn/dotagent for my CLAUDE.md because I use AI for coding... HaHaHA!.
- No destructive git operations without explicit user confirmation.
- This repo has no CI pipeline configured yet — see [TODO.md](TODO.md) (ENG tier). Run the
  manual checks in §5 before committing anything touching the deploy pipeline, migrations,
  or auth.

---

## 4. CLI Reference

All commands run from the project root via `php tera <command>`:

| Command | Description |
| --- | --- |
| `serve [port]` | PHP built-in dev server (default 8000) |
| `migrate` | Run all pending migrations |
| `make:password [pass]` | Hash a password for `config.php` |
| `github:test <project>` | Two-step PAT + repo access diagnostic |
| `deploy:reconcile` | Mark deployments stuck in `running` as `failed` |
| `clear:tmp` / `clear:backups` / `clear:logs` / `clear:archives` | Sweep one storage dir |
| `clear:all` | Run all clearing sweeps |
| `help` | List all commands |

Additional CLI-only maintenance scripts (not routed through the public webroot):

```bash
php scripts/probe.php       # install diagnostics — delete after use, or run via CLI only
php scripts/reset_db.php            # wipe all data for clean testing (prompts to confirm)
php scripts/reset_db.php --force    # skip confirmation
```

---

## 5. Testing & Verification

There is no automated test suite yet (`test/bootstrap_test.php` is a manual CLI diagnostic
script, not PHPUnit — see [TODO.md](TODO.md) ENG tier for the plan to add one). Before
completing work that touches the deploy pipeline, migrations, or auth:

1. `php test/bootstrap_test.php` — sanity-checks bootstrap/config loading.
2. `php tera deploy:reconcile` — should report "No stuck deployments found" on a clean DB.
3. Manually exercise the golden path in a browser: log in → add a project → deploy (Safe)
   → check Logs → check Backups → restore.
4. If you touched `safe_keep`/self-protection/env-manager logic, deploy twice in a row and
   confirm preserved paths and `.env` survive as expected.
5. If you touched the .env Manager, also check: the Live .env chip reads *In sync* right after
   **Apply now**; a required key left empty blocks both a deploy and an apply *before* the
   target changes; **Pull from live** asks before replacing a differing stored value; saving
   values keeps a multi-line value (e.g. a PEM key) intact; and flash messages appear.
6. Do UI and data-changing checks on a **throwaway copy**, not your real install: copy
   `app/`, `public/`, `migrations/` and a `config.php` with its own `DB_PATH` and
   `SECRET_KEY` somewhere else, then `php -S 127.0.0.1:8099 -t public`. Check phone widths
   (320–390px) as well as desktop — `docs/DESIGN.md` doesn't exist yet, so match the existing
   pages' styling.
7. `php -l` every changed `.php` file at minimum if no broader lint is available.

---

## 6. Debugging

- **Stuck deployment ("⟳ Deploying" forever):** `php tera deploy:reconcile`, or the
  equivalent button on `/settings.php`.
- **SSL errors on Windows dev:** confirm `DEV_MODE = true` in `config.php`. Never set this
  in production.
- **Hook / terminal command not found:** shared hosts often don't put `composer` on `PATH`
  — use the `{composer}` placeholder, which resolves via `HookRunner::resolveComposer()`.
- **Deployment log detail:** check `storage/logs/{project}_{deployment_id}.log` (flat-file
  fallback) alongside the `deployment_logs` table.
- **Full data reset for local testing:** `php scripts/reset_db.php --force`.

---

## 7. Deployment Preparation (shipping *this* app)

TeraPH deploys other PHP apps — but shipping a new version of TeraPH itself to a live
install uses `public/update.php` (self-update: downloads latest `main` from GitHub,
preserves `config.php` and `storage/`) or a manual re-upload. Before marking production
config ready, walk the Security Checklist in [README.md](../README.md#security-checklist).

---

*End of Document — Development Guide*
