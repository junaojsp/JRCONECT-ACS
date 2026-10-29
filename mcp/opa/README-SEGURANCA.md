# Regras de implementacao de futuras ferramentas IA

1. Nada de conectar diretamente no banco do Opa nem interceptar sessao de navegador para descobrir APIs internas.
2. Ferramentas de leitura exigirao usuario Keycloak e allowlist de identidades, e controle por recurso/assistente.
3. Nunca retornar conteudo de conversas ou dados pessoais sem necessidade e autorizacao especificas.
4. Escopo inicial: Charlotte - Novo Formato; ambiente em implantacao, sem publicar alteracoes.
5. Mudancas futuras: gerar diff, fazer backup versionado, solicitar aprovacao explicita e registrar auditoria.
6. Credenciais somente no servidor ACS, com permissao minima; nunca no GitHub publico.
