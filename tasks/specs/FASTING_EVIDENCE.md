# FASTING v2 — an evidence-driven, educational fasting feature

**Status:** BUILD NOW · **Requested by:** Alex, 2026-07-13
**Grounded in:** `docs/research/DAVID_SINCLAIR_LONGEVITY.md` (the longevity research) + the existing
`app/Support/Fasting.php` (timer + stage table) and the `fasting` coach card.
**One line:** Turn the fasting timer into a longevity *coaching* surface — it teaches the WHY (the
survival pathways engaging as you fast), runs a sustainable eating window, and stays scrupulously
honest about what fasting does and doesn't do. Whoop/most apps just count hours; Titan explains the
biology and refuses to overstate it. That honesty is the moat.

**Non-negotiable framing (straight from the research doc):** human fasting/TRE benefits are **real
but modest, metabolic/risk-factor, and mostly mediated by eating fewer calories — not clock magic,
and NOT proven lifespan extension.** Never claim "reverse aging." Autophagy timing in humans is not
well established — say "believed to increase," never assert a precise hour as fact.

---

## What exists today (build on it)
- `Fasting.php`: start/end/status, `goal_hours` (default 16), and a 6-row `STAGES` table keyed on
  elapsed hours (Fed 0 → Glycogen 4 → Fat-burning 12 → Ketosis 16 → Deep ketosis 24 → Autophagy 36),
  each with a one-line blurb. Emits a `fasting` card (elapsed/goal/pct/stage/next-stage-in).
- Coach tools `start_fast` / `end_fast` / `fasting_status`. iOS renders it (FuelRing in BodyFuel).
- It's a one-off timer only. No eating-window mode, no education beyond a blurb, no adherence/history,
  no honesty caveats, no tie to the pathway science or the protein nuance.

---

## Thrust 1 — Evidence-grounded stages that teach the pathway (the core upgrade)

Rebuild the stage timeline so each stage carries: (a) what's measurably happening (fuel source,
insulin, glycogen, ketones), (b) **which survival pathway is engaging** (insulin/mTOR ↓, AMPK ↑,
sirtuins/NAD⁺, autophagy) — the mechanisms from the research doc, and (c) an **honesty tag** where the
human evidence is thin. This is the Coach v3 educational stance made concrete.

Suggested stages (calibrate, keep honest):
| ~hrs | Stage | What's happening | Pathway (teach the why) | Honesty |
|---|---|---|---|---|
| 0–4 | Fed | Digesting; insulin high | mTOR active (growth mode) | — |
| 4–12 | Glycogen | Blood sugar settles; burning liver glycogen | insulin falling, AMPK rising | — |
| 12–16 | Fat-burning | Glycogen low → shifting to fat | AMPK↑, mTOR↓, lipolysis | solid |
| 16–24 | Ketosis onset | Ketones rising; appetite eases | sirtuins/NAD⁺ context, fat-adaptation | ketones vary by person/diet |
| 24+ | Deeper ketosis | Mostly fat/ketones; GH tends up | — | GH claim is short-term/modest |
| ~depends | Autophagy | Cellular "cleanup" **believed** to rise | autophagy (AMPK↑/mTOR↓ → ULK1) | **human timing NOT well established — do not assert an exact hour** |

- Add a short **"why this matters"** teaching line per stage (mechanism, not a promise).
- Where the app currently asserts "Autophagy at 36h," soften to evidence-honest language.

## Thrust 2 — Eating-window mode (the sustainable, evidence-backed default)

