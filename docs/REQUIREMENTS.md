# Requirements

The current requirements baseline for TeraPH Web Deployer. This supersedes
[archive/SRS.md](archive/SRS.md) (the original Phase 1 IEEE-830 draft) — several features
below (manual upload, deploy hooks/templates, web terminal, .env manager, self-protection,
General Settings) were built beyond that draft's original scope, and some of that draft's
Phase 1 items (webhook auto-deploy, PAT encryption) remain undelivered. For what is actually
implemented today vs. planned, see [PROGRESS.md](PROGRESS.md) and [TODO.md](TODO.md).

---

## 1. Purpose & Scope

TeraPH Web Deployer is a private, single-admin, browser-accessible panel that deploys
registered PHP projects from a GitHub repository (or a manually uploaded zip) to a target
directory on the same server — without SSH, Git, or external CI/CD. It owns the full
deployment lifecycle: download → backup → replace → hooks → permissions → log, with
one-click restore from any prior backup.

**Primary user:** a single administrator (developer or site owner) with full access to all
functions. There is no multi-user role model — see §5.

---

## 2. Functional Requirements

### 2.1 Authentication & Session

| ID | Requirement |
| --- | --- |
| REQ-F-001 | A login form is the only unauthenticated entry point. Every other page/endpoint requires an active session (`Auth::require()`). |
| REQ-F-002 | Credentials are a single configurable username (`APP_USERNAME`) and a bcrypt hash (`APP_PASSWORD_HASH`) — no plaintext password storage. |
| REQ-F-003 | Sessions use `cookie_httponly` + `cookie_samesite=Strict`, idle-expire after `SESSION_TIMEOUT` seconds (default 3600), and regenerate the session ID on login. |
| REQ-F-004 | Failed login shows a generic error — does not reveal whether the username or password was wrong. |
| REQ-F-005 *(not implemented)* | Login attempts should be rate-limited / lockable after N failures. See TODO.md P0.4. |

### 2.2 Project Management

| ID | Requirement |
| --- | --- |
| REQ-F-010 | A project is registered with: name (unique slug), source type (`github` or `manual`), repo URL + branch (GitHub) or uploaded archive (manual), target path, safe-keep paths, GitHub PAT, deploy template, pre/post-deploy hooks, terminal enabled flag, env mode, optional PHP binary. |
| REQ-F-011 | The dashboard lists all projects with last deployment status and timestamp. |
| REQ-F-012 | Projects can be edited; leaving the PAT field blank on edit preserves the stored token (`keep_pat` flag). |
| REQ-F-013 | Deleting a project removes its row and all associated `project_env_vars`, but **retains** its `deployments` and `deployment_logs` rows for audit continuity. |
| REQ-F-014 | Project name must be unique (`Project::nameExists()`); target path must be non-empty. |

### 2.3 Deployment Pipeline

| ID | Requirement |
| --- | --- |
| REQ-F-020 | A deploy is triggered by selecting a project, a mode (Safe/Full), and confirming via a modal. |
| REQ-F-021 | A per-project lock (`Deployment::isLocked()`) prevents concurrent deploys of the *same* project; different projects may deploy concurrently. |
| REQ-F-022 | Source acquisition: GitHub download via cURL (API endpoint if a PAT is set, direct zip URL otherwise) or, for `source_type = manual`, the project's saved archive under `storage/archives/`. |
| REQ-F-023 | The archive is extracted to `storage/tmp/`, stripping the top-level wrapper folder GitHub adds — only when that folder is the sole entry in the archive. Backup restore never strips. |
| REQ-F-024 | Extraction is validated non-empty before proceeding. |
| REQ-F-025 | A zip backup of the current target is created **before** any file is modified, unless the target does not yet exist (first deploy). |
| REQ-F-026 | The target directory is cleared, respecting `safe_keep` (Safe mode only) and any self-protection paths (always). |
| REQ-F-027 | Extracted files are copied into the target, respecting the same skip list. |
| REQ-F-028 | Missing `safe_keep` directories (e.g. `uploads/`, `writable/`) are created if absent so post-deploy hooks that expect them don't fail on first deploy. |
| REQ-F-029 | If `env_mode = managed`, a rendered `.env` is written to the target before hooks run; the deploy halts if a required env key has no value. |
| REQ-F-030 | Pre-deploy hooks run after file copy, before permissions. Post-deploy hooks run after permissions. A non-zero exit from any hook halts the pipeline and marks the deployment failed. |
| REQ-F-031 | Permissions are set to `0755` (directories) / `0644` (files) on everything outside the skip list. |
| REQ-F-032 | The lock is released and the deployment record finalized (success or failed) in a `finally` block regardless of outcome; temp files are always cleaned up. |

