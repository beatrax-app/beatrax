<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the system_alerts table — one row models one
 * operational-failure event surfaced to the user via the persistent
 * dashboard banner (corrupt backup, overdue backup, misconfigured
 * SQLite PRAGMA, etc.).
 *
 * Severity is one of `info` / `warning` / `critical`; the schema-level
 * trigger pair rejects out-of-band values so the Eloquent cast map
 * stays purely informational. Rows are never deleted; acknowledging an
 * alert stamps `acknowledged_at` so the audit trail accumulates.
 *
 * `user_id` is nullable: NULL means a system-wide alert visible to
 * every authenticated user (e.g. a SQLite PRAGMA drift). The
 * BelongsToUser trait adds the trait's user() relation and the
 * standard per-user global scope; the system-wide carve-out happens
 * at the read-service / action layer, where the per-user predicate is
 * widened with `orWhereNull('user_id')`.
 *
 * Read filters (active = `whereNull('acknowledged_at')`, by kind =
 * `where('kind', …)`) live inline at the call site rather than as
 * model scopes — the project's larastan-strict-rules profile rejects
 * local query scopes in favour of explicit query-builder predicates
 * at the service layer.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $kind
 * @property string $severity
 * @property string $message
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $acknowledged_at
 */
final class SystemAlert extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'kind',
        'severity',
        'message',
        'metadata',
        'acknowledged_at',
    ];

    /**
     * system_alerts rows write their own `created_at` via a SQLite
     * `useCurrent()` default and never update once acknowledged
     * (acknowledging stamps a separate `acknowledged_at` column),
     * so the `updated_at` column is omitted from the migration. Disable
     * Eloquent's auto-managed timestamps so `created_at` flows through
     * the cast pipeline without triggering an `updated_at` write.
     *
     * @var bool
     */
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'acknowledged_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
