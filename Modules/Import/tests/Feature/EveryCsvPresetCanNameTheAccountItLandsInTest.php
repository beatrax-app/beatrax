<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Internal\Exceptions\InvalidAccountNameException;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Modules\Ledger\Models\Account;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'preset-namer',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

function presetOwnIdentifier(string $format, string $fixture): string
{
    $registry = new CsvPresetRegistry;
    $preset = $registry->get($format);
    expect($preset)->not->toBeNull();

    $resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    // Resolved through the registry rather than hand-built: that is the adapter
    // the app actually reaches for this format, and a preset that stopped being
    // registered would otherwise still pass here against one the test made.
    $rows = iterator_to_array(
        app(SourceAdapterRegistry::class)->for($format)->parse(
            base_path('Modules/Ingestion/tests/fixtures/csv/'.$fixture),
            $resolver,
        ),
        preserve_keys: false,
    );

    expect($rows)->not->toBeEmpty();

    return $rows[0]->ownIban;
}

it('names the account a Revolut export lands in', function (): void {
    $identifier = presetOwnIdentifier(CsvPresetRegistry::REVOLUT, 'revolut-sample.csv');
    expect($identifier)->toBe('REVOLUT');

    $accountId = app(AccountNamer::class)($identifier, 'Revolut Current', $this->user);

    /** @var Account $account */
    $account = Account::query()->find($accountId);
    expect($account->iban)->toBe('REVOLUT');
    expect($account->name)->toBe('Revolut Current');
});

it('names the account an N26 export lands in', function (): void {
    $identifier = presetOwnIdentifier(CsvPresetRegistry::N26, 'n26-sample.csv');
    expect($identifier)->toBe('N26');

    $accountId = app(AccountNamer::class)($identifier, 'N26 Main', $this->user);

    /** @var Account $account */
    $account = Account::query()->find($accountId);
    expect($account->iban)->toBe('N26');
});

it('still refuses an identifier no preset issued and no bank could have', function (): void {
    $namer = app(AccountNamer::class);

    expect(fn () => $namer('NL42', 'Too short', $this->user))
        ->toThrow(InvalidAccountNameException::class);
    expect(fn () => $namer('nl42test1234567890', 'Lowercase', $this->user))
        ->toThrow(InvalidAccountNameException::class);
    expect(fn () => $namer('REVOLUT-PERSONAL', 'Almost a preset', $this->user))
        ->toThrow(InvalidAccountNameException::class);
});
