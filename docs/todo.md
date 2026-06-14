# TeraPH Web Deployer — PRAC Implementation Tracker

**Phase:** PRAC (Practical Blockers)
**Started:** 2026-06-14
**Completed:** 2026-06-14
**Plan reference:** [improvement_plan.md](improvement_plan.md)

---

## PRAC.1 — Deployer Self-Protection

- [x] `DEPLOYER_ROOT` constant added to `config.php` + `config.php.example`
- [x] `is_deployer_inside_target()` helper added to `app/helpers.php`
- [x] `deployer_relative_path()` helper added to `app/helpers.php`
- [x] `DeployService` auto-injects deployer dir into `$skipPaths` when overlap detected
- [x] `BackupService` excludes deployer directory from pre-deploy zip (+ fixed `getSubPathname` P1013)
- [x] Warning badge on project card in `public/index.php` when overlap detected
- [x] Settings link added to topbar in `public/index.php`
- [x] `public/settings.php` — General Settings page created

## PRAC.2 — Deploy Strategy Auto-Detection

- [x] `DEPLOY_STRATEGY` constant added to `config.php` + `config.php.example`
- [x] `detect_deploy_strategy()` helper added to `app/helpers.php` (symlink test + cache)
- [x] `DeployService` logs resolved strategy at deploy start

## PRAC.3 — Enhanced Terminal + Composer.phar

- [x] `migrations/003_project_hooks_terminal.php` — `terminal_enabled` column + `terminal_logs` table
- [x] `app/Services/HookRunner.php` created (`resolveComposer`, `run`, `runAll`)
- [x] `app/bootstrap.php` updated to require `HookRunner.php`
- [x] `TERMINAL_ENABLED` + `TERMINAL_TIMEOUT` + `HOOK_TIMEOUT` added to config files
- [x] `public/terminal_execute.php` — `TERMINAL_ENABLED` site-wide guard
- [x] `public/terminal_execute.php` — per-project `terminal_enabled` guard
- [x] `public/terminal_execute.php` — command audit log (`terminal_logs` table + flat-file fallback)
- [x] `public/terminal_execute.php` — `{composer}` placeholder resolved via `HookRunner::resolveComposer()`
- [x] `public/index.php` — composer shortcut toolbar added to terminal modal
- [x] `public/assets/js/terminal.js` — shortcut button injects command into input
- [x] `public/assets/js/terminal.js` — "↓ Get composer.phar" button handler
- [x] `public/composer_download.php` — download endpoint with SHA256 verification

## PRAC.4 — Deployment Templates

- [x] `migrations/003` — `deploy_template`, `pre_deploy_hooks`, `post_deploy_hooks` columns on `projects`
- [x] `app/Models/Project.php` — `create()` + `update()` handle new columns
- [x] `app/Models/Project.php` — `getDeployHooks(project, phase)` static method added
- [x] `public/projects.php` — `sanitizeProjectInput` handles template + hook fields
- [x] `public/index.php` — deployment template dropdown in project form
- [x] `public/index.php` — pre/post hook textareas in project form
- [x] `public/assets/js/app.js` — edit handler populates template + hook fields
- [x] `public/assets/js/app.js` — template selector pre-fills textareas from presets
- [x] `app/Services/DeployService.php` — Step 7a: run `pre_deploy_hooks` via `HookRunner`
- [x] `app/Services/DeployService.php` — Step 8a: run `post_deploy_hooks` via `HookRunner`

---

## Status Legend

- [ ] Pending
- [x] Done

---

*Last updated: 2026-06-14*


