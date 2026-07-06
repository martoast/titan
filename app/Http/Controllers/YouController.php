<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * The "You" tab — the web twin of the iOS ProfileView: a profile header plus the deeper
 * library/setup surfaces folded under this tab (band, physique, brain, research,
 * notifications, connect, account).
 */
class YouController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $profile = $user->ensureProfile();

        return view('you.index', [
            'user' => $user,
            'profile' => $profile,
        ]);
    }
}
