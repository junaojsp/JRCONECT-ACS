#!/usr/bin/env python3
"""Install an isolated inventory presentation layer over local ACS files.
No checkout, pull, API call, database access or header/footer replacement.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile

VERSION = '20261022-3'
START = '/* JRC_INVENTORY_SAFE_V3_START */'
END = '/* JRC_INVENTORY_SAFE_V3_END */'
CSS_PATH = 'assets/css/jrc-inventory-safe-v3.css'
JS_PATH = 'assets/js/jrc-inventory-safe-v3.js'
CSS = r'''/* JRC inventory v3. Scoped presentation; original data and controls remain. */
body #recent-devices .jrc-inv-scroll,
body .jrc-inv-scroll:has(> #devices-table) {
  overflow-x: auto; border: 1px solid #30333c; border-radius: 12px;
  background: #14161b; scrollbar-color: #484c58 #14161b;
}
body #recent-devices table.jrc-inventory-v3,
body #devices-table.jrc-inventory-v3 {
  width: 100% !important; table-layout: auto !important;
  margin: 0 !important; border-collapse: collapse !important; border-spacing: 0 !important;
  --bs-table-bg: transparent; --bs-table-hover-bg: transparent;
  --bs-table-accent-bg: transparent; --bs-table-striped-bg: transparent;
  color: #e3e7ef;
}
body #recent-devices table.jrc-inventory-v3 > thead > tr > th,
body #devices-table.jrc-inventory-v3 > thead > tr > th {
  background: #1b1e25 !important; color: #a6aebe !important;
  padding: 14px 16px !important; font-size: 11px !important;
  font-weight: 600 !important; letter-spacing: .055em;
  text-transform: uppercase; white-space: nowrap;
  border: 0 !important; border-bottom: 1px solid #333843 !important;
  box-shadow: none !important;
}
body #recent-devices table.jrc-inventory-v3 > tbody > tr,
body #devices-table.jrc-inventory-v3 > tbody > tr {
  background: #151820 !important; border: 0 !important;
  border-bottom: 1px solid #2c303a !important;
}
body #recent-devices table.jrc-inventory-v3 > tbody > tr:hover,
body #devices-table.jrc-inventory-v3 > tbody > tr:hover {
  background: #202631 !important;
}
body #recent-devices table.jrc-inventory-v3 > tbody > tr > td,
body #devices-table.jrc-inventory-v3 > tbody > tr > td {
  background: transparent !important; box-shadow: none !important;
  border: 0 !important; padding: 15px 16px !important;
  font-size: 13px !important; line-height: 1.45 !important;
  color: #dce2ed !important; vertical-align: middle !important;
}
/* The old .fb-muted rule uses display:block, including on TD elements. */
body #recent-devices table.jrc-inventory-v3 > tbody > tr > td.fb-muted:not([hidden]):not(.tags-column):not([style*="display:none"]):not([style*="display: none"]),
body #devices-table.jrc-inventory-v3 > tbody > tr > td.fb-muted:not([hidden]):not(.tags-column):not([style*="display:none"]):not([style*="display: none"]) {
  display: table-cell !important;
}
body #recent-devices table.jrc-inventory-v3 > tbody > tr:focus-within,
body #devices-table.jrc-inventory-v3 > tbody > tr:focus-within { background: #202631 !important; }
body #recent-devices table.jrc-inventory-v3 a:focus-visible,
body #devices-table.jrc-inventory-v3 a:focus-visible {
  outline: 2px solid #ff767d; outline-offset: 4px;
}
body #recent-devices .jrc-inv-identity,
body #devices-table .jrc-inv-identity {
  display: inline-flex !important; align-items: center; gap: 12px !important;
  min-width: 180px; text-decoration: none !important; color: #f4f6fb !important;
}
body #recent-devices .jrc-inv-icon,
body #devices-table .jrc-inv-icon,
body #recent-devices .jrc-inv-identity .fb-equip-icon,
body #devices-table .jrc-inv-identity .fb-equip-icon,
body #recent-devices .jrc-inv-identity .jrc-device-icon,
body #devices-table .jrc-inv-identity .jrc-device-icon {
  width: 38px !important; height: 38px !important; flex: 0 0 38px;
  display: inline-flex; align-items: center; justify-content: center;
  border-radius: 10px !important; background: #34222c !important; color: #ff7e88 !important;
}
body #recent-devices .jrc-inv-meta,
body #devices-table .jrc-inv-meta { display: flex; flex-direction: column; gap: 3px; }
body #recent-devices .jrc-inv-identity strong,
body #devices-table .jrc-inv-identity strong {
  display: block; font-size: 13px !important; font-weight: 600 !important;
  line-height: 1.4 !important; color: #f4f6fb !important;
}
body #recent-devices .jrc-inv-identity small,
body #devices-table .jrc-inv-identity small {
  display: block; font-size: 11px !important; font-weight: 400 !important;
  line-height: 1.5 !important; color: #9ca7b9 !important;
}
body #recent-devices table.jrc-inventory-v3 .badge,
body #devices-table.jrc-inventory-v3 .badge,
body #recent-devices table.jrc-inventory-v3 .fb-signal,
body #devices-table.jrc-inventory-v3 .fb-signal {
  border-radius: 999px !important; padding: 5px 9px !important;
  font-size: 11px !important; line-height: 1.3 !important;
}
body #recent-devices table.jrc-inventory-v3 .btn,
body #devices-table.jrc-inventory-v3 .btn {
  border-radius: 8px !important; min-width: 32px; min-height: 32px;
}
@media (max-width: 767px) {
  body #recent-devices table.jrc-inventory-v3 > tbody > tr > td,
  body #devices-table.jrc-inventory-v3 > tbody > tr > td { padding: 12px !important; }
  body #recent-devices .jrc-inv-identity, body #devices-table .jrc-inv-identity { min-width: 160px; }
}
'''
JS = r'''/* Presentation only. No fetch, API calls, timers or command handlers. */
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
'''


def digest(data):
    return hashlib.sha256(data).hexdigest()


def check_php(data):
    with tempfile.NamedTemporaryFile(suffix='.php') as handle:
        handle.write(data)
        handle.flush()
        result = subprocess.run(['php', '-l', handle.name], capture_output=True, text=True)
    if result.returncode:
        raise RuntimeError('PHP invalido. Nenhum arquivo instalado. ' + (result.stdout + result.stderr).strip())


def inject(data, page):
    text = data.decode('utf-8')
    required = 'recent-devices' if page == 'dashboard.php' else 'devices-table'
    if required not in text:
        raise RuntimeError(page + ': identificador esperado nao encontrado; instalacao cancelada.')
    text = re.sub(r'(?m)^[ \t]*/\* JRC_INVENTORY_SAFE_V3_START \*/.*?/\* JRC_INVENTORY_SAFE_V3_END \*/\r?\n', '', text, flags=re.S)
    pattern = re.compile(r"(?m)^(?P<indent>[ \t]*)(?:include|require)(?:_once)?\s+__DIR__\s*\.\s*(['\"])/views/layouts/footer\.php\2\s*;[^\r\n]*$")
    matches = list(pattern.finditer(text))
    if len(matches) != 1:
        raise RuntimeError(page + ': footer compartilhado nao identificado de forma unica; nada alterado.')
    match = matches[0]
    indent = match.group('indent')
    newline = '\r\n' if '\r\n' in text else '\n'
    block = newline.join([
        indent + START,
        indent + 'echo \'<link rel="stylesheet" href="/' + CSS_PATH + '?v=' + VERSION + '">\';',
        indent + 'echo \'<script src="/' + JS_PATH + '?v=' + VERSION + '"></script>\';',
        indent + END,
        ''
    ])
    return (text[:match.start()] + block + text[match.start():]).encode('utf-8')


def write_atomic(path, data, mode, uid, gid):
    fd, name = tempfile.mkstemp(prefix='.' + path.name + '.', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(name, mode)
        os.chown(name, uid, gid)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def apply(root, backup_root, dry_run):
    root = root.resolve(strict=True)
    if not shutil.which('php'):
        raise RuntimeError('PHP CLI nao encontrado. Nada alterado.')
    names = ['dashboard.php', 'devices.php', CSS_PATH, JS_PATH]
    before, after, metadata = {}, {}, {}
    for name in names:
        path = root / name
        if path.is_symlink() or not path.parent.is_dir() or not path.resolve().is_relative_to(root):
            raise RuntimeError('Caminho inesperado: ' + name + '. Nada alterado.')
        before[name] = path.read_bytes() if path.exists() else None
        if name.endswith('.php'):
            if before[name] is None:
                raise RuntimeError('Arquivo ausente: ' + name)
            check_php(before[name])
            after[name] = inject(before[name], name)
            check_php(after[name])
        else:
            after[name] = (CSS if name == CSS_PATH else JS).encode('utf-8')
        info = path.stat() if path.exists() else path.parent.stat()
        metadata[name] = dict(mode=stat.S_IMODE(info.st_mode) if path.exists() else 0o644, uid=info.st_uid, gid=info.st_gid)
    changed = [name for name in names if before[name] != after[name]]
    if not changed:
        print('Layout v3 ja instalado. Nenhum arquivo alterado.')
        return
    if dry_run:
        print('VALIDACAO OK (somente leitura). Arquivos: ' + ', '.join(changed))
        return
    backup_root.mkdir(parents=True, exist_ok=True)
    backup = Path(tempfile.mkdtemp(prefix='gacs-layout-' + datetime.datetime.now().strftime('%Y%m%d-%H%M%S') + '-', dir=str(backup_root)))
    os.chmod(backup, 0o700)
    manifest = {'root': str(root), 'files': {}}
    for name in changed:
        original = before[name]
        if original is not None:
            target = backup / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(root / name, target)
        manifest['files'][name] = dict(metadata[name], existed=original is not None,
            before_sha=digest(original) if original is not None else None, after_sha=digest(after[name]))
    (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    shutil.copy2(Path(__file__), backup / 'install_inventory.py')
    for name in names:
        path = root / name
        now = path.read_bytes() if path.exists() else None
        if now != before[name]:
            raise RuntimeError('Alteracao concorrente detectada em ' + name + '. Instalacao cancelada. Backup: ' + str(backup))
    written = []
    try:
        # Assets first, then page includes. The old pages continue to work throughout.
        for name in [CSS_PATH, JS_PATH, 'dashboard.php', 'devices.php']:
            if name not in changed:
                continue
            write_atomic(root / name, after[name], **metadata[name])
            written.append(name)
        for name in changed:
            if (root / name).read_bytes() != after[name]:
                raise RuntimeError('Verificacao apos escrita falhou: ' + name)
    except BaseException:
        for name in reversed(written):
            path = root / name
            if path.exists() and digest(path.read_bytes()) != digest(after[name]):
                print('Preservada alteracao concorrente: ' + name, file=sys.stderr)
                continue
            if before[name] is None:
                path.unlink(missing_ok=True)
            else:
                write_atomic(path, before[name], **metadata[name])
        raise
    pointer = backup_root / 'gacs-inventario-v3-ultimo'
    pointer.write_text(str(backup) + '\n')
    print('INSTALADO: layout v3 em Dashboard e Dispositivos.')
    print('Backup: ' + str(backup))
    print('Reverter: python3 ' + str(backup / 'install_inventory.py') + ' --rollback ' + str(backup))
    print('Header, APIs, renderizadores originais, banco e Git nao foram alterados.')


def rollback(backup):
    backup = backup.resolve(strict=True)
    manifest = json.loads((backup / 'manifest.json').read_text())
    root = Path(manifest['root'])
    pending = []
    for name, item in manifest['files'].items():
        path = root / name
        if path.is_symlink() or not path.resolve().is_relative_to(root):
            raise RuntimeError('Caminho inesperado; reversao cancelada.')
        actual = digest(path.read_bytes()) if path.exists() else None
        if actual == item['before_sha']:
            continue
        if actual != item['after_sha']:
            raise RuntimeError('Arquivo alterado depois da instalacao: ' + name + '. Nada restaurado para nao perder seu trabalho.')
        if item['existed'] and digest((backup / name).read_bytes()) != item['before_sha']:
            raise RuntimeError('Backup inconsistente: ' + name)
        pending.append((name, item))
    for name, item in pending:
        path = root / name
        if item['existed']:
            write_atomic(path, (backup / name).read_bytes(), item['mode'], item['uid'], item['gid'])
        else:
            path.unlink(missing_ok=True)
    print('RESTAURADO: arquivos anteriores ao layout v3. Demais arquivos preservados.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path('/var/www/gacs'))
    parser.add_argument('--backup-root', type=Path, default=Path('/root'))
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--rollback', type=Path)
    args = parser.parse_args()
    try:
        if args.rollback:
            rollback(args.rollback)
        else:
            apply(args.root, args.backup_root, args.dry_run)
    except Exception as error:
        print('ERRO: ' + str(error), file=sys.stderr)
        return 1
    return 0

if __name__ == '__main__':
    sys.exit(main())
