<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Modules\Sync\Public\Services\BlindIndexCodec;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final class SensitiveFieldRegistry
{
    // Named here rather than in the map's values so the ledger's own
    // CounterpartyKey constants and this registry cannot drift into two
    // spellings of one domain, which would hash the same plaintext two ways.
    public const string DOMAIN_COUNTERPARTY_NORMALIZED = 'counterparty-normalized';

    public const string DOMAIN_COUNTERPARTY_IBAN = 'counterparty-iban';

    // The only list either encryption hook consults. A new entry lands here
    // after an explicit scope decision; the columns deliberately left out, and
    // the ones knowingly accepted as plaintext, are argued on the linked page.
    /**
     * @return list<string>
     */
    public static function columns(): array
    {
        return [
            'transactions.note',
            'transactions.description',
            'transactions.counterparty_name',
            'transactions.counterparty_iban',
            'transactions.raw_payload',
            'counterparties.display_name',
            'counterparties.merchant_name',
            'counterparties.iban',
            'tax_transaction_tags.note',
            'transaction_splits.note',
            // Notification content columns. `id`, `user_id`, `created_at`,
            // `read_at`, `dismissed_at`, and `state` are deliberately NOT
            // listed — the PK is matched in dedup WHERE clauses and the
            // timestamps drive KEK-less pruning/unread counts.
            'notifications.title',
            'notifications.body',
            'notifications.params',
            'notifications.trigger_type',
        ];
    }

    // The identity columns an at-rest audit found readable and this project has
    // decided NOT to encrypt. Recorded rather than merely absent, so "not on the
    // list" stops reading the same as "nobody looked", and so the predicate
    // guard's allowlist can be checked against a decision instead of a silence.
    /**
     * @return array<string, string> {table}.{column} => why AEAD cannot apply
     *
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-identity-columns-that-are-still-plaintext-and-what-it-would-take-to-fix-them
     */
    public static function knowinglyPlaintext(): array
    {
        return [
            'accounts.iban' => 'Matched by equality in eleven raw predicates and the Account model, carries unique(user_id, iban), and is string(34) — too narrow to hold base64(nonce || ciphertext).',
            'accounts.slug' => 'Carries unique(user_id, slug), and AccountSlugResolver walks collisions with where(slug), which random-nonce ciphertext reads as always free.',
            'accounts.name' => 'Has no equality predicate of its own, but accounts.slug is Str::slug() of it and cannot be sealed, so encrypting the name leaves a readable copy one column over.',
            'counterparties.slug' => 'The URL segment of /counterparties/{slug} and the key CounterpartySlugResolver predicates its own collision walk on.',
            'known_counterparty_ibans.real_iban' => 'The alias set every IBAN-matching resolver arm predicates on, carries unique(user_id, real_iban) plus its own index, and is string(34) — too narrow to hold base64(nonce || ciphertext). The enable-time sweep also reads it to recover the IBAN half of a chain-link signature.',
            'merchants.name' => 'The user\'s own naming of a merchant, joined to the keyed merchants.normalized_name, so sealing it would leave the readable copy reachable through that join anyway. Argued on the linked page.',
            'recurring_series.detected_name' => 'The display string the review screen renders, matched by nothing; it is a copy of a name the ledger already holds beside a keyed column.',
            'transaction_search_docs.search_body' => 'The full-text shadow: it holds decrypted counterparty names, descriptions and tax notes, because FTS over ciphertext matches nothing. Disclosed in the UI rather than sealed.',
        ];
    }

    // Columns holding a KEYED ONE-WAY DIGEST rather than ciphertext. Kept apart
    // from columns() because that list is an instruction, not a description:
    // everything acting on it applies AEAD, and AEAD over a column the database
    // has to match on is the failure this design exists to prevent.
    /**
     * @return array<string, list<string>> {table}.{column} => every domain its rows may derive under
     *
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    public static function blindIndexColumns(): array
    {
        return [
            'transactions.counterparty_normalized' => [self::DOMAIN_COUNTERPARTY_NORMALIZED],
            'merchants.normalized_name' => [self::DOMAIN_COUNTERPARTY_NORMALIZED],
            // A list rather than one value because this column genuinely holds
            // two: an expense series stores the counterparty matching key, an
            // income series the payer's keyed IBAN, and a reader that trusted a
            // single domain here would be wrong for every income row.
            'recurring_series.cluster_counterparty_key' => [
                self::DOMAIN_COUNTERPARTY_NORMALIZED,
                self::DOMAIN_COUNTERPARTY_IBAN,
            ],
        ];
    }

    // The closed set a digest may be derived under. BlindIndexCodec's message
    // is separator-joined rather than length-prefixed, so injectivity holds
    // only while no domain contains the separator — checking membership here
    // is what keeps that an enforced invariant instead of a coincidence.
    /**
     * @return list<string>
     */
    public static function blindIndexDomains(): array
    {
        $domains = [];

        foreach (self::blindIndexColumns() as $columnDomains) {
            foreach ($columnDomains as $domain) {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }

    // BlindIndexCodec::SENTINEL is the one value these columns hold in the
    // clear; a guard reading this list needs it to tell an unkeyed value that
    // is a decision from one that is a defect.
    public static function blindIndexSentinel(): string
    {
        return BlindIndexCodec::SENTINEL;
    }

    // DI-shim surface — constructor-injected consumers call this instance
    // method rather than the static accessor directly.
    public function isSensitive(string $table, string $field): bool
    {
        return in_array("{$table}.{$field}", self::columns(), true);
    }
}
