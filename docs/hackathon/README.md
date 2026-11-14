# EarnFi — NEAR Hackathon 2026 readiness

**Tracks:** AGT-07 (primary), BUS-04 (secondary)  
**Deadline:** 14 November 2026, 23:59 UTC (15 Nov 00:59 WAT)

EarnFi is **Solana-first**. NEAR mainnet USDC x402 is an additional pay-in rail on the **same** Agent API (`/api/ai-agent/v1`).

## What judges should see

An AI agent creates a **manual job** (real-world brief), receives **HTTP 402** with **`accepts[]`**, pays with **NEAR USDC** (NEP-366), funds exactly **one** job, a **human worker** completes it in the live app, and the agent retrieves **evidence** via the Agent API.

Workers are paid through EarnFi’s existing work settlement flows; **automated withdrawals to users remain Solana USDC only** (NEAR funds the job, not worker cash-out chain).

## Public git repository

See [SUBMISSION-REPO.md](SUBMISSION-REPO.md) — reviewers need runnable source, not markdown alone.

## Documentation map

| File | Purpose |
|------|---------|
| [DEMO.md](DEMO.md) | Step-by-step API demo |
| [NEAR-INTEGRATION.md](NEAR-INTEGRATION.md) | Protocol + Solvador |
| [WORK-MONEY-LAYER.md](WORK-MONEY-LAYER.md) | Jobs, marketplace, receipts |
| [PAYMENTS.md](PAYMENTS.md) | x402 + facilitator split |
| [HOSTED-RUNTIME.md](HOSTED-RUNTIME.md) | What runs locally vs live |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Judge-facing diagram |
| [EXISTING-PRODUCT.md](EXISTING-PRODUCT.md) | Pre/post 19 Oct 2026 scope |
| [SECURITY.md](SECURITY.md) | Quote binding, replay, idempotency |
| [TRANSACTIONS.md](TRANSACTIONS.md) | Mainnet tx placeholders |

## Live surfaces

- Agent API: `https://app.earnfi.fun/api/ai-agent/v1`
- Capabilities: `GET .../x402/capabilities`
- Judge console (minimal): `https://app.earnfi.fun/hackathon-agent`
- Skill / OpenAPI: `/skill.md`, `/openapi-x402.json`
- MCP: `https://app.earnfi.fun/mcp`

**Compatibility:** `/api/ai-agent/v2` and `/api/work/v2` delegate to v1 handlers.

## Operator checklist

See [../earnfi-multichain/GATE-B-NEAR-E2E-RUNBOOK.md](../earnfi-multichain/GATE-B-NEAR-E2E-RUNBOOK.md) and [../earnfi-multichain/NEAR-ACTIVATION-GUIDE.md](../earnfi-multichain/NEAR-ACTIVATION-GUIDE.md).

Before claiming mainnet in the submission: record real tx references in [TRANSACTIONS.md](TRANSACTIONS.md).
