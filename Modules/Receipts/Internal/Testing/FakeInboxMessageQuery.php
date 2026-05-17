<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Testing;

use Generator;
use Illuminate\Database\DatabaseManager;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\EmailScan\Public\Services\InboxMessageQuery;

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
 * The fake EXTENDS the real query class so consumers that type-hint
 * `InboxMessageQuery` resolve cleanly from the container when the
 * test binds the fake via `$this->app->instance(InboxMessageQuery::class, $fake)`.
 *
 * Internal-only — never registered by the ReceiptsServiceProvider,
 * only resolved inside test bootstraps that explicitly bind it.
 */
final readonly class FakeInboxMessageQuery extends InboxMessageQuery
{
    /**
     * @param  list<InboxMessageDto>  $messages
     */
    public function __construct(private array $messages, DatabaseManager $db)
    {
        // The parent's DatabaseManager dependency is irrelevant for
        // the in-memory fake — it never touches the database — but
        // the parent's readonly property still needs to be initialised
        // so the inherited class stays type-safe. Tests must pass the
        // app's resolved DatabaseManager through (the standard
        // `$this->app->make(DatabaseManager::class)` shape).
        parent::__construct($db);
    }

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
