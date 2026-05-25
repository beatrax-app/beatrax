<?php

declare(strict_types=1);

namespace Modules\Community\Public\Events;

/**
 * Dispatched when a user successfully submits a suggested mapping from
 * the SuggestMappingModal. The event carries the userId + the
 * verbatim pattern the user submitted; downstream listeners can use
 * this signal to update per-user contribution counters, prune mystery
 * cards that have a pending suggestion, or hook analytics.
 *
 * The event is dispatched AFTER OpenExternalUrlAction has fired (so
 * we know the system browser launch succeeded), never on the failure
 * branch.
 */
final class MysteryMerchantSubmitted
{
    public function __construct(
        public readonly int $userId,
        public readonly string $pattern,
    ) {}
}
