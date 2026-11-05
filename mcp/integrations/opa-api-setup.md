# Opa Suite -> JR Conect MCPv2

**Estado:** adaptador preparado, **nao implantado**. O Opa Suite hoje e cliente do MCP; esta integracao e a direcao inversa.

Referencia: https://api.opasuite.com.br/ e https://central-opasuite.ixcsoft.com.br/documentacao/api/api-opa%21-suite.html

## Antes de conectar
1. Confirmar URL HTTPS da **instalacao Opa da JR Conect**; nunca utilizar dominio de exemplo.
2. Criar usuario Opa dedicado a API, com grupo de permissoes minimamente necessario e IP de origem ACS permitido. Token **novo** exclusivamente para integracao.
3. Guardar o token no host protegido e montar como /run/secrets/opa-api-token, legivel somente pelo usuario do container. Nunca salvar no Git, no chat ou no JavaScript web.
4. Definir OPA_API_BASE_URL, OPA_API_MCP_ENABLED=true e OPA_API_ALLOWED_SUB (UUID do operador OAuth), conceder role Keycloak opa-mcp-admin.
5. Registrar `registerOpaApiTools` somente em um handler MCP de OAuth JWT validado e allowlist; **nao** registrar no catalogo comum do Opa Suite, nem usar a autenticacao bearer do cliente Opa.
6. Fazer teste somente de leitura (GET canal de comunicacao; GET atendimento por ID), preferencialmente com registro de teste sem dados sensiveis. Revisar minimizacao/redacao antes de produzir dados reais em chat.
7. Manter backup e rollback; nao reiniciar canais de atendimento Opa.

Operacoes administrativas, envio de mensagens e escrita **nao estao habilitados**. Adicionar somente apos conferir requisitos especificos da API, escopos, auditoria e confirmacao por acao.
