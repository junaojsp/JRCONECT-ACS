/* Presentation only. No fetch, API calls, timers or command handlers. */
(() => {
  'use strict';
  if (window.__jrcInventoryV3) return;
  window.__jrcInventoryV3 = true;
  const marker = 'jrc-inventory-v3';
  const clean = value => String(value || '').replace(/\s+/g, ' ').trim();
  function decorate(table) {
    table.classList.add(marker);
    const wrapper = table.closest('.table-responsive, .fb-table-wrap');
    if (wrapper) wrapper.classList.add('jrc-inv-scroll');
    const header = table.tHead && table.tHead.rows[table.tHead.rows.length - 1];
    if (!header) return;
    const names = Array.from(header.cells, cell => clean(cell.textContent).toLowerCase());
    const identityIndex = names.findIndex(name => /^(equipamento|dispositivo|serial)/.test(name));
    const modelIndex = names.findIndex(name => /^modelo\b/.test(name));
    if (identityIndex < 0) return;
    Array.from(table.tBodies).forEach(body => Array.from(body.rows).forEach(row => {
      const cell = row.cells[identityIndex];
      if (!cell || cell.colSpan !== 1) return;
      const link = Array.from(cell.querySelectorAll('a[href]')).find(anchor =>
        /^(?:\/)?device-detail\.php\?/.test(anchor.getAttribute('href') || ''));
      if (!link || link.classList.contains('jrc-inv-identity')) return;
      if (link.matches('.fb-equip, .jrc-device-identity')) {
        link.classList.add('jrc-inv-identity');
        return;
      }
      // Leave complex/custom link contents intact. Never clone operational controls.
      if (link.children.length || !clean(link.textContent)) return;
      const model = modelIndex >= 0 && row.cells[modelIndex]
        ? clean(row.cells[modelIndex].textContent) : '';
      const icon = document.createElement('span');
      icon.className = 'jrc-inv-icon'; icon.setAttribute('aria-hidden', 'true');
      icon.innerHTML = '<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 11V4m14 7V4M4 11h16a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1Z"/><path d="M7 15h.01M11 15h.01M7 18h10"/></svg>';
      const meta = document.createElement('span'); meta.className = 'jrc-inv-meta';
      if (model && !/^(n\/a|n\/d|-)$/.test(model.toLowerCase())) {
        const title = document.createElement('strong'); title.textContent = model;
        const serial = document.createElement('small');
        while (link.firstChild) serial.appendChild(link.firstChild);
        meta.append(title, serial);
      } else {
        const serial = document.createElement('strong');
        while (link.firstChild) serial.appendChild(link.firstChild);
        meta.append(serial);
      }
      link.classList.add('jrc-inv-identity'); link.append(icon, meta);
    }));
  }
  function start() {
    const roots = [document.getElementById('recent-devices'), document.getElementById('devices-table')].filter(Boolean);
    if (!roots.length) return;
    let scheduled = false;
    const observer = new MutationObserver(() => {
      if (scheduled) return;
      scheduled = true;
      requestAnimationFrame(update);
    });
    function update() {
      scheduled = false;
      observer.disconnect();
      try {
        roots.forEach(root => {
          if (root.matches('table')) decorate(root);
          else root.querySelectorAll('table').forEach(decorate);
        });
      } catch (error) { console.warn('[JRC inventory] Layout not applied:', error); }
      finally { roots.forEach(root => observer.observe(root, { childList: true, subtree: true })); }
    }
    update();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
