<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\StackItem;
use App\Services\Stack\InteractionChecker;
use App\Services\Stack\StackScanService;
use App\Services\Stack\SupplementCatalog;
use App\Support\Stack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native-app "What you take" surface. Same StackItem / IntakeEvent rows the coach's my_stack /
 * log_intake tools read and the web view renders. Returns STRUCTURED JSON: today's checklist
 * card, the full protocol, and the (informational) interaction flags.
 */
class MobileStackController extends Controller
{
    /** Pinned everywhere interaction info appears — keeps us inside informational/general-wellness framing. */
    public const DISCLAIMER = 'Informational, not medical advice — check with your pharmacist or clinician.';

    public function __construct(
        protected SupplementCatalog $catalog,
        protected InteractionChecker $interactions,
        protected StackScanService $scan,
    ) {}

    /** Today's card + the full stack + cached interaction flags. */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->profile($request)));
    }

    /** Type-ahead catalog search (supplements via DSLD, meds via RxNorm, + built-ins). */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:80']]);

        return response()->json(['results' => $this->catalog->search((string) $request->string('q'))]);
    }

    /** Snap one bottle (mode=single) or the whole shelf (mode=shelf) → candidates to confirm. */
    public function scanPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'mode' => ['nullable', 'in:single,shelf'],
        ]);
        $res = $this->scan->scan($this->profile($request), $request->file('photo'), (string) $request->input('mode', 'single'));

        return response()->json($res);
    }

    /** Add an item to the stack. */
    public function store(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $item = $profile->stackItems()->create($this->validateItem($request, true));
        $this->interactions->refresh($profile);

        return response()->json(['item' => $this->itemJson($item)] + $this->payload($profile));
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $profile = $this->profile($request);
        $row = $profile->stackItems()->findOrFail($item);
        $row->update($this->validateItem($request, false));
        $this->interactions->refresh($profile);

        return response()->json(['item' => $this->itemJson($row->fresh())] + $this->payload($profile));
    }

    public function destroy(Request $request, int $item): JsonResponse
    {
        $profile = $this->profile($request);
        $profile->stackItems()->findOrFail($item)->delete();
        $this->interactions->refresh($profile);

        return response()->json(['ok' => true] + $this->payload($profile));
    }

    /** Log a scheduled dose taken/skipped (one tap on the daily card). */
    public function logItem(Request $request, int $item): JsonResponse
    {
        $profile = $this->profile($request);
        $row = $profile->stackItems()->findOrFail($item);
        $d = $request->validate([
            'status' => ['nullable', 'in:taken,skipped,extra'],
            'slot' => ['nullable', 'in:morning,midday,evening,night,anytime'],
            'taken_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:280'],
        ]);

        $event = $profile->intakeEvents()->create([
            'stack_item_id' => $row->id,
            'name' => $row->name,
            'kind' => $row->kind,
            'dose_amount' => $row->dose_amount,
            'dose_unit' => $row->dose_unit,
            'taken_at' => $d['taken_at'] ?? now(),
            'status' => $d['status'] ?? 'taken',
            'source' => 'manual',
            'slot' => $d['slot'] ?? null,
            'notes' => $d['notes'] ?? null,
        ]);

        return response()->json(['event_id' => $event->id] + $this->payload($profile));
    }

    /** Log a one-off dose with no parent item ("I just took an ibuprofen"). */
    public function logOneOff(Request $request): JsonResponse
    {
        $profile = $this->profile($request);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['nullable', 'in:supplement,medication,other'],
            'dose_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'dose_unit' => ['nullable', 'string', 'max:20'],
            'taken_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:280'],
        ]);

        $event = $profile->intakeEvents()->create([
            'name' => $d['name'],
            'kind' => $d['kind'] ?? 'supplement',
            'dose_amount' => $d['dose_amount'] ?? null,
            'dose_unit' => $d['dose_unit'] ?? null,
            'taken_at' => $d['taken_at'] ?? now(),
            'status' => 'taken',
            'source' => 'manual',
            'notes' => $d['notes'] ?? null,
        ]);

        return response()->json(['event_id' => $event->id] + $this->payload($profile));
    }

    /** Undo a logged dose (un-tap). */
    public function undo(Request $request, int $event): JsonResponse
    {
        $profile = $this->profile($request);
        $profile->intakeEvents()->findOrFail($event)->delete();

        return response()->json(['ok' => true] + $this->payload($profile));
    }

    /** Recompute + return the interaction flags (the "worth knowing" view). */
    public function interactionList(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        return response()->json([
            'flags' => $this->interactions->refresh($profile)->map(fn ($f) => $this->flagJson($f))->values(),
            'disclaimer' => self::DISCLAIMER,
        ]);
    }

    // ----- helpers ----------------------------------------------------------

    private function profile(Request $request): Profile
    {
        return $request->user()->profile ?? $request->user()->ensureProfile();
    }

    /** @return array<string,mixed> */
    private function payload(Profile $profile): array
    {
        return [
            'today' => Stack::today($profile),
            'items' => $profile->stackItems()->orderByDesc('active')->orderBy('kind')->orderBy('name')->get()
                ->map(fn (StackItem $i) => $this->itemJson($i))->values(),
            'flags' => $profile->interactionFlags()->get()->sortBy(fn ($f) => $f->rank())->values()
                ->map(fn ($f) => $this->flagJson($f)),
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /** @return array<string,mixed> */
    private function validateItem(Request $request, bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:80'],
            'kind' => ['sometimes', 'in:supplement,medication,other'],
            'brand' => ['nullable', 'string', 'max:80'],
            'dose_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'dose_unit' => ['nullable', 'string', 'max:20'],
            'form' => ['nullable', 'string', 'max:30'],
            'schedule' => ['nullable', 'array'],
            'schedule.frequency' => ['nullable', 'in:daily,specific_days,as_needed'],
            'schedule.times' => ['nullable', 'array'],
            'schedule.times.*' => ['in:morning,midday,evening,night,anytime'],
            'schedule.days' => ['nullable', 'array'],
            'schedule.days.*' => ['string', 'max:3'],
            'schedule.with_food' => ['nullable', 'boolean'],
            'dsld_id' => ['nullable', 'string', 'max:60'],
            'rxcui' => ['nullable', 'string', 'max:30'],
            'active' => ['sometimes', 'boolean'],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $data;
    }

    /** @return array<string,mixed> */
    private function itemJson(StackItem $i): array
    {
        return [
            'id' => $i->id,
            'name' => $i->name,
            'kind' => $i->kind,
            'brand' => $i->brand,
            'dose_amount' => $i->dose_amount !== null ? (float) $i->dose_amount : null,
            'dose_unit' => $i->dose_unit,
            'dose_label' => $i->doseLabel(),
            'form' => $i->form,
            'schedule' => $i->schedule,
            'slots' => $i->slots(),
            'active' => (bool) $i->active,
            'photo_url' => $i->photoUrl(),
            'adherence' => $i->adherencePct(),
            'notes' => $i->notes,
        ];
    }

    /** @return array<string,mixed> */
    private function flagJson(\App\Models\InteractionFlag $f): array
    {
        return [
            'id' => $f->id,
            'a' => $f->a_name,
            'b' => $f->b_name,
            'severity' => $f->severity,
            'summary' => $f->summary,
            'source' => $f->source,
        ];
    }
}
