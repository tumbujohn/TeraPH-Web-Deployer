# User Guide

How to use TeraPH Web Deployer day-to-day once it's installed. For installation and
configuration, see the [README](../README.md). This guide covers workflows only — no
internal implementation detail.

---

## 1. Getting Started

1. Open your deployer URL and log in with the admin username/password set during install.
2. You'll land on the **Dashboard** — a list of registered projects, each shown as a card
   with a status badge and action buttons.
3. If no projects exist yet, click **+ Add Project** to register your first one.

---

## 2. Adding a Project

Click **+ Add Project** and fill in:

| Field | Notes |
| --- | --- |
| **Project Name** | Unique slug — letters, numbers, hyphens, underscores. |
| **Source Type** | `GitHub Repository` or `Manual Zip Upload`. |
| **Repository URL / Branch** | GitHub source only. Branch defaults to `main`. |
| **Target Directory** | Absolute server path where this project is served from. |
| **Safe-Keep Paths** | Comma-separated files/dirs preserved on Safe deploys (e.g. `.env, uploads/, storage/`). |
| **GitHub PAT** | Needed for private repos — see §3. |
| **Deploy Template** | Pre-fills hook commands for a framework (`laravel`, `codeigniter`, `wordpress`, `symfony`, or `none`). |
| **Pre/Post-Deploy Hooks** | Shell commands run automatically as part of every deploy — see §5. |
| **Enable Terminal** | Whether the web terminal is available for this project. |
| **PHP for hooks & terminal** | Optional. Absolute path of the PHP CLI that Composer and `php artisan` use for this project. Leave empty for the automatic choice (the PHP that runs the site); the field suggests the PHP binaries found on the server. |
| **Required PHP extensions** | Optional. Extensions your hooks need, e.g. `pdo_pgsql, pgsql`. Checked before a deploy changes anything; enabled for hook runs if installed but switched off; otherwise the deploy stops with an explanation. |

For **Manual Zip Upload** projects, upload a `.zip` of the application after saving — it's
stored and reused for every deploy until you upload a new one.

Save, then click **▶ Deploy** on the new project card.

---

## 3. GitHub Access (Private Repos)

Public repos need nothing extra. Private repos need a Personal Access Token, set either
per-project (Project form) or globally (server config, used as a fallback).

**Simplest option — Classic PAT:** GitHub → Settings → Developer settings → Personal
access tokens (classic) → Generate → tick the `repo` scope.

**Fine-grained PAT** (more secure, more setup): GitHub → Settings → Developer settings →
Fine-grained tokens → Generate. Set **Resource owner** to whoever owns the private repo,
**Repository access** to the specific repo, and **Permissions → Contents → Read-only**. If
the owner is an organization, an org admin must approve the token before it works.

Test any PAT without running a real deploy: ask your administrator to run
`php tera github:test <project-name>` from the server, or check the Settings page.

> **Editing a project:** leaving the PAT field blank when editing preserves the previously
> saved token — the placeholder text confirms this ("PAT saved — leave blank to keep").

---

## 4. Deploying

1. Click **▶ Deploy** on a project card.
2. Choose a mode:
   - **Safe** (default) — preserves your Safe-Keep paths (`.env`, uploads, etc.); only
     application code is replaced. Use this for routine updates.
   - **Full** — replaces the *entire* target directory, including anything in Safe-Keep
     paths. A confirmation warning appears before you can proceed. Use only for a clean
     reinstall.
3. Confirm. A live log stream shows each pipeline step as it happens.
4. When it finishes, the card's status badge updates: **● Live**, **⚠ Failed**, or
   **⟳ Deploying** while in progress.

Every deploy automatically takes a timestamped backup of the current target *before*
touching any files — see §6.

If you don't see a result toast (e.g. network hiccup), the dashboard re-checks the real
status from the server automatically — the card will always reflect what actually happened.

---

## 5. Deploy Templates & Hooks

Selecting a **Deploy Template** in the project form auto-fills common commands for that
framework (you can edit them afterward):

| Template | Pre-Deploy | Post-Deploy |
| --- | --- | --- |
| **laravel** | `composer install`, `artisan config:cache`, `route:cache`, `view:cache`, `migrate --force` | `artisan queue:restart`, `cache:clear` |
| **codeigniter** | `composer install`, `spark migrate` | — |
| **wordpress** | `composer install` | — |
| **symfony** | `composer install`, `doctrine:migrations:migrate`, `cache:clear` | — |
| **none** | manual | manual |

Use `{composer}` instead of a hardcoded `composer` command — it automatically resolves to
whatever's available on the server (global binary or a downloaded `composer.phar`). If any
hook command fails (non-zero exit), the deploy halts and is marked failed — fix the issue
and redeploy.

---

## 6. Backups & Restore

Click **Backups** on a project card to see every backup taken for that project (timestamp,
size). Click **Restore** on any entry to roll back to that exact state — a safety snapshot
of the *current* state is taken automatically before the restore runs, so a bad restore is
itself recoverable.

Older backups beyond the configured retention limit are cleaned up automatically after each
new deploy.

---

## 7. Logs

Click **Logs** on a project card for the full chronological log of any past deployment —
every pipeline step, hook output line, and any error that halted it.

---

## 8. Web Terminal

If enabled for a project (toolbar button **>_ Terminal**), you get a browser-based shell
scoped to that project's target directory:

- A shortcut toolbar for common commands (`composer install`, `dump-autoload`,
  `artisan migrate`, `artisan config:cache`, `artisan queue:restart`).
- A **↓ composer.phar** button that downloads and verifies the latest Composer directly
  into the project directory — useful when the host doesn't provide a global `composer`.
- Output streams live as commands run.

Every command you run is recorded (who, when, what, exit code) for audit purposes.

