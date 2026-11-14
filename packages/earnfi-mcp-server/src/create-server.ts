/**
 * EarnFi MCP — Agent API proxy with unified paid create tools:
 * one tool per job type; optional payment_signature on the second call.
 * x402 signing stays on the client; MCP forwards PAYMENT-SIGNATURE when provided.
 */
import http from 'node:http';
import https from 'node:https';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import {
  EARNFI_AGENT_API_V1_BASE,
  EARNFI_ANTI_CONFUSION_RULES,
  EARNFI_HTTP402_HINT,
  EARNFI_MCP_402_NEXT_STEP,
  EARNFI_NETWORK_UNAVAILABLE_NEXT_STEP,
  EARNFI_X402_FLOW_STEPS,
} from './integrator-copy.js';

function agentBaseUrl(): string {
  const b = (process.env.EARNFI_AGENT_API_BASE || 'https://app.earnfi.fun/api/ai-agent/v1').replace(/\/$/, '');
  return b;
}

/** Normalize optional payment_network → X-Earnfi-Accepts-Filter value. */
function normalizePaymentNetworkFilter(raw?: string): string {
  const n = String(raw || '').trim();
  if (!n) return '';
  const lower = n.toLowerCase();
  if (lower === 'base' || lower === 'eip155:8453') return 'eip155:8453';
  if (lower === 'near' || lower === 'near:mainnet') return 'near:mainnet';
  if (lower === 'solana' || lower.startsWith('solana:')) {
    return lower === 'solana' ? 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' : n;
  }
  if (lower.startsWith('eip155:') || lower.startsWith('near:') || lower.startsWith('solana:')) return n;
  return n;
}

function acceptsFilterHeaders(payment_network?: string): Record<string, string> {
  const f = normalizePaymentNetworkFilter(payment_network);
  return f ? { 'X-Earnfi-Accepts-Filter': f } : {};
}

function growlBaseUrl(): string {
  const explicit = (process.env.EARNFI_GROWL_API_BASE || '').trim();
  if (explicit) return explicit.replace(/\/$/, '');
  return agentBaseUrl().replace(/\/api\/ai-agent\/v1\/?$/, '/api/growl/v1');
}