### 2.4 Deployment Modes

| ID | Requirement |
| --- | --- |
| REQ-F-040 | **Safe mode** (default): preserves `safe_keep` paths (default `.env`, `uploads/`, `storage/`, `writable/`) — only application code is replaced. |
| REQ-F-041 | **Full mode**: clears the entire target directory with no exclusions except self-protection. The UI must warn explicitly before a Full deploy proceeds. |

### 2.5 Backup & Restore

| ID | Requirement |
| --- | --- |
| REQ-F-050 | Every deploy creates a timestamped zip backup before touching files (REQ-F-025). |
| REQ-F-051 | Backups are listed per project (name, size, created time) via `backups.php`. |
| REQ-F-052 | Restoring a backup: takes a safety snapshot of current state first, clears the target, extracts the selected backup, sets permissions. |
| REQ-F-053 | `MAX_BACKUPS_PER_PROJECT` (default 10) is enforced — oldest backups beyond the limit are deleted after each new backup. |
| REQ-F-055 | `BACKUP_ENCRYPT_ENV` (default `false`) controls whether `.env` files inside backup zips are AES-256 password-protected (key derived from `SECRET_KEY`). Restore decrypts transparently, and decrypts backups made while the flag was on even after it is turned off. |
| REQ-F-054 *(not implemented)* | Backup download from the browser. See TODO.md. |

### 2.6 Logging & Audit Trail

| ID | Requirement |
| --- | --- |
| REQ-F-060 | Every pipeline step writes a structured `INFO`/`WARNING`/`ERROR` log entry in real time to the `deployment_logs` table. |
| REQ-F-061 | Log entries also stream to a flat file at `storage/logs/` as a DB-failure fallback. |
| REQ-F-062 | The dashboard's Logs modal shows all entries for a given deployment in chronological order, including live polling while a deploy is `running`. |
| REQ-F-063 | Every deployment record captures: triggering user, mode, start/end time, outcome, backup path. |
| REQ-F-064 | Every terminal command is logged to `terminal_logs` (user, IP, command, exit code) with a flat-file fallback (`terminal_audit.log`). |

### 2.7 GitHub Integration

| ID | Requirement |
| --- | --- |
| REQ-F-070 | Public repos download via `https://github.com/{owner}/{repo}/archive/refs/heads/{branch}.zip`. |
| REQ-F-071 | Private repos (or any repo with a PAT configured) download via the GitHub REST API zipball endpoint with a `Bearer` token — supports both classic (`ghp_`) and fine-grained (`github_pat_`) tokens. |
| REQ-F-072 | A per-project PAT overrides the global `GITHUB_PAT` fallback. |
| REQ-F-073 | Download failures (network, 401, 403, 404, undersized file) halt the pipeline with an actionable error message and release the lock. |
| REQ-F-074 | `php tera github:test <project>` diagnoses PAT validity and repo access independently of a real deploy. |

### 2.8 Deploy Templates & Hooks

