# TeraPH Web Deployer

A self-hosted, lightweight deployment panel for PHP applications on shared cPanel hosting.
Deploy registered web projects from GitHub to your server — safely, traceably, and reversibly — with no SSH or external CI/CD required.

---

## Table of Contents

1. [Requirements](#requirements)
2. [Quick Start (Local Dev)](#quick-start-local-dev)
3. [Configuration Reference](#configuration-reference)
4. [GitHub PAT Setup](#github-pat-setup)
5. [Production Installation](#production-installation)
6. [Adding Your First Project](#adding-your-first-project)
7. [Deployment Modes](#deployment-modes)
8. [CLI Reference](#cli-reference)
9. [Project Structure](#project-structure)
10. [Troubleshooting](#troubleshooting)
11. [Security Checklist](#security-checklist)

---

## Requirements

| Requirement        | Minimum                                               |
|--------------------|-------------------------------------------------------|
| PHP                | 8.0+                                                  |
| PHP Extensions     | `pdo`, `pdo_sqlite` or `pdo_mysql`, `zip`, `curl`    |
| Web Server         | Apache with `mod_rewrite` + `.htaccess` support       |
| Hosting            | Any cPanel shared hosting, or a local PHP environment |

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

```
http://localhost:8000
```

Log in with:
- **Username:** `admin` (set by `APP_USERNAME` in `config.php`)
- **Password:** whatever you used in step 3

> The database and storage directories are created automatically on first login.

---

## Configuration Reference

All configuration lives in `config.php`. Copy from `config.php.example` and edit.
**Never commit `config.php` to version control** — it is gitignored by default.

---

### Application

```php
define('APP_NAME',     'TeraPH Deployer');  // Panel title shown in the UI
define('APP_URL',      '');                  // Full URL to /public/ (used for links, optional)
```

---

### Authentication

```php
define('APP_USERNAME',      'admin');        // Login username
define('APP_PASSWORD_HASH', '...');          // bcrypt hash — generate with: php tera make:password
```

Generate a hash any time:

```bash
php tera make:password yournewpassword
```

Paste the printed hash into `APP_PASSWORD_HASH`.

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

No other code changes are needed — the system is DB-agnostic.

---

### Storage Paths

All writable paths used by the deployer. These directories are created automatically.

```php
define('STORAGE_PATH', __DIR__ . '/storage');           // Root storage dir
define('BACKUP_PATH',  __DIR__ . '/storage/backups');   // Pre-deploy backups
define('TMP_PATH',     __DIR__ . '/storage/tmp');       // Downloaded zip temp files
define('LOG_PATH',     __DIR__ . '/storage/logs');      // Flat-file deployment logs
```

All of these are blocked from public web access via `.htaccess`.

---

### Deployment Settings

```php
define('MAX_BACKUPS_PER_PROJECT', 10);    // Oldest backups auto-deleted past this count
define('DOWNLOAD_TIMEOUT',        120);   // Max seconds to wait for GitHub zip download
define('LOCK_TIMEOUT',            300);   // Seconds before a stuck deploy lock is auto-released
```

> **`LOCK_TIMEOUT`** is a safety net: if a deployment crashes mid-pipeline without cleaning up,
> the lock is automatically released after this many seconds. The UI will also
> detect stuck deployments via the status-check endpoint. Use `php tera deploy:reconcile`
> to fix any stuck records immediately.

---

### GitHub PAT (Global Fallback)

A global PAT used for any project that does not have its own PAT configured:

```php
define('GITHUB_PAT', 'ghp_xxxxxxxxxxxxxxxx');
```

You can also set a per-project PAT in the project form — that takes priority over this global value.

See [GitHub PAT Setup](#github-pat-setup) below for how to create one.

---

### Development Mode

```php
define('DEV_MODE',        true);   // true = local dev, false = production
define('CURL_SSL_VERIFY', !DEV_MODE);
```

`DEV_MODE = true` disables SSL certificate verification in cURL. This is needed on Windows
local machines where PHP does not ship with a CA certificate bundle.

> **Always set `DEV_MODE = false` before deploying to production.**

---

## GitHub PAT Setup

A Personal Access Token (PAT) allows the deployer to download zip archives from private
GitHub repositories. Two types are supported.

---

### Option A — Classic PAT (Simplest, Recommended)

1. Go to **GitHub → Settings** (avatar, top-right) → **Developer settings**
2. **Personal access tokens → Tokens (classic)**
3. Click **Generate new token (classic)**
4. Set an expiry (e.g. 90 days or No expiration)
5. Under **Scopes**, tick **`repo`** (full repository access)
6. Click **Generate token** and copy it immediately

Paste the token into either:
- `config.php` as the global `GITHUB_PAT`, **or**
- The project form's **GitHub PAT** field (per-project)

Classic PATs start with `ghp_`.

---

### Option B — Fine-Grained PAT

Fine-grained tokens are more secure but require precise setup.
Follow **every step exactly** — missing any one causes a `404` error.

1. Go to **GitHub → Settings → Developer settings**
2. **Personal access tokens → Fine-grained tokens**
3. Click **Generate new token**

**Step A — Resource Owner** (critical)

```
Resource owner: [select the account/org that OWNS the repository]
```

This must match the GitHub username or organisation that owns the private repo,
not necessarily your own account.

> If the resource owner is an **organisation**, the org admin must approve your PAT at:
> `github.com/organizations/{org-name}/settings/personal-access-tokens/pending`
> The token will not work until approved.

**Step B — Repository access**

```
Repository access: Only select repositories
→ [Add] your-repo-name
```

**Step C — Permissions**

```
Repository permissions:
  Metadata   → Read-only   (auto-enabled, keep it)
  Contents   → Read-only   (required for zip download)
```
Note: You may want to add `Content` to the repository permissions first if Metadata doesnt show-up immidiately, the default Metadata sometimes shows up after adding one permission.

**Step D — Generate and copy the token**

Fine-grained tokens start with `github_pat_`.

---

### Verifying your PAT

After saving the PAT to a project, run the built-in diagnostic:

```bash
php tera github:test <project-name>
```

Expected output for a working PAT:

```
[1/2] Checking if PAT is valid (GET /user)...
      OK — PAT is valid. Authenticated as: yourusername

[2/2] Testing repo access (owner/repo)...
      Result: HTTP 200 -- OK

[OK] Access confirmed. Deployment should work.
```

If you see `HTTP 404` on step 2 despite granting access, the most common causes are:
- The **Resource owner** in the fine-grained token settings doesn't match the repo owner
- An org admin hasn't approved the PAT yet
- Use a Classic PAT (`ghp_`) to bypass fine-grained scope issues

---

## Production Installation

### 1. Upload files

Upload the entire project to a **private path** on your server, outside `public_html`:

```
/home/youraccount/deployer/       ← project root
/home/youraccount/deployer/public ← point your (sub)domain here
```

> Do **not** place the root inside `public_html` without additional server-level protection.

### 2. Point your subdomain

In cPanel → **Subdomains** or **Addon Domains**, set the document root for your deploy panel
subdomain (e.g. `deploy.yourdomain.com`) to:

```
/home/youraccount/deployer/public
```

### 3. Configure for production

```php
// config.php
define('DEV_MODE',        false);   // ← must be false in production
define('CURL_SSL_VERIFY', true);    // ← SSL verification enabled
define('DB_DRIVER',       'sqlite'); // or 'mysql'
```

### 4. Run the bootstrap test

Via SSH (if available) or a temporary PHP file:

```bash
php test/bootstrap_test.php
```

All checks should pass before using the panel in production.

### 5. Run Database Migrations

You can run your database setups by SSH terminal or directly through the browser.

Via SSH (if available):
```bash
php tera migrate
```

Via the browser (when SSH isn't available, e.g., standard cPanel):
Simply navigate to your domain at `https://deploy.yourdomain.com/migrate.php`. It will prompt you to log in using the `APP_PASSWORD_HASH` defined in your `config.php`, securely run all unapplied migrations, and print the outputs onto the screen!

### 6. Log in and add projects

Open `https://deploy.yourdomain.com` and log in.

---

## Adding Your First Project

1. Click **+ Add Project** on the dashboard
2. Fill in the form:

   | Field | Description |
   |---|---|
   | **Project Name** | Unique slug (letters, numbers, hyphens, underscores) |
   | **Repository URL** | Full GitHub URL — HTTPS or `.git` suffix both accepted |
   | **Branch** | Default: `main` |
   | **Target Directory** | Absolute path on server (e.g. `/home/user/public_html/myapp`) |
   | **Safe-Keep Paths** | Comma-separated files/dirs to preserve during Safe deploys (e.g. `.env, uploads/, storage/`) |
   | **GitHub PAT** | Personal Access Token — required for private repos |

3. Click **Save Project**
4. Click **▶ Deploy** on the project card
5. Select mode (`Safe` or `Full`), confirm, and monitor the live status
6. Click **Logs** on the project card to view the full deployment log

> **PAT tip:** Leaving the GitHub PAT field blank when editing a project **preserves the stored
> token** — you only need to re-enter it when replacing it. The placeholder will read
> *"PAT saved — leave blank to keep"* to confirm this.

> **Target directory tip (local dev):** The target path must exist and be writable on the
> machine running the deployer. For local testing, use a real local path such as
> `C:/Users/yourname/Desktop/test-deploy` (create the folder first).

---

## Deployment Modes

| Mode | Behaviour | When to use |
|------|-----------|-------------|
| **Safe** | Downloads the new code, preserves files listed in *Safe-Keep Paths* (`.env`, `uploads/`, etc.), then replaces only the code files | Normal updates to live sites |
| **Full** | Replaces the **entire target directory** with the new code | Fresh installs, or when you want a complete clean slate |

> ⚠ **Full mode permanently deletes all existing files** in the target directory, including
> `.env`, user uploads, and any data files. A backup is always created first, but proceed carefully.

Both modes:
- Download the repository zip from GitHub (authenticated with PAT if set)
- Create a timestamped zip backup **before** touching any files
- Log every step to the database and to a flat `.log` file
- Acquire a deployment lock (prevents concurrent deploys on the same project)
- Automatically release the lock on success **or** failure
- Report the definitive outcome by querying the database — not just the HTTP response

---

## CLI Reference

All commands are run from the project root:

```bash
php tera <command> [options]
```

| Command | Description |
|---|---|
| `php tera serve` | Start the PHP dev server on `localhost:8000` |
| `php tera serve 8080` | Start on a custom port |
| `php tera migrate` | Run all pending database migrations |
| `php tera make:password` | Interactively hash a password for `config.php` |
| `php tera make:password mypass` | Hash a password non-interactively |
| `php tera github:test <project>` | Two-step PAT diagnostic (validates token + repo access) |
| `php tera deploy:reconcile` | Find and fix all deployments stuck in `running` state |
| `php tera clear:tmp` | Safely clear the temporary cache directory |
| `php tera clear:archives` | Clear old, manually-uploaded deployment ZIP files |
| `php tera clear:backups` | Clear stored pre-deployment safe-keep backups |
| `php tera clear:logs` | Clear the raw `/storage/logs` directory |
| `php tera clear:all` | Sequentially run all the clearing sweeps simultaneously |
| `php tera help` | Show all available commands |

### `github:test` details

Runs two sequential checks:
1. `GET /user` — verifies the PAT itself is valid and shows which GitHub account it belongs to
2. Range GET to the repo archive endpoint — confirms the token has access to the specific repo

```
[1/2] Checking if PAT is valid (GET /user)...
      OK - PAT is valid. Authenticated as: tumbujohn

[2/2] Testing repo access (owner/repo)...
      Result: HTTP 200 -- OK

[OK] Access confirmed. Deployment should work.
```

### `deploy:reconcile` details

Use this after a server crash, process kill, or any scenario where a deployment is
stuck displaying `⟳ Deploying` without completing.

```bash
php tera deploy:reconcile
```

For each stuck deployment it:
- Prints the last log entry (so you know what step it died at)
- Marks the status `failed` in the database
- Appends a `WARNING` audit log entry recording the reconcile action

The status-check endpoint (`GET deploy.php?action=status&project_id=X`) performs a
lighter version of this automatically each time the dashboard polls during a deploy.

---

## Project Structure

```
/deployer
├── .htaccess                  # HTTPS enforcement, blocks config.php access
├── README.md
├── config.php                 # Active config — GITIGNORED
├── config.php.example         # Template — commit this, not config.php
├── tera                       # CLI entry point: php tera <command>
├── server.php                 # PHP built-in dev server router
│
├── app/                       # NOT web-accessible (.htaccess blocks it)
│   ├── bootstrap.php          # Single require-once for all classes
│   ├── Auth.php               # Session auth, idle timeout
│   ├── Database.php           # PDO factory (SQLite / MySQL)
│   ├── helpers.php            # CSRF, flash messages, formatters
│   ├── Models/
│   │   ├── Project.php
│   │   ├── Deployment.php
│   │   └── DeploymentLog.php
│   └── Services/
│       ├── GitHubService.php  # cURL zip download with PAT support
│       ├── FileManager.php    # File system operations
│       ├── BackupService.php  # Zip backup and restore
│       └── DeployService.php  # 10-step deployment pipeline
│
├── migrations/                # NOT web-accessible
│   └── 001_initial_schema.php # Non-destructive schema (SQLite + MySQL)
│
├── public/                    # Web root — point your domain here
│   ├── index.php              # Dashboard
│   ├── login.php / logout.php
│   ├── deploy.php             # AJAX: POST=run pipeline, GET?action=status=real DB status
│   ├── logs.php               # AJAX: ?deployment_id=X or ?project_id=X (latest)
│   ├── backups.php            # AJAX backup list + restore
│   ├── projects.php           # AJAX project CRUD
│   ├── webhook.php            # Phase 2 stub
│   └── assets/
│       ├── css/app.css
│       └── js/app.js          # Status polling loop, DB-verified deploy outcomes
│
├── storage/                   # NOT web-accessible (.htaccess blocks it)
│   ├── backups/               # Pre-deploy zip backups
│   ├── tmp/                   # Temporary zip downloads
│   ├── logs/                  # Flat deployment log files
│   └── deployer.db            # SQLite database (if using SQLite)
│
├── test/
│   └── bootstrap_test.php     # CLI diagnostic script
│
└── docs/
    ├── concept_draft.md       # Software Concept & Architecture Specification
    └── SRS.md                 # Software Requirements Specification (IEEE 830)
```

---

## Troubleshooting

### Deployment status stuck at "⟳ Deploying"

This means a previous deployment was interrupted before it could update its status in the
database. Fix it immediately:

```bash
php tera deploy:reconcile
```

Then reload the dashboard — the card will show the corrected status.

---

### "Network error" / No toast after deploying

The dashboard always queries `GET deploy.php?action=status` after a deploy attempt,
so the card and toast reflect the **real DB status** even if the browser lost the
original fetch response. If both fail:

1. Check the browser console (`F12 → Console`) for a `[deploy]` error line
2. Check `storage/logs/deployment_N.log` for the pipeline's last recorded step
3. Run `php tera deploy:reconcile` to clean up any stuck state

---

### HTTP 404 when downloading from GitHub (private repo)

| Symptom | Cause | Fix |
|---|---|---|
| 404 with a classic PAT | PAT may have expired | Regenerate and update the project's PAT field |
| 404 with a fine-grained PAT | Resource owner mismatch or org approval pending | See [Fine-Grained PAT](#option-b--fine-grained-pat) checklist |
| 401 Unauthorized | Token is invalid | Run `php tera github:test <project>` to diagnose |

Always verify with:
```bash
php tera github:test <project-name>
```

---

### SSL certificate error (local Windows dev)

```
SSL certificate problem: unable to get local issuer certificate
```

Ensure `DEV_MODE = true` in `config.php`. This sets `CURL_SSL_VERIFY = false` for local
development only. Never use this on a production server.

---

### Target directory does not exist

The pipeline will fail at the file-copy step if the target directory does not exist.

- **Production:** ensure the `public_html/myapp` directory exists on the server
- **Local dev:** create a test folder and point the project's Target Directory field to it:
  ```
  C:/Users/yourname/Desktop/test-deploy
  ```

---

## Security Checklist

Before going live, verify each item:

- [ ] `config.php` is not committed to version control (check `.gitignore`)
- [ ] `APP_PASSWORD_HASH` is changed from the default
- [ ] `DEV_MODE` is set to `false` in production
- [ ] The deployer URL is on a subdomain, not in the main `public_html`
- [ ] `storage/`, `app/`, and `migrations/` return **403 Forbidden** when accessed directly in a browser
- [ ] HTTPS is enforced (the root `.htaccess` does this automatically with Apache `mod_rewrite`)
- [ ] GitHub PAT has the minimum required scope (`repo` for classic, `Contents: Read` for fine-grained)
- [ ] PAT is stored per-project (not only in `config.php`) for isolation
- [ ] `MAX_BACKUPS_PER_PROJECT` is set to a value your disk can sustain
- [ ] Run `php test/bootstrap_test.php` — all checks pass
- [ ] Run `php tera deploy:reconcile` — returns "No stuck deployments found"
