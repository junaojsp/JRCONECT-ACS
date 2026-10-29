# Opa! IA — consulta via MCP: estado real

**Situacao: endpoints de assistentes, fluxos, instrucoes e bases de conhecimento ainda nao confirmados. Nenhuma ferramenta dessas quatro esta ativa no servidor MCP.**

## O que foi validado

- Login ChatGPT -> MCP (Keycloak OAuth) e `status_servidor` funcionando.
- GET autenticado de atendimentos em `/api/v1/atendimento` retorna HTTP 200.
- A ferramenta `opa_listar_atendimentos` foi exercitada em homologacao, com limite 1.
- A autenticacao Bearer do Opa **nao** permite consultar atendimentos na implementacao de homologacao atual.
- Nenhum desses testes comprova acesso ao editor de assistentes do Opa! IA.

## Documentacao verificada

- API publica oficial: https://api.opasuite.com.br/
- Artigo API Opa Suite: https://central-opasuite.ixcsoft.com.br/documentacao/api/api-opa%21-suite.html
- Configuracao de assistentes: https://central-opasuite.ixcsoft.com.br/documentacao/ambiente-de-ia/assistentes-personalizados/configurando-um-assistente-inteligente
- Fluxo de interacoes: https://central-opasuite.ixcsoft.com.br/documentacao/ambiente-de-ia/assistentes-personalizados/fluxo-de-interacoes

A documentacao publica confirma as areas de Perfil, Instrucoes, Fluxo de interacoes e Sub assistentes, e exportacao/importacao pelo painel. Nao foram encontrados endpoints publicos especificos de **leitura/edicao dessas configuracoes**. Nao inferir endpoints internos ou tokens/sessoes do navegador.

## Ferramentas planejadas (nao publicadas)

| Ferramenta | Dados pretendidos | Endpoint confirmado? |
| --- | --- | --- |
| opa_listar_assistentes | IDs editoriais, nome, estado | Nao |
| opa_consultar_fluxo | Estrutura de etapas e transicoes de um assistente | Nao |
| opa_consultar_instrucoes | Instrucoes por assistente autorizado | Nao |
| opa_consultar_base_conhecimento | Lista e conteudo dos artigos autorizados | Nao |

Ver contratos em `contratos-consulta-ia.json`. Todos iniciam com `enabled:false`.

## Bloqueadores e definicao de pronto

1. Solicitar ao suporte Opa Suite documento oficial/API com: metodo, URL, parametros, formato de resposta, escopos do grupo API, limites e semantica de rascunhos/publicacao para as 4 funcoes.
2. Confirmar se a API de administracao de Opa! IA e disponibilizada no contrato/plano da JR Conect. Se indisponivel, usar exportacao oficial da aba Perfil, **apenas apos revisao de conteudo e remocao de segredos**.
3. Implementar ferramentas somente com contratos de resposta **validados**, evitando expor chaves, mensagens ou dados pessoais.
4. Acrescentar testes automatizados (autorizado, nao autorizado, campos proibidos, falha de API, limite e rollback).
5. Homologar com a assistente **Charlotte - Novo Formato**, ainda em implantacao. Nao publicar mudancas nem tocar na Charlotte atual.

## Texto para suporte do Opa

> Olá! Estamos integrando o Opa! IA da JR Conect a um servidor MCP proprio. A API `/api/v1/atendimento` ja foi validada. Precisamos de documentacao oficial das APIs para **listar assistentes inteligentes, consultar fluxo de interacoes, consultar instrucoes e consultar bases de conhecimento** (e futuramente propor alteracoes com aprovacao). Poderiam informar endpoints, metodos HTTP, filtros, escopos/permissoes do usuario API, schema dos retornos e se e possivel acessar **rascunhos sem publicar**? Caso tais recursos nao estejam disponiveis na API publica, existe API de integracao suportada para esse gerenciamento? Nao precisamos acesso administrativo direto ao banco.
