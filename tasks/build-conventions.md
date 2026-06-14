# Titan — Build Conventions (read this first, every agent)

You are building ONE vertical of **Project Titan**, an AI health/longevity OS (Laravel 13,
PHP 8.4, Sail/Docker, Blade + Alpine + Tailwind v4). The app already runs at
`http://localhost:8088`. Read `tasks/summary.md` for the product vision.

## 🚦 Golden rules (prevent collisions with other agents building in parallel)
1. **Only create/edit files inside YOUR domain's directories** (listed in your task) plus
   **your one route file** `routes/titan/<domain>.php` (already exists, already required &
   auth-protected — just add routes to it).
2. **NEVER edit these shared files:** `routes/web.php`, `app/Models/User.php`,
   `app/Models/Profile.php`, `resources/views/components/titan-layout.blade.php`,
   `database/seeders/DatabaseSeeder.php`, `config/services.php`, `composer.json`, `vite.config.js`.
   Everything you need from them is already wired (see below).
3. **Do NOT run** `php artisan migrate`, `npm run build`, `composer require`, `db:seed`, or
   restart containers. Write files only. The orchestrator integrates + migrates + builds + tests.
4. **Write plain PHP files directly** — do not use `artisan make:*` (avoids filesystem races).
5. Keep your final message SHORT: list files created + one-line status + anything the
   orchestrator must know. Do not paste file contents back.

## The hub: Profile
Every user has one `App\Models\Profile` (the seeded duo: profile **1 = Alex**, **2 = Bro**).
All your tables get a `profile_id` foreign key. Get the current profile in controllers with:
```php
$profile = auth()->user()->ensureProfile();
```
`Profile` ALREADY declares the hasMany relationship to your model(s) — see the canonical
model names below. Just match those class names and the wiring works.

## Canonical model names (match exactly — Profile already references them)
| Domain | Models (App\Models\*) | profile relation |
|---|---|---|
| brain | `KnowledgePage` | `knowledgePages()` |
| biomarkers | `BiomarkerReading` (+ marker catalog as you see fit) | `biomarkerReadings()` |
| body | `BodyMetric` | `bodyMetrics()` |
| meals | `Meal`, `MealItem` | `meals()` |
| workouts | `Exercise`, `Workout`, `WorkoutExercise`, `WorkoutSet` | `workouts()` |
| sleep/recovery | `SleepLog`, `RecoveryLog` | `sleepLogs()`, `recoveryLogs()` |
| physique | `ProgressPhoto`, `PhysiqueGoal`, `PhysiqueAnalysis` | `progressPhotos()`, `physiqueGoals()` |
| coach | `Conversation`, `ChatMessage` | `conversations()` |

## Migration timestamp prefixes (use YOUR range so filenames never collide)
profiles already exists at `2026_06_15_000000`. Use:
- brain `2026_06_15_0100xx` · biomarkers `0200xx` · body `0300xx` · meals `0400xx`
- workouts `0500xx` · sleep/recovery `0600xx` · physique `0700xx` · coach `0800xx`

(e.g. brain's first migration = `2026_06_15_010000_create_knowledge_pages_table.php`.)

## UI: use the app shell
Every page renders inside the shared dark layout:
```blade
<x-titan-layout title="Bloodwork" subtitle="optional small text">
    {{-- your content; Tailwind v4 utilities; Alpine.js is available globally --}}
</x-titan-layout>
```
The sidebar nav is already wired to `/<domain>` for every domain — your index route must
live at `/<domain>` (e.g. `/biomarkers`). Match the existing dark aesthetic: `bg-gray-900/50`
cards, `border-white/5`, indigo/cyan accents, `text-gray-100/400/500`. Look at
`resources/views/dashboard.blade.php` for the established style.

## AI services (already built — inject via constructor)
- `App\Services\Ai\AiService` — OpenAI. Methods:
  - `chat(array $messages, array $opts=[]): string`
  - `json(array $messages, array $opts=[]): array` (forces JSON object)
  - `vision(string $prompt, array $imageUrls, array $opts=[]): string` — image understanding.
    `$imageUrls` are data: URLs or https URLs. Use for meal photos & physique analysis.
  - `chatWithTools(array $messages, array $tools, callable $dispatch, array $opts=[]): string`
  - `embed(array $texts): array` / `embedOne(string): array` — for semantic search.
  - `transcribe(string $bytes, string $filename): ?string` — Whisper.
  - config: `config('services.openai.chat_model'|'vision_model'|'fast_model'|'embed_model')`.
- `App\Services\Ai\NanoBananaClient` — Gemini "Nano Banana 2" image gen. Methods:
  - `generateToDisk(string $prompt, string $dir='physique', array $inputImages=[]): array` →
    `['path','url','mime']` saved to the **public** disk.
  - `generate(string $prompt, array $inputImages=[]): array` → `['bytes','mime']`.
  - `imageFromDisk(string $path, string $disk='public'): array` → `['bytes','mime']` to feed back in.
  - `$inputImages` = `[['bytes'=>..., 'mime'=>...]]`. Pass the user's photo here for
    image+text→image (dream physique, progress morph).
- `App\Exceptions\AiException` is thrown on AI failure — catch at controller boundary, show a
  friendly message. AI may be unconfigured/offline; degrade gracefully (don't hard-crash pages).

## To port from the reference app (`~/Documents/alex/fullstack-labs/fullstack-suite`)
- brain: `app/Models/KnowledgePage.php`, `app/Services/Assistant/KnowledgeSearch.php`,
  `app/Services/Assistant/KnowledgeIngestor.php`, migrations `*_create_knowledge_pages_table`
  + `*_add_embedding_to_knowledge_pages`, `app/Services/Documents/{DocumentText,PdfService}.php`.
  Adapt: replace `tenant_id`→`profile_id`, `Tenant`→`Profile`, drop CRM bits (contacts), and
  use `config('services.openai.*')` keys from THIS app. Put services under `App\Services\Brain\`
  and `App\Services\Documents\`. `smalot/pdfparser` is already installed.
- Everyone: copy the plain-`Http` style of `App\Services\Ai\AiService` for any new HTTP work.

## Storage
Public disk is linked (`storage/app/public` → `public/storage`). Save uploads/generated
images to the `public` disk; reference with `Storage::disk('public')->url($path)`.

## Charts
For trend charts, use a small client lib via CDN in your Blade (e.g. Chart.js from jsDelivr)
driven by Alpine, OR render simple inline SVG sparklines. Don't add npm/composer deps.

## Done = 
Migration(s) + model(s) + controller(s) + Blade page(s) at `/<domain>` + routes in your
route file, matching the conventions above. Seed a few sample rows for profile 1 (Alex) via a
**domain seeder class** you create (orchestrator will call it) — do NOT edit DatabaseSeeder.
Lint your PHP mentally; the orchestrator runs `php -l`, migrates, builds, and browser-tests.
