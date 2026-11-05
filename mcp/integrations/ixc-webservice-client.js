import { readFileSync } from 'node:fs';
import { request } from 'node:https';

const resources = Object.freeze({
  cliente: 'cliente',
  contrato: 'cliente_contrato',
  financeiro: 'fn_areceber',
});

export function createIxcWebserviceClient({
  baseUrl = process.env.IXC_WS_BASE_URL || 'https://cda.jrconect.com',
  tokenFile = '/run/secrets/ixc-webservice-token',
} = {}) {
  const url = new URL(baseUrl);
  if (url.protocol !== 'https:' || url.pathname !== '/' || url.username || url.password || url.search || url.hash) {
    throw new Error('IXC deve usar URL HTTPS base sem caminho');
  }

  async function list(resource, { id = '1', page = 1, limit = 1 } = {}) {
    const table = resources[resource];
    if (!table) throw new Error('Recurso IXC nao autorizado');
    if (typeof id !== 'string' || !/^\d{1,12}$/.test(id)) throw new Error('ID invalido');
    if (!Number.isInteger(page) || page < 1 || page > 10000) throw new Error('Pagina invalida');
    if (!Number.isInteger(limit) || limit < 1 || limit > 20) throw new Error('Limite invalido');

    const credential = readFileSync(tokenFile, 'utf8').trim();
    // IXC Webservice commonly uses Basic Auth with the API token as its password.
    // The username is required because the API expects HTTP Basic credentials.
    const username = process.env.IXC_WS_USERNAME;
    if (!username || /[\r\n:]/.test(username)) throw new Error('IXC_WS_USERNAME ausente ou invalido');
    if (!credential || /\s/.test(credential)) throw new Error('Token IXC invalido');
    const data = JSON.stringify({
      qtype: table + '.id',
      query: id,
      oper: '>=',
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
          Authorization: 'Basic ' + Buffer.from(username + ':' + credential).toString('base64'),
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
