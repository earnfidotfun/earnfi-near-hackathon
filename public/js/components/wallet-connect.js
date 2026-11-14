// /wp-content/plugins/growl-fun/public/js/components/wallet-connect.js
const WalletConnectModal = {
    template: `
        <teleport to="body">
        <div v-if="visible" data-wallet-connect-modal class="fixed inset-0 z-[10000] overflow-y-auto overscroll-none"
            role="dialog" aria-modal="true" aria-labelledby="wallet-modal-title" @keydown.escape="handleClose">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm transition-opacity motion-reduce:backdrop-blur-none" aria-hidden="true" @click="handleClose"></div>
            <div class="flex min-h-full items-center justify-center p-4">
            <div :class="['relative rounded-2xl shadow-2xl w-full max-w-md max-h-[85vh] flex flex-col overflow-hidden',
                        store.theme === 'dark' ? 'bg-background border border-border' : 'bg-white border border-gray-200']"
                 @click.stop>
                <!-- Header -->
                <div class="flex-shrink-0 flex items-start justify-between gap-3 px-5 pt-5 pb-2">
                    <div class="min-w-0 pr-2">
                        <h3 id="wallet-modal-title" class="text-lg font-semibold tracking-tight text-foreground">{{ modalTitle }}</h3>
                        <p v-if="modalSubtitle" class="mt-1 text-sm text-muted-foreground leading-snug">{{ modalSubtitle }}</p>
                    </div>
                    <button type="button" @click="handleClose" aria-label="Close"
                            class="shrink-0 touch-manipulation transition-transform active:scale-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded-lg p-2 text-muted-foreground hover:text-foreground hover:bg-muted/60">
                        <i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i>
                    </button>
                </div>
                
                <!-- Content - Scrollable -->
                <div class="flex-1 overflow-y-auto overscroll-contain enhanced-scrollbar">
                    <div class="px-5 pb-5 pt-2">
                        <div v-if="!modalContentReady" class="space-y-4" aria-busy="true" aria-label="Loading">
                            <div v-if="showAuthExtras" class="space-y-3">
                                <div class="h-10 w-full rounded-lg bg-muted/50 animate-pulse"></div>
                                <div class="h-10 w-full max-w-[280px] mx-auto rounded-lg bg-muted/40 animate-pulse"></div>
                            </div>
                            <div v-if="showNetworkStep" class="flex gap-1 p-1 rounded-xl bg-muted/60">
                                <div v-for="i in 3" :key="'sk-n-'+i" class="h-[34px] w-[88px] rounded-lg bg-muted animate-pulse"></div>
                            </div>
                            <div class="space-y-2">
                                <div v-for="i in 3" :key="'sk-w-'+i" class="h-[52px] rounded-xl border border-border bg-muted/40 animate-pulse"></div>
                            </div>
                        </div>

                        <div v-else-if="!connecting && !connectionResult" class="space-y-4">

                            <!-- Referral + Google above network tabs (sign-in only) -->
                            <div v-if="showAuthExtras" class="space-y-3">
                                <div v-if="showReferralSection" class="rounded-xl border border-border overflow-hidden">
                                    <button type="button" @click="toggleReferralPanel"
                                        class="w-full flex items-center justify-between gap-2 px-3.5 py-3 text-left hover:bg-muted/40">
                                        <span class="text-sm font-semibold text-foreground">
                                            <span v-if="referralLocked">Referral applied</span>
                                            <span v-else-if="pendingReferralDisplay">Referral</span>
                                            <span v-else>Referral code</span>
                                        </span>
                                        <i :data-lucide="referralPanelOpen ? 'chevron-up' : 'chevron-down'" class="w-4 h-4 shrink-0 text-muted-foreground"></i>
                                    </button>
                                    <div v-show="referralPanelOpen" class="px-3.5 pb-3 space-y-2 border-t border-border">
                                        <div v-if="referralLocked" class="rounded-lg px-3 py-2 mt-2 bg-muted/50">
                                            <div class="text-xs text-muted-foreground">Code</div>
                                            <div class="text-sm font-mono font-semibold text-foreground">{{ pendingReferralDisplay }}</div>
                                        </div>
                                        <div v-else class="space-y-1.5 mt-2">
                                            <button v-if="!referralExpanded && !pendingReferralDisplay" type="button" @click="expandReferralEntry"
                                                class="text-sm font-semibold text-primary hover:underline">
                                                Add code
                                            </button>
                                            <template v-else>
                                                <label class="text-xs text-muted-foreground">Code</label>
                                                <input v-model="manualReferralInput" @input="persistManualReferral" type="text" autocomplete="off" maxlength="32"
                                                    class="w-full rounded-lg px-3 py-2.5 text-sm border border-border bg-background text-foreground"
                                                    placeholder="Friend’s code" />
                                                <button v-if="referralExpanded" type="button" @click="collapseManualReferral"
                                                    class="text-xs text-muted-foreground hover:text-foreground">
                                                    Clear
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <button
                                    v-if="!showReferralSection && !store.user"
                                    type="button"
                                    @click="expandReferralEntry"
                                    class="w-full text-center text-sm font-bold text-foreground hover:text-primary"
                                >
                                    Have a referral code?
                                </button>

                                <div v-if="showGoogleSignIn" class="w-full flex justify-center">
                                    <div ref="googleBtnContainer" class="w-full flex justify-center min-h-[40px]"></div>
                                </div>

                                <div v-if="showAuthWalletDivider" class="flex items-center gap-3">
                                    <div class="h-px flex-1 bg-border"></div>
                                    <div class="text-xs font-semibold text-muted-foreground">or</div>
                                    <div class="h-px flex-1 bg-border"></div>
                                </div>
                            </div>

                            <div v-if="showNetworkStep" class="min-w-0">
                                <p v-if="networkTilesForModal.length === 0" class="text-sm text-muted-foreground">{{ paymentsCopy.networkEmpty }}</p>
                                <div v-else role="tablist" aria-label="Network"
                                    class="earnfi-carousel-fade flex flex-nowrap items-center gap-1 p-1 rounded-xl overflow-x-auto overscroll-x-contain [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                                    :class="store.theme === 'dark' ? 'bg-muted/50' : 'bg-muted/80'">
                                    <button v-for="n in networkTilesForModal" :key="n.caip2" type="button" role="tab"
                                        @click="selectPaymentNetwork(n.caip2)"
                                        :disabled="n.enabled === false"
                                        :aria-selected="selectedPaymentNetwork === n.caip2"
                                        class="inline-flex items-center gap-1.5 shrink-0 min-h-[34px] px-2.5 rounded-lg text-xs font-semibold touch-manipulation transition-colors duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40"
                                        :class="selectedPaymentNetwork === n.caip2
                                            ? 'bg-foreground text-background shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground hover:bg-background/60'">
                                        <img v-if="networkLogo(n)" :src="networkLogo(n)" alt=""
                                            class="w-3.5 h-3.5 object-contain shrink-0 opacity-90"
                                            :class="networkLogoClass(n)"
                                            @error="$event.target.style.display='none'" />
                                        <span class="whitespace-nowrap">{{ n.label }}</span>
                                    </button>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div v-if="selectedNetworkIsBase" class="space-y-2">
                                    <button v-for="w in baseWalletOptions" :key="w.id" type="button"
                                        @click="connectBaseEvmForPayment(w.id)"
                                        :disabled="baseEvmConnecting"
                                        class="w-full min-h-[52px] flex items-center justify-between gap-3 px-3.5 py-2.5 border rounded-xl touch-manipulation transition-[transform,border-color,background-color] duration-150 ease-out active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                                        :class="store.theme === 'dark' ? 'border-border hover:border-primary/50 hover:bg-muted/40' : 'border-gray-200 hover:border-primary/40 hover:bg-gray-50'">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="w-8 h-8 flex items-center justify-center shrink-0 rounded-lg bg-muted/60 overflow-hidden">
                                                <img v-if="w.logo" :src="w.logo" alt="" class="w-5 h-5 object-contain"
                                                    @error="$event.target.style.display='none'" />
                                                <i v-else-if="w.isInstalledPicker" data-lucide="smartphone" class="w-5 h-5 opacity-90"></i>
                                                <i v-else data-lucide="wallet" class="w-4 h-4 text-muted-foreground" aria-hidden="true"></i>
                                            </div>
                                            <span class="font-semibold text-sm text-foreground truncate">{{ w.name }}</span>
                                        </div>
                                        <span v-if="baseEvmConnecting" class="text-xs text-muted-foreground shrink-0">…</span>
                                        <span v-else-if="w.mobileOpen" class="text-xs font-medium text-muted-foreground shrink-0">Open</span>
                                    </button>
                                </div>

                                <div v-else-if="selectedNetworkIsNear" class="space-y-2">
                                    <button v-for="w in nearWalletOptions" :key="w.id" type="button"
                                        @click="connectNearForPayment(w.id)"
                                        :disabled="nearConnecting"
                                        class="w-full min-h-[52px] flex items-center justify-between gap-3 px-3.5 py-2.5 border rounded-xl touch-manipulation transition-[transform,border-color,background-color] duration-150 ease-out active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                                        :class="store.theme === 'dark' ? 'border-border hover:border-primary/50 hover:bg-muted/40' : 'border-gray-200 hover:border-primary/40 hover:bg-gray-50'">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="w-8 h-8 flex items-center justify-center shrink-0 rounded-lg bg-muted/60 overflow-hidden">
                                                <img v-if="w.logo" :src="w.logo" alt="" class="w-5 h-5 object-contain"
                                                    @error="$event.target.style.display='none'" />
                                                <i v-else data-lucide="wallet" class="w-4 h-4 text-muted-foreground" aria-hidden="true"></i>
                                            </div>
                                            <span class="font-semibold text-sm text-foreground truncate">{{ w.name }}</span>
                                        </div>
                                        <span v-if="nearConnecting" class="text-xs text-muted-foreground shrink-0">…</span>
                                    </button>
                                </div>

                                <div v-else-if="!selectedNetworkIsSolana" class="space-y-3">
                                    <p class="text-sm text-muted-foreground">{{ paymentsCopy.otherNetworkLead }}</p>
                                    <button type="button" @click="switchToSolanaAndStay"
                                        class="w-full min-h-[48px] rounded-xl border border-border font-semibold text-sm touch-manipulation hover:bg-muted/50">
                                        {{ paymentsCopy.otherNetworkSwitchSolana }}
                                    </button>
                                </div>

                                <template v-else>
                                    <div v-if="showSeekerConnectButton" class="w-full flex justify-center">
                                        <seeker-connect-button
                                            :theme="store.theme === 'dark' ? 'dark' : 'light'"
                                            :disabled="connecting"
                                            class="w-full"
                                            style="display:block;width:100%;max-width:100%;height:56px;"
                                            @click="connectSeekerConnect"
                                        />
                                    </div>

                                    <div v-if="showSeekerConnectButton && walletsForPicker.length > 0" class="flex items-center gap-3">
                                        <div class="h-px flex-1 bg-border"></div>
                                        <div class="text-xs font-medium text-muted-foreground">or</div>
                                        <div class="h-px flex-1 bg-border"></div>
                                    </div>

                                    <div v-if="walletsForPicker.length > 0 && showSolanaWalletPicker" class="space-y-2">
                                        <button 
                                            v-for="wallet in walletsForPicker" 
                                            :key="wallet.name"
                                            type="button"
                                            @click="connectWallet(wallet.name)"
                                            :disabled="!isWalletSelectable(wallet) || connecting"
                                            :class="[
                                                'w-full min-h-[52px] flex items-center justify-between px-3.5 py-2.5 border rounded-xl touch-manipulation transition-[transform,border-color,background-color] duration-150 ease-out active:scale-[0.99] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                isWalletSelectable(wallet) && !connecting
                                                    ? (store.theme === 'dark' ? 'border-border hover:border-primary/60 hover:bg-muted/40 cursor-pointer' : 'border-gray-200 hover:border-primary/50 hover:bg-gray-50 cursor-pointer')
                                                    : 'border-border bg-muted/40 cursor-not-allowed opacity-55'
                                            ]"
                                        >
                                            <div class="flex items-center gap-3 min-w-0">
                                                <div class="w-8 h-8 flex items-center justify-center shrink-0 rounded-lg bg-muted/60 overflow-hidden">
                                                    <img 
                                                        v-if="wallet.logo"
                                                        :src="wallet.logo" 
                                                        :alt=""
                                                        class="w-5 h-5 rounded object-contain"
                                                        :class="wallet.logoClass || ''"
                                                        @error="$event.target.style.display='none'"
                                                    >
                                                    <i v-else-if="useSolanaMobilePhoneIcon(wallet.name)" data-lucide="smartphone" class="w-5 h-5 opacity-90" :class="store.theme === 'dark' ? 'text-[#0066FF]' : 'text-[#0449B0]'"></i>
                                                    <i v-else data-lucide="wallet" class="w-5 h-5 opacity-80"></i>
                                                </div>
                                                <span class="font-semibold text-sm text-foreground truncate">{{ walletDisplayName(wallet.name) }}</span>
                                            </div>
                                            <span v-if="isWalletSelectable(wallet) && !connecting" class="text-xs font-medium text-muted-foreground shrink-0">
                                                {{ isMobileDeepLinkOnly(wallet) ? 'Open' : '' }}
                                            </span>
                                            <span v-else-if="connecting" class="text-xs text-muted-foreground">…</span>
                                        </button>
                                    </div>
                                </template>
                            
                                <div v-if="showNetworkWarning && selectedNetworkIsSolana" 
                                     :class="['border rounded-xl p-3', store.theme === 'dark' ? 'bg-yellow-900/20 border-yellow-800' : 'bg-yellow-50 border-yellow-200']">
                                    <div class="flex items-start gap-2">
                                        <i data-lucide="alert-triangle" class="w-4 h-4 text-yellow-500 mt-0.5 flex-shrink-0"></i>
                                        <div :class="['text-xs', store.theme === 'dark' ? 'text-yellow-300' : 'text-yellow-800']">
                                            <p class="font-medium">Test network</p>
                                            <p>Use {{ currentNetwork }} in your wallet.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Connecting State -->
                        <div v-else-if="connecting && !connectionResult" class="text-center py-8">
                            <div :class="['animate-spin motion-reduce:animate-none rounded-full h-12 w-12 border-b-2 mx-auto mb-4', store.theme === 'dark' ? 'border-blue-400' : 'border-blue-500']" aria-hidden="true"></div>
                            <p :class="['text-sm', 'text-muted-foreground']" aria-live="polite">Connecting to {{ walletDisplayName(connectingWallet) }}…</p>
                            <p v-if="useSolanaMobilePhoneIcon(connectingWallet)" :class="['text-xs mt-1 max-w-xs mx-auto', store.theme === 'dark' ? 'text-gray-500' : 'text-gray-500']">Pick your wallet app when it opens, approve the connection, then return to EarnFi. This can take a moment.</p>
                            <p v-else :class="['text-xs mt-1', store.theme === 'dark' ? 'text-gray-500' : 'text-gray-500']">Approve the connection in your wallet</p>
                        </div>
                        
                        <!-- Connection Result -->
                        <div v-else-if="connectionResult" class="text-center py-6">
                            <div v-if="connectionResult.success">
                                <div class="flex justify-center mb-4">
                                    <div class="w-14 h-14 rounded-full flex items-center justify-center bg-primary/10 text-primary ring-1 ring-border">
                                        <img 
                                            v-if="getConnectedWalletLogo(connectionResult.walletInfo?.name)"
                                            :src="getConnectedWalletLogo(connectionResult.walletInfo?.name)" 
                                            :alt="connectionResult.walletInfo?.name"
                                            class="w-7 h-7 rounded-lg object-contain"
                                            :class="getConnectedWalletLogoClass(connectionResult.walletInfo?.name)"
                                        >
                                        <i v-else-if="useSolanaMobilePhoneIcon(connectionResult.walletInfo?.name)" data-lucide="smartphone" class="w-7 h-7"></i>
                                        <i v-else data-lucide="check" class="w-7 h-7"></i>
                                    </div>
                                </div>
                                <h4 class="text-lg font-semibold mb-2 text-foreground">Connected</h4>
                                <p class="text-sm mb-3 text-muted-foreground">
                                    {{ connectionResult.walletInfo?.name || 'Wallet' }} is ready
                                </p>
                                <p v-if="connectionResult.walletAddress" class="text-xs font-mono p-2 rounded mb-4 bg-muted text-muted-foreground">
                                    {{ truncateWallet(connectionResult.walletAddress) }}
                                </p>
                                
                                <button 
                                    @click="proceedToApp"
                                    :disabled="proceeding"
                                    class="w-full py-2.5 px-4 rounded-xl font-semibold transition-colors text-sm bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-50 disabled:pointer-events-none"
                                >
                                    <span v-if="proceeding">Working…</span>
                                    <span v-else>Continue</span>
                                </button>
                            </div>
                            <div v-else>
                                <div class="flex justify-center mb-3">
                                    <div class="w-14 h-14 rounded-full flex items-center justify-center bg-destructive/10 text-destructive ring-1 ring-border">
                                        <i data-lucide="x" class="w-7 h-7"></i>
                                    </div>
                                </div>
                                <h4 class="text-lg font-semibold mb-2 text-foreground">Couldn’t connect</h4>
                                <p class="text-sm mb-4 text-muted-foreground">{{ connectionResult.error }}</p>
                                
                                <button 
                                    @click="resetConnection"
                                    class="w-full py-2.5 px-4 rounded-xl font-semibold transition-colors text-sm border border-border hover:bg-muted/60 text-foreground"
                                >
                                    Try again
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Installation Links -->
                    <div v-if="modalContentReady && !connecting && !connectionResult && needWalletLinks.length > 0" class="px-5 pb-3">
                        <div class="border-t border-border pt-3">
                            <p class="text-[11px] mb-1.5 text-center font-bold text-foreground">{{ paymentsCopy.needWallet || 'Need a wallet?' }}</p>
                            <div class="flex flex-wrap justify-center gap-x-3 gap-y-1">
                                <a 
                                    v-for="wallet in needWalletLinks" 
                                    :key="wallet.name"
                                    :href="wallet.installUrl" 
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-[11px] text-muted-foreground hover:text-primary transition-colors"
                                >
                                    {{ wallet.name }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div :class="['flex-shrink-0 p-4 rounded-b-xl border-t', store.theme === 'dark' ? 'bg-muted border-border' : 'bg-gray-50 border-gray-200']">
                    <p class="text-xs text-center text-muted-foreground">
                        By connecting, you agree to our
                        <a href="https://earnfi.fun/tos" target="_blank" rel="noopener noreferrer" class="underline hover:no-underline">Terms of Service</a>
                        and
                        <a href="https://earnfi.fun/privacy-policy" target="_blank" rel="noopener noreferrer" class="underline hover:no-underline">Privacy Policy</a>
                    </p>
                </div>
            </div>
            </div>
        </div>
        </teleport>
    `,
    props: {
        visible: {
            type: Boolean,
            default: false
        },
        /** Hide Google sign-in; copy targets wallet funding (creator flow). */
        walletOnlyMode: {
            type: Boolean,
            default: false
        }
    },
    emits: ['close', 'connected'],
    setup(props, { emit }) {
        const store = window.growlStore;
        const connecting = Vue.ref(false);
        const proceeding = Vue.ref(false);
        const connectingWallet = Vue.ref('');
        const connectionResult = Vue.ref(null);
        /** Monotonic id so a superseded connect cannot paint a stale error over a later success. */
        const connectAttemptId = Vue.ref(0);
        const supportedWallets = Vue.ref([]);
        const walletEntryContext = Vue.ref('multichain');
        const paymentNetworkState = Vue.ref({ networks: [], enabledNetworks: [], selectedCaip2: '' });
        const selectedPaymentNetwork = Vue.ref('');
        const networksLoading = Vue.ref(false);
        /** Gate paint until open init finishes so users never see a stale success or half-loaded tabs. */
        const modalContentReady = Vue.ref(false);
        /** 'network' | 'methods' — one screen per step when multichain is available */
        const modalStep = Vue.ref('methods');

        const NETWORK_LOGOS = {
            solana: 'https://solana.com/src/img/branding/solanaLogoMark.svg',
            base: 'https://avatars.githubusercontent.com/u/108554348?s=200&v=4',
            near: 'https://cryptologos.cc/logos/near-protocol-near-logo.svg',
            evm_okx: 'https://static.okx.com/cdn/assets/imgs/247/58E63FEA47EB7D55.png',
        };

        /** Primary NEAR connect targets (near-connect manifest ids). */
        const NEAR_CONNECT_CATALOG = [
            {
                id: 'meteor-wallet',
                name: 'Meteor Wallet',
                logo: 'https://raw.githubusercontent.com/Meteor-Wallet/meteor_wallet_sdk/main/assets/meteor-logo-svg.svg',
                installUrl: 'https://meteorwallet.app/',
            },
            {
                id: 'hot-wallet',
                name: 'HOT Wallet',
                logo: 'https://app.hot-labs.org/images/hot/hot-icon.png',
                installUrl: 'https://hot-labs.org/wallet',
            },
            {
                id: 'near-mobile',
                name: 'NEAR Mobile',
                logo: 'https://cryptologos.cc/logos/near-protocol-near-logo.svg',
                installUrl: 'https://nearmobile.app',
            },
            {
                id: 'intear-wallet',
                name: 'Intear Wallet',
                logo: 'https://intea.rs/favicon.ico',
                installUrl: 'https://intea.rs/',
            },
        ];
        const NEAR_INSTALL_CATALOG = [
            ...NEAR_CONNECT_CATALOG,
            {
                id: 'mynearwallet',
                name: 'MyNearWallet',
                logo: 'https://mynearwallet.com/favicon.ico',
                installUrl: 'https://app.mynearwallet.com/',
            },
        ];

        const networkLogo = (n) => {
            if (!n) return '';
            if (n.logo) return n.logo;
            return NETWORK_LOGOS[n.id] || '';
        };

        /**
         * Active tab uses inverted surface (dark theme: white pill). Logo must flip
         * to the contrast variant for that surface, not only for the page theme.
         */
        const networkLogoClass = (n) => {
            const active = selectedPaymentNetwork.value === (n && n.caip2);
            if (store.theme === 'dark') {
                return active ? 'brightness-0' : 'brightness-0 invert';
            }
            return active ? 'brightness-0 invert' : 'brightness-0';
        };

        const refreshWalletPaymentContext = async () => {
            const Ctx = window.EarnfiWalletContext;
            if (!Ctx) {
                return;
            }
            walletEntryContext.value = Ctx.resolve();
            networksLoading.value = true;
            try {
                if (typeof Ctx.refreshNetworks === 'function') {
                    paymentNetworkState.value = await Ctx.refreshNetworks();
                } else {
                    paymentNetworkState.value = Ctx.networks();
                }
            } catch (_) {
                paymentNetworkState.value = Ctx.networks();
            } finally {
                networksLoading.value = false;
            }
            const preferred = String(store.walletModalPreferredNetwork || '').trim();
            const solanaNative = Ctx.isSolanaNative?.() || walletEntryContext.value === 'solana_native'
                || walletEntryContext.value === 'seeker_mobile';
            if (preferred && !solanaNative) {
                selectedPaymentNetwork.value = preferred;
                Ctx.setPaymentNetwork?.(preferred);
                store.walletModalPreferredNetwork = '';
            } else {
                selectedPaymentNetwork.value = paymentNetworkState.value.selectedCaip2 || Ctx.defaultNetwork?.() || '';
            }
            refreshBaseEvmAddress();
            refreshNearAccountId();
            refreshBaseWalletOptions();
            modalStep.value = 'methods';
        };

        const isSolanaNativeContext = Vue.computed(() => {
            return walletEntryContext.value === 'solana_native' || walletEntryContext.value === 'seeker_mobile'
                || (window.EarnfiWalletContext?.isSolanaNative?.() ?? false);
        });

        const networkTilesForModal = Vue.computed(() => {
            // Depend on refresh result so tabs appear after /networks loads (not a stale empty cache).
            const state = paymentNetworkState.value || {};
            void networksLoading.value;
            const fromState = state.enabledNetworks || state.networks || [];
            const Ctx = window.EarnfiWalletContext;
            let list = fromState.length
                ? fromState
                : (props.walletOnlyMode ? Ctx?.payNetworkTiles?.() : Ctx?.authNetworkTiles?.()) || [];
            if (!props.walletOnlyMode) {
                list = (list || []).filter((n) => n && n.authEnabled !== false);
            }
            return (list || []).filter(
                (n) => n
                    && n.enabled !== false
                    && n.connectKind !== 'evm_agent_rail'
                    && n.id !== 'evm_okx',
            );
        });

        const showNetworkStep = Vue.computed(() => {
            if (isSolanaNativeContext.value) {
                return false;
            }
            // Drive from reactive tiles so Base/NEAR from /networks paint on open.
            return networkTilesForModal.value.length > 1;
        });

        const resetModalStep = () => {
            modalStep.value = 'methods';
        };

        const paymentsCopy = Vue.computed(() => {
            const p = window.EARNFI_COPY?.payments;
            if (p && typeof p === 'object') {
                return p;
            }
            return {
                modalTitleConnect: 'Connect wallet',
                modalTitlePay: 'Pay',
                modalTitleSignIn: 'Sign in',
                modalTitleLink: 'Link wallet',
                modalSubtitleMultichain: 'Pick a network, then a wallet. Same wallet signs in and pays on that network.',
                modalSubtitleSolanaDevice: 'Connect a wallet to continue.',
                modalSubtitlePay: 'Pick a network and wallet to pay.',
                modalSubtitleLink: 'Linked wallets sign in and pay on that network.',
                networkUpdating: 'Loading networks…',
                networkEmpty: 'Solana is available now.',
                popularLabel: 'Wallets',
                needWallet: 'Need a wallet?',
                useInstalledWallet: 'Use installed wallet',
                methodsStepTitle: 'Wallets',
                baseConnect: 'Connect wallet',
                baseReconnect: 'Switch wallet',
                nearConnect: 'Connect NEAR wallet',
                nearReconnect: 'Switch NEAR wallet',
                otherNetworkLead: 'This network isn’t available here yet. Choose Solana, Base, or NEAR.',
                otherNetworkSwitchSolana: 'Use Solana',
            };
        });

        const modalPurpose = Vue.computed(() => {
            const p = String(store.walletModalPurpose || '').trim().toLowerCase();
            if (p === 'link' || p === 'pay' || p === 'signin') {
                return p;
            }
            if (props.walletOnlyMode) {
                return store.user ? 'pay' : 'pay';
            }
            return 'signin';
        });

        const selectedPaymentNetworkLabel = Vue.computed(() => {
            return window.EarnfiWalletContext?.selectedNetworkLabel?.(selectedPaymentNetwork.value)
                || 'Solana';
        });

        const selectedNetworkIsSolana = Vue.computed(() => {
            return String(selectedPaymentNetwork.value || '').startsWith('solana:')
                || selectedPaymentNetwork.value === '';
        });

        const selectedNetworkIsBase = Vue.computed(() => {
            const n = String(selectedPaymentNetwork.value || '');
            return n === 'eip155:8453' || n === 'eip155:84532';
        });

        const selectedNetworkIsNear = Vue.computed(() => {
            return String(selectedPaymentNetwork.value || '').startsWith('near:');
        });

        const showStepDots = Vue.computed(() => false);
        const showBackInHeader = Vue.computed(() => false);
        const networkStepHeading = Vue.computed(() => '');

        const showAuthExtras = Vue.computed(() => {
            if (props.walletOnlyMode) return false;
            if (modalPurpose.value === 'link' || modalPurpose.value === 'pay') return false;
            return true;
        });

        const showAuthWalletDivider = Vue.computed(() => {
            if (!showAuthExtras.value) return false;
            if (selectedNetworkIsBase.value) return baseWalletOptions.value.length > 0;
            if (selectedNetworkIsNear.value) return nearWalletOptions.value.length > 0;
            if (selectedNetworkIsSolana.value) return true;
            return false;
        });

        const modalTitle = Vue.computed(() => {
            if (modalPurpose.value === 'link') {
                return paymentsCopy.value.modalTitleLink || 'Link wallet';
            }
            if (modalPurpose.value === 'pay') {
                return paymentsCopy.value.modalTitlePay || 'Pay';
            }
            if (showNetworkStep.value) {
                return paymentsCopy.value.modalTitleConnect || 'Connect wallet';
            }
            return paymentsCopy.value.modalTitleSignIn || 'Sign in';
        });

        const modalSubtitle = Vue.computed(() => {
            if (connecting.value || connectionResult.value) {
                return '';
            }
            if (modalPurpose.value === 'link') {
                return paymentsCopy.value.modalSubtitleLink
                    || 'Linked wallets sign in and pay on that network.';
            }
            if (modalPurpose.value === 'pay') {
                return paymentsCopy.value.modalSubtitlePay || 'Pick a network and wallet to pay.';
            }
            if (isSolanaNativeContext.value) {
                return paymentsCopy.value.modalSubtitleSolanaDevice || 'Connect a wallet to continue.';
            }
            if (showNetworkStep.value) {
                return paymentsCopy.value.modalSubtitleMultichain || '';
            }
            return 'Google, Seeker, or wallet';
        });

        const continueToMethods = () => {
            modalStep.value = 'methods';
        };

        const goBackStep = () => {
            connectionResult.value = null;
            modalStep.value = 'methods';
        };

        const switchToSolanaAndStay = () => {
            const caip2 = window.EarnfiWalletContext?.defaultNetwork?.()
                || 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';
            selectedPaymentNetwork.value = caip2;
            window.EarnfiWalletContext?.setPaymentNetwork?.(caip2);
            refreshBaseEvmAddress();
            modalStep.value = 'methods';
        };

        const baseEvmAddress = Vue.ref('');
        const baseEvmConnecting = Vue.ref(false);
        const nearAccountId = Vue.ref('');
        const nearConnecting = Vue.ref(false);
        const baseWalletOptions = Vue.ref([]);
        /**
         * Named NEAR wallets (MATCHFLOW-style). Meteor opens its own Mobile vs Web
         * chooser; HOT / NEAR Mobile keep deep-link paths via near-connect.
         */
        const nearWalletOptions = Vue.computed(() => {
            const mobile = (() => {
                try {
                    return /Android|iPhone|iPad|iPod|Mobile/i.test(String(navigator.userAgent || ''));
                } catch (_) {
                    return false;
                }
            })();
            const order = mobile
                ? ['meteor-wallet', 'near-mobile', 'hot-wallet', 'intear-wallet']
                : ['meteor-wallet', 'hot-wallet', 'near-mobile', 'intear-wallet'];
            const byId = Object.fromEntries(NEAR_CONNECT_CATALOG.map((w) => [w.id, w]));
            return order.map((id) => byId[id]).filter(Boolean);
        });

        const refreshBaseEvmAddress = () => {
            baseEvmAddress.value = window.EarnfiBaseEvmWallet?.getAddress?.() || '';
        };

        const refreshNearAccountId = () => {
            nearAccountId.value = window.EarnfiNearX402Signer?.getAccountId?.() || '';
        };

        const refreshBaseWalletOptions = () => {
            try {
                const listed = window.EarnfiBaseEvmWallet?.listWallets?.();
                if (Array.isArray(listed) && listed.length) {
                    baseWalletOptions.value = listed;
                    return;
                }
            } catch (_) { /* ignore */ }
            baseWalletOptions.value = [
                {
                    id: 'metamask',
                    name: 'MetaMask',
                    logo: 'https://upload.wikimedia.org/wikipedia/commons/3/36/MetaMask_Fox.svg',
                    installed: !!(window.ethereum?.isMetaMask || window.ethereum?.providers?.some?.((p) => p.isMetaMask)),
                    installUrl: 'https://metamask.io/download/',
                },
                {
                    id: 'coinbase',
                    name: 'Coinbase Wallet',
                    logo: 'https://altcoinsbox.com/wp-content/uploads/2022/12/coinbase-wallet-logo.png',
                    installed: !!(window.ethereum?.isCoinbaseWallet || window.ethereum?.providers?.some?.((p) => p.isCoinbaseWallet)),
                    installUrl: 'https://www.coinbase.com/wallet/downloads',
                },
            ];
        };
        refreshBaseWalletOptions();

        const payErr = (key, fallback) => {
            const e = window.EARNFI_COPY?.payments?.errors;
            return (e && e[key]) || fallback;
        };

        const applyLinkedUser = (result) => {
            if (result?.user && window.growlStore) {
                window.growlStore.user = result.user;
            }
        };

        const connectNearForPayment = async (preferredId) => {
            if (!window.EarnfiNearX402Signer?.connect) {
                connectionResult.value = {
                    success: false,
                    error: payErr('walletPayUnavailable', 'Refresh the page and try again.'),
                };
                return;
            }
            const walletId = typeof preferredId === 'string' ? preferredId : '';
            const opt = nearWalletOptions.value.find((w) => w.id === walletId);
            nearConnecting.value = true;
            connecting.value = true;
            connectingWallet.value = opt?.name || 'NEAR';
            try {
                window.EarnfiNearX402Signer.resetSession?.();
                const { accountId } = await window.EarnfiNearX402Signer.connect(
                    selectedPaymentNetwork.value,
                    { walletId },
                );
                nearAccountId.value = accountId || '';
                if (!props.walletOnlyMode && window.EarnfiMultichainAuth?.signInWithNear) {
                    const result = await window.EarnfiMultichainAuth.signInWithNear({
                        accountId: nearAccountId.value,
                        walletId,
                    });
                    await finishMultichainSession(result);
                    return;
                }
                if (props.walletOnlyMode && store.user?.user_id && window.EarnfiMultichainAuth?.linkNearPaymentWallet) {
                    try {
                        applyLinkedUser(await window.EarnfiMultichainAuth.linkNearPaymentWallet());
                    } catch (_) { /* connect still succeeded locally */ }
                }
                connectionResult.value = {
                    success: true,
                    walletInfo: { name: opt?.name || 'NEAR' },
                    walletAddress: nearAccountId.value,
                    message: paymentsCopy.value.nearConnectedSuccess || 'NEAR wallet connected.',
                };
                emit('connected', { kind: 'near_wallet', accountId: nearAccountId.value, walletId });
            } catch (e) {
                connectionResult.value = {
                    success: false,
                    error: String(e?.message || e || payErr('evmPayRetry', 'Try again.')),
                };
            } finally {
                nearConnecting.value = false;
                connecting.value = false;
            }
        };

        const connectBaseEvmForPayment = async (preferredId) => {
            if (!window.EarnfiBaseEvmWallet?.connect) {
                connectionResult.value = {
                    success: false,
                    error: payErr('walletPayUnavailable', 'Refresh the page and try again.'),
                };
                return;
            }
            const walletId = typeof preferredId === 'string' ? preferredId : '';
            const opt = baseWalletOptions.value.find((w) => w.id === walletId);
            baseEvmConnecting.value = true;
            connecting.value = true;
            connectingWallet.value = opt?.name || 'Base';
            try {
                const { address } = await window.EarnfiBaseEvmWallet.connect({ preferred: walletId || undefined });
                baseEvmAddress.value = address;
                refreshBaseWalletOptions();
                if (!props.walletOnlyMode && window.EarnfiMultichainAuth?.signInWithBaseEvm) {
                    const result = await window.EarnfiMultichainAuth.signInWithBaseEvm({
                        address: baseEvmAddress.value,
                    });
                    await finishMultichainSession(result);
                    return;
                }
                if (props.walletOnlyMode && store.user?.user_id && window.EarnfiMultichainAuth?.linkBasePaymentWallet) {
                    try {
                        applyLinkedUser(await window.EarnfiMultichainAuth.linkBasePaymentWallet());
                    } catch (_) { /* connect still succeeded locally */ }
                }
                connectionResult.value = {
                    success: true,
                    walletInfo: { name: opt?.name || 'Base' },
                    walletAddress: address,
                    message: paymentsCopy.value.baseConnectedSuccess || 'Wallet connected.',
                };
                emit('connected', { kind: 'evm_base', address });
            } catch (e) {
                connectionResult.value = {
                    success: false,
                    error: String(e?.message || e || payErr('evmPayRetry', 'Try again.')),
                };
            } finally {
                baseEvmConnecting.value = false;
                connecting.value = false;
            }
        };

        const showSolanaWalletPicker = Vue.computed(() => {
            if (isSolanaNativeContext.value) {
                return true;
            }
            if (showNetworkStep.value && !selectedNetworkIsSolana.value) {
                return false;
            }
            return true;
        });

        const selectPaymentNetwork = (caip2) => {
            selectedPaymentNetwork.value = caip2;
            window.EarnfiWalletContext?.setPaymentNetwork?.(caip2);
            refreshBaseEvmAddress();
            refreshNearAccountId();
            if (String(caip2 || '').startsWith('eip155:')) {
                refreshBaseWalletOptions();
            }
            Vue.nextTick(() => {
                try {
                    window.lucide?.createIcons?.();
                } catch (_) { /* ignore */ }
            });
        };

        const switchToSolanaPaymentNetwork = () => {
            const caip2 = window.EarnfiWalletContext?.defaultNetwork?.()
                || 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';
            selectPaymentNetwork(caip2);
            modalStep.value = 'methods';
        };
        
        const currentNetwork = Vue.computed(() => {
            return window.growlConfig?.solanaNetwork || 'devnet';
        });
        
        const showNetworkWarning = Vue.computed(() => {
            return currentNetwork.value !== 'mainnet-beta';
        });
        
        const isLandingPage = Vue.computed(() => {
            return store?.isLandingPage || false;
        });
        
        const isNativeEarnFiApp = () =>
            !!(window.Capacitor?.isNativePlatform?.()
                || document.documentElement?.classList?.contains('earnfi-capacitor-native'));

        const isBrowserExtensionWalletName = (name) => {
            const k = String(name || '').toLowerCase();
            return k === 'phantom' || k === 'solflare' || k === 'backpack';
        };

        const deepLinks = Vue.computed(() => {
            try {
                const url = typeof window !== 'undefined' && window.location ? window.location.href : '';
                const encUrl = encodeURIComponent(url);
                const encRef = encodeURIComponent((typeof window !== 'undefined' && window.location ? window.location.origin : '') || url);
                return {
                    phantom: url ? `https://phantom.app/ul/browse/${encUrl}?ref=${encRef}` : '',
                    solflare: url ? `https://solflare.com/ul/v1/browse/${encUrl}?ref=${encRef}` : '',
                };
            } catch {
                return { phantom: '', solflare: '' };
            }
        });

        const showGoogleSignIn = Vue.computed(() => {
            if (props.walletOnlyMode) {
                return false;
            }
            const s = window.growlConfig?.settings || {};
            const enabled = !!s.google_auth_enabled;
            const clientId = String(s.google_client_id || '').trim();
            // Only show on initial login/signup (not when already authenticated and just linking wallet)
            const isAuthed = !!(store.user && (store.user.user_id || store.user.wp_user_id || store.user.wallet_address || store.user.email));
            return enabled && clientId && !isAuthed;
        });

        const showCloseButton = Vue.computed(() => {
            // Only optional flows (linking wallet for gated actions) should be dismissible.
            return !!props.walletOnlyMode;
        });

        const googleBtnContainer = Vue.ref(null);
        const googleInitialized = Vue.ref(false);

        const referralFromLink = Vue.ref(false);
        const pendingReferralDisplay = Vue.ref('');
        const referralExpanded = Vue.ref(false);
        const manualReferralInput = Vue.ref('');
        const referralPanelOpen = Vue.ref(false);

        const referralLocked = Vue.computed(() => referralFromLink.value && (pendingReferralDisplay.value || '').length > 0);

        const hasStoredSessionJwt = Vue.computed(() => {
            try {
                return !!(localStorage.getItem('growl_jwt') || '').trim();
            } catch (_e) {
                return false;
            }
        });

        const showReferralSection = Vue.computed(() => {
            if (props.walletOnlyMode || store.user) return false;
            if (hasStoredSessionJwt.value && !referralLocked.value) return false;
            if (referralLocked.value) return true;
            if (referralFromLink.value) return true;
            if ((pendingReferralDisplay.value || '').trim()) return true;
            if (referralExpanded.value || referralPanelOpen.value) return true;
            return false;
        });

        const refreshReferralIcons = () => {
            Vue.nextTick(() => {
                try {
                    if (typeof globalThis !== 'undefined' && globalThis.lucide?.createIcons) {
                        globalThis.lucide.createIcons();
                    } else if (typeof window !== 'undefined' && window.lucide?.createIcons) {
                        window.lucide.createIcons();
                    }
                } catch (e) { /* ignore */ }
            });
        };

        const refreshReferralState = () => {
            try {
                referralFromLink.value = sessionStorage.getItem('earnfi_referral_from_link') === '1';
            } catch (e) {
                referralFromLink.value = false;
            }
            let code = '';
            try {
                code = (localStorage.getItem('growl_pending_referral') || '').trim();
            } catch (e) {
                code = '';
            }
            pendingReferralDisplay.value = code;
            if (!referralFromLink.value) {
                manualReferralInput.value = code;
                referralExpanded.value = !!code;
            } else {
                manualReferralInput.value = code;
            }
            if (referralFromLink.value && code) {
                referralPanelOpen.value = true;
            } else if (!referralFromLink.value && code) {
                referralPanelOpen.value = false;
            } else {
                referralPanelOpen.value = false;
            }
            refreshReferralIcons();
        };

        const toggleReferralPanel = () => {
            referralPanelOpen.value = !referralPanelOpen.value;
            refreshReferralIcons();
        };

        const persistManualReferral = () => {
            const v = String(manualReferralInput.value || '').trim().slice(0, 32);
            if (v) {
                try {
                    localStorage.setItem('growl_pending_referral', v);
                    sessionStorage.removeItem('earnfi_referral_from_link');
                } catch (e) { /* ignore */ }
                referralFromLink.value = false;
            } else {
                try {
                    localStorage.removeItem('growl_pending_referral');
                } catch (e) { /* ignore */ }
            }
            pendingReferralDisplay.value = v;
        };

        const collapseManualReferral = () => {
            manualReferralInput.value = '';
            referralExpanded.value = false;
            persistManualReferral();
            refreshReferralIcons();
        };

        const expandReferralEntry = () => {
            referralPanelOpen.value = true;
            referralExpanded.value = true;
            Vue.nextTick(() => {
                if (!manualReferralInput.value && pendingReferralDisplay.value) {
                    manualReferralInput.value = pendingReferralDisplay.value;
                }
                refreshReferralIcons();
            });
        };

        const loadGoogleScript = () =>
            new Promise((resolve, reject) => {
                if (window.google?.accounts?.id) return resolve(true);
                const existing = document.querySelector('script[data-google-gis="1"]');
                if (existing) {
                    existing.addEventListener('load', () => resolve(true));
                    existing.addEventListener('error', reject);
                    return;
                }
                const s = document.createElement('script');
                s.src = 'https://accounts.google.com/gsi/client';
                s.async = true;
                s.defer = true;
                s.dataset.googleGis = '1';
                s.onload = () => resolve(true);
                s.onerror = reject;
                document.head.appendChild(s);
            });

        const initGoogleButton = async () => {
            try {
                const s = window.growlConfig?.settings || {};
                const clientId = String(s.google_client_id || '').trim();
                if (!s.google_auth_enabled || !clientId) return;
                if (!googleBtnContainer.value) return;

                await loadGoogleScript();

                if (!googleInitialized.value) {
                    window.google.accounts.id.initialize({
                        client_id: clientId,
                        ux_mode: 'popup',
                        use_fedcm_for_prompt: true,
                        cancel_on_tap_outside: false,
                        callback: async (response) => {
                            try {
                                const idToken = response?.credential;
                                if (!idToken) throw new Error('No Google credential returned');
                                let pendingRef = '';
                                try { pendingRef = (localStorage.getItem('growl_pending_referral') || '').trim(); } catch (e) { pendingRef = ''; }
                                const googleBody = { id_token: idToken };
                                if (pendingRef) googleBody.referral_code = pendingRef;
                                const result = await growlAPI.post('/auth/google', googleBody);
                                if (!result?.success || !result?.user) throw new Error(result?.message || 'Google sign-in didn’t complete. Please try again.');
                                localStorage.setItem('growl_jwt', result.jwt || '');
                                localStorage.setItem('growl_auth_method', 'google');
                                if (result.user?.referral_processed) {
                                    try {
                                        localStorage.removeItem('growl_pending_referral');
                                        sessionStorage.removeItem('earnfi_referral_from_link');
                                    } catch (e) { /* ignore */ }
                                }
                                if (result.user.wallet_address) {
                                    localStorage.setItem('growl_wallet', result.user.wallet_address);
                                } else {
                                    localStorage.removeItem('growl_wallet');
                                }

                                // Require a real WP session for "successful login" (mobile deeplink/new-tab safe).
                                const validate = await growlAPI.get('/auth/validate-session');
                                if (!validate?.success) {
                                    throw new Error('Login incomplete. Please enable cookies and try again.');
                                }
                                if (!validate?.wp_logged_in) {
                                    throw new Error('Sign-in could not be completed. Please try again.');
                                }
                                if (window.growlConfig) {
                                    window.growlConfig.isUserLoggedIn = true;
                                }
                                if (window.growlStore) {
                                    window.growlStore.user = validate.user || result.user;
                                    window.growlStore.isLandingPage = false;
                                }
                                window.growlHelpers?.dispatchUserLoggedIn?.(validate.user || result.user);
                                emit('connected', validate.user || result.user);
                            } catch (e) {
                                console.error('Google auth callback failed:', e);
                                const msg = e?.message || 'Google sign-in failed';
                                connectionResult.value = { success: false, error: msg };
                            }
                        },
                    });
                    googleInitialized.value = true;
                }

                // Render/refresh button each time modal is shown.
                googleBtnContainer.value.innerHTML = '';
                const w = Math.max(240, Math.min(420, googleBtnContainer.value.offsetWidth || 360));
                window.google.accounts.id.renderButton(googleBtnContainer.value, {
                    theme: store.theme === 'dark' ? 'filled_black' : 'outline',
                    size: 'large',
                    text: 'signin_with',
                    shape: 'rectangular',
                    width: w,
                    logo_alignment: 'left',
                });
            } catch (e) {
                console.error('Google init failed:', e);
            }
        };

        const signInWithGoogle = async () => {
            // GIS rendered button handles the popup click; this keeps a fallback path.
            try {
                connecting.value = true;
                connectionResult.value = null;
                await initGoogleButton();
                if (window.google?.accounts?.id?.prompt) {
                    window.google.accounts.id.prompt(() => {});
                }
            } catch (e) {
                console.error('Google sign-in failed:', e);
                const msg = e?.message || 'Google sign-in failed';
                connectionResult.value = { success: false, error: msg };
            } finally {
                connecting.value = false;
            }
        };

        const finishMultichainSession = async (result) => {
            if (!result?.user) {
                throw new Error('Sign-in did not complete.');
            }
            localStorage.setItem('growl_jwt', result.jwt || 'cookie-session');
            localStorage.setItem('growl_auth_method', 'wallet');
            if (result.user.wallet_address) {
                localStorage.setItem('growl_wallet', result.user.wallet_address);
                localStorage.setItem('growl_wallet_type', result.user.wallet_type || 'unknown');
            }
            const validate = await growlAPI.get('/auth/validate-session');
            if (!validate?.success || !validate?.wp_logged_in) {
                throw new Error('Sign-in could not be completed. Please try again.');
            }
            if (window.growlConfig) {
                window.growlConfig.isUserLoggedIn = true;
            }
            if (window.growlStore) {
                window.growlStore.user = validate.user || result.user;
                window.growlStore.isLandingPage = false;
            }
            window.growlHelpers?.dispatchUserLoggedIn?.(validate.user || result.user);
            connectionResult.value = { success: true, ...(validate.user || result.user) };
            emit('connected', validate.user || result.user);
            emit('close');
        };

        const base58Decode = (s) => {
            const bs58 = (typeof growlCrypto !== 'undefined' ? growlCrypto?.bs58 : window.growlCrypto?.bs58);
            if (!bs58) throw new Error('Base58 module not available');
            const v = String(s || '').trim();
            if (!v) return new Uint8Array([]);
            return bs58.decode(v);
        };

        const base58Encode = (bytes) => {
            const bs58 = (typeof growlCrypto !== 'undefined' ? growlCrypto?.bs58 : window.growlCrypto?.bs58);
            if (!bs58) throw new Error('Base58 module not available');
            return bs58.encode(Uint8Array.from(bytes));
        };

        const arrayToBase64 = (bytes) => btoa(String.fromCharCode(...bytes));
        const base64ToArray = (base64) => Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));

        /** Query params for wallet return may be on search or inside the hash (in-app browsers). */
        const growlWalletReturnSearchParams = () => {
            const merged = new URLSearchParams(typeof window !== 'undefined' ? (window.location.search || '') : '');
            try {
                const h = typeof window !== 'undefined' ? (window.location.hash || '') : '';
                const qm = h.indexOf('?');
                if (qm >= 0) {
                    const hp = new URLSearchParams(h.slice(qm + 1));
                    hp.forEach((v, k) => {
                        if (!merged.has(k)) merged.set(k, v);
                    });
                }
            } catch (e) { /* ignore */ }
            return merged;
        };

        const cleanupOldMobileWalletLinks = () => {
            try {
                const prefix = 'growl_mobile_wallet_link:';
                const now = Date.now();
                // Keep states briefly; long enough for mobile round-trips, short enough to limit replay windows.
                const ttlMs = 30 * 60 * 1000; // 30 minutes
                const maxEntries = 25;
                const toCheck = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const k = localStorage.key(i);
                    if (k && k.startsWith(prefix)) toCheck.push(k);
                }
                // If something goes wrong and keys accumulate, cap them.
                if (toCheck.length > maxEntries) {
                    toCheck.sort();
                    for (let i = 0; i < toCheck.length - maxEntries; i++) {
                        try { localStorage.removeItem(toCheck[i]); } catch (e) {}
                    }
                }
                for (const k of toCheck) {
                    try {
                        const raw = localStorage.getItem(k);
                        if (!raw) {
                            localStorage.removeItem(k);
                            continue;
                        }
                        const st = JSON.parse(raw);
                        const created = Number(st?.created_at || 0);
                        if (!created || now - created > ttlMs) {
                            localStorage.removeItem(k);
                        }
                    } catch (e) {
                        try { localStorage.removeItem(k); } catch (e2) {}
                    }
                }
            } catch (e) {
                // no-op
            }
        };

        const mobileAppConnect = async (walletName) => {
            try {
                const deeplink = await import('../utils/mobile-wallet-deeplink.js');
                deeplink.saveReturnHref(window.location.href);
            } catch (_) {
                /* fallback: st.return_href below */
            }
            const w = String(walletName || '').toLowerCase();
            const nacl = (typeof growlCrypto !== 'undefined' ? growlCrypto?.nacl : window.growlCrypto?.nacl);
            const bs58 = (typeof growlCrypto !== 'undefined' ? growlCrypto?.bs58 : window.growlCrypto?.bs58);
            if (!nacl || !nacl.box || !nacl.box.keyPair || !bs58) {
                throw new Error('Mobile wallet crypto module not available');
            }

            const network = currentNetwork.value || 'mainnet-beta';
            const apiBase = (window.growlConfig?.apiUrl || '/wp-json/growl/v1/').replace(/\/$/, '/');
            const appUrl = apiBase.startsWith('http')
                ? (apiBase + 'app-metadata')
                : `${window.location.origin}${apiBase.startsWith('/') ? '' : '/'}${apiBase}app-metadata`;

            const state = Math.random().toString(36).slice(2) + Date.now().toString(36);
            const kp = nacl.box.keyPair();
            const dappPub58 = base58Encode(kp.publicKey);

            const st = {
                v: 1,
                wallet: w,
                state,
                dapp_secret: arrayToBase64(kp.secretKey),
                dapp_public: dappPub58,
                stage: 'connect',
                network,
                created_at: Date.now(),
                /** Full URL when the deeplink started. Some wallets reopen the site at `/` and drop the SPA path. */
                return_href: window.location.href,
            };
            // Use localStorage so the return flow still works if the wallet opens a new tab.
            cleanupOldMobileWalletLinks();
            localStorage.setItem('growl_mobile_wallet_link:' + state, JSON.stringify(st));

            const redirect = new URL(window.location.href);
            redirect.searchParams.set('earnfi_wallet_return', '1');
            redirect.searchParams.set('wallet', w);
            redirect.searchParams.set('state', state);

            const base =
                w === 'phantom'
                    ? 'https://phantom.app/ul/v1/connect'
                    : w === 'solflare'
                        ? 'https://solflare.com/ul/v1/connect'
                        : w === 'backpack'
                            ? 'https://backpack.app/ul/v1/connect'
                            : '';
            if (!base) throw new Error('Unsupported mobile wallet');

            const u = new URL(base);
            u.searchParams.set('app_url', appUrl);
            u.searchParams.set('dapp_encryption_public_key', dappPub58);
            u.searchParams.set('redirect_link', redirect.toString());
            if (network && network !== 'mainnet-beta') u.searchParams.set('cluster', network);
            window.location.href = u.toString();
        };

        const cleanWalletReturnParams = () => {
            try {
                const clean = new URL(window.location.href);
                ['earnfi_wallet_return', 'wallet', 'state', 'nonce', 'data', 'phantom_encryption_public_key', 'solflare_encryption_public_key', 'backpack_encryption_public_key', 'errorCode', 'errorMessage'].forEach((k) => clean.searchParams.delete(k));
                for (const k of [...clean.searchParams.keys()]) {
                    if (k.startsWith('wallet_')) clean.searchParams.delete(k);
                }
                history.replaceState(null, '', clean.toString());
            } catch {}
        };

        const maybeHandleMobileWalletReturn = async () => {
            try {
                cleanupOldMobileWalletLinks();
                const initialParams = growlWalletReturnSearchParams();
                if (initialParams.get('earnfi_wallet_return') !== '1') return;

                const state = initialParams.get('state') || '';
                const savedRaw = state ? localStorage.getItem('growl_mobile_wallet_link:' + state) : null;
                if (!savedRaw) return;
                const st = JSON.parse(savedRaw);
                if (!st || st.v !== 1) return;
                if (initialParams.get('state') !== st.state) return;
                const walletParam = initialParams.get('wallet');
                if (walletParam && walletParam !== st.wallet) return;

                try {
                    const href = typeof st.return_href === 'string' ? st.return_href.trim() : '';
                    if (href !== '') {
                        const intended = new URL(href, window.location.origin);
                        const cur = new URL(window.location.href);
                        const lostPath = (cur.pathname === '/' || cur.pathname === '') && intended.pathname && intended.pathname !== '/';
                        if (lostPath) {
                            const merged = new URL(intended.href);
                            initialParams.forEach((v, k) => merged.searchParams.set(k, v));
                            history.replaceState(null, '', merged.pathname + merged.search + (window.location.hash || ''));
                        }
                    }
                } catch (e) { /* ignore */ }

                const params = growlWalletReturnSearchParams();

                const errorMessage = params.get('errorMessage');
                if (errorMessage) {
                    connectionResult.value = { success: false, error: decodeURIComponent(errorMessage) };
                    cleanWalletReturnParams();
                    return;
                }

                const nacl = (typeof growlCrypto !== 'undefined' ? growlCrypto?.nacl : window.growlCrypto?.nacl);
                const dappSk = base64ToArray(st.dapp_secret);
                // Detect wallet encryption public key param
                let walletEncKey58 = params.get('phantom_encryption_public_key') || params.get('solflare_encryption_public_key') || params.get('backpack_encryption_public_key');
                if (!walletEncKey58) {
                    for (const [k, v] of params.entries()) {
                        if (k && k.startsWith('wallet_') && v) {
                            walletEncKey58 = v;
                            break;
                        }
                    }
                }
                const nonce58 = params.get('nonce');
                const data58 = params.get('data');
                if (!walletEncKey58 || !nonce58 || !data58) return;

                const walletEncPk = base58Decode(walletEncKey58);
                const nonce = base58Decode(nonce58);
                const boxed = base58Decode(data58);

                const opened = nacl.box.open(boxed, nonce, walletEncPk, dappSk);
                if (!opened) throw new Error('Failed to decrypt wallet response');
                const json = JSON.parse(new TextDecoder().decode(opened));

                if (st.stage === 'connect') {
                    st.stage = 'sign';
                    st.session = json.session;
                    st.public_key = json.public_key;
                    st.wallet_enc = walletEncKey58;
                    const challenge = await window.growlWalletManager.getAuthChallenge(
                        st.network,
                        st.public_key,
                        'login',
                    );
                    st.challenge_id = challenge.id;
                    st.message = window.growlWalletManager.buildAuthMessage(challenge, st.public_key);
                    localStorage.setItem('growl_mobile_wallet_link:' + st.state, JSON.stringify(st));

                    const shared = nacl.box.before(walletEncPk, dappSk);
                    const reqNonce = nacl.randomBytes(24);
                    const payloadObj = {
                        message: base58Encode(new TextEncoder().encode(st.message)),
                        session: st.session,
                        display: 'utf8',
                    };
                    const payloadBytes = new TextEncoder().encode(JSON.stringify(payloadObj));
                    const boxedPayload = nacl.box.after(payloadBytes, reqNonce, shared);

                    const base =
                        st.wallet === 'phantom'
                            ? 'https://phantom.app/ul/v1/signMessage'
                            : st.wallet === 'solflare'
                                ? 'https://solflare.com/ul/v1/signMessage'
                                : 'https://backpack.app/ul/v1/signMessage';

                    const baseHref =
                        typeof st.return_href === 'string' && st.return_href.trim()
                            ? st.return_href.trim()
                            : window.location.href;
                    const redirect = new URL(baseHref, window.location.origin);
                    // keep marker params; wallet app will append new data/nonce
                    redirect.searchParams.delete('data');
                    redirect.searchParams.delete('nonce');
                    redirect.searchParams.delete('phantom_encryption_public_key');
                    redirect.searchParams.delete('solflare_encryption_public_key');
                    redirect.searchParams.delete('backpack_encryption_public_key');
                    for (const k of [...redirect.searchParams.keys()]) {
                        if (k.startsWith('wallet_')) redirect.searchParams.delete(k);
                    }

                    const u = new URL(base);
                    u.searchParams.set('dapp_encryption_public_key', st.dapp_public);
                    u.searchParams.set('nonce', base58Encode(reqNonce));
                    u.searchParams.set('redirect_link', redirect.toString());
                    u.searchParams.set('payload', base58Encode(boxedPayload));
                    window.location.href = u.toString();
                    return;
                }

                if (st.stage === 'sign') {
                    const signature58 = json.signature;
                    if (!signature58) throw new Error('Missing signature');
                    const sigBytes = base58Decode(signature58);
                    const signature = Array.from(sigBytes);
                    if (signature.length !== 64) throw new Error('Invalid signature length');

                    cleanWalletReturnParams();

                    await authenticateWithBackend({
                        walletAddress: st.public_key,
                        signature,
                        message: st.message,
                        network: st.network,
                        challengeId: st.challenge_id,
                        walletInfo: { name: st.wallet === 'phantom' ? 'Phantom' : st.wallet === 'solflare' ? 'Solflare' : 'Backpack' },
                    });
                    localStorage.removeItem('growl_mobile_wallet_link:' + st.state);
                }
            } catch (e) {
                console.error('Mobile wallet deeplink handling failed:', e);
                cleanWalletReturnParams();
            }
        };
        
        const useSolanaMobilePhoneIcon = (name) => {
            const k = String(name || '').toLowerCase();
            return (
                k.includes('solana mobile') ||
                k.includes('wallet app') ||
                k === 'mobile wallet adapter'
            );
        };

        /**
         * Official Solana Mobile UX guideline: label the MWA option "Use Installed Wallet" so
         * users understand it connects to a wallet app already on their device.
         */
        const walletDisplayName = (name) => {
            const k = String(name || '').toLowerCase();
            if (k === 'seeker connect') return 'Seeker Connect';
            if (useSolanaMobilePhoneIcon(name)) return 'Use Installed Wallet';
            return name;
        };

        const showSeekerConnectButton = Vue.computed(() => {
            if (isAppleMobileDevice()) return false;
            if (!isSolanaNativeContext.value && !selectedNetworkIsSolana.value) {
                return false;
            }
            return supportedWallets.value.some((w) => String(w.name || '').toLowerCase() === 'seeker connect');
        });

        const walletsForPicker = Vue.computed(() => {
            let list = supportedWallets.value;
            if (showSeekerConnectButton.value) {
                list = list.filter((w) => String(w.name || '').toLowerCase() !== 'seeker connect');
            }
            if (isNativeEarnFiApp()) {
                list = list.filter((w) => !isBrowserExtensionWalletName(w.name));
            } else {
                list = list.filter((w) => {
                    if (!isBrowserExtensionWalletName(w.name)) return true;
                    return hasInjectedWallet(String(w.name || '').toLowerCase());
                });
            }
            return list;
        });

        const showWalletOrDivider = Vue.computed(() => {
            if (!walletsForPicker.value.length) return false;
            return !!(showGoogleSignIn.value || showSeekerConnectButton.value);
        });

        const browserWalletSectionTitle = Vue.computed(() => {
            const c = window.EARNFI_COPY;
            if (showNetworkStep.value && selectedNetworkIsSolana.value) {
                return 'Solana wallet';
            }
            return (c && c.signInBrowserWallet) || 'Sign in with a browser wallet';
        });

        const connectSeekerConnect = () => connectWallet('Seeker Connect');

        // Get wallet logo paths
        const getWalletLogoPath = (walletName) => {
            const asset = typeof window !== 'undefined' && typeof window.growlPublicAssetUrl === 'function'
                ? window.growlPublicAssetUrl
                : () => '';
            const n = String(walletName || '').trim();
            const key = n.toLowerCase();
            if (key.includes('solana mobile')) {
                return '';
            }
            // Built-in CDN logos as fallback for known wallets
            const fallbackLogos = {
                'phantom': 'https://raw.githubusercontent.com/solana-labs/wallet-adapter/master/packages/wallets/icons/phantom.svg',
                'solflare': 'https://solflare.com/assets/logo.svg',
                'backpack': 'https://raw.githubusercontent.com/coral-xyz/backpack/master/packages/app-extension/public/assets/icon.png',
            };
            const logoFromAsset = asset(key) || asset(n);
            const logos = {
                'Phantom': logoFromAsset || fallbackLogos['phantom'],
                'Solflare': logoFromAsset || fallbackLogos['solflare'],
                'Backpack': logoFromAsset || fallbackLogos['backpack'],
            };
            if (logos[n]) return logos[n];
            const remote = {
                metamask: 'https://upload.wikimedia.org/wikipedia/commons/3/36/MetaMask_Fox.svg',
                'metamask solana': 'https://upload.wikimedia.org/wikipedia/commons/3/36/MetaMask_Fox.svg',
                coinbase: 'https://avatars.githubusercontent.com/u/1885080?s=200&v=4',
                trust: 'https://trustwallet.com/assets/images/media/assets/TWT.png',
                okx: 'https://static.okx.com/cdn/assets/imgs/221/5FA686A1961B557F.png',
                ledger: 'https://www.ledger.com/wp-content/uploads/2021/11/Ledger_logo.svg',
                solana: 'https://solana.com/src/img/branding/solanaLogoMark.svg',
            };
            for (const [slug, url] of Object.entries(remote)) {
                if (key.includes(slug)) return url;
            }
            return '';
        };
        
        // Get wallet logo class for styling
        const getWalletLogoClass = (walletName) => {
            const classes = {
                'Phantom': '',
                'Solflare': '',
                'Backpack': ''
            };
            return classes[walletName] || '';
        };
        
        const getConnectedWalletLogo = (walletName) => {
            return getWalletLogoPath(walletName);
        };
        
        const getConnectedWalletLogoClass = (walletName) => {
            return getWalletLogoClass(walletName);
        };
        
        const initializeWallets = async () => {
            try {
                if (window.growlWalletManager) {
                    if (typeof window.growlWalletManager.preloadMobileWalletAdapter === 'function') {
                        void window.growlWalletManager.preloadMobileWalletAdapter();
                    }
                    await window.growlWalletManager.initialize();
                    try {
                        const [reg, ui] = await Promise.all([
                            import('../utils/seeker-connect-register.js'),
                            import('@solana-mobile/seeker-connect-ui'),
                        ]);
                        reg.ensureSeekerConnectRegistered();
                        ui.defineSeekerConnectElements();
                    } catch (_) {
                        /* optional */
                    }
                    const wallets = window.growlWalletManager.getSupportedWallets();
                    
                    // Enhance wallets with logos and mobile deeplink availability.
                    supportedWallets.value = wallets.map((wallet) => {
                        const key = String(wallet.name || '').toLowerCase();
                        return {
                            ...wallet,
                            detected: !!wallet.detected || supportsMobileDeepLinkWallet(key),
                            logo: getWalletLogoPath(wallet.name),
                            logoClass: getWalletLogoClass(wallet.name)
                        };
                    });
                } else {
                    // Fallback with enhanced wallet data
                    supportedWallets.value = [
                        {
                            name: 'Phantom',
                            icon: 'wallet',
                            detected: hasInjectedWallet('phantom') || supportsMobileDeepLinkWallet('phantom'),
                            installUrl: 'https://phantom.app/',
                            logo: getWalletLogoPath('Phantom'),
                            logoClass: getWalletLogoClass('Phantom')
                        },
                        {
                            name: 'Solflare',
                            icon: 'wallet',
                            detected: hasInjectedWallet('solflare') || supportsMobileDeepLinkWallet('solflare'),
                            installUrl: 'https://solflare.com/',
                            logo: getWalletLogoPath('Solflare'),
                            logoClass: getWalletLogoClass('Solflare')
                        },
                        {
                            name: 'Backpack',
                            icon: 'wallet',
                            detected: hasInjectedWallet('backpack') || supportsMobileDeepLinkWallet('backpack'),
                            installUrl: 'https://www.backpack.app/',
                            logo: getWalletLogoPath('Backpack'),
                            logoClass: getWalletLogoClass('Backpack')
                        }
                    ];
                }
            } catch (error) {
                console.error('Failed to initialize wallets:', error);
            }
        };
        
        const isAppleMobileDevice = () => {
            if (typeof navigator === 'undefined') return false;
            const ua = String(navigator.userAgent || '').toLowerCase();
            return /iphone|ipad|ipod/.test(ua) || (ua.includes('macintosh') && Number(navigator.maxTouchPoints || 0) > 1);
        };

        const isMobileDevice = () => {
            if (typeof navigator === 'undefined') return false;
            const ua = String(navigator.userAgent || '').toLowerCase();
            return /android|mobile/.test(ua) || isAppleMobileDevice();
        };

        const undetectedWallets = Vue.computed(() => {
            if (isNativeEarnFiApp() || isMobileDevice()) return [];
            return supportedWallets.value.filter((wallet) => {
                if (!isBrowserExtensionWalletName(wallet.name)) return false;
                return !hasInjectedWallet(String(wallet.name || '').toLowerCase());
            });
        });

        const hasUndetectedWallets = Vue.computed(() => undetectedWallets.value.length > 0);

        const baseInstallLinks = Vue.computed(() => {
            try {
                const links = window.EarnfiBaseEvmWallet?.listInstallLinks?.();
                if (Array.isArray(links)) {
                    return links.map((w) => ({ name: w.name, installUrl: w.installUrl })).filter((w) => w.installUrl);
                }
            } catch (_) { /* ignore */ }
            return [];
        });

        const nearInstallLinks = Vue.computed(() => {
            const primaryIds = new Set(nearWalletOptions.value.map((w) => String(w.id || '').toLowerCase()));
            const primaryNames = new Set(nearWalletOptions.value.map((w) => String(w.name || '').toLowerCase()));
            return NEAR_INSTALL_CATALOG
                .filter((w) => {
                    if (!w.installUrl) return false;
                    const id = String(w.id || '').toLowerCase();
                    const name = String(w.name || '').toLowerCase();
                    return !primaryIds.has(id) && !primaryNames.has(name);
                })
                .map((w) => ({ name: w.name, installUrl: w.installUrl }));
        });

        const needWalletLinks = Vue.computed(() => {
            if (selectedNetworkIsBase.value) return baseInstallLinks.value;
            if (selectedNetworkIsNear.value) return nearInstallLinks.value;
            if (selectedNetworkIsSolana.value) {
                return undetectedWallets.value.map((w) => ({ name: w.name, installUrl: w.installUrl })).filter((w) => w.installUrl);
            }
            return [];
        });

        /*
         * Deep links are the correct path ONLY on iOS (and non-Chrome Android), where the
         * Mobile Wallet Adapter is not supported by the OS. On Android, MWA ("Use Installed
         * Wallet") handles Phantom/Solflare/etc. locally, so we do NOT deep-link there.
         */
        const supportsMobileDeepLinkWallet = (walletKey) => {
            const key = String(walletKey || '').toLowerCase();
            return isAppleMobileDevice() && ['phantom', 'solflare', 'backpack'].includes(key);
        };

        const hasInjectedWallet = (key) => {
            const k = String(key || '').toLowerCase();
            if (k === 'phantom') {
                return !!(
                    (window.solana && window.solana.isPhantom) ||
                    (window.phantom && window.phantom.solana && window.phantom.solana.isPhantom)
                );
            }
            if (k === 'solflare') {
                return !!(
                    (window.solflare && window.solflare.isSolflare) ||
                    (window.solflare && window.solflare.solana && window.solflare.solana.isSolflare)
                );
            }
            if (k === 'backpack') {
                const b = window.backpack || window.xnft;
                return !!(b && (b.solana || b.connect || b.isBackpack));
            }
            return false;
        };

        const isWalletSelectable = (wallet) => {
            if (!wallet) return false;
            if (wallet.detected) return true;
            const key = String(wallet.name || '').toLowerCase();
            return supportsMobileDeepLinkWallet(key);
        };

        const isMobileDeepLinkOnly = (wallet) => {
            if (!wallet) return false;
            const key = String(wallet.name || '').toLowerCase();
            return !wallet.detected && supportsMobileDeepLinkWallet(key);
        };

        const connectWallet = async (walletName) => {
            const attemptId = ++connectAttemptId.value;
            try {
                // Switching wallets while a prior attempt is hanging must cancel it first.
                if (connecting.value) {
                    window.growlWalletManager?.cancelPendingConnection?.();
                }
                connecting.value = true;
                connectingWallet.value = walletName;
                connectionResult.value = null;
                window.growlConnectingWalletAddress = null;
                
                if (!window.growlWalletManager) {
                    throw new Error('Wallet system not available');
                }

                const key = String(walletName || '').toLowerCase();
                const isSeekerConnect = key === 'seeker connect';
                const isLegacyMwa =
                    !isSeekerConnect &&
                    (key.includes('solana mobile') ||
                        key === 'mobile wallet adapter' ||
                        key.includes('wallet app'));
                const isMwa = isSeekerConnect || isLegacyMwa;
                // iOS and non-Chrome Android browsers use the wallet app deep link when no injected provider exists.
                // On Android, MWA ("Use Installed Wallet") handles this locally instead.
                if (
                    !isMwa &&
                    isAppleMobileDevice() &&
                    ['phantom', 'solflare', 'backpack'].includes(key) &&
                    !hasInjectedWallet(key)
                ) {
                    await mobileAppConnect(key);
                    return;
                }

                // Wallet-only: reattach signing provider without SIWS / JWT rewrite.
                // Full authenticateWithBackend on an already-logged-in session was clearing
                // storage on failure and leaving "Wallet not connected" until a later poll.
                if (props.walletOnlyMode) {
                    const linked = String(
                        store.user?.wallet_address || localStorage.getItem('growl_wallet') || ''
                    ).trim();
                    const lastType = String(
                        localStorage.getItem('growl_wallet_type')
                        || window.growlWalletManager.connectedWallet
                        || ''
                    ).trim();
                    const lastIsSeeker =
                        lastType === 'seeker_connect' ||
                        String(lastType || '').toLowerCase() === 'seeker connect';
                    const lastIsMwa =
                        !lastIsSeeker &&
                        (/solana mobile|mobile wallet|wallet app|mwa/i.test(lastType) ||
                            (typeof window.growlWalletManager._isSolanaMobileAdapterConnectName ===
                                'function' &&
                                window.growlWalletManager._isSolanaMobileAdapterConnectName(lastType) &&
                                !window.growlWalletManager._isSeekerConnectName?.(lastType)));

                    // Seeker Connect: SDK silent restore from its own cache (not local MWA).
                    if (isSeekerConnect && lastIsSeeker) {
                        try {
                            await window.growlWalletManager.initialize?.();
                            if (
                                !window.growlWalletManager._providerCanSign?.(
                                    window.growlWalletManager.provider,
                                )
                            ) {
                                await window.growlWalletManager._rehydrateSeekerConnectProvider?.();
                            }
                            const silentAddr = String(
                                window.growlWalletManager.walletAddress ||
                                    localStorage.getItem('growl_wallet') ||
                                    '',
                            ).trim();
                            if (
                                window.growlWalletManager._providerCanSign?.(
                                    window.growlWalletManager.provider,
                                ) &&
                                silentAddr
                            ) {
                                if (linked && silentAddr.toLowerCase() !== linked.toLowerCase()) {
                                    throw new Error(
                                        'Connected wallet does not match your linked EarnFi wallet (' +
                                            truncateWallet(linked) +
                                            '). Switch back to that account in your wallet app and try again.',
                                    );
                                }
                                try {
                                    localStorage.setItem('growl_wallet', silentAddr);
                                    localStorage.setItem(
                                        'growl_wallet_type',
                                        window.growlWalletManager.walletTypeForStorage?.(walletName) ||
                                            'seeker_connect',
                                    );
                                    localStorage.setItem(
                                        'growl_network',
                                        window.growlWalletManager.getConfiguredNetwork?.() ||
                                            'mainnet-beta',
                                    );
                                } catch (_) {
                                    /* storage may be unavailable */
                                }
                                window.growlConnectingWalletAddress = silentAddr;
                                try {
                                    window.dispatchEvent(
                                        new CustomEvent('growlWalletReady', {
                                            detail: {
                                                walletAddress: silentAddr,
                                                walletType:
                                                    window.growlWalletManager.connectedWallet ||
                                                    walletName,
                                                walletOnly: true,
                                                silentReattach: true,
                                            },
                                        }),
                                    );
                                } catch (_) {
                                    /* ignore */
                                }
                                connectionResult.value = {
                                    success: true,
                                    walletAddress: silentAddr,
                                    walletOnly: true,
                                    sessionReady: true,
                                    ...(store.user || {}),
                                    wallet_address: silentAddr,
                                };
                                emit('connected', connectionResult.value);
                                emit('close');
                                return;
                            }
                        } catch (silentErr) {
                            const smsg = String(silentErr?.message || silentErr || '');
                            if (/does not match your linked/i.test(smsg)) {
                                throw silentErr;
                            }
                        }
                    }

                    // Installed Wallet: silently reattach last authorized wallet (auth cache)
                    // so Android does not show the system app chooser on every reconnect.
                    if (isLegacyMwa && lastIsMwa) {
                        try {
                            window.growlWalletManager._mwaForceFreshNext = false;
                            await window.growlWalletManager.initialize?.();
                            if (!window.growlWalletManager._providerCanSign?.(window.growlWalletManager.provider)) {
                                await window.growlWalletManager.tryRestoreSession?.();
                            }
                            if (!window.growlWalletManager._providerCanSign?.(window.growlWalletManager.provider)) {
                                await window.growlWalletManager._rehydrateMwaProvider?.();
                            }
                            if (!window.growlWalletManager._providerCanSign?.(window.growlWalletManager.provider)) {
                                await window.growlWalletManager._ensureSigningProvider?.();
                            }
                            const silentAddr = String(
                                window.growlWalletManager.walletAddress
                                || localStorage.getItem('growl_wallet')
                                || ''
                            ).trim();
                            if (
                                window.growlWalletManager._providerCanSign?.(window.growlWalletManager.provider)
                                && silentAddr
                            ) {
                                if (linked && silentAddr.toLowerCase() !== linked.toLowerCase()) {
                                    throw new Error(
                                        'Connected wallet does not match your linked EarnFi wallet ('
                                        + truncateWallet(linked)
                                        + '). Switch back to that account in your wallet app and try again.'
                                    );
                                }
                                try {
                                    localStorage.setItem('growl_wallet', silentAddr);
                                    localStorage.setItem(
                                        'growl_wallet_type',
                                        window.growlWalletManager.walletTypeForStorage?.(walletName)
                                        || lastType
                                        || String(walletName || 'unknown').slice(0, 32)
                                    );
                                    localStorage.setItem(
                                        'growl_network',
                                        window.growlWalletManager.getConfiguredNetwork?.() || 'mainnet-beta'
                                    );
                                } catch (_) { /* storage may be unavailable */ }
                                window.growlConnectingWalletAddress = silentAddr;
                                try {
                                    window.dispatchEvent(new CustomEvent('growlWalletReady', {
                                        detail: {
                                            walletAddress: silentAddr,
                                            walletType: window.growlWalletManager.connectedWallet || walletName,
                                            walletOnly: true,
                                            silentReattach: true,
                                        },
                                    }));
                                } catch (_) { /* ignore */ }
                                connectionResult.value = {
                                    success: true,
                                    walletAddress: silentAddr,
                                    walletOnly: true,
                                    sessionReady: true,
                                    ...(store.user || {}),
                                    wallet_address: silentAddr,
                                };
                                emit('connected', connectionResult.value);
                                emit('close');
                                return;
                            }
                        } catch (silentErr) {
                            const smsg = String(silentErr?.message || silentErr || '');
                            if (/does not match your linked/i.test(smsg)) {
                                throw silentErr;
                            }
                            // Fall through to explicit connect (may show chooser if auth cache missing).
                        }
                    }

                    const result = await window.growlWalletManager.connectWallet(walletName, {
                        skipAuthentication: true,
                    });
                    if (attemptId !== connectAttemptId.value) return;
                    if (!result?.success || !result.walletAddress) {
                        throw new Error(result?.error || 'Connection failed');
                    }
                    const connectedAddr = String(result.walletAddress).trim();
                    if (linked && connectedAddr.toLowerCase() !== linked.toLowerCase()) {
                        throw new Error(
                            'Connected wallet does not match your linked EarnFi wallet ('
                            + truncateWallet(linked)
                            + '). Switch back to that account in your wallet app and try again.'
                        );
                    }
                    try {
                        localStorage.setItem('growl_wallet', connectedAddr);
                        const wt =
                            window.growlWalletManager.walletTypeForStorage?.(walletName)
                            || String(walletName || 'unknown').slice(0, 32);
                        localStorage.setItem('growl_wallet_type', wt);
                        localStorage.setItem(
                            'growl_network',
                            result.network || window.growlWalletManager.getConfiguredNetwork?.() || 'mainnet-beta'
                        );
                    } catch (_) {
                        /* storage may be unavailable */
                    }
                    window.growlConnectingWalletAddress = connectedAddr;
                    try {
                        window.dispatchEvent(new CustomEvent('growlWalletReady', {
                            detail: {
                                walletAddress: connectedAddr,
                                walletType: window.growlWalletManager.connectedWallet || walletName,
                                walletOnly: true,
                            },
                        }));
                    } catch (_) {
                        /* ignore */
                    }
                    connectionResult.value = {
                        success: true,
                        walletAddress: connectedAddr,
                        walletOnly: true,
                        sessionReady: true,
                        ...(store.user || {}),
                        wallet_address: connectedAddr,
                    };
                    // Auto-close so ensureConnected waiters and staking UI refresh immediately.
                    emit('connected', connectionResult.value);
                    emit('close');
                    return;
                }
                
                const result = await window.growlWalletManager.connectWallet(walletName);
                if (attemptId !== connectAttemptId.value) return;
                
                if (result.success) {
                    window.growlConnectingWalletAddress = result.walletAddress || null;
                    await authenticateWithBackend(result, () => attemptId === connectAttemptId.value);
                } else {
                    throw new Error(result.error || 'Connection failed');
                }
                
            } catch (error) {
                if (attemptId !== connectAttemptId.value) return;
                const msg = String(error?.message || error || '');
                if (/replaced by a newer attempt/i.test(msg)) {
                    return;
                }
                console.error('Connection error:', error);
                connectionResult.value = {
                    success: false,
                    error: error.message || 'Connection failed'
                };

                Vue.nextTick(() => {
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons();
                    }
                });
            } finally {
                if (attemptId === connectAttemptId.value) {
                    connecting.value = false;
                    window.growlConnectingWalletAddress = null;
                }
            }
        };
        
        const authenticateWithBackend = async (walletData, isCurrent = () => true) => {
            const isTransientNetworkError = (err) =>
                /failed to fetch|networkerror|network request failed|load failed|the network connection was lost|temporarily unavailable/i.test(
                    String(err?.message || err || '')
                );

            const waitForNetwork = async () => {
                if (typeof navigator === 'undefined' || navigator.onLine !== false) return;
                await new Promise((resolve) => {
                    const done = () => {
                        window.removeEventListener('online', done);
                        resolve();
                    };
                    window.addEventListener('online', done);
                    setTimeout(done, 2500);
                });
            };

            const withFetchRetry = async (fn, attempts = 4) => {
                let lastErr;
                for (let i = 0; i < attempts; i++) {
                    try {
                        if (i > 0) {
                            await waitForNetwork();
                            await new Promise((r) => setTimeout(r, 400 * i));
                        }
                        return await fn();
                    } catch (err) {
                        lastErr = err;
                        if (!isTransientNetworkError(err) || i === attempts - 1) {
                            throw err;
                        }
                    }
                }
                throw lastErr;
            };

            try {
                console.log('🔐 Completing sign-in...');
                
                let pendingReferral = String(manualReferralInput.value || '').trim();
                if (!pendingReferral) {
                    try {
                        pendingReferral = (localStorage.getItem('growl_pending_referral') || '').trim();
                    } catch (e) {
                        pendingReferral = '';
                    }
                }

                const storedWalletType =
                    window.growlWalletManager?.walletTypeForStorage?.(walletData.walletInfo?.name) ||
                    String(walletData.walletInfo?.name || 'unknown').slice(0, 32);

                const authPayload = {
                    wallet_address: walletData.walletAddress,
                    signature: walletData.signature,
                    message: walletData.message,
                    network: walletData.network,
                    challenge_id: walletData.challengeId,
                    wallet_type: storedWalletType,
                    referral_code: pendingReferral || undefined
                };

                // After returning from a wallet app, the first fetch can fail before the
                // WebView network stack is ready — retry the same signed payload.
                const authResult = await withFetchRetry(() =>
                    window.growlAPI.authWithWallet(authPayload)
                );
                if (!isCurrent()) {
                    throw new Error('Wallet connection was replaced by a newer attempt');
                }

                if (!authResult.success) {
                    throw new Error(authResult.message || 'Authentication failed');
                }

                window.growlWalletManager?.clearAuthChallenge?.(walletData.challengeId);
                console.log('✅ Backend authentication successful');
                
                // Clear pending referral if processed
                if (authResult.user?.referral_processed) {
                    try {
                        localStorage.removeItem('growl_pending_referral');
                        sessionStorage.removeItem('earnfi_referral_from_link');
                    } catch (e) { /* ignore */ }
                }
                console.log('📊 WordPress logged in:', authResult.wp_logged_in);
                console.log('📊 Cookies set:', authResult.cookies_set);

                // CRITICAL: Store all authentication components
                localStorage.setItem('growl_jwt', authResult.jwt);
                localStorage.setItem('growl_wallet', walletData.walletAddress);
                localStorage.setItem('growl_network', walletData.network);
                localStorage.setItem('growl_wallet_type', storedWalletType);

                // Verify all three auth states are set
                const jwtSet = !!localStorage.getItem('growl_jwt');
                const walletSet = !!localStorage.getItem('growl_wallet');
                const wpLoggedIn = authResult.wp_logged_in;

                console.log('🔐 Auth states verified:', {
                    jwt: jwtSet,
                    wallet: walletSet,
                    wpLoggedIn: wpLoggedIn
                });

                if (!jwtSet || !walletSet) {
                    throw new Error('Incomplete authentication setup');
                }
                // Require real WP session for "successful login".
                // Wallet deeplinks can return in a new tab; validate-session will also repair WP cookies if needed.
                const validate = await withFetchRetry(() =>
                    window.growlAPI.get('/auth/validate-session')
                );
                if (!isCurrent()) {
                    throw new Error('Wallet connection was replaced by a newer attempt');
                }
                if (!validate?.success) {
                    throw new Error('Login incomplete. Please enable cookies and try again.');
                }
                if (!validate?.wp_logged_in) {
                    throw new Error('Sign-in could not be completed. Please try again.');
                }
                if (window.growlConfig) {
                    window.growlConfig.isUserLoggedIn = true;
                }

                // Update store
                if (window.growlStore) {
                    window.growlStore.user = validate.user || authResult.user;
                    window.growlStore.wallet = walletData;
                    window.growlStore.balance = parseFloat(authResult.user.usd_balance) || 0;
                    window.growlStore.lockedBalance = parseFloat(authResult.user.locked_usd_balance) || 0;
                    window.growlStore.isLandingPage = false;
                    if (authResult.user && authResult.user.is_restricted && authResult.user.restriction_notice_pending) {
                        window.growlStore.showRestrictionModal = true;
                    }
                }
                window.growlHelpers?.dispatchUserLoggedIn?.(validate.user || authResult.user);

                try {
                    const wt = String(storedWalletType || '').toLowerCase();
                    const ch = String(validate.user?.client_channel || authResult.user?.client_channel || '').toLowerCase();
                    if (wt.includes('seeker') || ch === 'solana_seeker' || /solana mobile|seed vault/i.test(wt)) {
                        window.dispatchEvent(new CustomEvent('earnfi:verify-seeker', {
                            detail: { purpose: 'profile', source: 'wallet-auth', silent: true },
                        }));
                    }
                } catch (_) { /* ignore */ }

                // Wait for cookies and verify authentication
                console.log('⏳ Finalizing authentication...');
                await new Promise(resolve => setTimeout(resolve, 1000));
                if (!isCurrent()) {
                    throw new Error('Wallet connection was replaced by a newer attempt');
                }

                // Final verification
                const verifyResponse = await withFetchRetry(() =>
                    window.growlAPI.getUserProfile()
                );
                if (!verifyResponse.success) {
                    throw new Error('Final authentication verification failed');
                }

                console.log('✅ All authentication components verified');

                try {
                    if (window.opener && typeof window.opener.postMessage === 'function') {
                        window.opener.postMessage({ type: 'growl-wallet-auth-complete' }, window.location.origin);
                    }
                } catch (e) { /* ignore */ }
                try {
                    if (typeof BroadcastChannel !== 'undefined') {
                        const bc = new BroadcastChannel('growl_wallet_auth');
                        bc.postMessage({ type: 'growl-wallet-auth-complete', t: Date.now() });
                        bc.close();
                    }
                } catch (e) { /* ignore */ }

                if (!isCurrent()) {
                    throw new Error('Wallet connection was replaced by a newer attempt');
                }

                connectionResult.value = {
                    success: true,
                    ...walletData,
                    ...authResult.user,
                    sessionReady: true,
                    authStates: {
                        jwt: jwtSet,
                        wallet: walletSet,
                        wpLoggedIn: wpLoggedIn
                    }
                };

                try {
                    window.dispatchEvent(new CustomEvent('growlWalletReady', {
                        detail: {
                            walletAddress: walletData.walletAddress,
                            walletType: storedWalletType,
                        },
                    }));
                } catch (_) {
                    /* ignore */
                }

            } catch (error) {
                if (!isCurrent() || /replaced by a newer attempt/i.test(String(error?.message || ''))) {
                    throw error;
                }
                console.error('❌ Sign-in failed:', error);
                window.growlWalletManager?.clearAuthChallenge?.(walletData.challengeId);
                // SIWS / session failures must drop MWA cache so Try Again shows the wallet picker
                // instead of silently reusing Backpack/Phantom auth and looping on expired challenges.
                try {
                    await window.growlWalletManager?.markMwaAuthFailed?.();
                } catch (_) {
                    /* ignore */
                }

                // Never wipe an existing logged-in session from a wallet-only / reattach failure.
                const preserveSession = !!(
                    props.walletOnlyMode
                    || (store.user && (store.user.user_id || store.user.wp_user_id || store.user.wallet_address))
                    || localStorage.getItem('growl_jwt')
                );
                if (!preserveSession) {
                    localStorage.removeItem('growl_jwt');
                    localStorage.removeItem('growl_wallet');
                    localStorage.removeItem('growl_network');
                    localStorage.removeItem('growl_wallet_type');
                    
                    if (window.growlStore) {
                        window.growlStore.user = null;
                        window.growlStore.isLandingPage = false;
                    }
                }
                
                throw new Error(error.message || 'Sign-in did not complete. Please try again.');
            }
        };
        
        const proceedToApp = async () => {
            try {
                proceeding.value = true;
                emit('connected', connectionResult.value);
                emit('close');
            } catch (error) {
                console.error('Error closing wallet modal:', error);
            } finally {
                proceeding.value = false;
            }
        };
        
        const resetConnection = () => {
            connectAttemptId.value += 1;
            window.growlWalletManager?.cancelPendingConnection?.();
            connecting.value = false;
            proceeding.value = false;
            connectingWallet.value = '';
            connectionResult.value = null;

            Vue.nextTick(() => {
                if (typeof lucide !== 'undefined') {
                    lucide.createIcons();
                }
            });
        };
        
        const handleClose = () => {
            if (connecting.value && window.growlWalletManager) {
                // Cancel in-flight connect only — do NOT full-logout (clears JWT).
                try {
                    window.growlWalletManager.cancelPendingConnection?.();
                } catch (_) {
                    /* ignore */
                }
            }
            resetConnection();
            try {
                store.walletModalPurpose = '';
                store.walletModalPreferredNetwork = '';
            } catch (_) { /* ignore */ }
            emit('close');
        };
        
        const truncateWallet = (address) => {
            if (!address) return '';
            return `${address.slice(0, 6)}...${address.slice(-4)}`;
        };
        
        window.growlRunMobileWalletReturnIfNeeded = maybeHandleMobileWalletReturn;

        Vue.onMounted(async () => {
            // Prefetch networks + wallets so the next open paints ready content.
            try {
                await window.EarnfiWalletContext?.refreshNetworks?.();
            } catch (_) { /* ignore */ }
            refreshReferralState();
            window.growlWalletManager?.preloadAuthChallenge?.(currentNetwork.value, 'login');
            try {
                await initializeWallets();
            } catch (_) { /* ignore */ }
            if (props.visible) {
                await prepareModalOpen();
            }
        });

        const prepareModalOpen = async () => {
            // Sync reset first so the previous login success never paints.
            connectionResult.value = null;
            connecting.value = false;
            proceeding.value = false;
            connectingWallet.value = '';
            baseEvmConnecting.value = false;
            nearConnecting.value = false;
            modalContentReady.value = false;
            refreshReferralState();
            try {
                await refreshWalletPaymentContext();
                window.growlWalletManager?.preloadAuthChallenge?.(currentNetwork.value, 'login');
                await initializeWallets();
            } finally {
                // Paint auth extras (Google container) first — GIS needs the live ref.
                modalContentReady.value = true;
                Vue.nextTick(() => {
                    try {
                        window.lucide?.createIcons?.();
                    } catch (_) { /* ignore */ }
                    if (showGoogleSignIn.value) {
                        initGoogleButton().catch(() => {});
                    }
                });
            }
        };

        // Sync flush so a prior “Connected” screen never paints for one frame.
        Vue.watch(
            () => props.visible,
            (v) => {
                if (!v) {
                    modalContentReady.value = false;
                    connectionResult.value = null;
                    connecting.value = false;
                    proceeding.value = false;
                    return;
                }
                connectionResult.value = null;
                connecting.value = false;
                proceeding.value = false;
                connectingWallet.value = '';
                modalContentReady.value = false;
            },
            { flush: 'sync' },
        );

        Vue.watch(
            () => props.visible,
            async (v) => {
                if (!v) return;
                try {
                    await prepareModalOpen();
                } catch (e) {
                    console.error('Wallet modal open init failed:', e);
                    modalContentReady.value = true;
                }
            },
        );

        Vue.watch(
            () => props.walletOnlyMode,
            () => {
                if (props.visible && showGoogleSignIn.value) {
                    Vue.nextTick(() => initGoogleButton()).catch(() => {});
                }
            }
        );

        // Re-run lucide icons immediately when connectionResult is set so check-circle shows without delay
        Vue.watch(
            () => connectionResult.value,
            (v) => {
                if (v) {
                    Vue.nextTick(() => {
                        try {
                            const l = typeof globalThis !== 'undefined' ? globalThis.lucide : null;
                            if (l?.createIcons) l.createIcons();
                            else if (typeof window !== 'undefined' && window.lucide?.createIcons) window.lucide.createIcons();
                        } catch (_e) { /* ignore */ }
                    });
                }
            }
        );
        
        return {
            store,
            walletOnlyMode: props.walletOnlyMode,
            showCloseButton,
            connecting,
            proceeding,
            connectingWallet,
            connectionResult,
            supportedWallets,
            currentNetwork,
            showNetworkWarning,
            isLandingPage,
            hasUndetectedWallets,
            needWalletLinks,
            showAuthExtras,
            showAuthWalletDivider,
            modalPurpose,
            networkLogoClass,
            modalContentReady,
            undetectedWallets,
            isMobileDevice,
            deepLinks,
            showGoogleSignIn,
            signInWithGoogle,
            googleBtnContainer,
            googleInitialized,
            mobileAppConnect,
            connectWallet,
            proceedToApp,
            resetConnection,
            handleClose,
            truncateWallet,
            getWalletLogoPath,
            getConnectedWalletLogo,
            getConnectedWalletLogoClass,
            useSolanaMobilePhoneIcon,
            walletDisplayName,
            showSeekerConnectButton,
            walletsForPicker,
            connectSeekerConnect,
            isWalletSelectable,
            isMobileDeepLinkOnly,
            referralLocked,
            showReferralSection,
            showWalletOrDivider,
            browserWalletSectionTitle,
            referralPanelOpen,
            pendingReferralDisplay,
            referralExpanded,
            manualReferralInput,
            persistManualReferral,
            expandReferralEntry,
            toggleReferralPanel,
            collapseManualReferral,
            showNetworkStep,
            networkTilesForModal,
            networkStepHeading,
            selectedPaymentNetwork,
            selectedPaymentNetworkLabel,
            selectedNetworkIsSolana,
            selectedNetworkIsBase,
            selectedNetworkIsNear,
            paymentsCopy,
            baseEvmAddress,
            baseEvmConnecting,
            baseWalletOptions,
            connectBaseEvmForPayment,
            nearAccountId,
            nearConnecting,
            nearWalletOptions,
            connectNearForPayment,
            showSolanaWalletPicker,
            isSolanaNativeContext,
            networksLoading,
            selectPaymentNetwork,
            switchToSolanaPaymentNetwork,
            switchToSolanaAndStay,
            refreshWalletPaymentContext,
            modalStep,
            modalTitle,
            modalSubtitle,
            showStepDots,
            showBackInHeader,
            continueToMethods,
            goBackStep,
            networkLogo,
        };
    }
};

// Register component
if (typeof window !== 'undefined') {
    window.registerGrowlComponent('WalletConnectModal', WalletConnectModal);
}
