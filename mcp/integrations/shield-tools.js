export function registerShieldTools(mcp, client) {
  const routes = {
    shield_fluxo: 'flowStatus',
    shield_historico: 'historySummary',
    shield_politicas: 'policies',
    shield_mitigacoes: 'mitigations',
  };
  for (const [tool, endpoint] of Object.entries(routes)) {
    mcp.registerTool(tool, { description: 'Consulta de leitura do JRC SHIELD: ' + endpoint }, async () => {
      try {
        const result = await client.get(endpoint);
        return { content: [{ type: 'text', text: JSON.stringify(result) }] };
      } catch (error) {
        return { isError: true, content: [{ type: 'text', text: 'Falha na consulta SHIELD: ' + String(error.message || error) }] };
      }
    });
  }
}