| ID | Requirement |
| --- | --- |
| REQ-F-080 | Selecting a `deploy_template` (`laravel`, `codeigniter`, `wordpress`, `symfony`, `none`) pre-fills the pre/post hook text areas with framework-standard commands; the operator may edit freely afterward. |
| REQ-F-081 | Hook commands support a `{composer}` placeholder resolved at runtime to the correct composer invocation for that target directory. |
| REQ-F-082 | Each hook command is subject to `HOOK_TIMEOUT` (default 300s); output streams to the deployment log in real time. |

### 2.9 Web Terminal

| ID | Requirement |
| --- | --- |
| REQ-F-090 | A project with `terminal_enabled = 1` (and site-wide `TERMINAL_ENABLED = true`) exposes a browser shell scoped to its `target_path`. |
| REQ-F-091 | Terminal commands stream stdout/stderr to the browser in real time via `proc_open`. |
| REQ-F-092 | A shortcut toolbar provides one-click common commands and a "download composer.phar" action with SHA-256 verification. |
| REQ-F-093 | Commands are subject to `TERMINAL_TIMEOUT` (default 600s). |
| REQ-F-094 | Hooks, the Apply-now command and the terminal run with a PHP CLI chosen in this order: the project's own binary (`projects.php_binary`), then `CLI_PHP_PATH` (a path, or `system` to leave `PATH` alone), then automatic — the CLI belonging to the PHP serving the site, used when the `php` on the web server's `PATH` is a different major.minor version. The chosen binary's directory is placed first on `PATH` (a binary not named `php` is exposed as `php` through a shim under `storage/tmp/phpbin/`) and `{composer}` uses it. An unusable setting is ignored and flagged, never fatal. |
| REQ-F-095 | The project form accepts an optional PHP binary, validated on save as an absolute path to an executable file with no control characters, and suggests binaries found in the usual host locations. Settings shows the PHP hooks get and how it was chosen, flags a major.minor difference from the web PHP (with the reason), flags an unusable `CLI_PHP_PATH`, and lists the PHP binaries found with their versions. Deploy and apply logs state the PHP used. |

### 2.10 .env Manager

