# Changelog

Meaningful changes to TeraPH Web Deployer, reconstructed from git history. There are no
tagged releases yet — entries are grouped by date. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/).

---

## 2026-11-14 — .env Manager live tools, safer env handling, upgrade compatibility

### Upgrade notes
Updating an existing install is safe with its old `config.php` and database; migrations 005 and 006 run
automatically on the next page load (migration 007 as well, for the per-project PHP binary). Things worth knowing:
- **Hooks may now run a different PHP than before.** If the `php` on your web server's `PATH` was a different
  version than the one running the site, hooks, Apply now and the terminal now use the site's own PHP. This is
  the intended fix for Composer version errors; if a project depended on the other PHP, set its own *PHP for
  hooks & terminal* in the project settings, or set `define('CLI_PHP_PATH', 'system');` to restore the old
  behavior everywhere.- **`.env` file mode.** The managed `.env` mode comes from the new `ENV_FILE_MODE` constant. New installs get
  `0640` from `config.php.example`; a `config.php` without the constant keeps the previous `0644`. Add
  `define('ENV_FILE_MODE', 0640);` once you've confirmed your app can still read `.env`.
- **Deploys now stop** (before anything is downloaded or changed) if a managed project has a required key left
  empty or a stored value that cannot be decrypted (for example after `SECRET_KEY` changed). Previously such a
  deploy silently wrote an empty value.
- **Stored templates are scrubbed** of plaintext values by migration 006. The deployed `.env` does not change.
- **Self-updater limitations** (unchanged; see `docs/TODO.md`). It clears files before copying the new ones
  and is not atomic. PHP's opcache can serve a mix of old and new files for a few seconds after an update
  (`opcache.revalidate_freq`, 2s by default), so the first requests may error briefly; on hosts with
  `opcache.validate_timestamps=0` it lasts until PHP is restarted or opcache is reset. On a Windows host the
  updater cannot replace its own running script and stops half-way. Take the backup it offers, update when no
  deployment is running, and give it a minute before judging the result.

### Added
- **Automatic PHP for hooks and the terminal, with per-project choice.** Hooks and the terminal run as child processes of the web server, so a bare `php` followed the web server's `PATH` — often the host's default CLI, not the PHP the site runs on (Composer then failed platform checks while Settings and SSH showed a newer version). The deployer now uses the site's own PHP CLI automatically whenever `php` on `PATH` is a different version. Override per project (new *PHP for hooks & terminal* field, suggests the binaries found on the host) or globally with `CLI_PHP_PATH` (a path, or `'system'` for the old behavior). Settings shows which PHP hooks use and why, flags a mismatch and lists the PHP binaries on the host; deploy logs state it. Migration 007 adds `projects.php_binary`
- `BACKUP_ENCRYPT_ENV` config option (default `false`) to AES-256 password-protect `.env` files inside backup zips; restore decrypts transparently
- `ENV_FILE_MODE` config option for the managed `.env` permissions (see upgrade notes)
- .env Manager **Live .env** card: drift check against the server's `.env` (key names only), **Apply now** (write the `.env` without a deploy, optionally run a stored command; recorded as an `env_apply` deployment) and **Pull from live** (import the target's current `.env`; never deletes; asks before replacing differing stored values)
- .env Manager editing tools: filter keys, drag/▲▼ reorder (also reorders the template), bulk edit as `KEY=value` text, copy from another project, and passphrase-encrypted export/import
- Env preflight: managed-env deploys fail before the download/backup/target changes if a required key is empty or a stored value cannot be decrypted
- Deploy log compares the release's `.env.example` with managed keys and warns about unmanaged or empty keys
- Env Manager flags undecryptable values (`⚠ unreadable` badge and banner) and asks before dropping keys when a template save or `.env.example` import would remove existing ones
- Warning when a value contains `${NAME}`: shown in the .env Manager and logged on deploy/apply, because CodeIgniter 4's loader expands it inside any quotes and cannot be told not to
- Migration 005 adds `projects.env_apply_cmd`; migration 006 scrubs plaintext values out of templates saved by earlier versions (the deployed `.env` is unchanged because only values the template alone supplied are saved first; a one-click banner remains as a fallback)

### Fixed
- Laravel hooks failed with "Please provide a valid cache path" on a first deploy: with the default `storage/` Safe-keep the repo's `storage/` skeleton is skipped, so `storage/framework/views` didn't exist and Laravel's `realpath()` of it returned `false`. A new deploy step (7d) creates the runtime folders a recognised framework needs (Laravel `storage/…` and `bootstrap/cache`, CodeIgniter `writable/…`, Symfony `var/…`) before hooks run, whether the framework is set as the Deploy Template or detected from the project's files; only missing folders are created
- Backup restore (and any extraction) unwrapped an archive that had exactly one top-level folder even when files sat beside it, so restoring such a backup lost the top-level files and `.env`; a wrapper is now stripped only when it is the sole entry, and restore never strips
- Values containing line breaks (e.g. PEM keys) were flattened by the single-line value field and corrupted on the next save; they are now shown escaped (`\n`) and decoded on save, and multi-line quoted values in imported text parse as one entry instead of producing junk keys
- Multi-line quoted value parsing no longer swallows keys after a typo such as `KEY="unterminated` followed by ordinary `KEY=value` lines (templates saved by older versions can contain these); such a quote is treated as a typo and read line-by-line like before
- The stored env template kept pasted values in plaintext and re-saving it could overwrite newer values with stale ones; values are now scrubbed from the template on every save
- The .env Manager never displayed its flash messages (the page consumed them before rendering), so errors such as "Key already exists" were silent
- Topbar overflowed phone viewports by ~20px (brand name wrapped, action buttons ran off-screen) on every page

### Changed
- The dashboard status badge and "last deployed" time ignore **Apply now** records, so applying or failing to apply an env change no longer makes a project look redeployed or failed
- Required-key check moved from after the target was cleared to before any deploy work (previously a missing required key left the live site with new code and no `.env`)
- Managed `.env` values containing `${` are written single-quoted so phpdotenv no longer expands them (see DECISIONS.md for the CI4 caveat); all other quoting is unchanged
- Env template saves and key add/rename/delete are atomic (single transaction); deleting a key also removes its template line, and renaming handles `export`/spaced assignments and names containing `$`
- Re-saving a template or re-importing `.env.example` no longer resets keys' required flags
- Env Manager value fields are masked by default (`type=password`, `autocomplete=new-password`)
- Backup restore extracts and validates the archive before clearing the target, and extraction failures throw instead of being ignored
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
