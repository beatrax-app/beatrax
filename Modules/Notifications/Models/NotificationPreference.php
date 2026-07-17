<?php

declare(strict_types=1);

namespace Modules\Notifications\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the `notification_preferences` table — one row per
 * (user, device) pair (D-34). `NotificationPreferenceQuery` is the sole
 * Public read/write surface; direct model access stays inside this module.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_id
 * @property bool $reminders_enabled
 * @property bool $budget_nudges_enabled
 * @property string $digest_cadence
 * @property bool $savings_prompts_enabled
 * @property int $reminder_lead_days
 * @property bool $quiet_hours_enabled
 * @property string|null $quiet_hours_from
 * @property string|null $quiet_hours_to
 * @property bool $hide_details
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class NotificationPreference extends Model
{
    use BelongsToUser;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'device_id',
        'reminders_enabled',
        'budget_nudges_enabled',
        'digest_cadence',
        'savings_prompts_enabled',
        'reminder_lead_days',
        'quiet_hours_enabled',
        'quiet_hours_from',
        'quiet_hours_to',
        'hide_details',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reminders_enabled' => 'boolean',
            'budget_nudges_enabled' => 'boolean',
            'savings_prompts_enabled' => 'boolean',
            'reminder_lead_days' => 'integer',
            'quiet_hours_enabled' => 'boolean',
            'hide_details' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
