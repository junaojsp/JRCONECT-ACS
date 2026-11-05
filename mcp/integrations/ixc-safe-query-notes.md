# IXC query diagnosis (read-only)

The three IXC tools were successfully discovered by ChatGPT, while tool invocation was blocked by platform security settings before any returned result could be inspected. This does not establish a fault in IXC Webservice or in the MCP server.

## Changes
- Existing `ixc_clientes`, `ixc_contratos`, `ixc_financeiro` now return only response status and counts, not raw rows containing subscriber personal or financial data.
- New `ixc_diagnostico_api` performs three GET queries with page size 1 and emits aggregate counts and per-route success/error.
- No API writes, no URL/credential changes, no authentication permission bypass.
- The server still gates these tools through Keycloak `ixc-mcp-admin` and subject allowlist.
- Even this more privacy-minimized output **may still be blocked** by the ChatGPT platform. Do not weaken these platform protections or present an unverified "Online" state.

## Deployment
Only replace the active `/opt/jrconect-mcp/integrations/ixc-webservice-tools.js` after taking backup. Build the existing Docker Compose stack with **all 3 compose files** and restart only the jrconect-mcp container. Check `/health`, then refresh tools and try `ixc_diagnostico_api`. Run an independent ACS-side read-only diagnostics if invocation is still blocked.

Keep the old raw-data behavior out of the final system; reintroducing scoped record access requires a privacy/authorization review, explicit resource selection, audit, and data minimization.

API query sample already verified from ACS: Basic credential token including `usuario:token` without `IXC_WS_USERNAME`, GET `/webservice/v1/cliente` with `ixcsoft: listar`, JSON body and response containing `total`.
