<?php

declare(strict_types=1);

namespace Modules\EmailScan\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

// System-seeded rows carry user_id = NULL and source = 'system';
// per-user rows (manual or promoted from discovered_senders) carry
// user_id = $user->id and source = 'user'. The runtime query unions
// both via WHERE user_id = $userId OR user_id IS NULL.
/**
 * @property int $id
 * @property int|null $user_id
 * @property string $email_pattern
 * @property string $label
 * @property string $source
 * @property CarbonImmutable $added_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class KnownSender extends Model
{
    use BelongsToUser;

    protected $table = 'known_senders';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'email_pattern',
        'label',
        'source',
        'added_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'added_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
