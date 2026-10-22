# Equipamentos e detalhes no NOC 2.0

Prévia separada: `/devices-noc.php` e `/device-noc.php?id=...`, usando a sessão existente.
A dashboard NOC passa a apontar para essas telas. `devices.php`, `device-detail.php`,
dashboard antiga, login, ações e APIs legadas não são modificados.

## Lista

- Consulta ACS por páginas de 100, projeção explícita, resposta máxima de 4 MiB e 15 s por chamada.
- Busca por serial, modelo, fabricante ou IP; filtros de comunicação e RX; páginas visuais de 25.
- Conclui a leitura antes de substituir a última lista válida. Falha ou paginação repetida
  apresenta erro e preserva os dados anteriores; não publica uma lista parcial como completa.
- Limite da prévia: 20.000 dispositivos por carregamento; acima disso encaminha à lista atual.
- Totais representam somente equipamentos ACS carregados. Sinal segue IXC prioritário com
  TR-069 como alternativa e as mesmas faixas da dashboard. Sem leitura é N/D.
- Sem RX válido, o cartão crítico mostra N/D. Com leituras parciais, informa sua cobertura.
  Uma falha IXC aparece em aviso visível com motivo limitado e seguro (autorização, conexão,
  timeout, cache, coleta incompleta), sem devolver mensagens internas ou credenciais.
- Atualização manual, sem consulta automática que interfira na busca do operador.

## Detalhes

- Consulta exata por `_id`, serializado como string JSON, com limite de um dispositivo.
- Visão geral, RX/TX/temperatura com data quando disponível, sistema, WAN e SSIDs.
- Composição alinhada à referência recebida: cabeçalho compacto, abas funcionais e acessíveis,
  diagnóstico em três colunas, sinal e tráfego lado a lado, indicadores e propriedades abaixo.
- Abas de voz e hosts mostram apenas o que foi reportado; histórico e ações oferecem acesso
  à tela atual. O gráfico RX mostra uma única amostra real; não preenche uma curva histórica.
- Mantém os caminhos conhecidos do parser existente: WANDevice.1/WANConnectionDevice.1,
  conexões PPP/IP de 1 a 8, WLANConfiguration.1 a 8 e Device.WiFi.SSID.1 a 8.
- Ausência nesses caminhos não comprova ausência de WAN ou rádio desligado. Redes adicionais,
  parâmetros de outros modelos, histórico e ações continuam na tela atual.
- TX desta prévia usa somente VirtualParameters.TXPower em dBm na faixa -10 a 10.
  Temperatura usa os caminhos já utilizados pelo parser legado e sua conversão raw /256.
  Novos modelos precisam de homologação de unidades antes de ampliar o mapeamento.
- CPU, memória total/livre, hosts, estado NTP e primeira linha de voz usam campos explícitos
  conhecidos de DeviceInfo/Hosts/Time/VoiceService. Memória em uso deriva de total/livre da
  mesma árvore. Valores ausentes/fora de faixa ficam N/D; requer homologação por modelo.
- Contadores acumulados da WAN usam TotalBytesReceived/TotalBytesSent. Não calcula velocidade
  nem curva de tráfego a partir de uma amostra. Não estima clientes Wi-Fi ou conectividade Internet.
- Projeção não solicita senhas, chaves ou usuários PPPoE; a resposta retorna somente campos
  permitidos mesmo que o upstream ignore a projeção. Não retorna ConnectionRequestURL.
- O botão “Ações do equipamento” usa o ID original; nenhuma ação é executada ao abrir a prévia.

## Leitura IXC compartilhada

Dashboard, lista e detalhe usam o mesmo cache privado. Se outra consulta já estiver
atualizando-o, aguardam a publicação de um resultado válido dentro do orçamento de até
12 segundos, sem iniciar outra coleta. Se a atualização não terminar, retornam o motivo
`refresh_busy`, mantendo o ACS independente. Não reutilizam cache vencido como leitura atual.

A captura de produção mostrou lista indisponível e uma consulta posterior de detalhe com
RX IXC válido. Isso evidencia intermitência, mas os logs solicitados não retornaram registros;
a causa específica do incidente ainda precisa ser confirmada pela homologação dos motivos.

## Implantação e homologação

Usar o instalador seletivo atualizado, com backup e restauração, conforme
`docs/NOC-CORRECOES-IXC.md`. Requer a pasta existente `views/layouts` além das pastas usuais.

```bash
cd /tmp/jrc-acs-noc
git pull --ff-only origin feature/dashboard-noc-flowbix-inspirado
sudo python3 scripts/install-noc-fixes.py --target /var/www/gacs
sudo python3 scripts/install-noc-fixes.py --target /var/www/gacs --apply
```

Conferir `/devices-noc.php`: total ACS, busca, filtros, paginação e abertura de detalhe.
Em `/device-noc.php?id=...`, comparar serial, firmware, RX/data, temperatura e SSIDs
com a tela atual em pelo menos um equipamento de cada modelo utilizado.
Verificar que “lista atual” e “tela atual e ações” continuam acessíveis e que login
mantém seu destino. Atualizar o navegador com Ctrl+F5.

Testes locais isolam banco, ACS e IXC; não comprovam suporte de todos os modelos em produção.
Executar `tests/noc-endpoint.py`, `tests/noc-client.cjs`, `tests/noc-inventory-client.cjs`
e `tests/noc-install.py` com PHP CLI/cURL, Python e Node. `tests/noc-cache.py` reproduz
atualização concorrente e espera limitada sem consultar IXC real. Não requerem acesso ao servidor.

## Detalhe óptico: mesma consulta da tela atual

O detalhe novo consulta `/api/get-onu-optical.php?device_id=...`, exatamente o endpoint usado por `assets/js/device-detail.js`. Ele pesquisa a ONU em `radpop_radio_cliente_fibra` no IXC e retorna `sinal_rx`, `sinal_tx`, `temperatura`, `voltagem`, PON e data da leitura. A consulta anterior do inventário NOC guardava somente RX, por isso não podia preencher as outras métricas ópticas do IXC.

O inventário/identificação ainda aparece primeiro; em seguida a consulta óptica preenche as leituras válidas, com origem IXC e seu próprio horário. Falhas, serial/ID incompatível ou valores inválidos não apagam os dados disponíveis. São usados apenas campos conhecidos da resposta, sem renderizar nome, contrato ou credenciais. Não há tarefa/refresh na CPE nem consulta óptica individual para cada linha da lista.

CPU, memória e contadores WAN não fazem parte dessa resposta óptica da tela antiga. Continuam dependendo de valores efetivamente reportados; esta correção não os preenche por estimativa.
