<?php

declare(strict_types=1);

namespace Modules\Core\Public\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Core\Public\Support\SafeDate;

/**
 * @implements CastsAttributes<CarbonImmutable|null, CarbonImmutable|DateTimeInterface|string|null>
 *
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-date-column-carrying-a-time
 */
final class DateOnlyCast implements CastsAttributes, SerializesCastableAttributes
{
    private const string FORMAT = 'Y-m-d';

    // Eloquent otherwise hands back the very object a writer set, so a column
    // read in the same request as it was written would still carry a time.
    public bool $withoutObjectCaching = true;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        $day = self::toDay($model, $key, $value);

        return $day === null ? null : CarbonImmutable::parse($day);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::toDay($model, $key, $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // The stored attribute, never $value: Eloquent runs its own
        // serializeDate() over a class cast that returns a date first, and that
        // rewrites the day into UTC before this is ever asked.
        return self::toDay($model, $key, $attributes[$key] ?? null);
    }

    private static function toDay(Model $model, string $key, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(self::FORMAT);
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s::$%s is a calendar date and takes a date, a DateTimeInterface or a date string, %s given.',
                $model::class,
                $key,
                get_debug_type($value),
            ));
        }

        // A day-shaped string is judged as a day: sync writes these columns
        // through the query builder rather than the model, so '2027-02-29'
        // reached the column and came back out of here as 1 March. Anything
        // longer is a stored timestamp whose time half is an artefact.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
            return CarbonImmutable::parse($value)->format(self::FORMAT);
        }

        $day = SafeDate::dayOrNull($value);

        return $day === null
            ? throw self::notADate($model, $key, $value)
            : $day->format(self::FORMAT);
    }

    private static function notADate(Model $model, string $key, string $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            '%s::$%s is a calendar date and %s is not one.',
            $model::class,
            $key,
            var_export($value, true),
        ));
    }
}
