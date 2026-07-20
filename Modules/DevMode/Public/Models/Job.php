<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Models;

use Illuminate\Database\Eloquent\Model;

// Read-only for Dev Console consumers: no $fillable, no factory; the
// Laravel queue worker is the sole writer. The inspector's inline JSON
// viewer passes `payload` through RedactSecretsProcessor::scrub() before
// rendering, so every Bearer/JWT/OAuth literal is masked first.
/**
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

    // Framework jobs table uses unix timestamps (int) directly; Eloquent
    // auto-managed timestamps would overwrite `created_at` on save.
    /**
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
