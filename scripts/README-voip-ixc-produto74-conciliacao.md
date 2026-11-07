# Central VoIP — consulta consolidada do produto 74 (PR de preparacao)

## Entrega

Nova ferramenta MCP `ixc_voip_listar_contratos_produto74`, registrada no mesmo modulo e sob **as mesmas permissoes OAuth IXC** de `ixc_voip_consultar_contrato`.

- Consulta todas as associacoes ao produto **74** (paginacao verificada, limite 200).
- Consulta cada contrato unico por ID para obter `status_ixc_bruto` e `id_cliente`.
- Quando o contrato expoe `id_cliente`, busca a linha do cliente e retorna **somente** `razao` ou `nome`, sem CPF, endereco ou contato.
- Distingue `total_registros_produto` de `total_contratos` (podem diferir).
- Se falta o campo do nome, retorna `nome_cliente: null` e `titular_validado: false`. Se uma pagina estiver incompleta ou a consulta divergir, **falha sem produzir snapshot parcial**.
- `status_validado: false`: o significado do codigo `A` e dos demais precisa ser confirmado formalmente.
- **Nao grava**, **nao associa** numero, **nao altera** `stock.json`, **nao libera** DIDs.

## Validacoes reais pendentes

1. Verificar em ambiente IXC autorizado se `cliente_contrato` retorna `id_cliente` e se a consulta ao recurso `cliente` aceita filtro de ID. Verificar se o campo de nome e `razao` ou `nome`; se divergir, ajustar com teste, nunca inventar valor.
2. Conferir todos os 13 registros do produto 74 e quantos contratos unicos realmente representam.
3. Comparar com os 13 numeros marcados ocupados no estoque VoiceCopy, usando **match exato e unico de titular apenas como sugestao**; nomes repetidos, abreviados ou ausentes exigem revisao humana.
4. Confirmar codigo de status em documentacao/IXC antes de tomar decisoes operacionais.
5. Construir PR separada para vista de conciliacao e persistencia de vinculos, com CSRF, auditoria e confirmacao do operador. **Nunca tratar os 17 `a_conferir` como `disponivel` sem baixa VoiceCopy confirmada**.

## Implantacao segura

O MCP em producao roda dentro de imagem Docker e nao le novos arquivos do host automaticamente. Fazer merge **nao ativa a ferramenta**.

Esta PR altera somente `mcp/integrations/ixc-voip-tools.js` e adiciona testes. Nao substituir todo o `mcp/` do servidor: o container real possui integracoes adicionais. Para instalar, primeiro comparar o arquivo de integracao VoIP do repositório com o do host; preparar backup, construir uma imagem candidata a partir do Dockerfile e Compose reais do host, executar smoke sem rede, revisar e so depois considerar ativacao controlada separadamente. Manter o resto do MCP intacto.

Testes do modulo: no checkout `mcp` com dependencias existentes, executar `node --test test/ixc-voip-list-contracts.test.js`.

## Privacidade

Nomes de cliente sao dados pessoais; a nova consulta e restrita ao escopo de autenticacao do IXC. Nao colocar payloads com nomes em logs, URLs, exemplos, arquivos de teste de producao nem exportacoes publicas.
