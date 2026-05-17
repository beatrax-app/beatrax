<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Testing;

use Generator;
use Modules\EmailScan\Public\Dto\InboxMessageDto;

/**
 * In-memory test double for `Modules\EmailScan\Public\Services\InboxMessageQuery`.
 *
 * Constructed with a fixed list of `InboxMessageDto` rows and yields
 * the subset matching the requested `forStatus(...)` filter — so
 * Wave 1 + Wave 2 matcher-consumer tests can drive the dispatch
 * loop without seeding real inbox_messages rows. Centralising the
 * fake here keeps the Phase-6 InboxMessageQuery contract stable;
 * tests bind this fake into the container via the standard
 * `instance()` substitution pattern.
 *
 * Internal-only — never registered by the ReceiptsServiceProvider,
 * only resolved inside test bootstraps that explicitly bind it.
 */
final class FakeInboxMessageQuery
{
    /**
     * @param  list<InboxMessageDto>  $messages
     */
    public function __construct(private readonly array $messages) {}

    /**
     * @return Generator<int, InboxMessageDto>
     */
    public function forStatus(string $status): Generator
    {
        foreach ($this->messages as $message) {
            if ($message->status === $status) {
                yield $message;
            }
        }
    }
}
