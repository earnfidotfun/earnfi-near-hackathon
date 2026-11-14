/**
 * EarnFi MCP server — Streamable HTTP (MCP) + Express.
 * Binds 0.0.0.0; put TLS + auth in Caddy/nginx, or set MCP_BEARER_TOKEN.
 */
import type { Request, Response } from 'express';
import { randomUUID } from 'node:crypto';
import { createMcpExpressApp } from '@modelcontextprotocol/sdk/server/express.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { isInitializeRequest } from '@modelcontextprotocol/sdk/types.js';
import { createEarnFiMcpServer } from './create-server.js';
import { InMemoryEventStore } from './in-memory-event-store.js';

const transports: Record<string, StreamableHTTPServerTransport> = {};
const sseKeepaliveTimers: Record<string, ReturnType<typeof setInterval>> = {};

function clearSseKeepalive(sessionId: string | undefined) {
  if (!sessionId) return;
  const t = sseKeepaliveTimers[sessionId];
  if (t) {
    clearInterval(t);
    delete sseKeepaliveTimers[sessionId];
  }
}

function scheduleSseKeepalive(sessionId: string, transport: StreamableHTTPServerTransport) {
  clearSseKeepalive(sessionId);
  if (sseKeepaliveMs <= 0 || !enableEventStore) return;
  sseKeepaliveTimers[sessionId] = setInterval(() => {
    try {
      transport.closeStandaloneSSEStream();
    } catch {
      /* stream may already be closed */
    }
  }, sseKeepaliveMs);
}

function attachStandaloneSseDisconnectCleanup(
  req: Request,
  res: Response,
  transport: StreamableHTTPServerTransport
) {
  const accept = String(req.headers.accept || '');
  if (req.method !== 'GET' || !accept.includes('text/event-stream')) return;
  let done = false;
  const cleanup = () => {
    if (done) return;
    done = true;
    try {
      transport.closeStandaloneSSEStream();
    } catch {
      /* ignore */
    }
  };
  req.once('close', cleanup);
  res.once('close', cleanup);
}

const bearer = (process.env.MCP_BEARER_TOKEN || '').trim();
const port = parseInt(process.env.MCP_PORT || process.env.PORT || '3333', 10);
const host = (process.env.MCP_BIND_HOST || '0.0.0.0').trim();
const trustProxy = (process.env.MCP_TRUST_PROXY || '').trim() === '1';
const enableEventStore = (process.env.MCP_ENABLE_EVENT_STORE || '1').trim() !== '0';
const sseRetryIntervalMs = parseInt(process.env.MCP_SSE_RETRY_MS || '5000', 10);
const sseKeepaliveMs = parseInt(process.env.MCP_SSE_KEEPALIVE_MS || '45000', 10);

const allowedHosts = (process.env.MCP_ALLOWED_HOSTS || '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean);

const app = createMcpExpressApp(
  allowedHosts.length
    ? { host: '0.0.0.0' as const, allowedHosts }
    : { host: '0.0.0.0' as const }
);

if (trustProxy) {
  app.set('trust proxy', 1);
}

app.get('/healthz', (_req, res) => {
  res.json({ ok: true, service: 'earnfi-mcp' });
});

function mcpAuthOk(req: Request, res: Response): boolean {
  if (!bearer) return true;
  const h = req.headers.authorization;
  if (h === `Bearer ${bearer}`) return true;
  if (!res.headersSent) {
    res.status(401).json({
      jsonrpc: '2.0',
      error: { code: -32000, message: 'Unauthorized' },
      id: null,
    });
  }
  return false;
}

app.all('/mcp', async (req: Request, res: Response) => {
  if (!mcpAuthOk(req, res)) return;
  try {
    const sessionId = req.headers['mcp-session-id'] as string | undefined;
    let transport: StreamableHTTPServerTransport;

    if (sessionId && transports[sessionId]) {
      transport = transports[sessionId];
    } else if (!sessionId && req.method === 'POST' && req.body && isInitializeRequest(req.body)) {
      const eventStore = enableEventStore ? new InMemoryEventStore() : undefined;
      transport = new StreamableHTTPServerTransport({
        sessionIdGenerator: () => randomUUID(),
        ...(eventStore ? { eventStore } : {}),
        retryInterval: sseRetryIntervalMs,
        onsessioninitialized: (sid) => {
          if (sid) {
            transports[sid] = transport;
            scheduleSseKeepalive(sid, transport);
          }
        },
      });
      transport.onclose = () => {
        const sid = transport.sessionId;
        clearSseKeepalive(sid);
        if (sid && transports[sid]) {
          delete transports[sid];
        }
      };
      const hdrToken = String(
        req.headers['x-earnfi-agent-token'] ||
          (typeof req.query?.agentToken === 'string' ? req.query.agentToken : '') ||
          ''
      ).trim();
      const server = createEarnFiMcpServer({
        defaultAgentToken: hdrToken || undefined,
      });
      await server.connect(transport);
    } else {
      res.status(400).json({
        jsonrpc: '2.0',
        error: {
          code: -32000,
          message:
            'No MCP session ID. Start with initialize via POST /mcp (JSON-RPC body with method "initialize").',
        },
        id: null,
      });
      return;
    }
    attachStandaloneSseDisconnectCleanup(req, res, transport);
    const parsedBody = req.method === 'POST' ? req.body : undefined;
    await transport.handleRequest(req, res, parsedBody);
  } catch (e) {
    console.error(e);
    if (!res.headersSent) {
      res.status(500).json({
        jsonrpc: '2.0',
        error: { code: -32603, message: 'Internal server error' },
        id: null,
      });
    }
  }
});

app.listen(port, host, () => {
  console.log(
    `EarnFi MCP (Streamable HTTP) listening on http://${host}:${port}/mcp health=http://${host}:${port}/healthz`
  );
  if (allowedHosts.length) {
    console.log(`MCP allowed Host headers: ${allowedHosts.join(', ')}`);
  } else {
    console.warn(
      'MCP_ALLOWED_HOSTS is empty — for a public domain behind TLS, set it (e.g. mcp.yourdomain.com) to satisfy Host validation on 0.0.0.0.'
    );
  }
  if (bearer) console.log('MCP_BEARER_TOKEN is set (Authorization: Bearer required)');
  if (trustProxy) console.log('MCP_TRUST_PROXY=1 (X-Forwarded-* trusted from one hop)');
  console.log(
    enableEventStore
      ? 'MCP session event store: enabled (in-memory, single process)'
      : 'MCP session event store: disabled (MCP_ENABLE_EVENT_STORE=0)'
  );
});

const shutdown = async () => {
  for (const sid of Object.keys(sseKeepaliveTimers)) {
    clearSseKeepalive(sid);
  }
  for (const sid of Object.keys(transports)) {
    try {
      await transports[sid].close();
      delete transports[sid];
    } catch (e) {
      console.error(e);
    }
  }
  process.exit(0);
};
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
