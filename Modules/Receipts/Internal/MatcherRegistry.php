<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal;

use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;

/**
 * Holds the priority-sorted list of every `SenderMatcher` bound under
 * the `receipts.matcher` container tag. Constructed once by the
 * `ReceiptsServiceProvider` register-time closure with the tagged
 * collection already sorted (priority descending).
 *
 * `dispatch()` walks the list in priority order and returns the first
 * matcher's `match()` result whose `canHandle()` claims responsibility.
 * If no matcher claims the message, the registry returns
 * `MatchOutcomeDto::unmatched()` — the consumer transitions the
 * source row to `status='unmatched'` so a future matcher addition
 * can re-process it.
 *
 * The registry is the only place that knows the ordering; matchers
 * themselves never branch on each other.
 */
final class MatcherRegistry
{
    /** @param list<SenderMatcher> $matchers Sorted by priority() DESC. */
    public function __construct(private readonly array $matchers) {}

    public function dispatch(MatcherInputDto $input, string $emlRaw): MatchOutcomeDto
    {
        $inboxMsg = $input->toInboxMessageDto();
        foreach ($this->matchers as $matcher) {
            if ($matcher->canHandle($inboxMsg)) {
                return $matcher->match($emlRaw);
            }
        }

        return MatchOutcomeDto::unmatched();
    }

    /**
     * Return the stable matcher keys in priority order. Used by the
     * consumer job's audit log and by tests that need to assert the
     * registered matcher inventory matches expectation.
     *
     * @return list<string>
     */
    public function supportedKeys(): array
    {
        return array_map(
            static fn (SenderMatcher $m): string => $m->key(),
            $this->matchers,
        );
    }
}
