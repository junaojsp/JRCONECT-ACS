# PR #90 - Instalacao isolada de arquivos da consulta IXC VoIP

**Este instalador nao ativa ferramentas em producao.** Ele prepara os dois arquivos no **host** MCP, cria backup, valida marcadores e permite rollback. O container ativo pode usar uma imagem interna sem bind mount, portanto instalar arquivos no host **nao garante** que o runtime os carregue. A ativacao sera revisada separadamente.

## O que muda
- Novo: /opt/jrconect-mcp/integrations/ixc-voip-tools.js
- Alterado por patch de duas linhas: /opt/jrconect-mcp/integrations/ixc-webservice-tools.js
- Nao altera server.js, autenticacao, docker-compose, IXC, PABX, Opa, Wiki, ACS PHP ou estoque VoiceCopy.
- O instalador verifica apenas marcadores minimos do codigo. A revisao manual da cadeia OAuth continua obrigatoria.

## Preparo - comando seguro no SSH do ACS (apos merge)

Usa o repositorio ACS somente como base de objetos Git; nao altera a branch nem o remote origin.

~~~bash
set -e
REPO=/opt/jrconect-acs-git
SRC=https://github.com/junaojsp/JRCONECT-ACS-MCP.git
git -C "$REPO" fetch "$SRC" main
mkdir -p /root/voip-ixc-stage
git -C "$REPO" show FETCH_HEAD:scripts/install-voip-ixc-isolated.mjs > /root/voip-ixc-stage/install.mjs
git -C "$REPO" show FETCH_HEAD:mcp/integrations/ixc-voip-tools.js > /root/voip-ixc-stage/ixc-voip-tools.js
node --check /root/voip-ixc-stage/install.mjs
node --check /root/voip-ixc-stage/ixc-voip-tools.js
node /root/voip-ixc-stage/install.mjs --plan /root/voip-ixc-stage/ixc-voip-tools.js
~~~

O --plan mostra somente caminhos e hashes, nao revela segredos e nao grava no MCP.

## Antes de aplicar

1. Confirmar --plan OK e revisar os nomes dos arquivos.
2. Confirmar que o instalador foi extraido do commit revisado (sem modificacoes no stage).
3. Revisar em producao o escopo da autorizacao, incluindo o caminho OAuth, o papel ixc-mcp-admin, a lista de sujeitos e a separacao do token do Opa.
4. Identificar o mount do container: se a imagem contem copia interna dos JS e nao houver bind mount, esta etapa **nao habilitara a ferramenta**. Nao executar docker compose up/restart como atalho.

## Aplicacao EXPLICITA nos arquivos do host (somente apos validacao)

~~~bash
VOIP_IXC_APPROVE_APPLY=YES node /root/voip-ixc-stage/install.mjs --apply /root/voip-ixc-stage/ixc-voip-tools.js
~~~

O resultado mostra o caminho do backup no diretório /var/backups/jrconect-acs/voip-ixc/. Guarde esse caminho. Nao executar o comando varias vezes: a segunda aplicacao sera bloqueada.

## Rollback dos arquivos do host

~~~bash
node /root/voip-ixc-stage/install.mjs --rollback /var/backups/jrconect-acs/voip-ixc/NOME_DO_BACKUP
~~~

O script verifica hashes do patch antes de reverter. Em caso de arquivo alterado posteriormente, **bloqueia** e exige rollback manual. Nao reinicia o processo/container.

## Homologacao antes da ativacao real

- Confirmar a origem dos arquivos carregados pelo container MCP em execucao.
- Confirmar a separacao dos handlers OAuth e Opa sem expor ferramentas do IXC indevidamente.
- Testar a consulta em homologacao com um contrato conhecido que possua o produto 74 e outro sem ele.
- Verificar resposta de cliente_contrato e vd_contratos_produtos (status bruto, total, registros, filtros, paginacao).
- Reconciliar as diferencas do Compose e da imagem de producao em **outra PR** antes de rebuild/restart.
- A ferramenta nao grava contratos, nao associa DIDs e nao libera numeros.

Nao enviar tokens ou arquivos de ambiente ao GitHub ou chat.
