<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Actions\LabelCounterparty;
use Modules\Counterparties\Internal\Enums\CounterpartyMetadataKey;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// `counterparties.metadata` is one column and it merges whole. The
// `default_name` token inside it is the provenance of `display_name` --
// "nobody chose this name" -- and label() drops the token in the same edit that
// sets the name, saying in a comment why it has to. ignore() republished the
// whole blob it still held, token included, with no name beside it. A peer that
// had already renamed the row then read the reader's own words back as the
// app's placeholder, on every surface, with nothing logged.

function ftwUser(): User
{
    return User::query()->create([
        'username' => 'ftw-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function ftwCounterparty(int $userId, string $name, ?string $token): Counterparty
{
    $metadata = $token === null ? null : [CounterpartyMetadataKey::DefaultName->value => $token];

    return Counterparty::query()->create([
        'user_id' => $userId,
        'display_name' => $name,
        'slug' => 'ftw-'.bin2hex(random_bytes(4)),
        'type' => CounterpartyType::Unknown->value,
        'metadata' => $metadata,
    ]);
}

// A real listener, not Event::fake(): the action takes its Dispatcher through
// the constructor, so an action resolved before the fake goes in keeps
// announcing to the dispatcher it was built with and the fake records nothing.
//
// The buffer is cleared by the caller first, because creating the fixture row
// announces one of these too -- and a capture that kept the last event of any
// kind read the CREATE's fields and passed with the fix backed out.
/** @return array<string, mixed> */
function ftwAnnouncedFields(): array
{
    /** @var list<array<string, mixed>> $captured */
    $captured = test()->ftwCaptured;

    expect($captured)->toHaveCount(1, 'the edit announced '.count($captured).' events, so the one read below is a guess.');

    return $captured[0];
}

function ftwForget(): void
{
    test()->ftwCaptured = [];
}

beforeEach(function (): void {
    $this->ftwCaptured = [];

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->listen(EntityMutated::class, function (EntityMutated $event): void {
        if ($event->table === 'counterparties' && $event->mutationType === 'edit') {
            $this->ftwCaptured[] = $event->dirtyFields;
        }
    });

    $this->user = ftwUser();
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $this->session = $session;
    /** @var LabelCounterparty $labeller */
    $labeller = $this->app->make(LabelCounterparty::class);
    $this->labeller = $labeller;
});

it('sends the name beside the flag that says whose words it is', function (): void {
    $row = ftwCounterparty((int) $this->user->id, 'Unknown', CounterpartyDefaultName::UNKNOWN);

    ftwForget();

    $this->labeller->ignore($row, (int) $this->user->id, $this->session);

    $fields = ftwAnnouncedFields();

    expect($fields)->toHaveKey('display_name')
        ->and($fields['display_name'])->toBe('Unknown')
        ->and($fields['metadata'])->toHaveKey(CounterpartyMetadataKey::DefaultName->value);
});

// The blob merges by key now, and a key union cannot carry an absence: an
// unset reaches the peer as "this device never held the key", which its own
// copy of the token then survives. Cleared to a present null instead, which
// tokenIn() has always read as no token.
it('clears the flag with a key it still sends, not by dropping it', function (): void {
    $row = ftwCounterparty((int) $this->user->id, 'Unknown', CounterpartyDefaultName::UNKNOWN);

    ftwForget();

    $this->labeller->label(
        $row,
        (int) $this->user->id,
        CounterpartyType::Unknown,
        'Albert Heijn',
        null,
        $this->session,
    );

    $fields = ftwAnnouncedFields();

    expect($fields)->toHaveKey('metadata')
        ->and($fields['metadata'])->toHaveKey(CounterpartyMetadataKey::DefaultName->value)
        ->and($fields['metadata'][CounterpartyMetadataKey::DefaultName->value])->toBeNull()
        ->and(CounterpartyDefaultName::tokenIn($fields['metadata']))->toBeNull();
});

// The positive control. A row the reader already named carries no token, so
// there is no provenance to land on a peer's rename and no reason to republish
// a name this edit did not touch.
it('sends the blob alone for a row that carries no such flag', function (): void {
    $row = ftwCounterparty((int) $this->user->id, 'Albert Heijn', null);

    ftwForget();

    $this->labeller->ignore($row, (int) $this->user->id, $this->session);

    $fields = ftwAnnouncedFields();

    expect($fields)->toHaveKey('metadata')
        ->and($fields)->not->toHaveKey('display_name');
});

// What the peer does with what arrived: applying the announced fields whole is
// the merge, and the row must still read as the words the reader typed.
it('leaves a peers rename readable once both fields land', function (): void {
    $row = ftwCounterparty((int) $this->user->id, 'Unknown', CounterpartyDefaultName::UNKNOWN);

    ftwForget();

    $this->labeller->ignore($row, (int) $this->user->id, $this->session);

    $arrived = ftwAnnouncedFields();

    // The peer renamed it while this device was away, so its row already holds
    // the reader's words and no token.
    $peer = ftwCounterparty((int) $this->user->id, 'Albert Heijn', null);

    /** @var array<string, mixed> $metadata */
    $metadata = $arrived['metadata'];
    /** @var string $name */
    $name = $arrived['display_name'];

    $peer->forceFill(['display_name' => $name, 'metadata' => $metadata])->save();

    expect(CounterpartyDefaultName::resolve($peer->display_name, $peer->metadata))
        ->toBe('Unknown');
});
