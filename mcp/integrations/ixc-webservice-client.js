import { readFileSync } from 'node:fs';
import { request } from 'node:https';

const resources = Object.freeze({
  cliente: 'cliente',
  contrato: 'cliente_contrato',
  financeiro: 'fn_areceber',
  os: 'su_oss_chamado',
  assuntos_os: 'su_oss_assunto',
  diagnosticos_os: 'su_diagnostico',
  tickets: 'su_ticket',
  planos: 'vd_contratos',
  produtos_contrato: 'vd_contratos_produtos',
  descontos_contrato: 'cliente_contrato_descontos',
  servicos_contrato: 'cliente_contrato_servicos',
  produtos: 'produtos',
  estoque: 'view_prod_estoque_almox',
  logins: 'radusuarios',
  vendedores: 'vendedor',
  funcionarios: 'funcionarios',
});

export function createIxcWebserviceClient({
  baseUrl = process.env.IXC_WS_BASE_URL || 'https://cda.jrconect.com',
  tokenFile = '/run/secrets/ixc-webservice-token',
} = {}) {
  const url = new URL(baseUrl);
  if (url.protocol !== 'https:' || url.pathname !== '/' || url.username || url.password || url.search || url.hash) {
    throw new Error('IXC deve usar URL HTTPS base sem caminho');
  }

  async function list(resource, { id = '1', page = 1, limit = 1, osStatus = null, filters = [] } = {}) {
    const table = resources[resource];
    if (!table) throw new Error('Recurso IXC nao autorizado');
    if (osStatus !== null && (resource !== 'os' || !['F', '!F'].includes(osStatus))) {
      throw new Error('Filtro de OS nao autorizado');
    }
    // No unrestricted endpoint paths, fields or filters. Only whitelisted
    // aggregate queries are exposed by ixc-report-tools.js.
    if (!Array.isArray(filters) || filters.length > 3) throw new Error('Filtros IXC invalidos');
    for (const f of filters) {
      if (!f || typeof f.field !== 'string' || !/^[a-z_]{1,45}$/.test(f.field) ||
          !['=', '!=', '>=', '<=', 'IN'].includes(f.oper) ||
          typeof f.value !== 'string' || !/^[A-Za-z0-9,\/.-]{1,60}$/.test(f.value)) {
        throw new Error('Filtro IXC invalido');
      }
    }
    if (filters.length && osStatus !== null) throw new Error('Filtros IXC incompativeis');
    if (typeof id !== 'string' || !/^\d{1,12}$/.test(id)) throw new Error('ID invalido');
    if (!Number.isInteger(page) || page < 1 || page > 10000) throw new Error('Pagina invalida');
    if (!Number.isInteger(limit) || limit < 1 || limit > 20) throw new Error('Limite invalido');

    const credential = readFileSync(tokenFile, 'utf8').trim();
    // IXC's Basic credential is installation-specific. Accept a credential
    // stored as username:password in the secret file, or an explicit username
    // configured separately from the token. Never assume OAuth credentials work.
    const username = process.env.IXC_WS_USERNAME;
    const basicPair = username ? username + ':' + credential : credential;
    if (!basicPair.includes(':') || /[\r\n]/.test(basicPair)) {
      throw new Error('Credencial IXC Basic incompleta: requer usuario:token');
    }
    const data = JSON.stringify({
      qtype: filters.length ? table + '.' + filters[0].field : (osStatus ? table + '.status' : table + '.id'),
      query: filters.length ? filters[0].value : (osStatus ? 'F' : id),
      oper: filters.length ? filters[0].oper : (osStatus === '!F' ? '!=' : (osStatus === 'F' ? '=' : '>=')),
      ...(filters.length > 1 ? {grid_param: JSON.stringify(filters.slice(1).map(f => ({
        TB: table + '.' + f.field, OP: f.oper, P: f.value,
      })))} : {}),
      page: String(page),
      rp: String(limit),
      sortname: table + '.id',
      sortorder: 'desc',
    });
    const endpoint = new URL('/webservice/v1/' + table, url);
    return new Promise((resolve, reject) => {
      const req = request(endpoint, {
        method: 'GET',
        timeout: 12000,
        headers: {
          Authorization: 'Basic ' + Buffer.from(basicPair).toString('base64'),
          ixcsoft: 'listar',
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(data),
        },
      }, res => {
        let raw = '';
        res.setEncoding('utf8');
        res.on('data', part => {
          raw += part;
          if (raw.length > 1024 * 1024) req.destroy(new Error('Resposta IXC grande demais'));
        });
        res.on('end', () => {
          if (res.statusCode !== 200) return reject(new Error('IXC HTTP ' + res.statusCode));
          if (!String(res.headers['content-type'] || '').includes('json')) return reject(new Error('Resposta IXC nao JSON'));
          try { resolve(JSON.parse(raw)); } catch { reject(new Error('JSON IXC invalido')); }
        });
        res.on('error', reject);
      });
      req.on('timeout', () => req.destroy(new Error('Timeout IXC')));
      req.on('error', reject);
      req.end(data);
    });
  }
  return { list };
}
