<?php
// =============================================================================
// TeraPH Web Deployer — Database Layer (PDO Factory)
// =============================================================================
// DB-agnostic PDO factory. Switch from SQLite to MySQL by changing
// DB_DRIVER in config.php — no code changes required.
// =============================================================================

class Database
{
    private static ?PDO $instance = null;

    /**
     * Returns the singleton PDO connection.
     * Creates storage directories and runs migrations on first call.
     *
     * @throws RuntimeException on connection failure
     */
    public static function connect(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::ensureStorageDirs();

        $dsn = self::buildDsn();

        try {
            self::$instance = new PDO($dsn, DB_USER ?? null, DB_PASS ?? null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }

        return self::$instance;
    }

    /**
     * Builds the DSN string based on DB_DRIVER configuration.
     */
    private static function buildDsn(): string
    {
        if (DB_DRIVER === 'mysql') {
            return sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );
        }

        // Default: SQLite
        return 'sqlite:' . DB_PATH;
    }

    /**
     * Ensures all required storage directories exist and are writable.
     * Also creates the SQLite database file parent directory.
     */
    private static function ensureStorageDirs(): void
    {
        $dirs = [STORAGE_PATH, BACKUP_PATH, TMP_PATH, LOG_PATH];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                throw new RuntimeException("Cannot create storage directory: {$dir}");
            }
        }
    }

    /**
     * Resets the singleton — useful for testing.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
