# Histórico de potência das ONUs

O histórico coleta `sinal_rx`, `sinal_tx` e `data_sinal` do cadastro `radpop_radio_cliente_fibra` no IXC. Apenas ONUs presentes no GenieACS, vinculadas pelo serial e seus aliases, são armazenadas. Vínculos ambíguos são ignorados. Não são executados comandos nas ONUs, na OLT ou no IXC.

O gráfico aparece no detalhe da ONU. Períodos: 24 horas, 7 dias e 30 dias. Os pontos representam a média por intervalos de 15 minutos, 1 hora e 6 horas, respectivamente. Mínimo e máximo de cada intervalo aparecem no título do ponto; os indicadores acima usam os extremos das leituras, não os extremos das médias. Lacunas não são ligadas no gráfico. Referências de RX: −25 e −27 dBm.

## Ativação no servidor

Após atualizar a branch em `/var/www/gacs`:

```bash
php cron/optical-history.php --install
php cron/optical-history.php
```

O primeiro comando cria somente a tabela nova. O segundo faz a coleta inicial e imprime a contagem de leituras válidas, inseridas, sem vínculo, com vínculo ambíguo e sem leitura válida. Reexecutar a instalação é seguro.

Para coleta automática a cada 15 minutos, como root:

```bash
printf '%s\n' '*/15 * * * * root /usr/bin/php /var/www/gacs/cron/optical-history.php >> /var/log/jrconect-acs-optical-history.log 2>&1' > /etc/cron.d/jrconect-acs-optical-history
chmod 644 /etc/cron.d/jrconect-acs-optical-history
```

Usa as credenciais IXC/GenieACS e o banco existentes no ACS. A API IXC precisa permitir listagem do cadastro de fibra. A coleta tem bloqueio para evitar execuções sobrepostas, paginação de até 50.000 registros, gravação em transação e retenção de 90 dias. Os arquivos de log devem seguir a política de rotação do servidor.

## Horários e integridade

Datas sem timezone do IXC são interpretadas em `America/Sao_Paulo`, e o banco armazena UTC. A interface mostra o fuso do navegador. Quando existe `data_sinal`, leituras repetidas desse mesmo serial e horário não geram novas medições; apenas atualizam o horário da última observação. Sem data de origem, há no máximo uma observação por serial a cada 15 minutos, e a interface identifica explicitamente o uso do horário observado.

RX ausente ou fora do intervalo −60 a +10 dBm não vira zero. Datas inválidas, mais de cinco minutos no futuro ou anteriores à retenção não são armazenadas. TX ausente fica nulo. Falhas de paginação, autenticação ou consulta não criam amostras e não apagam o histórico. O histórico começa após ativação e não importa medições retroativas de outra ferramenta.

Sem a tabela instalada, o card informa que o histórico ainda não foi ativado, sem bloquear as leituras atuais ou os demais diagnósticos.

## Validação

```bash
php tests/optical-history-test.php
```

Após ativar, conferir a contagem da coleta, abrir uma ONU conhecida e comparar seu RX com o IXC. Repetir a coleta com o mesmo `data_sinal`: `inserted` deve ser zero para leituras já armazenadas. A tabela e o agendamento precisam ser validados no ambiente real; os testes de código usam leituras simuladas.
