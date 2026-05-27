<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Dto;

/**
 * Output shape of `CounterpartyResolver::resolve()`.
 *
 * Carries the resolved counterparty type, the canonical display name
 * the UI renders, the per-user-unique slug used for URL routing, and
 * the optional IBAN / merchant-name / metadata payload. `counterpartyId`
 * is the auto-increment primary key of the upserted `counterparties`
 * row, or null when the resolution short-circuits without writing a
 * row (the `self_account` branch — the user's own account legs route
 * to the account view instead of materialising as a counterparty).
 *
 * `type` is one of: `merchant`, `personal`, `bank`, `government`,
 * `self_account`, `unknown`. The set is enforced at the database
 * boundary via paired BEFORE INSERT / BEFORE UPDATE OF type triggers
 * on the `counterparties` table; any branch that constructs this DTO
 * with a value outside the set fails loud at the upsert call site.
 *
 * Privacy posture for the `personal` type: `slug` is derived from the
 * display name (kebab-cased) only; the `iban` field IS populated when
 * the original transaction carried one, but it never leaks into the
 * slug — the slug is the URL-routing key and a personal IBAN never
 * appears in the URL. The Plan 17-06b profile page enforces the same
 * rule on the rendering side via an explicit "Show IBAN" toggle.
 */
final readonly class CounterpartyResolutionDto
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $type,
        public string $displayName,
        public string $slug,
        public ?string $iban,
        public ?string $merchantName,
        public array $metadata,
        public ?int $counterpartyId,
    ) {}
}
