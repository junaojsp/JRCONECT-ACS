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

START = "/* JRC_GLOBAL_DARK_SURFACES_V1_START */"
END = "/* JRC_GLOBAL_DARK_SURFACES_V1_END */"

CSS_BLOCK = r'''
/* JRC_GLOBAL_DARK_SURFACES_V1_START */

/*
 * JR Conect ACS - normalizacao global de superficies.
 * Objetivo: remover fundos azul/ciano herdados de Bootstrap/temas antigos
 * e manter o padrao visual preto/cinza com vermelho como destaque.
 * Nao altera logica, layout estrutural ou links operacionais.
 */

:root {
    --jrc-dark-bg: #090b0f;
    --jrc-dark-panel: #111419;
    --jrc-dark-panel-2: #15191f;
    --jrc-dark-panel-3: #1a1e25;
    --jrc-dark-border: #2a3039;
    --jrc-dark-border-soft: #22272f;
    --jrc-dark-text: #eef1f5;
    --jrc-dark-muted: #98a2b1;
    --jrc-accent-red: #ef171f;
    --jrc-accent-red-hover: #ff2a31;
}

/* Estrutura principal */
html,
body,
.main-content,
.content-wrapper,
.page-content,
.tab-content,
.tab-pane {
    background-color: var(--jrc-dark-bg) !important;
    color: var(--jrc-dark-text) !important;
}

.topbar,
.page-header,
footer,
.footer,
.main-content > footer {
    background: #0c0f13 !important;
    border-color: var(--jrc-dark-border-soft) !important;
}

/* Cards / paineis / containers */
.card,
.card-body,
.card-header,
.card-footer,
.panel,
.panel-body,
.panel-heading,
.accordion-item,
.accordion-body,
.accordion-header,
.accordion-button,
.accordion-button:not(.collapsed),
.list-group,
.list-group-item,
.dropdown-menu,
.offcanvas,
.popover,
.toast,
.table-responsive {
    background: var(--jrc-dark-panel) !important;
    color: var(--jrc-dark-text) !important;
    border-color: var(--jrc-dark-border) !important;
}

.card-header,
.card-footer,
.panel-heading,
.accordion-button,
.accordion-button:not(.collapsed),
.modal-header,
.modal-footer {
    background: #0f1216 !important;
}

/* Tabelas - remove azul do Bootstrap e estilos legados */
.table,
.table-primary,
.table-info,
.table-light,
.table-secondary,
.table-dark,
#connected-devices-table {
    --bs-table-bg: var(--jrc-dark-panel) !important;
    --bs-table-color: var(--jrc-dark-text) !important;
    --bs-table-border-color: var(--jrc-dark-border) !important;
    --bs-table-striped-bg: #14181e !important;
    --bs-table-striped-color: var(--jrc-dark-text) !important;
    --bs-table-active-bg: #191d24 !important;
    --bs-table-active-color: var(--jrc-dark-text) !important;
    --bs-table-hover-bg: #1a1f26 !important;
    --bs-table-hover-color: #ffffff !important;
    color: var(--jrc-dark-text) !important;
    border-color: var(--jrc-dark-border) !important;
}

.table > :not(caption) > * > *,
.table-primary > :not(caption) > * > *,
.table-info > :not(caption) > * > *,
.table-light > :not(caption) > * > *,
.table-secondary > :not(caption) > * > *,
#connected-devices-table > :not(caption) > * > * {
    background-color: var(--jrc-dark-panel) !important;
    color: var(--jrc-dark-text) !important;
    border-color: var(--jrc-dark-border) !important;
    box-shadow: none !important;
}

.table > thead > tr > th,
.table thead th,
#connected-devices-table thead th {
    background: #0f1216 !important;
    color: #aeb6c2 !important;
    border-color: var(--jrc-dark-border) !important;
}

.table > tbody > tr:hover > *,
.table-hover > tbody > tr:hover > *,
#connected-devices-table tbody tr:hover > * {
    background: var(--jrc-dark-panel-3) !important;
    color: #ffffff !important;
}

/* Area de dispositivos conectados */
#devices-content,
#devices-content .table-responsive,
#devices-content .table,
#connected-devices-table {
    background: var(--jrc-dark-panel) !important;
}

#hotspot-status,
.hotspot-user,
.hotspot-traffic {
    background: transparent !important;
}

/* Formularios */
.form-control,
.form-select,
.form-control[readonly],
.form-control:disabled,
.form-select:disabled,
.input-group-text,
textarea,
select,
input[type="text"],
input[type="password"],
input[type="email"],
input[type="number"],
input[type="search"],
input[type="url"] {
    background: #0f1216 !important;
    color: var(--jrc-dark-text) !important;
    border-color: var(--jrc-dark-border) !important;
}

.form-control::placeholder,
input::placeholder,
textarea::placeholder {
    color: #737d8a !important;
}

.form-control:focus,
.form-select:focus,
textarea:focus,
input:focus,
select:focus {
    background: #12161b !important;
    color: #ffffff !important;
    border-color: var(--jrc-accent-red) !important;
    box-shadow: 0 0 0 .18rem rgba(239, 23, 31, .10) !important;
}

.form-check-input {
    background-color: #0f1216 !important;
    border-color: #3a414b !important;
}

.form-check-input:checked {
    background-color: var(--jrc-accent-red) !important;
    border-color: var(--jrc-accent-red) !important;
}

/* Abas e navegacao interna */
.nav-tabs,
.nav-pills,
#deviceTabs,
.device-tabs {
    background: #0d1014 !important;
    border-color: var(--jrc-dark-border-soft) !important;
}

.nav-tabs .nav-link,
.nav-pills .nav-link,
#deviceTabs .nav-link,
.device-tabs .nav-link {
    background: transparent !important;
    color: #9ba5b3 !important;
    border-color: transparent !important;
}

.nav-tabs .nav-link:hover,
.nav-pills .nav-link:hover,
#deviceTabs .nav-link:hover,
.device-tabs .nav-link:hover {
    background: #15191f !important;
    color: #ffffff !important;
}

.nav-tabs .nav-link.active,
.nav-pills .nav-link.active,
#deviceTabs .nav-link.active,
.device-tabs .nav-link.active {
    background: #15191f !important;
    color: #ffffff !important;
    border-color: #303640 !important;
    box-shadow: inset 0 -2px 0 var(--jrc-accent-red) !important;
}

.nav-tabs .badge,
.nav-pills .badge,
#deviceTabs .badge,
.device-tabs .badge {
    background: #1b1f25 !important;
    color: #d7dce4 !important;
    border-color: #363c46 !important;
}

/* Modais - sobrepoe inclusive fundos azuis inline sem !important */
.modal-content {
    background: var(--jrc-dark-panel) !important;
    color: var(--jrc-dark-text) !important;
    border: 1px solid var(--jrc-dark-border) !important;
    box-shadow: 0 28px 80px rgba(0, 0, 0, .58) !important;
}

.modal-header,
.modal-footer {
    background: #0f1216 !important;
    border-color: var(--jrc-dark-border) !important;
}

.modal-body {
    background: var(--jrc-dark-panel) !important;
    color: var(--jrc-dark-text) !important;
}

.modal .alert-info,
.modal .bg-primary,
.modal .bg-info {
    background: #171b21 !important;
    color: var(--jrc-dark-text) !important;
    border-color: #333a44 !important;
}

/* Botoes Bootstrap que ainda aparecerem azuis */
.btn-primary {
    background: var(--jrc-accent-red) !important;
    border-color: var(--jrc-accent-red) !important;
    color: #ffffff !important;
    box-shadow: none !important;
}

.btn-primary:hover,
.btn-primary:focus,
.btn-primary:active {
    background: var(--jrc-accent-red-hover) !important;
    border-color: var(--jrc-accent-red-hover) !important;
    color: #ffffff !important;
}

.btn-outline-primary,
.btn-outline-info {
    background: #15191f !important;
    border-color: #39404a !important;
    color: #e5e9ef !important;
}

.btn-outline-primary:hover,
.btn-outline-info:hover,
.btn-outline-primary:focus,
.btn-outline-info:focus {
    background: #1b2027 !important;
    border-color: var(--jrc-accent-red) !important;
    color: #ffffff !important;
}

/* Alerts informativos sem bloco azul */
.alert-info {
    background: #15191f !important;
    color: #dce1e8 !important;
    border-color: #303640 !important;
}

/* Badges Bootstrap azuis */
.badge.bg-primary,
.badge.bg-info,
.text-bg-primary,
.text-bg-info {
    background: #1b2026 !important;
    color: #e5e9ef !important;
    border: 1px solid #343b45 !important;
}

/* Paginacao */
.page-link {
    background: #111419 !important;
    color: #d8dde5 !important;
    border-color: var(--jrc-dark-border) !important;
}

.page-link:hover {
    background: #1a1f26 !important;
    color: #ffffff !important;
    border-color: #3a414b !important;
}

.page-item.active .page-link {
    background: var(--jrc-accent-red) !important;
    border-color: var(--jrc-accent-red) !important;
    color: #ffffff !important;
}

/* Menus e popups */
.dropdown-item {
    color: #d8dde5 !important;
}

.dropdown-item:hover,
.dropdown-item:focus,
.dropdown-item.active {
    background: #1a1f26 !important;
    color: #ffffff !important;
}

/* Mantem status operacionais sem interferencia */
.badge.online,
.text-success,
.bg-success {
    /* preserva verde funcional */
}

/* JRC_GLOBAL_DARK_SURFACES_V1_END */
'''


