<?php
// =============================================================================
// Migration 006 — Scrub values out of stored .env templates
//
// Earlier versions kept pasted .env text, values included, in
// projects.env_template (plaintext). The template is now structure-only. This
// blanks any remaining values via EnvVar::scrubTemplate(), which first saves
// (encrypted) any value the template alone was supplying, so the deployed
// .env does not change.
//
// Idempotent: once scrubbed, there is nothing left to do. It runs on every
// request like the other migrations, so it must be cheap and must never be
// able to break a page load — any failure is swallowed and retried next time.
// =============================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/Models/EnvVar.php';

try {
    $pdo  = Database::connect();
    $rows = $pdo->query(
        "SELECT id, env_template FROM projects WHERE env_template IS NOT NULL AND env_template != ''"
    )->fetchAll();

    foreach ($rows as $row) {
        if (env_template_has_values((string) $row['env_template'])) {
            $saved = EnvVar::scrubTemplate((int) $row['id']);
            echo "Scrubbed env template for project #{$row['id']}"
               . ($saved ? ' (saved from template: ' . implode(', ', $saved) . ')' : '') . ".\n";
        }
    }
} catch (Throwable $e) {
    echo 'Env template scrub skipped: ' . $e->getMessage() . "\n";
}
