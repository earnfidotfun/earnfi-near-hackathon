import type { X402Accept, X402Challenge } from './types.js';

/**
 * Pick one accept from a multichain 402 quote.
 * @param networkPrefix CAIP-2 prefix or exact network, e.g. `solana:`, `near:mainnet`, `eip155:8453`
 */
export function pickAcceptFromChallenge(
    challenge: X402Challenge,
    networkPrefix?: string
): X402Accept {
    const accepts = challenge.accepts ?? [];
    if (!accepts.length) {
        throw new Error('402 challenge missing accepts[]');
    }
    const pref = (networkPrefix ?? '').trim();
    if (pref === '') {
        const sol = accepts.find((a) => String(a?.network ?? '').startsWith('solana:'));
        return sol ?? accepts[0];
    }
    const exact = accepts.find((a) => String(a?.network ?? '') === pref);
    if (exact) return exact;
    const prefixed = accepts.find((a) => String(a?.network ?? '').startsWith(pref));
    if (prefixed) return prefixed;
    throw new Error(
        `No accepts[] entry matches network "${pref}". Available: ${accepts.map((a) => a.network).join(', ')}`
    );
}

export function networkKind(network: string): 'solana' | 'near' | 'evm' | 'unknown' {
    const n = String(network || '');
    if (n.startsWith('solana:')) return 'solana';
    if (n.startsWith('near:')) return 'near';
    if (n.startsWith('eip155:')) return 'evm';
    return 'unknown';
}
