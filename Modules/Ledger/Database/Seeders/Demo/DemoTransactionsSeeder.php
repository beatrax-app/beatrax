<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * @link ../../../../../.docs/features/ledger/architecture.md
 */
final class DemoTransactionsSeeder
{
    private const AH_COUNTERPARTY = 'Albert Heijn';

    // Consecutive calendar months the dataset spans, ending with the
    // current month. Three months of monthly-cadence rows plus the
    // windowed day-offset series produce the documented ~166-row set.
    private const MONTH_SPAN = 3;

    // Plausible mid-2026 EUR/USD mid-market rate for the USD-denominated
    // PayPal rows — the point is that the currency-mode toggle has
    // non-trivial data to convert, not that it matches a real provider.
    private const EUR_PER_USD = '0.92000000';

    // Inclusive upper bound for the day-offset series, set in run() to
    // the last day of the current calendar month so the row count is
    // stable on every run date; shared across the per-account methods.
    private CarbonImmutable $windowEnd;

    public function __construct(
        private readonly FingerprintComposer $fingerprints,
    ) {}

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array<string, Account>>  $accounts
     */
    public function run(array $users, array $accounts): int
    {
        // Anchor to the calendar-month boundary MONTH_SPAN-1 months back
        // (not a rolling today->subDays(89) cursor, which previously
        // clipped the oldest month's rows) via subMonthsNoOverflow so
        // end-of-month run dates never collapse two months into one.
        $today = CarbonImmutable::today();
        $windowStart = $today->subMonthsNoOverflow(self::MONTH_SPAN - 1)->startOfMonth();

        // Upper bound for the day-offset series: the last day of the
        // current calendar month, so the row count stays stable across
        // run dates (clamping to `today` would shrink a mid-month run).
        $this->windowEnd = $today->endOfMonth();

        // User 1: the active power-user persona — three accounts, ~120
        // transactions covering salary, household bills, groceries,
        // transit, and online purchases.
        if (isset($users['demo-1@beatrax.local'], $accounts['demo-1@beatrax.local'])) {
            $user = $users['demo-1@beatrax.local'];
            $perUserAccounts = $accounts['demo-1@beatrax.local'];

            $this->seedUser1AsnRows($user, $perUserAccounts['asn-demo-1'], $windowStart);
            $this->seedUser1IcsRows($user, $perUserAccounts['ics-demo-1'], $windowStart);
            $this->seedUser1PaypalRows($user, $perUserAccounts['paypal-demo-1'], $windowStart);

            $this->linkUser1Transfers($user);
        }

        // User 2: the secondary persona — proves multi-user isolation
        // works (the dashboard for user 2 must never show user 1's
        // data). Sparser slate.
        if (isset($users['demo-2@beatrax.local'], $accounts['demo-2@beatrax.local'])) {
            $user = $users['demo-2@beatrax.local'];
            $perUserAccounts = $accounts['demo-2@beatrax.local'];

            $this->seedUser2AsnRows($user, $perUserAccounts['asn-demo-2'], $windowStart);
            $this->seedUser2PaypalRows($user, $perUserAccounts['paypal-demo-2'], $windowStart);
        }

        return Transaction::query()
            ->where('source_format', 'demo')
            ->count();
    }

    private function seedUser1AsnRows(User $user, Account $asn, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $asn);
        $rowIndex = 0;
        $inserted = 0;

