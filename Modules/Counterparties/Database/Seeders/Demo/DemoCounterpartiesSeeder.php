<?php

declare(strict_types=1);

namespace Modules\Counterparties\Database\Seeders\Demo;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Walks every demo-tagged transaction and invokes the production
 * `CounterpartyResolver` — exactly the same code path the import
 * pipeline runs — so the resulting Counterparty rows mirror what a
 * real user's import would have produced. The seeder never
 * short-circuits the resolver chain; that is intentional: the
 * `/counterparties` UI surfaces, the type-chip statistics, and the
 * Triage queue would all read fundamentally different demo data if
 * the rows were hand-rolled.
 *
 * Idempotency: `CounterpartyResolver::resolve()` upserts via
 * `firstOrCreate(user_id, slug)` on every call, so running the demo
 * seeder twice converges on the same Counterparty row set without
 * duplicates. Demo transactions already carry stable fingerprints
 * across runs so the resolver's input is identical run-over-run.
 *
 * The seeder skips `transfer_in` rows whose paired `transfer_out`
 * the resolver already classified as `self_account` (i.e. the
 * ASN→PayPal top-up legs). The self-account branch never writes a
 * counterparty row, so re-iterating would be wasted work; the skip
 * is bookkeeping only.
 */
final class DemoCounterpartiesSeeder
{
    /**
     * Per-user merchant-alias seed list. The resolver's step-3
     * (`MerchantNameResolver`) walks per-user generalized patterns
     * first — so the same alias table the user would normally build
     * by hand through Settings → Aliases is what the demo seeder
     * pre-populates here. Without this set every merchant row would
     * fall through to type=`unknown` and the demo dataset would have
     * no `merchant` rows to render in the type-chip strip on the
     * /counterparties page.
     *
     * Pattern → friendly-name. Stored once per demo user so the
     * resolver's per-user explicit `where('user_id', $userId)` walk
     * finds them. The same dataset is shared across both demo users
     * because both interact with the same Dutch retailers.
     *
     * @var list<array{generalized: string, friendly: string}>
     */
    private const MERCHANT_ALIASES = [
        ['generalized' => 'ah filiaal', 'friendly' => 'Albert Heijn'],
        ['generalized' => 'ah to go', 'friendly' => 'Albert Heijn'],
        ['generalized' => 'jumbo', 'friendly' => 'Jumbo'],
        ['generalized' => 'lidl', 'friendly' => 'Lidl'],
        ['generalized' => 'dirk van den broek', 'friendly' => 'Dirk'],
        ['generalized' => 'hema', 'friendly' => 'HEMA'],
        ['generalized' => 'ns reizen', 'friendly' => 'NS Reizigers'],
        ['generalized' => 'domino', 'friendly' => "Domino's Pizza"],
        ['generalized' => 'la place', 'friendly' => 'La Place'],
        ['generalized' => 'spotify', 'friendly' => 'Spotify'],
        ['generalized' => 'netflix', 'friendly' => 'Netflix'],
        ['generalized' => 'kpn', 'friendly' => 'KPN'],
        ['generalized' => 'ziggo', 'friendly' => 'Ziggo'],
        ['generalized' => 'sport city', 'friendly' => 'Sport City'],
        ['generalized' => 'bol.com', 'friendly' => 'Bol.com'],
        ['generalized' => 'coolblue', 'friendly' => 'Coolblue'],
        ['generalized' => 'mediamarkt', 'friendly' => 'MediaMarkt'],
        ['generalized' => 'google *google play', 'friendly' => 'Google Play'],
        ['generalized' => 'booking.com', 'friendly' => 'Booking.com'],
        ['generalized' => 'cafe olivier', 'friendly' => 'Cafe Olivier'],
        ['generalized' => 'zilveren kruis', 'friendly' => 'Zilveren Kruis'],
        ['generalized' => 'vesteda', 'friendly' => 'Vesteda'],
        ['generalized' => 'woningstichting', 'friendly' => 'Woningstichting Centrum'],
        ['generalized' => 'gea asn bank', 'friendly' => 'ASN Bank ATM'],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CounterpartyResolver $resolver,
    ) {}

    /**
     * Per-user `bank` + `self_account` counterparty rows. The
     * production resolver short-circuits the `self_account` branch
     * before any upsert (intentional — self-account transfers do not
     * surface as a counterparty profile), and `bank` rows are only
     * written by the KnownCounterpartyIban resolver branch when a
     * recognised institution IBAN is in the seed list. The demo
     * dataset's PayPal funding + bank-fee IBANs are not in that seed
     * list, so without an explicit seed the dataset never carries
     * `bank` or `self_account` rows and the type-chip strip on the
     * `/counterparties` page would render only four of the six legal
     * type buckets.
     *
     * Idempotency: `updateOrCreate` keyed on `(user_id, slug)` matches
     * the UNIQUE on the counterparties table so a second seed run
     * reuses the existing row.
     *
     * @var list<array{type: string, slug: string, displayName: string, iban: ?string, merchantName: ?string}>
     */
    private const EXTRA_COUNTERPARTIES = [
        [
            'type' => 'bank',
            'slug' => 'asn-bank',
            'displayName' => 'ASN Bank',
            'iban' => 'NL57ASNB0123456789',
            'merchantName' => null,
        ],
        [
            'type' => 'bank',
            'slug' => 'international-card-services',
            'displayName' => 'International Card Services',
            'iban' => 'NL09ABNA0596780870',
            'merchantName' => null,
        ],
        [
            'type' => 'self_account',
            'slug' => 'self-asn-checking',
            'displayName' => 'My ASN checking account',
            'iban' => 'NL57ASNB0123456789',
            'merchantName' => null,
        ],
        [
            'type' => 'self_account',
            'slug' => 'self-paypal-wallet',
            'displayName' => 'My PayPal wallet',
            'iban' => 'PAYPAL-DEMO-1',
            'merchantName' => null,
        ],
        [
            'type' => 'personal',
            'slug' => 'maria-van-buren',
            'displayName' => 'Maria van Buren',
            'iban' => 'NL51ABNA0987654321',
            'merchantName' => null,
        ],
        [
            'type' => 'personal',
            'slug' => 'jeroen-de-vries',
            'displayName' => 'Jeroen de Vries',
            'iban' => 'NL92RABO0001234567',
            'merchantName' => null,
        ],
    ];

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        foreach ($users as $user) {
            $this->seedAliasesForUser($user);
            $this->resolveForUser($user);
            $this->seedExtraTypeCoverageForUser($user);
        }

