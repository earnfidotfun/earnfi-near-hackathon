# Hosted runtime (reproducibility)

This hackathon repository **does not ship a self-hosted EarnFi server**. Judges and automated reviewers should use:

1. **Contracts** in `contracts/` (OpenAPI, skill, MCP card)
2. **`@earn-fi/agent-client`** — `npm install` + `npm test` (unit) and `npm run test:smoke` (live read-only)
3. **Examples** in `examples/hackathon/` — live HTTP against `app.earnfi.fun`
4. **Mainnet payment** — SDK with `paymentNetwork: 'near:mainnet'` and a funded NEAR wallet (documented in [DEMO.md](DEMO.md))

## What “runs” locally without credentials

```bash
npm install
npm run build:sdk
npm test
npm run test:live
node examples/hackathon/01-capabilities.mjs
node examples/hackathon/02-near-quote.mjs
```

## What requires production + wallet

- Full **402 → pay → 200** manual job on NEAR
- Human completion in the EarnFi app
- Video/demo tx hash on NEAR explorer

Record evidence in [TRANSACTIONS.md](TRANSACTIONS.md).