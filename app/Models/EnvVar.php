<?php
// =============================================================================
// EnvVar Model — Per-project .env variable storage
// =============================================================================

class EnvVar
{
    /**
     * Returns all env vars for a project, ordered by sort_order.
     * Values are decrypted before being returned.
     *
     * @return array<int, array{id:int, key_name:string, value:string, is_required:int, sort_order:int}>
     */
    public static function allForProject(int $projectId): array
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("
            SELECT id, key_name, value_enc, is_required, sort_order
            FROM   project_env_vars
            WHERE  project_id = :pid
            ORDER  BY sort_order ASC, key_name ASC
        ");
        $stmt->execute([':pid' => $projectId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['value']     = env_decrypt((string) ($row['value_enc'] ?? ''));
            $row['is_required'] = (int) $row['is_required'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Inserts or updates a single key for a project.
     */
    public static function upsert(
        int    $projectId,
        string $key,
        string $value,
        bool   $required  = false,
        int    $sortOrder = 0
    ): void {
        $pdo = Database::connect();
        $enc = env_encrypt($value);
        $now = date('Y-m-d H:i:s');

        // Try update first
        $stmt = $pdo->prepare("
            UPDATE project_env_vars
            SET    value_enc = :enc, is_required = :req, sort_order = :ord, updated_at = :now
            WHERE  project_id = :pid AND key_name = :key
        ");
        $stmt->execute([
            ':enc' => $enc,
            ':req' => (int) $required,
            ':ord' => $sortOrder,
            ':now' => $now,
            ':pid' => $projectId,
            ':key' => $key,
        ]);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO project_env_vars (project_id, key_name, value_enc, is_required, sort_order, updated_at)
                VALUES (:pid, :key, :enc, :req, :ord, :now)
            ");
            $stmt->execute([
                ':pid' => $projectId,
                ':key' => $key,
                ':enc' => $enc,
                ':req' => (int) $required,
                ':ord' => $sortOrder,
                ':now' => $now,
            ]);
        }
    }

    /**
     * Syncs keys from a parsed template list.
     *
     * - Adds rows for keys not yet stored (empty value).
     * - Removes rows for keys no longer in the template.
     * - Existing values for retained keys are preserved.
     * - Updates sort_order to match the template order.
     *
     * @param string[] $keys Ordered list of key names
     */
    public static function syncKeys(int $projectId, array $keys): void
    {
        $pdo      = Database::connect();
        $existing = [];

        $stmt = $pdo->prepare("SELECT key_name FROM project_env_vars WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
        foreach ($stmt->fetchAll() as $row) {
            $existing[] = $row['key_name'];
        }

        // Insert new keys
        $now = date('Y-m-d H:i:s');
        foreach ($keys as $i => $key) {
            if (!in_array($key, $existing, true)) {
                $ins = $pdo->prepare("
                    INSERT INTO project_env_vars (project_id, key_name, value_enc, is_required, sort_order, updated_at)
                    VALUES (:pid, :key, '', 0, :ord, :now)
                ");
                $ins->execute([':pid' => $projectId, ':key' => $key, ':ord' => $i, ':now' => $now]);
            } else {
                // Update sort order only
                $upd = $pdo->prepare("
                    UPDATE project_env_vars SET sort_order = :ord WHERE project_id = :pid AND key_name = :key
                ");
                $upd->execute([':ord' => $i, ':pid' => $projectId, ':key' => $key]);
            }
        }

        // Remove keys that are no longer in template
        $gone = array_diff($existing, $keys);
        foreach ($gone as $key) {
            $del = $pdo->prepare("DELETE FROM project_env_vars WHERE project_id = :pid AND key_name = :key");
            $del->execute([':pid' => $projectId, ':key' => $key]);
        }
    }

    /**
     * Builds the final .env file content by merging stored (decrypted) values
     * into the template structure. If no template is stored, falls back to a
     * simple KEY=value listing of all stored vars.
     */
    public static function renderDotEnv(int $projectId, ?string $template): string
    {
        $vars   = self::allForProject($projectId);
        $values = [];
        foreach ($vars as $v) {
            $values[$v['key_name']] = $v['value'];
        }

        if (!empty($template)) {
            return env_render($template, $values);
        }

        // No template — plain list
        $lines = [];
        foreach ($vars as $v) {
            $val = $v['value'];
            if (preg_match('/[\s"\'#]/', $val)) {
                $val = '"' . addcslashes($val, '"\\') . '"';
            }
            $lines[] = $v['key_name'] . '=' . $val;
        }
        return implode("\n", $lines) . (count($lines) ? "\n" : '');
    }

    /**
     * Validates that all required keys have non-empty values.
     *
     * @return string[] List of missing required key names
     */
    public static function missingRequired(int $projectId): array
    {
        $vars    = self::allForProject($projectId);
        $missing = [];
        foreach ($vars as $v) {
            if ($v['is_required'] && $v['value'] === '') {
                $missing[] = $v['key_name'];
            }
        }
        return $missing;
    }

    /**
     * Deletes all env vars for a project (used when a project is deleted).
     */
    public static function deleteAllForProject(int $projectId): void
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("DELETE FROM project_env_vars WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
    }
}
