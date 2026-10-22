# NOC 2.0: correção de memória e homologação

## Diagnóstico confirmado

O log do servidor enviado em 21/10/2026 registra `Allowed memory size of 134217728 bytes exhausted`
em `/var/www/gacs/lib/GenieACS.php:67`, durante `GET /api/dashboard-noc-data.php`.
O endpoint inicial usava `getDevices()` sem limite nem projeção, decodificando todas as árvores
TR-069 simultaneamente. O erro fatal interrompia a resposta; o navegador tentava interpretar
o corpo incompleto e mostrava `Unexpected end of JSON input`.

## Correção

- Cliente exclusivo `lib/NocGenieACS.php`: apenas GET, seis campos necessários, páginas de 100,
  ordenação por `_id`, limite de 4 MiB por resposta e de 15 segundos por consulta.
- API agrega e libera cada página; limita a coleta a 45 segundos e nunca publica resultado parcial
  como sucesso. A lista de ocorrências também permanece limitada em memória.
- Sessão verificada antes do banco/GenieACS e liberada durante a coleta. API sem sessão retorna
  HTTP 401 em JSON. O login e os redirecionamentos das páginas existentes permanecem intactos.
- Bootstrap, exceções, encerramentos prematuros e erros fatais usuais passam pelo tratamento JSON.
  A reserva de memória permite responder ao esgotamento de memória testado. Falhas de processo,
  encerramentos pelo FPM/proxy e erros de sintaxe no próprio endpoint ainda podem impedir JSON;
  a interface trata corpos vazios/inválidos sem expor a mensagem do parser.
- Interface preserva os últimos números válidos quando há falha e sinaliza sua antiguidade.

`lib/GenieACS.php`, `dashboard.php`, `login.php`, `index.php`, configurações e banco não são
alterados. Não há comandos, refresh ou mudanças nos CPEs. A projeção segue a
[API oficial do GenieACS](https://docs.genieacs.com/en/latest/api-reference.html).

## Validação local

```bash
python3 tests/noc-endpoint.py
node --check assets/js/dashboard-noc.js
node tests/noc-client.cjs
git diff --check
```

Requer PHP CLI com cURL, Python 3 e Node. Os testes usam HTTP local e banco fictício em uma
pasta temporária; não carregam configurações nem credenciais de produção.
Opcional: `--php /caminho/php --curl-extension /caminho/php_curl.dll --temp-dir /pasta/gravavel`.

Validados PHP 8.3.36 e 8.4.27, limite de memória 128M, 16 cenários HTTP e 8 cenários da interface.
Para 2.413 dispositivos simulados: 25 requisições, totais completos e pico PHP de 2 MiB no PHP 8.4.
Isso mede o ambiente simulado; consumo e latência reais devem ser conferidos na homologação.

## Implantação seletiva no Debian

Não fazer merge nem `git pull` dentro de `/var/www/gacs`. Não copiar a configuração de exemplo
nem substituir o cliente GenieACS antigo. A folha CSS já instalada não mudou nesta correção.

Em um checkout novo da branch, selecionar o commit informado na entrega:

```bash
cd /tmp
git clone --single-branch --branch feature/dashboard-noc-flowbix-inspirado \
  https://github.com/junaojsp/JRCONECT-ACS.git jrc-noc-memory-fix
cd /tmp/jrc-noc-memory-fix
# Se essa pasta já existir, use uma pasta nova para não descartar alterações locais.
# Para fixar exatamente a versão revisada: git checkout --detach SHA_DO_COMMIT_ENTREGUE
```

Executar o bloco abaixo na mesma sessão, dentro desse checkout. O `bash -e` interrompe a
instalação se uma validação, backup ou cópia falhar. O backup fica fora da pasta pública.

```bash
sudo bash -e <<'SH'
src=/tmp/jrc-noc-memory-fix
dst=/var/www/gacs
test -f "$dst/config/config.php"
test -f "$dst/dashboard-noc.php"
test -d "$dst/api"
test -d "$dst/lib"
test -d "$dst/assets/js"
for file in api/dashboard-noc-data.php lib/NocGenieACS.php dashboard-noc.php; do
  php -l "$src/$file"
done
backup="/var/backups/jrc-noc-$(date +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup"
tar -cpf "$backup/before.tar" -C "$dst" \
  api/dashboard-noc-data.php assets/js/dashboard-noc.js dashboard-noc.php
if test -f "$dst/lib/NocGenieACS.php"; then
  cp -p "$dst/lib/NocGenieACS.php" "$backup/NocGenieACS.php"
fi
git -C "$src" rev-parse HEAD > "$backup/source-commit.txt"
# Copiar primeiro o cliente novo; publicar a API depois dele.
install -m 644 "$src/lib/NocGenieACS.php" "$dst/lib/NocGenieACS.php"
install -m 644 "$src/api/dashboard-noc-data.php" "$dst/api/dashboard-noc-data.php"
install -m 644 "$src/assets/js/dashboard-noc.js" "$dst/assets/js/dashboard-noc.js"
install -m 644 "$src/dashboard-noc.php" "$dst/dashboard-noc.php"
printf '\nBackup para reversão: %s\n' "$backup"
SH
```

O log identifica PHP 8.4-FPM. Se o OPcache não reconhecer os arquivos novos, validar e
recarregar esse serviço, usando o nome da instalação real:

```bash
sudo php-fpm8.4 -t
sudo systemctl reload php8.4-fpm
```

Não é necessário aumentar `memory_limit` como solução desta falha.

## Aceite no servidor

1. Entrar normalmente. Confirmar que o login continua levando à dashboard antiga.
2. Abrir `/dashboard-noc.php` e forçar atualização do navegador para carregar `dashboard-noc.js?v=2`.
3. Na aba Rede das ferramentas do navegador, verificar `/api/dashboard-noc-data.php`:
   HTTP 200, `Content-Type: application/json`, `success: true` e totais coerentes com o GenieACS.
   Não compartilhar cookies de sessão nem exportações de rede com credenciais.
4. Conferir no JSON: `total = online + offline` e soma das quatro faixas ópticas igual ao total.
   Comparar alguns dispositivos das três origens de RX suportadas; os limiares não mudaram.
5. Em janela sem sessão, consultar a API: deve responder 401 em JSON. A página NOC continua
   redirecionando ao login; o login continua usando sua rota antiga.
6. Conferir o log Nginx/FPM após o teste: sem novos erros de memória relacionados ao NOC.

Os indicadores vêm de uma varredura paginada; inserções/exclusões de dispositivos durante
a coleta podem alterar a consistência entre páginas. Não é uma transação do inventário.
Online significa Inform há menos de cinco minutos, não confirmação de acesso à Internet.
O cenário de carga simultânea e o tempo real de resposta ainda precisam de homologação.

## Reversão

Substituir o caminho abaixo pelo backup exibido durante a instalação:

```bash
sudo tar -xpf /var/backups/jrc-noc-DATA-HORA/before.tar -C /var/www/gacs
```

Isso restaura a API, a interface e a página anteriores. Se houver `NocGenieACS.php` no backup,
restaurá-lo em `/var/www/gacs/lib/NocGenieACS.php` com `cp -p`. Caso seja um arquivo novo,
ele pode permanecer: a versão anterior da API não o carrega. Se necessário, repetir a
recarga do PHP-FPM para invalidar o OPcache. Não alterar o login até homologação aprovada.
