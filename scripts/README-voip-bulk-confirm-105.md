# PR #105 — Confirmação de números novos em lote

## Contexto
O operador confirmou que os 17 números `a_conferir` são DIDs **novos**, entregues pela VoiceCopy para novas contratações; os 13 DIDs históricos estão ocupados e vinculados no painel. A PR não altera esses vínculos.

## Comportamento
- Em **Central VoIP → Vínculos manuais e estoque mínimo**, surge `Confirmar em lote os DIDs novos entregues pela VoiceCopy`.
- Exibe somente DIDs com `status=a_conferir`, sem titular histórico, sem vínculo de contrato/produto, e sem reserva.
- Seleção individual ou botão `Selecionar todos`; nada pré-selecionado.
- Exige confirmação de que foram entregues livres pela VoiceCopy.
- O backend revalida **todos** os selecionados sob o mesmo `stock.lock`, com revisão otimista de estoque; se um único DID for inadequado ou o estoque tiver sido alterado, toda a transação é rejeitada.
- Atualiza `a_conferir → disponivel` e audita cada DID, com mesmo ID de lote, data UTC e operador. Uma gravação atômica no `stock.json`.
- Não escreve no IXC, não executa a automação de contrato, não cria cron nem altera outros serviços.

## Verificação após deploy
1. Fazer backup dos arquivos PHP e de `/var/lib/jrconect-voip/stock.json` com permissões preservadas.
2. Instalar apenas `lib/voip-stock.php` e `voip-central.php` após passar `php -l`.
3. Na Central VoIP, conferir que os 13 DIDs históricos permanecem `ocupado` e que os 17 novos aparecem no formulário em lote.
4. Selecionar os 17 e confirmar **somente se** a entrega como livres pela VoiceCopy já tiver sido verificada.
5. Confirmar contadores `ocupado=13`, `disponivel=17`, `a_conferir=0`, `reservado=0` (se nenhuma outra operação ocorrer).
6. Verificar auditoria e baixar novamente a tabela de estoque. Não ativar ainda escrita IXC: continua dependente de integração de PUT validada.

## Testes
`php tests/voip-stock-batch-available.test.php` cobre atomicidade, revisão obsoleta, DIDs históricos, confirmação repetida, auditoria e reservas.
