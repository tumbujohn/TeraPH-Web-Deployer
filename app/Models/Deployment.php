<?php
// =============================================================================
// Deployment Model
// =============================================================================

class Deployment
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_RUNNING  = 'running';
    public const STATUS_SUCCESS  = 'success';
    public const STATUS_FAILED   = 'failed';

    /**
     * Creates a new deployment record and returns its ID.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("
            INSERT INTO deployments (project_id, status, mode, triggered_by, started_at)
            VALUES (:project_id, :status, :mode, :triggered_by, :started_at)
        ");
        $stmt->execute([
            ':project_id'   => $data['project_id'],
            ':status'       => $data['status'] ?? self::STATUS_PENDING,
            ':mode'         => $data['mode'] ?? 'safe',
            ':triggered_by' => $data['triggered_by'],
            ':started_at'   => date('Y-m-d H:i:s'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Updates the status of a deployment record.
     */
    public static function updateStatus(int $id, string $status, ?string $backupPath = null): void
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("
            UPDATE deployments
            SET status      = :status,
                finished_at = :finished_at,
                backup_path = :backup_path
            WHERE id = :id
        ");
        $stmt->execute([
            ':id'          => $id,
            ':status'      => $status,
            ':finished_at' => date('Y-m-d H:i:s'),
            ':backup_path' => $backupPath,
        ]);
    }

    /**
     * Finds a single deployment by ID.
     *
     * @return array<string, mixed>|false
     */
    public static function find(int $id): array|false
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("
            SELECT d.*, p.name AS project_name
            FROM deployments d
            JOIN projects p ON p.id = d.project_id
            WHERE d.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Returns all deployments for a given project, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forProject(int $projectId, int $limit = 20): array
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("
            SELECT * FROM deployments
            WHERE project_id = :project_id
            ORDER BY started_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':project_id', $projectId, PDO::PARAM_INT);
        $stmt->bindValue(':limit',      $limit,     PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Checks whether a deployment is currently running for a given project.
     * Also auto-releases stale locks older than LOCK_TIMEOUT seconds.
     */
    public static function isLocked(int $projectId): bool
    {
        $pdo = Database::connect();

        // Auto-release stale locks
        $cutoff = date('Y-m-d H:i:s', time() - LOCK_TIMEOUT);
        $stmt   = $pdo->prepare("
            UPDATE deployments
            SET status = :failed, finished_at = :now
            WHERE project_id = :project_id
              AND status     = :running
              AND started_at < :cutoff
        ");
        $stmt->execute([
            ':failed'     => self::STATUS_FAILED,
            ':now'        => date('Y-m-d H:i:s'),
            ':project_id' => $projectId,
            ':running'    => self::STATUS_RUNNING,
            ':cutoff'     => $cutoff,
        ]);

        // Check for an active lock
        $stmt = $pdo->prepare("
            SELECT id FROM deployments
            WHERE project_id = :project_id
              AND status     = :running
            LIMIT 1
        ");
        $stmt->execute([
            ':project_id' => $projectId,
            ':running'    => self::STATUS_RUNNING,
        ]);
        return $stmt->fetch() !== false;
    }

    /**
     * Returns the latest deployment for a given project.
     *
     * @return array<string, mixed>|false
     */
    public static function latest(int $projectId): array|false
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("
            SELECT * FROM deployments
            WHERE project_id = :project_id
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $stmt->execute([':project_id' => $projectId]);
        return $stmt->fetch();
    }

    /**
     * Alias of latest() — returns the most recent deployment for a project.
     * Used by logs.php when fetching logs by project rather than deployment ID.
     *
     * @return array<string, mixed>|false
     */
    public static function latestForProject(int $projectId): array|false
    {
        return self::latest($projectId);
    }
}
