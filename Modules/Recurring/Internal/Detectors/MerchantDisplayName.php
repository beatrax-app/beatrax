<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;

// recurring_series.detected_name was copied straight from the clustering key,
// which is lower-cased with the punctuation stripped, so "Domino's Pizza"
// reached the review screen as "domino s pizza". merchants maps that key back
// to the name as written, plaintext because rule evaluation joins on it.
/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final readonly class MerchantDisplayName
{
    use CoercesScalars;

    public function __construct(private DatabaseManager $db) {}

    // Null when the merchant is unknown; callers keep the normalised key,
    // which is still the best string available.
    public function forNormalized(int $userId, string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        $row = $this->db->connection()->table('merchants')
            ->where('user_id', $userId)
            ->where('normalized_name', $normalized)
            ->first(['name']);

        if ($row === null) {
            return null;
        }

        $name = self::toString($row->name);

        return $name === '' ? null : $name;
    }
}
