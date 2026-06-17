<?php

namespace App\Http\Controllers;

use App\Models\KnowledgePage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The Research Library — every deep-dive brief the coach has written, in one place. Briefs are
 * KnowledgePages tagged `research` (plus older ones recognised by their footer), so this is just a
 * focused view over them with a markdown reader.
 */
class ResearchLibraryController extends Controller
{
    public function index(Request $request)
    {
        $profile = $request->user()->ensureProfile();

        $briefs = $this->query($profile)->latest('id')->get()->map(fn (KnowledgePage $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'excerpt' => Str::limit(trim(preg_replace('/[#*_`\->\n]+/', ' ', (string) $p->content) ?? ''), 160),
            'date' => $p->created_at?->format('M j, Y'),
        ]);

        return view('research.index', ['briefs' => $briefs]);
    }

    public function show(Request $request, KnowledgePage $page)
    {
        $profile = $request->user()->ensureProfile();
        abort_unless($page->profile_id === $profile->id, 404);

        return view('research.show', [
            'title' => $page->title,
            'html' => Str::markdown((string) $page->content),
            'date' => $page->created_at?->format('M j, Y'),
        ]);
    }

    private function query($profile)
    {
        return $profile->knowledgePages()->where(function ($q) {
            $q->where('type', 'research')->orWhere('content', 'like', '%Researched by your Titan coach%');
        });
    }
}
