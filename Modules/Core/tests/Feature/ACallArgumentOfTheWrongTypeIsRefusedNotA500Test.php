<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleComponents\HandleComponents;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\LivewireClientRefusal;
use Modules\Core\Public\Support\PatternScan;

uses(RefreshDatabase::class);

// callMethods() splats the payload's own `params` into the method with no
// coercion, and Livewire's TypeError catch covers the property-assignment path
// rather than a method body. The palette search endpoint is the shortest reach:
// the shared layout mounts it on every authenticated page.

function callArgumentSnapshot(string $pageHtml): string
{
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"search.palette-search-endpoint"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the palette search endpoint.');
}

/**
 * @param  list<mixed>  $params
 */
function callArgumentTamper(string $snapshot, string $method, array $params): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['method' => $method, 'params' => $params, 'path' => '']],
        ]],
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'call-argument-type',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->snapshot = callArgumentSnapshot($this->get('/transactions')->assertOk()->getContent());
});

// The two builds do not answer the same number and cannot be made to.
// HandleRequests::handleUpdate() report()s the TypeError and then abort(419)s
// before the handler is reached with app.debug off; with it on it rethrows, and
// that rethrow is the 500 this maps. Both are refusals, neither is a 500.
it('never answers a server fault to an argument of the wrong type', function (bool $debug, int $expected): void {
    config()->set('app.debug', $debug);

    $response = callArgumentTamper($this->snapshot, 'search', [['zzz']]);

    expect($response->getStatusCode())->toBe($expected);
})->with([
    'debug build' => [true, 400],
    'production build' => [false, 419],
]);

// Handler::convertExceptionToArray() returns an HttpException's message
// verbatim in production, and PHP's own TypeError message spells out the whole
// declared signature and the absolute path of the file that made the call.
it('says what was refused without repeating the signature PHP names', function (): void {
    config()->set('app.debug', true);

    /** @var array{message?: string} $body */
    $body = (array) json_decode((string) callArgumentTamper($this->snapshot, 'search', [['zzz']])->getContent(), true);
    $message = $body['message'] ?? '';

    expect($message)->not->toBe('')
        ->and($message)->not->toContain('must be of type')
        ->and($message)->not->toContain(base_path());
});

it('leaves a call the browser makes properly working', function (): void {
    callArgumentTamper($this->snapshot, 'search', ['groceries'])->assertOk();
});

it('still separates the splat from a TypeError inside the method body', function (): void {
    expect(LivewireClientRefusal::refusal(new TypeError('handed the wrong type')))->toBeNull(
        'A TypeError with no Livewire frames at all is the ordinary shape of a server fault and has to stay a 500.',
    );
});

it('still keys on the frames Livewire actually reaches a component method through', function (): void {
    $this->withoutExceptionHandling();

    try {
        callArgumentTamper($this->snapshot, 'search', [['zzz']]);
    } catch (Throwable $refused) {
        $frames = array_map(
            fn (array $frame): string => ($frame['class'] ?? '').'::'.($frame['function'] ?? ''),
            $refused->getTrace(),
        );

        expect($refused::class)->toBe(
            TypeError::class,
            'The splat no longer raises a TypeError. Re-read HandleComponents::callMethods() before widening the match.',
        );
        expect($frames[1] ?? '')->toStartWith(
            'Illuminate\Container\BoundMethod::',
            "Livewire no longer reaches a component method through the container's own invoker. That frame is the only thing separating an argument the payload chose from a TypeError inside the method body, so find its replacement before widening the match.",
        );
        expect(in_array(HandleComponents::class.'::callMethods', $frames, true))->toBeTrue(
            'Livewire no longer assembles a call through HandleComponents::callMethods(). Without that frame the seam would answer 4xx for the app\'s own type errors.',
        );

        return;
    }

    throw new RuntimeException('Livewire accepted an argument of a type the method cannot hold.');
});