def atomic_write(path: Path, text: str):
    meta = path.stat()
    fd, tmp = tempfile.mkstemp(prefix=".jrc-dark-ui-", dir=str(path.parent))
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


def patch_css(text: str) -> str:
    if START in text and END in text:
        pattern = re.compile(
            re.escape(START) + r".*?" + re.escape(END),
            re.DOTALL
        )
        return pattern.sub(CSS_BLOCK.strip(), text, count=1)

    if START in text or END in text:
        raise RuntimeError("Marcador parcial de normalizacao encontrado; revise o style.css antes de aplicar")

    return text.rstrip() + "\n\n" + CSS_BLOCK.strip() + "\n"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/gacs")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    css = root / "assets/css/style.css"

    if not css.is_file() or css.is_symlink():
        raise RuntimeError("Arquivo ausente ou invalido: " + str(css))

    before = css.read_text(encoding="utf-8")
    after = patch_css(before)

    if START not in after or END not in after:
        raise RuntimeError("Bloco de normalizacao nao foi gerado corretamente")

    print("VALIDADO: normalizacao global de superficies JR CONECT.")
    print("Serao neutralizados fundos azul/ciano de tabelas, modais, formularios, abas, cards e componentes Bootstrap.")
    print("Vermelho permanece como destaque de acao/aba ativa.")
    print("Links operacionais e logica das paginas nao serao alterados.")

    if args.dry_run:
        print("SIMULACAO OK: nenhum arquivo alterado.")
        return

    backup_parent = Path(args.backup_dir).resolve()
    lock_path = backup_parent / ".jrc-global-dark-ui-v1.lock"

    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        if css.read_text(encoding="utf-8") != before:
            raise RuntimeError("assets/css/style.css mudou durante a instalacao")

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-global-dark-ui-v1-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent)
        ))
        os.chmod(backup, 0o700)

        (backup / "assets/css").mkdir(parents=True, exist_ok=True)
        shutil.copy2(css, backup / "assets/css/style.css")
        shutil.copy2(Path(__file__), backup / "install.py")

        atomic_write(css, after)

        print("INSTALADO: normalizacao global escura V1.")
        print("Backup: " + str(backup))


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
