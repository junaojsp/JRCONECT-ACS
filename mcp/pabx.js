import { existsSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const timeoutMs = 10000;
const maxOutputBytes = 16 * 1024;

function pabxConfig() {
  const host = process.env.PABX_SSH_HOST || '';
  const user = process.env.PABX_SSH_USER || '';
  const keyPath = process.env.PABX_SSH_KEY_PATH || '';
  const knownHostsPath = process.env.PABX_SSH_KNOWN_HOSTS || '';

  if (!/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?)$/.test(host)) return null;
  if (!/^[a-z_][a-z0-9_-]{0,31}$/.test(user)) return null;
  if (!keyPath.startsWith('/run/secrets/')) return null;
  if (!knownHostsPath.startsWith('/run/secrets/')) return null;
  if (!existsSync(keyPath) || !existsSync(knownHostsPath)) return null;

  return { host, user, keyPath, knownHostsPath };
}

export function registerPabxTools(mcp) {
  const config = pabxConfig();
  if (!config) return false;

  mcp.registerTool('pabx_status', {
    description: 'Consulta somente leitura a versão, uptime e quantidade de canais ativos do PABX JR CONECT.',
  }, async () => {
    try {
      const { stdout } = await execFileAsync('/usr/bin/ssh', [
        '-F', '/dev/null',
        '-T',
        '-i', config.keyPath,
        '-o', 'BatchMode=yes',
        '-o', 'IdentitiesOnly=yes',
        '-o', 'StrictHostKeyChecking=yes',
        '-o', `UserKnownHostsFile=${config.knownHostsPath}`,
        '-o', 'GlobalKnownHostsFile=/dev/null',
        '-o', 'PasswordAuthentication=no',
        '-o', 'KbdInteractiveAuthentication=no',
        '-o', 'GSSAPIAuthentication=no',
        '-o', 'ClearAllForwardings=yes',
        '-o', 'ConnectTimeout=5',
        '-o', 'ConnectionAttempts=1',
        `${config.user}@${config.host}`,
        'status',
      ], {
        timeout: timeoutMs,
        maxBuffer: maxOutputBytes,
        encoding: 'utf8',
        windowsHide: true,
      });

      const text = String(stdout || '').trim();
      if (!text || Buffer.byteLength(text, 'utf8') > maxOutputBytes) {
        throw new Error('Resposta vazia ou acima do limite');
      }
      return { content: [{ type: 'text', text }] };
    } catch {
      return {
        isError: true,
        content: [{
          type: 'text',
          text: 'Não foi possível consultar o PABX pelo canal SSH restrito. Verifique o acesso configurado no ACS.',
        }],
      };
    }
  });
  return true;
}
