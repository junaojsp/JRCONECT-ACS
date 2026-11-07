# PR #104 — Conciliação do estoque histórico de DIDs

A lista original de 13 DIDs com status ocupado pode conter clientes antigos ou contratos cancelados. A consulta IXC `ixc_voip_listar_contratos_produto74` é um snapshot apenas dos contratos que **ainda possuem produto 74**, não um inventário completo de todos os antigos contratos. Por isso a ausência no snapshot é **somente indicativo**, nunca prova de que o DID está livre.

## Funcionalidade
A seção Conciliação IXC × VoiceCopy passa a analisar **cada DID histórico ocupado**:
- Vínculo existente: protegido, sem permitir liberação pela nova ação.
- Correspondência única com status bruto A: possível vínculo ativo; conferir antes de usar Preparar vínculo.
- Correspondência única com status bruto I: candidato à liberação após conferir VoiceCopy.
- Sem produto 74 encontrado: verificar contrato removido/cancelado, antigo cadastro e VoiceCopy; NÃO assumir livre.
- Mesmo titular em mais de um contrato: ambíguo, não atribuir por nome.
- Status distinto de A/I: classificação indeterminada, revisão obrigatória.

O painel apresenta tabela por DID e resumo com contagens. A análise **não grava** e não muda os 30 números importados.

## Liberação supervisionada
Novo formulário `Confirmar liberação histórica`, acessível só a quem já usa a Central VoIP autenticada. Só lista e aceita DIDs `ocupado` **sem** `contract_id` e `product_record_id` e **sem** reserva. Exige que o operador confirme explicitamente que o número está realmente livre na VoiceCopy. Salva `disponivel` com auditoria (`legacy_free_confirmed_voicecopy`) sob o `stock.lock` existente, sem acessar IXC ou VoiceCopy para escrita. Nunca muda um DID já vinculado. Status A/I ou ausência de contrato não acionam a liberação sozinhos.

## Compatibilidade
- Nenhum novo endpoint IXC, nenhum job ou restart MCP.
- Preserva vínculos 2210/5006 e a reserva automática ainda desativada.
- Mantém a comparação atual e o JSON de 14 contratos; a nova visualização aparece abaixo.
- Não busca clientes sem produto 74 fora do snapshot (escopo insuficiente para atestar cancelamento).
- Mudanças na produção apenas depois de revisão, CI aprovado, merge e deploy explícito.

## Testes
`php tests/voip-historical-review.test.php`: snapshot, ambiguidades, ausência, vínculos protegidos, confirmação supervisionada e auditoria.

## Depois da PR
Com a lista VoiceCopy conferida, o operador confirma as liberações históricas e os DIDs `a_conferir` realmente livres, formando o estoque disponível que será usado pela automação do produto 74. Para liberar DID já vinculado será necessária rotina separada de baixa auditada com verificações de cancelamento.
