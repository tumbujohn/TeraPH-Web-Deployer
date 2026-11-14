# TODO

Actionable outstanding work, organized by priority. This consolidates and supersedes the
open items from [archive/improvement_plan.md](archive/improvement_plan.md) — the PRAC tier
described there is fully done (see [PROGRESS.md](PROGRESS.md)) and is not repeated here.

Priority tiers follow the original roadmap naming: **P0** (correctness & safety) → **P1**
(standard tool) → **P2** (competitive/professional) → **ENG** (engineering practice).

---

## Critical

*(none currently blocking)*

---

## High — P0: Correctness & Safety

- [ ] **Automatic rollback on deploy failure.** Today a failed deploy leaves the target in
      whatever partial state it reached; the pre-deploy backup exists but must be restored
      manually. Add: on any pipeline exception after the backup step, call
      `BackupService::restore()` automatically before marking the deployment failed.
      *(Symlink mode, once implemented, gets this for free — see P0.1 below.)*
- [ ] **GitHub PAT encryption at rest.** `projects.github_pat` is currently stored
      plaintext. Encrypt with the same `libsodium`/AES pattern already used for `.env`
      values (`env_encrypt`/`env_decrypt` in `app/helpers.php`); migrate existing plaintext
      values on next save. See [SECURITY.md](SECURITY.md).
- [ ] **Login brute-force protection.** Track failed attempts by IP (additive migration —
      new `login_attempts` table); lock out after N failures within a window.
- [ ] **Self-updater safety (`public/update.php`).** It deletes every file (except `config.php`
      and `storage/`) *before* copying the new ones, so the site is broken for the duration and
      permanently broken if the copy fails or times out (`set_time_limit(300)`); the pre-update
      backup zip must then be restored by hand. It also runs from the old version, so fixes only
      help the *next* update. Wanted: copy over existing files (replace per file, then prune
      files absent from the release) instead of clear-then-copy; verify the downloaded package
      first (required files present, `php -l` of the entry points, a release manifest declaring
      minimum PHP version and extensions, checked against the host); on any failure or a failed
      post-update bootstrap check, restore the pre-update zip automatically; refuse to start
      while a deployment is running; optionally a short maintenance flag during the swap.
      Also: call `opcache_reset()` and `clearstatcache()` after applying (without it, opcache can
      serve a mix of old and new files for `opcache.revalidate_freq` seconds, or indefinitely where
      `validate_timestamps=0` — verified by updating real historical installs), and handle replacing
      its own running file (Windows locks it: the copy fails, leaving `public/update.php`, `tera`
      and `scripts/` missing and no way to retry from the UI) — e.g. copy it last under a temporary
      name and rename, or finish the swap in a second request.
      Also: **refuse to run on a git working copy.** Running Self-Update on a developer checkout
      deleted `.git/` (`clear()` removes everything except `config.php` and `storage/`) — on a
      Windows dev machine it removed `HEAD`, `config` and `index`, so git reported "not a
      repository" and the repo had to be rebuilt from the updater's own pre-update zip. The updater
      should detect a `.git` directory and stop with a clear message (or at minimum preserve it
      like `config.php` and `storage/`). More generally, anything else an operator keeps in the
      install root is deleted, not preserved — consider a configurable extra-preserve list.
- [ ] **Secure session cookie flag.** Set `cookie_secure => !DEV_MODE` alongside the
      existing `cookie_httponly`/`cookie_samesite` in `app_boot()`.
