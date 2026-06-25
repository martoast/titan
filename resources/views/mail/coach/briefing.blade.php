{{-- Titan-branded proactive coach briefing email. Self-contained inline styles so it
     renders consistently across mail clients without depending on a published mail theme. --}}
<x-mail::message>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-bottom:24px;">
<tr><td>
<span style="font-family:'Archivo','Helvetica Neue',Arial,sans-serif;font-size:26px;font-weight:800;letter-spacing:-0.5px;background:linear-gradient(90deg,#818cf8,#67e8f9);-webkit-background-clip:text;background-clip:text;color:#6366f1;">TITAN</span>
<div style="font-family:'Manrope',Arial,sans-serif;font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#9ca3af;margin-top:4px;">{{ $heading }}</div>
</td></tr>
</table>

# Hey {{ $name }} 👋

{{ $body }}

<x-mail::button :url="$coachUrl" color="primary">
Open your coach
</x-mail::button>

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin-top:8px;border-top:1px solid #e5e7eb;padding-top:16px;">
<tr><td style="font-family:'Manrope',Arial,sans-serif;font-size:12px;color:#9ca3af;line-height:1.5;">
This is coaching grounded in your logged data — not medical advice. For clinical concerns, see a qualified physician.
@if ($kind === 'morning')
<br>You get this each morning. Turn briefings off anytime in your coach settings.
@elseif ($kind === 'evening')
<br>You get this each evening. Turn nudges off anytime in your coach settings.
@endif
</td></tr>
</table>

Stay consistent,<br>
**— Titan**
</x-mail::message>