The research is clear: a consistent daily **eating window** is the practical, sustainable proxy for
calorie restriction, and *earlier/consistent* eating is the part that helps. Add this alongside the
one-off timer:
- Let the user set a recurring window: **12/12 → 14/10 → 16/8 → 18/6 → OMAD**, with a start time.
- **Integrate with meal logging** (Titan's #1 feature): show "your window: 12:00–20:00," and when a
  meal is logged **outside** the window, note it gently (not shaming) and reflect it in adherence.
- Track **window adherence as a streak** (reuse the streak infra) — consistency is the behavior that
  actually helps, so reward it (like sleep/training streaks).
- Frame it honestly in-app: "A consistent window mostly helps by making it easier to eat less and by
  keeping eating earlier — that's the evidence, not a longevity guarantee."

## Thrust 3 — The educational layer + coach integration

- A **"what's happening in your body now"** view/card at the current stage — the pathway science,
  pulled from the longevity knowledge pack (see the research doc Part 7). Tap to learn more → a
  `lesson` card (the Coach v3 teaching card already exists).
- The coach can explain fasting mechanisms and the honest limits when asked ("does fasting reverse
  aging?" → the calibrated answer from the research doc, not hype).
- **Protein nuance (hard guardrail from the research):** fasting must not compromise adequate protein,
  especially for **older users / lifters** (the protein–longevity relationship flips with age; elders
  need MORE protein, ~1.2–1.5 g/kg/day). If a user's fasting window is squeezing their protein target
  (Titan already tracks it), the coach should flag it — don't let "fast harder" undercut muscle.

## Thrust 4 — Personalization, safety, honesty guardrails

- **Sustainable progression:** default suggestions start gentle (12/12) and build; never push extreme
  fasts. Tie the goal to the user's actual goal (fat-loss vs muscle-gain — fasting conflicts more with
  a muscle-building phase).
- **Safety framing (not medical advice):** note who should be cautious (pregnancy, diabetes/meds,
  history of disordered eating, underweight) and defer to a doctor — the same honest stance as the
  bloodwork card.
- **The guardrails, everywhere:** metabolic benefits (not lifespan) · mostly via eating less ·
  autophagy timing uncertain · never "reverse aging" · flag Sinclair-promoted supplement claims as
  unproven if they come up. These live in the knowledge pack so the card copy and the coach agree.

## Thrust 5 — Data & surfaces
- **Fasting history + weekly view:** past fasts, longest, window-adherence streak, a 7-day strip
  (mirror the sleep/training week surfaces so it feels native).
- **Enrich the `fasting` card:** current stage + pathway + honest blurb + window adherence + next
  stage. Keep it glanceable.
- Existing `start_fast`/`end_fast`/`fasting_status` stay; add window get/set + adherence to the tools.

## Design principles
- **Teach, don't hype.** Every stage explains a real mechanism; every benefit line is calibrated to
  the human evidence. A user should come away understanding their body, not believing a myth.
- **Honesty is the feature.** The willingness to say "this mostly helps by eating less, and autophagy
  timing isn't nailed down in humans" is exactly what no other fasting app does — lean into it.
- **One product.** Reuse the streak infra, the `lesson` card, the macro/protein targets, the design
  system. Fasting is a lens on the same body, not a silo.

## Acceptance
- [ ] Stages carry pathway + honest framing; the "Autophagy at 36h" assertion is softened to
      evidence-honest language.
- [ ] Eating-window mode: set a recurring window, integrated with meal logging, with an adherence
      streak.
- [ ] "What's happening now" education pulls from the longevity knowledge pack; coach answers fasting
      questions with the calibrated (non-hype) framing.
- [ ] Protein guardrail: the coach flags when a fasting window is squeezing the user's protein target.
- [ ] Fasting history/week view + enriched card.
- [ ] Field check on Alex: start a fast, verify the stage/pathway copy and window adherence on real
      data; confirm the coach's fasting answers are honest (no "reverse aging"). (Henry verifies.)

## Dependency / sequencing note
This is the first concrete build off the longevity research (`DAVID_SINCLAIR_LONGEVITY.md` Part 7).
It pairs with turning that doc's Part 7 into a **coach longevity knowledge pack** (guardrails + the
pathway explanations) — do that knowledge pack alongside, so the fasting copy and the coach share one
honest source of truth.
