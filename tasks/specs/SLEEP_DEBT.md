# SLEEP DEBT — make it a first-class, living concept

**Status:** BUILD NOW
**Requested by:** Alex, 2026-07-12 ("I really like the concept of sleep debt")
**One line:** Promote sleep debt from a buried scalar to a real **ledger** you can watch rise and
fall, understand, and pay down with a concrete plan — the recovery-side counterpart to the training
week.

---

## Where it stands today

`SleepCoach::assess` computes `debt_h`: a recency-weighted sum of the last **5** nights' deficits
vs an 8h baseline (`max(0, baseline − h) × 0.7^age`), capped at 5h. It's correct but thin:

- **Deficit-only.** A great night contributes 0 — it never explicitly *pays debt down*. Debt only
  fades because old short nights roll off the 5-night window. So "I caught up last night" isn't
  modeled or rewarded.
- **5-night window** is short — real debt research treats roughly the last **~2 weeks** as the
  recoverable horizon.
- **One number, buried** in the assess payload. No trend, no payback plan, no story. Alex loves the
  concept — so let's actually surface it.

This spec upgrades the **model** and makes debt a **surface** on iOS, web, the coach, the sleep
planner, and the new Sleep Week view (SLEEP_WEEK.md — its `debt` tip domain reads from here).

---

## The model — `App\Support\SleepDebt` (a ledger, not a sum)

New support class that owns the concept; `SleepCoach` delegates to it and keeps returning `debt_h`
for backward compatibility (nothing downstream breaks).

`SleepDebt::forProfile(Profile $profile, ?Carbon $day = null): array`

Walk the last **14 confident nights** oldest→newest as a running balance:

```
balance = 0
for each night (oldest → newest):
    delta = baseline_h − asleep_h          # + = short night (owe), − = long night (banked)
    if delta > 0:  balance += delta                       # accrue what you missed
    else:          balance += max(delta, −MAX_PAYBACK_PER_NIGHT)   # a big night pays SOME back, bounded
    balance = clamp(balance, 0, DEBT_CAP_H)               # never negative, never runaway
    balance *= NIGHTLY_DECAY_TO_HORIZON                   # old debt is biologically written off
```

Key behaviors the current model lacks:

- **Surplus pays down debt** (bounded). Sleeping 9.5h against an 8h baseline chips ~1.5h off the
  balance — but capped at `MAX_PAYBACK_PER_NIGHT` (~1.5h): you can recover recent debt, you can't
  bank sleep for the future or clear a week of deprivation in one night. That bound is the
  physiology, and it's the honest, motivating part.
- **~2-week horizon.** 14-night window + gentle decay = debt older than ~2 weeks stops counting,
  matching that chronic old debt isn't recoverable by catching up.
- **Balance never goes negative.** "Sleeping ahead" isn't a thing; extra good nights just hold you
  at 0 (call that state **"rested / no debt"**, and let a streak of it feed the Sleep Week streak).

### Return shape

```
{
  "balance_h": 3.2,                 // current debt (0 = rested)
  "band": "moderate",              // none(0) · light(<2) · moderate(2–4) · heavy(>4)
  "trend": "rising",               // vs 3 nights ago: rising | easing | steady
  "paid_back_last_night_h": 0.8,   // how much last night's surplus cleared (0 if it added debt)
  "added_last_night_h": 0.0,       // how much last night ADDED (0 if it paid down)
  "history": [                      // 14 entries, oldest→newest, for the trend line
    { "date": "2026-06-29", "balance_h": 1.1, "delta_h": -0.4 }, ...
  ],
  "payback": {                      // the concrete plan — the part Alex will actually use
    "clearable": true,
    "plan": "+40 min a night for 5 nights clears it",
    "extra_min_per_night": 40,
    "nights": 5,
    "tonight_target_h": 8.7          // = SleepCoach need tonight; one earlier night, not a heroic one
  },
  "explainer": "Debt is the sleep you owe from recent short nights. You can pay back the last ~2
                weeks — about an hour a night — but you can't bank ahead or clear it all at once."
}
```

`payback` must be **realistic**, not a crash diet: spread the balance over N nights at
`extra_min_per_night ≤ MAX_PAYBACK_PER_NIGHT`, and set `tonight_target_h` to the existing
`SleepCoach` need so the planner and the debt card agree. If `balance_h` is 0 → `band:"none"`,
celebrate ("You're rested — no debt to pay").

### Honesty (non-negotiable — same trust rule as everywhere)

Low-confidence and missing nights **do not move the ledger** (a jittery 4h estimate must not invent
3h of debt). Skip them in the walk; note in `history` that the day was unmeasured rather than
scoring it 0.

---

## Surfaces

1. **Sleep screen — a Debt card (iOS + web).** Balance as the hero (e.g. a "battery"/gauge that
   drains with debt), the 14-night **trend line**, last night's ± (paid back 0.8h / added 1.1h),
   and the **payback plan** as a single sentence + tonight's target. This is the headline home for
   the concept.
2. **Sleep Week view** (SLEEP_WEEK.md): the `debt` tip domain pulls its headline/insight/action
   straight from here — one source of truth for the debt story.
3. **Coach.** Expose a `sleep_debt` digest/tool so "how's my sleep debt?" and proactive nudges
   ("you're carrying 3.2h — want to plan an earlier night?") speak the same numbers. Educational
   stance (COACH_V3 THRUST 2): when it comes up, teach the mechanism from the `explainer`, don't
   just recite the number.
4. **Sleep Planner** (if built): tonight's recommended bedtime already accounts for `need`, which
   already includes debt — just make the planner *say* "…including 0.5h to chip at your debt" so
   the causality is visible.
5. **Dashboard readiness:** if debt is `heavy`, let it be a named reason in the recovery read
   ("Recovery's amber — a chunk of that is 4.1h of sleep debt"), tying debt to how he actually
   feels. Reuse existing readiness plumbing; don't fork it.

---

## Design principles

- **Debt you can watch move.** The whole appeal is seeing it rise after a rough night and drain
  when you catch up — the trend line and the last-night ± are the emotional core. A static number
  is what we have now; don't ship that again.
- **Always payable, never shameful.** Frame it as "here's the plan to clear it," never "you failed."
  The payback plan must always be achievable at a humane pace.
- **Honest about limits.** Teach that you can't bank ahead and can't clear a month in one weekend —
  that honesty is what makes Titan's debt trustworthy vs a gamified fiction.
- **One number everywhere.** Sleep card, week tip, coach, planner, dashboard all read the SAME
  `SleepDebt::forProfile` balance. No drift.

## Acceptance

- [ ] `SleepDebt::forProfile` returns the shape above; surplus nights pay down (bounded by
      `MAX_PAYBACK_PER_NIGHT`), balance clamps [0, cap], 14-night horizon with decay, low-confidence
      /missing nights skipped. `SleepCoach::debt_h` delegates and is unchanged for callers.
- [ ] Unit tests: (a) a run of short nights accrues; (b) a big night pays back but only up to the
      cap; (c) two weeks of good sleep returns to 0/rested; (d) a low_confidence 4h night does NOT
      add debt.
- [ ] iOS + web show the Debt card (balance gauge + 14-night trend + last-night ± + payback plan).
- [ ] Coach answers "how's my sleep debt?" with the same balance + the realistic payback plan and
      teaches the mechanism.
- [ ] Field check on Alex's real account: balance matches a hand-walk of his last 14 nights; a
      recent big night shows a non-zero `paid_back_last_night_h`. (Henry verifies on real data.)
