// GACS Dashboard JavaScript Utilities

// Toast Notification
function showToast(message, type = 'info', duration = 3000) {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.zIndex = '9999';
    toast.style.minWidth = '300px';
    toast.style.maxWidth = '500px';
    toast.style.animation = 'slideInRight 0.3s ease';
    toast.textContent = message;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// AJAX Helper
async function fetchAPI(url, options = {}) {
    try {
        // Add timeout support (default 15 seconds)
        const timeout = options.timeout || 15000;
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        const response = await fetch(url, {
            ...options,
            signal: controller.signal,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            }
        });

        clearTimeout(timeoutId);

        // Check if response is OK
        if (!response.ok) {
            console.error('HTTP Error:', response.status, response.statusText, 'URL:', url);
        }

        // Get content type
        const contentType = response.headers.get('content-type');

        // Check if response is JSON
        if (contentType && contentType.includes('application/json')) {
            const data = await response.json();
            return data;
        } else {
            // Response is not JSON (probably HTML error page or PHP error)
            const text = await response.text();
            console.error('Non-JSON response from', url, ':', text.substring(0, 500));

            // Show first 200 chars of error in toast for debugging
            const errorPreview = text.substring(0, 200).replace(/<[^>]*>/g, ''); // Remove HTML tags
            showToast('Server error: ' + errorPreview, 'danger');
            return null;
        }
    } catch (error) {
        // Check if error is due to abort (timeout or user navigated away)
        if (error.name === 'AbortError') {
            // Log but don't show toast for timeout/abort (will be handled by calling code)
            console.debug('Request aborted for', url);
            return { success: false, error: 'timeout', message: 'Request timeout' };
        }

        // Log other fetch errors
        console.error('Fetch error for', url, ':', error);

        // Only show toast for non-abort errors
        if (!url.includes('/api/get-hotspot-traffic.php')) {
            // Don't show toast for hotspot API errors (handled separately)
            showToast('Terjadi kesalahan koneksi: ' + error.message, 'danger');
        }
        return { success: false, error: error.name, message: error.message };
    }
}

// Format timestamp
function formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    return date.toLocaleString('id-ID');
}

// Confirm dialog
function confirmAction(message) {
    return confirm(message);
}

// Loading overlay
function showLoading() {
    const overlay = document.createElement('div');
    overlay.id = 'loading-overlay';
    overlay.style.position = 'fixed';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.width = '100%';
    overlay.style.height = '100%';
    overlay.style.background = 'rgba(0, 0, 0, 0.5)';
    overlay.style.zIndex = '9998';
    overlay.style.display = 'flex';
    overlay.style.justifyContent = 'center';
    overlay.style.alignItems = 'center';

    overlay.innerHTML = '<div class="spinner"></div>';
    document.body.appendChild(overlay);
}

function hideLoading() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

// Toggle sidebar for mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('active');
    }
}

// Auto refresh data
function autoRefresh(callback, interval = 30000) {
    setInterval(callback, interval);
}

// Copy to clipboard
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('Berhasil disalin!', 'success');
    }).catch(() => {
        showToast('Gagal menyalin', 'danger');
    });
}

