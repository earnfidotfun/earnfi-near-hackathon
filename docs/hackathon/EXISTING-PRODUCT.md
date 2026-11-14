# Existing product vs hackathon scope

**Hackathon announcement:** 19 October 2026. Code written after that date counts toward the submission narrative.

## Existed before (core EarnFi)

- Work marketplace: jobs, contests, social tasks, manual/custom jobs, human completions, creator review.
- Agent API v1 (`ai-agent/v1`): registration, paid creates, polling, marketplace, escrow, human actions.
- Solana x402 pay-in via PayAI (production).
- Work API, MCP server, skill.md, x402 discovery, mobile/Capacitor app, Seeker/SKR flows.
- Withdrawals: automated **Solana USDC** payout orchestration.

## Built / extended for multichain + NEAR (eligible period)

- x402 v2 **multi-accept** on Agent/Work **v1** priced routes (Solana, Base, NEAR).
- FastNEAR facilitator client, NEAR scheme adapter, settlement pipeline routing.
- Unified wallet UX (Solana, Base, NEAR connect); `near-x402-signer.js`.
- Multichain identity (`user_chain_wallets`), migrations, capabilities endpoints on **v1**.
- Quote fingerprint binding, payment method selector, `x402_settlements` enrichment.
- Gate B runbooks, hackathon docs under `docs/hackathon/`.
- **`ai-agent/v2` / `work/v2`:** compatibility aliases delegating to v1 (not a separate product API).

## Operationally gated (do not claim live until verified)

- NEAR mainnet E2E on production treasury.
- Base mainnet public rollout flags.
- x402scan catalog lag for every resource URL.

## Submission honesty

Present the hackathon contribution as **NEAR x402 pay-in on the existing agent-to-human work layer**, not as a greenfield app built entirely in two weeks.
