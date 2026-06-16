<x-titan-layout title="Connect an assistant" subtitle="Run your whole Titan account from Claude — no website needed">
    @if (session('status'))
        <div class="mb-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 px-4 py-3 text-sm text-emerald-300">{{ session('status') }}</div>
    @endif

    {{-- The just-minted secret — shown once --}}
    @if ($plain)
        <div class="mb-5 rounded-2xl border border-cyan-500/30 bg-cyan-500/10 p-4 md:p-5">
            <p class="text-sm font-semibold text-cyan-100">Your new token — copy it now, it won't be shown again.</p>
            <div class="mt-2 flex items-center gap-2">
                <code class="flex-1 min-w-0 truncate rounded-lg bg-gray-950 border border-white/10 px-3 py-2 text-xs text-cyan-200 nums">{{ $plain }}</code>
                <button type="button" onclick="navigator.clipboard.writeText('{{ $plain }}')"
                        class="shrink-0 rounded-lg bg-cyan-500/90 px-3 py-2 text-xs font-semibold text-gray-950 active:bg-cyan-400">Copy</button>
            </div>
        </div>
    @endif

    {{-- What this is --}}
    <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 mb-5">
        <h3 class="font-display font-bold text-gray-100">Your account, controlled by your agent</h3>
        <p class="text-sm text-gray-400 mt-1.5 leading-relaxed">
            Generate a token, hand it to your AI agent (Claude via MCP, or any HTTP client), and it can do everything you'd do here —
            read your recovery, sleep, fitness, steps and metabolic health; log workouts, weight and sleep; and even pair your wearable.
            No browser, no clicking. Your token is the key; treat it like a password.
        </p>
    </div>

    {{-- Mint a token --}}
    <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 mb-5">
        <h3 class="font-display font-bold text-gray-100 mb-3">Generate a token</h3>
        <form method="POST" action="{{ route('connect.tokens.store') }}" class="flex flex-col sm:flex-row sm:items-end gap-3">
            @csrf
            <label class="flex-1 min-w-0">
                <span class="block text-xs text-gray-500 mb-1">Name (so you remember where it lives)</span>
                <input type="text" name="name" placeholder="Claude on my laptop" required maxlength="60"
                       class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-cyan-500 focus:ring-0">
            </label>
            <label class="sm:w-40">
                <span class="block text-xs text-gray-500 mb-1">Access</span>
                <select name="scope" class="w-full h-11 rounded-xl bg-gray-950 border border-white/10 px-3 text-base text-gray-100 focus:border-cyan-500 focus:ring-0">
                    <option value="full">Read &amp; write</option>
                    <option value="read">Read-only</option>
                </select>
            </label>
            <button type="submit" class="h-11 rounded-xl bg-cyan-500/90 px-5 text-sm font-semibold text-gray-950 active:bg-cyan-400">Generate</button>
        </form>
    </div>

    {{-- Setup snippet --}}
    <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5 mb-5">
        <h3 class="font-display font-bold text-gray-100 mb-3">Point your agent at it</h3>
        <p class="text-xs text-gray-500 mb-2">Any agent can call the API directly:</p>
        <pre class="overflow-x-auto rounded-xl bg-gray-950 border border-white/10 p-3 text-[11px] text-gray-300 leading-relaxed"><code>curl {{ $apiBase }}/tool \
  -H "Authorization: Bearer &lt;your-token&gt;" \
  -H "Content-Type: application/json" \
  -d '{"tool":"get_today"}'</code></pre>
        <p class="text-xs text-gray-500 mt-3 mb-2">Or wire the Titan MCP server into Claude (<span class="text-gray-400">mcp/</span> in the repo) so it shows up as native tools:</p>
        <pre class="overflow-x-auto rounded-xl bg-gray-950 border border-white/10 p-3 text-[11px] text-gray-300 leading-relaxed"><code>{
  "mcpServers": {
    "titan": {
      "command": "node",
      "args": ["/path/to/titan-mcp/dist/index.js"],
      "env": { "TITAN_API": "{{ $apiBase }}", "TITAN_TOKEN": "&lt;your-token&gt;" }
    }
  }
}</code></pre>
        <p class="text-[11px] text-gray-600 mt-2">Tools available: <code class="text-gray-500">get_today, get_recovery, get_sleep, get_fitness, get_activity, get_workouts, get_biomarkers, get_meals, get_profile, get_devices, log_sleep, log_steps, log_recovery, log_weight, log_workout, set_goal, pair_device</code>.</p>
    </div>

    {{-- Existing tokens --}}
    <h3 class="text-[11px] uppercase tracking-wider text-gray-500 mb-2">Your tokens</h3>
    @if ($tokens->isEmpty())
        <div class="rounded-2xl border border-dashed border-white/10 bg-white/[0.02] p-6 text-center text-sm text-gray-500">No tokens yet.</div>
    @else
        <div class="space-y-2">
            @foreach ($tokens as $token)
                <div class="flex items-center justify-between gap-3 rounded-2xl border border-white/5 bg-white/[0.03] p-3.5">
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-100 truncate">{{ $token->name }}</div>
                        <div class="text-[11px] text-gray-500 nums">
                            {{ in_array('*', $token->abilities ?? ['*']) ? 'Read & write' : 'Read-only' }}
                            · {{ $token->last_used_at ? 'last used '.$token->last_used_at->diffForHumans() : 'never used' }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('connect.tokens.destroy', $token) }}" onsubmit="return confirm('Revoke this token? Any agent using it will lose access.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="shrink-0 rounded-lg border border-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-300 active:bg-rose-500/10">Revoke</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif
</x-titan-layout>
