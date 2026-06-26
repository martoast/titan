<?php

namespace App\Http\Controllers\Stack;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Services\Stack\InteractionChecker;
use App\Services\Stack\StackScanService;
use App\Services\Stack\SupplementCatalog;
use App\Support\Stack;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "What you take" — the web page (sibling of /meals). Hero is today's checklist; below it the
 * full protocol and the informational "worth knowing" interactions. Type-ahead search and
 * snap-a-bottle are AJAX; logging a dose and editing items post back and re-render.
 */
class StackController extends Controller
{
    public function __construct(
        protected SupplementCatalog $catalog,
        protected InteractionChecker $interactions,
        protected StackScanService $scan,
    ) {}

    public function index(Request $request): View
    {
        $profile = $this->profile($request);
        $items = $profile->stackItems()->orderByDesc('active')->orderBy('name')->get();

        // Avoid the N+1 when rendering the protocol: share the loaded profile onto each item and
        // batch the taken-dose counts, then resolve adherence once per item up front.
        $items->each->setRelation('profile', $profile);
        $counts = \App\Models\StackItem::takenCounts($items);
        $adherence = $items->mapWithKeys(fn ($i) => [$i->id => $i->adherencePct(14, $counts[$i->id] ?? 0)])->all();

        return view('stack.index', [
            'today' => Stack::today($profile),
            'supplements' => $items->where('kind', 'supplement')->values(),
            'medications' => $items->where('kind', 'medication')->values(),
            'others' => $items->whereNotIn('kind', ['supplement', 'medication'])->values(),
            'adherence' => $adherence,
            'flags' => $profile->interactionFlags()->get()->sortBy(fn ($f) => $f->rank())->values(),
            'disclaimer' => \App\Http\Controllers\Api\MobileStackController::DISCLAIMER,
        ]);
    }

    /** AJAX type-ahead. */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:80']]);

        return response()->json(['results' => $this->catalog->search((string) $request->string('q'))]);
    }

    /** AJAX snap-a-bottle / snap-the-shelf. */
    public function scanPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'mode' => ['nullable', 'in:single,shelf'],
        ]);

        return response()->json(
            $this->scan->scan($this->profile($request), $request->file('photo'), (string) $request->input('mode', 'single'))
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);
        $profile->stackItems()->create($this->validateItem($request, true));
        $this->interactions->refresh($profile);

        return redirect()->route('stack.index')->with('status', 'Added to your stack.');
    }

    public function update(Request $request, int $item): RedirectResponse
    {
        $profile = $this->profile($request);
        $profile->stackItems()->findOrFail($item)->update($this->validateItem($request, false));
        $this->interactions->refresh($profile);

        return back()->with('status', 'Updated.');
    }

    public function destroy(Request $request, int $item): RedirectResponse
    {
        $profile = $this->profile($request);
        $profile->stackItems()->findOrFail($item)->delete();
        $this->interactions->refresh($profile);

        return back()->with('status', 'Removed.');
    }

    /** Tap-to-take on the daily checklist. */
    public function logItem(Request $request, int $item): RedirectResponse|JsonResponse
    {
        $profile = $this->profile($request);
        $row = $profile->stackItems()->findOrFail($item);
        $d = $request->validate([
            'status' => ['nullable', 'in:taken,skipped,extra'],
            'slot' => ['nullable', 'in:morning,midday,evening,night,anytime'],
            'notes' => ['nullable', 'string', 'max:280'],
        ]);

        $event = $profile->intakeEvents()->create([
            'stack_item_id' => $row->id,
            'name' => $row->name,
            'kind' => $row->kind,
            'dose_amount' => $row->dose_amount,
            'dose_unit' => $row->dose_unit,
            'taken_at' => now(),
            'status' => $d['status'] ?? 'taken',
            'source' => 'manual',
            'slot' => $d['slot'] ?? null,
            'notes' => $d['notes'] ?? null,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['event_id' => $event->id, 'today' => Stack::today($profile)]);
        }

        return back();
    }

    public function undo(Request $request, int $event): RedirectResponse|JsonResponse
    {
        $profile = $this->profile($request);
        $profile->intakeEvents()->findOrFail($event)->delete();

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'today' => Stack::today($profile)]);
        }

        return back();
    }

    // ----- helpers ----------------------------------------------------------

    private function profile(Request $request): Profile
    {
        return $request->user()->profile ?? $request->user()->ensureProfile();
    }

    /** @return array<string,mixed> */
    private function validateItem(Request $request, bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';

        return $request->validate([
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
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
