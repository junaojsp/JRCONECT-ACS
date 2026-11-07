# Central VoIP JR Conect — Etapa 1: analise isolada

**Estado:** biblioteca de reconciliacao e testes, sem integracao ativa. Nao ha endpoint, menu, cron, tabela criada no banco nem deploy nesta PR.

## Contrato funcional aprovado

1. Suporte N1 adiciona produto IXC **74** ao contrato.
2. Um sincronizador futuro consulta *todos os registros individuais* do produto contratado com paginacao validada e consulta o estado do contrato.
3. Produto 74 em contrato ativo, sem numero associado ao **ID da linha de produto**, gera uma unica pendencia.
4. Equipe escolhe manualmente um DID livre, registra vinculo no estoque independente do ACS e provisiona na VoiceCopy.
5. Sistema monitora contrato/produto; suspensao preserva reserva. Cancelamento ou remocao gera alerta **aguardando baixa**.
6. Apenas apos confirmacao de desativacao pela VoiceCopy e elegibilidade de reutilizacao o numero volta ao estoque.

## Integracoes e limites identificados

- `mcp/integrations/ixc-report-tools.js` expoe apenas **contagem** de `produtos_contrato`.
- `mcp/integrations/ixc-webservice-client.js` ja possui recurso `produtos_contrato`, mas o consumidor atual nao expoe linhas individuais e os codigos reais de status do contrato nao foram validados.
- `findVoipPending` aceita dados **normalizados** e validados externamente. `contractStatus='active'` e um valor INTERNO, nao uma alegacao sobre codigos do IXC.
- A VoiceCopy nao disponibiliza API confirmada: ativacao/baixa permanecem supervisionadas.
- Nao consultar, inferir ou cancelar por ausencia de linhas numa resposta parcial/falha da API. Persistir cursor, geracao e integridade do snapshot antes de reconciliar.

## Especificacao do banco futuro (migração separada)

`voip_numbers`: DID E.164 unico, fornecedor, status livre/reservado/ativo/aguardando baixa, timestamps.

`voip_assignments`: ID da linha de produto IXC, ID do contrato, DID, status, carimbos de data; unicidade para impedir DID duplicado, com historico imutavel das alocacoes anteriores.

`voip_pending_requests`: chave unica por ID de produto contratado + tipo da solicitacao aberta; deduplicacao e encerramento na confirmacao do vinculo.

`voip_audit_log`: ator, acao, valor anterior/novo, data e origem da informacao.

A persistencia deve usar transacao e bloqueio/indice de unicidade para reserva concorrente. Nao modificar tabelas atuais nem escrever no IXC.

## Sequencia de PRs

- **PR 1 (esta):** analise de compatibilidade, regras puras e testes; codigo nao referenciado pelos servicos.
- **PR 2:** leitura paginada IXC com mapeamento/validacao de status e testes com fixtures.
- **PR 3:** migracao isolada e importacao/conciliacao de estoque (sem ativar processos).
- **PR 4:** endpoints autenticados, painel de pendencias e vinculacao manual com audit trail.
- **PR 5:** job de monitoramento opt-in, backoff, protecao contra snapshots incompletos e alertas.

## Seguranca e implantacao

- Feature flag desligada por padrao, deploy somente apos revisao.
- Sem alteracoes em `mcp/server.js`, `mcp/auth.js`, `mcp/integrations/*`, `dashboard.php`, `database.sql` ou servicos existentes.
- Antes de merge: executar `node --test mcp/voip/reconciliation.test.js` (Node 24+) e validar `npm run check` em `mcp`.
- Nenhuma distribuicao ou liberacao automatica de numero; cancelamento gera revisao humana.
- Monitorar o produto contratado e o contrato separadamente. Uma unica pendencia por linha do produto, nao por cliente.
