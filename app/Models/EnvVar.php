<?php
// =============================================================================
// EnvVar Model — Per-project .env variable storage
// =============================================================================

class EnvVar
{
    /**
     * Returns all env vars for a project, ordered by sort_order.
     * Values are decrypted before being returned.
     *
     * A value that cannot be decrypted (wrong SECRET_KEY, corrupt data) comes back
     * as '' with decrypt_failed = true, so callers can tell it from a blank value.
     *
     * @return array<int, array{id:int, key_name:string, value:string, is_required:int, sort_order:int, decrypt_failed:bool}>
     */
    public static function allForProject(int $projectId): array
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("
            SELECT id, key_name, value_enc, is_required, sort_order
            FROM   project_env_vars
            WHERE  project_id = :pid
            ORDER  BY sort_order ASC, key_name ASC
        ");
        $stmt->execute([':pid' => $projectId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $plain                 = env_decrypt_strict((string) ($row['value_enc'] ?? ''));
            $row['value']          = $plain ?? '';
            $row['decrypt_failed'] = $plain === null;
            $row['is_required']    = (int) $row['is_required'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Inserts or updates a single key for a project.
     *
     * $required = null leaves an existing key's required flag untouched (and
     * inserts new keys as not required); pass true/false to set it explicitly.
     */
    public static function upsert(
        int    $projectId,
        string $key,
        string $value,
        ?bool  $required  = null,
        int    $sortOrder = 0
    ): void {
        $pdo = Database::connect();
        $enc = env_encrypt($value);
        $now = date('Y-m-d H:i:s');

        // Try update first
        $reqSql = $required === null ? '' : 'is_required = :req, ';
        $stmt   = $pdo->prepare("
            UPDATE project_env_vars
            SET    value_enc = :enc, {$reqSql}sort_order = :ord, updated_at = :now
            WHERE  project_id = :pid AND key_name = :key
        ");
        $params = [
            ':enc' => $enc,
            ':ord' => $sortOrder,
            ':now' => $now,
            ':pid' => $projectId,
            ':key' => $key,
        ];
        if ($required !== null) {
            $params[':req'] = (int) $required;
        }
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO project_env_vars (project_id, key_name, value_enc, is_required, sort_order, updated_at)
                VALUES (:pid, :key, :enc, :req, :ord, :now)
            ");
            $stmt->execute([
                ':pid' => $projectId,
                ':key' => $key,
                ':enc' => $enc,
                ':req' => (int) ($required ?? false),
                ':ord' => $sortOrder,
                ':now' => $now,
            ]);
        }
    }

    /**
     * Syncs keys from a parsed template list.
     *
     * - Adds rows for keys not yet stored (empty value).
     * - Removes rows for keys no longer in the template.
     * - Existing values for retained keys are preserved.
     * - Updates sort_order to match the template order.
     *
     * @param string[] $keys Ordered list of key names
     */
    public static function syncKeys(int $projectId, array $keys): void
    {
        $pdo      = Database::connect();
        $existing = [];

        $stmt = $pdo->prepare("SELECT key_name FROM project_env_vars WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
        foreach ($stmt->fetchAll() as $row) {
            $existing[] = $row['key_name'];
        }

        // Insert new keys
        $now = date('Y-m-d H:i:s');
        foreach ($keys as $i => $key) {
            if (!in_array($key, $existing, true)) {
                $ins = $pdo->prepare("
                    INSERT INTO project_env_vars (project_id, key_name, value_enc, is_required, sort_order, updated_at)
                    VALUES (:pid, :key, '', 0, :ord, :now)
                ");
                $ins->execute([':pid' => $projectId, ':key' => $key, ':ord' => $i, ':now' => $now]);
            } else {
                // Update sort order only
                $upd = $pdo->prepare("
                    UPDATE project_env_vars SET sort_order = :ord WHERE project_id = :pid AND key_name = :key
                ");
                $upd->execute([':ord' => $i, ':pid' => $projectId, ':key' => $key]);
            }
        }

        // Remove keys that are no longer in template
        $gone = array_diff($existing, $keys);
        foreach ($gone as $key) {
            $del = $pdo->prepare("DELETE FROM project_env_vars WHERE project_id = :pid AND key_name = :key");
            $del->execute([':pid' => $projectId, ':key' => $key]);
        }
    }

    /**
     * Builds the final .env file content by merging stored (decrypted) values
     * into the template structure. If no template is stored, falls back to a
     * simple KEY=value listing of all stored vars.
     */
    public static function renderDotEnv(int $projectId, ?string $template): string
    {
        $vars   = self::allForProject($projectId);
        $values = [];
        foreach ($vars as $v) {
            $values[$v['key_name']] = $v['value'];
        }

        if (!empty($template)) {
            return env_render($template, $values);
        }

        // No template — plain list
        $lines = [];
        foreach ($vars as $v) {
            $lines[] = $v['key_name'] . '=' . env_quote_value($v['value']);
        }
        return implode("\n", $lines) . (count($lines) ? "\n" : '');
    }

    /**
     * Validates that all required keys have non-empty values.
     *
     * @return string[] List of missing required key names
     */
    public static function missingRequired(int $projectId): array
    {
        $vars    = self::allForProject($projectId);
        $missing = [];
        foreach ($vars as $v) {
            if ($v['is_required'] && $v['value'] === '') {
                $missing[] = $v['key_name'];
            }
        }
        return $missing;
    }

    /**
     * Keys whose stored value is present but cannot be decrypted. Deploying with
     * these would silently write empty values, so the pipeline refuses to.
     *
     * @return string[]
     */
    public static function undecryptable(int $projectId): array
    {
        $bad = [];
        foreach (self::allForProject($projectId) as $v) {
            if ($v['decrypt_failed']) {
                $bad[] = $v['key_name'];
            }
        }
        return $bad;
    }

    /**
     * Compares the managed keys against a release's .env.example.
     *
     * - missing: in .env.example but not managed (would never reach the .env)
     * - empty:   managed, in .env.example, but has no value
     * - unused:  managed but no longer in .env.example
     *
     * @return array{missing:string[], empty:string[], unused:string[]}
     */
    public static function compareWithExample(int $projectId, string $exampleContent): array
    {
        $exampleKeys = env_parse_keys($exampleContent);
        $stored      = [];
        foreach (self::allForProject($projectId) as $v) {
            $stored[$v['key_name']] = $v['value'];
        }

        $empty = [];
        foreach ($exampleKeys as $k) {
            if (array_key_exists($k, $stored) && $stored[$k] === '') {
                $empty[] = $k;
            }
        }

        return [
            'missing' => array_values(array_diff($exampleKeys, array_keys($stored))),
            'empty'   => $empty,
            'unused'  => array_values(array_diff(array_keys($stored), $exampleKeys)),
        ];
    }

    /**
     * Runs $fn in a transaction (joining one if already open). Any throwable
     * rolls everything back, so multi-write operations never leave the keys
     * and the template half-updated.
     *
     * @template T
     * @param  callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = Database::connect();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Stores the template text on the project ('' clears it).
     *
     * Values are blanked first (`KEY=secret` -> `KEY=`): the template only describes
     * structure, and the real values live encrypted in project_env_vars. Storing
     * them here too would leave every secret in plaintext in projects.env_template.
     */
    public static function setTemplate(int $projectId, ?string $template): void
    {
        $template = ($template === null || $template === '') ? null : env_template_scrub($template);
        $pdo = Database::connect();
        $pdo->prepare("UPDATE projects SET env_template = ?, updated_at = ? WHERE id = ?")
            ->execute([$template, date('Y-m-d H:i:s'), $projectId]);
    }

    /**
     * Saves a template and syncs the key list to it, atomically: the template,
     * added/removed keys, sort order and any non-empty values in the text.
     * Existing values and required flags of retained keys are preserved.
     *
     * Keys the template no longer lists ARE deleted — callers wanting a
     * confirmation step must compare against allForProject() first.
     *
     * @return string[] Keys that were removed
     */
    public static function saveTemplate(int $projectId, string $template): array
    {
        $pairs = env_parse_pairs($template);
        $keys  = array_keys($pairs);
        $order = array_flip($keys);

        return self::transaction(function () use ($projectId, $template, $pairs, $keys, $order): array {
            $removed = array_values(array_diff(
                array_column(self::allForProject($projectId), 'key_name'),
                $keys
            ));
            self::setTemplate($projectId, $template);
            self::syncKeys($projectId, $keys);
            foreach ($pairs as $key => $value) {
                if ($value !== '') {
                    self::upsert($projectId, $key, $value, null, $order[$key]);
                }
            }
            return $removed;
        });
    }

    /**
     * Adds a key (empty value) and appends a stub line to the template, atomically.
     */
    public static function addKey(int $projectId, string $key, int $order, ?string $template): void
    {
        self::transaction(function () use ($projectId, $key, $order, $template): void {
            self::upsert($projectId, $key, '', false, $order);
            if ($template !== null && $template !== '') {
                self::setTemplate($projectId, rtrim($template) . "\n" . $key . "=\n");
            }
        });
    }

    /**
     * Deletes a key and its line in the template, atomically. Without removing
     * the template line, the deployed .env would keep the template's placeholder
     * and the next template save would bring the key back.
     */
    public static function deleteKey(int $projectId, string $key, ?string $template): void
    {
        self::transaction(function () use ($projectId, $key, $template): void {
            $pdo = Database::connect();
            $pdo->prepare("DELETE FROM project_env_vars WHERE project_id = ? AND key_name = ?")
                ->execute([$projectId, $key]);
            if ($template !== null && $template !== '') {
                $new = env_template_remove_key($template, $key);
                if ($new !== $template) {
                    self::setTemplate($projectId, $new);
                }
            }
        });
    }

    /**
     * Renames a key (value, order and required flag preserved) and its line in
     * the template, atomically.
     */
    public static function renameKey(int $projectId, string $old, string $new, ?string $template): void
    {
        self::transaction(function () use ($projectId, $old, $new, $template): void {
            $pdo = Database::connect();
            $pdo->prepare("UPDATE project_env_vars SET key_name = ? WHERE project_id = ? AND key_name = ?")
                ->execute([$new, $projectId, $old]);
            if ($template !== null && $template !== '') {
                $renamed = env_template_rename_key($template, $old, $new);
                if ($renamed !== $template) {
                    self::setTemplate($projectId, $renamed);
                }
            }
        });
    }

    // =========================================================================
    // Target file: preflight, write, drift
    // =========================================================================

    /**
     * Absolute path of the .env file in the project's target directory.
     */
    public static function targetEnvPath(array $project): string
    {
        return rtrim((string) $project['target_path'], '/\\') . DIRECTORY_SEPARATOR . '.env';
    }

    /**
     * Throws unless the managed env is safe to write: no stored value that
     * cannot be decrypted (it would be written as empty) and no empty required key.
     *
     * @throws RuntimeException
     */
    public static function assertDeployable(int $projectId): void
    {
        $unreadable = self::undecryptable($projectId);
        if (!empty($unreadable)) {
            throw new RuntimeException(
                'Managed .env has value(s) that cannot be decrypted (SECRET_KEY changed or data corrupt): '
                . implode(', ', $unreadable) . '. Re-enter or delete them in the .env Manager.'
            );
        }
        $missing = self::missingRequired($projectId);
        if (!empty($missing)) {
            throw new RuntimeException('Managed .env has missing required value(s): ' . implode(', ', $missing));
        }
    }

    /**
     * Renders the managed .env and writes it to the target directory with mode
     * 0640 (owner rw, group r, nothing for other users on a shared host).
     *
     * @return int Number of variables written
     * @throws RuntimeException if the file cannot be written
     */
    public static function writeToTarget(array $project): int
    {
        $projectId = (int) $project['id'];
        $content   = self::renderDotEnv($projectId, $project['env_template'] ?? null);
        $path      = self::targetEnvPath($project);

        // umask makes a new file start restrictive; chmod covers one that already existed
        $oldUmask = umask(0137);
        $written  = file_put_contents($path, $content);
        umask($oldUmask);
        if ($written === false) {
            throw new RuntimeException("Failed to write managed .env to: {$path}");
        }
        @chmod($path, 0640);

        return count(self::allForProject($projectId));
    }

    /**
     * Compares the .env on disk with what the manager would write. Reports key
     * names only — never values.
     *
     * state: 'no_file' | 'unreadable' | 'in_sync' | 'drift'
     *
     * @return array{state:string, path:string, mtime:?int, size:?int,
     *               missing:string[], extra:string[], changed:string[]}
     */
    public static function drift(array $project): array
    {
        $path   = self::targetEnvPath($project);
        $result = ['state' => 'no_file', 'path' => $path, 'mtime' => null, 'size' => null,
                   'missing' => [], 'extra' => [], 'changed' => []];

        if (!is_file($path)) {
            return $result;
        }
        $result['mtime'] = (int) @filemtime($path);
        $result['size']  = (int) @filesize($path);

        $disk = @file_get_contents($path);
        if ($disk === false) {
            $result['state'] = 'unreadable';
            return $result;
        }

        $rendered = self::renderDotEnv((int) $project['id'], $project['env_template'] ?? null);
        $diff     = env_diff_pairs(env_parse_pairs($rendered), env_parse_pairs($disk));
        $result   = array_merge($result, $diff);
        $result['state'] = ($diff['missing'] || $diff['extra'] || $diff['changed']) ? 'drift' : 'in_sync';
        return $result;
    }

    /**
     * Saves the command(s) the "Apply now" action runs after writing the .env
     * (one per line; blank lines and # comments are skipped by HookRunner).
     */
    public static function setApplyCommand(int $projectId, string $command): void
    {
        $pdo = Database::connect();
        $pdo->prepare("UPDATE projects SET env_apply_cmd = ?, updated_at = ? WHERE id = ?")
            ->execute([trim($command) === '' ? null : trim($command), date('Y-m-d H:i:s'), $projectId]);
    }

    // =========================================================================
    // Bulk operations
    // =========================================================================

    /**
     * Merges incoming variables into a project, atomically. Never deletes.
     *
     * - New key: added (with its required flag if given).
     * - Existing key whose stored value is empty (or unreadable): filled.
     * - Existing key with a different non-empty value: replaced only if $overwrite,
     *   otherwise reported as kept.
     * - Incoming empty values never blank a stored value.
     *
     * The template is kept listing every stored key, so none go dormant: with no
     * template of its own the project adopts $incomingTemplate, and any key the
     * template lacks gets an empty stub line.
     *
     * @param array<int, array{key:string, value:string, required?:?bool}> $incoming
     * @return array{added:string[], filled:string[], overwritten:string[], kept:string[]}
     */
    public static function merge(
        int     $projectId,
        array   $incoming,
        ?string $ownTemplate,
        ?string $incomingTemplate,
        bool    $overwrite
    ): array {
        return self::transaction(function () use ($projectId, $incoming, $ownTemplate, $incomingTemplate, $overwrite): array {
            $existing = [];
            $maxOrder = -1;
            foreach (self::allForProject($projectId) as $v) {
                $existing[(string) $v['key_name']] = $v;
                $maxOrder = max($maxOrder, (int) $v['sort_order']);
            }

            $r = ['added' => [], 'filled' => [], 'overwritten' => [], 'kept' => []];
            foreach ($incoming as $item) {
                $key   = (string) ($item['key'] ?? '');
                $value = (string) ($item['value'] ?? '');
                if (!self::validKey($key)) {
                    continue;
                }

                if (!isset($existing[$key])) {
                    $req = array_key_exists('required', $item) && $item['required'] !== null ? (bool) $item['required'] : null;
                    self::upsert($projectId, $key, $value, $req, ++$maxOrder);
                    $existing[$key] = ['value' => $value, 'decrypt_failed' => false, 'sort_order' => $maxOrder];
                    $r['added'][] = $key;
                    continue;
                }

                $cur    = $existing[$key];
                $curVal = $cur['decrypt_failed'] ? '' : (string) $cur['value'];
                if ($value === '' || $value === $curVal) {
                    continue;
                }
                if ($curVal === '' || $overwrite) {
                    self::upsert($projectId, $key, $value, null, (int) $cur['sort_order']);
                    $existing[$key]['value']          = $value;
                    $existing[$key]['decrypt_failed'] = false;
                    $r[$curVal === '' ? 'filled' : 'overwritten'][] = $key;
                } else {
                    $r['kept'][] = $key;
                }
            }

            $tpl = ($ownTemplate !== null && $ownTemplate !== '') ? $ownTemplate : (string) ($incomingTemplate ?? '');
            if ($tpl !== '') {
                $listed = array_flip(array_map('strval', env_parse_keys($tpl)));
                foreach (array_keys($existing) as $k) {
                    if (!isset($listed[(string) $k])) {
                        $tpl = rtrim($tpl) . "\n" . $k . "=\n";
                    }
                }
                if ($tpl !== (string) $ownTemplate) {
                    self::setTemplate($projectId, $tpl);
                }
            }
            return $r;
        });
    }

    /**
     * Applies edited "KEY=value" text: sets the value of every key listed (an empty
     * value clears it) and adds keys that are new. Keys not listed are left alone.
     *
     * @return array{added:string[], updated:string[]}
     */
    public static function bulkEdit(int $projectId, string $text, ?string $template): array
    {
        $pairs = env_parse_pairs($text);

        return self::transaction(function () use ($projectId, $pairs, $template): array {
            $existing = [];
            $maxOrder = -1;
            foreach (self::allForProject($projectId) as $v) {
                $existing[(string) $v['key_name']] = $v;
                $maxOrder = max($maxOrder, (int) $v['sort_order']);
            }

            $r = ['added' => [], 'updated' => []];
            foreach ($pairs as $key => $value) {
                $key = (string) $key;
                if (!isset($existing[$key])) {
                    self::upsert($projectId, $key, $value, null, ++$maxOrder);
                    $r['added'][] = $key;
                } elseif ($existing[$key]['decrypt_failed'] || (string) $existing[$key]['value'] !== $value) {
                    self::upsert($projectId, $key, $value, null, (int) $existing[$key]['sort_order']);
                    $r['updated'][] = $key;
                }
            }

            if (!empty($r['added']) && $template !== null && $template !== '') {
                $tpl = $template;
                foreach ($r['added'] as $k) {
                    $tpl = rtrim($tpl) . "\n" . $k . "=\n";
                }
                self::setTemplate($projectId, $tpl);
            }
            return $r;
        });
    }

    /**
     * Saves every value and required flag from the values form, atomically, in the
     * order the rows were arranged. When the order changed, the template is
     * reordered to match so the written .env follows it too.
     *
     * @param array<string,string> $values   key => new value
     * @param array<string,mixed>  $required keys whose checkbox was ticked
     * @param string[]             $order    keys in the desired order (may be empty)
     */
    public static function saveValues(int $projectId, array $values, array $required, array $order, ?string $template): void
    {
        self::transaction(function () use ($projectId, $values, $required, $order, $template): void {
            $current = array_map('strval', array_column(self::allForProject($projectId), 'key_name'));

            // Rows named in $order first, in that order; anything else keeps its place after
            $final = array_values(array_unique(array_merge(
                array_values(array_intersect(array_map('strval', $order), $current)),
                $current
            )));

            foreach ($final as $i => $key) {
                self::upsert($projectId, $key, (string) ($values[$key] ?? ''), isset($required[$key]), $i);
            }

            if ($final !== $current && $template !== null && $template !== '') {
                self::setTemplate($projectId, env_template_reorder($template, $final));
            }
        });
    }

    /**
     * Snapshot of a project's env as a plain array, for the encrypted export.
     *
     * @return array<string,mixed>
     */
    public static function exportPayload(array $project): array
    {
        $vars = [];
        foreach (self::allForProject((int) $project['id']) as $v) {
            $vars[] = [
                'key'      => $v['key_name'],
                'value'    => $v['value'],
                'required' => (bool) $v['is_required'],
                'order'    => (int) $v['sort_order'],
            ];
        }
        $tpl = (string) ($project['env_template'] ?? '');
        return [
            'v'           => 1,
            'exported_at' => date('c'),
            'project'     => (string) $project['name'],
            'template'    => $tpl === '' ? null : env_template_scrub($tpl),
            'vars'        => $vars,
        ];
    }

    /**
     * Removes values from the stored template without changing what gets deployed.
     *
     * A stored value always wins when rendering (even an empty one), so a template
     * value only ever reached the file for a key with NO stored row. Exactly those
     * are saved as stored values first; rows that already exist are left alone, so
     * a value someone cleared on purpose is not resurrected from stale template text.
     *
     * @return string[] Keys saved from the template
     */
    public static function scrubTemplate(int $projectId): array
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("SELECT env_template FROM projects WHERE id = ?");
        $stmt->execute([$projectId]);
        $tpl = (string) ($stmt->fetchColumn() ?: '');
        if ($tpl === '') {
            return [];
        }

        return self::transaction(function () use ($projectId, $tpl): array {
            $existing = [];
            $maxOrder = -1;
            foreach (self::allForProject($projectId) as $v) {
                $existing[(string) $v['key_name']] = true;
                $maxOrder = max($maxOrder, (int) $v['sort_order']);
            }

            $added = [];
            foreach (env_parse_pairs($tpl) as $key => $value) {
                $key = (string) $key;
                if ($value !== '' && !isset($existing[$key]) && self::validKey($key)) {
                    self::upsert($projectId, $key, $value, null, ++$maxOrder);
                    $added[] = $key;
                }
            }
            self::setTemplate($projectId, $tpl);   // blanks every value
            return $added;
        });
    }

    /**
     * Keys whose value contains a ${NAME} reference. phpdotenv ignores these (they are
     * written single-quoted) but CodeIgniter 4's loader expands them inside ANY quotes
     * and has no escape, so the value may change when the app reads the file.
     * `definite` = the reference names another managed key, so it will expand.
     * Key names only — nothing from the value is returned.
     *
     * @return array<int, array{key:string, definite:bool}>
     */
    public static function expansionRisks(int $projectId): array
    {
        $vars  = self::allForProject($projectId);
        $names = [];
        foreach ($vars as $v) {
            $names[(string) $v['key_name']] = true;
        }

        $risks = [];
        foreach ($vars as $v) {
            if ($v['decrypt_failed'] || !preg_match_all('/\$\{([a-zA-Z0-9_.]+)\}/', $v['value'], $m)) {
                continue;
            }
            $risks[] = [
                'key'      => (string) $v['key_name'],
                'definite' => (bool) array_intersect_key(array_flip($m[1]), $names),
            ];
        }
        return $risks;
    }

    /**
     * One-line warning for deploy/apply logs, or null when nothing is at risk.
     */
    public static function expansionWarning(int $projectId): ?string
    {
        $risks = self::expansionRisks($projectId);
        if (empty($risks)) {
            return null;
        }
        $msg = "Value(s) contain \${NAME} references that CodeIgniter 4's .env loader expands even inside quotes "
             . '(phpdotenv leaves them alone), so they may change when the app reads the file: '
             . implode(', ', array_column($risks, 'key')) . '.';
        $definite = array_column(array_filter($risks, fn(array $r) => $r['definite']), 'key');
        if (!empty($definite)) {
            $msg .= ' Certain to change (they reference another managed key): ' . implode(', ', $definite) . '.';
        }
        return $msg;
    }

    /**
     * Same key rule the add/rename forms enforce.
     */
    public static function validKey(string $key): bool
    {
        return $key !== '' && !preg_match('/[\s#=]/', $key);
    }

    /**
     * Deletes all env vars for a project (used when a project is deleted).
     */
    public static function deleteAllForProject(int $projectId): void
    {
        $pdo  = Database::connect();
        $stmt = $pdo->prepare("DELETE FROM project_env_vars WHERE project_id = :pid");
        $stmt->execute([':pid' => $projectId]);
    }
}
