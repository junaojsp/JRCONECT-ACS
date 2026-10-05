(() => {
    const key = 'jrconect-acs-device-text-size';
    const root = document.documentElement;
    root.classList.add('acs-device-readable');
    let mode = 'standard';
    try { if (localStorage.getItem(key) === 'large') mode = 'large'; } catch (_) {}
    root.dataset.acsTextSize = mode;
    document.addEventListener('DOMContentLoaded', () => {
        const select = document.getElementById('acs-text-size');
        if (!select) return;
        select.value = mode;
        select.addEventListener('change', () => {
            mode = select.value === 'large' ? 'large' : 'standard';
            root.dataset.acsTextSize = mode;
            try { localStorage.setItem(key, mode); } catch (_) {}
        });
    });
})();
