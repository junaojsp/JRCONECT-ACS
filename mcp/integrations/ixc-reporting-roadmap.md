# Roadmap — Central de Consultas e Relatorios IXC / JR Conect MCPv2

Objetivo: organizar consultas somente leitura em seis dominios operacionais. O codigo deste branch e preparatorio e nao deve ser implantado sem validacao dos endpoints, esquemas, permissoes e filtros no IXC da JR Conect.

## 1. Suporte e Ordens de Servico
- OS por status (aberta, em execucao e finalizada), assunto, equipe, tecnico, cidade e periodo.
- OS atrasadas e reincidentes; produtividade de atendimento.
- Foco inicial: assunto Suporte + status Aberto/Executando, excluindo finalizadas.
- Fontes candidatas: su_oss_chamado, su_oss_assunto, su_ticket (testar disponibilidade).
- Validar ids locais de assunto e codigos de status antes do resultado.

## 2. Clientes e Contratos
- Totais por situacao, plano, cidade, periodo e motivo de cancelamento.
- Auditoria de clientes sem contrato, contratos sem plano, e divergencias de situacao.
- Fontes candidatas: cliente, cliente_contrato, vd_contratos.
- Diferenciar cadastro, contrato e plano de venda.

## 3. Produtos e Servicos
- Planos vendidos, servicos adicionais, descontos e produtos por contrato.
- Detectar produtos duplicados, adicionais sem vinculo e divergencias de precificacao.
- Fontes candidatas: produtos, vd_contratos_produtos, cliente_contrato_servicos e cliente_contrato_descontos; validar nomes.

## 4. Financeiro
- Titulos a vencer, vencidos, recebidos e cancelados; visoes por faixa de atraso.
- Cruzamentos autorizados para detectar contratos ativos sem faturamento e cobrancas inconsistentes.
- Fonte candidata: fn_areceber; validar semantica de status e datas.
- Nao retornar documentos, dados bancarios nem codigos de pagamento em diagnosticos gerais.

## 5. Rede e Autenticacao
- Totais de logins PPPoE por situacao e vinculo contratual; anomalias de cadastro.
- Fontes candidatas: radusuarios e contratos; validar permissao e campos.
- Dados de disponibilidade em tempo real podem depender do ACS/RADIUS, nao so do IXC.

## 6. Estoque e Equipamentos
- Saldos de produtos, movimentacoes, alocacoes e possiveis divergencias.
- Fontes candidatas: produtos e view_prod_estoque_almox; confirmar disponibilidade.
- Auditoria por almoxarifado, periodo e contrato somente apos validar esquema.

## Motor de relatorios
- GET com paginacao limitada, filtros allowlisted e grid_param quando validado.
- Contagens e agrupamentos primeiro; consultas detalhadas exclusivamente com identidade, finalidade e permissao adequadas.
- Separar filtros por status, assunto, periodo, cidade, tecnico e plano.
- Exportacao CSV/XLSX/PDF deve ocorrer em fluxo de relatorio autorizado, com limites de volume e sem expor dados pessoais em logs.
- Resultados com fonte, data de referencia, filtros aplicados e indicadores de cobertura.
- Usar calculos reconciliados, evitando assumir que contagens parciais equivalem ao total.
- Auditoria de acessos, limites de requisicao, timeout e testes de regressao.

## Checklist anterior ao deploy
1. Comparar o modulo IXC atual no ACS com o repositório e preservar o server.js de producao.
2. Conferir o endpoint e os nomes dos campos diretamente na documentacao/instalacao IXC.
3. Validar o encoding de grid_param e operadores aceitos.
4. Verificar os codigos de status, ids do assunto Suporte e recursos disponiveis.
5. Testar cada rota somente leitura, sem dados pessoais nos resultados exploratorios.
6. Rever controles do Keycloak e as regras de acesso por ferramenta.
7. Implantar exclusivamente a integracao IXC com backup, teste e rollback definidos.

Documentacao:
- https://api-provedor.ixcsoft.com.br/
- https://wikiapiprovedor.ixcsoft.com.br/
- https://wiki-erp.ixcsoft.com.br/documentacao/guias-tutoriais/estoque-e-ordem-de-servico/relatorios-e-auditorias.html
- https://wiki-erp.ixcsoft.com.br/documentacao/menu-relatorios/relatorios-provedor/relatorios-contratos/relatorios-contratos/formulario-relatorio-de-contratos

Status: escopo definido; endpoints e filtros adicionais ainda NAO homologados.
