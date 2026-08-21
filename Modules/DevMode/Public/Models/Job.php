<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Models;

use Illuminate\Database\Eloquent\Model;

// Read-only here: the queue worker is the sole writer. `payload` reaches the
// inspector only through RedactSecretsProcessor::scrub() — it routinely
// carries Bearer/JWT/OAuth literals.
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

    // `created_at` is a unix int here, so Eloquent's auto-timestamps would
    // overwrite it with a datetime string on save.
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
