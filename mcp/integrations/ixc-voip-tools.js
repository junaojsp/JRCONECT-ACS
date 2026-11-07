import { z } from 'zod';

// Consulta VoIP somente leitura. IDs e status bruto; nunca retorna dados pessoais.
// O chamador deve manter as mesmas validacoes de OAuth/escopo do IXC existente.
const idPattern = /^\d{1,12}$/;
const MAX_ROWS = 200;
const PAGE_SIZE = 20;

function id(value, label) {
  const text = String(value ?? '');
  if (!idPattern.test(text)) throw new Error(label + ' invalido');
  return text;
}

function parsePage(payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload) ||
      payload.error || payload.success === false ||
      String(payload.status ?? '').toLowerCase() === 'error') {
    throw new Error('Resposta IXC invalida');
  }
  // Ausencia de registros ou total NAO deve ser interpretada como conjunto vazio.
  const rows = Array.isArray(payload.registros) ? payload.registros :
    Array.isArray(payload.data) ? payload.data : null;
  const total = Number(payload.total ?? payload.total_registros);
  if (!rows || !Number.isSafeInteger(total) || total < 0 || total > MAX_ROWS) {
    throw new Error('IXC sem paginacao verificavel ou limite excedido');
  }
  return { rows, total };
}

async function readAll(client, resource, filters) {
  const seen = new Set();
  const out = [];
  let expected = null;
  for (let page = 1; page <= Math.ceil(MAX_ROWS / PAGE_SIZE) + 1; page++) {
    const result = parsePage(await client.list(resource, {
      id: '1', page, limit: PAGE_SIZE, filters,
    }));
    if (expected === null) expected = result.total;
    if (expected !== result.total || result.rows.length > PAGE_SIZE ||
        (out.length < expected && result.rows.length === 0)) {
      throw new Error('Snapshot IXC parcial ou inconsistente');
    }
    for (const row of result.rows) {
      if (!row || typeof row !== 'object') throw new Error('Registro IXC invalido');
      const rowId = id(row.id, 'Registro');
      if (seen.has(rowId)) throw new Error('Registros IXC duplicados');
      seen.add(rowId);
      out.push(row);
    }
    if (out.length === expected) return out;
    if (out.length > expected) throw new Error('Snapshot IXC excedeu o total');
  }
  throw new Error('Limite de paginas IXC excedido');
}

export async function queryVoipContract(client, contractId) {
  const target = id(contractId, 'Contrato');
  if (!client || typeof client.list !== 'function') throw new TypeError('Cliente IXC invalido');
  const contracts = await readAll(client, 'contrato',
    [{ field: 'id', oper: '=', value: target }]);
  if (contracts.length !== 1 || id(contracts[0].id, 'Contrato retornado') !== target) {
    if (contracts.length === 0) return { contrato_id: target, encontrado: false, produto_74: null };
    throw new Error('IXC retornou contrato inesperado');
  }

  const raw = contracts[0].status;
  if (typeof raw !== 'string' || !raw.trim() || raw.length > 40) {
    throw new Error('Status de contrato sem validacao');
  }
  const rows = await readAll(client, 'produtos_contrato', [
    { field: 'id_contrato', oper: '=', value: target },
    { field: 'id_produto', oper: '=', value: '74' },
  ]);
  const products = rows.map(row => {
    const productRowId = id(row.id, 'ID de produto contratado');
    if (id(row.id_contrato, 'Contrato da linha') !== target ||
        id(row.id_produto, 'Produto da linha') !== '74') {
      throw new Error('Produto retornado fora dos filtros');
    }
    return { registro_id: productRowId, produto_id: '74' };
  });
  return {
    contrato_id: target,
    encontrado: true,
    status_ixc_bruto: raw,
    status_validado: false,
    produto_74: products.length > 0,
    registros_produto_74: products,
    somente_leitura: true,
    vinculo_autorizado: false,
  };
}


const MAX_VOIP_CONTRACTS = 200;
function optionalId(value, label) {
  if (value === null || value === undefined || value === '') return null;
  return id(value, label);
}
function clientName(row) {
  // IXC customer name is usually 'razao'. Never infer from another record.
  for (const key of ['razao', 'nome']) {
    if (typeof row[key] === 'string') {
      const value = row[key].trim();
      if (value && value.length <= 250 && !/[\x00-\x1f]/.test(value)) return value;
    }
  }
  return null;
}

