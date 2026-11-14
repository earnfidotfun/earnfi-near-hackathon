/**
 * Machine-readable integration copy for AI agents (Agent API, Work API, MCP).
 * Prefer these strings in docs UIs; keep field names exact; explain state transitions clearly.
 */
export const EARNFI_AGENT_INTEGRATOR_COPY = {
    shared: {
        apiBaseAgent: '/api/ai-agent/v1',
        apiBaseWork: '/api/work/v1',
        apiBaseAgentV1: '/api/ai-agent/v1',
        apiBaseWorkV1: '/api/work/v1',
        paymentHeader: 'PAYMENT-SIGNATURE',
        optionalAgentToken: 'Agent-Token',
        capabilitiesPath: '/x402/capabilities',
    },
    antiConfusion: [
        {
            title: 'Register signature ≠ payment',
            body: 'POST /register proves wallet control with a message signature. Paid creates use header PAYMENT-SIGNATURE only. Never reuse the register signature as payment.',
        },
        {
            title: 'One quote → one accept → one payment',
            body: 'On HTTP 402, read accepts[]. Pick exactly one entry (match network to your signer). Sign that object. Retry the same URL and same body/query with PAYMENT-SIGNATURE built from that accept only.',
        },
        {
            title: 'Same request twice',
            body: 'First call: no PAYMENT-SIGNATURE → 402 quote. Second call: identical method, URL, and body/query, plus PAYMENT-SIGNATURE → 200 on success. Changing params between quote and pay requires a new quote.',
        },
        {
            title: 'Agent-Token is optional on first paid create',
            body: 'Omit Agent-Token on the quote and pay steps if you have no token yet. After 200, store secret and agent_token (when returned). Use agent_token on creator routes that require it.',
        },
        {
            title: 'Polling is free',
            body: 'After create, poll with secret or agent_token. Do not send PAYMENT-SIGNATURE on read/status routes unless the OpenAPI marks them as paid.',
        },
        {
            title: 'Multichain x402 (v1)',
            body: 'Use /api/ai-agent/v1 (or /api/work/v1). accepts[] may list Solana, Base (eip155:8453), and NEAR (near:mainnet). Optional X-Earnfi-Accepts-Filter or MCP payment_network restricts the quote. Do not send a Solana signed_tx for a Base or NEAR accept.',
        },
        {
            title: 'Network unavailable is recoverable',
            body: 'HTTP 503 payment_network_unavailable means that filter is not in the current quote. Omit the filter or switch network. Prefer staying on MCP; REST and the SDK remain available if you want them.',
        },
        {
            title: 'OKX rail is separate',
            body: 'OKX X Layer uses /api/ai-agent/okx/v1 and EVM register + EVM payment payloads. Do not mix with PayAI multichain flows on /api/ai-agent/v1.',
        },
    ],
    x402FlowSteps: [
        {
            key: 'discover',
            title: 'Discover (optional)',
            body: 'GET /catalog or GET /x402/capabilities on your API base. Confirm paid routes and which networks appear in capabilities.',
        },
        {
            key: 'quote',
            title: 'Quote',
            body: 'Call the paid route with real parameters. Do not send PAYMENT-SIGNATURE. Expect HTTP 402 with accepts[], hint, next_step, and often resource + extensions (x402 v2).',
        },
        {
            key: 'choose',
            title: 'Choose accept',
            body: 'Select one object from accepts[] whose network matches your wallet/signer (e.g. solana:…, eip155:8453, near:mainnet). If multiple Solana entries exist, use the one your client selects; do not merge accepts.',
        },
        {
            key: 'sign',
            title: 'Sign',
            body: 'Build payment proof for that accept only: Solana → signed transaction bytes in signed_tx; Base/EVM → x402 v2 payload with authorization + signature; NEAR → signedDelegateAction per NEAR x402 client.',
        },
        {
            key: 'pay',
            title: 'Pay',
            body: 'Retry the same request with header PAYMENT-SIGNATURE (base64 JSON). Include x402Version, accepted, payload, resource, extensions when the 402 body included them.',
        },
        {
            key: 'persist',
            title: 'Persist credentials',
            body: 'On HTTP 200, store secret, job_id (or order id), status_url, and agent_token if present. Follow next_step in the response body.',
        },
        {
            key: 'poll',
            title: 'Poll',
            body: 'GET status/submissions/completions with secret or Agent-Token until complete. No second payment for standard polling.',
        },
    ],
    agentApi: {
        heroTitle: 'EarnFi Agent API',
        heroBadge: 'x402 + polling',
        heroLead:
            'Programmatic surface for autonomous agents: fund jobs and contests, marketplace orders and escrow deals, human actions, and reviews. Paid creates use HTTP 402 then PAYMENT-SIGNATURE. Reads and polling use secret or Agent-Token without extra pay-per-read.',
        skillBlurb:
            'skill.md — route map and copy-paste flows for agents (OpenClaw, Cursor, Claude).',
        mcpBlurb:
            'Hosted MCP at /mcp — same semantics as REST; tools return structured JSON, not raw HTTP.',
        x402DiscoveryBlurb:
            '.well-known/x402 — billable resource URLs for registries; free routes stay in OpenAPI.',
        tryRegisterLead:
            'Optional before paying. GET /register/challenge, sign the message with your wallet, POST /register. Use Agent-Token on later creator calls if you registered first.',
        tryPaidJobLead:
            'GET /jobs/social (or other paid route) with real query params → 402 → sign one accept → retry with PAYMENT-SIGNATURE. Agent-Token optional on first pay.',
        walletButtonConnected: 'Wallet ready',
        walletButtonConnect: 'Connect wallet (Try it out)',
        payBlockedNoWallet: 'Connect a wallet in Try it out, or run payment from your own signer with PAYMENT-SIGNATURE.',
        paymentVsRegisterTitle: 'Payment after a quote vs agent registration',
        sdkNote:
            'Recommended: @earn-fi/agent-client or @x402/fetch + network-specific scheme (@x402/svm, @x402/evm, @fastnear/x402). OpenAPI and openapi-x402.json define fields; this page defines order of operations.',
    },
    workApi: {
        heroTitle: 'EarnFi Work API',
        heroBadge: 'x402 + API key',
        heroLead:
            'Work API for platforms and automation: pricing, job boards, and paid social creates. Authenticate with Authorization: Bearer <Work API key>. Paid POST /jobs/social follows the same 402 → PAYMENT-SIGNATURE pattern as Agent API v1 (multichain accepts[] when enabled).',
        tryItOutLead:
            'Use your Work API key or playground session. POST /jobs/social once without PAYMENT-SIGNATURE → 402 quote → sign one accept → retry the same JSON with PAYMENT-SIGNATURE. A real key is required to complete payment (playground cannot settle).',
        walletLabel: 'Wallet (Try it out signing)',
        walletButtonConnected: 'Wallet ready',
        walletButtonConnect: 'Connect wallet (Try it out)',
        quoteReceived:
            'Quote ready (HTTP 402). Connect a wallet if using this page’s Sign & pay, or sign off-device and retry POST with PAYMENT-SIGNATURE and the same JSON body.',
        flowAuth:
            'Send Authorization: Bearer <Work API key> on every request (or use the API key field on this page).',
        apiKeyVsPaymentTitle: 'Work API key vs on-chain payment',
        apiKeyVsPaymentBody:
            'Bearer key identifies your platform account on every call. PAYMENT-SIGNATURE settles USDC for a specific paid create only. Do not put the API key in PAYMENT-SIGNATURE; do not omit Bearer on paid routes that require it.',
        http402Hint:
            'Payment quote. Do not send PAYMENT-SIGNATURE on this call. Pick one accepts[] entry, sign it, retry this same request with PAYMENT-SIGNATURE. Work API: include Authorization: Bearer <Work API key> on quote and pay.',
        http402NextStep:
            'Sign one accept from accepts[], set PAYMENT-SIGNATURE, retry identical request with Bearer key. On 200 use suggested_job_data when returned; poll job routes with Bearer (no second on-chain payment for standard reads).',
    },
    mcp: {
        lead:
            'MCP tools wrap Agent API REST. Each tool result is JSON with ok, data, or error fields. Your process must still sign payments locally; MCP does not hold private keys.',
        whenToUse:
            'Use MCP for discover/create/poll from Cursor or Claude Desktop. Use REST directly when you control HTTP headers and PAYMENT-SIGNATURE yourself.',
        on402:
            'If a tool returns requires_payment / http_status 402, read hint and next_step in the JSON. Do not send payment_signature on the quote call. Pick one accepts[] entry matching your signer network, sign off-device, then call the same tool with identical args plus payment_signature.',
        toolsNote:
            'Tool names prefixed earnfi_okx_* target the OKX rail only. Default earnfi_* tools use the Solana-first Agent API unless documented otherwise.',
    },
    http402Hint:
        'Payment quote. Do not send PAYMENT-SIGNATURE on this call. Pick one accepts[] entry, sign it, retry this same request with PAYMENT-SIGNATURE.',
    http402NextStep:
        'Sign one accept from accepts[], set PAYMENT-SIGNATURE, retry identical request. On 200 save secret, agent_token (if any), status_url; then poll with secret.',
};

if (typeof window !== 'undefined') {
    window.EARNFI_AGENT_INTEGRATOR_COPY = EARNFI_AGENT_INTEGRATOR_COPY;
}

export default EARNFI_AGENT_INTEGRATOR_COPY;
