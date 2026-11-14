<?php
// =============================================================================
// EnvApplyService — write the managed .env without a full deploy
// =============================================================================
// "Apply now": renders the managed .env into the target directory and, if asked,
// runs the project's configured apply command (e.g. `php artisan config:cache`).
// Recorded as a deployment row (mode 'env_apply') so it has an audit trail,
// appears in the logs, and takes the same per-project lock as a deploy.
// =============================================================================

class EnvApplyService
{
    private HookRunner $hooks;

    public function __construct()
    {
        $this->hooks = new HookRunner();
    }

    /**
     * @param  bool $runCommand  Also run the project's configured apply command
     * @return array{success: bool, deployment_id: int, message: string}
     */
    public function apply(int $projectId, bool $runCommand, string $triggeredBy): array
    {
        $project = Project::find($projectId);
        if (!$project) {
            return $this->fail(0, "Project ID {$projectId} not found.");
        }
        if (($project['env_mode'] ?? 'none') !== 'managed') {
            return $this->fail(0, 'Managed .env is not enabled for this project.');
        }
        if (!is_dir($project['target_path'])) {
            return $this->fail(0, 'The target directory does not exist yet — run a deploy first.');
        }
        if (Deployment::isLocked($projectId)) {
            return $this->fail(0, "A deployment is already in progress for project: {$project['name']}");
        }

        $deploymentId = Deployment::create([
            'project_id'   => $projectId,
            'status'       => Deployment::STATUS_RUNNING,
            'mode'         => 'env_apply',
            'triggered_by' => $triggeredBy,
        ]);
        DeploymentLog::info($deploymentId, "Env apply started for project: {$project['name']}");

        try {
            EnvVar::assertDeployable($projectId);

            $count = EnvVar::writeToTarget($project);
            DeploymentLog::info($deploymentId, "Wrote managed .env ({$count} variable(s), mode 0640) to target.");
            if ($expansionWarning = EnvVar::expansionWarning($projectId)) {
                DeploymentLog::warning($deploymentId, $expansionWarning);
            }

            $commands = $runCommand ? $this->commandsFor($project) : [];
            if ($runCommand) {
                if (empty($commands)) {
                    DeploymentLog::info($deploymentId, 'No apply command configured — skipped.');
                } else {
                    DeploymentLog::info($deploymentId, 'Running apply command(s) (' . count($commands) . ')...');
                    $exit = $this->hooks->runAll(
                        $commands,
                        $project['target_path'],
                        function (string $chunk) use ($deploymentId): void {
                            $line = trim($chunk);
                            if ($line !== '') {
                                DeploymentLog::info($deploymentId, $line);
                            }
                        }
                    );
                    if ($exit !== 0) {
                        throw new RuntimeException("Apply command failed with exit code {$exit}. The .env was written.");
                    }
                    DeploymentLog::info($deploymentId, 'Apply command(s) completed.');
                }
            }

            DeploymentLog::info($deploymentId, 'Env apply completed successfully.');
            Deployment::updateStatus($deploymentId, Deployment::STATUS_SUCCESS);

            return [
                'success'       => true,
                'deployment_id' => $deploymentId,
                'message'       => "Wrote .env ({$count} variable(s))" . (!empty($commands) ? ' and ran the apply command' : '') . '.',
            ];
        } catch (Throwable $e) {
            DeploymentLog::error($deploymentId, 'Env apply failed: ' . $e->getMessage());
            Deployment::updateStatus($deploymentId, Deployment::STATUS_FAILED);
            return $this->fail($deploymentId, $e->getMessage());
        }
    }

    /**
     * The project's apply command(s), one per line.
     *
     * @return string[]
     */
    private function commandsFor(array $project): array
    {
        $raw = trim((string) ($project['env_apply_cmd'] ?? ''));
        return $raw === '' ? [] : (preg_split('/\R/', $raw) ?: []);
    }

    /**
     * @return array{success: false, deployment_id: int, message: string}
     */
    private function fail(int $deploymentId, string $message): array
    {
        return ['success' => false, 'deployment_id' => $deploymentId, 'message' => $message];
    }
}
