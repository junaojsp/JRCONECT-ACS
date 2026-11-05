# SHIELD MCP: implantação em etapas

O código principal no GitHub está defasado em relação ao ACS em produção. **Não executar docker compose up --build a partir do checkout atual.**

Arquivos novos: `shield-session-client.js` e `shield-tools.js`.

O cliente lê `/run/secrets/shield-env` (formato `SHIELD_USERNAME=...` e `SHIELD_PASSWORD=...`), autentica via POST `/auth/login`, utiliza cookie `jrcshield_session` e expõe somente GET das rotas `flow-status`, `history/summary`, `policies` e `mitigations`.

No ACS o arquivo `/opt/jrconect-mcp/secrets/shield.env` já foi criado pelo administrador. O container roda como usuário `node` e o arquivo tem permissão 0600 root:root; **montá-lo assim não permite leitura ao processo node**. Corrigir por permissão mínima com cópia dedicada para UID 1000, sem divulgar a senha, antes de ativar.

Para integrar ao servidor ativo, importar `createShieldClient` e `registerShieldTools` no `server.js` da versão em produção e chamar `registerShieldTools(mcp, createShieldClient())` dentro da factory MCP. Adicionar bind mount do arquivo protegido no compose existente, preservando todos os volumes atuais de PABX, Wiki e Opa. Validar autenticação real do endpoint antes de ativar. Não versionar credenciais.

**Segurança:** a conta usada atualmente é administrativa. O cliente oferece somente métodos GET, mas a credencial ainda possui privilégios elevados. Preferir usuário `viewer` dedicado posteriormente.
