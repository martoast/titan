<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Profile;
use Database\Seeders\Concerns\SeedsProfile;
use Illuminate\Database\Seeder;

/**
 * Seeds one sample coach conversation (with a couple of messages) for profile 1 (Alex),
 * so the /coach page has something to show on a fresh install. Idempotent: skips if a
 * conversation already exists for that profile.
 */
class CoachSeeder extends Seeder
{
    use SeedsProfile;

    public function run(): void
    {
        $profile = $this->targetProfile();
        if (! $profile) {
            return;
        }

        if (Conversation::where('profile_id', $profile->id)->exists()) {
            return;
        }

        $name = $profile->display_name ?: 'Alex';

        $conversation = Conversation::create([
            'profile_id' => $profile->id,
            'title' => 'Dialing in protein & recovery',
        ]);

        $conversation->messages()->createMany([
            [
                'role' => 'user',
                'content' => 'How are my biomarkers trending, and am I eating enough protein to hit my goal?',
            ],
            [
                'role' => 'assistant',
                'content' => "Good question, {$name}. Here's the picture from your logged data:\n\n"
                    ."**Biomarkers** — most markers are sitting in a healthy range. Keep an eye on **ApoB** and **LDL**; "
                    ."given your goal of strong long-term cardiovascular health, those are the levers worth trending over the next few labs.\n\n"
                    ."**Protein** — to support **+10 lbs of lean muscle**, aim for roughly **1.6–2.2 g/kg of bodyweight per day**. "
                    ."Your recent meal logs are landing a bit under that on training days. The fix is simple: anchor each meal "
                    ."with a 30–40 g protein source and add a post-workout serving.\n\n"
                    .'Want me to draft a day of meals that hits your protein target while keeping calories in your lean-bulk range?',
            ],
        ]);
    }
}
