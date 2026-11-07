#!/usr/bin/env bash
# Controlled promotion of an already smoke-tested IXC VoIP candidate.
set -Eeuo pipefail
umask 077
ROOT=/opt/jrconect-mcp
SERVICE=jrconect-mcp
SERVICE_IMAGE=jrconect-mcp-jrconect-mcp
COMPOSE=(-f docker-compose.yml -f docker-compose.opa.yml -f docker-compose.ixc.yml -f docker-compose.pabx-central.yml)
fail() { echo "BLOQUEADO: $*" >&2; exit 2; }
[[ "$(id -u)" == 0 ]] || fail 'Execute como root'
command -v docker >/dev/null || fail 'Docker ausente'
command -v curl >/dev/null || fail 'curl ausente'
cd "$ROOT" || fail 'MCP nao localizado'
docker compose "${COMPOSE[@]}" config --quiet || fail 'Compose atual invalido'
old_ref="$(docker inspect -f '{{.Image}}' "$SERVICE")" || fail 'MCP indisponivel'
old_name="$(docker inspect -f '{{.Config.Image}}' "$SERVICE")"
[[ "$old_name" == "$SERVICE_IMAGE" ]] || fail 'Nome da imagem em execucao mudou; exige revisao'
[[ "$(docker inspect -f '{{.State.Running}}' "$SERVICE")" == true ]] || fail 'MCP nao esta em execucao'

candidate="${2:-}"
[[ "$candidate" == jrconect-mcp:voip-ixc-candidate-* ]] || fail 'Informe a tag exata da candidata'
candidate_id="$(docker image inspect -f '{{.Id}}' "$candidate")" || fail 'Candidata nao encontrada'
[[ "$candidate_id" != "$old_ref" ]] || fail 'Candidata igual a imagem atual'
echo "MCP ativo: $old_ref"
echo "Imagem candidata: $candidate_id"
echo 'Compose: base, opa, ixc e pabx-central; somente o servico jrconect-mcp sera recriado.'
echo 'Ativacao exigira breve indisponibilidade. Imagem anterior sera preservada.'

mode="${1:---plan}"
case "$mode" in
--plan)
  echo 'PLAN OK: nenhuma mudanca executada.'
  ;;
--activate)
  [[ "${VOIP_IXC_APPROVE_ACTIVATION:-}" == YES ]] || fail 'Requer VOIP_IXC_APPROVE_ACTIVATION=YES'
  backup="jrconect-mcp:pre-voip-ixc-$(date -u +%Y%m%dT%H%M%SZ)"
  docker image tag "$old_ref" "$backup"
  echo "Imagem anterior preservada como: $backup"
  docker image tag "$candidate" "$SERVICE_IMAGE"

  restore() {
    echo 'ATIVACAO FALHOU: recuperando imagem anterior'
    docker image tag "$backup" "$SERVICE_IMAGE" || return 1
    docker compose "${COMPOSE[@]}" up -d --no-deps --no-build --force-recreate "$SERVICE" || return 1
    for i in 1 2 3 4 5 6 7 8; do
      if curl -fsS --max-time 2 http://127.0.0.1:3100/health >/dev/null; then
        echo "ROLLBACK: health recuperado, imagem anterior restaurada."
        return 0
      fi
      sleep 2
    done
    echo 'ROLLBACK FALHOU: verificar MCP imediatamente' >&2
    return 1
  }

  if ! docker compose "${COMPOSE[@]}" up -d --no-deps --no-build --force-recreate "$SERVICE"; then
    restore || true
    exit 1
  fi
  ok=0
  for i in 1 2 3 4 5 6 7 8; do
    current="$(docker inspect -f '{{.Image}}' "$SERVICE" 2>/dev/null || true)"
    if [[ "$current" == "$candidate_id" ]] &&
       curl -fsS --max-time 2 http://127.0.0.1:3100/health >/dev/null; then
      ok=1
      break
    fi
    sleep 2
  done
  if [[ "$ok" != 1 ]]; then
    restore || true
    exit 1
  fi
  echo "ATIVACAO OK: health ativo e imagem candidata aplicada."
  echo "IMAGEM DE ROLLBACK: $backup"
  echo 'PENDENTE: validar ferramentas MCP com OAuth IXC autorizado, nao somente /health.'
  ;;
*) fail 'Modo esperado: --plan ou --activate' ;;
esac
