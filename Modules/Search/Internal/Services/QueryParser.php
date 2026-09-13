<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Modules\Core\Public\Support\PatternScan;

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

        // The whole run, read by AmountToken rather than gated by a figure
        // shape spelled a second time here. A token naming no amount stays in
        // the text: stripping one the filter then ignores reads to the typist
        // exactly like a filter that worked.
        $amountMatch = PatternScan::first('/\bamount:(\S+)/i', $remainder);
        $amountBound = $amountMatch === [] ? null : AmountToken::bound($amountMatch[1], $readerCurrency);
        if ($amountBound !== null) {
            $filters['amount'] = $amountBound;
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
