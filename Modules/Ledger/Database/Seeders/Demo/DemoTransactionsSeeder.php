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
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * Builds a deterministic, believable 90-day transaction slate for both
 * demo users. The seeder is the dataset the README screenshots derive
 * from — every merchant name, every amount, and every cadence is
 * chosen so a contributor opening the demo install sees recognisable
 * Dutch retail activity rather than Faker-generated noise.
 *
 * Mechanics:
 *
 *   - One `import_runs` row per (user, account) pair, stamped
 *     `source_format = 'demo'`. The marker is the wipe boundary that
 *     `DemoSeedCommand::resetDemoData()` walks; production user data
 *     is never targeted.
 *   - Per merchant, an explicit cadence and amount table is defined
 *     so the dataset is reproducible bit-for-bit between runs. No
 *     `rand()` / `Faker` — deterministic input produces deterministic
 *     fingerprints, which lets the second `php artisan demo:seed` run
 *     pass the composite UNIQUE index without an OR-IGNORE escape.
 *   - Every row is built as a `CanonicalTransaction` and hashed
 *     through the production `FingerprintComposer` so demo rows are
 *     indistinguishable from imported rows at the fingerprint layer
 *     (the resolver / categorizer / chains modules read the same
 *     shape regardless of origin).
 *   - A handful of PayPal rows are USD-denominated with a realistic
 *     EUR/USD cross rate so the multi-currency surfaces have data to
 *     render.
 *   - Transfer pairs (the monthly ASN→PayPal top-up that funds online
 *     spending) are linked via `pair_transaction_id` after both legs
 *     land, exactly mirroring the production Layer-1 pair detector.
 */
final class DemoTransactionsSeeder
{
    /**
     * 90-day window anchored on the wall-clock "today" the seeder runs
     * at. The dataset slides forward as the developer runs the seeder
     * on different days, so the README screenshots always show
     * "recent" activity rather than a fixed historical snapshot.
     */
    private const WINDOW_DAYS = 90;

    /**
     * Demo EUR/USD cross-rate used for the USD-denominated PayPal
     * rows. The number is a plausible mid-2026 mid-market rate — the
     * point is that the dashboard's currency-mode toggle has
     * non-trivial data to convert, not that the rate matches a real
     * forex provider.
     */
    private const EUR_PER_USD = '0.92000000';

