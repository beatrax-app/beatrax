<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;

// IcsPdfAdapter emits the synthetic own-IBAN `ICS-CARD`. Gating the ICS naming
// prompt on "no card account exists" let a card account carrying any OTHER IBAN
// suppress it, and the generic unknown-IBAN namer then had to validate
// `ICS-CARD` as a real IBAN — it answers "IBAN must be 15..34 uppercase
// alphanumeric characters", so the import could never be confirmed.

function icsNamingUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'x',
        'period_start_day' => 1,
    ]);
}

function icsPdfRunFor(User $user, string $sha): int
{
    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => 'imports/'.$user->id.'/'.$sha.'.pdf',
        'sha256' => $sha,
        'uploaded_at' => now(),
    ]);

    return $run->id;
}

it('still asks for a card name when a card account carries a different IBAN', function (): void {
    $user = icsNamingUser('ics-other-iban@beatrax.local');

    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS Card',
        'slug' => 'ics-existing',
        'kind' => AccountKind::IcsCard->value,
        'iban' => 'ICS-DEMO-1-CARD',
        'default_currency' => 'EUR',
    ]);

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => icsPdfRunFor($user, str_repeat('a', 64))])
        ->viewData('needsIcsAccountName');

    expect($data)->toBeTrue();
});

// The prompt must still close once the literal itself is claimed, or every
// later ICS import would ask for a name it already has.
it('stops asking once an account carries the synthetic ICS IBAN', function (): void {
    $user = icsNamingUser('ics-claimed@beatrax.local');

    Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS Card',
        'slug' => 'ics-claimed',
        'kind' => AccountKind::IcsCard->value,
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => icsPdfRunFor($user, str_repeat('b', 64))])
        ->viewData('needsIcsAccountName');

    expect($data)->toBeFalse();
});

it('asks for a card name when the user has no card account at all', function (): void {
    $user = icsNamingUser('ics-none@beatrax.local');

    $data = Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => icsPdfRunFor($user, str_repeat('c', 64))])
        ->viewData('needsIcsAccountName');

    expect($data)->toBeTrue();
});
