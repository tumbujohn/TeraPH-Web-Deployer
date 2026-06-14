# TeraPH Web Deployer

A self-hosted, lightweight deployment panel for PHP applications on shared cPanel hosting.
Deploy registered web projects from GitHub (or manual zip uploads) to your server — safely, traceably, and reversibly — with no SSH or external CI/CD required.

---

The Goal: TeraPH WD should be the best deploy panel for shared/cPanel PHP hosting and a perfect fit for VPS too

---

## Table of Contents

1. [Requirements](#requirements)
2. [Quick Start (Local Dev)](#quick-start-local-dev)
3. [Production Installation](#production-installation)
4. [Bootstrap Installer](#bootstrap-installer)
5. [Configuration Reference](#configuration-reference)
6. [GitHub PAT Setup](#github-pat-setup)
7. [Adding Your First Project](#adding-your-first-project)
8. [Deployment Modes](#deployment-modes)
9. [Deploy Templates](#deploy-templates)
10. [Deploy Hooks](#deploy-hooks)
11. [Web Terminal](#web-terminal)
12. [Deployer Self-Protection](#deployer-self-protection)
13. [Deploy Strategy](#deploy-strategy)
14. [General Settings](#general-settings)
15. [CLI Reference](#cli-reference)
16. [Project Structure](#project-structure)
17. [Troubleshooting](#troubleshooting)
18. [Security Checklist](#security-checklist)

---

## Requirements

| Requirement | Minimum |
| --- | --- |
| PHP | **8.1+** |
| PHP Extensions | `pdo`, `pdo_sqlite` or `pdo_mysql`, `zip`, `curl` |
| Web Server | Apache with `mod_rewrite` + `.htaccess` support |
| Hosting | Any cPanel shared hosting, or a local PHP environment |

Verify your extensions are present:

```bash
php -m | grep -E "pdo|zip|curl"
```

---

## Quick Start (Local Dev)

### 1. Clone or copy the project

```bash
cd /your/projects/folder
# Copy or extract TeraPH Web Deployer here
```

### 2. Create your config file

```bash
cp config.php.example config.php
```

### 3. Generate a password hash

```bash
php tera make:password yourpassword
```

Copy the output hash — you will paste it into `config.php` next.

### 4. Edit `config.php`

Open `config.php` and update the following values at minimum:

```php
define('APP_PASSWORD_HASH', 'paste-your-hash-here');

// For local dev, SSL verification is disabled by default:
define('DEV_MODE', true);
```

See the full [Configuration Reference](#configuration-reference) below for all options.

### 5. Run the development server

```bash
php tera serve
# Or on a custom port:
php tera serve 8080
```

### 6. Open in your browser

```text
http://localhost:8000
```

Log in with:

- **Username:** `admin` (set by `APP_USERNAME` in `config.php`)
- **Password:** whatever you used in step 3

> The database and storage directories are created automatically on first login.

---

## Production Installation

### 1. Upload files

Upload the entire project to a **private path** on your server, outside `public_html`:

```text
/home/youraccount/deployer/        ← project root
/home/youraccount/deployer/public  ← point your (sub)domain here
```

> Do **not** place the root inside `public_html` without additional server-level protection.

### 2. Point your subdomain

In cPanel → **Subdomains** or **Addon Domains**, set the document root for your deploy panel
subdomain (e.g. `deploy.yourdomain.com`) to:

```text
/home/youraccount/deployer/public
```

### 3. Configure for production

```php
// config.php
define('DEV_MODE',        false);    // ← must be false in production
define('CURL_SSL_VERIFY', true);     // ← SSL verification enabled
define('DB_DRIVER',       'sqlite'); // or 'mysql'
define('APP_PASSWORD_HASH', '...');  // generate with: php tera make:password
```

### 4. Run database migrations

Via SSH:

```bash
php tera migrate
```

Via browser (when SSH isn't available):
Navigate to `https://deploy.yourdomain.com/migrate.php`. It will prompt for your password, run all pending migrations, and print the output.

### 5. Log in and add projects

Open `https://deploy.yourdomain.com` and log in.

---

## Bootstrap Installer

If you don't have SSH access or want a faster browser-based setup, use the single-file bootstrap installer. Drop it anywhere web-accessible, visit it in a browser, and it downloads + configures the deployer for you.

### How to use

1. Download `scripts/install.php` from this repository
2. Upload it to your server — for example, `public_html/install.php`
3. Visit `https://yourdomain.com/install.php` in a browser
4. Fill in the form:
   - **Install Directory** — absolute server path where the deployer will be extracted (default: a `deployer/` folder next to the installer)
   - **Dashboard URL** — auto-detected, correct it if needed
   - **Admin Username** and **Password**
5. Click **Download & Install**
6. When done, click **Delete install.php from server** — the installer has no auth protection and must be removed

### What the installer does

1. Downloads the latest deployer zip from GitHub (`main` branch)
2. Extracts and copies all files to the chosen install directory
3. Generates `config.php` with your credentials — `DEV_MODE` off, SSL verification on
4. Creates all `storage/` subdirectories and blocks direct web access to them
5. Database migrations run automatically on your first login

> **Security:** `install.php` has no authentication. Delete it immediately after installation — the installer page itself offers a one-click delete button.

---

## Configuration Reference

All configuration lives in `config.php`. Copy from `config.php.example` and edit.
**Never commit `config.php` to version control** — it is gitignored by default.

---

### Application

```php
define('APP_NAME', 'TeraPH Deployer');  // Panel title shown in the UI
define('APP_URL',  '');                  // Full URL to /public/ (used for links, optional)
```

---

### Authentication

```php
define('APP_USERNAME',      'admin');   // Login username
define('APP_PASSWORD_HASH', '...');     // bcrypt hash — generate with: php tera make:password
```

Generate a hash any time:

```bash
php tera make:password yournewpassword
```

---

### Session

```php
define('SESSION_NAME',    'tera_deployer_session');
define('SESSION_TIMEOUT',  3600);   // Idle timeout in seconds (default: 1 hour)
```

---

### Database

The default is SQLite — zero setup required:

```php
define('DB_DRIVER', 'sqlite');
define('DB_PATH',   __DIR__ . '/storage/deployer.db');
```

To switch to MySQL / MariaDB:

```php
define('DB_DRIVER', 'mysql');
define('DB_HOST',   'localhost');
define('DB_PORT',   '3306');
define('DB_NAME',   'deployer');
define('DB_USER',   'root');
define('DB_PASS',   'yourpassword');
```

---

### Storage Paths

```php
define('STORAGE_PATH', __DIR__ . '/storage');
define('BACKUP_PATH',  __DIR__ . '/storage/backups');
define('TMP_PATH',     __DIR__ . '/storage/tmp');
define('LOG_PATH',     __DIR__ . '/storage/logs');
```

All of these are blocked from public web access via `.htaccess`.

---

### Deployment Settings

```php
define('MAX_BACKUPS_PER_PROJECT', 10);   // Oldest backups auto-deleted past this count
define('DOWNLOAD_TIMEOUT',        300);  // Max seconds to wait for GitHub zip download
define('LOCK_TIMEOUT',            1200); // Seconds before a stuck deploy lock is auto-released
define('HOOK_TIMEOUT',            300);  // Max seconds a single pre/post deploy hook may run
```

---

### Strategy Setting

Controls how files are moved into the target directory on each deploy.

```php
// 'auto'    → test symlink capability on first deploy, cache the result
// 'symlink' → force atomic symlink-based releases (requires host support)
// 'copy'    → always use in-place file copy (safe fallback for shared hosting)
define('DEPLOY_STRATEGY', 'auto');
```

In `auto` mode the result of a one-time symlink test is cached in `storage/deploy_strategy.txt`.
See [Deploy Strategy](#deploy-strategy) for details.

---

### Deployer Root (Self-Protection)

```php
// Absolute path to the deployer installation root.
// Used by the self-protection guard to prevent the deployer from
// deleting itself when it lives inside a project's target directory.
define('DEPLOYER_ROOT', dirname(__DIR__));
```

See [Deployer Self-Protection](#deployer-self-protection) for details.

---

### Terminal

```php
define('TERMINAL_ENABLED', true);  // false = disable web terminal site-wide
define('TERMINAL_TIMEOUT', 600);   // Max seconds a single terminal command may run
```

---

### GitHub PAT (Global Fallback)

```php
define('GITHUB_PAT', 'ghp_xxxxxxxxxxxxxxxx');
```

A per-project PAT set in the project form takes priority over this global value.

---

### Development Mode

```php
define('DEV_MODE',        true);    // true = local dev, false = production
define('CURL_SSL_VERIFY', !DEV_MODE);
```

`DEV_MODE = true` disables SSL certificate verification in cURL. Needed on Windows
local machines where PHP does not ship with a CA certificate bundle.

> **Always set `DEV_MODE = false` before deploying to production.**

---

## GitHub PAT Setup

A Personal Access Token (PAT) allows the deployer to download zip archives from private
GitHub repositories. Two types are supported.

### Option A — Classic PAT (Simplest, Recommended)

1. Go to **GitHub → Settings** (avatar, top-right) → **Developer settings**
2. **Personal access tokens → Tokens (classic)**
3. Click **Generate new token (classic)**
4. Set an expiry and tick the **`repo`** scope
5. Click **Generate token** and copy it immediately

Classic PATs start with `ghp_`.

---

### Option B — Fine-Grained PAT

Fine-grained tokens are more secure but require precise setup.
Follow **every step exactly** — missing any one causes a `404` error.

1. Go to **GitHub → Settings → Developer settings → Fine-grained tokens**
2. Click **Generate new token**

#### Step A — Resource Owner (critical)

```text
Resource owner: [select the account/org that OWNS the repository]
```

This must match the GitHub user or org that owns the private repo.

> If the resource owner is an **organisation**, the org admin must approve your PAT at:
> `github.com/organizations/{org-name}/settings/personal-access-tokens/pending`

#### Step B — Repository access

```text
Repository access: Only select repositories → [Add] your-repo-name
```

#### Step C — Permissions

```text
Repository permissions:
  Metadata → Read-only   (auto-enabled)
  Contents → Read-only   (required for zip download)
```

Fine-grained tokens start with `github_pat_`.

---

### Verifying your PAT

```bash
php tera github:test <project-name>
```

Expected output:

```text
[1/2] Checking if PAT is valid (GET /user)...
      OK — PAT is valid. Authenticated as: yourusername

[2/2] Testing repo access (owner/repo)...
      Result: HTTP 200 -- OK

[OK] Access confirmed. Deployment should work.
```

---

## Adding Your First Project

1. Click **+ Add Project** on the dashboard
2. Fill in the form:

   | Field | Description |
   | --- | --- |
   | **Project Name** | Unique slug (letters, numbers, hyphens, underscores) |
   | **Source Type** | `GitHub Repository` or `Manual Zip Upload` |
   | **Repository URL** | Full GitHub HTTPS URL (GitHub source type only) |
   | **Branch** | Default: `main` (GitHub source type only) |
   | **Target Directory** | Absolute path on server (e.g. `/home/user/public_html/myapp`) |
   | **Safe-Keep Paths** | Comma-separated files/dirs preserved during Safe deploys (e.g. `.env, uploads/, storage/`) |
   | **GitHub PAT** | Personal Access Token — required for private repos |
   | **Deploy Template** | Pre-fills hook commands for a framework — `none`, `laravel`, `codeigniter`, `wordpress`, `symfony` |
   | **Pre-Deploy Hooks** | Commands run after files are copied, before permissions are set |
   | **Post-Deploy Hooks** | Commands run after permissions are set |
   | **Enable Terminal** | Whether the web terminal is available for this project |

3. Click **Save Project**
4. Click **▶ Deploy** on the project card
5. Select mode (`Safe` or `Full`), confirm, and monitor the live log stream
6. Click **Logs** on the project card to view the full deployment log

> **PAT tip:** Leaving the GitHub PAT field blank when editing a project **preserves the stored
> token**. The placeholder reads *"PAT saved — leave blank to keep"* to confirm this.

---

## Deployment Modes

| Mode | Behaviour | When to use |
| --- | --- | --- |
| **Safe** | Preserves files listed in *Safe-Keep Paths* (`.env`, `uploads/`, etc.), replaces only code files | Normal updates to live sites |
| **Full** | Replaces the **entire target directory** with the new code | Fresh installs, or when a clean slate is needed |

> ⚠ **Full mode permanently deletes all existing files** in the target directory. A backup is always created first, but proceed carefully.

Both modes:

- Download the repository zip from GitHub (or use the saved manual archive)
- Create a timestamped zip backup **before** touching any files
- Log every step to the database and to a flat `.log` file
- Acquire a deployment lock (prevents concurrent deploys on the same project)
- Run **pre-deploy hooks** after copying files (if configured)
- Run **post-deploy hooks** after setting permissions (if configured)
- Automatically release the lock on success **or** failure

---

## Deploy Templates

Selecting a template in the project form auto-fills the pre/post hook textareas with the standard commands for that framework. You can edit the commands after they are inserted.

| Template | Pre-Deploy Hooks | Post-Deploy Hooks |
| --- | --- | --- |
| **none** | *(empty — manual)* | *(empty — manual)* |
| **laravel** | `{composer} install --no-dev --optimize-autoloader`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`, `php artisan migrate --force` | `php artisan queue:restart`, `php artisan cache:clear` |
| **codeigniter** | `{composer} install --no-dev`, `php spark migrate` | *(empty)* |
| **wordpress** | `{composer} install --no-dev` | *(empty)* |
| **symfony** | `{composer} install --no-dev --optimize-autoloader`, `php bin/console doctrine:migrations:migrate --no-interaction`, `php bin/console cache:clear` | *(empty)* |

Switching templates in the form asks for confirmation before overwriting any hooks you have already typed.

---

## Deploy Hooks

Pre and post-deploy hooks are shell commands that run as part of the deployment pipeline, executed inside the project's target directory.

**Pre-deploy hooks** run immediately after new files are copied into the target directory.
**Post-deploy hooks** run after file permissions have been set.

If any hook exits with a non-zero code the pipeline halts and the deployment is marked failed. All hook output is streamed to the deployment log in real time.

### `{composer}` placeholder

Use `{composer}` instead of a hardcoded path in hook commands. At runtime it resolves to:

- `php composer.phar` — if a `composer.phar` exists in the project's target directory
- `composer` — if the global binary is available (`which composer` / `where composer`)
- `php composer.phar` — fallback (download it via the web terminal toolbar)

```bash
{composer} install --no-dev --optimize-autoloader
{composer} dump-autoload
```

Hook commands are stored one per line in the project form. Lines starting with `#` and blank lines are ignored.

### Timeout

Each hook command is subject to `HOOK_TIMEOUT` (default 300 seconds). Set it higher for slow composer installs on restricted hosts.

---

## Web Terminal

Every project card has a **>_ Terminal** button that opens a web-based shell running commands inside the project's target directory.

### Features

- **Shortcut toolbar** — one-click buttons for common commands (`{c} install`, `{c} dump-autoload`, `artisan migrate`, `artisan config:cache`, `artisan queue:restart`)
- **`{composer}` resolution** — type `{composer}` in any command and it resolves to the correct binary automatically
- **Download composer.phar** — the **↓ composer.phar** toolbar button downloads the latest stable `composer.phar` from `getcomposer.org` directly into the project's target directory, with SHA-256 verification
- **Audit log** — every command is logged to the `terminal_logs` database table (with user, IP, command, and exit code), with a flat-file fallback to `storage/logs/terminal_audit.log`
- **Streaming output** — stdout and stderr stream to the browser in real time

### Disabling the terminal

Site-wide:

```php
define('TERMINAL_ENABLED', false);  // config.php
```

Per-project: uncheck **Enable web terminal** in the project form.

### Command timeout

```php
define('TERMINAL_TIMEOUT', 600);  // seconds — default 10 minutes
```

---

## Deployer Self-Protection

When the deployer lives inside the same directory as an application being deployed (a common shared-hosting layout), it would normally delete itself during a Full deploy or overwrite itself during a Safe deploy.

TeraPH automatically detects this scenario and protects itself:

- **During deploy** — the deployer's own directory is silently added to the skip list, so it is never cleared or overwritten regardless of deploy mode
- **During backup** — the deployer directory is excluded from the pre-deploy zip so backups only contain the application code
- **Dashboard badge** — project cards show an **⚠ Deployer inside target** badge whenever this overlap is detected

### Configuration

```php
// Set this to the absolute path of the deployer's root directory.
// The default (dirname(__DIR__)) is correct for standard installations.
define('DEPLOYER_ROOT', '/home/user/public_html/deployer');
```

The detection uses `realpath()` so it is symlink-aware and path-separator agnostic.

---

## Deploy Strategy

TeraPH supports two strategies for applying files to the target directory:

| Strategy | How it works | Best for |
| --- | --- | --- |
| **copy** | Files are copied in-place (existing files are cleared first) | All hosting environments — the safe default |
| **symlink** | Atomic release via symlink swap | VPS / servers with symlink support |

### Auto-detection

```php
define('DEPLOY_STRATEGY', 'auto');  // default
```

In `auto` mode, TeraPH tests whether `symlink()` works in the server's temp directory on the first deployment. The result is cached in `storage/deploy_strategy.txt` — you can delete this file to force a re-test.

### Forcing a strategy

```php
define('DEPLOY_STRATEGY', 'copy');    // always use copy — safe on all cPanel hosts
define('DEPLOY_STRATEGY', 'symlink'); // always use symlink
```

The resolved strategy is logged at the start of every deployment:

```text
Deploy strategy: copy
```

---

## General Settings

The **Settings** page (`/settings.php`, linked in the topbar) shows a read-only overview of the active installation:

| Section | Information shown |
| --- | --- |
| Installation | Deployer root path, dev mode status |
| Deploy | Strategy setting + resolved strategy (auto-detected result) |
| Terminal | Site-wide enabled state, command timeout |
| Hooks | Hook timeout |
| Backups | Max backups per project |
| Database | Driver in use |

The Settings page also provides quick-action buttons:

- **Run Migrations** — triggers a migration run without needing CLI access
- **Self-Update** — downloads and applies the latest code from GitHub, preserving `config.php` and `storage/`

---

## CLI Reference

All commands are run from the project root:

```bash
php tera <command> [options]
```

| Command | Description |
| --- | --- |
| `php tera serve` | Start the PHP dev server on `localhost:8000` |
| `php tera serve 8080` | Start on a custom port |
| `php tera migrate` | Run all pending database migrations |
| `php tera make:password` | Interactively hash a password for `config.php` |
| `php tera make:password mypass` | Hash a password non-interactively |
| `php tera github:test <project>` | Two-step PAT diagnostic (validates token + repo access) |
| `php tera deploy:reconcile` | Find and fix all deployments stuck in `running` state |
| `php tera clear:tmp` | Safely clear the temporary cache directory |
| `php tera clear:archives` | Clear old manually-uploaded deployment ZIP files |
| `php tera clear:backups` | Clear stored pre-deployment backups |
| `php tera clear:logs` | Clear the raw `/storage/logs` directory |
| `php tera clear:all` | Run all clearing sweeps sequentially |
| `php tera help` | Show all available commands |

### `github:test` details

```text
[1/2] Checking if PAT is valid (GET /user)...
      OK - PAT is valid. Authenticated as: tumbujohn

[2/2] Testing repo access (owner/repo)...
      Result: HTTP 200 -- OK

[OK] Access confirmed. Deployment should work.
```

### `deploy:reconcile` details

Use after a server crash or process kill where a deployment is stuck at `⟳ Deploying`.

```bash
php tera deploy:reconcile
```

For each stuck deployment it prints the last log entry, marks the status `failed`, and writes an audit log entry.

---

## Project Structure

```text
/deployer
├── .htaccess                      # HTTPS enforcement, blocks config.php access
├── README.md
├── config.php                     # Active config — GITIGNORED
├── config.php.example             # Template — commit this, not config.php
├── tera                           # CLI entry point: php tera <command>
├── server.php                     # PHP built-in dev server router
│
├── scripts/
│   └── install.php                # Single-file bootstrap installer (drop on server, run in browser)
│
├── app/                           # NOT web-accessible (.htaccess blocks it)
│   ├── bootstrap.php              # Single require-once for all classes
│   ├── Auth.php                   # Session auth, idle timeout
│   ├── Database.php               # PDO factory (SQLite / MySQL)
│   ├── helpers.php                # CSRF, flash messages, formatters, self-protection helpers
│   ├── Models/
│   │   ├── Project.php            # getDeployHooks(), getSafeKeepPaths()
│   │   ├── Deployment.php
│   │   └── DeploymentLog.php
│   └── Services/
│       ├── GitHubService.php      # cURL zip download with PAT support
│       ├── FileManager.php        # File system operations
│       ├── BackupService.php      # Zip backup and restore (deployer-aware)
│       ├── HookRunner.php         # Pre/post hook execution, {composer} resolution
│       └── DeployService.php      # 10-step deployment pipeline with self-protection
│
├── migrations/                    # NOT web-accessible
│   ├── 001_initial_schema.php     # Core schema (projects, deployments, logs)
│   ├── 002_source_type.php        # Manual upload source type
│   └── 003_project_hooks_terminal.php  # deploy_template, pre/post hooks, terminal_logs
│
├── public/                        # Web root — point your domain here
│   ├── index.php                  # Dashboard (overlap badge, template form, terminal toolbar)
│   ├── login.php / logout.php
│   ├── deploy.php                 # AJAX: POST=run pipeline, GET?action=status
│   ├── logs.php                   # AJAX: ?deployment_id=X or ?project_id=X
│   ├── backups.php                # AJAX backup list + restore
│   ├── projects.php               # AJAX project CRUD (template + hook fields)
│   ├── settings.php               # General Settings page
│   ├── migrate.php                # Browser-based migration runner
│   ├── update.php                 # Self-update from GitHub
│   ├── terminal_execute.php       # Streaming terminal endpoint (audited, guarded)
│   ├── composer_download.php      # Download + verify composer.phar into a project directory
│   ├── webhook.php                # Webhook stub (Phase 2)
│   └── assets/
│       ├── css/app.css
│       ├── js/app.js              # Deploy flow, project form, template auto-fill
│       └── js/terminal.js         # Terminal streaming, shortcut toolbar, composer.phar download
│
├── storage/                       # NOT web-accessible (.htaccess blocks it)
│   ├── backups/                   # Pre-deploy zip backups
│   ├── tmp/                       # Temporary zip downloads
│   ├── logs/                      # Flat deployment log files + terminal audit log
│   ├── archives/                  # Saved manual upload zips
│   ├── deploy_strategy.txt        # Cached symlink capability result (auto mode)
│   └── deployer.db                # SQLite database (if using SQLite)
│
├── test/
│   └── bootstrap_test.php         # CLI diagnostic script
│
└── docs/
    ├── improvement_plan.md        # Feature roadmap and prioritised improvements
    ├── todo.md                    # PRAC implementation tracker
    ├── concept_draft.md           # Software Concept & Architecture Specification
    └── SRS.md                     # Software Requirements Specification (IEEE 830)
```

---

## Troubleshooting

### Deployment status stuck at "⟳ Deploying"

```bash
php tera deploy:reconcile
```

Then reload the dashboard.

---

### "Network error" / No toast after deploying

The dashboard always queries `GET deploy.php?action=status` after a deploy attempt,
so the card reflects the **real DB status** even if the browser lost the original response. If both fail:

1. Check the browser console (`F12 → Console`) for a `[deploy]` error line
2. Check `storage/logs/deployment_N.log` for the pipeline's last recorded step
3. Run `php tera deploy:reconcile` to clean up any stuck state

---

### HTTP 404 when downloading from GitHub (private repo)

| Symptom | Cause | Fix |
| --- | --- | --- |
| 404 with a classic PAT | PAT may have expired | Regenerate and update the project's PAT field |
| 404 with a fine-grained PAT | Resource owner mismatch or org approval pending | See [Fine-Grained PAT](#option-b--fine-grained-pat) checklist |
| 401 Unauthorized | Token is invalid | Run `php tera github:test <project>` to diagnose |

```bash
php tera github:test <project-name>
```

---

### Pre/post hook command not found

If a hook command fails with `command not found`, check:

- Use `{composer}` instead of `composer` — on many shared hosts the global binary is not on `PATH`
- If `{composer}` still fails, download `composer.phar` using the **↓ composer.phar** button in the web terminal toolbar
- Check `HOOK_TIMEOUT` — slow `composer install` on restricted hosts may need a higher value

---

### Terminal command times out

Increase `TERMINAL_TIMEOUT` in `config.php`:

```php
define('TERMINAL_TIMEOUT', 1200);  // 20 minutes
```

For deploy hook timeouts, increase `HOOK_TIMEOUT` instead.

---

### SSL certificate error (local Windows dev)

```text
SSL certificate problem: unable to get local issuer certificate
```

Ensure `DEV_MODE = true` in `config.php`. Never use this on a production server.

---

### Target directory does not exist

The pipeline fails at the file-copy step if the target directory does not exist.

- **Production:** ensure the `public_html/myapp` directory exists on the server
- **Local dev:** create a test folder and point the project's Target Directory field to it

---

### Deployer deletes itself during a deploy

Ensure `DEPLOYER_ROOT` in `config.php` is set to the correct absolute path of the deployer installation. Once set correctly, the self-protection guard automatically adds the deployer directory to the skip list and the dashboard will show an **⚠ Deployer inside target** badge on the affected project card.

---

## Security Checklist

Before going live, verify each item:

- [ ] `config.php` is not committed to version control (check `.gitignore`)
- [ ] `APP_PASSWORD_HASH` is changed from the default
- [ ] `DEV_MODE` is set to `false` in production
- [ ] The deployer URL is on a subdomain, not in the main `public_html`
- [ ] `storage/`, `app/`, and `migrations/` return **403 Forbidden** when accessed directly in a browser
- [ ] HTTPS is enforced (the root `.htaccess` does this automatically with Apache `mod_rewrite`)
- [ ] `scripts/install.php` has been deleted after installation (or was never uploaded)
- [ ] GitHub PAT has the minimum required scope (`repo` for classic, `Contents: Read` for fine-grained)
- [ ] PAT is stored per-project (not only in `config.php`) for isolation
- [ ] `TERMINAL_ENABLED` is `false` if the web terminal is not needed in production
- [ ] `MAX_BACKUPS_PER_PROJECT` is set to a value your disk can sustain
- [ ] Run `php test/bootstrap_test.php` — all checks pass
- [ ] Run `php tera deploy:reconcile` — returns "No stuck deployments found"
