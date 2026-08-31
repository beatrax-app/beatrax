<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Transfers\Public\Enums\CounterLegOrder;
use Modules\Transfers\Public\Support\CounterLegMatch;
use Modules\Transfers\Public\Support\CounterLegWindow;

final readonly class PairLookup
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function isPaired(int $txId, User $user): bool
    {
        return $this->db->connection()
            ->table('transactions')
            ->where('id', $txId)
            ->where('user_id', $user->id)
            ->whereNotNull('pair_transaction_id')
            ->exists();
    }

    public function partnerId(int $txId, User $user): ?int
    {
        $row = $this->db->connection()
            ->table('transactions')
            ->where('id', $txId)
            ->where('user_id', $user->id)
            ->first(['pair_transaction_id']);

        if ($row === null || $row->pair_transaction_id === null) {
            return null;
        }

        return self::toInt($row->pair_transaction_id);
    }

    // The one counter-leg search, for a caller holding the far side's account
    // and amount rather than a paired row's id. Every bound is the caller's,
    // down to the ordering: a default here would answer, silently and for the
    // other caller too, a question that caller never asked.
    public function counterLegOnAccount(CounterLegMatch $match, CounterLegWindow $window, User $user): ?int
    {
        $windowStart = $window->bookedAt->subDays($window->windowDays)->startOfDay()->toDateTimeString();
        $windowEnd = $window->bookedAt->addDays($window->windowDays)->endOfDay()->toDateTimeString();

        $query = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $match->accountId)
            ->whereIn('type', array_map(static fn (TransactionType $type): string => $type->value, $match->types))
            ->where('amount_minor', $match->amountMinor)
            ->whereBetween('booked_at', [$windowStart, $windowEnd]);

        if ($match->currency !== null) {
            $query->where('currency', $match->currency);
        }
        if ($match->unpairedOnly) {
            $query->whereNull('pair_transaction_id');
        }
        if ($match->excludeTransactionId !== null) {
            $query->where('id', '!=', $match->excludeTransactionId);
        }

        $ordered = match ($window->order) {
            CounterLegOrder::NearestToCentre => $query->orderByRaw(
                'ABS(julianday(booked_at) - julianday(?))',
                [$window->bookedAt->toDateTimeString()],
            ),
            CounterLegOrder::EarliestBooked => $query,
        };

        // Distance alone, and booked_at alone, both leave the last word to
        // SQLite — today's answer falls out of whichever index the planner
        // happens to pick. This tail is the same rule the planner was applying
        // by accident, written down: earlier date first, then lower id.
        $row = $ordered->orderBy('booked_at')->orderBy('id')->first(['id']);

        if ($row === null) {
            return null;
        }

        return self::toInt($row->id);
    }
}
