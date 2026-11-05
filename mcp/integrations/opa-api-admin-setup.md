# Opa Suite — ferramentas administrativas no MCP central

Este PR disponibiliza **cinco ferramentas administrativas reais** definidas na API publica:
`opa_adicionar_observacao`, `opa_adicionar_etiqueta`, `opa_adicionar_motivo`,
`opa_enviar_mensagem`, `opa_remover_etiqueta`.

As ferramentas **nao foram registradas no servidor em producao**. Opa API precisa de usuario dedicado, token proprio, base URL HTTPS real da instalacao e permissao no grupo exclusivo de API.

### Requisitos de autorizacao
1. Confirmar que `validarToken()` verificou JWT Keycloak, `azp=chatgpt-mcp`, allowlist `sub`, e role `opa-mcp-admin` com `opaApiAuthorized()`.
2. Registrar `registerOpaApiTools` e `registerOpaApiAdminTools` **apenas no handler OAuth permitido** e nunca no bearer compartilhado da Opa Suite. O cliente Opa existente precisa continuar com suas ferramentas antigas.
3. `OPA_API_BASE_URL` deve conter dominio HTTPS real da instalacao; `/run/secrets/opa-api-token` deve vir de um novo token da API, com permissão de arquivo limitada ao usuario da aplicacao. Nao reutilizar token que o Opa usa como cliente do MCP.
4. Testar inicialmente GET de canais, depois listar ferramentas para usuario autorizado e usuario sem permissao; **nao executar POST ou DELETE como teste**.
5. Toda alteracao real exige confirmacao independente do operador e log de auditoria com ator, recurso, acao e resultado. A string `CONFIRMAR_ACAO_OPA` no schema nao comprova por si so a aprovacao humana e nao substitui gate no MCP.
6. Nao afirmar acesso administrativo total a funcionalidades nao documentadas (por exemplo transferir ou encerrar atendimento). Somente endpoints documentados sao expostos.

Referencia: https://api.opasuite.com.br/
