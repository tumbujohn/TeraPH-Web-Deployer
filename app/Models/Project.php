<?php
// =============================================================================
// Project Model
// =============================================================================

class Project
{
    /**
     * Returns all projects ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $pdo  = Database::connect();
        $stmt = $pdo->query("
            SELECT p.*,
                   d.status       AS last_status,
                   d.finished_at  AS last_deployed_at
            FROM projects p
            LEFT JOIN deployments d
                   ON d.id = (
                       SELECT id FROM deployments
                       WHERE project_id = p.id
                       ORDER BY started_at DESC
                       LIMIT 1
                   )
            ORDER BY p.name ASC
        ");
        return $stmt->fetchAll();
    }

    /**
     * Finds a single project by ID.
     *
     * @return array<string, mixed>|false
     */
    public static function find(int $id): array|false
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Finds a project by unique name.
     *
     * @return array<string, mixed>|false
     */
    public static function findByName(string $name): array|false
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE name = :name LIMIT 1");
        $stmt->execute([':name' => $name]);
        return $stmt->fetch();
    }

    /**
     * Creates a new project record and returns the new row ID.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("
            INSERT INTO projects (name, source_type, repo_url, target_path, branch, safe_keep, github_pat, created_at)
            VALUES (:name, :source_type, :repo_url, :target_path, :branch, :safe_keep, :github_pat, :created_at)
        ");
        $stmt->execute([
            ':name'        => $data['name'],
            ':source_type' => $data['source_type'] ?? 'github',
            ':repo_url'    => $data['repo_url'] ?? '',
            ':target_path' => $data['target_path'],
            ':branch'      => $data['branch'] ?? 'main',
            ':safe_keep'   => $data['safe_keep'] ?? null,
            ':github_pat'  => $data['github_pat'] ?? null,
            ':created_at'  => date('Y-m-d H:i:s'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Updates an existing project's fields.
     *
     * If $data['github_pat'] is null/empty AND $data['keep_pat'] is true,
     * the existing PAT is retained unchanged.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $pdo = Database::connect();

        // If no new PAT was submitted, preserve the existing one
        $newPat = $data['github_pat'] ?? null;
        if (empty($newPat) && !empty($data['keep_pat'])) {
            $stmt = $pdo->prepare("
                UPDATE projects
                SET name        = :name,
                    source_type = :source_type,
                    repo_url    = :repo_url,
                    target_path = :target_path,
                    branch      = :branch,
                    safe_keep   = :safe_keep,
                    updated_at  = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':id'          => $id,
                ':name'        => $data['name'],
                ':source_type' => $data['source_type'] ?? 'github',
                ':repo_url'    => $data['repo_url'] ?? '',
                ':target_path' => $data['target_path'],
                ':branch'      => $data['branch'] ?? 'main',
                ':safe_keep'   => $data['safe_keep'] ?? null,
                ':updated_at'  => date('Y-m-d H:i:s'),
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE projects
                SET name        = :name,
                    source_type = :source_type,
                    repo_url    = :repo_url,
                    target_path = :target_path,
                    branch      = :branch,
                    safe_keep   = :safe_keep,
                    github_pat  = :github_pat,
                    updated_at  = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':id'          => $id,
                ':name'        => $data['name'],
                ':source_type' => $data['source_type'] ?? 'github',
                ':repo_url'    => $data['repo_url'] ?? '',
                ':target_path' => $data['target_path'],
                ':branch'      => $data['branch'] ?? 'main',
                ':safe_keep'   => $data['safe_keep'] ?? null,
                ':github_pat'  => $newPat ?: null,
                ':updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Deletes a project by ID. Associated deployments and logs are retained.
     */
    public static function delete(int $id): void
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("DELETE FROM projects WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /**
     * Checks whether a name is already taken, optionally excluding a given ID.
     */
    public static function nameExists(string $name, ?int $excludeId = null): bool
    {
        $pdo  = Database::connect();
        $sql  = "SELECT id FROM projects WHERE name = :name";
        $params = [':name' => $name];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }

    /**
     * Returns the safe_keep paths for a project as a PHP array.
     *
     * @return string[]
     */
    public static function getSafeKeepPaths(array $project): array
    {
        if (empty($project['safe_keep'])) {
            return ['.env', 'uploads/', 'storage/', 'writable/'];
        }

        $decoded = json_decode($project['safe_keep'], true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fallback: comma-separated string
        return array_map('trim', explode(',', $project['safe_keep']));
    }
}
