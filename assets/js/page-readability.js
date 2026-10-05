(() => {
    const root = document.documentElement;
    const key = 'jrconect-acs-device-text-size';
    root.classList.add('acs-page-readable');
    let mode = 'standard';
    try { if (localStorage.getItem(key) === 'large') mode = 'large'; } catch (_) {}
    root.dataset.acsTextSize = mode;
    document.addEventListener('DOMContentLoaded', () => {
        const bar = document.querySelector('.topbar');
        if (!bar) return;
        const label = document.createElement('label');
        label.className = 'acs-page-text-size';
        label.htmlFor = 'acs-page-text-size';
        label.innerHTML = '<span>Tamanho do texto</span><select id="acs-page-text-size"><option value="standard">Padrão</option><option value="large">Grande</option></select>';
        const select = label.querySelector('select');
        select.value = mode;
        select.addEventListener('change', () => {
            mode = select.value === 'large' ? 'large' : 'standard';
            root.dataset.acsTextSize = mode;
            try { localStorage.setItem(key, mode); } catch (_) {}
        });
        bar.insertBefore(label, bar.querySelector('.user-info'));
    });
})();
