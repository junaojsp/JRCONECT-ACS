#!/usr/bin/env bash
set -Eeuo pipefail
umask 027
REPO=/opt/jrconect-acs-git
WEB=/var/www/gacs
SOURCE=https://github.com/junaojsp/JRCONECT-ACS-MCP.git
DATA=/var/lib/jrconect-voip
[[ $EUID -eq 0 ]] || { echo 'Execute como root'; exit 1; }
command -v php >/dev/null
command -v flock >/dev/null
[[ -f "$WEB/voip-central.php" && -d "$WEB/lib" && -f "$WEB/config/config.php" ]] || { echo 'Instale primeiro a interface Central VoIP'; exit 1; }
exec 9>/run/lock/jrconect-voip-deploy.lock
flock -n 9 || { echo 'Outra implantação está em execução'; exit 1; }
commit=$(git -C "$REPO" rev-parse 'FETCH_HEAD^{commit}')
remote=$(git -C "$REPO" ls-remote "$SOURCE" refs/heads/main | awk '{print $1}')
[[ -n "$remote" && "$commit" == "$remote" ]] || { echo 'Origem não corresponde à main aprovada'; exit 1; }
tmp=$(mktemp -d)
backup="/var/backups/jrconect-acs/voip-stock-$(date -u +%Y%m%dT%H%M%SZ)-$$"
changed=0
cleanup() {
    code=$?
    if (( code != 0 && changed == 1 )); then
        for path in voip-central.php lib/voip-stock.php; do
            if [[ -f "$backup/$path" ]]; then cp -a "$backup/$path" "$WEB/$path"; else rm -f "$WEB/$path"; fi
        done
        echo 'Falha: arquivos restaurados. O estoque foi preservado.' >&2
    fi
    rm -rf "$tmp"
    rm -f "$WEB/.voip-stock-page.$$" "$WEB/lib/.voip-stock-lib.$$"
    exit "$code"
}
trap cleanup EXIT
for path in voip-central.php lib/voip-stock.php scripts/test-voip-stock.php; do
    mkdir -p "$tmp/$(dirname "$path")"
    git -C "$REPO" show "$commit:$path" > "$tmp/$path"
    php -l "$tmp/$path" >/dev/null
done
php "$tmp/scripts/test-voip-stock.php"
# Select the existing PHP worker account. Never grant access to MCP credentials.
webuser=${VOIP_WEB_USER:-}
if [[ -z "$webuser" ]]; then
    mapfile -t workers < <(ps -eo user=,args= | awk '$1 != "root" && /php-fpm: pool/ {print $1}' | sort -u)
    [[ ${#workers[@]} == 1 ]] || { echo 'Não foi possível identificar um único usuário PHP. Execute com VOIP_WEB_USER=usuario_do_php'; exit 1; }
    webuser=${workers[0]}
fi
id "$webuser" >/dev/null
[[ "$webuser" != root && ! -L "$DATA" ]] || { echo 'Usuário ou diretório de dados inválido'; exit 1; }
if [[ ! -d "$DATA" ]]; then install -d -m 0700 -o "$webuser" -g "$(id -gn "$webuser")" "$DATA"; fi
[[ $(stat -c %U "$DATA") == "$webuser" && $(stat -c %a "$DATA") == 700 ]] || { echo 'Confira proprietário e permissão 0700 do diretório do estoque'; exit 1; }
mkdir -p "$backup/lib"
for path in voip-central.php lib/voip-stock.php; do
    [[ ! -L "$WEB/$path" ]] || { echo 'Destino não pode ser link simbólico'; exit 1; }
    if [[ -f "$WEB/$path" ]]; then cp -a "$WEB/$path" "$backup/$path"; fi
done
install -m 0644 "$tmp/lib/voip-stock.php" "$WEB/lib/.voip-stock-lib.$$"
install -m 0644 "$tmp/voip-central.php" "$WEB/.voip-stock-page.$$"
chown --reference="$WEB/voip-central.php" "$WEB/.voip-stock-page.$$" "$WEB/lib/.voip-stock-lib.$$"
changed=1
mv -f "$WEB/lib/.voip-stock-lib.$$" "$WEB/lib/voip-stock.php"
mv -f "$WEB/.voip-stock-page.$$" "$WEB/voip-central.php"
echo "OK: estoque VoIP instalado. Backup: $backup"
echo 'Importe os números pelo painel autenticado. Nenhum contrato ou serviço MCP foi alterado.'
