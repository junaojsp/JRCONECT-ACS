// Diagnostic proxy only. Never accepts CPF, credentials, URL or HTTP method from callers.
const DEFAULT_TIMEOUT_MS = 5000;
export function createIxcHealthTool({ env = process.env, fetchImpl = fetch } = {}) {
  return async () => {
    const url = env.PABX_IXC_DIAGNOSTIC_URL;
    const token = env.PABX_IXC_DIAGNOSTIC_TOKEN;
    if (!url || !token) return result({ status: 'nao_configurado', detalhe: 'Endpoint privado de diagnostico do PABX nao configurado' });
    let parsed;
    try {
      parsed = new URL(url);
      if (parsed.protocol !== 'https:' || parsed.username || parsed.password || parsed.search || parsed.hash ||
          !/^[a-z0-9.-]+$/i.test(parsed.hostname) || parsed.hostname === 'localhost') throw new Error('URL invalida');
    } catch {
      return result({ status: 'configuracao_invalida' });
    }
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), DEFAULT_TIMEOUT_MS);
    const start = performance.now();
    try {
      const response = await fetchImpl(parsed.toString(), {
        method: 'GET', redirect: 'error', signal: controller.signal,
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      });
      const duration = Math.round(performance.now() - start);
      // Do not read or return response body: may contain secrets, customer data or upstream HTML.
      return result({ status: response.ok ? 'respondendo' : 'erro_http',
        http_status: response.status, tempo_ms: duration,
        autenticacao: response.status === 401 || response.status === 403 ? 'recusada' : 'nao_verificada' });
    } catch (error) {
      return result({ status: error?.name === 'AbortError' ? 'timeout' : 'falha_conexao' });
    } finally {
      clearTimeout(timer);
    }
  };
}
function result(data) {
  return { content: [{ type: 'text', text: JSON.stringify(data) }] };
}
