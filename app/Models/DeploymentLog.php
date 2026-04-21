<?php
// =============================================================================
// DeploymentLog Model
// =============================================================================

class DeploymentLog
{
    public const LEVEL_INFO    = 'INFO';
    public const LEVEL_WARNING = 'WARNING';
    public const LEVEL_ERROR   = 'ERROR';

    /**
     * Writes a single log entry to the database and flat-file simultaneously.
     *
     * @param int    $deploymentId  Associated deployment record ID
     * @param string $level         INFO | WARNING | ERROR
     * @param string $message       Human-readable log message
     */
    public static function write(int $deploymentId, string $level, string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');

        // --- Database write -------------------------------------------------
        try {
            $pdo  = Database::connect();
            $stmt = $pdo->prepare("
                INSERT INTO deployment_logs (deployment_id, level, message, logged_at)
                VALUES (:deployment_id, :level, :message, :logged_at)
            ");
            $stmt->execute([
                ':deployment_id' => $deploymentId,
                ':level'         => $level,
                ':message'       => $message,
                ':logged_at'     => $timestamp,
            ]);
        } catch (Throwable $e) {
            // Do not throw — flat file is the fallback
        }

        // --- Flat-file write (fallback) -------------------------------------
        $logFile = LOG_PATH . '/deployment_' . $deploymentId . '.log';
        $line    = sprintf("[%s] [%-7s] %s\n", $timestamp, $level, $message);
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Returns all log entries for a given deployment, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forDeployment(int $deploymentId): array
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("
            SELECT * FROM deployment_logs
            WHERE deployment_id = :deployment_id
            ORDER BY logged_at ASC, id ASC
        ");
        $stmt->execute([':deployment_id' => $deploymentId]);
        return $stmt->fetchAll();
    }

    /**
     * Reads the flat-file log for a deployment as a raw string.
     * Used as fallback when the database is unavailable.
     */
    public static function readFlatFile(int $deploymentId): string
    {
        $logFile = LOG_PATH . '/deployment_' . $deploymentId . '.log';
        return is_readable($logFile) ? file_get_contents($logFile) : '';
    }

    /**
     * Convenience method: write an INFO entry.
     */
    public static function info(int $deploymentId, string $message): void
    {
        self::write($deploymentId, self::LEVEL_INFO, $message);
    }

    /**
     * Convenience method: write a WARNING entry.
     */
    public static function warning(int $deploymentId, string $message): void
    {
        self::write($deploymentId, self::LEVEL_WARNING, $message);
    }

    /**
     * Convenience method: write an ERROR entry.
     */
    public static function error(int $deploymentId, string $message): void
    {
        self::write($deploymentId, self::LEVEL_ERROR, $message);
    }
}
