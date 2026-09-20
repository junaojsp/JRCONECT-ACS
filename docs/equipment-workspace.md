# Tela de detalhes do equipamento - layout de atendimento

Camada de apresentacao baseada na referencia aprovada: menu lateral expansivel, cabecalho do equipamento, cards de dispositivo/WAN/optico, area de Wi-Fi, portas LAN, ferramentas e assistente lateral. As contas administrativas, dispositivos conectados e todos os menus anteriores permanecem disponiveis. A lista de equipamentos, a Visao Geral do servidor e as demais paginas nao mudam.

## Preservacao das funcoes

O CSS so atua com a classe `body.jr-equipment-workspace`; o footer inclui os arquivos apenas em `device-detail.php`. O JavaScript reaproveita os nos e IDs existentes. Nao reescreve endpoints, seletores Wi-Fi, metodos de gravacao, permissoes, credenciais, firmware ou monitoramento. O assistente original e movido para a lateral e preservado durante a recriacao dos cards, mantendo pergunta e resposta. Os atalhos navegam para as abas existentes. As acoes de velocidade e acesso remoto so invocam os handlers originais apos clique explicito. Nao ha fetch, tarefas de CPE nem envio de formularios no carregamento desta camada.

A rede unificada mantem o estado e as limitacoes da implementacao existente; sua apresentacao nao libera Band Steering/MLO por conta propria. Firmware e IA tambem mantem suas limitacoes: um botao de navegacao nao cria uma integracao de IA ou mecanismo de instalacao. O assistente informa que respostas dependem da integracao configurada.

## Dados e imagens

Cliente, contrato, PON e numero de ONU usam o cache optico ja existente. Valores ausentes aparecem como Nao informado. Nao sao copiados nomes de clientes, planos, contagens, mensagens ou estado de servicos da imagem de referencia. O modem e representado por icone generico, pois nao foi encontrada uma foto validada para cada modelo no diretorio de imagens. Marca textual JRCONECT TELECOM; nenhuma alteracao em logotipos registrados.

As etiquetas estaticas CONECTADO e NORMAL do layout antigo nao sao usadas como novas provas de saude: WAN passa a usar o estado informado no objeto existente; o card optico indica Ultima leitura. Os calculos e os demais dados operacionais nao foram alterados.

## Voltar ao visual anterior

No topo da tela: **Acoes > Layout anterior**. Esse link acrescenta `layout=classic` a URL atual e desativa a nova camada sem alterar arquivos ou dados. Retirar esse parametro permite visualizar o novo layout novamente. A copia anterior do repositorio foi preservada em branch de backup antes da publicacao.

## Validacao local e limites

Node syntax check do JavaScript e PHP lint do footer. 48 assercoes de UI em Chromium com DOM, dados e adaptadores de abas simulados, sem requisicoes externas. Nove larguras: 1920, 1648, 1440, 1280, 1100, 900, 700, 390 e 320 px. Cobertura: IDs unicos apos cinco recriacoes, preservacao do campo da IA, manutencao dos handlers e destino do Wi-Fi 2.4/5, selecao de rede unificada, atalhos, recolhimento do assistente, metadados escapados, ausencia de escrita no carregamento e retorno ao layout anterior. Verificacao adicional de inclusao condicional do footer em tres rotas.

Esses testes validam a nova apresentacao com integracoes simuladas. Nao houve acesso autenticado ao servidor de producao, teste em modem fisico, leitura de senha, escrita de configuracao ou envio de firmware. A validacao final do tema com os dados reais acontece apos o deploy pelo administrador.

Referencias de implementacao: Bootstrap 5.3 Tabs (`getOrCreateInstance`, `shown.bs.tab`) e MutationObserver (MDN). Os assets novos devem ser publicados junto com a inclusao do footer.
