<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Native;

use Modules\Core\Public\Support\Lang;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\NativeComponent;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class AppShellScreen extends NativeComponent
{
    // The web path the embedded webview is currently showing. Every nav
    // destination is an existing Livewire route — this screen introduces no
    // new surfaces, it only changes what wraps them.
    public string $path = '/';

    // Which bottom-nav entry reads as active. Derived from $path rather than
    // set alongside it, so an in-page link that moves the webview cannot
    // leave the chrome pointing at the wrong tab.
    public string $active = 'dashboard';

    // Labels are the sidebar's own keys rather than new ones: this bar is
    // the drawer's replacement on a phone, and two key sets for the same
    // four destinations is how a Dutch build ends up half-translated.
    /** @var array<string, array{label: string, path: string, ios: string, android: string}> */
    public const DESTINATIONS = [
        'dashboard' => ['label' => 'core::sidebar.nav.dashboard', 'path' => '/', 'ios' => 'house', 'android' => 'home'],
        'transactions' => ['label' => 'core::sidebar.nav.transactions', 'path' => '/transactions', 'ios' => 'list.bullet', 'android' => 'list'],
        'calendar' => ['label' => 'core::sidebar.nav.calendar', 'path' => '/calendar', 'ios' => 'calendar', 'android' => 'calendar_month'],
        'settings' => ['label' => 'core::sidebar.nav.settings', 'path' => '/settings', 'ios' => 'gearshape', 'android' => 'settings'],
    ];

    // The destination table with its label keys resolved for the active
    // locale — what the view actually renders.
    /** @return array<string, array{label: string, path: string, ios: string, android: string}> */
    public static function destinations(): array
    {
        return array_map(
            static fn (array $d): array => [...$d, 'label' => Lang::get($d['label'])],
            self::DESTINATIONS,
        );
    }

    public function open(string $key): void
    {
        if (! isset(self::DESTINATIONS[$key])) {
            return;
        }

        $this->active = $key;
        $this->path = self::DESTINATIONS[$key]['path'];
    }

    // Fired by the webview on every committed top-frame navigation. Without
    // this the chrome desyncs the moment the user follows a link inside the
    // page rather than tapping the bottom bar.
    public function onNavigated(string $url = ''): void
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return;
        }

        $this->active = $this->destinationFor($path) ?? $this->active;
    }

    // `$this->view()` rather than the `view()` helper or a module-namespaced
    // name: the base class hardcodes a `native.` prefix and routes the result
    // through wrapWithChrome(), which is what hoists the inline top bar and
    // bottom nav onto the real native shell. A raw View skips that.
    public function render(): Element
    {
        return $this->view('app-shell', [
            'destinations' => self::destinations(),
        ]);
    }

    public function navTitle(): string
    {
        $key = self::DESTINATIONS[$this->active]['label'] ?? null;

        return $key === null ? 'beatrax' : Lang::get($key);
    }

    // The longest destination path that prefixes $path, so `/transactions/42`
    // still lights the Transactions tab. Root only ever matches itself —
    // every path is prefixed by `/`, which would otherwise win everything.
    private function destinationFor(string $path): ?string
    {
        $best = null;
        $bestLength = -1;

        foreach (self::DESTINATIONS as $key => $destination) {
            $candidate = $destination['path'];
            $matches = $candidate === '/'
                ? $path === '/'
                : str_starts_with($path, $candidate);

            if ($matches && mb_strlen($candidate) > $bestLength) {
                $best = $key;
                $bestLength = mb_strlen($candidate);
            }
        }

        return $best;
    }
}
