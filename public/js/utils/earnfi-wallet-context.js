/**
 * EarnFi wallet entry context — Solana-first on Seeker only; other mobiles stay multichain.
 */
const SOLANA_MAINNET = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

let cachedNetworksPayload = null;
let networksFetchPromise = null;

function earnfiIsSeekerUa() {
    try {
        const ua = typeof navigator !== 'undefined' ? String(navigator.userAgent || '') : '';
        return /Seeker|Solana Mobile|Seed Vault/i.test(ua);
    } catch (_) {
        return false;
    }
}

/**
 * Solana-only UX: Seeker browser / Seeker native app only.
 * Generic mobile Chrome + MWA registration must stay multichain.
 */
function earnfiDetectSeekerOrMobileSolana() {
    try {
        if (window.__EARNFI_SOLANA_NATIVE__ === true) {
            return true;
        }
        if (earnfiIsSeekerUa()) {
            return true;
        }
        const isCapNative = !!(
            document.documentElement?.classList?.contains('earnfi-capacitor-native')
            || window.Capacitor?.isNativePlatform?.()
        );
        // Native EarnFi Seeker build only — not every Capacitor session.
        if (isCapNative && (window.__EARNFI_SEEKER_APP__ === true || earnfiIsSeekerUa())) {
            return true;
        }
    } catch (_) {
        /* ignore */
    }
    return false;
}

function earnfiIsSolanaNativeContext() {
    return earnfiDetectSeekerOrMobileSolana();
}

function earnfiResolveWalletContext() {
    if (earnfiIsSolanaNativeContext()) {
        const isCapNative = !!(
            window.Capacitor?.isNativePlatform?.()
            || document.documentElement?.classList?.contains('earnfi-capacitor-native')
        );
        return isCapNative ? 'seeker_mobile' : 'solana_native';
    }
    return 'multichain';
}

function paymentTileCopy() {
    return window.EARNFI_COPY?.payments || {};
}

function settingTruthy(settings, key) {
    if (!settings || typeof settings !== 'object') {
        return false;
    }
    const v = settings[key];
    return v === true || v === 1 || v === '1' || v === 'true';
}

function configFlagOn(cfg, camelKey, settingsKeys) {
    if (cfg[camelKey] === true || cfg[camelKey] === 1 || cfg[camelKey] === '1') {
        return true;
    }
    const s = cfg.settings || {};
    return (settingsKeys || []).some((k) => settingTruthy(s, k));
}

/**
 * NEAR tile: require admin enable flag(s). Treasury alone does not force-show when rail is off.
 */
function nearEnabledFromConfig(cfg) {
    if (configFlagOn(cfg, 'nearX402Enabled', []) || configFlagOn(cfg, 'nearSignEnabled', [])) {
        return true;
    }
    if (cfg.nearX402Enabled === false && cfg.nearSignEnabled === false) {
        return false;
    }
    const s = cfg.settings || {};
    const flagged = (
        settingTruthy(s, 'near_x402_enabled')
        || settingTruthy(s, 'near_x402_gate_b_ok')
        || settingTruthy(s, 'near_rail_enabled')
    );
    if (!flagged) {
        return false;
    }
    // Treasury may be omitted from public settings; flags alone unlock connect UI.
    return true;
}

/**
 * Base tile: require admin enable flag(s). Pay-to alone does not force-show when rail is off.
 */
function baseEnabledFromConfig(cfg) {
    // Top-level growlConfig flags come from PHP catalog (admin source of truth).
    if (configFlagOn(cfg, 'baseX402Enabled', []) || configFlagOn(cfg, 'baseSignEnabled', [])) {
        return true;
    }
    if (cfg.baseX402Enabled === false && cfg.baseSignEnabled === false) {
        return false;
    }
    const s = cfg.settings || {};
    const flagged = (
        settingTruthy(s, 'base_x402_enabled')
        || settingTruthy(s, 'base_x402_gate_b_ok')
        || settingTruthy(s, 'base_rail_enabled')
    );
    if (!flagged) {
        return false;
    }
    const payTo = String(s.base_x402_pay_to || '').trim();
    // Pay-to may be omitted from public settings; flags alone are enough for connect UI.
    return payTo === '' || /^0x[a-fA-F0-9]{40}$/.test(payTo);
}

