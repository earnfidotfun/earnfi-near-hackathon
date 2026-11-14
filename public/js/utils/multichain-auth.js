/**
 * Base (EIP-191) and NEAR (NEP-413) human sign-in via /auth/wallet/challenge + /auth/chain-wallet.
 */

function apiBase() {
    return (window.growlConfig?.apiUrl || '/wp-json/growl/v1/').replace(/\/?$/, '/');
}

async function fetchChallenge(chainCaip2, walletAddress = '', purpose = 'login') {
    const res = await fetch(`${apiBase()}auth/wallet/challenge`, {
        method: 'POST',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            ...(window.growlConfig?.nonce ? { 'X-WP-Nonce': window.growlConfig.nonce } : {}),
        },
        body: JSON.stringify({
            chain_caip2: chainCaip2,
            wallet_address: walletAddress || undefined,
            purpose,
        }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.success || !data?.challenge) {
        throw new Error(data?.message || 'Could not prepare sign-in.');
    }
    return data.challenge;
}

async function completeChainAuth(payload) {
    const res = await fetch(`${apiBase()}auth/chain-wallet`, {
        method: 'POST',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            ...(window.growlConfig?.nonce ? { 'X-WP-Nonce': window.growlConfig.nonce } : {}),
        },
        body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.success) {
        throw new Error(data?.message || 'Sign-in failed.');
    }
    return data;
}

async function signInWithBaseEvm(options = {}) {
    const chain = 'eip155:8453';
    const evm = window.EarnfiBaseEvmWallet;
    if (!evm?.signPersonalMessage) {
        throw new Error('Base wallet helper is not loaded.');
    }
    let address = String(options.address || evm.getAddress?.() || '').trim();
    if (!address) {
        if (!evm.connect) {
            throw new Error('Base wallet helper is not loaded.');
        }
        const conn = await evm.connect();
        address = String(conn?.address || evm.getAddress?.() || '').trim();
    }
    if (!address) {
        throw new Error('Connect a Base wallet to continue.');
    }
    const challenge = await fetchChallenge(chain, address, 'login');
    const message = challenge.message;
    const signature = await evm.signPersonalMessage(message);
    return completeChainAuth({
        chain_caip2: chain,
        challenge_id: challenge.id,
        wallet_address: address,
        message,
        signature,
    });
}

async function signInWithNear(options = {}) {
    const chain = 'near:mainnet';
    const nearWallet = await import('@fastnear/wallet');
    let accountId = String(
        options.accountId
            || nearWallet.accountId?.({ network: 'mainnet' })
            || getConnectedNearAccountId()
            || '',
    ).trim();
    if (!accountId) {
        // Prefer EarnFi signer so UI walletId (Meteor / HOT / NEAR Mobile) is honored.
        if (window.EarnfiNearX402Signer?.connect) {
            const connection = await window.EarnfiNearX402Signer.connect('near:mainnet', {
                walletId: options.walletId || '',
            });
            accountId = String(connection?.accountId || '').trim();
        } else {
            const connection = await nearWallet.connect({
                network: 'mainnet',
                features: { signMessage: true },
                ...(options.walletId ? { walletId: options.walletId } : {}),
            });
            accountId = String(connection?.accountId || nearWallet.accountId?.({ network: 'mainnet' }) || '').trim();
        }
    }
    if (!accountId) {
        throw new Error('Connect a NEAR wallet to continue.');
    }
    const challenge = await fetchChallenge(chain, accountId, 'login');
    const nep = challenge.nep413 || {};
    const message = nep.message || challenge.message;
    const recipient = nep.recipient || challenge.domain;
    const nonceHex = nep.nonce_hex;
    if (!nonceHex || nonceHex.length !== 64) {
        throw new Error('Invalid NEAR sign-in challenge.');
    }
    const nonce = Uint8Array.from(nonceHex.match(/.{1,2}/g).map((b) => parseInt(b, 16)));
    const signed = await nearWallet.signMessage({
        message,
        recipient,
        nonce,
        network: 'mainnet',
        signerId: accountId,
    });
    return completeChainAuth({
        chain_caip2: chain,
        challenge_id: challenge.id,
        wallet_address: accountId,
        message,
        nep413: {
            message,
            recipient,
            nonce_hex: nonceHex,
            account_id: signed.accountId || accountId,
            public_key: signed.publicKey,
            signature: signed.signature,
        },
        signature: signed.signature,
    });
}

async function linkPaymentWallet(chainCaip2, walletAddress, proof) {
    return completeChainAuth({
        purpose: 'link_payment',
        chain_caip2: chainCaip2,
        wallet_address: walletAddress,
        ...proof,
    });
}

async function linkBasePaymentWallet() {
    const chain = 'eip155:8453';
    const evm = window.EarnfiBaseEvmWallet;
    const address = evm?.getAddress?.() || (await evm.connect())?.address;
    if (!address) {
        throw new Error('Connect Base wallet first.');
    }
    const challenge = await fetchChallenge(chain, address, 'link_payment');
    const message = challenge.message;
    const signature = await evm.signPersonalMessage(message);
    return linkPaymentWallet(chain, address, {
        challenge_id: challenge.id,
        message,
        signature,
    });
}

async function linkNearPaymentWallet() {
    const chain = 'near:mainnet';
    const nearWallet = await import('@fastnear/wallet');
    const accountId = nearWallet.accountId?.({ network: 'mainnet' }) || getConnectedNearAccountId();
    if (!accountId) {
        throw new Error('Connect NEAR wallet first.');
    }
    const challenge = await fetchChallenge(chain, accountId, 'link_payment');
    const nep = challenge.nep413 || {};
    const message = nep.message || challenge.message;
    const recipient = nep.recipient || challenge.domain;
    const nonceHex = nep.nonce_hex;
    const nonce = Uint8Array.from(nonceHex.match(/.{1,2}/g).map((b) => parseInt(b, 16)));
    const signed = await nearWallet.signMessage({
        message,
        recipient,
        nonce,
        network: 'mainnet',
        signerId: accountId,
    });
    return linkPaymentWallet(chain, accountId, {
        challenge_id: challenge.id,
        message,
        nep413: {
            message,
            recipient,
            nonce_hex: nonceHex,
            account_id: signed.accountId || accountId,
            public_key: signed.publicKey,
            signature: signed.signature,
        },
        signature: signed.signature,
    });
}

function getConnectedNearAccountId() {
    try {
        return localStorage.getItem('earnfi_near_account_id') || '';
    } catch (_) {
        return '';
    }
}

export const EarnfiMultichainAuth = {
    fetchChallenge,
    completeChainAuth,
    signInWithBaseEvm,
    signInWithNear,
    linkPaymentWallet,
    linkBasePaymentWallet,
    linkNearPaymentWallet,
};

if (typeof window !== 'undefined') {
    window.EarnfiMultichainAuth = EarnfiMultichainAuth;
}
