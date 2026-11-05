import { z } from 'zod';

const actions = [
  ['shield_atualizar_politicas', 'policies', 'ATUALIZAR_POLITICAS', 'Atualiza todas as politicas de deteccao do JRC SHIELD. Exige revisao atual.'],
  ['shield_criar_mitigacao', 'mitigationCreate', 'CRIAR_MITIGACAO', 'Cria uma mitigacao de rede no JRC SHIELD.'],
  ['shield_retirar_mitigacao', 'mitigationWithdraw', 'RETIRAR_MITIGACAO', 'Retira uma mitigacao existente no JRC SHIELD.'],
];
export function registerShieldAdminTools(mcp, client) {
  for (const [name, operation, confirmation, description] of actions) {
    mcp.registerTool(name, {
      description: description + ' Acao administrativa real; exigir autorizacao explicita do operador antes da chamada.',
      inputSchema: {
        payload: z.record(z.string(), z.unknown()).describe('Objeto JSON conforme API SHIELD; nao inclua credenciais.'),
        confirmacao: z.literal(confirmation).describe('Confirmacao explicita obrigatoria: ' + confirmation),
      },
    }, async ({ payload, confirmacao }) => {
      if (confirmacao !== confirmation) return { isError: true, content: [{ type: 'text', text: 'Confirmacao ausente' }] };
      try {
        const result = await client.execute(operation, payload);
        return { content: [{ type: 'text', text: JSON.stringify(result) }] };
      } catch (error) {
        return { isError: true, content: [{ type: 'text', text: String(error.message || error) }] };
      }
    });
  }
}
