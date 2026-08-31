<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Exceptions\IdReadBackFailedException;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\CalendarSpan;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;

final class DemoTransactionsSeeder
{
    private const AH_COUNTERPARTY = 'Albert Heijn';

    private const ASN_COUNTERPARTY = 'ASN Bank';

    // A plausible rate, not a real provider's: the point is that the
    // currency-mode toggle has non-trivial data to convert.
    private const EUR_PER_USD = '0.92000000';

    private CarbonImmutable $windowEnd;

    /** @var list<Period> oldest first, the current period last */
    private array $periods;

    public function __construct(
        private readonly FingerprintComposer $fingerprints,
        private readonly CounterpartyKey $counterpartyKey,
        private readonly DemoPeriodWindow $window,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array<string, Account>>  $accounts
     */
    public function run(array $users, array $accounts): int
    {
        // Each persona's rows land in that persona's OWN budget periods, taken
        // from the definition the budgets grid is drawn over. Anchored on
        // calendar months instead, a reader whose period starts on the 25th
        // opened /budgets on a period holding none of their spend.
        $now = $this->clock->now();

        if (isset($users['demo-1'], $accounts['demo-1'])) {
            $user = $users['demo-1'];
            $perUserAccounts = $accounts['demo-1'];
            $windowStart = $this->openWindowFor($user, $now);

            $this->seedUser1AsnRows($user, $perUserAccounts['asn-demo-1'], $windowStart);
            $this->seedUser1IcsRows($user, $perUserAccounts['ics-demo-1'], $windowStart);
            $this->seedUser1PaypalRows($user, $perUserAccounts['paypal-demo-1'], $windowStart);
            $this->seedUser1JpyRows($user, $perUserAccounts['jpy-demo-1'], $windowStart);

            $this->linkUser1Transfers($user);
        }

        // The sparse second persona, so multi-user isolation has something
        // to prove: user 2's dashboard must never show user 1's data.
        if (isset($users['demo-2'], $accounts['demo-2'])) {
            $user = $users['demo-2'];
            $perUserAccounts = $accounts['demo-2'];
            $windowStart = $this->openWindowFor($user, $now);

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

        // The row index feeds each fingerprint, so reordering these entries
        // rewrites the dataset's identity. Keep them in seed order.
        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, [
            ['day' => 25, 'type' => 'income', 'amountMinor' => 385000, 'description' => 'Salaris MijnWerkgever BV', 'counterpartyName' => 'MijnWerkgever BV', 'counterpartyIban' => 'NL44RABO0123456789', 'paymentType' => PaymentType::Transfer, 'categorySlug' => 'income-salary'],
            ['day' => 1, 'type' => 'expense', 'amountMinor' => -125000, 'description' => 'Huur Vesteda', 'counterpartyName' => 'Vesteda', 'counterpartyIban' => 'NL36INGB0007654321', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'housing-rent'],
            ['day' => 3, 'type' => 'expense', 'amountMinor' => -4500, 'description' => 'KPN Mobile + Internet', 'counterpartyName' => 'KPN BV', 'counterpartyIban' => 'NL27INGB0010040004', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'housing-internet'],
            ['day' => 5, 'type' => 'expense', 'amountMinor' => -5995, 'description' => 'Ziggo abonnement', 'counterpartyName' => 'Ziggo', 'counterpartyIban' => 'NL05INGB0700057757', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'housing-internet'],
            ['day' => 1, 'type' => 'expense', 'amountMinor' => -2500, 'priorAmountMinor' => -2250, 'priorMonths' => 1, 'description' => 'Sport City', 'counterpartyName' => 'Sport City Nederland BV', 'counterpartyIban' => 'NL02ABNA0123456789', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'subscriptions-memberships'],
            ['day' => 28, 'type' => 'expense', 'amountMinor' => -14250, 'description' => 'Zilveren Kruis Zorgverzekering', 'counterpartyName' => 'Zilveren Kruis', 'counterpartyIban' => 'NL39INGB0686806266', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => 'insurance-health'],
            ['day' => 27, 'type' => 'expense', 'amountMinor' => -8500, 'description' => 'Belastingdienst motorrijtuigenbelasting', 'counterpartyName' => 'Belastingdienst', 'counterpartyIban' => 'NL86INGB0002445588', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => null],
        ]);

        $groceriesCategory = $this->categoryId('groceries');
        $inserted += $this->seedUser1AhWeekly($user, $asn, $run, $rowIndex, $windowStart, $groceriesCategory);

        // DemoPeriodWindow::SPAN amounts each, oldest period first. A fourth
        // leading amount would be unreachable: it pairs with the period before
        // the window, which every series skips.
        $diversityRows = [
            ['name' => 'Jumbo', 'description' => 'Jumbo Supermarkt Utrecht', 'amounts' => [-3211, -2890, -4055], 'category' => $groceriesCategory, 'iban' => null, 'paymentType' => PaymentType::Pin],
            ['name' => 'Lidl', 'description' => 'Lidl Filiaal 0042', 'amounts' => [-1989, -2210, -1875], 'category' => $groceriesCategory, 'iban' => null, 'paymentType' => PaymentType::Pin],
            ['name' => 'Dirk', 'description' => 'Dirk van den Broek', 'amounts' => [-2755, -3120, -2480], 'category' => $groceriesCategory, 'iban' => null, 'paymentType' => PaymentType::Pin],
            ['name' => 'HEMA', 'description' => 'HEMA bv Utrecht', 'amounts' => [-1295, -1750, -2105], 'category' => $this->categoryId('personal-care'), 'iban' => null, 'paymentType' => PaymentType::Pin],
        ];
        foreach ($diversityRows as $merchant) {
            foreach ($this->monthlyDates(14, olderMonthStride: 2) as $idx => $date) {
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
        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, [
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

        // linkUser1Transfers() and the Chains demo seeder find these rows by
        // description rather than position, so this stays a table.
        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, [
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

        $inserted += $this->seedUser1JpyOnEuroCardRows($user, $ics, $run, $rowIndex, $windowStart);

        // The monthly ICS card settlement, and the `to_transaction` side of
        // the ics_bulk_settle chain.
        $inserted += $this->seedMonthlySeries($user, $ics, $run, $rowIndex, [
            ['day' => 18, 'type' => 'transfer_in', 'amountMinor' => 22500, 'description' => 'Afrekening MasterCard ICS', 'counterpartyName' => self::ASN_COUNTERPARTY, 'counterpartyIban' => 'NL57ASNB0123456789', 'paymentType' => PaymentType::Transfer, 'categorySlug' => 'transfers-internal'],
        ]);

        return $inserted;
    }

    // The zero-decimal leg of the dataset. Every figure here is a whole yen,
    // so a surface that divides by a hundred renders a hundredth of the
    // charge -- which no euro row in this file can show, because there the
    // wrong divisor and the right one agree.
    private function seedUser1JpyRows(User $user, Account $card, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $card);
        $rowIndex = 0;
        $inserted = 0;

        $groceries = $this->categoryId('groceries');
        $konbini = [-580, -430, -720, -650, -890, -510];
        foreach ([3, 12, 21, 34, 48, 66] as $i => $dayOffset) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $card, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => $konbini[$i] ?? -600,
                'currency' => Currency::Jpy->value,
                'settledCurrency' => Currency::Jpy->value,
                'description' => 'SEVEN ELEVEN SHIBUYA',
                'counterpartyName' => '7-Eleven Japan',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Pin,
                'categoryId' => $groceries,
            ]);
        }

        $transit = $this->categoryId('transport-public');
        foreach ([5 => -1320, 29 => -2460, 57 => -13840] as $dayOffset => $amount) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $card, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => $amount,
                'currency' => Currency::Jpy->value,
                'settledCurrency' => Currency::Jpy->value,
                'description' => 'JR EAST TOKYO STATION',
                'counterpartyName' => 'JR East',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Pin,
                'categoryId' => $transit,
            ]);
        }

        $eatingOut = $this->categoryId('eating-out');
        foreach ([8 => -3200, 40 => -4800] as $dayOffset => $amount) {
            $date = $windowStart->addDays($dayOffset);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $card, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => $amount,
                'currency' => Currency::Jpy->value,
                'settledCurrency' => Currency::Jpy->value,
                'description' => 'ICHIRAN RAMEN SHINJUKU',
                'counterpartyName' => 'Ichiran',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Pin,
                'categoryId' => $eatingOut,
            ]);
        }

        // Six figures of yen, the row a hundredth would render as a four-digit
        // one -- and the amount the chart axis has to be scaled for.
        $inserted += $this->insertTransaction($user, $card, $run, $rowIndex++, [
            'type' => 'expense',
            'amountMinor' => -128000,
            'currency' => Currency::Jpy->value,
            'settledCurrency' => Currency::Jpy->value,
            'description' => 'HOTEL GRANVIA KYOTO',
            'counterpartyName' => 'Hotel Granvia',
            'counterpartyIban' => null,
            'date' => $windowStart->addDays(37),
            'paymentType' => PaymentType::Pin,
            'categoryId' => null,
        ]);

        $inserted += $this->insertTransaction($user, $card, $run, $rowIndex++, [
            'type' => 'transfer_in',
            'amountMinor' => 150000,
            'currency' => Currency::Jpy->value,
            'settledCurrency' => Currency::Jpy->value,
            'description' => 'Opwaardering reiskaart',
            'counterpartyName' => self::ASN_COUNTERPARTY,
            'counterpartyIban' => 'NL57ASNB0123456789',
            'date' => $windowStart->addDays(2),
            'paymentType' => PaymentType::Transfer,
            'categoryId' => $this->categoryId('transfers-internal'),
        ]);

        return $inserted;
    }

    // The other half of the zero-decimal case: charged in yen, settled in
    // euro on a euro card. The two legs sit on different minor-unit scales,
    // which is the pair the effective-rate row has to quote correctly.
    private function seedUser1JpyOnEuroCardRows(User $user, Account $ics, ImportRun $run, int &$rowIndex, CarbonImmutable $windowStart): int
    {
        $inserted = 0;

        $rows = [
            ['day' => 44, 'yen' => -12800, 'euroMinor' => -7424, 'description' => 'ISETAN SHINJUKU TOKYO', 'counterpartyName' => 'Isetan'],
            ['day' => 59, 'yen' => -98000, 'euroMinor' => -56840, 'description' => 'ANA AIRLINES TOKYO', 'counterpartyName' => 'ANA'],
        ];

        foreach ($rows as $row) {
            $date = $windowStart->addDays($row['day']);
            if ($date->greaterThan($this->windowEnd)) {
                continue;
            }
            $inserted += $this->insertTransaction($user, $ics, $run, $rowIndex++, [
                'type' => 'expense',
                'amountMinor' => $row['yen'],
                'currency' => Currency::Jpy->value,
                'settledAmountMinor' => $row['euroMinor'],
                'settledCurrency' => Currency::Eur->value,
                'description' => $row['description'],
                'counterpartyName' => $row['counterpartyName'],
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Pin,
                'categoryId' => null,
            ]);
        }

        return $inserted;
    }

    private function seedUser1PaypalRows(User $user, Account $paypal, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $paypal);
        $rowIndex = 0;
        $inserted = 0;

        $inserted += $this->seedMonthlySeries($user, $paypal, $run, $rowIndex, [
            ['day' => 11, 'type' => 'expense', 'amountMinor' => -1099, 'priorAmountMinor' => -999, 'priorMonths' => 2, 'description' => 'Spotify Premium', 'counterpartyName' => 'Spotify AB', 'counterpartyIban' => null, 'paymentType' => PaymentType::Online, 'categorySlug' => 'subscriptions-music'],
            ['day' => 15, 'type' => 'expense', 'amountMinor' => -1499, 'priorAmountMinor' => -1399, 'priorMonths' => 1, 'description' => 'Netflix.com', 'counterpartyName' => 'Netflix International BV', 'counterpartyIban' => null, 'paymentType' => PaymentType::Online, 'categorySlug' => 'subscriptions-streaming'],
        ]);

        // USD minor units, settled in EUR via the demo cross-rate, so the FX
        // surface has data.
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
                'description' => 'GOOGLE *Google Play',
                'counterpartyName' => 'Google Play',
                'counterpartyIban' => null,
                'date' => $date,
                'paymentType' => PaymentType::Online,
                'categoryId' => $this->categoryId('subscriptions-cloud'),
            ]);
        }

        // The purchase and the ASN→PayPal funding that covers it, both on the
        // 10th: the chain_link wires that pair.
        $inserted += $this->seedMonthlySeries($user, $paypal, $run, $rowIndex, [
            ['day' => 10, 'type' => 'expense', 'amountMinor' => -7995, 'description' => 'Bol.com via PayPal', 'counterpartyName' => 'Bol.com', 'counterpartyIban' => null, 'paymentType' => PaymentType::Online, 'categorySlug' => 'subscriptions-cloud'],
            ['day' => 10, 'type' => 'transfer_in', 'amountMinor' => 10000, 'description' => 'Top-up from ASN', 'counterpartyName' => self::ASN_COUNTERPARTY, 'counterpartyIban' => 'NL57ASNB0123456789', 'paymentType' => PaymentType::Transfer, 'categorySlug' => 'transfers-internal'],
        ]);

        // Two rows, so the `refund` type and the `Refund` chip each have
        // more than one datapoint to render against.
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

        // One positive and one negative, exercising the `adjustment` chip
        // and the `Unknown` PaymentType fallback.
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

        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, [
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

        $inserted += $this->seedMonthlySeries($user, $asn, $run, $rowIndex, [
            ['day' => 22, 'type' => 'expense', 'amountMinor' => -6500, 'description' => 'Gemeente Den Haag woonlasten', 'counterpartyName' => 'Gemeente Den Haag', 'counterpartyIban' => 'NL03INGB0698027001', 'paymentType' => PaymentType::DirectDebit, 'categorySlug' => null],
        ]);

        return $inserted;
    }

    private function seedUser2PaypalRows(User $user, Account $paypal, CarbonImmutable $windowStart): int
    {
        $run = $this->ensureImportRun($user, $paypal);
        $rowIndex = 0;
        $inserted = 0;

        $inserted += $this->seedMonthlySeries($user, $paypal, $run, $rowIndex, [
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

    // Mirrors the production pair-detection listener, so demo data carries
    // the relationship shape chains, recurring and the queries expect.
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

    /**
     * @param  list<array{day: int, type: string, amountMinor: int, priorAmountMinor?: int, priorMonths?: int, description: string, counterpartyName: string, counterpartyIban: ?string, paymentType: PaymentType, categorySlug: ?string}>  $definitions
     */
    private function seedMonthlySeries(
        User $user,
        Account $account,
        ImportRun $run,
        int &$rowIndex,
        array $definitions,
    ): int {
        $inserted = 0;

        foreach ($definitions as $series) {
            foreach ($this->monthlyDates($series['day']) as $monthIndex => $date) {
                $inserted += $this->insertTransaction($user, $account, $run, $rowIndex++, [
                    'type' => $series['type'],
                    'amountMinor' => self::amountForMonth($series, $monthIndex),
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

    // A drift alert is a claim that a recurring charge changed price, so the
    // charges have to contain the change: the oldest `priorMonths` of them are
    // billed at the old price and the rest at `amountMinor`.
    /**
     * @param  array{amountMinor: int, priorAmountMinor?: int, priorMonths?: int}  $series
     * @param  int  $monthIndex  0 is the oldest month monthlyDates() returns
     */
    private static function amountForMonth(array $series, int $monthIndex): int
    {
        $priorAmountMinor = $series['priorAmountMinor'] ?? null;

        return $priorAmountMinor !== null && $monthIndex < ($series['priorMonths'] ?? 0)
            ? $priorAmountMinor
            : $series['amountMinor'];
    }

    /**
     * @param  int  $olderMonthStride  pushes each older month this many days
     *                                 later, so one merchant's rows do not all
     *                                 land on the same date of the month
     * @return list<CarbonImmutable>
     */
    private function monthlyDates(int $dayOfMonth, int $olderMonthStride = 0): array
    {
        $span = count($this->periods);

        $dates = [];
        for ($monthsBack = $span - 1; $monthsBack >= 0; $monthsBack--) {
            // Read out of the window this persona's grid is drawn over, never
            // stepped forward from its start by whole calendar months: the two
            // are the same walk only for a reader whose period opens on the
            // 1st, and the stride below can push a day past its own month.
            $dates[] = DemoPeriodWindow::dayIn(
                $this->periods[$span - 1 - $monthsBack],
                $dayOfMonth + ($monthsBack * $olderMonthStride),
            );
        }

        return $dates;
    }

    // The first day of the oldest seeded period, with the last day of the
    // newest recorded as the stop every relative row is clipped against.
    private function openWindowFor(User $user, CarbonImmutable $now): CarbonImmutable
    {
        $this->periods = $this->window->forUser($user, $now);
        $this->windowEnd = CalendarSpan::lastDayOf($this->periods[count($this->periods) - 1]);

        return $this->periods[0]->start;
    }

    private function categoryId(string $slug): ?int
    {
        /** @var Category|null $cat */
        $cat = Category::withoutGlobalScopes()
            ->where('slug', $slug)
            ->whereNull('user_id')
            ->first();

        return $cat?->id;
    }

    // Deterministic over (username, account-slug), so a re-seed finds the
    // same row via the (user_id, sha256) UNIQUE index.
    private function ensureImportRun(User $user, Account $account): ImportRun
    {
        $sha = hash('sha256', 'demo|'.$user->username.'|'.$account->slug);

        ImportRun::query()->updateOrCreate(
            ['user_id' => $user->id, 'sha256' => $sha],
            [
                'source_format' => 'demo',
                'raw_file_path' => 'demo://'.$account->slug,
                'uploaded_at' => $this->clock->now()->startOfDay(),
                'confirmed_at' => $this->clock->now()->startOfDay(),
                'status' => ImportRunStatus::Confirmed->value,
            ],
        );

        // Re-read by the same UNIQUE rather than kept from updateOrCreate(): it
        // ends in insertGetId(), lastInsertId() is per connection, and the badge
        // listener writes a `cache` row from inside this INSERT's own event, so
        // every demo row would name a run that does not exist.
        return ImportRun::query()
            ->where('user_id', $user->id)
            ->where('sha256', $sha)
            ->first() ?? throw new IdReadBackFailedException('import_runs');
    }

    // insertOrIgnore, so a re-seed is a no-op: the fingerprint UNIQUE and
    // the v3 tuple UNIQUE both catch a duplicate.
    /**
     * @param  array{type: string, amountMinor: int, description: string, counterpartyName: ?string, counterpartyIban: ?string, date: CarbonImmutable, paymentType: PaymentType, categoryId: ?int, currency?: string, settledAmountMinor?: int, settledCurrency?: string}  $row
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

        $normalized = $this->counterpartyKey->forName(
            $row['counterpartyName'] ?? ($row['description'] !== '' ? $row['description'] : 'demo'),
            $user->id,
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

        $now = $this->clock->now()->toDateTimeString();

        $attrs = array_merge($canonical->toAttributes(), [
            'fingerprint' => $fingerprint,
            'fingerprint_version' => $this->fingerprints->version(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Transaction::query()->insertOrIgnore($attrs);
    }
}
