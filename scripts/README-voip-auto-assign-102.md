# Central VoIP — PR #102: motor de atribuição, implantação desativada

## Confirmado
- Contrato de teste 4740 possui o produto 74, registro 571.
- A consulta da API identificou `obs_contrato` e o marcador `#JRC_VOIP_SOLICITAR#`.
- Código atual do MCP só oferece `list`/consulta do IXC. Não há escritor de contrato validado.
- O estoque registrado anteriormente possuía **zero DIDs marcados como disponíveis**. Não usar números `a_conferir` como disponíveis.

## O que a PR implementa
- Validação por **contrato exato** e um único registro do produto 74.
- Exigência de marcador exato e único nas observações; idempotência diante de atribuição já existente.
- Reserva atômica em estoque `disponivel`, com token não previsível e bloqueio pelo `stock.lock`.
- Substituição **somente do marcador** por `JRC_VOIP_CONTRATO=<id>;DID=<numero>`, preservando restante.
- Orquestrador aceita somente funções de leitura e escrita injetadas pelo integrador homologado.
- Readback integral da observação e produto 74 antes de ocupar DID no estoque.
- Quando há timeout/falha/resultado divergente, **DID permanece reservado** para revisão, sem liberação automática.
- Proteção de vínculo existente, colisões de contrato/produto, número `ocupado` e `a_conferir`.
- Testes de ponta a ponta **simulados** sem acessar o IXC real.

## O que a PR deliberadamente NÃO faz
**Não cria writer IXC, não adiciona cron automático, não chama o orquestrador em produção.**
O job de 15 minutos e a configuração do container MCP continuam **somente leitura**.
Nenhuma atualização do contrato 4740 ou de seu marcador ocorrerá após o merge desta PR.
A VoiceCopy não é provisionada por este código. O status ocupado indica somente o vínculo administrativo após confirmação no IXC, não prova de ativação SIP na operadora.

## Pendências de segurança antes de habilitar em produção
1. Conferir quais campos o **PUT `cliente_contrato/{id}`** realmente exige nesta instalação do IXC e se retorna `obs_contrato`; documentação pode exigir todos os demais campos. Evitar sobrescrever silenciosamente alterações concorrentes.
2. Criar credencial **específica de escrita e com escopo mínimo**, não alterar permissões do cliente read-only atual.
3. Construir writer com compare-and-write ou bloqueio concorrente validado; confirmar leitura da versão atual, preserve campos e texto, erro ambíguo mantém reserva.
4. Confirmar **um DID realmente livre na VoiceCopy** e marcá-lo como `disponivel` via fluxo supervisionado antes do teste.
5. Testar isoladamente o contrato 4740 em janela autorizada, mantendo os vínculos 2210/5006 intactos.
6. Só depois integrar o orquestrador ao scheduler, com flag de ativação independente, métricas/alertas, limite por rodada, anti-duplicidade e revisão de reservas pendentes.

### Testes
`php tests/voip-auto-assign.test.php` testa funcionamento com escritor simulado, erro de escrita e bloqueio do estoque.
