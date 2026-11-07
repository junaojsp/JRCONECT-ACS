#!/usr/bin/env bash
# IXC monitoring is read-only; after a valid snapshot, reserve only in local stock.
# Uses the existing MCP container's IXC secret only inside that container.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" == 0 ]] || { echo "BLOQUEADO: requer root" >&2; exit 2; }
[[ -f /opt/jrconect-voip-monitor/ingest.php ]] || { echo "BLOQUEADO: ingest nao instalado" >&2; exit 2; }
[[ -f /var/www/gacs/lib/voip-ixc-monitor.php ]] || { echo "BLOQUEADO: modulo PHP nao instalado" >&2; exit 2; }
[[ "$(docker inspect -f '{{.State.Running}}' jrconect-mcp)" == true ]] || { echo "BLOQUEADO: MCP nao esta executando" >&2; exit 2; }
# No new network port or service. Running a separate, temporary process in
# the live container does not restart it or change any MCP registration.
# No shell interpolated credentials, and no personal data sent to logs.
docker exec jrconect-mcp node --input-type=module -e '
  import { createIxcWebserviceClient } from "/app/integrations/ixc-webservice-client.js";
  import { listVoipProductContracts } from "/app/integrations/ixc-voip-tools.js";
  const result = await listVoipProductContracts(createIxcWebserviceClient());
  process.stdout.write(JSON.stringify(result));
' | php /opt/jrconect-voip-monitor/ingest.php
# This second phase performs ONLY local stock reservations; no IXC PUT.
# Existing cron interval is preserved. Missing deployment blocks safely.
[[ -f /opt/jrconect-voip-monitor/auto-reserve.php ]] || { echo 'RESERVA PENDENTE: auto-reserve nao instalado' >&2; exit 2; }
[[ -f /opt/jrconect-voip-monitor/lib/voip-automatic-reservation.php ]] || { echo 'RESERVA PENDENTE: biblioteca nao instalada' >&2; exit 2; }
php /opt/jrconect-voip-monitor/auto-reserve.php
