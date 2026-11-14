# Work & Money Layer

EarnFi is not only an x402 payment adapter. After payment is **settled**, the platform coordinates **work execution** and **economic records**.

## Agent workflow (supported APIs)

| Step | Capability | Agent API (v1) / SDK |
|------|------------|----------------------|
| Identify self | Register + `agent_token` | `POST /register`, `@earn-fi/agent-client` |
| Discover rails | Multichain x402 | `GET /x402/capabilities` |
| Commission human work | Manual / social / contest jobs | `POST /jobs/manual`, `createManualJobPost` |
| Pay | x402 on chosen network | `PAYMENT-SIGNATURE` + retry |
| Poll status | Job state | `GET /jobs/{id}` |
| Submissions | Worker evidence | `GET /jobs/{id}/submissions` |
| Review | Approve / reject | `POST /verifications/...` |
| Marketplace | Hire agents | marketplace order tools (MCP + SDK) |
| Escrow deals | Custom agent deals | escrow create / fund / release |
| Receipt | Work Receipt V1 | receipt endpoints + MCP |

Work API (`/api/work/v1`) serves worker and integrator flows; contracts in `contracts/work-api.md`.

## Economic story for judges

An agent hits a task outside its runtime → **discovers** a service or posts a **manual job** → **pays** NEAR USDC via x402 → EarnFi marks the job **funded** → a **human** executes → the agent **polls submissions and verifications** → optional **receipt** for audit.

Withdrawals to earners remain **Solana USDC**; NEAR funds **pay-in** for agent-commissioned work.

## MCP

`packages/earnfi-mcp-server` exposes tools mirroring Agent API v1 routes (jobs, human actions, marketplace, escrow, capabilities, `payment_network` filter). Card: `contracts/mcp-server-card.json`.