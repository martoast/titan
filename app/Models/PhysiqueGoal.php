<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * The product's signature artifact: an identity-preserved "dream physique" image.
 * `source_photo_path` is the original upload; `goal_image_path` is the Nano Banana
 * render of the same person with ~10 lbs more lean muscle. The active goal anchors
 * the before/after display, the "% to goal" progress bar, and the living-image morph.
 */
class PhysiqueGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'source_photo_path', 'goal_image_path', 'shots',
        'prompt', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'shots' => 'array',
        ];
    }

    /** The angles we capture, in display order. Front is the identity anchor + primary shot. */
    public const ANGLES = ['front', 'back', 'side'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** Public URL of the original uploaded photo. */
    public function sourceUrl(): ?string
    {
        return $this->source_photo_path ? Storage::disk('public')->url($this->source_photo_path) : null;
    }

    /** Public URL of the dream physique goal image (static model or storage). */
    public function goalUrl(): ?string
    {
        return $this->goal_image_path ? $this->resolveImageUrl($this->goal_image_path) : null;
    }

    /**
     * Every captured angle as display-ready URLs, ordered front → back → side.
     * Legacy single-shot goals (no `shots`) synthesize a single front entry, so the
     * gallery renders the same whether a goal predates multi-angle or not.
     *
     * @return array<int,array{angle:string,source_url:?string,goal_url:?string}>
     */
    public function shotUrls(): array
    {
        $disk = Storage::disk('public');
        $byAngle = [];
        foreach (($this->shots ?? []) as $s) {
            $angle = $s['angle'] ?? 'front';
            $byAngle[$angle] = [
                'angle' => $angle,
                'source_url' => ! empty($s['source']) ? $disk->url($s['source']) : null,
                'goal_url' => ! empty($s['goal']) ? $this->resolveImageUrl($s['goal']) : null,
            ];
        }

        $out = [];
        foreach (self::ANGLES as $angle) {
            if (isset($byAngle[$angle])) {
                $out[] = $byAngle[$angle];
            }
        }

        // Legacy goals (created before multi-angle): present the primary pair as the front shot.
        if ($out === [] && $this->goal_image_path) {
            $out[] = ['angle' => 'front', 'source_url' => $this->sourceUrl(), 'goal_url' => $this->goalUrl()];
        }

        return $out;
    }

    /**
     * Resolve a stored image path to a public URL. Pre-generated model images live in
     * public/images/physique/models/ (served via asset()), while user-uploaded images
     * live in the public storage disk (served via Storage::url()).
     */
    private function resolveImageUrl(string $path): string
    {
        if (str_starts_with($path, 'images/physique/models/')) {
            return asset($path);
        }

        return Storage::disk('public')->url($path);
    }

    /** Upsert one angle's {source,goal} into the shots set, keeping ANGLES order. */
    public function putShot(string $angle, string $sourcePath, string $goalPath): void
    {
        $shots = collect($this->shots ?? [])
            ->reject(fn ($s) => ($s['angle'] ?? null) === $angle)
            ->push(['angle' => $angle, 'source' => $sourcePath, 'goal' => $goalPath])
            ->sortBy(fn ($s) => array_search($s['angle'], self::ANGLES, true))
            ->values()
            ->all();

        $attrs = ['shots' => $shots];
        // Front is the primary shot the rest of the app reads.
        if ($angle === 'front') {
            $attrs['source_photo_path'] = $sourcePath;
            $attrs['goal_image_path'] = $goalPath;
        }
        $this->update($attrs);
    }
}
