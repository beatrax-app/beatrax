<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Import\Public\Dto\AliasMatchPreviewResultDto;
use stdClass;

/**
 * Read-only "test against my transactions" probe. Given a candidate
 * generalized pattern and a user id, walks the most-recent 500
 * transactions for that user and returns the total match count plus
 * the first five matched rows.
 *
 * The query is bounded by design: the consumer is a debounced live
 * input in Settings → Aliases (the rename popover preview line) and a
 * full-history scan on every keystroke would saturate SQLite WAL
 * contention. The 500-row window captures the recent statement-data
 * the user would visually verify against; an unmatched-by-recent
 * pattern is shown as `total: 0` which the consumer renders calmly.
 *
 * Pattern matching runs in PHP via `mb_strpos` / `mb_strtolower` —
 * never SQL LIKE — to mirror the RuleEvaluator defence: a user-
 * authored pattern never enters the SQL string. The query is
 * read-only; the service never issues an UPDATE or DELETE.
 *
 * Patterns under three characters are rejected with an explanatory
 * empty result. The threshold avoids the degenerate case where a one-
 * or two-character pattern matches thousands of unrelated rows.
 */
final class AliasMatchPreviewQuery
{
    private const MIN_PATTERN_LENGTH = 3;

    private const SCAN_LIMIT = 500;

    private const SAMPLE_LIMIT = 5;

    public function __construct(private readonly DatabaseManager $db) {}

    public function preview(string $generalizedPattern, int $userId): AliasMatchPreviewResultDto
    {
        $trimmed = trim($generalizedPattern);
        if (mb_strlen($trimmed) < self::MIN_PATTERN_LENGTH) {
            return AliasMatchPreviewResultDto::withoutMatches('Pattern is too short to test.');
        }

        /** @var iterable<stdClass> $rows */
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->select(['id', 'description', 'counterparty_name', 'booked_at', 'amount_minor'])
            ->orderByDesc('booked_at')
            ->limit(self::SCAN_LIMIT)
            ->get();

        $needle = mb_strtolower($trimmed);
        $matched = [];
        $total = 0;
        foreach ($rows as $row) {
            $description = isset($row->description) && is_string($row->description) ? $row->description : '';
            $counterparty = isset($row->counterparty_name) && is_string($row->counterparty_name) ? $row->counterparty_name : '';
            $haystack = mb_strtolower($description !== '' ? $description : $counterparty);
            if ($haystack === '') {
                continue;
            }
            if (mb_strpos($haystack, $needle) !== false) {
                $total++;
                if (count($matched) < self::SAMPLE_LIMIT) {
                    $matched[] = $row;
                }
            }
        }

        return AliasMatchPreviewResultDto::withMatches($total, $matched);
    }
}
