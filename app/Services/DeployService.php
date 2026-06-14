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
     * @param  int     $projectId      Registered project ID
     * @param  string  $mode           'safe' or 'full'
     * @param  string  $triggeredBy    Username of the initiating admin
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
        DeploymentLog::info($deploymentId, "Deploy strategy: " . detect_deploy_strategy());

        $zipPath    = null;
        $extractDir = null;
        $backupPath = null;

        try {
            // ---- Step 2: Acquire zip archive -------------------------------
            if (isset($project['source_type']) && $project['source_type'] === 'manual') {
                DeploymentLog::info($deploymentId, "Using saved manual upload archive...");
                
                $archivePath = STORAGE_PATH . '/archives/project_' . $projectId . '.zip';
                if (!file_exists($archivePath)) {
                    throw new RuntimeException("No archive uploaded for this project yet. Please edit the project and upload a .zip file.");
                }
                
                // Copy the archive to TMP_PATH to prevent modifications from affecting the saved source artifact
                $zipPath = TMP_PATH . '/deploy_' . $projectId . '_' . time() . '.zip';
                if (!copy($archivePath, $zipPath)) {
                     throw new RuntimeException("Failed to copy manual archive to temporary build workspace.");
                }
            } else {
                // Clean up any orphaned partial zips from previous aborted deploys
                $this->cleanOrphanTmpFiles($project['name']);

                $branch = $project['branch'] ?? 'main';
                DeploymentLog::info($deploymentId, "Downloading archive from GitHub (branch: {$branch})...");
                $zipPath = $this->github->download($project);
            }

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
            $envManaged = ($project['env_mode'] ?? 'none') === 'managed';

            // When env manager is active, .env is written fresh every deploy —
            // remove it from safe_keep so the old file doesn't persist.
            if ($envManaged) {
                $skipPaths = array_values(array_filter(
                    $skipPaths,
                    fn (string $p) => ltrim($p, '/') !== '.env'
                ));
            }

            if ($mode === 'safe' && !empty($skipPaths)) {
                DeploymentLog::info($deploymentId, "Preserving paths: " . implode(', ', $skipPaths));
            }

            // PRAC.1 — auto-protect the deployer if it lives inside the target
            if (is_deployer_inside_target($targetDir)) {
                $deployerRel = deployer_relative_path($targetDir);
                if ($deployerRel !== '') {
                    $skipPaths[] = $deployerRel;
                    DeploymentLog::info($deploymentId, "[GUARD] Deployer is inside target — auto-protecting: {$deployerRel}");
                }
            }

            DeploymentLog::info($deploymentId, "Clearing target directory: {$targetDir}");
            $this->fileManager->clear($targetDir, $skipPaths);

            // ---- Step 7: Move new files into target ------------------------
            // safe_keep paths are skipped in both clear() and copyContents(),
            // so they are never deleted, overwritten, or moved — only backed up.
            DeploymentLog::info($deploymentId, "Copying new files to target...");
            $this->fileManager->copyContents($extractedRoot, $targetDir, $skipPaths);

            // ---- Step 7b: Write managed .env -------------------------------
            if ($envManaged) {
                $missing = EnvVar::missingRequired((int) $project['id']);
                if (!empty($missing)) {
                    throw new RuntimeException(
                        'Managed .env has missing required value(s): ' . implode(', ', $missing)
                    );
                }

                $envVarsAll    = EnvVar::allForProject((int) $project['id']);
                $dotEnvContent = EnvVar::renderDotEnv(
                    (int) $project['id'],
                    $project['env_template'] ?? null
                );
                $dotEnvPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . '.env';
                if (file_put_contents($dotEnvPath, $dotEnvContent) === false) {
                    throw new RuntimeException("Failed to write managed .env to: {$dotEnvPath}");
                }
                DeploymentLog::info($deploymentId, "Wrote managed .env (" . count($envVarsAll) . " variable(s)) to target.");
            }

            // ---- Step 7a: Run pre-deploy hooks -----------------------------
            $hookRunner = new HookRunner();
            $preHooks   = Project::getDeployHooks($project, 'pre');
            if (!empty($preHooks)) {
                DeploymentLog::info($deploymentId, "Running pre-deploy hooks (" . count($preHooks) . " command(s))...");
                $exit = $hookRunner->runAll($preHooks, $targetDir, function (string $chunk) use ($deploymentId): void {
                    $line = trim($chunk);
                    if ($line !== '') {
                        DeploymentLog::info($deploymentId, $line);
                    }
                });
                if ($exit !== 0) {
                    throw new RuntimeException("Pre-deploy hook failed with exit code {$exit}.");
                }
                DeploymentLog::info($deploymentId, "Pre-deploy hooks completed.");
            }

            // ---- Step 8: Set permissions -----------------------------------
            DeploymentLog::info($deploymentId, "Setting file permissions (dirs: 755, files: 644)...");
            $this->fileManager->setPermissions($targetDir);

            // ---- Step 8a: Run post-deploy hooks ----------------------------
            $postHooks = Project::getDeployHooks($project, 'post');
            if (!empty($postHooks)) {
                DeploymentLog::info($deploymentId, "Running post-deploy hooks (" . count($postHooks) . " command(s))...");
                $exit = $hookRunner->runAll($postHooks, $targetDir, function (string $chunk) use ($deploymentId): void {
                    $line = trim($chunk);
                    if ($line !== '') {
                        DeploymentLog::info($deploymentId, $line);
                    }
                });
                if ($exit !== 0) {
                    throw new RuntimeException("Post-deploy hook failed with exit code {$exit}.");
                }
                DeploymentLog::info($deploymentId, "Post-deploy hooks completed.");
            }

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
