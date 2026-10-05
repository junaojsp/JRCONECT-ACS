# Confirmação de configurações

Atualize a mesma branch e instale as tabelas (instalador idempotente):

```sh
cd /var/www/gacs
git pull --ff-only origin ui/equipamentos-cards-reference
php cron/install-action-verification.php
```

Pressione Ctrl+F5. O histórico da ONU apresenta o retorno do comando e, separadamente, a confirmação dos campos. Não exige cron e só acompanha novas alterações enviadas pelo cliente instrumentado depois da instalação.

Depois de um envio bem-sucedido de configuração, solicita uma única leitura `getParameterValues` dos campos permitidos. A solicitação tem timeout curto e pode aguardar a próxima comunicação da ONU. O envio original não é repetido e seu resultado não é alterado caso a verificação falhe. Atualizações posteriores da tela somente consultam dados; não enviam novas tarefas de leitura.

Campos confirmados significa que todos os campos verificáveis coincidiram com o solicitado em uma leitura com timestamp posterior ao início da verificação. Dados antigos, ausentes ou sem timestamp não confirmam alterações. Valores divergentes indica uma leitura nova com diferenças; a comparação pode ser atualizada durante 24 horas. Após esse prazo, encerra a espera. Se não existir leitura nova, mantém a indicação de espera até encerrar o prazo.

O estado do comando permanece separado: valores coincidentes não provam que uma tarefa específica foi executada. Outra ação pode ter configurado os mesmos valores. Leituras ainda na fila ou com falha vinculada não confirmam campos. Sair da fila, isoladamente, também não confirma: exige valores coincidentes com timestamps novos. Isso confirma o estado observado no ACS, não prova a origem exata da atualização nem garante permanência da configuração. Uma confirmação já registrada é preservada como histórico.

Não armazena valores em claro. Persiste somente caminho permitido, tipo e SHA-256 de valores não secretos. Senhas, chaves e usuários são excluídos da comparação, dos hashes e da solicitação de leitura. A interface informa quando há campos não verificáveis; Campos confirmados nunca confirma a senha. Campos fora da lista permitida também são excluídos. Atualmente inclui SSID, habilitação, modos de segurança, alguns campos WAN/VLAN e DHCP, com máximo de 16 campos por alteração.

A consulta usa projeção dos campos permitidos, atende no máximo cinco alterações pendentes por atualização e limita o tamanho da projeção. Falhas de conexão preservam os estados e exibem consulta indisponível. Se o banco de verificação estiver indisponível, o comando continua funcionando, mas a confirmação pode não ser registrada. Consulte o log PHP para a mensagem genérica de indisponibilidade.

Testes locais cobrem leitura antiga, divergência, campos ausentes, normalização de booleanos e inteiros, exclusão de credenciais, integração do cliente com respostas simuladas e renderização segura. Não executam comandos em ONUs reais.
