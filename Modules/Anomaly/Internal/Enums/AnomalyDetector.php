<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Enums;

// Declaration order IS the canonical `anomaly_alerts.reasons` order: paired
// devices reach the same JSON for one charge only if both sort by this list,
// so a case inserted in the middle re-orders every alert written after it.
enum AnomalyDetector: string
{
    case Large = 'large';

    case FirstTime = 'first_time';

    case Duplicate = 'duplicate';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $detector): string => $detector->value, self::cases());
    }

    // Each surface keeps its own copy of the three labels under its own
    // namespace, so the shared half is the leaf and the caller supplies the
    // group it lives under.
    public function labelKey(string $group): string
    {
        return $group.'.'.$this->value;
    }

    /**
     * @param  list<self>  $detectors
     * @return list<self>
     */
    public static function inCanonicalOrder(array $detectors): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => in_array($case, $detectors, true),
        ));
    }

    /**
     * @param  mixed  $values  a decoded `reasons` JSON list, or any untrusted list
     * @return list<self>
     */
    public static function listFrom(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $parsed = [];
        foreach ($values as $value) {
            $case = is_string($value) ? self::tryFrom($value) : null;
            if ($case !== null) {
                $parsed[] = $case;
            }
        }

        return $parsed;
    }
}
