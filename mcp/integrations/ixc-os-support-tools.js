import { z } from 'zod';

const textResult = data => ({ content: [{ type: 'text', text: JSON.stringify(data) }] });
const errorResult = msg => ({ isError: true, content: [{ type: 'text', text: msg }] });
const total = result => {
  if (!result || typeof result !== 'object' || result.error || result.success === false ||
      String(result.status || '').toLowerCase() === 'error') throw Error('IXC error');
  const n = Number(result.total ?? result.total_registros);
  if (!Number.isSafeInteger(n) || n < 0) throw Error('Invalid total');
  return n;
};
const rowsOf = result => Array.isArray(result.registros) ? result.registros :
  Array.isArray(result.data) ? result.data : [];

export function registerIxcOsSupportTools(mcp, client) {
  // Exposes only subject IDs/names with matching term. Never exposes subscriber records.
  mcp.registerTool('ixc_os_localizar_assunto', {
    description: 'Localiza IDs de assuntos de OS pela descricao no cadastro IXC. Somente assuntos coincidentes, sem dados de clientes. A pesquisa e paginada, nao e um inventario completo.',
    inputSchema: {
      termo: z.string().trim().min(2).max(40).default('Suporte'),
      pagina: z.number().int().min(1).max(10000).default(1),
    },
  }, async ({ termo = 'Suporte', pagina = 1 } = {}) => {
    try {
      const r = await client.list('assuntos_os', {id:'1',page:pagina,limit:20});
      const matches = rowsOf(r).filter(row => typeof row.assunto === 'string' &&
        row.assunto.toLocaleLowerCase('pt-BR').includes(termo.toLocaleLowerCase('pt-BR')))
        .map(row => ({id:String(row.id),assunto:row.assunto})).filter(row=>/^\d{1,12}$/.test(row.id));
      return textResult({
        recurso:'su_oss_assunto', termo, pagina, encontrados:matches,
        total_cadastro:total(r), itens_lidos:rowsOf(r).length,
        cobertura:'Apenas pagina informada. Se nenhum resultado, testar pagina seguinte; nao presumir assunto inexistente.',
      });
    } catch {
      return errorResult('Consulta de cadastro de assuntos indisponivel; validar permissao/rota no IXC.');
    }
  });

  mcp.registerTool('ixc_os_contagem_assunto_status', {
    description:'Conta OS de um ID de assunto e de um codigo de status exato no IXC. Nao presume que status F, A ou outros correspondem a rotulos de tela; validar previamente. Nao retorna dados de clientes.',
    inputSchema: {
      id_assunto:z.string().regex(/^\d{1,12}$/),
      status:z.string().regex(/^[A-Za-z0-9]{1,12}$/),
    },
  }, async ({id_assunto,status}) => {
    try {
      const r = await client.list('os', {
        id:'1',page:1,limit:20,
        filters:[
          {field:'id_assunto',oper:'=',value:id_assunto},
          {field:'status',oper:'=',value:status},
        ],
      });
      // IXC pode ignorar filtros desconhecidos: validar uma amostra em memoria
      // e sinalizar inconsistencia em vez de divulgar contagem falsa.
      const sample = rowsOf(r);
      if (sample.some(row => String(row.id_assunto) !== id_assunto || String(row.status) !== status)) {
        return errorResult('IXC retornou OS fora do assunto/status esperado: filtros combinados nao validados.');
      }
      return textResult({
        recurso:'su_oss_chamado',id_assunto,status,total:total(r),
        itens_verificados:sample.length,
        verificacao:'amostra retornada confere com filtros',
        observacao:'Confirmar codigos de status na instalacao antes de classificar como Aberta/Executando.',
        consulta:'somente leitura',
      });
    } catch {
      return errorResult('Consulta de OS por assunto/status falhou. Verificar filtro grid_param e logs ACS.');
    }
  });
}
