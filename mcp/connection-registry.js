import { mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

const DEFAULT_PATH = process.env.MCP_CONNECTIONS_FILE || '/data/connections.json';
const ONLINE_WINDOW_MS = 5 * 60 * 1000;

function safeString(value, fallback = '') {
  return typeof value === 'string' ? value.slice(0, 128) : fallback;
}

export class ConnectionRegistry {
  constructor(filePath = DEFAULT_PATH) {
    this.filePath = filePath;
    this.records = new Map();
    this.load();
  }

  load() {
    try {
      const raw = readFileSync(this.filePath, 'utf8');
      const parsed = JSON.parse(raw);
      const rows = Array.isArray(parsed?.connections) ? parsed.connections : [];
      for (const row of rows) {
        if (!row || typeof row.id !== 'string') continue;
        this.records.set(row.id, {
          id: safeString(row.id),
          name: safeString(row.name, row.id),
          client_id: safeString(row.client_id, row.id),
          auth_type: safeString(row.auth_type, 'unknown'),
          first_seen: safeString(row.first_seen),
          last_seen: safeString(row.last_seen),
          requests: Number.isFinite(Number(row.requests)) ? Number(row.requests) : 0,
        });
      }
    } catch {
      // Primeira execucao ou arquivo ainda inexistente.
    }
  }

  persist() {
    const dir = dirname(this.filePath);
    mkdirSync(dir, { recursive: true });

    const payload = JSON.stringify({
      version: 1,
      updated_at: new Date().toISOString(),
      connections: [...this.records.values()],
    }, null, 2);

    const tmp = this.filePath + '.tmp';
    writeFileSync(tmp, payload, { encoding: 'utf8', mode: 0o644 });
    renameSync(tmp, this.filePath);
  }

  touch({ id, name, clientId, authType }) {
    const now = new Date().toISOString();
    const key = safeString(id);
    if (!key) return;

    const current = this.records.get(key);
    const next = {
      id: key,
      name: safeString(name, key),
      client_id: safeString(clientId, key),
      auth_type: safeString(authType, 'unknown'),
      first_seen: current?.first_seen || now,
      last_seen: now,
      requests: (current?.requests || 0) + 1,
    };

    this.records.set(key, next);
    try {
      this.persist();
    } catch (error) {
      console.warn('[MCP connections] falha ao persistir registro:', error?.message || error);
    }
  }

  list() {
    const now = Date.now();
    return [...this.records.values()]
      .map((row) => {
        const last = Date.parse(row.last_seen || '');
        const online = Number.isFinite(last) && (now - last) <= ONLINE_WINDOW_MS;
        return {
          ...row,
          status: online ? 'online' : 'offline',
        };
      })
      .sort((a, b) => String(b.last_seen).localeCompare(String(a.last_seen)));
  }
}
