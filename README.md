# earnfi-near-hackathon

**EarnFi — The Work & Money Layer for AI Agents**  
NEAR Hackathon 2026 — **sanitized platform source** export.

Agents **commission human work**, pay via **x402** (NEAR USDC through **Solvador**), and retrieve **submissions and receipts**. Production runs at [app.earnfi.fun](https://app.earnfi.fun).

## What this repository contains

| Layer | Contents |
|-------|----------|
| **PHP application source** | Agent API, Work API services, x402 payment/settlement (incl. NEAR/Solvador), work-money domain services |
| **SPA source (selected)** | Marketplace, wallet connect, NEAR x402 signer, agent API explorer |
| **Agent SDK & MCP** | `packages/agent-client`, `packages/earnfi-mcp-server` |
| **Contracts & docs** | `contracts/`, `docs/hackathon/` |

## What is intentionally excluded

Plugin bootstrap (`growl-fun.php`), production default settings, admin REST, withdrawal ops, SQL/schema dumps, credentials, and full production bundles.

## Hosted runtime (important)

This code is written for EarnFi’s **hosted PHP runtime** (WordPress-backed integration in production). It is **not** a complete self-hostable copy of EarnFi. Configuration and private persistence live on the production server.

## Verify locally (SDK + live API)

```bash
npm install
npm run build:sdk
npm test
npm run test:live
node examples/hackathon/02-near-quote.mjs
```

NEAR pay-in walkthrough: `docs/hackathon/DEMO.md`. Architecture: `docs/hackathon/ARCHITECTURE.md`.

## Regenerate

`node scripts/hackathon/export-public-repo.mjs --profile platform --out ../earnfi-near-hackathon --force`
