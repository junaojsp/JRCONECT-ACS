import { createRemoteJWKSet, jwtVerify } from 'jose';

const issuer = process.env.KEYCLOAK_ISSUER || 'https://auth.jrconect.com/realms/jrconect';
const audience = process.env.KEYCLOAK_AUDIENCE || 'jrconect-mcp';
const clientId = process.env.KEYCLOAK_CLIENT_ID || 'chatgpt-mcp';
const jwksUrl = process.env.KEYCLOAK_JWKS_URL;
if (!jwksUrl) throw new Error('KEYCLOAK_JWKS_URL nao configurada');
const jwks = createRemoteJWKSet(new URL(jwksUrl));

export async function validarToken(token) {
  if (!token || typeof token !== 'string') throw new Error('Token ausente');
  const { payload } = await jwtVerify(token, jwks, {
    issuer, audience, algorithms: ['RS256'], clockTolerance: 5,
  });
  if (payload.azp !== clientId) throw new Error('Cliente OAuth nao autorizado');
  if (!payload.sub) throw new Error('Usuario nao identificado');
  return payload;
}
