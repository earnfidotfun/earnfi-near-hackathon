#!/usr/bin/env node
/**
 * Live: quote a manual job with NEAR filter — expect 402 + near:mainnet accept.
 * Does not send payment (no wallet required).
 */
const BASE =
    (process.env.EARNFI_AGENT_API_BASE || 'https://app.earnfi.fun/api/ai-agent/v1').replace(/\/$/, '');

const body = {
    title: 'Hackathon verification brief',
    custom_instructions:
        'Confirm the named location exists. Submit photos and a short factual report.',
    verification_method: 'manual_review',
    slots: 1,
    reward_per_user: 0.5,
    execution_mode: 'human',
};

async function main() {
    const res = await fetch(`${BASE}/jobs/manual`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Earnfi-Accepts-Filter': 'near:mainnet',
        },
        body: JSON.stringify(body),
    });
    const text = await res.text();
    let json;
    try {
        json = JSON.parse(text);
    } catch {
        json = { raw: text.slice(0, 500) };
    }
    console.log('HTTP', res.status);
    if (res.status === 503 && json.code === 'payment_network_unavailable') {
        console.error('NEAR rail unavailable on this environment (admin/treasury/facilitator).');
        console.error(json);
        process.exit(1);
    }
    if (res.status !== 402) {
        console.error('Expected 402 quote; got', res.status, json);
        process.exit(1);
    }
    const accepts = json.accepts || [];
    const near = accepts.find((a) => String(a.network || '').startsWith('near:'));
    if (!near) {
        console.error('No near: accept in', accepts.map((a) => a.network));
        process.exit(1);
    }
    console.log('OK — near accept:', {
        network: near.network,
        asset: near.asset,
        amount: near.amount || near.maxAmountRequired,
        payTo: near.payTo,
        facilitator: near.extra?.facilitator,
    });
}

main().catch((e) => {
    console.error(e);
    process.exit(1);
});