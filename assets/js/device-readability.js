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
        const overview = document.getElementById('overview-content');
        if (!overview || typeof ResizeObserver === 'undefined') return;
        let frame = null;
        const watched = new Set();
        const schedule = () => {
            if (frame !== null) return;
            frame = requestAnimationFrame(() => {
                frame = null;
                const grid = overview.querySelector('.acs-overview-grid');
                if (!grid) return;
                const cards = Array.from(grid.children).filter(card => card.classList.contains('acs-overview-card'));
                const current = new Set([grid, ...cards]);
                for (const node of watched) if (!current.has(node)) { resize.unobserve(node); watched.delete(node); }
                for (const node of current) if (!watched.has(node)) { resize.observe(node); watched.add(node); }
                const packed = window.matchMedia('(min-width: 1101px)').matches;
                grid.dataset.acsPacked = packed ? 'true' : 'false';
                const style = getComputedStyle(grid);
                const gap = parseFloat(style.rowGap) || 18;
                const row = parseFloat(style.gridAutoRows) || 8;
                for (const card of cards) {
                    const height = card.getBoundingClientRect().height;
                    // Hidden tabs retain their previous spans until visible again.
                    if (packed && height === 0) continue;
                    const end = packed ? 'span ' + Math.max(1, Math.ceil((height + gap) / (row + gap))) : 'auto';
                    if (card.style.gridRowEnd !== end) card.style.gridRowEnd = end;
                }
            });
        };
        const resize = new ResizeObserver(schedule);
        resize.observe(overview);
        const mutations = new MutationObserver(schedule);
        mutations.observe(overview, {childList:true, subtree:true});
        window.addEventListener('resize', schedule);
        schedule();
        window.addEventListener('beforeunload', () => {
            resize.disconnect(); mutations.disconnect();
            window.removeEventListener('resize', schedule);
            if (frame !== null) cancelAnimationFrame(frame);
        });
    });
})();
