import { z } from 'zod';
import { createOpaReportClient } from './opa-api-report-client.js';
export function registerOpaApiTools(mcp, client) {
  mcp.registerTool('opa_canais', {
    description: 'Lista canais de comunicacao da API Opa Suite; consulta somente leitura para operador autorizado.',
  }, async () => {
    try { return { content: [{type:'text',text:JSON.stringify(await client.get('channels'))}] }; }
    catch (e) { return {isError:true,content:[{type:'text',text:String(e.message || e)}]}; }
  });
  // Summarized only. Protected by the same Opa OAuth authorization gate.
  mcp.registerTool('opa_relatorio_atendimentos', {
    description: 'Conta atendimentos finalizados (status F e data de fim) por periodo de ate 90 dias no fuso America/Sao_Paulo. Somente leitura, nao retorna dados pessoais. Consulta o historico completo da API Opa e pode demorar.',
    inputSchema: {
      inicio: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
      fim: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    },
  }, async ({ inicio, fim }) => {
    try {
      const report = await createOpaReportClient().finalizados({inicio, fim});
      return {content:[{type:'text',text:JSON.stringify(report)}]};
    } catch (e) {
      return {isError:true,content:[{type:'text',text:String(e.message || e)}]};
    }
  });
  mcp.registerTool('opa_atendimento', {
    description: 'Consulta um atendimento especifico do Opa Suite por ID; pode conter dados pessoais.',
    inputSchema: { id: z.string().regex(/^[a-f\d]{24}$/i) },
  }, async ({id}) => {
    try { return { content: [{type:'text',text:JSON.stringify(await client.get('attendance', id))}] }; }
    catch (e) { return {isError:true,content:[{type:'text',text:String(e.message || e)}]}; }
  });
}
