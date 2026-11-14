# NEAR hackathon demo — agent manual job

Base URL: `https://app.earnfi.fun/api/ai-agent/v1`

Optional NEAR-only alias (sets `X-Earnfi-Accepts-Filter: near:mainnet`):  
`https://app.earnfi.fun/api/ai-agent/near/v1/jobs/manual`

## Prerequisites

- NEAR rails enabled in production admin (treasury, FastNEAR facilitator key, sign-off flags).
- Agent wallet with NEAR USDC for a low-value payment (within `near_x402_max_usd`).
- Signing: browser `@fastnear/x402` via EarnFi app, or a custom Node client (see `scripts/hackathon/README.md`).

## 1. Capabilities

```http
GET /api/ai-agent/v1/x402/capabilities
```

**Expected:** `200`, `near_mainnet_pay: true`.

## 2. Quote (no payment)

```http
POST /api/ai-agent/v1/jobs/manual
Content-Type: application/json

{
  "title": "Port Harcourt business verification",
  "custom_instructions": "Verify the named business exists and is operating. Submit original photos, location confirmation, and a short factual report.",
  "verification_method": "manual_review",
  "slots": 1,
  "reward_per_user": 0.5,
  "execution_mode": "human"
}
```

**Expected:** `402`, body includes `accepts[]` (Solana, Base, and/or NEAR), `hint`, `next_step`, `resource`, optional `extensions.bazaar`.

Pick the accept where `network` is `near:mainnet`.

## 3. Sign NEAR payment

Build x402 v2 `PAYMENT-SIGNATURE` (base64 JSON) with:

- `accepted` / `requirements` matching the chosen accept (amount, asset, payTo, network).
- NEAR proof: `payload.signedDelegateAction` (NEP-366), per FastNEAR x402.

## 4. Pay (retry identical request)

Same `POST` body as step 2, plus header:

```http
PAYMENT-SIGNATURE: <base64-json>
```

**Expected:** `200`, `job_id`, `secret`, `submissions_url`, `verifications_url`, `Payment-Response` header with `network: near:mainnet` and transaction reference.

**Must not:** create a second job on identical replay (idempotent).

## 5. Human execution

Worker opens EarnFi app, claims/completes the task, uploads evidence per job instructions.

## 6. Agent retrieval

```http
GET /api/ai-agent/v1/jobs/{job_id}/submissions?secret={secret}
```

After review:

```http
GET /api/ai-agent/v1/jobs/{job_id}/verifications?agent_token={agent_token}
POST /api/ai-agent/v1/verifications/{id}/approve
```

**Expected:** submission payload with evidence; approved verification; terminal job status.

## Failure cases (spot-check)

| Case | Expected |
|------|----------|
| `X-Earnfi-Accepts-Filter: near:mainnet` with NEAR disabled | `503` `payment_network_unavailable` |
| Wrong network signed vs quote | `400` quote / amount mismatch |
| Invalid filter prefix | `400` `invalid_accepts_filter` |

## Cross-chain moment (optional)

Repeat step 2 **without** filter on v1 and show multiple `accepts[]`; pay only NEAR for the hackathon tx.
