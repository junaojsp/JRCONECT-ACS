#!/usr/bin/env node
// Patch only the two IXC VoIP files on host. No service restart or IXC queries.
import { readFileSync, writeFileSync, copyFileSync, existsSync, lstatSync, mkdirSync, renameSync, chmodSync, chownSync, mkdtempSync, rmSync, openSync, closeSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { createHash } from 'node:crypto';

const ANCHOR_IMPORT = "import { registerIxcReportTools } from './ixc-report-tools.js';";
const ADD_IMPORT = "import { registerIxcVoipTools } from './ixc-voip-tools.js';";
const ANCHOR_REG = '  registerIxcReportTools(mcp, client);';
const ADD_REG = '  registerIxcVoipTools(mcp, client);';
const ROOT = '/opt/jrconect-mcp';
const TARGET = join(ROOT, 'integrations/ixc-webservice-tools.js');
const MODULE = join(ROOT, 'integrations/ixc-voip-tools.js');
const ARCHIVE = '/var/backups/jrconect-acs/voip-ixc';

function fail(message) { throw Error('BLOQUEADO: ' + message); }
function hash(content) { return createHash('sha256').update(content).digest('hex'); }
function assertRegular(path) {
  if (!existsSync(path)) fail('arquivo ausente: ' + path);
  const st = lstatSync(path);
  if (!st.isFile() || st.isSymbolicLink()) fail('arquivo inseguro: ' + path);
  return st;
}
function exactOne(source, value) {
  if (source.split(value).length !== 2) fail('marcador ausente ou duplicado: ' + value);
}
function stagePatch(source) {
  if (source.includes(ADD_IMPORT) || source.includes(ADD_REG)) fail('modulo VoIP ja registrado; revisar manualmente');
  exactOne(source, ANCHOR_IMPORT);
  exactOne(source, ANCHOR_REG);
  return source.replace(ANCHOR_IMPORT, ANCHOR_IMPORT + '\n' + ADD_IMPORT)
    .replace(ANCHOR_REG, ANCHOR_REG + '\n' + ADD_REG);
}
function verifyAuth() {
  const server = readFileSync(join(ROOT, 'server.js'), 'utf8');
  const auth = readFileSync(join(ROOT, 'integrations/ixc-webservice-auth.js'), 'utf8');
  // This is only a marker check; full scope review must precede activation.
  for (const marker of ['ixcWebserviceAuthorized(payload)', 'registerIxcWebserviceTools',
    'ixcCentralMcpHandler', 'includeIxc']) {
    if (!server.includes(marker)) fail('verificacao da cadeia IXC: ' + marker);
  }
  for (const marker of ['ixc-mcp-admin', 'IXC_WS_ALLOWED_SUB', 'chatgpt-mcp']) {
    if (!auth.includes(marker)) fail('verificacao da autorizacao IXC: ' + marker);
  }
  // A marker check cannot certify authorization. Gate explicit user approval at apply.
}
function atomicReplace(path, contents, stat) {
  const tmp = join(dirname(path), '.voip-ixc-' + process.pid + '.tmp');
  if (existsSync(tmp)) fail('arquivo temporario ja existe');
  let fd;
  try {
    fd = openSync(tmp, 'wx', stat.mode & 0o777);
    writeFileSync(fd, contents, 'utf8');
    closeSync(fd); fd = undefined;
    chmodSync(tmp, stat.mode & 0o777);
    chownSync(tmp, stat.uid, stat.gid);
    renameSync(tmp, path);
  } finally {
    if (fd !== undefined) closeSync(fd);
    if (existsSync(tmp)) rmSync(tmp);
  }
}
function main() {
  const [mode, sourceModule, backupPath] = process.argv.slice(2);
  if (!['--plan', '--apply', '--rollback'].includes(mode)) fail('modo invalido');
  if (process.getuid?.() !== 0) fail('execute como root no ACS');
  const targetStat = assertRegular(TARGET);
  assertRegular(join(ROOT, 'server.js'));
  assertRegular(join(ROOT, 'integrations/ixc-webservice-auth.js'));
  verifyAuth();

  if (mode === '--rollback') {
    if (!backupPath || !backupPath.startsWith(ARCHIVE + '/')) fail('backup fora do diretorio esperado');
    const old = join(backupPath, 'ixc-webservice-tools.js');
    const created = join(backupPath, 'ixc-voip-tools.js');
    const manifest = join(backupPath, 'manifest.json');
    for (const file of [old, manifest]) assertRegular(file);
    const m = JSON.parse(readFileSync(manifest, 'utf8'));
    if (m.path !== TARGET || m.module !== MODULE || typeof m.appliedHash !== 'string') fail('manifest invalido');
    if (hash(readFileSync(TARGET)) !== m.appliedHash) fail('arquivo foi alterado depois do deploy; rollback manual');
    const previous = readFileSync(old, 'utf8');
    atomicReplace(TARGET, previous, targetStat);
    if (m.moduleWasAbsent === true && existsSync(MODULE)) {
      const moduleContent = readFileSync(MODULE);
      if (hash(moduleContent) !== m.moduleHash) fail('modulo alterado; remocao exige revisao');
      rmSync(MODULE);
    }
    console.log('ROLLBACK DOS ARQUIVOS concluido; processo/container NAO reiniciado.');
    return;
  }

  if (!sourceModule) fail('forneca arquivo extraido do commit aprovado como segundo argumento');
  assertRegular(sourceModule);
  if (existsSync(MODULE)) fail('modulo IXC VoIP ja existe; nao sobrescrever');
  const old = readFileSync(TARGET, 'utf8');
  const patch = stagePatch(old);
  const moduleCode = readFileSync(sourceModule, 'utf8');
  for (const marker of ['export function registerIxcVoipTools', 'queryVoipContract', 'vinculo_autorizado: false']) {
    if (!moduleCode.includes(marker)) fail('modulo de origem inesperado');
  }
  console.log('Modificacao planejada: somente ' + TARGET + ' e ' + MODULE);
  console.log('SHA256 antes: ' + hash(old));
  console.log('SHA256 depois: ' + hash(patch));
  console.log('Novo modulo SHA256: ' + hash(moduleCode));
  console.log('O script NAO reinicia MCP, NAO muda imagem Docker e NAO faz consultas ao IXC.');
  if (mode === '--plan') { console.log('PLAN OK: nenhuma alteracao realizada.'); return; }

  if (process.env.VOIP_IXC_APPROVE_APPLY !== 'YES') fail('aplicacao exige VOIP_IXC_APPROVE_APPLY=YES');
  const moduleDirStat = lstatSync(dirname(MODULE));
  if (!moduleDirStat.isDirectory() || moduleDirStat.isSymbolicLink()) fail('diretorio de integracoes inseguro');
  const stamp = new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0,14);
  mkdirSync(ARCHIVE, { recursive: true, mode: 0o700 });
  const backup = mkdtempSync(join(ARCHIVE, stamp + '-'));
  chmodSync(backup, 0o700);
  copyFileSync(TARGET, join(backup, 'ixc-webservice-tools.js'));
  const manifest = { path: TARGET, module: MODULE, moduleWasAbsent: true,
    originalHash: hash(old), appliedHash: hash(patch), moduleHash: hash(moduleCode) };
  writeFileSync(join(backup, 'manifest.json'), JSON.stringify(manifest, null, 2), { mode: 0o600 });
  const moduleStat = { mode: 0o644, uid: targetStat.uid, gid: targetStat.gid };
  try {
    atomicReplace(MODULE, moduleCode, moduleStat);
    atomicReplace(TARGET, patch, targetStat);
    if (hash(readFileSync(TARGET)) !== manifest.appliedHash) fail('verificacao pos-gravacao falhou');
    console.log('APLICADO NOS ARQUIVOS DO HOST; backup: ' + backup);
    console.log('AVISO: MCP em Docker pode nao carregar arquivos do host. Nao reinicie automaticamente.');
    console.log('Proxima etapa: validar mounts/imagem/container, autorizacao e planejar ativacao controlada.');
  } catch (error) {
    if (hash(readFileSync(TARGET)) === hash(old)) {
      if (existsSync(MODULE) && hash(readFileSync(MODULE)) === manifest.moduleHash) rmSync(MODULE);
    } else {
      atomicReplace(TARGET, old, targetStat);
      if (existsSync(MODULE) && hash(readFileSync(MODULE)) === manifest.moduleHash) rmSync(MODULE);
    }
    throw error;
  }
}
try { main(); } catch (error) { console.error(String(error.message)); process.exitCode = 2; }
