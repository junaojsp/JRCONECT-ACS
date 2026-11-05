import { z } from 'zod';
const actions = Object.freeze({
  financeiro_criar_conta:'account.create', financeiro_editar_conta:'account.update',
  financeiro_registrar_pagamento:'account.paid', financeiro_excluir_conta:'account.delete',
  financeiro_criar_fornecedor:'supplier.create', financeiro_editar_fornecedor:'supplier.update',
  financeiro_criar_categoria:'category.create', financeiro_editar_categoria:'category.update',
});
export function registerFinanceAdminTools(mcp, client) {
  for (const [name, action] of Object.entries(actions)) {
    mcp.registerTool(name, {
      description: 'Operacao administrativa empresarial '+action+'. Exige autorizacao explicita do usuario antes de cada execucao. Nao opera em contas pessoais.',
      inputSchema: {
        id: z.number().int().positive().optional(),
        payload: z.record(z.string(),z.unknown()).default({}),
        confirmacao: z.literal('CONFIRMAR_OPERACAO_FINANCEIRA'),
      },
    }, async ({id,payload,confirmacao}) => {
      if (confirmacao !== 'CONFIRMAR_OPERACAO_FINANCEIRA') return {isError:true,content:[{type:'text',text:'Confirmacao obrigatoria'}]};
      try {
        const result = await client.execute(action,id,payload);
        return {content:[{type:'text',text:JSON.stringify(result)}]};
      } catch(error) {
        return {isError:true,content:[{type:'text',text:String(error.message||error)}]};
      }
    });
  }
}
