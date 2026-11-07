# PR #107 — Auditoria integral da telefonia JR Conect (somente leitura)

## Objetivo
Ampliar o piloto do contrato 4740 a **todos os contratos que possuem o produto 74 no IXC**, conferindo os marcadores das observações específicas de cada contrato e comparando com os vínculos do estoque VoiceCopy.

A gravação `PUT` no IXC **continua pendente de homologação**. Um piloto de leitura bem-sucedido não valida a API de escrita.

## Auditoria
- Consulta o snapshot completo de produto 74 (paginação e totais verificados pela integração já existente).
- Relê cada contrato individualmente para identificar marcador exato `#JRC_VOIP_SOLICITAR#`, quantidade de ocorrências e presença de `JRC_VOIP_CONTRATO=`.
- Não retorna observações, nomes de clientes, endereços ou credenciais; somente ID de contrato, IDs de produto e estado.
- Usa o estoque local sob lock de leitura e separa:
  * já vinculados,
  * pedidos com marcador e sem DID,
  * contratos sem pedido,
  * marcador em contrato já vinculado,
  * observação já com DID mas estoque sem vínculo,
  * marcador duplicado,
  * vínculos de estoque que não aparecem no produto 74,
  * divergências de registro do produto.
- Mostra quantidade real de DIDs livres, não distribui DIDs durante a auditoria.
- Não usa códigos brutos A/I como autorização para liberar linhas.

## Instalação no ACS após CI/merge (sem mexer no MCP)
```sh
set -e
set -o pipefail
REPO=/opt/jrconect-acs-git
STAGE=/root/voip-audit-107
git -C "$REPO" fetch https://github.com/junaojsp/JRCONECT-ACS-MCP.git main
mkdir -p "$STAGE"
for FILE in lib/voip-full-audit.php scripts/voip-full-audit-cli.php; do
  mkdir -p "$STAGE/$(dirname "$FILE")"
  git -C "$REPO" show "FETCH_HEAD:$FILE" > "$STAGE/$FILE"
  php -l "$STAGE/$FILE"
done
# Script CLI pode usar os arquivos em stage e ler o estoque real; sem instalar no webroot:
git -C "$REPO" show FETCH_HEAD:scripts/voip-full-audit-readonly.mjs |
  docker exec -i jrconect-mcp node --input-type=module |
  php "$STAGE/scripts/voip-full-audit-cli.php"
```

Nota: a leitura do arquivo local `scripts/voip-full-audit-cli.php` usa caminhos alternativos de bibliotecas: `voip-stock.php` instalado no webroot e `voip-full-audit.php` em `../lib` do stage.

O pipeline usa `set -o pipefail` e falha fechado diante de consulta parcial.

## Próxima etapa (separada)
Homologar o PUT de `cliente_contrato` em IXC, usando conta técnica de escrita com privilégio mínimo, prévia de alterações e proteção contra sobrescrever campos de cobrança/contrato. Só então atrelar o escritor ao motor de reservas atômicas, com flag desativada por padrão, auditoria de sucesso/erro e reconsulta do IXC. Não mudar o monitor atual de 15 minutos nesta PR.
