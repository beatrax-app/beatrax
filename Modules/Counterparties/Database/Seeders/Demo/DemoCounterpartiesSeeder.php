<?php

declare(strict_types=1);

namespace Modules\Counterparties\Database\Seeders\Demo;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Every demo transaction goes through the production resolver rather than
// hand-rolled rows, so the demo data can only ever be shaped like a real import.
final class DemoCounterpartiesSeeder
{
    // Stands in for the aliases a user would build by hand: without them every
    // demo merchant resolves to type=unknown and the type-chip strip has no
    // `merchant` bucket to show.
    /**
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

    // The resolver never produces bank or self_account rows for this dataset —
    // its PayPal and bank-fee IBANs are not in the known-institution seed list —
    // so those two buckets have to be seeded directly to demo all six types.
    /**
     * @var list<array{type: string, slug: string, displayName: ?string, displayNameKey: ?string, iban: ?string, merchantName: ?string}>
     */
    private const EXTRA_COUNTERPARTIES = [
        [
            'type' => CounterpartyType::Bank->value,
            'slug' => 'asn-bank',
            'displayName' => 'ASN Bank',
            'displayNameKey' => null,
            'iban' => 'NL57ASNB0123456789',
            'merchantName' => null,
        ],
        [
            'type' => CounterpartyType::Bank->value,
            'slug' => 'international-card-services',
            'displayName' => 'International Card Services',
            'displayNameKey' => null,
            'iban' => 'NL09ABNA0596780870',
            'merchantName' => null,
        ],
        [
            'type' => CounterpartyType::SelfAccount->value,
            'slug' => 'self-asn-checking',
            'displayName' => null,
            'displayNameKey' => 'counterparty_own_bank_account',
            'iban' => 'NL57ASNB0123456789',
            'merchantName' => null,
        ],
        [
            'type' => CounterpartyType::SelfAccount->value,
            'slug' => 'self-paypal-wallet',
            'displayName' => null,
            'displayNameKey' => 'counterparty_own_paypal',
            'iban' => 'PAYPAL-DEMO-1',
            'merchantName' => null,
        ],
        [
            'type' => CounterpartyType::Personal->value,
            'slug' => 'maria-van-buren',
            'displayName' => 'Maria van Buren',
            'displayNameKey' => null,
            'iban' => 'NL51ABNA0987654321',
            'merchantName' => null,
        ],
        [
            'type' => CounterpartyType::Personal->value,
            'slug' => 'jeroen-de-vries',
            'displayName' => 'Jeroen de Vries',
            'displayNameKey' => null,
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
                    // A bank and a person are named the same in every
                    // language; "my current account" is not.
                    'display_name' => $row['displayNameKey'] === null
                        ? (string) $row['displayName']
                        : Lang::get('core::demo.'.$row['displayNameKey']),
                    'iban' => $row['iban'],
                    'merchant_name' => $row['merchantName'],
                    'metadata' => null,
                ],
            );
        }
    }

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

            // A resolution with a null counterpartyId is the self_account
            // short-circuit, not a failure — there is simply nothing to stamp.
            if ($resolution === null || $resolution->counterpartyId === null) {
                continue;
            }

            $this->db->connection()
                ->table('transactions')
                ->where('id', $tx->id)
                ->update(['counterparty_id' => $resolution->counterpartyId]);
        }
    }

    // The resolver reads four of these fields, but the DTO has no partial
    // constructor, so the whole row is rebuilt.
    private function reconstructCanonical(Transaction $tx): CanonicalTransaction
    {
        $paymentType = $tx->payment_type instanceof PaymentType
            ? $tx->payment_type
            : PaymentType::Unknown;

        // The driver decides whether the attribute bag hands this back as a
        // string or a float; the DTO insists on `?string`.
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
