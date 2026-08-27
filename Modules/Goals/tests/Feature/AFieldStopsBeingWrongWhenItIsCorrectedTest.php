<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Goals\Internal\Http\Livewire\GoalsPage;

// Found on an iPhone: the sheet showed 15/06/2027 in the target-date field and
// "Choose a target date." in red under it, with aria-invalid still true, for as
// long as the reader left it there. The date control syncs the moment a day is
// tapped, so the refusal outlived what it was refusing.
beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'stale-error',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
});

it('drops the date error as soon as a date is chosen', function (): void {
    Livewire::actingAs($this->user)->test(GoalsPage::class)
        ->set('name', 'Nieuwe keuken')
        ->set('targetAmount', '5000,00')
        ->set('targetDate', '')
        ->call('createGoal')
        ->assertSet('errorDate', 'Choose a target date.')
        ->set('targetDate', '2027-06-15')
        ->assertSet('errorDate', '');
});

// The message and the attribute are drawn from the same property, so a screen
// that keeps one keeps the other.
it('stops marking the control invalid once it is no longer invalid', function (): void {
    $rendered = Livewire::actingAs($this->user)->test(GoalsPage::class)
        ->set('name', 'Nieuwe keuken')
        ->set('targetAmount', '5000,00')
        ->set('targetDate', '')
        ->call('createGoal');

    expect($rendered->html())->toContain('goal-date-sheet-error');

    $rendered->set('targetDate', '2027-06-15');

    expect($rendered->html())->not->toContain('goal-date-sheet-error');
});
