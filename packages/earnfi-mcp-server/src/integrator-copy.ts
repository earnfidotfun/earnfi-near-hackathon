/**
 * Agent integrator copy — keep aligned with
 * public/js/utils/earnfi-agent-integrator-copy.js and class-earnfi-agent-integrator-copy.php
 */
export const EARNFI_HTTP402_HINT =
  'Payment quote. Do not send PAYMENT-SIGNATURE on this call. Pick one accepts[] entry, sign it, retry this same request with PAYMENT-SIGNATURE.';

export const EARNFI_HTTP402_NEXT_STEP =
  'Sign one accept from accepts[], set PAYMENT-SIGNATURE, retry identical request. On 200 save secret, status_url, agent_token (if any); then poll with secret.';

export const EARNFI_MCP_402_NEXT_STEP =
  'Payment quote (HTTP 402). Do not send payment_signature on the quote call. Pick exactly one accepts[] entry whose network matches your signer (solana:…, eip155:8453, near:mainnet). Optional payment_network on the same tool sends X-Earnfi-Accepts-Filter so the quote only returns that network. Sign that accept only: Solana signed_tx, Base EVM x402 authorization, or NEAR signedDelegateAction. OKX tools use eip155:196 USDT0 paymentPayload (never a Solana signed_tx). Call this same MCP tool again with identical parameters plus payment_signature. agent_token is optional on first pay; store secret and agent_token from 200, then poll without payment.';

export const EARNFI_NETWORK_UNAVAILABLE_NEXT_STEP =
  'This network is not in the current quote. That is not a hard outage. Omit payment_network and retry, or set payment_network to another live CAIP-2 (solana:…, eip155:8453, near:mainnet). For OKX USDT0 use earnfi_okx_* tools. Call earnfi_payment_rails or earnfi_x402_capabilities to see what is offered.';

export const EARNFI_AGENT_API_V1_BASE = '/api/ai-agent/v1';
export const EARNFI_WORK_API_V1_BASE = '/api/work/v1';

export const EARNFI_ANTI_CONFUSION_RULES: { title: string; body: string }[] = [
  {
    title: 'Register signature is not payment',
    body: 'POST /register proves wallet control with a message signature. Paid creates use PAYMENT-SIGNATURE only. Never reuse the register signature as payment.',
  },
  {
    title: 'One quote, one accept, one payment',
    body: 'On HTTP 402, read accepts[]. Pick exactly one entry whose network matches your signer. Sign that object. Retry the same tool args with payment_signature built from that accept only.',
  },
  {
    title: 'Same request twice',
    body: 'First call: no payment_signature → 402 quote. Second call: identical parameters plus payment_signature → 200. Changing params between quote and pay needs a new quote. Keep payment_network the same on both calls when you use it.',
  },
  {
    title: 'Agent-Token is optional on first paid create',
    body: 'Omit agent_token on quote and pay if you have none yet. After 200, store secret and agent_token when returned. Use agent_token on creator routes that need it.',
  },
  {
    title: 'Polling is free',
    body: 'After create, poll with secret or agent_token. Do not send payment_signature on read/status routes unless OpenAPI marks them paid.',
  },
  {
    title: 'Multichain x402 on /v1',
    body: 'Canonical base is /api/ai-agent/v1. accepts[] may list Solana, Base (eip155:8453), and NEAR (near:mainnet). Use payment_network or pick the matching accept. Never send a Solana signed_tx for a Base or NEAR accept.',
  },
  {
    title: 'Network unavailable is recoverable',
    body: 'HTTP 503 payment_network_unavailable or empty accepts means that filter is not offered right now. Omit the filter or switch to another network. Do not abandon MCP for REST solely because one network was missing.',
  },
  {
    title: 'Register per wallet type',
    body: 'Solana: earnfi_register_*. Base: earnfi_base_register_* (EIP-191). NEAR: earnfi_near_register_* (NEP-413). OKX: earnfi_okx_register_* (EIP-191 on /okx/v1). Registration is optional on every rail.',
  },
  {
    title: 'OKX rail is separate',
    body: 'OKX X Layer uses /api/ai-agent/okx/v1 and earnfi_okx_* tools with EVM USDT0 payloads. Do not mix with PayAI multichain tools on /v1.',
  },
  {
    title: 'Work API Bearer vs payment',
    body: 'Authorization: Bearer <Work API key> on every Work call. PAYMENT-SIGNATURE settles on-chain payment for a paid create only. Never substitute one for the other.',
  },
  {
    title: 'MCP and REST are the same API',
    body: 'MCP tools wrap Agent API routes with structured JSON. Prefer MCP when connected; REST and @earn-fi/agent-client remain fully supported. skill.md documents both.',
  },
];

export const EARNFI_X402_FLOW_STEPS = [
  { key: 'discover', title: 'Discover', body: 'earnfi_integrator_rules, earnfi_health, earnfi_agent_catalog, earnfi_x402_capabilities, earnfi_payment_rails.' },
  { key: 'quote', title: 'Quote', body: 'Paid create tool without payment_signature. Optional payment_network to filter accepts[]. Expect 402 with accepts[], hint, next_step.' },
  { key: 'choose', title: 'Choose accept', body: 'Select one accepts[] entry matching your signer, or re-quote with payment_network set.' },
  { key: 'sign', title: 'Sign', body: 'Build payment proof for that accept only (Solana signed_tx, Base EVM x402, NEAR signedDelegateAction, OKX USDT0).' },
  { key: 'pay', title: 'Pay', body: 'Same tool + payment_signature (+ same payment_network if used).' },
  { key: 'persist', title: 'Persist', body: 'On 200 store secret, job_id/action_id, status_url, agent_token if present.' },
  { key: 'poll', title: 'Poll', body: 'Status tools with secret or agent_token. No second payment for standard polling.' },
];
