<?php

namespace Database\Seeders;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the two brothers — the Titan duo. Each gets a health profile. Domain
     * seeders can hang sample data off these two profiles.
     */
    public function run(): void
    {
        $brothers = [
            ['name' => 'Alex', 'email' => 'alex@titan.test', 'goal' => '+10 lbs lean muscle, longevity, strong biomarkers'],
            ['name' => 'Bro',  'email' => 'bro@titan.test',  'goal' => '+10 lbs lean muscle, mobility, recovery'],
        ];

        foreach ($brothers as $b) {
            $user = User::firstOrCreate(
                ['email' => $b['email']],
                ['name' => $b['name'], 'password' => Hash::make('password')],
            );

            Profile::firstOrCreate(
                ['user_id' => $user->id],
                ['display_name' => $b['name'], 'primary_goal' => $b['goal'], 'coach_tone' => 'balanced'],
            );
        }

        // Domain seeders (built by the parallel verticals). Order: the exercise
        // library before workouts that reference it; everything else hangs off the
        // two profiles seeded above.
        $this->call([
            BrainSeeder::class,
            HealthDataSeeder::class,
            ExerciseLibrarySeeder::class,
            WorkoutsSeeder::class,
            MealsSeeder::class,
            SleepRecoverySeeder::class,
            PhysiqueSeeder::class,
            CoachSeeder::class,
            DuoSeeder::class,
        ]);
    }
}
