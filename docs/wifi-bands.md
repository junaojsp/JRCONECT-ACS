# Wi-Fi: bandas separadas e descoberta completa

O card oferece 2,4 GHz e 5 GHz (5,8) separadamente. Primeiro selecione a banda, depois o SSID dentro dela. Prefere uma interface habilitada quando a banda e selecionada pela primeira vez e preserva escolhas por identificador. SSIDs adicionais e desabilitados permanecem selecionaveis; suas instancias LAN/SSID sao indicadas para evitar nomes aparentemente duplicados.

A banda usa parametros de frequencia informados pelo equipamento, a referencia SSID/Radio no TR-181 e, como alternativa, padroes operacionais exclusivos de uma banda. N/AX/BE isolados, canal isolado, nome do SSID e numero de instancia nao sao utilizados para presumir 5 GHz. Frequencias conflitantes ou ambiguas ficam em Banda nao informada. Interfaces distintas com o mesmo SSID nao sao mescladas; apenas IDs repetidos sao removidos da lista.

Detectar redes do modem envia uma atualizacao de leitura dos objetos InternetGatewayDevice.LANDevice e/ou Device.WiFi do equipamento selecionado. Diferentemente de Atualizar leitura, nao fica restrito ao SSID atualmente selecionado e pode descobrir interfaces ausentes do cache. Nao envia SetParameterValues, nao ativa redes nem muda senhas. Uma leitura enfileirada aguarda a comunicacao do modem; requisicoes GET posteriores verificam o resultado, sem reenviar tarefas.

Se 2,4 GHz permanecer como Nao coletada apos a busca, isso nao prova que esteja desabilitada. Sera necessario conferir os parametros disponibilizados por esse firmware. O codigo nao fabrica a segunda rede copiando o SSID de 5 GHz.

Permissoes, CSRF, revelacao explicita de senha e revisao da selecao foram preservados. Rotinas de monitoramento e credenciais nao foram alteradas nesta entrega. Nenhuma senha real ou configuracao de CPE foi alterada durante a publicacao.

Validacao local: PHP lint, Node syntax check, 32 assercoes de classificacao/caminhos e 30 assercoes de interface em Chromium (1920, 1280, 768 e 390 px), usando API e modal simulados. Foram conferidos o alvo da edicao durante reordenacao, selecao por banda, ausencia real de 2,4 GHz, SSIDs iguais em bandas diferentes, entrada HTML escapada e ausencia de chamadas de escrita durante a busca. Os tres blobs foram comparados por SHA com os arquivos testados. Ainda sem validacao autenticada no modem fisico.
