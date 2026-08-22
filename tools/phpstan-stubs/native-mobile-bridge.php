<?php

/*
 * Signatures for the two global functions that ARE the PHP-to-native
 * boundary in `nativephp/mobile`.
 *
 * Same rationale as the sibling stubs in this directory: the package is
 * installed ONLY in `mobile-app/vendor` (the sibling-root topology exists
 * because `nativephp/desktop` conflicts with `nativephp/mobile`), so the
 * repo-root toolchain can never autoload it.
 *
 * These two are not classes, though, and on a real device they are not PHP
 * at all: `nativephp.o`, a C extension compiled into the shipped `libphp.a`,
 * defines `zif_nativephp_call` / `zif_nativephp_can`, which call the Swift
 * `@_cdecl` symbols `NativePHPCall` / `NativePHPCan` in the app target. Off a
 * device neither exists, which is why every call site guards on
 * `function_exists()` first — a guard PHPStan can only check if it knows what
 * is being guarded.
 *
 * The file lives outside every composer PSR-4 root, so it is never autoloaded
 * at runtime and these declarations never shadow the real extension.
 */

/**
 * @param  string  $params  JSON-encoded parameters.
 * @return string|null JSON-encoded answer, or null when the shell registered
 *                     no such bridge function.
 */
function nativephp_call(string $method, string $params = '{}'): ?string {}

function nativephp_can(string $method): bool {}
