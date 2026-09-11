<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal;

use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;

// Holds the priority-sorted list of every SenderMatcher tagged
// receipts.matcher, sorted once at register time. dispatch() walks it
// in order and returns the first matcher whose canHandle() claims the
// message; matchers themselves never branch on each other.
final readonly class MatcherRegistry
{
    /** @param list<SenderMatcher> $matchers Sorted by priority() DESC. */
    public function __construct(private array $matchers) {}

    public function dispatch(MatcherInputDto $input, string $emlRaw): MatchOutcomeDto
    {
        $inboxMsg = $input->toInboxMessageDto();
        foreach ($this->matchers as $matcher) {
            if ($matcher->canHandle($inboxMsg)) {
                return $matcher->match($emlRaw)->fromMatcher($matcher->key());
            }
        }

        return MatchOutcomeDto::unmatched();
    }

    /**
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
