import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { timingSafeEqual } from 'node:crypto';
import { createMcpHandler, McpServer } from '@modelcontextprotocol/sdk/server';
import { toNodeHandler } from '@modelcontextprotocol/node';
import { validarToken } from './auth.js';

const opaToken = readFileSync('/run/secrets/opa-token', 'utf8').trim();
if (!/^[a-f0-9]{64}$/i.test(opaToken)) {
  throw new Error('Segredo Opa ausente ou formato inesperado');
}
const expected = Buffer.from(opaToken, 'utf8');
function validarTokenOpa(token) {
  // Tokens arbitrariamente grandes nao devem consumir memoria sem limite.
  if (typeof token !== 'string' || token.length !== opaToken.length) return false;
  const received = Buffer.from(token, 'utf8');
  return received.length === expected.length && timingSafeEqual(received, expected);
}

const handler = createMcpHandler(() => {
  const mcp = new McpServer({ name: 'jrconect-opa-mcp', version: '1.0.0' });
  mcp.registerTool('status_servidor', {
    description: 'Verifica o funcionamento do MCP JR Conect',
  }, async () => ({
    content: [{ type: 'text', text: 'Servidor MCP JR Conect funcionando.' }],
  }));
  return mcp;
});
const mcpHandler = toNodeHandler(handler);

function unauthorized(res, reason = 'unauthorized') {
  if (res.headersSent) return;
  res.writeHead(401, {
    'Content-Type': 'application/json',
    'WWW-Authenticate': 'Bearer realm="jrconect-mcp"',
    'Cache-Control': 'no-store',
  });
  res.end(JSON.stringify({ error: reason }));
}

createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok' }));
    return;
  }
  if (req.url?.split('?')[0] !== '/mcp') {
    res.writeHead(404); res.end('Not Found'); return;
  }
  const match = /^Bearer ([^\s]+)$/i.exec(req.headers.authorization || '');
  if (!match) return unauthorized(res);
  if (validarTokenOpa(match[1])) {
    req.mcpUser = { sub: 'opa-suite', client: 'opa-suite' };
    return mcpHandler(req, res);
  }
  try {
    const payload = await validarToken(match[1]);
    req.mcpUser = { sub: payload.sub, client: payload.azp };
    return mcpHandler(req, res);
  } catch {
    return unauthorized(res, 'invalid_token');
  }
}).listen(3100, '0.0.0.0', () => {
  console.log('JR Conect MCP listening on 3100');
});
