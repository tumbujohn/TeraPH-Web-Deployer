<?php
// =============================================================================
// DeployService — Deployment Pipeline Orchestrator
// =============================================================================
// Executes the full 10-step deployment pipeline for a given project.
// All pipeline steps are logged in real-time to both the DB and flat file.
// =============================================================================

class DeployService
{
    private GitHubService $github;
    private FileManager   $fileManager;
    private BackupService $backup;

    public function __construct()
    {
        $this->github      = new GitHubService();
        $this->fileManager = new FileManager();
        $this->backup      = new BackupService();
    }

    /**
     * Executes the full deployment pipeline.
     *
     * @param  int    $projectId      Registered project ID
     * @param  string $mode           'safe' or 'full'
     * @param  string $triggeredBy    Username of the initiating admin
     * @return array{success: bool, deployment_id: int, message: string}
     */
    public function deploy(int $projectId, string $mode, string $triggeredBy): array
    {
        // ---- Resolve project -----------------------------------------------
        $project = Project::find($projectId);
        if (!$project) {
            return $this->fail(0, "Project ID {$projectId} not found.");
        }

        // ---- Step 1: Acquire lock ------------------------------------------
        if (Deployment::isLocked($projectId)) {
            return $this->fail(0, "A deployment is already in progress for project: {$project['name']}");
        }

        // Create deployment record (status: running)
        $deploymentId = Deployment::create([
            'project_id'   => $projectId,
            'status'       => Deployment::STATUS_RUNNING,
            'mode'         => $mode,
            'triggered_by' => $triggeredBy,
        ]);

        DeploymentLog::info($deploymentId, "Deployment started for project: {$project['name']} (mode: {$mode})");
        DeploymentLog::info($deploymentId, "Lock acquired.");

        $zipPath    = null;
        $extractDir = null;
        $backupPath = null;

        try {
            // ---- Step 2: Download repo zip ---------------------------------
            // Clean up any orphaned partial zips from previous aborted deploys
            $this->cleanOrphanTmpFiles($project['name']);

            $branch = $project['branch'] ?? 'main';
            DeploymentLog::info($deploymentId, "Downloading archive from GitHub (branch: {$branch})...");
            $zipPath = $this->github->download($project);

            if (!file_exists($zipPath) || filesize($zipPath) < 100) {
                throw new RuntimeException("Downloaded zip is missing or empty: {$zipPath}");
            }

            $size = number_format(filesize($zipPath) / 1024 / 1024, 2);
            DeploymentLog::info($deploymentId, "Download complete: {$size} MB → " . basename($zipPath));

            // ---- Step 3: Extract to temp folder ----------------------------
            $extractDir = TMP_PATH . '/' . $project['name'] . '_' . date('YmdHis') . '_extracted';
            DeploymentLog::info($deploymentId, "Extracting archive...");
            $extractedRoot = $this->fileManager->extract($zipPath, $extractDir);
            DeploymentLog::info($deploymentId, "Extracted to: {$extractedRoot}");

            // ---- Step 4: Validate structure --------------------------------
            // Validates that at least one file exists in the extraction
            if (!is_dir($extractedRoot) || count(scandir($extractedRoot)) <= 2) {
                throw new RuntimeException("Extracted archive appears empty. Check repository contents.");
            }
            DeploymentLog::info($deploymentId, "Structure validation passed.");

            // ---- Step 5: Backup current version ----------------------------
            DeploymentLog::info($deploymentId, "Creating pre-deploy backup...");
            $backupPath = $this->backup->create($project, $deploymentId);
            if ($backupPath) {
                $backupSize = number_format(filesize($backupPath) / 1024 / 1024, 2);
                DeploymentLog::info($deploymentId, "Backup created: " . basename($backupPath) . " ({$backupSize} MB)");
            } else {
                DeploymentLog::info($deploymentId, "Target directory does not exist yet — skipping backup (first deploy).");
            }

            // ---- Step 6: Clear target directory ----------------------------
            $targetDir  = $project['target_path'];
            $skipPaths  = ($mode === 'safe') ? Project::getSafeKeepPaths($project) : [];

            // Stash safe_keep files aside temporarily
            $stashDir = null;
            if ($mode === 'safe' && !empty($skipPaths)) {
                $stashDir = TMP_PATH . '/' . $project['name'] . '_stash_' . date('YmdHis');
                $this->stashPaths($targetDir, $stashDir, $skipPaths);
                DeploymentLog::info($deploymentId, "Stashed preserved paths: " . implode(', ', $skipPaths));
            }

            DeploymentLog::info($deploymentId, "Clearing target directory: {$targetDir}");
            $this->fileManager->clear($targetDir);

            // ---- Step 7: Move new files into target ------------------------
            DeploymentLog::info($deploymentId, "Copying new files to target...");
            $this->fileManager->copyContents($extractedRoot, $targetDir);

            // Restore stashed safe_keep paths
            if ($stashDir && is_dir($stashDir)) {
                $this->restoreStash($stashDir, $targetDir, $skipPaths);
                $this->fileManager->deleteDirectory($stashDir);
                DeploymentLog::info($deploymentId, "Restored preserved paths.");
            }

            // ---- Step 8: Set permissions -----------------------------------
            DeploymentLog::info($deploymentId, "Setting file permissions (dirs: 755, files: 644)...");
            $this->fileManager->setPermissions($targetDir);

            // ---- Step 9: Log success + unlock ------------------------------
            DeploymentLog::info($deploymentId, "Deployment completed successfully.");
            Deployment::updateStatus($deploymentId, Deployment::STATUS_SUCCESS, $backupPath ?: null);

        } catch (Throwable $e) {
            // ---- Error handler: log and fail gracefully --------------------
            DeploymentLog::error($deploymentId, "PIPELINE ERROR: " . $e->getMessage());
            DeploymentLog::info($deploymentId, "Deployment halted. Lock released.");
            Deployment::updateStatus($deploymentId, Deployment::STATUS_FAILED, $backupPath ?: null);

            return $this->fail($deploymentId, $e->getMessage());

        } finally {
            // ---- Step 10: Cleanup temp files (always) ----------------------
            if ($zipPath && file_exists($zipPath)) {
                @unlink($zipPath);
            }
            if ($extractDir && is_dir($extractDir)) {
                $this->fileManager->deleteDirectory($extractDir);
            }
        }

        return [
            'success'       => true,
            'deployment_id' => $deploymentId,
            'message'       => "Project '{$project['name']}' deployed successfully.",
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Moves safe_keep paths from the target directory to a stash directory.
     *
     * @param string   $sourceDir   Target directory
     * @param string   $stashDir    Temporary stash directory
     * @param string[] $paths       Relative paths to stash
     */
    private function stashPaths(string $sourceDir, string $stashDir, array $paths): void
    {
        if (!is_dir($stashDir)) {
            mkdir($stashDir, 0755, true);
        }

        foreach ($paths as $path) {
            $src  = rtrim($sourceDir, '/') . '/' . ltrim($path, '/');
            $dest = rtrim($stashDir, '/')  . '/' . ltrim($path, '/');

            if (!file_exists($src)) {
                continue;
            }

            $destParent = dirname($dest);
            if (!is_dir($destParent)) {
                mkdir($destParent, 0755, true);
            }

            rename($src, $dest);
        }
    }

    /**
     * Moves stashed paths back from the stash directory to the target directory.
     *
     * @param string   $stashDir   Stash directory
     * @param string   $targetDir  Target directory
     * @param string[] $paths      Relative paths to restore
     */
    private function restoreStash(string $stashDir, string $targetDir, array $paths): void
    {
        foreach ($paths as $path) {
            $src  = rtrim($stashDir,  '/') . '/' . ltrim($path, '/');
            $dest = rtrim($targetDir, '/') . '/' . ltrim($path, '/');

            if (!file_exists($src)) {
                continue;
            }

            $destParent = dirname($dest);
            if (!is_dir($destParent)) {
                mkdir($destParent, 0755, true);
            }

            rename($src, $dest);
        }
    }

    /**
     * Removes orphaned/partial zip files from a previous aborted deployment
     * for the same project. Files matching {project-name}_*.zip that are older
     * than DOWNLOAD_TIMEOUT seconds are considered stale and deleted.
     */
    private function cleanOrphanTmpFiles(string $projectName): void
    {
        $pattern  = TMP_PATH . '/' . $projectName . '_*.zip';
        $staleAge = defined('DOWNLOAD_TIMEOUT') ? (int) DOWNLOAD_TIMEOUT : 120;

        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file) && (time() - filemtime($file)) > $staleAge) {
                @unlink($file);
            }
        }
    }

    /**
     * Builds a normalized failure response array.
     */
    private function fail(int $deploymentId, string $message): array
    {
        return [
            'success'       => false,
            'deployment_id' => $deploymentId,
            'message'       => $message,
        ];
    }
}
