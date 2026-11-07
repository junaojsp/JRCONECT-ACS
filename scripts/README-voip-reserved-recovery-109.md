# PR #109 — Diagnóstico e retomada segura do DID reservado para o contrato 4740

## Situação real comprovada em produção
- DID 1156641257: `reservado` no estoque, vinculado aos IDs contrato `4740` e registro do produto 74 `571`.
- Revisão do estoque 16 após a tentativa original.
- A API do IXC ainda mostra `#JRC_VOIP_SOLICITAR#`; o texto `JRC_VOIP_CONTRATO=4740;DID=1156641257` não consta na observação.
- Backup completo do contrato 4740 foi produzido pelo operador em `/root/voip-4740-final-YYYYMMDD-HHMMSS/contrato-4740.json`.
- O comando original `--execute-4740` **não deve ser repetido**, pois tenta reservar outro DID.

## PR
- Diagnóstico da tentativa de PUT com **método e HTTP numérico**, sem resposta bruta, dados pessoais nem credenciais.
- Módulo `VoipReservedRecovery`: localiza **somente a reserva existente** do contrato 4740/produto 571, valida o token no estoque e verifica baseline dos outros campos do contrato com o backup.
- Se a observação já contém o vínculo correto, somente confirma a reserva após readback (sem novo PUT).
- Se o marcador original permanece e os demais campos estão idênticos ao backup, a recuperação **pode** executar um PUT usando o DID já reservado, sob autorização independente; não chama `reserveForVoip` e nunca libera outro número.
- Se há divergência de campos, status, observação, token, produto 74 ou estoque, bloqueia. Falhas após o PUT mantêm a reserva intacta.

## Aplicação após CI/merge
Apenas substituir no diretório root-only `/opt/jrconect-voip-writer/`:
- `lib/voip-ixc-controlled-writer.php`
- `lib/voip-reserved-recovery.php`
- `scripts/voip-ixc-recover-4740.php`

Fazer backup desses arquivos antes de substituí-los. **Não editar** `stock.json`, não reiniciar containers, não modificar o cron nem instalar módulos no webroot.

Encontrar a localização exata do backup do contrato com:
```sh
find /root -maxdepth 2 -type f -path '/root/voip-4740-final-*/contrato-4740.json' -print
```

Para examinar a operação, SEM escrita:
```sh
php /opt/jrconect-voip-writer/scripts/voip-ixc-recover-4740.php --inspect /root/voip-4740-final-AAAAMMDD-HHMMSS/contrato-4740.json
```
Substituir o caminho de exemplo pelo resultado do `find`. O comando `--inspect` deve indicar `put_pendente` ou `confirmar_reserva`, sem criar novas reservas.

Só depois da revisão do erro HTTP, das permissões de escrita IXC e da homologação dos campos completos do PUT, com nova autorização para a recuperação, executar:
```sh
JRC_VOIP_CONFIRM_RECOVERY=4740:571 php /opt/jrconect-voip-writer/scripts/voip-ixc-recover-4740.php --resume-4740 /root/voip-4740-final-AAAAMMDD-HHMMSS/contrato-4740.json
```

## Observações importantes
- O IXC exige todos os campos no PUT de contrato, e o PUT ainda não foi homologado plenamente na instalação. Não executar `--resume-4740` antes da revisão do diagnóstico e da autorização explícita.
- `--inspect` não exibe campos pessoais da observação nem tokens de reserva.
- Falha de leitura, credencial ou resposta parcial nunca gera nova reserva.
- Se a API atualizar a observação e falhar ao responder, execute `--inspect` primeiro; a recuperação reconhecerá o DID escrito e fará apenas a confirmação no estoque.
- O processo de atribuição periódica para demais contratos continua desativado.
