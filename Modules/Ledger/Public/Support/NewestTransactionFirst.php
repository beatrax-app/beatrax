<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

// `posted_at DESC, id DESC` reads as "the newest charge", but posted_at is a
// DATE and the id is a per-device autoincrement, so a day's charges ranked one
// way here and the other way there — and the top one is what the screen shows.
// Public, because four modules rank this table and one clause is what agrees.
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

    // The same key as a row value, for a read that has to say "strictly before
    // THIS charge" rather than only rank. SQLite compares a row value
    // lexicographically, which is the comparison the clause above sorts by, so
    // a reader ordering one way and cutting the other cannot disagree.
    /**
     * @link ../../../../.docs/architecture/an-ordering-that-picks.md#a-comparison-over-the-same-key
     */
    public const string KEY_ACROSS_ACCOUNTS = '(posted_at, booked_at, amount_minor, currency, counterparty_normalized, occurrence_ordinal, '.self::ACCOUNT.'.iban)';

    // The join alias both clauses above reach the account through.
    public const string ACCOUNT = 'charge_account';
}
