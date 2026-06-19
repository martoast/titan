<?php

namespace App\Services\Coach;

use App\Models\Profile;
use App\Services\Ai\AiService;
use Illuminate\Support\Str;

/**
 * The coach's deep-research engine. Given a topic the user wants explored (a training style, a
 * nutrition approach, a supplement, a protocol…), it works the way a good researcher does: decompose
 * the topic into angles, investigate each in depth, then synthesize a structured writeup tailored to
 * THIS person (their goal, level, constraints). The result is filed in their Brain wiki.
 *
 * This is knowledge synthesis by the model, not live web crawling -- the report says so, and stays in
 * Titan's wellness lane (no medical/diagnostic claims).
 */
class ResearchService
{
    public function __construct(
        protected AiService $ai,
        protected \App\Services\Web\WebSearch $web,
    ) {}

    /**
     * @return array{title:string,markdown:string,summary:string}
     */
    public function run(Profile $profile, string $topic, ?string $focus = null): array
    {
        $topic = trim($topic);
        $who = $this->context($profile);

        // 1. PLAN -- break the topic into a handful of focused angles to investigate.
        $plan = $this->ai->json([
            ['role' => 'system', 'content' => 'You plan a focused research brief for a fitness/health/longevity topic. Return JSON {"title": string, "sections": [{"heading": string, "question": string}]} with 4-5 sections covering the most useful angles (what it is, the mechanism/how it works, how to actually do it, the evidence + caveats, who it suits). No preamble.'],
            ['role' => 'user', 'content' => "Topic to research: {$topic}".($focus ? "\nUser's angle/why: {$focus}" : '')],
        ], ['temperature' => 0.4, 'max_tokens' => 500]);

        $title = trim((string) ($plan['title'] ?? ''));
        $title = $title !== '' ? $title : Str::title($topic);
        $sections = array_slice(is_array($plan['sections'] ?? null) ? $plan['sections'] : [], 0, 5);
        if ($sections === []) {
            $sections = [['heading' => 'Overview', 'question' => "Explain {$topic} thoroughly and practically."]];
        }

        // 2. INVESTIGATE -- a detailed, evidence-informed pass per angle.
        $body = [];
        $sources = [];
        foreach ($sections as $s) {
            $heading = trim((string) ($s['heading'] ?? 'Section'));
            $question = trim((string) ($s['question'] ?? $heading));

            // Ground each section in LIVE web findings where available.
            $webFacts = '';
            if ($this->web->configured()) {
                $hit = $this->web->search("{$topic} {$heading}", 4);
                $webFacts = $this->web->facts("{$topic} {$heading}", 4);
                foreach ($hit['results'] as $r) {
                    if ($r['link']) {
                        $sources[parse_url($r['link'], PHP_URL_HOST) ?: $r['link']] = $r['link'];
                    }
                }
            }

            $content = $this->ai->chat([
                ['role' => 'system', 'content' => 'You are a meticulous strength, nutrition and longevity researcher. Write one section of a research brief: accurate, specific, evidence-informed and practical (real numbers, ranges, protocols where they exist). Use tight prose and bullet points. Prefer the supplied LIVE web findings over memory where they apply. No fluff, no medical or diagnostic claims, no headings (the heading is added for you).'],
                ['role' => 'user', 'content' => "Research topic: {$topic}\nSection: {$heading}\nAddress: {$question}"
                    .($webFacts !== '' ? "\n\nLive web findings (prefer these; they are current):\n{$webFacts}" : '')],
            ], ['temperature' => 0.5, 'max_tokens' => 650]);
            $body[] = "## {$heading}\n\n".trim($content);
        }

        // 3. SYNTHESIZE -- personalise it to this person + a crisp summary for the chat/notification.
        $applied = $this->ai->json([
            ['role' => 'system', 'content' => 'Return JSON {"applies": string, "summary": string}. "applies" = a short, specific "How this applies to you" section (markdown, a few bullets) tailoring the research to the person described, honouring any injuries/constraints. "summary" = 2 sentences capturing the gist for a notification. No medical advice.'],
            ['role' => 'user', 'content' => "Person: {$who}\n\nResearch topic: {$topic}\n\nFindings:\n".Str::limit(implode("\n\n", $body), 6000)],
        ], ['temperature' => 0.5, 'max_tokens' => 500]);

        $applies = trim((string) ($applied['applies'] ?? ''));
        $summary = trim((string) ($applied['summary'] ?? "Researched {$topic} and saved a full writeup to your Brain."));

        $sourceList = '';
        if ($sources !== []) {
            $sourceList = "\n\n## Sources\n\n".collect($sources)->take(8)->map(fn ($url, $host) => "- [{$host}]({$url})")->implode("\n");
        }

        $grounded = $sources !== [];
        $markdown = "# {$title}\n\n"
            .implode("\n\n", $body)
            .($applies !== '' ? "\n\n## How this applies to you\n\n{$applies}" : '')
            .$sourceList
            ."\n\n---\n*Researched by your Titan coach"
            .($focus ? " (you asked: {$focus})" : '')
            .($grounded ? ', grounded in live web sources' : ' (knowledge synthesis)')
            .". Not medical advice -- verify specifics with a qualified professional.*";

        return ['title' => $title, 'markdown' => $markdown, 'summary' => $summary];
    }

    /** A compact description of the person so the research can be tailored to them. */
    private function context(Profile $profile): string
    {
        $bits = [];
        if ($profile->primary_goal) {
            $bits[] = 'goal: '.$profile->primary_goal;
        }
        if ($profile->sex) {
            $bits[] = 'sex: '.$profile->sex;
        }
        if ($profile->birthdate) {
            $bits[] = rescue(fn () => 'age: '.$profile->birthdate->age, null, false) ?? '';
        }
        if (($lvl = data_get($profile->settings, 'activity_level'))) {
            $bits[] = 'activity: '.$lvl;
        }
        if (class_exists(\App\Support\CoachMemoryBook::class)) {
            $mem = \App\Support\CoachMemoryBook::digest($profile, 500);
            if ($mem !== '') {
                $bits[] = "known facts -- {$mem}";
            }
        }

        return implode('; ', array_filter($bits)) ?: 'a Titan user';
    }
}
