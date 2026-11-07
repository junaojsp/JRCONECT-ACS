import test from 'node:test';
import assert from 'node:assert/strict';
import { listVoipProductContracts, registerIxcVoipTools } from '../integrations/ixc-voip-tools.js';

function page(records) { return { total: records.length, registros: records }; }
function fakeClient({ products = [], contracts = [], customers = [] } = {}) {
  return {
    calls: [],
    async list(resource, query) {
      this.calls.push({ resource, query });
      const rows = resource === 'produtos_contrato' ? products
        : resource === 'contrato' ? contracts : resource === 'cliente' ? customers : [];
      const filtered = rows.filter(row =>
        (query.filters || []).every(f => String(row[f.field]) === String(f.value)));
      return { total: filtered.length, registros: filtered.slice((query.page - 1) * query.limit, query.page * query.limit) };
    },
  };
}

test('lista produto 74, deduplica contrato e consulta nome do cliente', async () => {
  const client = fakeClient({
    products: [
      { id: '562', id_contrato: '4099', id_produto: '74' },
      { id: '563', id_contrato: '4099', id_produto: '74' },
      { id: '564', id_contrato: '4100', id_produto: '74' },
      { id: '565', id_contrato: '9000', id_produto: '10' },
    ],
    contracts: [
      { id: '4099', id_cliente: '55', status: 'A' },
      { id: '4100', id_cliente: '56', status: 'I' },
    ],
    customers: [
      { id: '55', razao: 'Cliente Exemplo' },
      { id: '56', nome: 'Outra Pessoa' },
    ],
  });
  const result = await listVoipProductContracts(client);
  assert.equal(result.total_registros_produto, 3);
  assert.equal(result.total_contratos, 2);
  assert.equal(result.contratos[0].nome_cliente, 'Cliente Exemplo');
  assert.deepEqual(result.contratos[0].registros_produto_74, ['562', '563']);
  assert.equal(result.contratos[0].status_ixc_bruto, 'A');
  assert.equal(result.contratos[0].status_validado, false);
  assert.equal(result.contratos[1].nome_cliente, 'Outra Pessoa');
  assert.equal(result.liberacao_automatica, false);
  assert.equal(client.calls.some(call => call.resource === 'cliente'), true);
});

test('cliente sem nome ou id nao inventa correspondencia', async () => {
  const result = await listVoipProductContracts(fakeClient({
    products: [{ id:'1', id_produto:'74', id_contrato:'2' }],
    contracts: [{ id:'2', status:'A' }],
  }));
  assert.equal(result.contratos[0].nome_cliente, null);
  assert.equal(result.contratos[0].titular_validado, false);
});

test('recusa pagina incompleta e evita falso estoque disponivel', async () => {
  const client = {
    async list(resource) {
      if (resource === 'produtos_contrato') return { total: 13, registros: [] };
      throw new Error('should not happen');
    },
  };
  await assert.rejects(listVoipProductContracts(client), /parcial/);
});

test('recusa registro retornado fora do filtro produto 74', async () => {
  const client = {
    async list() { return page([{ id:'1', id_produto:'99', id_contrato:'3' }]); },
  };
  await assert.rejects(listVoipProductContracts(client), /Filtro do produto ignorado/);
});

test('registra nova ferramenta e falha fechada sem expor erro IXC', async () => {
  const registered = new Map();
  registerIxcVoipTools({registerTool(name, _schema, handler) {registered.set(name,handler);}}, {
    async list() {throw new Error('token secreto');},
  });
  assert.ok(registered.has('ixc_voip_consultar_contrato'));
  assert.ok(registered.has('ixc_voip_listar_contratos_produto74'));
  const result = await registered.get('ixc_voip_listar_contratos_produto74')({});
  assert.equal(result.isError, true);
  assert.doesNotMatch(JSON.stringify(result), /token secreto/);
});
