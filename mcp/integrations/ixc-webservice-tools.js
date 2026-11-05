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
        id_inicial: z.string().regex(/^\d{1,12}$/).default('1'),
        pagina: z.number().int().min(1).max(10000).default(1),
        limite: z.number().int().min(1).max(20).default(1),
      },
    }, async ({id_inicial = '1', pagina = 1, limite = 1} = {}) => {
      try {
        const result = await client.list(resource, {id: id_inicial, page: pagina, limit: limite});
        return {content:[{type:'text',text:JSON.stringify(summarize(result,resource))}]};
      } catch (error) {
        const message = String(error?.message || '');
        const status = /^IXC HTTP (\d{3})$/.exec(message);
        const reason = status ? 'IXC HTTP ' + status[1] : 'Erro na consulta IXC. Verificar logs no ACS.';
        return {isError:true,content:[{type:'text',text:reason}]};
      }
    });
  }

  // Consulta apenas contagens agregadas. O filtro != F significa
  // "nao finalizadas" e NAO equivale necessariamente a "suporte em aberto".
  mcp.registerTool('ixc_os_resumo', {
    description: 'Resumo agregado de OS no IXC: total, finalizadas (F) e nao finalizadas (status diferente de F). Nao segmenta por setor de suporte, nao retorna dados pessoais e nao altera OS.',
  }, async () => {
    const checks = [];
    for (const [nome, filtro] of [['todas', null], ['finalizadas', 'F'], ['nao_finalizadas', '!F']]) {
      try {
        const result = await client.list('os', {id:'1', page:1, limit:1, osStatus:filtro});
        const summary = summarize(result, 'os');
        if (summary.total_registros === null) throw new Error('Total ausente');
        checks.push({grupo:nome, ok:true, total:summary.total_registros});
      } catch (error) {
        const status = /^IXC HTTP (\\d{3})$/.exec(String(error?.message || ''));
        checks.push({grupo:nome,ok:false,erro:status?'HTTP '+status[1]:'erro de consulta'});
      }
    }
    return {content:[{type:'text',text:JSON.stringify({
      recurso:'su_oss_chamado',
      consulta:'somente leitura',
      escopo:'todas as OS; sem filtro de setor de suporte',
      criterio_nao_finalizadas:'status != F',
      contagens:checks,
      observacao:'Validar codigos de status e ID do setor Suporte no IXC antes de chamar este numero de OS de suporte em aberto.'
    })}]};
  });

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
        const code = /^IXC HTTP (\d{3})$/.exec(String(error?.message || ''));
        checks.push({recurso:resource,ok:false,erro:code?'HTTP '+code[1]:'erro de API'});
      }
    }
    return {content:[{type:'text',text:JSON.stringify({sistema:'IXC Webservice',consultas:checks})}]};
  });
}
