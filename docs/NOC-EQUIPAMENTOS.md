# Equipamentos: visual comum, operação existente

O painel `/dashboard-noc.php` encaminha para `/dashboard.php`, e a lista `/devices-noc.php` encaminha para `/devices.php`. A navegação usa as páginas operacionais existentes, com o tema visual comum. A rota `/device-noc.php?id=...` é apenas uma entrada de compatibilidade autenticada que redireciona para esse mesmo detalhe.

A tela operacional usa o tema compartilhado, preservando o HTML, os modais e `assets/js/device-detail.js` instalados no servidor. Wi-Fi, WAN, etiquetas, comunicação, testes e demais ações continuam com seus controles existentes. A mudança de aparência não substitui esses fluxos por uma prévia de leitura.

## Consultas e ações

- Identificação e parâmetros: `/api/get-device-detail.php`, cliente e parser operacionais.
- Óptico: `/api/get-onu-optical.php`, integração IXC operacional.
- Alteração de Wi-Fi: formulário/modal existentes e POST `/api/update-wifi-config.php`, preservando WLAN selecionada e validações existentes.
- A lista operacional mantém sua busca, filtros, paginação e ações em lote originais.

O instalador não sobrescreve `device-detail.php`, `assets/js/device-detail.js`, `views/device-detail/modals.php` ou `api/update-wifi-config.php`. O cabeçalho local recebe somente as referências do tema com `--theme-only`.

O aviso óptico observado em produção continua sem causa confirmada. Encaminhar para a tela operacional restaura o acesso aos fluxos existentes, mas não comprova que o endpoint óptico está saudável. A homologação deve comparar a mesma ONU na tela operacional. Não são realizadas alterações reais de Wi-Fi durante os testes locais.

## Aplicação

```sh
cd /tmp/jrc-acs-noc
git pull --ff-only origin feature/dashboard-noc-flowbix-inspirado
sudo python3 scripts/install-noc-fixes.py --target /var/www/gacs --theme-only --apply
```

O instalador valida PHP e cria backup privado com restauração. Depois atualize o navegador com Ctrl+F5, abra uma ONU pelo painel/lista e confira a presença do controle de edição de Wi-Fi e dos modais existentes.

## Verificação

`tests/noc-operational.cjs` executa o manipulador original de Wi-Fi em ambiente simulado: abertura do modal, validação, WLAN e corpo POST exato. Nenhuma requisição real é enviada. `tests/noc-route.py` verifica autenticação, redirecionamento local e codificação segura do ID. `tests/noc-install.py` verifica preservação dos arquivos operacionais, backup, idempotência e restauração. As interfaces NOC continuam cobertas por seus testes de regressão.
