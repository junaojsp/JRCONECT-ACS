import assert from 'node:assert/strict';
import { pabxAdminAuthorized } from '../pabx-admin.js';

// Nao depende de token real do PABX nem de servico remoto.
process.env.PABX_ADMIN_MCP_ENABLED = 'true';
process.env.PABX_ADMIN_ALLOWED_SUB = 'usuario-aprovado';
process.env.PABX_ADMIN_ROLE = 'jrconect-pabx-admin';

const admin = {
  sub: 'usuario-aprovado',
  azp: 'chatgpt-mcp',
  realm_access: { roles: ['jrconect-pabx-admin'] },
};

assert.equal(pabxAdminAuthorized(admin), true);
assert.equal(pabxAdminAuthorized({ ...admin, azp: 'opa-suite' }), false);
assert.equal(pabxAdminAuthorized({ ...admin, sub: 'outro' }), false);
assert.equal(pabxAdminAuthorized({ ...admin, realm_access: { roles: ['user'] } }), false);
assert.equal(pabxAdminAuthorized({ ...admin, sub: null }), false);

process.env.PABX_ADMIN_MCP_ENABLED = 'false';
assert.equal(pabxAdminAuthorized(admin), false);

console.log('PABX_ADMIN_RBAC_OK');
