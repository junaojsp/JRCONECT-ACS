# JR Conect — MCP administrativo PABX (instalacao controlada)

## Arquitetura

A producao MCP ja tem 6 ferramentas PABX de consulta. Esta branch adiciona **/mcp-admin** no MESMO container para revisao administrativa de arquivos, sem disponibilizar o admin_token ao Opa Suite ou ao MCP publico /mcp.

Requisitos cumulativos para /mcp-admin:
1. JWT Keycloak valido pelo validador atual, azp=chatgpt-mcp.
2. Role de realm **jrconect-pabx-admin** no access token.
3. O subject **sub** na variavel PABX_ADMIN_ALLOWED_SUB.
4. PABX_ADMIN_MCP_ENABLED=true.
5. Arquivo secreto admin montado apenas no container, read-only.

As ferramentas sao: **pabx_ixc_arquivo_previa** (codigo mascarado) e **pabx_ixc_edicao_preparar** (proposta com hashes, sem alterar producao). Nao existe comando de aplicar ou rollback pelo MCP: a etapa exige operador root na VM Issabel.

## Primeiro: PABX

Instalar e testar a branch **feature/ixc-admin-review-gateway** de JRC-PABX seguindo api/ADMIN-IXC-REVISOES.md. Nao prosseguir se a previa administrativa falhar.

## Keycloak

Criar role de realm jrconect-pabx-admin e atribuir SOMENTE ao administrador autorizado. Confirmar que essa role aparece no access token do cliente chatgpt-mcp. Copiar o UUID exato de **sub** do usuario para a allowlist. Nao usar username, grupo Opa ou acesso administrativo global em substituicao.

Sem role, sub e habilitacao explicita o gateway deve permanecer inacessivel.

## Baixar sem mexer em producao

No ACS 138.204.112.25:

~~~sh
cd /opt/jrconect-acs-git
git fetch origin feat/pabx-admin-gateway
git switch -c feat/pabx-admin-gateway --track origin/feat/pabx-admin-gateway
python3 scripts/prepare-pabx-admin-mcp.py
~~~

Se a branch existir, usar git switch nela e git pull --ff-only. O ultimo comando **simula**, compara o server.js REAL de producao e mostra diff; nao substitui Wiki, Opa, OAuth ou ConnectionRegistry.

Depois de revisar:

~~~sh
python3 scripts/prepare-pabx-admin-mcp.py --apply
cd /opt/jrconect-mcp
node --check server.js
node --check pabx-admin.js
~~~

Cria backup. Nao reinicia container.

## Segredo administrativo no ACS

Nunca colar token no chat ou no GitHub. Preparar arquivo separado de read.token:

~~~sh
install -d -o root -g root -m 0700 /var/lib/jrc-pabx-mcp
install -o 1000 -g 1000 -m 0600 /dev/null /var/lib/jrc-pabx-mcp/admin.token
read -r -s -p "Cole o admin_token PABX: " PABX_ADMIN
echo
if [ "$(printf %s "$PABX_ADMIN" | wc -c)" -eq 64 ]; then
  printf '%s' "$PABX_ADMIN" > /var/lib/jrc-pabx-mcp/admin.token
  echo "Token administrativo armazenado."
else
  echo "TOKEN_INVALIDO: repita a leitura antes de continuar."
fi
unset PABX_ADMIN
chmod 600 /var/lib/jrc-pabx-mcp/admin.token
~~~

Se o comprimento nao for 64, nao continue e apague o arquivo invalido antes de repetir. O token de leitura anterior segue separado.

## Compose e teste isolado

~~~sh
cd /opt/jrconect-mcp
export PABX_READ_TOKEN_PATH=/var/lib/jrc-pabx-mcp/read.token
export PABX_ADMIN_TOKEN_PATH=/var/lib/jrc-pabx-mcp/admin.token
export PABX_ADMIN_ALLOWED_SUB="UUID_DO_SUB_KEYCLOAK"

docker compose -f docker-compose.yml -f docker-compose.pabx-rest.yml -f docker-compose.pabx-admin.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.pabx-rest.yml -f docker-compose.pabx-admin.yml build jrconect-mcp
~~~

Testar a ferramenta admin sem reiniciar o MCP, com token montado:

~~~sh
docker compose -f docker-compose.yml -f docker-compose.pabx-rest.yml -f docker-compose.pabx-admin.yml run --rm --no-deps --entrypoint node jrconect-mcp --input-type=module -e '
import {registerPabxAdminTools} from "./pabx-admin.js";
const tools = new Map();
if (!registerPabxAdminTools({registerTool:(n,c,h)=>tools.set(n,h)})) throw Error("ADMIN_MODULE_DISABLED");
const response = await tools.get("pabx_ixc_arquivo_previa")({area:"webservice_ixcsoft",arquivo:"WebserviceClient.php"});
if (response.isError) throw Error("PREVIEW_FAILED");
const p = JSON.parse(response.content[0].text);
if (p.conteudo_redigido !== true || !Array.isArray(p.linhas)) throw Error("PREVIEW_INVALID");
console.log("PABX_ADMIN_PREVIEW_OK linhas=",p.linhas.length);
'
~~~

Nao exibe codigo nem credenciais. Testar negação (401 sem token JWT, 403 JWT sem role/subject) ao homologar. Validar que Opa so acessa /mcp.

## Publicacao, apenas em janela aprovada

~~~sh
docker compose -f docker-compose.yml -f docker-compose.pabx-rest.yml -f docker-compose.pabx-admin.yml up -d --no-deps --no-build jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.pabx-rest.yml -f docker-compose.pabx-admin.yml ps
curl -fsS http://127.0.0.1:3100/health
docker logs --tail 50 jrconect-mcp
~~~

Recria somente o MCP (breve desconexao), nunca o Issabel.

## Reverse proxy e ChatGPT

Inspecionar a configuracao real do proxy do ACS antes de alterar. Encaminhar /mcp-admin e /.well-known/oauth-protected-resource/pabx-admin ao MESMO container jrconect-mcp:3100; manter /mcp existente. A porta 3100 permanece restrita ao localhost.

O recurso administrativo sera https://mcp.jrconect.com/mcp-admin. Registrar como conexao MCP administrativa SEPARADA para o administrador, autenticada por Keycloak; nao usar credenciais do PABX na interface ChatGPT. O Opa continua no MCP compartilhado.

## Rollback

Restaurar server.js e Dockerfile do backup do script, remover override admin da invocacao Compose, reconstruir apenas jrconect-mcp e validar /health e ferramentas da Wiki/Opa. Nenhum arquivo IXCsoft e alterado pelo gateway de revisao.

**Situacao:** codigo preparado no GitHub; ainda precisa testes no Rocky/Node e liberacao de role. Nao esta em producao nesta entrega.
