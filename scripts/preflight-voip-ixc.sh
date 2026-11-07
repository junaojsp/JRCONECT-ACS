#!/usr/bin/env bash
# Diagnostico de compatibilidade; SOMENTE LEITURA no ACS e MCP.
# Nao instala, reinicia, consulta clientes, muda credenciais ou sincroniza arquivos.
set -Eeuo pipefail
umask 077
REPO=/opt/jrconect-acs-git
MCP=/opt/jrconect-mcp
REF=FETCH_HEAD
SOURCE=https://github.com/junaojsp/JRCONECT-ACS-MCP.git
TMP=''
cleanup() {
  if [[ -n "$TMP" && -d "$TMP" ]]; then rm -rf "$TMP"; fi
}
trap cleanup EXIT

echo '=== Homologacao Central VoIP / IXC - sem alteracoes ==='
command -v git >/dev/null || { echo 'BLOQUEADO: git indisponivel'; exit 2; }
command -v node >/dev/null || { echo 'BLOQUEADO: Node nao encontrado'; exit 2; }
[[ -d "$MCP" ]] || { echo 'BLOQUEADO: diretorio do MCP ativo nao localizado'; exit 2; }
[[ -d "$REPO/.git" || -f "$REPO/.git" ]] || { echo 'BLOQUEADO: checkout do ACS nao localizado'; exit 2; }
sha="$(git -C "$REPO" rev-parse --verify "$REF^{commit}")" || exit 2
remote_sha="$(git -C "$REPO" ls-remote "$SOURCE" refs/heads/main | awk '{print $1}')"
[[ -n "$remote_sha" && "$sha" == "$remote_sha" ]] || {
  echo 'BLOQUEADO: fonte FETCH_HEAD diferente da main ACS-MCP'; exit 2;
}
TMP="$(mktemp -d)"
for file in \
  mcp/integrations/ixc-voip-tools.js \
  mcp/integrations/ixc-webservice-tools.js \
  mcp/integrations/ixc-webservice-client.js; do
  mkdir -p "$TMP/$(dirname "$file")"
  git -C "$REPO" show "$REF:$file" > "$TMP/$file"
  node --check "$TMP/$file" >/dev/null || { echo "BLOQUEADO: sintaxe invalida em $file"; exit 2; }
done
echo "Fonte aprovada: $(printf '%s' "$sha" | cut -c1-12); sintaxe dos 3 modulos: OK"
block=0

# Incompatibilidades sempre exigem revisao. NAO imprimir codigo ativo ou segredos.
for name in server.js auth.js integrations/ixc-webservice-tools.js \
  integrations/ixc-webservice-client.js integrations/ixc-webservice-auth.js; do
  if [[ -f "$MCP/$name" && ! -L "$MCP/$name" ]]; then
    echo "Arquivo MCP presente: $name"
  else
    echo "REVISAO OBRIGATORIA: arquivo MCP ausente/indireto: $name"
    block=1
  fi
done
if [[ -f "$MCP/integrations/ixc-webservice-tools.js" ]]; then
  if grep -Fq 'registerIxcWebserviceTools' "$MCP/integrations/ixc-webservice-tools.js"; then
    echo 'Export do registro IXC: identificado'
  else
    echo 'REVISAO OBRIGATORIA: registro IXC do runtime nao identificado'
    block=1
  fi
fi

if [[ -f "$MCP/server.js" ]]; then
  if grep -Eq 'registerIxcWebserviceTools|ixcWebserviceAuthorized' "$MCP/server.js"; then
    echo 'Referencia de integracao/autorizacao IXC no servidor: identificada (exige revisao manual de escopo)'
  else
    echo 'REVISAO OBRIGATORIA: registro/autorizacao IXC nao identificada diretamente em server.js'
    block=1
  fi
fi
if [[ -f "$MCP/integrations/ixc-webservice-auth.js" ]]; then
  if grep -Fq 'ixc-mcp-admin' "$MCP/integrations/ixc-webservice-auth.js" &&
     grep -Fq 'IXC_WS_ALLOWED_SUB' "$MCP/integrations/ixc-webservice-auth.js"; then
    echo 'Marcadores de controle por papel/allowlist: presentes (nao equivale a auditoria de seguranca)'
  else
    echo 'REVISAO OBRIGATORIA: marcadores de permissao esperados ausentes'
    block=1
  fi
fi

if [[ -f "$MCP/integrations/ixc-voip-tools.js" ]]; then
  echo 'Modulo VoIP ja existe em MCP ativo: nao sobrescrever sem revisao de versao'
  block=1
else
  echo 'Modulo VoIP ainda nao encontrado no diretorio MCP ativo'
fi

echo 'Proibido nesta etapa: docker compose up, restart, git pull, troca de origin, leitura de secrets.'
if (( block != 0 )); then
  echo 'RESULTADO: PENDENTE. Ajustar plano ao runtime antes de qualquer publicacao.'
else
  echo 'RESULTADO: INSPECAO INICIAL CONCLUIDA. Ainda exige revisao manual da autorizacao e homologacao IXC.'
fi
