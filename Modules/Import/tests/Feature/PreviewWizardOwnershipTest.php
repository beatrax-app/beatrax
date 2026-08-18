<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;

/*
 * IDOR regression. The mount() ownership gate alone was bypassable: $importRunId
 * was a plain (client-mutable) Livewire property, so an attacker could mount
 * their own run, retarget the id to another user's, and re-render — reading that
 * user's in-flight import preview (counterparty/IBAN/amount rows), because the
 * PreviewCache key is not user-scoped. These tests fail if #[Locked] or the
 * per-request ownership re-check in render()/applyRenameInPlace() is removed.
 */

function idorUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'x',
        'period_start_day' => 1,
    ]);
}

function seedRunForUser(User $user): int
{
    /** @var RunsImports $importer */
    $importer = app(RunsImports::class);

    $preview = $importer->runFromUpload(
        __DIR__.'/../../../../tests/fixtures/asn-sample-1.csv',
        'asn-csv',
        $user,
        'asn-sample-1.csv',
        BankCsvFormatHint::Asn,
    );

    return $preview->importRunId;
}

it('locks importRunId so a client cannot retarget the preview to another user', function (): void {
    $victim = idorUser('idor-victim');
    test()->actingAs($victim);
    $victimRunId = seedRunForUser($victim);

    $attacker = idorUser('idor-attacker');
    test()->actingAs($attacker);
    $attackerRunId = seedRunForUser($attacker);

    $component = Livewire::test(PreviewWizard::class, ['id' => $attackerRunId]);

    // Without #[Locked] this set succeeds and the next render leaks the victim's
    // preview; with it, Livewire refuses the client mutation.
    expect(fn () => $component->set('importRunId', $victimRunId))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('re-verifies ownership on applyRenameInPlace even for a foreign id (defence in depth)', function (): void {
    $victim = idorUser('idor-victim-2');
    test()->actingAs($victim);
    $victimRunId = seedRunForUser($victim);

    $attacker = idorUser('idor-attacker-2');
    test()->actingAs($attacker);

    // Bypass the Livewire #[Locked] guard at the PHP level to prove the
    // per-request ownership re-check is itself load-bearing (would fire if a
    // future change dropped #[Locked]).
    $component = new PreviewWizard;
    $component->importRunId = $victimRunId;

    expect(fn () => $component->applyRenameInPlace(0, 'poison', app(PreviewCache::class), app(CurrentUser::class)))
        ->toThrow(ModelNotFoundException::class);
});
