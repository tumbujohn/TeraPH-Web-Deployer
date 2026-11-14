# Changelog

Meaningful changes to TeraPH Web Deployer, reconstructed from git history. There are no
tagged releases yet — entries are grouped by date. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/).

---

## Unreleased

### Added
- `BACKUP_ENCRYPT_ENV` config option (default `false`) to AES-256 password-protect `.env` files inside backup zips; restore decrypts transparently
- .env Manager **Live .env** card: drift check against the server's `.env` (key names only), **Apply now** (write the `.env` without a deploy, optionally run a stored command; recorded as an `env_apply` deployment), and **Pull from live** (import the target's current `.env`; never deletes; asks before replacing differing stored values)
- .env Manager editing tools: filter keys, drag/▲▼ reorder (also reorders the template), bulk edit as `KEY=value` text, copy from another project, and passphrase-encrypted export/import
- Migration 005 adds `projects.env_apply_cmd`
- One-click scrub for templates saved by earlier versions that still hold plaintext values

- Env preflight: managed-env deploys now fail before the download/backup/target changes if a required key is empty or a stored value cannot be decrypted
- Deploy log compares the release's `.env.example` with managed keys and warns about unmanaged or empty keys
- Env Manager flags undecryptable values (`⚠ unreadable` badge and banner)

- Env Manager asks before dropping keys: a template save or `.env.example` import that would remove existing keys now shows what would be deleted (and which hold values) and offers remove or keep

### Fixed
- The .env Manager never displayed its flash messages (the page consumed them before rendering), so errors such as "Key already exists" were silent
- Values containing line breaks (e.g. PEM keys) were flattened by the single-line value field and corrupted on the next save; they are now shown escaped (`\n`) and decoded on save, and multi-line quoted values in imported text parse as one entry instead of producing junk keys
- The stored env template kept pasted values in plaintext and re-saving it could overwrite newer values with stale ones; values are now scrubbed from the template on every save

### Changed
- Managed `.env` values containing `${` are written single-quoted so phpdotenv no longer expands them (see DECISIONS.md for the CI4 caveat); all other quoting is unchanged
- Env template saves and key add/rename/delete are atomic (single transaction)
- Deleting a key now also removes its template line, so it no longer reappears on the next template save or leaves a placeholder in the deployed `.env`; renaming handles `export`/spaced assignments and names containing `$`
- Required-key check moved from after the target was cleared to before any deploy work (previously a missing required key left the live site with new code and no `.env`)
- Re-saving a template or re-importing `.env.example` no longer resets keys' required flags
- Env Manager value fields are masked by default (`type=password`, `autocomplete=new-password`)
- The managed `.env` is written with mode `0640` instead of `0644`
- Backup restore now extracts and validates the archive before clearing the target, and extraction failures throw instead of being ignored

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
