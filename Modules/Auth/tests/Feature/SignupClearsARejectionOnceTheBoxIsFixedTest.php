<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\SignupPage;

// The checklist under the password boxes is live and the rejection above it is
// not. Retyping the confirmation ticked "Both passwords match" while
// "Passwords do not match." stayed in red between the box and the tick.

it('drops the mismatch message once the confirmation is corrected', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'owner')
        ->set('password', 'a-long-enough-passphrase')
        ->set('passwordConfirmation', 'something-else-entirely')
        ->call('submit')
        ->assertHasErrors('passwordConfirmation')
        ->set('passwordConfirmation', 'a-long-enough-passphrase')
        ->assertHasNoErrors();
});

it('drops the mismatch message when the first box is the one corrected', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', 'owner')
        ->set('password', 'a-long-enough-passphrase')
        ->set('passwordConfirmation', 'something-else-entirely')
        ->call('submit')
        ->assertHasErrors('passwordConfirmation')
        ->set('password', 'something-else-entirely')
        ->assertHasNoErrors();
});

it('drops a username rejection once the username is retyped', function (): void {
    Livewire::test(SignupPage::class)
        ->set('username', '')
        ->set('password', 'a-long-enough-passphrase')
        ->set('passwordConfirmation', 'a-long-enough-passphrase')
        ->call('submit')
        ->assertHasErrors('username')
        ->set('username', 'owner')
        ->assertHasNoErrors();
});

it('syncs the boxes it must not contradict before the next submit', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/Auth/Resources/views/livewire/signup-page.blade.php')
    );

    // Without .live the modifier is ephemeral: the box syncs to the client-side
    // proxy on blur and no request is sent, so the hook that clears the message
    // does not run until the message is already gone.
    expect($blade)->toContain('wire:model.live.blur="password"')
        ->and($blade)->toContain('wire:model.live.blur="passwordConfirmation"')
        ->and($blade)->toContain('wire:model.live.blur="username"');
});
