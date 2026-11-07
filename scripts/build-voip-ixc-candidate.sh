#!/usr/bin/env bash
# Build the IXC VoIP candidate from the live MCP source; never restart production.
set -Eeuo pipefail
umask 077
ROOT=/opt/jrconect-mcp
SERVICE=jrconect-mcp
fail() { echo "BLOQUEADO: $*" >&2; exit 2; }
[[ "$(id -u)" == 0 ]] || fail "execute como root"
command -v docker >/dev/null || fail "Docker indisponivel"
command -v node >/dev/null || fail "Node indisponivel"
cd "$ROOT" || fail "MCP nao localizado"
for f in Dockerfile package.json package-lock.json server.js \
 integrations/ixc-voip-tools.js integrations/ixc-webservice-tools.js \
 integrations/ixc-webservice-client.js integrations/ixc-webservice-auth.js \
 docker-compose.yml docker-compose.opa.yml docker-compose.ixc.yml docker-compose.pabx-central.yml; do
  [[ -f "$f" && ! -L "$f" ]] || fail "Arquivo ausente ou link: $f"
done
grep -Fq 'COPY integrations/ /app/integrations/' Dockerfile || fail "Dockerfile nao inclui integrations"
grep -Fq 'registerIxcVoipTools(mcp, client)' integrations/ixc-webservice-tools.js || fail "Registro VoIP ausente"
grep -Fq "import { registerIxcVoipTools } from './ixc-voip-tools.js';" integrations/ixc-webservice-tools.js || fail "Import VoIP ausente"
grep -Fq 'ixcWebserviceAuthorized(payload)' server.js || fail "Escopo IXC nao identificado"
grep -Fq 'IXC_WS_ALLOWED_SUB' integrations/ixc-webservice-auth.js || fail "Allowlist IXC ausente"
grep -Fq 'ixc-mcp-admin' integrations/ixc-webservice-auth.js || fail "Papel IXC ausente"
node --check integrations/ixc-voip-tools.js
node --check integrations/ixc-webservice-tools.js
COMPOSE=(-f docker-compose.yml -f docker-compose.opa.yml -f docker-compose.ixc.yml -f docker-compose.pabx-central.yml)
docker compose "${COMPOSE[@]}" config --quiet || fail "Compose invalido"
old_image="$(docker inspect -f '{{.Image}}' "$SERVICE")" || fail "Container MCP ausente"
[[ "$(docker inspect -f '{{.State.Running}}' "$SERVICE")" == true ]] || fail "MCP nao esta em execucao"
echo "Imagem MCP em execucao: $old_image"
echo "Fonte: /opt/jrconect-mcp; Compose base + Opa + IXC + PABX central"
echo "Nenhum arquivo do MCP, secret ou container sera modificado neste passo."
case "${1:---plan}" in
--plan)
  echo "PLAN OK: pronto para construir uma imagem candidata."
  ;;
--build)
  [[ "${VOIP_IXC_APPROVE_BUILD:-}" == YES ]] || fail "Use VOIP_IXC_APPROVE_BUILD=YES"
  tag="jrconect-mcp:voip-ixc-candidate-$(date -u +%Y%m%dT%H%M%SZ)"
  docker build --pull=false -f Dockerfile -t "$tag" . || fail "Build da candidata falhou"
  for path in /app/server.js /app/integrations/ixc-voip-tools.js /app/integrations/ixc-webservice-tools.js; do
    docker run --rm --network none --entrypoint node "$tag" --check "$path" || fail "Erro de sintaxe na imagem: $path"
  done
  docker run --rm --network none --entrypoint node "$tag" --input-type=module -e '
    import {registerIxcWebserviceTools} from "/app/integrations/ixc-webservice-tools.js";
    import {registerIxcVoipTools} from "/app/integrations/ixc-voip-tools.js";
    if (typeof registerIxcWebserviceTools !== "function") process.exit(8);
    const names = [];
    registerIxcVoipTools({registerTool(name) { names.push(name); }}, {});
    if (names.length !== 2 || !names.includes("ixc_voip_consultar_contrato") || !names.includes("ixc_voip_listar_contratos_produto74") || new Set(names).size !== 2) process.exit(9);
    console.log("SMOKE OK: duas ferramentas VoIP registradas sem credenciais ou chamadas externas.");
  ' || fail "Smoke test da imagem falhou"
  [[ "$(docker inspect -f '{{.Image}}' "$SERVICE")" == "$old_image" ]] || fail "Imagem ativa mudou durante o build"
  echo "CANDIDATA VALIDADA: $tag"
  echo "IMAGEM ATUAL PRESERVADA: $old_image"
  echo "Nenhum restart, replace ou deploy foi executado."
  ;;
*) fail "Modo esperado: --plan ou --build" ;;
esac
