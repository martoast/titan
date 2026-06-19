<?php

namespace App\Services\Workouts;

use App\Models\Exercise;
use App\Services\Ai\AiService;
use Illuminate\Support\Str;

/**
 * Identifies a gym exercise from a photo -- the machine itself, its instruction
 * placard/label, or free-weight setup -- using OpenAI vision, then matches it to
 * the shared Exercise library (or creates a new entry). Powers the real-time
 * "snap the machine" workout-logging flow: the user photographs what they're on
 * instead of having to know the exact exercise name.
 */
class ExerciseIdentifier
{
    public function __construct(protected AiService $ai) {}

    public function configured(): bool
    {
        return $this->ai->configured();
    }

    /**
     * Vision-identify the exercise in an image (passed as a data: or https URL).
     *
     * @return array{name:string,muscle_group:string,category:string,equipment:?string,confidence:string,alternates:array<int,string>,note:string}
     */
    public function identify(string $imageUrl): array
    {
        $prompt = <<<'PROMPT'
        You are a gym equipment expert. This photo shows ONE of: a weight machine, the
        instruction placard/label on a machine, a cable/free-weight setup, or a person mid-rep.
        Identify the single most likely strength exercise being performed or that this station
        is for.

        Return ONLY strict JSON, no prose:
        {
          "name": "canonical exercise name, e.g. 'Leg Press' or 'Lat Pulldown'",
          "muscle_group": "primary group: chest|back|legs|shoulders|arms|core|cardio|full body",
          "category": "compound|isolation|cardio",
          "equipment": "machine|cable|barbell|dumbbell|bodyweight|smith machine or null",
          "confidence": "high|medium|low",
          "alternates": ["up to 3 other plausible exercise names"],
          "note": "one short sentence on what you saw (e.g. 'Read the placard: Hammer Strength Iso-Lateral Row')"
        }
        If you genuinely cannot tell, set name to "" and confidence to "low".
        PROMPT;

        $raw = $this->ai->vision($prompt, [$imageUrl], ['json' => true, 'max_tokens' => 400, 'temperature' => 0]);
        $data = $this->decode($raw);

        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'muscle_group' => $this->normalizeGroup((string) ($data['muscle_group'] ?? '')),
            'category' => in_array($data['category'] ?? '', Exercise::CATEGORIES, true) ? $data['category'] : 'compound',
            'equipment' => ($e = trim((string) ($data['equipment'] ?? ''))) !== '' && strtolower($e) !== 'null' ? $e : null,
            'confidence' => in_array($data['confidence'] ?? '', ['high', 'medium', 'low'], true) ? $data['confidence'] : 'low',
            'alternates' => array_values(array_filter(array_map('trim', (array) ($data['alternates'] ?? [])))),
            'note' => trim((string) ($data['note'] ?? '')),
        ];
    }

    /**
     * Resolve an identified exercise to a library row: exact slug match, then a
     * fuzzy contains match, else create a new library entry. Returns [Exercise, bool $created].
     *
     * @param  array{name:string,muscle_group:string,category:string,equipment:?string}  $identified
     * @return array{0:Exercise,1:bool}
     */
    public function matchOrCreate(array $identified): array
    {
        $name = trim($identified['name']);
        if ($name === '') {
            // Nothing recognized -- a generic placeholder the user will rename.
            $name = 'Unidentified exercise';
        }

        $slug = Exercise::slugFor($name);

        // 1) exact slug.
        if ($hit = Exercise::where('slug', $slug)->first()) {
            return [$hit, false];
        }

        // 2) fuzzy: library name contained in the guess or vice-versa (case-insensitive).
        $needle = mb_strtolower($name);
        $fuzzy = Exercise::all()->first(function (Exercise $e) use ($needle) {
            $lib = mb_strtolower($e->name);

            return str_contains($needle, $lib) || str_contains($lib, $needle);
        });
        if ($fuzzy) {
            return [$fuzzy, false];
        }

        // 3) create a new library entry.
        $exercise = Exercise::create([
            'name' => Str::limit($name, 120, ''),
            'slug' => $slug,
            'muscle_group' => $identified['muscle_group'] ?: 'full body',
            'category' => $identified['category'] ?: 'compound',
            'equipment' => $identified['equipment'] ?? null,
        ]);

        return [$exercise, true];
    }

    /** Parse the model's JSON, tolerating ```json fences / surrounding prose. */
    private function decode(string $raw): array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw) ?? $raw;
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Fallback: first {...} block.
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $decoded = json_decode($m[0], true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeGroup(string $g): string
    {
        $g = mb_strtolower(trim($g));
        $allowed = ['chest', 'back', 'legs', 'shoulders', 'arms', 'core', 'cardio', 'full body'];

        return in_array($g, $allowed, true) ? $g : 'full body';
    }
}
