<?php

namespace App\Http\Controllers\Brain;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Models\KnowledgePage;
use App\Services\Brain\KnowledgeIngestor;
use App\Services\Brain\KnowledgeSearch;
use App\Services\Documents\DocumentText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The Brain -- a per-profile long-term-memory markdown wiki the AI coach reads and
 * writes. Pages, semantic search, brain-dump ingestion, and document upload.
 */
class BrainController extends Controller
{
    public function __construct(
        protected KnowledgeSearch $search,
        protected KnowledgeIngestor $ingestor,
        protected DocumentText $documents,
    ) {}

    /** Index: pinned pages first + a search box that ranks pages with snippets. */
    public function index(Request $request): View
    {
        $profile = $request->user()->ensureProfile();
        $q = trim((string) $request->query('q', ''));

        $pages = KnowledgePage::query()
            ->where('profile_id', $profile->id)
            ->orderByDesc('is_pinned')
            ->orderBy('title')
            ->get();

        $results = [];
        $searchError = null;
        if ($q !== '') {
            try {
                $results = $this->search->search($profile, $q, 20);
            } catch (AiException $e) {
                $searchError = $e->getMessage();
            }
        }

        return view('brain.index', [
            'pages' => $pages,
            'q' => $q,
            'results' => $results,
            'searchError' => $searchError,
            'aiConfigured' => $this->search->configured(),
        ]);
    }

    /** Read a single page (rendered markdown + backlinks). */
    public function show(Request $request, string $slug): View
    {
        $profile = $request->user()->ensureProfile();
        $page = KnowledgePage::query()
            ->where('profile_id', $profile->id)
            ->where('slug', $slug)
            ->firstOrFail();

        return view('brain.show', [
            'page' => $page,
            'backlinks' => $page->backlinks(),
        ]);
    }

    /** New-page form. */
    public function create(): View
    {
        return view('brain.edit', ['page' => new KnowledgePage(['type' => 'note'])]);
    }

    /** Edit-page form. */
    public function edit(Request $request, string $slug): View
    {
        $profile = $request->user()->ensureProfile();
        $page = KnowledgePage::query()
            ->where('profile_id', $profile->id)
            ->where('slug', $slug)
            ->firstOrFail();

        return view('brain.edit', ['page' => $page]);
    }

    /** Create a page. */
    public function store(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensureProfile();
        $data = $this->validatePage($request);

        $slug = $this->uniqueSlug($profile->id, KnowledgePage::slugFor($data['title']));

        $page = KnowledgePage::query()->create([
            'profile_id' => $profile->id,
            'updated_by_user_id' => $request->user()->id,
            'title' => $data['title'],
            'slug' => $slug,
            'type' => $data['type'],
            'content' => $data['content'] ?? '',
            'is_pinned' => $request->boolean('is_pinned'),
        ]);

        $this->safeEmbed($page);

        return redirect("/brain/{$page->slug}")->with('status', 'Page created.');
    }

    /** Update a page. */
    public function update(Request $request, string $slug): RedirectResponse
    {
        $profile = $request->user()->ensureProfile();
        $page = KnowledgePage::query()
            ->where('profile_id', $profile->id)
            ->where('slug', $slug)
            ->firstOrFail();

        $data = $this->validatePage($request);
        $page->fill([
            'title' => $data['title'],
            'type' => $data['type'],
            'content' => $data['content'] ?? '',
            'is_pinned' => $request->boolean('is_pinned'),
            'updated_by_user_id' => $request->user()->id,
        ])->save();

        $this->safeEmbed($page);

        return redirect("/brain/{$page->slug}")->with('status', 'Page updated.');
    }

    /** Delete (soft) a page. */
    public function destroy(Request $request, string $slug): RedirectResponse
    {
        $profile = $request->user()->ensureProfile();
        $page = KnowledgePage::query()
            ->where('profile_id', $profile->id)
            ->where('slug', $slug)
            ->firstOrFail();
        $page->delete();

        return redirect('/brain')->with('status', 'Page deleted.');
    }

    /** Brain dump → librarian ingestion → created/updated pages. */
    public function ingest(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensureProfile();
        $dump = trim((string) $request->input('dump', ''));

        if ($dump === '') {
            return back()->with('status', 'Nothing to organize -- the note was empty.');
        }

        try {
            $result = $this->ingestor->ingest($profile, $request->user(), $dump);

            return back()->with('status', $result['message']);
        } catch (AiException $e) {
            return back()->with('brain_error', $e->getMessage());
        }
    }

    /** Upload a PDF/docx/txt → extract text → ingest into the wiki. */
    public function upload(Request $request): RedirectResponse
    {
        $profile = $request->user()->ensureProfile();
        $request->validate([
            'document' => ['required', 'file', 'max:20480'], // 20 MB
        ]);

        $file = $request->file('document');
        $ext = strtolower((string) $file->getClientOriginalExtension());

        if (! $this->documents->supports($ext)) {
            return back()->with('brain_error', "Unsupported file type: .{$ext}. Try PDF, DOCX, or TXT.");
        }

        $text = $this->documents->extract($file->getRealPath(), $ext);
        if (trim($text) === '') {
            return back()->with('brain_error', 'Could not read any text from that file.');
        }

        $label = $file->getClientOriginalName();
        $dump = "Source document: {$label}\n\n".$text;

        try {
            $result = $this->ingestor->ingest($profile, $request->user(), $dump);

            return back()->with('status', "Imported \"{$label}\": ".$result['message']);
        } catch (AiException $e) {
            return back()->with('brain_error', $e->getMessage());
        }
    }

    /** @return array{title:string,type:string,content:?string} */
    private function validatePage(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'string', 'in:'.implode(',', KnowledgePage::TYPES)],
            'content' => ['nullable', 'string'],
        ]);
    }

    /** A slug unique within the profile (append -2, -3, … on collision). */
    private function uniqueSlug(int $profileId, string $base): string
    {
        $slug = $base;
        $i = 2;
        while (KnowledgePage::query()->where('profile_id', $profileId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Re-embed a page right after a save; never let an AI outage break the request. */
    private function safeEmbed(KnowledgePage $page): void
    {
        try {
            $this->search->embedPage($page);
        } catch (AiException) {
            // Best-effort -- page is saved; embedding will refresh on next search.
        }
    }
}
