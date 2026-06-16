<?php

namespace App\Http\Controllers\Health;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Models\BiomarkerReading;
use App\Models\Profile;
use App\Services\Ai\AiService;
use App\Support\Biomarkers;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Bloodwork: trend cards + manual entry + the killer "upload a lab PDF, get
 * structured + flagged markers back" flow. Degrades gracefully when the document
 * extractor / brain ingestor / AI aren't available at runtime (parallel builds).
 */
class BiomarkerController extends Controller
{
    public function __construct(private readonly AiService $ai) {}

    /** /biomarkers — latest value per marker, trends, and the add/upload forms. */
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $readings = $profile->biomarkerReadings()
            ->orderBy('taken_at')
            ->orderBy('id')
            ->get()
            ->groupBy('marker');

        // Build one card per catalog marker, in catalog order, with its series.
        $cards = [];
        foreach (Biomarkers::all() as $key => $def) {
            $series = $readings->get($key);
            $latest = $series?->last();
            $cards[] = [
                'key' => $key,
                'def' => $def,
                'latest' => $latest,
                'range' => Biomarkers::rangeLabel($key),
                'points' => $series
                    ? $series->map(fn ($r) => [
                        'date' => $r->taken_at->format('Y-m-d'),
                        'value' => (float) $r->value,
                        'flag' => $r->flag,
                    ])->values()->all()
                    : [],
            ];
        }

        // Out-of-range markers float to the top; tracked-before markers next; untracked last.
        usort($cards, function ($a, $b) {
            $rank = fn ($c) => match (true) {
                $c['latest'] && in_array($c['latest']->flag, ['low', 'high'], true) => 0,
                (bool) $c['latest'] => 1,
                default => 2,
            };

            return $rank($a) <=> $rank($b);
        });

        // PhenoAge "biological-age clock" panel: which of the 9 markers are in, what's missing,
        // and the computed biological age — the thing that turns a blood upload into a real clock.
        $phenoMarkers = \App\Support\PhenoAge::requiredMarkers();
        $phenoStatus = array_map(function ($key) use ($readings) {
            $latest = $readings->get($key)?->last();
            return [
                'key' => $key, 'label' => Biomarkers::label($key),
                'have' => $latest !== null,
                'value' => $latest ? rtrim(rtrim(number_format((float) $latest->value, 2, '.', ''), '0'), '.') : null,
                'unit' => $latest?->unit,
            ];
        }, $phenoMarkers);

