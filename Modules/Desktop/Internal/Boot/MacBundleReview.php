<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Boot;

// What App Store review refuses, applied to a bundle that already exists
// rather than to the config meant to produce it. A runtime strategy nobody
// measured against an artifact is a plan, not a prerequisite.

// The judgement is pure so it can be tested without a build. Reading a real
// .app is MacBundleReader's half.
final class MacBundleReview
{
    // Refused outright for App Store distribution. Each is legitimate under
    // Developer ID, which is why the two lanes cannot share one file.
    public const array REFUSED_ENTITLEMENTS = [
        'com.apple.security.cs.allow-unsigned-executable-memory' => 'writable-executable memory; permitted only outside the store',
        'com.apple.security.cs.disable-library-validation' => 'turns off the check that a loaded library shares this signature',
        'com.apple.security.get-task-allow' => 'a debuggable build; this is a development signature, not a distribution one',
        'com.apple.security.cs.allow-dyld-environment-variables' => 'lets the loader be steered by the environment',
    ];

    // The pair a sandboxed child is allowed, and nothing else: a child
    // carrying a third sandbox entitlement is terminated on launch.
    public const array CHILD_SANDBOX_ENTITLEMENTS = [
        'com.apple.security.app-sandbox',
        'com.apple.security.inherit',
    ];

    private const string SANDBOX_PREFIX = 'com.apple.security.';

    /**
     * @param  array<string, mixed>  $appEntitlements
     * @param  array<string, array{entitlements: array<string, mixed>, signed: bool, inMacOsDirectory: bool, launched: bool}>  $children
     * @return list<string>
     */
    public function refusals(array $appEntitlements, array $children): array
    {
        return [
            ...$this->appRefusals($appEntitlements),
            ...$this->childRefusals($children),
        ];
    }

    /**
     * @param  array<string, mixed>  $entitlements
     * @return list<string>
     */
    private function appRefusals(array $entitlements): array
    {
        $refusals = [];

        if (($entitlements['com.apple.security.app-sandbox'] ?? false) !== true) {
            $refusals[] = 'the app is not sandboxed: com.apple.security.app-sandbox is absent or false, and the store takes no unsandboxed submission';
        }

        foreach (array_keys(self::REFUSED_ENTITLEMENTS) as $key) {
            if (($entitlements[$key] ?? false) === true) {
                $refusals[] = 'the app carries '.$key.': '.self::REFUSED_ENTITLEMENTS[$key];
            }
        }

        return $refusals;
    }

    /**
     * @param  array<string, array{entitlements: array<string, mixed>, signed: bool, inMacOsDirectory: bool, launched: bool}>  $children
     * @return list<string>
     */
    private function childRefusals(array $children): array
    {
        $refusals = [];

        foreach ($children as $path => $child) {
            $refusals = [...$refusals, ...$this->oneChildsRefusals($path, $child)];
        }

        return $refusals;
    }

    /**
     * @param  array{entitlements: array<string, mixed>, signed: bool, inMacOsDirectory: bool, launched: bool}  $child
     * @return list<string>
     */
    private function oneChildsRefusals(string $path, array $child): array
    {
        $refusals = [];

        if (! $child['signed']) {
            $refusals[] = $path.' carries no signature, and every executable in the package needs one';
        }

        // Not cosmetic and not a lint: a nested executable outside a MacOS
        // directory is rejected by the submission tooling before review sees
        // it, which is where the interpreter under Resources/build/php lands.
        if (! $child['inMacOsDirectory']) {
            $refusals[] = $path.' is an executable outside a Contents/MacOS directory, where a nested one has to live';
        }

        return [
            ...$refusals,
            ...$this->sandboxShapeRefusals($path, $child['entitlements'], $child['launched']),
            ...$this->refusedOn($path, $child['entitlements']),
        ];
    }

    // Three shapes, each learned from a bundle Apple shipped. A nested .app is
    // its own sandboxed program holding its OWN rights: Amphetamine's login
    // helper holds files.user-selected and is on the store.

    // A helper declaring `inherit` takes the parent's rights and may declare
    // nothing else, and that one Apple does enforce. An executable the app
    // never launches needs no sandbox at all — Apple Configurator ships an
    // unsandboxed cfgutilscript in its own MacOS directory.
    /**
     * @param  array<string, mixed>  $entitlements
     * @return list<string>
     */
    private function sandboxShapeRefusals(string $path, array $entitlements, bool $launched): array
    {
        if (($entitlements['com.apple.security.app-sandbox'] ?? false) !== true) {
            return $launched
                ? [$path.' is launched by the app and is not sandboxed, so it would run outside the container the app is confined to']
                : [];
        }

        if (($entitlements['com.apple.security.inherit'] ?? false) !== true) {
            return [];
        }

        $sandboxKeys = array_values(array_filter(
            array_keys($entitlements),
            static fn (string $key): bool => str_starts_with($key, self::SANDBOX_PREFIX)
                && ! str_starts_with($key, self::SANDBOX_PREFIX.'cs.'),
        ));

        $extra = array_diff($sandboxKeys, self::CHILD_SANDBOX_ENTITLEMENTS);

        return $extra === []
            ? []
            : [$path.' inherits the parent sandbox and also declares '.implode(', ', $extra)
                .'; a child that combines inherit with any other sandbox entitlement is terminated on launch'];
    }

    /**
     * @param  array<string, mixed>  $entitlements
     * @return list<string>
     */
    private function refusedOn(string $path, array $entitlements): array
    {
        $refusals = [];

        foreach (array_keys(self::REFUSED_ENTITLEMENTS) as $key) {
            if (($entitlements[$key] ?? false) === true) {
                $refusals[] = $path.' carries '.$key.': '.self::REFUSED_ENTITLEMENTS[$key];
            }
        }

        return $refusals;
    }
}
