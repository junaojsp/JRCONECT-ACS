# Rede unificada: identificacao e area separada

Esta entrega adiciona **Rede unificada** ao card Redes Wi-Fi, ao lado de **Redes individuais**. A selecao individual existente continua sendo a tela inicial e nao foi modificada. Quando existem parametros candidatos de MLO, aparece tambem **Rede MLO**. O tema e as rotinas existentes de credenciais, firmware, monitoramento e edicao individual foram preservados.

## Limite desta entrega

**A area esta disponivel para consulta e diagnostico. A gravacao de Band Steering/Smart Connect ou MLO ainda NAO esta implementada nem liberada.** Nao existe um mapeamento de fabricante/modelo/firmware validado para o equipamento real do usuario. O botao Gerenciar permanece bloqueado por esse motivo, e nao por perfil do usuario. Nenhum SSID, senha, modo de radio ou estado de unificacao e alterado por esta entrega.

O endpoint GET /api/wifi-network-groups.php verifica a sessao, o perfil ativo no banco e consulta apenas o cache do gerenciamento. Nao envia tarefas ao equipamento. Metodos diferentes de GET retornam 405. A consulta e executada ao abrir Rede unificada pela primeira vez ou clicar Reconsultar identificacao; nao faz polling adicional a cada atualizacao visual do equipamento.

A tecnologia usa os valores de SupportedStandards e OperatingStandards/Standard que realmente foram informados. Wi-Fi 6/7, nomes de SSID iguais e a existencia de 6 GHz nao sao usados para concluir que a unificacao ou MLO esta ativa. O estado do grupo continua Nao confirmado enquanto seu controle nao foi mapeado. Os caminhos candidatos nao habilitam escrita, mesmo que tenham metadados writable=true.

## Proximo passo no modem real

Equipamento > Redes Wi-Fi > Rede unificada > Baixar diagnostico. O arquivo **diagnostico-rede-unificada.json** contem fabricante, modelo, firmware, padroes de radio e caminhos/metadados dos parametros candidatos. Nao exporta valores de SSID ou senha, IP, serial, tokens nem identificador do dispositivo. O diagnostico nao envia comandos de leitura forcada ou de gravacao ao CPE.

Se os caminhos nao aparecerem, usar Detectar redes do modem na area Redes individuais (a rotina existente de atualizacao de leitura) e depois Reconsultar identificacao. Ausencia no cache nao prova que o recurso inexista na interface local do roteador.

Para liberar gravacao em uma proxima etapa, confirmar no firmware os caminhos/tipos, escopo das interfaces, vinculo ao grupo, estado, permissoes e efeito de habilitar/desabilitar. Nao sincronizar senhas de todas as bandas indiscriminadamente. Grupos de convidados e outras redes precisam ser preservados. MLO pode alterar conectividade e seguranca; nao deve ser ativado por simples deteccao de Wi-Fi 7.

## Validacao local

PHP lint dos dois arquivos novos e do footer; Node syntax check; 40 verificacoes de parser/metadados e limites; 107 assercoes de interface em Chromium em 1920, 1280, 768 e 390 px; dois testes da inclusao condicional do footer. API, dados e exportacao de arquivo foram simulados em about:blank sem trafego real. Cobertura: preservar selecao individual, recriacao da tela, abas, campos nao mapeados, ausencia de MLO mesmo com Wi-Fi 7, erros escapados, diagnostico sem segredos e ausencia de POST. Nenhum teste autenticado em producao ou alteracao de senha de modem fisico foi realizado.

Comandos locais: `php tests/wifi-network-groups-test.php`; `node --check assets/js/wifi-network-groups.js`; `python tests/wifi-network-groups-ui.py` (Playwright + Chromium necessarios).

Referencias: https://docs.genieacs.com/en/latest/api-reference.html ; https://cwmp-data-models.broadband-forum.org/tr-181-2-17-0-cwmp-diffs.html
