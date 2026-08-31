<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Internal\Services\OwnAccountPrompt;
use Modules\Import\Public\Actions\EnsureGooglePlayAccountAction;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\ImportRun;

function foreignRunReader(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'x',
        'period_start_day' => 1,
    ]);
}

// The prompt is asked directly rather than through PreviewWizard: the wizard's
// mount-time ownership assertion is exactly the caller discipline the class must
// not be relying on.
function foreignRunActingAs(User $user): CurrentUser
{
    test()->actingAs($user);

    return app(CurrentUser::class);
}

function foreignRunWithACachedPreview(User $owner, string $sha): int
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $owner->id,
        'source_format' => 'eml',
        'raw_file_path' => tempnam(sys_get_temp_dir(), 'foreign-run').'.eml',
        'sha256' => $sha,
        'uploaded_at' => now(),
    ]);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put(
        $run->id,
        new ImportPreviewResult(
            importRunId: $run->id,
            rows: [new PreviewRowDto(
                rowIndex: 0,
                status: PreviewRowStatus::Error,
                accountId: null,
                postedAt: '2026-05-17',
                counterpartyName: 'Google Play',
                counterpartyIban: null,
                description: 'Google Play',
                amountMinor: -1299,
                currency: 'USD',
                error: null,
            )],
            accountsToName: [new UnknownIban(
                EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN,
                'Google Play',
                statementCurrency: 'USD',
            )],
        ),
        canonical: [],
    );

    return $run->id;
}

// The preview cache key is not user-scoped, so a run id is the only thing
// standing between one reader and another's in-flight import.
it('does not raise a naming prompt off another reader\'s preview', function (): void {
    $alice = foreignRunReader('foreign-run-alice');
    $bob = foreignRunReader('foreign-run-bob');
    $runId = foreignRunWithACachedPreview($alice, str_repeat('a', 64));

    /** @var OwnAccountPrompt $prompt */
    $prompt = app(OwnAccountPrompt::class);

    expect($prompt->needsGooglePlayAccountName($runId, foreignRunActingAs($bob)))->toBeFalse();
});

it('still raises it for the reader whose run it is', function (): void {
    $alice = foreignRunReader('foreign-run-owner');
    $runId = foreignRunWithACachedPreview($alice, str_repeat('b', 64));

    /** @var OwnAccountPrompt $prompt */
    $prompt = app(OwnAccountPrompt::class);

    expect($prompt->needsGooglePlayAccountName($runId, foreignRunActingAs($alice)))->toBeTrue();
});

// The denomination comes off the parsed file, so answering it is handing over a
// fact about somebody else's statement -- and it is what the wizard writes onto
// the account it mints.
it('does not report the denomination of another reader\'s statement', function (): void {
    $alice = foreignRunReader('foreign-currency-alice');
    $bob = foreignRunReader('foreign-currency-bob');
    $runId = foreignRunWithACachedPreview($alice, str_repeat('c', 64));

    /** @var OwnAccountPrompt $prompt */
    $prompt = app(OwnAccountPrompt::class);

    expect($prompt->statementCurrency($runId, foreignRunActingAs($bob), EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN))
        ->toBeNull();
});

it('reports it to the reader whose statement it is', function (): void {
    $alice = foreignRunReader('own-currency-alice');
    $runId = foreignRunWithACachedPreview($alice, str_repeat('d', 64));

    /** @var OwnAccountPrompt $prompt */
    $prompt = app(OwnAccountPrompt::class);

    expect($prompt->statementCurrency($runId, foreignRunActingAs($alice), EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN))
        ->toBe('USD');
});

// The write-side guard the three save methods return on. Fail-closed on a run
// that is not the caller's: a save must not land an account off it.
it('refuses to name anything on a run that is not the reader\'s', function (): void {
    $alice = foreignRunReader('foreign-guard-alice');
    $bob = foreignRunReader('foreign-guard-bob');
    $runId = foreignRunWithACachedPreview($alice, str_repeat('e', 64));

    /** @var OwnAccountPrompt $prompt */
    $prompt = app(OwnAccountPrompt::class);

    expect($prompt->hasNothingToName($runId, foreignRunActingAs($bob)))->toBeTrue()
        ->and($prompt->hasNothingToName($runId, foreignRunActingAs($alice)))->toBeFalse();
});
