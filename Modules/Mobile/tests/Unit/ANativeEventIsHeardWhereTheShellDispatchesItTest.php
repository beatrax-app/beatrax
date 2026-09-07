<?php

declare(strict_types=1);

// The shell hands the phone's answer to the page as a CustomEvent built with a
// `detail` and nothing else, so `bubbles` is false and it never leaves the node
// it was dispatched on. Both shells dispatch on `document`; the listener was on
// `window`, so the notification grant was asked for, answered by the OS,
// dispatched by the shell — and recorded nowhere. Measured on an iPhone 12 mini
// before this, and after it.

/** @return list<string> the relative path of each shell template under a vendor root */
function nativeEventTemplatePaths(): array
{
    return [
        'nativephp/mobile/resources/xcode/NativePHP/ContentView.swift',
        'nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/utils/NativeActionCoordinator.kt',
    ];
}

/** @return list<string> every vendor tree that could hold them, from either composer root */
function nativeEventVendorRoots(): array
{
    $roots = [];

    foreach ([base_path('mobile-app/vendor'), base_path('vendor'), base_path('../vendor')] as $candidate) {
        if (is_dir($candidate.DIRECTORY_SEPARATOR.'nativephp'.DIRECTORY_SEPARATOR.'mobile')) {
            $roots[] = $candidate;
        }
    }

    return $roots;
}

/** @return list<string> both shell templates, or none where the mobile vendor tree is not installed */
function nativeEventShellTemplates(): array
{
    $found = [];

    foreach (nativeEventTemplatePaths() as $path) {
        foreach (nativeEventVendorRoots() as $root) {
            $candidate = $root.DIRECTORY_SEPARATOR.$path;

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

// The repository-root job installs no mobile-app/vendor, so an empty list is an
// ordinary answer there. It is asserted rather than assumed: a tree that HAS
// the vendored plugin and yields no templates is the plugin having moved them,
// which is the thing the two cases below are for.
it('reads both shell templates wherever the mobile plugin is installed', function (): void {
    $roots = nativeEventVendorRoots();
    $templates = nativeEventShellTemplates();

    expect(count($templates))->toBe(
        $roots === [] ? 0 : count(nativeEventTemplatePaths()),
        $roots === []
            ? 'no vendored mobile plugin in this composer root, so nothing was expected'
            : 'the mobile plugin is installed at '.implode(', ', $roots).' and its shell templates were not where they were'
    );
});

it('has every shell dispatching the event on the document', function (): void {
    $checked = 0;

    foreach (nativeEventShellTemplates() as $template) {
        expect(str_contains((string) file_get_contents($template), 'document.dispatchEvent'))->toBeTrue(
            $template.' dispatches the native event somewhere else, and the listener has to move with it'
        );

        $checked++;
    }

    expect($checked)->toBe(count(nativeEventShellTemplates()));
});

// The half that makes the target matter. A bubbling event would reach window
// from document, and this whole finding would not exist.
it('has no shell asking for a bubbling event', function (): void {
    $checked = 0;

    foreach (nativeEventShellTemplates() as $template) {
        $source = (string) file_get_contents($template);
        $start = strpos($source, 'new CustomEvent');

        expect($start)->not->toBeFalse($template.' builds no CustomEvent, so this reads nothing');

        expect(str_contains(substr($source, (int) $start, 400), 'bubbles'))->toBeFalse(
            $template.' now asks for a bubbling event, so a window listener would work and this guard is stale'
        );

        $checked++;
    }

    expect($checked)->toBe(count(nativeEventShellTemplates()));
});

it('listens on the document, and nowhere the event cannot reach', function (): void {
    $source = nativeEventAppJs();

    expect($source)->not->toBe('', 'app.js was not opened, so nothing below was checked')
        ->and($source)->toContain("document.addEventListener('native-event'")
        ->and($source)->toContain("document.removeEventListener('native-event'")
        ->and($source)->not->toContain("window.addEventListener('native-event'")
        ->and($source)->not->toContain("window.removeEventListener('native-event'");
});