        return view('biomarkers.index', [
            'profile' => $profile,
            'cards' => $cards,
            'catalog' => Biomarkers::all(),
            'parsed' => session('parsed'),       // confirmation set after an upload
            'parsedMeta' => session('parsedMeta'),
            'phenoStatus' => $phenoStatus,
            'phenoHave' => count(array_filter($phenoStatus, fn ($s) => $s['have'])),
            'bioAge' => \App\Support\BiologicalAge::assess($profile),
        ]);
    }

    /** Manual single-reading entry. */
    public function store(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $data = $request->validate([
            'marker' => ['required', 'string'],
            'value' => ['required', 'numeric'],
            'taken_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! Biomarkers::has($data['marker'])) {
            return back()->withErrors(['marker' => 'Unknown marker.'])->withInput();
        }

        $profile->biomarkerReadings()->create([
            'marker' => $data['marker'],
            'value' => $data['value'],
            'unit' => Biomarkers::unit($data['marker']),
            'taken_at' => $data['taken_at'],
            'source' => 'manual',
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('status', Biomarkers::label($data['marker']).' reading saved.');
    }

    public function destroy(Request $request, BiomarkerReading $reading)
    {
        abort_unless($reading->profile_id === $request->user()->ensureProfile()->id, 403);
        $reading->delete();

        return back()->with('status', 'Reading deleted.');
    }

    /**
     * Upload a lab-report PDF (or image/text): extract text, ask the LLM to pull
     * every recognised marker into structured rows, save them (source=lab_upload)
     * with computed flags, and file a narrative summary into the brain.
     */
    public function upload(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $request->validate([
            'report' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,txt', 'max:20480'],
        ]);

        $file = $request->file('report');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        // 1) Extract text from the document (guard: extractor may not exist yet).
        $text = $this->extractText($file->getRealPath(), $ext);
        if ($text === null || trim($text) === '') {
            return back()->withErrors([
                'report' => 'Could not read text from that file. Try a text-based PDF, or add readings manually below.',
            ]);
        }

        // 2) Ask the LLM to structure the markers.
        try {
            $rows = $this->extractMarkers($text);
        } catch (AiException $e) {
            Log::warning('[biomarkers] AI extraction failed', ['error' => $e->getMessage()]);

            return back()->withErrors([
                'report' => 'The AI parser is unavailable right now ('.$e->getMessage().'). You can add readings manually below.',
            ]);
        }

        if ($rows === []) {
            return back()->withErrors([
                'report' => 'No recognised biomarkers were found in that report. Try adding them manually below.',
            ]);
        }

        // 3) Persist as lab_upload readings with computed flags.
        $saved = $this->persistRows($profile, $rows);
        if ($saved === []) {
            return back()->withErrors([
                'report' => 'The report was parsed but none of the values mapped to tracked markers.',
            ]);
        }

        // 4) Coach reads the labs: a prioritised "what's off / what to work on" assessment.
        $assessment = $this->buildAssessment($saved);

        // 5) File a narrative summary into the brain (guard: ingestor may not exist).
        $this->ingestNarrative($profile, $saved, $assessment);

        return back()->with([
            'status' => count($saved).' marker(s) imported from your lab report.',
            'parsed' => array_map(fn ($r) => [
                'label' => Biomarkers::label($r->marker),
                'value' => (float) $r->value,
                'unit' => $r->unit,
                'flag' => $r->flag,
                'taken_at' => $r->taken_at->format('M j, Y'),
            ], $saved),
            'parsedMeta' => ['count' => count($saved)],
            'assessment' => $assessment,
        ]);
    }

    /**
     * Coach assessment of the imported labs: a prioritised, plain-English read of
     * what's off and what to do about it, plus the wins. Best-effort — returns null
     * if the AI is unavailable so the import itself still succeeds.
     *
     * @param  array<int,BiomarkerReading>  $saved
     * @return array{headline:string,concerns:array<int,array<string,string>>,wins:array<int,string>,priorities:array<int,string>}|null
     */
    private function buildAssessment(array $saved): ?array
    {
        $lines = array_map(function ($r) {
            $range = Biomarkers::rangeLabel($r->marker);

            return sprintf(
                '%s: %s %s [flag: %s; optimal %s]',
                Biomarkers::label($r->marker),
                rtrim(rtrim(number_format((float) $r->value, 2, '.', ''), '0'), '.'),
                $r->unit,
                $r->flag ?? 'n/a',
                $range ?: 'n/a',
            );
        }, $saved);

        $system = <<<SYS
        You are Titan — a sharp, encouraging longevity & performance coach reading a
        man's bloodwork. You are NOT a doctor and never prescribe drugs or doses; you
        speak in training, nutrition, sleep, lifestyle and "ask your doctor about X"
        terms. Be specific, prioritised, and motivating — no vague hedging.

        Return STRICT JSON:
        {
          "headline": "one punchy sentence on the overall picture",
          "concerns": [
            {"marker":"<label>","reading":"<value + unit>","why":"<one plain sentence on why it matters>","action":"<one concrete first step>"}
          ],
          "wins": ["<short praise for an optimal / strong marker>"],
          "priorities": ["<the 1-3 things to attack first, ordered>"]
        }

        Rules: concerns ordered worst-first; include only markers genuinely off
        (low/high, or a borderline-normal worth watching); 1-5 concerns; 1-4 wins;
        1-3 priorities. Keep every string tight.
        SYS;

        try {
            $result = $this->ai->json([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "MARKERS:\n".implode("\n", $lines)],
            ], ['temperature' => 0.3, 'max_tokens' => 1200]);
        } catch (AiException $e) {
            Log::info('[biomarkers] assessment skipped', ['error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($result) || ! is_string($result['headline'] ?? null)) {
            return null;
        }

        return [
            'headline' => (string) $result['headline'],
            'concerns' => array_values(array_filter(
                (array) ($result['concerns'] ?? []),
                fn ($c) => is_array($c) && isset($c['marker']),
            )),
            'wins' => array_values(array_filter((array) ($result['wins'] ?? []), 'is_string')),
            'priorities' => array_values(array_filter((array) ($result['priorities'] ?? []), 'is_string')),
        ];
    }

    /** Extract document text via the Brain build's DocumentText service, if present. */
    private function extractText(string $absPath, string $ext): ?string
    {
        $class = 'App\\Services\\Documents\\DocumentText';
        if (class_exists($class) && method_exists($class, 'extract')) {
            try {
                return app($class)->extract($absPath, $ext);
            } catch (\Throwable $e) {
                Log::warning('[biomarkers] DocumentText::extract failed', ['error' => $e->getMessage()]);

                return null;
            }
        }

        // Fallback: plain-text uploads still work without the extractor.
        if ($ext === 'txt') {
            return @file_get_contents($absPath) ?: null;
        }

        return null;
    }

    /**
     * LLM extraction: returns a list of ['marker'=>key,'value'=>float,'unit'=>?,'date'=>?Y-m-d].
     *
     * @return array<int,array{marker:string,value:float,unit:?string,date:?string}>
     */
    private function extractMarkers(string $text): array
    {
        $catalog = collect(Biomarkers::all())
            ->map(fn ($d, $k) => "- {$k}: {$d['label']} ({$d['unit']})")
            ->values()
            ->implode("\n");

        // Keep the prompt bounded; lab PDFs can be long.
        $snippet = mb_substr($text, 0, 12000);

        $system = <<<SYS
        You are a clinical lab-report parser. Extract every biomarker present in the
        report that matches one of the supported markers below. Map each to its exact
        catalog KEY. Convert the reported value to the catalog unit when it is a trivial
        unit match; otherwise keep the reported numeric value and report its unit.

        Supported markers (key: label (unit)):
        {$catalog}

        Return STRICT JSON of the shape:
        {"date":"YYYY-MM-DD or null","markers":[{"marker":"<key>","value":<number>,"unit":"<unit or null>"}]}

        Rules:
        - Only include markers from the supported list. Skip anything else.
        - "date" is the collection/draw date of the report if present, else null.
        - value must be a plain number (no ranges, no units, no commas).
        - If a marker appears more than once, use the most recent / primary result.
        SYS;

        $result = $this->ai->json([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "LAB REPORT TEXT:\n\n".$snippet],
        ], ['temperature' => 0, 'max_tokens' => 1500]);

        $date = is_string($result['date'] ?? null) ? $this->normalizeDate($result['date']) : null;
        $out = [];

        foreach (($result['markers'] ?? []) as $m) {
            $rawKey = (string) ($m['marker'] ?? '');
            $key = Biomarkers::resolveKey($rawKey);
            if ($key === null || ! is_numeric($m['value'] ?? null)) {
                continue;
            }
            $out[$key] = [
                'marker' => $key,
                'value' => (float) $m['value'],
                'unit' => isset($m['unit']) && is_string($m['unit']) && $m['unit'] !== '' ? $m['unit'] : Biomarkers::unit($key),
                'date' => $date,
            ];
        }

        return array_values($out);
    }

    /**
     * @param  array<int,array{marker:string,value:float,unit:?string,date:?string}>  $rows
     * @return array<int,BiomarkerReading>
     */
    private function persistRows(Profile $profile, array $rows): array
    {
        $saved = [];
        foreach ($rows as $row) {
            $takenAt = $row['date'] ? Carbon::parse($row['date']) : now();
            $saved[] = $profile->biomarkerReadings()->create([
                'marker' => $row['marker'],
                'value' => $row['value'],
                'unit' => $row['unit'],
                'taken_at' => $takenAt->toDateString(),
                'source' => 'lab_upload',
                // flag computed in model booted() hook
            ]);
        }

        return $saved;
    }

    /** Write a short bloodwork narrative page into the brain, if the ingestor exists. */
    private function ingestNarrative(Profile $profile, array $saved, ?array $assessment = null): void
    {
        $class = 'App\\Services\\Brain\\KnowledgeIngestor';
        if (! class_exists($class)) {
            return;
        }

        $date = $saved[0]->taken_at->format('M j, Y');
        $lines = array_map(
            fn ($r) => Biomarkers::label($r->marker).' '.rtrim(rtrim(number_format((float) $r->value, 2, '.', ''), '0'), '.')
                .' '.$r->unit.' ('.($r->flag ?? 'n/a').')',
            $saved,
        );
        $summary = "Bloodwork from {$date}: ".implode(', ', $lines).'.';

        if ($assessment) {
            $summary .= "\n\nCoach read: ".$assessment['headline'];
            if (! empty($assessment['priorities'])) {
                $summary .= "\nFocus: ".implode('; ', $assessment['priorities']).'.';
            }
        }

        try {
            $ingestor = app($class);
            // Be tolerant of the brain build's eventual method name.
            foreach (['ingestText', 'ingest', 'capture', 'addNote', 'ingestNote'] as $method) {
                if (method_exists($ingestor, $method)) {
                    $ingestor->{$method}($profile, $summary, "Bloodwork {$date}");

                    return;
                }
            }
        } catch (\Throwable $e) {
            Log::info('[biomarkers] brain ingest skipped', ['error' => $e->getMessage()]);
        }
    }

    /** Best-effort date normalisation to Y-m-d; null if unparseable. */
    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'null') {
            return null;
        }
        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
