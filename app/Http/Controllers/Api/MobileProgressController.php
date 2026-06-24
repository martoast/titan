<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\ProgressPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Native-app progress photos (the "Fuel" tab → Progress segment). A dated, private physique gallery
 * — the raw material the coach already reads (render_dream_physique / physique_progress read the
 * latest ProgressPhoto), so logging here feeds straight into the coach.
 */
class MobileProgressController extends Controller
{
    /** Newest-first gallery. */
    public function index(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        $photos = $profile->progressPhotos()
            ->orderByDesc('taken_at')->orderByDesc('id')
            ->limit(200)->get()
            ->map(fn (ProgressPhoto $p) => $this->json($p))->values();

        return response()->json(['photos' => $photos]);
    }

    /** Save a progress photo (front/side/back, optional weight + note). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'pose' => ['nullable', 'in:front,side,back'],
            'weight_kg' => ['nullable', 'numeric', 'min:20', 'max:400'],
            'notes' => ['nullable', 'string', 'max:280'],
            'taken_at' => ['nullable', 'date'],
        ]);
        $profile = $this->profile($request);

        $path = $request->file('photo')->store('physique/progress', 'public');

        $photo = $profile->progressPhotos()->create([
            'photo_path' => $path,
            'taken_at' => $data['taken_at'] ?? now()->toDateString(),
            'pose' => $data['pose'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['photo' => $this->json($photo)], 201);
    }

    public function destroy(Request $request, int $photo): JsonResponse
    {
        $this->profile($request)->progressPhotos()->findOrFail($photo)->delete();

        return response()->json(['ok' => true]);
    }

    private function profile(Request $request): Profile
    {
        return $request->user()->profile ?? $request->user()->ensureProfile();
    }

    /** @return array<string,mixed> */
    private function json(ProgressPhoto $p): array
    {
        return [
            'id' => $p->id,
            'taken_at' => $p->taken_at?->toDateString(),
            'pose' => $p->pose,
            'weight_kg' => $p->weight_kg !== null ? (float) $p->weight_kg : null,
            'notes' => $p->notes,
            'photo_url' => $p->photoUrl(),
        ];
    }
}
