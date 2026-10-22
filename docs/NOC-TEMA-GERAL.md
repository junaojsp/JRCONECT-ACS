# Tema geral do ACS

O tema azul do NOC cobre as páginas que usam o cabeçalho comum (dashboard atual, equipamentos atuais, detalhes, mapa, configurações, usuários, teste de velocidade e registros), além de Discovery, login, configuração inicial e páginas NOC.

O instalador mescla apenas as referências aos novos arquivos CSS/JS nos cabeçalhos locais das páginas existentes. Preserva formulários, autenticação, permissões, ações e o destino do login. Os arquivos próprios do NOC continuam sendo atualizados pelo instalador seletivo.

Na cópia de testes do repositório:

```sh
python3 scripts/install-noc-fixes.py --target /var/www/gacs --theme
sudo python3 scripts/install-noc-fixes.py --target /var/www/gacs --theme --apply
```

A primeira execução mostra a prévia. A segunda valida PHP, cria backup privado e aplica. Para desfazer, utilize o caminho de backup informado:

```sh
sudo python3 scripts/install-noc-fixes.py --target /var/www/gacs --restore /var/backups/jrc-noc-DATA
```

Teste login, menu recolhido e móvel, busca, tabelas, formulários, modais, mapa e ações já existentes. Aplicar o tema não implementa automaticamente as funções vistas nas referências da Flowbix.

## Diagnóstico de parâmetros da ONU

A leitura WAN agora inclui os contadores `Stats.EthernetBytesReceived` e `Stats.EthernetBytesSent` definidos no [modelo oficial TR-098](https://cwmp-data-models.broadband-forum.org/tr-098-1-8-0.html), preservando aliases anteriores. São bytes acumulados; velocidade depende de amostras ao longo do tempo.

Para identificar caminhos realmente presentes no HG6143D, execute a partir da cópia de testes, sem instalar o script no servidor web:

```sh
sudo -u www-data php scripts/inspect-noc-device.php --app /var/www/gacs --serial FHTT953C4D80 --values
```

Consulta somente uma CPE no ACS, sem enviar tarefas ou comandos. Exibe apenas caminhos de métricas, tipo, horário e amostras numéricas; filtra campos de credenciais e identificação. Sem `--values`, também omite as amostras numéricas. A ausência de um caminho no ACS não comprova falta de suporte na CPE. TX, temperatura, CPU e memória específicos do modelo dependem desse diagnóstico antes de novo mapeamento.


### Quando o filtro de métricas não encontra os nomes do fabricante

`--all-paths` lista até 2.000 caminhos com `_value` armazenado no ACS (inclusive nulo), além da indicação de presença de valor, tipo e horário. Continua excluindo nomes sensíveis. Este modo nunca imprime os valores, mesmo junto de `--values`. Objetos sem parâmetros coletados não são incluídos; não é uma descoberta de capacidades na CPE.

```sh
sudo -u www-data php scripts/inspect-noc-device.php --app /var/www/gacs --serial FHTT953C4D80 --all-paths
```

O relatório marca `truncated` se atingir o limite. O filtro normal de métricas inclui também o alias legado `VirtualParameters.gettemp`. Resultado vazio de um filtro não comprova ausência da função no equipamento: conferir os nomes armazenados antes de decidir se é necessário descobrir/coletar parâmetros na CPE.
