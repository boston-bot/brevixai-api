<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Alert Recommendations — Brevix</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            html { font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif; font-size: 14px; line-height: 1.5; background: #f9fafb; color: #111827; }
            body { min-height: 100vh; }
            .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border-width: 0; }
        </style>
    @endif

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>

    <style>
        /* Minimal fallback styles if Vite/Tailwind isn't running */
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">

<div
    x-data="alertRecommendations()"
    x-init="init()"
    class="max-w-4xl mx-auto px-4 py-8"
>
    {{-- Header --}}
    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-gray-900">Alert Recommendations</h1>
        <p class="mt-1 text-sm text-gray-500">
            Review risk signals that require a decision before an alert is created.
        </p>
    </div>

    {{-- Token gate: shown when no token is stored --}}
    <div x-show="!token" x-cloak class="mb-6 bg-white border border-gray-200 rounded-lg p-6 shadow-sm">
        <p class="text-sm font-medium text-gray-700 mb-3">Enter your API token to continue</p>
        <div class="flex gap-3">
            <input
                type="password"
                x-model="tokenInput"
                placeholder="Bearer token"
                class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                @keydown.enter="saveToken()"
            />
            <button
                @click="saveToken()"
                class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition-colors"
            >
                Connect
            </button>
        </div>
    </div>

    {{-- Success banner --}}
    <div
        x-show="successMessage"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="mb-4 flex items-center gap-3 bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm"
    >
        <svg class="w-4 h-4 flex-shrink-0 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        <span x-text="successMessage"></span>
        <button @click="successMessage = null" class="ml-auto text-green-600 hover:text-green-800">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    {{-- Global error banner --}}
    <div
        x-show="error"
        x-cloak
        class="mb-4 flex items-start gap-3 bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm"
    >
        <svg class="w-4 h-4 flex-shrink-0 mt-0.5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <span x-text="error"></span>
        <button @click="error = null" class="ml-auto text-red-600 hover:text-red-800">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>
    </div>

    {{-- Loading state --}}
    <div x-show="loading" x-cloak class="flex items-center justify-center py-20">
        <svg class="animate-spin w-6 h-6 text-indigo-500 mr-3" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span class="text-sm text-gray-500">Loading recommendations…</span>
    </div>

    {{-- Empty state --}}
    <div x-show="!loading && !error && recommendations.length === 0" x-cloak class="flex flex-col items-center py-20 text-center">
        <svg class="w-10 h-10 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm font-medium text-gray-500">No pending recommendations</p>
        <p class="text-xs text-gray-400 mt-1">All recommendations have been reviewed.</p>
    </div>

    {{-- Recommendation cards --}}
    <div x-show="!loading && recommendations.length > 0" x-cloak class="space-y-4">

        <div class="flex items-center justify-between mb-2">
            <p class="text-sm text-gray-500">
                <span x-text="recommendations.length"></span> pending
                <span x-show="recommendations.length === 1">recommendation</span>
                <span x-show="recommendations.length !== 1">recommendations</span>
            </p>
            <button
                @click="loadRecommendations()"
                class="text-xs text-indigo-600 hover:text-indigo-800 font-medium flex items-center gap-1"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Refresh
            </button>
        </div>

        <template x-for="rec in recommendations" :key="rec.id">
            <article class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">

                {{-- Card header --}}
                <div class="px-5 py-4 border-b border-gray-100">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">

                            {{-- Badges row --}}
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                {{-- Severity badge --}}
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                    :class="severityClasses(rec.severity)"
                                    x-text="rec.severity?.toUpperCase()"
                                ></span>

                                {{-- Source risk domain --}}
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700"
                                    x-text="formatDomain(rec.source_risk_domain)">
                                </span>

                                {{-- Human review badge — always shown --}}
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    Requires human review
                                </span>
                            </div>

                            <h2 class="text-base font-semibold text-gray-900" x-text="rec.title"></h2>
                            <p class="mt-1 text-sm text-gray-600" x-text="rec.summary"></p>
                        </div>

                        {{-- Confidence score --}}
                        <div class="flex-shrink-0 text-right">
                            <p class="text-xs text-gray-400 mb-0.5">Confidence</p>
                            <p class="text-lg font-semibold" :class="confidenceColor(rec.confidence_score)" x-text="confidencePercent(rec.confidence_score)"></p>
                        </div>
                    </div>
                </div>

                {{-- Evidence section (expandable) --}}
                <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                    <button
                        @click="rec.evidenceOpen = !rec.evidenceOpen"
                        class="flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-gray-900 w-full text-left"
                        :aria-expanded="rec.evidenceOpen"
                        aria-controls="evidence-section"
                    >
                        <svg
                            class="w-4 h-4 text-gray-400 transition-transform duration-150"
                            :class="rec.evidenceOpen ? 'rotate-90' : ''"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        Evidence
                        <span class="text-xs text-gray-400 font-normal">
                            (<span x-text="Object.keys(rec.evidence || {}).length"></span> signal<span x-show="Object.keys(rec.evidence || {}).length !== 1">s</span>)
                        </span>
                    </button>

                    <div
                        x-show="rec.evidenceOpen"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-cloak
                        class="mt-3"
                        id="evidence-section"
                    >
                        <dl class="space-y-1.5">
                            <template x-for="[key, value] in Object.entries(rec.evidence || {})" :key="key">
                                <div class="flex items-start gap-3 text-sm">
                                    <dt class="w-48 flex-shrink-0 text-gray-500 font-medium" x-text="formatKey(key)"></dt>
                                    <dd class="text-gray-900 break-words" x-text="formatValue(value)"></dd>
                                </div>
                            </template>
                        </dl>
                    </div>
                </div>

                {{-- Per-card error --}}
                <div
                    x-show="rec.actionError"
                    x-cloak
                    class="px-5 py-2 bg-red-50 border-b border-red-100 text-sm text-red-700"
                    x-text="rec.actionError"
                ></div>

                {{-- Actions --}}
                <div class="px-5 py-4">

                    {{-- Dismiss form (inline, shown when dismiss is clicked) --}}
                    <div x-show="rec.showDismissForm" x-cloak class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">
                            Review note
                            <span class="font-normal text-gray-400">(optional)</span>
                        </label>
                        <textarea
                            x-model="rec.reviewNote"
                            placeholder="Why are you dismissing this recommendation?"
                            rows="3"
                            maxlength="2000"
                            class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none"
                        ></textarea>
                        <div class="flex gap-2 mt-2">
                            <button
                                @click="dismiss(rec)"
                                :disabled="rec.loading"
                                class="flex items-center gap-1.5 px-4 py-2 bg-gray-700 text-white text-sm font-medium rounded-md hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                <svg x-show="rec.loading" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span x-show="!rec.loading">Dismiss Recommendation</span>
                                <span x-show="rec.loading">Dismissing…</span>
                            </button>
                            <button
                                @click="rec.showDismissForm = false; rec.reviewNote = ''; rec.actionError = null"
                                :disabled="rec.loading"
                                class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 font-medium rounded-md border border-gray-200 hover:border-gray-300 transition-colors disabled:opacity-50"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>

                    {{-- Primary action buttons (shown when dismiss form is not open) --}}
                    <div x-show="!rec.showDismissForm" class="flex flex-wrap items-center gap-3">
                        <button
                            @click="approve(rec)"
                            :disabled="rec.loading"
                            class="flex items-center gap-1.5 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            <svg x-show="rec.loading" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                            </svg>
                            <svg x-show="!rec.loading" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span x-show="!rec.loading">Approve Alert</span>
                            <span x-show="rec.loading">Approving…</span>
                        </button>

                        <button
                            @click="rec.showDismissForm = true; rec.actionError = null"
                            :disabled="rec.loading"
                            class="px-4 py-2 text-sm text-gray-600 font-medium rounded-md border border-gray-200 hover:border-gray-300 hover:text-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                            Dismiss Recommendation
                        </button>
                    </div>
                </div>

            </article>
        </template>
    </div>

