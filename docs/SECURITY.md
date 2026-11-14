# Security

Security architecture, controls, and known limitations for TeraPH Web Deployer. This panel
has full filesystem read/write access on whatever server it runs on — treat it accordingly.

For the pre-launch checklist, see [README.md § Security Checklist](../README.md#security-checklist).

---

## 1. Authentication

- Single admin account: username (`APP_USERNAME`) + bcrypt password hash
  (`APP_PASSWORD_HASH`), verified with `password_verify()` — no plaintext or reversible
  storage ([app/Auth.php](../app/Auth.php)).
- Session ID is regenerated on successful login (`session_regenerate_id(true)`).
- Idle session timeout enforced server-side on every request (`SESSION_TIMEOUT`, default
  3600s) — not just a cookie expiry.
- Session cookies: `cookie_httponly` and `cookie_samesite=Strict` set in `app_boot()`.
  **Gap:** `cookie_secure` is not currently forced — see §7.
- Failed login returns a generic error; it does not reveal whether the username or password
  was the problem.
- **Gap:** no login rate limiting / lockout. A determined attacker with network access to
  the login page can brute-force the password with no throttling. See §7.

---

## 2. CSRF Protection

Every state-changing POST request (`deploy.php`, `projects.php`, `backups.php`,
`env_manager.php`, `settings_action.php`, `terminal_execute.php`) calls `csrf_verify()`,
which validates a per-session token via `hash_equals()` and returns HTTP 403 on mismatch.
Every form renders its token via `csrf_field()`.

---

## 3. Directory & File Access Control

`.htaccess` `Deny from all` rules block direct browser access to:

- `/app` — all PHP classes
- `/migrations` — schema definitions
- `/storage` — database file, backups, logs, temp extraction, archives

These are enforced at the web-server level, not just by omitting links in the UI — a
directly guessed URL to any file under these paths is rejected by Apache before PHP ever
runs. `config.php` itself is also blocked. Verify these return 403 as part of the
[Security Checklist](../README.md#security-checklist) before going live.

---

## 4. Input Handling

- All HTML output is escaped via `h()` (`htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE)`).
- All SQL uses PDO prepared statements with named parameters — no string-interpolated
  queries anywhere in the codebase (`PDO::ATTR_EMULATE_PREPARES => false` in
  [app/Database.php](../app/Database.php), so bindings are real prepared-statement
  parameters, not client-side string substitution).
- The deployer never performs a file operation against a path taken directly from request
  input — every `target_path` is looked up from the `projects` table by `project_id`, never
  accepted as a raw path from the client.

---

## 5. Secrets at Rest

| Secret | Storage | Status |
| --- | --- | --- |
| Admin password | bcrypt hash in `config.php` | ✅ Hashed, not reversible |
| `.env` variable values | AES-256-GCM encrypted in `project_env_vars.value_enc` (CBC fallback on old OpenSSL) | ✅ Encrypted at rest, never logged |
| GitHub PAT | `projects.github_pat` — **plaintext** | ⚠️ **Not encrypted.** See below. |
| `SECRET_KEY` / `WEBHOOK_SECRET` / DB credentials | `config.php` only, gitignored | ✅ Never in the database or committed |

### GitHub PAT is stored in plaintext

`Project::create()`/`update()` write `github_pat` directly to the database with no
encryption. Anyone with read access to the database file (or a SQL injection elsewhere, or
a backup of `storage/deployer.db` that leaks) gets every project's GitHub token in the
clear. This is a known, tracked gap — see [TODO.md](TODO.md) P0 and
[DECISIONS.md](DECISIONS.md). Mitigate today by:

- Scoping PATs as narrowly as possible (fine-grained, single-repo, `Contents: Read-only`).
- Ensuring `storage/` is inaccessible from the web (§3) and that server-level file
  permissions restrict who can read `storage/deployer.db` directly.

### `.env` in backups

Backups are plain zips of the target directory, so a managed `.env` is stored there
**unencrypted** by default — anyone who can read `storage/backups/` can read your secrets.
Set `BACKUP_ENCRYPT_ENV` to `true` in `config.php` to AES-256 password-protect `.env` files
(`.env`, `.env.local`, … but not `.env.example`) inside each backup. The password is derived
from the same key as stored env values, so changing `SECRET_KEY` makes those `.env` entries
unrecoverable. Only `.env` files are protected; the rest of the archive is unencrypted.
Requires PHP 7.2+ with libzip 1.2+; if unavailable the backup fails with an explanatory
error instead of silently storing plaintext.

### .env Manager: template, apply command, bundles

- **The stored template holds no values.** `projects.env_template` is scrubbed on every save, so
  secrets exist only encrypted. Templates saved by older versions may still contain plaintext
  values; the .env Manager shows a banner with a one-click scrub. Until scrubbed, those values
  sit unencrypted in the database.
- **Apply now runs a stored command** (`projects.env_apply_cmd`) through `HookRunner`, with the
  same privileges and timeout as deploy hooks. It is set from the authenticated, CSRF-protected
  .env Manager page only. Treat it as equivalent to a deploy hook.
- **Export bundles are protected only by their passphrase** (PBKDF2-SHA256, 200 000 iterations,
  AES-256-GCM, ≥ 10 characters enforced on export). A weak passphrase is brute-forceable
  offline; the importer bounds the iteration count so a crafted file can't choose the CPU cost.
- **Drift and pull never display live values.** Drift lists key names only; a pull that would
  replace stored values asks first and shows only the key names.

### `.env` encryption key fallback

`env_encryption_key()` uses `SECRET_KEY` if set in `config.php`; if not, it derives a key
from `APP_PASSWORD_HASH` instead. This means the .env manager works without extra setup,
**but** changing the admin password on an install that never set `SECRET_KEY` permanently
breaks decryption of every stored `.env` value. The .env Manager UI shows a persistent
warning banner whenever `SECRET_KEY` is unset. **Set a dedicated `SECRET_KEY` before storing
any real `.env` values**:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

---

## 6. Web Terminal & Hook Execution

The web terminal and deploy hooks both run through `HookRunner`, which wraps `proc_open`.
This is deliberate, controlled shell access — not an oversight. Controls in place:

- Working directory is always the project's `target_path` — never arbitrary.
- Every terminal command is audit-logged: user, IP, command text, exit code, timestamp
  (`terminal_logs` table + `storage/logs/terminal_audit.log` fallback).
- `TERMINAL_ENABLED` (site-wide) and `terminal_enabled` (per-project) kill switches both
  exist — set `TERMINAL_ENABLED = false` in `config.php` if this attack surface isn't
  wanted at all.
- Commands (both hooks and terminal) are subject to a timeout (`HOOK_TIMEOUT` /
  `TERMINAL_TIMEOUT`) so a runaway process can't hang the pipeline indefinitely.
- **Given this is intentional shell access**, treat the admin credentials protecting it with
  the same seriousness as SSH credentials — anyone who can log in to the panel can run
  arbitrary shell commands on the server as the web server user.

---

## 7. Known Gaps / Accepted Risks

Tracked with remediation plans in [TODO.md](TODO.md):

| Gap | Risk | Priority |
| --- | --- | --- |
| GitHub PAT stored plaintext | DB read access = token leak for every private repo configured | P0 |
| No login rate limiting | Password brute-forceable if login page is network-reachable | P0 |
| `cookie_secure` not forced | Session cookie could be sent over plain HTTP if `DEV_MODE` is misconfigured | P0 |
| No automatic rollback on deploy failure | A failed deploy after the clear step can leave the target in a broken partial state until someone manually restores | P0 |
| No webhook signature verification (stub only) | N/A — feature not live yet, do not expose `webhook.php` publicly until implemented | P1 |
| Deploy lock not time-boxed automatically | A crashed deploy leaves a project locked until `deploy:reconcile` is run manually | P1 |

---

## 8. Operational Recommendations

- Host the panel on a subdomain, not inside `public_html` directly, unless additional
  server-level access control is layered on top.
- Delete `scripts/install.php` immediately after installation — it has no authentication.
- Delete or restrict `scripts/probe.php` after diagnosing an install issue — it echoes
  environment details.
- Set `DEV_MODE = false` in production — this also flips `CURL_SSL_VERIFY` on, restoring
  real TLS certificate verification for outbound GitHub requests.
- Keep `MAX_BACKUPS_PER_PROJECT` at a value your disk can actually sustain — backups are
  full zip copies of the target directory.
- Run through the [Security Checklist](../README.md#security-checklist) before every new
  production install.

---

## 9. Incident Response

There is no formal incident-response tooling today (no automated alerting, no built-in
session revocation UI beyond changing the password/logging out). If compromise is
suspected:

1. Rotate `APP_PASSWORD_HASH` immediately (`php tera make:password`), which invalidates all
   existing sessions on next validation pass.
2. Rotate every project's GitHub PAT (plaintext storage means a DB leak leaks all of them —
   see §5).
3. Review `deployment_logs` and `terminal_logs` for unrecognized activity.
4. Review recent backups under `storage/backups/` for unexpected restore points.

---

*Update this file whenever authentication, authorization, secrets handling, or any control
listed above changes.*
