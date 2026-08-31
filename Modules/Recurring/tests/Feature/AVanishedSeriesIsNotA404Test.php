<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\Http\Livewire\RecurringReviewPage;

function vanishedSeriesUser(): User
{
    return User::query()->create([
        'username' => 'vanseries-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    $this->user = vanishedSeriesUser();
});

afterEach(fn () => CarbonImmutable::setTestNow());

// Two tabs, or a detection sweep between render and click: the row is gone
// and the action raises. apply() answers false, so no success toast is raised
// and the re-render shows the queue as it really is.
it('answers every review action on a vanished series without a 404', function (string $method, array $args): void {
    Livewire::actingAs($this->user)
        ->test(RecurringReviewPage::class)
        ->call($method, ...$args)
        ->assertOk()
        ->assertNotDispatched('toast');
})->with([
    'approve' => ['approve', [424242]],
    'reject' => ['reject', [424242]],
    'unReject' => ['unReject', [424242]],
    'editName' => ['editName', [424242, 'Renamed']],
    'snooze' => ['snooze', [424242, '2026-06-17T12:00:00+00:00']],
]);
