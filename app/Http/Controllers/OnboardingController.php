<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * First-run onboarding -- the wizard that turns a fresh account into a real Titan profile.
 * The intake → profile logic lives in OnboardingService (shared with the native app); this
 * controller is the web wizard's thin shell + the per-step progress-photo upload.
 */
class OnboardingController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $profile = $request->user()->ensureProfile();
        if ($profile->isOnboarded()) {
            return redirect()->route('coach.index');
        }

        return view('onboarding.index', [
            'profile' => $profile,
            'name' => $profile->display_name ?: $request->user()->name,
            'goals' => OnboardingService::GOALS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensureProfile();
        $data = $request->validate(OnboardingService::rules());

        $res = app(OnboardingService::class)->apply($request->user(), $profile, $data);

        // If their band is already in hand, the perfect moment to connect it is right now.
        if ($res['route'] === 'pairing') {
            return redirect()->route('devices.index')->with('status', "Welcome to Titan, {$res['name']} -- your profile's ready. Let's connect your band so your coach reads recovery from night one.");
        }

        return redirect()->route('coach.index')->with('status', "Welcome to Titan, {$res['name']} -- your profile is ready. Ask me anything.");
    }

    /**
     * Store one progress photo during onboarding (AJAX). Called once per angle from the
     * "Your starting point" wizard step → a ProgressPhoto baseline from day one.
     */
    public function storeProgressPhoto(Request $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();

        $validator = Validator::make($request->all(), [
            'photo' => ['required', 'image', 'max:12288'],
            'angle' => ['nullable', 'in:front,back,side'],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => 'Upload a clear photo to continue.'], 422);
        }

        $path = $request->file('photo')->store('physique/progress', 'public');
        $photo = $profile->progressPhotos()->create([
            'photo_path' => $path,
            'taken_at' => now()->toDateString(),
            'pose' => $request->input('angle') ?: 'front',
        ]);

        return response()->json([
            'ok' => true,
            'photo_id' => $photo->id,
            'photo_url' => Storage::disk('public')->url($path),
        ]);
    }
}
