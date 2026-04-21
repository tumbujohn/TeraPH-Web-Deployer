<?php
// =============================================================================
// FileManager — Archive extraction, file copy/move, and permission management
// =============================================================================

class FileManager
{
    /**
     * Extracts a zip archive to a given destination directory.
     *
     * GitHub archives contain a top-level folder (e.g., repo-main/).
     * This method strips that wrapper and extracts directly to $destDir.
     *
     * @param  string $zipPath  Path to the zip archive
     * @param  string $destDir  Destination directory (will be created if not exists)
     * @return string  Absolute path to the extracted content directory
     * @throws RuntimeException on extraction failure
     */
    public function extract(string $zipPath, string $destDir): string
    {
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
            throw new RuntimeException("Cannot create extraction directory: {$destDir}");
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw new RuntimeException("Cannot open zip archive: {$zipPath} (error code: {$result})");
        }

        $zip->extractTo($destDir);
        $zip->close();

        // Strip top-level wrapper folder GitHub adds (repo-branchname/)
        $contents = glob($destDir . '/*', GLOB_ONLYDIR);
        if (count($contents) === 1) {
            return $contents[0];
        }

        return $destDir;
    }

    /**
     * Validates that a set of expected file paths exist within a directory.
     *
     * @param  string   $dir           Directory to check
     * @param  string[] $expectedFiles Relative file paths that must exist
     * @throws RuntimeException if any expected file is missing
     */
    public function validateStructure(string $dir, array $expectedFiles): void
    {
        foreach ($expectedFiles as $file) {
            if (!file_exists($dir . '/' . ltrim($file, '/'))) {
                throw new RuntimeException(
                    "Structure validation failed: expected file not found: {$file}"
                );
            }
        }
    }

    /**
     * Copies all contents of $sourceDir into $targetDir recursively.
     * Creates $targetDir if it does not exist.
     *
     * @throws RuntimeException on copy failure
     */
    public function copyContents(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new RuntimeException("Cannot create target directory: {$targetDir}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destPath = $targetDir . '/' . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($destPath) && !mkdir($destPath, 0755, true)) {
                    throw new RuntimeException("Cannot create directory: {$destPath}");
                }
            } else {
                if (!copy($item->getRealPath(), $destPath)) {
                    throw new RuntimeException("Cannot copy file: {$item->getRealPath()} → {$destPath}");
                }
            }
        }
    }

    /**
     * Deletes all contents of a directory, optionally skipping listed paths.
     *
     * @param  string   $dir       Directory to clear
     * @param  string[] $skipPaths Relative paths to preserve (relative to $dir)
     * @throws RuntimeException on delete failure
     */
    public function clear(string $dir, array $skipPaths = []): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Normalise skip paths to absolute paths
        $skipAbsolute = array_map(fn($p) => rtrim($dir . '/' . ltrim($p, '/'), '/'), $skipPaths);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getRealPath();

            // Skip preserved paths and anything inside them
            foreach ($skipAbsolute as $skip) {
                if (str_starts_with($path, $skip)) {
                    continue 2;
                }
            }

            if ($item->isDir() && !$item->isLink()) {
                @rmdir($path);
            } else {
                if (!@unlink($path)) {
                    throw new RuntimeException("Cannot delete file: {$path}");
                }
            }
        }
    }

    /**
     * Sets file permissions recursively on a target directory.
     *
     * Directories → 0755, Files → 0644
     *
     * @param string $dir  Root directory to chmod recursively
     */
    public function setPermissions(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @chmod($item->getRealPath(), 0755);
            } else {
                @chmod($item->getRealPath(), 0644);
            }
        }

        @chmod($dir, 0755);
    }

    /**
     * Recursively deletes a directory and all its contents.
     *
     * @param string $dir  Directory to delete entirely
     */
    public function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($dir);
    }

    /**
     * Returns the total size of a directory in bytes.
     */
    public function directorySize(string $dir): int
    {
        $size = 0;

        if (!is_dir($dir)) {
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
