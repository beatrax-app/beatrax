<?php

declare(strict_types=1);

namespace Modules\EmailScan\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

// A NULL user_id is a system-seeded row, so the runtime query unions the two
// populations with `WHERE user_id = ? OR user_id IS NULL`.
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
