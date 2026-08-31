<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Jobs;

use DateTimeImmutable;
use Modules\Core\Public\Contracts\Clock;
use Throwable;

// Owns the project Clock so every unparseable-date fallback here honours
// frozen time.
final readonly class ScanMessageMapper
{
    public function __construct(private Clock $clock) {}

    // Only messagesAdded carries a fetchable id; the messagesDeleted and
    // labelAdded shapes are ignored.
    /**
     * @param  list<array<string, mixed>>  $historyEntries
     * @return list<string>
     */
    public function extractGmailHistoryMessageIds(array $historyEntries): array
    {
        $ids = [];
        foreach ($historyEntries as $entry) {
            $added = $entry['messagesAdded'] ?? null;
            if (! is_array($added)) {
                continue;
            }
            foreach ($added as $msgAdded) {
                if (! is_array($msgAdded)) {
                    continue;
                }
                $message = $msgAdded['message'] ?? null;
                if (! is_array($message)) {
                    continue;
                }
                $id = $message['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    // Patterns starting with '@' match by domain suffix; anything else is a
    // case-insensitive substring match.
    /**
     * @param  list<string>  $patterns
     */
    public function matchesAnyPattern(string $senderAddr, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $p = strtolower($pattern);
            if (str_starts_with($p, '@')) {
                if (str_ends_with($senderAddr, $p)) {
                    return true;
                }
            } elseif (str_contains($senderAddr, $p)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $msgMeta
     */
    public function extractSenderAddress(array $msgMeta): string
    {
        $from = $msgMeta['from'] ?? null;
        if (! is_array($from)) {
            return '';
        }
        $emailAddress = $from['emailAddress'] ?? null;
        if (! is_array($emailAddress)) {
            return '';
        }
        $rawAddr = $emailAddress['address'] ?? null;

        return is_string($rawAddr) ? strtolower($rawAddr) : '';
    }

    /**
     * @param  array<string, mixed>  $msgMeta
     */
    public function extractProviderMessageId(array $msgMeta): string
    {
        return is_string($msgMeta['id'] ?? null) ? $msgMeta['id'] : '';
    }

    /**
     * @param  array<string, mixed>  $msgMeta
     */
    public function graphMessageInternalDate(array $msgMeta): ?DateTimeImmutable
    {
        $received = $msgMeta['receivedDateTime'] ?? null;
        if (! is_string($received) || $received === '') {
            return null;
        }

        return $this->safeParseDate($received);
    }

    public function safeParseDate(string $raw): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return $this->clock->now()->toDateTimeImmutable();
        }
    }
}
