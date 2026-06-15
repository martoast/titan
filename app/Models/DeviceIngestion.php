<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One signed ingestion batch's ledger row. Raw waveform bytes (IBI/PPG/accel) live in
 * MinIO under `object_key`; this row is the queryable index + the processing state
 * machine the worker advances (received → queued → processing → processed | failed).
 *
 * `batch_uid` (a client-supplied ULID) is the idempotency key — UNIQUE, so a replayed
 * batch resolves to the same row and queues no duplicate work. The primary key stays a
 * plain auto-increment id; batch_uid is supplied by the device, not auto-generated.
 * `result_refs` records which canonical rows the metrics landed in (reprocess + audit).
 */
class DeviceIngestion extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DUPLICATE = 'duplicate';

    protected $fillable = [
        'batch_uid', 'profile_id', 'source', 'kind', 'object_key',
        'window_start', 'window_end', 'status', 'algo_version',
        'result_refs', 'error',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'result_refs' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
