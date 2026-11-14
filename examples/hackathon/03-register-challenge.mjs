#!/usr/bin/env node
/**
 * Live: fetch register challenge for a NEAR account id (identity only, no payment).
 */
const BASE =
    (process.env.EARNFI_AGENT_API_BASE || 'https://app.earnfi.fun/api/ai-agent/v1').replace(/\/$/, '');
const NEAR_ACCOUNT = process.env.EARNFI_DEMO_NEAR_ACCOUNT || 'demo.testnet';

async function main() {
    const url = new URL(`${BASE}/register/challenge`);
    url.searchParams.set('wallet_address', NEAR_ACCOUNT);
    url.searchParams.set('agent_name', 'hackathon-demo-agent');
    url.searchParams.set('network', 'near:mainnet');
    const res = await fetch(url);
    const json = await res.json();
    console.log('HTTP', res.status);
    if (!res.ok) {
        console.error(json);
        process.exit(1);
    }
    console.log('message length:', String(json.message || '').length);
    console.log('network_caip2:', json.network_caip2 || json.network);
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});