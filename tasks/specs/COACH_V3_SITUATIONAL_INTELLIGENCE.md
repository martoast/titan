# COACH v3 — Situational Intelligence & Education

**Spec for the dev agent · from Alex + Henry · 2026-07-12**
**Status: BUILD (after the trust fix + Longevity Index in PRIORITY_NOW). Deepens how the coach understands the person.**

## The vision (Alex)

> "I love how the AI considers a woman's cycle for her nutrition and training. Extend that — give the coach
> a deeper grasp of the person and their situation. And it should EDUCATE as well as recommend: every
> recommendation is a chance to teach WHY it's being made."

Two thrusts: (1) make the coach reason through the person's **whole situation** the way it already does for
the menstrual cycle, and (2) make it a **teacher**, not just a prescriber.

## Why the cycle-awareness is the right template
`CoachService::systemPrompt` (`:660-666`) injects `Cycle::coachDigest($profile)` PLUS a directive: *"the
menstrual cycle shapes energy, training, nutrition, recovery, mood… weave the current phase into your
coaching across ALL of these, naturally… (lean into heavy training in the follicular phase, ease volume +
add fuel in late luteal)."* It works because it's **a digest + a connected, cross-domain instruction** —
not a number, a LENS the coach sees everything through. We already do this for cycle, physique, memory and
trajectory. The rest of the person's situation deserves the same treatment.

---

## THRUST 1 — A situational-lens system (extend the cycle pattern)

Build `app/Support/Coach/PersonalContext.php` that assembles the person's **active situational lenses** into
the system prompt — each a short digest + a cross-domain "weave this in" directive, only when it's relevant
(like cycle only shows for female profiles). The 18 existing `assess()`/`digest()` methods are the raw
material; this turns the relevant ones into always-on lenses:

- **Recovery / sleep-debt lens** (`SleepCoach`, `Readiness`, `RecoveryConfidence`) — *"carrying 5h of sleep
  debt and recovery is amber → today favor technique/volume over intensity, and protect tonight's sleep;
  don't program a PR attempt."* Connects to training + nutrition + the day's plan.
- **Stress lens** (`StressMonitor` — just shipped) — *"stress has run high since 2pm → suggest a
  down-regulating session + a physiological sigh, and don't add training stress on top of life stress."*
- **Training-phase lens** (`TrainingLoad`, `Autoregulator`, current mesocycle) — where they are in the block
  (accumulation vs deload vs peak) → volume/intensity + fueling shift, same as cycle phases do.
- **Biomarker-flags lens** (`Biomarkers`, `MetabolicHealth`) — a flagged marker becomes actionable context:
  *"ferritin's low → lean into iron-rich foods + pair with vitamin C; it also explains the flat endurance."*
- **Life-context lens** (`CoachMemoryBook`) — durable life facts (travel, new parent, job crunch, injury)
  shape tone + load, not just get "remembered." Weave them in like cycle: an injury reshapes the program,
  travel reshapes circadian + nutrition advice.
- Cycle stays the exemplar; unify them all under one PersonalContext assembler so a new lens is added in
  ONE place with a relevance gate + budget (keep the whole block tight — this rides in every prompt).

**The load-bearing idea (not new metrics):** lenses must be CONNECTED and CROSS-DOMAIN like cycle — "this
situation shifts training AND nutrition AND recovery AND how I talk to you" — not a list of siloed status
lines (the trajectory digest already covers the where's-it-heading numbers; this is the *so-what*, the
situational interpretation a great coach walks in with).

---

## THRUST 2 — The educational stance (teach the WHY, don't just prescribe)

Upgrade the single "explain the WHY" bullet (`CoachService.php:550`) into a genuine teaching posture:

- **Every recommendation carries its mechanism, at the right depth.** Not "eat more carbs tonight" but
  "eat more carbs tonight — training glycogen-depleted spikes cortisol, and high cortisol at night
  fragments your deep sleep; the carbs blunt it. That's why your deep dropped on your fasted-lifting nights."
  Teach the CHAIN so they build a mental model and eventually self-regulate.
- **Teachable moments from their own data.** When a lens or the trajectory reveals a pattern, deliver a
  crisp mini-lesson grounded in THEIR numbers ("here's what your HRV is actually telling you…") — the most
  persuasive teaching is about their own body.
- **Calibrate depth — teach, don't lecture.** One or two sharp sentences by default; go deeper only when
  they ask "why" or seem curious. The `coach_tone` + `coaching_intensity` settings gate how much.
- **Compound it into their wiki.** When the coach teaches a durable principle that fits the person (e.g.
  "late caffeine wrecks your deep sleep"), consider saving it via the living-knowledge path (P3) so the
  lesson persists and the coach can reference "like we talked about" — education that accumulates.
- **Cite when it's a fact, judgment when it's coaching** (the existing web_search grounding still applies) —
  teaching must be accurate, not confident hand-waving.

Prompt-wise: replace the one bullet with a short "You are a coach AND an educator" section, and add an
example or two so the model calibrates the teach-the-why depth.

---

## Surfaces & acceptance
- **PersonalContext** assembles the active lenses (relevance-gated, budgeted ≤ ~1200 chars total) into the
  system prompt, each with a cross-domain directive. Verify: a user with high sleep debt + high stress gets
  BOTH lenses woven into a training question's answer without being asked to fetch them.
- **Educational stance:** ask the coach a "should I train hard today?" with poor recovery — it should give a
  recommendation AND teach the mechanism (why recovery gates intensity) in the same short answer, calibrated
  to tone.
- Lenses degrade honestly (a lens with thin-confidence data hedges, never states a soft number as fact —
  the whole app's ethos, and consistent with the trajectory/confidence work).
- Field-test on Alex's real profile (and note: the cycle lens must keep working exactly as it does now).

## Relationship to the rest
- Builds directly on Coach v2 (trajectory P1, living knowledge P3) — PersonalContext sits alongside the
  trajectory digest (numbers → *where it's heading*; lenses → *what the situation means*).
- The Stress lens needs the Stress Monitor (shipping now); the Longevity lens can fold in once the Index
  lands. So this naturally comes AFTER the current priorities — by then its inputs all exist.

*The cycle lens proved the thesis: a coach that understands your situation, not just your stats, and teaches
you your own body — that's what makes it feel like a coach instead of a dashboard. Generalize it, and make
every recommendation a lesson.*
