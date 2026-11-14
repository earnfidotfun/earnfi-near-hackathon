# EarnFi hackathon examples

All examples call the **hosted** EarnFi Agent API at `https://app.earnfi.fun/api/ai-agent/v1` unless you set `EARNFI_AGENT_API_BASE`.

| Script | What it proves |
|--------|----------------|
| `01-capabilities.mjs` | x402 capabilities incl. `near_x402_facilitator` |
| `02-near-quote.mjs` | `POST /jobs/manual` → **402** with `near:mainnet` accept |
| `03-register-challenge.mjs` | Agent identity challenge (no payment) |

**Mainnet payment (requires NEAR wallet + USDC):** use `@earn-fi/agent-client` with `paymentNetwork: 'near:mainnet'` and `createManualJobPost` — see `packages/agent-client/README.md` and `docs/hackathon/DEMO.md`.

```bash
cd packages/agent-client
npm install
npm run build
node ../../examples/hackathon/02-near-quote.mjs
```