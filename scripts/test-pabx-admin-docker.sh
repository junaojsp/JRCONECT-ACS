#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd /opt/jrconect-mcp
# Run the repository test AND its matching implementation in a disposable
# container. The production image, container and filesystem stay unchanged.
docker compose \
  -f docker-compose.yml \
  -f docker-compose.pabx-rest.yml \
  -f docker-compose.pabx-admin.yml \
  run --rm --no-deps \
  --user 0:0 \
  -v "$ROOT/mcp/tests:/app/tests:ro" \
  -v "$ROOT/mcp/pabx-admin.js:/app/pabx-admin.js:ro" \
  --entrypoint node jrconect-mcp \
  --test /app/tests/pabx-admin-auth.test.js
