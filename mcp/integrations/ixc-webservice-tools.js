import { z } from 'zod';

export function registerIxcWebserviceTools(mcp, client) {
  const resources = [
    ['ixc_clientes', 'cliente', 'Consulta clientes no IXC Provedor'],
    ['ixc_contratos', 'contrato', 'Consulta contratos no IXC Provedor'],
    ['ixc_financeiro', 'financeiro', 'Consulta titulos financeiros no IXC Provedor'],
  ];
  for (const [name, resource, description] of resources) {
    mcp.registerTool(name, {
      description: description + '. Dados pessoais; apenas usuario administrativo autorizado.',
      inputSchema: {
        id_inicial: z.string().regex(/^\d{1,12}$/).default('1'),
        pagina: z.number().int().min(1).max(10000).default(1),
        limite: z.number().int().min(1).max(20).default(1),
      },
    }, async ({ id_inicial, pagina, limite }) => {
      try {
        const result = await client.list(resource, { id: id_inicial, page: pagina, limit: limite });
        return { content: [{ type:'text', text: JSON.stringify(result) }] };
      } catch (error) {
        return { isError:true, content:[{ type:'text', text:String(error.message || error) }] };
      }
    });
  }
}
