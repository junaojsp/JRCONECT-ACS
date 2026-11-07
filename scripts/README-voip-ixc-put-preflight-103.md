# Etapa #103 — Preparação para escrita controlada no IXC

## Objetivo
Solicitação de telefone via `#JRC_VOIP_SOLICITAR#` no campo **obs_contrato** do contrato com produto **74**. No caso do ensaio, contrato IXC **4740** e linha de produto **571**.

## Descoberta documental importante
O IXC documenta o PUT em `/webservice/v1/cliente_contrato/{id}`, mas seu exemplo de edição envia **todos os campos do contrato**, mesmo os inalterados. **Não fazer PUT apenas com `obs_contrato`** e jamais atualizar contrato inteiro com dados presumidos ou não validados. A API atual do MCP foi deliberadamente implementada só para listar registros; não tem escrita autorizada.

Referência: https://api-provedor.ixcsoft.com.br/ — PUT Contratos (editar).

## Escopo desta PR
O diagnóstico `scripts/voip-ixc-put-preflight.mjs`:
- Confere novamente registro exato 4740, produto 74 registro 571 e marcador único no campo obs_contrato.
- Compara a estrutura devolvida pela consulta com os **nomes de campos principais** documentados no PUT.
- Retorna **apenas nomes de campos e resultados booleanos**, não observa valores pessoais, token, senhas ou texto livre.
- Não possui código de escrita; não pode alterar IXC ou estoque.
- Não reinicia ou modifica container MCP, job cron, API em produção, painéis ou PABX.

## Uso no ACS
Executar como root no ACS após merge. O código só roda por stdin; preserva rootfs read-only e não grava arquivos no container:
```sh
git -C /opt/jrconect-acs-git fetch https://github.com/junaojsp/JRCONECT-ACS-MCP.git main
git -C /opt/jrconect-acs-git show FETCH_HEAD:scripts/voip-ixc-put-preflight.mjs | docker exec -i jrconect-mcp node --input-type=module
```

## Gatilhos que faltam para ligar a automação
1. Verificar que a conta atual de consulta não é usada em escrita; obter credencial de escopo mínimo para `cliente_contrato` edit.
2. Confirmar comportamento de PUT em homologação ou com operador, incluindo campos necessários e preservação do restante do contrato, e estabelecer tratamento de escrita concorrente.
3. Confirmar **um número disponível** realmente livre na VoiceCopy e registrá-lo no painel com a ação supervisionada de disponibilidade. Nunca promover os 17 a_conferir automaticamente.
4. Preparar backup dos dados, executar teste com contrato 4740 primeiro, comparar observação antes e depois e testar reconciliação.
5. Integrar o `VoipAutoAssignment` preparado na PR #102 com writer aprovado, job independente e **flag desativada por padrão**.
6. Não tocar nos contratos com vínculo manual existentes, nem liberar DID automaticamente.

## Política da próxima etapa
Só considerar `ocupado` depois de leitura posterior do IXC confirmar vínculo exato. Erro ambíguo deixa DID reservado e gera revisão; sem número livre não executa PUT. A alteração das observações preservará todo texto anterior.
