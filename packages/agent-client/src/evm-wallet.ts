import { privateKeyToAccount } from 'viem/accounts';
import type { EvmWalletLike } from './multichain-sign.js';

/**
 * Build an EarnFi-compatible EVM wallet from a hex private key (Base pay-in, etc.).
 */
export function createEvmWalletFromPrivateKey(privateKey: `0x${string}`): EvmWalletLike {
    const account = privateKeyToAccount(privateKey);
    return {
        address: account.address,
        signTypedData: (args) =>
            account.signTypedData({
                domain: args.domain as Parameters<typeof account.signTypedData>[0]['domain'],
                types: args.types as Parameters<typeof account.signTypedData>[0]['types'],
                primaryType: args.primaryType as Parameters<typeof account.signTypedData>[0]['primaryType'],
                message: args.message as Parameters<typeof account.signTypedData>[0]['message'],
            }),
    };
}
