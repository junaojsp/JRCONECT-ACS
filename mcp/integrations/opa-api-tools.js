import { z } from 'zod';
export function registerOpaApiTools(mcp, client) {
  mcp.registerTool('opa_canais', {
    description: 'Lista canais de comunicacao da API Opa Suite; consulta somente leitura para operador autorizado.',
  }, async () => {
    try { return { content: [{type:'text',text:JSON.stringify(await client.get('channels'))}] }; }
    catch (e) { return {isError:true,content:[{type:'text',text:String(e.message || e)}]}; }
  });
  mcp.registerTool('opa_atendimento', {
    description: 'Consulta um atendimento especifico do Opa Suite por ID; pode conter dados pessoais.',
    inputSchema: { id: z.string().regex(/^[a-f\d]{24}$/i) },
  }, async ({id}) => {
    try { return { content: [{type:'text',text:JSON.stringify(await client.get('attendance', id))}] }; }
    catch (e) { return {isError:true,content:[{type:'text',text:String(e.message || e)}]}; }
  });
}
