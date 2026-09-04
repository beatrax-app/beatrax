<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Mobile\Internal\Http\Middleware\ForgetStaleLivewireHeaderBetweenRequests;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'stale-livewire-header',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    // The rendered tab strip, as aria-selected pairs.
    $this->selection = function (string $html): array {
        $matches = PatternScan::all('/aria-selected="(true|false)"[^>]*>([\s\S]{0,40}?)</', $html);

        $pairs = [];
        foreach ($matches[2] as $index => $label) {
            $pairs[trim((string) preg_replace('/\s+/', ' ', $label))] = $matches[1][$index] === 'true';
        }

        return $pairs;
    };
});

it('reads a deep link query parameter even when the request carries a Livewire header', function (): void {
    // Livewire's #[Url] reads the query string from the URL on a page load and
    // from the Referer on a component update, and it tells the two apart by
    // this header. A persistent runtime that leaves one behind therefore makes
    // every deep link land on the default view.
    /** @link ../../../../.docs/features/mobile/architecture.md */
    app(Kernel::class)->prependMiddleware(ForgetStaleLivewireHeaderBetweenRequests::class);

    $html = (string) $this->withHeaders(['X-Livewire' => 'true'])
        ->get('/drift?type=anomaly')
        ->assertOk()
        ->getContent();

    $selected = ($this->selection)($html);

    expect($selected['Unusual charges'] ?? null)->toBeTrue()
        ->and($selected['Subscription drift'] ?? null)->toBeFalse();
});

it('still reads the query string with no such header', function (): void {
    $html = (string) $this->get('/drift?type=anomaly')->assertOk()->getContent();

    $selected = ($this->selection)($html);

    expect($selected['Unusual charges'] ?? null)->toBeTrue();
});

it('leaves the header alone on the endpoint that means it', function (): void {
    // Stripping it there would turn every component update into a page load,
    // which is the opposite failure: #[Url] would then read the URL of the
    // update endpoint instead of the Referer the reader is actually on.
    $middleware = app(ForgetStaleLivewireHeaderBetweenRequests::class);

    $request = Request::create(app('livewire')->getUpdateUri(), 'POST');
    $request->headers->set('X-Livewire', 'true');

    $middleware->handle($request, function (Request $passed): Response {
        expect($passed->hasHeader('X-Livewire'))->toBeTrue();

        return new Response;
    });
});

// The middleware only reaches a request where it is registered, and the runtime
// that needs it is the persistent mobile worker rather than this root.
it('is prepended by the mobile runtime that keeps a worker alive between requests', function (): void {
    $bootstrap = dirname(__DIR__, 4).'/mobile-app/bootstrap/app.php';

    expect(file_exists($bootstrap))->toBeTrue()
        ->and((string) file_get_contents($bootstrap))
        ->toContain('ForgetStaleLivewireHeaderBetweenRequests::class');
});
