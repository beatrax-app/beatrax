<?php

declare(strict_types=1);

namespace Modules\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * A user-saved report definition, optionally pinned to the dashboard
 * (Req 9/10, D-02). `definition` carries the full report definition payload
 * (metric/dimension/period/filters/currencyMode/viz/compare) as JSON.
 *
 * This model exists for the Sync/write-action Eloquent paths; aggregation
 * reads stay raw DatabaseManager per project convention (see
 * Modules\Reports\Internal\Aggregation\*Query classes).
 *
 * The `BelongsToUser` trait adds a global UserScope so resolving a foreign
 * `saved_reports.id` (client-supplied) safely returns nothing.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property array<string, mixed> $definition
 * @property bool $pinned
 * @property int|null $pin_order
 */
final class SavedReport extends Model
{
    use BelongsToUser;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'name',
        'definition',
        'pinned',
        'pin_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'pinned' => 'boolean',
            'pin_order' => 'integer',
        ];
    }
}
