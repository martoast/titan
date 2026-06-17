{{-- Titan weekly review email — week score, dream-physique progress, wins + next week. --}}
<x-mail::message>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:24px;">
<tr><td>
<span style="font-family:'Archivo','Helvetica Neue',Arial,sans-serif;font-size:26px;font-weight:800;letter-spacing:-0.5px;color:#6366f1;">TITAN</span>
<div style="font-family:'Manrope',Arial,sans-serif;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#9ca3af;margin-top:4px;">Your week in review · {{ $review['range'] ?? '' }}</div>
</td></tr>
</table>

# Hey {{ $name }} 👋

@if (!is_null($review['score'] ?? null))
**Week score: {{ $review['score'] }}/100**@if (!empty($review['score_delta'])) ({{ $review['score_delta'] > 0 ? '+' : '' }}{{ $review['score_delta'] }} vs last week)@endif@if (($review['streak'] ?? 0) >= 2) · 🔥 {{ $review['streak'] }}-week streak@endif
@endif

{{ $review['headline'] ?? '' }}

@if (!empty($review['physique']))
<x-mail::panel>
**Toward your dream physique:** you're **{{ $review['physique']['step_pct'] }}%** of the way there@if (!empty($review['physique']['step_delta']) && $review['physique']['step_delta'] > 0) — **+{{ $review['physique']['step_delta'] }}% this week**@endif. {{ $review['physique']['verdict_label'] ?? '' }}@if (!is_null($review['physique']['eta_weeks'] ?? null)), ~{{ $review['physique']['eta_weeks'] }} week{{ $review['physique']['eta_weeks'] === 1 ? '' : 's' }} to go at this pace@endif. Stay on it.
</x-mail::panel>
@endif

@if (!empty($review['metrics']))
**This week**
@foreach ($review['metrics'] as $m)
- **{{ $m['label'] }}:** {{ $m['value'] }}@if (!empty($m['delta']['text'])) ({{ $m['delta']['good'] ? '▲' : '▼' }} {{ $m['delta']['text'] }})@endif
@endforeach
@endif

@if (!empty($review['wins']))
**Wins**
@foreach ($review['wins'] as $w)
- {{ $w }}
@endforeach
@endif

@if (!empty($review['next']['text']))
**Next week:** {{ $review['next']['text'] }}
@endif

<x-mail::button :url="$progressUrl" color="primary">
See your progress
</x-mail::button>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:8px;border-top:1px solid #e5e7eb;padding-top:16px;">
<tr><td style="font-family:'Manrope',Arial,sans-serif;font-size:12px;color:#9ca3af;line-height:1.5;">
Coaching grounded in your logged data — not medical advice. You get this each week; turn the weekly review off anytime in your notification settings.
</td></tr>
</table>

Keep building,<br>
**— Titan**
</x-mail::message>
