<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Import\Public\Dto\AliasMatchPreviewResultDto;
use stdClass;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
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
