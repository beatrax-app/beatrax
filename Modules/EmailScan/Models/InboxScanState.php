<?php

declare(strict_types=1);

namespace Modules\EmailScan\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;

// One row per (inbox_id, folder). status drives the per-inbox health badge and
// may only be mutated through InboxScanStateMachine.
/**
 * @property int $id
 * @property int|null $user_id
 * @property int $inbox_id
 * @property string $folder
 * @property string|null $last_history_id
 * @property string|null $last_delta_link
 * @property CarbonImmutable|null $last_scan_at
 * @property string $status
 * @property string|null $error_message
 * @property int $retry_attempts
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class InboxScanState extends Model
{
    use BelongsToUser;

    protected $table = 'inbox_scan_state';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'inbox_id',
        'folder',
        'last_history_id',
        'last_delta_link',
        'last_scan_at',
        'status',
        'error_message',
        'retry_attempts',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_scan_at' => 'immutable_datetime',
            'retry_attempts' => 'integer',
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
