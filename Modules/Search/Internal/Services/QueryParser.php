<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

// Parses typed token syntax (account:/after:/before:/amount:/category:)
// out of the raw query string; tokens are stripped first, and the
// remaining text becomes the FTS text query.
final class QueryParser
{
    /**
     * @param  string  $readerCurrency  the money an `amount:` bound is typed in
     * @return array{textQuery: string, filters: array<string, mixed>}
     */
    public function parse(string $raw, string $readerCurrency): array
    {
        $filters = [];
        $remainder = $raw;

        $accountMatches = PatternScan::all('/\baccount:(\S+)/i', $remainder);
        if ($accountMatches[1] !== []) {
            $filters['accounts'] = $accountMatches[1];
            $remainder = PatternScan::replace('/\baccount:\S+/i', '', $remainder);
        }

        $afterMatch = PatternScan::first('/\bafter:(\d{4}-\d{2}(?:-\d{2})?)/i', $remainder);
        if ($afterMatch !== []) {
            $filters['after'] = $afterMatch[1];
            $remainder = PatternScan::replace('/\bafter:\S+/i', '', $remainder);
        }

        $beforeMatch = PatternScan::first('/\bbefore:(\d{4}-\d{2}(?:-\d{2})?)/i', $remainder);
        if ($beforeMatch !== []) {
            $filters['before'] = $beforeMatch[1];
            $remainder = PatternScan::replace('/\bbefore:\S+/i', '', $remainder);
        }

        // A bound written past the fraction this token's regex allowed was
        // truncated, not refused: `amount:12.500-13.000` reached the filter as
        // `12.50`, which is a hundredth of the dinar the reader typed.
        $decimals = MoneyInput::decimalPlaces($readerCurrency);
        $figure = '\d+'.($decimals === 0 ? '' : '(?:[.,]\d{1,'.$decimals.'})?');
        $amountMatch = PatternScan::first(
            '/\bamount:([<>]?'.$figure.'(?:-'.$figure.')?)/i',
            $remainder,
        );
        if ($amountMatch !== []) {
            $filters['amount'] = $amountMatch[1];
            $remainder = PatternScan::replace('/\bamount:\S+/i', '', $remainder);
        }

        $categoryMatch = PatternScan::first('/\bcategory:(\S+)/i', $remainder);
        if ($categoryMatch !== []) {
            $filters['category'] = $categoryMatch[1];
            $remainder = PatternScan::replace('/\bcategory:\S+/i', '', $remainder);
        }

        $textQuery = trim(PatternScan::replace('/\s{2,}/', ' ', $remainder));

        return [
            'textQuery' => $textQuery,
            'filters' => $filters,
        ];
    }
}
