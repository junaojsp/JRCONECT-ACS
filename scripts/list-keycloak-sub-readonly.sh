#!/bin/sh
# Identifica usuarios do realm jrconect pelo banco do Keycloak (somente SELECT).
# NAO exibe senhas, hashes, tokens nem altera Keycloak.
set -eu

command -v docker >/dev/null 2>&1 || { echo "Docker nao instalado"; exit 1; }
if ! docker ps --format '{{.Names}}' | grep -Fxq 'jrconect-auth-db'; then
  echo "Container jrconect-auth-db nao esta em execucao"
  exit 1
fi
printf '%s\n' 'Usuarios do realm jrconect (nome de login | subject UUID):'
docker exec -i jrconect-auth-db sh -c '
  PGPASSWORD="$POSTGRES_PASSWORD" psql -X -q -t -A \
    -U "${POSTGRES_USER:-postgres}" -d "${POSTGRES_DB:-keycloak}" \
    -v ON_ERROR_STOP=1
' <<'SQL'
SELECT u.username || ' | ' || u.id
FROM user_entity u
JOIN realm r ON r.id = u.realm_id
WHERE r.name = 'jrconect'
ORDER BY u.username
LIMIT 40;
SQL
