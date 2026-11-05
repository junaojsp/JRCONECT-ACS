# IXC Provedor — integracao no MCP central

**Status: somente codigo preparado; NAO conectado nem ativado em producao.**

Base da JR Conect: https://cda.jrconect.com
API oficial: https://api-provedor.ixcsoft.com.br/
Documentacao de geracao de token: https://wiki-erp.ixcsoft.com.br/documentacao/guias-tutoriais/api/como-gerar-um-token-para-integracoes-api

A API Webservice `/webservice/v1` nao e o MCP nativo do IXC `/services/mcp`; a primeira sera usada para conexao direta do JR Conect MCPv2 com o IXC.

## Autorizacao e protecao

- Criar usuario API exclusivo no IXC, marcado **Permite acesso a API**, com grupo de permissoes apropriado e restricao de IP ACS, se disponivel.
- Guardar credencial em arquivo do host, acessivel em `/run/secrets/ixc-webservice-token` no container como somente leitura; nao registrar token no repositorio nem no chat.
- Definir `IXC_WS_USERNAME` com usuario de Webservice documentado para a instalacao, `IXC_WS_BASE_URL=https://cda.jrconect.com`, `IXC_WS_MCP_ENABLED=true` e `IXC_WS_ALLOWED_SUB` para UUID OAuth autorizado.
- Conceder realm role `ixc-mcp-admin` no Keycloak ao usuario OAuth.
- Expor `registerIxcWebserviceTools` **somente no handler JWT autorizador**; NUNCA no handler compartilhado do cliente Opa bearer.
- Nao substituir o `server.js` live pelo arquivo `mcp/server.js` do repositorio, nem substituir o Dockerfile sem revisar o estado da producao.
- Teste inicial sem escrever: apenas uma chamada GET `cliente` com `ixcsoft: listar`, pagina 1, limite 1. Evitar retornar dados pessoais no diagnostico.
- Operacoes administrativas de escrita requerem cadastro dos payloads por recurso, confirmacao especifica, auditoria e validacao pre-escrita. Nao foi criado proxy generico de POST, PUT ou DELETE para proteger os dados dos assinantes.
- Nenhum token existente de OAuth do IXC ou Opa deve ser sobrescrito.

## Diferenca entre leitura e administracao

A credencial IXC pode possuir permissao administrativa conforme grupo do usuario, mas o MCP **nao executa alteracoes ainda**. Primeiro validar conectividade, depois implementar ferramentas administrativas explicitas para tarefas aprovadas.
