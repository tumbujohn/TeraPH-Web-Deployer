<?php
// =============================================================================
// GitHubService — Downloads repository zip archives from GitHub via cURL
// =============================================================================

class GitHubService
{
    /**
     * Downloads the repository zip for the given project and saves it to
     * the TMP storage directory.
     *
     * @param  array<string, mixed> $project  Project row from the database
     * @return string  Absolute path to the downloaded zip file
     * @throws RuntimeException on download failure
     */
    public function download(array $project): string
    {
        $pat      = trim($project['github_pat'] ?? '') ?: trim(GITHUB_PAT);
        $destPath = TMP_PATH . '/' . $project['name'] . '_' . date('YmdHis') . '.zip';

        $fh = fopen($destPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open temp file for writing: {$destPath}");
        }

        // Use GitHub API endpoint when a PAT is present (private repos).
        // The API handles auth through redirects correctly.
        // Fall back to the direct archive URL for public repos.
        $url     = !empty($pat) ? $this->buildApiUrl($project) : $this->buildZipUrl($project);
        $headers = $this->buildHeaders($pat);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL               => $url,
            CURLOPT_FILE              => $fh,
            CURLOPT_FOLLOWLOCATION    => true,
            CURLOPT_MAXREDIRS         => 10,
            CURLOPT_TIMEOUT           => DOWNLOAD_TIMEOUT,
            CURLOPT_USERAGENT         => 'TeraPH-Deployer/1.0',
            // Keep auth headers across redirects (required: github.com → codeload.github.com)
            CURLOPT_UNRESTRICTED_AUTH => true,
            CURLOPT_HTTPHEADER        => $headers,
            // CURL_SSL_VERIFY is false in DEV_MODE (Windows CA bundle workaround).
            // Always true in production (DEV_MODE = false in config.php).
            CURLOPT_SSL_VERIFYPEER    => CURL_SSL_VERIFY,
            CURLOPT_SSL_VERIFYHOST    => CURL_SSL_VERIFY ? 2 : 0,
            CURLOPT_FAILONERROR       => true,
        ]);

        $success    = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        $fileSize   = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);

        curl_close($ch);
        fclose($fh);

        if (!$success || $httpStatus >= 400) {
            @unlink($destPath);

            // Provide a more actionable error for common cases
            $hint = match (true) {
                $httpStatus === 401 => ' (401 Unauthorized — check your GitHub PAT has "repo" scope)',
                $httpStatus === 403 => ' (403 Forbidden — PAT may lack permissions for this repository)',
                $httpStatus === 404 => ' (404 Not Found — check the repo URL and branch name; private repos require a PAT)',
                default             => '',
            };

            throw new RuntimeException(
                "Download failed (HTTP {$httpStatus}). URL: {$url}. Error: {$curlError}{$hint}"
            );
        }

        if ($fileSize < 100) {
            @unlink($destPath);
            throw new RuntimeException(
                "Downloaded file is suspiciously small ({$fileSize} bytes). The repository may be empty or the branch does not exist."
            );
        }

        return $destPath;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Builds the GitHub REST API download URL for authenticated (private) repos.
     *
     * Format: https://api.github.com/repos/{owner}/{repo}/zipball/{branch}
     * Docs: https://docs.github.com/en/rest/repos/contents#download-a-repository-archive-zip
     */
    private function buildApiUrl(array $project): string
    {
        [$owner, $repo] = $this->parseOwnerRepo($project['repo_url']);
        $branch         = $project['branch'] ?? 'main';

        return "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$branch}";
    }

    /**
     * Builds the direct GitHub archive zip URL for public repos.
     *
     * Format: https://github.com/{owner}/{repo}/archive/refs/heads/{branch}.zip
     */
    private function buildZipUrl(array $project): string
    {
        $repoUrl = $project['repo_url'];

        // If it's already a direct .zip link, use it as-is
        if (str_ends_with($repoUrl, '.zip')) {
            return $repoUrl;
        }

        // Strip trailing .git suffix (e.g. from copy-pasting a clone URL)
        $base   = rtrim(preg_replace('/\.git$/', '', $repoUrl), '/');
        $branch = $project['branch'] ?? 'main';

        return "{$base}/archive/refs/heads/{$branch}.zip";
    }

    /**
     * Builds the required cURL HTTP headers.
     * Includes Authorization and GitHub API version headers when a PAT is set.
     *
     * @return string[]
     */
    private function buildHeaders(string $pat): array
    {
        $headers = [
            'User-Agent: TeraPH-Deployer/1.0',
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if (!empty($pat)) {
            // Bearer works for both classic PATs (ghp_) and fine-grained PATs (github_pat_)
            $headers[] = 'Authorization: Bearer ' . $pat;
        }

        return $headers;
    }

    /**
     * Parses {owner} and {repo} from a GitHub URL.
     *
     * Handles:
     *   https://github.com/owner/repo
     *   https://github.com/owner/repo.git
     *   git@github.com:owner/repo.git
     *
     * @return string[]  [owner, repo]
     * @throws RuntimeException if the URL cannot be parsed
     */
    private function parseOwnerRepo(string $url): array
    {
        // Strip .git suffix
        $url = preg_replace('/\.git$/', '', $url);

        // HTTPS format: https://github.com/owner/repo
        if (preg_match('#github\.com/([^/]+)/([^/]+)#', $url, $m)) {
            return [$m[1], $m[2]];
        }

        // SSH format: git@github.com:owner/repo
        if (preg_match('#github\.com:([^/]+)/([^/]+)#', $url, $m)) {
            return [$m[1], $m[2]];
        }

        throw new RuntimeException(
            "Cannot parse owner/repo from URL: {$url}. Expected format: https://github.com/owner/repo"
        );
    }
}
