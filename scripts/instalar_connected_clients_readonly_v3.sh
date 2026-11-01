#!/bin/sh
set -eu

ROOT="${1:-/var/www/gacs}"
BASE_COMMIT="8de39b1d4fb9058a51dc61faaa7019a6f117f71d"
TARGET_COMMIT="32ce8c682a92332f27052496c2095601df52d805"
FILES="
api/device-control.php
assets/css/style.css
assets/js/device-control-fix.js
assets/js/device-detail.js
lib/GenieACS.php
"

fail() {
    echo "CANCELADO: $*" >&2
    exit 1
}

command -v git >/dev/null 2>&1 || fail "git nao encontrado"
command -v php >/dev/null 2>&1 || fail "php nao encontrado"
command -v node >/dev/null 2>&1 || fail "node nao encontrado"

[ -d "$ROOT/.git" ] || fail "$ROOT nao e um repositorio Git"
cd "$ROOT"

git cat-file -e "${BASE_COMMIT}^{commit}" 2>/dev/null || fail "commit base ausente. Execute: git fetch origin fix/wifi-stable-render-red-theme-20261101"
git cat-file -e "${TARGET_COMMIT}^{commit}" 2>/dev/null || fail "commit alvo ausente. Execute: git fetch origin fix/connected-clients-readonly-v3-20261101"

echo "== VALIDANDO ARQUIVOS ATUAIS =="
for file in $FILES; do
    [ -f "$file" ] || fail "arquivo ausente: $file"
    expected="$(git rev-parse "${BASE_COMMIT}:${file}")"
    current="$(git hash-object "$file")"
    [ "$current" = "$expected" ] || fail "$file divergiu da base segura; nada foi alterado"
    echo "OK  $file"
done

TMPDIR="$(mktemp -d /tmp/jrc-connected-clients-v3.XXXXXX)"
trap 'rm -rf "$TMPDIR"' EXIT HUP INT TERM

echo "== PREPARANDO NOVA VERSAO =="
for file in $FILES; do
    mkdir -p "$TMPDIR/$(dirname "$file")"
    git show "${TARGET_COMMIT}:${file}" > "$TMPDIR/$file"
done

php -l "$TMPDIR/api/device-control.php" >/dev/null
php -l "$TMPDIR/lib/GenieACS.php" >/dev/null
node --check "$TMPDIR/assets/js/device-control-fix.js"
node --check "$TMPDIR/assets/js/device-detail.js"

grep -q "JRC_CONNECTED_CLIENTS_REFRESH_READONLY_V3" "$TMPDIR/api/device-control.php" || fail "marcador da consulta read-only ausente"
grep -q "JRC_CONNECTED_CLIENTS_READONLY_V3" "$TMPDIR/lib/GenieACS.php" || fail "marcador do classificador ausente"
grep -q "JRC_CONNECTED_CLIENTS_UI_READONLY_V3" "$TMPDIR/assets/js/device-control-fix.js" || fail "marcador da interface ausente"

BACKUP="/root/gacs-connected-clients-readonly-v3-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP"

echo "== BACKUP =="
for file in $FILES; do
    mkdir -p "$BACKUP/$(dirname "$file")"
    cp -a "$file" "$BACKUP/$file"
done
echo "$BACKUP"

echo "== INSTALANDO =="
for file in $FILES; do
    cat "$TMPDIR/$file" > "$file"
    echo "OK  $file"
done

php -l api/device-control.php >/dev/null
php -l lib/GenieACS.php >/dev/null
node --check assets/js/device-control-fix.js
node --check assets/js/device-detail.js

echo
echo "INSTALADO: consulta de clientes ACS read-only V3."
echo "Backup: $BACKUP"
echo "Nenhum setParameterValues foi adicionado para esta funcao."
echo "A consulta usa Hosts.Host / Device.Hosts e apenas refresh de leitura."
echo "Depois, use Ctrl+F5 no navegador e teste 'Atualizar clientes'."
