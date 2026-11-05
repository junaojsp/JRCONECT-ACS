# Financeiro MCP isolado: integracao na versao ATIVA do ACS

O checkout GitHub `mcp/server.js` nao representa o `server.js` ativo em
`/opt/jrconect-mcp` (Wiki, Opa, PABX e outros registros estao na versao ativa).
**Nao fazer deploy de `mcp/server.js` do GitHub.**

Preparacao:
- Importar `financeMcpAuthorized` de `./integrations/finance-auth.js`.
- Importar `registerFinanceSuite` de `./integrations/finance-suite.js`.
- Construir um novo `financeTransportHandler` com
  `toNodeHandler(createMcpHandler(() => { const mcp = new McpServer(...);
   registerFinanceSuite(mcp, {read: {tokenFile:'/run/secrets/finance-token'},
   admin: {tokenFile:'/run/secrets/finance-admin-token'}});
   return mcp; }))`.
- Antes do ramo existente que trata `/mcp` e `/mcp-admin`, adicionar o
  ramo `/mcp-finance` para POST/GET conforme transporte atual:
  extrair `Bearer`, chamar `validarToken(jwt)` existente, autorizar com
  `financeMcpAuthorized(payload)`, reescrever URL para `/mcp` e encaminhar
  ao `financeTransportHandler`. JWT inexistente/invalido: 401.
  JWT valido nao autorizado: 403. Nunca comparar contra token do Opa.
- Evitar registro das ferramentas em `/mcp` ou `/mcp-admin` do PABX.

Variaveis:
```
FINANCE_MCP_ENABLED=false
FINANCE_MCP_ALLOWED_SUB=<UUID real do administrador Keycloak>
```
A role `finance-mcp-admin` deve existir no realm Keycloak e ser atribuida
ao usuario. A ativacao de `FINANCE_MCP_ENABLED=true` ocorre apenas depois
da verificacao de allowlist e tokens. `PABX_ADMIN_MCP_ENABLED` permanece
desativado.

Tokens de leitura/escrita ja foram criados em
`/opt/jrconect-mcp/secrets/finance/{read-token,admin-token}`.
Os clientes Node esperam:
`/run/secrets/finance-token` e `/run/secrets/finance-admin-token`.

UID/GID do Node: 1000/1000. No host, os arquivos agora estao root:esdras,
0640, e a pasta finance foi criada 0700 root. **Mesmo que o GID esdras seja
1000, a pasta 0700 impede travessia**. Preparar bind mounts individuais dos
arquivos com root:1000 0640 e pasta finance root:1000 0750, e verificar
os mounts em container **de staging**, nunca no MCP em producao primeiro.
Nao colocar os segredos no contexto Docker.

Testes antes de ativar:
1. `node --check` em scripts registrados.
2. Docker compose config --quiet com o compose ATIVO.
3. Health de MCP e painel ACS antes/depois.
4. JWT ausente => 401; token Opa => 401/403; JWT valido nao autorizado => 403.
5. JWT autorizado com role + UUID => tools/list retorna 12 ferramentas.
6. Financeiro GET /summary => 200; POST admin com `{}` => 422,
   sem alterar dados.
7. Backup das configuracoes vivas, imagem antiga e rollback definido.

Observacao critica: `finance-admin-tools.js` passa uma frase de confirmacao
estatica. Isso nao prova confirmacao humana. Para operacoes de pagamento,
exclusao ou alteracao, a aprovacao deve ser realizada pelo operador na conversa
ANTES de invocar a ferramenta. Revisar modelo de auditoria antes do acesso FULL.

NENHUMA mudanca foi aplicada ao ACS por este branch.
