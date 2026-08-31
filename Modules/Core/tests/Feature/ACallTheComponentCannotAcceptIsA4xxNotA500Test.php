<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Public\Support\LivewireClientRefusal;

// The sibling file covers the `updates` half of a payload. The `calls` half had
// no mapping at all: naming a method the component does not have, and calling
// one without the argument it requires, were both 500s in debug and in
// production alike -- the one answer that says the server did something wrong
// when what was wrong is the payload.

// A wrong-TYPED argument is deliberately absent from this file: Livewire
// catches that \TypeError in HandleRequests::handleUpdate() and answers 419
// itself, so it never reaches the handler this seam hangs off.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'refused-call',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

function refusedCallSnapshot(): string
{
    $html = Livewire::test(SystemAlertsBanner::class)->html();

    expect(preg_match('/wire:snapshot="([^"]*)"/', $html, $matches))->toBe(1);

    return html_entity_decode($matches[1], ENT_QUOTES);
}

/**
 * @param  list<mixed>  $params
 */
function refusedCall(string $method, array $params): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => refusedCallSnapshot(),
            'updates' => [],
            'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
        ]],
    ]);
}

it('answers a call to a method the component does not have with a 400', function (): void {
    refusedCall('acknowledgeEverything', [])->assertStatus(400);
});

it('answers a required argument the call left out with a 400', function (): void {
    refusedCall('acknowledge', [])->assertStatus(400);
});

// Same answer either way, like the locked-property refusal beside it: with
// debug off the message IS the whole body, and the container's own words spell
// out the reflected parameter signature of an internal class.
it('answers the same 400 with debug off, naming the shape and not the signature', function (): void {
    config(['app.debug' => false]);

    $response = refusedCall('acknowledge', []);

    $response->assertStatus(400);
    expect($response->json('message'))->not->toContain('Parameter #');
});

// The discriminator, stated as its own case: the container raises this class
// for any binding it cannot resolve, and one raised outside a wire call is a
// genuine server fault that must stay a 500.
it('leaves a binding failure raised outside a wire call unmapped', function (): void {
    $serverFault = null;

    try {
        app()->make('a-binding-nothing-registers');
    } catch (BindingResolutionException $e) {
        $serverFault = $e;
    }

    expect($serverFault)->toBeInstanceOf(BindingResolutionException::class)
        ->and(LivewireClientRefusal::refusal($serverFault))->toBeNull();
});
