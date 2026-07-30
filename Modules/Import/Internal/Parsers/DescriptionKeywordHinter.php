<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Parsers;

use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Dto\PaymentTypeHint;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Scanning a description for the first matching keyword was written out four
// times, once per hinter, differing only in the table it scanned and whether
// it gated on the source format. The scan lives here so a change to how
// matching works — ordering, casing, what counts as a hit — is made once.
/**
 * @link ../../../../.docs/features/import/architecture.md#payment-type-hinters
 */
abstract class DescriptionKeywordHinter implements PaymentTypeHinter
{
    // Subclasses override both. Declared here so the scan reads them through
    // late static binding rather than through a pair of accessor methods that
    // every subclass would otherwise have to repeat verbatim.
    protected const SOURCE_FORMAT = '';

    /**
     * @var list<array{keyword: string, type: PaymentType, confidence: int}>
     */
    protected const KEYWORDS = [];

    // Overridden only by the fallback, which answers for every row regardless
    // of origin — that is what being last in the registry means.
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

    // A row from another parser and a row carrying no description are one
    // answer — there is nothing to scan — so they are asked as one question
    // rather than as two guards with identical bodies.
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
