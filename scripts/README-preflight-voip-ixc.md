# Homologação da consulta VoIP IXC no MCP já ativo

## Por que esta etapa é separada do deploy
A PR #88 adicionou a ferramenta `ixc_voip_consultar_contrato` ao repositório, mas o MCP em produção possui código, integrações e Compose não totalmente representados pelo diretório `mcp/` versionado. Um `docker compose up` ou troca de `server.js` pode derrubar Opa, PABX, Wiki e outros serviços.

Este procedimento **não instala nem habilita** a ferramenta. É a validação prévia para um deploy específico, preservando os módulos existentes.

## Depois do merge desta PR, executar uma única vez no SSH do ACS (somente leitura)

```bash
git -C /opt/jrconect-acs-git fetch https://github.com/junaojsp/JRCONECT-ACS-MCP.git main && \
git -C /opt/jrconect-acs-git show FETCH_HEAD:scripts/preflight-voip-ixc.sh > /tmp/jrc-preflight-voip-ixc.sh && \
bash -n /tmp/jrc-preflight-voip-ixc.sh && \
bash /tmp/jrc-preflight-voip-ixc.sh
```

Usa `fetch` por URL explícita e não modifica `origin`, `main` local, branch `feat/pabx-admin-gateway` ou arquivos modificados. Não consulta dados de assinantes nem lê conteúdo de tokens. O script só extrai **cópias temporárias** dos módulos versionados para checagem sintática e examina a presença de alguns marcadores de integração no MCP ativo.

## Resultado esperado

- `INSPECAO INICIAL CONCLUIDA`: os marcadores básicos estão presentes; **não é autorização de deploy**. Revisar manualmente escopo por identidade e autenticação no runtime.
- `PENDENTE`: não existe garantia de compatibilidade. Não instalar e não reiniciar nada.
- `BLOQUEADO`: fonte não validada, Node ou diretório não disponível. Nenhum arquivo do MCP é alterado.

## Critérios antes de uma futura PR de instalação

1. Identificar o verdadeiro processo/container de produção e a localização dos módulos carregados, sem divulgar segredo ou conteúdo integral de configuração.
2. Confirmar que `registerIxcWebserviceTools` só é registrado para usuários OAuth autorizados, usando `ixcWebserviceAuthorized`, `ixc-mcp-admin` e a allowlist já existente. Não expor ferramentas IXC ao cliente Opa.
3. Confirmar que os formatos de resposta `cliente_contrato` e `vd_contratos_produtos` suportam filtro combinado e paginação; usar contrato de homologação.
4. Testar consulta por ID sem dados pessoais e sem interpretar o status bruto. O resultado sempre traz `vinculo_autorizado: false`.
5. Desenvolver instalador isolado apenas após reconciliação real entre repositório e runtime, com backup, rollback e manutenção das integrações existentes.
6. Nunca fazer deploy geral do MCP a partir do `mcp/docker-compose.yml` versionado sem auditoria, pois há diferença declarada com a produção.

## Escopo desta PR

Somente adiciona script de pré-validação, documentação e verificação sintática em CI. Nada é instalado no ACS/MCP e nenhuma credencial é tocada.
