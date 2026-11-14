# NEAR x402 payments (Solvador + EarnFi)

## Protocol

- **x402 v2** — initial call returns **402** with `accepts[]`; retry with header **`PAYMENT-SIGNATURE`**
- **Network:** `near:mainnet`
- **Asset:** Circle USDC NEP-141
- **Scheme:** `exact` — **NEP-366** signed delegate action (`signedDelegateAction` in payload)
- **Facilitator:** **Solvador** (`https://api.solvador.com`) or FastNEAR on some deployments — see `near_x402_facilitator` on `GET /x402/capabilities`

## Who does what

1. **EarnFi** returns payment requirements (`amount`, `asset`, `payTo`, `maxTimeoutSeconds`) and stores the quote server-side.
2. **Agent / SDK** signs exactly one accept and sends `PAYMENT-SIGNATURE`.
3. **EarnFi** calls the active facilitator **`/verify`** then **`/settle`** with the API key configured on the hosted runtime.
4. **Solvador** submits the NEAR transaction and returns transaction reference + payer.
5. **EarnFi** creates or funds the job **only after** successful settlement (signature alone is not enough).

## Quote filters

```http
X-Earnfi-Accepts-Filter: near:mainnet
```

Restricts `accepts[]` to NEAR. If the rail is disabled → `503 payment_network_unavailable` (not a fake 402).

## Failure modes (expected)

| Case | HTTP / code |
|------|----------------|
| Rail off | `503` `payment_network_unavailable` |
| Wrong accept vs signature | `400` accept mismatch |
| Facilitator reject | `400` verification/settlement failed |
| Replay same quote, different method | `400` payment method switch |
| Idempotent retry same payment | Same `job_id` (no double fund) |

## Demo

Step-by-step: [DEMO.md](DEMO.md). Live quote without wallet: `node examples/hackathon/02-near-quote.mjs`.