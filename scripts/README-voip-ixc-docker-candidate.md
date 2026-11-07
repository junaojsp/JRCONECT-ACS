# Central VoIP — PR #91: imagem candidata e ativação controlada do IXC

## Escopo

A PR #88 criou a ferramenta `ixc_voip_consultar_contrato`; a PR #90 criou o instalador `--plan/--apply/--rollback` para adicionar a ferramenta **ao host**. O MCP real executa dentro da imagem `jrconect-mcp-jrconect-mcp`, com diretório `/app`; portanto a etapa Docker é indispensável.

**Composição REAL verificada no container da JR Conect:**
- `docker-compose.yml`
- `docker-compose.opa.yml`
- `docker-compose.ixc.yml`
- `docker-compose.pabx-central.yml`

A pasta `/opt/jrconect-mcp` contém integrações adicionais não reproduzidas pelo GitHub. O Dockerfile REAL possui `COPY integrations/ /app/integrations/`. **Não copiar o `mcp/Dockerfile` ou `mcp/server.js` do GitHub para produção.**

## 1. Após merge: preparar scripts pelo Git, sem alterar origin/branch

```bash
REPO=/opt/jrconect-acs-git
STAGE=/root/voip-ixc-stage
git -C "$REPO" fetch https://github.com/junaojsp/JRCONECT-ACS-MCP.git main
mkdir -p "$STAGE"
git -C "$REPO" show FETCH_HEAD:scripts/build-voip-ixc-candidate.sh > "$STAGE/build-candidate.sh"
git -C "$REPO" show FETCH_HEAD:scripts/promote-voip-ixc-candidate.sh > "$STAGE/promote-candidate.sh"
bash -n "$STAGE/build-candidate.sh"
bash -n "$STAGE/promote-candidate.sh"
```

## 2. Instalar apenas os arquivos da ferramenta no host

Se a PR #90 ainda não foi aplicada, validar e depois aplicar **uma única vez**, com a aprovação operacional:

```bash
node /root/voip-ixc-stage/install.mjs --plan /root/voip-ixc-stage/ixc-voip-tools.js
VOIP_IXC_APPROVE_APPLY=YES node /root/voip-ixc-stage/install.mjs --apply /root/voip-ixc-stage/ixc-voip-tools.js
```

Guardar o nome do backup impresso. O instalador modifica apenas `integrations/ixc-webservice-tools.js` e cria `integrations/ixc-voip-tools.js`. Não modificar os arquivos de servidores ou credenciais.

## 3. Construir imagem CANDIDATA sem parar o MCP

```bash
bash /root/voip-ixc-stage/build-candidate.sh --plan
VOIP_IXC_APPROVE_BUILD=YES bash /root/voip-ixc-stage/build-candidate.sh --build
```

O build usa o Dockerfile do host e **todos os módulos atuais**, não substitui a tag de produção e não recria containers. Smoke tests `node --check` e registro de `ixc_voip_consultar_contrato` ocorrem no container efêmero com `--network none` e **sem credenciais**.

Anotar o nome exato da candidata informado por `CANDIDATA VALIDADA`, por exemplo `jrconect-mcp:voip-ixc-candidate-AAAAMMDDTHHMMSSZ`.

## 4. Conferência antes da troca

Antes de ativar, verificar os testes em GitHub Actions, revisar o escopo OAuth real e assegurar que a candidata foi validada. A consulta de produto 74 ainda não foi testada com dados reais IXC; esse teste só ocorrerá após ativação controlada. Planejar janela de manutenção para possível interrupção breve do MCP.

```bash
bash /root/voip-ixc-stage/promote-candidate.sh --plan TAG_EXATA_DA_CANDIDATA
```

O `--plan` consulta o ID da imagem ativa, confirma a candidata e valida a configuração combinada dos quatro Compose. Não altera nada.

## 5. Ativação SOMENTE depois de aprovação específica

```bash
VOIP_IXC_APPROVE_ACTIVATION=YES bash /root/voip-ixc-stage/promote-candidate.sh --activate TAG_EXATA_DA_CANDIDATA
```

A ativação preserva a imagem antiga como `jrconect-mcp:pre-voip-ixc-...`, etiqueta a candidata sob a tag atual e recria **somente** o serviço `jrconect-mcp` utilizando `--no-deps --no-build --force-recreate` e exatamente os quatro Compose. Valida se o container iniciou com o ID esperado e `/health` responde em até aproximadamente 16 segundos. Se não passar, tenta automaticamente retornar à imagem anterior.

**Limitações:** a troca provoca indisponibilidade breve; `/health` não garante que todas as integrações estejam saudáveis. Interrupção abrupta durante o processo pode exigir rollback manual. Não executar em horário crítico sem janela de mudança.

## 6. Verificação da consulta e exposição no ChatGPT

Após a ativação bem-sucedida:

- Testar ferramentas existentes (`status_servidor`, IXC, Opa, PABX) com usuários autorizados.
- Atualizar ferramentas da integração ChatGPT/MCP apenas agora.
- Confirmar que `ixc_voip_consultar_contrato` aparece **somente para o usuário autorizado** (`ixc-mcp-admin` + allowlist) e não para o token do Opa.
- Consultar contrato de teste com produto 74 e contrato sem produto 74; validar filtros, paginação e o status bruto sem dados pessoais.
- Não registrar vínculo nem liberar DID nesta etapa.

## 7. Rollback da imagem (se os testes funcionais falharem)

Anotar `IMAGEM DE ROLLBACK` mostrada pela ativação e, com autorização operacional, executar:

```bash
cd /opt/jrconect-mcp
docker image tag NOME_EXATO_DA_IMAGEM_DE_ROLLBACK jrconect-mcp-jrconect-mcp
docker compose -f docker-compose.yml -f docker-compose.opa.yml -f docker-compose.ixc.yml -f docker-compose.pabx-central.yml up -d --no-deps --no-build --force-recreate jrconect-mcp
curl -fsS http://127.0.0.1:3100/health
```

Rollback dos arquivos do host, se necessário, usa o `--rollback` da PR #90 com o caminho de backup específico. Este rollback não altera contratos ou números.

**Não modificar os outros Compose do PABX/admin/rest, não executar `docker compose down`, não fazer deploy da pasta MCP inteira do GitHub e não divulgar tokens.**
