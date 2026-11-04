#!/usr/bin/env python3
"""Prepara integracao REST PABX no MCP de producao SEM reiniciar servicos.

Padrao: simulacao. So grava com --apply. Abortamos em layout inesperado.
Preserva server.js existente (Wiki, Opa, conexoes e OAuth).
"""
import argparse
import difflib
import re
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

OLD_IMPORT = "import { consultarPabx, pabxConfigurado } from './pabx.js';"
NEW_IMPORT = "import { registerPabxTools } from './pabx.js';"
OLD_BLOCK = re.compile(
    r"\n  if \(pabxConfigurado\(\)\) \{.*?\n  \}[ \t]*(?=\n\s*return mcp;)",
    re.DOTALL,
)
EXCLUDES = (
    "pabx-secrets/\n**/id_ed25519\n**/known_hosts\n"
    "*.token\n*.token.*\n"
)


def patch_server(source: str) -> str:
    s = source
    if OLD_IMPORT in s:
        s = s.replace(OLD_IMPORT, NEW_IMPORT, 1)
    elif NEW_IMPORT not in s:
        s = NEW_IMPORT + "\n" + s
    if "pabxConfigurado()" in s:
        s, n = OLD_BLOCK.subn("\n  registerPabxTools(mcp);", s, count=1)
        if n != 1:
            raise RuntimeError("Registro PABX SSH inesperado: nao alterar server.js")
    elif "  registerPabxTools(mcp);" not in s:
        marker = "  return mcp;"
        if s.count(marker) != 1:
            raise RuntimeError("Ponto de registro de ferramentas nao e unico")
        s = s.replace(marker, "  registerPabxTools(mcp);\n" + marker, 1)
    if s.count(NEW_IMPORT) != 1 or s.count("registerPabxTools(mcp);") != 1:
        raise RuntimeError("Registro PABX duplicado ou importacao incorreta")
    if "consultarPabx" in s or "pabxConfigurado" in s:
        raise RuntimeError("Restou referencia ao modulo SSH antigo")
    return s


def patch_docker(source: str) -> str:
    if "COPY . ." in source or "pabx.js" in source:
        return source
    old = "COPY server.js auth.js ./"
    if source.count(old) != 1:
        raise RuntimeError("Dockerfile fora dos layouts conhecidos")
    s = source.replace(old, "COPY server.js auth.js pabx.js ./")
    old_chmod = "chmod 644 /app/server.js /app/auth.js /app/package.json"
    if old_chmod in s:
        s = s.replace(old_chmod, "chmod 644 /app/server.js /app/auth.js /app/pabx.js /app/package.json")
    return s


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--prod", default="/opt/jrconect-mcp", help="Diretorio MCP ja em producao")
    parser.add_argument("--apply", action="store_true", help="Cria backups e aplica arquivos sem deploy")
    args = parser.parse_args()

    prod = Path(args.prod).resolve()
    src = Path(__file__).resolve().parents[1] / "mcp"
    files = ["server.js", "Dockerfile"]
    if not all((prod / name).is_file() for name in files):
        raise SystemExit("MCP de producao nao encontrado; abortando.")
    if not (src / "pabx.js").is_file() or not (src / "docker-compose.pabx-rest.yml").is_file():
        raise SystemExit("Fonte REST incompleta; abortando.")

    updates = {
        "server.js": patch_server((prod / "server.js").read_text()),
        "Dockerfile": patch_docker((prod / "Dockerfile").read_text()),
        "pabx.js": (src / "pabx.js").read_text(),
        "docker-compose.pabx-rest.yml": (src / "docker-compose.pabx-rest.yml").read_text(),
    }
    dockerignore = prod / ".dockerignore"
    old_ignore = dockerignore.read_text() if dockerignore.exists() else ""
    additions = [line for line in EXCLUDES.splitlines() if line not in old_ignore.splitlines()]
    updates[".dockerignore"] = old_ignore.rstrip() + "\n" + "\n".join(additions) + "\n"

    override = prod / "docker-compose.pabx-rest.yml"
    if override.exists() and override.read_text() != updates["docker-compose.pabx-rest.yml"]:
        raise SystemExit("Override REST preexistente difere da fonte: revise antes de continuar.")

    with tempfile.TemporaryDirectory() as t:
        for name in ("server.js", "pabx.js"):
            candidate = Path(t) / name
            candidate.write_text(updates[name])
            subprocess.run(["node", "--check", str(candidate)], check=True)

    print("Validacao Node OK. Alteracoes planejadas:")
    for name, new in updates.items():
        target = prod / name
        old = target.read_text() if target.is_file() else ""
        if old == new:
            continue
        if name in ("server.js", "Dockerfile"):
            print("".join(difflib.unified_diff(old.splitlines(True), new.splitlines(True), fromfile=name, tofile=name + ".novo")))
        else:
            print(" -", name, "(novo ou atualizado)")
    if not args.apply:
        print("SIMULACAO: sem escrita. Para aplicar apos revisar: --apply")
        return

    backups = prod / "backups" / ("pabx-rest-" + datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S"))
    backups.mkdir(parents=True, exist_ok=False)
    for name, new in updates.items():
        target = prod / name
        if target.exists():
            shutil.copy2(target, backups / name)
        tmp = target.with_name(target.name + ".pabx-rest.tmp")
        tmp.write_text(new)
        tmp.chmod(0o644)
        tmp.replace(target)
    print("Arquivos preparados. Backup em:", backups)
    print("NENHUM container foi reiniciado. Revisar Docker Compose e segredos antes de implantar.")


if __name__ == "__main__":
    main()
