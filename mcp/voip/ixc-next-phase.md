# Central VoIP - proxima fase: integracao IXC somente leitura

## Estado apos a melhoria visual

O estoque atual do ACS informa 30 numeros, dos quais 13 foram marcados como `ocupado` e 17 como `a_conferir`. **Nenhum dos 17 foi confirmado como disponivel.**

- `ocupado` => vermelho; telefone comprometido, mesmo que contrato IXC nao vinculado.
- `a_conferir` => amarelo; numero bloqueado para atribuicao ate revisao.
- `disponivel` => verde; estado futuro, **somente apos verificacao** com VoiceCopy e liberacao autorizada. O importador atual intencionalmente nao aceita `disponivel`.
- Nao classificar automaticamente `a_conferir` como `disponivel`.
- Qualquer mudanca de status devera ter permissao, validacao, auditoria e trava de concorrencia.

## Contrato de dados para a futura integracao

Consulta IXC deve retornar **linhas individuais**, nao apenas contagem:

- `id` do registro de `vd_contratos_produtos` (chave de vinculo).
- `id_produto = 74`.
- `id_contrato`.
- Dados minimos do contrato `cliente_contrato`: `id`, `status`, identificador do cliente para exibicao autenticada, se autorizado.

Validar o schema e os codigos de status reais; a biblioteca `mcp/voip/reconciliation.js` usa estados normalizados como `active`, nao os codigos IXC de origem.

## Etapas propostas sem interferir nos serviços

1. **PR de consulta:** novo modulo de leitura isolado no MCPv2, com paginação, whitelists, resposta validada e falha fechada; sem substituir consultas existentes.
2. **PR de persistencia:** vinculo manual validado por ID do contrato + ID do produto contratado e DID; sem gravar no IXC; prevencao de duplicidade e historico de alteracoes.
3. **PR de tela:** adicionar selecao de contrato e numero, com verificacao manual de titular, produto 74 e status.
4. **PR de monitoramento:** reconciliacao de snapshots completos periodicos, alerta unico por ID de produto, cancelamentos encaminhados a revisao. Liberação de DID somente após baixa confirmada na VoiceCopy.

## Cuidados com segurança

- Nao exibir nem versionar cadastro pessoal de titulares em GitHub publico.
- Nao modificar `mcp/server.js`, autenticacao, integrações existentes, banco IXC ou PABX nesta etapa.
- Nao iniciar cron nem automatizar alteracoes em contratos.
- Falha ou resposta parcial do IXC nunca deve liberar numero ou apagar vinculo.
- Os atuais 13 registros agregados do produto 74 nao necessariamente representam 13 contratos distintos; nao inferir vinculos por nome.
