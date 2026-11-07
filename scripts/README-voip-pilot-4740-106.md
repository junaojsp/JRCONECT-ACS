# Central VoIP — Piloto seguro do contrato 4740 (PR #106)

A entrega dos 17 DIDs novos foi confirmada na VoiceCopy e a Central VoIP passou a mostrar 13 ocupados, 17 disponíveis e zero a conferir. Contrato piloto **4740** possui o produto 74, registro de produto **571**, e a observação com `#JRC_VOIP_SOLICITAR#`.

## Por que a primeira execução é somente leitura
O IXC documenta `PUT /webservice/v1/cliente_contrato/{id}`, mas alerta que atualizar um registro exige reenviar todos os campos, mesmo aqueles não modificados. Isso foi confirmado no preflight #103 (47 campos de contrato obtidos), mas **a aceitação de um PUT pela instalação da JR Conect e o controle de concorrência ainda não foram homologados**. A credencial atual do MCP deve continuar só para consulta. Não enviaremos um PUT especulativo que possa modificar plano, cobrança, endereço, assinatura, status ou a observação de outra pessoa.

## O que esta PR faz
- Consulta **somente o contrato 4740 e o registro de produto 571** pela credencial de leitura existente no MCP. Retorna um resumo sanitizado sem observações e sem dados pessoais.
- Lê `/var/lib/jrconect-voip/stock.json` através do módulo já instalado (lock compartilhado), procura o primeiro DID `disponivel` sem vínculo ou reserva e monta **somente uma prévia** de `JRC_VOIP_CONTRATO=4740;DID=...`.
- Não grava em IXC, `stock.json`, não reserva número e não altera o cron/containers.
- Se o produto ou marcador não for encontrado, falha sem modificações; se o contrato já estiver vinculado ou reservado, bloqueia nova atribuição.
- A prévia é temporária: se o estoque mudar antes da escrita real, o DID deverá ser escolhido novamente sob lock.

## Execução no ACS após CI + merge
O MCP possui rootfs somente leitura. Por isso **não copiar arquivos para o container**.

Executar como root no ACS:

```sh
set -e
git -C /opt/jrconect-acs-git fetch https://github.com/junaojsp/JRCONECT-ACS-MCP.git main
mkdir -p /root/voip-pilot-106
git -C /opt/jrconect-acs-git show FETCH_HEAD:scripts/voip-pilot-4740-preview.php > /root/voip-pilot-106/preview.php
php -l /root/voip-pilot-106/preview.php
git -C /opt/jrconect-acs-git show FETCH_HEAD:scripts/voip-pilot-4740-ixc-readonly.mjs |
  docker exec -i jrconect-mcp node --input-type=module |
  php /root/voip-pilot-106/preview.php
```

Essa execução não aciona `VoipAutoAssignment::execute`. O DID apresentado é apenas uma sugestão segura.

## Antes do teste real de PUT e vínculo
1. Obter credencial IXC de edição **separada, com escopo apenas de contrato/observação**, se a plataforma suportar; guardar fora do repositório e sem divulgá-la no chat.
2. Homologar o payload completo de `cliente_contrato` com backup fiel, conferência de campos permitidos e proteção de concorrência; nenhuma atualização parcial especulativa.
3. Preparar execução **limitada ao contrato 4740**, com aprovação explícita na janela do teste, registro de auditoria, reconsulta imediata e reserva atômica do DID.
4. Se a gravação falhar ou o retorno for ambíguo, a reserva deve continuar bloqueada para revisão. Nunca liberar automaticamente.
5. Somente após o piloto bem-sucedido considerar ativação do monitor de 15 minutos para novos contratos, por flag independente.

A distribuição automática **ainda não está habilitada** por esta PR. Os 13 vínculos existentes permanecem intocados.
