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

export function registerIxcVoipTools(mcp, client) {
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
