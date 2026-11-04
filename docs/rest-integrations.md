# Integrações REST no ACS — primeira conexão: PABX

## Situação

Esta branch adiciona a página `/rest-integrations.php` (somente admin) e o atalho "Integrações REST" no layout **versionado** do ACS. O formulário existente "Adicionar instância MCP" mostrado no servidor de produção **não está presente no repositório** e portanto não foi modificado. A nova página é separada para evitar sobrescrever código de produção não versionado.

A página cadastra metadados **sem token**, permite testar o endpoint fixo do PABX em backend e mantém opção de conexão MCP local/OAuth atual intacta.

## Configuração do host ACS

- URL PABX: `https://pabx.jrconect.com/api/mcp/index.php` (fixa, sem URL arbitrária para evitar SSRF)
- Tipo: `API REST HTTPS — Bearer`
- IP do ACS autorizado no PABX: `138.204.112.25`
- Somente token de **leitura** para teste no ACS. Não habilitar token admin no formulário.
- Arquivo de registro, fora do webroot: `/etc/jrconect-acs/rest-integrations.json`.
- Token, fora do webroot: `/etc/jrconect-acs/pabx-read.token`.

A página pressupõe a função `requireLogin()`, `$_SESSION['role'] === 'admin'` e o layout presentes no ACS versionado. Antes de copiar para produção, comparar com os arquivos atuais.

Criar diretório de configuração privado com propriedade/grupo adequados ao serviço PHP **após verificar o usuário real do Apache/PHP**. Apenas o processo PHP do ACS deve poder gravar `rest-integrations.json`. Exemplo conceitual (substituir conta, grupo e permissões conforme servidor):

```sh
install -d -m 0750 /etc/jrconect-acs
# Provisionar token SOMENTE pelo terminal seguro do ACS; nao por browser, Git ou chat.
# Salvá-lo em /etc/jrconect-acs/pabx-read.token com 64 hex e acesso restrito ao usuario PHP.
```

O botão **Salvar conexão** registra somente nome, tipo, URL fixa e status `cadastrado`. **O botão Testar API** envia GET com Bearer pelo backend PHP com validação TLS, sem seguir redirects, e exige JSON `ok=true` / `action=status`. O token não é impresso ou retornado ao navegador.

## Ativação e integração MCP

1. Confirmar a API realmente instalada no Issabel e certificado válido.
2. Fazer backup dos arquivos ACS atuais; comparar `views/layouts/header.php` com o deploy de produção, pois a tela MCP atual não está versionada.
3. Revisar o código PHP, sessão, permissão admin, CSRF e configurações do webserver; executar `php -l rest-integrations.php`.
4. Copiar somente arquivos aprovados para staging e validar login admin, acesso negado para não-admin, fluxo de cadastro e teste.
5. A ferramenta MCP `pabx_status` ainda **não é ativada por esse cadastro**: é preciso implementar o conector HTTP REST no backend em produção, respeitando auth, tokens, permissões e o catálogo de ferramentas existente.
6. Para incorporar exatamente dentro do modal **Adicionar instância MCP** do painel novo, primeiro versionar os arquivos reais desse painel. Evitar substituir pelo `header.php` antigo sem comparar.

## Segurança e reversão

Não expor tokens no navegador, nos logs ou no Git. Não permitir URL livre, chamadas arbitrárias ou token administrativo pela interface. Para reverter, remover a página e o atalho após backup; manter o arquivo de segredos sob controle do administrador. Não substituir o `server.js` em produção por código divergente do GitHub.
