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
