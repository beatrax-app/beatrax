<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Chains\Internal\Http\Livewire\ChainHintsQueue;
use Modules\Chains\Internal\Http\Livewire\ChainReviewQueue;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// Two tabs on /chains/review: the candidate this one confirms was already
// confirmed in the other. The ownership check is right; its 404 reaching the
// browser instead of the queue's own error line is not.

function chainQueueUser(): User
{
    return User::query()->create([
        'username' => 'chain-queue-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

it('answers a confirm on a link that is gone with the queue error line', function (): void {
    $this->actingAs(chainQueueUser());

    Livewire::test(ChainReviewQueue::class)
        ->call('confirm', 999999)
        ->assertStatus(200)
        ->assertSet('actionError', Lang::get('core::errors.no_longer_here'));
});

it('answers a reject on a link that is gone with the queue error line', function (): void {
    $this->actingAs(chainQueueUser());

    Livewire::test(ChainReviewQueue::class)
        ->call('reject', 999999)
        ->assertStatus(200)
        ->assertSet('actionError', Lang::get('core::errors.no_longer_here'));
});

it('answers a dismiss on a hint that is gone with the queue status line', function (): void {
    $this->actingAs(chainQueueUser());

    Livewire::test(ChainHintsQueue::class)
        ->call('dismiss', 999999)
        ->assertStatus(200)
        ->assertSet('statusMessage', Lang::get('core::errors.no_longer_here'));
});
