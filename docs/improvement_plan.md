# TeraPH Web Deployer — Improvement & Hardening Plan

**Document Type:** Engineering Roadmap & Improvement Plan
**Status:** Active
**Version:** 0.2.0
**Date:** 2026-06-14
**Related:** [concept_draft.md](concept_draft.md), [SRS.md](SRS.md), [../README.md](../README.md)

---

## 0. Strategic Framing

The product occupies the **"best deploy panel for cPanel / shared PHP hosting"** niche.
That is a strong, defensible position. The goal is to adopt the *engineering standards* of
larger tools (Envoyer, Deployer, Forge) — atomic releases, deploy hooks, robust terminal —
without their full feature surface (multi-server orchestration, fleet management).

### Resolved decisions

| Decision | Resolution |
| --- | --- |
| Terminal: keep or remove? | **Keep and enhance.** Terminal is a deliberate feature, not a bug. |
| Symlinks on cPanel? | **Yes, with auto-detection + copy-mode fallback.** |
| Multi-user scope? | Single admin login for now; role model is P2. |
| DB engine? | SQLite default; MySQL remains optional via config. |

---

## 1. Priority Tiers

| Tier | Theme | Items |
| --- | --- | --- |
| **PRAC** | Practical blockers (do first) | Self-protection, deploy strategy auto-detect, enhanced terminal + composer.phar, deployment templates |
| **P0** | Correctness & safety | Atomic symlink releases, auto-rollback, login throttling, PAT encryption, secure cookies |
| **P1** | Standard tool | Background queue, migration tracking, atomic lock, webhooks, health checks |
| **P2** | Competitive / professional | Notifications, REST API, env-var manager, diff viewer, SSE logs, multi-user + audit log |
| **ENG** | Engineering practice | Test suite, static analysis + CI, namespaces/autoloader, self-updater hardening |

---

## 2. PRAC — Practical Blockers (Work On First)

### PRAC.1 — Deployer self-protection (co-located deployment guard)

**Problem.** The deployer may be installed inside or alongside the application being
deployed. For example:

```text
/home/user/public_html/
    myapp/              ← target_path for project "myapp"
    deployer/           ← the deployer itself (sibling)
```

or even:

```text
/home/user/public_html/   ← target_path (deploying the whole webroot)
    deployer/             ← deployer lives inside the target — would self-delete
```

There is no protection today. A misconfigured project can wipe the deployer in the clear
step, taking down the only tool that can fix it.

**Design.**

#### (a) DEPLOYER_ROOT constant

Add to `config.php.example` and auto-derive in bootstrap:

```php
// config.php.example
define('DEPLOYER_ROOT', dirname(__DIR__));  // absolute path to deployer root
```

#### (b) General Settings page

New `public/settings.php` — installation-wide settings, not per-project. Phase 1 exposes:

- Deployer root path (read-only, derived from `DEPLOYER_ROOT`).
- Deployment strategy (see PRAC.2).
- Terminal enabled toggle.

#### (c) Self-deploy detection in `DeployService`

Before the clear step, check whether the deployer root falls inside the target path:

```text
isDeployerInsideTarget($targetPath, $deployerRoot):
    realpath($deployerRoot) starts with realpath($targetPath) . '/'
```

If true:

1. Compute the relative path of the deployer root within the target (e.g. `deployer/`).
2. **Auto-inject** that relative path into `$skipPaths` for both `clear()` and
   `copyContents()` — same mechanism already used for `safe_keep` paths.
3. Log a `[WARNING] Deployer is inside target — skipping: deployer/` entry in the
   deployment log so the operator knows it was detected.

#### (d) UI warning badge

When a project's `target_path` would trigger self-deploy detection, show a yellow warning
badge on the project card: *"Deployer overlap detected — self-protection active"*.

#### (e) Backup exclusion

`BackupService` must also skip the deployer directory so the backup does not include the
deployer's own `storage/` tree (which may be large and recursive).

**Files affected:** `DeployService.php`, `BackupService.php`, `app/helpers.php` (add
`is_deployer_inside_target()` helper), `public/index.php` (card badge), new
`public/settings.php`.

---

### PRAC.2 — Deployment strategy: symlink with auto-detection + copy fallback

