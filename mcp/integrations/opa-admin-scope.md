# Escopo administrativo — Opa Suite e IXC Provedor

Meta: centralizar os dois sistemas no MCP principal **somente para JWT OAuth autenticado com UUID e roles apropriadas**. O bearer usado pelo Opa para acessar o MCP nao pode receber ferramentas administrativas.

## Opa Suite (fonte: documentacao oficial)
- API: `/api/v1`, token Bearer de usuario ativo, vinculado a grupo exclusivo de API; permitir IP do ACS.
- Administracao suportada conforme permissao: clientes/fornecedores e contatos (visualizar/adicionar/editar/excluir); etiquetas (adicionar/remover), observacao e motivo em atendimento; departamentos (visualizar/adicionar/editar); enviar mensagens e templates; etiquetas e notificacoes.
- API de atendimentos lista/consulta, mas a documentacao de permissoes declara 'Visualizar' para 'Atendimentos' — **nao assumir** transferencia ou encerramento pela API ate verificar rota documentada.
- Comecar com `opa_canais` e `opa_atendimento` do PR #62; adicionar operacoes administrativas somente depois de validar URL, paths, formatos e permissao.
- Confirmacao humana explicita antes de cada envio, exclusao, alteracao de dados ou notificacao. Auditoria com ator, acao, identificador, resultado e sem tokens/segredos.
- Exigir role `opa-mcp-admin` e allowlist de UUID verificada na factory/handler OAuth, nunca somente nos parametros das tools.

## IXC Provedor
- Priorizar API oficial Webservice `/webservice/v1` com Basic Auth token do webservice, nao reaproveitar OAuth do IXC ja rejeitado no passado nem confundir com /services/mcp nativo.
- Modulos documentados incluem `cliente`, `cliente_contrato`, `fn_areceber` e outros. Operacoes mutaveis (POST/PUT/DELETE) **somente** com payloads validados por recurso. Alguns PUT exigem **todos os campos** do registro, por isso fazer leitura e validacao pre-escrita, controle de concorrencia, confirmacao e trilha de auditoria.
- Preferir usuario de webservice dedicado com menor escopo possivel e IP autorizado. Credencial guardada em arquivo secreto de leitura somente no ACS.
- Validar consultas GET antes de qualquer escrita, com retorno claro de erro e sem repetir alteracoes financeiras.

## Requisitos para deploy de ambos
1. Endereco real HTTPS de cada sistema e documentacao correspondente a versao instalada.
2. Credenciais de API dedicadas, nunca exibidas no ChatGPT nem registradas no Git.
3. Handler OAuth autenticado separado do cliente Opa bearer; roles `opa-mcp-admin` e `ixc-mcp-admin` mais UUID allowlisted.
4. Revisar os endpoints e o que cada grupo pode executar; testes de integracao apenas leitura/sandbox.
5. Backup de `server.js`, compose, imagem e plano de rollback antes de reiniciar apenas o MCP.
6. `tools/list` por OAuth autorizado e negativo por Opa bearer/usuario nao autorizado.
7. Para escrita, implementar idempotencia onde aplicavel e autorizacao por acao. Nao chamar endpoint administrativo em teste sem autorizacao explicita.

Fontes:
- https://central-opasuite.ixcsoft.com.br/documentacao/api/api-opa%21-suite.html
- https://central-opasuite.ixcsoft.com.br/documentacao/menu-configuracoes/2-permissoes/funcoes-de-cada-permissao.html
- https://api.opasuite.com.br/
- https://api-provedor.ixcsoft.com.br/