function defaultNetworkTiles() {
    const cfg = window.growlConfig || {};
    const t = paymentTileCopy();
    const nearOn = nearEnabledFromConfig(cfg);
    const baseOn = baseEnabledFromConfig(cfg);
    const networks = [
        {
            id: 'solana',
            caip2: SOLANA_MAINNET,
            label: 'Solana',
            description: t.tileSolana || '',
            default: true,
            enabled: true,
            connectKind: 'solana_wallet',
            authEnabled: true,
            logo: 'https://solana.com/src/img/branding/solanaLogoMark.svg',
        },
        {
            id: 'evm_okx',
            caip2: 'eip155:196',
            label: 'OKX X Layer',
            description: t.tileOkx || '',
            default: false,
            enabled: false,
            connectKind: 'evm_agent_rail',
            authEnabled: false,
            logo: 'https://static.okx.com/cdn/assets/imgs/247/58E63FEA47EB7D55.png',
        },
        {
            id: 'near',
            caip2: 'near:mainnet',
            label: 'NEAR',
            description: t.tileNear || '',
            default: false,
            enabled: nearOn,
            connectKind: 'near_wallet',
            authEnabled: nearOn,
            logo: 'https://cryptologos.cc/logos/near-protocol-near-logo.svg',
        },
        {
            id: 'base',
            caip2: 'eip155:8453',
            label: 'Base',
            description: t.tileBase || '',
            default: false,
            enabled: baseOn,
            connectKind: 'evm_base_wallet',
            authEnabled: baseOn,
            logo: 'https://avatars.githubusercontent.com/u/108554348?s=200&v=4',
        },
    ];
    return networks;
}

/**
 * Merge API /networks with local defaults.
 * When /networks has loaded, that payload is admin source of truth for Base/NEAR.
 * Local config only fills defaults before the first successful fetch.
 */
function mergeNetworkTiles(apiNetworks) {
    const defaults = defaultNetworkTiles();
    const byId = new Map(defaults.map((n) => [n.id, { ...n }]));
    const fromApi = new Set();
    if (Array.isArray(apiNetworks)) {
        for (const raw of apiNetworks) {
            if (!raw || !raw.id) {
                continue;
            }
            fromApi.add(raw.id);
            const prev = byId.get(raw.id) || {};
            const connectKind = raw.connect_kind || raw.connectKind || prev.connectKind || 'solana_wallet';
            const apiEnabled = raw.enabled !== false;
            const apiAuth = raw.auth_enabled != null
                ? raw.auth_enabled !== false && connectKind !== 'evm_agent_rail'
                : prev.authEnabled !== false;
            byId.set(raw.id, {
                ...prev,
                id: raw.id,
                caip2: raw.caip2 || prev.caip2,
                label: raw.label || prev.label,
                description: '',
                default: raw.default != null ? !!raw.default : !!prev.default,
                enabled: apiEnabled,
                connectKind,
                authEnabled: apiEnabled && apiAuth,
                logo: prev.logo || raw.logo || '',
            });
        }
    }
    // Rails not returned by admin catalog are off (do not resurrect from stale local defaults).
    for (const id of ['near', 'base']) {
        const tile = byId.get(id);
        if (!tile) continue;
        if (fromApi.size > 0 && !fromApi.has(id)) {
            tile.enabled = false;
            tile.authEnabled = false;
        }
    }
    const okx = byId.get('evm_okx');
    if (okx) {
        okx.enabled = false;
        okx.authEnabled = false;
    }
    return [...byId.values()];
}

function earnfiPaymentNetworks() {
    const stored = (() => {
        try {
            return localStorage.getItem('earnfi_x402_payment_network') || '';
        } catch (_) {
            return '';
        }
    })();
    const networks = cachedNetworksPayload?.networks?.length
        ? mergeNetworkTiles(cachedNetworksPayload.networks)
        : defaultNetworkTiles();
    let selected = stored || cachedNetworksPayload?.default_caip2 || SOLANA_MAINNET;
    const enabled = networks.filter((n) => n.enabled !== false);
    if (!enabled.some((n) => n.caip2 === selected)) {
        selected = enabled.find((n) => n.default)?.caip2 || SOLANA_MAINNET;
    }
    if (earnfiIsSolanaNativeContext()) {
        selected = SOLANA_MAINNET;
    }
    return { networks, enabledNetworks: enabled, selectedCaip2: selected, context: earnfiResolveWalletContext() };
}

