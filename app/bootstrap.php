<?php
// =============================================================================
// app/bootstrap.php — Application class loader
// =============================================================================
// Loaded by the CLI tera script and by public entry points.
// Provides a single require-once for all core classes.
// =============================================================================

$root = dirname(__DIR__);

require_once $root . '/config.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/Auth.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/Models/Project.php';
require_once $root . '/app/Models/Deployment.php';
require_once $root . '/app/Models/DeploymentLog.php';
require_once $root . '/app/Services/GitHubService.php';
require_once $root . '/app/Services/FileManager.php';
require_once $root . '/app/Services/BackupService.php';
require_once $root . '/app/Services/DeployService.php';
