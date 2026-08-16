# Changelog

Meaningful changes to TeraPH Web Deployer, reconstructed from git history. There are no
tagged releases yet — entries are grouped by date. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/).

---

## 2026-06-15 — File Safety, Env Manager Completion, Project Deletion

### Added
- Per-project `.env` manager: template/value parsing supporting `export` prefixes and a
  wide range of key formats, AES-based value encryption
- CodeIgniter deploy template now creates required directories before `spark migrate` runs
- Safe-keep directories are now guaranteed to exist before hooks run (fixes first-deploy
  hook failures against `uploads/`, `writable/`, etc.)
- `HookRunner::buildEnv()` — ensures `HOME`/`COMPOSER_HOME` are set for `proc_open`, fixing
  Composer failures under web-server processes that run without `HOME`
- Installation probe (`scripts/probe.php`) and database reset script (`scripts/reset_db.php`)
- Hardened `.gitignore` and `.htaccess` rules (improved HTTPS enforcement)

### Changed
- Migration handling and terminal-enablement flow improved
- Deployment process now handles permissions more robustly alongside safe-keep guarantees
- Project deletion now removes the project's environment variables while explicitly
  retaining its deployments and deployment logs for audit continuity

### Fixed
- **File deletion / symlink safety** — `FileManager::clear()` and `deleteDirectory()` now
  unlink symlinks via their own path instead of resolving through the link target,
  preventing accidental deletion outside the webroot (see
  [DECISIONS.md](DECISIONS.md#symlinks-are-unlinked-by-path-never-resolved-through))
- Configuration and database files removed from version control (should never have been
  committed)

### Security
- `.htaccess` rules updated for improved security and HTTPS enforcement

---

## 2026-06-14 — Hooks, Bootstrap Installer, Env Manager (Initial)

### Added
- `HookRunner` service — executes deployment hooks and terminal commands through a single
  shared `proc_open` wrapper
- Single-file bootstrap installer (`scripts/install.php`) for SSH-less setup
- Initial per-project `.env` variable management

---

## 2026-06-10 — Self-Update & Deployment Streamlining

### Added
- Self-update functionality (`public/update.php`) — downloads and applies the latest code
  from GitHub with backup-first extraction

### Changed
- Deployment process streamlined: removed a stash-based intermediate step, enhanced direct
  file-copy logic

---

## 2026-04-21 — Initial Implementation

### Added
- Core deployment engine: `DeployService`, `BackupService`, session-based `Auth`, project
  management models
- Manual zip-upload support as an alternative project source type
- `tera` CLI utility with `clear:tmp` / `clear:backups` / `clear:logs` storage sweeps
- Browser-based migration runner (`migrate.php`) for hosts without terminal access
- Live console/log streaming in the deploy modal; deploy modal no longer closes on
  backdrop click mid-deployment

### Changed
- Security restrictions consolidated; transparent routing to the `public/` directory

---

## How to Use This File

Add a new dated section at the top when a meaningful change ships — a feature, a fix that
affects users, or a security-relevant change. Skip purely internal refactors with no
observable behavior change unless they're safety-relevant (like the symlink deletion fix
above). Categories: **Added**, **Changed**, **Fixed**, **Removed**, **Security**.
