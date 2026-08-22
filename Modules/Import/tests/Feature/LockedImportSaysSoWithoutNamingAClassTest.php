<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Public\Enums\PreviewRowStatus;
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
    expect($rows[0]->status)->toBe(PreviewRowStatus::Error);
    expect($rows[0]->error)->toBe(Lang::get('import::preview.errors.app_locked'));
    expect($rows[0]->error)->not->toContain('BlindIndexCodec');
    expect($rows[0]->error)->not->toContain((string) $user->id);
});

// The same refusal arriving by the other door. SensitiveColumnCodec now refuses
// rather than sealing nothing and returning the plaintext, and the state that
// reaches it without the blind index throwing first is the documented stranded
// one: `current_epoch` points at an epoch the keyring holds no key for, so the
// blind-index key still derives while no AEAD column can be sealed. Told as
// "row unreadable" the reader has nothing to act on; it is an app-lock problem.
it('tells the user to unlock when the AEAD column is the one that cannot be sealed', function (): void {
    $user = User::query()->create([
        'username' => 'lock-import-aead-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN stranded',
        'slug' => 'stranded-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
    app(GdkKeyringService::class)->generateAndPersist((int) $user->id, $session);

    // The key is NOT withheld: the session stays unlocked, so the blind index
    // still derives. Only the epoch pointer is moved off the key the keyring
    // holds, which is what a crash in the commit-then-finalize window leaves.
    DB::table('sync_encryption_state')->where('user_id', $user->id)->update(['current_epoch' => 987654321]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/stranded.csv',
        'sha256' => hash('sha256', 'stranded-'.bin2hex(random_bytes(8))),
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
            sourceRef: 'STRANDED-1',
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
    expect($rows[0]->status)->toBe(PreviewRowStatus::Error);
    expect($rows[0]->error)->toBe(Lang::get('import::preview.errors.app_locked'));
    expect($rows[0]->error)->not->toContain('SensitiveColumnCodec');
    expect($rows[0]->error)->not->toContain((string) $user->id);
    // One sentence covers both doors, so it may not name the blind index's
    // merchant key as the thing that failed: here the merchant key derives
    // fine and the AEAD column is the one that cannot be sealed.
    expect($rows[0]->error)->not->toContain('merchant key');
});
