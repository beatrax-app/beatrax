<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Public\Services\EloquentAccountResolver;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Sync\Internal\Crypto\GdkKeyringService;

uses(RefreshDatabase::class);

// The refusal is correct — writing a plaintext matching key would double the
// ledger on the next re-import. What reached the screen was the exception's own
// message, which names an internal class and the reader's own user id, once for
// every row of the statement.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
it('tells the user to unlock rather than printing the crypto exception', function (): void {
    $user = User::query()->create([
        'username' => 'lock-import-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN locked',
        'slug' => 'locked-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(GdkKeyringService::class)->generateAndPersist((int) $user->id, $session);

    app(AppLockKeyService::class)->withhold($session);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/locked.csv',
        'sha256' => hash('sha256', 'locked-'.bin2hex(random_bytes(8))),
        'uploaded_at' => CarbonImmutable::parse('2026-07-01 09:00:00'),
        'status' => 'previewed',
    ]);

    $source = (static function (): Generator {
        yield new SourceTransactionDto(
            bookedAt: CarbonImmutable::parse('2026-07-01 12:00:00'),
            postedAt: CarbonImmutable::parse('2026-07-01'),
            valueDate: CarbonImmutable::parse('2026-07-01'),
            ownIban: 'NL57ASNB0123456789',
            counterpartyIban: 'NL11RABO0123456789',
            counterpartyName: 'Apotheek Zuiderhout',
            currency: 'EUR',
            amountMinor: -2450,
            sourceRef: 'LOCKED-1',
            description: 'pharmacy',
            rawPayload: [],
            sourceRowIndex: 0,
        );
    })();

    $rows = app(ImportPipeline::class)->previewFromGenerator(
        $source,
        'asn-csv',
        new EloquentAccountResolver($user),
        $user,
        (int) $run->id,
    )['rows'];

    expect($rows)->toHaveCount(1);
    expect($rows[0]->status)->toBe('error');
    expect($rows[0]->error)->toBe(Lang::get('import::preview.errors.app_locked'));
    expect($rows[0]->error)->not->toContain('BlindIndexCodec');
    expect($rows[0]->error)->not->toContain((string) $user->id);
});
