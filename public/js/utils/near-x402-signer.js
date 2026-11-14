/**
 * NEAR x402 exact payments (NEP-366) via @fastnear/wallet + @fastnear/x402 (lazy-loaded).
 */

function payErr(key, fallback) {
    return window.earnfiPaymentError?.(key, fallback)
        ?? window.EARNFI_COPY?.payments?.errors?.[key]
        ?? fallback;
}

/** @type {Promise<object> | null} */
let modulesPromise = null;
/** @type {Promise<{ http: object }> | null} */
let sessionPromise = null;
/** @type {object|null} */
let lastPaymentPayload = null;

async function loadNearPayModules() {
    if (!modulesPromise) {
        modulesPromise = Promise.all([
            import('@fastnear/wallet'),
            import('@fastnear/x402'),
            import('@x402/core/client'),
        ]).then(([nearWallet, x402, core]) => ({
            nearWallet,
            createFastNearWalletSigner: x402.createFastNearWalletSigner,
            createNearX402Client: x402.createNearX402Client,
            x402HTTPClient: core.x402HTTPClient,
        }));
    }
    return modulesPromise;
}

function nearNetworkId(caip2) {
    const n = String(caip2 || 'near:mainnet');
    return n.includes('testnet') ? 'testnet' : 'mainnet';
}

function nearCaip2(caip2) {
    const n = String(caip2 || 'near:mainnet');
    return n.startsWith('near:') ? n : 'near:mainnet';
}

const NEAR_ACCOUNT_STORAGE_KEY = 'earnfi_near_account_id';

function persistNearAccountId(accountId) {
    try {
        if (accountId) {
            localStorage.setItem(NEAR_ACCOUNT_STORAGE_KEY, String(accountId));
        } else {
            localStorage.removeItem(NEAR_ACCOUNT_STORAGE_KEY);
        }
    } catch (_) {
        /* ignore */
    }
}

function getConnectedNearAccountId() {
    try {
        return localStorage.getItem(NEAR_ACCOUNT_STORAGE_KEY) || '';
    } catch (_) {
        return '';
    }
}

function isNearSigningAllowed() {
    if (window.growlConfig?.nearX402Enabled) {
        return true;
    }
    if (window.growlConfig?.nearSignEnabled) {
        return true;
    }
    try {
        const pref = localStorage.getItem('earnfi_x402_payment_network') || '';
        return pref.startsWith('near:');
    } catch (_) {
        return false;
    }
}

function maxNearUsd() {
    const fromCfg = window.growlConfig?.nearX402MaxUsd;
    if (typeof fromCfg === 'number' && fromCfg > 0) {
        return fromCfg;
    }
    const fromSettings = parseFloat(window.growlConfig?.settings?.near_x402_max_usd);
    return Number.isFinite(fromSettings) && fromSettings > 0 ? fromSettings : 0;
}

function assertWithinMaxUsd(requirements) {
    const maxUsd = maxNearUsd();
    if (!(maxUsd > 0)) {
        return;
    }
    const atomic = String(requirements?.amount ?? requirements?.maxAmountRequired ?? '0');
    if (!/^[1-9]\d*$/.test(atomic)) {
        return;
    }
    const usd = Number(atomic) / 1e6;
    if (usd > maxUsd + 1e-9) {
        throw new Error(payErr('amountOverNearLimit', `Try a smaller amount or pay with Solana (limit $${maxUsd.toFixed(2)}).`));
    }
}

function normalizeAccept(requirements) {
    const accept = { ...(requirements || {}) };
    if (!accept.network) {
        accept.network = 'near:mainnet';
    }
    if (!accept.scheme) {
        accept.scheme = 'exact';
    }
    const amt = accept.amount ?? accept.maxAmountRequired;
    if (amt != null && amt !== '') {
        accept.amount = String(amt);
    }
    delete accept.maxAmountRequired;
    return accept;
}

function paymentRequiredFromRequirements(requirements, quoteContext) {
    const accept = normalizeAccept(requirements);
    const body = quoteContext && typeof quoteContext === 'object' ? quoteContext : {};
    const paymentRequired = {
        x402Version: body.x402Version || 2,
        accepts: [accept],
    };
    if (body.resource) {
        paymentRequired.resource = body.resource;
    }
    if (body.extensions) {
        paymentRequired.extensions = body.extensions;
    }
    return paymentRequired;
}

/** Map EarnFi UI ids → @fastnear/near-connect manifest ids. */
function resolveNearWalletId(preferredId) {
    const id = String(preferredId || '').trim().toLowerCase();
    if (!id || id === 'near_wallets' || id === 'near-wallets') {
        return '';
    }
    const map = {
        meteor: 'meteor-wallet',
        'meteor-wallet': 'meteor-wallet',
        hot: 'hot-wallet',
        'hot-wallet': 'hot-wallet',
        near_mobile: 'near-mobile',
        'near-mobile': 'near-mobile',
        mynearwallet: 'mynearwallet',
        intear: 'intear-wallet',
        'intear-wallet': 'intear-wallet',
    };
    return map[id] || id;
}

