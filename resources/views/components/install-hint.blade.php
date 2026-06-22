{{--
  Install-to-home-screen hint. iOS Safari never surfaces an install prompt, so users
  don't know Titan can be a real app; Android/Chrome fire `beforeinstallprompt` which we
  capture for a custom button instead of the browser's tiny default infobar. Dismissible,
  remembered for 30 days, and never shown once the app is already installed (standalone).
--}}
<div x-data="installHint()" x-init="init()" x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-y-full opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-y-0 opacity-100" x-transition:leave-end="translate-y-full opacity-0"
     class="fixed inset-x-0 z-[60] px-3 bottom-[max(5rem,calc(4.25rem+env(safe-area-inset-bottom)))] md:bottom-4"
     style="pointer-events:none">
    <div class="mx-auto max-w-md rounded-2xl border border-white/10 bg-[#0c0e12]/95 backdrop-blur-xl p-4 shadow-2xl shadow-black/60"
         style="pointer-events:auto">
        <div class="flex items-start gap-3">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-cyan-400">
                <svg class="h-6 w-6 text-gray-900" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-display text-sm font-bold text-gray-100">Install Titan</p>
                {{-- iOS: manual Share → Add to Home Screen --}}
                <p x-show="ios" class="mt-0.5 text-[13px] leading-snug text-gray-400">
                    Tap <span class="inline-flex items-center px-0.5 align-middle text-indigo-300"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M5 14v4a2 2 0 002 2h10a2 2 0 002-2v-4"/></svg></span>
                    then <span class="font-medium text-gray-200">“Add to Home Screen”</span> for the full-screen app.
                </p>
                {{-- Android/Chrome: one-tap install --}}
                <p x-show="!ios" class="mt-0.5 text-[13px] leading-snug text-gray-400">
                    Add Titan to your home screen for a faster, full-screen, offline-ready app.
                </p>
                <div class="mt-2.5 flex items-center gap-2">
                    <button x-show="!ios" type="button" @click="install()"
                            class="h-9 rounded-xl bg-gradient-to-r from-indigo-500 to-cyan-400 px-4 text-sm font-semibold text-white active:opacity-90">Install</button>
                    <button type="button" @click="dismiss()"
                            class="h-9 rounded-xl px-3 text-sm font-medium text-gray-400 active:text-gray-200">Not now</button>
                </div>
            </div>
            <button type="button" @click="dismiss()" aria-label="Dismiss" class="-mr-1 -mt-1 p-1.5 text-gray-600 active:text-gray-300">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
</div>

<script>
    window.installHint = function () {
        return {
            show: false,
            ios: false,
            _deferred: null,
            KEY: 'titan_install_hint_dismissed_at',
            init() {
                // Already installed (standalone)? never nag.
                const standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
                if (standalone) return;

                // Dismissed in the last 30 days? stay quiet.
                const at = parseInt(localStorage.getItem(this.KEY) || '0', 10);
                if (at && (Date.now() - at) < 30 * 24 * 60 * 60 * 1000) return;

                const ua = window.navigator.userAgent || '';
                const isIOS = /iphone|ipad|ipod/i.test(ua) && !window.MSStream;
                const isSafari = /safari/i.test(ua) && !/crios|fxios|edgios/i.test(ua);

                if (isIOS && isSafari) {
                    this.ios = true;
                    // Give the page a beat to settle before sliding in.
                    setTimeout(() => { this.show = true; }, 2500);
                    return;
                }

                // Android/Chrome path: wait for the install opportunity.
                window.addEventListener('beforeinstallprompt', (e) => {
                    e.preventDefault();
                    this._deferred = e;
                    this.ios = false;
                    this.show = true;
                });
                // Hide the hint once installed.
                window.addEventListener('appinstalled', () => { this.show = false; this.dismiss(); });
            },
            async install() {
                if (!this._deferred) { this.dismiss(); return; }
                this._deferred.prompt();
                try { await this._deferred.userChoice; } catch (e) {}
                this._deferred = null;
                this.show = false;
            },
            dismiss() {
                this.show = false;
                try { localStorage.setItem(this.KEY, String(Date.now())); } catch (e) {}
            },
        };
    };
</script>
