# JRC-ACS 2.0 — contrato de escopo visual

Esta branch parte de `main` e destina-se **somente ao layout** do ACS existente.

## Regras obrigatórias
- Manter rotas, autenticação, sessões, permissões, parâmetros, IDs e nomes de formulários.
- Manter integrações GenieACS, IXC, APIs, consultas, comandos TR-069, banco de dados e regras de negócio inalterados.
- Preservar todos os eventos e funcionalidades existentes em dashboard.php, devices.php, device-detail.php e demais telas.
- Preferir CSS isolado e ajustes puramente semânticos de apresentação; não reescrever telas funcionais como telas de prévia.
- Não usar dashboard-noc.php, devices-noc.php ou device-noc.php como substitutos funcionais.
- Não instalar nem alterar arquivos do servidor de produção automaticamente.
- Não realizar merge sem testes comparativos e aprovação explícita.

## Homologação
1. Inventariar as telas e estados reais da `main`.
2. Criar CSS isolado para a identidade visual aprovada, sem sobrescrever regras operacionais.
3. Verificar cada tela com navegação, formulários, modais, tabelas e botões.
4. Validar fluxos originais: Wi-Fi, WAN, ações TR-069, detalhes de ONU, busca, filtros e testes.
5. Comparar antes/depois; comportamento deve ser idêntico, apenas aparência diferente.
6. Liberar em homologação e só então planejar produção com backup e rollback.

**Qualquer mudança funcional é fora de escopo.**
