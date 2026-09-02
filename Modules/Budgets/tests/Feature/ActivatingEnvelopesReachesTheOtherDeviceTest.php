<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Budgets\Public\Services\EnvelopeActivationService;
use Modules\Core\Models\User;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// envelope_activated_at is the carryover fold's genesis anchor, and the merge
// registry already says what a null one costs: a device reads every synced
// assignment as zero. The claim wrote it and announced nothing, so a peer
// learnt it only by pairing after activation and taking the whole row.
function envelopeActivationAnnouncements(): array
{
    $announced = [];
    foreach (Event::dispatched(EntityMutated::class) as [$event]) {
        if ($event->table === 'users' && array_key_exists('envelope_activated_at', $event->dirtyFields)) {
            $announced[] = $event;
        }
    }

    return $announced;
}

it('announces the genesis anchor when envelopes are activated', function (): void {
    $user = User::create([
        'username' => 'activate-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    Event::fake([EntityMutated::class]);

    app(EnvelopeActivationService::class)->activateForUser($user->id);

    $announced = envelopeActivationAnnouncements();
    expect($announced)->toHaveCount(1);
    expect($announced[0]->mutationType)->toBe('edit');
    expect($announced[0]->dirtyFields['envelope_activated_at'])->not->toBeNull();

    $stored = DB::table('users')->where('id', $user->id)->value('envelope_activated_at');
    expect($announced[0]->dirtyFields['envelope_activated_at'])->toBe($stored);
});

it('says nothing when the claim was already taken', function (): void {
    $user = User::create([
        'username' => 'activate-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);

    app(EnvelopeActivationService::class)->activateForUser($user->id);

    Event::fake([EntityMutated::class]);
    app(EnvelopeActivationService::class)->activateForUser($user->id);

    // The claim is atomic and the second call flips nothing, so there is no
    // second stamp for a peer to hear about.
    expect(envelopeActivationAnnouncements())->toBe([]);
});
