<?php

/*
 * Signatures for the `nativephp/mobile` EDGE surface the Mobile module's
 * native screens extend.
 *
 * Same rationale as `native-mobile-scanner.php` in this directory: the
 * package is installed ONLY in `mobile-app/vendor` (the sibling-root
 * topology exists because `nativephp/desktop` conflicts with
 * `nativephp/mobile`), so the repo-root toolchain can never autoload it.
 * Without these, a screen extending NativeComponent is a `class.notFound`
 * that no amount of level-10 analysis can see past — and the alternative,
 * excluding the screens from analysis, would leave the one part of the
 * mobile UI that is real PHP entirely unchecked.
 *
 * Kept in lockstep with `mobile-app/vendor/nativephp/mobile/src/Edge/`; it
 * declares nothing beyond what this repo calls. The file lives outside every
 * composer PSR-4 root, so it is never autoloaded at runtime.
 */

namespace Native\Mobile\Edge;

class Element {}

abstract class NativeComponent
{
    /** @param array<string, mixed> $data */
    protected function view(string $name, array $data = []): Element {}

    public function navTitle(): string {}

    /** @param array<string, mixed> $data */
    public function navigate(string $uri, array $data = []): static {}

    /** @param array<string, mixed> $data */
    public function replace(string $uri, array $data = []): static {}

    public function back(): static {}

    public function exitToWeb(string $uri): void {}

    public function mount(): void {}

    public function param(string $key, mixed $default = null): mixed {}

    public function data(string $key, mixed $default = null): mixed {}
}
