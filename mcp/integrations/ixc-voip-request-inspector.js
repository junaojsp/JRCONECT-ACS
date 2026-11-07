// Fase 1: detector somente leitura para o marcador operacional da JR Conect.
// Nunca altera IXC, estoque, contratos, observacoes ou reservas.
export const REQUEST_MARKER = '#JRC_VOIP_SOLICITAR#';
const CONTRACT_KEYS = Object.freeze(['obs_contrato', 'observacao', 'observacoes', 'obs']);
const CLIENT_KEYS = Object.freeze(['obs', 'observacao', 'observacoes']);
const ID = /^[0-9]{1,12}$/;

export function inspectObservation(row, keys) {
  if (!row || typeof row !== 'object' || Array.isArray(row)) {
    throw new Error('Registro IXC invalido');
  }
  const present = [];
  const marked = [];
  for (const key of keys) {
    if (!(key in row) || row[key] === null) continue;
    if (typeof row[key] !== 'string' || row[key].length > 40000) {
      throw new Error('Campo de observacao inesperado');
    }
    present.push(key);
    if (row[key].includes(REQUEST_MARKER)) marked.push(key);
  }
  return { present, marked };
}

function requireId(value) {
  const id = String(value ?? '');
  if (!ID.test(id)) throw new Error('ID IXC invalido');
  return id;
}
function pageCheck(result, expectedId) {
  if (!result || typeof result !== 'object' || result.error || result.success === false ||
      String(result.status ?? '').toLowerCase() === 'error') throw new Error('Resposta IXC invalida');
  const rows = Array.isArray(result.registros) ? result.registros :
    Array.isArray(result.data) ? result.data : null;
  const total = Number(result.total ?? result.total_registros);
  if (!rows || total !== 1 || rows.length !== 1 || requireId(rows[0].id) !== expectedId) {
    throw new Error('Consulta de identificador incompleta');
  }
  return rows[0];
}

export async function inspectVoipRequests(client, listing) {
  if (!client || typeof client.list !== 'function') throw new Error('Cliente IXC invalido');
  if (!listing || listing.produto_id !== '74' || listing.somente_leitura !== true ||
      listing.liberacao_automatica !== false ||
      !Number.isInteger(listing.total_contratos) ||
      !Array.isArray(listing.contratos) ||
      listing.contratos.length !== listing.total_contratos ||
      listing.contratos.length > 200) throw new Error('Snapshot produto 74 invalido');

  const seen = new Set();
  const inspected = [];
  for (const item of listing.contratos) {
    const contractId = requireId(item.contrato_id);
    if (seen.has(contractId)) throw new Error('Contrato duplicado');
    seen.add(contractId);
    if (!Array.isArray(item.registros_produto_74) || item.registros_produto_74.length === 0) {
      throw new Error('Produto 74 nao identificado');
    }
    const contract = pageCheck(await client.list('contrato', {
      id: '1', page: 1, limit: 20,
      filters: [{field:'id',oper:'=',value:contractId}]
    }), contractId);
    // Never return observation text or client details.
    const contractInfo = inspectObservation(contract, CONTRACT_KEYS);
    const clientId = contract.id_cliente == null || contract.id_cliente === '' ?
      null : requireId(contract.id_cliente);
    let clientInfo = { present: [], marked: [] };
    if (clientId !== null) {
      const customer = pageCheck(await client.list('cliente', {
        id:'1', page:1, limit:20,
        filters:[{field:'id', oper:'=', value:clientId}]
      }), clientId);
      clientInfo = inspectObservation(customer, CLIENT_KEYS);
    }
    const contractMarked = contractInfo.marked.length > 0;
    const clientMarked = clientInfo.marked.length > 0;
    const hasValidContractField = contractInfo.present.includes('obs_contrato');
    inspected.push({
      contrato_id: contractId,
      produto_74: true,
      campos_contrato_detectados: contractInfo.present,
      campos_cliente_detectados: clientInfo.present,
      marcador_contrato: contractMarked,
      marcador_cliente: clientMarked,
      classificacao: contractMarked ? 'marcador_contrato_validar_campo' :
        clientMarked ? 'marcador_cliente_requer_desambiguacao' : 'sem_marcador',
      campo_obs_contrato_presente: hasValidContractField,
      atribuicao_automatica: false
    });
  }
  return {
    produto_id:'74',
    contratos_inspecionados:inspected.length,
    registros:inspected,
    somente_leitura:true,
    escrita_ixc:false,
    reserva_did:false,
    observacao:'Reconhecimento do marcador sem gravacao, sem retornar texto das observacoes e sem escolha de DID.'
  };
}