**Problem.** Symlinks are the correct mechanism for atomic, zero-downtime deploys, but not
all shared hosts support them (or the webroot may not allow `symlink()` to resolve). The
strategy must be configurable and self-diagnosing.

#### Config

```php
// config.php.example
// 'auto'    → test symlink capability on first deploy; cache result
// 'symlink' → force symlink mode (fail hard if not supported)
// 'copy'    → always use copy-in-place (today's behaviour, enhanced)
define('DEPLOY_STRATEGY', 'auto');
```

#### Auto-detection logic

Run once per installation; result cached in `storage/deploy_strategy.txt`:

1. Attempt `symlink(storage/tmp/.symtest_target, storage/tmp/.symtest_link)`.
2. If `symlink()` succeeds and `is_link()` confirms → cache `'symlink'`.
3. Else → cache `'copy'`.
4. Clean up test files.

#### Symlink strategy

See also P0.1 — the full architectural change:

```text
target/
  releases/20260614_120000/    ← extracted + built here, never live until activated
  releases/20260614_130000/    ← next release
  shared/                      ← .env, uploads, storage (persistent, symlinked into each release)
  current -> releases/...      ← atomic swap; webroot points here
```

#### Copy strategy (enhanced in-place)

- Same as today but with robust `safe_keep` skip logic (already fixed).
- Auto-rollback from backup on failure (see P0.2).
- Self-protection guard (PRAC.1) applied.

`DEPLOY_STRATEGY` drives which strategy `DeployService` instantiates — implement as a
`DeployStrategy` interface with two concrete implementations so the pipeline code stays
unchanged.

---

### PRAC.3 — Enhanced terminal with Composer.phar support

**Decision: keep the terminal.** It is a deliberate feature for operators who need an
in-browser shell scoped to a specific project's directory. The goal is to make it *more*
robust and better controlled, not to remove it.

#### Composer mode

Many shared hosts restrict the global `composer` binary. The terminal needs to transparently
handle this via `composer.phar`.

**Auto-detection order** (evaluated each time a composer command is run):

1. Check `{target_path}/composer.phar` — if found, use `php composer.phar`.
2. Check global `composer` or `composer.phar` in `PATH`.
3. If neither found, show a "Download Composer" button.

**"Download Composer" button in the terminal UI:**

- Fetches `https://getcomposer.org/composer-stable.phar` into `{target_path}/composer.phar`
  via cURL (same pattern as the zip downloader).
- Confirms SHA256 against the published hash before saving.
- One-click, no SSH required.

**Composer shortcut commands in the terminal UI toolbar:**

Quick-action buttons scoped to the selected project. These inject the correct command
string into the terminal input and execute it — they are not hardcoded shell calls.

- `composer install --no-dev` (auto-selects phar or global).
- `composer update --no-dev`.
- `composer dump-autoload --optimize`.

#### Terminal hardening

| Control | Implementation |
| --- | --- |
| CWD lock | Working directory is always `target_path`. Confirm `..` traversal is rejected. |
| Command audit log | Every command, exit code, user, IP, and timestamp written to `deployment_logs` with `level = 'TERMINAL'`. |
| Config toggle | `TERMINAL_ENABLED = true` in `config.php` — operators can disable the terminal site-wide. |
| Per-project toggle | `terminal_enabled` boolean column on `projects` table (migration). |
| `..` traversal guard | Strip or reject commands containing `../` targeting paths above the project root. |
| Timeout | Already has 10-minute max; make configurable via `TERMINAL_TIMEOUT` constant. |

**Files affected:** `public/terminal_execute.php`, `public/assets/js/app.js` (toolbar UI),
`public/assets/css/app.css`, `app/Models/Project.php`, new migration.

---

### PRAC.4 — Deployment templates (framework presets)

**Problem.** Every real PHP app needs post-deploy build steps (`composer install`,
`artisan migrate`, cache clear). Today there is no way to configure these per project.
Operators run them manually in the terminal after every deploy — error-prone and forgettable.

#### (a) Schema

Additive migration:

