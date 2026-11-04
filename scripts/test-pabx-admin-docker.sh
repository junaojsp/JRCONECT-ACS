#!/bin/sh
set -eu
# Run the repository tests against the image dependencies, without writing to the read-only container.
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"
docker compose \
  -f /opt/jrconect-mcp/docker-compose.yml \
  -f /opt/jrconect-mcp/docker-compose.pabx-rest.yml \
  -f /opt/jrconect-mcp/docker-compose.pabx-admin.yml \
  run --rm --no-deps \
  --mount "type=bind,source=$ROOT/mcp/tests,target=/app/tests,readonly" \
  --entrypoint node jrconect-mcp \
  --test /app/tests/pabx-admin-auth.test.js
