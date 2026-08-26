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
        $haystack = $this->searchableText($tx, $sourceFormat);
        if ($haystack === null) {
            return null;
        }

        foreach ($this->keywords() as $entry) {
            if ($this->carries($haystack, $entry['keyword'])) {
                return new PaymentTypeHint(
                    type: $entry['type'],
                    confidence: $entry['confidence'],
                    sourceHint: $entry['keyword'],
                );
            }
        }

        return null;
    }

    // Letters, not `\b`: a bank glues a time or a terminal number straight
    // onto its lexeme (`Betaalautomaat12:34`), which `\b` would refuse, while
    // an ordinary word that merely ends in one — `coffee`, `Feenstra`,
    // `Idealo` — is not the lexeme and must not match.
    private function carries(string $haystack, string $keyword): bool
    {
        return preg_match('/(?<!\p{L})'.preg_quote($keyword, '/').'(?!\p{L})/u', $haystack) === 1;
    }

    // An adapter whose file has one text column puts it in `counterpartyName`
    // and leaves `description` null, so reading the description alone made the
    // classification depend on that choice: the same Revolut refund row was
    // Refund with a description and Unknown without one.
    private function searchableText(CanonicalTransaction $tx, string $sourceFormat): ?string
    {
        if (! $this->handles($sourceFormat)) {
            return null;
        }

        $text = trim(($tx->description ?? '').' '.($tx->counterpartyName ?? ''));

        return $text === '' ? null : mb_strtolower($text);
    }
}
