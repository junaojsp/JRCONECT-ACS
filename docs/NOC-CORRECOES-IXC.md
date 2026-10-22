# Correções do ACS: memória, óptico IXC e scripts

O erro 500 original foi confirmado no log do PHP-FPM: limite de 128 MiB esgotado ao
decodificar todas as árvores TR-069. A primeira correção já respondeu nas telas enviadas
pelo usuário. Esta etapa adiciona o RX do IXC e repara problemas encontrados na revisão.

## O que muda

- `NocIxcOptical.php` lista somente o cadastro `radpop_radio_cliente_fibra`, em páginas de
  100, com `ixcsoft: listar`. Não chama diagnóstico, refresh, reinício ou ações em CPEs.
  Limita cada resposta a 2 MiB, cada requisição a 5 s e a coleta óptica a 12 s/20 mil registros.
- Cache privado de até 120 segundos, com trava de atualização. Guarda somente seriais,
  RX, data do sinal e metadados de coleta; não guarda o token, login ou senhas.
  O token e a URL entram apenas no hash da identidade do cache, invalidando-o após rotação.
  Pode-se definir `NOC_CACHE_DIR` no FPM; o padrão é uma subpasta privada no temporário.
- Correlação de serial normalizado, com variantes ASCII/hexadecimal do fabricante.
  RX IXC válido é prioritário; sem correspondência/RX válido, mantém o RX do TR-069.
- A saúde óptica e o cartão crítico usam o inventário IXC completo, como a dashboard antiga.
  Inventário, comunicação e triagem por equipamento continuam no universo ACS: 2.413 registros
  IXC não significam 2.413 equipamentos no ACS. O limite crítico é RX <= -28 dBm.
- O visual azul/ciano aprovado passa a ter navegação própria, sem alterar o layout legado.
  O gráfico registra observações ACS reais, no máximo uma por minuto, durante as consultas;
  conserva 24 horas em armazenamento privado. Não retroage, não conta eventos Inform e
  não calcula SLA. Lacunas superiores a dez minutos não são conectadas.
- A tabela PON agrupa cadastros IXC por transmissor/slot/PON e prioriza RX crítico.
  Não representa capacidade, ocupação física ou estado da porta OLT.
- Sem medições ópticas válidas, o cartão crítico mostra `N/D`. RX sem leitura não significa
  sinal saudável. A tela identifica a fonte e apresenta a data do sinal nos itens disponíveis.
- Sem configuração IXC ou em falha de consulta, o NOC mantém a leitura independente do ACS
  e informa a ausência do IXC; não publica uma coleta parcial IXC como completa.
- O limite total da coleta NOC continua em 45 s. Falhas de sessão/bootstrap têm resposta JSON.
- O token literal sai de `get-onu-optical.php`: configuração por `IXC_BASE_URL`/`IXC_TOKEN`,
  `/etc/jrconect-acs/ixc.php` ou `config/ixc.php`. Esta última fica ignorada pelo Git.
- Os exemplos cron em comentários usam `0-59/5`, evitando o fechamento acidental `*/`
  que impedia dois scripts de executar. Nenhum agendamento é criado ou executado.

O instalador preserva `dashboard.php`, `login.php`, `index.php`, o cliente GenieACS antigo
e as configurações locais existentes. Preserva também um `lib/IXCConfig.php` já instalado
que forneça `getIxcConfig()`. Não importa branches inteiras nem sobrescreve perfis de CPE.

## 1. Atualizar somente o checkout de preparação

```bash
cd /tmp/jrc-acs-noc
git pull --ff-only origin feature/dashboard-noc-flowbix-inspirado
```

Não executar esse pull dentro de `/var/www/gacs` e não alterar o destino do login.

## 2. Preparar a configuração privada e substituir o token exposto

O token antigo aparece no histórico público. Removê-lo do código novo não revoga seu acesso.
Gerar no IXC um token substituto com permissão de leitura para a integração e configurar
localmente os serviços que o usam. Revogar o token exposto após a substituição.
Não colocar o token em comandos que ficam no histórico, no chat, em capturas ou em commits.

No servidor, consultar primeiro se `/etc/jrconect-acs/ixc.php` ou `config/ixc.php` já existe.
A presença de `lib/IXCConfig.php` por si só não comprova que há credenciais configuradas.
Preservar a configuração existente e editar somente o token necessário.

Se ainda não houver arquivo privado, dentro do checkout atualizado:

```bash
sudo install -d -m 750 -o root -g www-data /etc/jrconect-acs
sudo install -m 640 -o root -g www-data config/ixc.php.example /etc/jrconect-acs/ixc.php
sudo nano /etc/jrconect-acs/ixc.php
```

