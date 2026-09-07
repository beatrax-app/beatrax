<?php

declare(strict_types=1);

// The shell hands the phone's answer to the page as a CustomEvent built with a
// `detail` and nothing else, so `bubbles` is false and it never leaves the node
// it was dispatched on. Both shells dispatch on `document`; the listener was on
// `window`, so the notification grant was asked for, answered by the OS,
// dispatched by the shell — and recorded nowhere. Measured on an iPhone 12 mini
// before this, and after it.

/** @return list<string> both shell templates, from either composer root */
function nativeEventShellTemplates(): array
{
    $relative = [
        'vendor/nativephp/mobile/resources/xcode/NativePHP/ContentView.swift',
        'vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/utils/NativeActionCoordinator.kt',
    ];

    $found = [];

    foreach ($relative as $path) {
        foreach ([base_path('mobile-app/'.$path), base_path($path)] as $candidate) {
            if (is_file($candidate)) {
                $found[] = $candidate;

                break;
            }
        }
    }

    return $found;
}

function nativeEventAppJs(): string
{
    foreach ([base_path('resources/js/app.js'), base_path('../resources/js/app.js')] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return '';
}

it('reads both shell templates rather than assuming one of them', function (): void {
    expect(nativeEventShellTemplates())->toHaveCount(
        2,
        'the iOS and Android shell templates are what say where the event lands; neither was opened'
    );
});

it('has every shell dispatching the event on the document', function (): void {
    foreach (nativeEventShellTemplates() as $template) {
        $source = (string) file_get_contents($template);

        expect(str_contains($source, 'document.dispatchEvent'))->toBeTrue(
            $template.' dispatches the native event somewhere else, and the listener has to move with it'
        );
    }
});

// The half that makes the target matter. A bubbling event would reach window
// from document, and this whole finding would not exist.
it('has no shell asking for a bubbling event', function (): void {
    foreach (nativeEventShellTemplates() as $template) {
        $source = (string) file_get_contents($template);
        $start = strpos($source, 'new CustomEvent');

        expect($start)->not->toBeFalse($template.' builds no CustomEvent, so this reads nothing');

        $constructor = substr($source, (int) $start, 400);

        expect(str_contains($constructor, 'bubbles'))->toBeFalse(
            $template.' now asks for a bubbling event, so a window listener would work and this guard is stale'
        );
    }
});

it('listens on the document, and nowhere the event cannot reach', function (): void {
    $source = nativeEventAppJs();

    expect($source)->not->toBe('', 'app.js was not opened, so nothing below was checked')
        ->and($source)->toContain("document.addEventListener('native-event'")
        ->and($source)->toContain("document.removeEventListener('native-event'")
        ->and($source)->not->toContain("window.addEventListener('native-event'")
        ->and($source)->not->toContain("window.removeEventListener('native-event'");
});
