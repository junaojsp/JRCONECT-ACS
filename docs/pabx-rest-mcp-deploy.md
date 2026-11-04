# JR CONECT — ativar PABX REST no MCP principal

## Situacao comprovada

No ACS (138.204.112.25), `curl` ao endpoint `https://pabx.jrconect.com/api/mcp/index.php?action=status` retornou `{"ok":true,"action":"status","result":[...]}` com Bearer Token de leitura. HTTPS e certificado ja foram verificados no host ACS.

O container MCP **ainda nao foi homologado nem implantado com este modulo**. A API PABX responde, mas o ChatGPT ainda nao tem ferramentas do PABX.

## Esta branch

Base: `feature/pabx-mcp-bridge`. Troca `mcp/pabx.js` do modo SSH para API REST, e fornece:

- `registerPabxTools(mcp)`: `pabx_status`, `pabx_filas`, `pabx_ramais`, `pabx_troncos` e `pabx_ixc_arquivos`, todas somente leitura.
- Somente endpoint HTTPS fixo do PABX, Bearer Token de arquivo Docker privado, timeout, sem redirects, limite de resposta e validacao JSON.
- `mcp/docker-compose.pabx-rest.yml` override opcional, sem mexer no Compose atual.
- `.dockerignore` reforcado para segredos de outros servicos e chaves SSH.
- `scripts/prepare-pabx-rest-mcp.py` com simulacao, backup e substituicao pontual do registro SSH anterior.

**Crucial:** os arquivos `mcp/server.js` e `mcp/docker-compose.yml` historicos dessa branch NAO sao espelho da producao. O script inspeciona e adapta o `/opt/jrconect-mcp/server.js` ativo. Nao copiar os arquivos historicos `server.js` ou `docker-compose.yml` para producao.

## Passo 1 — preparar em modo simulacao no ACS

Fazer backup/snapshot da VM do ACS e conferir `git status --short` antes de mudar de branch.

```sh
cd /opt/jrconect-acs-git
git fetch origin feat/pabx-rest-connector-20261103
git switch --create feat/pabx-rest-connector-20261103 --track origin/feat/pabx-rest-connector-20261103
python3 scripts/prepare-pabx-rest-mcp.py
```

Caso a branch ja exista localmente, basta `git switch feat/pabx-rest-connector-20261103` e `git pull --ff-only`.

Revisar o diff na saida: deve alterar somente import/registro no `server.js`, incluir `pabx.js`, configurar `.dockerignore` e override Compose; sem remover Wiki/Opa/ConnectionRegistry/Keycloak.

Somente apos verificar o diff:

```sh
python3 scripts/prepare-pabx-rest-mcp.py --apply
cd /opt/jrconect-mcp
node --check server.js
node --check pabx.js
```

O script nao reinicia nada, nao cria segredo e salva backups em `/opt/jrconect-mcp/backups/pabx-rest-...`.

## Passo 2 — copiar token privado para o ACS

O token de leitura esta no PABX em `/etc/jrc-pabx-mcp/api.ini`. Nao colocar no navegador, GitHub, prints ou conversa. Provisionar no ACS diretamente em arquivo **fora** do contexto Docker:

```sh
install -d -o root -g root -m 0700 /var/lib/jrc-pabx-mcp
install -o 1000 -g 1000 -m 0600 /dev/null /var/lib/jrc-pabx-mcp/read.token
read -r -s -p "Cole o token de leitura PABX: " PABX_TOKEN; echo
printf '%s' "$PABX_TOKEN" > /var/lib/jrc-pabx-mcp/read.token
unset PABX_TOKEN
chmod 0600 /var/lib/jrc-pabx-mcp/read.token
wc -c < /var/lib/jrc-pabx-mcp/read.token
```

Esperado `64` bytes; o arquivo deve pertencer ao UID/GID 1000, pois o container roda como `node` (UID 1000). **Nao usar admin_token** para este conector.

## Passo 3 — revisar Docker e realizar implantacao controlada

No host ACS:

```sh
export PABX_READ_TOKEN_PATH=/var/lib/jrc-pabx-mcp/read.token
cd /opt/jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.pabx-rest.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.pabx-rest.yml config --services
```

Conferir o impacto do override na configuracao atual. Em janela de manutencao, se validado:

```sh
docker compose -f docker-compose.yml -f docker-compose.pabx-rest.yml up -d --build jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.pabx-rest.yml ps
curl -fsS http://127.0.0.1:3100/health
```

**Sem criar outra instancia MCP**. Não alterar AMI TCP/5038, Asterisk, Phonevox ou URA.

## Passo 4 — validacao ponta a ponta

- `status_servidor` continua operacional.
- Ferramentas Wiki, Opa, ChatGPT e autenticacao Keycloak permanecem.
- Ferramentas novas `pabx_status`, `pabx_filas`, `pabx_ramais`, `pabx_troncos` e `pabx_ixc_arquivos` aparecem na listagem MCP autenticada.\n- `pabx_ixc_arquivos` exige que o endpoint `ixc_files` esteja instalado na API Issabel, conforme PR #5 do `JRC-PABX`. O resultado lista somente metadados, nunca o conteudo dos scripts.
- Consultar `pabx_status` via ChatGPT/MCP e comparar com resultado do `curl` direto.
- Confirmar que nenhum segredo e retornado ou registrado em logs.
- Se nao aparecer no catalogo ChatGPT, atualizar/reconectar a integracao para redescoberta de ferramentas.

## Reversao

Voltar os arquivos alterados dos backups gerados no Passo 1 e remover o override da invocacao docker compose, reconstruindo apenas o `jrconect-mcp` com a configuracao anterior em janela controlada. Preservar os tokens e a API do Issabel para diagnostico.

## Painel ACS

O painel visual `assets/js/mcp-connections-panel.js` continua com os tipos local e external OAuth2 na branch-base. Cadastrar API REST no mesmo modal exige alteracao complementar do JS e de `api/mcp-instances.php`. O modulo aqui **faz o MCP consumir a API**, mas nao muda automaticamente as opcoes do modal.