        return Counterparty::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * Idempotently insert the bank + self_account rows that close the
     * type-coverage gap left by the resolver chain on the demo dataset.
     */
    private function seedExtraTypeCoverageForUser(User $user): void
    {
        foreach (self::EXTRA_COUNTERPARTIES as $row) {
            Counterparty::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'slug' => $row['slug'],
                ],
                [
                    'type' => $row['type'],
                    'display_name' => $row['displayName'],
                    'iban' => $row['iban'],
                    'merchant_name' => $row['merchantName'],
                    'metadata' => null,
                ],
            );
        }
    }

    /**
     * Idempotent insert of every demo-friendly merchant alias for the
     * given user. The `(user_id, pattern)` composite UNIQUE on the
     * merchant_aliases table guarantees a second seed run skips
     * existing rows; the seeder uses `updateOrInsert` so the keying
     * is explicit.
     */
    private function seedAliasesForUser(User $user): void
    {
        $now = (new \DateTimeImmutable)->format('Y-m-d H:i:s');

        foreach (self::MERCHANT_ALIASES as $row) {
            $this->db->connection()
                ->table('merchant_aliases')
                ->updateOrInsert(
                    [
                        'user_id' => $user->id,
                        'pattern' => $row['generalized'],
                    ],
                    [
                        'generalized_pattern' => $row['generalized'],
                        'friendly_name' => $row['friendly'],
                        'merged_from' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
        }
    }

    private function resolveForUser(User $user): void
    {
        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->orderBy('id')
            ->get();

        foreach ($transactions as $tx) {
            $canonical = $this->reconstructCanonical($tx);
            $resolution = $this->resolver->resolve($canonical, $user);

            // `resolve()` returns null only when the input carries
            // neither IBAN nor name nor description — pathological
            // data the demo seeder never produces. The conditional
            // upsert below also covers the `self_account` branch:
            // a non-null DTO with a null counterpartyId means the
            // resolver short-circuited before writing, so there is
            // nothing to stamp on the transaction either.
            if ($resolution === null || $resolution->counterpartyId === null) {
                continue;
            }

            $this->db->connection()
                ->table('transactions')
                ->where('id', $tx->id)
                ->update(['counterparty_id' => $resolution->counterpartyId]);
        }
    }

    /**
     * Rebuild the CanonicalTransaction shape from a persisted
     * Transaction model. The resolver only reads a subset of the
     * fields (type, counterpartyIban, counterpartyName, description),
     * but the DTO requires the full constructor signature so we
     * populate everything from the persisted row.
     */
    private function reconstructCanonical(Transaction $tx): CanonicalTransaction
    {
        $paymentType = $tx->payment_type instanceof PaymentType
            ? $tx->payment_type
            : PaymentType::Unknown;

        // SQLite returns DECIMAL columns as strings inside the
        // attribute bag but ConnectionResolver-cast values arrive as
        // floats here; the DTO insists on `?string`. Coerce the
        // attribute to a string when present so the DTO contract is
        // honoured regardless of the underlying driver's choice.
        $fxRateUsed = $tx->fx_rate_used;
        if ($fxRateUsed !== null && ! is_string($fxRateUsed)) {
            $fxRateUsed = (string) $fxRateUsed;
        }

        return new CanonicalTransaction(
            userId: $tx->user_id,
            accountId: $tx->account_id,
            type: $tx->type,
            postedAt: $tx->posted_at,
            bookedAt: $tx->booked_at,
            valueDate: $tx->value_date,
            amountMinor: $tx->amount_minor,
            currency: $tx->currency,
            settledAmountMinor: $tx->settled_amount_minor,
            settledCurrency: $tx->settled_currency,
            fxRateUsed: $fxRateUsed,
            counterpartyName: $tx->counterparty_name,
            counterpartyIban: $tx->counterparty_iban,
            counterpartyNormalized: $tx->counterparty_normalized,
            normalizationVersion: $tx->normalization_version,
            description: $tx->description,
            categoryId: $tx->category_id,
            sourceFormat: $tx->source_format,
            importRunId: $tx->import_run_id,
            sourceRowIndex: $tx->source_row_index,
            sourceRef: $tx->source_ref,
            rawPayload: $tx->raw_payload,
            autoCategoryProvenance: $tx->auto_category_provenance,
            paymentType: $paymentType,
        );
    }
}
