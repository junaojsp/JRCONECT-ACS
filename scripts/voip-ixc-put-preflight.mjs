// Safe contract update preflight. Strictly read-only: no PUT/POST or secret output.
// Designed to be piped via stdin into the existing read-only MCP container.
import { createIxcWebserviceClient } from '/app/integrations/ixc-webservice-client.js';

const contractId = '4740';
const productId = '571';
const marker = '#JRC_VOIP_SOLICITAR#';
const expected = [
  'tipo', 'id_cliente', 'id_vd_contrato', 'contrato', 'id_tipo_contrato',
  'id_modelo', 'id_filial', 'data', 'status', 'endereco_padrao_cliente',
  'obs_contrato', 'alerta_contrato'
];
async function exact(client, resource, id) {
  const res = await client.list(resource, {
    id:'1', page:1, limit:20, filters:[{field:'id',oper:'=',value:id}]
  });
  const rows = Array.isArray(res?.registros) ? res.registros :
    Array.isArray(res?.data) ? res.data : null;
  const total = Number(res?.total ?? res?.total_registros);
  if (!rows || total !== 1 || rows.length !== 1 || String(rows[0]?.id) !== id)
    throw new Error('Consulta de identificador incompleta ou ambigua');
  return rows[0];
}
try {
  const client = createIxcWebserviceClient();
  const contract = await exact(client, 'contrato', contractId);
  const product = await exact(client, 'produtos_contrato', productId);
  if (String(product.id_contrato) !== contractId || String(product.id_produto) !== '74')
    throw new Error('Produto 74 nao corresponde ao contrato de teste');
  const observation = contract.obs_contrato;
  if (typeof observation !== 'string' ||
      observation.split(marker).length !== 2 ||
      observation.includes('JRC_VOIP_CONTRATO=')) {
    throw new Error('Marcador ausente, duplicado ou contrato ja vinculado');
  }
  const missing = expected.filter(key => !(key in contract));
  const invalid = expected.filter(key => key in contract &&
      contract[key] !== null && !['string','number','boolean'].includes(typeof contract[key]));
  const fieldNames = Object.keys(contract);
  process.stdout.write(JSON.stringify({
    contrato_id:contractId,
    produto_74_id:productId,
    marcador_exato:true,
    campo_obs_contrato:true,
    campos_retornados:fieldNames.length,
    campos_chave_presentes:expected.filter(key=>key in contract),
    campos_chave_ausentes:missing,
    tipos_inesperados:invalid,
    put_contrato_documentado:true,
    compatibilidade_put_validada:false,
    pronto_para_escrita:false,
    motivo:'IXC PUT pode exigir todos os campos; validar conjunto aceito, permissao restrita e disponibilidade DID antes da primeira escrita.',
    somente_leitura:true
  },null,2)+'\n');
} catch {
  process.stderr.write('BLOQUEADO: preflight IXC 4740 falhou. Nenhuma alteracao realizada.\n');
  process.exitCode=2;
}