Usar o grupo real do pool PHP-FPM se não for `www-data`. O exemplo deixa o token vazio;
preenchê-lo apenas no editor local. Se usar variáveis no FPM, verificar se o pool permite
essas variáveis. Não substituir um arquivo privado existente pelo exemplo vazio.

Após instalar o leitor de configuração, verificar acesso pelo usuário do FPM sem imprimir valores:

```bash
sudo -u www-data php -r 'require "/var/www/gacs/lib/IXCConfig.php"; getIxcConfig(); echo "Configuração IXC acessível\n";'
```

## 3. Prévia e instalação seletiva

Requer Python 3.9+ e PHP CLI no Debian. A prévia não escreve no site:

```bash
cd /tmp/jrc-acs-noc
sudo python3 scripts/install-noc-fixes.py --target /var/www/gacs
```

Depois de conferir os arquivos e preparar a configuração privada:

```bash
sudo python3 scripts/install-noc-fixes.py --target /var/www/gacs --apply
```

O instalador valida a sintaxe antes de gravar, cria backup privado fora da pasta pública e
substitui cada arquivo de forma atômica. Corrige somente os exemplos dos comentários nos
scripts cron locais e os blocos de configuração conhecidos no endpoint óptico legado;
preserva o restante do código local. Se encontrar uma estrutura diferente, interrompe para
revisão. Se precisar migrar um token literal, exige que a configuração privada já esteja legível.
Erros durante a escrita acionam a restauração dos arquivos já substituídos.

Não altera credenciais, banco, agendamentos ou serviços. Não instala APIs de debug.
O backup pode conter o arquivo antigo com o token exposto; fica acessível somente ao root.

Se o OPcache conservar a versão anterior, validar e recarregar o serviço identificado no log:

```bash
sudo php-fpm8.4 -t
sudo systemctl reload php8.4-fpm
```

Não é necessário aumentar `memory_limit` como solução para o NOC.

## 4. Homologação

Abrir `/dashboard-noc.php` autenticado e usar Ctrl+F5. O JS deve ter `v=3`.
Na resposta de `/api/dashboard-noc-data.php`, conferir:

- HTTP 200 e `success: true` para o ACS. Sem sessão, HTTP 401 em JSON.
- `optical.status: ok` e `capabilities.ixc_optical_primary: true` quando a consulta IXC concluir.
- `summary.optical_from_ixc` deve aumentar conforme as correspondências; comparar alguns RX
  com o cadastro IXC e verificar sua data de sinal. Não basta o serviço responder `ok`.
- `summary.total = online + offline` e soma das quatro faixas ópticas igual ao total do ACS.
- `optical.inventory_total` é o inventário IXC; não somá-lo ao total do ACS.
- Sem novos erros de memória no log. O login continua levando à dashboard antiga.

Sem configuração ou sem acesso IXC, a tela deve explicar isso e manter o ACS funcionando.
O cache da aplicação não garante que o dado de sinal do próprio IXC seja recente.
Limiares ópticos, latência/carga real, cobertura de seriais e consistência durante alterações
do inventário ainda precisam de homologação. Leituras paginadas não são uma transação.

## Reversão

Usar o caminho de backup exibido pelo instalador:

```bash
sudo python3 /tmp/jrc-acs-noc/scripts/install-noc-fixes.py \
  --target /var/www/gacs --restore /var/backups/jrc-noc-DATA-HORA
```

A reversão repõe conteúdo, permissões e proprietário anteriores, e remove arquivos criados
por essa instalação. Não altera a configuração privada nem reativa o token revogado.
Repetir a recarga do FPM se necessário. Restaurar os arquivos antigos também restaura seus
defeitos anteriores; usar somente para recuperar o comportamento prévio enquanto se investiga.

## Testes locais

```bash
python3 tests/noc-endpoint.py
php tests/noc-ixc.php
python3 tests/noc-install.py
node tests/noc-client.cjs
git diff --check
```

Testados PHP 8.3.36 e 8.4.27, com limite 128M. São 23 cenários HTTP, incluindo prioridade IXC,
fallback, cache, resposta truncada, páginas repetidas e falha do IXC mantendo o ACS; 10 cenários
da interface e validação de RX, aliases e configuração de conexão. O cenário de 2.413
dispositivos ACS/2.500 registros IXC teve pico de 4 MiB no PHP 8.3 local.
O instalador foi validado em aplicação simulada: prévia, migração do endpoint, preservação de
arquivos locais, backup, reaplicação sem alterações e reversão. A implantação real depende do operador.
