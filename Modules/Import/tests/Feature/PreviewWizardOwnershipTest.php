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

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
});

// IDOR regression: the mount() gate alone was bypassable, because $importRunId
// was a client-mutable Livewire property and the PreviewCache key is not
// user-scoped. These fail if #[Locked] or the per-request ownership re-check in
// render()/applyRenameInPlace() is removed.

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

    // Constructed directly to step around #[Locked], so what is under test is
    // the ownership re-check alone.
    $component = new PreviewWizard;
    $component->importRunId = $victimRunId;

    expect(fn () => $component->applyRenameInPlace(0, 'poison', app(PreviewCache::class), app(CurrentUser::class)))
        ->toThrow(ModelNotFoundException::class);
});
