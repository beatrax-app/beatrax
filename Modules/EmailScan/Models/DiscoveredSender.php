<?php

declare(strict_types=1);

namespace Modules\EmailScan\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;

// Re-runs of the discovery scan are idempotent via the (user_id,
// inbox_id, sender_email) UNIQUE key: the row is either inserted new
// or its occurrence_count + last_seen_at are bumped. The discovery
// scan suppresses rows already in 'added' or 'dismissed'.
/**
 * @property int $id
 * @property int|null $user_id
 * @property int $inbox_id
 * @property string $sender_email
 * @property string|null $sender_name
 * @property int $occurrence_count
 * @property CarbonImmutable $last_seen_at
 * @property int|null $sample_message_id
 * @property string $state
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DiscoveredSender extends Model
{
    use BelongsToUser;

    protected $table = 'discovered_senders';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'inbox_id',
        'sender_email',
        'sender_name',
        'occurrence_count',
        'last_seen_at',
        'sample_message_id',
        'state',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurrence_count' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Inbox, $this> */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class);
    }

    /** @return BelongsTo<InboxMessage, $this> */
    public function sampleMessage(): BelongsTo
    {
        return $this->belongsTo(InboxMessage::class, 'sample_message_id');
    }
}
