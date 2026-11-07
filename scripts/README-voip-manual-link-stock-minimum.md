# PR #98 — Vínculos manuais e estoque mínimo VoiceCopy

## Objetivo

Concluir a primeira fase do registro manual de vínculos DID ↔ contrato IXC / registro do produto 74. A conciliação por nome continua **apenas sugestão**. A decisão é humana.

## Operação

- Na seção **Vínculos manuais e estoque mínimo**, inserir o número com DDD, o ID do contrato IXC e o ID da linha do produto 74 no IXC.
- Confirmar no checkbox que os três identificadores foram conferidos no IXC e na VoiceCopy; só então enviar.
- A gravação usa `flock` do estoque existente e escrita atômica no `/var/lib/jrconect-voip/stock.json`, mantendo as linhas importadas, com log de operador e UTC.
- Um número não pode receber dois vínculos; o mesmo contrato e o mesmo registro do produto 74 não podem ser usados em mais de um DID.
- Números `a_conferir` NÃO podem ser vinculados diretamente. Para disponibilizá-los, o operador precisa confirmar explicitamente que a VoiceCopy indicou disponibilidade; só então o estoque muda de `a_conferir` para `disponivel`.
- Um número `disponivel` confirmado passa para `ocupado` ao receber vínculo manual.
- Números que já estão `ocupado` na importação podem receber contrato após verificação operacional.
- Limite mínimo configurável de **1 a 100**, padrão **5**. O aviso aparece quando `disponivel <= minimo`: apenas aviso no painel, não solicita novas linhas automaticamente.

## Limites e proteção

**Não** existe autenticação servidor-servidor do ACS ao IXC nesta PR. A confirmação do operador é uma declaração manual baseada em conferência do IXC e VoiceCopy. O sistema **não** valida o vínculo com API no momento da gravação.

Não há liberação automática de números ocupados/vinculados com base no status `I`, nem desvinculação ou reatribuição automática. Isso requer histórico da atribuição real e validação da baixa no fornecedor. `A` e `I` permanecem códigos brutos.

Não alteramos outros serviços, Docker, MCP OAuth ou provedores; não escrevemos no IXC. O botão de alerta de novo contrato também ainda não é automático: exigirá monitoramento autenticado e baseline.

## Homologação e implantação

1. Exigir CI verde e revisar o diff da PR antes do merge.
2. **Não copiar automaticamente um checkout inteiro de GitHub** para produção.
3. Backup completo de `/var/www/gacs/voip-central.php`, `/var/www/gacs/lib/voip-stock.php` e `/var/lib/jrconect-voip/stock.json` (conteúdo privado, fora do repositório).
4. Conferir apenas três arquivos a aplicar: `voip-central.php`, `lib/voip-stock.php` (modificado) e testes não são implantados; não são necessários outros arquivos. O `voip-operational.php` permanece existente da PR #97.
5. Validar `php -l` antes e depois, e conferir permissões adequadas do estoque para o usuário web. Não reiniciar MCP.
6. Testar em homologação: vincular um dos 8 casos com correspondência única depois de **confirmar no IXC e VoiceCopy**, tentar duplicar o contrato e verificar rejeição, conferir auditoria e contagem dos números. Testar limite mínimo.
7. **Verificar resultado e auditoria antes de qualquer vinculação massiva.** Em rollback, restaurar PHP e stock.json do mesmo ponto de backup, caso já tenha havido alterações operacionais — reconciliar as alterações posteriores antes de restaurar para não perder vínculos legítimos.

Dados pessoais e IDs de estoque ficam somente em armazenamento privado no ACS; os testes utilizam nomes fictícios.
