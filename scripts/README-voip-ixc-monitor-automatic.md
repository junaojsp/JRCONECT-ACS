# PR #100 — Monitoramento automático do produto 74, sem alterar MCP

## Escopo

O ACS consulta periodicamente, **somente leitura**, os contratos com o produto 74 no IXC, usando o cliente e o segredo **já existentes no container MCP**. Não há senha nova, publicação HTTP, troca de OAuth, reinício de container, ou gravação no IXC ou estoque VoiceCopy.

Arquivos:
- `lib/voip-ixc-monitor.php` — valida snapshot e persiste só IDs, status bruto e eventos, sem nomes/CPF
- `scripts/voip-monitor-run.sh` — root no ACS usa `docker exec jrconect-mcp node ...` para consulta IXC temporária
- `scripts/voip-monitor-ingest.php` — PHP CLI root, valida snapshot, compara com referência anterior e atualiza estado atômico
- `voip-central.php` — exibe alertas persistentes e consulta desatualizada
- `tests/voip-ixc-monitor.test.php` — regressões

## Regras dos alertas

1. Primeira consulta bem-sucedida estabelece referência dos contratos existentes; **não** dispara 13 falsos novos.
2. Contrato surge com produto 74 depois da referência: `new_product_74`.
3. Status bruto de um contrato muda: `status_changed`. Códigos `A` e `I` ainda exigem validação formal para ação contratual.
4. Contrato antes visto deixa de constar no snapshot completo do produto 74: `product_74_absent` — gerar revisão, **não liberar** DID.
5. Lista de contratos com produto 74 ainda sem vinculação confirmada na Central VoIP: ajudar triagem; não assumir automaticamente que todos são novas contratações ativas.
6. Falha, timeout ou página parcial IXC: não sobrescrever último snapshot, indicar dados antigos quando ultrapassar 45 minutos.
7. Manter até 200 eventos sem nomes e exibir até 30 no painel. Sem notificação externa ou ação sobre VoiceCopy.
8. Acompanhar a partir de snapshots periódicos — não é webhook nem tempo real. Periodicidade sugerida: **15 minutos**.

## Implantação escalonada — NÃO automática após merge

**Pré-requisitos no ACS:** Docker container `jrconect-mcp` ativo, `node` disponível dentro dele, `IXC_WS_BASE_URL` e segredo IXC já funcionais no container; `/var/www/gacs/lib/voip-reconcile.php`, `/var/lib/jrconect-voip` e `www-data` disponíveis. Validar `php -m` incluindo `posix`. Não ativar sem esses checks.

1. Fazer backup de `/var/www/gacs/voip-central.php` e verificar que o estoque `stock.json` continua intacto (hash). Opcionalmente copiar o atual `ixc-monitor.json` caso já exista, para preservar referência e alertas.
2. Após homologar e revisar arquivos reais do host, instalar `voip-central.php` e `lib/voip-ixc-monitor.php` no ACS. Manter todos os demais módulos.
3. Preparar diretório `/opt/jrconect-voip-monitor` **root:root 0700** e copiar `scripts/voip-monitor-ingest.php` como `ingest.php` (0600) e `scripts/voip-monitor-run.sh` como `run.sh` (0700).
4. Executar manualmente como root `/opt/jrconect-voip-monitor/run.sh` e verificar `MONITOR OK`. A saída tem apenas quantidade de contratos e quantidade de eventos.
5. Verificar `/var/lib/jrconect-voip/ixc-monitor.json` com permissões 0640 grupo www-data, sem nome/CPF; conferir painel e se o baseline inicial não criou alertas.
6. Somente com autorização operacional, instalar arquivo separado em `/etc/cron.d/jrconect-voip-monitor`:
   ```cron
   */15 * * * * root /usr/bin/flock -n /run/lock/jrconect-voip-monitor-cron.lock /opt/jrconect-voip-monitor/run.sh >> /var/log/jrconect-voip-monitor.log 2>&1
   ```
   Usar `chmod 0644` e `chown root:root` no arquivo de cron. Confirmar caminhos executáveis no host. `flock` evita simultaneidade; `set -o pipefail` evita escrita se a consulta IXC falhar. Manter log de somente contagens e erros genéricos; configurar rotação curta.
7. Conferir segunda execução, comparar data `generated_at`, status do painel e evento após uma alteração de teste no IXC **somente com autorização**. Não mexer em contrato real apenas para simular.

## Importante sobre segurança

- O segredo IXC permanece no container e jamais é impresso. Apenas o snapshot com os nomes passa por stdout para o PHP CLI via pipe no host, sem ser gravado em disco. O estado persistente armazena somente IDs e códigos de status.
- O PHP do painel é autenticado pelo mecanismo ACS existente. O arquivo de estado não contém titular e o módulo de estoque é independente.
- Um usuário www-data que controle o diretório do estoque ainda pode manipular dados administrativos; **não usar o monitor como autorização de gravação/baixa**. Os alertas são informativos.
- Não declarar que o botão de conciliação usa consulta automática: ele continua aceitando JSON manual, enquanto a seção de monitoramento usa dados atualizados pelo cron.
- Se a consulta falhar, o estado anterior fica intacto; após 45 min aparece aviso de desatualização. O cron não reinicia nem recria o container.
- A PR #100 **não ativará sozinha o agendamento** e não cria webhooks ou avisos externos. Implantação e ativação são etapas distintas.
