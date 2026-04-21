/* ============================================================================
   TeraPH Web Deployer — Dashboard JavaScript
   ============================================================================ */

(function () {
    'use strict';

    // =========================================================================
    // State
    // =========================================================================
    const state = {
        pendingDeploy: {
            projectId:   null,
            projectName: null,
            mode:        'safe',
        },
    };

    // =========================================================================
    // Utility helpers
    // =========================================================================

    /** POST JSON to an endpoint; returns parsed response or throws with server text attached */
    async function post(url, formData) {
        formData.append('_csrf', window.CSRF_TOKEN);
        const res = await fetch(url, { method: 'POST', body: formData });
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (_) {
            // PHP emitted non-JSON (warning/error page) — expose the raw text for debugging
            const err = new Error('Server returned non-JSON response.');
            err.serverText = text;
            throw err;
        }
    }

    /** GET JSON from an endpoint */
    async function get(url) {
        const res  = await fetch(url);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (_) {
            const err = new Error('Server returned non-JSON response.');
            err.serverText = text;
            throw err;
        }
    }

    /** Opens a modal */
    function openModal(id) {
        const el = document.getElementById(id);
        if (el) {
            el.removeAttribute('hidden');
            el.focus?.();
        }
    }

    /** Closes a modal */
    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.setAttribute('hidden', '');
    }

    /** Shows an error inside a modal alert box */
    function showError(alertId, message) {
        const el = document.getElementById(alertId);
        if (!el) return;
        el.textContent = message;
        el.removeAttribute('hidden');
    }

    /** Hides an error alert */
    function hideError(alertId) {
        const el = document.getElementById(alertId);
        if (el) el.setAttribute('hidden', '');
    }

    /** Formats a log level into a label */
    function levelClass(level) {
        const map = { INFO: 'INFO', WARNING: 'WARNING', ERROR: 'ERROR' };
        return map[level] || 'INFO';
    }

    // =========================================================================
    // Modal: close all on overlay click or [data-close] button
    // =========================================================================
    document.addEventListener('click', function (e) {
        const overlay = e.target.closest('.modal-overlay');
        if (!overlay) return;

        // Close button
        if (e.target.matches('[data-close]')) {
            closeModal(e.target.dataset.close);
            return;
        }

        // Overlay background click
        if (e.target === overlay) {
            // Prevent closing if modal is marked as static
            if (overlay.dataset.static === 'true') {
                // Subtle feedback: shake the modal or just ignore
                return;
            }
            overlay.setAttribute('hidden', '');
        }
    });

    // Escape key closes any open modal unless it's static
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay:not([hidden])').forEach(el => {
                if (el.dataset.static !== 'true') {
                    el.setAttribute('hidden', '');
                }
            });
        }
    });

    // =========================================================================
    // Deploy: open confirmation modal
    // =========================================================================
    document.addEventListener('click', function (e) {
        if (!e.target.matches('.btn-deploy')) return;

        const projectId   = e.target.dataset.projectId;
        const projectName = e.target.dataset.projectName;
        const modeSelect  = document.querySelector(`.mode-select[data-project-id="${projectId}"]`);
        const mode        = modeSelect ? modeSelect.value : 'safe';

        state.pendingDeploy = { projectId, projectName, mode };

        // Populate confirmation modal
        document.getElementById('confirm-project-name').textContent = projectName;
        document.getElementById('confirm-mode').textContent         = mode === 'full' ? 'Full' : 'Safe';

        const warning = document.getElementById('full-deploy-warning');
        if (mode === 'full') {
            warning.removeAttribute('hidden');
        } else {
            warning.setAttribute('hidden', '');
        }

        openModal('modal-deploy');
    });

    // =========================================================================
    // Deploy: confirm & run
    // =========================================================================
    document.getElementById('btn-confirm-deploy')?.addEventListener('click', async function () {
        const { projectId, projectName, mode } = state.pendingDeploy;
        if (!projectId) return;

        closeModal('modal-deploy');

        // Show progress overlay
        document.getElementById('deploy-status-title').textContent = `Deploying ${projectName}…`;
        document.getElementById('deploy-status-sub').textContent   = 'Please wait — this may take up to a minute.';
        openModal('modal-deploying');

        // ---- Run the pipeline (blocking fetch) --------------------------------
        let fetchResult = null;
        let fetchError  = null;

        const deployFetch = post('deploy.php', (() => {
            const fd = new FormData();
            fd.append('project_id', projectId);
            fd.append('mode', mode);
            return fd;
        })()).then(r => { fetchResult = r; }).catch(e => { fetchError = e; });

        // ---- Show/Reset Console ----------------------------------------------
        const consoleEl = document.getElementById('deploy-console');
        if (consoleEl) {
            consoleEl.innerHTML = '<div class="log-entry"><span class="log-msg">Initializing pipeline...</span></div>';
            consoleEl.removeAttribute('hidden');
        }

        // ---- Poll DB status & Logs incrementally ------------------------------
        let pollInterval = null;
        let finalStatus  = null;
        let lastLogId    = 0;
        let isAutoScroll = true;

        // Smart scroll detection
        consoleEl?.addEventListener('scroll', () => {
            if (!consoleEl) return;
            const threshold = 15; // px buffer
            isAutoScroll = (consoleEl.scrollHeight - consoleEl.scrollTop - consoleEl.clientHeight) < threshold;
        });

        pollInterval = setInterval(async () => {
            try {
                // 1. Check status
                const s = await get(`deploy.php?action=status&project_id=${projectId}`);
                if (!s.success) return;

                const { status, last_error, deployment_id } = s.data;

                // 2. Fetch incrementally using deployment_id and lastLogId
                const logResp = await get(`logs.php?deployment_id=${deployment_id}&since_id=${lastLogId}`);
                if (logResp.success && consoleEl) {
                    if (logResp.data.logs.length > 0) {
                        appendLogsToContainer(logResp.data.logs, consoleEl, isAutoScroll);
                        lastLogId = logResp.data.last_log_id;
                    }
                }

                // Update the flying subtitle to show real progress
                const labels = {
                    running: 'Pipeline running — downloading, extracting, copying files…',
                    success: 'Pipeline completed successfully!',
                    failed:  last_error ? `Failed: ${last_error}` : 'Pipeline failed. Check logs.',
                };
                document.getElementById('deploy-status-sub').textContent =
                    labels[status] || `Status: ${status}`;

                // Pipeline has settled — capture and stop polling
                if (status === 'success' || status === 'failed') {
                    finalStatus = s.data;
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            } catch (_) {
                // Polling failed (probably PHP single-process dev server is busy)
            }
        }, 2000); // Polling frequency increased to 2s for better response


        // ---- Wait for the pipeline fetch to complete --------------------------
        await deployFetch;
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }

        // ---- Do a final definitive status check -------------------------------
        // The DB is the source of truth. The fetch() response is secondary.
        try {
            // Small grace period so the DB write commits before we read
            await new Promise(r => setTimeout(r, 300));

            const statusResp = await get(`deploy.php?action=status&project_id=${projectId}`);
            if (statusResp.success) {
                finalStatus = statusResp.data;
            }
        } catch (_) {
            // Status check failed — fall back to fetch() result
        }

        closeModal('modal-deploying');

        // ---- Determine outcome from DB status, then fetch() result ------------
        if (finalStatus) {
            const { status, last_error } = finalStatus;
            updateCardStatus(projectId, status);

            if (status === 'success') {
                showToast('success', `"${projectName}" deployed successfully.`);
            } else if (status === 'failed') {
                const hint = last_error ? ` Error: ${last_error}` : ' Check logs for details.';
                showToast('danger', `Deployment failed.${hint}`);
            } else {
                // Still 'running' — something is truly stuck
                showToast('warning', 'Deployment is still running or stuck. Use Logs to check.',);
                updateCardStatus(projectId, 'running');
            }

        } else if (fetchResult) {
            // Fallback: fetch() gave us a result but status endpoint didn't respond
            updateCardStatus(projectId, fetchResult.success ? 'success' : 'failed');
            showToast(
                fetchResult.success ? 'success' : 'danger',
                fetchResult.message || (fetchResult.success ? 'Deployed.' : 'Deployment failed.')
            );

        } else {
            // Complete fallback — both failed
            let msg = 'Could not confirm deployment outcome. Open Logs to verify.';
            if (fetchError?.serverText) {
                const plain = fetchError.serverText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 250);
                msg = `Server error: ${plain}`;
            }
            showToast('danger', msg);
            console.error('[deploy] fetch error:', fetchError, fetchError?.serverText ?? '');
        }
    });


    // =========================================================================
    // Logs: open log viewer modal
    // =========================================================================
    document.addEventListener('click', async function (e) {
        if (!e.target.matches('.btn-logs')) return;

        const projectId   = e.target.dataset.projectId;
        const projectName = e.target.dataset.projectName;

        document.getElementById('logs-project-name').textContent = projectName;
        document.getElementById('logs-container').innerHTML      = '<p class="log-loading">Loading logs…</p>';
        openModal('modal-logs');

        try {
            // Fetch the latest deployment logs for this project directly
            const latest = await get(`logs.php?project_id=${projectId}`);

            if (!latest.success) {
                document.getElementById('logs-container').innerHTML = '<p class="log-loading">No deployments found for this project yet.</p>';
                return;
            }

            // Show the deployment status header
            const dep = latest.data.deployment;
            if (dep) {
                const statusClass = { success: 'badge-success', failed: 'badge-danger', running: 'badge-running' }[dep.status] || 'badge-neutral';
                document.getElementById('logs-project-name').innerHTML =
                    `${escapeHtml(projectName)} &nbsp;<span class="status-badge ${statusClass}">${escapeHtml(dep.status)}</span>`;
            }

            renderLogs(latest.data.logs);

        } catch (err) {
            document.getElementById('logs-container').innerHTML = `<p class="log-loading">Failed to load logs: ${escapeHtml(err.message)}</p>`;
            console.error('[logs]', err);
        }
    });

    /**
     * Renders log entries into the log viewer container (replaces content).
     */
    function renderLogs(logs) {
        const container = document.getElementById('logs-container');
        if (!logs || logs.length === 0) {
            container.innerHTML = '<p class="log-loading">No log entries found.</p>';
            return;
        }

        container.innerHTML = logs.map(l => formatLogEntry(l)).join('');
        container.scrollTop = container.scrollHeight;
    }

    /**
     * Appends logs to a container without wiping existing content.
     * Implements auto-scroll logic.
     */
    function appendLogsToContainer(logs, container, shouldScroll) {
        if (!logs || logs.length === 0) return;

        // Remove cursor before appending
        const cursor = container.querySelector('.console-cursor');
        if (cursor) cursor.remove();

        const html = logs.map(l => formatLogEntry(l)).join('');
        container.insertAdjacentHTML('beforeend', html);
        
        // Re-add cursor
        container.insertAdjacentHTML('beforeend', '<span class="console-cursor">█</span>');

        if (shouldScroll) {
            container.scrollTop = container.scrollHeight;
        }
    }

    /**
     * Standard log entry HTML template.
     */
    function formatLogEntry(entry) {
        const time = escapeHtml(entry.logged_at.split(' ')[1] || entry.logged_at);
        return `
            <div class="log-entry new-log">
                <span class="log-ts">${time}</span>
                <span class="log-level ${levelClass(entry.level)}">${escapeHtml(entry.level)}</span>
                <span class="log-msg">${escapeHtml(entry.message)}</span>
            </div>
        `;
    }

    // =========================================================================
    // Backups: open backup list modal
    // =========================================================================
    document.addEventListener('click', async function (e) {
        if (!e.target.matches('.btn-backups')) return;

        const projectId   = e.target.dataset.projectId;
        const projectName = e.target.dataset.projectName;

        document.getElementById('backups-project-name').textContent = projectName;
        document.getElementById('backups-container').innerHTML      = '<p class="log-loading">Loading backups…</p>';
        openModal('modal-backups');

        try {
            const result = await get(`backups.php?action=list&project_id=${projectId}`);

            if (!result.success) {
                document.getElementById('backups-container').innerHTML = '<p class="log-loading">Failed to load backups.</p>';
                return;
            }

            renderBackups(result.data.backups, projectId);

        } catch (err) {
            document.getElementById('backups-container').innerHTML = '<p class="log-loading">Network error.</p>';
        }
    });

    /**
     * Renders backup rows into the backups container.
     */
    function renderBackups(backups, projectId) {
        const container = document.getElementById('backups-container');

        if (!backups || backups.length === 0) {
            container.innerHTML = '<p class="log-loading">No backups found for this project.</p>';
            return;
        }

        const rows = backups.map(b => `
            <tr>
                <td class="backup-name">${escapeHtml(b.name)}</td>
                <td>${escapeHtml(b.size)}</td>
                <td>${escapeHtml(b.time_ago)}</td>
                <td>
                    <button class="btn btn-ghost btn-sm btn-restore"
                        data-project-id="${escapeHtml(String(projectId))}"
                        data-backup-name="${escapeHtml(b.name)}">
                        Restore
                    </button>
                </td>
            </tr>
        `).join('');

        container.innerHTML = `
            <table class="backup-table">
                <thead>
                    <tr>
                        <th>Backup File</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    // =========================================================================
    // Backups: restore
    // =========================================================================
    document.addEventListener('click', async function (e) {
        if (!e.target.matches('.btn-restore')) return;

        const backupName = e.target.dataset.backupName;
        const projectId  = e.target.dataset.projectId;

        if (!confirm(`Restore backup: ${backupName}?\n\nThe current site will be backed up before restoring.`)) return;

        e.target.disabled = true;
        e.target.textContent = 'Restoring…';

        try {
            const fd = new FormData();
            fd.append('backup_name', backupName);

            const result = await post(`backups.php?action=restore&project_id=${projectId}`, fd);

            if (result.success) {
                closeModal('modal-backups');
                showToast('success', 'Restore completed successfully.');
                updateCardStatus(projectId, 'success');
            } else {
                alert('Restore failed: ' + result.message);
                e.target.disabled = false;
                e.target.textContent = 'Restore';
            }
        } catch (err) {
            alert('Network error during restore.');
            e.target.disabled = false;
            e.target.textContent = 'Restore';
        }
    });

    // =========================================================================
    // Project Form: Add
    // =========================================================================
    document.getElementById('btn-add-project')?.addEventListener('click', openAddForm);
    document.getElementById('btn-add-project-empty')?.addEventListener('click', openAddForm);

    function openAddForm() {
        document.getElementById('project-form-title').textContent = 'Add Project';
        document.getElementById('project-form-id').value          = '';
        document.getElementById('project-form-keep-pat').value    = '0';
        document.getElementById('p-github-pat').placeholder       = 'ghp_xxxxxxxxxxxxxxxx';
        document.getElementById('project-form').reset();
        hideError('project-form-error');
        openModal('modal-project-form');
    }

    // =========================================================================
    // Project Form: Edit
    // =========================================================================
    document.addEventListener('click', async function (e) {
        if (!e.target.matches('.btn-edit')) return;

        const id     = e.target.dataset.projectId;
        const result = await get(`projects.php?action=get&id=${id}`);

        if (!result.success) {
            alert('Could not load project data.');
            return;
        }

        const p = result.data.project;
        document.getElementById('project-form-title').textContent = 'Edit Project';
        document.getElementById('project-form-id').value          = p.id;
        // When editing: set keep_pat=1 so a blank PAT field preserves the stored value
        document.getElementById('project-form-keep-pat').value    = '1';
        document.getElementById('p-name').value                   = p.name;
        document.getElementById('p-repo-url').value               = p.repo_url;
        document.getElementById('p-branch').value                 = p.branch;
        document.getElementById('p-target-path').value            = p.target_path;

        // Parse safe_keep JSON back to comma-separated string
        let safeKeep = '';
        if (p.safe_keep) {
            try {
                safeKeep = JSON.parse(p.safe_keep).join(', ');
            } catch (e) {
                safeKeep = p.safe_keep;
            }
        }
        document.getElementById('p-safe-keep').value  = safeKeep;
        // Never pre-fill PAT — show placeholder indicating existing value is kept
        document.getElementById('p-github-pat').value       = '';
        document.getElementById('p-github-pat').placeholder = p.github_pat
            ? 'PAT saved — leave blank to keep, or enter a new one'
            : 'ghp_xxxxxxxxxxxxxxxx (none saved)';

        hideError('project-form-error');
        openModal('modal-project-form');
    });

    // =========================================================================
    // Project Form: Save (create or update)
    // =========================================================================
    document.getElementById('btn-save-project')?.addEventListener('click', async function () {
        hideError('project-form-error');
        const btn = this;
        btn.disabled = true;

        const id  = document.getElementById('project-form-id').value;
        const fd  = new FormData(document.getElementById('project-form'));
        const url = id
            ? `projects.php?action=update`
            : `projects.php?action=create`;

        try {
            const result = await post(url, fd);

            if (result.success) {
                closeModal('modal-project-form');
                showToast('success', result.message);
                // Reload page to refresh project grid
                setTimeout(() => window.location.reload(), 600);
            } else {
                showError('project-form-error', result.message);
            }
        } catch (err) {
            showError('project-form-error', 'Network error. Please try again.');
        } finally {
            btn.disabled = false;
        }
    });

    // =========================================================================
    // Project Delete
    // =========================================================================
    document.addEventListener('click', async function (e) {
        if (!e.target.matches('.btn-delete')) return;

        const projectId   = e.target.dataset.projectId;
        const projectName = e.target.dataset.projectName;

        if (!confirm(`Delete project "${projectName}"?\n\nBackups and logs will be retained.`)) return;

        const fd = new FormData();
        fd.append('id', projectId);

        try {
            const result = await post('projects.php?action=delete', fd);

            if (result.success) {
                const card = document.getElementById(`project-card-${projectId}`);
                if (card) {
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.97)';
                    card.style.transition = 'all 0.3s ease';
                    setTimeout(() => card.remove(), 320);
                }
                showToast('success', result.message);
            } else {
                alert('Delete failed: ' + result.message);
            }
        } catch (err) {
            alert('Network error.');
        }
    });

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Updates a project card's status badge without a page reload.
     */
    function updateCardStatus(projectId, status) {
        const card  = document.getElementById(`project-card-${projectId}`);
        if (!card) return;

        const badge = card.querySelector('.status-badge');
        if (!badge) return;

        const classes = { success: 'badge-success', failed: 'badge-danger', running: 'badge-running' };
        const labels  = { success: '● Live', failed: '⚠ Failed', running: '⟳ Deploying' };

        badge.className = 'status-badge ' + (classes[status] || 'badge-neutral');
        badge.textContent = labels[status] || status;
    }

    // getLatestDeploymentId() removed — logs.php now accepts ?project_id= directly
    // and resolves the latest deployment on the server side.

    /**
     * Shows a brief toast notification.
     */
    function showToast(type, message) {
        const toast = document.createElement('div');
        toast.className    = `alert alert-${type}`;
        toast.textContent  = message;
        toast.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            min-width: 280px;
            max-width: 420px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
            animation: slide-up 0.2s ease;
        `;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }

    /**
     * HTML escape helper for dynamic content.
     */
    function escapeHtml(str) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return String(str).replace(/[&<>"']/g, m => map[m]);
    }

})();
