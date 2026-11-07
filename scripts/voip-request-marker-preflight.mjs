// Execute somente mediante teste manual autorizado dentro do container MCP.
// Nenhuma alteracao em arquivos ou estoque; saída sem observacoes ou titulares.
import { createIxcWebserviceClient } from '../mcp/integrations/ixc-webservice-client.js';
import { listVoipProductContracts } from '../mcp/integrations/ixc-voip-tools.js';
import { inspectVoipRequests } from '../mcp/integrations/ixc-voip-request-inspector.js';

const client = createIxcWebserviceClient();
try {
  const listing = await listVoipProductContracts(client);
  const result = await inspectVoipRequests(client, listing);
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
} catch {
  process.stderr.write('BLOQUEADO: verificacao de observacoes IXC incompleta. Nenhuma alteracao realizada.\n');
  process.exitCode = 2;
}
