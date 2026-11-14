import type { Connection } from '@solana/web3.js';
import {
    createFastNearWalletSigner,
    createNearX402Client,
    type NearNetworkPattern,
} from '@fastnear/x402';
import * as nearWallet from '@fastnear/wallet';
import type { PaymentRequired } from '@x402/core/types';
import { x402Client, x402HTTPClient } from '@x402/core/client';
import { toClientEvmSigner } from '@x402/evm';
import { registerExactEvmScheme } from '@x402/evm/exact/client';
import type { PaymentSignaturePayload, WalletLike, X402Accept, X402Challenge } from './types.js';
import { networkKind } from './accepts.js';
import { b64encodeJson, signExactSvmPayment } from './x402.js';

export type EvmWalletLike = {
    address: `0x${string}`;
    signTypedData: (args: {
        domain: Record<string, unknown>;
        types: Record<string, unknown>;
        primaryType: string;
        message: Record<string, unknown>;
    }) => Promise<`0x${string}`>;
};

export type NearX402SignContext = {
    /** Override for tests — defaults to bundled @fastnear/x402 + @fastnear/wallet */
    loadNearModules?: () => Promise<{
        createNearX402Client: typeof createNearX402Client;
        createFastNearWalletSigner: typeof createFastNearWalletSigner;
        nearWallet: typeof nearWallet;
    }>;
    network?: string;
};

export type MultichainSignOptions = {
    wallet?: WalletLike;
    connection?: Connection;
    evmWallet?: EvmWalletLike;
    near?: NearX402SignContext;
    computeUnitLimit?: number;
    computeUnitPriceMicroLamports?: bigint;
};

async function defaultNearModules() {
    return {
        createNearX402Client,
        createFastNearWalletSigner,
        nearWallet,
    };
}

/**
 * Build the JSON object to send as PAYMENT-SIGNATURE (base64-encoded by caller).
 */
export async function buildPaymentSignaturePayload(
    accept: X402Accept,
    challenge: X402Challenge,
    opts: MultichainSignOptions
): Promise<Record<string, unknown>> {
    const kind = networkKind(String(accept.network ?? ''));

    if (kind === 'solana') {
        if (!opts.wallet || !opts.connection) {
            throw new Error('Solana payment requires wallet + connection on EarnFiAgentClient.');
        }
        const sol = await signExactSvmPayment(accept, {
            wallet: opts.wallet,
            connection: opts.connection,
            computeUnitLimit: opts.computeUnitLimit,
            computeUnitPriceMicroLamports: opts.computeUnitPriceMicroLamports,
        });
        return {
            signed_tx: sol.signed_tx,
            requirements: sol.requirements,
            accepted: sol.requirements,
            resource: challenge.resource,
            extensions: (challenge as { extensions?: unknown }).extensions,
        };
    }

    if (kind === 'near') {
        const loader = opts.near?.loadNearModules ?? defaultNearModules;
        const mods = await loader();
        const network = String(accept.network ?? 'near:mainnet');
        const connection = await mods.nearWallet.connect({
            network: network.includes('testnet') ? 'testnet' : 'mainnet',
            features: { signDelegateActions: true, signDelegateActionsWithTtl: true },
        });
        if (!connection?.accountId) {
            throw new Error('Connect a NEAR wallet before paying (FastNEAR wallet connect).');
        }
        const signer = mods.createFastNearWalletSigner({ wallet: mods.nearWallet });
        const client = mods.createNearX402Client({
            signer,
            network: network as NearNetworkPattern,
        });
        const http = new x402HTTPClient(client);
        const paymentRequired = {
            x402Version: challenge.x402Version ?? 2,
            accepts: [accept],
            resource: challenge.resource,
            extensions: (challenge as { extensions?: unknown }).extensions,
        } as PaymentRequired;
        const payload = (await http.createPaymentPayload(paymentRequired)) as {
            payload?: { signedDelegateAction?: string };
        };
        const delegate = payload?.payload?.signedDelegateAction;
        if (typeof delegate !== 'string' || delegate === '') {
            throw new Error('NEAR wallet did not return signedDelegateAction.');
        }
        return {
            payload,
            paymentPayload: payload,
            accepted: accept,
            requirements: accept,
            resource: challenge.resource,
        };
    }

    if (kind === 'evm') {
        if (!opts.evmWallet) {
            throw new Error('EVM payment requires evmWallet on EarnFiAgentClient (viem privateKeyToAccount or browser wallet).');
        }
        const evmSigner = toClientEvmSigner(opts.evmWallet);
        const client = registerExactEvmScheme(new x402Client(), { signer: evmSigner });
        const http = new x402HTTPClient(client);
        const paymentRequired = {
            x402Version: challenge.x402Version ?? 2,
            accepts: [accept],
            resource: challenge.resource,
            extensions: (challenge as { extensions?: unknown }).extensions,
        } as PaymentRequired;
        const payload = await http.createPaymentPayload(paymentRequired);
        return {
            payload,
            paymentPayload: payload,
            accepted: accept,
            requirements: accept,
            resource: challenge.resource,
        };
    }

    throw new Error(`Unsupported payment network: ${accept.network}`);
}

/** Legacy Solana-only shape for callers that expect PaymentSignaturePayload. */
export async function buildSolanaPaymentSignaturePayload(
    accept: X402Accept,
    opts: MultichainSignOptions
): Promise<PaymentSignaturePayload> {
    if (!opts.wallet || !opts.connection) {
        throw new Error('Solana payment requires wallet + connection.');
    }
    return signExactSvmPayment(accept, {
        wallet: opts.wallet,
        connection: opts.connection,
        computeUnitLimit: opts.computeUnitLimit,
        computeUnitPriceMicroLamports: opts.computeUnitPriceMicroLamports,
    });
}

export function encodePaymentSignatureHeader(payload: Record<string, unknown>): string {
    return b64encodeJson(payload);
}
