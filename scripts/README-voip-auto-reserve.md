# Reserva automatica VoIP — sem escrita IXC

## Resultado
O agendamento do monitor IXC existente (a cada 15 minutos) consulta os contratos com produto 74 pelo leitor GET dedicado existente. Nenhum processo ou arquivo do MCP e alterado. Apos snapshot validado, a etapa root-only consulta novamente o contrato pela mesma credencial Basic dedicada e verifica:
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
exec 9>/run/lock/jrconect-voip-auto-reserve.lock
flock -n 9 || { echo 'Monitor ocupado; tente depois.'; exit 1; }
mkdir -p "$BACKUP" "$MON/lib"
git -C "$REPO" fetch https://github.com/junaojsp/JRCONECT-ACS-MCP.git main
STAGE=$(mktemp -d "$MON/.stage-XXXXXX")
mkdir -p "$STAGE/lib"
for FILE in lib/voip-ixc-reservation-reader.php lib/voip-automatic-reservation.php; do
  git -C "$REPO" show "FETCH_HEAD:$FILE" > "$STAGE/$FILE"
  php -l "$STAGE/$FILE"
done
git -C "$REPO" show FETCH_HEAD:scripts/voip-auto-reserve.php > "$STAGE/auto-reserve.php"
git -C "$REPO" show FETCH_HEAD:scripts/voip-monitor-run.sh > "$STAGE/run.sh"
php -l "$STAGE/auto-reserve.php"
bash -n "$STAGE/run.sh"
php -r 'exit(extension_loaded("curl") ? 0 : 1);'
# Verificacao GET real, sem gravar monitor, reservar DID ou alterar IXC.
flock -u 9
php "$STAGE/auto-reserve.php" --check-monitor 9>&-
flock -n 9 || { echo 'Monitor ocupado; instalacao nao iniciada.'; exit 1; }
cp -a "$MON" "$BACKUP/monitor"
cp -a /var/lib/jrconect-voip/stock.json "$BACKUP/stock.json"
cp -a /etc/cron.d/jrconect-voip-monitor "$BACKUP/cron"
if [[ -f /var/lib/jrconect-voip/ixc-monitor.json ]]; then
  cp -a /var/lib/jrconect-voip/ixc-monitor.json "$BACKUP/ixc-monitor.json"
fi
for FILE in lib/voip-ixc-reservation-reader.php lib/voip-automatic-reservation.php auto-reserve.php; do
  install -o root -g root -m 600 "$STAGE/$FILE" "$MON/$FILE.new"
  mv "$MON/$FILE.new" "$MON/$FILE"
done
install -o root -g root -m 700 "$STAGE/run.sh" "$MON/run.sh.new"
mv "$MON/run.sh.new" "$MON/run.sh"
flock -u 9
echo "Instalado. Backup: $BACKUP"
# Este ciclo ja pode reservar contratos elegiveis; cron permanece intacto.
"$MON/run.sh" 9>&-
```

Nota: O runner instalado requer no ACS os arquivos `/var/www/gacs/lib/voip-stock.php`, `voip-auto-assign.php`, `voip-ixc-monitor.php`, e o token protegido ja configurado em `/etc/jrconect/voip-ixc-writer.basic`. Nenhuma credencial deve ser colocada no repositório. O monitor shell deve ser o existente em `$MON/run.sh`; confirme o caminho no agendamento local antes de instalar. Se o agendamento utiliza outro caminho, instale nesse arquivo e preserve a crontab original.

O primeiro ciclo reserva somente DIDs de contratos elegiveis; contratos sem marcador e os 13 vinculos existentes permanecem intocados. O contrato 4740, ja reservado, permanece com DID 1156641257.

## Limites / seguranca
- A reserva nao garante entrega nem ativacao de telefonia.
- Atualizacao da observacao IXC e confirmacao de ocupacao continuam manuais.
- Nao ha PUT nem rotina de liberacao automatica.
- Se o monitor estiver desatualizado ou o IXC falhar, nenhuma reserva nova e feita.
- O agendamento nao deve continuar se falhar a etapa anterior do snapshot.

## Diagnostico e validacao
- Antes: uma consulta de produtos, mais contrato e titular para cada contrato.
  Com 18 registros/contratos: aproximadamente 37 GETs sequenciais. O timeout de
  12 segundos era por requisicao, nao um prazo da rotina inteira. O endpoint que
  falhou e a causa externa da lentidao ainda precisam de evidencia de producao.
- Agora: produtos paginados em 20 e contratos por lotes IN de ate 20. Para 18
  registros/contratos: 2 GETs de snapshot. Sem titular, pois o monitor persiste
  somente IDs/status. A reserva continua fazendo revalidacao fresca individual.
- A revalidacao consulta todos os registros 74 do contrato: um segundo produto
  adicionado depois do snapshot impede a reserva.
- Snapshot parcial, total ausente ou filtros ignorados bloqueiam o ciclo sem
  substituir o estado anterior. Nao ha fallback para estado antigo.
- Logs: MONITOR OK com duracao; timeout com recurso/pagina; resumo das reservas
  e falhas por contrato/etapa. Sem observacao, resposta bruta ou credencial.
- Conferir no primeiro ciclo o numero e contrato no painel; repetir o ciclo e
  confirmar zero novas reservas dos mesmos contratos. Comparar os 13 vinculos
  historicos e 4740/571/1156641257 com o backup.
- Rollback: sob o mesmo flock, restaurar os arquivos de codigo de
  `$BACKUP/monitor` para `$MON`. Nunca restaurar stock.json automaticamente, pois
  isso apagaria reservas legitimas posteriores ao backup.