| ID | Requirement |
| --- | --- |
| REQ-F-100 | Per project, `env_mode` toggles between `none` (deployer does not touch `.env`) and `managed` (deployer owns and writes `.env` on every deploy). |
| REQ-F-101 | A template (raw `.env.example`/`.env` text) can be pasted or imported from the target's existing `.env.example`; saving extracts and syncs keys. |
| REQ-F-102 | Individual values are stored encrypted; the UI never displays raw values by default (per-field and "hide all" toggle) and the rendered preview always masks non-empty values. |
| REQ-F-103 | A key can be flagged `required`; a deploy halts in a preflight step — before the download, backup, or any change to the target — if any required key is empty. Re-saving a template or re-importing `.env.example` preserves existing required flags. |
| REQ-F-104 | Keys can be added, renamed (value/order preserved), or deleted individually. |
| REQ-F-105 | The same preflight halts the deploy if any stored value cannot be decrypted (changed `SECRET_KEY`, corrupt data) rather than writing it as empty; the UI flags those keys and shows a banner. |
| REQ-F-106 | After extraction, the release's `.env.example` is compared with the managed keys; keys it lists that are unmanaged or empty are logged as warnings (advisory — they do not fail the deploy), and managed keys it no longer lists are logged as info. |
| REQ-F-108 | Saving a template or importing `.env.example` never deletes keys silently: if the new text drops existing keys, nothing is written and the UI asks to remove them or keep them (kept keys are re-added to the template as empty stubs). Keys holding stored values are called out. |
| REQ-F-109 | Template saves, key add, rename and delete each run in a single database transaction, so keys and template cannot end up half-updated. Deleting a key also removes its line from the template; renaming rewrites it. |
| REQ-F-107 | The written `.env` gets the mode set by `ENV_FILE_MODE` (`0640` in `config.php.example`; `0644` when the constant is absent, so existing installs are unchanged until they opt in) and is excluded from the 0644 permission sweep. Value fields in the UI are masked (`type=password`) until revealed. |
| REQ-F-120 | The .env Manager shows a **Live .env** drift check: the target's `.env` compared with what the manager would write, as `In sync` / `Drift` / `No file` / `Unreadable`. Differences are listed by key name only (different value / managed but missing / in the file but unmanaged) — never values. |
| REQ-F-121 | **Apply now** writes the managed `.env` (mode per `ENV_FILE_MODE`) without a deploy, optionally followed by a stored per-project command (`projects.env_apply_cmd`, one per line, `HOOK_TIMEOUT` applies). It runs the same preflight as a deploy, is recorded as a deployment row with `mode = 'env_apply'` (audit trail + logs), and is refused if managed mode is off, the target directory is missing, or a deployment is running. A failing command marks the record failed and says the `.env` was already written. |
| REQ-F-122 | **Pull from live** imports the target's current `.env` (≤ 1 MB) with its values. It never deletes: unknown keys are added, empty stored values filled, the live file's structure becomes the template if the project has none, and every stored key stays listed in the template. If the live file holds a different value for a key that already has one, nothing is imported until the user chooses *replace* or *keep mine*. |
| REQ-F-123 | The stored template never contains values: it is scrubbed (`KEY=secret` → `KEY=`) on every save, so secrets exist only encrypted in `project_env_vars`. Templates saved by earlier versions are scrubbed automatically by migration 006 (a banner offers a manual scrub as a fallback). Scrubbing never changes the deployed file: a stored value always wins over the template, so only values for keys with no stored row are saved (encrypted) first, and cleared values are not resurrected. |
| REQ-F-124 | **Bulk edit** shows every value as `KEY=value` text and applies it: listed keys are updated (empty clears), new keys added, unlisted keys untouched. |
| REQ-F-125 | The Values table can be **filtered** by key name and **reordered** (drag with a mouse; ▲/▼ buttons everywhere). Saving persists the order and reorders the template to match — each key moves with the comment lines directly above it. |
| REQ-F-126 | **Copy from another project** merges that project's keys, values, required flags and structure; existing non-empty values are kept unless *overwrite* is ticked. Nothing is deleted. |
| REQ-F-127 | **Encrypted export/import**: a passphrase-protected JSON bundle (PBKDF2-SHA256 200 000 iterations → AES-256-GCM; passphrase ≥ 10 characters on export, file ≤ 1 MB on import) carrying keys, values, required flags and the scrubbed template. Import merges like Copy. The passphrase, not `SECRET_KEY`, is the key. |
| REQ-F-128 | Values containing line breaks (e.g. PEM keys) are supported end to end: multi-line quoted values parse as one entry, are shown in the masked field with `\n` escapes (a single-line input would strip real line breaks), and are decoded only for rows the page flagged as escaped. |
| REQ-F-129 | A value containing a `${NAME}` reference raises a warning (key names only) in the .env Manager and in the deploy/apply log: CodeIgniter 4's loader expands it inside any quotes with no escape, so it can change when the app reads the file. References to another managed key are marked as certain to change. It is advisory and never blocks a deploy. |

### 2.11 Dashboard & Settings UI

| ID | Requirement |
| --- | --- |
| REQ-F-110 | The dashboard is a single page: project cards with deploy/logs/backups actions, an add/edit project form, and a terminal modal — all without full page reloads for common actions. |
| REQ-F-111 | Each project card shows a status badge (`● Live` / `⚠ Failed` / `⟳ Deploying` / `○ Never deployed`) and, when applicable, a "⚠ Deployer inside target" warning badge. |
| REQ-F-112 | `settings.php` shows a read-only installation overview (deploy strategy, terminal state, PHP extension checks, storage directory sizes, integration flags) plus two admin actions (`settings_action.php`): clear tmp, and reconcile stuck deployments. |

---

## 3. Non-Functional Requirements

