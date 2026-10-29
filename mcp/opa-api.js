import { readFileSync } from 'node:fs';

// O token e carregado somente no host autorizado; nunca versionar o segredo.
const endpoint = 'https://opa.jrconect.com/api/v1/atendimento';

export async function listarAtendimentos({ limite = 1 } = {}) {
  if (!Number.isInteger(limite) || limite < 1 || limite > 10) {
    throw new Error('Limite invalido');
  }
  const token = readFileSync('/run/secrets/opa-api-token', 'utf8').trim();
  if (!token) throw new Error('Token da API do Opa nao configurado');
  const response = await fetch(endpoint, {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({ filter: {}, options: { limit: limite } }),
    signal: AbortSignal.timeout(15000),
    redirect: 'error',
  });
  if (!response.ok) throw new Error(`OPA_API_HTTP_${response.status}`);
  const payload = await response.json();
  if (payload.status !== 'success' || !Array.isArray(payload.data)) {
    throw new Error('OPA_API_RESPOSTA_INVALIDA');
  }
  // Somente metadados operacionais. Nunca devolver campos livres (observacoes,
  // descricao), dados de contato, documentos ou IDs de clientes.
  return payload.data.slice(0, limite).map(item => ({
    protocolo: item.protocolo ?? null,
    status: item.status ?? null,
    canal: item.canal ?? null,
    setor: item.setor ?? null,
    origem: item.origem ?? null,
    data: item.date ?? null,
    fim: item.fim ?? null,
  }));
}
