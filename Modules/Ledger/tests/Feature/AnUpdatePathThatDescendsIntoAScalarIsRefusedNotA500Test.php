<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Livewire\Mechanisms\HandleSynths\HandleSynths;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\LivewireClientRefusal;

uses(RefreshDatabase::class);

function scalarPathSnapshot(string $pageHtml): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $pageHtml, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"ledger.transactions-list"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the transactions list on the rendered page.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function scalarPathTamper(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [],
        ]],
    ]);
}

// `filterAccounts` is #[Url] and wire:model.live-bound, so the browser writes
// it legitimately and #[Locked] is not available. The first update supplies the
// int; the second asks Livewire to descend into it.
function scalarPathDescent(): TestResponse
{
    $snapshot = scalarPathSnapshot(test()->get('/transactions')->assertOk()->getContent());

    return scalarPathTamper($snapshot, [
        'filterAccounts' => [5],
        'filterAccounts.0.0' => true,
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'scalar-path',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);
});

it('answers one refusal to a path descending into a scalar element however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    scalarPathDescent()->assertStatus(400);
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

// Handler::convertExceptionToArray() returns an HttpException's message
// verbatim in production, and Livewire's json_encodes the value it stopped on
// -- which for a deep path is whatever the property holds.
it('answers without echoing what the property held', function (): void {
    config()->set('app.debug', false);

    $body = scalarPathDescent()->assertStatus(400)->getContent();

    expect(str_contains((string) $body, LivewireClientRefusal::UNSUPPORTED_TYPE_MESSAGE))->toBeFalse(
        'The production body repeats Livewire\'s own message, which carries the value the update path stopped on.',
    );
});

it('still keys on the exception Livewire actually throws', function (): void {
    $this->withoutExceptionHandling();

    try {
        scalarPathDescent();
    } catch (Throwable $refusal) {
        $frames = array_map(
            fn (array $frame): string => ($frame['class'] ?? '').'::'.($frame['function'] ?? ''),
            $refusal->getTrace(),
        );

        expect($refusal::class)->toBe(
            Exception::class,
            'Livewire no longer throws a bare \Exception for an unsupported property type. Key LivewireClientRefusal on the new class rather than on the message.',
        );
        expect($refusal->getMessage())->toStartWith(
            LivewireClientRefusal::UNSUPPORTED_TYPE_MESSAGE,
            'Livewire changed the message LivewireClientRefusal::UNSUPPORTED_TYPE_MESSAGE mirrors. Read HandleSynths::findByTarget() and update the constant; until then this refusal answers 500 again.',
        );
        expect(in_array(HandleComponents::class.'::recursivelySetValue', $frames, true))->toBeTrue(
            'Livewire no longer walks an update path through HandleComponents::recursivelySetValue(). That frame is the only thing telling a client-driven refusal apart from a render-time dehydration fault, so find its replacement before widening the match.',
        );

        return;
    }

    throw new RuntimeException('Livewire accepted an update path descending into a scalar.');
});

it('leaves a property type Livewire cannot dehydrate a server fault', function (): void {
    try {
        app(HandleSynths::class)->dehydrate(new class {}, null, 'somethingUnsupported');
    } catch (Throwable $e) {
        expect(LivewireClientRefusal::refusal($e))->toBeNull(
            'The same throw answers a component holding a property type Livewire cannot dehydrate, which is the server at fault. Mapping that to a 4xx would hide it.',
        );

        return;
    }

    throw new RuntimeException('Livewire dehydrated a type no synthesizer matches.');
});
