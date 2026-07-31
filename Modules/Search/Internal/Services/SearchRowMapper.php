<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Search\Public\Dto\SearchRowDto;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// The row-shaping half of a search: turn a raw joined transactions row
// (plus its optional FTS highlight row) into a SearchRowDto — decrypting
// the counterparty name, resolving the secondary-currency leg, and
// converting match sentinels into XSS-safe <mark> markup.
/**
 * @link ../../../../.docs/features/search/architecture.md
 */
final class SearchRowMapper
{
    use CoercesScalars;

    public function __construct(
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    public function map(stdClass $row, ?stdClass $highlight, int $userId): SearchRowDto
    {
        // Read-side decrypt — transactions.counterparty_name is
        // ciphertext at rest once encryption is enabled; a pass-through
        // no-op otherwise.
        $counterpartyName = $row->counterparty_name === null
            ? null
            : $this->codec->decryptValue('transactions', 'counterparty_name', self::toString($row->counterparty_name), $userId, ($this->session)())['value'];

        [$secondaryMinor, $secondaryCurrency] = $this->resolveSecondaryAmount($row, self::toString($row->display_currency));
        [$highlightedCounterparty, $snippet] = $this->extractHighlight($highlight, $counterpartyName);

        return new SearchRowDto(
            id: self::toInt($row->id),
            bookedAt: CarbonImmutable::parse(self::toString($row->booked_at))->format('d-m-Y'),
            counterpartyName: $counterpartyName,
            counterpartySlug: $this->counterpartySlug($row),
            categoryId: $row->category_id === null ? null : self::toInt($row->category_id),
            categoryName: $row->category_name === null ? null : self::toString($row->category_name),
            amountMinor: self::toInt($row->display_minor),
            amountCurrency: self::toString($row->display_currency),
            secondaryMinor: $secondaryMinor,
            secondaryCurrency: $secondaryCurrency,
            highlightedCounterparty: $highlightedCounterparty,
            snippet: $snippet,
        );
    }

    // Routes through brick/money (via the Ledger Money VO) so the palette
    // presents amounts exactly like the /transactions table and never
    // reintroduces float division for money.
    public function formatMinorAmount(int $minor, string $currency): string
    {
        return Money::ofMinor($minor, $currency)->format();
    }

    // The secondary leg is only shown when it exists and settles in a
    // different currency from the displayed one — same-currency or absent
    // legs collapse to null/null.
    /**
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveSecondaryAmount(stdClass $row, string $displayCurrency): array
    {
        if (! property_exists($row, 'secondary_currency') || ! property_exists($row, 'secondary_minor')) {
            return [null, null];
        }

        $secondaryCurrency = self::toString($row->secondary_currency);
        if ($secondaryCurrency === '' || $secondaryCurrency === $displayCurrency) {
            return [null, null];
        }

        return [self::toInt($row->secondary_minor), $secondaryCurrency];
    }

    private function counterpartySlug(stdClass $row): ?string
    {
        if (! property_exists($row, 'counterparty_slug') || $row->counterparty_slug === null) {
            return null;
        }

        $slug = self::toString($row->counterparty_slug);

        return $slug === '' ? null : $slug;
    }

    // Both fields carry control-char match sentinels; decorateHighlight()
    // escapes the raw text and converts the sentinels to <mark> tags so
    // the output is XSS-safe.
    /**
     * @return array{0: ?string, 1: ?string} highlighted counterparty, snippet
     */
    private function extractHighlight(?stdClass $highlight, ?string $counterpartyName): array
    {
        if ($highlight === null) {
            return [null, null];
        }

        $highlightedBody = self::toString($highlight->highlighted_body);
        $parts = explode(chr(12), $highlightedBody, 2);
        $highlightedCounterparty = $parts[0] !== '' && str_contains($parts[0], HighlightSentinels::START)
            ? self::decorateHighlight($parts[0])
            : null;

        // Strip the sentinels before comparing to the decrypted
        // counterparty name — otherwise the "don't repeat the
        // counterparty" dedup guard could never match once encryption is
        // enabled.
        $snippetBody = self::toString($highlight->snippet_body);
        $snippetPlain = str_replace([HighlightSentinels::START, HighlightSentinels::END], '', $snippetBody);
        $snippet = $snippetBody !== '' && $snippetPlain !== ($counterpartyName ?? '')
            ? self::decorateHighlight($snippetBody)
            : null;

        return [$highlightedCounterparty, $snippet];
    }

    // Escapes the raw text first, then swaps the control-char sentinels
    // for real <mark> tags — escaping before substitution neutralises any
    // HTML in the source text while the sentinels survive (htmlspecialchars
    // leaves STX/ETX untouched).
    private static function decorateHighlight(string $marked): string
    {
        $escaped = htmlspecialchars($marked, ENT_QUOTES, 'UTF-8');

        return str_replace(
            [HighlightSentinels::START, HighlightSentinels::END],
            ['<mark>', '</mark>'],
            $escaped,
        );
    }
}
