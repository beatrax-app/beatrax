<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\DetectsStartingBalance;
use Modules\Import\Public\Dto\StartingBalanceCandidate;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Import\Public\Services\DetectStartingBalancesQuery;
use Modules\Import\Tests\Support\PreviewSeedHelper;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Onboarding\Internal\Http\Livewire\Steps\FirstImportStep;
use Modules\Onboarding\Internal\Services\WizardProgressInitializer;

// The badge beside a starting-balance card is "KIND · last four of the IBAN".
// An account standing in for an IBAN has no last four: PayPal's read
// "PAYPAL · YPAL" and a Revolut wallet's read "BANK · OLUT".
function fundingTagAccount(int $userId, string $slug, string $iban, AccountKind $kind): int
{
    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $userId,
        'name' => $slug,
        'slug' => $slug,
        'kind' => $kind->value,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);

    return $account->id;
}

beforeEach(function (): void {
    $this->frozenNow = CarbonImmutable::parse('2026-05-15 12:00:00');
    Carbon::setTestNow($this->frozenNow);
    CarbonImmutable::setTestNow($this->frozenNow);

    $this->app->instance(Clock::class, new class($this->frozenNow) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    });

    $this->app->forgetInstance(PreviewCache::class);
    $this->app->forgetInstance(BuildConsolidatedPreviewQuery::class);

    $this->user = User::query()->create([
        'username' => 'funding-tag',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    /** @var WizardProgressInitializer $initializer */
    $initializer = $this->app->make(WizardProgressInitializer::class);
    $initializer->initialize($this->user->id);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('takes no last four off an account that never had an IBAN to take one from', function (): void {
    $runId = PreviewSeedHelper::seedRunWithPreview($this->user->id, 'camt053', 1);

    DB::table('wizard_progress')
        ->where('user_id', $this->user->id)
        ->where('step_key', 'connect-bank')
        ->update(['data' => json_encode(['bank_import_run_id' => $runId])]);

    $revolutIdentifier = app(CsvPresetRegistry::class)->get(CsvPresetRegistry::REVOLUT)?->ownAccountIdentifier();
    expect($revolutIdentifier)->toBe('REVOLUT');

    $bankId = fundingTagAccount($this->user->id, 'bank', 'NL91ABNA0417164300', AccountKind::Bank);
    $walletId = fundingTagAccount($this->user->id, 'wallet', SyntheticIban::Paypal->value, AccountKind::Paypal);
    $cardId = fundingTagAccount($this->user->id, 'card', SyntheticIban::IcsCard->value, AccountKind::IcsCard);
    $revolutId = fundingTagAccount($this->user->id, 'revolut', (string) $revolutIdentifier, AccountKind::Bank);

    $candidates = [];
    foreach ([$bankId, $walletId, $cardId, $revolutId] as $accountId) {
        $candidates[] = new StartingBalanceCandidate(
            accountId: $accountId,
            openingBalanceMinor: 1000,
            openingBalanceDate: '2026-05-01',
            sourceFormat: 'camt053',
        );
    }

    $detector = new class($candidates) implements DetectsStartingBalance
    {
        /**
         * @param  list<StartingBalanceCandidate>  $candidates
         */
        public function __construct(private readonly array $candidates) {}

        public function supports(string $sourceFormat): bool
        {
            return true;
        }

        /**
         * @param  list<int>  $importRunIds
         * @return list<StartingBalanceCandidate>
         */
        public function detect(array $importRunIds, User $user): array
        {
            return $this->candidates;
        }
    };

    $this->app->instance(DetectStartingBalancesQuery::class, new DetectStartingBalancesQuery([$detector]));

    /** @var array<int, array{label: string, short: string, currency: string}> $meta */
    $meta = Livewire::test(FirstImportStep::class)->viewData('accountMeta');

    expect($meta[$bankId]['short'])->toBe('BANK · 4300');
    expect($meta[$walletId]['short'])->toBe('PAYPAL');
    expect($meta[$cardId]['short'])->toBe('ICS');
    expect($meta[$revolutId]['short'])->toBe('BANK');
});
