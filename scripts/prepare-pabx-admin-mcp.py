#!/usr/bin/env python3
"""Insere endpoint /mcp-admin no servidor MCP JA EXISTENTE, sem deploy.

Nao substitui o server.js real pelo antigo do GitHub. Simula por padrao;
--apply cria backup e escreve arquivos, sem reconstruir/reiniciar container.
"""
import argparse
import difflib
from datetime import datetime, timezone
from pathlib import Path
import shutil
import subprocess
import tempfile

IMPORT = "import { registerPabxAdminTools, pabxAdminAuthorized } from './pabx-admin.js';"
HANDLER = """const pabxAdminTransportHandler = toNodeHandler(createMcpHandler(() => {
  const adminMcp = new McpServer({ name: 'jrconect-pabx-admin', version: '1.0.0' });
  registerPabxAdminTools(adminMcp);
  return adminMcp;
}));
"""
AUTH_ROUTE = """  // Endpoint independente do MCP de consulta compartilhado com o Opa.
  if (req.url?.split('?')[0] === '/mcp-admin') {
    if (process.env.PABX_ADMIN_MCP_ENABLED !== 'true') {
      res.writeHead(404); res.end('Not Found'); return;
    }
    const matchAdmin = /^Bearer ([^\\s]+)$/i.exec(req.headers.authorization || '');
    if (!matchAdmin) {
      res.writeHead(401, {
        'Content-Type': 'application/json',
        'WWW-Authenticate': 'Bearer resource_metadata="https://mcp.jrconect.com/.well-known/oauth-protected-resource/pabx-admin"',
      });
      res.end(JSON.stringify({ error: 'unauthorized' }));
      return;
    }
    try {
      const payload = await validarToken(matchAdmin[1]);
      if (!pabxAdminAuthorized(payload)) {
        res.writeHead(403, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'access_denied' }));
        return;
      }
      req.mcpUser = { sub: payload.sub, client: payload.azp };
      // A biblioteca MCP trata /mcp; somente após autorização reescrevemos a rota.
      req.url = req.url.replace(/^\\/mcp-admin(?=\\?|$)/, '/mcp');
      return pabxAdminTransportHandler(req, res);
    } catch {
      res.writeHead(401, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'invalid_token' }));
      return;
    }
  }
"""
METADATA = """  if (req.method === 'GET' &&
      req.url?.split('?')[0] === '/.well-known/oauth-protected-resource/pabx-admin') {
    res.writeHead(200, {
      'Content-Type': 'application/json',
      'Cache-Control': 'public, max-age=300',
    });
    res.end(JSON.stringify({
      resource: 'https://mcp.jrconect.com/mcp-admin',
      authorization_servers: ['https://auth.jrconect.com/realms/jrconect'],
    }));
    return;
  }
"""


def patch_server(source: str) -> str:
    if "pabxAdminTransportHandler" in source:
        raise RuntimeError("Gateway admin ja presente; revisar manualmente")
    import_anchor = "import { registerPabxTools } from './pabx.js';"
    if source.count(import_anchor) != 1:
        raise RuntimeError("Import PABX nao encontrado ou duplicado")
    s = source.replace(import_anchor, import_anchor + "\n" + IMPORT, 1)
    handler_anchor = "const mcpHandler = toNodeHandler(handler);"
    if s.count(handler_anchor) != 1:
        raise RuntimeError("Estrutura de handler diferente da esperada")
    s = s.replace(handler_anchor, handler_anchor + "\n\n" + HANDLER, 1)
    route_anchor = "  if (req.url?.split('?')[0] !== '/mcp') {"
    if s.count(route_anchor) != 1:
        raise RuntimeError("Roteamento /mcp diferente da versao aprovada")
    s = s.replace(route_anchor, AUTH_ROUTE + route_anchor, 1)
    server_anchor = "createServer(async (req, res) => {"
    if s.count(server_anchor) != 1:
        raise RuntimeError("createServer nao foi encontrado uma unica vez")
    s = s.replace(server_anchor, server_anchor + "\n" + METADATA, 1)
    return s


def patch_docker(source: str) -> str:
    if "/app/pabx-admin.js" in source:
        raise RuntimeError("Dockerfile ja ajustado; revisar")
    if "COPY . ." in source:
        return source.replace("COPY . .", "COPY . .\nRUN chmod 644 /app/pabx-admin.js && node --check /app/pabx-admin.js", 1)
    anchor = "COPY server.js auth.js pabx.js ./"
    if source.count(anchor) != 1:
        raise RuntimeError("Dockerfile fora do layout conhecido")
    return source.replace(anchor, "COPY server.js auth.js pabx.js pabx-admin.js ./\nRUN chmod 644 /app/pabx-admin.js && node --check /app/pabx-admin.js", 1)


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--prod", default="/opt/jrconect-mcp")
    p.add_argument("--apply", action="store_true")
    args = p.parse_args()
    prod = Path(args.prod).resolve()
    src = Path(__file__).resolve().parents[1] / "mcp"
    required = ["server.js", "Dockerfile"]
    if not all((prod / item).is_file() for item in required):
        raise SystemExit("Servidor MCP de producao ausente: ABORTADO")
    for item in ["pabx-admin.js", "docker-compose.pabx-admin.yml"]:
        if not (src / item).is_file():
            raise SystemExit("Arquivo da nova branch ausente: " + item)
    if (prod / "docker-compose.pabx-admin.yml").exists():
        raise SystemExit("Override administrativo existente: revisar antes de sobrescrever")
    if (prod / "pabx-admin.js").exists():
        raise SystemExit("Modulo admin existente: revisar antes de sobrescrever")

    changed = {
        "server.js": patch_server((prod / "server.js").read_text()),
        "Dockerfile": patch_docker((prod / "Dockerfile").read_text()),
        "pabx-admin.js": (src / "pabx-admin.js").read_text(),
        "docker-compose.pabx-admin.yml": (src / "docker-compose.pabx-admin.yml").read_text(),
    }
    with tempfile.TemporaryDirectory() as t:
        for item in ("server.js", "pabx-admin.js"):
            temp = Path(t) / item
            temp.write_text(changed[item])
            subprocess.run(["node", "--check", str(temp)], check=True)
    print("SINTAXE_NODE_OK")
    for item in required:
        original = (prod / item).read_text().splitlines(True)
        new = changed[item].splitlines(True)
        print("".join(difflib.unified_diff(original, new, fromfile=item, tofile=item + ".novo")))
    print("Novos arquivos: pabx-admin.js e docker-compose.pabx-admin.yml")
    if not args.apply:
        print("SIMULACAO=SIM / NENHUMA ALTERACAO FEITA")
        return

    backup = prod / "backups" / ("pabx-admin-" + datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S"))
    backup.mkdir(parents=True, exist_ok=False)
    for item in required:
        shutil.copy2(prod / item, backup / item)
    for item, new in changed.items():
        dest = prod / item
        tmp = dest.with_name(dest.name + ".admin-next")
        tmp.write_text(new)
        tmp.chmod(0o644)
        tmp.replace(dest)
    print("ARQUIVOS_PREPARADOS=SIM")
    print("BACKUP=" + str(backup))
    print("NAO RECONSTRUIDO NEM REINICIADO O CONTAINER")


if __name__ == "__main__":
    main()
