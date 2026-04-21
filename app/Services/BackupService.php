<?php
// =============================================================================
// BackupService — Pre-deploy backup creation and restore
// =============================================================================

class BackupService
{
    /**
     * Creates a zip backup of the target directory before a deployment.
     *
     * @param  array<string, mixed> $project     Project row
     * @param  int                  $deploymentId Deployment ID (for filename)
     * @return string  Absolute path to the created backup zip
     * @throws RuntimeException if the target directory is inaccessible or zip creation fails
     */
    public function create(array $project, int $deploymentId): string
    {
        $targetDir  = $project['target_path'];
        $timestamp  = date('YmdHis');
        $backupName = $project['name'] . '_' . $timestamp . '_' . $deploymentId . '.zip';
        $backupPath = BACKUP_PATH . '/' . $backupName;

        if (!is_dir($targetDir)) {
            // Nothing to back up — target does not exist yet (first deploy)
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create backup archive: {$backupPath}");
        }

        $this->addDirectoryToZip($zip, $targetDir, '');

        $zip->close();

        // Enforce retention limit
        $this->enforceRetentionLimit($project['name']);

        return $backupPath;
    }

    /**
     * Restores a backup zip to the project's target directory.
     * Always takes a safety snapshot of the current state before restoring.
     *
     * @param  array<string, mixed> $project       Project row
     * @param  string               $backupPath    Absolute path to the backup zip to restore
     * @param  int                  $deploymentId  Deployment record ID for logging
     * @throws RuntimeException on any restore error
     */
    public function restore(array $project, string $backupPath, int $deploymentId): void
    {
        if (!file_exists($backupPath)) {
            throw new RuntimeException("Backup file not found: {$backupPath}");
        }

        $fileManager = new FileManager();
        $targetDir   = $project['target_path'];

        // Safety backup of current state before restore
        $safetyName = $project['name'] . '_pre_restore_' . date('YmdHis') . '.zip';
        $safetyPath = BACKUP_PATH . '/' . $safetyName;
        if (is_dir($targetDir)) {
            $zip = new ZipArchive();
            $zip->open($safetyPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $this->addDirectoryToZip($zip, $targetDir, '');
            $zip->close();
        }

        // Clear target directory
        $fileManager->clear($targetDir);

        // Extract backup into target
        $extractDir = TMP_PATH . '/' . $project['name'] . '_restore_' . date('YmdHis');
        $extracted  = $fileManager->extract($backupPath, $extractDir);
        $fileManager->copyContents($extracted, $targetDir);
        $fileManager->setPermissions($targetDir);
        $fileManager->deleteDirectory($extractDir);
    }

    /**
     * Lists all backup files for a given project, newest first.
     *
     * @return array<int, array{name: string, path: string, size: int, created_at: string}>
     */
    public function listForProject(string $projectName): array
    {
        $pattern = BACKUP_PATH . '/' . $projectName . '_*.zip';
        $files   = glob($pattern) ?: [];

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        return array_map(fn($file) => [
            'name'       => basename($file),
            'path'       => $file,
            'size'       => filesize($file),
            'created_at' => date('Y-m-d H:i:s', filemtime($file)),
        ], $files);
    }

    /**
     * Deletes a specific backup file.
     *
     * @throws RuntimeException if the file cannot be deleted
     */
    public function delete(string $backupPath): void
    {
        // Security: ensure the path is within BACKUP_PATH
        $realBackup = realpath(BACKUP_PATH);
        $realFile   = realpath($backupPath);

        if ($realFile === false || !str_starts_with($realFile, $realBackup)) {
            throw new RuntimeException("Backup path is outside the backups directory: {$backupPath}");
        }

        if (!@unlink($realFile)) {
            throw new RuntimeException("Cannot delete backup: {$backupPath}");
        }
    }

    /**
     * Enforces the MAX_BACKUPS_PER_PROJECT retention limit.
     * Deletes oldest backups beyond the limit.
     */
    private function enforceRetentionLimit(string $projectName): void
    {
        $backups = $this->listForProject($projectName);
        $excess  = array_slice($backups, MAX_BACKUPS_PER_PROJECT);

        foreach ($excess as $backup) {
            @unlink($backup['path']);
        }
    }

    /**
     * Recursively adds a directory's contents to an open ZipArchive.
     *
     * @param ZipArchive $zip      Open ZipArchive instance
     * @param string     $dir      Absolute path to the directory to add
     * @param string     $zipRoot  Path prefix inside the zip archive
     */
    private function addDirectoryToZip(ZipArchive $zip, string $dir, string $zipRoot): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = $zipRoot . ($zipRoot ? '/' : '') . $iterator->getSubPathname();

            if ($item->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($item->getRealPath(), $relativePath);
            }
        }
    }
}
