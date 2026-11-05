# JRC SHIELD + Financeiro MCP (preparacao)

Este diretorio contem um cliente HTTP de leitura com HTTPS obrigatorio, lista explicita de rotas, timeout, token em arquivo e sem redirecionamentos.

## Estado
**Nao implantar ainda.** O MCP em producao difere do codigo da pasta `mcp/` no GitHub. O servidor em producao usa `pabx.js`, `pabx-admin.js`, `wiki-tools.js` e `connection-registry.js`, ausentes no GitHub. O `docker-compose.yml` do GitHub tambem nao representa todos os mounts ativos. Fazer merge ou docker compose up antes de reconciliar pode derrubar integracoes.

## SHIELD
Confirmar API autenticada de leitura, resposta JSON e mecanismo de credenciais de servico. Nao usar headers `Remote-User` ou `X-JRC-Role` como atalho de autenticacao, nem credenciais admin.

## Financeiro
O app Laravel em `junaojsp/JRC_ADM` tem rotas web com sessao e nao apresenta API MCP de leitura. Implementar primeiro rotas API com autorizacao dedicada, escopo empresarial, auditoria e bloqueio de dados pessoais. Nao expor pagamentos, exclusoes ou mutacoes.

## Proxima etapa
Sincronizar a versao ativa do MCP com o repositorio sem incluir segredos, conferir manifests e dependencias, adicionar as rotas reais e testes de integracao. Somente depois liberar `git pull` e deploy.
