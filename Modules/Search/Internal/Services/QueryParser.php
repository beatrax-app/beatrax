<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

// Parses typed token syntax (account:/after:/before:/amount:/category:)
// out of the raw query string; tokens are stripped first, and the
// remaining text becomes the FTS text query.
final class QueryParser
{
    /**
     * @return array{textQuery: string, filters: array<string, mixed>}
     */
    public function parse(string $raw): array
    {
        $filters = [];
        $remainder = $raw;

        $accountCount = preg_match_all('/\baccount:(\S+)/i', $remainder, $accountMatches);
        if ($accountCount > 0) {
            $filters['accounts'] = $accountMatches[1];
            $remainder = (string) preg_replace('/\baccount:\S+/i', '', $remainder);
        }

        $afterCount = preg_match('/\bafter:(\d{4}-\d{2}(?:-\d{2})?)/i', $remainder, $afterMatch);
        if ($afterCount > 0) {
            $filters['after'] = $afterMatch[1];
            $remainder = (string) preg_replace('/\bafter:\S+/i', '', $remainder);
        }

        $beforeCount = preg_match('/\bbefore:(\d{4}-\d{2}(?:-\d{2})?)/i', $remainder, $beforeMatch);
        if ($beforeCount > 0) {
            $filters['before'] = $beforeMatch[1];
            $remainder = (string) preg_replace('/\bbefore:\S+/i', '', $remainder);
        }

        $amountCount = preg_match(
            '/\bamount:([<>]?\d+(?:[.,]\d{1,2})?(?:-\d+(?:[.,]\d{1,2})?)?)/i',
            $remainder,
            $amountMatch,
        );
        if ($amountCount > 0) {
            $filters['amount'] = $amountMatch[1];
            $remainder = (string) preg_replace('/\bamount:\S+/i', '', $remainder);
        }

        $categoryCount = preg_match('/\bcategory:(\S+)/i', $remainder, $categoryMatch);
        if ($categoryCount > 0) {
            $filters['category'] = $categoryMatch[1];
            $remainder = (string) preg_replace('/\bcategory:\S+/i', '', $remainder);
        }

        $textQuery = trim((string) preg_replace('/\s{2,}/', ' ', $remainder));

        return [
            'textQuery' => $textQuery,
            'filters' => $filters,
        ];
    }
}
