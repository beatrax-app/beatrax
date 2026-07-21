<?php

declare(strict_types=1);

namespace Modules\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * @link ../../../.docs/features/reports/architecture.md
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
