<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Core\Public\Support\PatternScan;
use RuntimeException;

// Replays a rendered page's own component snapshot back through the Livewire
// update endpoint over real HTTP. `Livewire::test()` runs neither the
// middleware stack nor the hydrate hooks, so anything those two collide over
// — the request locale, for one — only a genuine round-trip can pin.
final class LivewireRoundTrip
{
    /**
     * @param  array<string, string>  $updates  public properties to set before the call
     */
    public static function call(
        TestCase $test,
        string $pageHtml,
        string $component,
        string $method,
        array $updates = [],
    ): string {
        $response = self::post($test, self::snapshotFor($pageHtml, $component), $updates, [
            ['path' => '', 'method' => $method, 'params' => []],
        ])->assertOk();

        $html = $response->json('components.0.effects.html');

        return is_string($html) ? $html : '';
    }

    // The same round-trip with no expectation of success: a payload written to
    // be refused needs the status back, not the rendered fragment. It lives
    // beside call() because a test proving a refusal against an envelope the
    // client never sends proves nothing about the client.
    /**
     * @param  array<string, mixed>  $updates
     * @param  list<array<string, mixed>>  $calls
     */
    public static function tamper(
        TestCase $test,
        string $snapshot,
        array $updates,
        array $calls = [],
    ): TestResponse {
        return self::post($test, $snapshot, $updates, $calls);
    }

    // A page carries one wire:snapshot per component on it, so the wanted one
    // is found by the component name inside the snapshot's own memo rather
    // than by position - the layout's chrome components render first.
    public static function snapshotFor(string $pageHtml, string $component): string
    {
        $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);

        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES);

            if (str_contains($snapshot, '"name":"'.$component.'"')) {
                return $snapshot;
            }
        }

        throw new RuntimeException("No wire:snapshot for [{$component}] on the rendered page.");
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  list<array<string, mixed>>  $calls
     */
    private static function post(
        TestCase $test,
        string $snapshot,
        array $updates,
        array $calls,
    ): TestResponse {
        return $test->withHeaders(['X-Livewire' => 'true'])
            ->postJson(Livewire::getUpdateUri(), [
                '_token' => csrf_token(),
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => $updates,
                    'calls' => $calls,
                ]],
            ]);
    }
}
