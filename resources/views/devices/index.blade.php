<x-titan-layout title="Devices" subtitle="Connect a band — your biosignals, your server">
    @php
        $sourceMeta = [
            'titan_band'   => ['Titan Band', 'from-indigo-500 to-cyan-400'],
            'bangle'       => ['Bangle.js', 'from-emerald-500 to-teal-400'],
            'polar'        => ['Polar', 'from-rose-500 to-orange-400'],
            'apple_health' => ['Apple Health', 'from-gray-400 to-gray-200'],
        ];
    @endphp

    <div class="space-y-4 md:space-y-5 max-w-2xl mx-auto">

        {{-- One-time secret reveal (flashed right after pairing) --}}
        @if ($justPaired)
            <div class="rounded-2xl border border-amber-500/30 bg-amber-500/[0.06] p-4 md:p-5"
                 x-data="{ copied: false }">
                <div class="flex items-start gap-3">
                    <svg class="h-5 w-5 shrink-0 text-amber-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div class="min-w-0">
                        <div class="font-display font-bold text-amber-200">{{ $justPaired['source'] }} paired</div>
                        <p class="text-xs text-amber-200/70 mt-0.5">Copy the secret now — it is shown once and never stored in plain text. Your device needs both values to sign uploads.</p>
                    </div>
                </div>
                <div class="mt-4 space-y-2">
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Device ID</div>
                        <code class="block mt-1 w-full rounded-xl bg-gray-950 border border-white/10 px-3 py-2.5 text-sm text-gray-100 break-all nums">{{ $justPaired['device_id'] }}</code>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase tracking-wide text-gray-500">Secret</div>
                        <div class="mt-1 flex gap-2">
                            <code class="flex-1 min-w-0 rounded-xl bg-gray-950 border border-white/10 px-3 py-2.5 text-sm text-gray-100 break-all nums">{{ $justPaired['secret'] }}</code>
                            <button type="button"
                                    @click="navigator.clipboard.writeText('{{ $justPaired['secret'] }}'); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="shrink-0 h-11 w-11 grid place-items-center rounded-xl border border-white/10 bg-white/[0.03] text-gray-300 active:bg-white/10">
                                <svg x-show="!copied" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                <svg x-show="copied" x-cloak class="h-5 w-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Pair a new device --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5" x-data="{ open: {{ $connections->isEmpty() ? 'true' : 'false' }} }">
            <button type="button" @click="open = !open" class="w-full flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="h-10 w-10 shrink-0 grid place-items-center rounded-xl bg-gradient-to-br from-indigo-500 to-cyan-400 text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    </span>
                    <div class="text-left min-w-0">
                        <div class="font-display font-bold text-gray-100">Pair a device</div>
                        <div class="text-xs text-gray-500 truncate">Issue a signing key for a new band</div>
                    </div>
                </div>
                <svg class="h-5 w-5 shrink-0 text-gray-500 transition" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>

            <form x-show="open" x-cloak method="POST" action="/devices/pair" class="mt-4 space-y-3">
                @csrf
                <div>
                    <label class="text-[11px] uppercase tracking-wide text-gray-500">Source</label>
                    <select name="source" required
                            class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-indigo-500 focus:ring-0">
                        @foreach ($pairable as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-[11px] uppercase tracking-wide text-gray-500">Label (optional)</label>
                    <input type="text" name="label" maxlength="80" placeholder="e.g. Left wrist band"
                           class="mt-1 w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 placeholder-gray-600 focus:border-indigo-500 focus:ring-0">
                </div>
                <button type="submit" class="w-full md:w-auto h-12 px-6 rounded-xl font-semibold text-white bg-gradient-to-r from-indigo-500 to-cyan-500 active:opacity-90">
                    Generate signing key
                </button>
            </form>
        </div>

        {{-- Apple Health import --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5"
             x-data="{ open: false, fileName: '' }">
            <button type="button" @click="open = !open" class="w-full flex items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="h-10 w-10 shrink-0 grid place-items-center rounded-xl bg-gradient-to-br from-gray-400 to-gray-200 text-gray-900">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                    </span>
                    <div class="text-left min-w-0">
                        <div class="font-display font-bold text-gray-100">Apple Health</div>
                        <div class="text-xs text-gray-500 truncate">Import your iPhone Health export</div>
                    </div>
                </div>
                <svg class="h-5 w-5 shrink-0 text-gray-500 transition" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>

            {{-- Last import summary --}}
            @if ($appleHealthSummary)
                <div class="mt-3 rounded-xl border border-emerald-500/30 bg-emerald-500/[0.06] p-3">
                    <div class="text-[11px] uppercase tracking-wide text-emerald-300/80">Last import</div>
                    <p class="mt-1 text-sm text-emerald-100/90">
                        {{ $appleHealthSummary['recovery'] }} recovery · {{ $appleHealthSummary['sleep'] }} sleep · {{ $appleHealthSummary['body'] }} body · {{ $appleHealthSummary['workouts'] }} workouts
                    </p>
                    @if ($appleHealthSummary['date_from'])
                        <p class="mt-0.5 text-xs text-emerald-200/60 nums">{{ $appleHealthSummary['date_from'] }} → {{ $appleHealthSummary['date_to'] }} · {{ number_format($appleHealthSummary['samples']) }} samples read</p>
                    @endif
                </div>
            @elseif ($appleHealth?->last_sync_at)
                <div class="mt-3 text-xs text-gray-500">Last imported {{ $appleHealth->last_sync_at->diffForHumans() }}.</div>
            @endif

            @if ($appleHealthError)
                <div class="mt-3 rounded-xl border border-rose-500/30 bg-rose-500/[0.06] p-3">
                    <p class="text-sm text-rose-200/90">{{ $appleHealthError }}</p>
                </div>
            @endif

            <div x-show="open" x-cloak class="mt-4 space-y-3">
                <ol class="space-y-1.5 text-xs text-gray-400 list-decimal list-inside">
                    <li>On your iPhone, open <span class="text-gray-200">Health</span> → tap your <span class="text-gray-200">profile picture</span>.</li>
                    <li>Scroll down and tap <span class="text-gray-200">Export All Health Data</span>.</li>
                    <li>Save / AirDrop the <code class="text-gray-200">export.zip</code> to this device, then upload it below.</li>
                </ol>

                <form method="POST" action="/devices/apple-health/import" enctype="multipart/form-data" class="space-y-3"
                      x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <x-upload-zone kind="file" name="export" required accept=".zip,application/zip"
                                   label="Tap to add your Apple Health export" hint="The export.zip from the Health app" />
                    <button type="submit" x-bind:disabled="busy"
                            class="w-full h-12 px-6 rounded-xl font-semibold text-gray-900 bg-gradient-to-r from-gray-200 to-white active:opacity-90 disabled:opacity-40">
                        <span x-show="!busy">Import health data</span>
                        <span x-show="busy" x-cloak>Importing…</span>
                    </button>
                    <p class="text-[11px] text-gray-600 leading-relaxed">
                        Large exports can take a moment and may need a higher server upload limit. Re-importing is safe — it updates days in place and never overwrites your own ratings.
                    </p>
                </form>
            </div>
        </div>

        {{-- Polar AccessLink connect --}}
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            <div class="flex items-start gap-3">
                <span class="h-10 w-10 shrink-0 grid place-items-center rounded-xl bg-gradient-to-br from-rose-500 to-orange-400 text-white">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12h-4l-3 8L9 4l-3 8H3"/></svg>
                </span>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <div class="font-display font-bold text-gray-100">Polar</div>
                        @if ($polarConnection?->isConnected())
                            <span class="shrink-0 inline-flex items-center gap-1 text-[11px] text-emerald-300">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span> Connected
                            </span>
                        @endif
                    </div>
                    <p class="mt-0.5 text-xs text-gray-500">Pull exercises, sleep &amp; Nightly Recharge from your Polar watch.</p>

                    @if ($polarConfigured)
                        <a href="/devices/polar/connect"
                           class="mt-3 inline-flex items-center justify-center h-11 px-5 rounded-xl font-semibold text-white bg-gradient-to-r from-rose-500 to-orange-500 active:opacity-90">
                            {{ $polarConnection?->isConnected() ? 'Reconnect Polar' : 'Connect Polar' }}
                        </a>
                    @else
                        <div class="mt-3 rounded-xl border border-dashed border-white/10 bg-white/[0.02] p-3">
                            <p class="text-xs text-gray-500">Needs a free Polar dev account — set <code class="text-gray-400">POLAR_CLIENT_ID</code> / <code class="text-gray-400">POLAR_CLIENT_SECRET</code> from <span class="text-gray-400">admin.polaraccesslink.com</span> to enable.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Last sync summary --}}
        @if ($lastIngestion)
            <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                <div class="text-[11px] uppercase tracking-wide text-gray-500">Last batch received</div>
                <div class="mt-1 flex items-baseline gap-2">
                    <span class="font-display text-xl font-bold text-gray-100">{{ $lastIngestion->created_at->diffForHumans() }}</span>
                    <span class="text-sm text-gray-500">{{ ucfirst($lastIngestion->kind) }} · {{ ucfirst(str_replace('_', ' ', $lastIngestion->source)) }}</span>
                </div>
                @php
                    $statusColor = match ($lastIngestion->status) {
                        'processed' => 'text-emerald-400',
                        'failed' => 'text-rose-400',
                        default => 'text-amber-400',
                    };
                @endphp
                <div class="mt-1 text-xs {{ $statusColor }}">{{ ucfirst($lastIngestion->status) }}@if($lastIngestion->algo_version) · {{ $lastIngestion->algo_version }}@endif</div>
            </div>
        @endif

        {{-- Connected devices --}}
        <div class="space-y-3">
            <div class="text-[11px] uppercase tracking-wide text-gray-500 px-1">Connected devices</div>
            @forelse ($connections as $connection)
                @php [$name, $grad] = $sourceMeta[$connection->source] ?? [ucfirst($connection->source), 'from-indigo-500 to-cyan-400']; @endphp
                <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
                    <div class="flex items-start gap-3">
                        <span class="h-11 w-11 shrink-0 grid place-items-center rounded-xl bg-gradient-to-br {{ $grad }} text-white">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17a2 2 0 11-4 0 2 2 0 014 0zm10 0a2 2 0 11-4 0 2 2 0 014 0zM5 9h14M7 9V6a1 1 0 011-1h8a1 1 0 011 1v3m-1 0v4a1 1 0 01-1 1H9a1 1 0 01-1-1V9"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <div class="font-display font-bold text-gray-100 truncate">{{ $connection->last_payload_type ?: $name }}</div>
                                @if ($connection->isConnected())
                                    <span class="shrink-0 inline-flex items-center gap-1 text-[11px] text-emerald-300">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span> Connected
                                    </span>
                                @else
                                    <span class="shrink-0 text-[11px] text-gray-500">Disconnected</span>
                                @endif
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500 truncate nums">{{ $connection->device_id }}</div>
                            <div class="mt-2 grid grid-cols-2 gap-3">
                                <div>
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Last sync</div>
                                    <div class="text-sm text-gray-300 nums">{{ $connection->last_sync_at?->diffForHumans() ?? 'Never' }}</div>
                                </div>
                                <div>
                                    <div class="text-[11px] uppercase tracking-wide text-gray-500">Timezone</div>
                                    <div class="text-sm text-gray-300 truncate">{{ $connection->timezone ?? '—' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @if ($connection->isConnected())
                        <form method="POST" action="/devices/{{ $connection->id }}" class="mt-4"
                              onsubmit="return confirm('Disconnect this device? Its signing key is revoked immediately.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="w-full h-11 rounded-xl border border-rose-500/30 bg-rose-500/10 text-sm font-semibold text-rose-300 active:bg-rose-500/20">
                                Disconnect
                            </button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.02] p-8 text-center">
                    <p class="text-gray-400 text-sm">No devices connected yet.</p>
                    <p class="text-gray-600 text-xs mt-1">Pair a band above to start streaming HRV, sleep and recovery into Titan.</p>
                </div>
            @endforelse
        </div>

    </div>
</x-titan-layout>
