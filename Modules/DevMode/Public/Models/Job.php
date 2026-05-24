<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the framework-managed `jobs` table — one row per
 * pending (or in-flight) queue job.
 *
 * Read-only for Dev Console consumers: no `$fillable`, no factory; the
 * Laravel queue worker is the sole writer. The inspector page reads
 * rows via the DatabaseManager raw query builder (sidesteps Eloquent
 * dynamic-call larastan flags) for list rendering, and uses this model
 * only when a typed accessor is convenient.
 *
 * The `payload` column carries the serialised job class + bound
 * data. The queue inspector's inline JSON viewer passes the raw
 * string through RedactSecretsProcessor::scrub() before rendering
 * — every Bearer / JWT / OAuth scrub-set literal is masked before
 * any payload byte reaches the browser. Cast to array here for
 * code paths that prefer a parsed structure.
 *
 * @property int $id
 * @property string $queue
 * @property array<string, mixed>|string $payload
 * @property int $attempts
 * @property int|null $reserved_at unix ts
 * @property int $available_at unix ts
 * @property int $created_at unix ts
 */
final class Job extends Model
{
    /** @var string|null */
    protected $table = 'jobs';

    /**
     * Framework jobs table uses unix timestamps (int) directly; Eloquent
     * auto-managed timestamps would overwrite `created_at` on save. The
     * model is read-only for the Dev Console, but disable the auto-
     * timestamps anyway to mirror the table contract.
     *
     * @var bool
     */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'reserved_at' => 'integer',
            'available_at' => 'integer',
            'created_at' => 'integer',
        ];
    }
}
