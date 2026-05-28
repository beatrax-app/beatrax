<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

/**
 * Suggestion-banner output the triage queue renders above an unknown
 * counterparty card.
 *
 * `confidence` is one of `high` | `medium` | `low`. The triage page
 * formats the banner copy verbatim per UI-SPEC based on confidence:
 *
 *   - high   → `✨ Looks like **{name}** — confidence high`
 *   - medium → `✨ Maybe **{name}** — confidence medium`
 *   - low    → `Pattern match: **{name}** — confidence low. Verify before linking.`
 *
 * `reasoning` is the load-bearing 1-sentence rationale rendered as the
 * banner's sub-line. The suggestion is NEVER rendered without it.
 *
 * `suggestedCounterpartyName` is the canonical merchant name returned
 * by the resolver layer; the accept button copy uses it verbatim
 * (`Yes, link to {name} ↵`).
 */
final readonly class TriageSuggestion
{
    public function __construct(
        public string $suggestedCounterpartyName,
        public string $confidence,
        public string $reasoning,
    ) {}
}
