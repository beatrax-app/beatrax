<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\MysteryMerchantsPage;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// Read on an iPhone 12 mini: all 24 cards in the queue carried the same
// sentence, "Likely: an unnamed merchant." — including the row the app had
// itself chipped "↺ Refund" and the one it had chipped "◷ Fee". A constant
// that is wrong wherever the app knows better, beside a chip that already
// carries the answer.

it('does not tell a refund it is likely an unnamed merchant', function (): void {
    $user = makeCommunityTestUser('mystery-guess-user');
    $this->actingAs($user);

    $account = Account::create([
        'user_id' => $user->id,
        'name' => 'Revolut',
        'slug' => 'mystery-guess-revolut',
        'kind' => 'bank',
        'iban' => 'NL00MYSTERY0000001',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'revolut-csv',
        'raw_file_path' => '/tmp/mystery-guess.csv',
        'sha256' => str_repeat('d', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'income',
        'posted_at' => '2026-05-20',
        'booked_at' => '2026-05-20 12:00:00',
        'value_date' => '2026-05-20',
        'amount_minor' => 5000,
        'currency' => 'EUR',
        'settled_amount_minor' => 5000,
        'settled_currency' => 'EUR',
        'counterparty_name' => null,
        'counterparty_normalized' => '',
        'normalization_version' => 1,
        'source_format' => 'revolut-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('1', 64, 'f', STR_PAD_LEFT),
        'fingerprint_version' => 1,
        'description' => 'Amazon.com refund',
        'payment_type' => 'refund',
    ]);

    Livewire::test(MysteryMerchantsPage::class)
        ->assertSee('Amazon.com refund')
        ->assertDontSee('Likely:');
});

it('leaves no locale carrying the sentence nothing reads any more', function (): void {
    $locales = array_values(array_filter(
        scandir(base_path('Modules/Community/Resources/lang')) ?: [],
        static fn (string $entry): bool => ! str_starts_with($entry, '.'),
    ));

    expect($locales)->toHaveCount(26);

    $stale = [];
    foreach ($locales as $locale) {
        /** @var array<string, mixed> $strings */
        $strings = require base_path("Modules/Community/Resources/lang/{$locale}/mystery.php");
        if (isset($strings['card']['likely'])) {
            $stale[] = $locale;
        }
    }

    expect($stale)->toBe([], 'Still carrying card.likely: '.implode(', ', $stale));
});
