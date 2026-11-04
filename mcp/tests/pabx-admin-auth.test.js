import assert from 'node:assert/strict';
import { pabxAdminAuthorized } from '../pabx-admin.js';

// Testes locais: nao usa tokens reais do PABX nem Keycloak.
const id = '01234567-89ab-4cde-8123-456789abcdef';
process.env.PABX_ADMIN_MCP_ENABLED = 'true';
process.env.PABX_ADMIN_ALLOWED_SUB = id;
process.env.PABX_ADMIN_ROLE = 'jrconect-pabx-admin';
delete process.env.PABX_ADMIN_AUTH_MODE;

const base = {
  sub: id,
  azp: 'chatgpt-mcp',
  realm_access: { roles: ['jrconect-pabx-admin'] },
};

// Modo padrao continua exigindo role+sub.
assert.equal(pabxAdminAuthorized(base), true);
assert.equal(pabxAdminAuthorized({ ...base, realm_access: { roles: [] } }), false);

// Modo single-subject depende de configuracao explicita.
process.env.PABX_ADMIN_AUTH_MODE = 'single-subject';
assert.equal(pabxAdminAuthorized({ ...base, realm_access: { roles: [] } }), true);
assert.equal(pabxAdminAuthorized({ ...base, azp: 'opa-suite' }), false);
assert.equal(pabxAdminAuthorized({ ...base, sub: 'outro' }), false);
assert.equal(pabxAdminAuthorized({ ...base, sub: null }), false);

// Falha fechada se a allowlist for ampla, ausente ou nao for UUID.
process.env.PABX_ADMIN_ALLOWED_SUB = id + ',outro';
assert.equal(pabxAdminAuthorized(base), false);
process.env.PABX_ADMIN_ALLOWED_SUB = 'admin';
assert.equal(pabxAdminAuthorized({ ...base, sub: 'admin' }), false);
process.env.PABX_ADMIN_ALLOWED_SUB = '';
assert.equal(pabxAdminAuthorized(base), false);
process.env.PABX_ADMIN_ALLOWED_SUB = id;
process.env.PABX_ADMIN_MCP_ENABLED = 'false';
assert.equal(pabxAdminAuthorized(base), false);

console.log('PABX_ADMIN_RBAC_OK');
