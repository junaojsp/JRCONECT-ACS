import { z } from 'zod';

const id = z.string().regex(/^[a-f0-9]{24}$/i);
const confirm = z.literal('CONFIRMAR_ACAO_OPA');
// Every call performs a real remote administrative action.
// Authorization by JWT subject and role must occur BEFORE these tools are registered.
export function registerOpaApiAdminTools(mcp, client) {
  const commands = [
    ['opa_adicionar_observacao', 'observation.add', 'Adiciona uma observacao interna no atendimento Opa.', z.string().min(1).max(2000)],
    ['opa_adicionar_etiqueta', 'tag.add', 'Adiciona etiqueta ao atendimento Opa.', id],
    ['opa_adicionar_motivo', 'reason.add', 'Adiciona motivo ao atendimento Opa.', id],
    ['opa_enviar_mensagem', 'message.text', 'Envia mensagem de texto real a um atendimento Opa.', z.string().min(1).max(2000)],
  ];
  for (const [name, action, description, valueSchema] of commands) {
    mcp.registerTool(name, {
      description: description + ' Requer confirmacao especifica do operador antes da chamada.',
      inputSchema: { atendimento_id: id, valor: valueSchema, confirmacao: confirm },
    }, async ({atendimento_id, valor}) => {
      try {
        const data = await client.execute(action, atendimento_id, valor);
        return {content:[{type:'text',text:JSON.stringify(data)}]};
      } catch (e) {
        return {isError:true,content:[{type:'text',text:String(e.message || e)}]};
      }
    });
  }
  mcp.registerTool('opa_remover_etiqueta', {
    description: 'Remove etiqueta de um atendimento. Exige aprovacao explicita do operador.',
    inputSchema: { atendimento_id: id, etiqueta_id: id, confirmacao: confirm },
  }, async ({atendimento_id, etiqueta_id}) => {
    try {
      const data = await client.execute('tag.remove', atendimento_id, undefined, etiqueta_id);
      return {content:[{type:'text',text:JSON.stringify(data)}]};
    } catch (e) {
      return {isError:true,content:[{type:'text',text:String(e.message || e)}]};
    }
  });
}
