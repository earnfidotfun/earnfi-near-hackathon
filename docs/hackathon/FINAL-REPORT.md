# Hackathon submission report (Strategy B+)

## Repository shape

Public submission: **SDK + MCP + contracts + live examples** — no server CMS source. Regenerate with `scripts/hackathon/export-public-repo.mjs`.

## NEAR pay-in (hosted runtime)

- x402 v2 on `POST /api/ai-agent/v1/jobs/manual`
- Filter: `X-Earnfi-Accepts-Filter: near:mainnet`
- Facilitator: **Solvador** (or FastNEAR per `near_x402_facilitator` on capabilities)
- Client: `@earn-fi/agent-client` with `paymentNetwork: 'near:mainnet'`

## Automated checks (export tree)

```bash
npm install && npm run build:sdk && npm test && npm run test:live
node examples/hackathon/02-near-quote.mjs
node scripts/hackathon/verify-no-wordpress.mjs --dir .
```

## Production E2E status

Record mainnet tx + `job_id` in [TRANSACTIONS.md](TRANSACTIONS.md) after ops enable NEAR rail and a paid Agent API run completes.

## Honest limitations

- Self-hosting full EarnFi backend is **out of scope** for this repo — see [HOSTED-RUNTIME.md](HOSTED-RUNTIME.md)
- Mainnet payment video requires wallet-funded NEAR USDC demo