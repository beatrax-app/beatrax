<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// The keyword scan lives here so a change to how matching works — ordering,
// casing, what counts as a hit — reaches all four hinters at once.
/**
 * @link ../../../../.docs/features/import/architecture.md#payment-type-hinters
 */
abstract class DescriptionKeywordHinter implements PaymentTypeHinter
{
    // Read through late static binding, so a subclass overrides the constant
    // rather than repeating a pair of accessors.
    protected const SOURCE_FORMAT = '';

    /**
     * @var list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    protected const KEYWORDS = [];

    // Only the fallback overrides this, to answer for every row.
    protected function handles(string $sourceFormat): bool
    {
        return $sourceFormat === static::SOURCE_FORMAT;
    }

    /**
     * @return list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    protected function keywords(): array
    {
        return static::KEYWORDS;
    }

    public function hint(CanonicalTransaction $tx, string $sourceFormat): ?PaymentTypeHint
    {
        $haystack = $this->searchableDescription($tx, $sourceFormat);
        if ($haystack === null) {
            return null;
        }

        foreach ($this->keywords() as $entry) {
            if (mb_strpos($haystack, $entry['keyword']) !== false) {
                return new PaymentTypeHint(
                    type: $entry['type'],
                    confidence: $entry['confidence'],
                    sourceHint: $entry['keyword'],
                );
            }
        }

        return null;
    }

    private function searchableDescription(CanonicalTransaction $tx, string $sourceFormat): ?string
    {
        if (! $this->handles($sourceFormat)) {
            return null;
        }

        if ($tx->description === null || $tx->description === '') {
            return null;
        }

        return mb_strtolower($tx->description);
    }
}
