<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Illuminate\Contracts\Config\Repository;

final class AndroidApiLevels
{
    // What Google Play requires a new submission or update to target. Held
    // separately from the pinned target because they are different facts: one
    // is what this product ships, the other is the floor a store enforces, and
    // a build below it is rejected on upload rather than at review.
    public const int PLAY_TARGET_SDK = 36;

    // The Gradle names, in the order the file declares them. The keys are
    // config('nativephp.android.*'), which is what the packager substitutes in.
    private const array LEVELS = [
        'compile_sdk' => 'compileSdk',
        'min_sdk' => 'minSdk',
        'target_sdk' => 'targetSdk',
    ];

    /** @return array<string, int> the pinned levels, keyed by config name */
    public static function configured(Repository $config): array
    {
        $levels = [];

        foreach (array_keys(self::LEVELS) as $key) {
            $value = $config->get('nativephp.android.'.$key);

            if (is_int($value)) {
                $levels[$key] = $value;
            }
        }

        return $levels;
    }

    // Named rather than counted, because the answer a reader needs is which
    // level is missing: an absent key resolves to the package's own default
    // and the build carries a number this product never chose.
    public static function unpinned(Repository $config): ?string
    {
        $configured = self::configured($config);
        $missing = array_diff(array_keys(self::LEVELS), array_keys($configured));

        if ($missing !== []) {
            return "config('nativephp.android') pins no integer ".implode(' or ', $missing)
                .'. Unpinned, nativephp/mobile resolves the level from its own default filtered '
                .'through NATIVEPHP_ANDROID_* in a git-ignored .env, so the API level the store '
                .'reads off the APK is not a choice this product made. Set it in '
                .'mobile-app/config/nativephp.php as an integer literal.';
        }

        $target = $configured['target_sdk'];

        if ($target < self::PLAY_TARGET_SDK) {
            return "The pinned targetSdk is {$target}, below the API level ".self::PLAY_TARGET_SDK
                .' Google Play requires of a new submission or update. Play refuses the upload, so '
                .'this is a refusal here rather than a rejected release.';
        }

        return null;
    }

    // Read back after a package run: the template ships REPLACE_COMPILE_SDK and
    // PreparesBuild substitutes config('nativephp.android.*') on its way into
    // Gradle. Before that, every level here reads as null.
    /** @return array<string, int> the levels the built project actually carries */
    public static function inGradle(string $gradleContents): array
    {
        $found = [];

        foreach (self::LEVELS as $key => $gradleName) {
            if (preg_match('/'.$gradleName.'\s*=\s*(\d+)/', $gradleContents, $matches) === 1) {
                $found[$key] = (int) $matches[1];
            }
        }

        return $found;
    }

    // Every level the artefact disagrees on, in one line, so a build that got
    // two of the three wrong does not need two runs to say so.
    public static function shippedDisagreement(Repository $config, string $gradleContents): ?string
    {
        $expected = self::configured($config);
        $actual = self::inGradle($gradleContents);
        $wrong = [];

        foreach ($expected as $key => $level) {
            if (($actual[$key] ?? null) !== $level) {
                $wrong[] = self::LEVELS[$key].' is '.($actual[$key] ?? 'no readable value').', not '.$level;
            }
        }

        return $wrong === [] ? null : implode('; ', $wrong);
    }
}
