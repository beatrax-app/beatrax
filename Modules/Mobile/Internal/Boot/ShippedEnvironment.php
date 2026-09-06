<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Modules\Core\Public\Support\PatternScan;

// APP_DEBUG carries the weight here rather than BEATRAX_DEV_MODE: on a mobile
// runtime DevConsoleBuildGate reads config('app.debug'), and the dev-mode key
// it reads on a desktop is not consulted at all. A debuggable phone build opens
// the artisan runner and the query panel to the only account a phone has.
/**
 * @link ../../../../.docs/features/dev-mode/the-console-on-a-shipped-build.md
 */
final class ShippedEnvironment
{
    /** @var array<string, string> */
    private const array REQUIRED = [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
    ];

    // Refused by value rather than pinned to one: an operator may reasonably
    // ship `info`, and may not ship the level that writes a personal ledger's
    // rows to a phone's disk. `.env.bundled` carries `warning`; this is what
    // stops a hand-edited file getting past that.
    /** @var array<string, string> */
    private const array REFUSED = [
        'LOG_LEVEL' => 'debug',
    ];

    // Absence is refused alongside a wrong value, deliberately. Both keys
    // resolve to their safe default when the file omits them — but the local
    // template ships them set to local and true, and a bundle whose safety
    // rests on a line nobody wrote is one edit away from resting on nothing.
    /**
     * @return array<string, string> key => what the file carries, for every key
     *                               a shipped bundle may not carry that value for
     */
    public static function wrongIn(string $envContents): array
    {
        $wrong = [];

        foreach (self::REQUIRED as $key => $required) {
            $actual = self::valueOf($envContents, $key);

            if ($actual !== $required) {
                $wrong[$key] = $actual ?? 'no uncommented assignment at all';
            }
        }

        foreach (self::REFUSED as $key => $refused) {
            if (self::valueOf($envContents, $key) === $refused) {
                $wrong[$key] = $refused;
            }
        }

        return $wrong;
    }

    /** @return array<string, string> the value each key has to carry */
    public static function required(): array
    {
        return self::REQUIRED;
    }

    /** @return array<string, string> the value each key may not carry */
    public static function refused(): array
    {
        return self::REFUSED;
    }

    // The first uncommented assignment, which is the one that reaches config():
    // Dotenv's immutable loader refuses to overwrite a name it has already
    // written, so a second APP_ENV further down the file is never read.
    private static function valueOf(string $envContents, string $key): ?string
    {
        $matched = PatternScan::first('/^[ \t]*'.$key.'[ \t]*=[ \t]*(\S*)/m', $envContents);

        if ($matched === []) {
            return null;
        }

        return strtolower(trim($matched[1], "\"'"));
    }
}
