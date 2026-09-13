<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Support;

// Which of several candidate legs a search answers with is written into
// pair_transaction_id, a synced column, so it has to be the same leg on both
// devices — and `transactions.id` is not, being a per-device autoincrement.
/**
 * @link ../../../../.docs/features/transfers/architecture.md#which-leg-becomes-the-pair
 */
final class EarliestLegFirst
{
    // The device-stable half of transactions_fingerprint_uq: every column of
    // that UNIQUE except user_id and account_id, which are counted per device.
    // With the caller's account and amount pinned by the predicates, what is
    // left here is the rest of a unique key, so the order is total.
    public const string ON_ONE_ACCOUNT = 'booked_at, posted_at, amount_minor, currency, counterparty_normalized, occurrence_ordinal';

    // A search spanning accounts needs one term more, because the columns above
    // are unique only WITHIN an account. The account is named by its IBAN,
    // which is what it IS rather than what this device numbered it: it carries
    // unique(user_id, iban) and is never sealed.
    public const string ACROSS_ACCOUNTS = self::ON_ONE_ACCOUNT.', '.self::ACCOUNT.'.iban';

    // The join alias the clause above reaches the account through.
    public const string ACCOUNT = 'leg_account';
}
