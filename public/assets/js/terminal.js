// =============================================================================
// Web Terminal Logic (Stateless Native Runner)
// =============================================================================

let currentProjectId = null;
let commandActive = false;

// Attach click listener for terminal buttons
document.addEventListener('click', async function (e) {
    const btn = e.target.closest('.btn-terminal');
    if (!btn) return;
    
    currentProjectId = btn.dataset.projectId;
    const projectName = btn.dataset.projectName;
    
    document.getElementById('terminal-project-name').textContent = projectName;
    
    // UI Setup
    const terminalModal = document.getElementById('modal-web-terminal');
    terminalModal.removeAttribute('hidden');
    
    const input = document.getElementById('terminal-input');
    const log = document.getElementById('terminal-log');
    
    log.innerHTML = `<div style="color: #888;">--- Terminal context set to project: ${projectName} ---</div>`;
    input.value = '';
    input.disabled = false;
    input.focus();
});

// Focus input whenever clicking the terminal body
document.getElementById('terminal-log').addEventListener('click', () => {
    const input = document.getElementById('terminal-input');
    if (!input.disabled) {
        // Prevent selection snapping issues by only focusing if there's no selection
        if (!window.getSelection().toString()) {
            input.focus();
        }
    }
});

// Execute Command
document.getElementById('terminal-input').addEventListener('keydown', async function (e) {
    if (e.key === 'Enter') {
        const cmd = this.value.trim();
        if (!cmd) return;
        
        if (commandActive || !currentProjectId) return;
        commandActive = true;
        this.disabled = true;
        
        const log = document.getElementById('terminal-log');
        
        // Append execution intent with basic colorization
        const entry = document.createElement('div');
        entry.innerHTML = `<span style="color:var(--color-success)">$</span> <span style="color:#FFF">${cmd}</span>`;
        log.appendChild(entry);
        
        this.value = '';
        log.scrollTop = log.scrollHeight;
        
        // Send POST sequence using streams API
        try {
            const fd = new FormData();
            fd.append('project_id', currentProjectId);
            fd.append('cmd', cmd);
            if (window.CSRF_TOKEN) fd.append('_csrf', window.CSRF_TOKEN);
            
            const response = await fetch('terminal_execute.php', {
                method: 'POST',
                body: fd
            });
            
            if (!response.ok) {
                 const errText = await response.text();
                 appendLogOutput(log, `\n[Server Error ${response.status}]: ${errText}\n`, true);
                 unlockInput();
                 return;
            }
            
            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            
            let currentOutputBox = document.createElement('div');
            log.appendChild(currentOutputBox);
            
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                
                const chunk = decoder.decode(value, { stream: true });
                appendLogOutput(currentOutputBox, chunk, false);
                log.scrollTop = log.scrollHeight;
            }
            
        } catch (err) {
            appendLogOutput(log, `\n[Network Execution Failed: ${err.message}]\n`, true);
        }
        
        unlockInput();
    }
});

function unlockInput() {
    commandActive = false;
    const input = document.getElementById('terminal-input');
    input.disabled = false;
    input.focus();
}

/** 
 * Safely appends and sanitizes text chunks into the output box. 
 * Provides extremely basic red colouring for [ERROR] strings. 
 */
function appendLogOutput(container, text, isError) {
    if (text === '') return;
    
    // Safety encode html
    const escaped = text.replace(/&/g, "&amp;")
                        .replace(/</g, "&lt;")
                        .replace(/>/g, "&gt;");
                        
    const span = document.createElement('span');
    span.innerHTML = escaped;
    
    if (isError || text.includes('[ERROR]')) {
        span.style.color = '#ff6b6b';
    } else {
        span.style.color = '#e5e7eb';
    }
    
    container.appendChild(span);
}

// Ensure cleanup flag resets on close
document.querySelector('[data-close="modal-web-terminal"]').addEventListener('click', () => {
    commandActive = false;
    currentProjectId = null;
});