// Format uptime
function formatUptime(seconds) {

    if (
        seconds === null ||
        seconds === undefined ||
        seconds === '' ||
        seconds === 'N/A'
    ) {
        return 'Não disponível';
    }

    seconds = Number(seconds);

    if (!Number.isFinite(seconds) || seconds < 0) {
        return 'Não disponível';
    }

    const days = Math.floor(seconds / 86400);

    const hours = Math.floor(
        (seconds % 86400) / 3600
    );

    const minutes = Math.floor(
        (seconds % 3600) / 60
    );

    const secs = Math.floor(
        seconds % 60
    );

    const parts = [];

    if (days > 0) {
        parts.push(
            days + (days === 1 ? ' dia' : ' dias')
        );
    }

    if (hours > 0) {
        parts.push(
            hours + (hours === 1 ? ' hora' : ' horas')
        );
    }

    if (minutes > 0) {
        parts.push(
            minutes + ' min'
        );
    }

    if (
        days === 0 &&
        hours === 0 &&
        minutes === 0
    ) {
        parts.push(
            secs + ' s'
        );
    }

    return parts.join(' ');
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Keep the sidebar layout, control labels and saved preference in sync.
function initSidebarToggle() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const button = document.getElementById('sidebarToggle');
    if (!sidebar || !mainContent || !button || button.dataset.sidebarBound) return;
    button.dataset.sidebarBound = 'true';
    const icon = button.querySelector('i');
    const applyState = collapsed => {
        sidebar.classList.toggle('collapsed', collapsed);
        mainContent.classList.toggle('collapsed', collapsed);
        if (icon) {
            icon.classList.toggle('bi-chevron-right', collapsed);
            icon.classList.toggle('bi-chevron-left', !collapsed);
        }
        const label = collapsed ? 'Expandir menu' : 'Recolher menu';
        button.title = label;
        button.setAttribute('aria-label', label);
        button.setAttribute('aria-expanded', String(!collapsed));
    };
    let collapsed = true;
    try {
        const saved = localStorage.getItem('sidebarCollapsed');
        collapsed = saved === null ? true : saved === 'true';
    } catch (error) { /* The control still works when browser storage is unavailable. */ }
    applyState(collapsed);
    button.addEventListener('click', event => {
        event.preventDefault();
        collapsed = !sidebar.classList.contains('collapsed');
        applyState(collapsed);
        try { localStorage.setItem('sidebarCollapsed', String(collapsed)); }
        catch (error) { /* Preserve the current session's state. */ }
        window.dispatchEvent(new Event('resize'));
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebarToggle);
} else {
    initSidebarToggle();
}

/* Short, in-memory cache for read-only ONU enrichment. No persisted customer data. */
const onuReadCache = new Map();
const onuReadPending = new Map();
let onuReadGeneration = 0;
function clearOnuReadCache() { onuReadGeneration++; onuReadCache.clear(); }
async function fetchOnuBatch(url, serialNumbers) {
    const generation = onuReadGeneration;
    const field = url.includes('location') ? 'locations' : 'devices';
    const serials = [...new Set(serialNumbers.map(String))];
    const output = {};
    const missing = [];
    const now = Date.now();
    serials.forEach(serial => {
        const entry = onuReadCache.get(url + ':' + serial);
        if (entry && now - entry.time < 30000) output[serial] = entry.value;
        else missing.push(serial);
    });
    for (let offset = 0; offset < missing.length; offset += 100) {
        const batch = missing.slice(offset, offset + 100).sort();
        const key = generation + ':' + url + ':' + JSON.stringify(batch);
        if (!onuReadPending.has(key)) {
            const pending = (async () => {
                const result = await fetchAPI(url, { method: 'POST', body: JSON.stringify({ serial_numbers: batch }) });
                if (!result?.success || !result[field]) throw new Error(result?.message || 'Consulta indisponível');
                const fetchedAt = new Date().toISOString();
                const values = {};
                batch.forEach(serial => {
                    const value = result[field][serial];
                    if (!value) return;
                    values[serial] = { ...value, _queried_at: fetchedAt };
                    if (generation === onuReadGeneration) onuReadCache.set(url + ':' + serial, {time: Date.now(), value: values[serial]});
                });
                return values;
            })();
            onuReadPending.set(key, pending);
        }
        try { Object.assign(output, await onuReadPending.get(key)); }
        finally { onuReadPending.delete(key); }
    }
    return { success: true, [field]: output };
}
function onuReadingLabel(source, queriedAt, measuredAt) {
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const queried = queriedAt ? new Date(queriedAt) : null;
    const time = queried && Number.isFinite(queried.getTime()) ? queried.toLocaleTimeString('pt-BR') : null;
    const detail = measuredAt ? 'Data da leitura na origem: ' + measuredAt : 'Data da leitura na origem não informada';
    return '<small class="d-block text-muted" title="' + escape(detail) + '">' + escape(source) +
        (time ? ' · consulta ' + escape(time) : ' · sem horário confirmado') + '</small>';
}
