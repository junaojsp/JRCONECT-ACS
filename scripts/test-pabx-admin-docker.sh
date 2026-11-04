#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd /opt/jrconect-mcp
# Run only the disposable test container as root to read the restrictive
# repository test files. The production service remains unchanged.
docker compose \
  -f docker-compose.yml \
  -f docker-compose.pabx-rest.yml \
  -f docker-compose.pabx-admin.yml \
  run --rm --no-deps \
  --user 0:0 \
  -v "$ROOT/mcp/tests:/app/tests:ro" \
  --entrypoint node jrconect-mcp \
  --test /app/tests/pabx-admin-auth.test.js