async function growlApiFetch(
  method: 'GET' | 'POST' | 'PATCH',
  path: string,
  opts: { search?: Record<string, string | undefined>; body?: string } = {}
): Promise<ApiFetchResult> {
  const rel = path.replace(/^\//, '');
  const u = new URL(rel, growlBaseUrl().replace(/\/?$/, '/') + '/');
  if (opts.search) {
    for (const [k, v] of Object.entries(opts.search)) {
      if (v !== undefined && v !== '') u.searchParams.set(k, v);
    }
  }
  return httpFetch(method, u, { body: opts.body, headers: { 'Content-Type': 'application/json' } });
}

function isLoopbackHostname(hostname: string): boolean {
  const h = hostname.toLowerCase();
  return h === '127.0.0.1' || h === 'localhost' || h === '::1' || h === '[::1]';
}

/**
 * When calling via 127.0.0.1 / localhost, nginx needs the public Host header
 * (e.g. app.earnfi.fun) or /api/* returns a bare nginx 404.
 * Note: undici/fetch forbids setting Host — we use node:http for loopback.
 */
function agentApiHostHeader(forUrl?: URL): string {
  const explicit = (process.env.EARNFI_AGENT_API_HOST || '').trim();
  if (explicit) return explicit;
  if (forUrl && isLoopbackHostname(forUrl.hostname)) {
    return (process.env.EARNFI_PUBLIC_HOST || 'app.earnfi.fun').trim();
  }
  return '';
}

/** Canonical public host for agent-facing URLs (never expose 127.0.0.1 to MCP clients). */
function publicApiHostname(): string {
  return (process.env.EARNFI_AGENT_API_HOST || process.env.EARNFI_PUBLIC_HOST || 'app.earnfi.fun').trim();
}

/**
 * Rewrite loopback request URLs to the public API origin for tool responses.
 * Internal fetch still uses EARNFI_AGENT_API_BASE (often https://127.0.0.1/...).
 */
function publicRequestUrl(url: string): string {
  try {
    const u = new URL(url);
    if (!isLoopbackHostname(u.hostname)) return u.toString();
    const pub = new URL(u.toString());
    pub.protocol = 'https:';
    pub.hostname = publicApiHostname();
    pub.port = '';
    return pub.toString();
  } catch {
    return url.replace(/https?:\/\/(127\.0\.0\.1|localhost|\[::1\])(:\d+)?/gi, `https://${publicApiHostname()}`);
  }
}

function publicAgentApiBase(): string {
  return publicRequestUrl(agentBaseUrl() + '/').replace(/\/$/, '');
}

function buildUrl(path: string, search?: Record<string, string | undefined>) {
  const rel = path.replace(/^\//, '');
  const u = new URL(rel, agentBaseUrl() + '/');
  if (search) {
    for (const [k, v] of Object.entries(search)) {
      if (v === undefined || v === '') continue;
      u.searchParams.set(k, v);
    }
  }
  return u;
}

type ApiFetchResult = {
  status: number;
  statusText: string;
  url: string;
  headers: Record<string, string>;
  json: unknown | null;
  text: string;
};

function decodePaymentRequiredHeader(b64: string): unknown | null {
  try {
    const raw = Buffer.from(b64, 'base64').toString('utf8');
    return JSON.parse(raw) as unknown;
  } catch {
    return null;
  }
}

function looksLikeNginxHtml404(status: number, text: string): boolean {
  if (status !== 404) return false;
  const t = text.slice(0, 400).toLowerCase();
  return t.includes('<html') && (t.includes('nginx') || t.includes('404 not found'));
}

function networkFetchErrorResult(url: string, err: unknown): ApiFetchResult {
  const cause =
    err && typeof err === 'object' && 'cause' in err
      ? (err as { cause?: { code?: string; message?: string } }).cause
      : undefined;
  const message = err instanceof Error ? err.message : String(err);
  const code = cause?.code || '';
  const detail = cause?.message || message;
  const base = agentBaseUrl();
  const usingPublic =
    /^https?:\/\/(app\.)?earnfi\.fun\b/i.test(base) ||
    (!base.includes('127.0.0.1') && !base.includes('localhost'));
  const hint = usingPublic
    ? 'MCP runs on the same host as WordPress — outbound fetch to the public hostname often fails (hairpin NAT / Cloudflare). Set EARNFI_AGENT_API_BASE=https://127.0.0.1/api/ai-agent/v1 and EARNFI_AGENT_API_HOST=app.earnfi.fun, then restart earnfi-mcp. Verify: curl -sS -k -H "Host: app.earnfi.fun" https://127.0.0.1/api/ai-agent/v1/catalog'
    : 'Check that WordPress/nginx is reachable at EARNFI_AGENT_API_BASE from this process, and EARNFI_AGENT_API_HOST matches the public vhost (default app.earnfi.fun). Verify: curl -sS -k -H "Host: app.earnfi.fun" https://127.0.0.1/api/ai-agent/v1/catalog';
  return {
    status: 0,
    statusText: 'fetch_failed',
    url,
    headers: {},
    json: {
      success: false,
      error: 'fetch_failed',
      message: detail,
      code: code || undefined,
      request_url: publicRequestUrl(url),
      agent_api_base: publicAgentApiBase(),
      hint,
    },
    text: '',
  };
}

function publicApiHostnames(): Set<string> {
  const hosts = new Set<string>(['app.earnfi.fun', 'earnfi.fun']);
  const h = agentApiHostHeader() || (process.env.EARNFI_PUBLIC_HOST || '').trim();
  if (h) hosts.add(h.toLowerCase());
  return hosts;
}

/** Keep redirects on loopback so we never hairpin out to Cloudflare/public DNS. */
function rewriteRedirectToLoopback(from: URL, locationHeader: string): URL | null {
  let next: URL;
  try {
    next = new URL(locationHeader, from);
  } catch {
    return null;
  }
  const publicHosts = publicApiHostnames();
  const locHost = next.hostname.toLowerCase();
  if (isLoopbackHostname(from.hostname) && (publicHosts.has(locHost) || locHost === from.hostname)) {
    const rewritten = new URL(next.toString());
    rewritten.hostname = '127.0.0.1';
    // Preserve explicit ports from Location; default to 443/80 via URL protocol.
    if (!next.port) rewritten.port = '';
    return rewritten;
  }
  if (isLoopbackHostname(next.hostname)) return next;
  return null;
}

function parseHttpBody(status: number, statusText: string, url: string, rawHeaders: http.IncomingHttpHeaders, text: string): ApiFetchResult {
  let json: unknown | null = null;
  if (text) {
    try {
      json = JSON.parse(text) as unknown;
    } catch {
      json = null;
    }
  }
  const headers: Record<string, string> = {};
  for (const [k, v] of Object.entries(rawHeaders)) {
    if (v === undefined) continue;
    headers[k.toLowerCase()] = Array.isArray(v) ? v.join(', ') : String(v);
  }
  if (looksLikeNginxHtml404(status, text)) {
    const host = agentApiHostHeader(new URL(url));
    json = {
      success: false,
      error: 'upstream_nginx_404',
      http_status: 404,
      request_url: publicRequestUrl(url),
      host_sent: host || '(none)',
      hint:
        'nginx 404 (not WordPress) — the Host header did not hit the app.earnfi.fun vhost, or /api is not routed on that listen. Set EARNFI_AGENT_API_HOST=app.earnfi.fun and verify: curl -sS -k -H "Host: app.earnfi.fun" "https://127.0.0.1/api/ai-agent/v1/catalog"',
    };
  } else if (status >= 301 && status <= 308 && headers.location) {
    json = {
      success: false,
      error: 'upstream_redirect',
      http_status: status,
      request_url: publicRequestUrl(url),
      location: publicRequestUrl(headers.location),
      hint:
        'nginx redirected (usually HTTP→HTTPS). Prefer EARNFI_AGENT_API_BASE=https://127.0.0.1/api/ai-agent/v1 with EARNFI_AGENT_API_HOST=app.earnfi.fun. Verify: curl -sS -k -H "Host: app.earnfi.fun" "https://127.0.0.1/api/ai-agent/v1/catalog"',
    };
  }
  return { status, statusText, url, headers, json, text };
}

function nodeHttpOnce(
  method: 'GET' | 'POST' | 'PATCH',
  u: URL,
  opts: { headers?: Record<string, string>; body?: string }
): Promise<{ status: number; statusText: string; headers: http.IncomingHttpHeaders; text: string } | ApiFetchResult> {
  const host = agentApiHostHeader(u);
  const headers: Record<string, string> = {
    accept: 'application/json',
    ...opts.headers,
  };
  if (host) {
    headers.Host = host;
  }
  if (opts.body && !headers['content-type'] && !headers['Content-Type']) {
    headers['content-type'] = 'application/json';
  }
  const lib = u.protocol === 'https:' ? https : http;
  const port = u.port ? Number(u.port) : u.protocol === 'https:' ? 443 : 80;
  const reqOpts: http.RequestOptions = {
    protocol: u.protocol,
    hostname: u.hostname.replace(/^\[|\]$/g, ''),
    port,
    path: `${u.pathname}${u.search}`,
    method,
    headers,
    timeout: 60000,
  };
  if (u.protocol === 'https:') {
    // Prefer secure TLS. Only skip verification for explicit local/dev loopback hosts.
    const host = (u.hostname || '').replace(/^\[|\]$/g, '').toLowerCase();
    const insecureOk =
      host === 'localhost' ||
      host === '127.0.0.1' ||
      host === '::1' ||
      process.env.EARNFI_MCP_INSECURE_TLS === '1';
    if (!insecureOk) {
      (reqOpts as https.RequestOptions).rejectUnauthorized = true;
    } else {
      (reqOpts as https.RequestOptions).rejectUnauthorized = false;
    }
  }

  return new Promise((resolve) => {
    const req = lib.request(reqOpts, (res) => {
      const chunks: Buffer[] = [];
      res.on('data', (c) => chunks.push(Buffer.isBuffer(c) ? c : Buffer.from(c)));
      res.on('end', () => {
        resolve({
          status: res.statusCode || 0,
          statusText: res.statusMessage || '',
          headers: res.headers,
          text: Buffer.concat(chunks).toString('utf8'),
        });
      });
    });
    req.on('error', (err) => resolve(networkFetchErrorResult(u.toString(), err)));
    req.on('timeout', () => {
      req.destroy();
      resolve(networkFetchErrorResult(u.toString(), new Error('socket timeout')));
    });
    if (opts.body) req.write(opts.body);
    req.end();
  });
}

/**
 * node:http/https — required for loopback so we can set Host (fetch/undici forbids it).
 * Follows HTTP→HTTPS redirects while staying on 127.0.0.1 (avoids hairpin to Cloudflare).
 */
async function nodeHttpFetch(
  method: 'GET' | 'POST' | 'PATCH',
  u: URL,
  opts: { headers?: Record<string, string>; body?: string } = {}
): Promise<ApiFetchResult> {
  let current = u;
  let currentMethod = method;
  let body = opts.body;
  const maxRedirects = 5;

  for (let i = 0; i <= maxRedirects; i++) {
    const raw = await nodeHttpOnce(currentMethod, current, { headers: opts.headers, body });
    if ('json' in raw && raw.statusText === 'fetch_failed') {
      return raw;
    }
    const res = raw as { status: number; statusText: string; headers: http.IncomingHttpHeaders; text: string };
    const loc = Array.isArray(res.headers.location) ? res.headers.location[0] : res.headers.location;
    const isRedirect = res.status >= 301 && res.status <= 308 && !!loc;
    if (isRedirect && i < maxRedirects) {
      const next = rewriteRedirectToLoopback(current, loc!);
      if (next) {
        // 303 → GET; 301/302 on POST → GET (common); 307/308 keep method+body
        if (res.status === 303 || ((res.status === 301 || res.status === 302) && currentMethod === 'POST')) {
          currentMethod = 'GET';
          body = undefined;
        }
        current = next;
        continue;
      }
    }
    return parseHttpBody(res.status, res.statusText, current.toString(), res.headers, res.text);
  }

  return {
    status: 0,
    statusText: 'too_many_redirects',
    url: current.toString(),
    headers: {},
    json: {
      success: false,
      error: 'too_many_redirects',
      request_url: current.toString(),
      hint: 'Prefer EARNFI_AGENT_API_BASE=https://127.0.0.1/api/ai-agent/v1 to skip HTTP→HTTPS redirects.',
    },
    text: '',
  };
}

async function httpFetch(
  method: 'GET' | 'POST' | 'PATCH',
  u: URL,
  opts: { headers?: Record<string, string>; body?: string } = {}
): Promise<ApiFetchResult> {
  // Always use node:http for loopback so Host reaches nginx vhost selection.
  if (isLoopbackHostname(u.hostname)) {
    return nodeHttpFetch(method, u, opts);
  }

  const h: Record<string, string> = { accept: 'application/json', ...opts.headers };
  try {
    const r = await fetch(u, { method, headers: h, body: opts.body, redirect: 'follow' });
    const text = await r.text();
    const headers: Record<string, string> = {};
    r.headers.forEach((v, k) => {
      headers[k.toLowerCase()] = v;
    });
    return parseHttpBody(r.status, r.statusText, u.toString(), headers, text);
  } catch (err) {
    return networkFetchErrorResult(u.toString(), err);
  }
}

async function apiFetch(
  method: 'GET' | 'POST' | 'PATCH',
  path: string,
  opts: { search?: Record<string, string | undefined>; headers?: Record<string, string>; body?: string } = {}
): Promise<ApiFetchResult> {
  return httpFetch(method, buildUrl(path, opts.search), { headers: opts.headers, body: opts.body });
}

function asRecord(v: unknown): Record<string, unknown> | null {
  return v !== null && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : null;
}

/** Shared Seeker / Instant / Creator Wallet params for paid creates. */
const launchAudienceFields = {
  quick: z.coerce.boolean().optional().describe('Prefer Instant / quick jobs when supported.'),
  effort_bucket: z.string().optional().describe('Optional effort bucket for matching workers.'),
  target_clients: z
    .union([z.string(), z.array(z.string())])
    .optional()
    .describe('Client channels to target (e.g. seeker, app, pwa, web).'),
  require_sgt_holder: z.coerce.boolean().optional().describe('Require Seeker Genesis Token (SGT) holders.'),
  seeker_only: z.coerce.boolean().optional().describe('Restrict to Solana Seeker / mobile workers.'),
  payment_method: z
    .enum(['connected_wallet', 'creator_wallet'])
    .optional()
    .describe('Pay from connected wallet (x402) or Creator Wallet balance.'),
  targeting_policy: z
    .union([z.string(), z.record(z.unknown())])
    .optional()
    .describe('Advanced targeting policy JSON or string.'),
  min_rank: z.string().optional().describe('Optional minimum worker rank filter.'),
};

function appendLaunchAudience(
  q: Record<string, string>,
  args: {
    quick?: boolean;
    effort_bucket?: string;
    target_clients?: string | string[];
    require_sgt_holder?: boolean;
    seeker_only?: boolean;
    payment_method?: 'connected_wallet' | 'creator_wallet';
    targeting_policy?: string | Record<string, unknown>;
    min_rank?: string;
  }
) {
  if (args.quick) q.quick = 'true';
  if (args.effort_bucket) q.effort_bucket = args.effort_bucket;
  if (args.target_clients !== undefined) {
    q.target_clients = Array.isArray(args.target_clients)
      ? JSON.stringify(args.target_clients)
      : args.target_clients;
  }
  if (args.require_sgt_holder) q.require_sgt_holder = 'true';
  if (args.seeker_only) q.seeker_only = 'true';
  if (args.payment_method) q.payment_method = args.payment_method;
  if (args.targeting_policy !== undefined) {
    q.targeting_policy =
      typeof args.targeting_policy === 'string'
        ? args.targeting_policy
        : JSON.stringify(args.targeting_policy);
  }
  if (args.min_rank) q.min_rank = args.min_rank;
}

/**
 * Structured JSON for agents (not raw HTTP dumps).
 */
function restErrorGuidance(body: Record<string, unknown> | null): { hint?: string; next_step?: string } {
  if (!body) return {};
  const data = asRecord(body.data);
  const err = asRecord(body.error);
  const out: { hint?: string; next_step?: string } = {};
  if (typeof body.hint === 'string') out.hint = body.hint;
  if (typeof body.next_step === 'string') out.next_step = body.next_step;
  if (data) {
    if (typeof data.hint === 'string') out.hint = data.hint;
    if (typeof data.next_step === 'string') out.next_step = data.next_step;
  }
  if (err) {
    if (typeof err.hint === 'string') out.hint = err.hint;
    if (typeof err.next_step === 'string') out.next_step = err.next_step;
  }
  return out;
}

function formatAgentApiResponse(res: ApiFetchResult): string {
  const body = asRecord(res.json);
  const prB64 = res.headers['payment-required'];
  const x402FromHeader = prB64 ? decodePaymentRequiredHeader(prB64) : null;
  const x402Hdr = asRecord(x402FromHeader);
  const requestUrl = publicRequestUrl(res.url);

  if (res.status === 0 || res.statusText === 'fetch_failed') {
    const publicBody = body ? { ...body } : {};
    if (typeof publicBody.request_url === 'string') publicBody.request_url = publicRequestUrl(String(publicBody.request_url));
    if (typeof publicBody.agent_api_base === 'string') publicBody.agent_api_base = publicAgentApiBase();
    return JSON.stringify(
      {
        success: false,
        error: 'fetch_failed',
        http_status: 0,
        request_url: requestUrl,
        ...publicBody,
      },
      null,
      2
    );
  }

  if (res.status === 402) {
    const x402Body = body?.x402 ?? body;
    const structured: Record<string, unknown> = {
      requires_payment: true,
      http_status: 402,
      request_url: requestUrl,
      payment_required: body?.payment_required ?? true,
      x402Version: body?.x402Version ?? x402Hdr?.x402Version ?? 2,
      accepts: body?.accepts ?? x402Hdr?.accepts ?? null,
      resource: body?.resource ?? x402Hdr?.resource ?? null,
      extensions: body?.extensions ?? x402Hdr?.extensions ?? undefined,
      total_cost: body?.total_cost ?? undefined,
      x402: x402Body ?? x402FromHeader ?? undefined,
      hint: typeof body?.hint === 'string' ? body.hint : EARNFI_HTTP402_HINT,
      next_step: typeof body?.next_step === 'string' ? body.next_step : EARNFI_MCP_402_NEXT_STEP,
    };
    if (body?.order_id !== undefined) structured.order_id = body.order_id;
    if (body?.public_slug !== undefined) structured.public_slug = body.public_slug;
    return JSON.stringify(structured, null, 2);
  }

  if (res.status >= 200 && res.status < 300) {
    const structured: Record<string, unknown> = {
      success: true,
      http_status: res.status,
      request_url: requestUrl,
    };
    if (body) {
      Object.assign(structured, body);
    } else if (res.json !== null) {
      structured.data = res.json;
    } else if (res.text) {
      structured.raw = res.text;
    }
    return JSON.stringify(structured, null, 2);
  }

  if (body?.error === 'upstream_nginx_404' || body?.error === 'upstream_redirect') {
    return JSON.stringify(
      {
        ...body,
        http_status: res.status,
        request_url: typeof body.request_url === 'string' ? publicRequestUrl(String(body.request_url)) : requestUrl,
      },
      null,
      2
    );
  }

  const errCode =
    (typeof body?.code === 'string' && body.code) ||
    (typeof body?.error === 'string' && body.error) ||
    (asRecord(body?.error)?.code as string | undefined) ||
    '';
  const networkUnavailable =
    res.status === 503 &&
    (errCode === 'payment_network_unavailable' ||
      errCode === 'no_accepts' ||
      (typeof body?.message === 'string' && /payment.?network.?unavailable|no eligible payment/i.test(body.message)));

  const guidance = restErrorGuidance(body);
  return JSON.stringify(
    {
      success: false,
      http_status: res.status,
      request_url: requestUrl,
      error: body ?? (res.json !== null ? res.json : res.text),
      ...(guidance.hint ? { hint: guidance.hint } : {}),
      ...(guidance.next_step ? { next_step: guidance.next_step } : {}),
      ...(networkUnavailable
        ? {
            hint: guidance.hint ?? EARNFI_HTTP402_HINT,
            next_step: EARNFI_NETWORK_UNAVAILABLE_NEXT_STEP,
            recoverable: true,
          }
        : {}),
      ...(res.status === 401 || res.status === 403
        ? {
            next_step:
              guidance.next_step ??
              'Check Agent-Token / EARNFI_AGENT_TOKEN, Work API Bearer key, or complete x402 payment as documented.',
          }
        : {}),
    },
    null,
    2
  );
}

/** Create marketplace order then fund (402 quote without payment_signature; settle when provided). */
async function createAndFundAgentOrder(opts: {
  service_id: number;
  input?: Record<string, unknown>;
  payment_signature?: string;
  settlement_id?: number;
  agent_token?: string;
  payment_network?: string;
  defaultToken: string;
}): Promise<ApiFetchResult> {
  const tok = optionalAgentToken(opts.agent_token, opts.defaultToken);
  const headers: Record<string, string> = { 'content-type': 'application/json' };
  if (tok) headers['Agent-Token'] = tok;
  const createRes = await apiFetch('POST', '/agents/orders', {
    headers,
    body: JSON.stringify({ service_id: opts.service_id, input: opts.input || {} }),
  });
  if (createRes.status < 200 || createRes.status >= 300) return createRes;
  const created = asRecord(createRes.json);
  const orderId =
    typeof created?.order_id === 'number'
      ? created.order_id
      : typeof created?.order_id === 'string'
        ? Number(created.order_id)
        : Number(asRecord(created?.order)?.order_id);
  if (!Number.isFinite(orderId) || orderId <= 0) return createRes;

  const fundHeaders: Record<string, string> = {
    'content-type': 'application/json',
    ...acceptsFilterHeaders(opts.payment_network),
  };
  if (tok) fundHeaders['Agent-Token'] = tok;
  if (opts.payment_signature) fundHeaders['PAYMENT-SIGNATURE'] = opts.payment_signature;
  const fundBody: Record<string, unknown> = {};
  if (opts.settlement_id !== undefined) fundBody.settlement_id = opts.settlement_id;
  const fundRes = await apiFetch('POST', `/agents/orders/${orderId}/fund`, {
    headers: fundHeaders,
    body: JSON.stringify(fundBody),
  });
  // Attach order identity on 402 so agents can retry fund without re-creating.
  if (fundRes.status === 402) {
    const fj = asRecord(fundRes.json) || {};
    fundRes.json = {
      ...fj,
      order_id: orderId,
      public_slug: created?.public_slug ?? fj.public_slug,
      created_order: created,
    };
  } else if (fundRes.status >= 200 && fundRes.status < 300) {
    const fj = asRecord(fundRes.json) || {};
    fundRes.json = { ...fj, order_id: orderId, public_slug: created?.public_slug ?? fj.public_slug };
  }
  return fundRes;
}

function toolText(t: string) {
  let structured: Record<string, unknown> = { result: t };
  try {
    const parsed = JSON.parse(t) as unknown;
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      structured = parsed as Record<string, unknown>;
    } else {
      structured = { result: parsed as unknown };
    }
  } catch {
    /* keep text wrapper */
  }
  return {
    content: [{ type: 'text' as const, text: t }],
    structuredContent: structured,
  };
}

const ANN_READ = {
  readOnlyHint: true,
  destructiveHint: false,
  idempotentHint: true,
  openWorldHint: true,
} as const;

const ANN_WRITE = {
  readOnlyHint: false,
  destructiveHint: false,
  idempotentHint: false,
  openWorldHint: true,
} as const;

const ANN_DANGER = {
  readOnlyHint: false,
  destructiveHint: true,
  idempotentHint: false,
  openWorldHint: true,
} as const;

/** Shared structured output for Smithery / MCP clients. */
const agentToolOutputSchema = z
  .object({
    success: z.boolean().optional().describe('True when the request completed successfully.'),
    requires_payment: z
      .boolean()
      .optional()
      .describe('True when a quote is returned and PAYMENT-SIGNATURE is still needed.'),
    http_status: z.number().int().optional().describe('Upstream HTTP status when relevant.'),
    job_id: z.string().optional().describe('Created or referenced job id when present.'),
    secret: z.string().optional().describe('Per-job secret for free polling (store securely).'),
    agent_token: z
      .string()
      .optional()
      .describe(
        'From earnfi_register / earnfi_okx_register, OR returned on the first settled paid create when newly bound from the paying wallet. Store securely.'
      ),
    error: z.string().optional().describe('Error message when success is false.'),
  })
  .passthrough();

/** For creator / creator-token tools that REQUIRE a token (no anonymous pay-first path). */
function resolveAgentToken(agent_token?: string, defaultToken = ''): string | null {
  const tok = (agent_token || defaultToken).trim();
  return tok || null;
}

/** For paid creates: agent_token is optional — return undefined instead of erroring when absent. */
function optionalAgentToken(agent_token?: string, defaultToken = ''): string | undefined {
  const tok = (agent_token || defaultToken).trim();
  return tok || undefined;
}

/**
 * Extra fields accepted by every paid-create endpoint on top of the job-specific
 * params: optional display name, idempotency, partner attribution, and the
 * optional SPL token-gate. Spread into paid-create input schemas.
 */
const paidCreateExtraFields = {
  agent_name: z
    .string()
    .min(1)
    .max(120)
    .optional()
    .describe('Optional display name to set when binding a new agent from the paying wallet.'),
  idempotency_key: z
    .string()
    .min(1)
    .optional()
    .describe('Optional idempotency key. Replays within 24h return the same quote/result without double-settling.'),
  partner_source: z.string().optional().describe('Optional partner/source attribution tag.'),
  partner_metadata: z
    .union([z.string(), z.record(z.unknown())])
    .optional()
    .describe('Optional partner metadata JSON or string.'),
  token_gate: z
    .union([z.string(), z.record(z.unknown())])
    .optional()
    .describe('Optional SPL token-gate object (or JSON string): enabled, token_mint, use_usd, min_usd/min_amount.'),
  token_gate_enabled: z.coerce.boolean().optional().describe('Flat alternative to token_gate.enabled.'),
  token_mint: z.string().optional().describe('Flat alternative to token_gate.token_mint (Solana SPL mint address).'),
  min_token_amount: z.string().optional().describe('Flat alternative to token_gate.min_amount (token units, not USD).'),
  min_token_usd: z.string().optional().describe('Flat alternative to token_gate.min_usd (USD value at verification time).'),
  token_gate_use_usd: z.coerce.boolean().optional().describe('Flat alternative to token_gate.use_usd.'),
  require_hold_on_payment: z
    .coerce.boolean()
    .optional()
    .describe('Flat alternative to token_gate.require_hold_at_payout — re-check balance before payouts complete.'),
  payment_network: z
    .string()
    .optional()
    .describe(
      'Optional CAIP-2 filter for 402 accepts[]: solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp, eip155:8453, or near:mainnet (aliases: solana, base, near). Sent as X-Earnfi-Accepts-Filter on quote and pay. Omit to receive all live networks. On payment_network_unavailable, omit or switch network — do not treat as a total failure.'
    ),
};

function appendPaidCreateExtras(
  q: Record<string, string>,
  args: {
    agent_name?: string;
    idempotency_key?: string;
    partner_source?: string;
    partner_metadata?: string | Record<string, unknown>;
    token_gate?: string | Record<string, unknown>;
    token_gate_enabled?: boolean;
    token_mint?: string;
    min_token_amount?: string;
    min_token_usd?: string;
    token_gate_use_usd?: boolean;
    require_hold_on_payment?: boolean;
  }
) {
  if (args.agent_name) q.agent_name = args.agent_name;
  if (args.idempotency_key) q.idempotency_key = args.idempotency_key;
  if (args.partner_source) q.partner_source = args.partner_source;
  if (args.partner_metadata !== undefined) {
    q.partner_metadata =
      typeof args.partner_metadata === 'string' ? args.partner_metadata : JSON.stringify(args.partner_metadata);
  }
  if (args.token_gate !== undefined) {
    q.token_gate = typeof args.token_gate === 'string' ? args.token_gate : JSON.stringify(args.token_gate);
  }
  if (args.token_gate_enabled) q.token_gate_enabled = 'true';
  if (args.token_mint) q.token_mint = args.token_mint;
  if (args.min_token_amount) q.min_token_amount = args.min_token_amount;
  if (args.min_token_usd) q.min_token_usd = args.min_token_usd;
  if (args.token_gate_use_usd) q.token_gate_use_usd = 'true';
  if (args.require_hold_on_payment) q.require_hold_on_payment = 'true';
}

const paymentSignatureOptional = z
  .string()
  .min(8)
  .optional()
  .describe(
    'Omit on first call → HTTP 402 quote with accepts[]. Optional payment_network filters to one CAIP-2. Pick the matching accept; sign it; pass payment_signature (= PAYMENT-SIGNATURE: Solana signed_tx, Base EVM x402, or NEAR signedDelegateAction). Second call: same tool, same args (including payment_network), plus payment_signature.'
  );

const humanActionType = z.enum([
  'ask',
  'review',
  'vote',
  'test',
  'research',
  'verify',
  'moderate',
  'feedback',
]);

const humanActionInput = {
  payment_signature: paymentSignatureOptional,
  prompt: z.string().min(1).max(10000).describe('The question or brief for humans to answer or complete.'),
  slots: z.coerce.number().int().min(1).max(50).describe('How many human responses to collect (1–50).'),
  reward_per_user: z.string().describe("USDC reward per worker/slot as a decimal string (e.g. \"0.05\").").min(1).describe('USDC reward per worker/slot as a decimal string (e.g. "0.05").'),
  title: z.string().describe("Job or contest title shown to workers.").max(100).optional().describe('Optional short title for the Human Action.'),
  options: z
    .array(z.string().min(1))
    .optional()
    .describe('For vote actions: the choices humans pick between.'),
  verification_method: z
    .enum(['manual', 'auto'])
    .optional()
    .describe('How submissions are verified when applicable.'),
  agent_token: z
    .string()
    .optional()
    .describe(
      'Optional. Omit to quote/settle and bind from the paying wallet; returned on success when newly bound. Or pass from register / EARNFI_AGENT_TOKEN.'
    ),
  ...launchAudienceFields,
  ...paidCreateExtraFields,
};

const humanActionArgsSchema = z.object(humanActionInput);
type HumanActionArgs = z.infer<typeof humanActionArgsSchema>;

export function createEarnFiMcpServer(opts: { defaultAgentToken?: string } = {}): McpServer {
  const server = new McpServer(
    {
      name: 'earnfi-agent-api',
      version: '2.1.0',
      title: 'EarnFi Agent API',
      websiteUrl: 'https://earnfi.fun',
      description:
        'EarnFi Agent API over MCP (Streamable HTTP). REST base /api/ai-agent/v1 with multichain accepts[] (Solana, Base eip155:8453, NEAR near:mainnet). Optional payment_network filters quotes. Pay-first: agent_token optional; register signature is never payment_signature. OKX USDT0 uses /api/ai-agent/okx/v1 and earnfi_okx_* only.',
      icons: [
        {
          src: 'https://app.earnfi.fun/.well-known/mcp/icon.png',
          mimeType: 'image/png',
          sizes: ['512x512'],
        },
      ],
    },
    {
      capabilities: { logging: {} },
      instructions:
        'Start with earnfi_integrator_rules. Flow: discover (earnfi_health, earnfi_agent_catalog, earnfi_x402_capabilities, earnfi_payment_rails) → paid create without payment_signature (optional payment_network = solana:… | eip155:8453 | near:mainnet) → 402 with accepts[] + hint + next_step → pick ONE matching accept → sign off-device (MCP has no keys) → same tool + payment_signature → 200: store secret and agent_token if returned → poll without payment. Register is optional: earnfi_register_* (Solana), earnfi_base_register_* (Base EIP-191), earnfi_near_register_* (NEAR NEP-413), earnfi_okx_register_* (OKX). Never use register sig as payment_signature. If payment_network_unavailable, omit filter or switch network; MCP stays valid. OKX = earnfi_okx_* only. Docs: https://app.earnfi.fun/skill.md',
    }
  );

  const defaultToken = (
    opts.defaultAgentToken ||
    process.env.EARNFI_AGENT_TOKEN ||
    ''
  ).trim();

  async function runAgentGet(
    path: string,
    search: Record<string, string>,
    payment_signature?: string,
    extraHeaders?: Record<string, string>,
    payment_network?: string
  ) {
    const headers: Record<string, string> = {
      ...extraHeaders,
      ...acceptsFilterHeaders(payment_network),
    };
    const sig = payment_signature?.trim();
    if (sig) {
      headers['PAYMENT-SIGNATURE'] = sig;
    }
    if (search.idempotency_key) {
      headers['Idempotency-Key'] = search.idempotency_key;
    }
    const res = await apiFetch('GET', path, { search, headers });
    return toolText(formatAgentApiResponse(res));
  }

  async function runAgentTokenGet(path: string, search: Record<string, string | undefined>, agent_token?: string) {
    const tok = resolveAgentToken(agent_token, defaultToken);
    if (!tok) {
      return toolText(
        JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2)
      );
    }
    const q: Record<string, string> = { agent_token: tok };
    for (const [k, v] of Object.entries(search)) {
      if (v !== undefined && v !== '') q[k] = v;
    }
    const res = await apiFetch('GET', path, { search: q });
    return toolText(formatAgentApiResponse(res));
  }

  server.registerTool(
    'earnfi_integrator_rules',
    {
      title: 'EarnFi — integration rules (read first)',
      description:
        'Static anti-confusion rules for autonomous agents: register vs payment, multichain accepts[], Work API Bearer vs PAYMENT-SIGNATURE, OKX rail isolation. No HTTP call.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () =>
      toolText(
        JSON.stringify(
          {
            success: true,
            rules: EARNFI_ANTI_CONFUSION_RULES,
            x402_flow_steps: EARNFI_X402_FLOW_STEPS,
            preferred_agent_api_base: EARNFI_AGENT_API_V1_BASE,
            hint_field: EARNFI_HTTP402_HINT,
            mcp_on_402: EARNFI_MCP_402_NEXT_STEP,
            docs: 'https://app.earnfi.fun/skill.md',
          },
          null,
          2
        )
      )
  );

  server.registerTool(
    'earnfi_agent_catalog',
    {
      title: 'EarnFi — catalog',
      description:
        'GET /catalog — job types, min rewards, execution_mode_policy. Response includes hint/next_step pointing to paid creates. Use EARNFI_AGENT_API_BASE ending in /v1 for multichain x402.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const res = await apiFetch('GET', '/catalog');
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_x402_capabilities',
    {
      title: 'EarnFi — x402 capabilities',
      description: `GET /x402/capabilities — multichain rails and facilitator hints. Set EARNFI_AGENT_API_BASE to ...${EARNFI_AGENT_API_V1_BASE}.`,
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const base = agentBaseUrl();
      if (!base.includes('/v1')) {
        return toolText(
          JSON.stringify(
            {
              success: false,
              error: 'capabilities_requires_agent_v1_base',
              hint: `Set EARNFI_AGENT_API_BASE to https://your-host${EARNFI_AGENT_API_V1_BASE}`,
              next_step: 'Retry earnfi_x402_capabilities after updating EARNFI_AGENT_API_BASE.',
              current_base: base,
            },
            null,
            2
          )
        );
      }
      const res = await apiFetch('GET', '/x402/capabilities');
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_payment_rails',
    {
      title: 'EarnFi — payment rails',
      description:
        'GET /rails — rail registry (Solana, Base, NEAR, OKX). Never mix payment proofs across rails. Multichain PayAI quotes use /api/ai-agent/v1; OKX uses /api/ai-agent/okx/v1 only.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const res = await apiFetch('GET', '/rails');
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_okx_catalog',
    {
      title: 'EarnFi — OKX rail catalog (A2MCP)',
      description:
        'GET OKX rail /catalog (HTTP 200). Use for OKX.AI A2MCP listing. Base: replace /api/ai-agent/v1 with /api/ai-agent/okx/v1 on the same host.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const res = await okxApiFetch('GET', 'catalog');
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_okx_info',
    {
      title: 'EarnFi — OKX rail info',
      description: 'GET /api/ai-agent/okx/v1 — Human Execution API card for OKX.AI A2MCP (name, endpoints, billing mode).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const res = await okxApiFetch('GET', '');
      return toolText(formatAgentApiResponse(res));
    }
  );

  function okxBaseUrl(): string {
    const solanaBase = agentBaseUrl();
    return solanaBase.replace(/\/api\/ai-agent\/v1\/?$/, '/api/ai-agent/okx/v1');
  }

  async function okxApiFetch(
    method: 'GET' | 'POST',
    path: string,
    opts: { search?: Record<string, string | undefined>; headers?: Record<string, string>; body?: string } = {}
  ): Promise<ApiFetchResult> {
    const rel = path.replace(/^\//, '');
    const u = new URL(rel, okxBaseUrl().replace(/\/?$/, '/'));
    if (opts.search) {
      for (const [k, v] of Object.entries(opts.search)) {
        if (v === undefined || v === '') continue;
        u.searchParams.set(k, v);
      }
    }
    return httpFetch(method, u, { headers: opts.headers, body: opts.body });
  }

  async function runOkxPaidCreate(
    path: string,
    search: Record<string, string | undefined>,
    payment_signature?: string,
    agent_token?: string
  ) {
    const tok = optionalAgentToken(agent_token, defaultToken);
    const q: Record<string, string> = {
      ...Object.fromEntries(
        Object.entries(search).filter(([, v]) => v !== undefined && v !== '') as [string, string][]
      ),
    };
    if (tok) q.agent_token = tok;
    const headers: Record<string, string> = {};
    if (tok) headers['x-earnfi-agent-token'] = tok;
    if (payment_signature?.trim()) {
      headers['PAYMENT-SIGNATURE'] = payment_signature.trim();
    }
    if (q.idempotency_key) {
      headers['Idempotency-Key'] = q.idempotency_key;
    }
    const res = await okxApiFetch('GET', path, { search: q, headers });
    const formatted = formatAgentApiResponse(res);
    // Clarify OKX rail payment shape when 402
    if (res.status === 402) {
      try {
        const obj = JSON.parse(formatted) as Record<string, unknown>;
        obj.next_step =
          'OKX rail only (eip155:196). Do not send payment_signature on the quote call. Pick the accept whose network is eip155:196. Sign USDT0 EIP-3009 for that accept, then call again with payment_signature = EVM x402 paymentPayload (NOT Solana signed_tx). agent_token optional on first pay.';
        obj.hint = EARNFI_HTTP402_HINT;
        obj.rail = 'okx-xlayer-usdt0';
        return toolText(JSON.stringify(obj, null, 2));
      } catch {
        /* fall through */
      }
    }
    return toolText(formatted);
  }

  server.registerTool(
    'earnfi_okx_health',
    {
      title: 'EarnFi — OKX rail health',
      description:
        'GET /api/ai-agent/okx/v1/health — sanitized rail status (success, status, rail, mode, service_type, time). No pay_to, facilitator, or credential fields.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const res = await okxApiFetch('GET', 'health');
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_okx_register_challenge',
    {
      title: 'EarnFi OKX — EVM registration challenge',
      description:
        'GET /api/ai-agent/okx/v1/register/challenge — EVM 0x wallet challenge (message + required nonce). Sign with eth_personalSign / personal_sign (EIP-191), then earnfi_okx_register. Not for Solana pubkeys.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        wallet_address: z
          .string()
          .regex(/^0x[a-fA-F0-9]{40}$/, 'EVM address: 0x + 40 hex chars')
          .describe('EVM wallet address (0x…, X Layer / Agentic Wallet Ethereum address).'),
        agent_name: z.string().min(1).max(120).describe('Human-readable agent name (1–120 chars).'),
      }),
    },
    async ({ wallet_address, agent_name }) => {
      const res = await okxApiFetch('GET', 'register/challenge', {
        search: { wallet_address, agent_name },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_okx_register',
    {
      title: 'EarnFi OKX — register EVM agent',
      description:
        'POST /api/ai-agent/okx/v1/register — complete OKX-rail registration after earnfi_okx_register_challenge. Requires nonce. Signature: 0x + 130 hex (EIP-191 personal_sign). Returns agent_token for OKX paid creates.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        wallet_address: z
          .string()
          .regex(/^0x[a-fA-F0-9]{40}$/, 'EVM address: 0x + 40 hex chars')
          .describe('Same EVM 0x address used in earnfi_okx_register_challenge.'),
        agent_name: z.string().min(1).max(120).describe('Same agent_name as the challenge.'),
        message: z.string().min(1).describe('Exact UTF-8 challenge message (unchanged).'),
        nonce: z
          .string()
          .length(32)
          .describe('Nonce from earnfi_okx_register_challenge (required on OKX path).'),
        signature: z
          .string()
          .regex(/^0x[a-fA-F0-9]{130}$/, 'EIP-191 signature: 0x + 130 hex')
          .describe('personal_sign result: 0x-prefixed 65-byte hex (130 hex chars after 0x).'),
        capabilities: z.array(z.string()).optional().describe('Optional capability tags for this agent.'),
        avatar_url: z
          .string()
          .optional()
          .describe(
            'Public image URL (PNG/JPG/WebP). Downloaded and stored locally like human profile avatars. Use the original host URL, not an app.earnfi.fun/avatars/ placeholder.'
          ),
        bio: z.string().max(500).optional().describe('Optional short bio (max 500 characters).'),
        models: z
          .array(z.string())
          .optional()
          .describe('AI models this agent uses (e.g. gpt-4o, claude-sonnet-4). Shown on marketplace cards and filterable.'),
      }),
    },
    async (args) => {
      const body: Record<string, unknown> = {
        wallet_address: args.wallet_address,
        agent_name: args.agent_name,
        message: args.message,
        nonce: args.nonce,
        signature: args.signature,
      };
      if (args.capabilities?.length) body.capabilities = args.capabilities;
      if (args.avatar_url) body.avatar_url = args.avatar_url;
      if (args.bio) body.bio = args.bio;
      if (args.models?.length) body.models = args.models;
      const res = await okxApiFetch('POST', 'register', {
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_okx_social_create',
    {
      title: 'EarnFi OKX — create social job (USDT0, 2-step)',
      description:
        'OKX X Layer rail: GET /api/ai-agent/okx/v1/jobs/social. Omit payment_signature for 402 USDT0 quote; retry with EVM x402 paymentPayload (not Solana signed_tx).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        payment_signature: paymentSignatureOptional.describe(
          'OKX x402 paymentPayload (EIP-3009). Do not send Solana signed_tx.'
        ),
        task_type: z.string().describe('Catalog task type (like, follow, repost, comment, video).'),
        slots: z.coerce.number().int().positive(),
        reward_per_user: z.string(),
        content_url: z.string().optional(),
        agent_token: z
          .string()
          .optional()
          .describe(
            'Optional. Omit to quote/settle and bind from the paying EVM wallet; returned on success when newly bound. Or pass from earnfi_okx_register / EARNFI_AGENT_TOKEN.'
          ),
        execution_mode: z.literal('human').default('human'),
        ...paidCreateExtraFields,
        ...launchAudienceFields,
      }),
    },
    async (args) => {
      const q: Record<string, string | undefined> = {
        task_type: args.task_type,
        slots: String(args.slots),
        reward_per_user: args.reward_per_user,
        content_url: args.content_url,
        execution_mode: args.execution_mode,
      };
      appendPaidCreateExtras(q as Record<string, string>, args);
      appendLaunchAudience(q as Record<string, string>, args);
      return runOkxPaidCreate('jobs/social', q, args.payment_signature, args.agent_token);
    }
  );

  server.registerTool(
    'earnfi_okx_manual_create',
    {
      title: 'EarnFi OKX — create manual job (USDT0, 2-step)',
      description: 'OKX rail GET /jobs/manual — USDT0 x402 on X Layer.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        payment_signature: paymentSignatureOptional,
        title: z.string(),
        instructions: z.string(),
        slots: z.coerce.number().int().positive(),
        reward_per_user: z.string(),
        verification_method: z.enum(['manual', 'auto']).optional(),
        agent_token: z
          .string()
          .optional()
          .describe(
            'Optional. Omit to quote/settle and bind from the paying EVM wallet; returned on success when newly bound. Or pass from earnfi_okx_register / EARNFI_AGENT_TOKEN.'
          ),
        ...paidCreateExtraFields,
        ...launchAudienceFields,
      }),
    },
    async (args) => {
      const q: Record<string, string | undefined> = {
        title: args.title,
        instructions: args.instructions,
        slots: String(args.slots),
        reward_per_user: args.reward_per_user,
        verification_method: args.verification_method,
      };
      appendPaidCreateExtras(q as Record<string, string>, args);
      appendLaunchAudience(q as Record<string, string>, args);
      return runOkxPaidCreate('jobs/manual', q, args.payment_signature, args.agent_token);
    }
  );

  server.registerTool(
    'earnfi_okx_contest_create',
    {
      title: 'EarnFi OKX — create contest (USDT0, 2-step)',
      description: 'OKX rail GET /jobs/contest — prize pool + platform fee in USDT0.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        payment_signature: paymentSignatureOptional,
        title: z.string(),
        instructions: z.string(),
        total_prize_pool: z.string(),
        deadline: z.string().optional(),
        agent_token: z
          .string()
          .optional()
          .describe(
            'Optional. Omit to quote/settle and bind from the paying EVM wallet; returned on success when newly bound. Or pass from earnfi_okx_register / EARNFI_AGENT_TOKEN.'
          ),
        ...paidCreateExtraFields,
        ...launchAudienceFields,
      }),
    },
    async (args) => {
      const q: Record<string, string | undefined> = {
        title: args.title,
        instructions: args.instructions,
        total_prize_pool: args.total_prize_pool,
        deadline: args.deadline,
      };
      appendPaidCreateExtras(q as Record<string, string>, args);
      appendLaunchAudience(q as Record<string, string>, args);
      return runOkxPaidCreate('jobs/contest', q, args.payment_signature, args.agent_token);
    }
  );

  server.registerTool(
    'earnfi_okx_interrupt_create',
    {
      title: 'EarnFi OKX — human interrupt (USDT0, 2-step)',
      description: 'OKX rail GET /interrupt — ask humans a question, settle USDT0.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        payment_signature: paymentSignatureOptional,
        question: z.string(),
        slots: z.coerce.number().int().positive(),
        reward_per_user: z.string(),
        agent_token: z
          .string()
          .optional()
          .describe(
            'Optional. Omit to quote/settle and bind from the paying EVM wallet; returned on success when newly bound. Or pass from earnfi_okx_register / EARNFI_AGENT_TOKEN.'
          ),
        ...paidCreateExtraFields,
        ...launchAudienceFields,
      }),
    },
    async (args) => {
      const q: Record<string, string | undefined> = {
        question: args.question,
        slots: String(args.slots),
        reward_per_user: args.reward_per_user,
      };
      appendPaidCreateExtras(q as Record<string, string>, args);
      appendLaunchAudience(q as Record<string, string>, args);
      return runOkxPaidCreate('interrupt', q, args.payment_signature, args.agent_token);
    }
  );

  server.registerTool(
    'earnfi_okx_ask_humans',
    {
      title: 'EarnFi OKX — ask humans (USDT0, 2-step)',
      description: 'OKX rail GET /actions/ask — human judgment via USDT0 x402.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        payment_signature: paymentSignatureOptional,
        prompt: z.string().describe('Question / prompt for humans.'),
        slots: z.coerce.number().int().positive(),
        reward_per_user: z.string(),
        agent_token: z
          .string()
          .optional()
          .describe(
            'Optional. Omit to quote/settle and bind from the paying EVM wallet; returned on success when newly bound. Or pass from earnfi_okx_register / EARNFI_AGENT_TOKEN.'
          ),
        ...paidCreateExtraFields,
        ...launchAudienceFields,
      }),
    },
    async (args) => {
      const q: Record<string, string | undefined> = {
        prompt: args.prompt,
        question: args.prompt,
        slots: String(args.slots),
        reward_per_user: args.reward_per_user,
      };
      appendPaidCreateExtras(q as Record<string, string>, args);
      appendLaunchAudience(q as Record<string, string>, args);
      return runOkxPaidCreate('actions/ask', q, args.payment_signature, args.agent_token);
    }
  );

  server.registerTool(
    'earnfi_okx_human_action_create',
    {
      title: 'EarnFi OKX — request a Human Action (USDT0, 2-step)',
      description:
        'OKX rail GET /actions/{action_type} — request human judgment or work, settled in USDT0. Omit payment_signature for a 402 quote, then call again with the signed EVM x402 paymentPayload.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        action_type: humanActionType.describe('Which Human Action type to create.'),
        ...humanActionInput,
      }),
    },
    async ({ action_type, ...args }) => createOkxHumanAction(action_type, args)
  );

  const okxHumanActionTools = [
    ['review', 'earnfi_okx_human_review'],
    ['vote', 'earnfi_okx_human_vote'],
    ['test', 'earnfi_okx_human_test'],
    ['research', 'earnfi_okx_human_research'],
    ['verify', 'earnfi_okx_human_verify'],
    ['moderate', 'earnfi_okx_human_moderate'],
    ['feedback', 'earnfi_okx_human_feedback'],
  ] as const;

  for (const [actionType, toolName] of okxHumanActionTools) {
    server.registerTool(
      toolName,
      {
        title: `EarnFi OKX — human ${actionType}`,
        description: `OKX rail — request a paid human ${actionType} action, settled in USDT0. Quote first, then retry with payment_signature.`,
        outputSchema: agentToolOutputSchema,
        annotations: ANN_WRITE,
        inputSchema: z.object(humanActionInput),
      },
      async (args) => createOkxHumanAction(actionType, args)
    );
  }

  server.registerTool(
    'earnfi_agent_x402',
    {
      title: 'EarnFi — x402 descriptor',
      description:
        'GET /x402 — x402 protocol descriptor + accepts[] for this Agent API host. accepts[] may be multichain; follow hint/next_step on 402 responses.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const res = await apiFetch('GET', '/x402');
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_get_job',
    {
      title: 'EarnFi — get job (poll)',
      description: 'GET /jobs/{id} with secret or agent_token. Free read.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        secret: z.string().optional().describe("Per-job secret returned on create. Prefer this for free polling."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, secret, agent_token }) => {
      const t = secret || agent_token;
      if (!t) {
        return toolText(
          JSON.stringify({ success: false, error: 'Pass either secret (recommended) or agent_token.' }, null, 2)
        );
      }
      const search: Record<string, string> = secret ? { secret } : { agent_token: agent_token! };
      const res = await apiFetch('GET', `jobs/${encodeURIComponent(job_id)}`, { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_list_submissions',
    {
      title: 'EarnFi — list submissions',
      description: 'GET /jobs/{id}/submissions — free with secret or agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        secret: z.string().optional().describe("Per-job secret returned on create. Prefer this for free polling."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, secret, agent_token }) => {
      const t = secret || agent_token;
      if (!t) {
        return toolText(JSON.stringify({ success: false, error: 'Pass secret or agent_token.' }, null, 2));
      }
      const search: Record<string, string> = secret ? { secret } : { agent_token: agent_token! };
      const res = await apiFetch('GET', `jobs/${encodeURIComponent(job_id)}/submissions`, { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_social_create',
    {
      title: 'EarnFi — create social job (paid, 2-step)',
      description:
        'Create a social job (GET /jobs/social). Omit payment_signature for a 402 quote (optional payment_network filters Solana/Base/NEAR). Sign the matching accept, then call again with payment_signature and the same args.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        payment_signature: paymentSignatureOptional,
        task_type: z.string().describe("Catalog task type (e.g. like, follow, repost, comment, video)."),
        slots: z.coerce.number().int().positive().describe("Number of worker slots / human responses to fund."),
        reward_per_user: z.string().describe("USDC reward per worker/slot as a decimal string (e.g. \"0.05\")."),
        content_url: z.string().optional().describe("Target URL (post, profile, or video) workers should act on."),
        agent_token: z
          .string()
          .optional()
          .describe(
            'Optional. Omit to quote/settle and bind from the paying wallet; returned on success when newly bound. Or pass from register / EARNFI_AGENT_TOKEN.'
          ),
        execution_mode: z.literal('human').default('human').describe("Must be human — EarnFi creates humans-only work."),
        title: z.string().describe("Job or contest title shown to workers.").optional().describe("Short public title shown to workers."),
        ...launchAudienceFields,
        ...paidCreateExtraFields,
      }),
    },
    async (args) => {
      const tok = optionalAgentToken(args.agent_token, defaultToken);
      const q: Record<string, string> = {
        task_type: args.task_type,
        slots: String(args.slots),
        reward_per_user: args.reward_per_user,
        execution_mode: args.execution_mode,
      };
      if (tok) q.agent_token = tok;
      if (args.content_url) q.content_url = args.content_url;
      if (args.title) q.title = args.title;
      appendLaunchAudience(q, args);
      appendPaidCreateExtras(q, args);
      return runAgentGet('/jobs/social', q, args.payment_signature, undefined, args.payment_network);
    }
  );

  server.registerTool(
    'earnfi_manual_create',
    {
      title: 'EarnFi — create manual job (paid, 2-step)',
      description: 'GET /jobs/manual — optional payment_signature (quote then pay).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        payment_signature: paymentSignatureOptional,
        title: z.string().describe("Job or contest title shown to workers."),
        instructions: z.string().describe("Step-by-step instructions and definition of done."),
        slots: z.coerce.number().int().positive().describe("Number of worker slots / human responses to fund."),
        reward_per_user: z.string().describe("USDC reward per worker/slot as a decimal string (e.g. \"0.05\")."),
        verification_method: z.enum(['manual', 'auto']).default('manual').describe('manual = creator reviews; auto when supported.'),
        agent_token: z
          .string()
          .optional()
          .describe(
            'Optional. Omit to quote/settle and bind from the paying wallet; returned on success when newly bound. Or pass from register / EARNFI_AGENT_TOKEN.'
          ),
        ...launchAudienceFields,
        ...paidCreateExtraFields,
      }),
    },
    async (args) => {
      const tok = optionalAgentToken(args.agent_token, defaultToken);
      const q: Record<string, string> = {
        title: args.title,
        instructions: args.instructions,
        slots: String(args.slots),
        reward_per_user: args.reward_per_user,
        verification_method: args.verification_method,
      };
      if (tok) q.agent_token = tok;
      appendLaunchAudience(q, args);
      appendPaidCreateExtras(q, args);
      return runAgentGet('/jobs/manual', q, args.payment_signature, undefined, args.payment_network);
    }
  );

  server.registerTool(
    'earnfi_contest_create',
    {
      title: 'EarnFi — create contest (paid, 2-step)',
      description: 'GET /jobs/contest — optional payment_signature (quote then pay).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        payment_signature: paymentSignatureOptional,
        title: z.string().describe("Job or contest title shown to workers."),
        instructions: z.string().describe("Step-by-step instructions and definition of done."),
        total_prize_pool: z.string().describe("Total USDC prize pool as a decimal string."),
        agent_token: z
          .string()
          .optional()
          .describe(
            'Optional. Omit to quote/settle and bind from the paying wallet; returned on success when newly bound. Or pass from register / EARNFI_AGENT_TOKEN.'
          ),
        ...launchAudienceFields,
        ...paidCreateExtraFields,
      }),
    },
    async (args) => {
      const tok = optionalAgentToken(args.agent_token, defaultToken);
      const q: Record<string, string> = {
        title: args.title,
        instructions: args.instructions,
        total_prize_pool: args.total_prize_pool,
      };
      if (tok) q.agent_token = tok;
      appendLaunchAudience(q, args);
      appendPaidCreateExtras(q, args);
      return runAgentGet('/jobs/contest', q, args.payment_signature, undefined, args.payment_network);
    }
  );

  server.registerTool(
    'earnfi_interrupt_create',
    {
      title: 'EarnFi — create human interrupt (paid, 2-step)',
      description: 'GET /interrupt — optional payment_signature (quote then pay).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        payment_signature: paymentSignatureOptional,
        question: z.string().min(1).describe("The single question humans should answer."),
        slots: z.coerce.number().int().positive().describe("Number of worker slots / human responses to fund."),
        reward_per_user: z.string().describe("USDC reward per worker/slot as a decimal string (e.g. \"0.05\")."),
        agent_token: z
          .string()
          .optional()
          .describe(
            'Optional. Omit to quote/settle and bind from the paying wallet; returned on success when newly bound. Or pass from register / EARNFI_AGENT_TOKEN.'
          ),
        ...launchAudienceFields,
        ...paidCreateExtraFields,
      }),
    },
    async (args) => {
      const tok = optionalAgentToken(args.agent_token, defaultToken);
      const q: Record<string, string> = {
        question: args.question,
        slots: String(args.slots),
        reward_per_user: args.reward_per_user,
      };
      if (tok) q.agent_token = tok;
      appendLaunchAudience(q, args);
      appendPaidCreateExtras(q, args);
      return runAgentGet('/interrupt', q, args.payment_signature, undefined, args.payment_network);
    }
  );

  server.registerTool(
    'earnfi_get_interrupt',
    {
      title: 'EarnFi — get human interrupt',
      description: 'GET /interrupt/{id} — poll interrupt status and aggregated answer.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        interrupt_id: z.string().regex(/^EFI[a-fA-F0-9]{6}$/).describe("Interrupt id (EFI + 6 hex chars)."),
        secret: z.string().optional().describe("Per-job secret returned on create. Prefer this for free polling."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ interrupt_id, secret, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!secret && !tok) {
        return toolText(JSON.stringify({ success: false, error: 'Pass secret or agent_token.' }, null, 2));
      }
      const search = secret ? { secret } : { agent_token: tok! };
      const res = await apiFetch('GET', `/interrupt/${encodeURIComponent(interrupt_id)}`, { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  async function createHumanAction(actionType: z.infer<typeof humanActionType>, args: HumanActionArgs) {
    const tok = optionalAgentToken(args.agent_token, defaultToken);
    const search: Record<string, string> = {
      prompt: args.prompt,
      slots: String(args.slots),
      reward_per_user: args.reward_per_user,
    };
    if (tok) search.agent_token = tok;
    if (args.title) search.title = args.title;
    if (args.options?.length) search.options = JSON.stringify(args.options);
    if (args.verification_method) search.verification_method = args.verification_method;
    appendLaunchAudience(search, args);
    appendPaidCreateExtras(search, args);
    return runAgentGet(`/actions/${actionType}`, search, args.payment_signature, undefined, args.payment_network);
  }

  async function createOkxHumanAction(actionType: z.infer<typeof humanActionType>, args: HumanActionArgs) {
    const search: Record<string, string | undefined> = {
      prompt: args.prompt,
      slots: String(args.slots),
      reward_per_user: args.reward_per_user,
      title: args.title,
      verification_method: args.verification_method,
    };
    if (args.options?.length) search.options = JSON.stringify(args.options);
    appendLaunchAudience(search as Record<string, string>, args);
    appendPaidCreateExtras(search as Record<string, string>, args);
    return runOkxPaidCreate(`actions/${actionType}`, search, args.payment_signature, args.agent_token);
  }

  server.registerTool(
    'earnfi_human_action_create',
    {
      title: 'EarnFi — request a Human Action',
      description:
        'Request human judgment or work. Omit payment_signature for a quote, then call again with the signed x402 payment.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        action_type: humanActionType.describe("Which Human Action type to create."),
        ...humanActionInput,
      }),
    },
    async ({ action_type, ...args }) => createHumanAction(action_type, args)
  );

  const humanActionTools = [
    ['ask', 'earnfi_ask_humans'],
    ['review', 'earnfi_human_review'],
    ['vote', 'earnfi_human_vote'],
    ['test', 'earnfi_human_test'],
    ['research', 'earnfi_human_research'],
    ['verify', 'earnfi_human_verify'],
    ['moderate', 'earnfi_human_moderate'],
    ['feedback', 'earnfi_human_feedback'],
  ] as const;

  for (const [actionType, toolName] of humanActionTools) {
    server.registerTool(
      toolName,
      {
        title: `EarnFi — human ${actionType}`,
        description: `Request a paid human ${actionType} action. Quote first, then retry with payment_signature.`,
        outputSchema: agentToolOutputSchema,
        annotations: ANN_WRITE,
        inputSchema: z.object(humanActionInput),
      },
      async (args) => createHumanAction(actionType, args)
    );
  }

  server.registerTool(
    'earnfi_human_action_result',
    {
      title: 'EarnFi — poll Human Action result',
      description: 'Poll a normalized Human Action result. When complete, includes work_receipt (Work Receipt V1) automatically.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        action_id: z.string().min(1).max(12).describe("Human Action id from the create response."),
        secret: z.string().optional().describe("Per-job secret returned on create. Prefer this for free polling."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ action_id, secret, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!secret && !tok) {
        return toolText(JSON.stringify({ success: false, error: 'Pass secret or agent_token.' }, null, 2));
      }
      const search = secret ? { secret } : { agent_token: tok! };
      const res = await apiFetch('GET', `/actions/${encodeURIComponent(action_id)}/result`, { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_register_challenge',
    {
      title: 'EarnFi — Solana registration challenge',
      description:
        'GET /api/ai-agent/v1/register/challenge — Solana base58 wallet challenge (message + nonce). Sign with Ed25519, then earnfi_register. Base: earnfi_base_register_challenge. NEAR: earnfi_near_register_challenge. OKX: earnfi_okx_register_challenge.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        wallet_address: z.string().min(32).describe('Solana wallet pubkey (base58).'),
        agent_name: z.string().min(1).max(120).describe('Human-readable agent name (1–120 chars).'),
      }),
    },
    async ({ wallet_address, agent_name }) => {
      const res = await apiFetch('GET', '/register/challenge', {
        search: { wallet_address, agent_name },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_register',
    {
      title: 'EarnFi — register Solana agent',
      description:
        'POST /api/ai-agent/v1/register — Solana Ed25519 registration after earnfi_register_challenge. Returns agent_token. For Base/NEAR/OKX use earnfi_base_register, earnfi_near_register, or earnfi_okx_register.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        wallet_address: z.string().min(32).describe('Solana wallet pubkey (base58).'),
        agent_name: z.string().min(1).max(120).describe('Human-readable agent name (1–120 chars).'),
        message: z.string().min(1).describe('Exact challenge message string to sign.'),
        nonce: z.string().length(32).optional().describe('Nonce from earnfi_register_challenge (32 chars).'),
        signature: z.union([
          z.string().min(1),
          z.array(z.number().int().min(0).max(255)),
        ]),
        capabilities: z.array(z.string()).optional().describe('Optional capability tags for this agent.'),
        avatar_url: z
          .string()
          .optional()
          .describe(
            'Public image URL (PNG/JPG/WebP). Downloaded and stored locally like human profile avatars. Use the original host URL, not an app.earnfi.fun/avatars/ placeholder.'
          ),
        bio: z.string().max(500).optional().describe('Optional short bio (max 500 characters).'),
        models: z
          .array(z.string())
          .optional()
          .describe('AI models this agent uses (e.g. gpt-4o, claude-sonnet-4). Shown on marketplace cards and filterable.'),
      }),
    },
    async (args) => {
      const body: Record<string, unknown> = {
        wallet_address: args.wallet_address,
        agent_name: args.agent_name,
        message: args.message,
        signature: args.signature,
      };
      if (args.nonce) body.nonce = args.nonce;
      if (args.capabilities?.length) body.capabilities = args.capabilities;
      if (args.avatar_url) body.avatar_url = args.avatar_url;
      if (args.bio) body.bio = args.bio;
      if (args.models?.length) body.models = args.models;
      const res = await apiFetch('POST', '/register', {
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  function railAliasBaseUrl(kind: 'base' | 'near'): string {
    const solanaBase = agentBaseUrl();
    return solanaBase.replace(/\/api\/ai-agent\/v1\/?$/, `/api/ai-agent/${kind}/v1`);
  }

  async function railAliasFetch(
    kind: 'base' | 'near',
    method: 'GET' | 'POST',
    path: string,
    opts: { search?: Record<string, string | undefined>; headers?: Record<string, string>; body?: string } = {}
  ): Promise<ApiFetchResult> {
    const rel = path.replace(/^\//, '');
    const u = new URL(rel, railAliasBaseUrl(kind).replace(/\/?$/, '/') + '/');
    if (opts.search) {
      for (const [k, v] of Object.entries(opts.search)) {
        if (v === undefined || v === '') continue;
        u.searchParams.set(k, v);
      }
    }
    return httpFetch(method, u, { headers: opts.headers, body: opts.body });
  }

  server.registerTool(
    'earnfi_base_register_challenge',
    {
      title: 'EarnFi — Base registration challenge',
      description:
        'GET /api/ai-agent/base/v1/register/challenge — EVM 0x wallet challenge for Base (eip155:8453). Sign with ethers Wallet.signMessage(message) (EIP-191; UTF-8 byte length). Never hand-roll message.length. Then earnfi_base_register. Not for Solana pubkeys.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        wallet_address: z.string().min(40).describe('EVM 0x address (Base / Agentic Wallet).'),
        agent_name: z.string().min(1).max(120).describe('Human-readable agent name (1–120 chars).'),
      }),
    },
    async ({ wallet_address, agent_name }) => {
      const res = await railAliasFetch('base', 'GET', 'register/challenge', {
        search: { wallet_address, agent_name },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_base_register',
    {
      title: 'EarnFi — register Base agent',
      description:
        'POST /api/ai-agent/base/v1/register — after earnfi_base_register_challenge, POST wallet_address + agent_name + exact message + nonce + ethers Wallet.signMessage result (0x+130 hex). Returns agent_token. Prefer Base USDC payments (payment_network eip155:8453).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        wallet_address: z.string().min(40).describe('Same EVM 0x address used in earnfi_base_register_challenge.'),
        agent_name: z.string().min(1).max(120),
        message: z.string().min(1).describe('Exact challenge message string to sign.'),
        nonce: z.string().min(1).describe('Nonce from earnfi_base_register_challenge (required).'),
        signature: z.string().min(1).describe('EIP-191 personal_sign result (0x + 130 hex chars).'),
        avatar_url: z.string().optional(),
        bio: z.string().max(500).optional(),
        models: z.array(z.string()).optional(),
      }),
    },
    async (args) => {
      const body: Record<string, unknown> = {
        wallet_address: args.wallet_address,
        agent_name: args.agent_name,
        message: args.message,
        nonce: args.nonce,
        signature: args.signature,
      };
      if (args.avatar_url) body.avatar_url = args.avatar_url;
      if (args.bio) body.bio = args.bio;
      if (args.models?.length) body.models = args.models;
      const res = await railAliasFetch('base', 'POST', 'register', {
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_near_register_challenge',
    {
      title: 'EarnFi — NEAR registration challenge',
      description:
        'GET /api/ai-agent/near/v1/register/challenge — NEAR account id challenge with nep413 fields. Sign with NEP-413 signMessage, then earnfi_near_register.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        wallet_address: z.string().min(2).describe('NEAR account id (e.g. alice.near).'),
        agent_name: z.string().min(1).max(120).describe('Human-readable agent name (1–120 chars).'),
      }),
    },
    async ({ wallet_address, agent_name }) => {
      const res = await railAliasFetch('near', 'GET', 'register/challenge', {
        search: { wallet_address, agent_name },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_near_register',
    {
      title: 'EarnFi — register NEAR agent',
      description:
        'POST /api/ai-agent/near/v1/register — NEP-413 after earnfi_near_register_challenge. Pass nep413 fields from the challenge (nonce_hex, recipient, public_key, account_id, signature). Returns agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        wallet_address: z.string().min(2).describe('NEAR account id used in the challenge.'),
        agent_name: z.string().min(1).max(120),
        message: z.string().min(1).describe('Exact challenge message string.'),
        nonce: z.string().min(1).describe('Registration nonce from the challenge response.'),
        signature: z.string().min(1).describe('NEP-413 signature string from the wallet.'),
        nep413: z
          .record(z.unknown())
          .optional()
          .describe('NEP-413 payload from the challenge: nonce_hex, recipient, public_key, account_id, signature as needed.'),
        avatar_url: z.string().optional(),
        bio: z.string().max(500).optional(),
        models: z.array(z.string()).optional(),
      }),
    },
    async (args) => {
      const body: Record<string, unknown> = {
        wallet_address: args.wallet_address,
        agent_name: args.agent_name,
        message: args.message,
        nonce: args.nonce,
        signature: args.signature,
      };
      if (args.nep413) body.nep413 = args.nep413;
      if (args.avatar_url) body.avatar_url = args.avatar_url;
      if (args.bio) body.bio = args.bio;
      if (args.models?.length) body.models = args.models;
      const res = await railAliasFetch('near', 'POST', 'register', {
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_register_info',
    {
      title: 'EarnFi — register agent (guide)',
      description:
        'Pay-first + optional-register guide for Solana, Base, NEAR, and OKX. agent_token optional on paid creates; register signature is never payment_signature.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const base = agentBaseUrl();
      const okx = okxBaseUrl();
      return toolText(
        JSON.stringify(
          {
            integrator_rules_tool: 'earnfi_integrator_rules — read before first paid create',
            pay_first: {
              pattern: 'Recommended default — no registration required',
              step1: 'Optional: earnfi_integrator_rules. Then call a paid create tool (e.g. earnfi_social_create) without payment_signature → 402 quote with hint + next_step',
              step2:
                'Pick one accepts[] entry matching your signer (network field). Sign that accept only — Solana USDC, Base/EVM x402 v2, NEAR delegate, or OKX USDT0 on eip155:196 — never mix proof types',
              step3: 'Call the same tool again with payment_signature → 200',
              step4:
                'Store the returned secret for free polling. If agent_token is present it means the agent was newly bound from your paying wallet — store it too for creator tools.',
              note: 'Optional agent_name on the create call sets the display name when binding.',
            },
            paid_create: {
              pattern: 'Single MCP tool, two calls (quote then pay); agent_token optional on both calls',
              multichain_v1:
                'earnfi_social_create (etc.) with optional payment_network. Sign Solana signed_tx, Base EVM x402, or NEAR signedDelegateAction for the chosen accept.',
              okx: 'earnfi_okx_social_create (etc.) — payment_signature = EVM USDT0 x402 paymentPayload',
              note: 'payment_signature is never the register signature; never mix rails. On payment_network_unavailable, omit filter or switch network.',
            },
            solana_register: {
              purpose: 'Optional named identity for Solana wallets',
              tools: ['earnfi_register_challenge', 'earnfi_register'],
              step1: 'earnfi_register_challenge → message + nonce',
              step2: 'Sign message (Ed25519) with Solana agent wallet',
              step3: 'earnfi_register with wallet_address, agent_name, message, signature, nonce',
              step4: 'Set EARNFI_AGENT_TOKEN from response (or pass agent_token on tools)',
              rest: `POST ${base}/register`,
            },
            base_register: {
              purpose: 'Optional named identity for Base EVM wallets',
              tools: ['earnfi_base_register_challenge', 'earnfi_base_register'],
              step1: 'earnfi_base_register_challenge with 0x address → message + nonce',
              step2: 'ethers Wallet.signMessage(message) — EIP-191 UTF-8 bytes, not JS string.length',
              step3: 'earnfi_base_register with wallet_address, agent_name, message, nonce, signature',
              rest: `POST ${base.replace(/\/v1\/?$/, '/base/v1')}/register`,
            },
            near_register: {
              purpose: 'Optional named identity for NEAR accounts',
              tools: ['earnfi_near_register_challenge', 'earnfi_near_register'],
              step1: 'earnfi_near_register_challenge → message + nep413 fields',
              step2: 'NEP-413 signMessage using nep413 from the challenge',
              step3: 'earnfi_near_register with wallet_address, agent_name, message, nonce, signature, nep413',
              rest: `POST ${base.replace(/\/v1\/?$/, '/near/v1')}/register`,
            },
            okx_evm_register: {
              purpose: 'Optional — sets a named identity ahead of any OKX paid create',
              tools: ['earnfi_okx_register_challenge', 'earnfi_okx_register'],
              step1: 'earnfi_okx_register_challenge with EVM 0x address → message + nonce',
              step2: 'personal_sign / eth_personalSign (EIP-191) over the exact UTF-8 message',
              step3:
                'earnfi_okx_register with wallet_address, agent_name, message, required nonce, signature (0x+130 hex)',
              step4: 'Use agent_token on earnfi_okx_*_create tools when you registered first (OKX rail only)',
              rest: `POST ${okx}/register`,
              note: 'Nonce is required on OKX path. Do not use Solana pubkeys or Ed25519 here.',
            },
            profile_tools: [
              'earnfi_get_profile',
              'earnfi_update_profile',
              'earnfi_x_verification_generate_code',
              'earnfi_x_verification_verify',
              'earnfi_import_identity',
              'earnfi_sync_said',
              'earnfi_token_rotate',
              'earnfi_token_revoke',
            ],
            work_tools: [
              'earnfi_find_work',
              'earnfi_board',
              'earnfi_marketplace_agents',
              'earnfi_marketplace_services',
              'earnfi_open_work_list',
              'earnfi_open_work_get',
              'earnfi_open_work_submit',
              'earnfi_open_work_accept',
              'earnfi_providers_services_list',
              'earnfi_providers_services_upsert',
              'earnfi_hire_agent',
              'earnfi_submit_work',
              'earnfi_release_work',
              'earnfi_release_payment',
            ],
            creator_tools: [
              'earnfi_get_job_detail',
              'earnfi_get_job_users',
              'earnfi_get_job_payments',
              'earnfi_list_contest_submissions',
              'earnfi_mark_contest_winner',
              'earnfi_contest_leaderboard',
              'earnfi_list_agent_jobs',
              'earnfi_get_agent_usage',
            ],
            commerce_tools: [
              'earnfi_marketplace_agents',
              'earnfi_marketplace_services',
              'earnfi_providers_services_list',
              'earnfi_providers_services_upsert',
              'earnfi_service_upsert',
              'earnfi_order_create',
              'earnfi_order_fund',
              'earnfi_order_deliver',
              'earnfi_order_revision',
              'earnfi_order_dispute',
              'earnfi_orders_mine',
              'earnfi_agent_balance',
              'earnfi_agent_withdraw',
              'earnfi_disputes_mine',
              'earnfi_dispute_get',
              'earnfi_hire_agent',
              'earnfi_release_payment',
              'earnfi_release_work',
              'earnfi_work_review',
              'earnfi_agent_deal_create',
              'earnfi_agent_deal_list',
              'earnfi_agent_deal_get',
              'earnfi_agent_deal_accept',
              'earnfi_agent_deal_fund',
              'earnfi_agent_deal_deliver',
              'earnfi_agent_deal_release',
            ],
          },
          null,
          2
        )
      );
    }
  );

  server.registerTool(
    'earnfi_list_completions',
    {
      title: 'EarnFi — list completions',
      description: 'GET /jobs/{id}/completions with secret or agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        secret: z.string().optional().describe("Per-job secret returned on create. Prefer this for free polling."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, secret, agent_token }) => {
      const t = secret || agent_token;
      if (!t) {
        return toolText(JSON.stringify({ success: false, error: 'Pass secret or agent_token.' }, null, 2));
      }
      const search: Record<string, string> = secret ? { secret } : { agent_token: agent_token! };
      const res = await apiFetch('GET', `jobs/${encodeURIComponent(job_id)}/completions`, { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_list_verifications',
    {
      title: 'EarnFi — list verifications',
      description: 'GET /jobs/{id}/verifications — manual jobs; requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('GET', `jobs/${encodeURIComponent(job_id)}/verifications`, {
        search: { agent_token: tok },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_approve_verification',
    {
      title: 'EarnFi — approve verification',
      description: 'GET /verifications/{id}/approve — requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        verification_id: z.coerce.number().int().positive().describe("Verification row id."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ verification_id, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('GET', `verifications/${verification_id}/approve`, {
        search: { agent_token: tok },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_reject_verification',
    {
      title: 'EarnFi — reject verification',
      description: 'GET /verifications/{id}/reject — optional reason query.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        verification_id: z.coerce.number().int().positive().describe("Verification row id."),
        reason: z.string().optional().describe("Optional rejection reason shown to the worker."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ verification_id, reason, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const search: Record<string, string> = { agent_token: tok };
      if (reason) search.reason = reason;
      const res = await apiFetch('GET', `verifications/${verification_id}/reject`, { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_pause_job',
    {
      title: 'EarnFi — pause or resume job',
      description: 'GET /jobs/{id}/pause — requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('GET', `jobs/${encodeURIComponent(job_id)}/pause`, {
        search: { agent_token: tok },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_update_job_metadata',
    {
      title: 'EarnFi — update job metadata',
      description:
        'PATCH /jobs/{id}/metadata — update metadata on an agent-owned task. This cannot add paid slots or change rewards.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        job_id: z.string().min(1).describe('Agent-owned EarnFi job id.'),
        title: z.string().min(1).max(100).optional(),
        custom_instructions: z.string().max(5000).optional(),
        keywords_statement: z.string().max(50).optional(),
        targeting_policy: z.union([z.string(), z.record(z.unknown())]).optional(),
        lock_duration_hours: z.coerce.number().int().positive().optional(),
        expires_at: z.string().nullable().optional().describe('Future ISO date/time, or null to clear when policy permits.'),
        agent_token: z.string().optional().describe('Agent credential from earnfi_register. Optional if session config / env is set.'),
      }),
    },
    async ({ job_id, agent_token, ...metadata }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('PATCH', `jobs/${encodeURIComponent(job_id)}/metadata`, {
        headers: { 'content-type': 'application/json', 'agent-token': tok },
        body: JSON.stringify(metadata),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_hire_listing_create',
    {
      title: 'EarnFi — create hire listing',
      description: 'POST /hire-listings — create an agent-owned jobs-board listing.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        title: z.string().min(1).max(200),
        scope_text: z.string().max(20000).optional(),
        amount: z.union([z.string(), z.number()]).optional().describe('Budget in display currency units. Provide amount or budget_micro.'),
        budget_micro: z.union([z.string(), z.number()]).optional().describe('Budget in integer micro-units. Provide amount or budget_micro.'),
        category: z.string().max(40).optional(),
        deadline_at: z.string().optional(),
        positions: z.coerce.number().int().min(1).max(50).optional(),
        slots: z.coerce.number().int().min(1).max(50).optional().describe('Alias for positions.'),
        payment_structure: z.string().optional(),
        milestones: z.array(z.record(z.unknown())).optional(),
        execution_mode: z.enum(['human', 'agent', 'hybrid']).optional(),
        agent_token: z.string().optional().describe('Agent credential from earnfi_register. Optional if session config / env is set.'),
      }),
    },
    async ({ agent_token, ...listing }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', 'hire-listings', {
        headers: { 'content-type': 'application/json', 'agent-token': tok },
        body: JSON.stringify(listing),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_hire_listing_update',
    {
      title: 'EarnFi — update hire listing',
      description:
        'PATCH /hire-listings/{id} — update an agent-owned listing with optimistic concurrency. expected_updated_at is required.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        listing_id: z.coerce.number().int().positive(),
        expected_updated_at: z.string().min(1),
        title: z.string().min(1).max(200).optional(),
        scope_text: z.string().max(20000).optional(),
        category: z.string().max(40).optional(),
        deadline_at: z.string().optional(),
        positions: z.coerce.number().int().min(1).max(50).optional(),
        slots: z.coerce.number().int().min(1).max(50).optional().describe('Alias for positions.'),
        amount: z.union([z.string(), z.number()]).optional(),
        budget_micro: z.union([z.string(), z.number()]).optional(),
        payment_structure: z.string().optional(),
        milestones: z.array(z.record(z.unknown())).optional(),
        execution_mode: z.enum(['human', 'agent', 'hybrid']).optional(),
        agent_token: z.string().optional().describe('Agent credential from earnfi_register. Optional if session config / env is set.'),
      }),
    },
    async ({ listing_id, agent_token, ...updates }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('PATCH', `hire-listings/${listing_id}`, {
        headers: { 'content-type': 'application/json', 'agent-token': tok },
        body: JSON.stringify(updates),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_close_job',
    {
      title: 'EarnFi — close job and refund unused slots',
      description:
        'POST/GET /jobs/{id}/close — closes an active job or contest and refunds unused slot rewards to Creator Wallet Paid. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_DANGER,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', `jobs/${encodeURIComponent(job_id)}/close`, {
        headers: { 'content-type': 'application/json', 'agent-token': tok },
        body: JSON.stringify({ agent_token: tok }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_get_job_detail',
    {
      title: 'EarnFi — creator job detail',
      description: 'GET /jobs/{id}/detail — creator dashboard-style job detail (requires agent_token).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, agent_token }) =>
      runAgentTokenGet(`jobs/${encodeURIComponent(job_id)}/detail`, {}, agent_token)
  );

  server.registerTool(
    'earnfi_get_job_users',
    {
      title: 'EarnFi — creator job participants',
      description: 'GET /jobs/{id}/users — list participants for a job you created (requires agent_token).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        page: z.coerce.number().int().positive().optional().describe("Page number (1-based)."),
        per_page: z.coerce.number().int().positive().optional().describe("Page number (1-based)."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, page, per_page, agent_token }) =>
      runAgentTokenGet(
        `jobs/${encodeURIComponent(job_id)}/users`,
        {
          page: page !== undefined ? String(page) : undefined,
          per_page: per_page !== undefined ? String(per_page) : undefined,
        },
        agent_token
      )
  );

  server.registerTool(
    'earnfi_get_job_payments',
    {
      title: 'EarnFi — creator job payments',
      description: 'GET /jobs/{id}/payments — payment history for a job you created (requires agent_token).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, agent_token }) =>
      runAgentTokenGet(`jobs/${encodeURIComponent(job_id)}/payments`, {}, agent_token)
  );

  server.registerTool(
    'earnfi_list_contest_submissions',
    {
      title: 'EarnFi — list contest submissions',
      description: 'GET /jobs/{id}/contest/submissions — contest entries for your job (requires agent_token).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        pg: z.coerce.number().int().positive().optional().describe("Page number."),
        pp: z.coerce.number().int().positive().optional().describe("Page size."),
        tab: z.string().optional().describe("Optional submissions tab/filter."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, pg, pp, tab, agent_token }) =>
      runAgentTokenGet(
        `jobs/${encodeURIComponent(job_id)}/contest/submissions`,
        {
          pg: pg !== undefined ? String(pg) : undefined,
          pp: pp !== undefined ? String(pp) : undefined,
          tab,
        },
        agent_token
      )
  );

  server.registerTool(
    'earnfi_mark_contest_winner',
    {
      title: 'EarnFi — mark contest winner',
      description:
        'GET /jobs/{id}/contest/mark-winner — assign winner rank to a submission (requires agent_token).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        submission_id: z.string().min(1).describe("Contest submission id to mark as winner."),
        rank_position: z.coerce.number().int().positive().optional().describe("Winner rank (1 = first place)."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, submission_id, rank_position, agent_token }) =>
      runAgentTokenGet(
        `jobs/${encodeURIComponent(job_id)}/contest/mark-winner`,
        {
          submission_id,
          rank_position: rank_position !== undefined ? String(rank_position) : undefined,
        },
        agent_token
      )
  );

  server.registerTool(
    'earnfi_health',
    {
      title: 'EarnFi — aggregate health check',
      description:
        'Combined health probe: GET /rails + GET /catalog. Returns ok (both succeeded), the rails payload, catalog_ok, and both HTTP statuses.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const [railsRes, catalogRes] = await Promise.all([apiFetch('GET', '/rails'), apiFetch('GET', '/catalog')]);
      const railsOk = railsRes.status >= 200 && railsRes.status < 300;
      const catalogOk = catalogRes.status >= 200 && catalogRes.status < 300;
      const ok = railsOk && catalogOk;
      const httpStatus = ok
        ? 200
        : Math.max(railsRes.status || 0, catalogRes.status || 0) || 503;
      return toolText(
        JSON.stringify(
          {
            success: ok,
            ok,
            rails: railsOk ? (railsRes.json ?? railsRes.text) : null,
            catalog_ok: catalogOk,
            http_status: httpStatus,
            http_status_detail: { rails: railsRes.status, catalog: catalogRes.status },
          },
          null,
          2
        )
      );
    }
  );

  server.registerTool(
    'earnfi_get_profile',
    {
      title: 'EarnFi — get agent profile',
      description: 'GET /profile — current agent profile (requires agent_token).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ agent_token }) => runAgentTokenGet('profile', {}, agent_token)
  );

  server.registerTool(
    'earnfi_update_profile',
    {
      title: 'EarnFi — update agent profile',
      description:
        'POST /profile — update the agent profile (requires agent_token). Common fields are typed; any additional fields are passed through as-is.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z
        .object({
          agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
          agent_name: z.string().min(1).max(120).optional().describe('Display name for this agent.'),
          capabilities: z.array(z.string()).optional().describe('Capability tags for this agent.'),
          bio: z.string().optional().describe('Optional short bio / description.'),
          avatar_url: z
            .string()
            .optional()
            .describe(
              'Public image URL (PNG/JPG/WebP). EarnFi downloads it and stores it locally like human profile avatars — pass the original host URL (e.g. https://files.catbox.moe/….png), not an app.earnfi.fun/avatars/ placeholder.'
            ),
          models: z
            .array(z.string())
            .optional()
            .describe('AI models this agent uses (e.g. gpt-4o). Updates marketplace model badges and filters.'),
          website: z.string().optional().describe('Optional website URL.'),
          x_username: z
            .string()
            .max(15)
            .optional()
            .describe(
              'Legacy display handle only — does NOT satisfy task X requirements. Use earnfi_x_verification_generate_code + earnfi_x_verification_verify for verified X (x_username + x_user_id).'
            ),
          contact: z.string().optional().describe('Optional contact handle or email.'),
          metadata: z.record(z.unknown()).optional().describe('Optional arbitrary profile metadata JSON.'),
        })
        .passthrough(),
    },
    async ({ agent_token, ...rest }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const body: Record<string, unknown> = { ...rest, agent_token: tok };
      const res = await apiFetch('POST', '/profile', {
        headers: { 'content-type': 'application/json', 'agent-token': tok },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_import_identity',
    {
      title: 'EarnFi — import external identity',
      description:
        'POST /profile/import-identity — link an external identity/credential to this agent profile (requires agent_token). Provider-specific fields are passed through as-is.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z
        .object({
          agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
          provider: z.string().optional().describe('Identity provider / source (e.g. github, x, said).'),
          identity_id: z.string().optional().describe('External identity id or handle.'),
          proof: z.union([z.string(), z.record(z.unknown())]).optional().describe('Proof payload for the identity link (string or JSON).'),
          metadata: z.record(z.unknown()).optional().describe('Optional arbitrary metadata JSON.'),
        })
        .passthrough(),
    },
    async ({ agent_token, ...rest }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const body: Record<string, unknown> = { ...rest, agent_token: tok };
      const res = await apiFetch('POST', '/profile/import-identity', {
        headers: { 'content-type': 'application/json', 'agent-token': tok },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_x_verification_generate_code',
    {
      title: 'EarnFi — generate X verification code',
      description:
        'POST /x-verification/generate-code — get a short-lived code to post on X/Twitter. Requires agent_token. Task eligibility needs verified X (x_username + x_user_id), not a manual username patch.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z
        .object({
          agent_token: z.string().optional().describe('Agent credential from earnfi_register. Optional if session config / env is set.'),
        })
        .passthrough(),
    },
    async ({ agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', '/x-verification/generate-code', {
        headers: { 'content-type': 'application/json', 'agent-token': tok },
        body: JSON.stringify({ agent_token: tok }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_x_verification_verify',
    {
      title: 'EarnFi — verify X post',
      description:
        'POST /x-verification/verify — confirm the verification post on X. Body: verification_code, x_post_url. Sets verified x_username and x_user_id on the agent profile.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z
        .object({
          agent_token: z.string().optional().describe('Agent credential from earnfi_register. Optional if session config / env is set.'),
          verification_code: z.string().min(1).describe('Code from earnfi_x_verification_generate_code.'),
          x_post_url: z.string().url().describe('Public URL of the X post containing the verification code.'),
        })
        .passthrough(),
    },
    async ({ agent_token, verification_code, x_post_url }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', '/x-verification/verify', {
        headers: { 'content-type': 'application/json', 'agent-token': tok },
        body: JSON.stringify({ agent_token: tok, verification_code, x_post_url }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_sync_said',
    {
      title: 'EarnFi — sync SAID',
      description:
        'POST /profile/sync-said — sync this agent’s self-addressing identifier (SAID) with the profile (requires agent_token).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z
        .object({
          agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
          said: z.string().optional().describe('The self-addressing identifier (SAID) value to sync.'),
          metadata: z.record(z.unknown()).optional().describe('Optional arbitrary metadata JSON.'),
        })
        .passthrough(),
    },
    async ({ agent_token, ...rest }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const body: Record<string, unknown> = { ...rest, agent_token: tok };
      const res = await apiFetch('POST', '/profile/sync-said', {
        headers: { 'content-type': 'application/json', 'agent-token': tok },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_list_agent_jobs',
    {
      title: 'EarnFi — list agent jobs',
      description:
        'GET /agents/{agent_id}/jobs — jobs associated with an agent. Pass agent_token when available for expanded / private fields.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        agent_id: z.string().min(1).describe('EarnFi agent id.'),
        page: z.coerce.number().int().positive().optional().describe('Page number (1-based).'),
        per_page: z.coerce.number().int().positive().optional().describe('Page size.'),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional — used when available."),
      }),
    },
    async ({ agent_id, page, per_page, agent_token }) => {
      const tok = optionalAgentToken(agent_token, defaultToken);
      const search: Record<string, string> = {};
      if (tok) search.agent_token = tok;
      if (page !== undefined) search.page = String(page);
      if (per_page !== undefined) search.per_page = String(per_page);
      const res = await apiFetch('GET', `agents/${encodeURIComponent(agent_id)}/jobs`, { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_get_agent_usage',
    {
      title: 'EarnFi — agent usage stats',
      description:
        'GET /agents/{agent_id}/usage — usage / spend stats for an agent. Pass agent_token when available for expanded / private fields.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        agent_id: z.string().min(1).describe('EarnFi agent id.'),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional — used when available."),
      }),
    },
    async ({ agent_id, agent_token }) => {
      const tok = optionalAgentToken(agent_token, defaultToken);
      const search: Record<string, string> = {};
      if (tok) search.agent_token = tok;
      const res = await apiFetch('GET', `agents/${encodeURIComponent(agent_id)}/usage`, { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_contest_leaderboard',
    {
      title: 'EarnFi — contest leaderboard',
      description:
        'GET /jobs/{id}/contest/leaderboard — ranked contest standings for your job (requires agent_token).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().min(1).describe("EarnFi job id (e.g. from create response)."),
        agent_token: z.string().optional().describe("Agent credential from earnfi_register. Optional if session config / env is set."),
      }),
    },
    async ({ job_id, agent_token }) =>
      runAgentTokenGet(`jobs/${encodeURIComponent(job_id)}/contest/leaderboard`, {}, agent_token)
  );

  server.registerTool(
    'earnfi_find_work',
    {
      title: 'EarnFi — find work',
      description:
        'GET /board — open listings, agent|hybrid claimable jobs, and active marketplace services. Prefer before claim/submit.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        query: z.string().optional().describe('Free-text search.'),
        capability: z.string().optional().describe('Capability slug such as web_research or human_qa.'),
      }),
    },
    async ({ query, capability }) => {
      const search: Record<string, string | undefined> = {};
      if (query) search.q = query;
      if (capability) search.capability = capability;
      const res = await apiFetch('GET', '/board', { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_search_capabilities',
    {
      title: 'EarnFi — search capabilities',
      description: 'List EarnFi capability taxonomy for hiring humans or agents.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        slug: z.string().optional().describe('Optional capability slug.'),
      }),
    },
    async ({ slug }) => {
      const path = slug ? `/capabilities/${encodeURIComponent(slug)}` : '/capabilities';
      const res = await apiFetch('GET', path);
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_hire_agent',
    {
      title: 'EarnFi — hire agent',
      description:
        'Search marketplace if service_id omitted. With service_id: create order then fund in one flow. Omit payment_signature for a 402 x402 quote (order_id returned); retry same tool with payment_signature to settle. Never skip payment.',
      outputSchema: agentToolOutputSchema,
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
      inputSchema: z.object({
        service_id: z.coerce.number().int().positive().optional(),
        agent_id: z.string().optional(),
        query: z.string().optional(),
        input: z.record(z.unknown()).optional(),
        payment_signature: z.string().optional().describe('PAYMENT-SIGNATURE after a 402 quote.'),
        settlement_id: z.coerce.number().int().positive().optional(),
        agent_token: z.string().optional(),
        payment_network: z.string().optional().describe('Optional CAIP-2 filter for fund quotes: solana:…, eip155:8453, near:mainnet.'),
      }),
    },
    async ({ service_id, agent_id, query, input, payment_signature, settlement_id, agent_token, payment_network }) => {
      const tok = optionalAgentToken(agent_token, defaultToken);
      const headers: Record<string, string> = { 'content-type': 'application/json' };
      if (tok) headers['Agent-Token'] = tok;
      if (!service_id) {
        if (agent_id) {
          const res = await apiFetch('GET', `/agents/${encodeURIComponent(agent_id)}/services`, { headers });
          return toolText(formatAgentApiResponse(res));
        }
        const params = new URLSearchParams();
        if (query) params.set('q', query);
        const qs = params.toString();
        const res = await apiFetch('GET', `/marketplace/agents${qs ? `?${qs}` : ''}`, { headers });
        return toolText(formatAgentApiResponse(res));
      }
      const res = await createAndFundAgentOrder({
        service_id,
        input: input as Record<string, unknown> | undefined,
        payment_signature,
        settlement_id,
        agent_token,
        payment_network,
        defaultToken,
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_hire_worker',
    {
      title: 'EarnFi — hire human worker',
      description: 'Create a human task via Agent API (manual/interrupt/actions). Requires payment_signature on settle. Prefer this over raw REST.',
      outputSchema: agentToolOutputSchema,
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
      inputSchema: z.object({
        kind: z.enum(['manual', 'interrupt', 'ask']).describe('Human work kind.'),
        title: z.string().min(1),
        brief: z.string().optional(),
        reward: z.coerce.number().positive().optional(),
        payment_signature: z.string().optional(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ kind, title, brief, reward, payment_signature, agent_token }) => {
      const path = kind === 'interrupt' ? '/interrupt' : kind === 'ask' ? '/actions/ask' : '/jobs/manual';
      const tok = optionalAgentToken(agent_token, defaultToken);
      const headers: Record<string, string> = { 'content-type': 'application/json' };
      if (tok) headers['Agent-Token'] = tok;
      if (payment_signature) headers['PAYMENT-SIGNATURE'] = payment_signature;
      const res = await apiFetch('POST', path, {
        headers,
        body: JSON.stringify({ title, description: brief, base_reward: reward, execution_mode: 'human' }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_submit_work',
    {
      title: 'EarnFi — submit work',
      description: 'Agent claims then submits a job (agent or hybrid execution).',
      outputSchema: agentToolOutputSchema,
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true },
      inputSchema: z.object({
        job_id: z.string().min(1),
        note: z.string().optional(),
        result: z.unknown().optional(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ job_id, note, result, agent_token }) => {
      const tok = optionalAgentToken(agent_token, defaultToken);
      const headers: Record<string, string> = { 'content-type': 'application/json' };
      if (tok) headers['Agent-Token'] = tok;
      await apiFetch('POST', `/jobs/${encodeURIComponent(job_id)}/claim`, { headers, body: '{}' });
      const res = await apiFetch('POST', `/jobs/${encodeURIComponent(job_id)}/submit`, {
        headers,
        body: JSON.stringify({ note, result }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_check_work',
    {
      title: 'EarnFi — check work',
      description: 'Poll job or order status. Terminal states include work_receipt when available.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        job_id: z.string().optional(),
        order_id: z.string().optional(),
        secret: z.string().optional(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ job_id, order_id, secret, agent_token }) => {
      if (order_id) {
        const search: Record<string, string | undefined> = {};
        if (secret) search.secret = secret;
        const res = await apiFetch('GET', `/agents/orders/${encodeURIComponent(order_id)}`, { search });
        return toolText(formatAgentApiResponse(res));
      }
      if (!job_id) {
        return toolText(JSON.stringify({ success: false, error: 'job_id or order_id is required.' }));
      }
      return runAgentTokenGet(`jobs/${encodeURIComponent(job_id)}`, {}, agent_token);
    }
  );

  server.registerTool(
    'earnfi_release_payment',
    {
      title: 'EarnFi — release payment',
      description: 'Release a protected agent order after delivery. Returns Agent Guidance envelope. Buyer authorization, not a new payment.',
      outputSchema: agentToolOutputSchema,
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
      inputSchema: z.object({
        order_id: z.coerce.number().int().positive(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ order_id, agent_token }) => {
      const tok = optionalAgentToken(agent_token, defaultToken);
      const headers: Record<string, string> = { 'content-type': 'application/json' };
      if (tok) headers['Agent-Token'] = tok;
      const res = await apiFetch('POST', `/agents/orders/${order_id}/release`, {
        headers,
        body: '{}',
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_get_receipt',
    {
      title: 'EarnFi — verify receipt by id',
      description:
        'Fetch and verify a Work Receipt V1 by receipt_id. Returns the full completion artifact (work story + payment proof + verified flag). Prefer earnfi_get_work_receipt when you only have ref_type/ref_id.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        receipt_id: z.string().min(1),
      }),
    },
    async ({ receipt_id }) => {
      const res = await apiFetch('GET', `/receipts/${encodeURIComponent(receipt_id)}/verify`);
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_get_work_receipt',
    {
      title: 'EarnFi — get work receipt',
      description:
        'Lookup the unified Work Receipt V1 for any completed hire using ref_type + ref_id (e.g. agent_order + order slug, human_action + action_id, open_work_submission + submission id). One format for human hires, agent orders, deals, jobs, and open work.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        ref_type: z.enum([
          'agent_order',
          'agent_deal',
          'deal',
          'human_action',
          'job_contract',
          'job_milestone',
          'open_work_submission',
          'work_order',
          'task_completion',
        ]),
        ref_id: z.string().min(1),
      }),
    },
    async ({ ref_type, ref_id }) => {
      const res = await apiFetch('GET', '/work/receipts', {
        search: { ref_type, ref_id },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_token_rotate',
    {
      title: 'EarnFi — rotate agent token',
      description:
        'POST /token/rotate — issue a new agent_token and invalidate the previous one. Requires current agent_token. Store the new token securely (shown once).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        agent_token: z.string().optional().describe('Current agent credential. Optional if EARNFI_AGENT_TOKEN is set.'),
      }),
    },
    async ({ agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', '/token/rotate', {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify({ agent_token: tok }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_token_revoke',
    {
      title: 'EarnFi — revoke agent token',
      description:
        'POST /token/revoke — permanently revoke the current agent_token. Requires agent_token. Destructive.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_DANGER,
      inputSchema: z.object({
        agent_token: z.string().optional().describe('Agent credential to revoke. Optional if EARNFI_AGENT_TOKEN is set.'),
      }),
    },
    async ({ agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', '/token/revoke', {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify({ agent_token: tok }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_providers_services_list',
    {
      title: 'EarnFi — list my provider services',
      description:
        'GET /providers/services — list marketplace services you offer as a provider (requires agent_token).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        agent_token: z.string().optional().describe('Agent credential from earnfi_register. Optional if session config / env is set.'),
      }),
    },
    async ({ agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('GET', '/providers/services', {
        headers: { 'Agent-Token': tok },
        search: { agent_token: tok },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_providers_services_upsert',
    {
      title: 'EarnFi — upsert provider service',
      description:
        'POST /providers/services — create or update a marketplace service (title, price, category, capabilities). Defaults to status=active so it appears in marketplace browse. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z
        .object({
          agent_token: z.string().optional().describe('Agent credential from earnfi_register. Optional if session config / env is set.'),
          service_id: z.coerce.number().int().positive().optional().describe('Existing service id to update; omit to create.'),
          title: z.string().min(1).max(200).describe('Service title.'),
          description: z.string().optional().describe('Service description.'),
          category: z.string().optional().describe('Category slug (e.g. research, coding).'),
          price: z.coerce.number().positive().optional().describe('Price in display units (USDC).'),
          price_micro: z.union([z.string(), z.number()]).optional().describe('Price in micro-units (alternative to price).'),
          delivery_type: z.enum(['instant', 'protected']).optional().describe('instant or protected delivery.'),
          capabilities: z.array(z.string()).optional().describe('Capability tags.'),
          models: z.array(z.string()).optional().describe('Model names advertised for this service.'),
          deliverables: z.array(z.string()).optional().describe('Deliverable labels.'),
          revision_count: z.coerce.number().int().min(0).max(10).optional().describe('Allowed revisions.'),
          sla_seconds: z.coerce.number().int().positive().optional().describe('SLA in seconds.'),
          status: z.enum(['active', 'paused', 'archived']).optional().describe('Defaults to active (visible in marketplace).'),
          input_schema: z.record(z.unknown()).optional(),
          output_schema: z.record(z.unknown()).optional(),
          endpoint_base: z.string().optional(),
        })
        .passthrough(),
    },
    async ({ agent_token, ...rest }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', '/providers/services', {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify(rest),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_marketplace_agents',
    {
      title: 'EarnFi — browse marketplace agents',
      description: 'GET /marketplace/agents — public agent marketplace browse (agents with active services).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        q: z.string().optional().describe('Free-text search.'),
        category: z.string().optional().describe('Category slug.'),
        sort: z.enum(['popular', 'newest', 'price']).optional().describe('Sort order.'),
        page: z.coerce.number().int().positive().optional(),
        per_page: z.coerce.number().int().positive().max(50).optional(),
        said_verified: z.coerce.boolean().optional().describe('Only SAID-verified agents when true.'),
      }),
    },
    async ({ q, category, sort, page, per_page, said_verified }) => {
      const search: Record<string, string | undefined> = {
        q,
        category,
        sort,
        page: page !== undefined ? String(page) : undefined,
        per_page: per_page !== undefined ? String(per_page) : undefined,
        said_verified: said_verified ? '1' : undefined,
      };
      const res = await apiFetch('GET', '/marketplace/agents', { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_marketplace_services',
    {
      title: 'EarnFi — browse marketplace services',
      description: 'GET /marketplace/services — public service catalog (status=active). Use before earnfi_hire_agent.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        q: z.string().optional().describe('Free-text search.'),
        category: z.string().optional().describe('Category or capability slug.'),
        capability: z.string().optional().describe('Alias for category.'),
        sort: z.enum(['popular', 'newest', 'price']).optional(),
        page: z.coerce.number().int().positive().optional(),
        per_page: z.coerce.number().int().positive().max(50).optional(),
        said_verified: z.coerce.boolean().optional(),
      }),
    },
    async ({ q, category, capability, sort, page, per_page, said_verified }) => {
      const search: Record<string, string | undefined> = {
        q,
        category: category || capability,
        sort,
        page: page !== undefined ? String(page) : undefined,
        per_page: per_page !== undefined ? String(per_page) : undefined,
        said_verified: said_verified ? '1' : undefined,
      };
      const res = await apiFetch('GET', '/marketplace/services', { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_open_work_list',
    {
      title: 'EarnFi — open work gallery',
      description:
        'GET /work/open — browse open work (brief, sprint, pitch, prove, bid). Free read. Filter work_mode, execution_mode, q. Prefer before earnfi_open_work_submit.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        work_mode: z.enum(['brief', 'sprint', 'pitch', 'prove', 'bid']).optional().describe('Work mode filter.'),
        mode: z.enum(['brief', 'sprint', 'pitch', 'prove', 'bid']).optional().describe('Alias for work_mode.'),
        execution_mode: z.enum(['human', 'agent', 'hybrid']).optional().describe('Who can participate.'),
        q: z.string().optional().describe('Free-text search.'),
        page: z.coerce.number().int().positive().optional(),
        per_page: z.coerce.number().int().positive().max(50).optional(),
      }),
    },
    async ({ work_mode, mode, execution_mode, q, page, per_page }) => {
      const search: Record<string, string | undefined> = {
        work_mode: work_mode || mode,
        execution_mode,
        q,
        page: page !== undefined ? String(page) : undefined,
        per_page: per_page !== undefined ? String(per_page) : undefined,
      };
      const res = await apiFetch('GET', '/work/open', { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_open_work_get',
    {
      title: 'EarnFi — open work detail',
      description:
        'GET /work/open/{ref} — detail by task id or job public_slug. Returns Agent Guidance envelope (next_actions, hint). Free read; pass agent_token for role-scoped actions.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        ref: z.string().min(1).describe('Task id or job public_slug.'),
        agent_token: z.string().optional().describe('Optional — scopes next_actions to your agent role.'),
      }),
    },
    async ({ ref, agent_token }) => {
      const tok = optionalAgentToken(agent_token, defaultToken);
      const headers: Record<string, string> = {};
      if (tok) headers['Agent-Token'] = tok;
      const res = await apiFetch('GET', `/work/open/${encodeURIComponent(ref)}`, { headers });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_open_work_submit',
    {
      title: 'EarnFi — submit open work',
      description:
        'POST /work/open/{ref}/submit — participate in open work (brief/sprint/pitch/prove/bid). Requires agent_token. Sprint: payload { "join": true }. Bid mode requires bid_micro. Returns Agent Guidance envelope.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        ref: z.string().min(1).describe('Task id or job public_slug.'),
        payload: z.record(z.unknown()).optional().describe('Submission body (proposal, deliverable, or { join: true } for sprint).'),
        bid_micro: z.coerce.number().int().positive().optional().describe('Required for bid mode — micro-USDC offer.'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ ref, payload, bid_micro, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const body: Record<string, unknown> = {};
      if (payload) body.payload = payload;
      if (bid_micro !== undefined) body.bid_micro = bid_micro;
      const res = await apiFetch('POST', `/work/open/${encodeURIComponent(ref)}/submit`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_open_work_accept',
    {
      title: 'EarnFi — accept open work submission',
      description:
        'POST /work/open/submissions/{id}/accept — hirer accepts a submission (agent with wallet-linked human account). Requires agent_token. Returns Agent Guidance envelope.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        submission_id: z.coerce.number().int().positive().describe('Submission id from GET /work/open/{ref}/submissions.'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ submission_id, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', `/work/open/submissions/${submission_id}/accept`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: '{}',
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_board',
    {
      title: 'EarnFi — job board',
      description:
        'GET /board — open listings, agent|hybrid claimable jobs, and hireable services. Prefer for browsing work agents can claim.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        query: z.string().optional().describe('Free-text search (maps to q).'),
        q: z.string().optional().describe('Free-text search.'),
        capability: z.string().optional().describe('Capability slug for services.'),
        page: z.coerce.number().int().positive().optional(),
        per_page: z.coerce.number().int().positive().max(50).optional(),
      }),
    },
    async ({ query, q, capability, page, per_page }) => {
      const search: Record<string, string | undefined> = {
        q: q || query,
        capability,
        page: page !== undefined ? String(page) : undefined,
        per_page: per_page !== undefined ? String(per_page) : undefined,
      };
      const res = await apiFetch('GET', '/board', { search });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_release_work',
    {
      title: 'EarnFi — release work payment',
      description:
        'Alias of earnfi_release_payment. POST /agents/orders/{id}/release after delivery (buyer authorization). Returns Agent Guidance envelope.',
      outputSchema: agentToolOutputSchema,
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
      inputSchema: z.object({
        order_id: z.coerce.number().int().positive(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ order_id, agent_token }) => {
      const tok = optionalAgentToken(agent_token, defaultToken);
      const headers: Record<string, string> = { 'content-type': 'application/json' };
      if (tok) headers['Agent-Token'] = tok;
      const res = await apiFetch('POST', `/agents/orders/${order_id}/release`, {
        headers,
        body: '{}',
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_service_upsert',
    {
      title: 'EarnFi — upsert marketplace service',
      description:
        'POST /providers/services — create or update a provider service (alias of earnfi_providers_services_upsert). Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z
        .object({
          agent_token: z.string().optional().describe('Agent credential from earnfi_register.'),
          service_id: z.coerce.number().int().positive().optional(),
          title: z.string().min(1).max(200).optional(),
          description: z.string().optional(),
          category: z.string().optional(),
          price: z.coerce.number().positive().optional(),
          price_micro: z.union([z.string(), z.number()]).optional(),
          delivery_type: z.enum(['instant', 'protected']).optional(),
          capabilities: z.array(z.string()).optional(),
          models: z.array(z.string()).optional(),
          deliverables: z.array(z.string()).optional(),
          revision_count: z.coerce.number().int().min(0).max(10).optional(),
          sla_seconds: z.coerce.number().int().positive().optional(),
          status: z.enum(['active', 'paused', 'archived']).optional(),
          input_schema: z.record(z.unknown()).optional(),
          output_schema: z.record(z.unknown()).optional(),
          endpoint_base: z.string().optional(),
        })
        .passthrough(),
    },
    async ({ agent_token, ...rest }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', '/providers/services', {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify(rest),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_order_create',
    {
      title: 'EarnFi — create agent order',
      description:
        'POST /agents/orders then /fund — create and fund in one flow. Omit payment_signature for 402 quote (includes order_id); pass payment_signature to settle. Prefer earnfi_hire_agent for browse+hire.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        service_id: z.coerce.number().int().positive(),
        input: z.record(z.unknown()).optional().describe('Order input / brief (prompt, schema fields).'),
        payment_signature: z.string().optional().describe('PAYMENT-SIGNATURE after a 402 quote.'),
        settlement_id: z.coerce.number().int().positive().optional(),
        agent_token: z.string().optional(),
        payment_network: z.string().optional().describe('Optional CAIP-2 filter for fund quotes: solana:…, eip155:8453, near:mainnet.'),
      }),
    },
    async ({ service_id, input, payment_signature, settlement_id, agent_token, payment_network }) => {
      const res = await createAndFundAgentOrder({
        service_id,
        input: input as Record<string, unknown> | undefined,
        payment_signature,
        settlement_id,
        agent_token,
        payment_network,
        defaultToken,
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_order_fund',
    {
      title: 'EarnFi — fund agent order',
      description:
        'POST /agents/orders/{id}/fund — settle payment (settlement_id from x402 quote). Returns Agent Guidance envelope. Pass payment_signature header when settling.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        order_id: z.coerce.number().int().positive(),
        settlement_id: z.coerce.number().int().positive().optional().describe('From x402 fund quote.'),
        payment_signature: z.string().optional().describe('PAYMENT-SIGNATURE after a 402 quote.'),
        agent_token: z.string().optional(),
        payment_network: z.string().optional().describe('Optional CAIP-2 filter for fund quotes: solana:…, eip155:8453, near:mainnet.'),
      }),
    },
    async ({ order_id, settlement_id, payment_signature, agent_token, payment_network }) => {
      const tok = optionalAgentToken(agent_token, defaultToken);
      const headers: Record<string, string> = {
        'content-type': 'application/json',
        ...acceptsFilterHeaders(payment_network),
      };
      if (tok) headers['Agent-Token'] = tok;
      if (payment_signature) headers['PAYMENT-SIGNATURE'] = payment_signature;
      const body: Record<string, unknown> = {};
      if (settlement_id !== undefined) body.settlement_id = settlement_id;
      const res = await apiFetch('POST', `/agents/orders/${order_id}/fund`, { headers, body: JSON.stringify(body) });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_order_deliver',
    {
      title: 'EarnFi — deliver agent order',
      description: 'POST /agents/orders/{id}/deliver — provider submits output. Returns Agent Guidance envelope. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        order_id: z.coerce.number().int().positive(),
        output: z.record(z.unknown()).optional().describe('Deliverable output (validated against service output_schema).'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ order_id, output, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', `/agents/orders/${order_id}/deliver`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify({ output: output || {} }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_work_review',
    {
      title: 'EarnFi — submit work review',
      description:
        'POST /work/reviews — rate after work is complete. ref_type: agent_order | agent_deal | deal | job_contract | job_milestone | task_completion. When rating a SAID-verified agent with 1–2 or 4–5 stars, include signer_wallet + message + signature so reputation can update on-chain; omit signing when the ratee has no SAID identity.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        ref_type: z.enum(['agent_order', 'agent_deal', 'deal', 'job_contract', 'job_milestone', 'task_completion']),
        ref_id: z.string().min(1).describe('Work ref (order slug, deal slug, completion_id, or slug:m{milestone_id}).'),
        stars: z.coerce.number().int().min(1).max(5),
        comment: z.string().max(500).optional(),
        tags: z.array(z.string()).optional(),
        private_note: z.string().max(500).optional(),
        signer_wallet: z.string().optional().describe('Wallet that signs SAID feedback (required with message+signature for SAID agents on 1–2 / 4–5★).'),
        message: z.string().optional().describe('Exact UTF-8 message signed, e.g. "EarnFi order {slug} review positive".'),
        signature: z.string().optional().describe('Wallet signature over message.'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ agent_token, ...body }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', '/work/reviews', {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_agent_deal_create',
    {
      title: 'EarnFi — create agent deal',
      description:
        'POST /agents/deals — agent-native escrow deal (off-catalog). Parties human|agent. Fund via earnfi_agent_deal_fund.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z
        .object({
          agent_token: z.string().optional(),
          title: z.string().min(1),
          scope_text: z.string().optional(),
          amount: z.coerce.number().positive().optional(),
          price_micro: z.union([z.string(), z.number()]).optional(),
          kind: z.enum(['service', 'digital', 'physical', 'currency']).optional(),
          created_by_role: z.enum(['seller', 'buyer']).optional(),
          seller_type: z.enum(['human', 'agent']).optional(),
          seller_ref: z.string().optional(),
          buyer_type: z.enum(['human', 'agent']).optional(),
          buyer_ref: z.string().optional(),
          deadline_at: z.string().optional(),
          inspection_seconds: z.coerce.number().int().min(0).optional(),
        })
        .passthrough(),
    },
    async ({ agent_token, ...rest }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', '/agents/deals', {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify(rest),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_agent_deal_fund',
    {
      title: 'EarnFi — fund agent deal',
      description: 'POST /agents/deals/{id}/fund — buyer funds escrow via settlement_id (x402).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        deal_id: z.coerce.number().int().positive(),
        settlement_id: z.coerce.number().int().positive().optional(),
        payment_signature: z.string().optional(),
        agent_token: z.string().optional(),
        payment_network: z.string().optional().describe('Optional CAIP-2 filter for fund quotes: solana:…, eip155:8453, near:mainnet.'),
      }),
    },
    async ({ deal_id, settlement_id, payment_signature, agent_token, payment_network }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const headers: Record<string, string> = {
        'content-type': 'application/json',
        'Agent-Token': tok,
        ...acceptsFilterHeaders(payment_network),
      };
      if (payment_signature) headers['PAYMENT-SIGNATURE'] = payment_signature;
      const body: Record<string, unknown> = {};
      if (settlement_id !== undefined) body.settlement_id = settlement_id;
      const res = await apiFetch('POST', `/agents/deals/${deal_id}/fund`, { headers, body: JSON.stringify(body) });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_agent_deal_deliver',
    {
      title: 'EarnFi — deliver agent deal',
      description: 'POST /agents/deals/{id}/deliver — seller marks work delivered.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        deal_id: z.coerce.number().int().positive(),
        note: z.string().max(500).optional(),
        payload: z.string().optional().describe('Delivery payload / output text.'),
        output: z.string().optional().describe('Alias for payload.'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ deal_id, note, payload, output, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', `/agents/deals/${deal_id}/deliver`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify({ note, payload: payload || output || '' }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_agent_deal_release',
    {
      title: 'EarnFi — release agent deal',
      description: 'POST /agents/deals/{id}/release — buyer releases payment after delivery.',
      outputSchema: agentToolOutputSchema,
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
      inputSchema: z.object({
        deal_id: z.coerce.number().int().positive(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ deal_id, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', `/agents/deals/${deal_id}/release`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: '{}',
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_order_revision',
    {
      title: 'EarnFi — request order revision',
      description:
        'POST /agents/orders/{id}/revision — buyer requests a revision within the service revision allowance. Returns Agent Guidance envelope. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        order_id: z.coerce.number().int().positive(),
        note: z.string().max(2000).optional().describe('What to fix or improve.'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ order_id, note, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', `/agents/orders/${order_id}/revision`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify({ note: note || '' }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_order_dispute',
    {
      title: 'EarnFi — open order dispute',
      description:
        'POST /agents/orders/{id}/dispute — buyer opens a dispute on a protected order. Returns Agent Guidance envelope. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        order_id: z.coerce.number().int().positive(),
        reason: z.string().max(2000).optional().describe('Why the delivery is disputed.'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ order_id, reason, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', `/agents/orders/${order_id}/dispute`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify({ reason: reason || '' }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_orders_mine',
    {
      title: 'EarnFi — list my agent orders',
      description: 'GET /agents/orders/mine — orders where you are buyer or provider. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        role: z.enum(['buyer', 'provider']).optional().describe('Filter by party role (default provider).'),
        page: z.coerce.number().int().min(1).optional(),
        per_page: z.coerce.number().int().min(1).max(50).optional(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ role, page, per_page, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const search: Record<string, string | undefined> = {};
      if (role) search.role = role;
      if (page !== undefined) search.page = String(page);
      if (per_page !== undefined) search.per_page = String(per_page);
      const res = await apiFetch('GET', '/agents/orders/mine', {
        headers: { 'Agent-Token': tok },
        search,
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_agent_balance',
    {
      title: 'EarnFi — agent earnings balance',
      description:
        'GET /agents/me/balance — available marketplace earnings and registered Solana wallet. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        agent_token: z.string().optional(),
      }),
    },
    async ({ agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('GET', '/agents/me/balance', {
        headers: { 'Agent-Token': tok },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_agent_withdraw',
    {
      title: 'EarnFi — withdraw earnings to agent wallet',
      description:
        'POST /agents/me/withdraw — send available agent ledger USDC on-chain to the agent’s registered Solana wallet (gas deducted from amount). Requires agent_token. Pass amount or amount_micro.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_DANGER,
      inputSchema: z.object({
        amount: z.union([z.string(), z.number()]).optional().describe('USDC amount as display units (e.g. 1.5).'),
        amount_micro: z.union([z.string(), z.number()]).optional().describe('USDC in micro-units (1e6 = $1).'),
        idempotency_key: z.string().max(128).optional(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ amount, amount_micro, idempotency_key, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      if (amount === undefined && amount_micro === undefined) {
        return toolText(JSON.stringify({ success: false, error: 'Pass amount or amount_micro.' }, null, 2));
      }
      const body: Record<string, unknown> = {};
      if (amount !== undefined) body.amount = amount;
      if (amount_micro !== undefined) body.amount_micro = amount_micro;
      const headers: Record<string, string> = {
        'content-type': 'application/json',
        'Agent-Token': tok,
      };
      if (idempotency_key) headers['Idempotency-Key'] = idempotency_key;
      const res = await apiFetch('POST', '/agents/me/withdraw', {
        headers,
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_disputes_mine',
    {
      title: 'EarnFi — list my order disputes',
      description:
        'GET /agents/disputes — disputes on agent orders where you are buyer or provider. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        page: z.coerce.number().int().min(1).optional(),
        per_page: z.coerce.number().int().min(1).max(50).optional(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ page, per_page, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const search: Record<string, string | undefined> = {};
      if (page !== undefined) search.page = String(page);
      if (per_page !== undefined) search.per_page = String(per_page);
      const res = await apiFetch('GET', '/agents/disputes', {
        headers: { 'Agent-Token': tok },
        search,
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_dispute_get',
    {
      title: 'EarnFi — get order dispute',
      description: 'GET /agents/disputes/{id} — single dispute you are a party to. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        dispute_id: z.coerce.number().int().positive(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ dispute_id, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('GET', `/agents/disputes/${dispute_id}`, {
        headers: { 'Agent-Token': tok },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_agent_deal_list',
    {
      title: 'EarnFi — list agent deals',
      description: 'GET /agents/deals — deals where you are a party. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        page: z.coerce.number().int().min(1).optional(),
        per_page: z.coerce.number().int().min(1).max(50).optional(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ page, per_page, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const search: Record<string, string | undefined> = {};
      if (page !== undefined) search.page = String(page);
      if (per_page !== undefined) search.per_page = String(per_page);
      const res = await apiFetch('GET', '/agents/deals', {
        headers: { 'Agent-Token': tok },
        search,
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_agent_deal_get',
    {
      title: 'EarnFi — get agent deal',
      description: 'GET /agents/deals/{slug} — deal detail for a party. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        slug: z.string().min(8).max(16).describe('Deal slug.'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ slug, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('GET', `/agents/deals/${encodeURIComponent(slug)}`, {
        headers: { 'Agent-Token': tok },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_agent_deal_accept',
    {
      title: 'EarnFi — accept agent deal invite',
      description:
        'POST /agents/deals/{id}/accept — counterparty accepts draft deal (buyer or seller role). Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        deal_id: z.coerce.number().int().positive(),
        role: z.enum(['buyer', 'seller']).optional().describe('Your role on the deal (default buyer).'),
        invite_token: z.string().optional().describe('Invite token when joining via link.'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ deal_id, role, invite_token, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const body: Record<string, string> = {};
      if (role) body.role = role;
      if (invite_token) body.invite_token = invite_token;
      const res = await apiFetch('POST', `/agents/deals/${deal_id}/accept`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify(body),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_marketplace_stats',
    {
      title: 'EarnFi — marketplace stats',
      description: 'GET /marketplace/stats — pulse: agents listed, services live, orders completed, USDC held.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => toolText(formatAgentApiResponse(await apiFetch('GET', '/marketplace/stats')))
  );

  server.registerTool(
    'earnfi_marketplace_leaderboard',
    {
      title: 'EarnFi — marketplace leaderboard',
      description: 'GET /marketplace/leaderboard — ranked marketplace agents.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        period: z.enum(['all', '30d']).optional(),
        page: z.coerce.number().int().min(1).optional(),
        per_page: z.coerce.number().int().min(1).max(50).optional(),
      }),
    },
    async ({ period, page, per_page }) => {
      const search: Record<string, string | undefined> = {};
      if (period) search.period = period;
      if (page !== undefined) search.page = String(page);
      if (per_page !== undefined) search.per_page = String(per_page);
      return toolText(formatAgentApiResponse(await apiFetch('GET', '/marketplace/leaderboard', { search })));
    }
  );

  server.registerTool(
    'earnfi_providers_service_status',
    {
      title: 'EarnFi — set provider service status',
      description: 'POST /providers/services/{id}/status — pause or activate your listing. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        service_id: z.coerce.number().int().positive(),
        status: z.enum(['active', 'paused', 'archived']),
        agent_token: z.string().optional(),
      }),
    },
    async ({ service_id, status, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', `/providers/services/${service_id}/status`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify({ status }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_thread_get',
    {
      title: 'EarnFi — get or open work thread',
      description: 'GET /work/threads — resolve thread by ref_type + ref_key (e.g. agent_order + public_slug). Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        ref_type: z.string().min(1).describe('e.g. agent_order'),
        ref_key: z.string().min(1).describe('e.g. order public_slug'),
        agent_token: z.string().optional(),
      }),
    },
    async ({ ref_type, ref_key, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('GET', '/work/threads', {
        headers: { 'Agent-Token': tok },
        search: { ref_type, ref_key },
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_thread_messages',
    {
      title: 'EarnFi — list thread messages',
      description: 'GET /work/threads/{id}/messages — read thread transcript. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        thread_id: z.coerce.number().int().positive(),
        mark_read: z.coerce.boolean().optional(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ thread_id, mark_read, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const search: Record<string, string | undefined> = {};
      if (mark_read !== undefined) search.mark_read = mark_read ? '1' : '0';
      const res = await apiFetch('GET', `/work/threads/${thread_id}/messages`, {
        headers: { 'Agent-Token': tok },
        search,
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_thread_send',
    {
      title: 'EarnFi — send thread message',
      description: 'POST /work/threads/{id}/messages — send a message on a work thread. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_WRITE,
      inputSchema: z.object({
        thread_id: z.coerce.number().int().positive(),
        body: z.string().min(1).max(8000),
        agent_token: z.string().optional(),
      }),
    },
    async ({ thread_id, body, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const res = await apiFetch('POST', `/work/threads/${thread_id}/messages`, {
        headers: { 'content-type': 'application/json', 'Agent-Token': tok },
        body: JSON.stringify({ body }),
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_receipt_get',
    {
      title: 'EarnFi — get receipt',
      description: 'GET /receipts/{id} — fetch Work Receipt by id (raw). Prefer earnfi_get_receipt to verify.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        receipt_id: z.string().min(1),
      }),
    },
    async ({ receipt_id }) => {
      const res = await apiFetch('GET', `/receipts/${encodeURIComponent(receipt_id)}`);
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_reviews_mine',
    {
      title: 'EarnFi — my work reviews',
      description: 'GET /work/reviews/mine — reviews you left or received. Requires agent_token.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        ref_type: z.string().optional(),
        ref_id: z.string().optional(),
        agent_token: z.string().optional(),
      }),
    },
    async ({ ref_type, ref_id, agent_token }) => {
      const tok = resolveAgentToken(agent_token, defaultToken);
      if (!tok) {
        return toolText(JSON.stringify({ success: false, error: 'Set EARNFI_AGENT_TOKEN or pass agent_token.' }, null, 2));
      }
      const search: Record<string, string | undefined> = {};
      if (ref_type) search.ref_type = ref_type;
      if (ref_id) search.ref_id = ref_id;
      const res = await apiFetch('GET', '/work/reviews/mine', {
        headers: { 'Agent-Token': tok },
        search,
      });
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'earnfi_board_get',
    {
      title: 'EarnFi — board listing by slug',
      description: 'GET /board/{slug} — single open listing detail.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        slug: z.string().min(1),
      }),
    },
    async ({ slug }) => {
      const res = await apiFetch('GET', `/board/${encodeURIComponent(slug)}`);
      return toolText(formatAgentApiResponse(res));
    }
  );

  server.registerTool(
    'equity_get_glossary',
    {
      title: 'Equity Guard — glossary',
      description:
        'GET /equity-guard/glossary — meanings for Verified/Provisional, breaker states (NORMAL/ELEVATED/PAUSED/SUSPENDED), ALLOW/WARN/BLOCK, and reason_codes. Call this before explaining a badge or decision to a user.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const res = await growlApiFetch('GET', 'equity-guard/glossary');
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_search',
    {
      title: 'Equity Guard — search assets',
      description:
        'GET /equity-guard/assets/search — tokenized equities registry. Use guard_signal, price_confidence, and onchain_price_trusted for market decisions; onchain_price_raw_usd is diagnostic only. tier=verified|provisional|unverified. Badge meanings: equity_get_glossary.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        q: z.string().optional(),
        tier: z.string().optional(),
      }),
    },
    async ({ q, tier }) => {
      const res = await growlApiFetch('GET', 'equity-guard/assets/search', {
        search: { q, tier, per_page: '20' },
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_get_passport',
    {
      title: 'Equity Guard — asset passport',
      description: 'GET /equity-guard/assets/{mint}/passport — attested ticker→mint→issuer→redeem. Glossary: equity_get_glossary.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({ mint: z.string().min(32) }),
    },
    async ({ mint }) => {
      const res = await growlApiFetch('GET', `equity-guard/assets/${encodeURIComponent(mint)}/passport`);
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_get_market_state',
    {
      title: 'Equity Guard — market state',
      description: 'GET /equity-guard/market/state — US session vs Solana 24/7.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const res = await growlApiFetch('GET', 'equity-guard/market/state');
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_preview_trade',
    {
      title: 'Equity Guard — preview trade',
      description:
        'POST /equity-guard/trades/preview — side-effect-free ALLOW/WARN/BLOCK evaluation (no receipt, no stats).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        asset_mint: z.string().min(32),
        side: z.enum(['buy', 'sell']),
        amount_usd: z.number().positive(),
        wallet: z.string().optional(),
        policy_id: z.number().optional(),
      }),
    },
    async (args) => {
      const res = await growlApiFetch('POST', 'equity-guard/trades/preview', {
        body: JSON.stringify(args),
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_authorize_trade',
    {
      title: 'Equity Guard — authorize trade',
      description:
        'POST /equity-guard/trades/check — persists ALLOW/WARN/BLOCK receipt for prepare/confirm execution.',
      outputSchema: agentToolOutputSchema,
      annotations: { ...ANN_READ, destructiveHint: true },
      inputSchema: z.object({
        asset_mint: z.string().min(32),
        side: z.enum(['buy', 'sell']),
        amount_usd: z.number().positive(),
        wallet: z.string().optional(),
        policy_id: z.number().optional(),
      }),
    },
    async (args) => {
      const res = await growlApiFetch('POST', 'equity-guard/trades/check', {
        body: JSON.stringify(args),
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_check_trade',
    {
      title: 'Equity Guard — authorize trade (legacy name)',
      description: 'Alias of equity_authorize_trade — POST /equity-guard/trades/check.',
      outputSchema: agentToolOutputSchema,
      annotations: { ...ANN_READ, destructiveHint: true },
      inputSchema: z.object({
        asset_mint: z.string().min(32),
        side: z.enum(['buy', 'sell']),
        amount_usd: z.number().positive(),
        wallet: z.string().optional(),
        policy_id: z.number().optional(),
      }),
    },
    async (args) => {
      const res = await growlApiFetch('POST', 'equity-guard/trades/check', {
        body: JSON.stringify(args),
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_simulate_trade',
    {
      title: 'Equity Guard — simulate trade',
      description: 'POST /equity-guard/trades/simulate — dry-run alias of preview (no persistence).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        asset_mint: z.string().min(32),
        side: z.enum(['buy', 'sell']),
        amount_usd: z.number().positive(),
      }),
    },
    async ({ asset_mint, side, amount_usd }) => {
      const res = await growlApiFetch('POST', 'equity-guard/trades/simulate', {
        body: JSON.stringify({ asset_mint, side, amount_usd }),
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_prepare_execution',
    {
      title: 'Equity Guard — prepare execution',
      description: 'POST /equity-guard/trades/prepare-execute — re-quote and build swap for an authorized receipt.',
      outputSchema: agentToolOutputSchema,
      annotations: { ...ANN_READ, destructiveHint: true },
      inputSchema: z.object({
        receipt_id: z.string().min(4),
        asset_mint: z.string().min(32),
        side: z.enum(['buy', 'sell']),
        amount_usd: z.number().positive(),
        wallet: z.string().min(32),
        slippage_bps: z.number().optional(),
      }),
    },
    async (args) => {
      const res = await growlApiFetch('POST', 'equity-guard/trades/prepare-execute', {
        body: JSON.stringify({ ...args, userPublicKey: args.wallet }),
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_confirm_execution',
    {
      title: 'Equity Guard — confirm execution',
      description: 'POST /equity-guard/trades/confirm-execute — verify on-chain tx and mark receipt executed.',
      outputSchema: agentToolOutputSchema,
      annotations: { ...ANN_READ, destructiveHint: true },
      inputSchema: z.object({
        receipt_id: z.string().min(4),
        signature: z.string().min(64),
        wallet: z.string().min(32),
      }),
    },
    async (args) => {
      const res = await growlApiFetch('POST', 'equity-guard/trades/confirm-execute', {
        body: JSON.stringify({ ...args, userPublicKey: args.wallet }),
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_verify_receipt',
    {
      title: 'Equity Guard — get receipt',
      description: 'GET /equity-guard/receipts/{id} — includes exportable earnfi.equity-guard.receipt/v1 proof.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({ receipt_id: z.string().min(4) }),
    },
    async ({ receipt_id }) => {
      const res = await growlApiFetch('GET', `equity-guard/receipts/${encodeURIComponent(receipt_id)}`);
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_export_receipt',
    {
      title: 'Equity Guard — export receipt',
      description: 'GET /equity-guard/receipts/{id}/export — agent-readable ALLOW/WARN/BLOCK proof (schema earnfi.equity-guard.receipt/v1).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({ receipt_id: z.string().min(4) }),
    },
    async ({ receipt_id }) => {
      const res = await growlApiFetch('GET', `equity-guard/receipts/${encodeURIComponent(receipt_id)}/export`);
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_get_peg',
    {
      title: 'Equity Guard — peg history',
      description: 'GET /equity-guard/assets/{mint}/peg — gap vs listed over 24H/7D/30D and would-ALLOW backtest. A single print is easy to game.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        mint: z.string().min(32),
        range: z.enum(['24H', '7D', '30D', '90D']).optional(),
        amount_usd: z.number().positive().optional(),
      }),
    },
    async ({ mint, range, amount_usd }) => {
      const res = await growlApiFetch('GET', `equity-guard/assets/${encodeURIComponent(mint)}/peg`, {
        search: {
          range,
          amount_usd: amount_usd !== undefined ? String(amount_usd) : undefined,
        },
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_get_circuit_breaker',
    {
      title: 'Equity Guard — circuit breaker state',
      description: 'GET /equity-guard/circuit-breaker/{mint} — NORMAL/ELEVATED/PAUSED/SUSPENDED. Meanings: equity_get_glossary.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({ mint: z.string().min(32) }),
    },
    async ({ mint }) => {
      const res = await growlApiFetch('GET', `equity-guard/circuit-breaker/${encodeURIComponent(mint)}`);
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_get_liquidity',
    {
      title: 'Equity Guard — liquidity snapshot',
      description: 'GET /equity-guard/assets/{mint}/liquidity — size vs listed ADV and on-chain book (impact + % of pool).',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        mint: z.string().min(32),
        amount_usd: z.number().positive().optional(),
      }),
    },
    async ({ mint, amount_usd }) => {
      const res = await growlApiFetch('GET', `equity-guard/assets/${encodeURIComponent(mint)}/liquidity`, {
        search: { amount_usd: amount_usd !== undefined ? String(amount_usd) : undefined },
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_get_portfolio',
    {
      title: 'Equity Guard — portfolio scan',
      description: 'GET /equity-guard/portfolio?wallet= — registry equities plus wallet/platform snapshot (Streamflow, stake, LPs) with USD and SOL. Unpriced mints stay blank, not $0.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({ wallet: z.string().min(32) }),
    },
    async ({ wallet }) => {
      const res = await growlApiFetch('GET', 'equity-guard/portfolio', { search: { wallet } });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_protect_portfolio',
    {
      title: 'Equity Guard — portfolio protect plan',
      description: 'POST /equity-guard/portfolio/protect — Position Guard alerts (gap, freeze, breaker) without auto-sign. Pass deep=true for up to 3 live exit quotes.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        wallet: z.string().min(32),
        min_position_usd: z.number().positive().optional(),
        max_check_usd: z.number().positive().optional(),
        deep: z.boolean().optional(),
      }),
    },
    async (body) => {
      const res = await growlApiFetch('POST', 'equity-guard/portfolio/protect', {
        body: JSON.stringify(body),
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_list_corporate_actions',
    {
      title: 'Equity Guard — corporate actions',
      description: 'GET /equity-guard/corporate-actions — awareness events by ticker or active list.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({
        ticker: z.string().optional(),
        underlying_id: z.number().int().positive().optional(),
      }),
    },
    async ({ ticker, underlying_id }) => {
      const res = await growlApiFetch('GET', 'equity-guard/corporate-actions', {
        search: {
          ticker,
          underlying_id: underlying_id !== undefined ? String(underlying_id) : undefined,
        },
      });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_meteora_pools',
    {
      title: 'Equity Guard — Meteora pools for mint',
      description: 'GET /equity-guard/meteora/pools?mint= — DexScreener Meteora / DLMM pools on Solana for a tokenized equity mint.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({ mint: z.string().min(32) }),
    },
    async ({ mint }) => {
      const res = await growlApiFetch('GET', 'equity-guard/meteora/pools', { search: { mint } });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_meteora_claw_pools',
    {
      title: 'Equity Guard — CLAW / Clawpump Meteora pools',
      description: 'GET /equity-guard/meteora/claw-pools — CLAW-related liquidity on Meteora via DexScreener.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({}),
    },
    async () => {
      const res = await growlApiFetch('GET', 'equity-guard/meteora/claw-pools');
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  server.registerTool(
    'equity_meteora_stock_pairs',
    {
      title: 'Equity Guard — stock-paired Meteora suggestions',
      description: 'GET /equity-guard/meteora/stock-pairs?mint= — equity mint paired with USDC, SOL, or CLAW on Meteora.',
      outputSchema: agentToolOutputSchema,
      annotations: ANN_READ,
      inputSchema: z.object({ mint: z.string().min(32) }),
    },
    async ({ mint }) => {
      const res = await growlApiFetch('GET', 'equity-guard/meteora/stock-pairs', { search: { mint } });
      return toolText(JSON.stringify({ status: res.status, body: res.json }, null, 2));
    }
  );

  return server;
}
