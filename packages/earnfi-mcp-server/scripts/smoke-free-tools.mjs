/**
 * Free EarnFi MCP reads (no agent_token, no x402 payment).
 * Same URL as packages/earnfi-cursor-plugin/.mcp.json
 */
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const MCP_URL = process.env.EARNFI_MCP_URL || 'https://app.earnfi.fun/mcp';

/** Always free — no agent_token, no payment. */
const FREE_TOOLS = [
  'earnfi_agent_catalog',
  'earnfi_marketplace_stats',
  'earnfi_marketplace_agents',
  'earnfi_board',
];

/** Optional aggregate probe (fixed in MCP >= deploy with flat http_status). */
const OPTIONAL_TOOLS = ['earnfi_health'];

const transport = new StreamableHTTPClientTransport(new URL(MCP_URL));
const client = new Client({ name: 'earnfi-free-tools-smoke', version: '1.0.0' });

const results = [];

try {
  await client.connect(transport);
  const { tools } = await client.listTools();
  const names = new Set(tools.map((t) => t.name));

  const allToRun = [...FREE_TOOLS, ...(process.env.SMOKE_INCLUDE_HEALTH === '1' ? OPTIONAL_TOOLS : [])];

  for (const name of allToRun) {
    if (!names.has(name)) {
      results.push({ tool: name, ok: false, error: 'tool not listed' });
      continue;
    }
    try {
      const out = await client.callTool({ name, arguments: {} });
      const text = out.content?.find((c) => c.type === 'text')?.text ?? '';
      const ok = !out.isError && text.length > 0 && !/"success":\s*false/i.test(text);
      results.push({
        tool: name,
        ok,
        preview: text.slice(0, 120).replace(/\s+/g, ' '),
        isError: out.isError === true,
      });
    } catch (e) {
      results.push({ tool: name, ok: false, error: String(e.message || e) });
    }
  }

  const failed = results.filter((r) => !r.ok && FREE_TOOLS.includes(r.tool));
  console.log(
    JSON.stringify(
      {
        mcp_url: MCP_URL,
        tool_count: tools.length,
        results,
        optional_skipped: OPTIONAL_TOOLS.filter((t) => !allToRun.includes(t)),
        hint: 'Set SMOKE_INCLUDE_HEALTH=1 after earnfi_health MCP fix is deployed.',
      },
      null,
      2
    )
  );
  if (failed.length) {
    console.error('FAIL:', failed.map((f) => f.tool).join(', '));
    process.exit(1);
  }
} finally {
  await transport.close?.();
  await client.close?.();
}
