# Edição independente de Wi-Fi 2,4 GHz e 5 GHz

O formulário Gerenciar Wi-Fi agora contém o seletor **Qual rede deseja alterar?**, agrupado por banda. É possível abrir pelo Wi-Fi de 5 GHz e selecionar a interface de 2,4 GHz dentro do formulário. SSID, senha e identificador de destino mudam juntos. Campos não salvos exigem confirmação antes da troca de rede. Atualizações em segundo plano não mudam o alvo do formulário aberto.

O servidor confere o identificador observado, a revisão, a banda enviada e as permissões de escrita do equipamento antes de enviar alterações. Nenhuma regra presume que a instância 1 seja 2,4 GHz. Somente os campos alterados são enviados. Senha em branco preserva a atual. A edição de 5 GHz e os controles administrativos foram preservados, assim como todo o trecho de monitoramento.

A classificação passa a reconhecer grafias compactas de padrões operacionais, como bgn/bg/gn e anac. N, AX ou BE sozinhos continuam insuficientes para identificar uma banda. Esse defeito do parser foi reproduzido com dados de teste, mas não houve confirmação de que seja a única causa no modem do usuário. Não foram consultados dados autenticados do modem real nesta entrega.

Quando a interface existe mas não é gravável, o formulário mostra os dados sem liberar Salvar. Quando 2,4 GHz não está na leitura, essa opção é marcada como Não coletada, nunca como uma cópia editável de 5 GHz. Use Detectar redes do modem para atualizar a descoberta. Se continuar ausente ou bloqueada, use **Baixar diagnóstico Wi-Fi**: o JSON contém caminhos, classificação e permissões, sem valores de SSID/senha, endereço IP ou serial. A ação de diagnóstico consulta somente o cache e não envia tarefas ao CPE.

Validação local dos blobs publicados: php -l, node --check, 34 asserções de backend e 27 de interface em Chromium, com quatro larguras e integração/modal simulados. Testes cobriram destino 2,4 GHz, destino 5 GHz, mesma identificação de SSID, referência TR-181 com índices diferentes, banda divergente, parâmetros somente leitura, manutenção de senha, seleção durante atualização e diagnóstico sem segredos. Não houve troca de senha ou teste em equipamento físico.
