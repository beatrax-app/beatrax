<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final readonly class RowMatcher
{
    public function __construct(
        private RuleEngine $engine,
        private RuleApplier $applier,
        private User $user,
        private int $userId,
        private SensitiveColumnCodec $codec,
        private Session $session,
        private bool $hasKek,
    ) {}

    /**
     * @return array<string, mixed> the fields actually changed (dirtyFields shape)
     */
    public function changedFields(stdClass $row, int $transactionId): array
    {
        $matched = $this->engine->match($this->matchInputFromRow($row), $this->user);

        return $this->applier->applyAtReapply($matched, $transactionId, $this->userId);
    }

    private function matchInputFromRow(stdClass $row): RuleMatchInput
    {
        $counterpartyName = is_string($row->counterparty_name) ? $row->counterparty_name : null;
        $description = is_string($row->description) ? $row->description : null;

        // decryptValue() is already a pass-through without encryption; this
        // guard only avoids a wasted keyring-load attempt on every row.
        if ($this->hasKek) {
            if ($counterpartyName !== null && $counterpartyName !== '') {
                $counterpartyName = $this->codec->decryptValue('transactions', 'counterparty_name', $counterpartyName, $this->userId, $this->session)['value'];
            }
            if ($description !== null && $description !== '') {
                $description = $this->codec->decryptValue('transactions', 'description', $description, $this->userId, $this->session)['value'];
            }
        }

        $settledAmountMinor = is_numeric($row->settled_amount_minor) ? (int) $row->settled_amount_minor : 0;
        $postedAt = is_string($row->posted_at) ? CarbonImmutable::parse($row->posted_at) : CarbonImmutable::now();

        return new RuleMatchInput(
            counterpartyName: $counterpartyName,
            description: $description,
            settledAmountMinor: $settledAmountMinor,
            postedAt: $postedAt,
        );
    }
}
