<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

/**
 * AIS-only-by-construction access scope for the Enable Banking `/auth`
 * request body's `access` object (Req 11 / D-11 Pitfall 6, RESEARCH.md
 * Common Pitfalls §6).
 *
 * Exactly three constructor-promoted booleans — `balances`,
 * `transactions`, `accounts` — with no `payments` concept anywhere in
 * this class. A loosely-typed array literal could silently grow a
 * `payments` key (the PIS opt-in signal per general PSD2 aggregator
 * conventions) without anyone noticing; a typed DTO with a fixed
 * property list makes that structurally impossible — adding
 * payment-initiation scope would require editing this file, a
 * compile-time-visible, reviewable change rather than a silent runtime
 * array-key addition. This makes PHPStan level 10 strict itself part of
 * the AIS-only guard (RESEARCH.md's own framing).
 */
final readonly class EnableBankingAccessScope
{
    public function __construct(
        public bool $balances,
        public bool $transactions,
        public bool $accounts,
    ) {}

    /**
     * Emits only the keys that are true — never a `payments` key, since
     * no such property exists on this class.
     *
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        $out = [];

        if ($this->balances) {
            $out['balances'] = true;
        }
        if ($this->transactions) {
            $out['transactions'] = true;
        }
        if ($this->accounts) {
            $out['accounts'] = true;
        }

        return $out;
    }
}
