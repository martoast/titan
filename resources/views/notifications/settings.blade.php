<x-titan-layout title="Notifications" subtitle="How and when your coach reaches out">
    @php
        $typeMeta = [
            'briefing' => ['🌅', 'A grounded read on your day each morning'],
            'meals'    => ['🍽️', "Nudges to eat when a meal's due — before you're hungry"],
            'sleep'    => ['🌙', 'A wind-down reminder before your target bedtime'],
            'cycle'    => ['🌸', 'A heads-up when your period is a day or two out'],
            'move'     => ['🚶', "A mid-day move + stretch when you've been sitting"],
            'training' => ['💪', 'A recovery-aware nudge to push hard or back off'],
        ];
    @endphp

    <div x-data="notifSettings()" class="space-y-5 max-w-xl">

        @if (session('status'))
            <div class="rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-2.5 text-sm text-emerald-300">{{ session('status') }}</div>
        @endif

        {{-- Push status + test --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            @if ($hasPush)
                <div class="flex items-center gap-3">
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-emerald-500/15 text-emerald-400">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="font-display font-bold text-gray-100">Notifications are on</div>
                        <div class="text-xs text-gray-500">This device will get your coach's nudges.</div>
                    </div>
                </div>
                <button type="button" @click="testPush()" :disabled="testing"
                        class="mt-3 w-full rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm font-semibold text-gray-200 active:bg-white/[0.08] disabled:opacity-50 transition">
                    <span x-show="!testing">Send a test notification</span>
                    <span x-show="testing" x-cloak>Sending…</span>
                </button>
            @else
                <div class="font-display font-bold text-gray-100">Turn on notifications</div>
                <p class="mt-1 text-sm text-gray-400 leading-relaxed">Let Titan buzz this device so your coach can remind you to eat, train, move and sleep.</p>
                <button type="button" @click="enablePush()" :disabled="working"
                        class="mt-3 w-full rounded-xl bg-indigo-500 px-4 py-3 text-sm font-bold text-white active:bg-indigo-400 disabled:opacity-50 transition">
                    <span x-show="!working">Enable notifications</span>
                    <span x-show="working" x-cloak>Enabling…</span>
                </button>
                <p class="mt-2 text-[11px] text-gray-600 leading-relaxed">On iPhone, add Titan to your Home Screen first (Share → Add to Home Screen), then enable from the installed app.</p>
            @endif
            <p x-show="toast" x-cloak x-transition class="mt-2 text-sm text-cyan-300" x-text="toast"></p>
        </div>

        {{-- Coaching intensity --}}
        <form method="POST" action="/notifications/settings" class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            @csrf
            <input type="hidden" name="section" value="intensity">
            <h2 class="font-display text-lg font-bold text-gray-100">How present should I be?</h2>
            <p class="mt-1 text-sm text-gray-500">Sets the default mix of reminders. Fine-tune the individual ones below.</p>
            <div class="mt-4 space-y-2.5">
                @foreach (['intense', 'balanced', 'minimal'] as $key)
                    @php $opt = $intensities[$key]; $on = $summary['intensity'] === $key; @endphp
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border-2 px-4 py-3 transition {{ $on ? 'border-indigo-400/60 bg-indigo-400/10' : 'border-white/8' }}">
                        <input type="radio" name="intensity" value="{{ $key }}" @checked($on) class="sr-only peer">
                        <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full border-2 {{ $on ? 'border-indigo-400' : 'border-white/20' }}">
                            <span class="h-2.5 w-2.5 rounded-full {{ $on ? 'bg-indigo-400' : '' }}"></span>
                        </span>
                        <span class="min-w-0">
                            <span class="font-display font-bold text-gray-100">{{ $opt['label'] }}</span>
                            <span class="mt-0.5 block text-[13px] leading-snug text-gray-500">{{ $opt['blurb'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <button class="mt-4 w-full rounded-xl bg-indigo-500 px-4 py-3 text-sm font-bold text-white active:bg-indigo-400 transition">Save intensity</button>
        </form>

        {{-- Fine-tune individual reminders --}}
        <form method="POST" action="/notifications/settings" class="rounded-2xl border border-white/5 bg-white/[0.03] p-4">
            @csrf
            <input type="hidden" name="section" value="types">
            <h2 class="font-display text-lg font-bold text-gray-100">Fine-tune</h2>
            <p class="mt-1 text-sm text-gray-500">Override any single reminder, whatever your intensity.</p>
            <div class="mt-3 divide-y divide-white/5">
                @foreach ($types as $type => $label)
                    @php [$emoji, $desc] = $typeMeta[$type] ?? ['🔔', '']; $on = $summary['types'][$type]['on']; @endphp
                    <label class="flex cursor-pointer items-center gap-3 py-3">
                        <span class="text-xl">{{ $emoji }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-gray-100">{{ $label }}</span>
                            <span class="block text-xs text-gray-500 leading-snug">{{ $desc }}</span>
                        </span>
                        <input type="checkbox" name="types[{{ $type }}]" value="1" @checked($on)
                               class="peer h-6 w-11 shrink-0 cursor-pointer appearance-none rounded-full bg-white/15 transition checked:bg-indigo-500 relative before:absolute before:top-0.5 before:left-0.5 before:h-5 before:w-5 before:rounded-full before:bg-white before:transition checked:before:translate-x-5">
                    </label>
                @endforeach
            </div>
            <button class="mt-4 w-full rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm font-semibold text-gray-200 active:bg-white/[0.08] transition">Save reminders</button>
        </form>
    </div>

    <script>
        function notifSettings() {
            return {
                working: false, testing: false, toast: '',
                async enablePush() {
                    this.working = true; this.toast = '';
                    try {
                        const ok = window.titanSubscribeToPush ? await window.titanSubscribeToPush() : false;
                        if (ok) { location.reload(); return; }
                        this.toast = "Couldn't enable — check this browser's notification permission.";
                    } catch (e) { this.toast = "Couldn't enable notifications."; }
                    this.working = false;
                },
                async testPush() {
                    this.testing = true; this.toast = '';
                    try {
                        const res = await fetch('/notifications/test', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                        });
                        const d = await res.json();
                        this.toast = d.ok ? 'Sent! Check your device in a moment.' : (d.message || 'Could not send a test.');
                    } catch (e) { this.toast = 'Could not send a test.'; }
                    this.testing = false;
                    setTimeout(() => { this.toast = ''; }, 5000);
                },
            };
        }
    </script>
</x-titan-layout>
