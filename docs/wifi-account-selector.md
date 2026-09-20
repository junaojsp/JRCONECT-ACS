# Seletor de Wi-Fi e contas do equipamento

Escolha uma rede pelo SSID, estado e identificador antes de clicar em **Gerenciar Wi-Fi**. Apenas SSID e senha dessa interface podem ser alterados. Canal, modo de seguranca e estado de habilitacao continuam somente leitura nesta tela; nao sao modificados implicitamente. A escolha sobrevive a atualizacoes e reordenacao da lista. SSIDs desabilitados permanecem disponiveis na selecao.

O card administrativo lista somente contas informadas pelo equipamento. **Senha atual** usa a ultima leitura disponivel, exibida apenas mediante solicitacao explicita e temporariamente. Alguns firmwares ocultam esse parametro mesmo quando permitem alteracao. Nesse caso a interface nao inventa nem recupera a senha antiga. Contas virtuais continuam somente leitura.

O perfil e a condicao ativa do usuario sao verificados na tabela users em cada requisicao. Admin e NOC podem ler e editar credenciais administrativas; atendimento nao recebe acesso a essas contas. Nenhuma permissao e concedida pelo nome do usuario, e nenhum perfil e alterado por este pacote. Uma sessao antiga sem role pode funcionar se o cadastro vigente confirmar o perfil autorizado.

Gravacoes usam CSRF, caminho observado e gravavel, identificador estavel, revisao da leitura e apenas campos alterados. A senha nova requer confirmacao na interface. Campo vazio preserva a senha existente. Comando enfileirado nao e apresentado como aplicado; em timeout, conferir a leitura antes de reenviar.

Validacao local: PHP lint; Node syntax check; 36 assercoes de parametros/permissoes; 32 assercoes de interface em Chromium com API e adaptador de modal simulados, em 4 larguras. Os tres blobs publicados foram comparados por SHA com os arquivos testados. Nenhum teste autenticado no servidor de producao e nenhuma alteracao de senha de equipamento real foram realizados.

O codigo e estilos de monitoramento foram preservados. O pacote nao corrige por si so campos proprietarios ausentes do firmware. Testar primeiro num equipamento de laboratorio com acesso local alternativo.
