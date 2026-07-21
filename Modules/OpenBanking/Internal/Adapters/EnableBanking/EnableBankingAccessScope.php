<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Adapters\EnableBanking;

/**
 * @link ../../../../../.docs/features/open-banking/architecture.md
 */
final readonly class EnableBankingAccessScope
{
    public function __construct(
        public bool $balances,
        public bool $transactions,
        public bool $accounts,
    ) {}

    // Emits only the keys that are true — never a `payments` key, since no
    // such property exists on this class.
    /**
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
