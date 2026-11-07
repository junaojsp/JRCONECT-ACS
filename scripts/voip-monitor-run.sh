#!/usr/bin/env bash
# Existing 15-minute cron: GET-only snapshot and local reservation.
set -Eeuo pipefail
umask 077
[[ "$(id -u)" == 0 ]] || { echo "BLOQUEADO: requer root" >&2; exit 2; }
MON=/opt/jrconect-voip-monitor
for FILE in auto-reserve.php lib/voip-ixc-reservation-reader.php lib/voip-automatic-reservation.php; do
  [[ -f "$MON/$FILE" ]] || { echo "BLOQUEADO: modulo de reserva ausente" >&2; exit 2; }
done
# A single existing reservation lock now covers snapshot + ingest + reservation.
# No MCP process, container files, services or registrations are changed.
php "$MON/auto-reserve.php" --monitor
