// Exercise the actual dashboard script with HTTP/error responses and a minimal DOM.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/dashboard-noc.js'), 'utf8');
async function test(response, expected, succeeds = false) {
  const nodes = new Map();
  const node = id => {
    if (!nodes.has(id)) nodes.set(id, {textContent: id === 'noc-total' ? '42' : '', style: {}, setAttribute() {}, addEventListener(event, fn) {this.click = fn;}});
    return nodes.get(id);
  };
  vm.runInNewContext(source, {
    document: {hidden: false, getElementById: node},
    window: {setInterval() {}}, fetch: async () => response,
  });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(node('noc-refresh').disabled, false);
  if (succeeds) {
    assert.equal(node('noc-health').textContent, 'ACS RESPONDENDO');
    assert.equal(node('noc-total').textContent, '0');
  } else {
    assert.match(node('noc-error').textContent, expected);
    assert.equal(node('noc-error').hidden, false);
    assert.equal(node('noc-total').textContent, '42');
    assert.equal(node('noc-health').textContent, 'DADOS INDISPONÍVEIS');
  }
}
const response = (status, data, type = 'application/json') => ({
  status, ok: status >= 200 && status < 300, redirected: false,
  headers: {get: () => type}, json: async () => {
    if (data === 'INVALID') throw new SyntaxError('Unexpected end of JSON input');
    return data;
  },
});
(async () => {
  await test(response(500, 'INVALID'), /HTTP 500/);
  await test(response(502, 'INVALID', 'text/html'), /HTTP 502/);
  await test(response(200, 'INVALID'), /Resposta inválida/);
  await test(response(200, null), /Resposta inválida/);
  await test(response(401, {}), /Sessão expirada/);
  await test({...response(200, {}, 'text/html'), redirected: true}, /Sessão expirada/);
  await test(response(503, {success: false, message: 'GenieACS não configurado.'}), /não configurado/);
  await test(response(200, {success: true, summary: {}, issues: []}), null, true);
  console.log('PASS 8 frontend cases; last valid numbers preserved on failures');
})().catch(error => {console.error(error); process.exitCode = 1;});