</div>

<script>
function alertRecommendations() {
    return {
        recommendations: [],
        loading: false,
        error: null,
        successMessage: null,
        token: null,
        tokenInput: '',

        init() {
            this.token = localStorage.getItem('brevix_api_token') || null;
            if (this.token) {
                this.loadRecommendations();
            }
        },

        saveToken() {
            const t = this.tokenInput.trim();
            if (!t) return;
            this.token = t;
            localStorage.setItem('brevix_api_token', t);
            this.tokenInput = '';
            this.loadRecommendations();
        },

        async loadRecommendations() {
            if (!this.token) return;
            this.loading = true;
            this.error = null;

            try {
                const response = await fetch('/api/alert-recommendations', {
                    headers: this.authHeaders(),
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    if (response.status === 401 || response.status === 403) {
                        this.error = 'Authentication failed. Please check your token.';
                    } else {
                        this.error = data.error || 'Failed to load recommendations.';
                    }
                    return;
                }

                const data = await response.json();
                this.recommendations = (data.recommendations || []).map(r => ({
                    ...r,
                    evidenceOpen: false,
                    showDismissForm: false,
                    reviewNote: '',
                    actionError: null,
                    loading: false,
                }));
            } catch {
                this.error = 'Unable to reach the server. Please try again.';
            } finally {
                this.loading = false;
            }
        },

        async approve(rec) {
            rec.loading = true;
            rec.actionError = null;

            try {
                const response = await fetch(`/api/alert-recommendations/${rec.id}/approve`, {
                    method: 'POST',
                    headers: this.authHeaders(),
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    if (response.status === 409) {
                        rec.actionError = 'This recommendation has already been reviewed.';
                    } else {
                        rec.actionError = data.error || 'Failed to approve. Please try again.';
                    }
                    rec.loading = false;
                    return;
                }

                this.successMessage = 'Alert approved and created.';
                await this.loadRecommendations();
                this.clearSuccessAfterDelay();
            } catch {
                rec.actionError = 'Request failed. Please check your connection and try again.';
                rec.loading = false;
            }
        },

        async dismiss(rec) {
            rec.loading = true;
            rec.actionError = null;

            try {
                const response = await fetch(`/api/alert-recommendations/${rec.id}/dismiss`, {
                    method: 'POST',
                    headers: this.authHeaders(),
                    body: JSON.stringify({
                        review_note: rec.reviewNote || null,
                    }),
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    if (response.status === 409) {
                        rec.actionError = 'This recommendation has already been reviewed.';
                    } else {
                        rec.actionError = data.error || 'Failed to dismiss. Please try again.';
                    }
                    rec.loading = false;
                    return;
                }

                this.successMessage = 'Recommendation dismissed.';
                await this.loadRecommendations();
                this.clearSuccessAfterDelay();
            } catch {
                rec.actionError = 'Request failed. Please check your connection and try again.';
                rec.loading = false;
            }
        },

        authHeaders() {
            return {
                'Authorization': `Bearer ${this.token}`,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            };
        },

        clearSuccessAfterDelay() {
            setTimeout(() => { this.successMessage = null; }, 6000);
        },

        severityClasses(severity) {
            const map = {
                critical: 'bg-red-100 text-red-800',
                high: 'bg-orange-100 text-orange-800',
                medium: 'bg-yellow-100 text-yellow-800',
                low: 'bg-blue-100 text-blue-800',
            };
            return map[severity] || 'bg-gray-100 text-gray-700';
        },

        confidenceColor(score) {
            if (score >= 0.8) return 'text-green-600';
            if (score >= 0.6) return 'text-yellow-600';
            return 'text-red-600';
        },

        confidencePercent(score) {
            return Math.round((score || 0) * 100) + '%';
        },

        formatDomain(domain) {
            if (!domain) return '';
            return domain.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        },

        formatKey(key) {
            return key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        },

        formatValue(value) {
            if (Array.isArray(value)) return value.join(', ');
            if (value === null || value === undefined) return '—';
            if (typeof value === 'object') return JSON.stringify(value);
            return String(value);
        },
    };
}
</script>
</body>
</html>