- [ ] **P0.1 — Atomic symlink-based releases.** `DEPLOY_STRATEGY` and
      `detect_deploy_strategy()` exist but the pipeline never branches on the result —
      `DeployService` always calls `FileManager::clear()`/`copyContents()` in place. Needs a
      `DeployStrategy` interface with `CopyStrategy` (today's behavior) and
      `SymlinkStrategy` (`releases/{ts}/` + `shared/` + atomic `current` symlink swap)
      implementations, plus a design doc before coding — this is a real architectural
      change, not a small patch.

## Medium — P1: Standard Tool

- [ ] **Background/queued deployment execution.** Deploys run synchronously in the web
      request; large repos or slow hooks risk PHP/gateway timeouts. Enqueue + detached
      background process + status polling (polling UI already exists).
- [ ] **Migration runner with version tracking.** Add a `schema_migrations` table so
      `app_boot()` stops re-running every migration file on every request.
- [ ] **Atomic lock acquisition.** Replace the check-then-act `isLocked()` + `create()`
      sequence with an atomic unique-key `INSERT` or per-project `flock()`.
- [ ] **Stale-lock auto-release by timestamp.** `LOCK_TIMEOUT` is defined in config but not
      currently enforced automatically inside the pipeline — stuck locks are only cleared
      via the manual/CLI `deploy:reconcile` action.
- [ ] **Webhook auto-deploy.** Implement `public/webhook.php` (currently a stub) with
      `X-Hub-Signature-256` HMAC verification, per-project secret, branch filtering.
- [ ] **Post-deploy health check.** Optional configurable health-check URL; auto-rollback
      if it doesn't return HTTP 200 before marking success.
- [ ] **Backup download from the browser** (REQ-F-054 in REQUIREMENTS.md).

## Low — P2: Competitive / Professional

- [ ] Notifications — Slack / Discord / Telegram / email on deploy success or failure
- [ ] REST API + deploy tokens for external CI triggers without the session UI
- [ ] Deploy history diff/dry-run viewer (list changed files before committing a deploy)
- [ ] Real-time log streaming via SSE (replace polling)
- [ ] Multi-user roles (Admin / Deployer / Viewer) with full audit trail
- [ ] IP allowlisting for panel access

## Technical Debt — ENG

- [ ] **Settings page overflows horizontally on phones** (~44px at 320px wide, ~4px at 360px):
      `.settings-card` content (long paths in `<dd>`) is wider than the viewport. The topbar
      overflow was fixed separately; this is the page's own layout.
- [ ] **Automated test suite (PHPUnit).** Cover `FileManager` skip-path/symlink logic,
      `GitHubService` URL parsing, `HookRunner`, `EnvVar` template sync, and pipeline
      happy/failure paths. `test/bootstrap_test.php` is a manual diagnostic only today.
- [ ] **Static analysis + CI.** PHPStan/Psalm + `php -l` + tests in GitHub Actions.
- [ ] **Namespaces + PSR-4 autoloader** to replace the manual `require_once` chain in
      `app/bootstrap.php`, without requiring Composer on the host.
- [ ] **Self-updater hardening.** Pin `update.php` to tagged releases and verify a SHA-256
      checksum before overwriting the running app.
- [ ] **Delete `scripts/probe.php` guidance.** It's a diagnostic tool meant to be removed
      after use, same as `scripts/install.php` — consider adding it to the Security
      Checklist in the README explicitly.

---

## Suggestions To Be Reviewed

Ideas from an Env Manager review. Not committed work — each needs a yes/no (and, where
noted, a design decision) before it moves into a priority tier above.

### Env Manager

- [ ] **Per-deployment env snapshot + change audit log.** Store an encrypted snapshot of the
      rendered env with each deployment row, plus a log of key changes (key, when, old→new
      hash). Rollback/restore could then bring back the matching env — today restoring a
      backup brings back whatever `.env` was on disk then, which no longer matches the
      manager. Also enables a diff of env between two releases.
- [ ] **Write-only secrets.** An `is_secret` flag per key: secret values are never sent
      back to the browser (UI shows `•••• (set)`, replaced only when a new value is typed),
      and revealing one requires re-entering the admin password. Closes the remaining gap
      that values are present in the page source even though the inputs are masked.
- [x] **Apply now / pull from live / drift check.** *(done)*
      *Apply now:* write the `.env` (and optionally run a hook such as `config:cache`)
      without a full deploy. *Pull from live:* import the target's current `.env` with its
      values, not just `.env.example`, for onboarding an existing site.
      *Drift check:* compare the on-disk `.env` with what the manager would render.
- [ ] **Pass the project's env to hooks.** `HookRunner::buildEnv()` currently only passes
      `getenv()` through. Merging the managed variables would let `composer`/`artisan`/
      `spark` hooks see them even before the file exists. Needs a decision on secrets
      appearing in process environments.
- [ ] **Value generators and validation.** Generate Laravel `APP_KEY` (`base64:` + 32 random
      bytes), CI4 `encryption.key`, random hex/passwords; optional per-key type
      (string/int/bool/url/email/regex) checked on save and in the deploy preflight; use the
      `#` comment above a key in the template as its hint text.
- [ ] **Shared variable groups.** Reusable sets (SMTP, S3, Sentry) attachable to several
      projects, with per-project overrides, so a shared credential is updated once.
- [ ] **Flexible output.** Configurable file name/path (`.env.production`, `app/.env`,
      CI4's `env`) and other formats: a PHP `config.local.php` returning an array, or
      `SetEnv` lines for `.htaccess`.
- [ ] **Encryption key rotation.** Prefix stored values with a key ID and add a re-encrypt
      action in Settings, so changing `SECRET_KEY` (or the password hash fallback — see
      DECISIONS.md) stops being permanent data loss.
- [x] **Editing quality of life.** *(done)* Raw-text bulk editor (parsed through `env_parse_pairs`),
      search/filter for long lists, drag-to-reorder, copy env from another project,
      encrypted export/import.

---

## Open Decisions

Carried over from the improvement plan, still unresolved:

- [ ] Symlink/cPanel compatibility needs confirming on a real target host before committing
      to the full P0.1 architecture — copy mode remains the safety-net default either way.
- [ ] Should a failing deploy hook always abort the deploy, or should individual hooks be
      markable optional/warning-only?
- [ ] General Settings persistence: currently config-file-based/derived at request time —
      confirm whether any of it should move into a dedicated `settings` DB table as the
      surface grows.

---

*Update this file when tasks are completed or new ones are discovered.*
