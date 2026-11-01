#!/usr/bin/env python3
import argparse
import datetime
import fcntl
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

JS_MARKER = "JRC_WIFI_SINGLE_RENDER_OWNER_V1"
CSS_START = "/* JRC_WIFI_MODAL_DARK_V1_START */"
CSS_END = "/* JRC_WIFI_MODAL_DARK_V1_END */"

CSS_BLOCK = r'''
/* JRC_WIFI_MODAL_DARK_V1_START */

/* Neutraliza os componentes Wi-Fi que ainda usam o tema azul legado. */
#jrDeviceControlModal .modal-content,
.jr-control-modal .modal-content {
    background: #111419 !important;
    color: #eef1f5 !important;
    border: 1px solid #2a3039 !important;
    box-shadow: 0 28px 80px rgba(0,0,0,.62) !important;
}

#jrDeviceControlModal .modal-header,
#jrDeviceControlModal .modal-body,
#jrDeviceControlModal .modal-footer,
.jr-control-modal .modal-header,
.jr-control-modal .modal-body,
.jr-control-modal .modal-footer {
    background: #111419 !important;
    color: #eef1f5 !important;
    border-color: #2a3039 !important;
}

#jrDeviceControlModal .modal-header,
.jr-control-modal .modal-header {
    background: #0f1216 !important;
}

#jrDeviceControlModal .modal-footer,
.jr-control-modal .modal-footer {
    background: #0f1216 !important;
}

#jrDeviceControlModal .form-control,
#jrDeviceControlModal .form-select,
#jrDeviceControlModal input,
#jrDeviceControlModal select,
.jr-control-modal .form-control,
.jr-control-modal .form-select,
.jr-control-modal input,
.jr-control-modal select {
    background: #0d1014 !important;
    color: #eef1f5 !important;
    border-color: #303640 !important;
    box-shadow: none !important;
}

#jrDeviceControlModal .form-control:focus,
#jrDeviceControlModal .form-select:focus,
.jr-control-modal .form-control:focus,
.jr-control-modal .form-select:focus {
    background: #11151a !important;
    color: #fff !important;
    border-color: #ef171f !important;
    box-shadow: 0 0 0 .18rem rgba(239,23,31,.10) !important;
}

#jrDeviceControlModal .jr-target,
#jrDeviceControlModal .jr-control-note,
#jrDeviceControlModal .jr-selection-meta,
#jrDeviceControlModal .jr-manager-note,
.jr-control-modal .jr-target,
.jr-control-modal .jr-control-note,
.jr-control-modal .jr-selection-meta,
.jr-control-modal .jr-manager-note {
    background: #15191f !important;
    color: #cfd5dd !important;
    border-color: #303640 !important;
}

#jrDeviceControlModal .jr-password-input button,
.jr-control-modal .jr-password-input button,
#jrDeviceControlModal .acs-eye-btn,
.jr-control-modal .acs-eye-btn {
    background: #15191f !important;
    color: #e6eaf0 !important;
    border-color: #303640 !important;
}

#jrDeviceControlModal .acs-soft-btn,
.jr-control-modal .acs-soft-btn,
.acs-wifi-edit-btn,
.acs-wifi-health-footer button,
.jr-wifi-band-switch .acs-soft-btn,
.jr-manager-actions .acs-soft-btn,
.acs-icon-btn,
.acs-eye-btn {
    background: #15191f !important;
    color: #e1e6ed !important;
    border-color: #343b45 !important;
    box-shadow: none !important;
}

#jrDeviceControlModal .acs-soft-btn:hover,
.jr-control-modal .acs-soft-btn:hover,
.acs-wifi-edit-btn:hover,
.acs-wifi-health-footer button:hover,
.jr-wifi-band-switch .acs-soft-btn:hover,
.jr-manager-actions .acs-soft-btn:hover,
.acs-icon-btn:hover,
.acs-eye-btn:hover {
    background: #1b2027 !important;
    color: #fff !important;
    border-color: #ef171f !important;
}

#jrDeviceControlModal .acs-soft-btn.primary,
.jr-control-modal .acs-soft-btn.primary,
.jr-wifi-band-switch .acs-soft-btn.primary,
.jr-manager-actions .acs-soft-btn.primary {
    background: #ef171f !important;
    color: #fff !important;
    border-color: #ef171f !important;
}

#jrDeviceControlModal .acs-soft-btn.primary:hover,
.jr-control-modal .acs-soft-btn.primary:hover,
.jr-wifi-band-switch .acs-soft-btn.primary:hover,
.jr-manager-actions .acs-soft-btn.primary:hover {
    background: #ff2a31 !important;
    border-color: #ff2a31 !important;
}

#jrDeviceControlModal .modal-title,
.jr-control-modal .modal-title,
#jrDeviceControlModal label,
.jr-control-modal label {
    color: #eef1f5 !important;
}

#jrDeviceControlModal small,
.jr-control-modal small {
    color: #98a2b1 !important;
}

.jr-manager,
.jr-wifi-bands-shell,
.jr-wifi-selected,
.jr-empty-control,
.jr-wifi-discover,
.jr-selection-meta,
.jr-manager-values > div {
    background: #111419 !important;
    color: #eef1f5 !important;
    border-color: #2a3039 !important;
}

/* Remove o ciano legado dos controles Wi-Fi sem afetar status verdes. */
.acs-wifi-edit-btn,
.acs-wifi-health-footer button,
.acs-soft-btn,
.acs-icon-btn,
.acs-eye-btn {
    --bs-btn-bg: #15191f !important;
    --bs-btn-border-color: #343b45 !important;
    --bs-btn-color: #e1e6ed !important;
}

/* JRC_WIFI_MODAL_DARK_V1_END */
'''


