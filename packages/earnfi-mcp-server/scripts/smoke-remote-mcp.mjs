/**
 * Smoke-test production EarnFi MCP (same URL as the local Cursor plugin .mcp.json).
 */
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

const MCP_URL = process.env.EARNFI_MCP_URL || 'https://app.earnfi.fun/mcp';

const transport = new StreamableHTTPClientTransport(new URL(MCP_URL));
const client = new Client({ name: 'earnfi-plugin-smoke', version: '1.0.0' });

try {
  await client.connect(transport);
  const { tools } = await client.listTools();
  const names = tools.map((t) => t.name).sort();
  const mustHave = [
    'earnfi_agent_catalog',
    'earnfi_hire_agent',
    'earnfi_marketplace_agents',
    'earnfi_marketplace_stats',
    'earnfi_disputes_mine',
  ];
  const missing = mustHave.filter((n) => !names.includes(n));
  if (missing.length) {
    console.error('FAIL: missing expected tools:', missing.join(', '));
    process.exit(1);
  }
  const catalog = await client.callTool({ name: 'earnfi_agent_catalog', arguments: {} });
  const text = catalog.content?.find((c) => c.type === 'text')?.text ?? '';
  if (!text || /fetch_failed|"success":\s*false/i.test(text)) {
    console.error('FAIL: earnfi_agent_catalog returned error payload');
    console.error(text.slice(0, 500));
    process.exit(1);
  }
  const hasEquity = names.some((n) => n.startsWith('equity_'));
  console.log(
    JSON.stringify(
      {
        ok: true,
        mcp_url: MCP_URL,
        tool_count: names.length,
        sample_tools: mustHave,
        has_equity_tools: hasEquity,
        catalog_preview: text.slice(0, 280).replace(/\s+/g, ' '),
      },
      null,
      2
    )
  );
} finally {
  await transport.close?.();
  await client.close?.();
}
