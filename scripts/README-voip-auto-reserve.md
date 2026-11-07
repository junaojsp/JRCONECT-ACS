# Reserva automatica VoIP — sem escrita IXC

## Resultado
O agendamento do monitor IXC existente (a cada 15 minutos) continua consultando os contratos com produto 74 no MCP **somente para leitura**. Apos snapshot validado, uma etapa root-only consulta novamente o contrato pela credencial Basic dedicada, verifica:
- status do contrato IXC `A`;
- exatamente um registro do produto `74`, consistente com snapshot;
- `obs_contrato` contendo a ocorrencia unica exata `#JRC_VOIP_SOLICITAR#`;
- contrato e produto ainda sem vinculacao/reserva no estoque;
- DID que ja estava `disponivel` por confirmacao VoiceCopy.

So entao executa `VoipStock::reserveForVoip` localmente, sob stock.lock, sem modificar a observacao no IXC, sem ativar VoiceCopy, sem marcar `ocupado` e sem liberar nenhuma reserva. Nao ha numero duplicado em execucoes subsequentes. Vínculos históricos e reserva 4740/571 sao preservados.

No painel VoIP, a linha de estoque mostra `reservado` + numero + contrato e produto. A equipe escreve a observacao no IXC manualmente, e somente apos conferencias confirma o vinculo via processo operacional apropriado; NAO chamar o antigo `--execute-4740`.

## Instalar depois de merge (no ACS, como root)
ATENCAO: Verifique caminhos locais, mantenha copia de seguranca e revise a PR antes de executar. Este procedimento instala a rotina e a habilita nas proximas execucoes do monitor.

```bash
set -Eeuo pipefail
umask 077
REPO=/opt/jrconect-acs-git
MON=/opt/jrconect-voip-monitor
BACKUP=/root/voip-auto-reserve-backup-$(date +%Y%m%d-%H%M%S)
mkdir -p "$BACKUP" "$MON/lib"
cp -a /var/lib/jrconect-voip/stock.json "$BACKUP/stock.json"
cp -a "$MON/run.sh" "$BACKUP/run.sh"
git -C "$REPO" fetch https://github.com/junaojsp/JRCONECT-ACS-MCP.git main
for FILE in lib/voip-ixc-reservation-reader.php lib/voip-automatic-reservation.php; do
  git -C "$REPO" show "FETCH_HEAD:$FILE" > "$MON/$FILE"
  php -l "$MON/$FILE"
  chmod 600 "$MON/$FILE"
done
git -C "$REPO" show FETCH_HEAD:scripts/voip-auto-reserve.php > "$MON/auto-reserve.php"
php -l "$MON/auto-reserve.php"
chmod 600 "$MON/auto-reserve.php"
git -C "$REPO" show FETCH_HEAD:scripts/voip-monitor-run.sh > "$MON/run.sh.new"
bash -n "$MON/run.sh.new"
chmod 700 "$MON/run.sh.new"
mv "$MON/run.sh.new" "$MON/run.sh"
echo "Instalado. Backup: $BACKUP"
```

Nota: O runner instalado requer no ACS os arquivos `/var/www/gacs/lib/voip-stock.php`, `voip-auto-assign.php`, `voip-ixc-monitor.php`, e o token protegido ja configurado em `/etc/jrconect/voip-ixc-writer.basic`. Nenhuma credencial deve ser colocada no repositório. O monitor shell deve ser o existente em `$MON/run.sh`; confirme o caminho no agendamento local antes de instalar. Se o agendamento utiliza outro caminho, instale nesse arquivo e preserve a crontab original.

O primeiro ciclo reserva somente DIDs de contratos elegiveis; contratos sem marcador e os 13 vinculos existentes permanecem intocados. O contrato 4740, ja reservado, permanece com DID 1156641257.

## Limites / seguranca
- A reserva nao garante entrega nem ativacao de telefonia.
- Atualizacao da observacao IXC e confirmacao de ocupacao continuam manuais.
- Nao ha PUT nem rotina de liberacao automatica.
- Se o monitor estiver desatualizado ou o IXC falhar, nenhuma reserva nova e feita.
- O agendamento nao deve continuar se falhar a etapa anterior do snapshot.
