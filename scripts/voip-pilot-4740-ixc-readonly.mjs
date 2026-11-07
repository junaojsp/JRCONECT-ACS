// Contract 4740 pilot: read only. Run via stdin in the existing MCP container.
// Output includes no name, address, telephone contact, secrets or observations.
import { createIxcWebserviceClient } from '/app/integrations/ixc-webservice-client.js';

const ID = '4740';
const PRODUCT_RECORD = '571';
const MARKER = '#JRC_VOIP_SOLICITAR#';
async function exact(client, resource, id) {
  const result = await client.list(resource, {
    id: '1', page: 1, limit: 20, filters: [{field: 'id',oper:'=',value:id}]
  });
  const rows = Array.isArray(result?.registros) ? result.registros :
    Array.isArray(result?.data) ? result.data : null;
  if (Number(result?.total ?? result?.total_registros) !== 1 ||
      !rows || rows.length !== 1 || String(rows[0]?.id) !== id) {
    throw new Error('Registro IXC incompleto ou ambiguo');
  }
  return rows[0];
}
try {
  const client = createIxcWebserviceClient();
  const contract = await exact(client, 'contrato', ID);
  const product = await exact(client, 'produtos_contrato', PRODUCT_RECORD);
  if (String(product.id_contrato) !== ID || String(product.id_produto) !== '74') {
    throw new Error('Produto 74 incorreto');
  }
  if (typeof contract.obs_contrato !== 'string' ||
      contract.obs_contrato.split(MARKER).length !== 2 ||
      contract.obs_contrato.includes('JRC_VOIP_CONTRATO=')) {
    throw new Error('Marcador ausente, repetido ou ja convertido');
  }
  process.stdout.write(JSON.stringify({
    contrato_id: ID,
    produto_registro_id:PRODUCT_RECORD,
    produto_id:'74',
    observacao_marcador_unico:true,
    observacao_com_texto_adicional:contract.obs_contrato.length > MARKER.length,
    status_ixc_bruto:String(contract.status ?? ''),
    somente_leitura:true,
    escrita_ixc:false,
  })+'\n');
} catch {
  process.stderr.write('BLOQUEADO: nao foi possivel confirmar o contrato 4740 e produto 571. Nenhuma escrita.\n');
  process.exitCode=2;
}
