<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Foundation\Testing\TestCase;
use Livewire\Livewire;
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
        $response = $test->withHeaders(['X-Livewire' => 'true'])
            ->postJson(Livewire::getUpdateUri(), [
                'components' => [[
                    'snapshot' => self::snapshotFor($pageHtml, $component),
                    'updates' => $updates,
                    'calls' => [['path' => '', 'method' => $method, 'params' => []]],
                ]],
            ])
            ->assertOk();

        $html = $response->json('components.0.effects.html');

        return is_string($html) ? $html : '';
    }

    // A page carries one wire:snapshot per component on it, so the wanted one
    // is found by the component name inside the snapshot's own memo rather
    // than by position - the layout's chrome components render first.
    private static function snapshotFor(string $pageHtml, string $component): string
    {
        preg_match_all('/wire:snapshot="([^"]*)"/', $pageHtml, $matches);

        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES);

            if (str_contains($snapshot, '"name":"'.$component.'"')) {
                return $snapshot;
            }
        }

        throw new RuntimeException("No wire:snapshot for [{$component}] on the rendered page.");
    }
}
