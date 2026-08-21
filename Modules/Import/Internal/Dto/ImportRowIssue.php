<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Dto;

use Modules\Import\Internal\Enums\ImportFailureReason;
use Modules\Import\Internal\Enums\ImportIssueKind;

/**
 * @link ../../../../.docs/features/import/architecture.md#what-the-results-screen-can-still-say
 */
final class ImportRowIssue
{
    private const DETAIL_LIMIT = 240;

    public function __construct(
        public readonly ImportIssueKind $kind,
        public readonly ?int $rowIndex,
        public readonly ?ImportFailureReason $reason,
        public readonly ?string $detail,
    ) {}

    /**
     * @return array{kind: string, row: int|null, reason: string|null, detail: string|null}
     */
    public function toStored(): array
    {
        return [
            'kind' => $this->kind->value,
            'row' => $this->rowIndex,
            'reason' => $this->reason?->value,
            'detail' => $this->detail === null ? null : mb_substr($this->detail, 0, self::DETAIL_LIMIT),
        ];
    }

    // Hand-decoded rather than hydrated: the column outlives any shape this
    // release writes, so an entry from an older or corrupted payload is
    // dropped instead of throwing on the results screen.
    /**
     * @return list<self>
     */
    public static function listFromStored(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $issues = [];
        foreach ($stored as $entry) {
            $issue = self::fromStoredEntry($entry);
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    // The sentence the reader gets: the parser's own words when they were safe
    // to keep, the translated reason when they were not, and nothing rather
    // than a guess when neither is on record.
    public function describe(): ?string
    {
        return $this->detail ?? $this->reason?->label();
    }

    private static function fromStoredEntry(mixed $entry): ?self
    {
        if (! is_array($entry)) {
            return null;
        }

        $rawKind = $entry['kind'] ?? null;
        $kind = is_string($rawKind) ? ImportIssueKind::tryFrom($rawKind) : null;
        if ($kind === null) {
            return null;
        }

        $rawRow = $entry['row'] ?? null;
        $rawReason = $entry['reason'] ?? null;
        $rawDetail = $entry['detail'] ?? null;

        return new self(
            kind: $kind,
            rowIndex: is_int($rawRow) ? $rawRow : null,
            reason: is_string($rawReason) ? ImportFailureReason::tryFrom($rawReason) : null,
            detail: is_string($rawDetail) && $rawDetail !== '' ? $rawDetail : null,
        );
    }
}
