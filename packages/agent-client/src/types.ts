import type { Connection, PublicKey, Transaction, VersionedTransaction } from '@solana/web3.js';

export const EARNFI_DEFAULT_API_BASE = 'https://app.earnfi.fun/api/ai-agent/v1';

/** x402 exact-SVM facilitator limits per EarnFi skill.md */
export const X402_COMPUTE_UNIT_LIMIT = 40_000;
export const X402_COMPUTE_UNIT_PRICE_MICRO_LAMPORTS = 5n;

export type WalletLike = {
    publicKey: PublicKey;
    signTransaction: (tx: Transaction | VersionedTransaction) => Promise<Transaction | VersionedTransaction>;
};

export type X402Accept = {
    scheme: 'exact';
    network: string;
    amount: string;
    payTo: string;
    asset: string;
    extra?: {
        feePayer?: string;
        tokenDecimals?: number;
    };
    [k: string]: unknown;
};

export type X402Challenge = {
    x402Version: number;
    resource: { url: string; description?: string; mimeType?: string };
    accepts: X402Accept[];
};

export type PaymentSignaturePayload = {
    signed_tx: string;
    requirements: X402Accept;
};

export type JsonResponse = { status: number; headers: Headers; json: unknown };

export type X402Response = JsonResponse & { paymentRequired?: X402Challenge };

export type JobAuth = {
    secret?: string;
    /** Override client agentToken for this request */
    agentToken?: string;
};

export type AgentClientOptions = {
    /** Defaults to {@link EARNFI_DEFAULT_API_BASE} */
    baseUrl?: string;
    agentToken?: string;
    /**
     * When the 402 lists multiple accepts[], pick the one matching this CAIP-2 id or prefix
     * (e.g. `solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp`, `near:mainnet`, `eip155:8453`).
     * Default: first Solana accept, else accepts[0].
     */
    paymentNetwork?: string;
    /** Required for Solana x402 pay-in */
    wallet?: WalletLike;
    /** Required for Solana x402 pay-in */
    connection?: Connection;
    /** Required for Base / EVM x402 pay-in (EIP-3009 via @x402/evm) */
    evmWallet?: import('./multichain-sign.js').EvmWalletLike;
    /** Optional NEAR module loader override (defaults to @fastnear/x402 + @fastnear/wallet) */
    near?: import('./multichain-sign.js').NearX402SignContext;
    fetchImpl?: typeof fetch;
    /** Send Agent-Token header (default true); set false to use ?agent_token= query instead */
    preferAgentTokenHeader?: boolean;
    /** Skip USDC balance/ATA preflight before signing payment tx */
    skipPaymentPreflight?: boolean;
};

export type TokenGateInput = string | Record<string, unknown>;
