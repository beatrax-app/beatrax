<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

/**
 * Parses typed token syntax out of the raw query string.
 *
 * Tokens:
 *   account:NAME   — restrict to a named account (multi-valued; space-separated tokens)
 *   after:YYYY-MM or after:YYYY-MM-DD — include transactions from this date onward
 *   before:YYYY-MM or before:YYYY-MM-DD — include transactions up to this date
 *   amount:>50 / amount:<100 / amount:50 — amount filter token
 *   category:NAME  — restrict to a category
 *
 * Precedence: tokens are parsed first (regex word-boundary match); the remaining
 * text after stripping all tokens becomes the FTS text query (RESEARCH OQ1).
 *
 * Returns the remainder as `textQuery` and extracted filters under `filters`.
 */
final class QueryParser
{
    /**
     * @return array{textQuery: string, filters: array<string, mixed>}
     */
    public function parse(string $raw): array
    {
        $filters = [];
        $remainder = $raw;

        // --- account:VALUE tokens (multi-valued) ---
        $accountCount = preg_match_all('/\baccount:(\S+)/i', $remainder, $accountMatches);
        if ($accountCount > 0) {
            $filters['accounts'] = $accountMatches[1];
            $remainder = (string) preg_replace('/\baccount:\S+/i', '', $remainder);
        }

        // --- after:YYYY-MM or after:YYYY-MM-DD ---
        $afterCount = preg_match('/\bafter:(\d{4}-\d{2}(?:-\d{2})?)/i', $remainder, $afterMatch);
        if ($afterCount > 0) {
            $filters['after'] = $afterMatch[1];
            $remainder = (string) preg_replace('/\bafter:\S+/i', '', $remainder);
        }

        // --- before:YYYY-MM or before:YYYY-MM-DD ---
        $beforeCount = preg_match('/\bbefore:(\d{4}-\d{2}(?:-\d{2})?)/i', $remainder, $beforeMatch);
        if ($beforeCount > 0) {
            $filters['before'] = $beforeMatch[1];
            $remainder = (string) preg_replace('/\bbefore:\S+/i', '', $remainder);
        }

        // --- amount:>50, amount:<100, amount:50 ---
        $amountCount = preg_match('/\bamount:([<>]?\d+(?:[.,]\d{1,2})?)/i', $remainder, $amountMatch);
        if ($amountCount > 0) {
            $filters['amount'] = $amountMatch[1];
            $remainder = (string) preg_replace('/\bamount:\S+/i', '', $remainder);
        }

        // --- category:NAME ---
        $categoryCount = preg_match('/\bcategory:(\S+)/i', $remainder, $categoryMatch);
        if ($categoryCount > 0) {
            $filters['category'] = $categoryMatch[1];
            $remainder = (string) preg_replace('/\bcategory:\S+/i', '', $remainder);
        }

        // Collapse multiple spaces and trim
        $textQuery = trim((string) preg_replace('/\s{2,}/', ' ', $remainder));

        return [
            'textQuery' => $textQuery,
            'filters' => $filters,
        ];
    }
}
