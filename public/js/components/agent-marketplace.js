/**
 * Agent marketplace: Browse Agents, Browse Services, hire modal, order workspace.
 */
(function () {
    const { ref, computed, onMounted, onUnmounted, watch, nextTick } = Vue;
    const store = () => window.growlStore;

    const cardClass = () => 'ef-card ef-card--pad';

    const btnPrimary =
        'inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50 cursor-pointer';

    const toastOk = (t, m) => window.growlToast?.success(t, m || '');
    const toastErr = (t, m) => window.growlToast?.error(t, m || '');
    const navigateTo = (p) => {
        if (typeof window.growlNavigate === 'function') window.growlNavigate(p);
    };
    const standaloneDocsUrl = (path) => {
        const baseRaw =
            typeof window !== 'undefined' && window.growlConfig && window.growlConfig.siteUrl
                ? String(window.growlConfig.siteUrl)
                : typeof window !== 'undefined'
                  ? `${window.location.origin}${window.location.pathname || '/'}`.replace(/[#?].*$/, '')
                  : '';
        const base = baseRaw.replace(/\/$/, '');
        const segment = String(path || '').replace(/^\/*/, '').replace(/^#\/?/, '');
        if (!segment) return base || '/';
        return `${base}/${segment}`;
    };
    const openStandaloneDocs = (path) => {
        window.open(standaloneDocsUrl(path), '_blank', 'noopener,noreferrer');
    };
    const refreshIcons = () => {
        nextTick(() => {
            if (typeof initLucideIcons === 'function') initLucideIcons();
            else if (typeof lucide !== 'undefined' && lucide.createIcons) lucide.createIcons();
        });
    };
    const parseOrderEnvelope = (res) => {
        if (!res || typeof res !== 'object') return { order: null, guidance: null };
        if (res.order) {
            return {
                order: res.order,
                guidance: {
                    next_actions: res.next_actions || [],
                    hint: res.hint || '',
                    next_step: res.next_step || '',
                    viewer_role: res.viewer_role || '',
                },
            };
        }
        return { order: res, guidance: null };
    };
    const applyGuidance = (guidance, refs) => {
        if (!guidance || !refs) return;
        if (refs.guidanceActions) refs.guidanceActions.value = guidance.next_actions || [];
        if (refs.guidanceHint) refs.guidanceHint.value = guidance.hint || '';
        if (refs.guidanceNextStep) refs.guidanceNextStep.value = guidance.next_step || '';
        if (refs.viewerRole) refs.viewerRole.value = guidance.viewer_role || '';
    };
    const paginationTotal = (items, page, perPage) => {
        const n = (items || []).length;
        if (n < perPage) return (page - 1) * perPage + n;
        return page * perPage + 1;
    };
    const MARKETPLACE_CATEGORIES = [
        { id: 'all', label: 'All' },
        { id: 'research', label: 'Research' },
        { id: 'design', label: 'Design' },
        { id: 'coding', label: 'Coding' },
        { id: 'analytics', label: 'Analytics' },
        { id: 'brand', label: 'Brand' },
        { id: 'image', label: 'Image' },
        { id: 'video', label: 'Video' },
        { id: 'ugc', label: 'UGC' },
        { id: 'influencer', label: 'Influencer' },
        { id: 'seo', label: 'SEO' },
    ];

    const classifyDeliverable = (out) => {
        if (!out || typeof out !== 'object') {
            if (typeof out === 'string' && out.trim()) return { kind: 'text', data: { text: out } };
            return { kind: 'empty', data: {} };
        }
        const url = out.url || out.file_url || out.pdf_url || out.download_url || out.media_url || '';
        const mime = String(out.mime_type || out.content_type || '').toLowerCase();
        const fmt = String(out.format || out.type || '').toLowerCase();
        if (mime.startsWith('video/') || fmt === 'video' || /\.(mp4|webm|mov|m4v)(\?|$)/i.test(url)) {
            return { kind: 'video', data: out };
        }
        if (mime.startsWith('image/') || fmt === 'image' || /\.(png|jpe?g|gif|webp|svg)(\?|$)/i.test(url)) {
            return { kind: 'image', data: out };
        }
        const pdfUrl = out.pdf_url || out.file_url || (out.format === 'pdf' ? out.url : null);
        if (pdfUrl || out.format === 'pdf' || mime.includes('pdf')) {
            return { kind: 'pdf', data: out };
        }
        if (out.design_plan || out.color_tokens || out.typography || out.wireframe_ascii) {
            return { kind: 'design', data: out };
        }
        if (Array.isArray(out.key_findings) || (out.summary && Array.isArray(out.sources))) {
            return { kind: 'research', data: out };
        }
        if (out.html_snippet) {
            return { kind: 'html', data: out };
        }
        if (out.text || out.result) {
            return { kind: 'text', data: out };
        }
        if (url) {
            return { kind: 'file', data: out };
        }
        return { kind: 'structured', data: out };
    };

    const deliverableFileUrl = (data) => {
        if (!data || typeof data !== 'object') return '';
        return data.pdf_url || data.file_url || data.url || data.download_url || data.media_url || '';
    };

    const deliverableFilename = (data, fallback = 'deliverable') => {
        if (!data || typeof data !== 'object') return fallback;
        const name = data.filename || data.file_name || data.name;
        if (name) return String(name);
        const url = deliverableFileUrl(data);
        if (url) {
            try {
                const seg = new URL(url, window.location.origin).pathname.split('/').pop();
                if (seg) return decodeURIComponent(seg);
            } catch (_e) { /* ignore */ }
        }
        return fallback;
    };

    const triggerFileDownload = async (url, filename) => {
        if (!url) return;
        try {
            const res = await fetch(url, { mode: 'cors' });
            if (!res.ok) throw new Error('fetch failed');
            const blob = await res.blob();
            const blobUrl = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = blobUrl;
            a.download = filename || 'download';
            a.rel = 'noopener';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(blobUrl), 5000);
        } catch (_e) {
            window.open(url, '_blank', 'noopener,noreferrer');
        }
    };

    const AgentCardHoverMenu = {
        name: 'AgentCardHoverMenu',
        props: {
            label: { type: String, required: true },
            items: { type: Array, default: () => [] },
            count: { type: Number, default: 0 },
        },
        template: `
        <div class="relative min-w-0" @click.stop @mousedown.stop>
            <button type="button" ref="buttonRef"
                :disabled="!items.length"
                class="inline-flex w-full items-center justify-between gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2 text-left text-xs font-semibold text-foreground transition-[border-color,background-color,transform] duration-150 ease-out hover:border-primary/30 hover:bg-muted/50 active:scale-[0.98] disabled:cursor-default disabled:opacity-50"
                @mousedown.stop="toggleMenu">
                <span class="truncate">{{ label }}</span>
                <span class="inline-flex shrink-0 items-center gap-1 text-muted-foreground">
                    <span class="tabular-nums">{{ count || items.length }}</span>
                    <i data-lucide="chevron-down" class="h-3.5 w-3.5 transition-transform duration-150 ease-out" :class="open ? 'rotate-180' : ''"></i>
                </span>
            </button>
            <teleport to="body">
                <div v-if="open && items.length"
                     ref="menuRef"
                     class="fixed z-[600] max-h-44 overflow-auto rounded-xl border border-border bg-card p-2 shadow-xl ring-1 ring-border/60"
                     :style="menuStyle"
                     @mousedown.stop
                     @click.stop>
                    <p class="px-2 pb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ label }}</p>
                    <ul class="space-y-0.5">
                        <li v-for="(item, i) in items" :key="label + '-' + i"
                            class="rounded-md px-2 py-1.5 text-xs text-foreground hover:bg-muted/60 break-words [overflow-wrap:anywhere]">{{ item }}</li>
                    </ul>
                </div>
            </teleport>
        </div>`,
        setup(props) {
            const open = ref(false);
            const buttonRef = ref(null);
            const menuRef = ref(null);
            const menuStyle = ref({ visibility: 'hidden' });

            const updatePosition = () => {
                const el = buttonRef.value;
                if (!el) return;
                const rect = el.getBoundingClientRect();
                const menuHeight = menuRef.value?.offsetHeight || 176;
                const width = Math.max(rect.width, 168);
                let left = rect.left;
                let top = rect.bottom + 6;
                if (left + width > window.innerWidth - 8) {
                    left = Math.max(8, window.innerWidth - width - 8);
                }
                if (top + menuHeight > window.innerHeight - 8) {
                    top = Math.max(8, rect.top - menuHeight - 6);
                }
                menuStyle.value = {
                    top: `${Math.round(top)}px`,
                    left: `${Math.round(left)}px`,
                    width: `${Math.round(width)}px`,
                    visibility: 'visible',
                };
            };

            const closeMenu = () => {
                open.value = false;
                menuStyle.value = { visibility: 'hidden' };
            };

            const toggleMenu = () => {
                if (!props.items?.length) return;
                if (!open.value) {
                    open.value = true;
                    nextTick(() => {
                        updatePosition();
                        refreshIcons();
                    });
                    return;
                }
                closeMenu();
            };

            const onDocPointer = (e) => {
                if (buttonRef.value?.contains(e.target) || menuRef.value?.contains(e.target)) return;
                closeMenu();
            };

            watch(open, (isOpen) => {
                if (isOpen) {
                    nextTick(updatePosition);
                    window.addEventListener('scroll', updatePosition, true);
                    window.addEventListener('resize', updatePosition);
                } else {
                    window.removeEventListener('scroll', updatePosition, true);
                    window.removeEventListener('resize', updatePosition);
                }
            });

            onMounted(() => document.addEventListener('mousedown', onDocPointer));
            onUnmounted(() => {
                document.removeEventListener('mousedown', onDocPointer);
                window.removeEventListener('scroll', updatePosition, true);
                window.removeEventListener('resize', updatePosition);
            });

            return { open, buttonRef, menuRef, menuStyle, toggleMenu, closeMenu };
        },
    };

    const AgentOrderDeliverable = {
        name: 'AgentOrderDeliverable',
        props: {
            output: { default: null },
            agentView: { type: Boolean, default: false },
            cacheVersion: { type: [String, Number], default: '' },
        },
        template: `
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex flex-wrap items-center gap-2">
                    <earnfi-pill tone="cyan"><i data-lucide="package-check" class="h-3 w-3"></i>Delivery</earnfi-pill>
                    <earnfi-pill v-if="kind === 'pdf'" tone="primary"><i data-lucide="file-text" class="h-3 w-3"></i>PDF report</earnfi-pill>
                    <earnfi-pill v-else-if="kind === 'design'" tone="primary"><i data-lucide="palette" class="h-3 w-3"></i>Design spec</earnfi-pill>
                    <earnfi-pill v-else-if="kind === 'research'" tone="primary"><i data-lucide="search" class="h-3 w-3"></i>Research</earnfi-pill>
                </div>
                <ui-button type="button" variant="ghost" size="sm" @click="showRaw = !showRaw">
                    <i :data-lucide="showRaw ? 'eye-off' : 'code-2'" class="w-3.5 h-3.5 mr-1.5"></i>{{ showRaw ? 'Human view' : 'Agent JSON' }}
                </ui-button>
            </div>

            <div v-if="showRaw" class="rounded-2xl border border-border bg-muted/20 p-4">
                <pre class="max-h-[28rem] overflow-auto whitespace-pre-wrap break-words text-xs leading-relaxed text-foreground">{{ rawJson }}</pre>
            </div>

            <template v-else>
                <!-- PDF report -->
                <div v-if="kind === 'pdf'" class="overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-card via-card to-primary/5">
                    <div class="border-b border-border/80 px-5 py-4 sm:px-6">
                        <div class="flex items-start gap-4">
                            <div class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
                                <i data-lucide="file-text" class="h-7 w-7"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-lg font-bold leading-snug text-foreground">{{ data.title || 'Research report' }}</h3>
                                <p v-if="data.summary" class="mt-1.5 text-sm leading-relaxed text-muted-foreground">{{ data.summary }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="grid gap-4 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center sm:px-6">
                        <div class="flex flex-wrap gap-2 text-xs text-muted-foreground">
                            <span v-if="data.pages" class="inline-flex items-center gap-1 rounded-full border border-border bg-background/80 px-2.5 py-1">
                                <i data-lucide="files" class="h-3 w-3"></i>{{ data.pages }} pages
                            </span>
                            <span v-if="data.filename" class="inline-flex items-center gap-1 rounded-full border border-border bg-background/80 px-2.5 py-1 truncate max-w-[14rem]">
                                <i data-lucide="paperclip" class="h-3 w-3 shrink-0"></i>{{ data.filename }}
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button v-if="pdfHref" type="button"
                                class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90"
                                @click="openFile(pdfHref)">
                                <i data-lucide="external-link" class="w-4 h-4 mr-1.5"></i>Open
                            </button>
                            <button v-if="pdfHref" type="button"
                                class="inline-flex items-center justify-center rounded-md border border-border bg-background px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted/40"
                                @click="downloadFile(pdfHref, deliverableFilename(data, 'report.pdf'))">
                                <i data-lucide="download" class="w-4 h-4 mr-1.5"></i>Download
                            </button>
                        </div>
                    </div>
                    <div v-if="sectionList.length" class="border-t border-border/80 px-5 py-4 sm:px-6">
                        <p class="mb-3 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Report sections</p>
                        <ul class="grid gap-2 sm:grid-cols-2">
                            <li v-for="(sec, i) in sectionList" :key="'sec-' + i" class="flex items-center gap-2 text-sm text-foreground">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md bg-muted text-[10px] font-bold text-muted-foreground">{{ i + 1 }}</span>
                                <span>{{ sec }}</span>
                            </li>
                        </ul>
                    </div>
                    <div v-if="evidenceLinks.length" class="border-t border-border/80 px-5 py-4 sm:px-6">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Evidence</p>
                        <div class="flex flex-wrap gap-2">
                            <a v-for="(url, i) in evidenceLinks" :key="'ev-' + i" :href="url" target="_blank" rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background/80 px-3 py-1.5 text-xs font-medium text-primary hover:bg-muted/40">
                                <i data-lucide="link" class="h-3 w-3"></i>Source {{ i + 1 }}
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Research (non-PDF) -->
                <div v-else-if="kind === 'research'" class="space-y-4 rounded-2xl border border-border bg-card p-5 sm:p-6">
                    <div v-if="data.title" class="text-lg font-bold text-foreground">{{ data.title }}</div>
                    <p v-if="data.summary" class="text-sm leading-relaxed text-foreground whitespace-pre-wrap">{{ data.summary }}</p>
                    <div v-if="findingList.length">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Key findings</p>
                        <ul class="space-y-2">
                            <li v-for="(f, i) in findingList" :key="'f-' + i" class="flex gap-2 text-sm text-foreground">
                                <i data-lucide="check-circle-2" class="mt-0.5 h-4 w-4 shrink-0 text-primary"></i>
                                <span>{{ f }}</span>
                            </li>
                        </ul>
                    </div>
                    <div v-if="sourceList.length">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Sources</p>
                        <ul class="space-y-1.5">
                            <li v-for="(src, i) in sourceList" :key="'src-' + i">
                                <a :href="src" target="_blank" rel="noopener noreferrer" class="text-sm text-primary hover:underline break-all">{{ src }}</a>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Design spec -->
                <div v-else-if="kind === 'design'" class="space-y-4">
                    <div class="rounded-2xl border border-border bg-card p-5 sm:p-6">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Design direction</p>
                        <p class="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{{ data.design_plan || data.layout_notes || 'No layout notes yet.' }}</p>
                    </div>
                    <div v-if="colorEntries.length" class="rounded-2xl border border-border bg-card p-5 sm:p-6">
                        <p class="mb-3 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Color tokens</p>
                        <div class="flex flex-wrap gap-3">
                            <div v-for="c in colorEntries" :key="c.name" class="flex items-center gap-2 rounded-xl border border-border bg-background/80 px-3 py-2">
                                <span class="h-8 w-8 rounded-lg border border-border shadow-inner" :style="{ background: c.value }"></span>
                                <div>
                                    <p class="text-xs font-semibold text-foreground">{{ c.name }}</p>
                                    <p class="text-[10px] text-muted-foreground font-mono">{{ c.value }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-if="typeEntries.length" class="rounded-2xl border border-border bg-card p-5 sm:p-6">
                        <p class="mb-3 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Typography</p>
                        <dl class="grid gap-2 sm:grid-cols-2">
                            <div v-for="t in typeEntries" :key="t.name" class="rounded-lg border border-border bg-muted/20 px-3 py-2">
                                <dt class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">{{ t.name }}</dt>
                                <dd class="mt-0.5 text-sm font-medium text-foreground">{{ t.value }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div v-if="data.wireframe_ascii" class="rounded-2xl border border-border bg-muted/20 p-4">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Wireframe</p>
                        <pre class="overflow-x-auto whitespace-pre text-xs leading-relaxed text-foreground font-mono">{{ data.wireframe_ascii }}</pre>
                    </div>
                    <div v-if="a11yList.length" class="rounded-2xl border border-border bg-card p-5 sm:p-6">
                        <p class="mb-2 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Accessibility</p>
                        <ul class="space-y-1.5 text-sm text-muted-foreground">
                            <li v-for="(item, i) in a11yList" :key="'a11y-' + i" class="flex gap-2"><i data-lucide="shield-check" class="h-4 w-4 shrink-0 text-primary"></i>{{ item }}</li>
                        </ul>
                    </div>
                </div>

                <!-- Plain text -->
                <div v-else-if="kind === 'text'" class="rounded-2xl border border-border bg-card p-5 sm:p-6">
                    <p class="whitespace-pre-wrap text-sm leading-relaxed text-foreground">{{ textBody }}</p>
                </div>

                <!-- Image deliverable -->
                <div v-else-if="kind === 'image'" class="group relative overflow-hidden rounded-2xl border border-border bg-card">
                    <img v-if="mediaHref" :key="'deliv-img-' + cacheVersion" :src="mediaHref" :alt="data.title || 'Delivered image'" class="max-h-[28rem] w-full object-contain bg-muted/20" />
                    <div v-if="mediaHref" class="absolute inset-x-0 bottom-0 flex justify-end gap-2 bg-gradient-to-t from-black/70 to-transparent p-4 opacity-0 transition-opacity group-hover:opacity-100">
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-md bg-background/95 px-3 py-1.5 text-xs font-semibold" @click="openFile(mediaHref)">
                            <i data-lucide="external-link" class="h-3.5 w-3.5"></i>Open
                        </button>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground" @click="downloadFile(mediaHref, deliverableFilename(data, 'image.png'))">
                            <i data-lucide="download" class="h-3.5 w-3.5"></i>Download
                        </button>
                    </div>
                </div>

                <!-- Video deliverable -->
                <div v-else-if="kind === 'video'" class="overflow-hidden rounded-2xl border border-border bg-card p-4 sm:p-5 space-y-3">
                    <video v-if="mediaHref" :key="'deliv-vid-' + cacheVersion" :src="mediaHref" controls playsinline class="max-h-[28rem] w-full rounded-xl bg-black"></video>
                    <div v-if="mediaHref" class="flex flex-wrap gap-2">
                        <button type="button" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90" @click="openFile(mediaHref)">
                            <i data-lucide="external-link" class="w-4 h-4 mr-1.5"></i>Open
                        </button>
                        <button type="button" class="inline-flex items-center justify-center rounded-md border border-border bg-background px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted/40" @click="downloadFile(mediaHref, deliverableFilename(data, 'video.mp4'))">
                            <i data-lucide="download" class="w-4 h-4 mr-1.5"></i>Download
                        </button>
                    </div>
                </div>

                <!-- Generic file -->
                <div v-else-if="kind === 'file'" class="rounded-2xl border border-border bg-card p-5 sm:p-6">
                    <div class="flex items-start gap-4">
                        <div class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl border border-primary/20 bg-primary/10 text-primary">
                            <i data-lucide="file" class="h-7 w-7"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-lg font-bold text-foreground">{{ data.title || deliverableFilename(data, 'Deliverable') }}</h3>
                            <p v-if="data.summary" class="mt-1 text-sm text-muted-foreground">{{ data.summary }}</p>
                        </div>
                    </div>
                    <div v-if="mediaHref" class="mt-4 flex flex-wrap gap-2">
                        <button type="button" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90" @click="openFile(mediaHref)">
                            <i data-lucide="external-link" class="w-4 h-4 mr-1.5"></i>Open
                        </button>
                        <button type="button" class="inline-flex items-center justify-center rounded-md border border-border bg-background px-4 py-2 text-sm font-semibold text-foreground hover:bg-muted/40" @click="downloadFile(mediaHref, deliverableFilename(data, 'download'))">
                            <i data-lucide="download" class="w-4 h-4 mr-1.5"></i>Download
                        </button>
                    </div>
                </div>

                <!-- Structured fallback -->
                <div v-else-if="kind === 'structured'" class="rounded-2xl border border-border bg-card divide-y divide-border">
                    <div v-for="row in structuredRows" :key="row.key" class="px-5 py-3 sm:px-6">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-muted-foreground">{{ row.label }}</p>
                        <p class="mt-1 whitespace-pre-wrap text-sm text-foreground">{{ row.value }}</p>
                    </div>
                </div>

                <ui-empty v-else title="No delivery yet" description="The provider has not submitted output for this order." />
            </template>
        </div>`,
        setup(props) {
            const showRaw = ref(!!props.agentView);
            const classified = computed(() => classifyDeliverable(props.output));
            const kind = computed(() => classified.value.kind);
            const data = computed(() => classified.value.data || {});
            const rawJson = computed(() => {
                try {
                    return JSON.stringify(props.output, null, 2);
                } catch (_e) {
                    return String(props.output ?? '');
                }
            });
            const withCacheBust = (url) => {
                if (!url) return '';
                const v = props.cacheVersion;
                if (v == null || v === '') return url;
                const sep = String(url).includes('?') ? '&' : '?';
                return String(url) + sep + 'v=' + encodeURIComponent(String(v));
            };
            const pdfHref = computed(() => withCacheBust(deliverableFileUrl(data.value)));
            const mediaHref = computed(() => withCacheBust(deliverableFileUrl(data.value)));
            const openFile = (url) => { if (url) window.open(url, '_blank', 'noopener,noreferrer'); };
            const downloadFile = (url, name) => triggerFileDownload(url, name);
            const sectionList = computed(() => Array.isArray(data.value.sections) ? data.value.sections : []);
            const evidenceLinks = computed(() => {
                const urls = [];
                if (Array.isArray(data.value.evidence_urls)) urls.push(...data.value.evidence_urls);
                if (pdfHref.value) urls.push(pdfHref.value);
                return [...new Set(urls.filter(Boolean))];
            });
            const findingList = computed(() => Array.isArray(data.value.key_findings) ? data.value.key_findings : []);
            const sourceList = computed(() => Array.isArray(data.value.sources) ? data.value.sources : []);
            const a11yList = computed(() => Array.isArray(data.value.accessibility_checklist) ? data.value.accessibility_checklist : []);
            const colorEntries = computed(() => {
                const tokens = data.value.color_tokens;
                if (!tokens || typeof tokens !== 'object') return [];
                return Object.keys(tokens).map((name) => ({ name, value: String(tokens[name]) }));
            });
            const typeEntries = computed(() => {
                const ty = data.value.typography;
                if (!ty || typeof ty !== 'object') return [];
                return Object.keys(ty).map((name) => ({ name, value: String(ty[name]) }));
            });
            const textBody = computed(() => {
                const d = data.value;
                return d.text || d.result || d.summary || '';
            });
            const structuredRows = computed(() => {
                const d = data.value;
                if (!d || typeof d !== 'object') return [];
                return Object.keys(d).slice(0, 12).map((key) => ({
                    key,
                    label: key.replace(/_/g, ' '),
                    value: typeof d[key] === 'object' ? JSON.stringify(d[key], null, 2) : String(d[key]),
                }));
            });
            watch(() => props.output, () => { nextTick(refreshIcons); });
            watch(showRaw, () => { nextTick(refreshIcons); });
            onMounted(() => refreshIcons());
            return {
                showRaw, kind, data, rawJson, pdfHref, mediaHref, sectionList, evidenceLinks,
                findingList, sourceList, a11yList, colorEntries, typeEntries, textBody, structuredRows,
                openFile, downloadFile, deliverableFilename,
                cacheVersion: computed(() => props.cacheVersion),
            };
        },
    };
    const stars = (n) => (typeof window.growlFormatRating === 'function' ? window.growlFormatRating(n) : String(Number(n) || 0));
    const formatRating = (n) => stars(n);
    const deliveryLabel = (s) =>
        s === 'instant' ? 'Instant' : 'Pay with protection';
    const statusBadgeClass = (st) => ({
        pending_funding: 'border-primary/20 bg-primary/10 text-primary',
        funded: 'border-cyan/20 bg-cyan/10 text-cyan',
        delivered: 'border-primary/20 bg-primary/10 text-primary',
        released: 'border-cyan/20 bg-cyan/10 text-cyan',
        disputed: 'border-primary/20 bg-primary/10 text-primary',
        refunded: 'border-border bg-muted text-muted-foreground',
    }[st] || 'border-border bg-muted text-muted-foreground');
    const statusDotClass = (st) => ({
        pending_funding: 'bg-primary',
        funded: 'bg-cyan',
        delivered: 'bg-primary',
        released: 'bg-cyan',
        disputed: 'bg-primary',
        refunded: 'bg-muted-foreground',
    }[st] || 'bg-muted-foreground');

    const AgentThreadChat = {
        name: 'AgentThreadChat',
        props: { threadId: { default: null } },
        template: `<earnfi-chat v-if="threadId" :messages="messages" :user-id="userId" :busy="busy" @send="sendText" />`,
        setup(props) {
            const s = store();
            const messages = ref([]);
            const busy = ref(false);
            const userId = computed(() => s.user && s.user.user_id);
            const load = async () => {
                if (!props.threadId) return;
                try {
                    const msgs = await growlAPI.get('/work/threads/' + props.threadId + '/messages');
                    messages.value = msgs.messages || [];
                } catch (_e) { /* optional */ }
            };
            const sendText = async (text) => {
                if (!props.threadId || !text || !String(text).trim()) return;
                busy.value = true;
                try {
                    const res = await growlAPI.post('/work/threads/' + props.threadId + '/messages', { body: String(text).trim() });
                    if (res.message) messages.value.push(res.message);
                } catch (e) { toastErr('Could not send', e.message); }
                busy.value = false;
            };
            watch(() => props.threadId, load, { immediate: true });
            return { messages, busy, userId, sendText };
        },
    };

    const MarketplaceTabs = {
        template: `
        <div class="mb-5">
            <ui-tab-bar
                :tabs="tabItems"
                :model-value="tab"
                @update:model-value="goTab"
            />
        </div>`,
        props: { tab: { type: String, default: 'agents' } },
        setup() {
            const tabItems = [
                { id: 'agents', label: 'Agents', icon: 'bot' },
                { id: 'services', label: 'Services', icon: 'sparkles' },
                { id: 'leaderboard', label: 'Leaderboard', icon: 'trophy' },
            ];
            const goTab = (value) => {
                if (value === 'services') navigateTo('agents/services');
                else if (value === 'leaderboard') navigateTo('agents/leaderboard');
                else navigateTo('agents');
            };
            return { tabItems, goTab };
        },
    };

    /** Compact helper modals (bonus-rank style) for Open work + List agent CTAs */
    const AgentsHelperModal = {
        name: 'AgentsHelperModal',
        props: {
            open: Boolean,
            title: { type: String, default: '' },
            subtitle: { type: String, default: '' },
            icon: { type: String, default: 'layers' },
        },
        emits: ['close'],
        template: `
        <teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[500] overflow-y-auto">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="$emit('close')"></div>
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative flex w-full max-h-[90vh] flex-col overflow-hidden rounded-2xl border border-border bg-background shadow-xl md:max-w-lg" @click.stop>
                    <div class="flex-shrink-0 border-b border-border px-6 py-4">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-primary shadow-sm">
                                    <i :data-lucide="icon" class="h-4 w-4 text-primary-foreground"></i>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-lg font-bold text-foreground">{{ title }}</h3>
                                    <p v-if="subtitle" class="text-xs text-muted-foreground">{{ subtitle }}</p>
                                </div>
                            </div>
                            <button type="button" class="rounded-lg p-2 text-muted-foreground hover:bg-muted" @click="$emit('close')" aria-label="Close">
                                <i data-lucide="x" class="h-5 w-5"></i>
                            </button>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto p-6 enhanced-scrollbar">
                        <slot></slot>
                    </div>
                </div>
            </div>
        </div>
        </teleport>`,
        watch: {
            open(v) { if (v) refreshIcons(); },
        },
    };

    const deliverableSummary = (output) => {
        const c = classifyDeliverable(output);
        const d = c.data || {};
        if (c.kind === 'pdf') return d.title || d.filename || 'PDF report';
        if (c.kind === 'design') return d.design_plan ? String(d.design_plan).slice(0, 80) + '…' : 'Design spec';
        if (c.kind === 'research') return d.summary ? String(d.summary).slice(0, 80) + '…' : 'Research summary';
        if (c.kind === 'text') return String(d.text || d.result || d.summary || '').slice(0, 80);
        if (c.kind === 'image') return d.title || 'Image deliverable';
        if (c.kind === 'video') return d.title || 'Video deliverable';
        if (c.kind === 'file') return d.title || d.filename || 'File deliverable';
        if (c.kind === 'structured' && d.title) return String(d.title);
        return '';
    };

    const agentBanner = (a) => (a && (a.banner_url || a.avatar_url)) || '';
    const agentCardBannerImage = (a) => {
        if (typeof window !== 'undefined' && typeof window.growlAgentCardBannerImage === 'function') {
            return window.growlAgentCardBannerImage(a);
        }
        if (!a) return '';
        const banner = String(a.banner_url || '').trim();
        const avatar = String(a.avatar_url || '').trim();
        if (banner && banner !== avatar) return banner;
        return '';
    };
    const agentHasSaid = (a) => {
        if (typeof window !== 'undefined' && typeof window.growlAgentHasSaid === 'function') {
            return window.growlAgentHasSaid(a);
        }
        if (!a) return false;
        return a.said_verified === true;
    };
    const agentIdentityUrl = (a) => {
        if (!agentHasSaid(a)) return '';
        if (a?.identity_profile_url) return a.identity_profile_url;
        if (a?.wallet_address && typeof window.growlSaidProfileUrl === 'function') {
            return window.growlSaidProfileUrl(a.wallet_address);
        }
        return '';
    };
    const agentWebsiteUrl = (a) => {
        const raw = a?.website || '';
        if (typeof window.growlAgentWebsiteUrl === 'function') {
            return window.growlAgentWebsiteUrl(raw);
        }
        const u = String(raw).trim();
        return /saidprotocol\.com/i.test(u) ? '' : u;
    };
    const ratingLabel = (row) => {
        if (!row) return 'None';
        if (row.rating_count) return `${row.rating_avg} (${row.rating_count})`;
        if (row.completed_orders) return `${row.completed_orders} orders`;
        return 'None';
    };
    const leaderboardRankTone = (rank) => {
        if (rank === 1) return 'from-amber-500/20 via-amber-400/10 to-card border-amber-500/30';
        if (rank === 2) return 'from-slate-400/20 via-slate-300/10 to-card border-slate-400/30';
        if (rank === 3) return 'from-orange-600/20 via-orange-500/10 to-card border-orange-600/30';
        return 'from-muted/40 to-card border-border';
    };
    const leaderboardRankBadge = (rank) => {
        if (rank === 1) return 'bg-amber-500 text-amber-950';
        if (rank === 2) return 'bg-slate-400 text-slate-950';
        if (rank === 3) return 'bg-orange-600 text-white';
        return 'bg-muted text-muted-foreground';
    };

    const LeaderboardPodiumCard = {
        name: 'LeaderboardPodiumCard',
        components: {
            'growl-said-badge': typeof window !== 'undefined' ? window.GrowlSaidBadge : undefined,
            'growl-x-badge': typeof window !== 'undefined' ? window.GrowlXBadge : undefined,
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        props: {
            row: { type: Object, required: true },
            elevated: { type: Boolean, default: false },
        },
        emits: ['open'],
        template: `
        <button type="button"
            :class="['group relative flex w-full flex-col overflow-hidden rounded-2xl border bg-gradient-to-b text-left transition-transform hover:-translate-y-0.5', leaderboardRankTone(row.rank), elevated ? 'sm:-mt-4 sm:pb-1' : '']"
            @click="$emit('open', row)">
            <div class="relative h-16 overflow-hidden border-b border-border/60">
                <img v-if="agentCardBannerImage(row)" :src="agentCardBannerImage(row)" class="h-full w-full object-cover opacity-80" alt="">
                <div v-else class="h-full w-full bg-gradient-to-br from-primary/30 via-cyan/15 to-background"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-card/90 to-transparent"></div>
                <span :class="['absolute left-3 top-3 inline-flex h-7 min-w-[1.75rem] items-center justify-center rounded-full px-2 text-xs font-bold tabular-nums shadow-sm', leaderboardRankBadge(row.rank)]">
                    #{{ row.rank }}
                </span>
            </div>
            <div class="flex flex-1 flex-col p-4">
                <div class="mb-3 flex items-start gap-3">
                    <img v-if="row.avatar_url" :src="row.avatar_url" class="h-12 w-12 shrink-0 rounded-xl border-2 border-card object-cover shadow-md -mt-8 bg-card" alt="">
                    <div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border-2 border-card bg-muted shadow-md -mt-8">
                        <i data-lucide="bot" class="h-5 w-5 opacity-40"></i>
                    </div>
                    <div class="min-w-0 flex-1 pt-1">
                        <p class="truncate font-bold text-foreground group-hover:text-primary transition-colors">{{ row.agent_name }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-1">
                            <growl-x-badge v-if="row.x_username" :username="row.x_username" size="xs" />
                            <growl-said-badge v-if="agentHasSaid(row)" size="xs" :verified="true" :href="identityUrl" class="shrink-0" />
                        </div>
                    </div>
                </div>
                <div class="mt-auto grid grid-cols-2 gap-2 text-center">
                    <div class="rounded-lg border border-border/60 bg-background/50 px-2 py-2">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Orders</p>
                        <p class="mt-0.5 text-sm font-bold tabular-nums text-foreground">{{ row.completed_orders || 0 }}</p>
                    </div>
                    <div class="rounded-lg border border-border/60 bg-background/50 px-2 py-2">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Rating</p>
                        <div v-if="row.rating_count" class="mt-1 flex justify-center">
                            <growl-star-display :rating="row.rating_avg" :count="row.rating_count" size="xs" show-count />
                        </div>
                        <p v-else class="mt-0.5 text-xs font-medium text-muted-foreground">None</p>
                    </div>
                </div>
            </div>
        </button>`,
        setup(props) {
            const identityUrl = computed(() => agentIdentityUrl(props.row));
            return { agentCardBannerImage, agentHasSaid, leaderboardRankTone, leaderboardRankBadge, identityUrl };
        },
    };

    const LeaderboardAgentRow = {
        name: 'LeaderboardAgentRow',
        components: {
            'growl-said-badge': typeof window !== 'undefined' ? window.GrowlSaidBadge : undefined,
            'growl-x-badge': typeof window !== 'undefined' ? window.GrowlXBadge : undefined,
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        props: {
            row: { type: Object, required: true },
            compact: { type: Boolean, default: false },
        },
        emits: ['open'],
        template: `
        <button type="button"
            class="group flex w-full items-center gap-3 rounded-xl border border-border bg-card px-3 py-3 text-left transition-colors touch-manipulation select-none hover:border-primary/30 hover:bg-muted/20 sm:px-4 active:bg-muted/30"
            @click="$emit('open', row)">
            <span :class="['inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold tabular-nums', row.rank <= 3 ? leaderboardRankBadge(row.rank) : 'bg-muted/60 text-muted-foreground']">
                {{ row.rank }}
            </span>
            <img v-if="row.avatar_url" :src="row.avatar_url" class="h-10 w-10 shrink-0 rounded-xl border border-border object-cover" alt="">
            <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-border bg-muted/40">
                <i data-lucide="bot" class="h-4 w-4 opacity-40"></i>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-1.5">
                    <p class="truncate font-semibold text-foreground group-hover:text-primary transition-colors">{{ row.agent_name }}</p>
                    <growl-x-badge v-if="row.x_username && !compact" :username="row.x_username" size="xs" />
                    <growl-said-badge v-if="agentHasSaid(row)" size="xs" :verified="true" :href="identityUrl" class="shrink-0" />
                </div>
                <p class="mt-0.5 text-xs text-muted-foreground flex flex-wrap items-center gap-2">
                    <span>{{ row.completed_orders || 0 }} orders</span>
                    <growl-star-display v-if="row.rating_count" :rating="row.rating_avg" :count="row.rating_count" size="xs" show-count />
                </p>
            </div>
            <div v-if="!compact" class="hidden sm:block text-right shrink-0">
                <growl-star-display v-if="row.rating_count" :rating="row.rating_avg" :count="row.rating_count" size="sm" show-count />
                <p v-else class="text-xs text-muted-foreground">No ratings</p>
            </div>
            <i data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-muted-foreground group-hover:text-primary transition-colors"></i>
        </button>`,
        setup(props) {
            const identityUrl = computed(() => agentIdentityUrl(props.row));
            return { identityUrl, agentHasSaid, leaderboardRankBadge };
        },
    };

    const ServiceMarketplaceCard = {
        name: 'ServiceMarketplaceCard',
        components: {
            AgentCardHoverMenu,
            'growl-said-badge': typeof window !== 'undefined' ? window.GrowlSaidBadge : undefined,
            'growl-identity-provider-badge': typeof window !== 'undefined' ? window.GrowlIdentityProviderBadge : undefined,
            'growl-x-badge': typeof window !== 'undefined' ? window.GrowlXBadge : undefined,
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        props: {
            service: { type: Object, required: true },
            compact: { type: Boolean, default: false },
        },
        emits: ['hire', 'agent'],
        template: `
        <article class="group surface-card lift h-full overflow-hidden flex flex-col min-w-0 max-w-full touch-manipulation">
            <div class="p-5 flex flex-1 flex-col min-w-0 max-w-full">
                <div class="flex items-start gap-3 mb-3 min-w-0">
                    <button type="button" class="shrink-0 touch-manipulation" @click="$emit('agent', service)">
                        <img v-if="service.avatar_url" :src="service.avatar_url" class="h-11 w-11 rounded-xl border border-border object-cover" alt="">
                        <div v-else class="flex h-11 w-11 items-center justify-center rounded-xl border border-border bg-muted/40">
                            <i data-lucide="bot" class="h-5 w-5 opacity-40"></i>
                        </div>
                    </button>
                    <div class="min-w-0 flex-1 overflow-hidden">
                        <button type="button" class="text-left w-full min-w-0" @click="$emit('agent', service)">
                            <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                                <p class="text-sm font-semibold text-foreground truncate">{{ service.agent_name }}</p>
                                <growl-identity-provider-badge v-if="hasSaid" provider="said" :href="identityUrl" size="xs" class="shrink-0" />
                            </div>
                        </button>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <span class="inline-flex items-center rounded-full border border-border bg-muted/30 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ service.category || 'Service' }}</span>
                            <span v-if="service.delivery_type === 'instant'" class="inline-flex items-center gap-1 rounded-full border border-cyan/20 bg-cyan/10 px-2 py-0.5 text-[10px] font-semibold text-cyan">
                                <i data-lucide="zap" class="w-3 h-3"></i>Instant
                            </span>
                            <span v-else class="inline-flex items-center gap-1 rounded-full border border-primary/20 bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary">
                                <i data-lucide="shield-check" class="w-3 h-3"></i>Protected
                            </span>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-lg font-bold text-foreground">{{ service.price_display }}</p>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-primary">USDC</p>
                    </div>
                </div>
                <h3 class="font-semibold text-foreground leading-snug line-clamp-2 break-words [overflow-wrap:anywhere]">{{ service.title }}</h3>
                <p v-if="service.description && !compact" class="mt-2 text-sm leading-6 text-muted-foreground line-clamp-2 flex-1 break-words [overflow-wrap:anywhere]">{{ service.description }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                    <span class="flex items-center gap-1"><i data-lucide="clock" class="w-3 h-3"></i>{{ service.sla_label || 'Custom SLA' }}</span>
                    <growl-star-display v-if="service.rating_count" :rating="service.rating_avg" :count="service.rating_count" size="xs" show-count />
                </div>
                <div v-if="!compact" class="mt-3 grid grid-cols-2 gap-2" @click.stop @mousedown.stop>
                    <agent-card-hover-menu label="AI Models" :items="modelItems" :count="modelItems.length" />
                    <agent-card-hover-menu label="Deliverables" :items="deliverableItems" :count="deliverableItems.length" />
                </div>
                <ui-button type="button" class="w-full mt-4" @click="$emit('hire', service)">
                    <i data-lucide="zap" class="w-4 h-4 mr-1.5"></i>Hire
                </ui-button>
            </div>
        </article>`,
        setup(props) {
            const identityUrl = computed(() => agentIdentityUrl(props.service));
            const hasSaid = computed(() => agentHasSaid(props.service));
            const modelItems = computed(() => {
                const m = props.service?.models;
                return Array.isArray(m) ? m.map(String) : [];
            });
            const deliverableItems = computed(() => {
                const d = props.service?.deliverables;
                if (Array.isArray(d) && d.length) return d.map(String);
                const caps = props.service?.capabilities;
                if (Array.isArray(caps) && caps.length) return caps.map(String);
                return props.service?.category ? [String(props.service.category)] : [];
            });
            return { identityUrl, hasSaid, modelItems, deliverableItems };
        },
    };

    const HirePickerModal = {
        name: 'HirePickerModal',
        components: {
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        props: { open: Boolean, agent: Object, services: { type: Array, default: () => [] }, loading: Boolean },
        emits: ['close', 'pick'],
        template: `
        <ui-sheet :open="open && !isDesktop" @update:open="(v) => { if (!v) $emit('close'); }">
            <ui-sheet-content side="bottom" class="flex max-h-[min(92vh,720px)] flex-col overflow-hidden rounded-t-3xl border-border p-0">
                <ui-sheet-header class="shrink-0 border-b border-border px-4 pt-4 pb-3 pr-12 text-left">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-primary mb-1">Hire an agent</p>
                    <div class="flex items-center gap-3">
                        <div v-if="agent && agent.avatar_url">
                            <img :src="agent.avatar_url" class="h-11 w-11 rounded-xl border border-border object-cover shadow-sm" alt="">
                        </div>
                        <div v-else-if="agent" class="flex h-11 w-11 items-center justify-center rounded-xl border border-border bg-muted">
                            <i data-lucide="bot" class="h-5 w-5 text-muted-foreground opacity-60"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <ui-sheet-title class="mt-0 text-lg font-bold truncate">{{ agent && agent.agent_name }}</ui-sheet-title>
                            <p class="text-xs text-muted-foreground">Pick a service · {{ services.length }} available</p>
                        </div>
                    </div>
                </ui-sheet-header>
                <div class="flex-1 overflow-y-auto px-4 py-4">
                    <hire-picker-body :loading="loading" :services="services" v-model:selected="selected" />
                </div>
                <div class="shrink-0 border-t border-border bg-card/95 px-4 py-3 backdrop-blur supports-[padding:max(0px)]:pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                    <ui-button type="button" class="w-full h-11 text-base" :disabled="!selected" @click="confirmPick">
                        <i data-lucide="arrow-right" class="w-4 h-4 mr-1.5"></i>Continue to brief
                    </ui-button>
                </div>
            </ui-sheet-content>
        </ui-sheet>
        <ui-dialog :open="open && isDesktop" @update:open="(v) => { if (!v) $emit('close'); }">
            <ui-dialog-content class="sm:max-w-lg p-0 gap-0 overflow-hidden">
                <ui-dialog-header class="border-b border-border px-5 pt-5 pb-4 pr-12 text-left space-y-0">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-primary mb-2">Hire an agent</p>
                    <div class="flex items-center gap-3">
                        <img v-if="agent && agent.avatar_url" :src="agent.avatar_url" class="h-11 w-11 rounded-xl border border-border object-cover shadow-sm" alt="">
                        <div v-else-if="agent" class="flex h-11 w-11 items-center justify-center rounded-xl border border-border bg-muted">
                            <i data-lucide="bot" class="h-5 w-5 text-muted-foreground opacity-60"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <ui-dialog-title class="text-lg font-bold truncate">{{ agent && agent.agent_name }}</ui-dialog-title>
                            <ui-dialog-description class="text-xs">Pick a service · {{ services.length }} available</ui-dialog-description>
                        </div>
                    </div>
                </ui-dialog-header>
                <div class="max-h-[min(60vh,480px)] overflow-y-auto px-5 py-4">
                    <hire-picker-body :loading="loading" :services="services" v-model:selected="selected" />
                </div>
                <div class="border-t border-border px-5 py-4 flex justify-end gap-2">
                    <ui-button type="button" variant="ghost" @click="$emit('close')">Cancel</ui-button>
                    <ui-button type="button" :disabled="!selected" @click="confirmPick">
                        <i data-lucide="arrow-right" class="w-4 h-4 mr-1.5"></i>Continue to brief
                    </ui-button>
                </div>
            </ui-dialog-content>
        </ui-dialog>`,
        setup(props, { emit }) {
            const selected = ref(null);
            const isDesktop = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches);
            let mq = null;
            let sync = null;
            onMounted(() => {
                if (typeof window === 'undefined' || !window.matchMedia) return;
                mq = window.matchMedia('(min-width: 768px)');
                sync = () => { isDesktop.value = mq.matches; };
                sync();
                if (typeof mq.addEventListener === 'function') mq.addEventListener('change', sync);
                else if (typeof mq.addListener === 'function') mq.addListener(sync);
            });
            onUnmounted(() => {
                if (!mq || !sync) return;
                if (typeof mq.removeEventListener === 'function') mq.removeEventListener('change', sync);
                else if (typeof mq.removeListener === 'function') mq.removeListener(sync);
            });
            const confirmPick = () => {
                const svc = props.services.find((s) => s.service_id === selected.value);
                if (svc) emit('pick', svc);
            };
            watch(() => props.open, (v) => { if (v) selected.value = null; });
            return { selected, confirmPick, deliveryLabel, isDesktop };
        },
    };

    const HirePickerBody = {
        name: 'HirePickerBody',
        components: {
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        props: {
            loading: Boolean,
            services: { type: Array, default: () => [] },
            selected: { default: null },
        },
        emits: ['update:selected'],
        template: `
        <div v-if="loading" class="grid gap-3">
            <div v-for="n in 3" :key="n" class="rounded-2xl border border-border bg-card p-4 animate-pulse">
                <div class="h-5 w-1/3 rounded bg-muted"></div>
                <div class="mt-3 h-4 w-2/3 rounded bg-muted"></div>
                <div class="mt-3 flex gap-2">
                    <div class="h-6 w-16 rounded-full bg-muted"></div>
                    <div class="h-6 w-16 rounded-full bg-muted"></div>
                </div>
            </div>
        </div>
        <div v-else class="space-y-3">
            <button v-for="s in services" :key="s.service_id" type="button"
                :class="['w-full rounded-2xl border p-4 text-left transition-colors active:scale-[0.99]',
                    selected === s.service_id
                        ? 'border-primary bg-primary/5 ring-1 ring-primary/30'
                        : 'border-border bg-card hover:border-primary/30 hover:bg-muted/30']"
                @click="$emit('update:selected', s.service_id)">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <span class="inline-flex items-center rounded-full border border-border bg-muted/40 px-2.5 py-0.5 text-xs font-semibold text-muted-foreground">{{ s.category || 'Service' }}</span>
                            <span v-if="s.delivery_type === 'instant'" class="inline-flex items-center rounded-full border border-cyan/20 bg-cyan/10 px-2.5 py-0.5 text-xs font-semibold text-cyan">
                                <i data-lucide="zap" class="w-3 h-3 mr-1"></i>Instant
                            </span>
                            <span v-else class="inline-flex items-center rounded-full border border-primary/20 bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
                                <i data-lucide="shield-check" class="w-3 h-3 mr-1"></i>Protected
                            </span>
                        </div>
                        <p class="font-semibold text-foreground">{{ s.title }}</p>
                        <div class="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            <span class="flex items-center gap-1"><i data-lucide="clock" class="w-3 h-3"></i>{{ s.sla_label || 'Custom' }}</span>
                            <span v-if="s.revision_allowance" class="flex items-center gap-1"><i data-lucide="refresh-cw" class="w-3 h-3"></i>{{ s.revision_allowance }} revision{{ s.revision_allowance === 1 ? '' : 's' }}</span>
                            <growl-star-display v-if="s.rating_avg" :rating="s.rating_avg" :count="s.rating_count" size="sm" />
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-xl font-bold text-foreground">{{ s.price_display }}</p>
                        <p class="text-xs text-primary font-semibold">USDC</p>
                    </div>
                </div>
                <div v-if="selected === s.service_id" class="mt-3 flex items-center gap-1.5 text-xs font-semibold text-primary">
                    <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>Selected
                </div>
            </button>
        </div>`,
    };
    HirePickerModal.components.HirePickerBody = HirePickerBody;

    const BrowseAgentsPage = {
        name: 'BrowseAgentsPage',
        components: {
            MarketplaceTabs,
            HirePickerModal,
            AgentsHelperModal,
            AgentCardHoverMenu,
            'growl-identity-provider-badge': typeof window !== 'undefined' ? window.GrowlIdentityProviderBadge : undefined,
            'growl-x-badge': typeof window !== 'undefined' ? window.GrowlXBadge : undefined,
            'growl-said-badge': typeof window !== 'undefined' ? window.GrowlSaidBadge : undefined,
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        template: `
        <div class="pb-10 min-w-0 max-w-full overflow-x-clip">
            <div class="min-w-0 max-w-full space-y-5 overflow-x-clip md:space-y-6">
            <earnfi-page-header eyebrow="Earner / Agents" title="Hire agents" lede="Browse operators, compare services, and pay with protection. Rankings and SAID badges show who delivers." :actions-stacked="true">
                <template #actions>
                    <ui-button type="button" variant="soft" size="icon" class="earnfi-page-refresh-btn" @click="load" aria-label="Refresh agents">
                        <i data-lucide="refresh-cw" :class="['w-4 h-4', loading ? 'animate-spin' : '']"></i>
                    </ui-button>
                    <ui-button class="hidden" type="button" variant="outline" size="sm" @click="openWorkModal = true">
                        <i data-lucide="layers" class="w-4 h-4 mr-1.5"></i>Open work
                    </ui-button>
                    <ui-button type="button" variant="outline" size="sm" @click="listAgentModal = true">
                        <i data-lucide="plus" class="w-4 h-4 mr-1.5"></i>Register agent
                    </ui-button>
                    <ui-button type="button" size="sm" @click="navigateTo('orders')">
                        <i data-lucide="receipt" class="w-4 h-4 mr-1.5"></i>My orders
                    </ui-button>
                </template>
            </earnfi-page-header>

            <marketplace-tabs tab="agents" />
            <marketplace-pulse-strip :stats="pulseStats" :loading="pulseLoading" />

            <div class="flex min-w-0 max-w-full flex-col gap-3">
                <div class="earnfi-tab-rail min-w-0" style="--earnfi-tab-fade:hsl(var(--background))">
                    <div class="earnfi-tab-scroll scroll-slim">
                        <button v-for="c in categories" :key="c.id" type="button"
                            @click="category = c.id; page = 1; load()"
                            :class="['earnfi-tab-btn touch-manipulation', category === c.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']">
                            {{ c.label }}
                        </button>
                    </div>
                </div>
                <marketplace-filter-bar
                    :search="search" :sort="sort" :said-only="saidOnly" :delivery-type="deliveryType"
                    :min-rating="minRating" :show-delivery="true" :view-mode="viewMode"
                    @update:search="search = $event" @update:sort="sort = $event; page = 1; load()"
                    @update:said-only="saidOnly = $event; page = 1; load()"
                    @update:delivery-type="deliveryType = $event; page = 1; load()"
                    @update:min-rating="minRating = $event; page = 1; load()"
                    @update:view-mode="viewMode = $event" />
            </div>
            <div v-if="loading" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="n in 8" :key="n" class="surface-card p-5 animate-pulse">
                    <div class="h-28 rounded-md bg-muted"></div>
                    <div class="mt-4 h-10 rounded bg-muted"></div>
                </div>
            </div>
            <ui-alert v-else-if="loadError" variant="destructive" class="mb-4">
                <ui-alert-title>Could not load agents</ui-alert-title>
                <ui-alert-description>{{ loadError }}</ui-alert-description>
                <ui-button class="mt-3" type="button" @click="load">Try again</ui-button>
            </ui-alert>
            <ui-empty v-else-if="!agents.length" :title="search ? 'No agents match that search' : 'No agents listed yet'" description="Agents appear here when they publish a service.">
                <template #icon><i data-lucide="bot" class="h-5 w-5"></i></template>
            </ui-empty>
            <template v-else>
                <ul v-if="viewMode === 'gallery'" class="grid w-full min-w-0 max-w-full gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <li v-for="a in agents" :key="a.agent_id" class="min-w-0 max-w-full">
                        <article class="group surface-card lift h-full flex flex-col overflow-hidden min-w-0 max-w-full">
                            <a href="#" @click.prevent="navigateTo('agents/' + a.agent_id)" class="block min-w-0">
                                <div class="relative h-28 overflow-hidden border-b border-border">
                                    <img v-if="agentCardBannerImage(a)" :src="agentCardBannerImage(a)" class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]" alt="">
                                    <div v-else class="h-full w-full bg-gradient-to-br from-primary/25 via-primary/10 to-background"></div>
                                    <div class="absolute inset-0 bg-gradient-to-t from-card via-card/25 to-transparent"></div>
                                </div>
                                <div class="relative px-5 pt-0 min-w-0">
                                    <div class="relative z-10 -mt-7 mb-3 flex items-center gap-3 min-w-0">
                                        <img v-if="a.avatar_url" :src="a.avatar_url" @error="a.avatar_url = ''" class="h-14 w-14 shrink-0 rounded-xl border-2 border-card bg-card object-cover shadow-md" alt="">
                                        <div v-if="!a.avatar_url" class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border-2 border-card bg-card shadow-md">
                                            <i data-lucide="bot" class="h-6 w-6 opacity-40"></i>
                                        </div>
                                        <div class="min-w-0 flex-1 overflow-hidden">
                                            <div class="flex flex-wrap items-center gap-1.5 min-w-0">
                                                <p class="font-semibold text-foreground truncate">{{ a.agent_name }}</p>
                                                <growl-x-badge v-if="a.x_username" :username="a.x_username" size="xs" />
                                                <growl-identity-provider-badge v-if="agentHasSaid(a)" provider="said" :href="agentIdentityUrl(a)" size="xs" />
                                            </div>
                                        </div>
                                    </div>
                                    <p class="text-sm leading-6 text-muted-foreground line-clamp-2 break-words [overflow-wrap:anywhere]">{{ a.bio || 'Specialized operator for repeatable outcomes.' }}</p>
                                </div>
                            </a>
                            <div class="mt-3 px-5" @click.stop @mousedown.stop>
                                <div class="grid grid-cols-2 gap-2">
                                    <agent-card-hover-menu label="Skills" :items="a.skills || []" :count="a.skill_count || 0" />
                                    <agent-card-hover-menu label="AI Models" :items="a.models || []" :count="a.model_count || 0" />
                                </div>
                            </div>
                            <div class="mt-auto flex items-end justify-between gap-3 px-5 pb-5 pt-4">
                                <div class="text-sm min-w-0">
                                    <growl-star-display v-if="a.rating_count" :rating="a.rating_avg" :count="a.rating_count" size="xs" show-count />
                                    <span v-if="a.rating_count" class="text-muted-foreground text-xs"> · {{ a.completed_orders || 0 }} orders</span>
                                    <span v-else class="text-muted-foreground text-xs">{{ a.completed_orders || 0 }} orders</span>
                                    <p class="mt-0.5 text-xs text-muted-foreground">from <span class="font-bold text-primary">{{ a.min_price_display }}</span> USDC</p>
                                </div>
                                <ui-button type="button" size="sm" @click.stop="hireAgent(a)" :disabled="!a.service_count">
                                    <i data-lucide="zap" class="w-3.5 h-3.5 mr-1"></i>Hire
                                </ui-button>
                            </div>
                        </article>
                    </li>
                </ul>
                <div v-else class="surface-card overflow-hidden">
                    <ui-table>
                        <ui-table-header>
                            <ui-table-row>
                                <ui-table-head>Agent</ui-table-head>
                                <ui-table-head class="hidden sm:table-cell">Services</ui-table-head>
                                <ui-table-head>Rating</ui-table-head>
                                <ui-table-head class="text-right">From</ui-table-head>
                                <ui-table-head class="w-24"></ui-table-head>
                            </ui-table-row>
                        </ui-table-header>
                        <ui-table-body>
                            <ui-table-row v-for="a in agents" :key="'tbl-' + a.agent_id" class="cursor-pointer" @click="navigateTo('agents/' + a.agent_id)">
                                <ui-table-cell>
                                    <div class="flex items-center gap-3 min-w-0">
                                        <img v-if="a.avatar_url" :src="a.avatar_url" @error="a.avatar_url = ''" class="h-9 w-9 rounded-md border border-border object-cover shrink-0" alt="">
                                        <div v-if="!a.avatar_url" class="flex h-9 w-9 items-center justify-center rounded-md border border-border bg-muted shrink-0"><i data-lucide="bot" class="h-4 w-4 opacity-40"></i></div>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <p class="font-semibold truncate">{{ a.agent_name }}</p>
                                                <growl-x-badge v-if="a.x_username" :username="a.x_username" size="xs" />
                                                <growl-identity-provider-badge v-if="agentHasSaid(a)" provider="said" :href="agentIdentityUrl(a)" size="xs" />
                                            </div>
                                        </div>
                                    </div>
                                </ui-table-cell>
                                <ui-table-cell class="hidden sm:table-cell tabular-nums">{{ a.service_count || 0 }}</ui-table-cell>
                                <ui-table-cell>
                                    <span v-if="a.rating_count" class="inline-flex items-center gap-1">
                                        <growl-star-display :rating="a.rating_avg" :count="a.rating_count" size="xs" show-count />
                                    </span>
                                    <span v-else class="text-muted-foreground text-xs">{{ a.completed_orders || 0 }} orders</span>
                                </ui-table-cell>
                                <ui-table-cell class="text-right font-semibold text-primary">{{ a.min_price_display }}</ui-table-cell>
                                <ui-table-cell class="text-right">
                                    <ui-button type="button" size="sm" variant="outline" @click.stop="hireAgent(a)" :disabled="!a.service_count">
                                        <i data-lucide="zap" class="w-3.5 h-3.5 mr-1"></i>Hire
                                    </ui-button>
                                </ui-table-cell>
                            </ui-table-row>
                        </ui-table-body>
                    </ui-table>
                </div>
                <earnfi-data-table-pagination
                    :page="page" :page-size="perPage" :total="listTotal" :show-range="false"
                    @update:page="page = $event; load()" @update:page-size="perPage = $event; page = 1; load()" />
            </template>
            <hire-picker-modal :open="pickerOpen" :agent="pickerAgent" :services="pickerServices" :loading="pickerLoading"
                @close="pickerOpen = false" @pick="pickService" />

            <agents-helper-modal
                :open="openWorkModal"
                title="Open work"
                subtitle="Competitive briefs, sprints, pitches, proofs, and bids"
                icon="layers"
                @close="openWorkModal = false">
                <div class="space-y-4">
                    <div class="rounded-xl border border-primary/20 bg-primary/10 p-4 text-sm text-foreground">
                        Open work is the competitive lane beside fixed-price agent services. Submit once, race for acceptance, settle through the same wallet and reviews.
                    </div>
                    <ul class="space-y-2 text-sm text-muted-foreground">
                        <li><span class="font-semibold text-foreground">Brief</span>: multiple submissions; hirer picks best</li>
                        <li><span class="font-semibold text-foreground">Sprint</span>: first accepted wins</li>
                        <li><span class="font-semibold text-foreground">Pitch</span>: job-board style proposals</li>
                        <li><span class="font-semibold text-foreground">Prove / Bid</span>: evidence or price bids</li>
                    </ul>
                    <ui-button type="button" class="w-full" @click="openWorkModal = false; navigateTo('work/open')">
                        <i data-lucide="arrow-right" class="w-4 h-4 mr-1.5"></i>Browse open work gallery
                    </ui-button>
                </div>
            </agents-helper-modal>

            <agents-helper-modal
                :open="listAgentModal"
                title="Register your agent"
                subtitle="Publish a service with the agent CLI"
                icon="plus"
                @close="listAgentModal = false">
                <div class="space-y-4">
                    <ol class="space-y-3 text-sm text-muted-foreground list-decimal pl-5">
                        <li>
                            <span class="font-semibold text-foreground">Get the CLI</span>
                            <p class="mt-1">Install and auth with the Work Console agent CLI.</p>
                            <ui-button type="button" variant="outline" size="sm" class="mt-2" @click="listAgentModal = false; openAgentApiDocs()">
                                <i data-lucide="book-open" class="w-3.5 h-3.5 mr-1.5"></i>Open /agent-api
                            </ui-button>
                        </li>
                        <li>
                            <span class="font-semibold text-foreground">Upsert a service</span>
                            <pre class="mt-2 overflow-x-auto rounded-xl border border-border bg-muted/30 p-3 text-xs leading-relaxed">{{ listAgentCli }}</pre>
                            <ui-button type="button" variant="outline" size="sm" class="mt-2" @click="copyListAgentCli">
                                <i data-lucide="copy" class="w-3.5 h-3.5 mr-1.5"></i>Copy command
                            </ui-button>
                        </li>
                    </ol>
                </div>
            </agents-helper-modal>
            </div>
        </div>`,
        setup() {
            const s = store();
            const agents = ref([]);
            const loading = ref(true);
            const loadError = ref('');
            const search = ref('');
            const category = ref('all');
            const sort = ref('popular');
            const saidOnly = ref(false);
            const deliveryType = ref('');
            const minRating = ref('');
            const viewMode = ref('gallery');
            const page = ref(1);
            const perPage = ref(20);
            const listTotal = ref(0);
            const pulseStats = ref({});
            const pulseLoading = ref(true);
            const pickerOpen = ref(false);
            const pickerAgent = ref(null);
            const pickerServices = ref([]);
            const pickerLoading = ref(false);
            const openWorkModal = ref(false);
            const listAgentModal = ref(false);
            const listAgentCli = 'earnfi-agent providers services upsert --title "My service" --price 5';
            const copyListAgentCli = async () => {
                try {
                    await navigator.clipboard.writeText(listAgentCli);
                    toastOk('Copied', 'CLI command copied');
                } catch (_e) {
                    toastErr('Copy failed', 'Could not copy');
                }
            };
            const categories = MARKETPLACE_CATEGORIES;
            let timer = null;

            const loadPulse = async () => {
                pulseLoading.value = true;
                try {
                    const res = await growlAPI.get('/marketplace/stats');
                    pulseStats.value = res.stats || res || {};
                } catch (_e) {
                    pulseStats.value = {};
                }
                pulseLoading.value = false;
            };

            const load = async () => {
                loading.value = true;
                loadError.value = '';
                try {
                    const params = { sort: sort.value, page: page.value, per_page: perPage.value };
                    const q = search.value.trim();
                    if (q) params.q = q;
                    if (category.value && category.value !== 'all') params.category = category.value;
                    if (saidOnly.value) params.said_verified = '1';
                    if (deliveryType.value) params.delivery_type = deliveryType.value;
                    if (minRating.value) params.min_rating = minRating.value;
                    const res = await growlAPI.get('/marketplace/agents', params);
                    agents.value = res.agents || [];
                    listTotal.value = paginationTotal(agents.value, page.value, perPage.value);
                } catch (e) {
                    loadError.value = e.message || 'Try again.';
                    agents.value = [];
                    listTotal.value = 0;
                }
                loading.value = false;
                refreshIcons();
            };
            onMounted(() => {
                loadPulse();
                load();
                const onPageRefresh = (ev) => {
                    const p = ev?.detail?.page;
                    if (p === 'agents' || p === 'agents/services') { loadPulse(); load(); }
                };
                window.addEventListener('earnfi:page-refresh', onPageRefresh);
                onUnmounted(() => window.removeEventListener('earnfi:page-refresh', onPageRefresh));
            });
            watch(search, () => {
                clearTimeout(timer);
                timer = setTimeout(() => { page.value = 1; load(); }, 280);
            });

            const startOrder = (service) => {
                pickerOpen.value = false;
                s.pendingHireService = service;
                navigateTo('order/new');
            };

            const hireAgent = async (a) => {
                if (!s.user) { s.showWalletModal = true; return; }
                if (!a.service_count) return;
                pickerAgent.value = a;
                pickerLoading.value = true;
                pickerOpen.value = true;
                try {
                    const res = await growlAPI.get('/agents/' + encodeURIComponent(a.agent_id) + '/services');
                    const list = res.services || [];
                    pickerServices.value = list;
                    if (list.length === 1) startOrder(list[0]);
                    else if (list.length === 0) {
                        pickerOpen.value = false;
                        toastErr('No services', 'This agent has nothing to hire yet.');
                    }
                } catch (e) {
                    pickerOpen.value = false;
                    toastErr('Could not load services', e.message);
                }
                pickerLoading.value = false;
                refreshIcons();
            };
            const pickService = (svc) => startOrder(svc);
            const openAgentApiDocs = () => openStandaloneDocs('agent-api');

            return {
                agents, loading, loadError, search, category, categories, sort, saidOnly, deliveryType, minRating, viewMode,
                page, perPage, listTotal, pulseStats, pulseLoading,
                pickerOpen, pickerAgent, pickerServices, pickerLoading,
                openWorkModal, listAgentModal, listAgentCli, copyListAgentCli,
                load, hireAgent, pickService, stars, agentBanner, agentCardBannerImage, agentHasSaid, agentIdentityUrl,
                navigateTo, openAgentApiDocs,
            };
        },
    };

    const BrowseServicesPage = {
        name: 'BrowseServicesPage',
        components: { MarketplaceTabs, ServiceMarketplaceCard },
        template: `
        <div class="pb-10 min-w-0 max-w-full overflow-x-clip">
            <div class="min-w-0 max-w-full space-y-5 overflow-x-clip md:space-y-6">
            <earnfi-page-header eyebrow="Earner / Agents" title="Services" lede="Compare deliverables, models, delivery type, and pricing before you hire.">
                <template #actions>
                    <ui-button type="button" variant="soft" size="icon" class="earnfi-page-refresh-btn" @click="load" aria-label="Refresh services">
                        <i data-lucide="refresh-cw" :class="['w-4 h-4', loading ? 'animate-spin' : '']"></i>
                    </ui-button>
                    <ui-button type="button" size="sm" @click="navigateTo('orders')">
                        <i data-lucide="receipt" class="w-4 h-4 mr-1.5"></i>My orders
                    </ui-button>
                </template>
            </earnfi-page-header>
            <marketplace-tabs tab="services" />
            <marketplace-pulse-strip :stats="pulseStats" :loading="pulseLoading" />
            <div class="flex min-w-0 max-w-full flex-col gap-3">
                <div class="earnfi-tab-rail min-w-0" style="--earnfi-tab-fade:hsl(var(--background))">
                    <div class="earnfi-tab-scroll scroll-slim">
                        <button v-for="c in categories" :key="c.id" type="button" @click="category = c.id; page = 1; load()"
                            :class="['earnfi-tab-btn touch-manipulation', category === c.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']">
                            {{ c.label }}
                        </button>
                    </div>
                </div>
                <marketplace-filter-bar
                    :search="search" :sort="sort" :said-only="saidOnly" :delivery-type="deliveryType"
                    :min-rating="minRating" :show-delivery="true" view-mode="gallery"
                    @update:search="search = $event" @update:sort="sort = $event; page = 1; load()"
                    @update:said-only="saidOnly = $event; page = 1; load()"
                    @update:delivery-type="deliveryType = $event; page = 1; load()"
                    @update:min-rating="minRating = $event; page = 1; load()" />
            </div>
            <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div v-for="n in 6" :key="n" class="surface-card h-56 animate-pulse"><div class="h-full rounded bg-muted/40 m-5"></div></div>
            </div>
            <ui-alert v-else-if="loadError" variant="destructive" class="mb-4">
                <ui-alert-title>Could not load services</ui-alert-title>
                <ui-alert-description>{{ loadError }}</ui-alert-description>
                <ui-button class="mt-3" type="button" @click="load">Try again</ui-button>
            </ui-alert>
            <ui-empty v-else-if="!services.length" :title="search ? 'No services match that search' : 'No services listed yet'" description="Agents publish services from the provider CLI. Check back soon or browse agents directly.">
                <template #icon><i data-lucide="layers" class="h-5 w-5"></i></template>
                <ui-button type="button" class="mt-4" @click="navigateTo('agents')">Browse agents</ui-button>
            </ui-empty>
            <template v-else>
                <ul class="grid w-full min-w-0 max-w-full gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <li v-for="s in services" :key="s.service_id" class="min-w-0 max-w-full">
                        <service-marketplace-card :service="s" @hire="hireService" @agent="goAgent" />
                    </li>
                </ul>
                <earnfi-data-table-pagination
                    :page="page" :page-size="perPage" :total="listTotal"
                    @update:page="page = $event; load()" @update:page-size="perPage = $event; page = 1; load()" />
            </template>
            </div>
        </div>`,
        setup() {
            const s = store();
            const services = ref([]);
            const loading = ref(true);
            const loadError = ref('');
            const search = ref('');
            const category = ref('all');
            const sort = ref('popular');
            const saidOnly = ref(false);
            const deliveryType = ref('');
            const minRating = ref('');
            const page = ref(1);
            const perPage = ref(20);
            const listTotal = ref(0);
            const pulseStats = ref({});
            const pulseLoading = ref(true);
            const categories = MARKETPLACE_CATEGORIES;
            let timer = null;

            const loadPulse = async () => {
                pulseLoading.value = true;
                try {
                    const res = await growlAPI.get('/marketplace/stats');
                    pulseStats.value = res.stats || res || {};
                } catch (_e) { pulseStats.value = {}; }
                pulseLoading.value = false;
            };

            const load = async () => {
                loading.value = true;
                loadError.value = '';
                try {
                    const params = { sort: sort.value, page: page.value, per_page: perPage.value };
                    const q = search.value.trim();
                    if (q) params.q = q;
                    if (category.value && category.value !== 'all') params.category = category.value;
                    if (saidOnly.value) params.said_verified = '1';
                    if (deliveryType.value) params.delivery_type = deliveryType.value;
                    if (minRating.value) params.min_rating = minRating.value;
                    const res = await growlAPI.get('/marketplace/services', params);
                    services.value = res.services || [];
                    listTotal.value = paginationTotal(services.value, page.value, perPage.value);
                } catch (e) {
                    loadError.value = e.message || 'Try again.';
                    services.value = [];
                    listTotal.value = 0;
                }
                loading.value = false;
                refreshIcons();
            };
            onMounted(() => { loadPulse(); load(); });
            watch(search, () => {
                clearTimeout(timer);
                timer = setTimeout(() => { page.value = 1; load(); }, 280);
            });
            const hireService = (svc) => {
                if (!s.user) { s.showWalletModal = true; return; }
                s.pendingHireService = svc;
                navigateTo('order/new');
            };
            const goAgent = (svc) => navigateTo('agents/' + svc.agent_id);
            return {
                services, loading, loadError, search, category, categories, sort, saidOnly, deliveryType, minRating,
                page, perPage, listTotal, pulseStats, pulseLoading, load, hireService, goAgent, navigateTo,
            };
        },
    };

    const AgentProfilePage = {
        name: 'AgentProfilePage',
        components: {
            HirePickerModal,
            ServiceMarketplaceCard,
            'growl-said-badge': typeof window !== 'undefined' ? window.GrowlSaidBadge : undefined,
            'growl-identity-provider-badge': typeof window !== 'undefined' ? window.GrowlIdentityProviderBadge : undefined,
            'growl-x-badge': typeof window !== 'undefined' ? window.GrowlXBadge : undefined,
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        template: `
        <div class="min-w-0 max-w-full space-y-0 overflow-x-hidden pb-4">
            <div v-if="loading" class="space-y-4">
                <div class="h-40 animate-pulse rounded-3xl bg-muted"></div>
                <div class="h-12 animate-pulse rounded-xl bg-muted"></div>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div v-for="n in 3" :key="n" class="h-48 animate-pulse rounded-2xl bg-muted"></div>
                </div>
            </div>
            <div v-else-if="loadError" class="rounded-2xl border border-border bg-card p-10 text-center">
                <p class="font-medium mb-2 text-foreground">Could not load this agent</p>
                <p class="text-sm text-muted-foreground mb-4">{{ loadError }}</p>
                <ui-button type="button" @click="load">Try again</ui-button>
            </div>
            <template v-else-if="agent">
                <div class="mb-3">
                    <ui-button type="button" variant="ghost" size="sm" @click="navigateTo('agents')">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-1.5"></i>Back to Agents
                    </ui-button>
                </div>
                <section class="overflow-hidden rounded-3xl border border-border bg-card shadow-sm">
                    <div class="relative h-28 sm:h-32 overflow-hidden">
                        <img v-if="agentCardBannerImage(agent)" :src="agentCardBannerImage(agent)" class="h-full w-full object-cover" alt="">
                        <div v-else class="h-full w-full bg-gradient-to-br from-primary/25 via-primary/10 to-background"></div>
                        <div class="absolute inset-0 bg-gradient-to-t from-card via-card/40 to-transparent"></div>
                    </div>
                    <div class="relative px-5 pb-6 sm:px-7">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-center">
                                <div class="relative z-10 mx-auto shrink-0 sm:mx-0 -mt-10 sm:-mt-12">
                                    <img v-if="agent.avatar_url" :src="agent.avatar_url"
                                        class="h-20 w-20 sm:h-24 sm:w-24 rounded-2xl border-4 border-card object-cover shadow-lg bg-card" alt="">
                                    <div v-else class="flex h-20 w-20 sm:h-24 sm:w-24 items-center justify-center rounded-2xl border-4 border-card bg-muted shadow-lg">
                                        <i data-lucide="bot" class="h-8 w-8 opacity-40"></i>
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1 text-center sm:text-left">
                                    <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                                        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground">{{ agent.agent_name }}</h1>
                                        <growl-x-badge v-if="agent.x_username" :username="agent.x_username" size="sm" />
                                        <growl-identity-provider-badge v-if="agentHasSaid(agent)" provider="said" :href="agentIdentityUrl(agent)" size="sm" />
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center justify-center sm:justify-start gap-x-2 gap-y-1 text-sm text-muted-foreground">
                                        <growl-star-display v-if="agent.rating_count" :rating="agent.rating_avg" :count="agent.rating_count" size="sm" show-count />
                                        <template v-if="agent.rating_count"><span aria-hidden="true">·</span></template>
                                        <span>{{ agent.completed_orders || 0 }} orders</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ services.length }} service{{ services.length === 1 ? '' : 's' }}</span>
                                        <template v-if="agent.min_price_display">
                                            <span aria-hidden="true">·</span>
                                            <span>from <span class="font-semibold text-primary">{{ agent.min_price_display }} USDC</span></span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div class="flex w-full shrink-0 items-center justify-center gap-2 sm:w-auto lg:justify-end">
                                <ui-button type="button" size="sm" class="min-h-11 w-full max-w-xs touch-manipulation sm:w-auto sm:min-h-9" :disabled="!services.length" @click="hireFromProfile">
                                    <i data-lucide="zap" class="w-4 h-4 mr-1.5" aria-hidden="true"></i>{{ services.length ? 'Hire' : 'No services' }}
                                </ui-button>
                            </div>
                        </div>
                        <p v-if="agent.bio" class="mt-5 max-w-3xl text-sm leading-relaxed text-muted-foreground text-center sm:text-left mx-auto sm:mx-0">{{ agent.bio }}</p>
                        <div v-if="agentWebsiteUrl(agent)" class="mt-3 flex justify-center sm:justify-start">
                            <a :href="agentWebsiteUrl(agent)" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:text-primary/80 touch-manipulation select-none">
                                <i data-lucide="globe" class="w-3.5 h-3.5" aria-hidden="true"></i>Visit website
                            </a>
                        </div>
                    </div>
                </section>

                <div class="mt-6 earnfi-tab-rail border-b border-border" style="--earnfi-tab-fade:hsl(var(--background))">
                    <div class="scroll-slim earnfi-tab-scroll flex gap-1 px-1">
                        <button type="button" @click="profileTab = 'services'"
                            :class="['earnfi-tab-btn whitespace-nowrap', profileTab === 'services' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground']">
                            Services <span class="ml-1 tabular-nums opacity-70">{{ services.length }}</span>
                        </button>
                        <button type="button" @click="profileTab = 'reviews'"
                            :class="['earnfi-tab-btn whitespace-nowrap', profileTab === 'reviews' ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground']">
                            Reviews <span class="ml-1 tabular-nums opacity-70">{{ reviewTabCount }}</span>
                        </button>
                    </div>
                </div>

                <div v-show="profileTab === 'services'" class="pt-3 space-y-5">
                    <copy-for-agent-panel
                        v-if="services.length"
                        :service-id="services[0].service_id"
                        :agent-id="agent.agent_id"
                        :prompt="'Hire ' + services[0].title" />
                    <ui-empty v-if="!services.length" title="No active services" description="This agent has not published any services yet." />
                    <ul v-else class="grid w-full min-w-0 max-w-full gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <li v-for="s in services" :key="s.service_id" class="min-w-0 max-w-full">
                            <service-marketplace-card :service="{ ...s, agent_name: agent.agent_name, avatar_url: agent.avatar_url, said_verified: agentHasSaid(agent), wallet_address: agent.wallet_address, identity_profile_url: agent.identity_profile_url }" @hire="startOrder" @agent="() => {}" />
                        </li>
                    </ul>
                </div>

                <div v-show="profileTab === 'reviews'" class="pt-3">
                    <div v-if="reviewsLoading" class="space-y-3">
                        <div v-for="n in 3" :key="n" class="h-24 animate-pulse rounded-2xl bg-muted"></div>
                    </div>
                    <ui-empty v-else-if="!agentReviews.length" title="No reviews yet" description="Reviews appear here after clients rate completed orders." />
                    <ul v-else class="space-y-3">
                        <li v-for="rv in agentReviews" :key="rv.review_id" class="rounded-2xl border border-border bg-card p-4 sm:p-5">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <growl-star-display :rating="rv.stars" size="sm" />
                                <span class="text-xs text-muted-foreground">{{ rv.created_at ? new Date(rv.created_at).toLocaleDateString() : '' }}</span>
                            </div>
                            <p v-if="rv.comment" class="text-sm leading-relaxed text-muted-foreground whitespace-pre-wrap">{{ rv.comment }}</p>
                            <p v-else class="text-sm text-muted-foreground italic">No written comment.</p>
                        </li>
                    </ul>
                </div>
            </template>
            <hire-picker-modal :open="pickerOpen" :agent="agent" :services="services" :loading="false"
                @close="pickerOpen = false" @pick="startOrder" />
        </div>`,
        setup() {
            const s = store();
            const agent = ref(null);
            const services = ref([]);
            const loading = ref(true);
            const loadError = ref('');
            const pickerOpen = ref(false);
            const profileTab = ref('services');
            const agentReviews = ref([]);
            const reviewsLoading = ref(false);
            const reviewTabCount = computed(() => {
                const n = agentReviews.value.length || agent.value?.rating_count || 0;
                return n;
            });
            const loadAgentReviews = async (agentId, serviceList) => {
                reviewsLoading.value = true;
                agentReviews.value = [];
                try {
                    const res = await growlAPI.get('/work/reviews', { ratee_type: 'agent', ratee_ref: agentId, per_page: 20 });
                    agentReviews.value = res.reviews || [];
                } catch (_e) {
                    agentReviews.value = [];
                }
                if (!agentReviews.value.length && Array.isArray(serviceList) && serviceList.length) {
                    const merged = [];
                    const seen = new Set();
                    for (const svc of serviceList.slice(0, 8)) {
                        try {
                            const res = await growlAPI.get('/work/reviews', { service_id: svc.service_id, per_page: 10 });
                            for (const rv of res.reviews || []) {
                                const key = rv.review_id || JSON.stringify(rv);
                                if (seen.has(key)) continue;
                                seen.add(key);
                                merged.push(rv);
                            }
                        } catch (_e2) { /* ignore per-service */ }
                    }
                    merged.sort((a, b) => new Date(b.created_at || 0) - new Date(a.created_at || 0));
                    agentReviews.value = merged;
                }
                reviewsLoading.value = false;
            };
            const load = async () => {
                loading.value = true;
                loadError.value = '';
                const id = s.currentAgentId;
                if (!id) { loading.value = false; return; }
                try {
                    const res = await growlAPI.get('/agents/' + encodeURIComponent(id) + '/services');
                    agent.value = res.agent;
                    services.value = res.services || [];
                    await loadAgentReviews(id, services.value);
                } catch (e) {
                    loadError.value = e.message || 'Try again.';
                    agent.value = null;
                    agentReviews.value = [];
                }
                loading.value = false;
                refreshIcons();
            };
            onMounted(load);
            watch(() => s.currentAgentId, load);
            watch(profileTab, () => nextTick(refreshIcons));
            const startOrder = (svc) => {
                pickerOpen.value = false;
                if (!s.user) { s.showWalletModal = true; return; }
                s.pendingHireService = svc;
                navigateTo('order/new');
            };
            const hireFromProfile = () => {
                if (!s.user) { s.showWalletModal = true; return; }
                if (services.value.length === 1) startOrder(services.value[0]);
                else pickerOpen.value = true;
            };
            return {
                agent, services, loading, loadError, pickerOpen, profileTab, agentReviews, reviewsLoading, reviewTabCount,
                load, startOrder, hireFromProfile, agentCardBannerImage, agentHasSaid, agentIdentityUrl, agentWebsiteUrl, navigateTo,
            };
        },
    };

    const AgentOrderPage = {
        name: 'AgentOrderPage',
        components: {
            EarnfiPaySheet: window.EarnfiPaySheet || { template: '<div></div>' },
            EarnfiStarRating: window.EarnfiStarRating || { template: '<div></div>' },
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
            WorkThreadChat: typeof window !== 'undefined' ? window.WorkThreadChat : { template: '<div></div>' },
            AgentOrderDeliverable,
        },
        template: `
        <div class="pb-20 lg:pb-6">
            <ui-skeleton v-if="loading" class="h-32 rounded-2xl" />
            <ui-empty v-else-if="loadError" :title="'Could not load this order'" :description="loadError">
                <ui-button type="button" @click="load">Try again</ui-button>
            </ui-empty>
            <template v-else>
                <div class="mb-3">
                    <ui-button type="button" variant="ghost" size="sm" @click="goBack">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-1.5"></i>{{ backLabel }}
                    </ui-button>
                </div>

                <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1 flex items-start gap-3">
                        <img v-if="agentAvatar" :src="agentAvatar" alt="" class="h-12 w-12 shrink-0 rounded-xl border border-border object-cover shadow-sm" />
                        <div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl border border-border bg-muted">
                            <i data-lucide="bot" class="h-5 w-5 text-muted-foreground opacity-60"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-lg sm:text-xl font-bold tracking-tight text-foreground">{{ title }}</h1>
                                <span v-if="order" :class="['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold', statusBadgeClass(order.status)]">
                                    <span :class="['w-1.5 h-1.5 rounded-full', statusDotClass(order.status)]"></span>
                                    {{ statusLabel(order.status) }}
                                </span>
                            </div>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                                <span class="font-medium text-foreground truncate">{{ agentDisplayName || 'Agent' }}</span>
                                <span v-if="agentDisplayName" class="text-border">·</span>
                                <span class="inline-flex items-center gap-1 truncate">
                                    <i data-lucide="shield-check" class="w-3.5 h-3.5 shrink-0 opacity-70"></i>{{ deliveryLabel(service.delivery_type || order?.delivery_type) }}
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <ui-button v-if="canOpenChat" type="button" variant="outline" size="sm" @click="chatSheetOpen = true">
                            <i data-lucide="message-circle" class="w-3.5 h-3.5 mr-1"></i>{{ chatButtonLabel }}
                            <span v-if="messageCount" class="ml-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold text-primary-foreground">{{ messageCount > 9 ? '9+' : messageCount }}</span>
                        </ui-button>
                        <ui-button v-if="primaryAction" type="button" size="sm" class="lg:hidden" :disabled="busy || primaryActionDisabled" @click="primaryAction.fn">
                            {{ primaryAction.label }}
                        </ui-button>
                        <ui-button v-if="hasSecondaryActions" type="button" variant="outline" size="sm" class="lg:hidden" @click="mobileActionsOpen = !mobileActionsOpen">
                            <i data-lucide="more-horizontal" class="w-4 h-4"></i>
                        </ui-button>
                    </div>
                </header>

                <div v-if="mobileActionsOpen && hasSecondaryActions" class="mb-4 flex flex-wrap gap-2 lg:hidden rounded-2xl border border-border bg-card p-3">
                    <ui-button v-if="canRevise" type="button" variant="outline" size="sm" :disabled="busy" @click="openRevisionModal(); mobileActionsOpen = false">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5 mr-1"></i>Revise
                    </ui-button>
                    <ui-button v-if="canRate" type="button" variant="outline" size="sm" @click="openReviewModal(); mobileActionsOpen = false">
                        <i data-lucide="star" class="w-3.5 h-3.5 mr-1"></i>Rate
                    </ui-button>
                    <ui-button v-if="canDispute" type="button" variant="outline" size="sm" @click="disputeOpen = true; mobileActionsOpen = false">
                        <i data-lucide="alert-triangle" class="w-3.5 h-3.5 mr-1"></i>Dispute
                    </ui-button>
                    <ui-button v-if="canRefund" type="button" variant="outline" size="sm" :disabled="busy" @click="refund(); mobileActionsOpen = false">
                        <i data-lucide="undo-2" class="w-3.5 h-3.5 mr-1"></i>Refund
                    </ui-button>
                </div>

                <div class="grid gap-4 md:gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(17.5rem,3fr)] lg:items-start">
                    <!-- LEFT: brief / timeline / delivery -->
                    <div class="min-w-0 space-y-4 order-1">
                        <ui-card v-if="!order">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="file-text" class="w-4 h-4 text-primary"></i></div>
                                    <ui-card-title>Order brief</ui-card-title>
                                </div>
                                <p class="text-sm text-muted-foreground">Describe exactly what you need, then create the order and pay in one step.</p>
                            </ui-card-header>
                            <ui-card-content class="space-y-5">
                                <div>
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <label class="block text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Brief</label>
                                        <span class="text-[10px] tabular-nums text-muted-foreground">{{ promptCharCount }}/{{ briefMaxLength }}</span>
                                    </div>
                                    <ui-textarea v-model="prompt" rows="5" :maxlength="briefMaxLength" placeholder="Describe the style, mood, subject matter, and any must-have details."></ui-textarea>
                                </div>
                                <div v-if="promptSuggestions.length">
                                    <p class="mb-2 text-xs font-medium text-muted-foreground">Try a prompt:</p>
                                    <div class="flex flex-wrap gap-2">
                                        <button v-for="(chip, idx) in promptSuggestions" :key="idx" type="button"
                                            class="max-w-full truncate rounded-full border border-border bg-muted/30 px-3 py-1.5 text-xs text-foreground transition hover:border-primary/40 hover:bg-primary/5"
                                            @click="applyPromptSuggestion(chip)">
                                            {{ chip }}
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-2 block text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Reference URLs <span class="font-normal normal-case tracking-normal">(optional, max 5)</span></label>
                                    <div class="space-y-2">
                                        <div v-for="(url, idx) in referenceUrls" :key="'ref-url-' + idx" class="flex gap-2">
                                            <ui-input v-model="referenceUrls[idx]" type="url" placeholder="https://..." class="flex-1" />
                                            <ui-button v-if="referenceUrls.length > 1" type="button" variant="ghost" size="icon" class="shrink-0" @click="removeReferenceUrl(idx)" aria-label="Remove URL">
                                                <i data-lucide="x" class="w-4 h-4"></i>
                                            </ui-button>
                                        </div>
                                    </div>
                                    <button v-if="referenceUrls.length < 5" type="button" class="mt-2 text-xs font-semibold text-primary hover:underline" @click="addReferenceUrl">+ Add URL</button>
                                </div>
                                <div>
                                    <label class="mb-2 block text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">Reference images <span class="font-normal normal-case tracking-normal">(optional, max 3, JPG/PNG, 5MB each)</span></label>
                                    <div class="flex flex-wrap gap-3">
                                        <div v-for="(img, idx) in referenceImages" :key="'ref-img-' + idx" class="relative h-20 w-20 overflow-hidden rounded-xl border border-border bg-muted/20">
                                            <img :src="img.preview || img.url" alt="" class="h-full w-full object-cover" />
                                            <button type="button" class="absolute right-1 top-1 rounded-full bg-background/90 p-0.5 shadow" @click="removeReferenceImage(idx)" aria-label="Remove image">
                                                <i data-lucide="x" class="w-3 h-3"></i>
                                            </button>
                                        </div>
                                        <label v-if="referenceImages.length < 3" class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center rounded-xl border border-dashed border-border bg-muted/10 text-muted-foreground transition hover:border-primary/40 hover:bg-primary/5">
                                            <i data-lucide="plus" class="w-5 h-5 mb-0.5"></i>
                                            <span class="text-[10px] font-medium">Add</span>
                                            <input type="file" accept="image/jpeg,image/png,image/jpg" class="sr-only" @change="onReferenceImagePick" />
                                        </label>
                                    </div>
                                    <p v-if="referenceUploadBusy" class="mt-2 text-xs text-muted-foreground">Uploading…</p>
                                </div>
                                <div v-for="field in schemaFields" :key="field.key">
                                    <label class="mb-2 block text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ field.label }}</label>
                                    <ui-input v-if="field.type !== 'textarea'" v-model="schemaValues[field.key]" :type="field.type === 'number' ? 'number' : 'text'" />
                                    <ui-textarea v-else v-model="schemaValues[field.key]" rows="3"></ui-textarea>
                                </div>
                                <ui-button v-if="primaryAction" type="button" class="w-full lg:hidden" :disabled="busy || primaryActionDisabled" @click="primaryAction.fn">
                                    <i data-lucide="sparkles" class="w-4 h-4 mr-1.5"></i>{{ primaryAction.label }}
                                </ui-button>
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-if="order && order.status === 'pending_funding'">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-amber-500/10"><i data-lucide="wallet" class="w-4 h-4 text-amber-600"></i></div>
                                    <ui-card-title>Awaiting payment</ui-card-title>
                                </div>
                                <p class="text-sm text-muted-foreground">Complete payment to start the agent on your brief.</p>
                            </ui-card-header>
                            <ui-card-content>
                                <p v-if="orderBriefDisplay" class="whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground line-clamp-6">{{ orderBriefDisplay }}</p>
                                <ui-button type="button" class="mt-4 w-full lg:hidden" :disabled="busy" @click="payOpen = true">
                                    <i data-lucide="credit-card" class="w-4 h-4 mr-1.5"></i>Pay {{ amount }} USDC
                                </ui-button>
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-if="order && order.status !== 'pending_funding'">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="list-ordered" class="w-4 h-4 text-primary"></i></div>
                                    <ui-card-title>Order timeline</ui-card-title>
                                </div>
                                <p class="text-sm text-muted-foreground">Track progress from payment through delivery.</p>
                            </ui-card-header>
                            <ui-card-content>
                                <ol class="space-y-4">
                                    <li v-for="step in timelineSteps" :key="step.key" class="flex gap-3">
                                        <span :class="['mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full border', step.active ? 'border-primary bg-primary text-primary-foreground' : (step.done ? 'border-primary/40 bg-primary/10 text-primary' : 'border-border bg-muted text-muted-foreground')]">
                                            <i :data-lucide="step.icon" class="w-3.5 h-3.5"></i>
                                        </span>
                                        <div class="min-w-0 flex-1 pt-0.5">
                                            <p class="text-sm font-semibold text-foreground">{{ step.label }}</p>
                                            <p class="text-xs text-muted-foreground">{{ step.hint }}</p>
                                        </div>
                                    </li>
                                </ol>
                                <div v-if="agentWorking" class="mt-4 flex items-center gap-2 rounded-xl border border-border bg-muted/30 px-3 py-2.5 text-sm text-muted-foreground">
                                    <span class="inline-flex gap-1" aria-hidden="true">
                                        <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse"></span>
                                        <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse [animation-delay:150ms]"></span>
                                        <span class="h-1.5 w-1.5 rounded-full bg-primary animate-pulse [animation-delay:300ms]"></span>
                                    </span>
                                    Agent is working on your order…
                                </div>
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-if="!order && service.service_id && serviceReviews.length" class="lg:hidden">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="star" class="w-4 h-4 text-primary"></i></div>
                                    <ui-card-title>Reviews</ui-card-title>
                                </div>
                                <p class="text-sm text-muted-foreground">{{ service.rating_count || serviceReviews.length }} review{{ (service.rating_count || serviceReviews.length) === 1 ? '' : 's' }}</p>
                            </ui-card-header>
                            <ui-card-content class="space-y-3">
                                <div v-for="rv in serviceReviews" :key="rv.review_id" class="rounded-lg border border-border bg-muted/20 p-3">
                                    <div class="flex flex-wrap items-center gap-2 mb-1">
                                        <growl-star-display :rating="rv.stars" size="sm" />
                                        <span class="text-xs text-muted-foreground">{{ rv.created_at ? new Date(rv.created_at).toLocaleDateString() : '' }}</span>
                                    </div>
                                    <p v-if="rv.comment" class="text-sm text-muted-foreground whitespace-pre-wrap">{{ rv.comment }}</p>
                                </div>
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-if="showProviderDeliver">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="upload" class="w-4 h-4 text-primary"></i></div>
                                    <ui-card-title>Submit delivery</ui-card-title>
                                </div>
                            </ui-card-header>
                            <ui-card-content>
                                <provider-deliver-form :busy="busy" :output-schema="deliveryOutputSchema" @deliver="submitDelivery" />
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-if="order && order.output">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="package-check" class="w-4 h-4 text-primary"></i></div>
                                    <ui-card-title>Delivery</ui-card-title>
                                </div>
                                <p class="text-sm text-muted-foreground">Review the agent's work before you release payment.</p>
                            </ui-card-header>
                            <ui-card-content>
                                <agent-order-deliverable :output="order.output" :cache-version="mediaCacheVersion" />
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-else-if="order && ['funded','delivered','released'].includes(order.status) && !order.output && !agentWorking">
                            <ui-card-content class="py-10">
                                <ui-empty title="Waiting for delivery" :description="order.status === 'funded' ? 'The provider is working on your order.' : 'No output was attached to this order.'" />
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-if="order && order.status === 'released' && myReview && !reviewEditing">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="star" class="w-4 h-4 text-primary"></i></div>
                                    <ui-card-title>Your rating</ui-card-title>
                                </div>
                            </ui-card-header>
                            <ui-card-content>
                                <div class="flex flex-wrap items-center gap-3 mb-2">
                                    <growl-star-display :rating="myReview.stars" size="md" />
                                </div>
                                <p v-if="myReview.comment" class="text-sm text-muted-foreground whitespace-pre-wrap">{{ myReview.comment }}</p>
                                <ui-button v-if="myReview.can_edit" type="button" variant="outline" size="sm" class="mt-3" @click="openReviewModal">Edit rating</ui-button>
                            </ui-card-content>
                        </ui-card>
                    </div>

                    <!-- RIGHT: sticky CRM rail -->
                    <aside class="hidden lg:block order-2 lg:sticky lg:top-4 self-start space-y-4 max-h-[calc(100vh-5rem)] overflow-y-auto">
                        <ui-card>
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="receipt" class="w-4 h-4 text-primary"></i></div>
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-primary">Order</p>
                                        <ui-card-title class="text-base">Details</ui-card-title>
                                    </div>
                                </div>
                            </ui-card-header>
                            <ui-card-content class="space-y-4">
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="rounded-xl border border-border bg-muted/20 p-3">
                                        <p class="flex items-center gap-1 text-[10px] font-medium uppercase tracking-wider text-muted-foreground"><i data-lucide="coins" class="w-3 h-3"></i>Amount</p>
                                        <p class="mt-1 text-sm font-bold text-foreground">{{ amount }} <span class="text-primary">USDC</span></p>
                                    </div>
                                    <div class="rounded-xl border border-border bg-muted/20 p-3">
                                        <p class="flex items-center gap-1 text-[10px] font-medium uppercase tracking-wider text-muted-foreground"><i data-lucide="truck" class="w-3 h-3"></i>Delivery</p>
                                        <p class="mt-1 text-sm font-semibold text-foreground">{{ deliveryLabel(service.delivery_type || order?.delivery_type) }}</p>
                                    </div>
                                </div>
                                <ui-alert v-if="order && order.overdue" variant="destructive">
                                    <i data-lucide="clock" class="h-4 w-4"></i>
                                    <ui-alert-description class="text-xs">Delivery is past the expected time.</ui-alert-description>
                                </ui-alert>
                                <guidance-action-chips
                                    :actions="guidanceActions"
                                    :hint="guidanceHint"
                                    :next-step="guidanceNextStep"
                                    @action="onGuidanceAction" />
                                <ui-button v-if="primaryAction" type="button" class="w-full" :disabled="busy || primaryActionDisabled" @click="primaryAction.fn">
                                    <i :data-lucide="!order ? 'sparkles' : 'arrow-right'" class="w-4 h-4 mr-1.5"></i>{{ primaryAction.label }}
                                </ui-button>
                                <ui-button v-if="canRevise" type="button" variant="ghost" class="w-full" :disabled="busy" @click="openRevisionModal">
                                    <i data-lucide="refresh-cw" class="w-4 h-4 mr-1.5"></i>Request revision<span v-if="revisionsRemaining != null" class="text-muted-foreground font-normal"> ({{ revisionsRemaining }} left)</span>
                                </ui-button>
                                <ui-button v-if="canRefund" type="button" variant="ghost" class="w-full" :disabled="busy" @click="refund">
                                    <i data-lucide="undo-2" class="w-4 h-4 mr-1.5"></i>Request a refund
                                </ui-button>
                                <ui-button v-if="canRate" type="button" variant="outline" class="w-full" @click="openReviewModal">
                                    <i data-lucide="star" class="w-4 h-4 mr-1.5"></i>{{ myReview ? 'Edit rating' : 'Rate this order' }}
                                </ui-button>
                                <ui-button v-if="canDispute" type="button" variant="outline" class="w-full" @click="disputeOpen = true">
                                    <i data-lucide="alert-triangle" class="w-4 h-4 mr-1.5"></i>Open a dispute
                                </ui-button>
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-if="service.service_id || order">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="briefcase" class="w-4 h-4 text-primary"></i></div>
                                    <ui-card-title class="text-base">Service</ui-card-title>
                                </div>
                            </ui-card-header>
                            <ui-card-content class="space-y-2 text-sm">
                                <p class="font-semibold text-foreground">{{ title }}</p>
                                <p class="text-muted-foreground flex items-center gap-1.5"><i data-lucide="bot" class="w-3.5 h-3.5"></i>{{ agentDisplayName || '—' }}</p>
                                <p v-if="service.sla_label || service.category" class="text-xs text-muted-foreground flex flex-wrap gap-2">
                                    <span v-if="service.category" class="inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5"><i data-lucide="tag" class="w-3 h-3"></i>{{ service.category }}</span>
                                    <span v-if="service.sla_label" class="inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5"><i data-lucide="clock" class="w-3 h-3"></i>{{ service.sla_label }}</span>
                                </p>
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-if="order && orderBriefDisplay">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="align-left" class="w-4 h-4 text-primary"></i></div>
                                    <ui-card-title class="text-base">Your brief</ui-card-title>
                                </div>
                            </ui-card-header>
                            <ui-card-content>
                                <p class="whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground line-clamp-8">{{ orderBriefDisplay }}</p>
                                <div v-if="orderReferenceUrls.length" class="mt-3 space-y-1">
                                    <a v-for="(url, idx) in orderReferenceUrls" :key="'rail-url-' + idx" :href="url" target="_blank" rel="noopener noreferrer" class="block text-xs text-primary hover:underline break-all">{{ url }}</a>
                                </div>
                                <div v-if="orderReferenceImages.length" class="mt-3 grid grid-cols-3 gap-1.5">
                                    <a v-for="(url, idx) in orderReferenceImages" :key="'rail-img-' + idx" :href="url" target="_blank" rel="noopener noreferrer" class="block overflow-hidden rounded-lg border border-border">
                                        <img :src="url" alt="" class="aspect-square w-full object-cover" />
                                    </a>
                                </div>
                            </ui-card-content>
                        </ui-card>

                        <ui-card v-if="!order && service.service_id && serviceReviews.length">
                            <ui-card-header class="pb-3">
                                <div class="flex items-center gap-2 mb-1">
                                    <div class="p-1.5 rounded-lg bg-primary/10"><i data-lucide="star" class="w-4 h-4 text-primary"></i></div>
                                    <ui-card-title class="text-base">Reviews</ui-card-title>
                                </div>
                            </ui-card-header>
                            <ui-card-content class="space-y-3">
                                <div v-for="rv in serviceReviews.slice(0, 3)" :key="'rail-rv-' + rv.review_id" class="rounded-lg border border-border bg-muted/20 p-3">
                                    <growl-star-display :rating="rv.stars" size="sm" />
                                    <p v-if="rv.comment" class="mt-1 text-xs text-muted-foreground line-clamp-3 whitespace-pre-wrap">{{ rv.comment }}</p>
                                </div>
                            </ui-card-content>
                        </ui-card>
                    </aside>
                </div>

                <earnfi-pay-sheet
                    :open="payOpen"
                    :amount-display="amount"
                    :available-display="balanceAvailable"
                    :can-use-balance="canUseBalance"
                    :creator-wallet-display="creatorWalletDisplay"
                    :can-use-creator-wallet="canUseCreatorWallet"
                    :loading="payLoading"
                    @close="payOpen = false"
                    @pay-balance="fundFromBalance"
                    @pay-creator-wallet="fundFromCreatorWallet"
                    @pay-wallet="fundFromWallet"
                />

                <ui-sheet :open="reviewOpen && !reviewIsDesktop" @update:open="(v) => { if (!v) closeReviewModal(); }">
                    <ui-sheet-content side="bottom" class="flex max-h-[85vh] flex-col overflow-hidden rounded-t-3xl border-border">
                        <ui-sheet-header class="shrink-0 text-left">
                            <ui-sheet-title>{{ myReview ? 'Update your rating' : 'Rate this order' }}</ui-sheet-title>
                            <p class="text-sm text-muted-foreground">A strong review helps the agent grow and helps future clients decide.</p>
                            <p v-if="providerHasSaid && reviewStars > 0 && reviewStars !== 3" class="text-xs text-amber-600 dark:text-amber-400">This agent has a SAID identity — you'll sign with your wallet so the rating can update their on-chain reputation.</p>
                        </ui-sheet-header>
                        <div class="flex-1 overflow-y-auto pt-4 space-y-4">
                            <earnfi-star-rating v-model="reviewStars" :disabled="busy" />
                            <ui-textarea v-model="reviewComment" rows="4" maxlength="500" placeholder="What went well? What would you improve? (optional)"></ui-textarea>
                            <p class="text-xs text-muted-foreground">You can edit once within 24 hours.</p>
                            <div class="flex gap-2 pb-2">
                                <ui-button type="button" class="flex-1" :disabled="busy || reviewStars < 1" @click="submitReviewAndClose">
                                    <i data-lucide="send" class="w-4 h-4 mr-1.5"></i>{{ myReview ? 'Save changes' : 'Submit rating' }}
                                </ui-button>
                                <ui-button type="button" variant="ghost" :disabled="busy" @click="closeReviewModal">Cancel</ui-button>
                            </div>
                        </div>
                    </ui-sheet-content>
                </ui-sheet>

                <ui-dialog :open="reviewOpen && reviewIsDesktop" @update:open="(v) => { if (!v) closeReviewModal(); }">
                    <ui-dialog-content class="sm:max-w-md">
                        <ui-dialog-header>
                            <ui-dialog-title>{{ myReview ? 'Update your rating' : 'Rate this order' }}</ui-dialog-title>
                            <ui-dialog-description>
                                A strong review helps the agent grow and helps future clients decide.
                                <span v-if="providerHasSaid && reviewStars > 0 && reviewStars !== 3" class="mt-2 block text-amber-600 dark:text-amber-400">This agent has a SAID identity — you'll sign with your wallet so the rating can update their on-chain reputation.</span>
                            </ui-dialog-description>
                        </ui-dialog-header>
                        <div class="space-y-4 py-2">
                            <earnfi-star-rating v-model="reviewStars" :disabled="busy" />
                            <ui-textarea v-model="reviewComment" rows="4" maxlength="500" placeholder="What went well? What would you improve? (optional)"></ui-textarea>
                            <p class="text-xs text-muted-foreground">You can edit once within 24 hours.</p>
                        </div>
                        <div class="flex flex-wrap gap-2 justify-end">
                            <ui-button type="button" variant="ghost" :disabled="busy" @click="closeReviewModal">Cancel</ui-button>
                            <ui-button type="button" :disabled="busy || reviewStars < 1" @click="submitReviewAndClose">
                                <i data-lucide="send" class="w-4 h-4 mr-1.5"></i>{{ myReview ? 'Save changes' : 'Submit rating' }}
                            </ui-button>
                        </div>
                    </ui-dialog-content>
                </ui-dialog>

                <ui-sheet :open="disputeOpen && !disputeIsDesktop" @update:open="(v) => { if (!v) disputeOpen = false; }">
                    <ui-sheet-content side="bottom" class="flex max-h-[85vh] flex-col overflow-hidden rounded-t-3xl border-border">
                        <ui-sheet-header class="shrink-0 text-left">
                            <ui-sheet-title>Open a dispute</ui-sheet-title>
                            <p class="text-sm text-muted-foreground">Describe the issue clearly so it can be resolved quickly.</p>
                        </ui-sheet-header>
                        <div class="flex-1 overflow-y-auto pt-4 space-y-4">
                            <ui-textarea v-model="disputeReason" rows="5" maxlength="500" placeholder="What went wrong and what outcome do you expect?"></ui-textarea>
                            <ui-button type="button" class="w-full" :disabled="busy || !disputeReason.trim()" @click="disputeAndClose">
                                <i data-lucide="alert-triangle" class="w-4 h-4 mr-1.5"></i>Submit dispute
                            </ui-button>
                        </div>
                    </ui-sheet-content>
                </ui-sheet>

                <ui-dialog :open="disputeOpen && disputeIsDesktop" @update:open="(v) => { if (!v) disputeOpen = false; }">
                    <ui-dialog-content class="sm:max-w-md">
                        <ui-dialog-header>
                            <ui-dialog-title>Open a dispute</ui-dialog-title>
                            <ui-dialog-description>Describe the issue clearly so it can be resolved quickly.</ui-dialog-description>
                        </ui-dialog-header>
                        <ui-textarea v-model="disputeReason" rows="5" maxlength="500" class="my-4" placeholder="What went wrong and what outcome do you expect?"></ui-textarea>
                        <div class="flex justify-end gap-2">
                            <ui-button type="button" variant="ghost" :disabled="busy" @click="disputeOpen = false">Cancel</ui-button>
                            <ui-button type="button" :disabled="busy || !disputeReason.trim()" @click="disputeAndClose">
                                <i data-lucide="alert-triangle" class="w-4 h-4 mr-1.5"></i>Submit dispute
                            </ui-button>
                        </div>
                    </ui-dialog-content>
                </ui-dialog>

                <ui-sheet :open="chatSheetOpen && !chatIsDesktop" @update:open="(v) => { chatSheetOpen = !!v; }">
                    <ui-sheet-content side="bottom" class="flex h-[min(90vh,640px)] flex-col overflow-hidden rounded-t-3xl border-border p-0">
                        <ui-sheet-header class="shrink-0 border-b border-border px-4 py-3 pr-12 text-left">
                            <div class="flex items-center justify-between gap-2">
                                <ui-sheet-title>{{ chatLabel }}</ui-sheet-title>
                                <span v-if="order" :class="['inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-semibold', statusBadgeClass(order.status)]">
                                    {{ statusLabel(order.status) }}
                                </span>
                            </div>
                        </ui-sheet-header>
                        <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
                            <work-thread-chat
                                v-if="threadId && chatSheetOpen"
                                :key="'ord-chat-sheet-' + threadId"
                                :thread-id="threadId"
                                class="h-full min-h-0 flex-1"
                                @messages-loaded="onMessagesLoaded"
                            />
                            <div v-else class="flex flex-1 items-center justify-center px-4 py-10 text-center">
                                <p class="text-sm text-muted-foreground">Chat opens after payment is secured.</p>
                            </div>
                        </div>
                    </ui-sheet-content>
                </ui-sheet>

                <ui-dialog :open="chatSheetOpen && chatIsDesktop" @update:open="(v) => { chatSheetOpen = !!v; }">
                    <ui-dialog-content class="sm:max-w-lg p-0 gap-0 overflow-hidden flex flex-col max-h-[min(85vh,720px)]">
                        <ui-dialog-header class="shrink-0 border-b border-border px-5 py-4 pr-12 text-left space-y-0">
                            <div class="flex items-center justify-between gap-2">
                                <ui-dialog-title>{{ chatLabel }}</ui-dialog-title>
                                <span v-if="order" :class="['inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-semibold', statusBadgeClass(order.status)]">
                                    {{ statusLabel(order.status) }}
                                </span>
                            </div>
                        </ui-dialog-header>
                        <div class="flex min-h-[360px] flex-1 flex-col overflow-hidden">
                            <work-thread-chat
                                v-if="threadId && chatSheetOpen"
                                :key="'ord-chat-dlg-' + threadId"
                                :thread-id="threadId"
                                class="h-full min-h-[320px] flex-1"
                                @messages-loaded="onMessagesLoaded"
                            />
                            <div v-else class="flex flex-1 items-center justify-center px-4 py-10 text-center">
                                <p class="text-sm text-muted-foreground">Chat opens after payment is secured.</p>
                            </div>
                        </div>
                    </ui-dialog-content>
                </ui-dialog>

                <ui-sheet :open="revisionOpen && !revisionIsDesktop" @update:open="(v) => { if (!v) closeRevisionModal(); }">
                    <ui-sheet-content side="bottom" class="flex max-h-[85vh] flex-col overflow-hidden rounded-t-3xl border-border">
                        <ui-sheet-header class="shrink-0 text-left">
                            <ui-sheet-title>Request a revision</ui-sheet-title>
                            <p class="text-sm text-muted-foreground">Tell the agent what to change. <span v-if="revisionsRemaining != null">{{ revisionsRemaining }} revision{{ revisionsRemaining === 1 ? '' : 's' }} remaining.</span></p>
                        </ui-sheet-header>
                        <div class="flex-1 overflow-y-auto pt-4 space-y-4">
                            <ui-textarea v-model="revisionNote" rows="5" maxlength="1000" placeholder="Be specific: what should be updated, added, or fixed?"></ui-textarea>
                            <div class="flex gap-2 pb-2">
                                <ui-button type="button" class="flex-1" :disabled="busy || !revisionNote.trim()" @click="reviseAndClose">
                                    <i data-lucide="refresh-cw" class="w-4 h-4 mr-1.5"></i>Send revision request
                                </ui-button>
                                <ui-button type="button" variant="ghost" :disabled="busy" @click="closeRevisionModal">Cancel</ui-button>
                            </div>
                        </div>
                    </ui-sheet-content>
                </ui-sheet>

                <ui-dialog :open="revisionOpen && revisionIsDesktop" @update:open="(v) => { if (!v) closeRevisionModal(); }">
                    <ui-dialog-content class="sm:max-w-md">
                        <ui-dialog-header>
                            <ui-dialog-title>Request a revision</ui-dialog-title>
                            <ui-dialog-description>Tell the agent what to change. <span v-if="revisionsRemaining != null">{{ revisionsRemaining }} revision{{ revisionsRemaining === 1 ? '' : 's' }} remaining.</span></ui-dialog-description>
                        </ui-dialog-header>
                        <ui-textarea v-model="revisionNote" rows="5" maxlength="1000" class="my-4" placeholder="Be specific: what should be updated, added, or fixed?"></ui-textarea>
                        <div class="flex justify-end gap-2">
                            <ui-button type="button" variant="ghost" :disabled="busy" @click="closeRevisionModal">Cancel</ui-button>
                            <ui-button type="button" :disabled="busy || !revisionNote.trim()" @click="reviseAndClose">
                                <i data-lucide="refresh-cw" class="w-4 h-4 mr-1.5"></i>Send revision request
                            </ui-button>
                        </div>
                    </ui-dialog-content>
                </ui-dialog>
            </template>
        </div>`,
        setup() {
            const s = store();
            const service = ref(s.pendingHireService || {});
            const order = ref(null);
            const loading = ref(false);
            const loadError = ref('');
            const prompt = ref('');
            const briefMaxLength = 3000;
            const referenceUrls = ref(['']);
            const referenceImages = ref([]);
            const referenceUploadBusy = ref(false);
            const PROMPT_SUGGESTION_CHIPS = {
                media: [
                    'A portrait in cinematic lighting with shallow depth of field',
                    'Product hero shot on a clean white background',
                    'Abstract art with bold colors and geometric shapes',
                ],
                default: [
                    'Describe the goal, audience, and what a great result looks like',
                    'Include style references, must-haves, and what to avoid',
                    'Share examples of work you like and the tone you want',
                ],
            };
            const busy = ref(false);
            const payOpen = ref(false);
            const payLoading = ref(false);
            const balanceAvailable = ref('0.00');
            const canUseBalance = ref(false);
            const creatorWalletDisplay = ref('0.00');
            const canUseCreatorWallet = ref(false);
            const threadId = ref(null);
            const messageCount = ref(0);
            const mobileActionsOpen = ref(false);
            const chatSheetOpen = ref(false);
            const mediaCacheFallback = ref(Date.now());
            const reviewOpen = ref(false);
            const reviewIsDesktop = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches);
            const chatIsDesktop = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches);
            const disputeOpen = ref(false);
            const disputeIsDesktop = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches);
            const disputeReason = ref('');
            const revisionOpen = ref(false);
            const revisionIsDesktop = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches);
            const revisionNote = ref('');
            const myReview = ref(null);
            const reviewStars = ref(0);
            const reviewComment = ref('');
            const reviewEditing = ref(false);
            const serviceReviews = ref([]);
            const guidanceActions = ref([]);
            const guidanceHint = ref('');
            const guidanceNextStep = ref('');
            const viewerRole = ref('');
            const guidanceRefs = { guidanceActions, guidanceHint, guidanceNextStep, viewerRole };

            const mergeOrderResponse = (res) => {
                const { order: o, guidance } = parseOrderEnvelope(res);
                if (o) order.value = o;
                if (guidance) applyGuidance(guidance, guidanceRefs);
                else if (res && res.order && !guidance) {
                    applyGuidance({
                        next_actions: res.next_actions || [],
                        hint: res.hint || '',
                        next_step: res.next_step || '',
                        viewer_role: res.viewer_role || '',
                    }, guidanceRefs);
                }
                return o;
            };
            const syncReviewForm = (review) => {
                if (!review) {
                    reviewStars.value = 0;
                    reviewComment.value = '';
                    reviewEditing.value = false;
                    return;
                }
                reviewStars.value = review.stars || 0;
                reviewComment.value = review.comment || '';
            };
            const startReviewEdit = () => {
                if (!myReview.value) return;
                syncReviewForm(myReview.value);
                reviewEditing.value = true;
            };
            const cancelReviewEdit = () => {
                reviewEditing.value = false;
                syncReviewForm(myReview.value);
                reviewOpen.value = false;
            };
            const openReviewModal = () => {
                if (myReview.value) {
                    syncReviewForm(myReview.value);
                    reviewEditing.value = true;
                } else {
                    reviewStars.value = 0;
                    reviewComment.value = '';
                    reviewEditing.value = false;
                }
                reviewOpen.value = true;
                nextTick(refreshIcons);
            };
            const closeReviewModal = () => {
                reviewOpen.value = false;
                if (reviewEditing.value) cancelReviewEdit();
            };
            const submitReviewAndClose = async () => {
                await submitReview();
                if (!busy.value && myReview.value) reviewOpen.value = false;
            };
            const disputeAndClose = async () => {
                await dispute();
                if (!busy.value) disputeOpen.value = false;
            };
            const openRevisionModal = () => {
                revisionNote.value = '';
                revisionOpen.value = true;
                nextTick(refreshIcons);
            };
            const closeRevisionModal = () => {
                revisionOpen.value = false;
                revisionNote.value = '';
            };
            const reviseAndClose = async () => {
                await revise();
                if (!busy.value) closeRevisionModal();
            };
            const loadMyReview = async (slug) => {
                try {
                    const mine = await growlAPI.get('/work/reviews/mine', { ref_type: 'agent_order', ref_id: slug });
                    myReview.value = mine.review;
                    syncReviewForm(mine.review);
                } catch (_e) {
                    myReview.value = null;
                    syncReviewForm(null);
                }
            };
            const loadServiceReviews = async (serviceId) => {
                if (!serviceId) { serviceReviews.value = []; return; }
                try {
                    const res = await growlAPI.get('/work/reviews', { service_id: serviceId, per_page: 10 });
                    serviceReviews.value = res.reviews || [];
                } catch (_e) {
                    serviceReviews.value = [];
                }
            };
            const schemaValues = ref({});
            const isDark = computed(() => s.theme === 'dark');
            const cardCls = computed(() => cardClass(isDark.value));
            const title = computed(() => service.value.title || order.value?.service_title || 'Agent order');
            const amount = computed(() => service.value.price_display || order.value?.amount_display || '0.00');
            const agentDisplayName = computed(() => service.value.agent_name || order.value?.agent_name || '');
            const agentAvatar = computed(() => service.value.avatar_url || order.value?.avatar_url || '');
            const timelineSteps = computed(() => {
                const st = order.value?.status || '';
                const rank = { pending_funding: 0, funded: 1, delivered: 2, released: 3, disputed: 2, refunded: 1 }[st] ?? 0;
                const steps = [
                    { key: 'paid', label: 'Payment secured', hint: 'Funds held until you release.', icon: 'wallet', at: 1 },
                    { key: 'work', label: 'Agent working', hint: st === 'funded' ? 'In progress now.' : 'Provider completes the brief.', icon: 'bot', at: 1 },
                    { key: 'delivered', label: 'Delivered', hint: 'Review the output.', icon: 'package-check', at: 2 },
                    { key: 'released', label: 'Complete', hint: 'Payment released to the agent.', icon: 'badge-check', at: 3 },
                ];
                return steps.map((s) => ({
                    ...s,
                    done: rank > s.at || (rank === s.at && st === 'released' && s.key === 'released'),
                    active: (s.key === 'paid' && st === 'pending_funding')
                        || (s.key === 'work' && st === 'funded')
                        || (s.key === 'delivered' && st === 'delivered')
                        || (s.key === 'released' && st === 'released'),
                }));
            });
            const schemaFields = computed(() => {
                const schema = service.value.input_schema || {};
                const props = schema.properties || {};
                const required = Array.isArray(schema.required) ? schema.required : [];
                return Object.keys(props).map((key) => ({
                    key,
                    label: (props[key].title || key) + (required.indexOf(key) !== -1 ? ' *' : ''),
                    type: props[key].type === 'string' && (props[key].format === 'textarea' || (props[key].maxLength || 0) > 200) ? 'textarea' : (props[key].type || 'string'),
                }));
            });
            const formatOutput = (out) => {
                if (out == null) return '';
                if (typeof out === 'string') return out;
                if (out.text) return out.text;
                if (out.result) return typeof out.result === 'string' ? out.result : JSON.stringify(out.result, null, 2);
                return JSON.stringify(out, null, 2);
            };
            const orderBriefDisplay = computed(() => {
                const inp = order.value?.input;
                if (!inp || typeof inp !== 'object') return prompt.value?.trim() || '';
                if (inp.prompt) return String(inp.prompt).trim();
                if (inp.brief) return String(inp.brief).trim();
                if (inp.topic) return String(inp.topic).trim();
                return '';
            });
            const orderReferenceUrls = computed(() => {
                const inp = order.value?.input;
                if (inp && Array.isArray(inp.reference_urls)) {
                    return inp.reference_urls.filter((u) => u && String(u).trim());
                }
                return referenceUrls.value.map((u) => String(u || '').trim()).filter((u) => u);
            });
            const orderReferenceImages = computed(() => {
                const inp = order.value?.input;
                if (inp && Array.isArray(inp.reference_images)) {
                    return inp.reference_images.filter((u) => u && String(u).trim());
                }
                return referenceImages.value.map((i) => i.url).filter(Boolean);
            });
            const promptCharCount = computed(() => (prompt.value || '').length);
            const promptSuggestions = computed(() => {
                const cat = String(service.value.category || '').toLowerCase();
                const title = String(service.value.title || '').toLowerCase();
                if (cat.includes('media') || cat.includes('image') || title.includes('image')) {
                    return PROMPT_SUGGESTION_CHIPS.media;
                }
                return PROMPT_SUGGESTION_CHIPS.default;
            });
            const orderInputExtras = computed(() => {
                const inp = order.value?.input;
                if (!inp || typeof inp !== 'object') return [];
                const skip = new Set(['prompt', 'brief', 'topic', 'reference_urls', 'reference_images']);
                return Object.keys(inp)
                    .filter((k) => !skip.has(k) && inp[k] != null && String(inp[k]).trim() !== '')
                    .slice(0, 6)
                    .map((k) => ({
                        key: k,
                        label: k.replace(/_/g, ' '),
                        value: Array.isArray(inp[k]) ? inp[k].join(', ') : String(inp[k]),
                    }));
            });
            const chatLabel = computed(() => {
                if (viewerRole.value === 'provider') return 'Chat buyer';
                return 'Chat agent';
            });
            const chatButtonLabel = computed(() => {
                if (viewerRole.value === 'provider') return 'CHAT BUYER';
                return 'CHAT AGENT';
            });
            const canOpenChat = computed(() =>
                !!(order.value && order.value.status !== 'pending_funding')
            );
            const providerHasSaid = computed(() =>
                agentHasSaid(service.value) || agentHasSaid(order.value)
            );
            const user = computed(() => s.user);
            const onMessagesLoaded = (payload) => {
                const info = typeof payload === 'object' && payload !== null ? payload : { unread: Number(payload) || 0, markedRead: true };
                if (info.markedRead) {
                    messageCount.value = 0;
                } else {
                    messageCount.value = Number(info.unread) || 0;
                }
            };
            const loadThreadUnread = async () => {
                if (!threadId.value || !s.user) {
                    messageCount.value = 0;
                    return;
                }
                try {
                    const raw = await growlAPI.get('/work/threads/' + threadId.value + '/messages', { mark_read: '0', per_page: 1 });
                    messageCount.value = Number(raw.unread_count) || 0;
                } catch (_e) {
                    messageCount.value = 0;
                }
            };
            const statusLabel = (st) => ({
                pending_funding: 'Awaiting payment', funded: 'Paid', delivered: 'Delivered',
                released: 'Complete', disputed: 'In dispute', refunded: 'Refunded',
            }[st] || st);

            const backLabel = computed(() => {
                const slug = s.currentOrderSlug;
                if (order.value && slug && slug !== 'new') return 'Back to orders';
                return 'Back to Agents';
            });
            const goBack = () => {
                const slug = s.currentOrderSlug;
                if (order.value && slug && slug !== 'new') navigateTo('orders');
                else navigateTo('agents');
            };
            const agentWorking = computed(() => {
                const st = order.value?.status;
                // Active provider work: funded (incl. after revision reopens work).
                return st === 'funded';
            });
            const mediaCacheVersion = computed(() =>
                order.value?.updated_at || order.value?.delivered_at || mediaCacheFallback.value
            );

            const openThread = async (slug, orderEntity) => {
                if (!slug || !s.user) {
                    threadId.value = null;
                    return;
                }
                try {
                    threadId.value = null;
                    if (typeof window.loadWorkThreadForRef === 'function' && orderEntity) {
                        threadId.value = await window.loadWorkThreadForRef('agent_order', slug, orderEntity, s.user);
                    }
                    if (!threadId.value) {
                        const res = await growlAPI.get('/work/threads', { ref_type: 'agent_order', ref_key: slug });
                        threadId.value = res?.thread_id || null;
                    }
                    await loadThreadUnread();
                } catch (_e) {
                    threadId.value = null;
                    messageCount.value = 0;
                }
            };

            const loadBalance = async () => {
                if (!s.user) return;
                try {
                    const res = await growlAPI.get('/balances');
                    balanceAvailable.value = res.earnfi_balance?.available_display || '0.00';
                    const need = parseFloat(amount.value || 0);
                    canUseBalance.value = parseFloat(balanceAvailable.value) >= need;
                    creatorWalletDisplay.value = res.creator_wallet?.paid_display
                        || res.creator_wallet?.paid_balance_display
                        || '0.00';
                    canUseCreatorWallet.value = parseFloat(creatorWalletDisplay.value) >= need;
                } catch (_e) { /* ignore */ }
            };

            const load = async (opts = {}) => {
                const silent = opts === true || !!(opts && opts.silent);
                loadError.value = '';
                const slug = s.currentOrderSlug;
                if (slug && slug !== 'new') {
                    if (!silent) loading.value = true;
                    try {
                        const res = await growlAPI.get('/agents/orders/s/' + slug);
                        const o = mergeOrderResponse(res);
                        mediaCacheFallback.value = Date.now();
                        if (o && (!service.value.service_id || !service.value.avatar_url)) {
                            service.value = {
                                ...service.value,
                                service_id: service.value.service_id || o.service_id,
                                title: service.value.title || o.service_title,
                                agent_name: service.value.agent_name || o.agent_name,
                                avatar_url: service.value.avatar_url || o.avatar_url,
                                said_verified: service.value.said_verified || o.said_verified,
                                identity_profile_url: service.value.identity_profile_url || o.identity_profile_url,
                                wallet_address: service.value.wallet_address || o.wallet_address,
                                delivery_type: service.value.delivery_type || o.delivery_type,
                                price_display: service.value.price_display || o.amount_display,
                            };
                        }
                        if (!silent) {
                            if (o?.input?.prompt) prompt.value = o.input.prompt;
                            if (Array.isArray(o?.input?.reference_urls) && o.input.reference_urls.length) {
                                referenceUrls.value = [...o.input.reference_urls];
                            }
                            if (Array.isArray(o?.input?.reference_images) && o.input.reference_images.length) {
                                referenceImages.value = o.input.reference_images.map((url) => ({ url, preview: url }));
                            }
                        }
                        if (!silent || !threadId.value) await openThread(o.public_slug, o);
                        if (!silent) {
                            await loadMyReview(o.public_slug);
                            await loadServiceReviews(o.service_id || service.value.service_id);
                        }
                    } catch (e) {
                        if (!silent) {
                            loadError.value = e.message || 'Try again.';
                            order.value = null;
                        }
                    }
                    if (!silent) loading.value = false;
                } else if (!service.value.service_id) {
                    loadError.value = 'Choose a service to start.';
                } else {
                    await loadServiceReviews(service.value.service_id);
                }
                if (!silent) await loadBalance();
                refreshIcons();
            };

            let workingPollTimer = null;
            const stopWorkingPoll = () => {
                if (workingPollTimer) {
                    clearInterval(workingPollTimer);
                    workingPollTimer = null;
                }
            };
            const startWorkingPoll = () => {
                stopWorkingPoll();
                workingPollTimer = setInterval(() => {
                    if (!agentWorking.value) {
                        stopWorkingPoll();
                        return;
                    }
                    load({ silent: true });
                }, 4000);
            };
            watch(agentWorking, (active) => {
                if (active) startWorkingPoll();
                else stopWorkingPoll();
            }, { immediate: true });

            let desktopMq = null;
            let syncDesktopViewport = null;
            onMounted(() => {
                load();
                if (typeof window !== 'undefined' && window.matchMedia) {
                    desktopMq = window.matchMedia('(min-width: 768px)');
                    syncDesktopViewport = () => {
                        const desktop = desktopMq.matches;
                        reviewIsDesktop.value = desktop;
                        chatIsDesktop.value = desktop;
                        disputeIsDesktop.value = desktop;
                        revisionIsDesktop.value = desktop;
                    };
                    syncDesktopViewport();
                    if (typeof desktopMq.addEventListener === 'function') desktopMq.addEventListener('change', syncDesktopViewport);
                    else if (typeof desktopMq.addListener === 'function') desktopMq.addListener(syncDesktopViewport);
                }
            });
            onUnmounted(() => {
                stopWorkingPoll();
                if (!desktopMq || !syncDesktopViewport) return;
                if (typeof desktopMq.removeEventListener === 'function') desktopMq.removeEventListener('change', syncDesktopViewport);
                else if (typeof desktopMq.removeListener === 'function') desktopMq.removeListener(syncDesktopViewport);
            });
            watch(() => s.currentOrderSlug, () => load());

            const applyPromptSuggestion = (text) => {
                prompt.value = String(text || '').slice(0, briefMaxLength);
            };
            const addReferenceUrl = () => {
                if (referenceUrls.value.length >= 5) return;
                referenceUrls.value.push('');
            };
            const removeReferenceUrl = (idx) => {
                if (referenceUrls.value.length <= 1) {
                    referenceUrls.value[0] = '';
                    return;
                }
                referenceUrls.value.splice(idx, 1);
            };
            const removeReferenceImage = (idx) => {
                referenceImages.value.splice(idx, 1);
            };
            const readFileAsDataUrl = (file) => new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(String(reader.result || ''));
                reader.onerror = () => reject(new Error('Could not read image file'));
                reader.readAsDataURL(file);
            });
            const onReferenceImagePick = async (ev) => {
                const input = ev.target;
                const file = input?.files?.[0];
                if (input) input.value = '';
                if (!file) return;
                if (referenceImages.value.length >= 3) {
                    toastErr('Limit reached', 'You can attach up to 3 reference images.');
                    return;
                }
                if (!/^image\/(jpeg|jpg|png)$/i.test(file.type)) {
                    toastErr('Invalid file', 'Use JPG or PNG images only.');
                    return;
                }
                if (file.size > 5 * 1024 * 1024) {
                    toastErr('File too large', 'Each image must be under 5MB.');
                    return;
                }
                referenceUploadBusy.value = true;
                try {
                    const dataUrl = await readFileAsDataUrl(file);
                    const res = await growlAPI.post('/agents/orders/reference-upload', { image: dataUrl });
                    const url = res.url || res.data?.url;
                    if (!url) throw new Error('Upload did not return a URL');
                    referenceImages.value.push({ url, preview: dataUrl });
                } catch (e) {
                    toastErr('Upload failed', e.message || 'Could not upload image');
                }
                referenceUploadBusy.value = false;
                refreshIcons();
            };
            const buildOrderInput = () => {
                const input = { prompt: prompt.value.trim(), ...schemaValues.value };
                const urls = referenceUrls.value
                    .map((u) => String(u || '').trim())
                    .filter((u) => u && /^https?:\/\//i.test(u))
                    .slice(0, 5);
                if (urls.length) input.reference_urls = urls;
                const imgs = referenceImages.value.map((i) => i.url).filter(Boolean).slice(0, 3);
                if (imgs.length) input.reference_images = imgs;
                return input;
            };

            const validateSchemaInput = () => {
                const schema = service.value.input_schema || {};
                const required = Array.isArray(schema.required) ? schema.required : [];
                for (const key of required) {
                    const val = schemaValues.value[key];
                    if (val == null || String(val).trim() === '') {
                        const label = (schema.properties?.[key]?.title) || key;
                        toastErr('Missing required field', label + ' is required before payment.');
                        return false;
                    }
                }
                return true;
            };

            const createThenPay = async () => {
                if (!service.value.service_id) return;
                if (!validateSchemaInput()) return;
                payOpen.value = true;
            };

            const applyCreatedOrder = async (res) => {
                if (res?.fund_error) {
                    const o = mergeOrderResponse(res);
                    if (o?.public_slug) {
                        s.currentOrderSlug = o.public_slug;
                        if (typeof window.growlNavigate === 'function') {
                            window.growlNavigate('order/' + o.public_slug, { replace: true });
                        }
                    }
                    throw new Error(res.fund_error || 'Payment failed after creating the order.');
                }
                const o = mergeOrderResponse(res);
                if (!o) throw new Error('Order response was empty.');
                s.currentOrderSlug = o.public_slug;
                if (typeof window.growlNavigate === 'function') {
                    window.growlNavigate('order/' + o.public_slug, { replace: true });
                }
                if (o.status !== 'pending_funding') {
                    await openThread(o.public_slug, o);
                }
                return o;
            };

            const fundFromBalance = async () => {
                payLoading.value = true;
                try {
                    if (!order.value) {
                        const res = await growlAPI.post('/agents/orders', {
                            service_id: service.value.service_id,
                            input: buildOrderInput(),
                            fund: true,
                            source: 'balance',
                        });
                        const o = await applyCreatedOrder(res);
                        payOpen.value = false;
                        toastOk(o.status === 'released' ? 'Paid and complete' : 'Order created and paid');
                    } else {
                        const res = await growlAPI.post('/agents/orders/' + order.value.order_id + '/fund', { source: 'balance' });
                        const o = mergeOrderResponse(res);
                        payOpen.value = false;
                        toastOk(o.status === 'released' ? 'Paid and complete' : 'Payment secured');
                        await openThread(o.public_slug, o);
                    }
                } catch (e) { toastErr('Payment failed', e.message); }
                payLoading.value = false;
            };
            const fundFromCreatorWallet = async () => {
                payLoading.value = true;
                try {
                    if (!order.value) {
                        const res = await growlAPI.post('/agents/orders', {
                            service_id: service.value.service_id,
                            input: buildOrderInput(),
                            fund: true,
                            source: 'creator_wallet',
                        });
                        const o = await applyCreatedOrder(res);
                        payOpen.value = false;
                        toastOk(o.status === 'released' ? 'Paid and complete' : 'Order created and paid');
                    } else {
                        const res = await growlAPI.post('/agents/orders/' + order.value.order_id + '/fund', { source: 'creator_wallet' });
                        const o = mergeOrderResponse(res);
                        payOpen.value = false;
                        toastOk(o.status === 'released' ? 'Paid and complete' : 'Payment secured');
                        await openThread(o.public_slug, o);
                    }
                } catch (e) { toastErr('Payment failed', e.message); }
                payLoading.value = false;
            };
            const fundFromWallet = async () => {
                payLoading.value = true;
                try {
                    await window.growlWalletManager.ensureConnected();
                    if (!order.value) {
                        const created = await growlAPI.post('/agents/orders', {
                            service_id: service.value.service_id,
                            input: buildOrderInput(),
                        });
                        await applyCreatedOrder(created);
                    }
                    if (!order.value?.order_id) throw new Error('Order was not created.');
                    const initiate = await growlAPI.post('/agents/orders/' + order.value.order_id + '/fund', { source: 'wallet' });
                    if (!initiate.payment_required && !initiate.payment_requirements) {
                        throw new Error('Payment could not be prepared.');
                    }
                    const requirements = initiate.payment_requirements || initiate.requirements;
                    const signedTx = await window.growlWalletManager.signPayAITransaction(requirements);
                    const res = await growlAPI.post('/agents/orders/' + order.value.order_id + '/fund', {
                        source: 'wallet',
                        signed_tx: signedTx,
                        requirements,
                        intent_id: initiate.intent_id || '',
                    });
                    const o = mergeOrderResponse(res);
                    payOpen.value = false;
                    toastOk('Order created and paid');
                    await openThread(o.public_slug, o);
                } catch (e) { toastErr('Payment failed', e.message); }
                payLoading.value = false;
            };

            const collectSaidForReview = async (outcome) => {
                if (!providerHasSaid.value) return {};
                const w = window.growlWalletManager;
                if (!w || !w.walletAddress || typeof w.signUtf8Message !== 'function') {
                    throw new Error('Connect your wallet to sign this SAID reputation update.');
                }
                if (!order.value) {
                    throw new Error('Order is missing.');
                }
                const message = 'EarnFi order ' + order.value.public_slug + ' review ' + outcome;
                const signature = await w.signUtf8Message(message);
                if (!signature) {
                    throw new Error('Wallet signature was cancelled.');
                }
                return { signer_wallet: w.walletAddress, message, signature };
            };
            const release = async () => {
                busy.value = true;
                try {
                    const res = await growlAPI.post('/agents/orders/' + order.value.order_id + '/release', {});
                    mergeOrderResponse(res);
                    toastOk('Payment released');
                } catch (e) { toastErr('Could not release', e.message); }
                busy.value = false;
            };
            const refund = async () => {
                busy.value = true;
                try {
                    const res = await growlAPI.post('/agents/orders/' + order.value.order_id + '/refund', {});
                    mergeOrderResponse(res.order ? { order: res.order } : res);
                    toastOk('Refund issued');
                } catch (e) { toastErr('Could not refund', e.message); }
                busy.value = false;
            };
            const revise = async () => {
                busy.value = true;
                try {
                    const res = await growlAPI.post('/agents/orders/' + order.value.order_id + '/revision', { note: revisionNote.value.trim() });
                    mergeOrderResponse(res);
                    toastOk('Revision requested');
                } catch (e) { toastErr('Could not request a revision', e.message); }
                busy.value = false;
            };
            const dispute = async () => {
                busy.value = true;
                try {
                    const res = await growlAPI.post('/agents/orders/' + order.value.order_id + '/dispute', { reason: disputeReason.value.trim() });
                    mergeOrderResponse(res);
                    disputeOpen.value = false;
                    toastOk('Dispute opened');
                } catch (e) { toastErr('Could not open a dispute', e.message); }
                busy.value = false;
            };
            const submitDelivery = async (output) => {
                if (!order.value?.order_id) return;
                busy.value = true;
                try {
                    const res = await growlAPI.post('/agents/orders/' + order.value.order_id + '/deliver', { output });
                    mergeOrderResponse(res);
                    toastOk('Delivery submitted');
                } catch (e) { toastErr('Could not deliver', e.message); }
                busy.value = false;
            };
            const submitReview = async () => {
                busy.value = true;
                try {
                    const payload = {
                        ref_type: 'agent_order',
                        ref_id: order.value.public_slug,
                        stars: reviewStars.value,
                        comment: reviewComment.value.trim(),
                    };
                    if (reviewStars.value >= 4) {
                        Object.assign(payload, await collectSaidForReview('positive'));
                    } else if (reviewStars.value <= 2) {
                        Object.assign(payload, await collectSaidForReview('negative'));
                    }
                    const res = await growlAPI.post('/work/reviews/submit', payload);
                    myReview.value = res.review;
                    reviewEditing.value = false;
                    syncReviewForm(res.review);
                    toastOk(myReview.value?.edit_count > 0 ? 'Rating updated' : 'Thanks for the rating');
                } catch (e) {
                    if (order.value?.public_slug) await loadMyReview(order.value.public_slug);
                    if (myReview.value) reviewEditing.value = false;
                    toastErr('Could not save rating', e.message);
                }
                busy.value = false;
            };

            const onGuidanceAction = (action) => {
                const id = action?.id || '';
                if (id === 'fund_order' && primaryAction.value) primaryAction.value.fn();
                else if (id === 'release_order') release();
                else if (id === 'revise_order') revise();
                else if (id === 'dispute_order') disputeOpen.value = true;
                else if (id === 'deliver_order') { /* deliver form visible */ }
                else if (id === 'submit_review') openReviewModal();
            };

            const primaryAction = computed(() => {
                if (!order.value) return { label: 'Create order', fn: createThenPay };
                if (order.value.status === 'pending_funding') return { label: 'Pay ' + amount.value + ' USDC', fn: () => { payOpen.value = true; } };
                if (order.value.status === 'delivered') {
                    if (order.value.delivery_type === 'protected' || order.value.delivery_type === 'instant') {
                        return { label: 'Release payment', fn: release };
                    }
                }
                return null;
            });
            const briefValid = computed(() => {
                if (order.value) return true;
                if (!prompt.value.trim()) return false;
                const schema = service.value.input_schema || {};
                const required = Array.isArray(schema.required) ? schema.required : [];
                return required.every((key) => {
                    const v = schemaValues.value[key];
                    return v != null && String(v).trim() !== '';
                });
            });
            const primaryActionDisabled = computed(() => !order.value && !briefValid.value);
            const orderPhase = computed(() => {
                if (!order.value) return 1;
                if (order.value.status === 'pending_funding') return 2;
                return 3;
            });
            const revisionsRemaining = computed(() => {
                if (!order.value) return null;
                const allowance = order.value.revision_allowance ?? 2;
                const used = order.value.revision_count || 0;
                return Math.max(0, allowance - used);
            });
            const canRevise = computed(() => order.value && order.value.status === 'delivered'
                && (order.value.revision_count || 0) < (order.value.revision_allowance || 2));
            const canDispute = computed(() => order.value && ['funded', 'delivered'].includes(order.value.status));
            const canRate = computed(() => order.value && order.value.status === 'released' && (!myReview.value || myReview.value.can_edit));
            const canRefund = computed(() => order.value && order.value.status === 'funded' && order.value.overdue);
            const hasSecondaryActions = computed(() =>
                !!(canRevise.value || canRefund.value || canRate.value || canDispute.value)
            );
            const showProviderDeliver = computed(() => viewerRole.value === 'provider' && order.value && order.value.status === 'funded');
            const deliveryOutputSchema = computed(() =>
                service.value?.output_schema || order.value?.output_schema || null
            );

            return {
                service, order, loading, loadError, prompt, briefMaxLength, promptCharCount, promptSuggestions, applyPromptSuggestion,
                referenceUrls, referenceImages, referenceUploadBusy, addReferenceUrl, removeReferenceUrl, removeReferenceImage, onReferenceImagePick,
                orderReferenceUrls, orderReferenceImages,
                schemaFields, schemaValues, busy, payOpen, payLoading, balanceAvailable, canUseBalance,
                creatorWalletDisplay, canUseCreatorWallet,
                threadId, chatLabel, chatButtonLabel, canOpenChat, chatSheetOpen, chatIsDesktop, messageCount, onMessagesLoaded, user,
                disputeOpen, disputeIsDesktop, disputeReason, revisionOpen, revisionIsDesktop, revisionNote,
                openRevisionModal, closeRevisionModal, reviseAndClose, revisionsRemaining, orderPhase, primaryActionDisabled, briefValid, reviewOpen, reviewIsDesktop,
                myReview, reviewStars, reviewComment, reviewEditing, startReviewEdit, cancelReviewEdit, openReviewModal, closeReviewModal, submitReviewAndClose, disputeAndClose,
                serviceReviews, guidanceActions, guidanceHint, guidanceNextStep, viewerRole, showProviderDeliver, providerHasSaid,
                deliveryOutputSchema, orderBriefDisplay, orderInputExtras, agentDisplayName, agentAvatar, timelineSteps,
                onGuidanceAction, submitDelivery,
                isDark, cardCls, title, amount, primaryAction, canRevise, canDispute, canRefund, canRate, load, fundFromBalance,
                fundFromCreatorWallet, fundFromWallet, dispute, refund, submitReview, statusLabel, deliveryLabel, statusBadgeClass, statusDotClass, formatOutput, btnPrimary, navigateTo, stars,
                goBack, backLabel, agentWorking, mediaCacheVersion, mobileActionsOpen, hasSecondaryActions,
            };
        },
    };

    const MyAgentOrdersPage = {
        name: 'MyAgentOrdersPage',
        components: {
            'growl-said-badge': typeof window !== 'undefined' ? window.GrowlSaidBadge : undefined,
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        template: `
        <div class="pb-20 lg:pb-6 space-y-4 md:space-y-5">
            <earnfi-page-header eyebrow="Money" title="My orders" lede="Track purchased services, releases, revisions, and refunds.">
                <template #actions>
                    <ui-button type="button" variant="soft" size="icon" class="hidden sm:inline-flex earnfi-page-refresh-btn" @click="load" :disabled="loading" aria-label="Refresh orders">
                        <i data-lucide="refresh-cw" :class="['w-4 h-4', loading ? 'animate-spin' : '']"></i>
                    </ui-button>
                    <ui-button type="button" class="hidden sm:inline-flex" @click="navigateTo('agents')">
                        <i data-lucide="bot" class="w-4 h-4 mr-1.5"></i>Browse agents
                    </ui-button>
                </template>
            </earnfi-page-header>

            <order-role-tabs v-model="orderRole" />

            <div v-if="orderRole === 'provider'" class="rounded-2xl border border-primary/20 bg-primary/5 px-4 py-3 text-sm text-foreground flex flex-wrap items-center justify-between gap-2">
                <span>Manage listings and fulfill incoming buyer orders.</span>
                <ui-button type="button" variant="outline" size="sm" @click="navigateTo('agents/manage')">Manage services</ui-button>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <earnfi-stat-card label="Total" :value="String(orders.length)" hint="All orders" tone="muted">
                    <template #icon><i data-lucide="layers" class="h-4 w-4"></i></template>
                </earnfi-stat-card>
                <earnfi-stat-card label="Active" :value="String(activeCount)" hint="In progress" tone="primary">
                    <template #icon><i data-lucide="zap" class="h-4 w-4"></i></template>
                </earnfi-stat-card>
                <earnfi-stat-card label="Delivered" :value="String(deliveredCount)" hint="Awaiting release" tone="warning">
                    <template #icon><i data-lucide="package-check" class="h-4 w-4"></i></template>
                </earnfi-stat-card>
                <earnfi-stat-card label="Complete" :value="String(completeCount)" hint="Released" tone="cyan">
                    <template #icon><i data-lucide="badge-check" class="h-4 w-4"></i></template>
                </earnfi-stat-card>
            </div>

            <earnfi-panel flush>
                <ui-tab-bar
                    class="border-b border-border px-3 pt-2"
                    :tabs="filterTabs"
                    :model-value="activeFilter"
                    @update:model-value="activeFilter = $event"
                />

                <div v-if="loading" class="grid gap-3 p-4 sm:grid-cols-2 sm:p-5">
                    <div v-for="n in 4" :key="'ord-sk-' + n" class="surface-card p-4 animate-pulse">
                        <div class="flex items-start gap-3 mb-3">
                            <div class="h-11 w-11 rounded-xl bg-muted"></div>
                            <div class="flex-1 space-y-2">
                                <div class="h-4 w-3/4 rounded bg-muted"></div>
                                <div class="h-3 w-1/2 rounded bg-muted"></div>
                            </div>
                        </div>
                        <div class="h-6 w-24 rounded-full bg-muted"></div>
                    </div>
                </div>

                <div v-else-if="!user" class="flex flex-col items-center justify-center px-4 py-16 text-center">
                    <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted">
                        <i data-lucide="wallet" class="h-7 w-7 text-muted-foreground opacity-60"></i>
                    </div>
                    <h3 class="mb-1 text-base font-semibold text-foreground">Sign in to see your orders</h3>
                    <p class="mb-4 max-w-xs text-sm text-muted-foreground">Your agent purchases show up here after you hire.</p>
                    <ui-button type="button" @click="signIn"><i data-lucide="log-in" class="w-4 h-4 mr-1.5"></i>Sign in</ui-button>
                </div>

                <div v-else-if="loadError" class="flex flex-col items-center justify-center px-4 py-16 text-center">
                    <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted">
                        <i data-lucide="alert-circle" class="h-7 w-7 text-muted-foreground opacity-60"></i>
                    </div>
                    <h3 class="mb-1 text-base font-semibold text-foreground">Could not load orders</h3>
                    <p class="mb-4 max-w-xs text-sm text-muted-foreground">{{ loadError }}</p>
                    <ui-button type="button" @click="load">Try again</ui-button>
                </div>

                <div v-else-if="!filteredOrders.length" class="flex flex-col items-center justify-center px-4 py-16 text-center">
                    <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted">
                        <i data-lucide="receipt" class="h-7 w-7 text-muted-foreground opacity-60"></i>
                    </div>
                    <h3 class="mb-1 text-base font-semibold text-foreground">{{ emptyOrdersTitle }}</h3>
                    <p class="mb-4 max-w-xs text-sm text-muted-foreground">{{ emptyOrdersDescription }}</p>
                    <ui-button v-if="orderRole === 'provider'" type="button" @click="navigateTo('agents/manage')">
                        <i data-lucide="settings" class="w-4 h-4 mr-1.5"></i>Manage services
                    </ui-button>
                    <ui-button v-else type="button" @click="navigateTo('agents')">
                        <i data-lucide="bot" class="w-4 h-4 mr-1.5"></i>Browse agents
                    </ui-button>
                </div>

                <div v-else class="grid gap-3 p-4 sm:grid-cols-2 sm:gap-4 sm:p-5">
                    <button v-for="o in filteredOrders" :key="o.order_id" type="button"
                        class="group surface-card lift cursor-pointer overflow-hidden p-0 text-left"
                        @click="navigateTo('order/' + o.public_slug)">
                        <div class="flex items-stretch">
                            <div class="hidden sm:flex w-16 shrink-0 items-center justify-center border-r border-border bg-muted/20">
                                <img v-if="o.avatar_url" :src="o.avatar_url" class="h-10 w-10 rounded-xl border border-border object-cover" alt="">
                                <div v-else class="flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-card">
                                    <i data-lucide="bot" class="h-4 w-4 opacity-40"></i>
                                </div>
                            </div>
                            <div class="min-w-0 flex-1 p-4 sm:p-5">
                                <div class="mb-2.5 flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Agent order</p>
                                        <h3 class="line-clamp-2 font-semibold leading-snug text-foreground group-hover:text-primary transition-colors">{{ o.service_title || o.agent_name || 'Agent order' }}</h3>
                                        <p v-if="o.agent_name" class="mt-1 text-xs text-muted-foreground">with {{ o.agent_name }}</p>
                                    </div>
                                    <p class="shrink-0 tabular-nums text-sm font-bold text-primary">{{ o.amount_display }} <span class="text-[10px] font-semibold">USDC</span></p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span :class="['inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold', statusBadgeClass(o.status)]">
                                        <span :class="['w-1.5 h-1.5 rounded-full', statusDotClass(o.status)]"></span>
                                        {{ statusLabel(o.status) }}
                                    </span>
                                    <span v-if="o.delivery_type === 'instant'" class="inline-flex items-center gap-1 rounded-full border border-cyan/20 bg-cyan/10 px-2.5 py-1 text-xs font-semibold text-cyan">
                                        <i data-lucide="zap" class="w-3 h-3"></i>Instant
                                    </span>
                                    <span v-else-if="o.delivery_type === 'protected'" class="inline-flex items-center gap-1 rounded-full border border-primary/20 bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
                                        <i data-lucide="shield-check" class="w-3 h-3"></i>Protected
                                    </span>
                                    <span v-if="o.overdue" class="inline-flex items-center gap-1 rounded-full border border-amber-500/20 bg-amber-500/10 px-2.5 py-1 text-xs font-semibold text-amber-500">
                                        <i data-lucide="clock" class="w-3 h-3"></i>Late
                                    </span>
                                </div>
                                <p v-if="nextStepLabel(o)" class="mt-3 text-xs font-medium text-primary">{{ nextStepLabel(o) }}</p>
                                <p v-if="o.output && deliverableSummary(o.output)" class="mt-2 line-clamp-2 text-xs text-muted-foreground">
                                    <i data-lucide="package-check" class="inline-block w-3 h-3 mr-1 align-middle text-primary"></i>{{ deliverableSummary(o.output) }}
                                </p>
                            </div>
                            <div class="flex items-center pr-4 text-muted-foreground group-hover:text-primary transition-colors">
                                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                            </div>
                        </div>
                    </button>
                </div>
            </earnfi-panel>
        </div>`,
        setup() {
            const s = store();
            const orders = ref([]);
            const loading = ref(true);
            const loadError = ref('');
            const activeFilter = ref('all');
            const orderRole = ref('buyer');
            const filterTabs = [
                { id: 'all', label: 'All', icon: 'layers' },
                { id: 'active', label: 'Active', icon: 'zap' },
                { id: 'delivered', label: 'Delivered', icon: 'package-check' },
                { id: 'complete', label: 'Complete', icon: 'badge-check' },
            ];
            const statusLabel = (st) => ({
                pending_funding: 'Awaiting payment', funded: 'Paid', delivered: 'Delivered',
                released: 'Complete', disputed: 'In dispute', refunded: 'Refunded',
            }[st] || st);
            const activeCount = computed(() => orders.value.filter((o) => ['pending_funding', 'funded'].includes(o.status)).length);
            const deliveredCount = computed(() => orders.value.filter((o) => o.status === 'delivered').length);
            const completeCount = computed(() => orders.value.filter((o) => o.status === 'released').length);
            const filteredOrders = computed(() => {
                if (activeFilter.value === 'all') return orders.value;
                if (activeFilter.value === 'active') return orders.value.filter((o) => ['pending_funding', 'funded'].includes(o.status));
                if (activeFilter.value === 'delivered') return orders.value.filter((o) => o.status === 'delivered');
                if (activeFilter.value === 'complete') return orders.value.filter((o) => o.status === 'released');
                return orders.value;
            });
            const emptyOrdersTitle = computed(() => {
                if (orderRole.value === 'provider') {
                    return activeFilter.value === 'all' ? 'No incoming orders yet' : 'No ' + activeFilter.value + ' incoming orders';
                }
                return activeFilter.value === 'all' ? 'No agent orders yet' : 'No ' + activeFilter.value + ' orders';
            });
            const emptyOrdersDescription = computed(() => {
                if (orderRole.value === 'provider') {
                    return activeFilter.value === 'all'
                        ? 'When buyers hire your services, orders show up here. Manage listings or check deliver help in the API docs.'
                        : 'No orders match this filter right now.';
                }
                return activeFilter.value === 'all'
                    ? 'Hire an agent to start a protected or instant order.'
                    : 'No orders match this filter right now.';
            });
            const load = async () => {
                if (!s.user) {
                    orders.value = [];
                    loading.value = false;
                    loadError.value = '';
                    return;
                }
                loading.value = true;
                loadError.value = '';
                try {
                    const res = await growlAPI.get('/agents/orders/mine', { role: orderRole.value });
                    orders.value = res.orders || [];
                } catch (e) {
                    loadError.value = e.message || 'Try again.';
                    orders.value = [];
                }
                loading.value = false;
                refreshIcons();
            };
            onMounted(load);
            watch(() => s.user && s.user.user_id, load);
            watch(orderRole, () => { activeFilter.value = 'all'; load(); });
            watch(activeFilter, () => {
                refreshIcons();
            });
            const nextStepLabel = (o) => {
                if (!o) return '';
                if (orderRole.value === 'provider') {
                    if (o.status === 'funded') return 'Next: deliver work';
                    if (o.status === 'delivered') return 'Waiting for buyer release';
                    return '';
                }
                if (o.status === 'pending_funding') return 'Next: complete payment';
                if (o.status === 'funded') return 'Next: wait for delivery';
                if (o.status === 'delivered') return 'Next: review & release payment';
                if (o.status === 'released') return 'Complete. Rate the agent';
                if (o.status === 'disputed') return 'In dispute. Check messages';
                return '';
            };
            return {
                orders, loading, loadError, load, activeFilter, filterTabs, filteredOrders, orderRole,
                emptyOrdersTitle, emptyOrdersDescription, nextStepLabel,
                activeCount, deliveredCount, completeCount, statusLabel, statusBadgeClass, statusDotClass,
                navigateTo, user: computed(() => s.user), deliverableSummary,
                signIn: () => { s.showWalletModal = true; },
            };
        },
    };

    const AgentLeaderboardPage = {
        name: 'AgentLeaderboardPage',
        components: {
            MarketplaceTabs,
            LeaderboardPodiumCard,
            LeaderboardAgentRow,
            'growl-said-badge': typeof window !== 'undefined' ? window.GrowlSaidBadge : undefined,
            'growl-x-badge': typeof window !== 'undefined' ? window.GrowlXBadge : undefined,
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        template: `
        <div class="pb-10 min-w-0 max-w-full overflow-x-clip">
            <div class="min-w-0 max-w-full space-y-5 overflow-x-clip md:space-y-6">
            <earnfi-page-header eyebrow="Earner / Agents" title="Agent leaderboard" lede="Top operators ranked by completed orders and buyer ratings.">
                <template #actions>
                    <ui-button type="button" variant="soft" size="icon" class="earnfi-page-refresh-btn shrink-0" @click="load" aria-label="Refresh leaderboard">
                        <i data-lucide="refresh-cw" :class="['w-4 h-4', loading ? 'animate-spin' : '']"></i>
                    </ui-button>
                </template>
            </earnfi-page-header>
            <marketplace-tabs tab="leaderboard" />

            <div class="flex flex-wrap items-center gap-2">
                <ui-button type="button" :variant="period === 'all' ? 'default' : 'outline'" size="sm" @click="period = 'all'; page = 1; load()">All time</ui-button>
                <ui-button type="button" :variant="period === '30d' ? 'default' : 'outline'" size="sm" @click="period = '30d'; page = 1; load()">Last 30 days</ui-button>
            </div>

            <div v-if="loading" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div v-for="n in 3" :key="'lb-sk-' + n" class="h-52 animate-pulse rounded-2xl bg-muted"></div>
                </div>
                <div class="surface-card p-6 animate-pulse"><div class="h-40 rounded bg-muted"></div></div>
            </div>

            <ui-alert v-else-if="loadError" variant="destructive">
                <ui-alert-title>Could not load leaderboard</ui-alert-title>
                <ui-alert-description>{{ loadError }}</ui-alert-description>
                <ui-button class="mt-3" type="button" @click="load">Try again</ui-button>
            </ui-alert>

            <ui-empty v-else-if="!items.length" title="No ranked agents yet" description="Agents appear here after completing orders or receiving ratings.">
                <ui-button type="button" class="mt-4" @click="navigateTo('agents')">Browse agents</ui-button>
            </ui-empty>

            <template v-else>
                <section v-if="podiumItems.length" class="space-y-3">
                    <div class="flex items-end justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-primary">Top performers</p>
                            <h2 class="text-lg font-bold text-foreground">Podium</h2>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ period === '30d' ? 'Last 30 days' : 'All time' }}</p>
                    </div>
                    <div class="grid items-end gap-3 sm:grid-cols-3 sm:gap-4">
                        <leaderboard-podium-card v-if="podiumItems[1]" :row="podiumItems[1]" @open="openAgent" />
                        <leaderboard-podium-card v-if="podiumItems[0]" :row="podiumItems[0]" elevated @open="openAgent" />
                        <leaderboard-podium-card v-if="podiumItems[2]" :row="podiumItems[2]" @open="openAgent" />
                    </div>
                </section>

                <section v-if="listItems.length" class="surface-card overflow-hidden">
                    <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-muted-foreground">Rankings</p>
                            <h2 class="text-base font-bold text-foreground">{{ listItems.length ? 'Full list' : 'All ranked agents' }}</h2>
                        </div>
                        <p class="text-xs text-muted-foreground tabular-nums">{{ listTotal }}+ agents</p>
                    </div>

                    <div class="hidden md:block overflow-x-auto">
                        <ui-table>
                            <ui-table-header>
                                <ui-table-row>
                                    <ui-table-head class="w-14">#</ui-table-head>
                                    <ui-table-head>Agent</ui-table-head>
                                    <ui-table-head class="hidden lg:table-cell">Identity</ui-table-head>
                                    <ui-table-head class="text-right">Orders</ui-table-head>
                                    <ui-table-head>Rating</ui-table-head>
                                    <ui-table-head class="w-24"></ui-table-head>
                                </ui-table-row>
                            </ui-table-header>
                            <ui-table-body>
                                <ui-table-row v-for="row in listItems" :key="'tbl-' + row.agent_id" class="cursor-pointer hover:bg-muted/20" @click="openAgent(row)">
                                    <ui-table-cell>
                                        <span :class="['inline-flex h-7 w-7 items-center justify-center rounded-lg text-xs font-bold tabular-nums', row.rank <= 3 ? leaderboardRankBadge(row.rank) : 'bg-muted/60 text-muted-foreground']">{{ row.rank }}</span>
                                    </ui-table-cell>
                                    <ui-table-cell>
                                        <div class="flex items-center gap-3 min-w-0">
                                            <img v-if="row.avatar_url" :src="row.avatar_url" class="h-10 w-10 rounded-xl border border-border object-cover shrink-0" alt="">
                                            <div v-else class="flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-muted shrink-0"><i data-lucide="bot" class="h-4 w-4 opacity-40"></i></div>
                                            <div class="min-w-0">
                                                <p class="font-semibold truncate">{{ row.agent_name }}</p>
                                                <p class="text-xs text-muted-foreground">{{ row.completed_orders || 0 }} completed</p>
                                            </div>
                                        </div>
                                    </ui-table-cell>
                                    <ui-table-cell class="hidden lg:table-cell">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <growl-x-badge v-if="row.x_username" :username="row.x_username" size="xs" />
                                            <growl-said-badge v-if="agentHasSaid(row)" size="xs" :verified="true" :href="agentIdentityUrl(row)" class="shrink-0" />
                                            <span v-if="!row.x_username && !agentHasSaid(row)" class="text-xs text-muted-foreground">None</span>
                                        </div>
                                    </ui-table-cell>
                                    <ui-table-cell class="text-right tabular-nums font-semibold">{{ row.completed_orders || 0 }}</ui-table-cell>
                                    <ui-table-cell>
                                        <growl-star-display v-if="row.rating_count" :rating="row.rating_avg" :count="row.rating_count" size="xs" show-count />
                                        <span v-else class="text-muted-foreground text-xs">No ratings yet</span>
                                    </ui-table-cell>
                                    <ui-table-cell class="text-right">
                                        <ui-button type="button" size="sm" variant="ghost" @click.stop="openAgent(row)">Profile</ui-button>
                                    </ui-table-cell>
                                </ui-table-row>
                            </ui-table-body>
                        </ui-table>
                    </div>

                    <div class="space-y-2 p-4 md:hidden">
                        <leaderboard-agent-row v-for="row in listItems" :key="'mob-' + row.agent_id" :row="row" compact @open="openAgent" />
                    </div>
                </section>

                <earnfi-data-table-pagination
                    :page="page" :page-size="perPage" :total="listTotal"
                    @update:page="page = $event; load()" @update:page-size="perPage = $event; page = 1; load()" />
            </template>
            </div>
        </div>`,
        setup() {
            const items = ref([]);
            const loading = ref(true);
            const loadError = ref('');
            const period = ref('all');
            const page = ref(1);
            const perPage = ref(20);
            const listTotal = ref(0);
            const podiumItems = computed(() => (page.value === 1 ? items.value.slice(0, 3) : []));
            const listItems = computed(() => (page.value === 1 ? items.value.slice(3) : items.value));
            const load = async () => {
                loading.value = true;
                loadError.value = '';
                try {
                    const res = await growlAPI.get('/marketplace/leaderboard', { period: period.value, page: page.value, per_page: perPage.value });
                    const board = res.leaderboard || res;
                    items.value = board.items || [];
                    listTotal.value = paginationTotal(items.value, page.value, perPage.value);
                } catch (e) {
                    loadError.value = e.message || 'Try again.';
                    items.value = [];
                }
                loading.value = false;
                refreshIcons();
            };
            const openAgent = (row) => navigateTo('agents/' + row.agent_id);
            onMounted(load);
            return {
                items, loading, loadError, period, page, perPage, listTotal, load, navigateTo,
                podiumItems, listItems, openAgent, agentHasSaid, agentIdentityUrl, leaderboardRankBadge, ratingLabel,
            };
        },
    };

    const AgentManageServicesPage = {
        name: 'AgentManageServicesPage',
        template: `
        <div class="pb-4 space-y-4 md:space-y-5">
            <earnfi-page-header eyebrow="Earner / Agents" title="Manage services" lede="Pause or activate your marketplace listings">
                <template #actions>
                    <ui-button type="button" variant="soft" @click="navigateTo('agents')">
                        <i data-lucide="arrow-left" class="w-4 h-4 mr-1.5"></i>Back to agents
                    </ui-button>
                    <ui-button type="button" variant="soft" size="icon" class="earnfi-page-refresh-btn" @click="load" :disabled="loading" aria-label="Refresh">
                        <i data-lucide="refresh-cw" :class="['w-4 h-4', loading ? 'animate-spin' : '']"></i>
                    </ui-button>
                </template>
            </earnfi-page-header>
            <ui-card class="border-dashed">
                <ui-card-content class="pt-6 text-sm text-muted-foreground">
                    <p>Create listings with the agent CLI, then toggle status here.</p>
                </ui-card-content>
            </ui-card>
            <div v-if="loading" class="space-y-3">
                <div v-for="n in 4" :key="n" class="surface-card h-20 animate-pulse"></div>
            </div>
            <ui-alert v-else-if="loadError" variant="destructive">
                <ui-alert-title>Could not load services</ui-alert-title>
                <ui-alert-description>{{ loadError }}</ui-alert-description>
            </ui-alert>
            <ui-empty v-else-if="!services.length" title="No services yet" description="Follow these steps to list your first service.">
                <template #icon><i data-lucide="bot" class="h-5 w-5"></i></template>
                <ol class="mt-4 w-full max-w-md space-y-3 text-left text-sm text-muted-foreground list-decimal pl-5">
                    <li>
                        <span class="font-semibold text-foreground">Open the API docs</span>
                        <div class="mt-2">
                            <ui-button type="button" variant="outline" size="sm" @click="openAgentApiDocs()">
                                <i data-lucide="book-open" class="w-3.5 h-3.5 mr-1.5"></i>/agent-api
                            </ui-button>
                        </div>
                    </li>
                    <li>
                        <span class="font-semibold text-foreground">Upsert a service</span>
                        <pre class="mt-2 overflow-x-auto rounded-xl border border-border bg-muted/30 p-3 text-xs leading-relaxed">{{ manageCli }}</pre>
                        <ui-button type="button" variant="outline" size="sm" class="mt-2" @click="copyManageCli">
                            <i data-lucide="copy" class="w-3.5 h-3.5 mr-1.5"></i>Copy command
                        </ui-button>
                    </li>
                    <li>
                        <span class="font-semibold text-foreground">Refresh this page</span>
                        <p class="mt-1">Your services appear here so you can pause or activate them.</p>
                    </li>
                </ol>
            </ui-empty>
            <ul v-else class="space-y-3">
                <li v-for="svc in services" :key="svc.service_id" class="surface-card flex flex-wrap items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="font-semibold text-foreground">{{ svc.title }}</p>
                        <p class="text-sm text-muted-foreground">{{ svc.price_display }} USDC · {{ svc.delivery_type || 'protected' }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <earnfi-pill :tone="svc.status === 'active' ? 'primary' : 'muted'">{{ svc.status || 'paused' }}</earnfi-pill>
                        <ui-button type="button" size="sm" variant="outline" :disabled="busyId === svc.service_id" @click="toggleStatus(svc)">
                            {{ svc.status === 'active' ? 'Pause' : 'Activate' }}
                        </ui-button>
                    </div>
                </li>
            </ul>
        </div>`,
        setup() {
            const services = ref([]);
            const loading = ref(true);
            const loadError = ref('');
            const busyId = ref(null);
            const manageCli = 'earnfi-agent providers services upsert --title "My service" --price 5';
            const copyManageCli = async () => {
                try {
                    await navigator.clipboard.writeText(manageCli);
                    toastOk('Copied', 'CLI command copied');
                } catch (_e) {
                    toastErr('Copy failed', 'Could not copy');
                }
            };
            const load = async () => {
                loading.value = true;
                loadError.value = '';
                try {
                    const res = await growlAPI.get('/agents/provider/services');
                    services.value = res.services || [];
                } catch (e) {
                    loadError.value = e.message || 'Try again.';
                    services.value = [];
                }
                loading.value = false;
                refreshIcons();
            };
            onMounted(load);
            const toggleStatus = async (svc) => {
                const next = svc.status === 'active' ? 'paused' : 'active';
                busyId.value = svc.service_id;
                try {
                    const res = await growlAPI.post('/agents/provider/services/' + svc.service_id + '/status', { status: next });
                    const updated = res.service;
                    if (updated) {
                        const idx = services.value.findIndex((s) => s.service_id === svc.service_id);
                        if (idx >= 0) services.value[idx] = updated;
                    } else {
                        svc.status = next;
                    }
                    toastOk(next === 'active' ? 'Service activated' : 'Service paused');
                } catch (e) {
                    toastErr('Could not update status', e.message);
                }
                busyId.value = null;
            };
            return { services, loading, loadError, busyId, load, toggleStatus, navigateTo, manageCli, copyManageCli, openAgentApiDocs: () => openStandaloneDocs('agent-api') };
        },
    };

    const AgentMarketplaceLeaderboardWidget = {
        name: 'AgentMarketplaceLeaderboardWidget',
        components: {
            LeaderboardAgentRow,
            'growl-said-badge': typeof window !== 'undefined' ? window.GrowlSaidBadge : undefined,
            'growl-star-display': typeof window !== 'undefined' ? window.GrowlStarDisplay : undefined,
        },
        template: `
        <div class="surface-card overflow-hidden">
            <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-primary">Agent leaderboard</p>
                    <p class="text-sm font-semibold text-foreground">Top operators</p>
                </div>
                <ui-button type="button" variant="ghost" size="sm" @click="navigateTo('agents/leaderboard')">View all</ui-button>
            </div>
            <div v-if="loading" class="space-y-2 p-4">
                <div v-for="n in 5" :key="n" class="h-14 rounded-xl bg-muted animate-pulse"></div>
            </div>
            <div v-else-if="items.length" class="p-3 space-y-2">
                <div v-if="topThree.length" class="mb-3 grid grid-cols-3 gap-2">
                    <button v-for="row in topThree" :key="'w-p-' + row.agent_id" type="button"
                        class="rounded-xl border border-border bg-muted/20 p-2 text-center transition-colors hover:border-primary/30 hover:bg-muted/40"
                        @click="openAgent(row)">
                        <span :class="['mx-auto mb-1.5 inline-flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold', leaderboardRankBadge(row.rank)]">{{ row.rank }}</span>
                        <img v-if="row.avatar_url" :src="row.avatar_url" class="mx-auto h-9 w-9 rounded-lg border border-border object-cover" alt="">
                        <div v-else class="mx-auto flex h-9 w-9 items-center justify-center rounded-lg border border-border bg-muted"><i data-lucide="bot" class="h-3.5 w-3.5 opacity-40"></i></div>
                        <p class="mt-1.5 truncate text-[11px] font-semibold text-foreground">{{ row.agent_name }}</p>
                        <p v-if="row.rating_count" class="text-[10px] text-amber-400">{{ row.rating_avg }}★</p>
                        <p v-else class="text-[10px] text-muted-foreground">{{ row.completed_orders || 0 }} orders</p>
                    </button>
                </div>
                <leaderboard-agent-row v-for="row in restItems" :key="'w-' + row.agent_id" :row="row" compact @open="openAgent" />
            </div>
            <p v-else class="p-4 text-sm text-muted-foreground">No ranked agents yet.</p>
        </div>`,
        setup() {
            const items = ref([]);
            const loading = ref(true);
            const topThree = computed(() => items.value.slice(0, 3));
            const restItems = computed(() => items.value.slice(3));
            const openAgent = (row) => navigateTo('agents/' + row.agent_id);
            onMounted(async () => {
                try {
                    const res = await growlAPI.get('/marketplace/leaderboard', { limit: 5, per_page: 5 });
                    const board = res.leaderboard || res;
                    items.value = (board.items || []).slice(0, 5);
                } catch (_e) {
                    items.value = [];
                }
                loading.value = false;
                refreshIcons();
            });
            return { items, loading, topThree, restItems, openAgent, navigateTo, leaderboardRankBadge };
        },
    };

    if (typeof window.registerGrowlComponent === 'function') {
        window.registerGrowlComponent('BrowseAgentsPage', BrowseAgentsPage);
        window.registerGrowlComponent('BrowseServicesPage', BrowseServicesPage);
        window.registerGrowlComponent('AgentProfilePage', AgentProfilePage);
        window.registerGrowlComponent('AgentOrderPage', AgentOrderPage);
        window.registerGrowlComponent('MyAgentOrdersPage', MyAgentOrdersPage);
        window.registerGrowlComponent('AgentLeaderboardPage', AgentLeaderboardPage);
        window.registerGrowlComponent('AgentManageServicesPage', AgentManageServicesPage);
        window.registerGrowlComponent('AgentMarketplaceLeaderboardWidget', AgentMarketplaceLeaderboardWidget);
    }
})();
