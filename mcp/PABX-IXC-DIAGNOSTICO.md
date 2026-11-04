# PABX / IXC - diagnostico seguro

A ferramenta MCP `pabx_ixc_testar_api` faz **somente um GET** a um endpoint de diagnostico privado, preconfigurado no ambiente do MCP. Nao recebe CPF, URL ou token como argumento. Nao le nem devolve corpo da resposta.

## Configuracao (somente depois de revisar a instalacao de producao)

- `PABX_IXC_DIAGNOSTIC_URL`: URL HTTPS fixa de um endpoint privado de diagnostico do PABX, sem query string.
- `PABX_IXC_DIAGNOSTIC_TOKEN`: segredo de acesso ao endpoint, armazenado fora do Git, preferencialmente em secret mount.
- O endpoint remoto **ainda precisa existir**. Deve testar a API IXC do proprio PABX, sem CPF real, sem alterar cadastros e sem registrar credenciais.
- Nao expor o endpoint na Internet sem controles de rede, autenticacao e rate limit.
- A ferramenta retorna status HTTP do **endpoint de diagnostico**, nao necessariamente da API IXC. Para distinguir os dois, sera necessario definir um contrato seguro de resposta e implantar o agente PABX.

## Seguranca e implantacao

A versao deste repositorio nao foi comprovada como equivalente ao MCP atualmente em producao, que ja possui outras ferramentas PABX. **Nao substituir `/opt/jrconect-mcp` pelo codigo deste PR sem comparar os arquivos e preservar as ferramentas existentes.** Nao executar deploy automatico.

Executar `npm run check` na pasta `mcp` antes de integrar. Testes sao isolados e nao acessam o PABX. O Dockerfile inclui o novo modulo. Se o endpoint nao estiver configurado, a ferramenta retorna `nao_configurado`.

Este PR prepara a integracao e nao corrige, por si so, a consulta real de CPF da URA.
