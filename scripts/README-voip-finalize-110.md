# PR #110 — Finalização controlada do contrato 4740 (DID já reservado)

## Situação atual, sem novas suposições
- Contrato **4740**, produto **74**, registro **571**.
- DID **1156641257** já reservado no `stock.json`.
- Backup original do contrato em `/root/voip-4740-final-20261107-033748/contrato-4740.json`.
- Última inspeção passou (`put_pendente`); observação do IXC permanece com `#JRC_VOIP_SOLICITAR#`.
- A primeira gravação real falhou; **não sabemos ainda o código HTTP desse PUT**. Não é possível afirmar sucesso em produção sem a nova execução.

## Alterações
1. Corrige o tratamento de sucesso de `PUT`: alguns endpoints respondem HTTP 2xx com corpo vazio ou texto simples; a integração não exige resposta JSON para aceitar o *ACK*.
2. O retorno HTTP 2xx **não encerra a operação**: sempre relê o contrato e os campos após o PUT para confirmar o DID exato.
3. Se a conexão falhar ou a resposta indicar erro mas o GET provar que o DID foi gravado e todos os demais campos continuam iguais ao backup, **finaliza exclusivamente a reserva já existente**, sem PUT repetido.
4. Se houve rejeição verdadeira, divergência de contrato ou GET inconclusivo, **mantém a mesma reserva bloqueada**, reporta HTTP numérico quando disponível e não escolhe outro número.
5. Serializa execuções CLI por lock local `/run/lock/jrconect-voip-4740-recovery.lock`; execução concorrente não passa.
6. Continua restrito ao contrato 4740 e registro 571, sem job automático e sem tocar no MCP ou outros contratos.

## Instalar após merge (sem realizar PUT)
```bash
set +e
REPO=/opt/jrconect-acs-git
DIR=/opt/jrconect-voip-writer
BKP=/root/backup-voip-pr110-$(date +%Y%m%d-%H%M%S)
mkdir -p "$BKP/lib" "$BKP/scripts"
git -C "$REPO" fetch https://github.com/junaojsp/JRCONECT-ACS-MCP.git main || exit 1
cp -a "$DIR/lib/voip-ixc-controlled-writer.php" "$BKP/lib/"
cp -a "$DIR/lib/voip-reserved-recovery.php" "$BKP/lib/"
cp -a "$DIR/scripts/voip-ixc-recover-4740.php" "$BKP/scripts/"
cp -a /var/lib/jrconect-voip/stock.json "$BKP/"
for P in lib/voip-ixc-controlled-writer.php lib/voip-reserved-recovery.php scripts/voip-ixc-recover-4740.php; do
  git -C "$REPO" show "FETCH_HEAD:$P" > "$DIR/$P" || exit 1
  chmod 600 "$DIR/$P"
  php -l "$DIR/$P" || exit 1
done
php "$DIR/scripts/voip-ixc-recover-4740.php" --inspect /root/voip-4740-final-20261107-033748/contrato-4740.json
```

## Execução definitiva do piloto (escrita REAL; operar somente após revisão)
Há risco inerente ao `PUT` completo do IXC, que pode impor validações e efeitos próprios. A PR melhora idempotência e retorno, mas **não pode garantir compatibilidade do PUT sem executá-lo**. A equipe deve autorizar explicitamente a gravação de um único contrato após garantir backup e credencial de edição.

```bash
JRC_VOIP_CONFIRM_RECOVERY=4740:571 php /opt/jrconect-voip-writer/scripts/voip-ixc-recover-4740.php --resume-4740 /root/voip-4740-final-20261107-033748/contrato-4740.json
```

**Nunca executar a ação antiga** `--execute-4740` após reserva existente. Se falhar, não repetir cegamente: o CLI informa código HTTP de rejeição quando possível e mantém reserva. Readback reconcilia ACKs e timeouts.

## Resultado de sucesso comprovável
- O IXC retorna a observação íntegra, com apenas o marcador substituído por `JRC_VOIP_CONTRATO=4740;DID=1156641257`.
- Estoque muda exatamente esse DID `reservado → ocupado`, contrato 4740, registro do produto 571.
- Nenhuma nova reserva; os outros 13 vínculos existentes continuam protegidos.

Para próximos contratos será necessário ampliar o escopo depois que a execução real de 4740 passar; esta PR não ativa distribuição geral.