```sql
ALTER TABLE projects ADD COLUMN deploy_template  VARCHAR(30) DEFAULT 'none';
ALTER TABLE projects ADD COLUMN pre_deploy_hooks  TEXT;   -- JSON array of command strings
ALTER TABLE projects ADD COLUMN post_deploy_hooks TEXT;   -- JSON array of command strings
```

#### (b) Built-in templates

Pre-filled hook sets the operator can adopt as-is or customize:

| Template | `pre_deploy_hooks` | `post_deploy_hooks` |
| --- | --- | --- |
| `none` | `[]` | `[]` |
| `laravel` | `["php {composer} install --no-dev --optimize-autoloader"]` | `["php artisan migrate --force", "php artisan config:cache", "php artisan route:cache", "php artisan view:cache", "php artisan storage:link"]` |
| `codeigniter` | `["php {composer} install --no-dev"]` | `[]` |
| `wordpress` | `[]` | `["chmod -R 755 wp-content/uploads", "chmod 644 wp-config.php"]` |
| `custom` | (user-defined) | (user-defined) |

`{composer}` is a placeholder resolved at runtime to `composer.phar` or `composer`
using the same auto-detection logic from PRAC.3.

#### (c) Project form UI

- New "Deployment Template" dropdown in the Add/Edit project form.
- Selecting a template **pre-fills** `pre_deploy_hooks` and `post_deploy_hooks` text areas
  (editable — the template is a starting point, not a lock).
- Selecting `custom` leaves the fields blank for full manual entry.

#### (d) Pipeline integration

New hook-runner steps added to `DeployService`:

```text
Step 7:  Copy new files → target
Step 7a: Run pre_deploy_hooks in target directory  ← NEW
Step 8:  Set file permissions
Step 8a: Run post_deploy_hooks in target directory ← NEW
Step 9:  Mark success / release lock
```

Each hook command is executed via `proc_open` with stdout/stderr streamed to
`DeploymentLog`. On non-zero exit code, the pipeline halts and marks `failed`.

#### (e) HookRunner service

Extract shared command execution from `terminal_execute.php` into a new
`app/Services/HookRunner.php`:

```php
class HookRunner
{
    public function run(string $command, string $cwd, int $deploymentId): int { ... }
    public function resolveComposer(string $cwd): string { ... } // 'php composer.phar' or 'composer'
}
```

`terminal_execute.php` and `DeployService` both use `HookRunner` — no duplicated
`proc_open` logic.

**Files affected:** new `app/Services/HookRunner.php`, `app/Services/DeployService.php`,
`app/Models/Project.php`, `public/projects.php`, `public/assets/js/app.js` (form JS),
new migration.

---

## 3. P0 — Correctness & Safety

### P0.1 — Atomic symlink-based releases

Full architecture specified in PRAC.2. This is the deeper implementation once the
copy-mode guard (PRAC.1) and strategy abstraction (PRAC.2) are in place. Requires a
separate design doc before coding.

**Pipeline target:**

1. Extract into `releases/{timestamp}/`.
2. Symlink `shared/*` paths into the release.
3. Run `pre_deploy_hooks` (from PRAC.4).
4. Optional health check.
5. Atomic `current` symlink swap.
6. Run `post_deploy_hooks`.
7. Prune old releases beyond `MAX_RELEASES` count.
8. Rollback = repoint symlink to previous release (instant, no zip restore).

### P0.2 — Automatic rollback on failure

- **Symlink mode:** live `current` is untouched until the swap; failed release dir is deleted.
- **Copy mode:** catch block calls `BackupService::restore()` automatically — not just logs the failure.

### P0.3 — Terminal command audit log

Covered under PRAC.3 hardening. Every terminal command is written to `deployment_logs`
with `level = 'TERMINAL'` including user, IP, exit code.

### P0.4 — Login brute-force protection + secure cookies

