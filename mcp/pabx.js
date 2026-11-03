import { execFile } from 'node:child_process';
import { existsSync } from 'node:fs';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const SSH_KEY = '/run/secrets/pabx-ssh-key';
const KNOWN_HOSTS = '/run/secrets/pabx-known-hosts';
const PABX_HOST = process.env.PABX_SSH_HOST || '';
const PABX_PORT = process.env.PABX_SSH_PORT || '22';

// Nao aceitar um host/porta vindo da chamada MCP: destinos e comandos sao fixos no servidor.
export function pabxConfigurado() {
  return process.env.PABX_ENABLED === 'true'
    && /^(?:[a-zA-Z0-9.-]+)$/.test(PABX_HOST)
    && /^[0-9]{1,5}$/.test(PABX_PORT)
    && Number(PABX_PORT) > 0 && Number(PABX_PORT) <= 65535
    && existsSync(SSH_KEY) && existsSync(KNOWN_HOSTS);
}

export async function consultarPabx() {
  if (!pabxConfigurado()) throw new Error('PABX nao configurado');

  // Sem shell, sem comandos arbitrarios, sem PTY e com validacao obrigatoria de host key.
  const args = [
    '-F', '/dev/null',
    '-i', SSH_KEY,
    '-o', 'BatchMode=yes',
    '-o', 'IdentitiesOnly=yes',
    '-o', 'IdentityAgent=none',
    '-o', 'PasswordAuthentication=no',
    '-o', 'KbdInteractiveAuthentication=no',
    '-o', 'StrictHostKeyChecking=yes',
    '-o', `UserKnownHostsFile=${KNOWN_HOSTS}`,
    '-o', 'GlobalKnownHostsFile=/dev/null',
    '-o', 'ConnectTimeout=5',
    '-o', 'ConnectionAttempts=1',
    '-o', 'RequestTTY=no',
    '-o', 'ClearAllForwardings=yes',
    '-o', 'LogLevel=ERROR',
    '-p', PABX_PORT,
    `jrc-mcp@${PABX_HOST}`,
    'status',
  ];
  const { stdout } = await execFileAsync('/usr/bin/ssh', args, {
    encoding: 'utf8',
    timeout: 12000,
    maxBuffer: 32768,
    windowsHide: true,
  });
  // Resposta deve conter somente os tres blocos permitidos pelo helper do Issabel.
  if (!stdout.includes('=== Asterisk version ===')
      || !stdout.includes('=== Asterisk uptime ===')
      || !stdout.includes('=== Active channel count ===')) {
    throw new Error('Formato de status inesperado');
  }
  return stdout.trim();
}
