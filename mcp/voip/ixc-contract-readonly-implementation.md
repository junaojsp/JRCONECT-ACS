# Central VoIP: consulta individual IXC por contrato - PR 1

## O que esta incluido
- `ixc_voip_consultar_contrato`: nova ferramenta **somente leitura**, registrada pelo ponto de entrada existente `registerIxcWebserviceTools`.
- Consulta de `cliente_contrato` por ID e de `vd_contratos_produtos` por `id_contrato` e `id_produto=74`.
- Retorna apenas IDs de contrato/registro de produto, status IXC **bruto** e verificacao de presenca do produto 74. **Nao retorna nomes, documentos, telefones ou dados financeiros**.
- Paginacao ate 200 registros com limite 20/pagina, valida totais, rejeita IDs duplicados e resposta incompleta.
- Nenhuma escrita no IXC, nenhum vinculo gravado no estoque, nenhuma ativacao de monitoramento.

## Dependencias de homologacao
- O runtime de producao em `/opt/jrconect-mcp` difere do `mcp/server.js` versionado. **Nao executar deploy integral do MCP** nem substituir `server.js`, `auth.js`, manifests ou arquivos nao versionados.
- No repositório, `mcp/server.js` nao chama `registerIxcWebserviceTools`. No runtime ativo, validar se ele ja usa esse ponto de entrada e qual e a implementacao local antes de publicar apenas os novos arquivos.
- Verificar que o acesso as ferramentas IXC segue condicionado a `ixcWebserviceAuthorized`, papel `ixc-mcp-admin`, `IXC_WS_ALLOWED_SUB` e os mesmos controles existentes. **Nao registrar a ferramenta em escopo global se este controle nao estiver aplicado**.
- Validar com um contrato de teste a resposta real de `cliente_contrato` e `vd_contratos_produtos`, incluindo formatos de `registros`, `total`, `status` e o segundo filtro `grid_param`.
- Em caso de resposta nao suportada, o modulo falha fechado. Nao classificar contrato como ativo com base na letra do status sem mapeamento oficial.

## Testes
```bash
cd mcp
npm install --ignore-scripts --no-audit --no-fund
node --test integrations/ixc-voip-tools.test.js
node --check integrations/ixc-webservice-tools.js
```

## Proxima etapa, SOMENTE apos validar o runtime
- Implementar endpoint autenticado do painel para consultar contrato e produto 74, sem expor credenciais IXC ao navegador.
- Criar persistencia de vinculacao manual atômica para DID + registro do produto contratado, com auditoria e protecao contra numero duplicado.
- Validar titular em sessao autorizada; o modulo atual minimiza dados deliberadamente.
- Habilitar reconciliacao e alertas somente apos status e paginação serem comprovados.
- Numeros `a_conferir` continuam bloqueados; VoIP nao libera nem atribui numeros automaticamente.
