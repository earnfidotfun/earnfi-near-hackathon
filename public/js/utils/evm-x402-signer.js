/**
 * EVM x402 exact (EIP-3009) — Base mainnet via @x402/evm + injected wallet.
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

async function loadEvmPayModules() {
    if (!modulesPromise) {
        modulesPromise = Promise.all([
            import('@x402/core/client'),
            import('@x402/evm/exact/client'),
            import('@x402/evm'),
            import('viem'),
            import('viem/chains'),
            import('./base-evm-wallet.js'),
        ]).then(([core, evmExact, evm, viem, chains, baseWallet]) => ({
            x402Client: core.x402Client,
            x402HTTPClient: core.x402HTTPClient,
            ExactEvmScheme: evmExact.ExactEvmScheme,
            toClientEvmSigner: evm.toClientEvmSigner,
            createWalletClient: viem.createWalletClient,
            custom: viem.custom,
            base: chains.base,
            baseWallet: baseWallet.default || baseWallet,
        }));
    }
    return modulesPromise;
}

function isBaseNetwork(network) {
    const n = String(network || '');
    return n === 'eip155:8453' || n === 'eip155:84532';
}

function isEvmSigningAllowed(network) {
    if (!String(network || '').startsWith('eip155:')) {
        return false;
    }
    if (isBaseNetwork(network)) {
        if (window.growlConfig?.baseX402Enabled || window.growlConfig?.baseSignEnabled) {
            return true;
        }
        try {
            const pref = localStorage.getItem('earnfi_x402_payment_network') || '';
            return pref.startsWith('eip155:8453');
        } catch (_) {
            return false;
        }
    }
    if (window.growlConfig?.okxRailEnabled && window.earnfiOkxX402Sign) {
        return true;
    }
    return false;
}

function assertEvmMaxUsd(requirements, network) {
    if (!isBaseNetwork(network)) {
        return;
    }
    if (!isEvmSigningAllowed(network)) {
        return;
    }
    const maxUsd = typeof window.growlConfig?.baseX402MaxUsd === 'number'
        ? window.growlConfig.baseX402MaxUsd
        : parseFloat(window.growlConfig?.settings?.base_x402_max_usd);
    if (!(Number.isFinite(maxUsd) && maxUsd > 0)) {
        return;
    }
    const atomic = String(requirements?.amount ?? requirements?.maxAmountRequired ?? '0');
    if (!/^[1-9]\d*$/.test(atomic)) {
        return;
    }
    const usd = Number(atomic) / 1e6;
    if (usd > maxUsd + 1e-9) {
        throw new Error(payErr('amountOverBaseLimit', `Try a smaller amount or pay with Solana (limit $${maxUsd.toFixed(2)}).`));
    }
}

function normalizeAccept(requirements) {
    const accept = { ...(requirements || {}) };
    if (!accept.scheme) {
        accept.scheme = 'exact';
    }
    const amt = accept.amount ?? accept.maxAmountRequired;
    if (amt != null && amt !== '') {
        accept.amount = String(amt);
    }
    delete accept.maxAmountRequired;
    if (!accept.extra || typeof accept.extra !== 'object') {
        accept.extra = {};
    }
    if (isBaseNetwork(accept.network) && !accept.extra.name && !accept.extra.version) {
        accept.extra = {
            ...accept.extra,
            name: 'USD Coin',
            version: '2',
            assetTransferMethod: accept.extra.assetTransferMethod || 'eip3009',
        };
    }
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
    } else if (accept.extra?.resourceUrl) {
        paymentRequired.resource = { url: accept.extra.resourceUrl };
    }
    if (body.extensions) {
        paymentRequired.extensions = body.extensions;
    }
    return paymentRequired;
}

async function ensureEvmX402Session(networkCaip2) {
    const network = String(networkCaip2 || 'eip155:8453');
    const mods = await loadEvmPayModules();
    if (!sessionPromise) {
        sessionPromise = (async () => {
            const { provider, address } = await mods.baseWallet.getSession();
            const walletClient = mods.createWalletClient({
                chain: mods.base,
                transport: mods.custom(provider),
            });
            const clientSigner = mods.toClientEvmSigner({
                address,
                signTypedData: (msg) => walletClient.signTypedData({
                    account: address,
                    ...msg,
                }),
            });
            const core = new mods.x402Client().register(
                network.startsWith('eip155:') ? 'eip155:*' : network,
                new mods.ExactEvmScheme(clientSigner),
            );
            return { http: new mods.x402HTTPClient(core), address };
        })().catch((err) => {
            sessionPromise = null;
            throw err;
        });
    }
    return sessionPromise;
}

/**
 * @returns {Promise<object>} Full x402 v2 body for PAYMENT-SIGNATURE (not a Solana tx string).
 */
async function signEvmX402PaymentBody(requirements, quoteContext) {
    const network = String(requirements?.network || '');
    if (!network.startsWith('eip155:')) {
        throw new Error(payErr('missingQuote', 'Request payment again from the previous step.'));
    }
    if (window.growlConfig?.okxRailEnabled && window.earnfiOkxX402Sign && network === 'eip155:196') {
        const legacy = await window.earnfiOkxX402Sign(requirements);
        return legacy;
    }
    if (!isEvmSigningAllowed(network)) {
        throw new Error(payErr('networkUnsupported', 'Choose Solana or another payment option.'));
    }
    assertEvmMaxUsd(requirements, network);
    const { http } = await ensureEvmX402Session(network);
    const paymentRequired = paymentRequiredFromRequirements(requirements, quoteContext);
    const payload = await http.createPaymentPayload(paymentRequired);
    lastPaymentPayload = payload;
    if (!payload?.payload?.authorization || !payload?.payload?.signature) {
        throw new Error(payErr('paymentNotCompleted', 'Approve the payment in your wallet, then try again.'));
    }
    return payload;
}

async function signEvmX402(requirements, quoteContext) {
    return signEvmX402PaymentBody(requirements, quoteContext);
}

async function signEvmX402Payload(requirements, quoteContext) {
    return signEvmX402PaymentBody(requirements, quoteContext);
}

window.EarnfiEvmX402Signer = {
    sign: signEvmX402,
    signPayload: signEvmX402Payload,
    resetSession: () => {
        sessionPromise = null;
        lastPaymentPayload = null;
        modulesPromise = null;
    },
};

export default window.EarnfiEvmX402Signer;
