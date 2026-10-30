#!/usr/bin/env python3
"""Final V5 cleanup: force provisioning text inputs to neutral black/gray."""
import argparse
import datetime
import fcntl
import os
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

TARGET = "provisionamento-onu.php"
MARKER = "JRC_PROVISION_INPUTS_V5"

STYLE = r"""
<style id="jrc-provision-inputs-v5">
/* JRC_PROVISION_INPUTS_V5 */
html body #onu-serial,
html body #onu-model,
html body #onu-client,
html body .onu-filters #onu-serial,
html body .onu-filters #onu-model,
html body .onu-filters #onu-client {
    --bs-body-bg: #0d0f12 !important;
    --bs-secondary-bg: #0d0f12 !important;
    --bs-tertiary-bg: #0d0f12 !important;
    background: #0d0f12 !important;
    background-color: #0d0f12 !important;
    background-image: none !important;
    border: 1px solid #30343c !important;
    color: #e8ebef !important;
    box-shadow: inset 0 0 0 1000px #0d0f12 !important;
    -webkit-box-shadow: inset 0 0 0 1000px #0d0f12 !important;
    -webkit-text-fill-color: #e8ebef !important;
    color-scheme: dark !important;
}

html body #onu-serial:hover,
html body #onu-model:hover,
html body #onu-client:hover,
html body #onu-serial:focus,
html body #onu-model:focus,
html body #onu-client:focus {
    background: #101217 !important;
    background-color: #101217 !important;
    border-color: #555d69 !important;
    box-shadow:
        inset 0 0 0 1000px #101217,
        0 0 0 2px rgba(255,255,255,.035) !important;
    -webkit-box-shadow:
        inset 0 0 0 1000px #101217,
        0 0 0 2px rgba(255,255,255,.035) !important;
    outline: none !important;
}

html body #onu-serial::placeholder,
html body #onu-model::placeholder,
html body #onu-client::placeholder {
    color: #697384 !important;
    opacity: 1 !important;
    -webkit-text-fill-color: #697384 !important;
}

html body #onu-serial:-webkit-autofill,
html body #onu-model:-webkit-autofill,
html body #onu-client:-webkit-autofill,
html body #onu-serial:-webkit-autofill:hover,
html body #onu-model:-webkit-autofill:hover,
html body #onu-client:-webkit-autofill:hover,
html body #onu-serial:-webkit-autofill:focus,
html body #onu-model:-webkit-autofill:focus,
html body #onu-client:-webkit-autofill:focus {
    -webkit-text-fill-color: #e8ebef !important;
    -webkit-box-shadow: inset 0 0 0 1000px #0d0f12 !important;
    box-shadow: inset 0 0 0 1000px #0d0f12 !important;
    transition: background-color 999999s ease-in-out 0s !important;
}
</style>
"""

def php_lint(text: str):
    php = shutil.which("php")
    if not php:
        raise RuntimeError("PHP CLI nao encontrado.")
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as f:
        f.write(text)
        tmp = f.name
    try:
        p = subprocess.run([php, "-l", tmp], capture_output=True, text=True)
        if p.returncode != 0:
            raise RuntimeError((p.stderr or p.stdout)[:1800])
    finally:
        os.unlink(tmp)

def atomic_write(path: Path, data: bytes, meta):
    fd, tmp = tempfile.mkstemp(prefix=".jrc-prov-v5-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
            os.fchmod(f.fileno(), stat.S_IMODE(meta.st_mode))
            if os.geteuid() == 0:
                os.fchown(f.fileno(), meta.st_uid, meta.st_gid)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def patch(text: str):
    if MARKER in text:
        return text, 0

    required = ['id="onu-serial"', 'id="onu-model"', 'id="onu-client"']
    missing = [x for x in required if x not in text]
    if missing:
        raise RuntimeError(
            "Campos esperados nao encontrados: " + ", ".join(missing)
        )

    anchor_candidates = [
        '<script src="/assets/js/provisionamento-onu.js',
        "<script src='/assets/js/provisionamento-onu.js",
        '</body>',
    ]

    pos = -1
    for anchor in anchor_candidates:
        pos = text.find(anchor)
        if pos >= 0:
            break

    if pos < 0:
        raise RuntimeError(
            "Ponto seguro para inserir o override visual nao encontrado."
        )

    return text[:pos] + STYLE + "\n" + text[pos:], 1

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--root", default="/var/www/gacs")
    ap.add_argument("--backup-dir", default="/root")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    backup_parent = Path(args.backup_dir).resolve()
    target = root / TARGET

    if not target.is_file() or target.is_symlink():
        raise RuntimeError("provisionamento-onu.php nao encontrado.")

    lock_path = backup_parent / ".jrc-provision-inputs-v5.lock"

    with lock_path.open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)

        before = target.read_text(encoding="utf-8")
        after, changes = patch(before)
        php_lint(after)

        print("VALIDADO: Provisionamento V5 inputs.")
        print("Somente os campos Serial Number, Modelo e Cliente IXC serao alterados.")
        print("Azul residual sera substituido por preto/cinza.")
        print("Nenhuma logica de carregamento sera alterada.")
        print(f"Alteracoes previstas: {changes}.")

        if args.dry_run:
            print("SIMULACAO OK: nenhum arquivo alterado.")
            return

        backup = Path(tempfile.mkdtemp(
            prefix="gacs-provisionamento-v5-" +
                   datetime.datetime.now().strftime("%Y%m%d-%H%M%S") + "-",
            dir=str(backup_parent),
        ))
        os.chmod(backup, 0o700)

        shutil.copy2(target, backup / TARGET)
        shutil.copy2(Path(__file__), backup / "install.py")

        if target.read_text(encoding="utf-8") != before:
            raise RuntimeError(
                "provisionamento-onu.php mudou durante a instalacao."
            )

        atomic_write(target, after.encode("utf-8"), target.stat())
        php_lint(target.read_text(encoding="utf-8"))

        print("INSTALADO: inputs do Provisionamento neutralizados.")
        print("Backup: " + str(backup))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("CANCELADO: " + str(exc), file=sys.stderr)
        sys.exit(1)