/**
 * Read-only snapshot of all associations to product 74.
 * No binding, stock mutations or automatic release is performed.
 * Client fields may vary between IXC versions: missing name is a
 * verifiable pending field, not a fabricated match.
 */
export async function listVoipProductContracts(client) {
  if (!client || typeof client.list !== 'function') throw new TypeError('Cliente IXC invalido');
  const productRows = await readAll(client, 'produtos_contrato', [
    { field: 'id_produto', oper: '=', value: '74' },
  ]);
  const grouped = new Map();
  for (const row of productRows) {
    if (id(row.id_produto, 'Produto retornado') !== '74') throw new Error('Filtro do produto ignorado');
    const contractId = id(row.id_contrato, 'Contrato do produto');
    const recordId = id(row.id, 'Registro de produto');
    if (!grouped.has(contractId)) grouped.set(contractId, []);
    grouped.get(contractId).push(recordId);
  }
  if (grouped.size > MAX_VOIP_CONTRACTS) throw new Error('Limite de contratos excedido');
  const contracts = [];
  for (const [contractId, recordIds] of grouped) {
    const contractRows = await readAll(client, 'contrato', [
      { field: 'id', oper: '=', value: contractId },
    ]);
    if (contractRows.length !== 1 || id(contractRows[0].id, 'Contrato retornado') !== contractId) {
      throw new Error('Contrato IXC nao encontrado ou divergente');
    }
    const contract = contractRows[0];
    if (typeof contract.status !== 'string' || !contract.status.trim() || contract.status.length > 40) {
      throw new Error('Status de contrato sem validacao');
    }
    const customerId = optionalId(contract.id_cliente, 'Cliente do contrato');
    let customer = null;
    if (customerId) {
      const customerRows = await readAll(client, 'cliente', [
        { field: 'id', oper: '=', value: customerId },
      ]);
      if (customerRows.length !== 1 || id(customerRows[0].id, 'Cliente retornado') !== customerId) {
        throw new Error('Cliente IXC nao encontrado ou divergente');
      }
      customer = clientName(customerRows[0]);
    }
    contracts.push({
      contrato_id: contractId,
      id_cliente: customerId,
      nome_cliente: customer,
      titular_validado: customer !== null,
      status_ixc_bruto: contract.status,
      status_validado: false,
      registros_produto_74: recordIds.sort((a,b) => Number(a)-Number(b)),
    });
  }
  contracts.sort((a,b) => Number(a.contrato_id)-Number(b.contrato_id));
  return {
    produto_id: '74',
    total_registros_produto: productRows.length,
    total_contratos: contracts.length,
    contratos: contracts,
    somente_leitura: true,
    conciliacao_confirmada: false,
    liberacao_automatica: false,
    observacao: 'Nomes e codigos de status exigem validacao na instalacao IXC; nao usar coincidencia de nome para liberar DID.',
  };
}

export function registerIxcVoipTools(mcp, client) {
  mcp.registerTool('ixc_voip_listar_contratos_produto74', {
    description: 'Lista todos os contratos com produto 74, status bruto, IDs e titular quando disponivel no cadastro IXC. Somente leitura; nao altera ou libera numeros. Restrito ao mesmo escopo OAuth IXC.',
    inputSchema: {},
  }, async () => {
    try {
      const result = await listVoipProductContracts(client);
      return { content: [{ type: 'text', text: JSON.stringify(result) }] };
    } catch {
      return { isError: true, content: [{ type: 'text', text:
        'Consulta IXC incompleta ou indisponivel. Nenhum numero foi alterado.' }] };
    }
  });
  mcp.registerTool('ixc_voip_consultar_contrato', {
    description: 'Consulta por ID um contrato e seus registros do produto 74 no IXC. Somente leitura, sem titular e sem autorizar vinculo automatico. Requer as mesmas permissoes IXC do MCP atual.',
    inputSchema: {
      contrato_id: z.string().regex(idPattern),
    },
  }, async ({ contrato_id }) => {
    try {
      const result = await queryVoipContract(client, contrato_id);
      return { content: [{ type: 'text', text: JSON.stringify(result) }] };
    } catch {
      return { isError: true, content: [{ type: 'text', text:
        'Consulta IXC indisponivel ou nao validada. Nenhum vinculo foi alterado.' }] };
    }
  });
}
