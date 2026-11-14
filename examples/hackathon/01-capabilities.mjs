#!/usr/bin/env node
/**
 * Live: GET /x402/capabilities — NEAR rail and active facilitator hint.
 */
const BASE =
    (process.env.EARNFI_AGENT_API_BASE || 'https://app.earnfi.fun/api/ai-agent/v1').replace(/\/$/, '');

async function main() {
    const res = await fetch(`${BASE}/x402/capabilities`);
    const body = await res.json();
    console.log('HTTP', res.status);
    console.log('near_mainnet_pay:', body.near_mainnet_pay);
    console.log('near_x402_facilitator:', body.near_x402_facilitator ?? '(not set)');
    console.log('facilitators:', (body.facilitators || []).join(', '));
    if (!res.ok) process.exit(1);
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});