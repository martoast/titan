<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a coach conversation. `role` is user|assistant|tool|system. The
 * `tool_calls` JSON preserves the assistant's tool invocations so a thread can be
 * faithfully replayed. Only user + assistant messages are rendered in the UI.
 */
class ChatMessage extends Model
{
    use HasFactory;

    public const ROLES = ['user', 'assistant', 'tool', 'system'];

    /** Background-generation lifecycle for an assistant row (null = user/tool/legacy = already done). */
    public const STATUS_PENDING = 'pending';
    public const STATUS_STREAMING = 'streaming';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';

    /**
     * PROACTIVE coach messages — the ones it sends unprompted, which now land inline in the day's
     * chat rather than in a hidden thread. NULL kind = an ordinary chat turn.
     */
    public const KIND_BRIEFING = 'briefing';   // morning briefing / evening nudge
    public const KIND_REACTION = 'reaction';   // event-driven (sleep sealed, workout sealed, meal logged…)

    public const PROACTIVE_KINDS = [self::KIND_BRIEFING, self::KIND_REACTION];

    protected $fillable = ['conversation_id', 'role', 'kind', 'content', 'tool_calls', 'status'];

    protected function casts(): array
    {
        return ['tool_calls' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** Did the coach send this unprompted (briefing / reaction) rather than in reply? */
    public function isProactive(): bool
    {
        return in_array($this->kind, self::PROACTIVE_KINDS, true);
    }
}
