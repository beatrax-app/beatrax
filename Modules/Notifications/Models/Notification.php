<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the `notifications` table — one row per deduplicated
 * notification, keyed by a deterministic sha256 string PK (D-05) computed
 * by `DeterministicKeyDeriver`.
 *
 * `$incrementing = false` + `$keyType = 'string'` are the ONE required
 * deviation from the project's usual autoincrement-PK model shape: the id
 * is always supplied by the caller (never DB-generated), so Eloquent must
 * not attempt to read `lastInsertId()` after an insert.
 *
 * `state` is mutated exclusively by
 * `Modules\Notifications\Internal\StateMachines\NotificationStateMachine`
 * (Req 13's resolved/withdrawn axis only) and enforced at the DB layer by
 * the `notifications_state_check_insert`/`_update` triggers (D-39).
 * `read_at` (D-09) and `dismissed_at` (D-10) are plain nullable
 * timestamps written directly by callers / the op-log replayer — they are
 * NOT part of the state machine.
 *
 * @property string $id
 * @property int $user_id
 * @property string $state
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $dismissed_at
 * @property string $title
 * @property string $body
 * @property string|null $params
 * @property string $trigger_type
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Notification extends Model
{
    use BelongsToUser;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'state',
        'read_at',
        'dismissed_at',
        'title',
        'body',
        'params',
        'trigger_type',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'read_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
