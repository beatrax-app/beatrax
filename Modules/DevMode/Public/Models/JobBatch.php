<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Models;

use Illuminate\Database\Eloquent\Model;

// Read-only for Dev Console consumers, one row per dispatched batch
// (Bus::batch(...)). Writes flow through Illuminate\Bus\BatchRepository
// injected directly into QueueActions via DI — no facade. The `id`
// column is a string UUID, so this model overrides the int key type.
/**
 * @property string $id
 * @property string $name
 * @property int $total_jobs
 * @property int $pending_jobs
 * @property int $failed_jobs
 * @property array<int, string>|string $failed_job_ids
 * @property array<string, mixed>|string|null $options
 * @property int|null $cancelled_at unix ts
 * @property int $created_at unix ts
 * @property int|null $finished_at unix ts
 */
final class JobBatch extends Model
{
    /** @var string|null */
    protected $table = 'job_batches';

    // The framework writes UUIDs into `id`; suppress Eloquent's
    // auto-increment expectation.
    /**
     * @var bool
     */
    public $incrementing = false;

    /** @var string */
    protected $keyType = 'string';

    // Unix-timestamp int columns + JSON columns are written directly by
    // BatchRepository; Eloquent's auto-managed timestamps don't apply.
    /**
     * @var bool
     */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_jobs' => 'integer',
            'pending_jobs' => 'integer',
            'failed_jobs' => 'integer',
            'failed_job_ids' => 'array',
            'options' => 'array',
            'cancelled_at' => 'integer',
            'created_at' => 'integer',
            'finished_at' => 'integer',
        ];
    }
}
