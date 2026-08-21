<?php

declare(strict_types=1);

namespace Modules\Receipts\Tests\Doubles;

use Generator;
use Illuminate\Database\DatabaseManager;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\EmailScan\Public\Services\InboxMessageQuery;

// The fake extends the real query so a container-bound instance still satisfies
// consumers type-hinting InboxMessageQuery. It lives under tests/Doubles rather
// than Internal/Testing so the PHPStan and boundary scans over production source
// never walk into it.
final readonly class FakeInboxMessageQuery extends InboxMessageQuery
{
    /**
     * @param  list<InboxMessageDto>  $messages
     */
    public function __construct(private array $messages, DatabaseManager $db)
    {
        // The fake never touches the database, but the parent's readonly
        // property still has to be initialised.
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
