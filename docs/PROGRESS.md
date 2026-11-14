# Progress

Current implementation state of TeraPH Web Deployer, kept in sync with the codebase. For
what's planned next, see [TODO.md](TODO.md). For why things were built this way, see
[DECISIONS.md](DECISIONS.md).

**Last updated:** 2026-11-14 (documentation pass — reconciled against `git log` through
commit `1444172`).

---

## Overall Status

The core deploy pipeline, project management, backups, logging, GitHub integration, and
dashboard (SRS Phase 1 scope) are complete and in daily use. Beyond that original scope,
four substantial feature sets have since shipped: manual zip-upload source, deploy
hooks/templates, a web terminal, and a full per-project `.env` manager (since extended with
live-file tools and bulk editing). The product is
pre-P0 on the [improvement plan](archive/improvement_plan.md)'s hardening tier — meaning
atomic rollback, PAT encryption, and login throttling are not yet in place.

---

## Completed

### Core Pipeline (original Phase 1 scope)

- [x] Session-based auth with idle timeout, CSRF on all state-changing requests
- [x] Project CRUD (GitHub source)
- [x] Deploy pipeline: download → extract → validate → backup → clear → copy → permissions → log
- [x] Safe mode (safe_keep preservation) and Full mode (with UI warning)
- [x] Per-project deploy lock
- [x] Backup creation, listing, and restore (with pre-restore safety snapshot)
- [x] `MAX_BACKUPS_PER_PROJECT` retention enforcement
- [x] DB-backed + flat-file deployment logging
- [x] GitHub zip/API download with per-project or global PAT
- [x] Single-page dashboard with status badges, deploy confirmation modal, live log polling
- [x] SQLite (default) / MySQL portability via `DB_DRIVER`
- [x] CLI (`tera`): serve, migrate, make:password, github:test, deploy:reconcile, clear:*

### PRAC Tier (see [archive/prac_tracker.md](archive/prac_tracker.md))

- [x] **Self-protection** — deployer-inside-target detection, auto-skip during
      clear/copy/backup, dashboard warning badge, `settings.php`
- [x] **Deploy strategy detection** — `DEPLOY_STRATEGY` config + symlink capability
      auto-test + caching (detection only — pipeline still always copies, see TODO.md P0.1)
- [x] **Enhanced terminal** — composer.phar auto-detect/download with SHA-256 verification,
      shortcut toolbar, per-project + site-wide enable toggle, command audit log
- [x] **Deployment templates** — laravel/codeigniter/wordpress/symfony presets, pre/post
      hook storage and execution via `HookRunner`

### Beyond Original Scope

- [x] **Manual zip-upload source** (`source_type = manual`) as an alternative to GitHub
- [x] **Per-project .env manager** — template/value split, AES-256-GCM encryption at rest,
      required-key validation, deploy-time rendering, masked preview
- [x] **.env Manager live-file tools** — drift check against the server's `.env`, Apply now
      (write without a deploy, optional stored command, recorded as an `env_apply`
      deployment), Pull from live (never deletes; confirms before replacing values)
- [x] **.env Manager editing tools** — key filter, drag/▲▼ reorder (reorders the template),
      bulk edit as text, copy from another project, passphrase-encrypted export/import
- [x] **.env safety hardening** — preflight before download/backup (empty required keys,
      undecryptable values), release `.env.example` comparison, atomic template/key
      operations, confirmation before dropping keys, structure-only templates (migration 006
      scrubs older ones), multi-line value support, `${NAME}` warning for CI4, `0640` file mode
- [x] **Optional backup `.env` encryption** (`BACKUP_ENCRYPT_ENV`, default off)
- [x] **Backup restore layout fix** — `extract()` unwraps a folder only when it is the sole
      archive entry; restore never unwraps
- [x] **General Settings page** — install overview, storage stats, extension checks,
      one-click clear-tmp and deploy-reconcile actions
- [x] **Project deletion refinement** — deleting a project now removes its `project_env_vars`
      but explicitly retains `deployments`/`deployment_logs` for audit continuity
- [x] **File deletion / symlink safety hardening** — `FileManager::clear()` and
      `deleteDirectory()` now unlink symlinks via their own path rather than resolving
      through the link target, preventing accidental deletion outside the webroot
- [x] **Safe-keep directory guarantee** — missing `safe_keep` directories (e.g. `uploads/`,
      `writable/`) are created before hooks run, fixing first-deploy hook failures
- [x] **CodeIgniter template hardening** — pre-deploy commands now ensure required
      directories exist before `spark migrate` runs
- [x] Bootstrap installer (`scripts/install.php`) for SSH-less setup
- [x] CLI-only maintenance scripts: `scripts/probe.php` (install diagnostics),
      `scripts/reset_db.php` (full data wipe for clean testing)

---

## Not Yet Implemented

Tracked in detail in [TODO.md](TODO.md). Headline gaps:

- Atomic symlink-based releases (config/detection exist; pipeline doesn't use them)
- Automatic rollback to backup on deploy failure (manual restore only today)
- GitHub PAT encryption at rest (currently stored plaintext in the DB)
- Login brute-force protection / secure-cookie hardening
- Background/queued deployment execution (pipeline is synchronous in the HTTP request)
- GitHub webhook auto-deploy (`webhook.php` is a stub)
- Automated test suite / CI / static analysis
- Multi-user roles, notifications, REST API, dry-run/diff viewer

---

## Current Milestone

No active development milestone is in flight as of this documentation pass — the codebase
is in a stable, deployed-and-used state. The next priority tier (per
[archive/improvement_plan.md](archive/improvement_plan.md)) is **P0 — Correctness &
Safety**: atomic rollback, login throttling, PAT encryption.

---

*Update this file after meaningful implementation milestones — not for every commit.*
