import test from 'node:test';
import assert from 'node:assert/strict';
import { createIxcHealthTool } from '../pabx-ixc-health.js';

const read = async (tool) => JSON.parse((await tool()).content[0].text);
test('disabled without configuration', async () => {
  assert.equal((await read(createIxcHealthTool({ env: {} }))).status, 'nao_configurado');
});
test('rejects insecure endpoint', async () => {
  assert.equal((await read(createIxcHealthTool({ env: {
    PABX_IXC_DIAGNOSTIC_URL: 'http://example.org/health',
    PABX_IXC_DIAGNOSTIC_TOKEN: 'secret',
  } }))).status, 'configuracao_invalida');
});
test('reports status without returning sensitive response body', async () => {
  const tool = createIxcHealthTool({ env: {
    PABX_IXC_DIAGNOSTIC_URL: 'https://pabx.example.org/diagnostico',
    PABX_IXC_DIAGNOSTIC_TOKEN: 'secret',
  }, fetchImpl: async (url, opts) => {
    assert.equal(opts.method, 'GET');
    assert.equal(opts.redirect, 'error');
    assert.equal(opts.headers.Authorization, 'Bearer secret');
    return { ok: false, status: 401, text: async () => { throw new Error('body must not be read'); } };
  } });
  const value = await read(tool);
  assert.equal(value.http_status, 401);
  assert.equal(value.autenticacao, 'recusada');
  assert.ok(!JSON.stringify(value).includes('secret'));
});