        // The fixed monthly slate: salary in on the 25th, then the direct
        // debits that leave on the same day every month. Written in the
        // order they are seeded — the row index feeds each fingerprint, so
        // reordering these entries would rewrite the dataset's identity.
        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, $windowStart, [
            ['day' => 25, 'type' => 'income', 'amountMinor' => 385000, 'description' => 'Salaris MijnWerkgever BV', 'counterpartyName' => 'MijnWerkgever BV', 'counterpartyIban' => 'NL44RABO0123456789', 'paymentType' => PaymentType::Transfer, 'categorySlug' => 'income-salary'],
            ['day' => 1, 'type' => 'expense', 'amountMinor' => -125000, 'description' => 'Huur Vesteda', 'counterpartyName' => 'Vesteda', 'counterpartyIban' => 'NL36INGB0007654321', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'housing-rent'],
            ['day' => 3, 'type' => 'expense', 'amountMinor' => -4500, 'description' => 'KPN Mobile + Internet', 'counterpartyName' => 'KPN BV', 'counterpartyIban' => 'NL27INGB0010040004', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'housing-internet'],
            ['day' => 5, 'type' => 'expense', 'amountMinor' => -5995, 'description' => 'Ziggo abonnement', 'counterpartyName' => 'Ziggo', 'counterpartyIban' => 'NL05INGB0700057757', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'housing-internet'],
            ['day' => 1, 'type' => 'expense', 'amountMinor' => -2500, 'description' => 'Sport City', 'counterpartyName' => 'Sport City Nederland BV', 'counterpartyIban' => 'NL02ABNA0123456789', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'subscriptions-memberships'],
            ['day' => 28, 'type' => 'expense', 'amountMinor' => -14250, 'description' => 'Zilveren Kruis Zorgverzekering', 'counterpartyName' => 'Zilveren Kruis', 'counterpartyIban' => 'NL39INGB0686806266', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'insurance-health'],
            ['day' => 27, 'type' => 'expense', 'amountMinor' => -8500, 'description' => 'Belastingdienst motorrijtuigenbelasting', 'counterpartyName' => 'Belastingdienst', 'counterpartyIban' => 'NL86INGB0002445588', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => null],
        ]);

        // Albert Heijn — weekly groceries on Saturday + a midweek top-up on
        // Wednesday. Amounts vary per week to look natural.
        $groceriesCategory = $this->categoryId('groceries');
        $inserted += $this->seedUser1AhWeekly($user, $asn, $run, $rowIndex, $windowStart, $groceriesCategory);

        // Jumbo + Lidl + Dirk + Hema diversity — one each per month
        // (MONTH_SPAN amounts each, oldest month first). Each list used to
        // carry a fourth leading amount that nothing could reach: it paired
        // with the month before the window, which every series then skipped.
        $diversityRows = [
            ['name' => 'Jumbo', 'description' => 'Jumbo Supermarkt Utrecht', 'amounts' => [-3211, -2890, -4055], 'category' => $groceriesCategory, 'iban' => null, 'paymentType' => PaymentType::Pin],
            ['name' => 'Lidl', 'description' => 'Lidl Filiaal 0042', 'amounts' => [-1989, -2210, -1875], 'category' => $groceriesCategory, 'iban' => null, 'paymentType' => PaymentType::Pin],
            ['name' => 'Dirk', 'description' => 'Dirk van den Broek', 'amounts' => [-2755, -3120, -2480], 'category' => $groceriesCategory, 'iban' => null, 'paymentType' => PaymentType::Pin],
            ['name' => 'HEMA', 'description' => 'HEMA bv Utrecht', 'amounts' => [-1295, -1750, -2105], 'category' => $this->categoryId('personal-care'), 'iban' => null, 'paymentType' => PaymentType::Pin],
        ];
        foreach ($diversityRows as $merchant) {
            foreach ($this->monthlyDates($windowStart, 14, olderMonthStride: 2) as $idx => $date) {
                $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                    'type' => 'expense',
                    'amountMinor' => $merchant['amounts'][$idx] ?? -2500,
                    'description' => $merchant['description'],
                    'counterpartyName' => $merchant['name'],
                    'counterpartyIban' => $merchant['iban'],
                    'date' => $date,
                    'paymentType' => $merchant['paymentType'],
                    'categoryId' => $merchant['category'],
                ]);
            }
        }

        $inserted += $this->seedUser1NsTransit($user, $asn, $run, $rowIndex, $windowStart, $this->categoryId('transport-public'));

        $eatingOutCategory = $this->categoryId('eating-out');
        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, $windowStart, [
            ['day' => 12, 'type' => 'expense', 'amountMinor' => -2095, 'description' => "Domino's Pizza Utrecht", 'counterpartyName' => "Domino's Pizza", 'counterpartyIban' => null, 'paymentType' => PaymentType::Pin, 'categorySlug' => 'eating-out'],
        ]);
        foreach ([0, 14, 28, 42, 56, 70, 84] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->lessThan($windowStart) || $date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -1450,
                'description' => 'La Place CS Utrecht',
                'counterpartyName' => 'La Place',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Pin,
                'categoryId' => $eatingOutCategory,
            ]);
        }

        // The cash withdrawal, the two internal legs and the P2P transfer.
        // linkUser1Transfers() and the Chains demo seeder find their rows by
        // description rather than by position, so this stays a table.
        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, $windowStart, [
            ['day' => 8, 'type' => 'expense', 'amountMinor' => -10000, 'description' => 'GEA ASN BANK Utrecht', 'counterpartyName' => 'ASN Bank GEA', 'counterpartyIban' => null, 'paymentType' => PaymentType::Cash, 'categorySlug' => 'cash-withdrawal'],
            ['day' => 10, 'type' => 'transfer_out', 'amountMinor' => -10000, 'description' => 'PayPal top-up', 'counterpartyName' => 'PayPal', 'counterpartyIban' => 'PAYPAL-DEMO-1', 'paymentType' => PaymentType::Transfer, 'categorySlug' => 'transfers-internal'],
            ['day' => 18, 'type' => 'transfer_out', 'amountMinor' => -22500, 'description' => 'ICS afrekening MasterCard', 'counterpartyName' => 'International Card Services', 'counterpartyIban' => 'NL09ABNA0596780870', 'paymentType' => PaymentType::Transfer, 'categorySlug' => 'transfers-internal'],
            ['day' => 20, 'type' => 'transfer_out', 'amountMinor' => -2500, 'description' => 'Tikkie aandeel diner', 'counterpartyName' => 'M VAN BUREN', 'counterpartyIban' => 'NL51ABNA0987654321', 'paymentType' => PaymentType::Transfer, 'categorySlug' => null],
        ]);

        return $inserted;
    }

    private function seedUser1IcsRows(User $user, Account $ics, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $ics);
        $rowIndex = 0;
        $inserted = 0;

        $onlineCategory = $this->categoryId('subscriptions-cloud');
        $bolAmounts = [-3500, -1295, -4995, -2150, -1875, -5495, -2799];
        foreach ([2, 9, 16, 23, 38, 52, 70] as $i => $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $ics, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => $bolAmounts[$i] ?? -2500,
                'description' => 'BOL.COM B.V. UTRECHT',
                'counterpartyName' => 'Bol.com',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $onlineCategory,
            ]);
        }

        $inserted += $this->insertTransaction($user, $ics, $run, $rowIndex++, [
            'type' => 'expense',
            'amountMinor' => -29900,
            'description' => 'COOLBLUE ROTTERDAM',
            'counterpartyName' => 'Coolblue',
            'counterpartyIban' => null,
            'date' => $windowStart->addDays(45),
            'paymentType' => PaymentType::Online,
            'categoryId' => null,
        ]);

        foreach ([20, 68] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $ics, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => $dayOffset === 20 ? -8995 : -12450,
                'description' => 'MEDIAMARKT UTRECHT',
                'counterpartyName' => 'MediaMarkt',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => null,
            ]);
        }

        $inserted += $this->insertTransaction($user, $ics, $run, $rowIndex++, [
            'type' => 'expense',
            'amountMinor' => -18900,
            'description' => 'BOOKING.COM AMSTERDAM',
            'counterpartyName' => 'Booking.com',
            'counterpartyIban' => null,
            'date' => $windowStart->addDays(33),
            'paymentType' => PaymentType::Online,
            'categoryId' => null,
        ]);

        $eatingOutCategory = $this->categoryId('eating-out');
        foreach ([6, 19, 27, 41, 55, 73, 81] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $ics, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -(($dayOffset * 73) % 4500 + 2500),
                'description' => 'RESTAURANT CAFE OLIVIER',
                'counterpartyName' => 'Cafe Olivier',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $eatingOutCategory,
            ]);
        }

        // The monthly ICS card settlement — an inbound transfer from
        // the ASN account zeroing the card. Three of them across the
        // window. These rows are the `to_transaction` side of the
        // ics_bulk_settle chain.
        $inserted += $this->seedMonthlySeries($user, $ics, $run, $rowIndex, $windowStart, [
            ['day' => 18, 'type' => 'transfer_in', 'amountMinor' => 22500, 'description' => 'Afrekening MasterCard ICS', 'counterpartyName' => 'ASN Bank', 'counterpartyIban' => 'NL57ASNB0123456789', 'paymentType' => PaymentType::Transfer, 'categorySlug' => 'transfers-internal'],
        ]);

        return $inserted;
    }

    private function seedUser1PaypalRows(User $user, Account $paypal, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $paypal);
        $rowIndex = 0;
        $inserted = 0;

        $inserted += $this->seedMonthlySeries($user, $paypal, $run, $rowIndex, $windowStart, [
            ['day' => 11, 'type' => 'expense', 'amountMinor' => -1099, 'description' => 'Spotify Premium', 'counterpartyName' => 'Spotify AB', 'counterpartyIban' => null, 'paymentType' => PaymentType::Online, 'categorySlug' => 'subscriptions-music'],
            ['day' => 15, 'type' => 'expense', 'amountMinor' => -1499, 'description' => 'Netflix.com', 'counterpartyName' => 'Netflix International BV', 'counterpartyIban' => null, 'paymentType' => PaymentType::Online, 'categorySlug' => 'subscriptions-streaming'],
        ]);

        // Google Play — five USD-denominated rows so the FX surface
        // has data. Amounts are USD minor units; settled in EUR via
        // the demo cross-rate.
        $usdAmounts = [-499, -999, -299, -1299, -599];
        foreach ([4, 22, 39, 58, 79] as $i => $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $amountUsd = $usdAmounts[$i] ?? -499;
            $settledEur = (int) round($amountUsd * (float) self::EUR_PER_USD);
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => $amountUsd,
                'currency' => 'USD',
                'settledAmountMinor' => $settledEur,
                'settledCurrency' => Currency::Eur->value,
                'fxRateUsed' => self::EUR_PER_USD,
                'description' => 'GOOGLE *Google Play',
                'counterpartyName' => 'Google Play',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $this->categoryId('subscriptions-cloud'),
            ]);
        }

        // The Bol.com purchase and the ASN→PayPal funding that covers it,
        // both on the 10th. The chain_link wires that pair, and
        // linkUser1Transfers() pairs the transfer_in with its ASN leg.
        $inserted += $this->seedMonthlySeries($user, $paypal, $run, $rowIndex, $windowStart, [
            ['day' => 10, 'type' => 'expense', 'amountMinor' => -7995, 'description' => 'Bol.com via PayPal', 'counterpartyName' => 'Bol.com', 'counterpartyIban' => null, 'paymentType' => PaymentType::Online, 'categorySlug' => 'subscriptions-cloud'],
            ['day' => 10, 'type' => 'transfer_in', 'amountMinor' => 10000, 'description' => 'Top-up from ASN', 'counterpartyName' => 'ASN Bank', 'counterpartyIban' => 'NL57ASNB0123456789', 'paymentType' => PaymentType::Transfer, 'categorySlug' => 'transfers-internal'],
        ]);

        // Bol.com + Coolblue refunds — two rows so the `refund` type
        // and the `Refund` PaymentType chip both have multiple
        // datapoints to render against.
        $refundsCategory = $this->categoryId('income-refunds');
        $refundRows = [
            ['day' => 35, 'amount' => 1250, 'description' => 'Retour Bol.com', 'merchant' => 'Bol.com'],
            ['day' => 62, 'amount' => 3499, 'description' => 'Retour Coolblue', 'merchant' => 'Coolblue'],
        ];
        foreach ($refundRows as $refund) {
            $date = $windowStart->addDays($refund['day']);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'refund',
                'amountMinor' => $refund['amount'],
                'description' => $refund['description'],
                'counterpartyName' => $refund['merchant'],
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Refund,
                'categoryId' => $refundsCategory,
            ]);
        }

        // PayPal cross-currency conversion fee — two rows so the `fee`
        // type + `Fee` PaymentType chip both have data to render against
        // on the /transactions list.
        foreach ([29, 73] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'fee',
                'amountMinor' => -150,
                'description' => 'PayPal conversion fee',
                'counterpartyName' => 'PayPal',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Fee,
                'categoryId' => null,
            ]);
        }

        // PayPal balance adjustment — two rows: one positive (PayPal
        // gives store credit after a chargeback) and one negative (PayPal
        // claws back a previously-applied promo). Exercises the
        // `adjustment` type chip + the `Unknown` PaymentType fallback.
        $adjustmentRows = [
            ['day' => 21, 'amount' => 500, 'description' => 'PayPal goodwill credit'],
            ['day' => 64, 'amount' => -750, 'description' => 'PayPal promo clawback'],
        ];
        foreach ($adjustmentRows as $row) {
            $date = $windowStart->addDays($row['day']);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'adjustment',
                'amountMinor' => $row['amount'],
                'description' => $row['description'],
                'counterpartyName' => 'PayPal',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Unknown,
                'categoryId' => null,
            ]);
        }

        return $inserted;
    }

    private function seedUser2AsnRows(User $user, Account $asn, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $asn);
        $rowIndex = 0;
        $inserted = 0;

        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, $windowStart, [
            ['day' => 25, 'type' => 'income', 'amountMinor' => 285000, 'description' => 'Salaris StichtingZorg', 'counterpartyName' => 'StichtingZorg', 'counterpartyIban' => 'NL93RABO0987654321', 'paymentType' => PaymentType::Transfer, 'categorySlug' => 'income-salary'],
            ['day' => 1, 'type' => 'expense', 'amountMinor' => -89500, 'description' => 'Huur Woningstichting', 'counterpartyName' => 'Woningstichting Centrum', 'counterpartyIban' => 'NL70INGB0001112223', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'housing-rent'],
        ]);

        $groceriesCategory = $this->categoryId('groceries');
        foreach ([3, 10, 17, 24, 38, 52, 66, 80] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -(($dayOffset * 89) % 3000 + 2500),
                'description' => 'AH Filiaal 8901 Den Haag',
                'counterpartyName' => self::AH_COUNTERPARTY,
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Pin,
                'categoryId' => $groceriesCategory,
            ]);
        }

        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, $windowStart, [
            ['day' => 22, 'type' => 'expense', 'amountMinor' => -6500, 'description' => 'Gemeente Den Haag woonlasten', 'counterpartyName' => 'Gemeente Den Haag', 'counterpartyIban' => 'NL03INGB0698027001', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => null],
        ]);

        return $inserted;
    }

    private function seedUser2PaypalRows(User $user, Account $paypal, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $paypal);
        $rowIndex = 0;
        $inserted = 0;

        $inserted += $this->seedMonthlySeries($user, $paypal, $run, $rowIndex, $windowStart, [
            ['day' => 9, 'type' => 'expense', 'amountMinor' => -1099, 'description' => 'Spotify Premium', 'counterpartyName' => 'Spotify AB', 'counterpartyIban' => null, 'paymentType' => PaymentType::Online, 'categorySlug' => 'subscriptions-music'],
        ]);

        $onlineCategory = $this->categoryId('subscriptions-cloud');
        foreach ([14, 47, 76] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -(($dayOffset * 31) % 5000 + 1500),
                'description' => 'BOL.COM via PayPal',
                'counterpartyName' => 'Bol.com',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $onlineCategory,
            ]);
        }

        return $inserted;
    }

    // Walks the freshly-seeded ASN→PayPal transfer pairs for user 1 and
    // links each pair via pair_transaction_id, mirroring the production
    // Layer-1 pair-detection listener so demo data carries the same
    // relationship shape consumers (chains, recurring, queries) expect.
    private function linkUser1Transfers(User $user): void
    {
        $pairs = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->whereIn('type', TransactionType::transferValues())
            ->whereIn('description', ['PayPal top-up', 'Top-up from ASN'])
            ->get(['id', 'type', 'posted_at', 'amount_minor', 'description']);

        $byDate = [];
        foreach ($pairs as $tx) {
            $key = $tx->posted_at->toDateString();
            $byDate[$key][$tx->type][] = $tx->id;
        }

        foreach ($byDate as $key => $bucket) {
            if (! isset($bucket['transfer_out'], $bucket['transfer_in'])) {
                continue;
            }
            $outIds = $bucket['transfer_out'];
            $inIds = $bucket['transfer_in'];
            $pairCount = min(count($outIds), count($inIds));
            for ($i = 0; $i < $pairCount; $i++) {
                $outId = $outIds[$i];
                $inId = $inIds[$i];
                Transaction::query()->where('id', $outId)->update(['pair_transaction_id' => $inId]);
                Transaction::query()->where('id', $inId)->update(['pair_transaction_id' => $outId]);
            }
        }
    }

    // Walks the window one week at a time from the first Saturday, seeding a
    // Saturday grocery run plus a Wednesday top-up (the row three days back).
    // rowIndex advances by reference so the fingerprints stay identical to
    // the inline walk this replaced.
    private function seedUser1AhWeekly(
        User $user,
        Account $asn,
        ImportRun $run,
        int &$rowIndex,
        CarbonImmutable $windowStart,
        ?int $groceriesCategory,
    ): int {
        $inserted = 0;
        $ahAmounts = [-6754, -5421, -7188, -4998, -6342, -5876, -7011, -5188, -6655, -7290, -5511, -6020];
        $cursor = $windowStart->startOfWeek(CarbonImmutable::SATURDAY);
        $i = 0;
        while ($cursor->lessThanOrEqualTo($this->windowEnd)) {
            if ($cursor->greaterThanOrEqualTo($windowStart) && isset($ahAmounts[$i])) {
                $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                    'type' => 'expense',
                    'amountMinor' => $ahAmounts[$i],
                    'description' => 'AH Filiaal 1234 Utrecht',
                    'counterpartyName' => self::AH_COUNTERPARTY,
                    'counterpartyIban' => null,
                    'date' => $cursor,
                    'paymentType' => PaymentType::Pin,
                    'categoryId' => $groceriesCategory,
                ]);
                $midweek = $cursor->subDays(3);
                if ($midweek->greaterThanOrEqualTo($windowStart) && isset($ahAmounts[$i + 6])) {
                    $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                        'type' => 'expense',
                        'amountMinor' => intdiv($ahAmounts[$i + 6] ?? -2500, 2),
                        'description' => 'AH To Go Utrecht CS',
                        'counterpartyName' => self::AH_COUNTERPARTY,
                        'counterpartyIban' => null,
                        'date' => $midweek,
                        'paymentType' => PaymentType::Pin,
                        'categoryId' => $groceriesCategory,
                    ]);
                }
            }
            $cursor = $cursor->addDays(7);
            $i++;
        }

        return $inserted;
    }

    // Twice-weekly (Tuesday/Thursday) train commute across the window, one
    // amount per trip until the fixed amount table is exhausted. rowIndex
    // advances by reference to preserve the original seed order.
    private function seedUser1NsTransit(
        User $user,
        Account $asn,
        ImportRun $run,
        int &$rowIndex,
        CarbonImmutable $windowStart,
        ?int $transitCategory,
    ): int {
        $inserted = 0;
        $nsAmounts = [-1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240];
        $cursor = $windowStart;
        $nsIdx = 0;
        while ($cursor->lessThanOrEqualTo($this->windowEnd) && $nsIdx < count($nsAmounts)) {
            if (in_array($cursor->dayOfWeek, [CarbonImmutable::TUESDAY, CarbonImmutable::THURSDAY], true)) {
                $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                    'type' => 'expense',
                    'amountMinor' => $nsAmounts[$nsIdx],
                    'description' => 'NS Reizen Utrecht-Amsterdam',
                    'counterpartyName' => 'NS Reizigers',
                    'counterpartyIban' => null,
                    'date' => $cursor,
                    'paymentType' => PaymentType::Pin,
                    'categoryId' => $transitCategory,
                ]);
                $nsIdx++;
            }
            $cursor = $cursor->addDay();
        }

        return $inserted;
    }

    // Seeds each fixed monthly series in turn, one row per month. They are
    // data rather than code because only the eight constants differed, and
    // writing the walk out per series is what let the two personas drift
    // into near-identical methods that had to be edited in step.
    /**
     * @param  list<array{day: int, type: string, amountMinor: int, description: string, counterpartyName: string, counterpartyIban: ?string, paymentType: PaymentType, categorySlug: ?string}>  $definitions
     */
    private function seedMonthlySeries(
        User $user,
        Account $account,
        ImportRun $run,
        int &$rowIndex,
        CarbonImmutable $windowStart,
        array $definitions,
    ): int {
        $inserted = 0;

        foreach ($definitions as $series) {
            foreach ($this->monthlyDates($windowStart, $series['day']) as $date) {
                $inserted += $this->insertTransaction($user, $account, $run, $rowIndex++, [
                    'type' => $series['type'],
                    'amountMinor' => $series['amountMinor'],
                    'description' => $series['description'],
                    'counterpartyName' => $series['counterpartyName'],
                    'counterpartyIban' => $series['counterpartyIban'],
                    'date' => $date,
                    'paymentType' => $series['paymentType'],
                    'categoryId' => $series['categorySlug'] === null
                        ? null
                        : $this->categoryId($series['categorySlug']),
                ]);
            }
        }

        return $inserted;
    }

    // The date in each month of the window that falls on $dayOfMonth,
    // oldest month first. Every monthly series in this seeder walks the
    // window the same way, and writing that walk out per series is what
    // let dayInMonth() drift away from its own parameter.
    /**
     * @param  int  $olderMonthStride  pushes each older month this many days
     *                                 later, so one merchant's rows do not all
     *                                 land on the same date of the month
     * @return list<CarbonImmutable>
     */
    private function monthlyDates(CarbonImmutable $windowStart, int $dayOfMonth, int $olderMonthStride = 0): array
    {
        $dates = [];
        for ($monthsBack = self::MONTH_SPAN - 1; $monthsBack >= 0; $monthsBack--) {
            $dates[] = $this->dayInMonth(
                $windowStart,
                $dayOfMonth + ($monthsBack * $olderMonthStride),
                $monthsBack,
            );
        }

        return $dates;
    }

    // Builds the Nth day in the month that is `$monthsBack` months before
    // the newest month of the window, capped at that month's last day
    // (day=31 in February clamps to Feb 28/29).
    private function dayInMonth(CarbonImmutable $windowStart, int $day, int $monthsBack): CarbonImmutable
    {
        // Counted forward from $windowStart rather than backward from a
        // second today(). That read anchored the dates to whenever this ran
        // instead of to the window run() had fixed: a seed crossing midnight
        // into a new month shifted every row while windowEnd stayed behind.
        $anchor = $windowStart
            ->addMonthsNoOverflow(self::MONTH_SPAN - 1 - $monthsBack)
            ->startOfMonth();

        return $anchor->setDay(min($day, $anchor->daysInMonth));
    }

    // Looks up the global default-tree category id for a slug; returns
    // null when the slug is unknown (caller treats null as "leave
    // category_id null").
    private function categoryId(string $slug): ?int
    {
        /** @var Category|null $cat */
        $cat = Category::withoutGlobalScopes()
            ->where('slug', $slug)
            ->whereNull('user_id')
            ->first();

        return $cat?->id;
    }

    // The sha256 is deterministic over (username, account-slug), so a
    // second seed run finds the same row via the (user_id, sha256)
    // UNIQUE index.
    private function ensureImportRun(User $user, Account $account): ImportRun
    {
        $sha = hash('sha256', 'demo|'.$user->username.'|'.$account->slug);

        return ImportRun::query()->updateOrCreate(
            ['user_id' => $user->id, 'sha256' => $sha],
            [
                'source_format' => 'demo',
                'raw_file_path' => 'demo://'.$account->slug,
                'uploaded_at' => CarbonImmutable::today(),
                'confirmed_at' => CarbonImmutable::today(),
                'status' => ImportRunStatus::Confirmed->value,
            ],
        );
    }

    // Builds a CanonicalTransaction, computes its production
    // fingerprint, and INSERTs it via insertOrIgnore so an idempotent
    // re-seed is a no-op (the composite UNIQUE on fingerprint + the v3
    // tuple UNIQUE both catch a duplicate).
    /**
     * @param  array{type: string, amountMinor: int, description: string, counterpartyName: ?string, counterpartyIban: ?string, date: CarbonImmutable, paymentType: PaymentType, categoryId: ?int, currency?: string, settledAmountMinor?: int, settledCurrency?: string, fxRateUsed?: string|null}  $row
     */
    private function insertTransaction(
        User $user,
        Account $account,
        ImportRun $run,
        int $rowIndex,
        array $row,
    ): int {
        $currency = $row['currency'] ?? Currency::Eur->value;
        $settledAmountMinor = $row['settledAmountMinor'] ?? $row['amountMinor'];
        $settledCurrency = $row['settledCurrency'] ?? Currency::Eur->value;
        $fxRateUsed = $row['fxRateUsed'] ?? null;

        $normalized = $this->fingerprints->normalize(
            $row['counterpartyName'] ?? ($row['description'] !== '' ? $row['description'] : 'demo'),
        );

        $bookedAt = $row['date']->setTime(12, 0, 0);

        $sourceRef = 'DEMO-'.$user->id.'-'.$account->id.'-'.$rowIndex;

        $canonical = new CanonicalTransaction(
            userId: $user->id,
            accountId: $account->id,
            type: $row['type'],
            postedAt: $row['date'],
            bookedAt: $bookedAt,
            valueDate: $row['date'],
            amountMinor: $row['amountMinor'],
            currency: $currency,
            settledAmountMinor: $settledAmountMinor,
            settledCurrency: $settledCurrency,
            fxRateUsed: $fxRateUsed,
            counterpartyName: $row['counterpartyName'],
            counterpartyIban: $row['counterpartyIban'],
            counterpartyNormalized: $normalized,
            normalizationVersion: $this->fingerprints->version(),
            description: $row['description'],
            categoryId: $row['categoryId'],
            sourceFormat: 'demo',
            importRunId: $run->id,
            sourceRowIndex: $rowIndex,
            sourceRef: $sourceRef,
            rawPayload: null,
            autoCategoryProvenance: null,
            paymentType: $row['paymentType'],
        );

        $fingerprint = $this->fingerprints->compose($canonical);

        $now = CarbonImmutable::now()->toDateTimeString();

        $attrs = array_merge($canonical->toAttributes(), [
            'fingerprint' => $fingerprint,
            'fingerprint_version' => $this->fingerprints->version(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Transaction::query()->insertOrIgnore($attrs);
    }
}