- Track failed login attempts by IP in a `login_attempts` table (additive migration).
- Lockout after `LOGIN_MAX_ATTEMPTS` (default: 10) failures within `LOGIN_LOCKOUT_WINDOW` (default: 15 min).
- Set `cookie_secure => !DEV_MODE` in session config ([helpers.php:29-32](../app/helpers.php#L29-L32)).

### P0.5 — Encrypt GitHub PATs at rest

- libsodium `sodium_crypto_secretbox` with a key stored in `config.php` (never in DB).
- Encrypt on `Project::create/update`, decrypt on `GitHubService::download`.
- Additive — existing plaintext PATs are encrypted on next save.

---

## 4. P1 — Standard Tool

### P1.1 — Background / queued deployment execution

Deploys run synchronously in the web request. Large repos + slow builds hit PHP/gateway
timeouts. Fix: enqueue → detached background process runs it → UI polls status (already
exists).

### P1.2 — Migration runner with version tracking

Add a `schema_migrations` table; runner skips already-applied versions. Removes the
"run on every request" hack in `app_boot()`.

### P1.3 — Atomic lock acquisition

Replace the check-then-act `isLocked()` + `create()` sequence with an atomic unique-key
`INSERT` or `flock()` per-project lockfile.

### P1.4 — Webhook auto-deploy

Implement [webhook.php](../public/webhook.php) stub with `X-Hub-Signature-256` HMAC
verification, per-project secret, and branch filtering.

### P1.5 — Post-deploy health check

Before marking `success`, GET a configurable health URL; auto-rollback if not HTTP 200.

---

## 5. P2 — Competitive / Professional

- **Notifications** — Slack / Discord / Telegram / email on deploy success or failure.
- **REST API + deploy tokens** — trigger deploys from external CI without the session UI.
- **Environment-variable manager** — edit `shared/.env` in-browser; write to shared path.
- **Deploy history + diff / dry-run viewer** — list changed files before committing a deploy.
- **Real-time log streaming via SSE** — replace polling with Server-Sent Events.
- **Multi-user + roles + audit log** — Admin / Deployer / Viewer with full login + action trail.

---

## 6. ENG — Engineering Practice

- **Test suite (PHPUnit):** cover `FileManager` skip-path logic, `GitHubService` URL parsing, `HookRunner`, and deploy pipeline happy + failure paths.
- **Static analysis + CI:** PHPStan/Psalm + `php -l` lint + tests in GitHub Actions.
- **Namespaces + PSR-4 autoloader:** replace manual `require_once` in [bootstrap.php](../app/bootstrap.php) without requiring Composer on the host.
- **Self-updater hardening:** pin to tagged releases, verify SHA256 checksum before overwriting the running app.

---

## 7. Recommended Sequence

```text
Phase 1 (PRAC — do now)
├── PRAC.1  Deployer self-protection + General Settings page
├── PRAC.2  DEPLOY_STRATEGY config + auto-detect + strategy interface
├── PRAC.3  Terminal hardening + Composer.phar support + HookRunner extraction
└── PRAC.4  Deployment templates schema + project form + pipeline hook steps

Phase 2 (P0 — correctness & security)
├── P0.1    Full symlink-release architecture (design doc first)
├── P0.2    Auto-rollback (copy mode: call restore; symlink mode: free)
├── P0.3    Terminal audit log (part of PRAC.3, finalize here)
├── P0.4    Login throttling + secure cookies
└── P0.5    PAT encryption at rest

Phase 3 (P1 — standard tool)
├── P1.1    Background deployment queue
├── P1.2    Migration runner with version tracking
├── P1.3    Atomic lock acquisition
├── P1.4    Webhook auto-deploy
└── P1.5    Post-deploy health check

Phase 4 (P2 + ENG — competitive + trustworthy)
    Notifications, REST API, multi-user, SSE logs,
    tests, static analysis, CI, self-updater hardening
```

---

## 8. Open Decisions (Remaining)

- [ ] **Symlink cPanel compatibility confirmation** — test on a target host before committing
      to the full symlink-release architecture (P0.1). Copy-mode fallback is the safety net.
- [ ] **HookRunner failure behaviour** — should a failing hook (non-zero exit) always abort
      the deploy, or should individual hooks be marked optional/warning-only?
- [ ] **Composer.phar download trust** — verify SHA256 against `getcomposer.org` published
      hash before writing to disk; decide whether to prompt or auto-verify silently.
- [ ] **General Settings persistence** — store in `config.php` (requires file write) vs a
      new `settings` DB table (cleaner but adds complexity). DB table preferred.

---

End of Document — TeraPH Web Deployer Improvement & Hardening Plan v0.2.0
