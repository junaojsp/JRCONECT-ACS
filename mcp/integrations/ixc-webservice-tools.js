import { z } from 'zod';

// No raw customer, contract or financial records are returned in these
// diagnostic queries. This is data minimization, NOT an authorization bypass.
function summarize(result, resource) {
  if (!result || typeof result !== 'object') throw new Error('IXC: resposta inesperada');
  const rows = Array.isArray(result.registros)
    ? result.registros
    : Array.isArray(result.data) ? result.data : [];
  const rawTotal = result.total ?? result.total_registros ?? null;
  const total = rawTotal === null ? null : Number(rawTotal);
  const status = String(result.status || '').toLowerCase();
  if (status === 'error' || result.success === false || result.error) {
    throw new Error('IXC retornou erro de aplicacao');
  }
  return {
    recurso: resource,
    consulta: 'somente leitura',
    resposta_api: 'valida',
    total_registros: Number.isFinite(total) && total >= 0 ? total : null,
    itens_na_pagina: rows.length,
    dados_pessoais: 'omitidos',
    escrita_administrativa: 'nao habilitada',
  };
}

export function registerIxcWebserviceTools(mcp, client) {
  const resources = [
    ['ixc_clientes', 'cliente', 'Resumo da disponibilidade de clientes no IXC, sem dados pessoais'],
    ['ixc_contratos', 'contrato', 'Resumo da disponibilidade de contratos no IXC, sem dados pessoais'],
    ['ixc_financeiro', 'financeiro', 'Resumo da disponibilidade de titulos no IXC, sem dados pessoais'],
  ];
  for (const [name, resource, description] of resources) {
    mcp.registerTool(name, {
      description: description + '. Apenas contagens e estado da consulta, para operador OAuth autorizado.',
      inputSchema: {
        id_inicial: z.string().regex(/^\\d{1,12}$/).default('1'),
        pagina: z.number().int().min(1).max(10000).default(1),
        limite: z.number().int().min(1).max(20).default(1),
      },
    }, async ({id_inicial = '1', pagina = 1, limite = 1} = {}) => {
      try {
        const result = await client.list(resource, {id: id_inicial, page: pagina, limit: limite});
        return {content:[{type:'text',text:JSON.stringify(summarize(result,resource))}]};
      } catch (error) {
        const message = String(error?.message || '');
        const status = /^IXC HTTP (\\d{3})$/.exec(message);
        const reason = status ? 'IXC HTTP ' + status[1] : 'Erro na consulta IXC. Verificar logs no ACS.';
        return {isError:true,content:[{type:'text',text:reason}]};
      }
    });
  }

  mcp.registerTool('ixc_diagnostico_api', {
    description: 'Diagnostico agregado das tres rotas IXC (clientes, contratos, financeiro). Nao retorna cadastro ou dados pessoais.',
  }, async () => {
    const checks = [];
    for (const resource of ['cliente','contrato','financeiro']) {
      try {
        const response = await client.list(resource, {id:'1',page:1,limit:1});
        const summary = summarize(response,resource);
        checks.push({recurso:resource,ok:true,total_registros:summary.total_registros});
      } catch (error) {
        const code = /^IXC HTTP (\\d{3})$/.exec(String(error?.message || ''));
        checks.push({recurso:resource,ok:false,erro:code?'HTTP '+code[1]:'erro de API'});
      }
    }
    return {content:[{type:'text',text:JSON.stringify({sistema:'IXC Webservice',consultas:checks})}]};
  });
}
