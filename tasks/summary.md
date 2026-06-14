# Project Titan — Summary

> **Working title:** Titan
> An AI-powered **personal operating system** for becoming the strongest, healthiest,
> most capable version of yourself — over **decades**, not weeks.
> Built for **two users (Alex + his brother)** as a private duo, structured so it
> *could* open up later, but optimized now for depth over breadth.

## The goal is not "fitness"
The goal is lifelong optimization across: **muscle gain, longevity, mobility, strength,
recovery, nutrition, sleep, biomarkers, cognitive performance, and life goals.**

The product's wedge (validated by market research, see `tasks/research-feature-report.md`):
a **believable, identity-preserved "dream physique" image that *lives*** — it advances
toward the goal as you stay consistent and eases back when you slack — narrated by an
AI coach that can actually *see* your meals and progress photos, with the two brothers
racing each other toward their own future selves. That single loop is the
differentiator, the retention engine, and the marketing hook all at once.

---

## Stack (ported from `~/Documents/alex/fullstack-labs/fullstack-suite`)
| Layer | Tech |
|---|---|
| Backend | Laravel 13 (PHP 8.4), Laravel Sail + Docker |
| Frontend | Blade + Alpine.js + TailwindCSS v4 + Vite |
| Auth | Laravel Breeze (Blade), dark mode |
| Realtime | (later) Reverb websockets for live coach chat |
| Data | MySQL 8 + Redis (queues, cache) |
| AI — language/vision | **OpenAI** via `App\Services\Ai\AiService` (chat, json, tool-calling, embeddings, Whisper) |
| AI — image gen | **Gemini "Nano Banana 2"** via `App\Services\Ai\NanoBananaClient` (text→image AND image+text→image) |

AI keys are reused from `fullstack-suite/.env` (OpenAI + Gemini), already wired into
`.env` and `config/services.php` (`services.openai.*`, `services.gemini.*`).

---

## Architecture: three data layers per profile

Each **profile** (one per brother) owns three complementary stores:

### 1. Structured tracking (typed tables, for charts & trends)
Time-series rows we can graph and feed the coach as numbers:
- **Biomarkers** — bloodwork results over time. Tracked markers (extensible):
  Testosterone, Free Testosterone, ApoB, LDL, HDL, Triglycerides, HbA1c,
  Fasting Glucose, Insulin, Vitamin D, Ferritin, hs-CRP, liver markers (ALT/AST/GGT),
  kidney markers (creatinine/eGFR/BUN). Each marker has units + optimal/reference ranges.
- **Meals** — per meal: photo, AI-estimated calories + macros (protein/carbs/fat),
  editable ingredient breakdown. Daily macro totals vs targets.
- **Workouts** — sessions, exercises, sets/reps/weight, simple adaptive progression.
- **Body metrics** — weight, body-fat % (from progress-photo vision), measurements.
- **Sleep** — duration, stages (if available), quality score.
- **Recovery / stress** — HRV, resting HR, subjective stress, soreness, mood.
- **Progress photos** — dated, alignment-guided; source for physique analysis + morphs.

### 2. The "brain" — per-profile narrative wiki (long-term memory)
Ported from the suite's knowledge system (`KnowledgePage` + services). Markdown pages,
`[[wikilinks]]`, pinned pages = **core memory** injected into the coach every turn.
Hybrid **semantic + keyword search** (cached JSON embeddings, lazy re-embed on change,
brute-force cosine — plenty fast at one profile's scale). This is where narrative,
context, and synthesized insight live: "Alex responds well to high-volume leg days",
"family history of high cholesterol", goals, preferences, doctor's notes, etc.
- **Ingestion:** `KnowledgeIngestor` (LLM "librarian") turns brain dumps + uploaded
  documents (PDF/docx via `DocumentText`/`PdfService` — **bloodwork PDFs, lab reports**)
  into clean, organized, append-safe wiki pages.
- **Agent read/write:** the coach has tools — `search_knowledge`, `read_knowledge`,
  `save_knowledge`, `append_knowledge`, `organize_notes`, `list_knowledge`,
  `lint_knowledge` — so it gets smarter about each brother over time.

> Design rule: **structured numbers go in typed tables** (for charts + precise trends);
> **meaning, context, and synthesis go in the brain.** Bloodwork uploads do BOTH — the
> parser extracts marker values into the `biomarkers` table AND files a narrative page.

### 3. Conversation history
The coach chat itself, recallable so the agent reconstructs past context.

---

## Signature AI features (the loop)
1. **Dream-physique generation** — onboarding: upload current photo → Nano Banana
   renders the goal (e.g. "+10 lbs lean muscle"), identity-preserving, grounded so it's
   realistic not a fantasy filter.
2. **Living goal image** — at each weekly check-in, re-render the user's *current*
   progress photo a calibrated step toward the goal, reflecting real logged adherence.
3. **Photo meal logging** — OpenAI vision → editable ingredient + macro breakdown in
   seconds, with voice/text/barcode fallback; coach asks one clarifying question when
   the estimate is ambiguous (meals are ~10–25% error — design around it).
4. **Progress-photo physique analysis** — vision estimates BF% (confidence-aware range,
   no fake precision, no medical claims), muscle-group ratings, photo-to-photo diff,
   and "% of the way to your goal image".
5. **AI coach chat** — grounded in the profile's own data (tables + brain), explains the
   *why*, proactive check-ins, configurable tone (tough-love ↔ gentle), can read/write
   the brain.
6. **Bloodwork intelligence** — flags out-of-range markers, trends them over time,
   explains them in plain language, ties them to nutrition/training/sleep.
7. **Brother-vs-brother** — shared duo space, head-to-head streaks, "who's closer to
   their dream physique" dual race, cooperate/compete modes.

---

## Key reference files in `fullstack-suite` (patterns to port)
- `app/Services/Ai/AiService.php` — OpenAI wrapper (chat/json/tools/embed/transcribe).
- `app/Services/Meta/NanoBananaClient.php` — Gemini image gen (extend for image input).
- `app/Models/KnowledgePage.php` + migrations `*_create_knowledge_pages_table`,
  `*_add_embedding_to_knowledge_pages` — the brain schema.
- `app/Services/Assistant/KnowledgeSearch.php` — hybrid semantic+keyword search.
- `app/Services/Assistant/KnowledgeIngestor.php` — LLM librarian ingestion.
- `app/Services/Assistant/CrmTools.php` (knowledge tool schemas ~L1699-1727) — agent tools.
- `app/Services/Documents/PdfService.php` + `DocumentText.php` — document/PDF extraction.

## Setup / run
- `./vendor/bin/sail up -d` then `./vendor/bin/sail artisan migrate`
- `./vendor/bin/sail npm run dev` (Vite)
- Requires Docker Desktop running. `.env` already configured (Sail + AI keys).
