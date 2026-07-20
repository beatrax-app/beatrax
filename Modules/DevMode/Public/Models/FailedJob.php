<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

// Read-only for Dev Console consumers, one row per job that exhausted
// all retry attempts. The queue worker writes via
// FailedJobProviderInterface; the inspector surfaces rows + lets a
// developer call forget(uuid) or re-dispatch via QueueActions::retryFailed.
/**
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property array<string, mixed>|string $payload
 * @property string $exception
 * @property CarbonImmutable $failed_at
 */
final class FailedJob extends Model
{
    /** @var string|null */
    protected $table = 'failed_jobs';

    // The framework migration provides `failed_at` (via useCurrent());
    // there is no `updated_at` column, so auto-timestamps would mismatch.
    /**
     * @var bool
     */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