def js_check(path):
    node = shutil.which("node")
    if not node:
        return
    p = subprocess.run([node, "--check", str(path)], capture_output=True, text=True)
    if p.returncode != 0:
        raise RuntimeError((p.stderr or p.stdout)[:3000])


def atomic_write(path, text):
    meta = path.stat()
    fd, tmp = tempfile.mkstemp(prefix=".jrc-wifi-render-theme-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(text)
            f.flush()
            os.fsync(f.fileno())
            os.fchmod(f.fileno(), stat.S_IMODE(meta.st_mode))
            if os.geteuid() == 0:
                os.fchown(f.fileno(), meta.st_uid, meta.st_gid)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def patch_control_js(text):
    if JS_MARKER in text:
        return text

    # O device-control deve continuar carregando estado/API para o modal,
    # mas nao pode sobrescrever o card oficial da aba Wi-Fi depois que ele existe.
    pattern = re.compile(
        r"(function\s+renderWifi\s*\(\)\s*\{\s*"
        r"const\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*document\.querySelector\([^;]+;\s*"
        r"if\s*\(!\2\)\s*return;)",
        re.DOTALL
    )
    m = pattern.search(text)
    if not m:
        raise RuntimeError("Funcao renderWifi() do device-control-fix.js nao foi localizada")

    var_name = m.group(2)
    guard = f'''
        // {JS_MARKER}
        // O card oficial (.acs-wifi-health-list) pertence ao device-detail.js.
        // Este modulo continua mantendo estado, refresh e modal de edicao,
        // mas nao deve substituir novamente a grade principal.
        const jrcOfficialWifiHealth = {var_name}.querySelector('.acs-wifi-health-list');
        if (jrcOfficialWifiHealth) {{
            if (typeof window.jrcApplyWifiClientCounters === 'function' && window.currentDeviceData) {{
                window.jrcApplyWifiClientCounters(window.currentDeviceData);
            }}
            return;
        }}
'''
    text = text[:m.end()] + guard + text[m.end():]
    return text


def patch_css(text):
    if CSS_START in text and CSS_END in text:
        pattern = re.compile(re.escape(CSS_START) + r".*?" + re.escape(CSS_END), re.DOTALL)
        return pattern.sub(CSS_BLOCK.strip(), text, count=1)

    if CSS_START in text or CSS_END in text:
        raise RuntimeError("Marcador parcial do tema Wi-Fi encontrado no style.css")

    return text.rstrip() + "\n\n" + CSS_BLOCK.strip() + "\n"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/gacs")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    control_js = root / "assets/js/device-control-fix.js"
    css = root / "assets/css/style.css"

    if not control_js.is_file() or control_js.is_symlink():
        raise RuntimeError("assets/js/device-control-fix.js nao encontrado na producao")
    if not css.is_file() or css.is_symlink():
        raise RuntimeError("assets/css/style.css nao encontrado")

    js_before = control_js.read_text(encoding="utf-8")
    css_before = css.read_text(encoding="utf-8")

    js_after = patch_control_js(js_before)
    css_after = patch_css(css_before)

    with tempfile.NamedTemporaryFile("w", suffix=".js", delete=False, encoding="utf-8") as f:
        f.write(js_after)
        tmp_js = Path(f.name)

    try:
        js_check(tmp_js)
    finally:
        tmp_js.unlink(missing_ok=True)

    if JS_MARKER not in js_after:
        raise RuntimeError("Guard de render Wi-Fi nao foi inserido")
    if CSS_START not in css_after or CSS_END not in css_after:
        raise RuntimeError("Tema escuro especifico do Wi-Fi nao foi inserido")

    print("VALIDADO: consolidacao do render Wi-Fi + tema escuro.")
    print("device-control-fix continuara consultando /api/device-control.php e abrindo o modal.")
    print("Quando o card oficial estiver presente, ele nao sera mais sobrescrito pelo render legado.")
    print("Canal, clientes e demais dados do card oficial deixam de piscar/sumir por conflito de render.")
    print("Modal e controles Wi-Fi serao neutralizados para preto/cinza + vermelho.")

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado.")
        return

    backup_parent = Path(args.backup_dir).resolve()
    lock_path = backup_parent / ".jrc-wifi-render-theme-v1.lock"

    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        if control_js.read_text(encoding="utf-8") != js_before:
            raise RuntimeError("device-control-fix.js mudou durante a instalacao")
        if css.read_text(encoding="utf-8") != css_before:
            raise RuntimeError("style.css mudou durante a instalacao")

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-wifi-render-theme-v1-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        (backup / "assets/js").mkdir(parents=True, exist_ok=True)
        (backup / "assets/css").mkdir(parents=True, exist_ok=True)

        shutil.copy2(control_js, backup / "assets/js/device-control-fix.js")
        shutil.copy2(css, backup / "assets/css/style.css")
        shutil.copy2(Path(__file__), backup / "install.py")

        atomic_write(control_js, js_after)
        atomic_write(css, css_after)

        js_check(control_js)

        print("INSTALADO: render Wi-Fi consolidado + tema escuro V1.")
        print("Backup: " + str(backup))


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
