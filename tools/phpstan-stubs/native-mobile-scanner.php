<?php

/*
 * Signatures for the `nativephp/mobile-scanner` surface the Mobile module
 * calls through `QrScanBridge`.
 *
 * The package is installed ONLY in `mobile-app/vendor` (the sibling-root
 * topology exists because `nativephp/desktop` conflicts with
 * `nativephp/mobile`), so the repo-root toolchain can never autoload it and
 * larastan resolves every call through the facade to `mixed`. A `mixed`
 * receiver makes the fluent builder uncheckable, which is the opposite of
 * what level 10 is for. These stubs restore the real signatures so the chain
 * is genuinely analysed here, rather than suppressed.
 *
 * The facade's accessor target is declared too: larastan resolves a facade
 * through `getFacadeAccessor()`, so stubbing only the facade would still
 * leave it pointing at an unknown class.
 *
 * Kept in lockstep with `mobile-app/vendor/nativephp/mobile/src/Scanner.php`,
 * `PendingScanner.php` and `Facades/Scanner.php`; it declares nothing beyond
 * what this repo calls.
 */

namespace Native\Mobile;

class PendingScanner
{
    public function prompt(string $prompt): self {}

    public function continuous(bool $continuous = true): self {}

    /**
     * @param  list<string>  $formats
     */
    public function formats(array $formats): self {}

    public function id(string $id): self {}

    public function getId(): ?string {}

    public function scan(): void {}
}

class Scanner
{
    public static function scan(): PendingScanner {}

    public static function make(): PendingScanner {}
}

namespace Native\Mobile\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Native\Mobile\PendingScanner scan()
 * @method static \Native\Mobile\PendingScanner make()
 *
 * @see \Native\Mobile\Scanner
 */
class Scanner extends Facade
{
    protected static function getFacadeAccessor(): string {}
}
