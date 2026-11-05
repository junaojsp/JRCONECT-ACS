import { z } from 'zod';

// Endpoints and fields explicitly allowlisted for aggregate reports.
// Other candidate resources in the client remain disabled until reviewed.
const catalog = Object.freeze({
  os: ['id', 'id_assunto', 'status'],
  contrato: ['id', 'status', 'id_vd_contrato'],
  financeiro: ['id', 'status', 'data_vencimento', 'id_contrato'],
  planos: ['id', 'nome'],
  produtos_contrato: ['id', 'id_produto', 'id_contrato'],
});
const fSchema = z.object({
  campo: z.string(),
  operador: z.enum(['=', '!=', '>=', '<=', 'IN']),
  valor: z.string().min(1).max(60),
}).strict();
function summary(payload) {
  if (!payload || typeof payload !== 'object' || payload.error || payload.success === false ||
      String(payload.status || '').toLowerCase() === 'error') throw Error('Falha IXC');
  const total = Number(payload.total ?? payload.total_registros);
  if (!Number.isSafeInteger(total) || total < 0) throw Error('Total ausente');
  return total;
}
export function registerIxcReportTools(mcp, client) {
  mcp.registerTool('ixc_catalogo_relatorios', {
    description: 'Lista os modulos IXC preparados para relatorios agregados. Nem todos os recursos estao validados na instalacao.',
  }, async () => ({content:[{type:'text',text:JSON.stringify({
    tipo:'catalogo', modo:'somente leitura',
    recursos:Object.entries(catalog).map(([recurso,campos])=>({recurso,campos})),
    estado:'validacao de endpoints e status pendente'
  })}]}));

  mcp.registerTool('ixc_relatorio_contagem', {
    description: 'Retorna apenas a contagem de registros do IXC para um recurso e ate 3 filtros permitidos. Sem linhas individuais.',
    inputSchema:{
      recurso:z.enum(Object.keys(catalog)),
      filtros:z.array(fSchema).max(3).default([]),
    },
  }, async ({recurso,filtros=[]}) => {
    const allowed=catalog[recurso];
    if (!allowed || filtros.some(f=>!allowed.includes(f.campo) ||
      !/^[A-Za-z0-9,\/.-]{1,60}$/.test(f.valor))) {
      return {isError:true,content:[{type:'text',text:'Filtro nao permitido'}]};
    }
    try {
      const r=await client.list(recurso,{id:'1',page:1,limit:1,
        filters:filtros.map(f=>({field:f.campo,oper:f.operador,value:f.valor}))});
      return {content:[{type:'text',text:JSON.stringify({
        recurso,filtros,total:summary(r),modo:'somente leitura',
        nota:'Validar codigos e o significado dos campos antes de classificar os resultados.'
      })}]};
    } catch {
      return {isError:true,content:[{type:'text',text:'Consulta IXC falhou ou recurso indisponivel. Verificar logs no ACS.'}]};
    }
  });
}
