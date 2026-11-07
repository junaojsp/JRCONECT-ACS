# PR #101 — Validação segura do marcador de solicitação VoIP no IXC

## Objetivo
O vendedor informa `#JRC_VOIP_SOLICITAR#` em uma observação no IXC. O objetivo final é reservar um número confirmado como disponível e substituir apenas o marcador pelo texto estruturado `JRC_VOIP_CONTRATO=<contrato>;DID=<telefone>`.

**Esta PR é a FASE 1 SOMENTE LEITURA.** Ela **não faz essa escrita**, não reserva DID, não altera `stock.json` e não modifica nenhum contrato. É necessária porque o usuário mostrou um campo de **Observações do cliente possivelmente compartilhado com contratos**. Não é seguro assumir o nome do campo nem seu escopo sem consultar o resultado real.

## Diagnóstico
- Inspeciona somente contratos que já têm produto 74, utilizando cliente IXC e segredo Basic existentes no MCP.
- Busca as chaves *candidatas* `obs_contrato`, `observacao`, `observacoes`, `obs` no contrato e `obs`, `observacao`, `observacoes` no cliente.
- Procura o marcador exato, preservando texto original sem registrar ou imprimir suas observações.
- Resultado traz apenas ID do contrato, nomes de campos presentes e se o marcador foi encontrado em cliente ou contrato; classificação de marcador no cliente exige desambiguação porque pode haver vários contratos.
- Se uma página falhar ou responder incongruentemente, aborta; nenhuma reserva, gravação ou atualização do monitor.
- Não altera o cron de 15 minutos implantado anteriormente.

## Como executar diagnóstico isolado APÓS CI E MERGE, sob decisão operacional
Não baixar/substituir o código do MCP em funcionamento. Copiar arquivos da branch já revisada para o host ACS, preservando-os fora do webroot, e depois executar no container apenas de forma temporária:

```sh
docker cp /root/voip-marker-stage/ixc-voip-request-inspector.js jrconect-mcp:/tmp/jrc-voip-request-inspector.mjs
docker cp /root/voip-marker-stage/voip-request-marker-preflight.mjs jrconect-mcp:/tmp/jrc-voip-request-preflight.mjs
docker exec jrconect-mcp node /tmp/jrc-voip-request-preflight.mjs
```

Os imports do preflight usam os módulos já instalados em `/app/integrations` e o inspector temporário em `/tmp`. Conferir que todos os arquivos temporários são legíveis pelo usuário `node`. Comandos de limpeza opcionais, depois do diagnóstico:
```sh
docker exec -u root jrconect-mcp rm -f /tmp/jrc-voip-request-inspector.mjs /tmp/jrc-voip-request-preflight.mjs
```

Não há restart ou rebuild do container, porém `docker cp` grava **apenas dois arquivos temporários** em `/tmp`. Não há chamada de atualização ao IXC nem edição do painel.

## Próxima PR (após validar os campos com resultados reais)

1. Confirmar se a observação pertence ao registro `cliente` ou `cliente_contrato` e se a leitura é possível para a conta técnica.
2. Para observação de cliente compartilhada, aceitar somente **um contrato inequivocamente elegível** (produto 74 e regras de status formalmente validadas); caso contrário, revisão humana.
3. Implementar reserva transacional de `disponivel` com idempotência, sem escolher `a_conferir`, `ocupado` ou DID já vinculado.
4. Introduzir **conta de escrita separada, mínima e autorizada**, com API testada: atualização condicional das observações sem perder texto e sem afetar outros campos. Confirmar a resposta por nova consulta antes de ocupar definitivamente.
5. Falhas e timeouts mantêm reserva em estado de revisão, não reaproveitam DID, não finalizam operação automaticamente.
6. Exigir teste autorizado em contrato de homologação, dry-run, auditoria, reversão e ativação independente.

> A PR #101 não altera o catálogo do ChatGPT, MCP em produção, daemon cron, IXC, PABX, Opa, estoque ou números já atribuídos.