    public function __construct(
        private readonly FingerprintComposer $fingerprints,
    ) {}

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array<string, Account>>  $accounts
     */
    public function run(array $users, array $accounts): int
    {
        $today = CarbonImmutable::today();
        $windowStart = $today->subDays(self::WINDOW_DAYS - 1);

        $totalInserted = 0;

        // User 1: the active power-user persona — three accounts, ~120
        // transactions covering salary, household bills, groceries,
        // transit, and online purchases.
        if (isset($users['demo-1@beatrax.local'], $accounts['demo-1@beatrax.local'])) {
            $user = $users['demo-1@beatrax.local'];
            $perUserAccounts = $accounts['demo-1@beatrax.local'];

            $totalInserted += $this->seedUser1AsnRows($user, $perUserAccounts['asn-demo-1'], $windowStart);
            $totalInserted += $this->seedUser1IcsRows($user, $perUserAccounts['ics-demo-1'], $windowStart);
            $totalInserted += $this->seedUser1PaypalRows($user, $perUserAccounts['paypal-demo-1'], $windowStart);

            $this->linkUser1Transfers($user);
        }

        // User 2: the secondary persona — proves multi-user isolation
        // works (the dashboard for user 2 must never show user 1's
        // data). Sparser slate.
        if (isset($users['demo-2@beatrax.local'], $accounts['demo-2@beatrax.local'])) {
            $user = $users['demo-2@beatrax.local'];
            $perUserAccounts = $accounts['demo-2@beatrax.local'];

            $totalInserted += $this->seedUser2AsnRows($user, $perUserAccounts['asn-demo-2'], $windowStart);
            $totalInserted += $this->seedUser2PaypalRows($user, $perUserAccounts['paypal-demo-2'], $windowStart);
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

        // 3× monthly salary credits (Salaris MijnWerkgever BV) on the
        // 25th of each of the three months in the window.
        $salaryCategory = $this->categoryId('income-salary');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 25, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'income',
                'amountMinor' => 385000,
                'description' => 'Salaris MijnWerkgever BV',
                'counterpartyName' => 'MijnWerkgever BV',
                'counterpartyIban' => 'NL11RABO0123456789',
                'date' => $date,
                'paymentType' => PaymentType::Transfer,
                'categoryId' => $salaryCategory,
            ]);
        }

        // Monthly rent on the 1st (housing-rent).
        $rentCategory = $this->categoryId('housing-rent');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 1, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -125000,
                'description' => 'Huur Vesteda',
                'counterpartyName' => 'Vesteda',
                'counterpartyIban' => 'NL45INGB0007654321',
                'date' => $date,
                'paymentType' => PaymentType::DirectDebit,
                'categoryId' => $rentCategory,
            ]);
        }

        // Monthly KPN internet + phone on the 3rd.
        $internetCategory = $this->categoryId('housing-internet');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 3, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -4500,
                'description' => 'KPN Mobile + Internet',
                'counterpartyName' => 'KPN BV',
                'counterpartyIban' => 'NL09INGB0010040004',
                'date' => $date,
                'paymentType' => PaymentType::DirectDebit,
                'categoryId' => $internetCategory,
            ]);
        }

        // Monthly Ziggo TV/internet on the 5th.
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 5, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -5995,
                'description' => 'Ziggo abonnement',
                'counterpartyName' => 'Ziggo',
                'counterpartyIban' => 'NL55INGB0700057757',
                'date' => $date,
                'paymentType' => PaymentType::DirectDebit,
                'categoryId' => $internetCategory,
            ]);
        }

        // Monthly Sport City gym membership on the 1st.
        $membershipsCategory = $this->categoryId('subscriptions-memberships');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 1, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -2500,
                'description' => 'Sport City',
                'counterpartyName' => 'Sport City Nederland BV',
                'counterpartyIban' => 'NL22ABNA0123456789',
                'date' => $date,
                'paymentType' => PaymentType::DirectDebit,
                'categoryId' => $membershipsCategory,
            ]);
        }

        // Health insurance on the 28th (Zilveren Kruis).
        $healthInsuranceCategory = $this->categoryId('insurance-health');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 28, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -14250,
                'description' => 'Zilveren Kruis Zorgverzekering',
                'counterpartyName' => 'Zilveren Kruis',
                'counterpartyIban' => 'NL56INGB0686806266',
                'date' => $date,
                'paymentType' => PaymentType::DirectDebit,
                'categoryId' => $healthInsuranceCategory,
            ]);
        }

        // Belastingdienst (tax) on the 27th of each month.
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 27, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -8500,
                'description' => 'Belastingdienst motorrijtuigenbelasting',
                'counterpartyName' => 'Belastingdienst',
                'counterpartyIban' => 'NL86INGB0002445588',
                'date' => $date,
                'paymentType' => PaymentType::DirectDebit,
                'categoryId' => null,
            ]);
        }

        // Albert Heijn — weekly groceries on Saturday + a midweek
        // top-up on Wednesday. Amounts vary per week to look natural.
        $groceriesCategory = $this->categoryId('groceries');
        $ahAmounts = [-6754, -5421, -7188, -4998, -6342, -5876, -7011, -5188, -6655, -7290, -5511, -6020];
        $cursor = $windowStart->startOfWeek(CarbonImmutable::SATURDAY);
        $i = 0;
        while ($cursor->lessThanOrEqualTo($windowStart->addDays(self::WINDOW_DAYS - 1))) {
            if ($cursor->greaterThanOrEqualTo($windowStart) && isset($ahAmounts[$i])) {
                $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                    'type' => 'expense',
                    'amountMinor' => $ahAmounts[$i],
                    'description' => 'AH Filiaal 1234 Utrecht',
                    'counterpartyName' => 'Albert Heijn',
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
                        'counterpartyName' => 'Albert Heijn',
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

        // Jumbo + Lidl + Dirk + Hema diversity — one each per month.
        $diversityRows = [
            ['name' => 'Jumbo', 'description' => 'Jumbo Supermarkt Utrecht', 'amounts' => [-3211, -2890, -4055], 'category' => $groceriesCategory, 'iban' => null, 'paymentType' => PaymentType::Pin],
            ['name' => 'Lidl', 'description' => 'Lidl Filiaal 0042', 'amounts' => [-1989, -2210, -1875], 'category' => $groceriesCategory, 'iban' => null, 'paymentType' => PaymentType::Pin],
            ['name' => 'Dirk', 'description' => 'Dirk van den Broek', 'amounts' => [-2755, -3120, -2480], 'category' => $groceriesCategory, 'iban' => null, 'paymentType' => PaymentType::Pin],
            ['name' => 'HEMA', 'description' => 'HEMA bv Utrecht', 'amounts' => [-1295, -1750, -2105], 'category' => $this->categoryId('personal-care'), 'iban' => null, 'paymentType' => PaymentType::Pin],
        ];
        foreach ($diversityRows as $merchant) {
            foreach ([2, 1, 0] as $idx => $monthsBack) {
                $date = $this->dayInMonth($windowStart, 14 + ($monthsBack * 2), $monthsBack);
                if ($date->lessThan($windowStart)) {
                    continue;
                }
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

        // NS (public transport) — twice a week, business commute.
        $transitCategory = $this->categoryId('transport-public');
        $nsAmounts = [-1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240, -1180, -1180, -1240];
        $cursor = $windowStart;
        $nsIdx = 0;
        while ($cursor->lessThanOrEqualTo($windowStart->addDays(self::WINDOW_DAYS - 1)) && $nsIdx < count($nsAmounts)) {
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

        // Eating out — Domino's monthly, La Place biweekly.
        $eatingOutCategory = $this->categoryId('eating-out');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 12, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -2095,
                'description' => "Domino's Pizza Utrecht",
                'counterpartyName' => "Domino's Pizza",
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Pin,
                'categoryId' => $eatingOutCategory,
            ]);
        }
        foreach ([0, 14, 28, 42, 56, 70, 84] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->lessThan($windowStart) || $date->greaterThan($windowStart->addDays(self::WINDOW_DAYS - 1))) {
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

        // Cash withdrawal — once a month from ATM.
        $cashCategory = $this->categoryId('cash-withdrawal');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 8, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -10000,
                'description' => 'GEA ASN BANK Utrecht',
                'counterpartyName' => 'ASN Bank GEA',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Cash,
                'categoryId' => $cashCategory,
            ]);
        }

        // ASN→PayPal top-up — monthly transfer that funds online
        // purchases. The matching transfer_in lands on the PayPal
        // account; linkUser1Transfers() wires the pair_transaction_id
        // after both legs are written.
        $transfersInternalCategory = $this->categoryId('transfers-internal');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 10, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'transfer_out',
                'amountMinor' => -10000,
                'description' => 'PayPal top-up',
                'counterpartyName' => 'PayPal',
                'counterpartyIban' => 'PAYPAL-DEMO-1',
                'date' => $date,
                'paymentType' => PaymentType::Transfer,
                'categoryId' => $transfersInternalCategory,
            ]);
        }

        // ICS bulk settlement on the 18th (one ICS→ASN row that
        // settles the full ICS card balance for the prior period).
        // The matching ICS credits land on the ICS account; the
        // Chains demo seeder wires the chain_link in Task 3.
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 18, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'transfer_out',
                'amountMinor' => -22500,
                'description' => 'ICS afrekening MasterCard',
                'counterpartyName' => 'International Card Services',
                'counterpartyIban' => 'NL75ABNA0596780870',
                'date' => $date,
                'paymentType' => PaymentType::Transfer,
                'categoryId' => $transfersInternalCategory,
            ]);
        }

        // Personal P2P transfers — one outgoing to a friend (Maria
        // van Buren) once a month so the personal-counterparty type
        // has data to surface.
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 20, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'transfer_out',
                'amountMinor' => -2500,
                'description' => 'Tikkie aandeel diner',
                'counterpartyName' => 'M VAN BUREN',
                'counterpartyIban' => 'NL66ABNA0987654321',
                'date' => $date,
                'paymentType' => PaymentType::Transfer,
                'categoryId' => null,
            ]);
        }

        return $inserted;
    }

    private function seedUser1IcsRows(User $user, Account $ics, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $ics);
        $rowIndex = 0;
        $inserted = 0;

        // Bol.com — biweekly online purchases on the ICS card.
        $onlineCategory = $this->categoryId('subscriptions-cloud');
        $bolAmounts = [-3500, -1295, -4995, -2150, -1875, -5495, -2799];
        foreach ([2, 9, 16, 23, 38, 52, 70] as $i => $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($windowStart->addDays(self::WINDOW_DAYS - 1))) {
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

        // Coolblue — one big-ticket purchase mid-window.
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

        // MediaMarkt — two purchases in different months.
        foreach ([20, 68] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($windowStart->addDays(self::WINDOW_DAYS - 1))) {
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

        // bookings + leisure travel
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

        // Restaurant evenings on ICS card.
        $eatingOutCategory = $this->categoryId('eating-out');
        foreach ([6, 19, 27, 41, 55, 73, 81] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($windowStart->addDays(self::WINDOW_DAYS - 1))) {
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
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 18, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $ics, $run, $rowIndex++, [
                'type' => 'transfer_in',
                'amountMinor' => 22500,
                'description' => 'Afrekening MasterCard ICS',
                'counterpartyName' => 'ASN Bank',
                'counterpartyIban' => 'NL00ASNB0123456789',
                'date' => $date,
                'paymentType' => PaymentType::Transfer,
                'categoryId' => $this->categoryId('transfers-internal'),
            ]);
        }

        return $inserted;
    }

    private function seedUser1PaypalRows(User $user, Account $paypal, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $paypal);
        $rowIndex = 0;
        $inserted = 0;

        // Monthly Spotify on the 11th.
        $musicCategory = $this->categoryId('subscriptions-music');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 11, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -1099,
                'description' => 'Spotify Premium',
                'counterpartyName' => 'Spotify AB',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $musicCategory,
            ]);
        }

        // Monthly Netflix on the 15th.
        $streamingCategory = $this->categoryId('subscriptions-streaming');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 15, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -1499,
                'description' => 'Netflix.com',
                'counterpartyName' => 'Netflix International BV',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $streamingCategory,
            ]);
        }

        // Google Play — five USD-denominated rows so the FX surface
        // has data. Amounts are USD minor units; settled in EUR via
        // the demo cross-rate.
        $usdAmounts = [-499, -999, -299, -1299, -599];
        foreach ([4, 22, 39, 58, 79] as $i => $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($windowStart->addDays(self::WINDOW_DAYS - 1))) {
                continue;
            }
            $amountUsd = $usdAmounts[$i] ?? -499;
            $settledEur = (int) round($amountUsd * (float) self::EUR_PER_USD);
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => $amountUsd,
                'currency' => 'USD',
                'settledAmountMinor' => $settledEur,
                'settledCurrency' => 'EUR',
                'fxRateUsed' => self::EUR_PER_USD,
                'description' => 'GOOGLE *Google Play',
                'counterpartyName' => 'Google Play',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $this->categoryId('subscriptions-cloud'),
            ]);
        }

        // PayPal Bol.com — the chain-seed transactions for the
        // PayPal→ASN demo chain. Each one is funded by an ASN→PayPal
        // top-up on the same date; the chain_link wires the pair.
        $onlineCategory = $this->categoryId('subscriptions-cloud');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 10, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -7995,
                'description' => 'Bol.com via PayPal',
                'counterpartyName' => 'Bol.com',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $onlineCategory,
            ]);
        }

        // ASN→PayPal funding (transfer_in). The matching transfer_out
        // sits on the ASN account; linkUser1Transfers() pairs them.
        $transfersInternalCategory = $this->categoryId('transfers-internal');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 10, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'transfer_in',
                'amountMinor' => 10000,
                'description' => 'Top-up from ASN',
                'counterpartyName' => 'ASN Bank',
                'counterpartyIban' => 'NL00ASNB0123456789',
                'date' => $date,
                'paymentType' => PaymentType::Transfer,
                'categoryId' => $transfersInternalCategory,
            ]);
        }

        // Occasional Bol.com refund — exercises the refund type.
        $refundsCategory = $this->categoryId('income-refunds');
        $refundDate = $windowStart->addDays(35);
        if ($refundDate->lessThanOrEqualTo($windowStart->addDays(self::WINDOW_DAYS - 1))) {
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'refund',
                'amountMinor' => 1250,
                'description' => 'Retour Bol.com',
                'counterpartyName' => 'Bol.com',
                'counterpartyIban' => null,
                'date' => $refundDate,
                'paymentType' => PaymentType::Refund,
                'categoryId' => $refundsCategory,
            ]);
        }

        return $inserted;
    }

    private function seedUser2AsnRows(User $user, Account $asn, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $asn);
        $rowIndex = 0;
        $inserted = 0;

        // Salary on the 25th — slightly smaller than user 1's.
        $salaryCategory = $this->categoryId('income-salary');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 25, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'income',
                'amountMinor' => 285000,
                'description' => 'Salaris StichtingZorg',
                'counterpartyName' => 'StichtingZorg',
                'counterpartyIban' => 'NL76RABO0987654321',
                'date' => $date,
                'paymentType' => PaymentType::Transfer,
                'categoryId' => $salaryCategory,
            ]);
        }

        // Monthly rent (lower than user 1).
        $rentCategory = $this->categoryId('housing-rent');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 1, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -89500,
                'description' => 'Huur Woningstichting',
                'counterpartyName' => 'Woningstichting Centrum',
                'counterpartyIban' => 'NL44INGB0001112223',
                'date' => $date,
                'paymentType' => PaymentType::DirectDebit,
                'categoryId' => $rentCategory,
            ]);
        }

        // Albert Heijn weekly — sparse.
        $groceriesCategory = $this->categoryId('groceries');
        foreach ([3, 10, 17, 24, 38, 52, 66, 80] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($windowStart->addDays(self::WINDOW_DAYS - 1))) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -(($dayOffset * 89) % 3000 + 2500),
                'description' => 'AH Filiaal 8901 Den Haag',
                'counterpartyName' => 'Albert Heijn',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Pin,
                'categoryId' => $groceriesCategory,
            ]);
        }

        // Gemeente Den Haag — local tax.
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 22, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $asn, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -6500,
                'description' => 'Gemeente Den Haag woonlasten',
                'counterpartyName' => 'Gemeente Den Haag',
                'counterpartyIban' => 'NL47INGB0698027001',
                'date' => $date,
                'paymentType' => PaymentType::DirectDebit,
                'categoryId' => null,
            ]);
        }

        return $inserted;
    }

    private function seedUser2PaypalRows(User $user, Account $paypal, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $paypal);
        $rowIndex = 0;
        $inserted = 0;

        // Monthly Spotify (the user keeps it on their PayPal).
        $musicCategory = $this->categoryId('subscriptions-music');
        foreach ([2, 1, 0] as $monthsBack) {
            $date = $this->dayInMonth($windowStart, 9, $monthsBack);
            if ($date->lessThan($windowStart)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $paypal, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => -1099,
                'description' => 'Spotify Premium',
                'counterpartyName' => 'Spotify AB',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $musicCategory,
            ]);
        }

        // A couple of Bol.com purchases.
        $onlineCategory = $this->categoryId('subscriptions-cloud');
        foreach ([14, 47, 76] as $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($windowStart->addDays(self::WINDOW_DAYS - 1))) {
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

    /**
     * Walks the freshly-seeded ASN→PayPal transfer pairs for user 1
     * and links each pair via `pair_transaction_id`, mirroring the
     * production Layer-1 pair-detection listener so demo data carries
     * the same relationship shape consumers (chains, recurring, query
     * services) expect.
     */
    private function linkUser1Transfers(User $user): void
    {
        $pairs = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->whereIn('type', ['transfer_in', 'transfer_out'])
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

    /**
     * Build a deterministic CalendarImmutable for the Nth day in the
     * month that is `$monthsBack` months before the window-anchor
     * month. Caps at the month's last day so e.g. day=31 in February
     * clamps to Feb 28 / 29.
     */
    private function dayInMonth(CarbonImmutable $windowStart, int $day, int $monthsBack): CarbonImmutable
    {
        $anchor = CarbonImmutable::today()->subMonths($monthsBack)->startOfMonth();
        $candidate = $anchor->setDay(min($day, $anchor->daysInMonth));

        return $candidate;
    }

    /**
     * Look up the global default-tree category id for a slug. Returns
     * null when the slug is unknown (which the caller should treat as
     * "leave category_id null").
     */
    private function categoryId(string $slug): ?int
    {
        /** @var Category|null $cat */
        $cat = Category::withoutGlobalScopes()
            ->where('slug', $slug)
            ->whereNull('user_id')
            ->first();

        return $cat?->id;
    }

    /**
     * Ensure the demo ImportRun for (user, account) exists. The
     * sha256 is deterministic — first 64 hex chars of a hash over
     * (username, account-slug) — so a second seed run finds the same
     * row via the (user_id, sha256) UNIQUE index.
     */
    private function ensureImportRun(User $user, Account $account): ImportRun
    {
        $sha = hash('sha256', 'demo|'.$user->username.'|'.$account->slug);

        /** @var ImportRun $run */
        $run = ImportRun::query()->updateOrCreate(
            ['user_id' => $user->id, 'sha256' => $sha],
            [
                'source_format' => 'demo',
                'raw_file_path' => 'demo://'.$account->slug,
                'uploaded_at' => CarbonImmutable::today(),
                'confirmed_at' => CarbonImmutable::today(),
                'status' => 'confirmed',
            ],
        );

        return $run;
    }

    /**
     * Build a `CanonicalTransaction`, compute its production
     * fingerprint, and INSERT it via `insertOrIgnore` so an
     * idempotent re-seed is a no-op (the composite UNIQUE on
     * fingerprint + the v3 tuple UNIQUE both catch a duplicate).
     *
     * @param  array{type: string, amountMinor: int, description: string, counterpartyName: ?string, counterpartyIban: ?string, date: CarbonImmutable, paymentType: PaymentType, categoryId: ?int, currency?: string, settledAmountMinor?: int, settledCurrency?: string, fxRateUsed?: string|null}  $row
     */
    private function insertTransaction(
        User $user,
        Account $account,
        ImportRun $run,
        int $rowIndex,
        array $row,
    ): int {
        $currency = $row['currency'] ?? 'EUR';
        $settledAmountMinor = $row['settledAmountMinor'] ?? $row['amountMinor'];
        $settledCurrency = $row['settledCurrency'] ?? 'EUR';
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

        $affected = Transaction::query()->insertOrIgnore($attrs);

        return $affected;
    }
}
