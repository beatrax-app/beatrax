<?php

declare(strict_types=1);

namespace Modules\EmailScan\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

// No column here carries a secret: credentials live exclusively in the
// chmod-600 JSON repository on disk.
/**
 * @property int $id
 * @property int|null $user_id
 * @property string $provider
 * @property string $email
 * @property int $backfill_window_months
 * @property array<string, mixed>|null $backfill_progress
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Inbox extends Model
{
    use BelongsToUser;

    protected $table = 'inboxes';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'provider',
        'email',
        'backfill_window_months',
        'backfill_progress',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'backfill_window_months' => 'integer',
            'backfill_progress' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
