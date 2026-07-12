{{-- Titan weekly review email — week score, dream-physique progress, wins + next week. --}}
<x-mail::message>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:24px;">
<tr><td>
<span style="font-family:'Archivo','Helvetica Neue',Arial,sans-serif;font-size:26px;font-weight:800;letter-spacing:-0.5px;color:#6366f1;">TITAN</span>
<div style="font-family:'Manrope',Arial,sans-serif;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#9ca3af;margin-top:4px;">{{ __('Your week in review · :range', ['range' => $review['range'] ?? '']) }}</div>
</td></tr>
</table>

# {{ __('Hey :name 👋', ['name' => $name]) }}

@if (!is_null($review['score'] ?? null))
@php
    $scoreLine = __('Week score: :score/100', ['score' => $review['score']]);
    if (!empty($review['score_delta'])) {
        $scoreLine .= ' ('.($review['score_delta'] > 0 ? '+' : '').$review['score_delta'].' '.__('vs last week').')';
    }
    if (($review['streak'] ?? 0) >= 2) {
        $scoreLine .= ' · 🔥 '.__(':count-week streak', ['count' => $review['streak']]);
    }
@endphp
**{{ $scoreLine }}**
@endif

{{ $review['headline'] ?? '' }}

@if (!empty($review['physique']))
@php
    $ph = $review['physique'];
    $physLine = __('Toward your dream physique:').' '.__("you're :pct% of the way there", ['pct' => $ph['step_pct']]);
    if (!empty($ph['step_delta']) && $ph['step_delta'] > 0) {
        $physLine .= ' — **'.__('+:pct% this week', ['pct' => $ph['step_delta']]).'**';
    }
    $physLine .= '. '.($ph['verdict_label'] ?? '');
    if (!is_null($ph['eta_weeks'] ?? null)) {
        $physLine .= ', '.trans_choice('~:count week to go at this pace|~:count weeks to go at this pace', (int) $ph['eta_weeks'], ['count' => $ph['eta_weeks']]);
    }
    $physLine .= '. '.__('Stay on it.');
@endphp
<x-mail::panel>
{{ $physLine }}
</x-mail::panel>
@endif

@if (!empty($review['metrics']))
**{{ __('This week') }}**
@foreach ($review['metrics'] as $m)
- **{{ $m['label'] }}:** {{ $m['value'] }}@if (!empty($m['delta']['text'])) ({{ $m['delta']['good'] ? '▲' : '▼' }} {{ $m['delta']['text'] }})@endif
@endforeach
@endif

@if (!empty($review['wins']))
**{{ __('Wins') }}**
@foreach ($review['wins'] as $w)
- {{ $w }}
@endforeach
@endif

@if (!empty($review['next']['text']))
**{{ __('Next week:') }}** {{ $review['next']['text'] }}
@endif

<x-mail::button :url="$progressUrl" color="primary">
{{ __('See your progress') }}
</x-mail::button>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:8px;border-top:1px solid #e5e7eb;padding-top:16px;">
<tr><td style="font-family:'Manrope',Arial,sans-serif;font-size:12px;color:#9ca3af;line-height:1.5;">
{{ __('Coaching grounded in your logged data — not medical advice. You get this each week; turn the weekly review off anytime in your notification settings.') }}
</td></tr>
</table>

{{ __('Keep building,') }}<br>
**— Titan**
</x-mail::message>
