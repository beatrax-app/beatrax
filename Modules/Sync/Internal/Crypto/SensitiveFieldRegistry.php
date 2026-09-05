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
     * @return array<string, string> {table}.{column} => why AEAD does not apply here
     *
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-identity-columns-that-are-still-plaintext-and-what-it-would-take-to-fix-them
     */
    public static function knowinglyPlaintext(): array
    {
        return [
            'accounts.iban' => 'Matched by equality in eleven raw predicates and the Account model, carries unique(user_id, iban), and is string(34) — too narrow to hold base64(nonce || ciphertext).',
            'accounts.slug' => 'Carries unique(user_id, slug), and AccountSlugResolver walks collisions with where(slug), which random-nonce ciphertext reads as always free.',
            'accounts.name' => 'Has no equality predicate of its own, but accounts.slug is Str::slug() of it and cannot be sealed, so encrypting the name leaves a readable copy one column over.',
            'categories.slug' => 'Carries unique(user_id, slug) plus a partial UNIQUE over the global rows, and those rows have user_id IS NULL while the codec keys on a user — there is no key to seal them under. Argued at length on the linked page, and absent from this list until now, which is the silence the list exists to break.',
            'counterparties.slug' => 'The URL segment of /counterparties/{slug} and the key CounterpartySlugResolver predicates its own collision walk on. It is therefore a readable shadow of the sealed display_name it is derived from, and the one bound on that shadow is that a name spelling an account number takes an opaque base instead: the profile hides the IBAN behind a Show-IBAN toggle, so the address bar must not spell it either.',
            'known_counterparty_ibans.real_iban' => 'The alias set every IBAN-matching resolver arm predicates on, carries unique(user_id, real_iban) plus its own index, and is string(34) — too narrow to hold base64(nonce || ciphertext). The enable-time sweep also reads it to recover the IBAN half of a chain-link signature.',
            'merchants.name' => 'The user\'s own naming of a merchant, joined to the keyed merchants.normalized_name, so sealing it would leave the readable copy reachable through that join anyway. Argued on the linked page.',
            'recurring_series.detected_name' => 'LikeNeedle::contains puts it in a raw LIKE ... ESCAPE predicate in EntityNameSearch, which a substring search over random-nonce ciphertext answers with nothing; it is also a copy of a name the ledger already holds beside a keyed column. Recorded here as "matched by nothing" until the LIKE arm was found, which is the shape a reason nobody re-checks decays into.',
            'recurring_series.display_name_override' => 'The other half of the LIKE predicate above: EntityNameSearch searches the pair together and LikeNeedle whitelists both column names. One of the two was recorded and the other was not, on the same table, holding the same kind of string.',
            'merchant_aliases.pattern' => 'The immutable first-seen raw description and the per-user identity column: it carries unique(user_id, pattern) and is the updateOrCreate match key in CreateMerchantAlias, so ciphertext would file every re-import as a new alias. It is a verbatim copy of the sealed transactions.description.',
            'merchant_aliases.generalized_pattern' => 'A predicate nowhere — the resolver reads every row for the user and matches in PHP — so this one is sealable, and sealing it would protect nothing. PatternGeneralizer only DROPS whitespace-split tokens and lowercases what survives, so every token here is a verbatim token of merchant_aliases.pattern, which carries the UNIQUE above and provably cannot be sealed. The tokens it drops are the card tail, terminal id, amount and date, which makes this the less identifying of the two columns, not the more. Sealing a strict subset of a string stored in the clear one column over is the objection that keeps accounts.name next to accounts.slug.',
            'discovered_senders.sender_email' => 'Carries unique(user_id, inbox_id, sender_email) and is the equality predicate DiscoveryScanJob walks before deciding a sender is already a candidate; under ciphertext every rescan discovers the same sender again.',
            'known_senders.email_pattern' => 'Carries unique(user_id, email_pattern), is the predicate the ICS seeder tests before inserting, and is an input to DerivedRowId::for(). Two devices have to compute one primary key from it, which a random nonce makes impossible.',
            'known_senders.label' => 'The rows the create migration seeds have user_id IS NULL while the codec keys on a user, so there is no key to seal them under — the categories.slug argument. It is also where PromoteDiscoveredSender copies discovered_senders.sender_name verbatim, so it is the readable end of that name however the other end is stored.',
            'migration_import_baseline.baseline_value' => 'The plaintext snapshot the three-way merge resolver compares an incoming value against; a baseline that reads back as different bytes on every write would report every field as changed. It snapshots the narrative and the payee name, permanently, in a table that syncs. Argued on the linked page since before this entry existed, which is the docs-yes/registry-no half of the silence categories.slug was the other half of.',
            'inbox_messages.subject' => self::unreachableFromTheQueue('The e-mail subject line, matched by nothing and indexed by nothing. It is the largest single entry on this list: a receipt subject spells the merchant, the amount and the order id, and the receipt matchers copy those same two strings into the sealed transactions.raw_payload.'),
            'inbox_messages.sender_name' => self::unreachableFromTheQueue('The display name off the From header, matched by nothing and indexed by nothing. The receipt matchers copy it into the sealed transactions.raw_payload beside the subject, so it is a shadow of a sealed column and not merely an unencrypted one.'),
            'inbox_messages.sender_email' => self::unreachableFromTheQueue('The From address. Selected and then domain-matched in PHP by DetectIcsStatementReadyJob rather than in SQL, so no predicate stands in the way.'),
            'file_imports.subject' => self::unreachableFromTheQueue('The receipt e-mail subject, write-only in production: nothing reads it back, and the preview screen renders the in-memory capture instead.'),
            'file_imports.sender_name' => self::unreachableFromTheQueue('Written as a literal null by the only production writer, so it holds nothing today; the entry exists so the column cannot start holding a name without this decision being re-made.'),
            'file_imports.sender_email' => self::unreachableFromTheQueue('The From address of the receipt mail. No unique, no index, no predicate — the row is found by provider_message_id.'),
            'file_imports.source_filename' => self::unreachableFromTheQueue('The name of the file the receipt came from. The drop-folder arm stores what the user called it, verbatim — the same string the log layer redacts, because a statement is named by the bank for the account it covers. Never selected, never matched: the row is found by provider_message_id.'),
            'discovered_senders.sender_name' => self::unreachableFromTheQueue('The display name beside the unsealable sender_email above, ordered by neither and predicated on by neither.'),
            'transaction_search_docs.search_body' => 'The full-text shadow: it holds decrypted counterparty names, descriptions and tax notes, because FTS over ciphertext matches nothing. Disclosed in the UI rather than sealed.',
        ];
    }

    // The eight mailbox columns are not held back by a predicate; they are held
    // back by where their writer runs. Every one is written and read from
    // ShouldQueue jobs alone, and a queue worker builds its own session and so
    // never holds the app-lock key the codec needs.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-columns-a-queue-worker-cannot-seal
     */
    private static function unreachableFromTheQueue(string $column): string
    {
        return $column.' Sealing it would make encryptAttrs() refuse the scan write and hand the reader a blob to match on, so the mailbox scan would stop rather than the subject line become private. What has to move first is on the linked page.';
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
