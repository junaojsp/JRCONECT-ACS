# Histórico de ações por ONU

Na branch `ui/equipamentos-cards-reference`, após atualizar o código:

```sh
cd /var/www/gacs
git pull --ff-only origin ui/equipamentos-cards-reference
php cron/install-action-history.php
```

O instalador cria uma tabela própria e pode ser executado novamente. Não exige cron. Atualize o navegador com Ctrl+F5 e abra a ONU.

Registra novas ações feitas nos endpoints de controle, comunicação, Wi-Fi, WAN e DHCP do painel e pelo cliente SIMS instrumentado. Ações anteriores à instalação, comandos feitos diretamente no GenieACS e rotinas externas como o reset IXC não são importados. Cada tarefa de atualização de leitura pode gerar sua própria linha.

Armazena responsável, data UTC, identificação do equipamento, categoria dos campos, status, ID da tarefa e código HTTP. Não armazena senhas, valores de parâmetros nem respostas completas. A tela apresenta o horário local do navegador. Integrações sem sessão são identificadas como Integração.

Enviado: envio iniciado. Aguardando ONU: retorno HTTP 202 ou tarefa presente na fila. Concluído: resposta imediata HTTP 200 bem-sucedida. Falhou: rejeição HTTP 4xx ou falha vinculada à tarefa no GenieACS. Sem confirmação: erro de transporte, resposta inconclusiva ou tarefa que deixou a fila sem confirmação. A remoção da fila não comprova execução; inclusive tarefas executadas posteriormente podem ficar sem confirmação. Não há tentativas automáticas de reenviar comandos.

A consulta tem paginação de 50 registros, usa índice por equipamento e atualiza a página recente a cada 15 segundos com a aba visível. Consulta somente os IDs de tarefas e falhas, sem ler seus valores nem enviar comandos à ONU. Se a NBI não responder, preserva os estados registrados e informa a indisponibilidade.

Se o banco do histórico estiver indisponível, os comandos existentes continuam funcionando, mas poderão ficar sem registro. O PHP registra uma mensagem genérica no log. Portanto, a ativação da tabela é necessária e este histórico não garante auditoria completa durante indisponibilidade do banco. Não há exclusão automática de registros nesta versão.

Validação local: lint PHP e JavaScript, testes de estados, categorias sem valores, renderização com escape HTML, deduplicação de consultas e rejeição de respostas antigas. O banco e o GenieACS de produção não são acessados pelos testes.