async function earnfiRefreshNetworksFromApi() {
    if (networksFetchPromise) {
        return networksFetchPromise;
    }
    const base = (window.growlConfig?.apiUrl || '').replace(/\/growl\/v1\/?$/, '');
    const url = base ? `${base}/growl/v1/networks` : '';
    if (!url) {
        return earnfiPaymentNetworks();
    }
    networksFetchPromise = fetch(url, { credentials: 'same-origin' })
        .then((r) => (r.ok ? r.json() : null))
        .then((json) => {
            if (json && Array.isArray(json.networks) && json.networks.length) {
                cachedNetworksPayload = {
                    default_caip2: json.default_caip2,
                    networks: json.networks,
                };
            }
            return earnfiPaymentNetworks();
        })
        .catch(() => earnfiPaymentNetworks())
        .finally(() => {
            networksFetchPromise = null;
        });
    return networksFetchPromise;
}

function earnfiSetPaymentNetwork(caip2) {
    if (earnfiIsSolanaNativeContext()) {
        caip2 = SOLANA_MAINNET;
    }
    try {
        localStorage.setItem('earnfi_x402_payment_network', String(caip2 || SOLANA_MAINNET));
    } catch (_) {
        /* ignore */
    }
}

function earnfiSelectedNetworkLabel(caip2) {
    const { networks } = earnfiPaymentNetworks();
    const hit = networks.find((n) => n.caip2 === caip2);
    return hit?.label || (String(caip2 || '').startsWith('solana:') ? 'Solana' : caip2);
}

function earnfiShouldShowNetworkStep(walletOnlyMode = false) {
    if (earnfiIsSolanaNativeContext()) {
        return false;
    }
    if (walletOnlyMode) {
        return earnfiPayNetworkTiles().length > 1;
    }
    return earnfiAuthNetworkTiles().length > 1;
}

/** @deprecated use earnfiShouldShowNetworkStep */
function earnfiShouldShowPaymentNetworkStep(walletOnlyMode) {
    if (!walletOnlyMode && earnfiAuthNetworkTiles().length <= 1) {
        return false;
    }
    return earnfiShouldShowNetworkStep();
}

function isEarnfiWalletModalNetwork(n) {
    if (!n || n.enabled === false) {
        return false;
    }
    if (n.connectKind === 'evm_agent_rail' || n.id === 'evm_okx') {
        return false;
    }
    return true;
}

function earnfiAuthNetworkTiles() {
    const { enabledNetworks } = earnfiPaymentNetworks();
    return enabledNetworks.filter(
        (n) => n.authEnabled !== false && isEarnfiWalletModalNetwork(n),
    );
}

function earnfiPayNetworkTiles() {
    const { enabledNetworks } = earnfiPaymentNetworks();
    return enabledNetworks.filter((n) => isEarnfiWalletModalNetwork(n));
}

function earnfiSelectedNetworkConnectKind(caip2) {
    const { networks } = earnfiPaymentNetworks();
    const hit = networks.find((n) => n.caip2 === caip2);
    return hit?.connectKind || 'solana_wallet';
}

const EarnfiWalletContext = {
    resolve: earnfiResolveWalletContext,
    isSolanaNative: earnfiIsSolanaNativeContext,
    isSeekerOrMobile: earnfiDetectSeekerOrMobileSolana,
    networks: earnfiPaymentNetworks,
    refreshNetworks: earnfiRefreshNetworksFromApi,
    setPaymentNetwork: earnfiSetPaymentNetwork,
    defaultNetwork: () => SOLANA_MAINNET,
    selectedNetworkLabel: earnfiSelectedNetworkLabel,
    shouldShowNetworkStep: earnfiShouldShowNetworkStep,
    shouldShowPaymentNetworkStep: earnfiShouldShowPaymentNetworkStep,
    authNetworkTiles: earnfiAuthNetworkTiles,
    payNetworkTiles: earnfiPayNetworkTiles,
    selectedNetworkConnectKind: earnfiSelectedNetworkConnectKind,
    nearEnabledFromConfig,
    baseEnabledFromConfig,
};

window.EarnfiWalletContext = EarnfiWalletContext;
export default EarnfiWalletContext;
