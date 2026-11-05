# Financeiro no MCP admin - guia de seguranca

**Nunca** copiar `mcp/server.js` ou `mcp/docker-compose.yml` deste repositorio
por cima de `/opt/jrconect-mcp`: as versoes de producao possuem PABX, Wiki,
Opa, Keycloak e um endpoint admin OAuth nao presentes no checkout GitHub.

O arquivo `integrations/finance-suite.js` agrupa as 12 ferramentas de consulta
e administracao. Registre-o **apenas** na fabrica do MCP admin ja protegida
por `pabxAdminAuthorized` e servida em `/mcp-admin`. Nao registre no handler
`/mcp` compartilhado com o token do Opa Suite.

No ACS devem existir dois arquivos de segredo independentes, somente leitura:
- `/run/secrets/finance-token` (GET)
- `/run/secrets/finance-admin-token` (POST)

No host, mantenha os arquivos fora da imagem Docker. Para um container `USER node`
UID/GID 1000, os arquivos bind-mounted podem ser root:1000 com modo 0640.
O build context deve ignorar `secrets/` e `runtime/`.

Antes de ativar, exigir:
1. `/health` do MCP existente HTTP 200.
2. API Financeiro GET /summary HTTP 200 com token de leitura.
3. POST /admin/execute com body vazio e token admin HTTP 422, sem alteracao.
4. Snapshot de server.js, compose, .dockerignore e imagem Docker existentes.
5. Checagem de sintaxe JS e configuracao Docker antes de recriar somente jrconect-mcp.
6. Verificar /health, acesso aos segredos pelo UID 1000 e exposicao publica
   /mcp-admin retornando 401 sem token; rollback automatico se falhar.

A ativacao do transporte /mcp-admin tambem disponibiliza as funcoes PABX admin
existentes a usuarios autorizados. Essa decisao deve ser explicita.
