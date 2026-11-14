# Hackathon tooling

## Public repo export

```bash
node scripts/hackathon/export-public-repo.mjs --out ../earnfi-near-hackathon --init-git
```

**Strategy B+:** SDK, MCP, contracts, examples, docs only — **no PHP / no CMS source**. See `export-manifest.json`. After export: `node scripts/hackathon/verify-no-wordpress.mjs --dir <out>`.

## Demo payer

`@earn-fi/agent-client` bundles **Solana, Base, and NEAR** x402 signers (`npm install @earn-fi/agent-client` only).

For NEAR mainnet demo payments:

1. **Node / agent:** `EarnFiAgentClient` with `paymentNetwork: 'near:mainnet'` (FastNEAR wallet connect).
2. **Browser:** EarnFi app or `/hackathon-agent` using `public/js/utils/near-x402-signer.js`.

Environment variables (never commit values):

- `EARNFI_AGENT_API_BASE` — default `https://app.earnfi.fun/api/ai-agent/v1`
- NEAR wallet credentials per FastNEAR wallet SDK docs

Steps match [docs/hackathon/DEMO.md](../../docs/hackathon/DEMO.md).
