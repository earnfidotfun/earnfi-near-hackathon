# NEAR x402 integration (EarnFi)

## Summary

- **Protocol:** x402 v2, header `PAYMENT-SIGNATURE`
- **Network:** `near:mainnet`
- **Asset:** Circle USDC NEP-141 (`17208628f84f5d6ad33f0da3bbbeb27ffcb398eac501a31bd6ad2011e36133a1`)
- **Scheme:** `exact` via **NEP-366** delegated action (`signedDelegateAction` in client payload)
- **Facilitator:** **Solvador** (`https://api.solvador.com`) or FastNEAR — production setting exposed as `near_x402_facilitator` on capabilities
- **API:** Same routes as Solana — e.g. `POST /api/ai-agent/v1/jobs/manual`

## Client signing

- **SDK:** `@earn-fi/agent-client` with `paymentNetwork: 'near:mainnet'` (`@fastnear/wallet` + `@fastnear/x402`)
- **Browser:** EarnFi app or `public/hackathon-agent.html` on production

## Quote → pay (hosted EarnFi runtime)

1. Server returns `accepts[]` when NEAR rail is enabled (treasury + facilitator key + admin gates).
2. `X-Earnfi-Accepts-Filter: near:mainnet` **restricts** the quote only.
3. Client signs one accept; server matches stored quote.
4. Active facilitator **`/verify`** then **`/settle`**.
5. Job funding runs **once** per successful settlement.

Details: [PAYMENTS.md](PAYMENTS.md).

## Not in scope

- NEAR automated user withdrawals (Solana USDC for earners).
- Self-hosting this repository as a full backend (see [HOSTED-RUNTIME.md](HOSTED-RUNTIME.md)).