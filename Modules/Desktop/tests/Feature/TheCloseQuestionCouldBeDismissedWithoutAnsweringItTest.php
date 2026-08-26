<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;

// Flux splits the three ways out of a modal across three props: dismissible
// covers a click outside, escapable the Escape key, closable the × in the
// corner. The prompt set only the first, so Escape and the × both dismissed it
// — and this page has nothing on it but the modal, so either one left an empty
// window with the close unanswered and no behaviour recorded, which means the
// same empty window on the next close too.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'close-prompt-escape',
        'password' => 'opensesame-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('closes on neither Escape, a click outside, nor a corner cross', function (): void {
    Livewire::test(CloseWindowPrompt::class)
        ->assertSee('disable-escape', false)
        ->assertSee('disable-click-outside', false)
        ->assertDontSee('Close modal');
});

it('still offers both answers', function (): void {
    Livewire::test(CloseWindowPrompt::class)
        ->assertSee('chooseQuit', false)
        ->assertSee('chooseKeepInTray', false);
});