| ID | Requirement |
| --- | --- |
| REQ-NF-001 | Every request to a protected endpoint verifies an active session before any other logic runs. |
| REQ-NF-002 | All state-changing POST requests validate a per-session CSRF token (`csrf_verify()`); failure returns HTTP 403. |
| REQ-NF-003 | `/app`, `/migrations`, `/storage` and `config.php` are blocked from direct web access by `.htaccess`. |
| REQ-NF-004 | Passwords are bcrypt-hashed (`password_hash`/`password_verify`) only — never encrypted or reversible. |
| REQ-NF-005 | The deployer never operates on a path not sourced from a registered project's `target_path` column. |
| REQ-NF-006 | All HTML output is escaped via `h()` (`htmlspecialchars`); all SQL uses PDO named parameters — no string-interpolated queries. |
| REQ-NF-007 | Stored `.env` values are encrypted at rest (AES-256-GCM). See SECURITY.md for the `SECRET_KEY` caveat. |
| REQ-NF-010 | The deploy pipeline runs synchronously in the triggering HTTP request — no background queue. Large repos/slow hooks may hit PHP `max_execution_time` or gateway timeouts (known limitation, not yet mitigated). |
| REQ-NF-011 | Downloads enforce `DOWNLOAD_TIMEOUT` (default 300s); hooks enforce `HOOK_TIMEOUT`; terminal commands enforce `TERMINAL_TIMEOUT`. |
| REQ-NF-012 | A deploy lock is per-project. A lock is not currently auto-released on a timestamp basis inside the pipeline itself — stuck `running` rows are cleared via the manual/CLI `deploy:reconcile` action, not an automatic timeout. |
| REQ-NF-020 | Database schema changes are additive only — `ADD COLUMN` / `CREATE TABLE IF NOT EXISTS`. No `DROP`/`RENAME`/`MODIFY` on a live table. (Also enforced by [CLAUDE.md](../CLAUDE.md) project rules.) |
| REQ-NF-021 | Business logic lives in `app/Services/*` and `app/Models/*` — public entry points under `public/` coordinate only (validate input, call a service, render/respond). |
| REQ-NF-030 | The database engine (SQLite ↔ MySQL/MariaDB) is switched via `DB_DRIVER` in `config.php` alone — no query in the codebase may use engine-specific SQL. |
| REQ-NF-031 | The application must be deployable to shared cPanel hosting by uploading files — no server-level config (php.ini, vhost) changes required for baseline operation. |

---

## 4. Constraints

| ID | Constraint |
| --- | --- |
| CON-01 | No SSH, `exec`/`system` shell-out outside of `HookRunner`'s controlled `proc_open` usage. All filesystem work goes through `FileManager` / native PHP file functions. |
| CON-02 | No external PHP framework as a runtime dependency. |
| CON-03 | No Composer requirement for the deployer itself to run (Composer is only used *by deployed projects*, resolved via `{composer}`). |
| CON-04 | `config.php` (with real credentials) must never be committed — `config.php.example` is the committed template. |
| CON-05 | Migrations must remain idempotent — `app_boot()` re-runs the full migration set on every request. |

---

## 5. Users, Roles & Permissions

There is exactly one role: **Administrator**, with unrestricted access to every function
once authenticated. There is no Viewer/read-only role, no per-project access control, and
no API-token-based access — this is a deliberate Phase 1 scope decision (see
[DECISIONS.md](DECISIONS.md)). Multi-user/roles is tracked as a future item in
[TODO.md](TODO.md) (P2).

---

## 6. Out of Scope (Current)

These are explicitly not implemented and not assumed by any requirement above:

- GitHub webhook auto-deploy (`public/webhook.php` is a stub only)
- Automatic rollback to backup on pipeline failure (manual restore only)
- Symlink-based atomic releases (config flag + detection exist; pipeline doesn't use it)
- Login rate limiting / IP allowlisting
- GitHub PAT encryption at rest (currently stored plaintext in the DB — see SECURITY.md)
- Background/queued deployment execution
- Multi-user roles, notifications, REST API, dry-run/diff viewer

Full detail and priority tiers: [TODO.md](TODO.md).

---

*End of Document — Requirements Baseline*
