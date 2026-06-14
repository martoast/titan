<?php

namespace App\Services\Brain;

use App\Exceptions\AiException;
use App\Models\KnowledgePage;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiService;
use Illuminate\Support\Str;

/**
 * Turns a raw "brain dump" (the user pasting everything about their health, or text
 * extracted from an uploaded lab report / document) into a clean, organized set of
 * wiki pages — the AI acting as the personal-health LIBRARIAN. Append-safe by
 * contract: it only CREATES new pages or APPENDS to existing ones (matched by slug),
 * never silently overwrites. Each touched page is re-embedded so search works
 * immediately.
 */
class KnowledgeIngestor
{
    /** Cap the dump so a giant paste can't blow the model's context. */
    private const MAX_CHARS = 24000;

    private const MAX_PAGES = 24;

    public function __construct(
        protected AiService $ai,
        protected KnowledgeSearch $search,
    ) {}

    /**
     * Organize $dump into pages for $profile. Returns a summary:
     * ['created' => [...], 'updated' => [...], 'message' => string].
     *
     * @return array{created:array<int,array<string,mixed>>,updated:array<int,array<string,mixed>>,message:string}
     */
    public function ingest(Profile $profile, User $user, string $dump): array
    {
        $dump = trim($dump);
        if ($dump === '') {
            return ['created' => [], 'updated' => [], 'message' => 'Nothing to organize — the note was empty.'];
        }
        if (! $this->ai->configured()) {
            throw new AiException('The Brain AI is not configured (missing OPENAI_API_KEY).');
        }
        $dump = Str::limit($dump, self::MAX_CHARS, '');

        $existing = KnowledgePage::query()->where('profile_id', $profile->id)
            ->get(['id', 'title', 'slug', 'type']);
        $index = $existing->isEmpty()
            ? '(the wiki is empty)'
            : $existing->map(fn ($p) => "- {$p->slug} — \"{$p->title}\" [{$p->type}]")->implode("\n");

        $plan = $this->ai->json([
            ['role' => 'system', 'content' => $this->systemPrompt($index)],
            ['role' => 'user', 'content' => "Organize this into the wiki:\n\n".$dump],
        ], ['model' => config('services.openai.chat_model'), 'temperature' => 0.2, 'max_tokens' => 4000]);

        $pages = array_slice((array) ($plan['pages'] ?? []), 0, self::MAX_PAGES);
        $created = [];
        $updated = [];

        foreach ($pages as $spec) {
            $title = trim((string) ($spec['title'] ?? ''));
            $body = trim((string) ($spec['content'] ?? ''));
            if ($title === '' || $body === '') {
                continue;
            }
            $type = in_array($spec['type'] ?? '', KnowledgePage::TYPES, true) ? $spec['type'] : 'note';
            $wantPinned = ! empty($spec['pinned']);

            // Resolve the target: an explicit existing slug, else by title slug.
            $slug = Str::slug((string) ($spec['existing_slug'] ?? '')) ?: KnowledgePage::slugFor($title);
            $page = KnowledgePage::query()->where('profile_id', $profile->id)->where('slug', $slug)->first();

            if ($page) {
                // APPEND — never overwrite. Skip if this exact text is already present.
                if (! str_contains((string) $page->content, $body)) {
                    $page->content = trim((string) $page->content)."\n\n".$body;
                }
                $page->is_pinned = $page->is_pinned || $wantPinned;
                $page->updated_by_user_id = $user->id;
                $page->save();
                $this->search->embedPage($page);
                $updated[] = $this->row($page);
            } else {
                $page = KnowledgePage::query()->create([
                    'profile_id' => $profile->id,
                    'title' => Str::limit($title, 160, ''),
                    'slug' => $slug,
                    'type' => $type,
                    'content' => $body,
                    'is_pinned' => $wantPinned,
                    'updated_by_user_id' => $user->id,
                ]);
                $this->search->embedPage($page);
                $created[] = $this->row($page);
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'message' => $this->summary($created, $updated),
        ];
    }

    private function systemPrompt(string $index): string
    {
        return <<<PROMPT
        You are the knowledge LIBRARIAN for a person's long-term PERSONAL HEALTH wiki. The user pastes a
        raw brain dump — notes about their training, nutrition, sleep, bloodwork, goals, injuries, and
        family history, or text extracted from a lab report or document. Your job is to organize it into
        clean, well-structured wiki pages that an AI health coach can actually use to get smarter about
        this person over time — not a single wall of text.

        Return ONLY JSON: {"pages": [{"title", "type", "action", "existing_slug", "content", "pinned"}]}

        Rules:
        - Split the dump into COHERENT topics, one page each. Good topic examples for a health wiki:
          "Profile Overview", "Goals", "Training History", "Nutrition Preferences", "Bloodwork Notes",
          "Injuries", "Sleep & Recovery", "Family History", "Supplements", "Medications". Aim for a
          handful of focused pages, not dozens of tiny ones and not one giant page.
        - "type" is one of: note, concept, overview, entity (entity = about a specific person such as a
          doctor or family member).
        - "action": "append" to add to an EXISTING page when the dump expands a topic already in the wiki
          (set "existing_slug" to that page's slug, from the list below); otherwise "create".
        - REUSE existing pages by slug rather than creating near-duplicates. When unsure, append.
        - "content" is clean Markdown: short intro, then bullet points / sections. Preserve every concrete
          specific (dates, numbers, lab values + units, exercise weights, doctor names). Do NOT invent
          facts not in the dump. Do NOT give medical advice or diagnoses — just file what was said.
        - Cross-link related pages with [[Page Title]] where natural.
        - "pinned": true only for the 1–2 most foundational pages (e.g. a "Profile Overview" or "Goals").

        Existing wiki pages (slug — "title" [type]):
        {$index}
        PROMPT;
    }

    private function row(KnowledgePage $page): array
    {
        return ['id' => $page->id, 'title' => $page->title, 'slug' => $page->slug, 'type' => $page->type];
    }

    private function summary(array $created, array $updated): string
    {
        $parts = [];
        if ($created !== []) {
            $parts[] = count($created).' new page'.(count($created) === 1 ? '' : 's').' ('.implode(', ', array_map(fn ($p) => $p['title'], $created)).')';
        }
        if ($updated !== []) {
            $parts[] = count($updated).' page'.(count($updated) === 1 ? '' : 's').' updated ('.implode(', ', array_map(fn ($p) => $p['title'], $updated)).')';
        }

        return $parts === [] ? "I couldn't find anything to organize into pages." : 'Organized into '.implode(' and ', $parts).'.';
    }
}
