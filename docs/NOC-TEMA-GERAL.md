# Tema visual do ACS — funções originais

A mudança fica restrita à aparência: cores, menu, cabeçalhos, cartões, tabelas, formulários e modais. As páginas operacionais, consultas, validações, autenticação e ações existentes são mantidas.

As URLs da prévia encaminham para as páginas operacionais: `/dashboard-noc.php` → `/dashboard.php`, `/devices-noc.php` → `/devices.php`, `/device-noc.php?id=...` → `/device-detail.php?id=...`. Assim a edição de Wi-Fi, WAN, ações em lote e demais controles usam as implementações existentes.

## Aplicar somente o visual

```sh
cd /tmp/jrc-acs-noc
git pull --ff-only origin feature/dashboard-noc-flowbix-inspirado
sudo python3 scripts/install-noc-fixes.py --target /var/www/gacs --theme-only --apply
```

Sem `--apply`, mostra a prévia. Este modo copia somente CSS/JS do tema e as entradas de compatibilidade acima, e mescla as referências CSS/JS nos cabeçalhos existentes. Não instala APIs NOC, não migra configuração IXC, não altera cron, banco ou scripts de ações. Personalizações locais dos cabeçalhos e formulários são preservadas.

O instalador valida a sintaxe PHP e cria backup privado. Para desfazer, use o caminho exibido:

```sh
sudo python3 scripts/install-noc-fixes.py --target /var/www/gacs --restore /var/backups/jrc-noc-DATA
```

Após aplicar, pressione Ctrl+F5. Confira a abertura da mesma ONU e a presença do modal de edição Wi-Fi original. Os testes locais simulam o POST de Wi-Fi sem alterar equipamentos reais. O erro óptico observado anteriormente ainda depende de confirmação no servidor; esta atualização não afirma resolver a causa desse erro.