/**
 * Connect for identity / payment. Do not require delegate features here —
 * that filtered Meteor/HOT out of the picker on some hosts. Delegate capability
 * is enforced at payment sign time instead.
 * @param {string} [networkCaip2]
 * @param {{ walletId?: string }} [opts]
 */
async function connectNearWallet(networkCaip2 = 'near:mainnet', opts = {}) {
    const network = nearNetworkId(networkCaip2);
    const mods = await loadNearPayModules();
    const walletId = resolveNearWalletId(opts.walletId || opts.preferredId || '');
    const connectOpts = {
        network,
        // signMessage keeps NEP-413 auth wallets visible (MATCHFLOW-style picker).
        features: { signMessage: true },
    };
    if (walletId) {
        connectOpts.walletId = walletId;
    }
    let connection;
    try {
        connection = await mods.nearWallet.connect(connectOpts);
    } catch (e) {
        const msg = String(e?.message || e || '').trim();
        throw new Error(msg || payErr('nearWalletMissing', 'Open your NEAR wallet and connect, then try again.'));
    }
    if (!connection?.accountId) {
        throw new Error(payErr('nearWalletMissing', 'Open your NEAR wallet and connect, then try again.'));
    }
    persistNearAccountId(connection.accountId);
    sessionPromise = null;
    return { accountId: connection.accountId, publicKey: connection.publicKey || '', walletId: walletId || '' };
}

async function ensureNearX402Session(networkCaip2) {
    const network = nearNetworkId(networkCaip2);
    const caip2 = nearCaip2(networkCaip2);
    const mods = await loadNearPayModules();
    if (!sessionPromise) {
        sessionPromise = (async () => {
            let accountId = getConnectedNearAccountId();
            if (!accountId) {
                // Prefer already-connected session; otherwise open picker without
                // over-filtering. Payment still needs a wallet that can sign delegates.
                const connection = await connectNearWallet(caip2);
                accountId = connection.accountId;
            }
            const signer = mods.createFastNearWalletSigner({ wallet: mods.nearWallet });
            const client = mods.createNearX402Client({ signer, network: caip2 });
            return { http: new mods.x402HTTPClient(client), accountId };
        })().catch((err) => {
            sessionPromise = null;
            throw err;
        });
    }
    return sessionPromise;
}

/**
 * @param {object} requirements x402 accept / requirements
 * @param {object} [quoteContext] optional 402 body (resource, extensions)
 * @returns {Promise<string>} base64 signedDelegateAction for PAYMENT-SIGNATURE
 */
async function signNearX402(requirements, quoteContext) {
    if (!isNearSigningAllowed()) {
        throw new Error(payErr('networkUnsupported', 'Choose Solana or another payment option.'));
    }
    assertWithinMaxUsd(requirements);
    const network = nearCaip2(requirements?.network);
    const { http } = await ensureNearX402Session(network);
    const paymentRequired = paymentRequiredFromRequirements(requirements, quoteContext);
    const payload = await http.createPaymentPayload(paymentRequired);
    lastPaymentPayload = payload;
    const delegate = payload?.payload?.signedDelegateAction;
    if (typeof delegate !== 'string' || delegate === '') {
        throw new Error(payErr('paymentNotCompleted', 'Approve the payment in your NEAR wallet, then try again.'));
    }
    return delegate;
}

async function signNearX402Payload(requirements, quoteContext) {
    await signNearX402(requirements, quoteContext);
    return lastPaymentPayload;
}

window.EarnfiNearX402Signer = {
    sign: signNearX402,
    signPayload: signNearX402Payload,
    connect: connectNearWallet,
    resolveWalletId: resolveNearWalletId,
    listWallets: async (networkCaip2 = 'near:mainnet') => {
        const network = nearNetworkId(networkCaip2);
        const mods = await loadNearPayModules();
        if (typeof mods.nearWallet.availableWallets !== 'function') {
            return [];
        }
        try {
            const manifests = await mods.nearWallet.availableWallets({
                network,
                features: { signMessage: true },
            });
            return Array.isArray(manifests) ? manifests : [];
        } catch (_) {
            return [];
        }
    },
    getAccountId: getConnectedNearAccountId,
    disconnect: async () => {
        sessionPromise = null;
        lastPaymentPayload = null;
        persistNearAccountId('');
        try {
            const mods = await loadNearPayModules();
            await mods.nearWallet.disconnect?.({ network: 'mainnet' });
        } catch (_) { /* ignore */ }
    },
    resetSession: () => {
        sessionPromise = null;
        lastPaymentPayload = null;
        modulesPromise = null;
    },
};
export default window.EarnfiNearX402Signer;
