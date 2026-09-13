<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Support;

// `posted_at DESC, id DESC` reads as "the newest charge", but posted_at is a
// DATE and the id is a per-device autoincrement, so a day's charges ranked one
// way here and the other way there — and the top one is what the screen shows.
/**
 * @link ../../../../.docs/features/counterparties/architecture.md#which-charge-is-the-newest
 */
final class NewestTransactionFirst
{
    // The device-stable half of transactions_fingerprint_uq — that UNIQUE minus
    // user_id and account_id — plus the account named by its IBAN, since one
    // counterparty is charged across accounts and the six columns are unique
    // only within one. The keyed digest last: arbitrary, but agreed.
    public const string ACROSS_ACCOUNTS = 'posted_at desc, booked_at desc, amount_minor desc, currency desc, counterparty_normalized desc, occurrence_ordinal desc, '.self::ACCOUNT.'.iban desc';

    // The join alias the clause above reaches the account through.
    public const string ACCOUNT = 'charge_account';
}
