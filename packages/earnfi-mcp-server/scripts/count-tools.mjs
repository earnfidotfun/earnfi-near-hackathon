import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(dir, '../src/create-server.ts'), 'utf8');
const callCount = (src.match(/server\.registerTool\(/g) || []).length;
const re = /server\.registerTool\(\s*'((?:earnfi_|equity_)[^']+)'/g;
const names = [];
let m;
while ((m = re.exec(src))) names.push(m[1]);
const unique = [...new Set(names)];

const card = JSON.parse(
  fs.readFileSync(path.join(dir, '../../../server-card.json'), 'utf8')
);
const cardTools = (card.tools || card.mcp_tools || []).map((t) => t.name).filter(Boolean);
const cardSet = new Set(cardTools);

console.log(JSON.stringify({
  registerTool_calls: callCount,
  literal_quoted_names: names.length,
  unique_literal_names: unique.length,
  equity_literal: unique.filter((n) => n.startsWith('equity_')).length,
  earnfi_literal: unique.filter((n) => n.startsWith('earnfi_')).length,
  server_card_tools: cardSet.size,
  in_card_not_literal: [...cardSet].filter((n) => !unique.includes(n)).slice(0, 20),
  literal_not_in_card: unique.filter((n) => !cardSet.has(n)).slice(0, 20),
}, null, 2));
