<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use DateTimeImmutable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
interface GmailApiClientContract
{
    /**
     * @param  list<string>  $senderPatterns
     * @return array{messages: list<array{id: string, threadId: string}>, nextPageToken: ?string, historyId: ?string, resultSizeEstimate: int}
     */
    public function listSenderMessages(
        int $inboxId,
        array $senderPatterns,
        ?string $pageToken,
        ?DateTimeImmutable $windowStart = null,
    ): array;

    public function getRawMessage(int $inboxId, string $providerMessageId): string;

    /**
     * @return array{history: list<array<string, mixed>>, historyId: ?string}
     */
    public function listHistory(int $inboxId, string $startHistoryId): array;

    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $excludeSenders
     * @return array{messages: list<array<string, mixed>>, nextPageToken: ?string}
     */
    public function listDiscoveryCandidates(
        int $inboxId,
        array $keywords,
        array $excludeSenders,
        ?string $pageToken = null,
    ): array;
}
