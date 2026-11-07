import test from 'node:test';
import assert from 'node:assert/strict';
import { queryVoipContract, registerIxcVoipTools } from './ixc-voip-tools.js';

function client(contractPages, productPages) {
  const calls = [];
  return {
    calls,
    async list(resource, opts) {
      calls.push({ resource, ...opts });
      const page = (resource === 'contrato' ? contractPages : productPages)[opts.page - 1];
      if (!page) throw new Error('Unexpected page');
      return page;
    },
  };
}
const contract = { id: '1520', status: 'A', id_cliente: '999', nome: 'DADO PRIVADO' };
const product = { id: '77', id_produto: '74', id_contrato: '1520', descricao: 'DADO PRIVADO' };
const page = (registros, total = registros.length) => ({ registros, total });

test('consulta um contrato e valida o produto 74, omitindo dados pessoais', async () => {
  const api = client([page([contract])], [page([product])]);
  const out = await queryVoipContract(api, '1520');
  assert.deepEqual(out, {
    contrato_id: '1520', encontrado: true, status_ixc_bruto: 'A',
    status_validado: false, produto_74: true,
    registros_produto_74: [{ registro_id: '77', produto_id: '74' }],
    somente_leitura: true, vinculo_autorizado: false,
  });
  assert.equal(api.calls.length, 2);
  assert.deepEqual(api.calls[1].filters, [
    { field: 'id_contrato', oper: '=', value: '1520' },
    { field: 'id_produto', oper: '=', value: '74' },
  ]);
  assert.equal(JSON.stringify(out).includes('DADO PRIVADO'), false);
});

test('contrato inexistente nao realiza segunda consulta', async () => {
  const api = client([page([], 0)], []);
  assert.deepEqual(await queryVoipContract(api, '1520'), {
    contrato_id: '1520', encontrado: false, produto_74: null,
  });
  assert.equal(api.calls.length, 1);
});

test('contrato existente sem produto 74 nao autoriza vinculo', async () => {
  const result = await queryVoipContract(client([page([contract])], [page([], 0)]), '1520');
  assert.equal(result.produto_74, false);
  assert.equal(result.vinculo_autorizado, false);
});

test('snapshot de produtos paginado com total coerente', async () => {
  const many = Array.from({ length: 21 }, (_, i) => ({
    id: String(i + 1), id_contrato: '1520', id_produto: '74',
  }));
  const result = await queryVoipContract(client([page([contract])],
    [page(many.slice(0, 20), 21), page(many.slice(20), 21)]), '1520');
  assert.equal(result.registros_produto_74.length, 21);
});

test('falha fechada em total ausente ou paginacao inconsistente', async () => {
  await assert.rejects(queryVoipContract(client([{ registros: [contract] }], []), '1520'));
  await assert.rejects(queryVoipContract(client([page([contract])],
    [page([product], 2), page([], 2)]), '1520'));
});

test('falha fechada em filtro violado, duplicidade e status desconhecido', async () => {
  await assert.rejects(queryVoipContract(client([page([contract])],
    [page([{ ...product, id_produto: '75' }])]), '1520'));
  await assert.rejects(queryVoipContract(client([page([contract])],
    [page([product, product])]), '1520'));
  await assert.rejects(queryVoipContract(client([page([{ ...contract, status: null }])],
    [page([product])]), '1520'));
});

test('IDs de consulta invalidos sao rejeitados', async () => {
  const api = client([], []);
  for (const value of ['abc', '1 OR 1=1', '', '1-2']) {
    await assert.rejects(queryVoipContract(api, value));
  }
  assert.equal(api.calls.length, 0);
});

test('handler de ferramenta nao divulga detalhes de erro', async () => {
  let register;
  const mock = { registerTool(name, spec, handler) {
    assert.equal(name, 'ixc_voip_consultar_contrato');
    assert.ok(spec.inputSchema.contrato_id);
    register = handler;
  }};
  registerIxcVoipTools(mock, {
    async list() { throw new Error('SEGREDO - nao pode vazar'); },
  });
  const out = await register({ contrato_id: '1520' });
  assert.equal(out.isError, true);
  assert.equal(JSON.stringify(out).includes('SEGREDO'), false);
});
