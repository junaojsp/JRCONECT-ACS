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
- Atualização manual, sem consulta automática que interfira na busca do operador.

## Detalhes

- Consulta exata por `_id`, serializado como string JSON, com limite de um dispositivo.
- Visão geral, RX/TX/temperatura com data quando disponível, sistema, WAN e SSIDs.
- Mantém os caminhos conhecidos do parser existente: WANDevice.1/WANConnectionDevice.1,
  conexões PPP/IP de 1 a 8, WLANConfiguration.1 a 8 e Device.WiFi.SSID.1 a 8.
- Ausência nesses caminhos não comprova ausência de WAN ou rádio desligado. Redes adicionais,
  parâmetros de outros modelos, histórico e ações continuam na tela atual.
- TX desta prévia usa somente VirtualParameters.TXPower em dBm na faixa -10 a 10.
  Temperatura usa os caminhos já utilizados pelo parser legado e sua conversão raw /256.
  Novos modelos precisam de homologação de unidades antes de ampliar o mapeamento.
- Não estima velocidade, tráfego, clientes Wi-Fi, CPU, memória ou conectividade Internet.
- Projeção não solicita senhas, chaves ou usuários PPPoE; a resposta retorna somente campos
  permitidos mesmo que o upstream ignore a projeção. Não retorna ConnectionRequestURL.
- O botão “Abrir tela atual e ações” usa o ID original; nenhuma ação é executada ao abrir a prévia.

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
e `tests/noc-install.py` com PHP CLI/cURL, Python e Node. Não requerem acesso ao servidor.
