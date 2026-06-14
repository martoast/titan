<?php

namespace Database\Seeders;

use App\Models\KnowledgePage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds a few starter brain pages for Alex (profile 1) so the wiki — and the
 * coach's long-term memory — has real context out of the box. The orchestrator
 * calls this; it does NOT touch DatabaseSeeder. Embeddings are left null and will
 * be filled lazily on first search (ensureIndexed).
 */
class BrainSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $pages = [
            [
                'slug' => 'profile-overview',
                'title' => 'Profile Overview',
                'type' => 'overview',
                'is_pinned' => true,
                'content' => <<<'MD'
Core memory for Alex — the foundation the coach should always keep in mind.

- **Age:** early 30s. **Height:** 180 cm.
- **Primary goal:** +10 lbs lean muscle over the next year, with longevity and strong biomarkers as the long game.
- **Training style:** consistent 4–5x/week, responds best to high-volume work. See [[Training History]].
- **Watch-outs:** family history of high cholesterol — keep an eye on ApoB / LDL. See [[Family History]].
- Competing with his brother in the Titan duo — motivated by head-to-head streaks.
MD,
            ],
            [
                'slug' => 'training-history',
                'title' => 'Training History',
                'type' => 'note',
                'is_pinned' => false,
                'content' => <<<'MD'
What we know about how Alex trains and what works for him.

- 8+ years lifting; intermediate-to-advanced.
- **Responds well to high-volume leg days** — quad growth lags, so extra volume there pays off.
- Prefers a push/pull/legs split run over 5 days.
- Bench has been a long-term sticking point; overhead press progresses more reliably.
- Tweaked his lower back deadlifting heavy in 2024 — now keeps deadlifts moderate and high-rep. See [[Injuries]].
MD,
            ],
            [
                'slug' => 'family-history',
                'title' => 'Family History',
                'type' => 'note',
                'is_pinned' => false,
                'content' => <<<'MD'
Relevant family medical history for long-term biomarker tracking.

- **Father:** high cholesterol, on statins from his late 40s.
- Paternal grandfather: cardiovascular disease.
- Takeaway for the coach: prioritize cardiovascular markers (ApoB, LDL, triglycerides) and tie nutrition advice back to them. Links to [[Profile Overview]].
MD,
            ],
        ];

        foreach ($pages as $p) {
            KnowledgePage::updateOrCreate(
                ['profile_id' => 1, 'slug' => $p['slug']],
                [
                    'updated_by_user_id' => 1,
                    'title' => $p['title'],
                    'type' => $p['type'],
                    'content' => $p['content'],
                    'is_pinned' => $p['is_pinned'],
                ],
            );
        }
    }
}
