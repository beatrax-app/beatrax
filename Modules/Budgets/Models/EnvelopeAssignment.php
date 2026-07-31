<?php

declare(strict_types=1);

namespace Modules\Budgets\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Public\Concerns\BelongsToUser;
use Modules\Ledger\Models\Category;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property CarbonImmutable $period_start
 * @property int $assigned_minor
 * @property string $currency
 */
final class EnvelopeAssignment extends Model
{
    use BelongsToUser;

    protected $fillable = [
        'user_id',
        'category_id',
        'period_start',
        'assigned_minor',
        'currency',
    ];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_minor' => 'integer',
        ];
    }

    /**
     * @link ../../../.docs/features/budgets/architecture.md
     *
     * @return Attribute<CarbonImmutable|null, string>
     */
    protected function periodStart(): Attribute
    {
        return Attribute::make(
            get: static fn (mixed $value): ?CarbonImmutable => is_string($value) && $value !== ''
                ? CarbonImmutable::parse($value)
                : null,
            set: static fn (mixed $value): string => self::periodStartToDateString($value),
        );
    }

    private static function periodStartToDateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::parse($value)->toDateString();
        }

        $raw = is_scalar($value) ? (string) $value : '';

        return CarbonImmutable::parse($raw)->toDateString();
    }
}
