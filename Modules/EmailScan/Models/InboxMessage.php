<?php

declare(strict_types=1);

namespace Modules\EmailScan\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;

// The index over the .eml blobs on disk. (inbox_id, provider_message_id) is
// UNIQUE so a re-fetch is a no-op via insertOrIgnore, and sender + subject are
// captured at fetch time so the parser never re-opens a blob just to filter.
/**
 * @property int $id
 * @property int|null $user_id
 * @property int $inbox_id
 * @property string $provider_message_id
 * @property CarbonImmutable $internal_date
 * @property string $sender_email
 * @property string|null $sender_name
 * @property string|null $subject
 * @property string $status
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class InboxMessage extends Model
{
    use BelongsToUser;

    protected $table = 'inbox_messages';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'inbox_id',
        'provider_message_id',
        'internal_date',
        'sender_email',
        'sender_name',
        'subject',
        'status',
        'fetched_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'internal_date' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Inbox, $this> */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }
}
