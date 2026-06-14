# Project Titan — Roadmap / TODO

> **STATUS — first full build complete.** Foundation + all 8 verticals built by parallel
> agents, integrated, migrated, seeded, and browser-tested (every route 200). OpenAI + Gemini
> verified live. App runs at http://localhost:8088 (login alex@titan.test / password).
> Built: Brain, Bloodwork/Biomarkers, Body, Meals, Workouts, Sleep, Recovery, Physique,
> Coach, Duo. Next: polish each vertical, wire notifications/streak automation, real photo
> flows for the living goal image, adaptive targets, wearable imports.

Legend: `[ ]` todo · `[~]` in progress · `[x]` done

## Phase 0 — Foundation (in progress)
- [x] Scaffold fresh Laravel 13 + Breeze (Blade, dark) auth
- [x] Sail + MySQL + Redis (`docker-compose.yml`)
- [x] `.env` wired: Sail DB, Redis queue/cache, OpenAI + Gemini keys
- [x] `config/services.php`: `openai` + `gemini` blocks
- [x] Project docs: `tasks/summary.md`, `tasks/todo.md`, research report
- [ ] Port `AiService` → `app/Services/Ai/AiService.php`
- [ ] Port + extend `NanoBananaClient` (add image+text→image input)
- [ ] Boot the stack: `sail up -d`, `migrate`, confirm login works
- [ ] `Profile` concept: each user has one profile (the "duo" = the two of them)

## Phase 1 — The Brain (per-profile long-term memory)  ← do first, everything leans on it
- [ ] `knowledge_pages` migration (profile-scoped) + `embedding`/`embed_hash`
- [ ] `KnowledgePage` model (wikilinks, backlinks, pinned = core memory)
- [ ] Port `KnowledgeSearch` (hybrid semantic + keyword, lazy re-embed)
- [ ] Port `KnowledgeIngestor` (LLM librarian brain-dump → pages)
- [ ] Port `DocumentText` + `PdfService` (PDF/docx extraction)
- [ ] Wiki UI: list / read / edit pages, see backlinks, pin to core memory
- [ ] Upload flow: drop a document → ingest into the brain

## Phase 2 — Health Data Layer (the most valuable piece)
- [ ] `biomarkers` table (profile_id, marker, value, unit, taken_at, source) + reference ranges catalog
- [ ] Bloodwork upload → AI parses PDF → extracts marker values into `biomarkers`
      AND files a narrative brain page; flags out-of-range values
- [ ] Biomarker dashboard: per-marker trend charts, in/out-of-range status
- [ ] Markers to support first: Testosterone, Free T, ApoB, LDL, HDL, Triglycerides,
      HbA1c, Fasting Glucose, Insulin, Vitamin D, Ferritin, hs-CRP, ALT/AST/GGT,
      creatinine/eGFR/BUN
- [ ] `body_metrics` (weight, BF%, measurements) + trend chart

## Phase 3 — Meals & Macros (AI photo logging)
- [ ] `meals` + `meal_items` tables (photo, calories, protein/carbs/fat, ingredients)
- [ ] Snap-a-meal: OpenAI vision → editable ingredient/macro breakdown
- [ ] Voice/text/barcode fallback; coach asks 1 clarifying question on ambiguity
- [ ] Daily macro targets vs actuals; reconcile to one verified nutrition DB
- [ ] Adaptive targets (recalc TDEE from weight + intake trend — MacroFactor-style)

## Phase 4 — Workouts
- [ ] `workouts` + `exercises` + `sets` tables; exercise library
- [ ] Log a session (sets/reps/weight, RPE); simple progressive-overload suggestions
- [ ] Adaptive plan that adjusts after each session

## Phase 5 — Sleep, Recovery & Stress
- [ ] `sleep_logs` (duration, stages, quality), `recovery_logs` (HRV, RHR, stress, soreness, mood)
- [ ] Manual entry first; wearable/Apple Health import later
- [ ] Feed recovery signal into training load + coach guidance

## Phase 6 — Progress Photos + the Living Goal Image (the wedge)
- [ ] `progress_photos` table; aligned capture with overlay/ghost guide
- [ ] Dream-physique generation at onboarding (Nano Banana, identity-preserving)
- [ ] Vision physique analysis: BF% range, muscle-group ratings, photo-to-photo diff
- [ ] "% to goal" comparison vs the goal image
- [ ] Living goal image: weekly re-render of current photo stepped toward goal by adherence
- [ ] Milestone "reveal" generations as rewards

## Phase 7 — AI Coach (ties it all together)
- [ ] Chat coach grounded in tables + brain; tool-calling (read/write knowledge, query data)
- [ ] Pinned core-memory pages injected each turn; explains the "why"
- [ ] Proactive check-ins (daily/weekly), configurable tone
- [ ] Whisper voice notes → transcribe → coach
- [ ] Realtime via Reverb (streaming replies)

## Phase 8 — Duo / Brother-vs-Brother & Retention
- [ ] Shared duo space; head-to-head weekly streaks (+ streak freeze)
- [ ] Dual physique race ("who's closer to their goal")
- [ ] Cooperate vs compete modes; light stakes ("you owe dinner")
- [ ] Day-one celebrated win; evening (5–7pm) behavior-triggered push notifications
- [ ] Shareable before/after + auto time-lapse

---

### Immediate next slice (proposed)
**Phase 0 finish + Phase 1 (the Brain)** — port `AiService` + `NanoBananaClient`, boot the
stack, then stand up the per-profile knowledge wiki with document/bloodwork ingestion.
Everything else (biomarkers, meals, coach) reads from and writes to the brain, so it's
the right foundation to build first.