Disable it per-project by unchecking **Enable web terminal** in the project form, or your
administrator can disable it site-wide.

---

## 9. .env Manager

Open **.env Manager** (from the dashboard or a project's menu) to manage a project's
environment variables from the browser instead of editing files by hand.

1. **Enable "Managed .env"** for the project — once on, the deployer writes `.env` to the
   target automatically on every deploy.
2. **Paste a template** — your `.env.example` or a full `.env` — into the Template card and
   save, or click **Import from .env.example** if one already exists in the target
   directory. This extracts every key (and any default values found).
3. **Fill in values** in the Values table. Each value can be hidden/shown individually, or
   all at once with the top-right toggle. Mark any key **Required** — a deploy will refuse
   to proceed if a required key is left empty.
4. Add or remove individual keys with the **+ Add Key** row or the **✕** button per row;
   rename a key with the **✎** icon.
5. The **Preview** card shows exactly what will be written on the next deploy, with all
   values masked.

**Working with the live file**

6. The **Live .env** card compares the `.env` on the server with what the manager would write
   and lists any differences by key name (never values).
7. **Apply now** writes the `.env` immediately without a deploy. Optionally add a command to
   run afterwards (for example `php artisan config:cache`) — tick *Run the command* and press
   **Apply now**. It shows up in Logs like a deploy and can't run while a deploy is running.
8. **Pull from live** brings an existing site's current `.env` under management: new keys are
   added and empty values filled in, nothing is deleted. If a key already has a different
   stored value you're asked whether to replace it or keep yours.

**Editing tools**

9. Use the **filter box** above the Values table to find a key, and drag the ⋮⋮ handle (or use
   ▲ ▼) to reorder; **Save Values** keeps the order and rearranges the template to match.
10. **Bulk edit as text** lets you edit every value as `KEY=value` lines. Keys you leave out
    are not touched.
11. **Copy from another project** merges another project's variables into this one.
12. **Encrypted export / import** saves a passphrase-protected file you can keep or move to
    another install. Keep the passphrase safe — it cannot be recovered.

> Values containing line breaks (like a private key) show their line breaks as `\n` in the
> masked field; edit with care or use Bulk edit.

> If a value contains `${SOMETHING}`, a warning appears: CodeIgniter 4 expands that text when
> it reads the `.env`, even inside quotes, so the value may change. Avoid `${` in secrets for
> CodeIgniter 4 projects.
Values are stored encrypted and are never written to any deploy log.

> If your administrator hasn't set a dedicated encryption key on the server, you'll see a
> warning banner — ask them to add one (see [README](../README.md#configuration-reference)).
> Changing the site's admin password afterward would otherwise make stored values
> unreadable.

---

## 10. Settings Page

The **Settings** link in the topbar shows a read-only overview of the installation:
deployer root path, resolved deploy strategy, terminal status, hook/terminal timeouts,
backup retention, database driver, storage directory sizes, and configured integrations.

Two one-click maintenance actions are available there:

- **Run Migrations** — apply any pending database migrations without CLI access.
- **Self-Update** — pull and apply the latest deployer code from GitHub, preserving your
  `config.php` and all `storage/` data.

---

## 11. Troubleshooting

| Symptom | What to do |
| --- | --- |
| Deployment stuck at "⟳ Deploying" | Ask your administrator to run `php tera deploy:reconcile`, or use the equivalent action on the Settings page, then reload. |
| No result after clicking Deploy | The dashboard always re-checks real status from the server — reload if unsure. Check the Logs modal for the pipeline's last recorded step. |
| 404 downloading from a private GitHub repo | Usually an expired or misscoped PAT. Re-check the PAT setup in §3. |
| Composer says my PHP version is too old, but Settings and SSH show a newer one | Hooks use the CLI PHP, which can differ from the PHP serving the site. The deployer now picks the site's own PHP automatically. **Settings → PHP for hooks & terminal** shows what hooks use and lists the PHP binaries on the server. To use a different one, set *PHP for hooks & terminal* in the project's settings (or your administrator sets `CLI_PHP_PATH`). Also check that the lock file doesn't need a newer PHP than the one chosen. See the README troubleshooting entry. |
| A hook (`php artisan migrate`) fails with "could not find driver" | The PHP the hooks run lacks the database extension (e.g. `pdo_pgsql`). **Settings → PHP Environment** shows which PHP has which database drivers. Add the extension to the project's *Required PHP extensions* (it is enabled for hook runs if installed, otherwise the deploy stops cleanly before changing anything), choose a PHP that has it, or ask your host to enable it. See the README troubleshooting entry. |
| A Laravel hook (`php artisan …`) fails with "Please provide a valid cache path" | The target had no `storage/framework/views` folder. The deployer now creates Laravel's (and CodeIgniter's and Symfony's) runtime folders automatically before hooks run. On an older deployer, run `mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache` in the terminal, then redeploy. |
| A pre/post-deploy hook says "command not found" | Use `{composer}` instead of `composer` directly, or use the terminal's **↓ composer.phar** button. |
| Terminal command times out | Ask your administrator to raise the terminal/hook timeout in server config. |
| Target directory doesn't exist | Create it on the server first, or double-check the Target Directory field for typos. |

---

## FAQ

**Does Full mode delete my `.env` and uploads?**
Yes — Full mode has no exclusions besides deployer self-protection. Use Safe mode for
routine updates; Full mode is for clean reinstalls only, and the UI warns you before it runs.

**What happens to a project's deploy history if I delete it?**
The project record and its `.env` variables are removed, but past deployments and logs are
kept for audit purposes.

**Can more than one person use this?**
Not yet — there's a single administrator account. Multi-user roles are on the roadmap; see
[TODO.md](TODO.md).

---

*End of Document — User Guide*
