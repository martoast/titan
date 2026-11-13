<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Erase an account and everything personal attached to it.
 *
 * Apple's Guideline 5.1.1(v) requires any app that supports account creation to let a person
 * delete their account FROM INSIDE the app, removing "the entire account record, along with
 * associated personal data" — deactivation is explicitly not enough. Titan holds sleep, heart
 * rate, menstrual cycles, body photos and raw PPG waveforms, so a half-deletion is the worst
 * outcome of the three.
 *
 * The database does most of the work: 48 foreign keys cascade from `users` and `profiles`, so
 * deleting the user row clears all 45 health tables plus api_tokens and push_tokens. What the
 * database CANNOT reach is the bytes on disk — a person's photos on the `public` disk and their
 * raw waveform objects in MinIO (`raw`) — so those paths are collected BEFORE the rows go and
 * deleted afterwards.
 *
 * Ordering is deliberate. Rows first, files second: if a file delete fails we leave an orphan
 * blob (loud in the log, cleanable) rather than destroying somebody's photos and then failing to
 * close their account.
 */
class DeleteAccount
{
    /** Columns holding a path on the `public` disk, as table => [columns]. */
    private const PUBLIC_FILES = [
        'progress_photos' => ['photo_path'],
        'living_goal_renders' => ['image_path'],
        'physique_goals' => ['source_photo_path', 'goal_image_path'],
        'meals' => ['photo_path'],
    ];

    /**
     * @return array{user_id:int, files_deleted:int, files_failed:int, objects_deleted:int, objects_failed:int}
     */
    public function run(User $user): array
    {
        $profile = $user->profile;

        $publicPaths = $profile ? $this->publicPaths($profile->id) : [];
        $rawKeys = $profile ? $this->rawObjectKeys($profile->id) : [];

        if ($profile && filled($profile->avatar_path)) {
            $publicPaths[] = (string) $profile->avatar_path;
        }

        $userId = $user->id;

        // One transaction: either the account is gone or nothing moved. The cascade does the
        // rest — see the 48 FKs on users/profiles.
        DB::transaction(function () use ($user) {
            $user->forceDelete();
        });

        $files = $this->forget('public', array_values(array_unique($publicPaths)), $userId);
        $objects = $this->forget('raw', array_values(array_unique($rawKeys)), $userId);

        Log::warning('[account] deleted', [
            'user_id' => $userId,
            'files_deleted' => $files['deleted'],
            'files_failed' => $files['failed'],
            'objects_deleted' => $objects['deleted'],
            'objects_failed' => $objects['failed'],
        ]);

        return [
            'user_id' => $userId,
            'files_deleted' => $files['deleted'],
            'files_failed' => $files['failed'],
            'objects_deleted' => $objects['deleted'],
            'objects_failed' => $objects['failed'],
        ];
    }

    /** @return list<string> */
    private function publicPaths(int $profileId): array
    {
        $paths = [];

        foreach (self::PUBLIC_FILES as $table => $columns) {
            foreach ($columns as $column) {
                $paths = array_merge($paths, DB::table($table)
                    ->where('profile_id', $profileId)
                    ->whereNotNull($column)
                    ->pluck($column)
                    ->all());
            }
        }

        return array_values(array_filter(array_map('strval', $paths), 'strlen'));
    }

    /** @return list<string> */
    private function rawObjectKeys(int $profileId): array
    {
        return array_values(array_filter(array_map('strval', DB::table('device_ingestions')
            ->where('profile_id', $profileId)
            ->whereNotNull('object_key')
            ->pluck('object_key')
            ->all()), 'strlen'));
    }

    /**
     * Best-effort removal. A disk that is unreachable (MinIO down) must not turn a completed
     * deletion into an error the person sees — their account IS gone — so this only counts and
     * logs. Each failure names its path so it can be swept later.
     *
     * @param  list<string>  $paths
     * @return array{deleted:int, failed:int}
     */
    private function forget(string $disk, array $paths, int $userId): array
    {
        if ($paths === []) {
            return ['deleted' => 0, 'failed' => 0];
        }

        $deleted = 0;
        $failed = 0;

        foreach ($paths as $path) {
            try {
                // A path that is already gone is a success, not a failure.
                if (Storage::disk($disk)->delete($path) || ! Storage::disk($disk)->exists($path)) {
                    $deleted++;

                    continue;
                }
                $failed++;
                Log::warning('[account] file survived deletion', compact('disk', 'path', 'userId'));
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('[account] file delete threw', [
                    'disk' => $disk,
                    'path' => $path,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
