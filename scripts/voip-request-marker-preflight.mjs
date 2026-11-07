// Executar de /tmp/jrc-voip-request-preflight.mjs no container MCP.
// O modulo auxiliar reside em /tmp/jrc-voip-request-inspector.mjs.
// Execute somente mediante teste manual autorizado dentro do container MCP.
// Nenhuma alteracao em arquivos ou estoque; saída sem observacoes ou titulares.
import { createIxcWebserviceClient } from '/app/integrations/ixc-webservice-client.js';
import { listVoipProductContracts } from '/app/integrations/ixc-voip-tools.js';
import { inspectVoipRequests } from '/tmp/jrc-voip-request-inspector.mjs';

const client = createIxcWebserviceClient();
try {
  const listing = await listVoipProductContracts(client);
  const result = await inspectVoipRequests(client, listing);
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
} catch {
  process.stderr.write('BLOQUEADO: verificacao de observacoes IXC incompleta. Nenhuma alteracao realizada.\n');
  process.exitCode = 2;
}
