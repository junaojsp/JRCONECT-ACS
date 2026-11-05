import { z } from 'zod';

export function registerFinanceTools(mcp, client) {
  const simple = [
    ['financeiro_resumo', 'summary', 'Resumo das contas empresariais do Financeiro'],
    ['financeiro_fornecedores', 'suppliers', 'Consulta nomes de fornecedores'],
    ['financeiro_categorias', 'categories', 'Consulta categorias empresariais'],
  ];
  for (const [name, route, description] of simple) {
    mcp.registerTool(name, { description }, async () => {
      try { return { content: [{ type: 'text', text: JSON.stringify(await client.get(route)) }] }; }
      catch (e) { return { isError: true, content: [{ type: 'text', text: String(e.message || e) }] }; }
    });
  }
  mcp.registerTool('financeiro_contas', {
    description: 'Lista contas empresariais do Financeiro; nunca inclui contas pessoais nem codigos de pagamento',
    inputSchema: { status: z.string().max(40).optional(), due_from: z.string().regex(/^\\d{4}-\\d{2}-\\d{2}$/).optional(), due_to: z.string().regex(/^\\d{4}-\\d{2}-\\d{2}$/).optional(), limit: z.number().int().min(1).max(100).optional() },
  }, async (filters) => {
    try { return { content: [{ type: 'text', text: JSON.stringify(await client.get('accounts', filters)) }] }; }
    catch (e) { return { isError: true, content: [{ type: 'text', text: String(e.message || e) }] }; }
  });
}
