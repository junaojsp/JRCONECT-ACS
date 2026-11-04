#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd /opt/jrconect-mcp
# Compose run supports -v; mount the test read-only and keep the image dependencies.
docker compose \
  -f docker-compose.yml \
  -f docker-compose.pabx-rest.yml \
  -f docker-compose.pabx-admin.yml \
  run --rm --no-deps \
  -v "$ROOT/mcp/tests:/app/tests:ro" \
  --entrypoint node jrconect-mcp \
  --test /app/tests/pabx-admin-auth.test.js
