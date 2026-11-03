# Integração PABX Issabel 5 via MCP principal (somente leitura)

## Escopo

Adiciona ferramenta `pabx_status` ao servidor MCP **existente**, sem criar outra instância nem novo endpoint. Por padrão ela não é registrada; só é disponibilizada quando `PABX_ENABLED=true` e os arquivos SSH estão montados. A rotina consulta **versão, uptime e contagem de canais** através do comando forçado `status` no Issabel.

O MCP não utiliza AMI (5038), não abre portas novas no PABX e não permite editar ramais, chamadas, URA ou Phonevox.

## Dependências

- Repositório `junaojsp/JRC-PABX`, branch `feature/mcp-readonly-bridge`, presente em `/opt/JRC-PABX` no Issabel.
- PABX em `138.204.112.27` com SSH administrativo previamente acessível. Confirme porta 22.
- **Confirmar o endereço real de origem** do servidor ACS (antes era citado `138.204.112.25`, não validado nesta etapa).
- Backup/snapshot do Issabel e cópia da configuração do MCP existente em `/opt/jrconect-mcp`.
- Comparar os arquivos ativos do MCP com os arquivos versionados; a base Git não é considerada espelho auditado da produção.

## Etapa A: preparar par de chaves no host ACS (não no container)

Execute no host do MCP:

```sh
ip -4 route get 138.204.112.27
umask 077
mkdir -p /opt/jrconect-mcp/pabx-secrets
ssh-keygen -t ed25519 -f /opt/jrconect-mcp/pabx-secrets/id_ed25519 -N '' -C 'jrconect-mcp-pabx'
cat /opt/jrconect-mcp/pabx-secrets/id_ed25519.pub
```

Nao reutilizar a Deploy Key do GitHub. Chave privada jamais no GitHub, chat ou logs. Quando usar container executando como `node` (UID 1000), assegurar que a chave **seja legível somente por esse UID e pelo root** no host (por exemplo, `chown 1000:1000` e `chmod 0600` em um ambiente com mapeamento de UID confirmado). Arquivo `known_hosts` igualmente legível ao container.

## Etapa B: instalação PABX — revisão manual

Transferir **apenas** a chave pública ao Issabel por canal administrativo seguro. Confirmar a origem IP de fato utilizada do ACS. Revisar instruções em `/opt/JRC-PABX/mcp/IMPLANTACAO.md` e instalador antes de executar:

```sh
cd /opt/JRC-PABX
sudo sh mcp/install-pabx-bridge.sh /root/jrc_mcp_pabx.pub IP_DE_ORIGEM_REAL
```

O script cria usuário `jrc-mcp`, instala helper e `sudoers` restrito, associa chave SSH a comando forçado e IP. Ele **não altera automaticamente o sshd/firewall**, que devem ser revisados com acesso de console disponível. Importante: `jrc-mcp` é uma conta sem senha; confirme que o SSH do sistema permite login por chave para ela. Validar `sshd -t` e a restrição de origem. Não disponibilizar AMI 5038.

## Etapa C: host key e SSH

Obter a fingerprint da host key do Issabel diretamente por console/canal confiável; conferir com a chave apresentada pela conexão. Só então construir arquivo dedicado `/opt/jrconect-mcp/pabx-secrets/known_hosts` com o nome/IP e host key verificadas. **Nao usar** `StrictHostKeyChecking=no`.

Exemplo de teste realizado do host MCP, ajustando caminhos reais:

```sh
ssh -i /opt/jrconect-mcp/pabx-secrets/id_ed25519 \
    -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile=/opt/jrconect-mcp/pabx-secrets/known_hosts \
    jrc-mcp@138.204.112.27 status
```

A saída deve conter três blocos: Asterisk version, Asterisk uptime, Active channel count. Qualquer comando diferente de `status` deve falhar.

## Etapa D: MCP Docker (apenas depois do teste SSH)

No host do MCP, verificar as diferenças entre Git e `/opt/jrconect-mcp`, fazer backup e aplicar os arquivos novos **somente após revisão**. O override é opcional e não altera o compose padrão:

```sh
export PABX_SSH_PRIVATE_KEY_PATH=/opt/jrconect-mcp/pabx-secrets/id_ed25519
export PABX_SSH_KNOWN_HOSTS_PATH=/opt/jrconect-mcp/pabx-secrets/known_hosts
cd /opt/jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.pabx.yml config --quiet
```

Depois de conferir as diferenças de configuração, construir e subir na janela controlada:

```sh
docker compose -f docker-compose.yml -f docker-compose.pabx.yml up -d --build jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.pabx.yml ps
curl -fsS http://127.0.0.1:3100/health
```

Verificar o registro de `pabx_status` pelo cliente MCP autenticado, mantendo as outras ferramentas e o comportamento de autenticação existentes. Não imprimir tokens nem senhas. Caso não apareça no ChatGPT, atualizar a conexão/catálogo de ferramentas.

## Limites e reversão

O painel ACS não passa a mostrar automaticamente um card de PABX: isso requer alteração independente na UI. Desativar a função removendo o override e recriando o serviço com compose original, mantendo o backup; no Issabel, remover a chave do usuario de serviço para revogar imediatamente acesso. Não apagar nem substituir configurações de produção sem conferir diferenças.

**Status:** implementação versionada em branch; SSH ponta a ponta, build do Docker e deploy de produção ainda não executados.
