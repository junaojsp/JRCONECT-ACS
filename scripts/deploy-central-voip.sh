#!/usr/bin/env bash
# Central VoIP: implantacao isolada, reversivel e idempotente.
# Fonte: commit ja integrado na branch main do GitHub.
set -Eeuo pipefail
umask 027

REPO=/opt/jrconect-acs-git
WEB=/var/www/gacs
EXPECTED_REMOTE='junaojsp/JRCONECT-ACS-MCP'
REF=FETCH_HEAD
BACKUP_ROOT=/var/backups/jrconect-acs
tmp=''
backup=''
changed=0
page_existed=0
header_existed=0
rollback() {
  local code=$?
  if (( code != 0 && changed == 1 )); then
    echo "ERRO: restaurando somente os arquivos da Central VoIP..." >&2
    if (( header_existed == 1 )); then cp -a "$backup/header.php" "$WEB/views/layouts/header.php"; fi
    if (( page_existed == 1 )); then
      cp -a "$backup/voip-central.php" "$WEB/voip-central.php"
    else
      rm -f "$WEB/voip-central.php"
    fi
  fi
  if [[ -n "$tmp" && -d "$tmp" ]]; then rm -rf "$tmp"; fi
  exit "$code"
}
trap rollback EXIT

[[ $EUID -eq 0 ]] || { echo "Execute como root/sudo" >&2; exit 1; }
command -v git >/dev/null
command -v php >/dev/null
[[ -d "$WEB" && -f "$WEB/dashboard.php" && -f "$WEB/views/layouts/header.php" ]] ||
  { echo "Diretorio de producao inesperado; nada alterado" >&2; exit 1; }
git -C "$REPO" rev-parse --is-inside-work-tree | grep -qx true ||
  { echo "Repositorio ACS indisponivel; nada alterado" >&2; exit 1; }
remote="$(git -C "$REPO" remote get-url origin)"
case "$remote" in
  "https://github.com/$EXPECTED_REMOTE"|"https://github.com/$EXPECTED_REMOTE.git"|"git@github.com:$EXPECTED_REMOTE"|"git@github.com:$EXPECTED_REMOTE.git") ;;
  *) echo "Remote origin inesperado: abortando" >&2; exit 1 ;;
esac
# FETCH_HEAD e gerado pelo comando 'git fetch origin main' da documentacao.
git -C "$REPO" rev-parse --verify "$REF^{commit}" >/dev/null ||
  { echo "Commit de origem ausente" >&2; exit 1; }

tmp="$(mktemp -d)"
git -C "$REPO" show "$REF:voip-central.php" > "$tmp/voip-central.php"
git -C "$REPO" show "$REF:views/layouts/header.php" > "$tmp/header-referencia.php"
grep -Fq 'href="/voip-central.php"' "$tmp/header-referencia.php" ||
  { echo "Commit sem entrada Central VoIP; abortando" >&2; exit 1; }
php -l "$tmp/voip-central.php" >/dev/null
php -l "$WEB/views/layouts/header.php" >/dev/null

# Preserva TODAS as personalizacoes do header em producao; injeta so o novo item.
php /dev/stdin "$WEB/views/layouts/header.php" "$tmp/header-final.php" <<'PHP'
<?php
$src = file_get_contents($argv[1]);
if ($src === false) exit(2);
if (strpos($src, 'href="/voip-central.php"') !== false) {
    $result = $src; // idempotente
} else {
    $needle = '~(?=^[ \t]*<li>[ \t]*\R[ \t]*\R[ \t]*<a[ \t]*\R[ \t]*href="/configuration\.php")~m';
    $new = <<<'HTML'
            <li>
                <a
                    href="/voip-central.php"
                    class="<?php echo ($currentPage ?? '') === 'voip-central' ? 'active' : ''; ?>"
                    data-tooltip="Central VoIP"
                >
                    <i class="bi bi-telephone"></i>
                    <span>Central VoIP</span>
                </a>
            </li>


HTML;
    $result = preg_replace($needle, $new, $src, 1, $n);
    if ($result === null || $n !== 1) {
        fwrite(STDERR, "Ponto de insercao do menu nao encontrado; abortando sem alterar producao\n");
        exit(3);
    }
}
if (file_put_contents($argv[2], $result) === false) exit(4);
PHP
php -l "$tmp/header-final.php" >/dev/null
grep -Fq 'href="/voip-central.php"' "$tmp/header-final.php" ||
  { echo "Validacao do menu falhou" >&2; exit 1; }

# Backup antes de qualquer escrita, inclusive preservando ownership/permissoes.
mkdir -p "$BACKUP_ROOT"
backup="$BACKUP_ROOT/central-voip-$(date -u +%Y%m%dT%H%M%SZ)-$$"
mkdir -p "$backup"
cp -a "$WEB/views/layouts/header.php" "$backup/header.php"
header_existed=1
if [[ -f "$WEB/voip-central.php" ]]; then
  page_existed=1
  cp -a "$WEB/voip-central.php" "$backup/voip-central.php"
fi

# Cria substitutos no mesmo filesystem e troca atomicamente.
install -m 0644 "$tmp/header-final.php" "$WEB/views/layouts/.header.voip-new.$$"
chown --reference="$WEB/views/layouts/header.php" "$WEB/views/layouts/.header.voip-new.$$"
chmod --reference="$WEB/views/layouts/header.php" "$WEB/views/layouts/.header.voip-new.$$"
install -m 0644 "$tmp/voip-central.php" "$WEB/.voip-central.new.$$"
chown --reference="$WEB/dashboard.php" "$WEB/.voip-central.new.$$"
chmod --reference="$WEB/dashboard.php" "$WEB/.voip-central.new.$$"

changed=1
mv -f "$WEB/views/layouts/.header.voip-new.$$" "$WEB/views/layouts/header.php"
mv -f "$WEB/.voip-central.new.$$" "$WEB/voip-central.php"
php -l "$WEB/views/layouts/header.php" >/dev/null
php -l "$WEB/voip-central.php" >/dev/null
echo "OK: Central VoIP publicada em $WEB/voip-central.php"
echo "Backup: $backup"
echo "MCP, IXC, banco e outros servicos nao foram modificados."
